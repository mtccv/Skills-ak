<?php
declare(strict_types=1);

/** Run once, before mutable Phase 10 fixtures. Never reset or replace a database. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE10_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
$baseline = json_decode( (string) file_get_contents( '/repo/tests/contributions/results/baseline-php' . ( PHP_VERSION_ID >= 80400 ? '84' : '83' ) . '.json' ), true, 512, JSON_THROW_ON_ERROR );
if ( '1.7.0' !== $baseline['schema'] || count( $baseline['tables'] ) !== 25 ) { exit( 3 ); }
$core = AISkillsHub\Core\Core::boot(); $db = $core->get( 'database' ); $checks = array();
function p10_rows_fingerprint( string $sql ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'qa_preservation_unavailable' ); }
	$hashes = array_map( static fn( array $row ): string => hash( 'sha256', (string) json_encode( $row ) ), $rows ); sort( $hashes );
	return array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', implode( '', $hashes ) ) );
}
try {
	$beforeVersion = get_option( 'aish_schema_version' );
	if ( '1.7.0' !== $beforeVersion ) { throw new RuntimeException( 'untouched_schema_1_7_required' ); }
	$checks['incremental_migration'] = true === $core->migrator()->migrate( '1.8.0' );
	$checks['schema_version'] = '1.8.0' === get_option( 'aish_schema_version' );
	AISkillsHub\Core\Database\Schema::verify( $db, '1.8.0' ); $checks['physical_schema'] = true;
	$checks['repeat_migration'] = true === $core->migrator()->migrate( '1.8.0' );
	$tables = $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', array( $wpdb->esc_like( $wpdb->prefix . 'aish_' ) . '%' ) );
	$checks['exactly_26_tables'] = count( $tables ) === 26;
	foreach ( $baseline['tables'] as $entity => $saved ) {
		$table = $wpdb->prefix . 'aish_' . $entity; $rows = p10_rows_fingerprint( "SELECT * FROM `$table`" );
		$checks['data_' . $entity] = $saved['count'] === $rows['count'] && hash_equals( $saved['sha256'], $rows['sha256'] );
		$ddl = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
		if ( ! is_array( $ddl ) || ! isset( $ddl[1] ) || $wpdb->last_error ) { throw new RuntimeException( 'qa_structure_unavailable' ); }
		$checks['structure_' . $entity] = hash_equals( $saved['structure_sha256'], hash( 'sha256', preg_replace( '/ AUTO_INCREMENT=[0-9]+/', '', $ddl[1] ) ) );
	}
	$sqls = array(
		'posts' => "SELECT * FROM {$wpdb->posts} WHERE post_type='aish_message'",
		'postmeta' => "SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='aish_message'",
		'revisions' => "SELECT r.* FROM {$wpdb->posts} r INNER JOIN {$wpdb->posts} p ON p.ID=r.post_parent WHERE p.post_type='aish_message' AND r.post_type='revision'",
		'revision_meta' => "SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} r ON r.ID=m.post_id INNER JOIN {$wpdb->posts} p ON p.ID=r.post_parent WHERE p.post_type='aish_message' AND r.post_type='revision'",
	);
	foreach ( $sqls as $kind => $sql ) { $checks['inbox_' . $kind] = p10_rows_fingerprint( $sql ) === $baseline['inbox'][ $kind ]; }
} catch ( Throwable $error ) { $checks['unexpected_error'] = false; }
$failed = count( array_filter( $checks, static fn( bool $pass ): bool => ! $pass ) );
echo json_encode( array( 'environment' => $baseline['environment'], 'kind' => 'Real WordPress/MySQL incremental migration; no external APIs', 'schema_before' => $beforeVersion ?? null, 'schema_after' => get_option( 'aish_schema_version' ), 'original_tables' => 25, 'original_rows' => array_sum( array_column( $baseline['tables'], 'count' ) ), 'inbox_before' => array_map( static fn( array $v ): int => $v['count'], $baseline['inbox'] ), 'checks' => $checks, 'passed' => count( $checks ) - $failed, 'failed' => $failed ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failed ? 1 : 0 );
