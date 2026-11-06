<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE8_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Database\Schema;

$prefix = 'p8_test_' . bin2hex( random_bytes( 5 ) ) . '_';
$option = 'p8_test_schema_' . bin2hex( random_bytes( 5 ) );
$db = new Database( $wpdb, $prefix ); $migrator = new Migrator( $db, $option ); $checks = array();
$check = static function ( bool $ok, string $id ) use ( &$checks ): void { $checks[] = array( 'id' => $id, 'status' => $ok ? 'passed' : 'failed' ); if ( ! $ok ) { throw new RuntimeException( $id ); } };
try {
	$check( true === $migrator->migrate( '1.5.0' ), 'fresh-legacy-schema-1.5' );
	Schema::verify( $db, '1.5.0' );
	$check( 14 === (int) $db->value( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', array( $wpdb->esc_like( $prefix . 'aish_' ) . '%' ) ), 'legacy-fourteen-only' );
	$id = $db->insert( 'skills', array( 'repository_id' => null, 'source_path' => null, 'status' => 'draft', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$before = $db->row( 'SELECT * FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $id ) );
	// Deliberate DDL obstruction in our random test prefix only; no existing tables are removed.
	$obstruction = $db->table( 'notification_outbox' ); $db->execute( "CREATE VIEW $obstruction AS SELECT 1 AS id" );
	$result = $migrator->migrate( '1.6.0' );
	$check( is_wp_error( $result ) && $migrator->version() === '1.5.0', 'partial-ddl-failure-retains-last-version' );
	$db->execute( "DROP VIEW $obstruction" );
	$check( true === $migrator->migrate( '1.6.0' ), 'partial-ddl-recovery' );
	Schema::verify( $db, '1.6.0' );
	$check( 17 === (int) $db->value( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', array( $wpdb->esc_like( $prefix . 'aish_' ) . '%' ) ), 'exactly-seventeen-no-future-tables' );
	$check( $before === $db->row( 'SELECT * FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $id ) ), 'existing-stable-id-and-row-unchanged' );
	$check( true === $migrator->migrate() && true === $migrator->migrate(), 'repeat-upgrade-idempotent' );
	$check( is_wp_error( $migrator->migrate( '1.5.0' ) ), 'downgrade-refused' );
	update_option( $option, '9.9.9', false );
	$check( is_wp_error( $migrator->migrate() ) && $migrator->version() === '9.9.9', 'future-version-refused' );
} catch ( Throwable $e ) { $checks[] = array( 'id' => 'unexpected-exception', 'status' => 'failed', 'reason' => 'Test did not complete; inspect isolated diagnostic.' ); }
finally {
	// Every name is derived from this invocation's random prefix; never drop a live theme table.
	foreach ( array_reverse( array_keys( Schema::COLUMNS ) ) as $entity ) {
		$table = $db->table( $entity ); $wpdb->query( "DROP TABLE IF EXISTS $table" );
	}
	delete_option( $option );
}
$failed = count( array_filter( $checks, static fn( array $case ): bool => $case['status'] === 'failed' ) );
echo json_encode( array( 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ) ), 'kind' => 'real MySQL migration in random isolated prefix', 'checks' => $checks, 'passed' => count( $checks ) - $failed, 'failed' => $failed ), JSON_PRETTY_PRINT ) . "\n";
exit( $failed ? 1 : 0 );
