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
	foreach ( Schema::definitions( $db, '1.9.0' ) as $entity => $sql ) {
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
$baseline = json_decode( file_get_contents( dirname( __DIR__ ) . '/discovery/fixtures/schema-1.8.0.json' ), true, 512, JSON_THROW_ON_ERROR );
$current = Schema::definitions( $db, '1.9.0' ); $old = Schema::definitions( $db, '1.8.0' );
check( '1.9.0' === Schema::VERSION, 'Discovery targets schema 1.9.0.' );
check( 33 === count( $current ) && 26 === count( $old ), 'Exactly seven additive tables; no Phase 12 storage.' );
$added = array( 'editorial_collections', 'collection_translations', 'collection_items', 'use_cases', 'use_case_translations', 'use_case_skills', 'term_translations' );
check( $added === array_values( array_diff( array_keys( $current ), array_keys( $old ) ) ), 'Only the seven reviewed discovery tables are added.' );
foreach ( $baseline['definition_sha256'] as $entity => $expected ) {
	check( hash_equals( $expected, hash( 'sha256', $current[ $entity ] ) ), 'Existing schema definition unchanged: ' . $entity );
	check( hash_equals( $expected, hash( 'sha256', $old[ $entity ] ) ), 'Historical definition matches committed main: ' . $entity );
}
foreach ( $added as $entity ) { check( array_keys( Schema::COLUMNS[ $entity ] ) === array_column( $connection->fields[ $entity ], 'Field' ), 'DAL allowlist matches full physical schema: ' . $entity ); }
check( array( 'collection_id', 'skill_id' ) === Schema::UNIQUE_KEYS['collection_items']['PRIMARY'], 'Collections use stable unique Skill identities.' );
check( array( 'use_case_id', 'skill_id' ) === Schema::UNIQUE_KEYS['use_case_skills']['PRIMARY'], 'Bilingual use cases share a unique Skill relationship.' );
check( array( 'term_type', 'term_id', 'locale' ) === Schema::UNIQUE_KEYS['term_translations']['term_locale'], 'Translations preserve existing taxonomy IDs.' );
check( ! isset( Schema::COLUMNS['editorial_collections']['ends_at'] ), 'Scheduling supplements use bounded native options, not an unreviewed table change.' );
Schema::verify( $db, '1.9.0' ); check( true, 'Full 33-table schema model verifies.' );
$connection->queries = array(); Schema::verify( $db, '1.8.0' );
foreach ( $added as $entity ) { check( ! str_contains( implode( '\n', $connection->queries ), 'fixture_aish_' . $entity ), 'Historical verifier does not demand future tables: ' . $entity ); }
foreach ( array(
	array( 'editorial_collections', 'owner_user_id', 'Null', 'NO' ),
	array( 'editorial_collections', 'revision', 'Type', 'bigint' ),
	array( 'collection_translations', 'slug', 'Null', 'NO' ),
	array( 'collection_items', 'skill_id', 'Type', 'int unsigned' ),
	array( 'use_case_translations', 'locale', 'Type', 'varchar(5)' ),
	array( 'term_translations', 'description', 'Type', 'varchar(255)' ),
) as [ $entity, $field, $property, $value ] ) {
	[ $connection, $db ] = fixture();
	foreach ( $connection->fields[ $entity ] as &$column ) { if ( $field === $column['Field'] ) { $column[ $property ] = $value; } } unset( $column );
	rejects( fn() => Schema::verify( $db, '1.9.0' ), 'Malformed discovery type/nullability rejected: ' . $entity . '/' . $field );
}
foreach ( $added as $entity ) {
	foreach ( Schema::UNIQUE_KEYS[ $entity ] as $key => $columns ) {
		[ $connection, $db ] = fixture();
		foreach ( $connection->indexes[ $entity ] as &$index ) { if ( $index['Key_name'] === $key ) { $index['Sub_part'] = 8; } } unset( $index );
		rejects( fn() => Schema::verify( $db, '1.9.0' ), 'Truncated discovery uniqueness rejected: ' . $entity . '/' . $key );
	}
	foreach ( Schema::SEARCH_KEYS[ $entity ] ?? array() as $key => $columns ) {
		[ $connection, $db ] = fixture(); $connection->indexes[ $entity ] = array_filter( $connection->indexes[ $entity ], static fn( array $index ): bool => $key !== $index['Key_name'] );
		rejects( fn() => Schema::verify( $db, '1.9.0' ), 'Discovery query index required: ' . $entity . '/' . $key );
	}
	[ $connection, $db ] = fixture(); $connection->engines[ $entity ] = 'MyISAM';
	rejects( fn() => Schema::verify( $db, '1.9.0' ), 'Discovery memberships require transactions: ' . $entity );
}
echo 'Discovery schema: ' . $count . " isolated assertions passed (DDL/metadata model; no real WordPress/MySQL).\n";
