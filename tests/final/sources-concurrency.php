<?php
declare(strict_types=1);
require __DIR__ . '/sources-support.php';
use AISkillsHub\Core\Core;
use AISkillsHub\Core\Sources\Frontmatter;
use AISkillsHub\Core\Sources\RiskInspector;

// All subprocesses execute this repository-owned test file, never fetched Skill scripts.
if ( 'child' === ( $argv[1] ?? '' ) ) {
	$action = $argv[2] ?? ''; $repository = filter_var( $argv[3] ?? '', FILTER_VALIDATE_INT ); $barrier = $argv[4] ?? '';
	if ( ! $repository || ! preg_match( '#^/tmp/aish-sources-[a-f0-9]{16}/go$#D', $barrier ) ) { exit( 2 ); }
	file_put_contents( dirname( $barrier ) . '/ready-' . getmypid(), 'ready' );
	$deadline = microtime( true ) + 20; while ( ! is_file( $barrier ) && microtime( true ) < $deadline ) { usleep( 10000 ); clearstatcache( true, $barrier ); }
	if ( ! is_file( $barrier ) ) { echo '{"status":"barrier_timeout"}'; exit( 3 ); }
	try {
		if ( 'enqueue' === $action ) { $result = array( 'status' => 'ok', 'id' => sources_store()->enqueue( $repository ) ); }
		elseif ( 'claim' === $action ) { $job = sources_store()->claim(); $result = array( 'status' => $job ? 'claimed' : 'empty', 'id' => $job ? (int) $job['id'] : null ); }
		elseif ( 'record' === $action ) {
			$job = sources_rows( 'jobs', 'repository_id=%d AND active_key=%d', array( $repository, $repository ) )[0];
			$raw = sources_raw( 'alpha' ); $parsed = Frontmatter::parse( $raw, 'alpha/SKILL.md' ); $inspection = RiskInspector::inspect( $raw, $parsed, array(), 'MIT' );
			$recorded = sources_store()->record( (int) $job['id'], $job['lease_token'], 'alpha/SKILL.md', sources_sha( $raw ), sha1( 'concurrent-' . $repository ), $raw, $parsed, $inspection, true );
			$result = array( 'status' => 'ok', 'id' => $recorded['skill_id'] );
		} elseif ( in_array( $action, array( 'disconnect', 'interrupt-checkpoint', 'archive-flight' ), true ) ) {
			global $wpdb; $repo = sources_row( 'repositories', $repository ); $api = new SourcesMockGitHub( $repository, $repo['owner'], $repo['name'] ); $api->files = array( 'alpha/SKILL.md' => sources_raw( 'alpha' ) );
			if ( 'interrupt-checkpoint' === $action ) {
				$job = sources_rows( 'jobs', 'active_key=%d', array( $repository ) )[0]; $table = sources_db()->table( 'jobs' );
				add_filter( 'query', static function ( string $sql ) use ( $job, $table ): string {
					if ( str_starts_with( $sql, 'UPDATE ' . $table ) && str_contains( $sql, "'queued'" ) && preg_match( '/WHERE\\s+`id`\\s*=\\s*' . (int) $job['id'] . '$/', $sql ) ) {
						echo '{"status":"checkpoint_interrupted"}'; exit( 0 );
					}
					return $sql;
				}, PHP_INT_MAX );
				Core::boot()->get( 'import_worker' )->run(); echo '{"status":"interruption_not_reached"}'; exit( 0 );
			}
			if ( 'archive-flight' === $action ) {
				$api->beforeResponse = static function () use ( $barrier ): void {
					$entered = dirname( $barrier ) . '/in-flight'; $resume = dirname( $barrier ) . '/resume'; file_put_contents( $entered, 'waiting' );
					$deadline = microtime( true ) + 15; while ( ! is_file( $resume ) && microtime( true ) < $deadline ) { usleep( 10000 ); clearstatcache( true, $resume ); }
					if ( ! is_file( $resume ) ) { throw new RuntimeException( 'QA interleave timeout.' ); }
				};
				Core::boot()->get( 'import_worker' )->run(); echo wp_json_encode( array( 'status' => 'resumed', 'mock_http_calls' => count( $api->calls ) ) ); exit( 0 );
			}
			$victim = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ); $killer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$api->beforeResponse = static function () use ( $killer, $victim, $api ): void { $api->beforeResponse = null; $killer->query( 'KILL CONNECTION ' . $victim ); };
			$mode = ( new mysqli_driver() )->report_mode; Core::boot()->get( 'import_scheduler' )->tick();
			$result = array( 'status' => 'disconnected', 'mock_http_calls' => count( $api->calls ), 'mode_restored' => $mode === ( new mysqli_driver() )->report_mode ); $api->close(); $killer->close();
		} else { exit( 2 ); }
	} catch ( DomainException $error ) { $result = array( 'status' => 'busy' ); }
	catch ( Throwable $error ) { $result = array( 'status' => 'error', 'type' => get_class( $error ) ); }
	echo wp_json_encode( $result ); exit( 0 );
}

/** Starts separate PHP/WordPress processes with separate real MySQL sessions and a common barrier. */
function sources_parallel( string $action, int $repository, int $count, ?callable $interleave = null ): array {
	$directory = '/tmp/aish-sources-' . bin2hex( random_bytes( 8 ) ); mkdir( $directory, 0700 ); $barrier = $directory . '/go'; $processes = array();
	try {
		for ( $i = 0; $i < $count; ++$i ) {
			$process = proc_open( array( PHP_BINARY, __FILE__, 'child', $action, (string) $repository, $barrier ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Assertion: QA subprocess did not start.' ); }
			fclose( $pipes[0] ); $processes[] = array( $process, $pipes );
		}
		$deadline = microtime( true ) + 15; while ( count( glob( $directory . '/ready-*' ) ) < $count && microtime( true ) < $deadline ) { usleep( 10000 ); }
		if ( count( glob( $directory . '/ready-*' ) ) !== $count ) { throw new RuntimeException( 'Assertion: all QA processes must reach the barrier.' ); }
		file_put_contents( $barrier, 'go' );
		if ( $interleave ) {
			$entered = $directory . '/in-flight'; $deadline = microtime( true ) + 15;
			while ( ! is_file( $entered ) && microtime( true ) < $deadline ) { usleep( 10000 ); clearstatcache( true, $entered ); }
			if ( ! is_file( $entered ) ) { throw new RuntimeException( 'Assertion: worker did not reach interleaving point.' ); }
			$interleave(); file_put_contents( $directory . '/resume', 'resume' );
		}
		$results = array();
		foreach ( $processes as list( $process, $pipes ) ) {
			$out = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $code = proc_close( $process );
			$result = json_decode( $out, true ); if ( 0 !== $code || ! is_array( $result ) || '' !== $error ) { throw new RuntimeException( 'Assertion: QA child failed or emitted an unexpected diagnostic.' ); }
			$results[] = $result;
		}
		$processes = array(); return $results;
	} finally {
		foreach ( $processes as list( $process, $pipes ) ) { if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); } }
		foreach ( glob( $directory . '/*' ) as $path ) { unlink( $path ); } rmdir( $directory );
	}
}

$qa = new SourcesQA();
$qa->test( 'parallel_enqueue_preserves_one_active_repository_job', function () use ( $qa ): void {
	list( $repository, $api ) = $qa->repository( 'parallel-enqueue' ); $results = sources_parallel( 'enqueue', $repository, 4 );
	$accepted = array_values( array_filter( $results, fn( $row ) => 'ok' === $row['status'] ) );
	$qa->check( (bool) $accepted && ! array_diff( array_column( $results, 'status' ), array( 'ok', 'busy' ) ), 'Contending enqueues either share the job or report bounded lock contention.' );
	$jobs = sources_rows( 'jobs', 'repository_id=%d', array( $repository ) ); $qa->check( 1 === count( $jobs ) && $repository === (int) $jobs[0]['active_key'], 'Concurrent requests persist exactly one active task.' );
	$qa->check( 1 === count( array_unique( array_column( $accepted, 'id' ) ) ) && (int) $jobs[0]['id'] === sources_store()->enqueue( $repository ), 'Accepted requests and later repeats return the same task ID.' ); $api->close();
} );
$qa->test( 'parallel_claim_and_process_exit_recover_with_fenced_lease', function () use ( $qa ): void {
	list( $repository, $api ) = $qa->repository( 'parallel-claim' ); $api->files = array( 'alpha/SKILL.md' => sources_raw( 'alpha' ) ); $job = sources_enqueue( $repository );
	$results = sources_parallel( 'claim', $repository, 4 ); $claimed = array_filter( $results, fn( $row ) => 'claimed' === $row['status'] );
	$qa->check( 1 === count( $claimed ) && ! array_diff( array_column( $results, 'status' ), array( 'claimed', 'empty', 'busy' ) ), 'Only one process owns a queued job after concurrent claims.' );
	$running = sources_row( 'jobs', $job ); $qa->check( 'running' === $running['status'] && 64 === strlen( $running['lease_token'] ), 'Lease is durably committed after its owning PHP process exits.' );
	sources_db()->execute( 'UPDATE ' . sources_db()->table( 'jobs' ) . ' SET locked_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=%d', array( $job ) );
	$reclaimed = sources_parallel( 'claim', $repository, 1 ); $replacement = sources_row( 'jobs', $job );
	$qa->check( 'claimed' === $reclaimed[0]['status'] && 1 === (int) $replacement['attempts'] && $running['lease_token'] !== $replacement['lease_token'], 'Another process recovers a lost lease with a fresh token.' );
	$qa->rejects( fn() => sources_store()->checkpoint( $job, $running['lease_token'], array() ), DomainException::class, 'An old process token cannot checkpoint after replacement.' );
	sources_db()->execute( 'UPDATE ' . sources_db()->table( 'jobs' ) . ' SET locked_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=%d', array( $job ) );
	$done = sources_finish( $job ); $qa->check( 'complete' === $done['status'] && 1 === count( sources_rows( 'imports', 'repository_id=%d', array( $repository ) ) ), 'Process interruption resumes without duplicate Skills.' ); $api->close();
} );
$qa->test( 'concurrent_source_replay_preserves_unique_id_and_versions', function () use ( $qa ): void {
	list( $repository, $api ) = $qa->repository( 'parallel-record' ); $job = sources_enqueue( $repository ); sources_store()->claim();
	$results = sources_parallel( 'record', $repository, 3 );
	$qa->check( array( 'ok', 'ok', 'ok' ) === array_column( $results, 'status' ) && 1 === count( array_unique( array_column( $results, 'id' ) ) ), 'Concurrent replay serializes on the leased job and returns one stable Skill ID.' );
	foreach ( array( 'skills', 'imports', 'skill_versions' ) as $entity ) { $qa->check( 1 === count( sources_rows( $entity, 'repository_id=%d', array( $repository ) ) ), 'Real unique source/version identity remains singular: ' . $entity ); }
	$qa->check( 1 === count( sources_rows( 'import_logs', "job_id=%d AND code='source_new'", array( $job ) ) ), 'Replay does not duplicate the new-source event.' );
	$skill = sources_row( 'skills', (int) $results[0]['id'] ); $qa->check( 'pending' === $skill['status'] && ! sources_rows( 'skill_translations', 'skill_id=%d', array( (int) $skill['id'] ) ), 'Concurrent recording never publishes or fabricates translations.' ); $api->close();
} );
$qa->test( 'actual_mysql_connection_loss_during_mock_response_preserves_recoverable_job', function () use ( $qa ): void {
	list( $repository, $api ) = $qa->repository( 'connection-loss' ); $api->files = array( 'alpha/SKILL.md' => sources_raw( 'alpha' ) ); $job = sources_enqueue( $repository );
	$result = sources_parallel( 'disconnect', $repository, 1 )[0];
	$qa->check( 'disconnected' === $result['status'] && 1 === $result['mock_http_calls'] && $result['mode_restored'], 'Killing the worker MySQL session after a mock response is contained and restores driver mode.' );
	$interrupted = sources_row( 'jobs', $job ); $qa->check( 'running' === $interrupted['status'] && '{}' === $interrupted['state_json'], 'No automatic reconnect or false checkpoint occurs after losing the lock connection.' );
	$qa->check( ! sources_rows( 'imports', 'repository_id=%d', array( $repository ) ) && '' === sources_row( 'repositories', $repository )['last_sync_commit'], 'Lost-session result does not create Skills or advance the successful baseline.' );
	$lock = 'aish_worker:' . substr( hash( 'sha256', DB_NAME . ':' . sources_db()->prefix() ), 0, 48 );
	$qa->check( 1 === (int) sources_db()->value( 'SELECT GET_LOCK(%s,0)', array( $lock ) ), 'Killed session releases its real global advisory lock.' ); sources_db()->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) );
	sources_db()->execute( 'UPDATE ' . sources_db()->table( 'jobs' ) . ' SET locked_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=%d', array( $job ) );
	$qa->check( 'complete' === sources_finish( $job )['status'], 'A separate healthy connection later resumes the durable task.' ); $api->close();
} );
$qa->test( 'process_exit_after_source_commit_before_checkpoint_replays_once', function () use ( $qa ): void {
	list( $repository, $api ) = $qa->repository( 'checkpoint-exit' ); $api->files = array( 'alpha/SKILL.md' => sources_raw( 'alpha' ) ); $job = sources_enqueue( $repository ); sources_until( $job, 'files' ); sources_due( $job );
	$result = sources_parallel( 'interrupt-checkpoint', $repository, 1 )[0];
	$qa->check( 'checkpoint_interrupted' === $result['status'], 'The child exits after record committed and immediately before checkpoint update.' );
	$stale = sources_row( 'jobs', $job ); $qa->check( 'running' === $stale['status'] && 0 === sources_json( $stale )['cursor'] && 1 === count( sources_rows( 'imports', 'repository_id=%d', array( $repository ) ) ), 'Process exit rolls back only checkpoint, retaining source commit and old durable cursor.' );
	sources_db()->execute( 'UPDATE ' . sources_db()->table( 'jobs' ) . ' SET locked_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=%d', array( $job ) ); $done = sources_finish( $job );
	$qa->check( 'complete' === $done['status'] && 1 === sources_json( $done )['stats']['new'] && 1 === count( sources_rows( 'skill_versions', 'repository_id=%d', array( $repository ) ) ), 'Recovery replays one source without duplicating its ID/version or losing the original new count.' ); $api->close();
} );
$qa->test( 'archive_during_in_flight_response_revokes_worker_before_writeback', function () use ( $qa ): void {
	list( $repository, $api ) = $qa->repository( 'archive-flight' ); $job = sources_enqueue( $repository );
	$result = sources_parallel( 'archive-flight', $repository, 1, static function () use ( $repository ): void { sources_store()->archiveRepository( $repository ); } )[0];
	$qa->check( 'resumed' === $result['status'] && 1 === $result['mock_http_calls'], 'Separate admin transaction archives while the worker response is paused.' );
	$cancelled = sources_row( 'jobs', $job ); $qa->check( 'cancelled' === $cancelled['status'] && null === $cancelled['active_key'] && '' === $cancelled['lease_token'], 'Late worker cannot revive an archived task.' );
	$qa->check( null === sources_row( 'repositories', $repository )['github_id'] && ! sources_rows( 'imports', 'repository_id=%d', array( $repository ) ), 'Revoked worker cannot save late identity metadata or source content.' ); $api->close();
} );
$qa->report( 'sources-concurrency-real-wordpress-mysql', 'Mock WordPress HTTP responses; real PHP processes/MySQL sessions, locks, transactions and KILL CONNECTION. No external GitHub requests.' );
