<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Security\Access;
use AISkillsHub\Core\Security\Input;
use AISkillsHub\Core\Sources\ImportStore;

defined( 'ABSPATH' ) || exit;

/** Private generations and append-only attempts/reviews; generated text never publishes itself. */
final class GenerationStore {
	public const MODES = array( 'both', 'zh_CN', 'en_US', 'summary', 'use_cases', 'connection' );
	public const STATUSES = array( 'queued', 'running', 'retry', 'paused', 'needs_review', 'failed', 'cancelled', 'complete' );
	public const LEASE_SECONDS = 120;
	public const MAX_ATTEMPTS = 3;
	public const REVIEW_PAGE_SIZE = 25;
	private const PROFILE_FIELDS = array( 'id', 'provider', 'base_url', 'model', 'temperature', 'timeout', 'max_output_tokens', 'token_parameter', 'input_price', 'output_price', 'max_cost', 'enabled' );
	public function __construct( private Database $db, private Migrator $migrator ) {}
	private static function now( int $offset = 0 ): string { return gmdate( 'Y-m-d H:i:s', time() + $offset ); }
	private function ready(): void { if ( ! $this->migrator->isCurrent() ) { throw new \DomainException( 'ai_schema_required' ); } }
	private function authorize(): void { Access::write( 'aish_generate_content' ); $this->ready(); }
	private static function background(): void {
		if ( ! ( defined( 'DOING_CRON' ) && DOING_CRON ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { throw new \DomainException( 'ai_background_required' ); }
	}
	private static function code( string $code ): string { if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code ) ) { throw new \InvalidArgumentException( 'ai_invalid_error_code' ); } return $code; }
	private static function json( array $data, int $limit = 1048576 ): string {
		$value = json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( strlen( $value ) > $limit ) { throw new \InvalidArgumentException( 'ai_storage_limit' ); } return $value;
	}
	private static function decode( mixed $value ): array {
		if ( null === $value || '' === $value ) { return array(); }
		if ( ! is_string( $value ) || strlen( $value ) > 1048576 ) { throw new \RuntimeException( 'ai_stored_data_invalid' ); }
		$data = json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) ) { throw new \RuntimeException( 'ai_stored_data_invalid' ); } return $data;
	}
	private function row( string $entity, int $id, bool $lock = false ): array {
		Input::id( $id ); $table = $this->db->table( $entity );
		$row = $this->db->row( "SELECT * FROM $table WHERE id=%d" . ( $lock ? ' FOR UPDATE' : '' ), array( $id ) );
		if ( ! $row ) { throw new \DomainException( 'ai_record_missing' ); } return $row;
	}
	private function advisory( callable $work ): mixed {
		$key = 'aish_ai_store:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 48 );
		if ( 1 !== (int) $this->db->value( 'SELECT GET_LOCK(%s,0)', array( $key ) ) ) { throw new \DomainException( 'ai_queue_busy' ); }
		try { return $work(); } finally { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $key ) ); }
	}

	/** Normalized editorial values, independent of SQL ordering and optional empty rows. */
	public static function translationSnapshot( array $rows ): array {
		$result = array();
		foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
			$found = $rows[ $locale ] ?? array();
			foreach ( $rows as $row ) { if ( is_array( $row ) && ( $row['locale'] ?? '' ) === $locale ) { $found = $row; break; } }
			$result[ $locale ] = array();
			foreach ( array( 'title', 'slug', 'summary', 'content', 'seo_title', 'seo_description' ) as $field ) { $result[ $locale ][ $field ] = (string) ( $found[ $field ] ?? '' ); }
		}
		return $result;
	}
	private function translations( int $skillId, bool $lock = false ): array {
		$table = $this->db->table( 'skill_translations' );
		return $this->db->rows( "SELECT * FROM $table WHERE skill_id=%d ORDER BY locale" . ( $lock ? ' FOR UPDATE' : '' ), array( $skillId ) );
	}
	private function liveSource( array $source ): array {
		$id = Input::id( $source['skill_id'] ?? 0 );
		$skill = $this->row( 'skills', $id, true );
		if ( in_array( $skill['status'], array( 'archived', 'rejected' ), true ) || (int) $skill['revision'] !== (int) ( $source['skill_revision'] ?? 0 ) || (int) $skill['repository_id'] !== (int) ( $source['repository_id'] ?? 0 ) || $skill['source_path'] !== ( $source['source_path'] ?? null ) ) { throw new \DomainException( 'ai_source_or_editorial_changed' ); }
		$repository = $this->row( 'repositories', (int) $skill['repository_id'], true );
		if ( ! empty( $repository['is_archived'] ) ) { throw new \DomainException( 'ai_repository_archived' ); }
		$import = $this->row( 'imports', (int) ( $source['import_id'] ?? 0 ), true );
		if ( (int) $import['skill_id'] !== $id || (int) $import['repository_id'] !== (int) $skill['repository_id'] || $import['source_path'] !== $skill['source_path'] || 'present' !== $import['source_status'] || ! hash_equals( ImportStore::sourceRevision( $import ), (string) ( $source['source_fingerprint'] ?? '' ) ) || $import['commit_sha'] !== ( $source['commit_sha'] ?? '' ) || $import['file_sha'] !== ( $source['file_sha'] ?? '' ) ) { throw new \DomainException( 'ai_source_or_editorial_changed' ); }
		$translations = self::translationSnapshot( $this->translations( $id, true ) );
		if ( self::json( $translations ) !== self::json( self::translationSnapshot( $source['translations'] ?? array() ) ) ) { throw new \DomainException( 'ai_source_or_editorial_changed' ); }
		return $skill;
	}

	public function enqueue( array $source, array $profile, string $mode, string $requestKey, string $promptVersion ): int {
		$this->authorize();
		if ( ! in_array( $mode, self::MODES, true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $requestKey ) ) { throw new \InvalidArgumentException( 'ai_invalid_request' ); }
		if ( 'connection' === $mode ) { Access::write( 'aish_manage_ai_settings' ); $source = array(); }
		$profile = array_intersect_key( $profile, array_flip( self::PROFILE_FIELDS ) );
		if ( empty( $profile['enabled'] ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', (string) ( $profile['id'] ?? '' ) ) ) { throw new \InvalidArgumentException( 'ai_profile_disabled' ); }
		$provider = Input::plain( $profile['provider'] ?? '', 64 ); $model = Input::plain( $profile['model'] ?? '', 191 );
		$promptVersion = Input::plain( $promptVersion, 64 );
		$profileJson = self::json( $profile, 16384 ); $profileHash = Settings::fingerprint( $profile );
		return $this->advisory( function () use ( $source, $profile, $mode, $requestKey, $promptVersion, $provider, $model, $profileJson, $profileHash ): int {
			return $this->db->transaction( function () use ( $source, $profile, $mode, $requestKey, $promptVersion, $provider, $model, $profileJson, $profileHash ): int {
				$table = $this->db->table( 'ai_jobs' );
				$existing = $this->db->row( "SELECT * FROM $table WHERE request_key=%s FOR UPDATE", array( $requestKey ) );
				if ( $existing ) {
					if ( $existing['mode'] !== $mode || (int) ( $existing['skill_id'] ?? 0 ) !== (int) ( $source['skill_id'] ?? 0 ) || $existing['profile_hash'] !== $profileHash ) { throw new \DomainException( 'ai_idempotency_conflict' ); }
					return (int) $existing['id'];
				}
				if ( 'connection' !== $mode ) { $this->liveSource( $source ); }
				$active = hash( 'sha256', 'connection' === $mode ? 'connection:' . $profile['id'] : 'skill:' . $source['skill_id'] );
				$existing = $this->db->row( "SELECT id FROM $table WHERE active_key=%s FOR UPDATE", array( $active ) );
				// A different request key has no durable alias to this job. Reporting it as
				// accepted would allow its later replay to create another paid generation.
				if ( $existing ) { throw new \DomainException( 'ai_other_job_active' ); }
				return $this->db->insert( 'ai_jobs', array( 'skill_id' => $source['skill_id'] ?? null, 'mode' => $mode, 'request_key' => $requestKey, 'active_key' => $active, 'profile_json' => $profileJson, 'profile_hash' => $profileHash, 'provider' => $provider, 'model' => $model, 'prompt_version' => $promptVersion, 'source_json' => self::json( $source ), 'source_commit' => $source['commit_sha'] ?? '', 'file_sha' => $source['file_sha'] ?? '', 'status' => 'queued', 'review_status' => 'pending', 'revision' => 1, 'output_json' => null, 'edited_json' => null, 'attempts' => 0, 'input_tokens' => null, 'output_tokens' => null, 'cost_estimate' => '0.00000000', 'elapsed_ms' => 0, 'requester_id' => get_current_user_id(), 'lease_token' => '', 'locked_until' => null, 'available_at' => self::now(), 'deadline_at' => self::now( 172800 ), 'created_at' => self::now(), 'updated_at' => self::now(), 'completed_at' => null, 'last_error' => '' ) );
			} );
		} );
	}
	private static function unpack( array $job ): array {
		foreach ( array( 'source', 'profile', 'output', 'edited' ) as $key ) { $job[ $key ] = self::decode( $job[ $key . '_json' ] ?? null ); unset( $job[ $key . '_json' ] ); } return $job;
	}
	private function terminal( array $job, string $status, string $code = '' ): void {
		$this->db->updateById( 'ai_jobs', (int) $job['id'], array( 'status' => $status, 'active_key' => null, 'lease_token' => '', 'locked_until' => null, 'last_error' => $code, 'completed_at' => self::now(), 'updated_at' => self::now() ) );
	}
	public function claim(): ?array {
		self::background(); $this->ready();
		return $this->advisory( function (): ?array {
			return $this->db->transaction( function (): ?array {
				$table = $this->db->table( 'ai_jobs' );
				$job = $this->db->row( "SELECT * FROM $table WHERE (status IN ('queued','retry') AND available_at<=%s) OR (status='running' AND locked_until<=%s) ORDER BY available_at,id LIMIT 1 FOR UPDATE", array( self::now(), self::now() ) );
				if ( ! $job ) { return null; }
				$attempts = $this->db->table( 'ai_attempts' );
				$inflight = $this->db->row( "SELECT * FROM $attempts WHERE job_id=%d AND status='inflight' FOR UPDATE", array( (int) $job['id'] ) );
				if ( $inflight ) {
					$this->db->updateById( 'ai_attempts', (int) $inflight['id'], array( 'status' => 'uncertain', 'error_code' => 'ai_delivery_uncertain', 'completed_at' => self::now() ) );
					$this->db->updateById( 'ai_jobs', (int) $job['id'], $this->totals( (int) $job['id'] ) );
					$this->terminal( $job, 'failed', 'ai_delivery_uncertain' ); return null;
				}
				if ( $job['deadline_at'] <= self::now() || (int) $job['attempts'] >= self::MAX_ATTEMPTS ) { $this->terminal( $job, 'failed', 'ai_attempt_limit' ); return null; }
				$token = bin2hex( random_bytes( 32 ) );
				$this->db->updateById( 'ai_jobs', (int) $job['id'], array( 'status' => 'running', 'lease_token' => $token, 'locked_until' => self::now( self::LEASE_SECONDS ), 'updated_at' => self::now() ) );
				return self::unpack( array_merge( $job, array( 'status' => 'running', 'lease_token' => $token ) ) );
			} );
		} );
	}
	private function leased( int $id, string $token ): array {
		self::background(); $this->ready(); $job = $this->row( 'ai_jobs', $id, true );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) || 'running' !== $job['status'] || ! hash_equals( (string) $job['lease_token'], $token ) || $job['locked_until'] <= self::now() || $job['deadline_at'] <= self::now() ) { throw new \DomainException( 'ai_lease_expired' ); } return $job;
	}
	public function assertCurrent( int $id, string $token ): void {
		$this->db->transaction( function () use ( $id, $token ): void { $job = $this->leased( $id, $token ); if ( 'connection' !== $job['mode'] ) { $this->liveSource( self::decode( $job['source_json'] ) ); } } );
	}
	public function beginAttempt( int $id, string $token ): array {
		return $this->db->transaction( function () use ( $id, $token ): array {
			$job = $this->leased( $id, $token );
			// Recheck after prompt construction, within the transaction that records a paid attempt.
			if ( 'connection' !== $job['mode'] ) { $this->liveSource( self::decode( $job['source_json'] ) ); }
			$number = (int) $job['attempts'] + 1;
			if ( $number > self::MAX_ATTEMPTS ) { throw new \DomainException( 'ai_attempt_limit' ); }
			$table = $this->db->table( 'ai_attempts' );
			if ( $this->db->row( "SELECT id FROM $table WHERE job_id=%d AND status='inflight' FOR UPDATE", array( $id ) ) ) { throw new \DomainException( 'ai_attempt_inflight' ); }
			$key = hash( 'sha256', $job['request_key'] . ':' . $number );
			$attempt = $this->db->insert( 'ai_attempts', array( 'job_id' => $id, 'attempt_number' => $number, 'request_key' => $key, 'status' => 'inflight', 'input_tokens' => null, 'output_tokens' => null, 'cost_estimate' => null, 'elapsed_ms' => 0, 'error_code' => '', 'started_at' => self::now(), 'completed_at' => null ) );
			$this->db->updateById( 'ai_jobs', $id, array( 'attempts' => $number, 'updated_at' => self::now() ) );
			return array( 'id' => $attempt, 'request_key' => $key );
		} );
	}
	private function attempt( array $job, int $id ): array {
		$attempt = $this->row( 'ai_attempts', $id, true );
		if ( (int) $attempt['job_id'] !== (int) $job['id'] || 'inflight' !== $attempt['status'] ) { throw new \DomainException( 'ai_attempt_invalid' ); } return $attempt;
	}
	private static function usage( array $usage ): array {
		$clean = array();
		foreach ( array( 'input_tokens', 'output_tokens' ) as $field ) { $value = $usage[ $field ] ?? null; if ( null !== $value && ( ! is_int( $value ) || $value < 0 || $value > 100000000 ) ) { throw new \InvalidArgumentException( 'ai_usage_invalid' ); } $clean[ $field ] = $value; }
		$elapsed = $usage['elapsed_ms'] ?? 0; if ( ! is_int( $elapsed ) || $elapsed < 0 || $elapsed > 3600000 ) { throw new \InvalidArgumentException( 'ai_usage_invalid' ); } $clean['elapsed_ms'] = $elapsed;
		$cost = $usage['cost_estimate'] ?? null;
		if ( null !== $cost && ( ! is_numeric( $cost ) || ! is_finite( (float) $cost ) || (float) $cost < 0 || (float) $cost > 1000000000 ) ) { throw new \InvalidArgumentException( 'ai_usage_invalid' ); }
		$clean['cost_estimate'] = null === $cost ? null : number_format( (float) $cost, 8, '.', '' ); return $clean;
	}
	private function totals( int $id ): array {
		$table = $this->db->table( 'ai_attempts' ); $rows = $this->db->rows( "SELECT input_tokens,output_tokens,cost_estimate,elapsed_ms FROM $table WHERE job_id=%d ORDER BY attempt_number", array( $id ) );
		$result = array( 'input_tokens' => 0, 'output_tokens' => 0, 'cost_estimate' => 0.0, 'elapsed_ms' => 0 );
		foreach ( $rows as $row ) { foreach ( array_keys( $result ) as $key ) { if ( null === ( $row[ $key ] ?? null ) ) { $result[ $key ] = null; } elseif ( null !== $result[ $key ] ) { $result[ $key ] += $row[ $key ]; } } }
		if ( null !== $result['cost_estimate'] ) { $result['cost_estimate'] = number_format( (float) $result['cost_estimate'], 8, '.', '' ); } return $result;
	}
	private static function cleanTranslations( array $translations, string $mode ): array {
		if ( array_diff( array_keys( $translations ), array( 'zh_CN', 'en_US' ) ) ) { throw new \InvalidArgumentException( 'ai_translation_invalid' ); }
		$clean = array();
		foreach ( $translations as $locale => $fields ) {
			if ( ! is_array( $fields ) || array_diff( array_keys( $fields ), array( 'title', 'summary', 'content' ) ) || ( in_array( $mode, array( 'zh_CN', 'en_US' ), true ) && $locale !== $mode ) ) { throw new \InvalidArgumentException( 'ai_translation_invalid' ); }
			foreach ( $fields as $key => $value ) {
				if ( ( 'summary' === $mode && 'summary' !== $key ) || ( 'use_cases' === $mode && 'content' !== $key ) ) { throw new \InvalidArgumentException( 'ai_translation_scope' ); }
				$clean[ $locale ][ $key ] = match ( $key ) { 'title' => Input::plain( $value, 191, true ), 'summary' => Input::plain( $value, 2000, true ), 'content' => wp_kses_post( Input::utf8( $value, 524288 ) ) };
			}
		}
		return $clean;
	}
	public function completeAttempt( int $id, string $token, int $attemptId, array $output, array $translations, array $usage ): void {
		$usage = self::usage( $usage ); $json = self::json( $output );
		$this->db->transaction( function () use ( $id, $token, $attemptId, $output, $translations, $usage, $json ): void {
			$job = $this->leased( $id, $token ); $this->attempt( $job, $attemptId );
			if ( null !== $job['output_json'] && '' !== $job['output_json'] ) { throw new \DomainException( 'ai_output_immutable' ); }
			$clean = self::cleanTranslations( $translations, $job['mode'] );
			$this->db->updateById( 'ai_attempts', $attemptId, $usage + array( 'status' => 'succeeded', 'error_code' => '', 'completed_at' => self::now() ) );
			$this->db->updateById( 'ai_jobs', $id, $this->totals( $id ) + array( 'output_json' => $json, 'edited_json' => self::json( $clean ), 'status' => 'connection' === $job['mode'] ? 'complete' : 'needs_review', 'review_status' => 'pending', 'active_key' => null, 'lease_token' => '', 'locked_until' => null, 'completed_at' => self::now(), 'updated_at' => self::now(), 'last_error' => '' ) );
		} );
	}
	public function failAttempt( int $id, string $token, int $attemptId, string $code, bool $retryable, int $delay = 60, bool $ambiguous = false, array $usage = array() ): void {
		self::code( $code ); $usage = self::usage( $usage );
		if ( $delay < 1 || $delay > 86400 ) { throw new \InvalidArgumentException( 'ai_retry_delay' ); }
		$this->db->transaction( function () use ( $id, $token, $attemptId, $code, $retryable, $delay, $ambiguous, $usage ): void {
			$job = $this->leased( $id, $token ); $this->attempt( $job, $attemptId );
			$this->db->updateById( 'ai_attempts', $attemptId, $usage + array( 'status' => $ambiguous ? 'uncertain' : 'failed', 'error_code' => $code, 'completed_at' => self::now() ) );
			$retry = $retryable && ! $ambiguous && (int) $job['attempts'] < self::MAX_ATTEMPTS && self::now( $delay ) < $job['deadline_at'];
			$update = $this->totals( $id ) + array( 'status' => $retry ? 'retry' : 'failed', 'lease_token' => '', 'locked_until' => null, 'available_at' => self::now( $delay ), 'updated_at' => self::now(), 'last_error' => $code );
			if ( ! $retry ) { $update += array( 'active_key' => null, 'completed_at' => self::now() ); }
			$this->db->updateById( 'ai_jobs', $id, $update );
		} );
	}
	public function fail( int $id, string $token, string $code ): void {
		self::code( $code ); $this->db->transaction( function () use ( $id, $token, $code ): void { $this->terminal( $this->leased( $id, $token ), 'failed', $code ); } );
	}

	public function control( int $id, string $action ): void {
		$this->authorize();
		if ( ! in_array( $action, array( 'pause', 'resume', 'cancel', 'retry' ), true ) ) { throw new \InvalidArgumentException( 'ai_control_invalid' ); }
		$this->advisory( function () use ( $id, $action ): void { $this->db->transaction( function () use ( $id, $action ): void {
			$job = $this->row( 'ai_jobs', $id, true );
			if ( 'connection' === $job['mode'] ) { Access::write( 'aish_manage_ai_settings' ); }
			if ( 'pause' === $action ) {
				if ( ! in_array( $job['status'], array( 'queued', 'retry' ), true ) ) { throw new \DomainException( 'ai_pause_after_current_request' ); }
				$this->db->updateById( 'ai_jobs', $id, array( 'status' => 'paused', 'updated_at' => self::now() ) ); return;
			}
			if ( 'cancel' === $action ) {
				if ( ! in_array( $job['status'], array( 'queued', 'retry', 'paused' ), true ) ) { throw new \DomainException( 'ai_cannot_cancel_inflight' ); }
				$this->terminal( $job, 'cancelled', 'ai_cancelled' ); return;
			}
			if ( ( 'resume' === $action && 'paused' !== $job['status'] ) || ( 'retry' === $action && 'failed' !== $job['status'] ) ) { throw new \DomainException( 'ai_control_state' ); }
			if ( (int) $job['attempts'] >= self::MAX_ATTEMPTS ) { throw new \DomainException( 'ai_attempt_limit_new_request_required' ); }
			if ( 'connection' !== $job['mode'] ) { $this->liveSource( self::decode( $job['source_json'] ) ); }
			$profile = self::decode( $job['profile_json'] ); $active = hash( 'sha256', 'connection' === $job['mode'] ? 'connection:' . $profile['id'] : 'skill:' . $job['skill_id'] );
			$table = $this->db->table( 'ai_jobs' ); $other = $this->db->row( "SELECT id FROM $table WHERE active_key=%s FOR UPDATE", array( $active ) );
			if ( $other && (int) $other['id'] !== $id ) { throw new \DomainException( 'ai_other_job_active' ); }
			$this->db->updateById( 'ai_jobs', $id, array( 'status' => 'queued', 'active_key' => $active, 'available_at' => self::now( 60 ), 'deadline_at' => self::now( 172800 ), 'completed_at' => null, 'lease_token' => '', 'locked_until' => null, 'updated_at' => self::now() ) );
		} ); } );
	}
	public function listJobs( int $page = 1, string $status = '', int $skillId = 0 ): array {
		$this->authorize();
		if ( $page < 1 || $page > 10000 || ( '' !== $status && ! in_array( $status, self::STATUSES, true ) ) || $skillId < 0 ) { throw new \InvalidArgumentException( 'ai_list_invalid' ); }
		$where = array( '1=1' ); $values = array();
		if ( '' !== $status ) { $where[] = 'status=%s'; $values[] = $status; } if ( $skillId ) { $where[] = 'skill_id=%d'; $values[] = $skillId; }
		$table = $this->db->table( 'ai_jobs' ); $where = implode( ' AND ', $where );
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $table WHERE $where", $values );
		$items = $this->db->rows( "SELECT id,skill_id,mode,provider,model,prompt_version,status,review_status,revision,attempts,input_tokens,output_tokens,cost_estimate,elapsed_ms,last_error,created_at,updated_at FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( 25, ( $page - 1 ) * 25 ) ) );
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => 25 );
	}
	/** History lists contain metadata only; large immutable snapshots are read one at a time. */
	public function job( int $id, int $reviewPage = 1 ): array {
		if ( $reviewPage < 1 || $reviewPage > 10000 ) { throw new \InvalidArgumentException( 'ai_review_page_invalid' ); }
		$this->authorize(); $job = self::unpack( $this->row( 'ai_jobs', $id ) ); unset( $job['lease_token'] );
		$attempts = $this->db->table( 'ai_attempts' ); $reviews = $this->db->table( 'ai_reviews' );
		$job['attempts_history'] = $this->db->rows( "SELECT * FROM $attempts WHERE job_id=%d ORDER BY attempt_number", array( $id ) );
		$job['reviews_total'] = (int) $this->db->value( "SELECT COUNT(*) FROM $reviews WHERE job_id=%d", array( $id ) );
		$job['reviews_page'] = $reviewPage;
		$job['reviews_per_page'] = self::REVIEW_PAGE_SIZE;
		$job['reviews'] = $this->db->rows( "SELECT id,job_id,revision,action,actor_id,created_at FROM $reviews WHERE job_id=%d ORDER BY revision DESC LIMIT %d OFFSET %d", array( $id, self::REVIEW_PAGE_SIZE, ( $reviewPage - 1 ) * self::REVIEW_PAGE_SIZE ) );
		$job['current_translations'] = empty( $job['skill_id'] ) ? array() : self::translationSnapshot( $this->translations( (int) $job['skill_id'] ) ); return $job;
	}
	public function reviewSnapshot( int $jobId, int $reviewId ): array {
		$this->authorize(); Input::id( $jobId ); Input::id( $reviewId );
		$table = $this->db->table( 'ai_reviews' );
		$review = $this->db->row( "SELECT id,job_id,revision,action,actor_id,created_at,snapshot_json FROM $table WHERE job_id=%d AND id=%d", array( $jobId, $reviewId ) );
		if ( ! $review ) { throw new \DomainException( 'ai_review_missing' ); }
		$review['snapshot'] = self::decode( $review['snapshot_json'] ); unset( $review['snapshot_json'] );
		return $review;
	}
	private function reviewable( int $id, int $revision ): array {
		$this->authorize(); Access::write( 'aish_review_skills' );
		$job = $this->row( 'ai_jobs', $id, true );
		if ( 'needs_review' !== $job['status'] || ! in_array( $job['review_status'], array( 'pending', 'edited' ), true ) || (int) $job['revision'] !== $revision ) { throw new \DomainException( 'ai_draft_changed_or_closed' ); } return $job;
	}
	private function reviewEvent( array $job, string $action, array $snapshot ): void {
		$this->db->insert( 'ai_reviews', array( 'job_id' => (int) $job['id'], 'revision' => (int) $job['revision'] + 1, 'action' => $action, 'actor_id' => get_current_user_id(), 'snapshot_json' => self::json( $snapshot ), 'created_at' => self::now() ) );
	}
	public function saveReview( int $id, int $revision, array $translations ): void {
		$this->db->transaction( function () use ( $id, $revision, $translations ): void {
			$job = $this->reviewable( $id, $revision ); $clean = self::cleanTranslations( $translations, $job['mode'] );
			$this->reviewEvent( $job, 'edited', $clean );
			$this->db->updateById( 'ai_jobs', $id, array( 'edited_json' => self::json( $clean ), 'review_status' => 'edited', 'revision' => $revision + 1, 'updated_at' => self::now() ) );
		} );
	}
	public function reject( int $id, int $revision ): void {
		$this->db->transaction( function () use ( $id, $revision ): void {
			$job = $this->reviewable( $id, $revision ); $this->reviewEvent( $job, 'rejected', self::decode( $job['edited_json'] ) );
			$this->db->updateById( 'ai_jobs', $id, array( 'review_status' => 'rejected', 'revision' => $revision + 1, 'updated_at' => self::now() ) );
		} );
	}
	public function accept( int $id, int $revision, array $fields ): void {
		Access::write( 'aish_manage_skills' );
		if ( ! $fields || count( $fields ) > 6 || array_filter( $fields, static fn( mixed $value ): bool => ! is_string( $value ) ) || count( array_unique( $fields ) ) !== count( $fields ) ) { throw new \InvalidArgumentException( 'ai_selection_invalid' ); }
		$this->db->transaction( function () use ( $id, $revision, $fields ): void {
			$job = $this->reviewable( $id, $revision ); if ( 'use_cases' === $job['mode'] ) { throw new \DomainException( 'ai_partial_body_requires_editor' ); }
			$source = self::decode( $job['source_json'] ); $skill = $this->liveSource( $source );
			$draft = self::cleanTranslations( self::decode( $job['edited_json'] ), $job['mode'] );
			$existing = array(); foreach ( $this->translations( (int) $skill['id'], true ) as $row ) { $existing[ $row['locale'] ] = $row; }
			$patch = array();
			foreach ( $fields as $selected ) {
				if ( ! is_string( $selected ) || ! preg_match( '/^(zh_CN|en_US)\.(title|summary|content)$/D', $selected, $match ) ) { throw new \InvalidArgumentException( 'ai_selection_invalid' ); }
				list( , $locale, $field ) = $match;
				if ( ! isset( $draft[ $locale ][ $field ] ) || '' === trim( $draft[ $locale ][ $field ] ) ) { throw new \InvalidArgumentException( 'ai_selected_field_empty' ); }
				if ( '' !== (string) ( $existing[ $locale ][ $field ] ?? '' ) ) { throw new \DomainException( 'ai_human_content_protected' ); }
				$patch[ $locale ][ $field ] = $draft[ $locale ][ $field ];
			}
			foreach ( $patch as $locale => $values ) {
				$values['updated_at'] = self::now();
				if ( isset( $existing[ $locale ] ) ) { $this->db->updateById( 'skill_translations', (int) $existing[ $locale ]['id'], $values ); }
				else { $this->db->insert( 'skill_translations', $values + array( 'skill_id' => (int) $skill['id'], 'locale' => $locale, 'title' => '', 'slug' => null, 'summary' => '', 'content' => '', 'seo_title' => '', 'seo_description' => '', 'created_at' => self::now() ) ); }
			}
			$this->db->updateById( 'skills', (int) $skill['id'], array( 'status' => 'pending', 'review_status' => 'pending', 'revision' => (int) $skill['revision'] + 1, 'updated_at' => self::now() ) );
			$this->reviewEvent( $job, 'accepted', array( 'fields' => $fields, 'translations' => $patch, 'skill_revision_before' => (int) $skill['revision'], 'skill_revision_after' => (int) $skill['revision'] + 1 ) );
			$this->db->updateById( 'ai_jobs', $id, array( 'review_status' => 'accepted', 'revision' => $revision + 1, 'updated_at' => self::now() ) );
		} );
	}
}
