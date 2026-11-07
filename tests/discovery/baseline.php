<?php
declare(strict_types=1);

/** Read-only pre-migration evidence. Output hashes/counts, never underlying records. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE11_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_discovery', 'qa_discovery84' ), true ) || '1.8.0' !== get_option( 'aish_schema_version' ) ) { exit( 2 ); }
$entities = array( 'repositories','skills','skill_translations','skill_versions','categories','tags','skill_categories','skill_tags','imports','jobs','import_logs','ai_jobs','ai_attempts','ai_reviews','email_verifications','user_notifications','notification_outbox','favorites','reviews','review_revisions','review_reports','skill_reports','workflow_events','metric_events','daily_metrics','author_claims' );
function p11_fingerprint( string $sql ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'qa_baseline_unavailable' ); }
	$hashes = array_map( static fn( array $row ): string => hash( 'sha256', (string) json_encode( $row ) ), $rows ); sort( $hashes );
	return array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', implode( '', $hashes ) ) );
}
$data = array( 'schema' => get_option( 'aish_schema_version' ), 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ) ), 'tables' => array(), 'inbox' => array() );
foreach ( $entities as $entity ) {
	$table = $wpdb->prefix . 'aish_' . $entity;
	$data['tables'][ $entity ] = p11_fingerprint( "SELECT * FROM `$table`" );
	$ddl = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
	if ( ! is_array( $ddl ) || ! isset( $ddl[1] ) || $wpdb->last_error ) { throw new RuntimeException( 'qa_structure_unavailable' ); }
	$data['tables'][ $entity ]['structure_sha256'] = hash( 'sha256', preg_replace( '/ AUTO_INCREMENT=[0-9]+/', '', $ddl[1] ) );
}
$data['inbox']['posts'] = p11_fingerprint( "SELECT * FROM {$wpdb->posts} WHERE post_type='aish_message'" );
$data['inbox']['postmeta'] = p11_fingerprint( "SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='aish_message'" );
$data['inbox']['revisions'] = p11_fingerprint( "SELECT r.* FROM {$wpdb->posts} r INNER JOIN {$wpdb->posts} p ON p.ID=r.post_parent WHERE p.post_type='aish_message' AND r.post_type='revision'" );
$data['inbox']['revision_meta'] = p11_fingerprint( "SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} r ON r.ID=m.post_id INNER JOIN {$wpdb->posts} p ON p.ID=r.post_parent WHERE p.post_type='aish_message' AND r.post_type='revision'" );
echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
