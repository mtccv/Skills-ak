<?php
declare(strict_types=1);
/** Fresh-process real WordPress/MySQL read benchmark. No external traffic or production database. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE13_QA' ) !== '1' ) { exit( 1 ); }
define( 'SAVEQUERIES', true );
$_SERVER['HTTP_HOST'] = PHP_VERSION_ID >= 80400 ? '127.0.0.1:18184' : '127.0.0.1:18183';
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_discovery', 'qa_operations84' ), true ) || '1.9.0' !== get_option( 'aish_schema_version' ) ) { exit( 2 ); }
$core = \AISkillsHub\Core\Core::boot(); $db = $core->get( 'database' );
$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
if ( ! $admins ) { exit( 3 ); } wp_set_current_user( (int) $admins[0] );
$external = 0; $mail = 0;
add_filter( 'pre_http_request', static function () use ( &$external ): WP_Error { ++$external; return new WP_Error( 'qa_blocked' ); }, 1 );
add_filter( 'pre_wp_mail', static function () use ( &$mail ): bool { ++$mail; return false; }, 1 );
$scope = $argv[1] ?? 'admin'; if ( ! in_array( $scope, array( 'admin', 'overview_only', 'reviews_only' ), true ) ) { exit( 4 ); }
if ( 'admin' !== $scope ) {
	add_filter( 'user_has_cap', static function ( array $caps ) use ( $scope ): array {
		foreach ( $caps as $key => &$allowed ) { if ( str_starts_with( $key, 'aish_' ) || 'manage_options' === $key ) { $allowed = false; } } unset( $allowed );
		$caps['aish_view_operations'] = true; if ( 'reviews_only' === $scope ) { $caps['aish_moderate_community'] = true; } return $caps;
	}, 999 );
}
$counts = array(); foreach ( array( 'skills', 'skill_translations', 'reviews', 'review_revisions', 'review_reports', 'skill_reports', 'author_claims', 'repositories', 'jobs', 'imports', 'ai_jobs', 'ai_attempts', 'notification_outbox' ) as $table ) { $counts[ $table ] = (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( $table ) ); }
$counts['native_users'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
$counts['private_inbox'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='aish_message' AND post_status='private'" );
$counts['owned_submissions'] = (int) $db->value( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE ' . $core->get( 'submission_query' )->ownedSql() );
$startQuery = $wpdb->num_queries; $startIndex = count( $wpdb->queries ); $start = hrtime( true );
$snapshot = $core->get( 'operations_dashboard' )->snapshot();
$elapsed = ( hrtime( true ) - $start ) / 1e6; $queries = array_slice( $wpdb->queries, $startIndex );
$groups = array( 'permission_state_reads' => 0, 'aggregate_counts' => 0, 'private_content_projection' => 0, 'other' => 0 ); $fingerprints = array(); $sqlMs = 0.0;
foreach ( $queries as $query ) {
	$sql = $query[0]; $sqlMs += $query[1] * 1000; $key = hash( 'sha256', $sql ); $fingerprints[ $key ] = ( $fingerprints[ $key ] ?? 0 ) + 1;
	if ( str_contains( $sql, $wpdb->usermeta ) && ( str_contains( $sql, "meta_key='_aish_account_state'" ) || str_contains( $sql, "meta_key='_aish_account_deletion_pending'" ) ) && str_contains( $sql, 'ORDER BY umeta_id' ) ) { ++$groups['permission_state_reads']; }
	elseif ( str_contains( $sql, 'COUNT(' ) || str_contains( $sql, 'SUM(' ) ) { ++$groups['aggregate_counts']; }
	elseif ( str_contains( $sql, 'SELECT p.*' ) || str_contains( $sql, 'SELECT r.*' ) || str_contains( $sql, 'SELECT post_id,meta_key,meta_value' ) || str_contains( $sql, 'SELECT * FROM `' . $wpdb->prefix . 'aish_review_revisions' ) ) { ++$groups['private_content_projection']; }
	else { ++$groups['other']; }
}
$metrics = array(); foreach ( $snapshot['metrics'] as $row ) { $metrics[ $row['key'] ] = array_intersect_key( $row, array_flip( array( 'label', 'scope', 'unit', 'status', 'value', 'url' ) ) ); } ksort( $metrics );
$theme = get_template_directory();
echo wp_json_encode( array( 'kind' => 'Real WordPress/MySQL Dashboard snapshot; one fresh PHP process, no persistent cache reset or HTTP/browser timing', 'phase' => getenv( 'AISH_BENCHMARK_PHASE' ) ?: 'baseline', 'scope' => $scope, 'environment' => array( 'php' => PHP_VERSION, 'wordpress' => $wp_version, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'database' => DB_NAME, 'theme_version' => wp_get_theme()->get( 'Version' ), 'dashboard_sha256' => hash_file( 'sha256', $theme . '/inc/core/Operations/Dashboard.php' ) ), 'fixture_rows' => $counts, 'measurement' => array( 'elapsed_ms' => round( $elapsed, 3 ), 'sql_time_ms' => round( $sqlMs, 3 ), 'sql_queries' => count( $queries ), 'query_categories' => $groups, 'repeated_identical_queries' => count( $queries ) - count( $fingerprints ) ), 'metrics' => $metrics, 'external_http_attempts' => $external, 'mail_attempts' => $mail ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
