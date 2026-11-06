<?php
declare(strict_types=1);

/** Explicit isolated upgrade. Run once before any Phase 9 fixtures are created. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE9_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
$baseline = json_decode( (string) file_get_contents( '/repo/tests/community/results/baseline-php' . ( PHP_VERSION_ID >= 80400 ? '84' : '83' ) . '.json' ), true, 512, JSON_THROW_ON_ERROR );
if ( '1.6.0' !== $baseline['schema'] || count( $baseline['tables'] ) !== 17 ) { exit( 3 ); }
$core = AISkillsHub\Core\Core::boot(); $db = $core->get( 'database' );
$checks = array();
try {
	$beforeVersion = get_option( 'aish_schema_version' );
	if ( '1.6.0' !== $beforeVersion ) { throw new RuntimeException( 'Expected untouched schema 1.6.0 fixture.' ); }
	$checks['incremental_migration'] = true === $core->migrator()->migrate();
	$checks['schema_version'] = '1.7.0' === get_option( 'aish_schema_version' );
	AISkillsHub\Core\Database\Schema::verify( $db, '1.7.0' ); $checks['physical_schema'] = true;
	$checks['repeat_migration'] = true === $core->migrator()->migrate();
	$tables = $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', array( $wpdb->esc_like( $wpdb->prefix . 'aish_' ) . '%' ) );
	$checks['exactly_25_tables'] = count( $tables ) === 25;
	foreach ( $baseline['tables'] as $entity => $saved ) {
		$rows = $db->rows( 'SELECT * FROM ' . $db->table( $entity ) );
		$hashes = array_map( static fn( array $row ): string => hash( 'sha256', (string) json_encode( $row ) ), $rows ); sort( $hashes );
		$checks['preserve_' . $entity] = count( $rows ) === $saved['count'] && hash_equals( $saved['sha256'], hash( 'sha256', implode( '', $hashes ) ) );
	}
	$checks['private_messages_preserved'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='aish_message'" ) === $baseline['private_messages'];
} catch ( Throwable $error ) { $checks['unexpected_error'] = false; }
$failed = count( array_filter( $checks, static fn( bool $pass ): bool => ! $pass ) );
echo json_encode( array( 'environment' => $baseline['environment'], 'kind' => 'Real WordPress/MySQL incremental migration; no external APIs', 'schema_before' => $beforeVersion, 'schema_after' => get_option( 'aish_schema_version' ), 'original_tables' => 17, 'original_rows' => array_sum( array_column( $baseline['tables'], 'count' ) ), 'private_messages_before' => $baseline['private_messages'], 'checks' => $checks, 'passed' => count( $checks ) - $failed, 'failed' => $failed ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failed ? 1 : 0 );
