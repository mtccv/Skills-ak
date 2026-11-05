<?php
declare(strict_types=1);
// Temporary MU fixture. Shared mounts must never change another QA site's behavior.
if ( ! defined( 'DB_NAME' ) || 'qa_sources' !== DB_NAME || '1' !== getenv( 'AISH_FINAL_QA' ) ) { return; }
$qaSourcesCronControl = get_option( 'aish_qa_sources_http_cron_control' );
if ( ! is_array( $qaSourcesCronControl ) || true !== ( $qaSourcesCronControl['active'] ?? false ) ) { return; }

add_filter( 'pre_http_request', static function ( $preempt, array $args, string $url ) use ( $qaSourcesCronControl ) {
	$counts = get_option( 'aish_qa_sources_http_cron_counts', array() );
	$expected = 'https://api.github.com/repos/' . $qaSourcesCronControl['owner'] . '/http-cron';
	if ( $url !== $expected ) {
		$counts['blocked_external_requests'] = (int) ( $counts['blocked_external_requests'] ?? 0 ) + 1;
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $host ) && preg_match( '/^[a-z0-9.-]{1,253}$/D', $host ) ) { $counts['blocked_hosts'][ $host ] = (int) ( $counts['blocked_hosts'][ $host ] ?? 0 ) + 1; }
		update_option( 'aish_qa_sources_http_cron_counts', $counts, false );
		return new WP_Error( 'qa_external_http_blocked', 'QA fixture blocks external HTTP.' );
	}
	$counts['mock_github_requests'] = (int) ( $counts['mock_github_requests'] ?? 0 ) + 1;
	update_option( 'aish_qa_sources_http_cron_counts', $counts, false );
	return array( 'headers' => array( 'x-ratelimit-remaining' => '1000', 'x-ratelimit-reset' => (string) ( time() + 3600 ) ), 'body' => wp_json_encode( array( 'id' => 1000000000 + $qaSourcesCronControl['repository_id'], 'full_name' => $qaSourcesCronControl['owner'] . '/http-cron', 'private' => false, 'visibility' => 'public', 'archived' => false, 'disabled' => false, 'default_branch' => 'main', 'license' => array( 'spdx_id' => 'MIT' ) ) ), 'response' => array( 'code' => 200, 'message' => 'QA fixture' ), 'cookies' => array(), 'filename' => null );
}, PHP_INT_MAX, 3 );

foreach ( array( 'aish_import_tick' => 'import_scheduler', 'aish_ai_tick' => 'ai_scheduler' ) as $qaSourcesCronHook => $qaSourcesCronService ) {
	add_action( $qaSourcesCronHook, static function () use ( $qaSourcesCronHook, $qaSourcesCronService ): void {
		$counts = get_option( 'aish_qa_sources_http_cron_counts', array() );
		$counts[ $qaSourcesCronHook ] = (int) ( $counts[ $qaSourcesCronHook ] ?? 0 ) + 1;
		$service = \AISkillsHub\Core\Core::boot()->get( $qaSourcesCronService );
		$counts['contexts'][ $qaSourcesCronHook ] = array( 'doing_cron' => defined( 'DOING_CRON' ) && DOING_CRON, 'sapi' => PHP_SAPI, 'user_id' => get_current_user_id(), 'production_callback_priority' => has_action( $qaSourcesCronHook, array( $service, 'tick' ) ), 'wp_cron_request' => str_contains( $_SERVER['SCRIPT_NAME'] ?? '', 'wp-cron.php' ) );
		update_option( 'aish_qa_sources_http_cron_counts', $counts, false );
	}, PHP_INT_MAX );
}
