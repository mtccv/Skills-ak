<?php
declare(strict_types=1);
require __DIR__ . '/sources-support.php';
if ( 'qa_sources' !== DB_NAME ) { throw new RuntimeException( 'This HTTP Cron fixture requires the dedicated qa_sources site.' ); }
$mode = $argv[1] ?? '';
$controlKey = 'aish_qa_sources_http_cron_control'; $backupKey = 'aish_qa_sources_http_cron_backup'; $countsKey = 'aish_qa_sources_http_cron_counts';
$hooks = array( 'aish_import_tick' => 'aish_import_minute', 'aish_ai_tick' => 'aish_ai_minute' );
function sources_http_events(): array {
	$result = array( 'aish_import_tick' => array(), 'aish_ai_tick' => array() );
	foreach ( _get_cron_array() as $time => $hooks ) { foreach ( $result as $hook => $unused ) { foreach ( $hooks[ $hook ] ?? array() as $event ) { $result[ $hook ][] = array( 'timestamp' => (int) $time, 'schedule' => $event['schedule'], 'interval' => $event['interval'] ?? null ); } } }
	return $result;
}
try {
	if ( 'setup' === $mode ) {
		if ( get_option( $backupKey, false ) !== false ) { throw new RuntimeException( 'Previous HTTP Cron fixture requires cleanup first.' ); }
		if ( sources_db()->value( 'SELECT COUNT(*) FROM ' . sources_db()->table( 'jobs' ) . ' WHERE active_key IS NOT NULL' ) > 0 ) { throw new RuntimeException( 'Existing active import jobs must be isolated before this fixture.' ); }
		$run = bin2hex( random_bytes( 5 ) ); $owner = 'qa-cron-' . $run;
		$enabled = sources_rows( 'repositories', 'sync_enabled=1' );
		$pausedExists = null !== sources_db()->connection()->get_var( sources_db()->connection()->prepare( "SELECT option_id FROM {$GLOBALS['wpdb']->options} WHERE option_name=%s", 'aish_ai_paused' ) );
		$backup = array( 'cron' => _get_cron_array(), 'ai_paused_exists' => $pausedExists, 'ai_paused' => get_option( 'aish_ai_paused', true ), 'sync_enabled' => array_column( $enabled, 'id' ) );
		update_option( $backupKey, $backup, false );
		$core->get( 'ai_settings' )->setPaused( true );
		foreach ( $enabled as $repository ) { sources_db()->updateById( 'repositories', (int) $repository['id'], array( 'sync_enabled' => 0 ) ); }
		_set_cron_array( array() ); delete_transient( 'doing_cron' );
		$repository = sources_store()->saveRepository( null, 'https://github.com/' . $owner . '/http-cron', false ); $job = sources_store()->enqueue( $repository );
		$control = array( 'active' => true, 'run' => $run, 'repository_id' => $repository, 'job_id' => $job, 'owner' => $owner, 'ai_attempts_before' => (int) sources_db()->value( 'SELECT COUNT(*) FROM ' . sources_db()->table( 'ai_attempts' ) ) );
		update_option( $controlKey, $control, false ); update_option( $countsKey, array(), false );
		delete_transient( 'aish_github_rate_' . substr( hash( 'sha256', 'anonymous' ), 0, 24 ) );
		foreach ( $hooks as $hook => $schedule ) { if ( ! wp_schedule_event( time() - 5, $schedule, $hook ) ) { throw new RuntimeException( 'Could not schedule QA due event.' ); } }
		echo wp_json_encode( array( 'run' => $run, 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $GLOBALS['wpdb']->get_var( 'SELECT VERSION()' ), 'schema' => get_option( 'aish_schema_version' ), 'database' => DB_NAME ), 'events' => sources_http_events() ) );
	} elseif ( 'snapshot' === $mode ) {
		$control = get_option( $controlKey ); if ( ! is_array( $control ) ) { throw new RuntimeException( 'HTTP Cron fixture is not active.' ); }
		$job = sources_row( 'jobs', $control['job_id'] ); $state = sources_json( $job ); $repository = sources_row( 'repositories', $control['repository_id'] );
		echo wp_json_encode( array( 'counts' => get_option( $countsKey, array() ), 'events' => sources_http_events(), 'job' => array( 'status' => $job['status'], 'stage' => $state['stage'] ?? null, 'cursor' => $state['cursor'] ?? null, 'available_at' => $job['available_at'], 'state_hash' => hash( 'sha256', $job['state_json'] ), 'active_key_retained' => (int) $job['active_key'] === $control['repository_id'], 'lease_released' => '' === $job['lease_token'] && null === $job['locked_until'] ), 'repository' => array( 'sync_status' => $repository['sync_status'], 'sync_enabled' => (int) $repository['sync_enabled'], 'github_identity_bound' => (int) $repository['github_id'] === 1000000000 + $control['repository_id'], 'last_sync_commit' => $repository['last_sync_commit'] ), 'ai_paused' => $core->get( 'ai_settings' )->paused(), 'ai_attempt_count_unchanged' => $control['ai_attempts_before'] === (int) sources_db()->value( 'SELECT COUNT(*) FROM ' . sources_db()->table( 'ai_attempts' ) ) ) );
	} elseif ( 'cleanup' === $mode ) {
		$control = get_option( $controlKey ); $backup = get_option( $backupKey );
		if ( is_array( $control ) ) { sources_store()->archiveRepository( $control['repository_id'] ); }
		if ( is_array( $backup ) ) {
			_set_cron_array( $backup['cron'] );
			if ( $backup['ai_paused_exists'] ) { update_option( 'aish_ai_paused', $backup['ai_paused'], false ); } else { delete_option( 'aish_ai_paused' ); }
			foreach ( $backup['sync_enabled'] as $repository ) { sources_db()->updateById( 'repositories', (int) $repository, array( 'sync_enabled' => 1 ) ); }
		}
		delete_option( $controlKey ); delete_option( $countsKey ); delete_option( $backupKey ); delete_transient( 'doing_cron' );
		$restored = ! is_array( $backup ) || ( _get_cron_array() === $backup['cron'] && ( $backup['ai_paused_exists'] ? get_option( 'aish_ai_paused' ) === $backup['ai_paused'] : false === get_option( 'aish_ai_paused', false ) ) );
		foreach ( $backup['sync_enabled'] ?? array() as $repository ) { $restored = $restored && 1 === (int) sources_row( 'repositories', (int) $repository )['sync_enabled']; }
		echo wp_json_encode( array( 'restored' => $restored && false === get_option( $controlKey, false ), 'fixture_history_retained' => ! is_array( $control ) || null !== sources_row( 'jobs', $control['job_id'] ) ) );
	} else { throw new RuntimeException( 'Unknown HTTP Cron fixture mode.' ); }
} catch ( Throwable $error ) { fwrite( STDERR, 'HTTP Cron fixture failed: ' . get_class( $error ) . "\n" ); exit( 1 ); }
