<?php
declare(strict_types=1);

/** Native inbox write contracts with in-memory WordPress/SQL substitutes; no integration services. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DB_NAME', 'inbox_contract' );
$GLOBALS['checks'] = 0;
$GLOBALS['hooks'] = array();
function check( bool $condition, string $message ): void { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function __( string $value, string $domain = '' ): string { return $value; }
function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $value, string $domain = '' ): string { return esc_html( $value ); }
function add_action( string $name, callable $callback, int $priority = 10, int $accepted = 1 ): void { $GLOBALS['hooks'][ $name ][] = $callback; }
function add_filter( string $name, callable $callback, int $priority = 10, int $accepted = 1 ): void { add_action( $name, $callback, $priority, $accepted ); }
function current_user_can( string $cap, mixed ...$args ): bool { return $GLOBALS['caps'][ $cap ] ?? false; }
function get_current_user_id(): int { return 23; }
function wp_is_post_revision( int $id ): bool { return $GLOBALS['revision']; }
function wp_is_post_autosave( int $id ): bool { return $GLOBALS['autosave']; }
function wp_verify_nonce( string $value, string $action ): bool { return 'valid' === $value && 'aish_message_state_17' === $action; }
function wp_unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_slash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
function maybe_serialize( string $value ): string { return $value; }
function get_post( int $id ): ?WP_Post { return $GLOBALS['posts'][ $id ] ?? null; }
function wp_suspend_cache_addition( ?bool $value = null ): bool { $previous = $GLOBALS['suspended']; if ( null !== $value ) { $GLOBALS['suspended'] = $value; } return $previous; }
function clean_post_cache( int $id ): void { $GLOBALS['cleaned'][] = $id; unset( $GLOBALS['cache'][ $id ] ); }
function wp_cache_delete( int $id, string $group ): void { if ( 'post_meta' === $group ) { unset( $GLOBALS['cache'][ $id ] ); } }
function get_post_meta( int $id, string $key, bool $single = true ): mixed {
	$values = $GLOBALS['cache'][ $id ] ?? $GLOBALS['metadata'][ $id ] ?? array();
	if ( ! $GLOBALS['suspended'] ) { $GLOBALS['cache'][ $id ] = $values; }
	return $single ? ( $values[ $key ][0] ?? '' ) : ( $values[ $key ] ?? array() );
}
function update_post_meta( int $id, string $key, string $value ): bool {
	++$GLOBALS['writes'];
	if ( $key === $GLOBALS['failure'] ) {
		if ( ! $GLOBALS['silent_failure'] ) { $GLOBALS['wpdb']->last_error = 'secret SQL and password must not escape'; }
		return false;
	}
	$value = wp_unslash( $value );
	if ( ( $GLOBALS['metadata'][ $id ][ $key ] ?? null ) === array( $value ) ) { return false; }
	$GLOBALS['metadata'][ $id ][ $key ] = array( $value );
	// Simulate another reader putting a pre-commit snapshot into a shared cache.
	$GLOBALS['cache'][ $id ] = $GLOBALS['metadata'][ $id ];
	return true;
}
function set_transient( string $key, mixed $value, int $seconds ): bool { $GLOBALS['transients'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['transients'][ $key ] ?? false; }
function delete_transient( string $key ): bool { unset( $GLOBALS['transients'][ $key ] ); return true; }
class WP_Post {
	public string $post_type = 'aish_message';
	public string $post_content = 'Original visitor message';
	public function __construct( public int $ID ) {}
}
class wpdb {
	public string $prefix = 'test_';
	public string $postmeta = 'test_postmeta';
	public string $last_error = '';
	public bool $suppressed = false;
	public bool $locked = false;
	public bool $busy = false;
	public array $commands = array();
	private array $snapshot = array();
	public function suppress_errors( bool $value ): bool { $previous = $this->suppressed; $this->suppressed = $value; return $previous; }
	public function prepare( string $sql, array $values ): string { return json_encode( array( $sql, $values ), JSON_THROW_ON_ERROR ); }
	public function get_var( string $prepared ): string {
		$this->last_error = ''; list( $sql ) = json_decode( $prepared, true, 8, JSON_THROW_ON_ERROR );
		if ( str_contains( $sql, 'GET_LOCK' ) ) { if ( $this->busy ) { return '0'; } $this->locked = true; return '1'; }
		if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $this->locked = false; return '1'; }
		throw new RuntimeException( 'Unexpected query.' );
	}
	public function query( string $sql ): int {
		$this->last_error = ''; $this->commands[] = $sql;
		if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $GLOBALS['metadata']; }
		elseif ( 'ROLLBACK' === $sql ) { $GLOBALS['metadata'] = $this->snapshot; }
		elseif ( 'COMMIT' !== $sql ) { throw new RuntimeException( 'Unexpected query.' ); }
		return 1;
	}
	public function get_results( string $prepared, string $format ): array {
		$this->last_error = ''; list( $sql, $values ) = json_decode( $prepared, true, 8, JSON_THROW_ON_ERROR );
		if ( ! str_starts_with( $sql, 'SELECT meta_value FROM test_postmeta WHERE post_id=%d AND meta_key=%s' ) ) { throw new RuntimeException( 'Unexpected query.' ); }
		return array_map( static fn( string $value ): array => array( 'meta_value' => $value ), $GLOBALS['metadata'][ $values[0] ][ $values[1] ] ?? array() );
	}
}
$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Database.php', 'Security/Input.php', 'Frontend/NativeMetadata.php', 'Frontend/Forms.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Frontend\Forms;
function fixture(): Forms {
	$GLOBALS['wpdb'] = new wpdb();
	$GLOBALS['caps'] = array( 'edit_post' => true, 'aish_manage_settings' => true );
	$GLOBALS['revision'] = $GLOBALS['autosave'] = $GLOBALS['suspended'] = $GLOBALS['silent_failure'] = false;
	$GLOBALS['failure'] = ''; $GLOBALS['writes'] = 0;
	$GLOBALS['metadata'] = array( 17 => array( '_aish_message_state' => array( 'pending' ), '_aish_message_notes' => array( 'Existing private notes' ) ) );
	$GLOBALS['posts'] = array( 17 => new WP_Post( 17 ) );
	$GLOBALS['transients'] = $GLOBALS['cache'] = $GLOBALS['cleaned'] = array();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array( '_aish_message_nonce' => 'valid', 'aish_message_state' => 'reviewed', 'aish_message_notes' => 'New private notes' );
	return new Forms( new Database( $GLOBALS['wpdb'] ) );
}
function assertReleased(): void {
	check( ! $GLOBALS['wpdb']->locked && ! $GLOBALS['wpdb']->suppressed && ! $GLOBALS['suspended'], 'Transaction left locks, error suppression or cache policy changed.' );
}
foreach ( array( 'method', 'cap', 'edit_cap', 'nonce', 'nonce_array', 'type', 'missing', 'revision', 'autosave', 'zero_id' ) as $case ) {
	$forms = fixture(); $before = $GLOBALS['metadata']; $id = 17;
	switch ( $case ) {
		case 'method': $_SERVER['REQUEST_METHOD'] = 'GET'; break;
		case 'cap': $GLOBALS['caps']['aish_manage_settings'] = false; break;
		case 'edit_cap': $GLOBALS['caps']['edit_post'] = false; break;
		case 'nonce': $_POST['_aish_message_nonce'] = 'wrong'; break;
		case 'nonce_array': $_POST['_aish_message_nonce'] = array( 'valid' ); break;
		case 'type': $GLOBALS['posts'][17]->post_type = 'page'; break;
		case 'missing': unset( $GLOBALS['posts'][17] ); break;
		case 'revision': $GLOBALS['revision'] = true; break;
		case 'autosave': $GLOBALS['autosave'] = true; break;
		case 'zero_id': $id = 0; break;
	}
	$forms->saveState( $id );
	check( $before === $GLOBALS['metadata'] && 0 === $GLOBALS['writes'] && ! $GLOBALS['wpdb']->commands, "Invalid $case request wrote metadata." );
	assertReleased();
}
$forms = fixture();
$forms->saveState( 17 );
check( 'reviewed' === get_post_meta( 17, '_aish_message_state', true ) && 'New private notes' === get_post_meta( 17, '_aish_message_notes', true ), 'Valid state and notes did not commit together.' );
check( array( 'START TRANSACTION', 'COMMIT' ) === $GLOBALS['wpdb']->commands && in_array( 17, $GLOBALS['cleaned'], true ), 'Commit or post cache cleanup missing.' );
check( 'Original visitor message' === $GLOBALS['posts'][17]->post_content && ! $GLOBALS['transients'], 'Metadata save changed visitor source or reported an error.' );
assertReleased();
$forms->saveState( 17 );
check( ! $GLOBALS['transients'] && 'COMMIT' === end( $GLOBALS['wpdb']->commands ), 'Unchanged WordPress false return incorrectly failed.' );
foreach ( array( '_aish_message_state', '_aish_message_notes' ) as $key ) {
	foreach ( array( false, true ) as $silent ) {
		$forms = fixture(); $before = $GLOBALS['metadata']; $GLOBALS['failure'] = $key; $GLOBALS['silent_failure'] = $silent;
		$forms->saveState( 17 );
		check( $before === $GLOBALS['metadata'] && 'ROLLBACK' === end( $GLOBALS['wpdb']->commands ), 'A failed native metadata write was only partially rolled back.' );
		check( 'pending' === get_post_meta( 17, '_aish_message_state', true ) && 'Existing private notes' === get_post_meta( 17, '_aish_message_notes', true ), 'Uncommitted values escaped through object cache.' );
		check( array( 'aish_message_error_23' => '1' ) === $GLOBALS['transients'], 'Failure was hidden or raw diagnostics were persisted.' );
		assertReleased();
	}
}
foreach ( array( array( 'aish_message_state', 'published' ), array( 'aish_message_state', array() ), array( 'aish_message_notes', array() ), array( 'aish_message_notes', str_repeat( '中', 3001 ) ), array( 'aish_message_notes', "invalid\0notes" ), array( 'aish_message_notes', "bad\xff" ) ) as list( $key, $invalid ) ) {
	$forms = fixture(); $before = $GLOBALS['metadata']; $_POST[ $key ] = $invalid; $forms->saveState( 17 );
	check( $before === $GLOBALS['metadata'] && ! $GLOBALS['writes'] && '1' === get_transient( 'aish_message_error_23' ), 'Invalid fields wrote metadata or failed silently.' );
}
$forms = fixture(); $notes = "Path C:\\work\\skill, 'quoted'\n<b>reviewed</b>"; $_POST['aish_message_notes'] = wp_slash( $notes ); $forms->saveState( 17 );
check( sanitize_textarea_field( $notes ) === get_post_meta( 17, '_aish_message_notes', true ), 'Notes lost literal slashes/quotes or retained markup.' );
$forms = fixture(); $GLOBALS['wpdb']->busy = true; $before = $GLOBALS['metadata']; $forms->saveState( 17 );
check( $before === $GLOBALS['metadata'] && ! $GLOBALS['writes'] && '1' === get_transient( 'aish_message_error_23' ), 'Busy metadata lock permitted a write or hid the failure.' ); assertReleased();
$forms = fixture(); $GLOBALS['suspended'] = true; $forms->saveState( 17 );
check( $GLOBALS['suspended'] && ! $GLOBALS['wpdb']->locked && ! $GLOBALS['wpdb']->suppressed, 'Caller cache-addition suspension was not preserved.' );
$forms = fixture(); $GLOBALS['caps']['aish_manage_settings'] = false; $GLOBALS['caps']['manage_options'] = true; $forms->saveState( 17 );
check( 'reviewed' === get_post_meta( 17, '_aish_message_state', true ), 'Authorized administrator fallback stopped working.' );
$forms = fixture(); ( new Forms() )->saveState( 17 );
check( 0 === $GLOBALS['writes'] && '1' === get_transient( 'aish_message_error_23' ), 'Missing database dependency caused partial writes.' );
$forms->register();
ob_start(); foreach ( $GLOBALS['hooks']['admin_notices'] as $callback ) { $callback(); } $notice = ob_get_clean();
check( str_contains( $notice, 'notice-error' ) && ! str_contains( $notice, 'secret' ) && ! str_contains( $notice, 'SQL' ) && ! get_transient( 'aish_message_error_23' ), 'Notice disclosed diagnostics or was not consumed.' );
set_transient( 'aish_message_error_23', '<script>untrusted()</script>', 120 );
ob_start(); foreach ( $GLOBALS['hooks']['admin_notices'] as $callback ) { $callback(); } $notice = ob_get_clean();
check( '' === $notice, 'Unexpected transient content rendered as a notice.' );
set_transient( 'aish_message_error_23', '1', 120 ); $GLOBALS['caps'] = array();
ob_start(); foreach ( $GLOBALS['hooks']['admin_notices'] as $callback ) { $callback(); } $notice = ob_get_clean();
check( '' === $notice && '1' === get_transient( 'aish_message_error_23' ), 'Unauthorized visitor consumed or displayed the management notice.' );
printf( "Inbox management: %d isolated assertions passed.\n", $GLOBALS['checks'] );
