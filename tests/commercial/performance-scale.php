<?php
declare(strict_types=1);
/** Paired read-model comparison on rollback-only synthetic Inbox growth; no surviving test rows. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE13_QA' ) !== '1' ) { exit( 1 ); }
define( 'SAVEQUERIES', true );
$_SERVER['HTTP_HOST'] = PHP_VERSION_ID >= 80400 ? '127.0.0.1:18184' : '127.0.0.1:18183';
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_discovery', 'qa_operations84' ), true ) || '1.9.0' !== get_option( 'aish_schema_version' ) ) { exit( 2 ); }
$scale = (int) ( $argv[1] ?? -1 ); if ( ! in_array( $scale, array( 0, 100, 1000 ), true ) ) { exit( 3 ); }
$order = $argv[2] ?? 'before_first'; if ( ! in_array( $order, array( 'before_first', 'after_first' ), true ) ) { exit( 4 ); }
$original = '/tmp/aish-dashboard-baseline.php'; if ( ! is_file( $original ) || ! preg_match( '/^[a-f0-9]{64}$/D', (string) getenv( 'AISH_BASELINE_SHA256' ) ) ) { exit( 5 ); }
require $original;
$core = \AISkillsHub\Core\Core::boot(); $db = $core->get( 'database' );
$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) ); if ( ! $admins ) { exit( 6 ); } $admin = (int) $admins[0]; wp_set_current_user( $admin );
$external = 0; $mail = 0;
add_filter( 'pre_http_request', static function () use ( &$external ): WP_Error { ++$external; return new WP_Error( 'qa_blocked' ); }, 1 );
add_filter( 'pre_wp_mail', static function () use ( &$mail ): bool { ++$mail; return false; }, 1 );
$baseline = new \AISkillsHub\Core\Operations\BenchmarkDashboardBaseline( $db, $core->migrator(), $core->get( 'operations_workbench' ), $core->get( 'operations_health' ) );
$baseline->addSummary( 'accounts', 'aish_manage_accounts', array( $core->get( 'operations_users' ), 'summary' ) );
$baseline->addSummary( 'mail', 'aish_manage_notifications', array( $core->get( 'operations_mail' ), 'summary' ) );
function perf_measure( object $dashboard ): array {
	global $wpdb; $index = count( $wpdb->queries ); $start = hrtime( true ); $snapshot = $dashboard->snapshot(); $ms = ( hrtime( true ) - $start ) / 1e6;
	$queries = array_slice( $wpdb->queries, $index ); $sqlMs = array_sum( array_column( $queries, 1 ) ) * 1000; $metrics = array();
	foreach ( $snapshot['metrics'] as $row ) { $metrics[ $row['key'] ] = array_intersect_key( $row, array_flip( array( 'label', 'scope', 'unit', 'status', 'value', 'url' ) ) ); } ksort( $metrics );
	return array( 'elapsed_ms' => round( $ms, 3 ), 'sql_time_ms' => round( $sqlMs, 3 ), 'sql_queries' => count( $queries ), 'metrics' => $metrics );
}
$prefix = 'p13-perf-' . bin2hex( random_bytes( 8 ) ) . '-'; $where = $wpdb->prepare( "post_type='aish_message' AND post_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ); $ids = array(); $results = array();
$wpdb->query( 'START TRANSACTION' );
try {
	$fields = array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' );
	$now = gmdate( 'Y-m-d H:i:s' ); $stateList = array( 'submitted', 'in_review', 'needs_changes' );
	$payload = \AISkillsHub\Core\Submissions\Input::payload( array( 'kind' => 'submit', 'locale' => 'en_US', 'source_kind' => 'manual', 'name' => 'Synthetic performance fixture', 'summary' => 'Rollback-only QA fixture, never a real user submission.', 'notes' => 'Synthetic count benchmark. No publication or external service.' ), true );
	for ( $offset = 0; $offset < $scale; $offset += 100 ) {
		$values = array(); $holders = array();
		for ( $i = $offset; $i < min( $scale, $offset + 100 ); ++$i ) { array_push( $values, $admin, $now, $now, '', 'Synthetic count benchmark', '', 'private', 'closed', 'closed', '', $prefix . $i, '', '', $now, $now, '', 0, '', 0, 'aish_message', '', 0 ); $holders[] = '(' . implode( ',', array_fill( 0, count( $fields ), '%s' ) ) . ')'; }
		if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->posts} (" . implode( ',', $fields ) . ') VALUES ' . implode( ',', $holders ), $values ) ) ) { throw new RuntimeException( 'QA fixture insert failed.' ); }
	}
	$ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE $where ORDER BY ID" ) );
	if ( count( $ids ) !== $scale ) { throw new RuntimeException( 'QA fixture count mismatch.' ); }
	foreach ( array_chunk( $ids, 50 ) as $chunkIndex => $chunk ) {
		$values = array(); $holders = array();
		foreach ( $chunk as $index => $id ) {
			$meta = array( '_aish_submission_version' => '1', '_aish_message_kind' => 'submit', '_aish_submission_state' => $stateList[ ( $chunkIndex * 50 + $index ) % 3 ], '_aish_submission_revision' => '1', '_aish_message_locale' => 'en_US', '_aish_submission_payload' => wp_json_encode( $payload ), '_aish_result_skill_ids' => '[]', '_aish_submitted_at' => $now );
			foreach ( $meta as $key => $value ) { array_push( $values, $id, $key, $value ); $holders[] = '(%d,%s,%s)'; }
		}
		if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES " . implode( ',', $holders ), $values ) ) ) { throw new RuntimeException( 'QA fixture metadata insert failed.' ); }
	}
	$rows = array( 'synthetic_submissions_added_in_transaction' => $scale, 'owned_submissions' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE ' . $core->get( 'submission_query' )->ownedSql() ), 'skills' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skills' ) ), 'reviews' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'reviews' ) ) );
	$models = 'before_first' === $order ? array( 'before' => $baseline, 'after' => $core->get( 'operations_dashboard' ) ) : array( 'after' => $core->get( 'operations_dashboard' ), 'before' => $baseline );
	foreach ( $models as $key => $model ) { $results[ $key ] = perf_measure( $model ); }
	$equal = $results['before']['metrics'] === $results['after']['metrics'];
	if ( ! $equal || $external || $mail ) { throw new RuntimeException( 'Read-model semantics or external side-effect regression.' ); }
} finally { $wpdb->query( 'ROLLBACK' ); }
$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where" );
$remainingMeta = $ids ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . implode( ',', $ids ) . ')' ) : 0;
if ( $remaining || $remainingMeta ) { throw new RuntimeException( 'Rollback left synthetic benchmark rows.' ); }
echo wp_json_encode( array( 'kind' => 'Fresh PHP process, paired same-transaction snapshot comparison; only Dashboard class is substituted for baseline; fixture setup excluded from measurement', 'environment' => array( 'php' => PHP_VERSION, 'wordpress' => $wp_version, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'database' => DB_NAME ), 'baseline_commit' => '46d63dbfd256ae38c579f65608ee46876cf545f0', 'baseline_source_sha256' => getenv( 'AISH_BASELINE_SHA256' ), 'order' => $order, 'fixture_rows' => $rows, 'measurements' => $results, 'identical_metrics' => $equal, 'remaining_synthetic_posts' => $remaining, 'remaining_synthetic_metadata' => $remainingMeta, 'external_http_attempts' => $external, 'mail_attempts' => $mail ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
