<?php
declare(strict_types=1);

/** Isolated native metadata failure/security contracts; no WordPress or MySQL is running. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DB_NAME', 'isolated_editorial' );
$checks = 0;
function check( bool $value, string $message ): void { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function __( string $message, string $domain = '' ): string { return $message; }
function wp_unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_slash( mixed $value ): mixed { return is_string( $value ) ? addslashes( $value ) : $value; }
function maybe_serialize( mixed $value ): mixed { return $value; }
function wp_suspend_cache_addition( ?bool $value = null ): bool { if ( null !== $value ) { $GLOBALS['cache_suspended'] = $value; } return $GLOBALS['cache_suspended']; }
function wp_cache_delete( int|string $key, string $group ): void { unset( $GLOBALS['cache'][ $group ][ $key ] ); }
function clean_post_cache( int $id ): void { $GLOBALS['cleaned'][] = $id; wp_cache_delete( $id, 'post_meta' ); }
function wp_is_post_revision( int $id ): bool { return $GLOBALS['revision']; }
function wp_is_post_autosave( int $id ): bool { return $GLOBALS['autosave']; }
function current_user_can( string $capability, int $id = 0 ): bool { return ! in_array( $id, $GLOBALS['denied'], true ); }
function wp_verify_nonce( string $nonce, string $action ): bool { return 'valid:' . $action === $nonce; }
function get_current_user_id(): int { return 5; }
function set_transient( string $key, mixed $value, int $expires ): bool { $GLOBALS['notices'][ $key ] = $value; return true; }
class WP_Post {
	public function __construct( public int $ID, public string $post_type = 'aish_tutorial', public string $post_content = 'Native body already saved.' ) {}
}
function get_post( int $id ): ?WP_Post { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_meta( int $id, string $key, bool $single = false ): mixed {
	if ( ! isset( $GLOBALS['cache']['post_meta'][ $id ] ) ) {
		$meta = $GLOBALS['metadata'][ $id ] ?? array();
		if ( ! wp_suspend_cache_addition() ) { $GLOBALS['cache']['post_meta'][ $id ] = $meta; }
	} else { $meta = $GLOBALS['cache']['post_meta'][ $id ]; }
	return $single ? ( $meta[ $key ][0] ?? '' ) : ( $meta[ $key ] ?? array() );
}
function fail_write( string $operation, int $id, string $key, string $value = '' ): bool {
	$GLOBALS['writes'][] = array( $operation, $id, $key, $value );
	$failure = $GLOBALS['failure'];
	if ( is_callable( $failure ) && $failure( $operation, $id, $key, $value ) ) {
		$GLOBALS['wpdb']->last_error = $GLOBALS['silent_failure'] ? '' : 'Sensitive SQL details';
		return true;
	}
	return false;
}
function update_post_meta( int $id, string $key, string $value ): bool {
	$value = wp_unslash( $value );
	if ( fail_write( 'update', $id, $key, $value ) ) { return false; }
	if ( ( $GLOBALS['metadata'][ $id ][ $key ] ?? array() ) === array( $value ) ) { return false; }
	$GLOBALS['metadata'][ $id ][ $key ] = array( $value ); wp_cache_delete( $id, 'post_meta' ); return true;
}
function delete_post_meta( int $id, string $key ): bool {
	if ( fail_write( 'delete', $id, $key ) ) { return false; }
	$exists = isset( $GLOBALS['metadata'][ $id ][ $key ] ); unset( $GLOBALS['metadata'][ $id ][ $key ] ); wp_cache_delete( $id, 'post_meta' ); return $exists;
}
function add_post_meta( int $id, string $key, string $value ): int|false {
	$value = wp_unslash( $value );
	if ( fail_write( 'add', $id, $key, $value ) ) { return false; }
	$GLOBALS['metadata'][ $id ][ $key ][] = $value; wp_cache_delete( $id, 'post_meta' ); return 100;
}
class wpdb {
	public string $prefix = 'wp_';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';
	public bool $suppressed = false;
	public bool $locked = false;
	public bool $busy = false;
	public array $queries = array();
	public ?array $snapshot = null;
	public mixed $on_lock = null;
	public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function prepare( string $sql, mixed ...$values ): string {
		if ( count( $values ) === 1 && is_array( $values[0] ) ) { $values = $values[0]; }
		return preg_replace_callback( '/%[ds]/', static function ( array $match ) use ( &$values ): string {
			$value = array_shift( $values ); return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
		}, $sql );
	}
	public function query( string $sql ): int {
		$this->last_error = ''; $this->queries[] = $sql;
		if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $GLOBALS['metadata']; }
		elseif ( 'ROLLBACK' === $sql ) { $GLOBALS['metadata'] = $this->snapshot; $this->snapshot = null; }
		elseif ( 'COMMIT' === $sql ) { $this->snapshot = null; }
		else { throw new RuntimeException( 'Unexpected SQL command.' ); }
		return 1;
	}
	public function get_var( string $sql ): mixed {
		$this->last_error = ''; $this->queries[] = $sql;
		if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $this->locked = false; return '1'; }
		if ( str_contains( $sql, 'GET_LOCK' ) ) {
			if ( $this->busy ) { return '0'; }
			$this->locked = true;
			if ( is_callable( $this->on_lock ) ) { ( $this->on_lock )(); }
			return '1';
		}
		throw new RuntimeException( 'Unexpected scalar query.' );
	}
	public function get_results( string $sql, string $format ): array {
		$this->last_error = ''; $this->queries[] = $sql;
		if ( preg_match( "/^SELECT meta_value FROM wp_postmeta WHERE post_id=([0-9]+) AND meta_key='([^']*)' ORDER BY meta_id$/D", $sql, $match ) ) {
			return array_map( static fn( string $value ): array => array( 'meta_value' => $value ), $GLOBALS['metadata'][ (int) $match[1] ][ $match[2] ] ?? array() );
		}
		if ( preg_match( '/^SELECT id FROM `wp_aish_skills` WHERE id IN \(([0-9,]+)\) FOR SHARE$/D', $sql, $match ) ) {
			return array_map( static fn( int $id ): array => array( 'id' => $id ), array_values( array_intersect( array_map( 'intval', explode( ',', $match[1] ) ), array( 1, 2, 3 ) ) ) );
		}
		throw new RuntimeException( 'Unexpected row query: ' . $sql );
	}
}
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Security/Input.php', 'Database/Schema.php', 'Database/Database.php', 'Frontend/NativeMetadata.php', 'Frontend/Editorial.php' ) as $file ) { require $base . $file; }
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Frontend\Editorial;
use AISkillsHub\Core\Frontend\NativeMetadata;

function reset_case(): Editorial {
	$GLOBALS['wpdb'] = new wpdb(); $GLOBALS['cache_suspended'] = false; $GLOBALS['cache'] = array(); $GLOBALS['cleaned'] = array();
	$GLOBALS['revision'] = $GLOBALS['autosave'] = $GLOBALS['silent_failure'] = false;
	$GLOBALS['denied'] = $GLOBALS['notices'] = $GLOBALS['writes'] = array(); $GLOBALS['failure'] = null;
	$GLOBALS['posts'] = array( 10 => new WP_Post( 10 ), 11 => new WP_Post( 11 ), 12 => new WP_Post( 12 ), 20 => new WP_Post( 20, 'page' ) );
	$GLOBALS['metadata'] = array(
		10 => array( '_aish_locale' => array( 'zh_CN' ), '_aish_translation_id' => array( '11' ), '_aish_related_skill' => array( '1' ) ),
		11 => array( '_aish_locale' => array( 'en_US' ), '_aish_translation_id' => array( '10' ) ),
		12 => array( '_aish_locale' => array( 'en_US' ) ),
		20 => array( '_aish_locale' => array( 'en_US' ), '_aish_page_kind' => array( 'about' ) ),
	);
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array( '_aish_editorial_nonce' => 'valid:aish_editorial_10', 'aish_editorial_locale' => 'zh_CN', 'aish_editorial_translation' => '12', 'aish_editorial_skills' => '2,3' );
	return new Editorial( null, new Database( $GLOBALS['wpdb'] ) );
}
function saved( Editorial $editorial, int $id = 10 ): void { $editorial->saveMeta( $id, $GLOBALS['posts'][ $id ] ); }
function cleanup_ok(): void {
	check( ! $GLOBALS['wpdb']->locked && ! $GLOBALS['wpdb']->suppressed && null === $GLOBALS['wpdb']->snapshot, 'Connection/lock/transaction state leaked.' );
	check( ! wp_suspend_cache_addition(), 'Cache addition suspension was not restored.' );
	foreach ( $GLOBALS['cleaned'] as $id ) { check( ! isset( $GLOBALS['cache']['post_meta'][ $id ] ), 'Native metadata cache was not cleared.' ); }
}

$editorial = reset_case(); saved( $editorial );
check( array( '12' ) === $GLOBALS['metadata'][10]['_aish_translation_id'] && array( '10' ) === $GLOBALS['metadata'][12]['_aish_translation_id'], 'Pair was not saved reciprocally.' );
check( ! isset( $GLOBALS['metadata'][11]['_aish_translation_id'] ) && array( '2', '3' ) === $GLOBALS['metadata'][10]['_aish_related_skill'], 'Old pair or relations were not replaced.' );
check( ! $GLOBALS['notices'], 'Valid metadata save raised an error.' ); cleanup_ok();
saved( $editorial ); check( ! $GLOBALS['notices'], 'Unchanged values should allow update_post_meta false.' ); cleanup_ok();

foreach ( array( 'second_link', 'reverse_pair', 'old_pair', 'silent_failure' ) as $failure_case ) {
	$editorial = reset_case(); $before = $GLOBALS['metadata'];
	$GLOBALS['silent_failure'] = 'silent_failure' === $failure_case;
	$GLOBALS['failure'] = static fn( string $op, int $id, string $key, string $value ): bool => match ( $failure_case ) {
		'second_link' => 'add' === $op && '_aish_related_skill' === $key && '3' === $value,
		'old_pair' => 'delete' === $op && 11 === $id,
		default => 'update' === $op && 12 === $id && '_aish_translation_id' === $key,
	};
	saved( $editorial );
	check( $before === $GLOBALS['metadata'], 'Metadata partially persisted after ' . $failure_case );
	check( isset( $GLOBALS['notices']['aish_editorial_error_5'] ) && ! str_contains( $GLOBALS['notices']['aish_editorial_error_5'], 'Sensitive' ), 'Failure was silent or leaked SQL.' );
	check( 'Native body already saved.' === $GLOBALS['posts'][10]->post_content, 'Metadata rollback changed the already saved native body.' ); cleanup_ok();
}

$editorial = reset_case(); $before = $GLOBALS['metadata'];
$GLOBALS['cache']['post_meta'][12] = $GLOBALS['metadata'][12];
$GLOBALS['wpdb']->on_lock = static function (): void { $GLOBALS['metadata'][12]['_aish_translation_id'] = array( '99' ); };
saved( $editorial );
check( array( '99' ) === $GLOBALS['metadata'][12]['_aish_translation_id'] && $before[10] === $GLOBALS['metadata'][10] && ! $GLOBALS['writes'], 'Concurrent pair assignment was overwritten through stale cache.' ); cleanup_ok();

foreach ( array( 'GET', 'nonce', 'capability', 'other_capability', 'previous_capability', 'autosave', 'revision', 'type', 'id', 'missing_skill', 'busy', 'locale' ) as $guard ) {
	$editorial = reset_case(); $before = $GLOBALS['metadata']; $id = 10;
	switch ( $guard ) {
		case 'GET': $_SERVER['REQUEST_METHOD'] = 'GET'; break;
		case 'nonce': $_POST['_aish_editorial_nonce'] = 'invalid'; break;
		case 'capability': $GLOBALS['denied'] = array( 10 ); break;
		case 'other_capability': $GLOBALS['denied'] = array( 12 ); break;
		case 'previous_capability': $GLOBALS['denied'] = array( 11 ); break;
		case 'autosave': $GLOBALS['autosave'] = true; break;
		case 'revision': $GLOBALS['revision'] = true; break;
		case 'type': $GLOBALS['posts'][10]->post_type = 'aish_message'; break;
		case 'id': $GLOBALS['posts'][10]->ID = 20; break;
		case 'missing_skill': $_POST['aish_editorial_skills'] = '999'; break;
		case 'busy': $GLOBALS['wpdb']->busy = true; break;
		case 'locale': $_POST['aish_editorial_locale'] = array( 'en_US' ); break;
	}
	saved( $editorial ); check( $before === $GLOBALS['metadata'] && ! $GLOBALS['writes'], 'Invalid mutation was accepted: ' . $guard ); cleanup_ok();
}

$editorial = reset_case(); $_POST['aish_editorial_translation'] = ''; $_POST['aish_editorial_skills'] = ''; saved( $editorial );
check( ! isset( $GLOBALS['metadata'][10]['_aish_translation_id'], $GLOBALS['metadata'][11]['_aish_translation_id'] ) && ! isset( $GLOBALS['metadata'][10]['_aish_related_skill'] ), 'Explicit pair/link removal failed.' ); cleanup_ok();

foreach ( array( false, true ) as $fail ) {
	$editorial = reset_case(); $before = $GLOBALS['metadata'];
	$_POST = array( '_aish_editorial_nonce' => 'valid:aish_editorial_20', 'aish_editorial_locale' => 'zh_CN', 'aish_page_kind' => 'contact' );
	if ( $fail ) { $GLOBALS['failure'] = static fn( string $op, int $id, string $key ): bool => '_aish_page_kind' === $key; }
	saved( $editorial, 20 );
	check( $fail ? $before === $GLOBALS['metadata'] : array( '_aish_locale' => array( 'zh_CN' ), '_aish_page_kind' => array( 'contact' ) ) === $GLOBALS['metadata'][20], 'Page language and purpose did not change atomically.' ); cleanup_ok();
}

$editorial = reset_case(); wp_suspend_cache_addition( true ); saved( $editorial );
check( wp_suspend_cache_addition(), 'Prior cache-addition suspension was lost.' );
wp_suspend_cache_addition( false ); cleanup_ok();
$writer = new NativeMetadata( new Database( $GLOBALS['wpdb'] ) );
$literal = "Literal C:\\skills\\test and 'quotes'";
$writer->transaction( static fn( NativeMetadata $meta ) => $meta->set( 20, '_literal', $literal ) );
check( array( $literal ) === $GLOBALS['metadata'][20]['_literal'], 'Native metadata unslashing changed literal content.' ); cleanup_ok();
echo 'Editorial management contracts: ' . $checks . " assertions passed (isolated stubs; no WordPress/MySQL integration).\n";
