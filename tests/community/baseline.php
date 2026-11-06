<?php
declare(strict_types=1);

/** Read-only pre-migration evidence; never emit underlying records or credentials. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE9_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
$entities = array( 'repositories', 'skills', 'skill_translations', 'skill_versions', 'categories', 'tags', 'skill_categories', 'skill_tags', 'imports', 'jobs', 'import_logs', 'ai_jobs', 'ai_attempts', 'ai_reviews', 'email_verifications', 'user_notifications', 'notification_outbox' );
$data = array( 'schema' => get_option( 'aish_schema_version' ), 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ) ), 'tables' => array() );
foreach ( $entities as $entity ) {
	$rows = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->prefix . 'aish_' . $entity . '`', ARRAY_A );
	if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'qa_baseline_unavailable' ); }
	$hashes = array_map( static fn( array $row ): string => hash( 'sha256', (string) json_encode( $row ) ), $rows ); sort( $hashes );
	$data['tables'][ $entity ] = array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', implode( '', $hashes ) ) );
}
$data['private_messages'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='aish_message'" );
echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
