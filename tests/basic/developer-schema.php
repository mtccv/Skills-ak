<?php
declare(strict_types=1);

// DDL and physical-schema verification model only; no WordPress/MySQL connection.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
class wpdb {
	public string $prefix = 'fixture_'; public string $last_error = '';
	public array $fields = array(); public array $indexes = array(); public array $queries = array(); public array $engines = array(); public array $collations = array(); private array $prepared = array();
	public function suppress_errors( bool $value ): bool { return false; }
	public function prepare( string $sql, array $values ): string { $id = 'prepared:' . count( $this->prepared ); $this->prepared[ $id ] = array( $sql, $values ); return $id; }
	public function get_results( string $sql, string $format ): array {
		$this->queries[] = $sql;
		if ( ! preg_match( '/^SHOW (?:FULL )?(COLUMNS|INDEX) FROM `fixture_aish_([a-z_]+)`$/D', $sql, $match ) ) { throw new RuntimeException( 'Unexpected schema query.' ); }
		return 'INDEX' === $match[1] ? $this->indexes[ $match[2] ] : $this->fields[ $match[2] ];
	}
	public function get_var( string $id ): mixed {
		[ $sql, $values ] = $this->prepared[ $id ]; $this->queries[] = $sql;
		$entity = substr( $values[0], strlen( 'fixture_aish_' ) );
		return str_starts_with( $sql, 'SELECT ENGINE' ) ? ( $this->engines[ $entity ] ?? 'InnoDB' ) : ( $this->collations[ $entity ] ?? 'utf8mb4_unicode_ci' );
	}
}
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Database/Schema.php';
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Database/Database.php';
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Schema;
$count = 0;
function check( bool $value, string $label ): void { global $count; if ( ! $value ) { throw new RuntimeException( $label ); } ++$count; }
function rejects( callable $callback, string $label ): void { try { $callback(); } catch ( RuntimeException $error ) { check( true, $label ); return; } throw new RuntimeException( $label ); }
function fixture(): array {
	$connection = new wpdb(); $db = new Database( $connection );
	foreach ( Schema::definitions( $db, '1.8.0' ) as $entity => $sql ) {
		$connection->fields[ $entity ] = array(); $connection->indexes[ $entity ] = array();
		foreach ( explode( "\n", $sql ) as $line ) {
			if ( preg_match( '/^([a-z_]+) ([a-z]+(?:\([0-9,]+\))?(?: unsigned)?)(.*)$/D', rtrim( $line, ',' ), $m ) ) {
				$type = preg_replace( '/\b(bigint|int|tinyint)\(\d+\)/', '$1', $m[2] );
				$connection->fields[ $entity ][] = array( 'Field' => $m[1], 'Type' => $type, 'Null' => str_contains( $m[3], 'NOT NULL' ) ? 'NO' : 'YES', 'Extra' => str_contains( $m[3], 'AUTO_INCREMENT' ) ? 'auto_increment' : '', 'Collation' => str_contains( $m[3], 'ascii_bin' ) ? 'ascii_bin' : 'utf8mb4_unicode_ci' );
			} elseif ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:([a-z_]+) )?\(([^)]+)\),?$/D', $line, $m ) ) {
				foreach ( explode( ',', $m[3] ) as $i => $column ) { $connection->indexes[ $entity ][] = array( 'Key_name' => 'PRIMARY KEY' === $m[1] ? 'PRIMARY' : $m[2], 'Non_unique' => 'KEY' === $m[1] ? 1 : 0, 'Sub_part' => null, 'Seq_in_index' => $i + 1, 'Column_name' => $column ); }
			}
		}
	}
	return array( $connection, $db );
}
[ $connection, $db ] = fixture();
$baseline = json_decode( file_get_contents( dirname( __DIR__ ) . '/contributions/fixtures/schema-1.7.0.json' ), true, 512, JSON_THROW_ON_ERROR );
check( '1.8.0' === Schema::VERSION, 'Current schema is Phase 10 version 1.8.0.' );
$current = Schema::definitions( $db, '1.8.0' );
check( 26 === count( $current ), 'Exactly 26 business tables, retaining the existing 25.' );
check( array( 'author_claims' ) === array_values( array_diff( array_keys( $current ), array_keys( $baseline['definition_sha256'] ) ) ), 'Claims are the only new table; submissions remain native WordPress records.' );
foreach ( $baseline['definition_sha256'] as $entity => $expected ) {
	check( hash_equals( $expected, hash( 'sha256', $current[ $entity ] ) ), 'Committed original definition unchanged: ' . $entity );
	check( hash_equals( $expected, hash( 'sha256', Schema::definitions( $db, '1.7.0' )[ $entity ] ) ), 'Historical schema retains the baseline definition: ' . $entity );
}
check( array_keys( Schema::COLUMNS['author_claims'] ) === array_column( $connection->fields['author_claims'], 'Field' ), 'All 17 claim fields match the physical definition and DAL allowlist.' );
check( array( 'request_key' ) === Schema::UNIQUE_KEYS['author_claims']['request_identity'], 'Every claim has a unique idempotency identity.' );
check( array( 'active_request_key' ) === Schema::UNIQUE_KEYS['author_claims']['active_request'], 'Only one active application for each controlled claimant/Skill identity.' );
check( array( 'approved_skill_key' ) === Schema::UNIQUE_KEYS['author_claims']['approved_skill'], 'A Skill has one primary verified attribution, independent of locale.' );
check( ! isset( $current['submissions'], $current['editorial_collections'], $current['newsletter_subscriptions'] ), 'No duplicate submission or later-phase storage.' );
Schema::verify( $db, '1.8.0' ); check( true, 'The complete physical-schema model verifies.' );
$connection->queries = array(); Schema::verify( $db, '1.7.0' );
check( ! str_contains( implode( '\n', $connection->queries ), 'fixture_aish_author_claims' ), 'Historical verification never requires the new table.' );
foreach ( array(
	array( 'claimant_user_id', 'Null', 'NO' ), array( 'approved_skill_key', 'Null', 'NO' ),
	array( 'skill_id', 'Type', 'bigint' ), array( 'source_revision', 'Type', 'int unsigned' ),
	array( 'claim_role', 'Type', 'varchar(8)' ), array( 'request_key', 'Collation', 'utf8mb4_unicode_ci' ),
	array( 'active_request_key', 'Collation', 'ascii_general_ci' ), array( 'proof_json', 'Type', 'varchar(255)' ),
) as [ $field, $property, $value ] ) {
	[ $connection, $db ] = fixture();
	foreach ( $connection->fields['author_claims'] as &$column ) { if ( $field === $column['Field'] ) { $column[ $property ] = $value; } } unset( $column );
	rejects( fn() => Schema::verify( $db, '1.8.0' ), 'Malformed claim contract rejected: ' . $field . '/' . $property );
}
foreach ( array( 'request_identity', 'active_request', 'approved_skill' ) as $key ) {
	[ $connection, $db ] = fixture();
	foreach ( $connection->indexes['author_claims'] as &$index ) { if ( $index['Key_name'] === $key ) { $index['Sub_part'] = 16; } } unset( $index );
	rejects( fn() => Schema::verify( $db, '1.8.0' ), 'Incomplete claim identity index rejected: ' . $key );
}
foreach ( array( 'skill_state', 'claimant_state', 'claim_queue' ) as $key ) {
	[ $connection, $db ] = fixture();
	$connection->indexes['author_claims'] = array_filter( $connection->indexes['author_claims'], fn( array $i ): bool => $key !== $i['Key_name'] );
	rejects( fn() => Schema::verify( $db, '1.8.0' ), 'Claim pagination/review index is required: ' . $key );
}
[ $connection, $db ] = fixture(); $connection->engines['author_claims'] = 'MyISAM';
rejects( fn() => Schema::verify( $db, '1.8.0' ), 'Nontransactional claims are rejected.' );
[ $connection, $db ] = fixture(); $connection->collations['author_claims'] = 'latin1_swedish_ci';
rejects( fn() => Schema::verify( $db, '1.8.0' ), 'Non-Unicode claim evidence is rejected.' );
echo 'Developer schema: ' . $count . " isolated assertions passed (DDL/metadata model; no real WordPress/MySQL).\n";
