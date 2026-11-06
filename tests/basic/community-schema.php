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
	foreach ( Schema::definitions( $db, '1.7.0' ) as $entity => $sql ) {
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
$old = json_decode( file_get_contents( dirname( __DIR__ ) . '/community/fixtures/schema-1.6.0.json' ), true, 512, JSON_THROW_ON_ERROR );
check( '1.7.0' === Schema::VERSION, 'Current schema is 1.7.0.' );
foreach ( array( '1.0.0' => 8, '1.1.0' => 8, '1.2.0' => 8, '1.3.0' => 8, '1.4.0' => 11, '1.5.0' => 14, '1.6.0' => 17, '1.7.0' => 25 ) as $version => $total ) { check( count( Schema::definitions( $db, $version ) ) === $total, 'Historical table count: ' . $version ); }
$current = Schema::definitions( $db, '1.7.0' );
foreach ( $old['definition_sha256'] as $entity => $expected ) {
	check( hash_equals( $expected, hash( 'sha256', $current[ $entity ] ) ), 'Existing committed definition preserved: ' . $entity );
}
$community = array( 'favorites', 'reviews', 'review_revisions', 'review_reports', 'skill_reports', 'workflow_events', 'metric_events', 'daily_metrics' );
check( array_values( array_diff( array_keys( $current ), array_keys( $old['definition_sha256'] ) ) ) === $community, 'Exactly eight planned tables added.' );
check( array( 'user_id', 'skill_id' ) === Schema::UNIQUE_KEYS['favorites']['PRIMARY'], 'Favorites use one cross-language user/Skill relationship.' );
check( array( 'user_id', 'skill_id' ) === Schema::UNIQUE_KEYS['reviews']['user_skill'], 'One persistent review identity per user/Skill.' );
check( array( 'review_id', 'revision' ) === Schema::UNIQUE_KEYS['review_revisions']['review_revision'], 'Revision identity is independent from the review head.' );
check( array( 'request_key' ) === Schema::UNIQUE_KEYS['review_revisions']['request_identity'], 'Review requests have a complete unique idempotency key.' );
check( array( 'event_day', 'metric_name', 'dedupe_key' ) === Schema::UNIQUE_KEYS['metric_events']['daily_event'], 'Metric deduplication includes UTC bucket and action.' );
check( array( 'stat_day', 'metric_name', 'dimension_type', 'dimension_key', 'locale' ) === Schema::UNIQUE_KEYS['daily_metrics']['PRIMARY'], 'Daily projection has a complete dimension identity.' );
foreach ( array( 'title', 'platform', 'version_label' ) as $field ) { check( isset( Schema::COLUMNS['review_revisions'][ $field ] ), 'Requested review field has independent storage: ' . $field ); }
foreach ( $community as $entity ) { check( array_keys( Schema::COLUMNS[ $entity ] ) === array_column( $connection->fields[ $entity ], 'Field' ), 'DAL fields match migration: ' . $entity ); }
check( ! isset( $current['author_claims'], $current['editorial_collections'], $current['newsletter_subscriptions'] ), 'No later-phase table is created.' );
Schema::verify( $db, '1.7.0' ); check( true, 'Complete physical schema model verifies.' );
$connection->queries = array(); Schema::verify( $db, '1.6.0' );
check( ! preg_match( '/fixture_aish_(favorites|reviews|review_revisions|review_reports|skill_reports|workflow_events|metric_events|daily_metrics)/', implode( "\n", $connection->queries ) ), 'Historical verification never asks for community tables.' );
foreach ( array(
	array( 'reviews', 'user_id', 'Null', 'NO' ),
	array( 'reviews', 'approved_revision_id', 'Null', 'NO' ),
	array( 'review_revisions', 'rating', 'Type', 'varchar(1)' ),
	array( 'review_revisions', 'rating', 'Type', 'tinyint' ),
	array( 'review_revisions', 'title', 'Type', 'varchar(100)' ),
	array( 'review_reports', 'active_key', 'Null', 'NO' ),
	array( 'review_reports', 'request_key', 'Collation', 'utf8mb4_unicode_ci' ),
	array( 'metric_events', 'event_day', 'Type', 'datetime' ),
	array( 'daily_metrics', 'metric_count', 'Type', 'bigint' ),
) as [ $entity, $field, $property, $value ] ) {
	[ $connection, $db ] = fixture();
	foreach ( $connection->fields[ $entity ] as &$column ) { if ( $field === $column['Field'] ) { $column[ $property ] = $value; } } unset( $column );
	rejects( fn() => Schema::verify( $db, '1.7.0' ), 'Malformed physical contract rejected: ' . $entity . '.' . $field . '/' . $property );
}
foreach ( array( 'user_skill', 'review_revision', 'request_identity', 'active_report', 'daily_event' ) as $key ) {
	[ $connection, $db ] = fixture();
	foreach ( $connection->indexes as &$indexes ) { foreach ( $indexes as &$index ) { if ( $index['Key_name'] === $key ) { $index['Sub_part'] = 16; } } unset( $index ); } unset( $indexes );
	rejects( fn() => Schema::verify( $db, '1.7.0' ), 'Truncated identity index rejected: ' . $key );
}
[ $connection, $db ] = fixture(); $connection->indexes['favorites'] = array_filter( $connection->indexes['favorites'], fn( array $i ): bool => 'user_active' !== $i['Key_name'] );
rejects( fn() => Schema::verify( $db, '1.7.0' ), 'Missing favorite pagination index rejected.' );
[ $connection, $db ] = fixture(); $connection->engines['reviews'] = 'MyISAM';
rejects( fn() => Schema::verify( $db, '1.7.0' ), 'Nontransactional review storage rejected.' );
[ $connection, $db ] = fixture(); $connection->collations['review_revisions'] = 'latin1_swedish_ci';
rejects( fn() => Schema::verify( $db, '1.7.0' ), 'Non-Unicode revisions rejected.' );
echo 'Community schema: ' . $count . " isolated assertions passed (DDL/physical metadata model, no real WordPress/MySQL).\n";
