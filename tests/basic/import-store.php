<?php
declare(strict_types=1);
// Isolated in-memory SQL adapter contracts. Does not run MySQL, WordPress or GitHub.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DOING_CRON', true );
function get_option( string $key, mixed $default = false ): mixed { return 'aish_schema_version' === $key ? \AISkillsHub\Core\Database\Schema::VERSION : $default; }
function current_user_can( string $cap ): bool { return $GLOBALS['allow_import_admin'] ?? true; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
final class wpdb {
	public string $prefix = 't_'; public string $last_error = ''; public int $insert_id = 0;
	public array $tables = array(); public array $prepared = array(); public array $executed = array(); public int $failFinish = 0; public int $failQueuedRepository = 0; public bool $failBindingRead = false; private ?array $snapshot = null;
	public function suppress_errors( bool $value ): bool { return false; }
	public function prepare( string $sql, array $args ): string { $this->prepared[] = array( $sql, $args ); return 'prepared:' . ( count( $this->prepared ) - 1 ); }
	private function unpack( string $sql ): array { return str_starts_with( $sql, 'prepared:' ) ? $this->prepared[ (int) substr( $sql, 9 ) ] : array( $sql, array() ); }
	private function entity( string $table ): string { return str_replace( array( '`', 't_aish_' ), '', $table ); }
	public function insert( string $table, array $data, array $formats ): int {
		$table = $this->entity( $table ); $id = count( $this->tables[ $table ] ?? array() ) + 1;
		$this->tables[ $table ][ $id ] = array( 'id' => $id ) + $data;
		$this->insert_id = $id; $this->executed[] = array( 'insert', $table, $data ); return 1;
	}
	public function update( string $table, array $data, array $where, array $formats, array $whereFormats ): int {
		$table = $this->entity( $table ); $id = $where['id'];
		if ( 'jobs' === $table && 'complete' === ( $data['status'] ?? '' ) && $this->failFinish > 0 ) { --$this->failFinish; throw new RuntimeException( 'Fixture finalization write failed.' ); }
		if ( 'repositories' === $table && 'queued' === ( $data['sync_status'] ?? '' ) && $this->failQueuedRepository > 0 ) { --$this->failQueuedRepository; throw new RuntimeException( 'Fixture repository checkpoint failed.' ); }
		if ( ! isset( $this->tables[ $table ][ $id ] ) ) { return 0; }
		$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $data );
		$this->executed[] = array( 'update', $table, $data ); return 1;
	}
	public function get_results( string $prepared, string $format ): array {
		list( $sql, $args ) = $this->unpack( $prepared ); $this->executed[] = array( $sql, $args );
		preg_match( '/FROM `t_aish_([a-z_]+)`/', $sql, $match ); $table = $match[1] ?? '';
		$rows = array_values( $this->tables[ $table ] ?? array() );
		if ( str_contains( $sql, 'SELECT 1 AS missing_binding' ) ) {
			if ( $this->failBindingRead ) { throw new RuntimeException( 'Fixture binding query failed.' ); }
			foreach ( $rows as $row ) {
				if ( $row['repository_id'] !== $args[0] || 'present' !== $row['source_status'] ) { continue; }
				$matches = array_filter( $this->tables['skill_versions'] ?? array(), fn( $version ) => $version['skill_id'] === $row['skill_id'] && $version['commit_sha'] === $row['commit_sha'] && $version['file_sha'] === $row['file_sha'] && $version['repository_id'] === $row['repository_id'] && $version['source_path'] === $row['source_path'] );
				if ( ! $matches ) { return array( array( 'missing_binding' => 1 ) ); }
			}
			return array();
		}
		if ( str_contains( $sql, 'SELECT v.source_content' ) ) {
            foreach ( $rows as $row ) { if ( $row['repository_id'] === $args[0] && $row['source_path'] === $args[1] && $row['file_sha'] === $args[2] ) { foreach ( $this->tables['skill_versions'] as $version ) { if ( $version['skill_id'] === $row['skill_id'] && $version['commit_sha'] === $row['commit_sha'] && ( ! str_contains( $sql, 'v.file_sha=i.file_sha' ) || ( $version['file_sha'] === $row['file_sha'] && $version['repository_id'] === $row['repository_id'] && $version['source_path'] === $row['source_path'] ) ) ) { return array( array( 'source_content' => $version['source_content'] ) ); } } } } return array();
        }
        if ( 'import_logs' === $table && str_contains( $sql, 'code IN (' ) ) { return array_slice( array_values( array_filter( $rows, fn( $row ) => $row['job_id'] === $args[0] && $row['source_path'] === $args[1] && in_array( $row['code'], array( 'source_new', 'source_updated', 'source_unchanged' ), true ) ) ), 0, 1 ); }
        if ( 'import_logs' === $table && str_contains( $sql, 'COALESCE(source_path' ) ) { return array_values( array_filter( $rows, fn( $row ) => $row['job_id'] === $args[0] && $row['code'] === $args[1] && ( $row['source_path'] ?? '' ) === $args[2] ) ); }
		if ( 'jobs' === $table && str_contains( $sql, 'WHERE repository_id=%d AND id<%d' ) ) {
			$rows = array_values( array_filter( $rows, fn( $row ) => $row['repository_id'] === $args[0] && $row['id'] < $args[1] ) );
			usort( $rows, fn( $left, $right ) => $right['id'] <=> $left['id'] ); return array_slice( $rows, 0, 1 );
		}
        if ( str_contains( $sql, 'WHERE id=%d' ) ) { return array_values( array_filter( $rows, fn( $row ) => $row['id'] === $args[0] ) ); }
		if ( str_contains( $sql, 'WHERE active_key=%d' ) ) { return array_values( array_filter( $rows, fn( $row ) => ( $row['active_key'] ?? null ) === $args[0] ) ); }
		if ( str_contains( $sql, 'WHERE repository_id=%d AND source_path=%s' ) ) { return array_values( array_filter( $rows, fn( $row ) => $row['repository_id'] === $args[0] && $row['source_path'] === $args[1] ) ); }
		if ( str_contains( $sql, 'WHERE skill_id=%d AND commit_sha=%s' ) ) { return array_values( array_filter( $rows, fn( $row ) => $row['skill_id'] === $args[0] && $row['commit_sha'] === $args[1] ) ); }
		if ( str_contains( $sql, 'WHERE repository_id=%d AND file_sha=%s' ) ) { return array_values( array_filter( $rows, fn( $row ) => $row['repository_id'] === $args[0] && $row['file_sha'] === $args[1] && $row['skill_id'] !== $args[2] ) ); }
		if ( str_contains( $sql, "status IN ('queued','retry')" ) ) { return array_slice( array_values( array_filter( $rows, fn( $row ) => ( in_array( $row['status'], array( 'queued', 'retry' ), true ) && $row['available_at'] <= $args[0] ) || ( 'running' === $row['status'] && $row['locked_until'] <= $args[1] ) ) ), 0, 1 ); }
		return $rows;
	}
	public function get_var( string $prepared ): mixed {
		list( $sql, $args ) = $this->unpack( $prepared ); $this->executed[] = array( $sql, $args );
		if ( str_contains( $sql, 'GET_LOCK(' ) || str_contains( $sql, 'RELEASE_LOCK(' ) ) { return 1; }
		if ( str_contains( $sql, 'MAX(created_at)' ) ) { return null; }
		if ( str_contains( $sql, 'import_logs' ) ) { return count( array_filter( $this->tables['import_logs'] ?? array(), fn( $row ) => $row['job_id'] === $args[0] ) ); }
		if ( str_contains( $sql, "status='running'" ) ) { return count( array_filter( $this->tables['jobs'] ?? array(), fn( $row ) => 'running' === $row['status'] && $row['locked_until'] > $args[0] ) ); }
		return 0;
	}
	public function query( string $prepared ): int {
		list( $sql, $args ) = $this->unpack( $prepared ); $this->executed[] = array( $sql, $args );
		if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $this->tables; return 1; }
		if ( 'COMMIT' === $sql ) { $this->snapshot = null; return 1; }
		if ( 'ROLLBACK' === $sql ) { if ( null !== $this->snapshot ) { $this->tables = $this->snapshot; } $this->snapshot = null; return 1; }
		if ( str_contains( $sql, "SET source_status='missing'" ) ) {
			$count = 0;
			foreach ( $this->tables['imports'] ?? array() as $id => $row ) {
				if ( $row['repository_id'] === $args[1] && ( $row['last_seen_job'] ?? 0 ) !== $args[2] && 'missing' !== $row['source_status'] ) { $this->tables['imports'][ $id ] = array_merge( $row, array( 'source_status' => 'missing', 'change_status' => 'missing', 'updated_at' => $args[0] ) ); ++$count; }
			}
			return $count;
		}
		if ( str_contains( $sql, 'SET last_seen_job=%d' ) ) {
			$pathFilter = str_contains( $sql, 'source_path IN (' ) ? array_slice( $args, 2 ) : array( $args[3] );
			$repositoryId = str_contains( $sql, 'source_path IN (' ) ? $args[1] : $args[2];
			foreach ( $this->tables['imports'] ?? array() as $id => $row ) {
				if ( $row['repository_id'] === $repositoryId && in_array( $row['source_path'], $pathFilter, true ) ) {
					$this->tables['imports'][ $id ]['last_seen_job'] = $args[0];
					if ( str_contains( $sql, 'source_status=\'present\'' ) ) { $this->tables['imports'][ $id ]['source_status'] = 'present'; if ( 'missing' === $row['source_status'] ) { $this->tables['imports'][ $id ]['change_status'] = 'restored'; } }
				}
			}
		}
		return 1;
	}
}
$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Security/Input.php', 'Security/Access.php', 'Sources/ImportStore.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Database\Schema;
use AISkillsHub\Core\Sources\ImportStore;
$count = 0;
function check( bool $condition, string $message ): void { global $count; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$count; }
function rejects( callable $call, string $message ): void { try { $call(); } catch ( InvalidArgumentException | DomainException $error ) { check( true, $message ); return; } throw new RuntimeException( $message ); }
function blobSha( string $raw ): string { return sha1( 'blob ' . strlen( $raw ) . "\0" . $raw ); }
function runningJob( wpdb $wpdb, int $id, array $state = array() ): string {
	$token = str_repeat( dechex( $id ), 64 );
	$wpdb->tables['jobs'][ $id ] = array( 'id' => $id, 'repository_id' => 1, 'kind' => 'sync', 'status' => 'running', 'active_key' => 1, 'state_json' => json_encode( $state ), 'lease_token' => $token, 'attempts' => 0, 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 120 ), 'deadline_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ); return $token;
}
$wpdb = new wpdb(); $db = new Database( $wpdb ); $store = new ImportStore( $db, new Migrator( $db ) );
$wpdb->tables['repositories'][1] = array( 'id' => 1, 'owner' => 'owner', 'name' => 'repo', 'is_archived' => 0, 'sync_enabled' => 1, 'last_sync_commit' => '', 'license_spdx' => 'MIT' );
$commit1 = str_repeat( 'a', 40 ); $commit2 = str_repeat( 'b', 40 ); $raw = "---\nname: sample\ndescription: Example\n---\nBody";
$token = runningJob( $wpdb, 1 ); $parsed = array( 'name' => 'sample', 'description' => 'Example', 'body' => 'Body', 'metadata' => array() );
$metadata = array( 'id' => 42, 'owner' => 'owner', 'name' => 'repo', 'private' => false, 'default_branch' => 'main', 'license' => 'Apache-2.0' );
$store->updateRepository( 1, $token, $metadata );
check( 'MIT' === $wpdb->tables['repositories'][1]['license_spdx'], 'Fetching repository metadata cannot advance the successful license baseline.' );
check( 42 === $wpdb->tables['repositories'][1]['github_id'] && 'main' === $wpdb->tables['repositories'][1]['default_branch'], 'Repository identity and branch are still recorded before scanning.' );
check( ! $store->baselineReusable( 1, $token, $commit1 ), 'A repository with no previous completed job cannot skip scanning.' );
$result = $store->record( 1, $token, 'sample/SKILL.md', blobSha( $raw ), $commit1, $raw, $parsed, array( 'risk_level' => 'unknown' ) );
check( $result === array( 'skill_id' => 1, 'change' => 'new' ), 'New source uses a stable Skill ID.' );
check( 'pending' === $wpdb->tables['skills'][1]['status'] && 'pending' === $wpdb->tables['skills'][1]['review_status'], 'Imported Skills are pending, never published.' );
check( empty( $wpdb->tables['skill_translations'] ), 'No generated translations are written.' );
check( count( $wpdb->tables['skill_versions'] ) === 1 && $wpdb->tables['skill_versions'][1]['file_sha'] === blobSha( $raw ), 'Immutable version records File and Commit SHA.' );
check( $wpdb->tables['skill_versions'][1]['source_path'] === 'sample/SKILL.md' && $wpdb->tables['skill_versions'][1]['repository_id'] === 1, 'Version snapshots retain source binding.' );
check( ! str_contains( $wpdb->tables['imports'][1]['metadata_json'], '"body"' ), 'Source body is not duplicated into metadata.' );
$repeat = $store->record( 1, $token, 'sample/SKILL.md', blobSha( $raw ), $commit1, $raw, $parsed, array( 'risk_level' => 'unknown' ) );
check( $repeat['skill_id'] === 1 && $repeat['change'] === 'new' && count( $wpdb->tables['skills'] ) === 1 && count( $wpdb->tables['skill_versions'] ) === 1, 'Replaying a completed file step preserves its original result and does not duplicate data.' );
check( count( $wpdb->tables['import_logs'] ) === 1, 'Replaying a source step does not duplicate its log.' );
$wpdb->tables['skills'][1]['status'] = 'published'; $wpdb->tables['skills'][1]['revision'] = 7; $wpdb->tables['skills'][1]['review_status'] = 'approved';
$wpdb->tables['skill_translations'][1] = array( 'skill_id' => 1, 'locale' => 'en_US', 'slug' => 'editor-slug', 'content' => 'Human text' );
$originalSkill = $wpdb->tables['skills'][1]; $originalTranslation = $wpdb->tables['skill_translations'][1];
$updatedRaw = $raw . "\nNew source";
$changed = $store->record( 1, $token, 'sample/SKILL.md', blobSha( $updatedRaw ), $commit2, $updatedRaw, $parsed, array() );
check( $changed['skill_id'] === 1 && 'updated' === $changed['change'], 'An updated file keeps the original Skill ID.' );
check( $wpdb->tables['skills'][1] === $originalSkill && $wpdb->tables['skill_translations'][1] === $originalTranslation, 'Source updates preserve published status, review revision, slug and human translation.' );
check( count( $wpdb->tables['skill_versions'] ) === 2 && $wpdb->tables['skill_versions'][1]['source_content'] === $raw, 'Source history is immutable.' );
rejects( fn() => $store->record( 1, $token, 'sample/SKILL.md', blobSha( $raw ), $commit2, $raw, $parsed, array() ), 'Same commit with conflicting content is rejected.' );
check( count( $wpdb->tables['skill_versions'] ) === 2, 'Conflict rollback retains existing history.' );
rejects( fn() => $store->record( 1, $token, 'sample/SKILL.md', str_repeat( 'c', 40 ), $commit2, $raw, $parsed, array() ), 'Blob SHA mismatch is rejected.' );
rejects( fn() => $store->record( 1, $token, '../SKILL.md', blobSha( $raw ), $commit1, $raw, $parsed, array() ), 'Traversal paths are rejected.' );
rejects( fn() => $store->record( 1, $token, 'sample/readme.md', blobSha( $raw ), $commit1, $raw, $parsed, array() ), 'Non SKILL files cannot create resources.' );
rejects( fn() => $store->record( 1, str_repeat( '0', 64 ), 'other/SKILL.md', blobSha( $raw ), $commit1, $raw, $parsed, array() ), 'A stale worker token cannot write.' );
$wpdb->tables['jobs'][1]['locked_until'] = '2000-01-01 00:00:00';
rejects( fn() => $store->seen( 1, $token, 'sample/SKILL.md' ), 'Expired leases cannot mutate inventory.' );
$token = runningJob( $wpdb, 2, array( 'inventory_complete' => false, 'files_complete' => false, 'errors' => 1, 'commit_sha' => $commit2 ) );
rejects( fn() => $store->completeInventory( 2, $token, array(), $commit2 ), 'Incomplete scan cannot mark existing sources missing.' );
check( 'present' === $wpdb->tables['imports'][1]['source_status'], 'Failed inventory preserves existing source state.' );
$state = array( 'inventory_complete' => true, 'files_complete' => true, 'errors' => 0, 'commit_sha' => $commit2, 'repository' => $metadata );
rejects( fn() => $store->finish( 2, $token, $state ), 'Complete requires a durable inventory reconciliation.' );
rejects( fn() => $store->finish( 2, $token, $state + array( 'unchanged_head' => true ) ), 'Unchanged-head skip requires a previous successful baseline.' );
$wpdb->tables['jobs'][2]['state_json'] = json_encode( $state );
check( 1 === $store->completeInventory( 2, $token, array(), $commit2 ), 'Only a complete inventory can mark a missing source.' );
check( 'missing' === $wpdb->tables['imports'][1]['source_status'] && $wpdb->tables['skills'][1] === $originalSkill, 'Source removal never deletes or archives published content.' );
check( 1 === $store->completeInventory( 2, $token, array(), $commit2 ), 'Reconciliation restart preserves its original missing count.' );
$store->finish( 2, $token, $state );
check( 'complete' === $wpdb->tables['jobs'][2]['status'] && null === $wpdb->tables['jobs'][2]['active_key'], 'Successful jobs release their unique active key.' );
check( $wpdb->tables['repositories'][1]['last_sync_commit'] === $commit2, 'Successful baseline is recorded only at completion.' );
check( 'Apache-2.0' === $wpdb->tables['repositories'][1]['license_spdx'], 'Only successful completion atomically promotes the inspected license with its commit baseline.' );
$token = runningJob( $wpdb, 3 ); $store->seen( 3, $token, 'sample/SKILL.md' );
check( $store->baselineReusable( 3, $token, $commit2 ), 'A directly preceding complete job and matching Commit form a reusable baseline.' );
check( ! $store->baselineReusable( 3, $token, $commit1 ), 'A different Commit is never a reusable baseline.' );
check( 'restored' === $wpdb->tables['imports'][1]['change_status'] && 'present' === $wpdb->tables['imports'][1]['source_status'], 'Reappearing source requires manual handling.' );
$metadata['license'] = 'BSD-3-Clause'; $store->updateRepository( 3, $token, $metadata );
$store->retry( 3, $token, array( 'stage' => 'tree' ), 'api_rate_limited', 120 );
check( 'retry' === $wpdb->tables['jobs'][3]['status'] && '' === $wpdb->tables['jobs'][3]['lease_token'] && 1 === $wpdb->tables['jobs'][3]['attempts'], 'Retry persists state and releases the lease.' );
check( $wpdb->tables['repositories'][1]['last_sync_commit'] === $commit2, 'Failures do not advance a successful baseline.' );
check( 'Apache-2.0' === $wpdb->tables['repositories'][1]['license_spdx'], 'A failed scan after metadata refresh retains the prior successful license for the next retry.' );
$token = runningJob( $wpdb, 4 ); $wpdb->tables['jobs'][4]['attempts'] = 4;
check( ! $store->baselineReusable( 4, $token, $commit2 ), 'An intervening partial retry invalidates the fast path even when the last successful Commit matches.' );
$dirtyState = $state + array( 'unchanged_head' => true );
rejects( fn() => $store->finish( 4, $token, $dirtyState ), 'Completion rechecks the previous-job chain before accepting unchanged-head shortcuts.' );
$store->retry( 4, $token, array(), 'network_error', 120 );
check( 'failed' === $wpdb->tables['jobs'][4]['status'] && null === $wpdb->tables['jobs'][4]['active_key'], 'Retries have a bounded budget.' );
$token = runningJob( $wpdb, 5 );
check( ! $store->baselineReusable( 5, $token, $commit2 ), 'Failed partial imports cannot be hidden by an upstream rollback to the last successful Commit.' );
rejects( fn() => $store->baselineReusable( 5, str_repeat( '0', 64 ), $commit2 ), 'Fast-path checks require the active worker lease.' );
rejects( fn() => $store->checkpoint( 5, $token, array( 'large' => str_repeat( 'x', ImportStore::STATE_BYTES ) ) ), 'Checkpoint size is bounded.' );
rejects( fn() => $store->log( 5, $token, 'token=secret' ), 'Logs accept fixed event codes, not raw error/credential text.' );
rejects( fn() => $store->markHandled( 1, $commit1, ImportStore::sourceRevision( $wpdb->tables['imports'][1] ) ), 'Acknowledgement detects an outdated commit.' );
$store->markHandled( 1, $commit2, ImportStore::sourceRevision( $wpdb->tables['imports'][1] ) );
check( 'handled' === $wpdb->tables['imports'][1]['change_status'] && $wpdb->tables['skills'][1] === $originalSkill, 'Acknowledgement does not publish or modify a Skill.' );
check( $store->sourceContent( 1, 'sample/SKILL.md', blobSha( $updatedRaw ) ) === $updatedRaw, 'An unchanged blob can reuse its verified private snapshot.' );
$oldRevision = ImportStore::sourceRevision( $wpdb->tables['imports'][1] );
$token6 = runningJob( $wpdb, 6 );
$riskUpdate = array( 'risk_level' => 'high', 'inspection_commit_sha' => str_repeat( 'c', 40 ) );
$updated = $store->record( 6, $token6, 'sample/SKILL.md', blobSha( $updatedRaw ), str_repeat( 'c', 40 ), $updatedRaw, $parsed, $riskUpdate );
check( 'updated' === $updated['change'] && 'inspection_updated' === $wpdb->tables['imports'][1]['change_status'], 'A changed dependency or license inspection requires manual handling even when the Skill blob is unchanged.' );
check( count( $wpdb->tables['skill_versions'] ) === 2 && $wpdb->tables['imports'][1]['commit_sha'] === $commit2, 'Inspection changes do not duplicate source versions or rewrite their commit identity.' );
rejects( fn() => $store->markHandled( 1, $commit2, $oldRevision ), 'Same-commit changes invalidate a stale acknowledgement fingerprint.' );
$store->markHandled( 1, $commit2, ImportStore::sourceRevision( $wpdb->tables['imports'][1] ) );
$token7 = runningJob( $wpdb, 7 ); $riskUpdate['inspection_commit_sha'] = str_repeat( 'd', 40 );
$unchanged = $store->record( 7, $token7, 'sample/SKILL.md', blobSha( $updatedRaw ), str_repeat( 'd', 40 ), $updatedRaw, $parsed, $riskUpdate );
check( 'unchanged' === $unchanged['change'] && 'handled' === $wpdb->tables['imports'][1]['change_status'], 'A new inspection commit alone does not reopen an unchanged reviewed notification.' );
check( $wpdb->tables['skills'][1] === $originalSkill && $wpdb->tables['skill_translations'][1] === $originalTranslation, 'Risk refresh still never overwrites approved editorial content.' );
check( null === $store->claim(), 'Global background concurrency has a two-lease ceiling.' );
foreach ( $wpdb->tables['jobs'] as &$job ) { $job['status'] = 'complete'; $job['active_key'] = null; } unset( $job );
$wpdb->tables['jobs'][5]['status'] = 'queued'; $wpdb->tables['jobs'][5]['active_key'] = 1;
check( 5 === $store->enqueue( 1 ), 'Repeated scheduling returns the active repository job.' );
$claimed = $store->claim();
check( 5 === $claimed['id'] && 64 === strlen( $claimed['lease_token'] ) && $claimed['lease_token'] !== $token, 'Claim fences an existing queued job with a fresh token.' );
$store->checkpoint( 5, $claimed['lease_token'], array( 'stage' => 'tree' ) );
check( 'queued' === $wpdb->tables['jobs'][5]['status'] && '' === $wpdb->tables['jobs'][5]['lease_token'], 'A resumable checkpoint releases its worker lease.' );
rejects( fn() => $store->jobs( 1, 0, 'complete OR 1=1' ), 'Job status filters are allowlisted.' );
rejects( fn() => $store->imports( 1, 0, 'unknown' ), 'Source change filters are allowlisted.' );
$jobPage = $store->jobs();
check( ! isset( $jobPage['items'][0]['state_json'], $jobPage['items'][0]['state'], $jobPage['items'][0]['lease_token'] ), 'Task lists never load/decode checkpoint bodies or expose lease tokens.' );
$sql = end( $wpdb->prepared )[0];
check( ! str_contains( $sql, 'SELECT *' ) && ! str_contains( $sql, 'state_json' ), 'Job list SQL projects only small summary columns.' );
$wpdb->tables['repositories'][1]['is_archived'] = 1;
rejects( fn() => $store->configureRepository( 1, false, 3600 ), 'Bulk disabling cannot implicitly restore an archived repository.' );
check( 1 === $wpdb->tables['repositories'][1]['is_archived'], 'Failed bulk configuration preserves the archived flag.' );
$wpdb->tables['repositories'][1]['is_archived'] = 0;
$store->configureRepository( 1, false, 3600 );
check( 0 === $wpdb->tables['repositories'][1]['sync_enabled'] && 3600 === $wpdb->tables['repositories'][1]['sync_interval'] && 0 === $wpdb->tables['repositories'][1]['is_archived'], 'Active repository configuration updates scheduling only.' );
$sameSecondRevision = ImportStore::sourceRevision( $wpdb->tables['imports'][1] );
$wpdb->tables['imports'][1]['findings_json'] = json_encode( array( 'risk_level' => 'high', 'findings' => array( array( 'code' => 'new_network_access' ) ) ) );
rejects( fn() => $store->markHandled( 1, $commit2, $sameSecondRevision ), 'Changed findings invalidate acknowledgements even within the same second and commit.' );
$detailCopy = $wpdb->tables['imports'][1];
$detailCopy['metadata'] = json_decode( $detailCopy['metadata_json'], true ); $detailCopy['findings'] = json_decode( $detailCopy['findings_json'], true );
unset( $detailCopy['metadata_json'], $detailCopy['findings_json'] );
check( ImportStore::sourceRevision( $detailCopy ) === ImportStore::sourceRevision( $wpdb->tables['imports'][1] ), 'Revision is consistent for decoded private detail payloads.' );
$GLOBALS['allow_import_admin'] = false;
rejects( fn() => $store->enqueue( 1 ), 'Logged-out/unauthorized callers cannot enqueue.' );
rejects( fn() => $store->markHandled( 1, $commit2, ImportStore::sourceRevision( $wpdb->tables['imports'][1] ) ), 'Acknowledgement requires review capability.' );
$GLOBALS['allow_import_admin'] = true;
$legacy = Schema::definitions( $db, '1.3.0' ); $current = Schema::definitions( $db, '1.4.0' );
check( count( $legacy ) === 8 && count( $current ) === 11, 'Migration extends original tables with three operational tables.' );
foreach ( array( 'skills', 'skill_translations', 'categories', 'tags', 'skill_categories', 'skill_tags' ) as $table ) { check( $legacy[ $table ] === $current[ $table ], 'Core content table is unchanged: ' . $table ); }
check( str_contains( $current['jobs'], 'UNIQUE KEY active_repository (active_key)' ), 'Database uniqueness prevents duplicate active repository jobs.' );
check( str_contains( $current['skills'], 'UNIQUE KEY skill_source (repository_id,source_path)' ), 'Stable source identity constraint remains unchanged.' );
check( str_contains( $current['imports'], 'UNIQUE KEY import_source (repository_id,source_path)' ), 'Import metadata uses the same stable source identity.' );
check( str_contains( $current['repositories'], 'github_id bigint(20) unsigned DEFAULT NULL' ), 'Existing repository rows keep a nullable external identity.' );

// The reconciliation transaction can commit before a later finalization write fails.
// Exercise the real store, rather than a worker fixture which counts the same missing source repeatedly.
$recoveryDb = new wpdb(); $recoveryDal = new Database( $recoveryDb ); $recovery = new ImportStore( $recoveryDal, new Migrator( $recoveryDal ) );
$recoveryDb->tables['repositories'][1] = $wpdb->tables['repositories'][1];
$recoveryDb->tables['imports'][1] = $wpdb->tables['imports'][1];
$recoveryDb->tables['imports'][1]['source_status'] = 'present'; $recoveryDb->tables['imports'][1]['last_seen_job'] = null;
$recoveryState = array( 'stage' => 'finalize', 'inventory_complete' => true, 'files_complete' => true, 'errors' => 0, 'commit_sha' => str_repeat( 'e', 40 ), 'inventory_paths' => array(), 'repository' => array( 'license' => 'MIT' ), 'stats' => array( 'missing' => 0 ) );
$recoveryToken = runningJob( $recoveryDb, 1, $recoveryState );
$recoveryState['stats']['missing'] = $recovery->completeInventory( 1, $recoveryToken, array(), $recoveryState['commit_sha'] );
check( 1 === $recoveryState['stats']['missing'], 'Reconciliation records the initial missing source before finalization.' );
$recoveryDb->failFinish = 1; $failed = false;
try { $recovery->finish( 1, $recoveryToken, $recoveryState ); } catch ( RuntimeException $error ) { $failed = true; }
check( $failed && 'running' === $recoveryDb->tables['jobs'][1]['status'], 'An injected finalization failure does not publish a completed task.' );
check( $recoveryDb->tables['repositories'][1]['last_sync_commit'] !== $recoveryState['commit_sha'], 'Failed finalization does not advance the successful repository baseline.' );
$changedCommit = $recoveryState; $changedCommit['commit_sha'] = str_repeat( 'f', 40 );
rejects( fn() => $recovery->retry( 1, $recoveryToken, $changedCommit, 'import_stage_failed', 1 ), 'Retry cannot move durable reconciliation to another Commit.' );
rejects( fn() => $recovery->checkpoint( 1, $recoveryToken, $changedCommit ), 'Checkpoint cannot move durable reconciliation to another Commit.' );
rejects( fn() => $recovery->finish( 1, $recoveryToken, $changedCommit ), 'Finalization cannot move durable reconciliation to another Commit.' );
$recovery->retry( 1, $recoveryToken, $recoveryState, 'import_stage_failed', 1 );
$saved = json_decode( $recoveryDb->tables['jobs'][1]['state_json'], true );
check( true === ( $saved['inventory_reconciled'] ?? false ) && 1 === ( $saved['missing_count'] ?? null ), 'Stale worker state cannot erase committed reconciliation when scheduling a retry.' );
$recoveryDb->tables['jobs'][1]['available_at'] = '2000-01-01 00:00:00';
$resumed = $recovery->claim();
check( 1 === $recovery->completeInventory( 1, $resumed['lease_token'], array(), $recoveryState['commit_sha'] ), 'Resumed reconciliation returns the original missing count rather than recounting zero.' );
$recoveryState['stats']['missing'] = 999;
$recovery->checkpoint( 1, $resumed['lease_token'], $recoveryState );
$saved = json_decode( $recoveryDb->tables['jobs'][1]['state_json'], true );
check( true === ( $saved['inventory_reconciled'] ?? false ) && 1 === $saved['stats']['missing'], 'A later stale checkpoint retains authoritative inventory statistics.' );
$recoveryDb->tables['jobs'][1]['available_at'] = '2000-01-01 00:00:00'; $resumed = $recovery->claim();
$recovery->finish( 1, $resumed['lease_token'], $recoveryState );
$saved = json_decode( $recoveryDb->tables['jobs'][1]['state_json'], true );
check( 'complete' === $recoveryDb->tables['jobs'][1]['status'] && 1 === $saved['stats']['missing'] && 1 === $saved['missing_count'], 'Final job history preserves the actual missing count after recovery.' );
$reconciliations = array_filter( $recoveryDb->executed, static fn( array $query ): bool => str_contains( (string) $query[0], "SET source_status='missing'" ) );
check( 1 === count( $reconciliations ), 'The missing-source update executes once across failed finalization, retry and checkpoint.' );
$forgedToken = runningJob( $recoveryDb, 2, $recoveryState );
$forgedState = $recoveryState + array( 'inventory_reconciled' => true, 'missing_count' => 123 );
$recovery->checkpoint( 2, $forgedToken, $forgedState );
$saved = json_decode( $recoveryDb->tables['jobs'][2]['state_json'], true );
check( ! isset( $saved['inventory_reconciled'], $saved['missing_count'] ), 'Caller-provided reconciliation flags cannot create proof of a database inventory update.' );
$recoveryDb->tables['jobs'][2]['available_at'] = '2000-01-01 00:00:00'; $resumed = $recovery->claim();
rejects( fn() => $recovery->finish( 2, $resumed['lease_token'], $forgedState ), 'Forged checkpoint proof cannot authorize successful completion.' );

// Legacy Phase 1 snapshots must be verified against a fresh, pinned Blob before AI can use them.
$compatDb = new wpdb(); $compatDal = new Database( $compatDb ); $compat = new ImportStore( $compatDal, new Migrator( $compatDal ) );
$compatDb->tables['repositories'][1] = array( 'id' => 1, 'owner' => 'owner', 'name' => 'repo', 'is_archived' => 0, 'sync_enabled' => 1, 'sync_status' => 'running' );
$compatDb->tables['skills'][1] = array( 'id' => 1, 'repository_id' => 1, 'source_path' => 'sample/SKILL.md', 'status' => 'published', 'revision' => 9 );
$compatDb->tables['skill_translations'][1] = array( 'skill_id' => 1, 'locale' => 'en_US', 'slug' => 'human-slug', 'content' => 'Human content' );
$legacyVersion = array( 'id' => 1, 'skill_id' => 1, 'commit_sha' => $commit1, 'content_hash' => hash( 'sha256', $raw ), 'source_content' => $raw, 'version_label' => 'Manual legacy label', 'created_at' => '2025-01-01 00:00:00', 'file_sha' => '', 'repository_id' => null, 'source_path' => null );
$compatDb->tables['skill_versions'][1] = $legacyVersion;
$legacySkill = $compatDb->tables['skills']; $legacyTranslations = $compatDb->tables['skill_translations'];
$compatToken = runningJob( $compatDb, 1 );
rejects( fn() => $compat->record( 1, $compatToken, 'sample/SKILL.md', blobSha( $raw ), $commit1, $raw, $parsed, array() ), 'Cached evidence cannot silently bind a legacy version.' );
check( $compatDb->tables['skill_versions'][1] === $legacyVersion && empty( $compatDb->tables['imports'] ), 'Missing fresh proof leaves the old snapshot and source inventory untouched.' );
$compatResult = $compat->record( 1, $compatToken, 'sample/SKILL.md', blobSha( $raw ), $commit1, $raw, $parsed, array(), true );
$verifiedVersion = $compatDb->tables['skill_versions'][1];
check( 1 === $compatResult['skill_id'] && 1 === count( $compatDb->tables['skill_versions'] ), 'Verified same-Commit legacy scan keeps Skill and version IDs.' );
check( blobSha( $raw ) === $verifiedVersion['file_sha'] && 1 === $verifiedVersion['repository_id'] && 'sample/SKILL.md' === $verifiedVersion['source_path'], 'Fresh pinned Blob completes only missing source identity metadata.' );
foreach ( array( 'id', 'skill_id', 'commit_sha', 'content_hash', 'source_content', 'version_label', 'created_at' ) as $field ) { check( $legacyVersion[ $field ] === $verifiedVersion[ $field ], 'Legacy immutable snapshot field remains unchanged: ' . $field ); }
check( $compatDb->tables['skills'] === $legacySkill && $compatDb->tables['skill_translations'] === $legacyTranslations, 'Legacy verification cannot overwrite published or human content.' );
check( $raw === $compat->sourceContent( 1, 'sample/SKILL.md', blobSha( $raw ) ), 'Verified snapshot is eligible for future exact-source reuse.' );
$compat->record( 1, $compatToken, 'sample/SKILL.md', blobSha( $raw ), $commit1, $raw, $parsed, array(), true );
check( 1 === count( $compatDb->tables['skills'] ) && 1 === count( $compatDb->tables['skill_versions'] ), 'Repeated verified scans do not duplicate legacy identities.' );
foreach ( array( 'file_sha' => str_repeat( 'f', 40 ), 'repository_id' => 2, 'source_path' => 'other/SKILL.md' ) as $field => $conflict ) {
	$compatDb->tables['skill_versions'][1] = $verifiedVersion; $compatDb->tables['skill_versions'][1][ $field ] = $conflict;
	$beforeConflict = $compatDb->tables;
	rejects( fn() => $compat->record( 1, $compatToken, 'sample/SKILL.md', blobSha( $raw ), $commit1, $raw, $parsed, array(), true ), 'A fresh fetch never overwrites known version identity: ' . $field );
	check( $beforeConflict === $compatDb->tables, 'Conflicting version identity rolls back all writes: ' . $field );
}
$compatDb->tables['skill_versions'][1] = $legacyVersion;
check( null === $compat->sourceContent( 1, 'sample/SKILL.md', blobSha( $raw ) ), 'Unbound legacy content forces a fresh Blob request instead of cached reuse.' );
rejects( fn() => $compat->record( 1, $compatToken, 'sample/SKILL.md', blobSha( $raw ), $commit2, $raw, $parsed, array() ), 'A new Commit cannot repair an old unbound source using cached evidence.' );
$compat->record( 1, $compatToken, 'sample/SKILL.md', blobSha( $raw ), $commit2, $raw, $parsed, array(), true );
check( $compatDb->tables['skill_versions'][1] === $legacyVersion, 'A later Commit never backfills unverifiable historical source binding.' );
check( 2 === count( $compatDb->tables['skill_versions'] ) && $compatDb->tables['skill_versions'][2]['commit_sha'] === $commit2 && $compatDb->tables['imports'][1]['commit_sha'] === $commit2, 'Fresh later-Commit proof creates a bound snapshot and updates only the incoming source pointer.' );
check( $raw === $compat->sourceContent( 1, 'sample/SKILL.md', blobSha( $raw ) ), 'The replacement snapshot satisfies all exact-source join constraints.' );
$compat->record( 1, $compatToken, 'sample/SKILL.md', blobSha( $raw ), str_repeat( 'c', 40 ), $raw, $parsed, array() );
check( 2 === count( $compatDb->tables['skill_versions'] ) && $commit2 === $compatDb->tables['imports'][1]['commit_sha'], 'Already-bound unchanged Blobs still reuse history without unnecessary versions.' );

// Repository status and the recoverable job checkpoint must commit together.
$beforeCheckpoint = $compatDb->tables; $compatDb->failQueuedRepository = 1; $checkpointFailed = false;
try { $compat->checkpoint( 1, $compatToken, array( 'stage' => 'files' ) ); } catch ( RuntimeException $error ) { $checkpointFailed = true; }
check( $checkpointFailed && $compatDb->tables === $beforeCheckpoint, 'Repository status failure rolls back the job checkpoint and lease release.' );
$compat->checkpoint( 1, $compatToken, array( 'stage' => 'files' ) );
check( 'queued' === $compatDb->tables['jobs'][1]['status'] && 'queued' === $compatDb->tables['repositories'][1]['sync_status'], 'A successful checkpoint reports queued consistently in both admin views.' );

// A same-HEAD successful baseline cannot hide missing current snapshot bindings.
$compatDb->tables['repositories'][1]['last_sync_commit'] = $commit2;
$compatDb->tables['jobs'][1]['status'] = 'complete'; $compatDb->tables['jobs'][1]['active_key'] = null;
$baselineToken = runningJob( $compatDb, 2 );
check( $compat->baselineReusable( 2, $baselineToken, $commit2 ), 'A bound present source retains the fast path despite unrelated unbound historical versions.' );
$bindingQuery = end( $compatDb->prepared );
check( str_contains( $bindingQuery[0], 'LIMIT 1' ) && ! str_contains( $bindingQuery[0], 'source_content' ) && array( 1 ) === $bindingQuery[1], 'Baseline validation projects one existence result for this repository without loading source bodies.' );
$boundCurrent = $compatDb->tables['skill_versions'][2];
foreach ( array( array( 'file_sha', '' ), array( 'repository_id', null ), array( 'source_path', null ), array( 'file_sha', str_repeat( 'f', 40 ) ), array( 'repository_id', 2 ), array( 'source_path', 'other/SKILL.md' ) ) as list( $field, $value ) ) {
	$compatDb->tables['skill_versions'][2] = $boundCurrent; $compatDb->tables['skill_versions'][2][ $field ] = $value;
	check( ! $compat->baselineReusable( 2, $baselineToken, $commit2 ), 'Incomplete or conflicting current source binding prevents a same-HEAD shortcut: ' . $field );
}
rejects( fn() => $compat->finish( 2, $baselineToken, $state + array( 'unchanged_head' => true ) ), 'Completion rechecks current snapshot binding before trusting unchanged HEAD.' );
unset( $compatDb->tables['skill_versions'][2] );
check( ! $compat->baselineReusable( 2, $baselineToken, $commit2 ), 'A missing referenced snapshot prevents the successful-baseline shortcut.' );
$compatDb->tables['skill_versions'][2] = $boundCurrent;
$compatDb->tables['imports'][2] = array_replace( $compatDb->tables['imports'][1], array( 'id' => 2, 'skill_id' => 2, 'source_path' => 'old/SKILL.md', 'source_status' => 'missing' ) );
check( $compat->baselineReusable( 2, $baselineToken, $commit2 ), 'Missing historical sources do not force repeated full scans.' );
$beforeBindingRead = $compatDb->tables; $compatDb->failBindingRead = true; $bindingReadFailed = false;
try { $compat->baselineReusable( 2, $baselineToken, $commit2 ); } catch ( RuntimeException $error ) { $bindingReadFailed = true; }
check( $bindingReadFailed && $beforeBindingRead === $compatDb->tables, 'A binding-query failure fails closed and never repairs history or reports a usable baseline.' );
$compatDb->failBindingRead = false;

// A lost lease consumes the same bounded failure budget as an explicit retry.
$leaseDb = new wpdb(); $leaseDal = new Database( $leaseDb ); $leases = new ImportStore( $leaseDal, new Migrator( $leaseDal ) );
$leaseDb->tables['repositories'][1] = array( 'id' => 1, 'is_archived' => 0, 'sync_status' => 'running' );
$oldToken = runningJob( $leaseDb, 1 );
for ( $attempt = 1; $attempt < 5; ++$attempt ) {
	$leaseDb->tables['jobs'][1]['locked_until'] = '2000-01-01 00:00:00';
	$reclaimed = $leases->claim();
	check( $attempt === $reclaimed['attempts'] && $oldToken !== $reclaimed['lease_token'], 'Each recoverable lease expiry consumes one failure and replaces the fencing token.' );
	rejects( fn() => $leases->checkpoint( 1, $oldToken, array() ), 'A recovered worker cannot checkpoint with its old token.' );
	$oldToken = $reclaimed['lease_token'];
}
$leaseDb->tables['jobs'][1]['locked_until'] = '2000-01-01 00:00:00';
check( null === $leases->claim(), 'The fifth consecutive lost lease cannot start a sixth execution.' );
$terminalLease = $leaseDb->tables['jobs'][1];
check( 5 === $terminalLease['attempts'] && 'failed' === $terminalLease['status'] && null === $terminalLease['active_key'] && '' === $terminalLease['lease_token'] && null === $terminalLease['locked_until'] && 'failed' === $leaseDb->tables['repositories'][1]['sync_status'], 'Exhausted lease recovery persists the failure count and releases all active ownership.' );
rejects( fn() => $leases->checkpoint( 1, $oldToken, array() ), 'An exhausted worker cannot resurrect its terminal job.' );
$progressToken = runningJob( $leaseDb, 2 ); $leaseDb->tables['jobs'][2]['attempts'] = 3;
$leases->checkpoint( 2, $progressToken, array( 'stage' => 'files' ) );
$leaseDb->tables['jobs'][2]['available_at'] = '2000-01-01 00:00:00'; $progressClaim = $leases->claim();
check( 0 === $progressClaim['attempts'] && 2 === $progressClaim['id'], 'A successful checkpoint still resets consecutive failures before the next step.' );
$deadlineToken = runningJob( $leaseDb, 3 ); $leaseDb->tables['jobs'][3]['locked_until'] = '2000-01-01 00:00:00'; $leaseDb->tables['jobs'][3]['deadline_at'] = gmdate( 'Y-m-d H:i:s' );
check( null === $leases->claim() && 'failed' === $leaseDb->tables['jobs'][3]['status'] && null === $leaseDb->tables['jobs'][3]['active_key'], 'A lease at its deadline expires without another execution.' );
rejects( fn() => $leases->checkpoint( 3, $deadlineToken, array() ), 'A deadline-expired worker cannot checkpoint its old lease.' );
$cancelledToken = runningJob( $leaseDb, 4 ); $leaseDb->tables['jobs'][4]['status'] = 'cancelled'; $leaseDb->tables['jobs'][4]['active_key'] = null; $leaseDb->tables['jobs'][4]['locked_until'] = '2000-01-01 00:00:00';
check( null === $leases->claim() && 'cancelled' === $leaseDb->tables['jobs'][4]['status'], 'Claim cannot resurrect an already cancelled job.' );
rejects( fn() => $leases->checkpoint( 4, $cancelledToken, array() ), 'A cancelled worker cannot resume with its old lease.' );
echo 'PASS: ' . $count . " import-store contracts (in-memory DB adapter; no MySQL/WordPress/GitHub integration).\n";
