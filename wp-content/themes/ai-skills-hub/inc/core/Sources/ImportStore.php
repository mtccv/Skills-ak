<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Security\Access;
use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Internal import persistence. Worker writes require a live fenced job lease, never an admin identity. */
final class ImportStore {
	public const STATE_BYTES = 4194304;
	public const LEASE_SECONDS = 120;
	private const PAGE_SIZE = 25;
	private const TERMINAL = array( 'complete', 'incomplete', 'failed', 'cancelled' );

	public function __construct( private Database $db, private Migrator $migrator ) {}

	private function ready(): void { if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'Import storage requires the current schema.' ); } }
	private static function now( int $offset = 0 ): string { return gmdate( 'Y-m-d H:i:s', time() + $offset ); }
	private static function background(): void {
		if ( ! ( defined( 'DOING_CRON' ) && DOING_CRON ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { throw new \DomainException( 'Import workers require a background context.' ); }
	}
	private static function sha( string $sha ): string {
		if ( ! preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $sha ) ) { throw new \InvalidArgumentException( 'Invalid Git identity.' ); }
		return $sha;
	}
	private static function json( array $value, int $limit = self::STATE_BYTES ): string {
		$result = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $result ) > $limit ) { throw new \InvalidArgumentException( 'Import checkpoint exceeds its size limit.' ); }
		return $result;
	}
	private static function decoded( mixed $value ): array {
		if ( ! is_string( $value ) || strlen( $value ) > self::STATE_BYTES ) { throw new \RuntimeException( 'Invalid stored import state.' ); }
		$result = json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $result ) ) { throw new \RuntimeException( 'Invalid stored import state.' ); }
		return $result;
	}
	private static function code( string $code ): string {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code ) ) { throw new \InvalidArgumentException( 'Invalid import event code.' ); }
		return $code;
	}
	private function lockName( string $kind ): string { return 'aish_import_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() . ':' . $kind ), 0, 48 ); }
	private function advisory( string $kind, callable $operation ): mixed {
		$name = $this->lockName( $kind );
		if ( 1 !== (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $name ) ) ) { throw new \DomainException( 'Import scheduling is busy.' ); }
		try { return $operation(); } finally { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $name ) ); }
	}

	public function repository( int $id ): array {
		$this->ready(); Input::id( $id );
		$table = $this->db->table( 'repositories' );
		$row = $this->db->row( "SELECT * FROM $table WHERE id=%d", array( $id ) );
		if ( ! $row ) { throw new \DomainException( 'Repository not found.' ); }
		return $row;
	}

	public function saveRepository( ?int $id, string $url, bool $enabled, int $interval = 86400 ): int {
		Access::write( 'aish_manage_repositories' ); $this->ready();
		$identity = GitHubClient::repositoryIdentity( $url );
		if ( $interval < 3600 || $interval > 604800 ) { throw new \InvalidArgumentException( 'Invalid synchronization interval.' ); }
		if ( null !== $id ) { Input::id( $id ); }
		return $this->advisory( 'repository-settings', function () use ( $id, $identity, $enabled, $interval ): int {
			return $this->db->transaction( function () use ( $id, $identity, $enabled, $interval ): int {
				$table = $this->db->table( 'repositories' );
				$row = null === $id ? $this->db->row( "SELECT * FROM $table WHERE owner=%s AND name=%s FOR UPDATE", array( $identity['owner'], $identity['name'] ) ) : $this->db->row( "SELECT * FROM $table WHERE id=%d FOR UPDATE", array( $id ) );
				if ( null !== $id && ! $row ) { throw new \DomainException( 'Repository not found.' ); }
				if ( $row ) {
					$id = (int) $row['id'];
					if ( $row['owner'] !== $identity['owner'] || $row['name'] !== $identity['name'] ) {
						$skills = $this->db->table( 'skills' ); $jobs = $this->db->table( 'jobs' );
						if ( ! empty( $row['github_id'] ) || (int) $this->db->value( "SELECT COUNT(*) FROM $skills WHERE repository_id=%d", array( $id ) ) || (int) $this->db->value( "SELECT COUNT(*) FROM $jobs WHERE repository_id=%d", array( $id ) ) ) { throw new \DomainException( 'An imported repository identity cannot be changed.' ); }
					}
					$this->db->updateById( 'repositories', $id, array( 'owner' => $identity['owner'], 'name' => $identity['name'], 'sync_enabled' => $enabled ? 1 : 0, 'sync_interval' => $interval, 'is_archived' => 0 ) );
					return $id;
				}
				return $this->db->insert( 'repositories', array( 'owner' => $identity['owner'], 'name' => $identity['name'], 'sync_enabled' => $enabled ? 1 : 0, 'sync_interval' => $interval, 'created_at' => self::now() ) );
			} );
		} );
	}

	public function configureRepository( int $id, bool $enabled, int $interval = 86400 ): void {
		Access::write( 'aish_manage_repositories' ); $this->ready(); Input::id( $id );
		if ( $interval < 3600 || $interval > 604800 ) { throw new \InvalidArgumentException( 'Invalid synchronization interval.' ); }
		$this->db->transaction( function () use ( $id, $enabled, $interval ): void {
			$table = $this->db->table( 'repositories' );
			$repo = $this->db->row( "SELECT * FROM $table WHERE id=%d FOR UPDATE", array( $id ) );
			if ( ! $repo || (int) $repo['is_archived'] ) { throw new \DomainException( 'Archived repositories require an explicit edit before configuration.' ); }
			$this->db->updateById( 'repositories', $id, array( 'sync_enabled' => $enabled ? 1 : 0, 'sync_interval' => $interval ) );
		} );
	}

	public function archiveRepository( int $id ): void {
		Access::write( 'aish_manage_repositories' ); $this->ready(); Input::id( $id );
		$this->db->transaction( function () use ( $id ): void {
			$jobs = $this->db->table( 'jobs' );
			$this->db->rows( "SELECT id FROM $jobs WHERE active_key=%d FOR UPDATE", array( $id ) );
			$this->db->updateById( 'repositories', $id, array( 'is_archived' => 1, 'sync_enabled' => 0, 'sync_status' => 'cancelled' ) );
			$this->db->execute( "UPDATE $jobs SET status='cancelled',active_key=NULL,lease_token='',locked_until=NULL,completed_at=%s,updated_at=%s,last_error='repository_archived' WHERE active_key=%d", array( self::now(), self::now(), $id ) );
		} );
	}

	public function dueRepositories( int $limit = 20 ): array {
		self::background(); $this->ready();
		if ( $limit < 1 || $limit > 20 ) { throw new \InvalidArgumentException( 'Invalid repository batch.' ); }
		$table = $this->db->table( 'repositories' ); $jobs = $this->db->table( 'jobs' );
		return $this->db->rows( "SELECT r.* FROM $table r WHERE r.sync_enabled=1 AND r.is_archived=0 AND (r.last_scan_at IS NULL OR r.last_scan_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL r.sync_interval SECOND)) AND NOT EXISTS (SELECT 1 FROM $jobs j WHERE j.active_key=r.id) ORDER BY r.last_scan_at ASC,r.id ASC LIMIT %d", array( $limit ) );
	}
	public function dueRepos( int $limit = 20 ): array { return $this->dueRepositories( $limit ); }

	public function enqueue( int $repositoryId, string $kind = 'sync', bool $scheduled = false ): int {
		if ( $scheduled ) { self::background(); } else { Access::write( 'aish_manage_repositories' ); }
		$this->ready(); Input::id( $repositoryId );
		if ( ! in_array( $kind, array( 'scan', 'sync' ), true ) ) { throw new \InvalidArgumentException( 'Invalid import job kind.' ); }
		return $this->advisory( 'enqueue:' . $repositoryId, function () use ( $repositoryId, $kind, $scheduled ): int {
			return $this->db->transaction( function () use ( $repositoryId, $kind, $scheduled ): int {
				$jobs = $this->db->table( 'jobs' ); $repositories = $this->db->table( 'repositories' );
				$active = $this->db->row( "SELECT id FROM $jobs WHERE active_key=%d FOR UPDATE", array( $repositoryId ) );
				if ( $active ) { return (int) $active['id']; }
				$repo = $this->db->row( "SELECT * FROM $repositories WHERE id=%d FOR UPDATE", array( $repositoryId ) );
				if ( ! $repo || (int) $repo['is_archived'] || ( $scheduled && ! (int) $repo['sync_enabled'] ) ) { throw new \DomainException( 'Repository synchronization is not available.' ); }
				$recent = $this->db->value( "SELECT MAX(created_at) FROM $jobs WHERE repository_id=%d", array( $repositoryId ) );
				if ( is_string( $recent ) && $recent > self::now( -60 ) ) { throw new \DomainException( 'Wait before starting another repository scan.' ); }
				$id = $this->db->insert( 'jobs', array( 'repository_id' => $repositoryId, 'kind' => $kind, 'status' => 'queued', 'active_key' => $repositoryId, 'state_json' => '{}', 'attempts' => 0, 'available_at' => self::now(), 'lease_token' => '', 'deadline_at' => self::now( 172800 ), 'created_at' => self::now(), 'updated_at' => self::now(), 'last_error' => '' ) );
				$this->db->updateById( 'repositories', $repositoryId, array( 'last_scan_at' => self::now(), 'sync_status' => 'queued' ) );
				return $id;
			} );
		} );
	}

	/** At most two active background leases across all repositories; crashed leases are reclaimed. */
	public function claim(): ?array {
		self::background(); $this->ready();
		return $this->advisory( 'claim', function (): ?array {
			return $this->db->transaction( function (): ?array {
				$jobs = $this->db->table( 'jobs' ); $repositories = $this->db->table( 'repositories' );
				if ( (int) $this->db->value( "SELECT COUNT(*) FROM $jobs WHERE status='running' AND locked_until>%s", array( self::now() ) ) >= 2 ) { return null; }
				$job = $this->db->row( "SELECT * FROM $jobs WHERE ((status IN ('queued','retry') AND available_at<=%s) OR (status='running' AND locked_until<=%s)) ORDER BY available_at ASC,id ASC LIMIT 1 FOR UPDATE", array( self::now(), self::now() ) );
				if ( ! $job ) { return null; }
				$repo = $this->db->row( "SELECT * FROM $repositories WHERE id=%d FOR UPDATE", array( (int) $job['repository_id'] ) );
				if ( ! $repo || (int) $repo['is_archived'] || $job['deadline_at'] <= self::now() || (int) $job['attempts'] >= 5 ) {
					$status = ! $repo || (int) $repo['is_archived'] ? 'cancelled' : 'failed';
					$this->db->updateById( 'jobs', (int) $job['id'], array( 'status' => $status, 'active_key' => null, 'lease_token' => '', 'locked_until' => null, 'completed_at' => self::now(), 'updated_at' => self::now(), 'last_error' => 'job_expired' ) );
					if ( $repo ) { $this->db->updateById( 'repositories', (int) $repo['id'], array( 'sync_status' => $status ) ); }
					return null;
				}
				$token = bin2hex( random_bytes( 32 ) );
				$attempts = (int) $job['attempts'] + ( 'running' === $job['status'] ? 1 : 0 );
				$this->db->updateById( 'jobs', (int) $job['id'], array( 'status' => 'running', 'attempts' => $attempts, 'lease_token' => $token, 'locked_until' => self::now( self::LEASE_SECONDS ), 'updated_at' => self::now() ) );
				$this->db->updateById( 'repositories', (int) $repo['id'], array( 'sync_status' => 'running' ) );
				$job['state'] = self::decoded( $job['state_json'] ); unset( $job['state_json'] );
				return array_merge( $job, array( 'status' => 'running', 'lease_token' => $token, 'attempts' => $attempts ) );
			} );
		} );
	}

	/** Called inside each write transaction; SELECT FOR UPDATE fences resumed or expired workers. */
	private function leased( int $jobId, string $token ): array {
		$this->ready(); Input::id( $jobId );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { throw new \DomainException( 'Invalid import lease.' ); }
		$jobs = $this->db->table( 'jobs' ); $repositories = $this->db->table( 'repositories' );
		$job = $this->db->row( "SELECT * FROM $jobs WHERE id=%d FOR UPDATE", array( $jobId ) );
		if ( ! $job || 'running' !== $job['status'] || ! hash_equals( (string) $job['lease_token'], $token ) || $job['locked_until'] <= self::now() || $job['deadline_at'] <= self::now() ) { throw new \DomainException( 'Import lease has expired.' ); }
		$repo = $this->db->row( "SELECT * FROM $repositories WHERE id=%d FOR UPDATE", array( (int) $job['repository_id'] ) );
		if ( ! $repo || (int) $repo['is_archived'] ) { throw new \DomainException( 'Repository is no longer available.' ); }
		return $job;
	}

	public function checkpoint( int $jobId, string $token, array $state, int $delay = 1 ): void {
		self::json( $state );
		if ( $delay < 1 || $delay > 86400 ) { throw new \InvalidArgumentException( 'Invalid checkpoint delay.' ); }
		$this->db->transaction( function () use ( $jobId, $token, $state, $delay ): void {
			$job = $this->leased( $jobId, $token );
			$json = self::json( self::preserveReconciliation( $state, self::decoded( $job['state_json'] ) ) );
			$this->db->updateById( 'jobs', $jobId, array( 'state_json' => $json, 'status' => 'queued', 'attempts' => 0, 'available_at' => self::now( $delay ), 'lease_token' => '', 'locked_until' => null, 'updated_at' => self::now(), 'last_error' => '' ) );
		} );
	}

	public function retry( int $jobId, string $token, array $state, string $code, int $delay ): void {
		self::json( $state ); self::code( $code );
		if ( $delay < 1 || $delay > 172800 ) { throw new \InvalidArgumentException( 'Invalid retry delay.' ); }
		$this->db->transaction( function () use ( $jobId, $token, $state, $code, $delay ): void {
			$job = $this->leased( $jobId, $token ); $attempts = (int) $job['attempts'] + 1;
			$json = self::json( self::preserveReconciliation( $state, self::decoded( $job['state_json'] ) ) );
			$failed = $attempts >= 5 || self::now( $delay ) >= $job['deadline_at'];
			$data = array( 'state_json' => $json, 'status' => $failed ? 'failed' : 'retry', 'attempts' => $attempts, 'available_at' => self::now( $delay ), 'lease_token' => '', 'locked_until' => null, 'updated_at' => self::now(), 'last_error' => $code );
			if ( $failed ) { $data += array( 'active_key' => null, 'completed_at' => self::now() ); }
			$this->db->updateById( 'jobs', $jobId, $data );
			$this->db->updateById( 'repositories', (int) $job['repository_id'], array( 'sync_status' => $data['status'] ) );
			$this->logRow( $job, $code, '', 'error' );
		} );
	}

	private static function completeState( array $state ): bool {
		return true === ( $state['inventory_complete'] ?? false ) && true === ( $state['files_complete'] ?? false ) && 0 === ( $state['errors'] ?? null );
	}

	/** Inventory reconciliation commits separately from finalization; stale worker state cannot erase it. */
	private static function preserveReconciliation( array $state, array $stored ): array {
		// Only completeInventory may establish this proof, never a supplied checkpoint.
		unset( $state['inventory_reconciled'], $state['missing_count'] );
		if ( true !== ( $stored['inventory_reconciled'] ?? false ) ) { return $state; }
		if ( ! is_string( $stored['commit_sha'] ?? null ) || $stored['commit_sha'] !== ( $state['commit_sha'] ?? null ) ) { throw new \DomainException( 'Reconciled inventory cannot change its Commit identity.' ); }
		$count = $stored['missing_count'] ?? null;
		if ( ! is_int( $count ) || $count < 0 ) { throw new \RuntimeException( 'Invalid stored inventory reconciliation.' ); }
		$state['inventory_reconciled'] = true;
		$state['missing_count'] = $count;
		$state['stats'] = is_array( $state['stats'] ?? null ) ? $state['stats'] : array();
		$state['stats']['missing'] = $count;
		return $state;
	}

	private function reusableBaseline( array $job, string $commit ): bool {
		$repo = $this->repository( (int) $job['repository_id'] );
		if ( '' === ( $repo['last_sync_commit'] ?? '' ) || ! hash_equals( (string) $repo['last_sync_commit'], $commit ) ) { return false; }
		$jobs = $this->db->table( 'jobs' );
		$previous = $this->db->row( "SELECT id,status FROM $jobs WHERE repository_id=%d AND id<%d ORDER BY id DESC LIMIT 1", array( (int) $job['repository_id'], (int) $job['id'] ) );
		return $previous && 'complete' === $previous['status'];
	}

	/** A previous partial attempt may have staged sources after the last successful Commit. */
	public function baselineReusable( int $jobId, string $token, string $commit ): bool {
		self::sha( $commit );
		return $this->db->transaction( fn(): bool => $this->reusableBaseline( $this->leased( $jobId, $token ), $commit ) );
	}

	public function finish( int $jobId, string $token, array $state, string $status = 'complete' ): void {
		if ( ! in_array( $status, self::TERMINAL, true ) || ( 'complete' === $status && ! self::completeState( $state ) ) ) { throw new \DomainException( 'Incomplete imports cannot be reported complete.' ); }
		self::json( $state );
		$this->db->transaction( function () use ( $jobId, $token, $state, $status ): void {
			$job = $this->leased( $jobId, $token );
			$storedState = self::decoded( $job['state_json'] );
			$json = self::json( self::preserveReconciliation( $state, $storedState ) );
			$repoUpdate = array( 'sync_status' => $status );
			if ( 'complete' === $status ) {
				$sha = self::sha( $state['commit_sha'] ?? '' );
				$license = Input::plain( $state['repository']['license'] ?? '', 100, true );
				if ( ! empty( $state['unchanged_head'] ) && ! $this->reusableBaseline( $job, $sha ) ) { throw new \DomainException( 'No trusted unchanged-head baseline exists.' ); }
				if ( empty( $state['unchanged_head'] ) && ( true !== ( $storedState['inventory_reconciled'] ?? false ) || $sha !== ( $storedState['commit_sha'] ?? '' ) ) ) { throw new \DomainException( 'A successful import requires durable inventory reconciliation.' ); }
				$repoUpdate += array( 'last_sync_commit' => $sha, 'last_sync_at' => self::now(), 'license_spdx' => $license );
			}
			$this->db->updateById( 'jobs', $jobId, array( 'state_json' => $json, 'status' => $status, 'active_key' => null, 'lease_token' => '', 'locked_until' => null, 'completed_at' => self::now(), 'updated_at' => self::now(), 'last_error' => 'complete' === $status ? '' : $status ) );
			$this->db->updateById( 'repositories', (int) $job['repository_id'], $repoUpdate );
			$this->logRow( $job, 'job_' . $status, '', 'complete' === $status ? 'info' : 'warning' );
		} );
	}

	public function updateRepository( int $jobId, string $token, array $metadata ): void {
		$githubId = filter_var( $metadata['id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $githubId || ( $metadata['private'] ?? true ) !== false ) { throw new \DomainException( 'Only identified public repositories may be imported.' ); }
		$branch = Input::utf8( $metadata['default_branch'] ?? '', 255 );
		Input::plain( $metadata['license'] ?? '', 100, true );
		if ( '' === $branch || preg_match( '/[\x00-\x20\x7f]/', $branch ) ) { throw new \InvalidArgumentException( 'Invalid default branch.' ); }
		$this->db->transaction( function () use ( $jobId, $token, $githubId, $branch, $metadata ): void {
			$job = $this->leased( $jobId, $token ); $id = (int) $job['repository_id']; $repo = $this->repository( $id );
			if ( ! empty( $repo['github_id'] ) && (int) $repo['github_id'] !== $githubId ) { throw new \DomainException( 'Repository identity changed; manual verification required.' ); }
			if ( strtolower( (string) ( $metadata['owner'] ?? '' ) ) !== $repo['owner'] || strtolower( (string) ( $metadata['name'] ?? '' ) ) !== $repo['name'] ) { throw new \DomainException( 'Repository rename requires manual verification.' ); }
			// License belongs to the last successful scan baseline, not an unfinished metadata fetch.
			$this->db->updateById( 'repositories', $id, array( 'github_id' => $githubId, 'default_branch' => $branch ) );
		} );
	}

	public function source( int $repositoryId, string $path ): ?array {
		$this->ready(); Input::id( $repositoryId ); $path = Input::sourcePath( $path );
		$table = $this->db->table( 'imports' );
		return $this->db->row( "SELECT * FROM $table WHERE repository_id=%d AND source_path=%s", array( $repositoryId, $path ) );
	}

	/** Reuse a verified private snapshot when the Skill blob is unchanged but its dependency tree changed. */
	public function sourceContent( int $repositoryId, string $path, string $fileSha ): ?string {
		self::background(); $this->ready(); Input::id( $repositoryId ); $path = Input::sourcePath( $path ); self::sha( $fileSha );
		$imports = $this->db->table( 'imports' ); $versions = $this->db->table( 'skill_versions' );
		$row = $this->db->row( "SELECT v.source_content FROM $imports i INNER JOIN $versions v ON v.skill_id=i.skill_id AND v.commit_sha=i.commit_sha WHERE i.repository_id=%d AND i.source_path=%s AND i.file_sha=%s", array( $repositoryId, $path, $fileSha ) );
		if ( ! $row || ! is_string( $row['source_content'] ?? null ) ) { return null; }
		$raw = Input::utf8( $row['source_content'], 524288 );
		if ( ! hash_equals( $fileSha, hash( 40 === strlen( $fileSha ) ? 'sha1' : 'sha256', 'blob ' . strlen( $raw ) . "\0" . $raw ) ) ) { throw new \DomainException( 'The saved snapshot does not match its Git identity.' ); }
		return $raw;
	}

	public function seen( int $jobId, string $token, string $path ): void {
		$path = Input::sourcePath( $path );
		$this->db->transaction( function () use ( $jobId, $token, $path ): void {
			$job = $this->leased( $jobId, $token ); $table = $this->db->table( 'imports' );
			$this->db->execute( "UPDATE $table SET last_seen_job=%d,change_status=IF(source_status='missing','restored',change_status),source_status='present',updated_at=%s WHERE repository_id=%d AND source_path=%s", array( $jobId, self::now(), (int) $job['repository_id'], $path ) );
		} );
	}

	/** Original source is private evidence. This method never creates or overwrites translation rows. */
	public function record( int $jobId, string $token, string $path, string $fileSha, string $commitSha, string $raw, array $parsed, array $inspection ): array {
		$path = Input::sourcePath( $path ); self::sha( $fileSha ); self::sha( $commitSha ); $raw = Input::utf8( $raw, 524288 );
		if ( basename( $path ) !== 'SKILL.md' ) { throw new \InvalidArgumentException( 'Only SKILL.md sources may create Skills.' ); }
		$blob = hash( 40 === strlen( $fileSha ) ? 'sha1' : 'sha256', 'blob ' . strlen( $raw ) . "\0" . $raw );
		if ( ! hash_equals( $blob, $fileSha ) ) { throw new \DomainException( 'The source blob does not match its Git identity.' ); }
		$metadata = self::json( array_intersect_key( $parsed, array_flip( array( 'name', 'description', 'metadata', 'warnings' ) ) ), 65536 );
		$findings = self::json( $inspection, 131072 );
		return $this->db->transaction( function () use ( $jobId, $token, $path, $fileSha, $commitSha, $raw, $metadata, $findings ): array {
			$job = $this->leased( $jobId, $token ); $repositoryId = (int) $job['repository_id'];
			$skills = $this->db->table( 'skills' ); $imports = $this->db->table( 'imports' ); $versions = $this->db->table( 'skill_versions' );
			$skill = $this->db->row( "SELECT * FROM $skills WHERE repository_id=%d AND source_path=%s FOR UPDATE", array( $repositoryId, $path ) );
			$source = $this->db->row( "SELECT * FROM $imports WHERE repository_id=%d AND source_path=%s FOR UPDATE", array( $repositoryId, $path ) );
			if ( $source && ( ! $skill || (int) $skill['id'] !== (int) $source['skill_id'] ) ) { throw new \DomainException( 'Source binding was changed by an editor.' ); }
			$newSkill = ! $skill;
			$skillId = $skill ? (int) $skill['id'] : $this->db->insert( 'skills', array( 'repository_id' => $repositoryId, 'source_path' => $path, 'status' => 'pending', 'review_status' => 'pending', 'revision' => 1, 'risk_level' => 'unknown', 'created_at' => self::now(), 'updated_at' => self::now() ) );
			$fileChanged = ! $source || $source['file_sha'] !== $fileSha;
			$priorFindings = $source ? self::decoded( $source['findings_json'] ) : array();
			$currentFindings = self::decoded( $findings );
			unset( $priorFindings['inspection_commit_sha'], $currentFindings['inspection_commit_sha'] );
			$inspectionChanged = $source && ( $priorFindings !== $currentFindings || $source['metadata_json'] !== $metadata );
			$change = $newSkill ? 'new' : ( $fileChanged || $inspectionChanged ? 'updated' : 'unchanged' );
			$logs = $this->db->table( 'import_logs' );
			$priorResult = $this->db->row( "SELECT code FROM $logs WHERE job_id=%d AND source_path=%s AND code IN ('source_new','source_updated','source_unchanged') ORDER BY id ASC LIMIT 1", array( $jobId, $path ) );
			$returnChange = $source && ! $fileChanged && $priorResult ? substr( $priorResult['code'], 7 ) : $change;
			$hash = hash( 'sha256', $raw );
			$version = $this->db->row( "SELECT id,content_hash,file_sha FROM $versions WHERE skill_id=%d AND commit_sha=%s FOR UPDATE", array( $skillId, $commitSha ) );
			if ( $version && ( ! hash_equals( $version['content_hash'], $hash ) || ( '' !== $version['file_sha'] && ! hash_equals( $version['file_sha'], $fileSha ) ) ) ) { throw new \DomainException( 'An immutable source version conflicts.' ); }
			if ( ! $version && $fileChanged ) {
				$this->db->insert( 'skill_versions', array( 'skill_id' => $skillId, 'commit_sha' => $commitSha, 'file_sha' => $fileSha, 'content_hash' => $hash, 'source_content' => $raw, 'repository_id' => $repositoryId, 'source_path' => $path, 'version_label' => '', 'created_at' => self::now() ) );
			}
			$changeStatus = ! $fileChanged && $inspectionChanged ? 'inspection_updated' : ( 'unchanged' === $change && $source ? ( 'missing' === $source['source_status'] ? 'restored' : $source['change_status'] ) : $change );
			$values = array( 'file_sha' => $fileSha, 'commit_sha' => ! $fileChanged && $source ? $source['commit_sha'] : $commitSha, 'source_status' => 'present', 'change_status' => $changeStatus, 'metadata_json' => $metadata, 'findings_json' => $findings, 'last_seen_job' => $jobId, 'updated_at' => self::now() );
			if ( $source ) { $this->db->updateById( 'imports', (int) $source['id'], $values ); }
			else { $this->db->insert( 'imports', $values + array( 'skill_id' => $skillId, 'repository_id' => $repositoryId, 'source_path' => $path, 'created_at' => self::now() ) ); }
			if ( $newSkill ) {
				$state = self::decoded( $job['state_json'] );
				$inventory = $state['inventory_paths'] ?? array();
				if ( is_array( $inventory ) && true === ( $state['inventory_complete'] ?? false ) ) {
					$candidates = $this->db->rows( "SELECT skill_id,source_path FROM $imports WHERE repository_id=%d AND file_sha=%s AND skill_id<>%d LIMIT 20", array( $repositoryId, $fileSha, $skillId ) );
					foreach ( $candidates as $candidate ) {
						if ( ! in_array( $candidate['source_path'], $inventory, true ) ) { $this->logRow( $job, 'possible_move', $path, 'warning', $skillId ); break; }
					}
				}
			}
			if ( ! $priorResult ) { $this->logRow( $job, 'source_' . $change, $path, 'info', $skillId ); }
			return array( 'skill_id' => $skillId, 'change' => $returnChange );
		} );
	}

	/** Deletion inference is allowed only after every inventory and file step completed without errors. */
	public function completeInventory( int $jobId, string $token, array $seenPaths, string $commitSha ): int {
		self::sha( $commitSha );
		if ( count( $seenPaths ) > 500 ) { throw new \InvalidArgumentException( 'Too many imported sources.' ); }
		foreach ( $seenPaths as $path ) { Input::sourcePath( $path ); }
		return $this->db->transaction( function () use ( $jobId, $token, $seenPaths, $commitSha ): int {
			$job = $this->leased( $jobId, $token ); $state = self::decoded( $job['state_json'] );
			if ( ! self::completeState( $state ) || ( $state['commit_sha'] ?? '' ) !== $commitSha ) { throw new \DomainException( 'An incomplete inventory cannot mark missing sources.' ); }
			if ( true === ( $state['inventory_reconciled'] ?? false ) ) { return (int) ( $state['missing_count'] ?? 0 ); }
			$table = $this->db->table( 'imports' );
			// Parameterized paths also preserve previously invalid files that the current inventory observed.
			if ( $seenPaths ) {
				$this->db->execute( "UPDATE $table SET last_seen_job=%d WHERE repository_id=%d AND source_path IN (" . implode( ',', array_fill( 0, count( $seenPaths ), '%s' ) ) . ')', array_merge( array( $jobId, (int) $job['repository_id'] ), $seenPaths ) );
			}
			$count = $this->db->execute( "UPDATE $table SET source_status='missing',change_status='missing',updated_at=%s WHERE repository_id=%d AND (last_seen_job IS NULL OR last_seen_job<>%d) AND source_status<>'missing'", array( self::now(), (int) $job['repository_id'], $jobId ) );
			$state['inventory_reconciled'] = true; $state['missing_count'] = $count;
			$this->db->updateById( 'jobs', $jobId, array( 'state_json' => self::json( $state ), 'updated_at' => self::now() ) );
			$this->logRow( $job, 'inventory_complete', '', 'info' );
			return $count;
		} );
	}

	private function logRow( array $job, string $code, string $path = '', string $level = 'warning', ?int $skillId = null ): void {
		self::code( $code );
		if ( ! in_array( $level, array( 'info', 'warning', 'error' ), true ) ) { throw new \InvalidArgumentException( 'Invalid event severity.' ); }
		if ( '' !== $path ) { Input::sourcePath( $path ); }
		if ( null !== $skillId ) { Input::id( $skillId ); }
		$table = $this->db->table( 'import_logs' );
		$existing = $this->db->row( "SELECT id FROM $table WHERE job_id=%d AND code=%s AND COALESCE(source_path,'')=%s LIMIT 1", array( (int) $job['id'], $code, '' === $path ? '' : $path ) );
		if ( $existing ) { return; }
		if ( (int) $this->db->value( "SELECT COUNT(*) FROM $table WHERE job_id=%d", array( (int) $job['id'] ) ) >= 1000 ) { return; }
		$this->db->insert( 'import_logs', array( 'job_id' => (int) $job['id'], 'repository_id' => (int) $job['repository_id'], 'skill_id' => $skillId, 'source_path' => '' === $path ? null : $path, 'level' => $level, 'code' => $code, 'message' => 'Import event: ' . $code, 'created_at' => self::now() ) );
	}
	public function log( int $jobId, string $token, string $code, string $path = '', string $level = 'warning', ?int $skillId = null ): void {
		$this->db->transaction( function () use ( $jobId, $token, $code, $path, $level, $skillId ): void { $this->logRow( $this->leased( $jobId, $token ), $code, $path, $level, $skillId ); } );
	}

	private function page( string $entity, int $page, string $where = '1=1', array $values = array() ): array {
		Access::write( 'aish_manage_repositories' ); $this->ready();
		if ( $page < 1 || $page > 10000 ) { throw new \InvalidArgumentException( 'Invalid import pagination.' ); }
		$table = $this->db->table( $entity );
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $table WHERE $where", $values );
		$fields = match ( $entity ) {
			'jobs' => 'id,repository_id,kind,status,attempts,available_at,locked_until,deadline_at,created_at,updated_at,completed_at,last_error',
			'imports' => 'id,skill_id,repository_id,source_path,file_sha,commit_sha,source_status,change_status,last_seen_job,created_at,updated_at',
			default => '*',
		};
		$items = $this->db->rows( "SELECT $fields FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ) ) );
		foreach ( $items as &$item ) {
			unset( $item['state_json'], $item['lease_token'], $item['metadata_json'], $item['findings_json'] );
		}
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => self::PAGE_SIZE );
	}
	public function repositories( int $page = 1 ): array { return $this->page( 'repositories', $page ); }
	public function jobs( int $page = 1, int $repositoryId = 0, string $status = '' ): array {
		if ( ! in_array( $status, array( '', 'queued', 'running', 'retry', 'complete', 'incomplete', 'failed', 'cancelled' ), true ) ) { throw new \InvalidArgumentException( 'Invalid job status filter.' ); }
		$where = array( '1=1' ); $values = array();
		if ( $repositoryId > 0 ) { $where[] = 'repository_id=%d'; $values[] = $repositoryId; }
		if ( '' !== $status ) { $where[] = 'status=%s'; $values[] = $status; }
		return $this->page( 'jobs', $page, implode( ' AND ', $where ), $values );
	}
	public function imports( int $page = 1, int $repositoryId = 0, string $change = '' ): array {
		if ( ! in_array( $change, array( '', 'new', 'updated', 'inspection_updated', 'missing', 'restored', 'handled' ), true ) ) { throw new \InvalidArgumentException( 'Invalid source change filter.' ); }
		$where = array( '1=1' ); $values = array();
		if ( $repositoryId > 0 ) { $where[] = 'repository_id=%d'; $values[] = $repositoryId; }
		if ( '' !== $change ) { $where[] = 'change_status=%s'; $values[] = $change; }
		return $this->page( 'imports', $page, implode( ' AND ', $where ), $values );
	}
	public function logs( int $jobId, int $page = 1 ): array { Input::id( $jobId ); return $this->page( 'import_logs', $page, 'job_id=%d', array( $jobId ) ); }
	public function job( int $id ): array {
		Access::write( 'aish_manage_repositories' ); $this->ready(); Input::id( $id ); $table = $this->db->table( 'jobs' );
		$item = $this->db->row( "SELECT * FROM $table WHERE id=%d", array( $id ) );
		if ( ! $item ) { throw new \DomainException( 'Import job not found.' ); }
		$item['state'] = self::decoded( $item['state_json'] ); unset( $item['state_json'], $item['lease_token'] ); return $item;
	}
	public function import( int $id ): array {
		Access::write( 'aish_manage_repositories' ); $this->ready(); Input::id( $id );
		$table = $this->db->table( 'imports' ); $versions = $this->db->table( 'skill_versions' ); $repositories = $this->db->table( 'repositories' );
		$item = $this->db->row( "SELECT i.*,v.source_content,r.owner,r.name FROM $table i INNER JOIN $repositories r ON r.id=i.repository_id LEFT JOIN $versions v ON v.skill_id=i.skill_id AND v.commit_sha=i.commit_sha WHERE i.id=%d", array( $id ) );
		if ( ! $item ) { throw new \DomainException( 'Imported source not found.' ); }
		$item['metadata'] = self::decoded( $item['metadata_json'] ); $item['findings'] = self::decoded( $item['findings_json'] ); $item['review_revision'] = self::sourceRevision( $item ); unset( $item['metadata_json'], $item['findings_json'] ); return $item;
	}
	public static function sourceRevision( array $item ): string {
		$values = array();
		foreach ( array( 'commit_sha', 'file_sha', 'source_status', 'change_status', 'last_seen_job', 'updated_at' ) as $key ) { $values[ $key ] = (string) ( $item[ $key ] ?? '' ); }
		foreach ( array( 'metadata', 'findings' ) as $key ) {
			$raw = $item[ $key . '_json' ] ?? self::json( is_array( $item[ $key ] ?? null ) ? $item[ $key ] : array() );
			$values[ $key . '_hash' ] = hash( 'sha256', (string) $raw );
		}
		return hash( 'sha256', self::json( $values ) );
	}
	public function markHandled( int $id, string $expectedCommit, string $expectedRevision ): void {
		Access::write( 'aish_review_skills' ); $this->ready(); Input::id( $id ); self::sha( $expectedCommit );
		$this->db->transaction( function () use ( $id, $expectedCommit, $expectedRevision ): void {
			$table = $this->db->table( 'imports' ); $row = $this->db->row( "SELECT * FROM $table WHERE id=%d FOR UPDATE", array( $id ) );
			if ( ! $row || ! hash_equals( (string) $row['commit_sha'], $expectedCommit ) || ! hash_equals( self::sourceRevision( $row ), $expectedRevision ) ) { throw new \DomainException( 'The source changed; reload before acknowledging.' ); }
			$this->db->updateById( 'imports', $id, array( 'change_status' => 'handled', 'updated_at' => self::now() ) );
		} );
	}
}
