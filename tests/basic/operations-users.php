<?php
declare(strict_types=1);

/** Isolated state transitions with modeled WordPress/database; not MySQL integration. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DB_NAME', 'operations_fixture' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DAY_IN_SECONDS', 86400 );
final class WP_User {
	public array $roles = array( 'subscriber' );
	public function __construct( public int $ID, public string $user_email, public array $allcaps = array( 'read' => true ) ) {}
}
final class wpdb {
	public string $users = 'wp_users'; public string $usermeta = 'wp_usermeta'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta'; public string $prefix = 'wp_'; public string $last_error = ''; public int $insert_id = 0;
	public array $meta = array(); public array $events = array(); public array $sql = array(); public array $locks = array(); public bool $failAudit = false; public string $engine = 'InnoDB'; private array $snapshot = array(); private bool $suppressed = false;
	public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function prepare( string $sql, mixed ...$values ): string { return json_encode( array( $sql, is_array( $values[0] ?? null ) ? $values[0] : $values ), JSON_THROW_ON_ERROR ); }
	private function unpack( string $prepared ): array { $v = str_starts_with( $prepared, '[' ) ? json_decode( $prepared, true, 32, JSON_THROW_ON_ERROR ) : array( $prepared, array() ); $this->sql[] = $v[0]; return $v; }
	public function get_var( string $prepared ): mixed {
		[ $sql, $values ] = $this->unpack( $prepared );
		if ( str_contains( $sql, 'GET_LOCK' ) ) { $this->locks[ $values[0] ] = true; return 1; }
		if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { unset( $this->locks[ $values[0] ] ); return 1; }
		throw new RuntimeException( 'Unexpected variable query.' );
	}
	public function get_results( string $prepared, string $format ): array {
		[ $sql, $v ] = $this->unpack( $prepared );
		if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { return array( array( 'Engine' => $this->engine ) ); }
		if ( str_starts_with( $sql, 'SELECT ID FROM wp_users' ) ) { return isset( $GLOBALS['users'][ $v[0] ] ) ? array( array( 'ID' => $v[0] ) ) : array(); }
		if ( str_starts_with( $sql, 'SELECT user_id,meta_key,meta_value' ) ) {
			$rows = array(); foreach ( $v as $id ) { if ( ! is_int( $id ) ) { continue; } foreach ( $this->meta[ $id ] ?? array() as $key => $values ) { foreach ( $values as $value ) { $rows[] = array( 'user_id' => $id, 'meta_key' => $key, 'meta_value' => $value ); } } } return $rows;
		}
		if ( str_starts_with( $sql, 'SELECT meta_value FROM wp_usermeta' ) ) { return array_map( fn( $v ) => array( 'meta_value' => $v ), $this->meta[ $v[0] ][ $v[1] ] ?? array() ); }
		if ( str_starts_with( $sql, 'SELECT metadata_json FROM' ) ) { foreach ( $this->events as $event ) { if ( $v[0] === $event['event_key'] ) { return array( array( 'metadata_json' => $event['metadata_json'] ) ); } } return array(); }
		throw new RuntimeException( 'Unexpected rows query: ' . $sql );
	}
	public function query( string $prepared ): int {
		[ $sql, $v ] = $this->unpack( $prepared );
		if ( 'START TRANSACTION' === $sql ) { $this->snapshot = array( $this->meta, $this->events ); return 0; }
		if ( 'COMMIT' === $sql ) { $this->snapshot = array(); return 0; }
		if ( 'ROLLBACK' === $sql ) { [ $this->meta, $this->events ] = $this->snapshot; $this->snapshot = array(); return 0; }
		if ( str_starts_with( $sql, 'DELETE FROM wp_usermeta' ) ) { unset( $this->meta[ $v[0] ]['_aish_account_state'], $this->meta[ $v[0] ]['_aish_operations_account'] ); return 2; }
		if ( str_starts_with( $sql, 'INSERT INTO wp_usermeta' ) ) { $key = str_contains( $sql, "'_aish_operations_account'" ) ? '_aish_operations_account' : '_aish_account_state'; $this->meta[ $v[0] ][ $key ] = array( $v[1] ); return 1; }
		if ( str_starts_with( $sql, 'UPDATE `wp_aish_email_verifications`' ) ) { return 1; }
		throw new RuntimeException( 'Unexpected mutation.' );
	}
	public function insert( string $table, array $data, array $formats ): int {
		if ( $this->failAudit ) { throw new RuntimeException( 'Fixture audit failure.' ); }
		if ( 'wp_aish_workflow_events' !== $table ) { throw new RuntimeException( 'Unexpected entity write.' ); }
		$this->events[] = $data; ++$this->insert_id; return 1;
	}
}
function get_current_user_id(): int { return $GLOBALS['actor']; }
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['users'][ $GLOBALS['actor'] ]->allcaps[ $cap ] ); }
function user_can( WP_User $user, string $cap ): bool { return ! empty( $user->allcaps[ $cap ] ); }
function get_userdata( int $id ): WP_User|false { return $GLOBALS['users'][ $id ] ?? false; }
function clean_user_cache( int $id ): void { $GLOBALS['purges'][] = $id; }
function wp_cache_delete( mixed $id, string $group ): bool { $GLOBALS['purges'][] = $id; return true; }
function wp_suspend_cache_addition( ?bool $value = null ): bool { $old = $GLOBALS['cache_suspended'] ?? false; if ( null !== $value ) { $GLOBALS['cache_suspended'] = $value; } return $old; }
function is_multisite(): bool { return false; }
function is_super_admin( int $id ): bool { return false; }
function get_option( string $name, mixed $default = false ): mixed { return 'aish_schema_version' === $name ? '1.9.0' : $default; }
function wp_json_encode( mixed $value ): string { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); }
function wp_salt( string $scheme ): string { return 'test-only-operations-' . $scheme; }
function maybe_unserialize( mixed $value ): mixed { return $value; }
function is_email( string $value ): bool { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function email_exists( string $email ): int|false { foreach ( $GLOBALS['users'] as $user ) { if ( strtolower( $user->user_email ) === strtolower( $email ) ) { return $user->ID; } } return false; }
function wp_slash( string $value ): string { return addslashes( $value ); }
function update_user_meta( int $id, string $key, mixed $value ): bool { $GLOBALS['connection']->meta[ $id ][ $key ] = array( is_string( $value ) ? stripslashes( $value ) : $value ); return true; }
function delete_user_meta( int $id, string $key ): bool { unset( $GLOBALS['connection']->meta[ $id ][ $key ] ); return true; }
final class WP_Session_Tokens {
	public function __construct( private int $id ) {}
	public static function get_instance( int $id ): self { return new self( $id ); }
	public function destroy_all(): void { delete_user_meta( $this->id, 'session_tokens' ); }
}

$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Database.php', 'Database/Schema.php', 'Database/Migrator.php', 'Accounts/Locks.php', 'Accounts/AccountException.php', 'Accounts/Store.php', 'Accounts/Crypto.php', 'Accounts/Mail.php', 'Accounts/RateLimiter.php', 'Accounts/AccountService.php', 'Operations/Users.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Database\{Database,Migrator};
use AISkillsHub\Core\Accounts\{AccountService,Store,Mail,Locks};
use AISkillsHub\Core\Operations\Users;
$GLOBALS['users'] = array( 1 => new WP_User( 1, 'operator@example.test', array( 'read' => true, Users::CAPABILITY => true ) ), 2 => new WP_User( 2, 'user@example.test' ), 3 => new WP_User( 3, 'pending@example.test' ), 4 => new WP_User( 4, 'staff@example.test', array( 'manage_options' => true ) ), 5 => new WP_User( 5, 'legacy@example.test' ) );
$GLOBALS['actor'] = 1; $GLOBALS['purges'] = array();
$connection = new wpdb(); $db = new Database( $connection ); $store = new Store( $db ); $locks = new Locks( $db ); $accounts = new AccountService( $db, $store, new Mail( $store ), $locks ); $service = new Users( $db, new Migrator( $db ), $accounts, $locks );
$verified = array( '_aish_account_state' => array( 'active' ), '_aish_verified_email_hash' => array( Store::emailHash( 'user@example.test' ) ), '_aish_email_verified_at' => array( '2026-11-07 00:00:00' ) );
$connection->meta[2] = $verified; $connection->meta[3] = array( '_aish_account_state' => array( 'pending_email' ) );
$checks = 0;
function check( bool $value, string $message ): void { global $checks; if ( ! $value ) { throw new RuntimeException( $message ); } ++$checks; }
function denied( callable $f, string $code ): void { try { $f(); } catch ( Throwable $e ) { check( $e->getMessage() === $code, 'Expected ' . $code . ', got ' . $e->getMessage() ); return; } throw new RuntimeException( 'Expected rejection ' . $code ); }
function operation( int $id, string $action, int $revision, string $key ): void { $GLOBALS['users_service']->setState( $id, $action, $revision, 'Reviewed community abuse report', 'Community restrictions reviewed', $key ); }
$GLOBALS['users_service'] = $service;
$key = str_repeat( 'a', 32 ); operation( 2, 'suspend', 0, $key );
check( 'suspended' === $accounts->state( 2 ) && ! $accounts->verified( 2 ), 'Suspension uses authoritative existing account state and verification eligibility.' );
check( $connection->meta[2]['_aish_email_verified_at'] === $verified['_aish_email_verified_at'] && $connection->meta[2]['_aish_verified_email_hash'] === $verified['_aish_verified_email_hash'], 'Suspension preserves exact email evidence.' );
check( 1 === count( $connection->events ) && 'user_suspended' === $connection->events[0]['action'] && 1 === $connection->events[0]['actor_user_id'], 'Transition persists operator, action and original states.' );
check( array( 'revision' => 1, 'paused' => true, 'prior_state' => 'active' ) === json_decode( $connection->meta[2][Users::META][0], true ), 'Control record captures prior qualification and optimistic revision.' );
operation( 2, 'suspend', 0, $key ); check( 1 === count( $connection->events ), 'Repeated same request is an audited no-op.' );
denied( fn() => $service->setState( 2, 'suspend', 0, 'Changed payload', 'Review', $key ), 'users_conflict' );
denied( fn() => operation( 2, 'resume', 0, str_repeat( 'b', 32 ) ), 'users_conflict' );
operation( 2, 'resume', 1, str_repeat( 'c', 32 ) ); check( $accounts->verified( 2 ) && 'active' === $accounts->state( 2 ), 'Restore returns original active state with original verification evidence.' );
check( ! array_filter( $connection->sql, fn( $sql ) => preg_match( '/(?:UPDATE|DELETE|INSERT).*aish_(reviews|skills|favorites|review_revisions)/i', $sql ) ), 'Eligibility operations never change user content, ratings, favorites or approvals.' );
operation( 3, 'suspend', 0, str_repeat( 'd', 32 ) ); operation( 3, 'resume', 1, str_repeat( 'e', 32 ) ); check( 'pending_email' === $accounts->state( 3 ) && ! $accounts->verified( 3 ), 'Unverified users remain pending after restore.' );
operation( 5, 'suspend', 0, str_repeat( 'f', 32 ) ); operation( 5, 'resume', 1, str_repeat( '0', 32 ) ); check( 'legacy' === $accounts->state( 5 ) && ! isset( $connection->meta[5]['_aish_account_state'] ), 'Legacy state restored without false active/verified claims.' );
denied( fn() => operation( 4, 'suspend', 0, str_repeat( '1', 32 ) ), 'users_protected' );
denied( fn() => operation( 1, 'suspend', 0, str_repeat( '2', 32 ) ), 'users_protected' );
$connection->meta[3]['_aish_account_deletion_pending'] = array( '1' ); denied( fn() => operation( 3, 'suspend', 2, str_repeat( '3', 32 ) ), 'users_erasing' ); unset( $connection->meta[3]['_aish_account_deletion_pending'] );
$connection->meta[3]['_aish_account_state'] = array( 'suspended' ); denied( fn() => operation( 3, 'resume', 2, str_repeat( '4', 32 ) ), 'users_conflict' );
$connection->meta[3]['_aish_account_state'] = array( 'pending_email', 'pending_email' ); denied( fn() => operation( 3, 'suspend', 2, str_repeat( '5', 32 ) ), 'users_ambiguous_meta' );
$connection->meta[3]['_aish_account_state'] = array( 'pending_email' );
$before = $connection->meta; $events = $connection->events; $connection->failAudit = true;
denied( fn() => operation( 3, 'suspend', 2, str_repeat( '6', 32 ) ), 'Fixture audit failure.' ); $connection->failAudit = false;
check( $before === $connection->meta && $events === $connection->events, 'Audit failure rolls back both control and account state.' );
check( ! $connection->locks && ! $GLOBALS['cache_suspended'] && in_array( 3, $GLOBALS['purges'], true ), 'All locks and cache scope release after rollback.' );
$connection->engine = 'MyISAM'; denied( fn() => operation( 3, 'suspend', 2, str_repeat( '7', 32 ) ), 'users_unavailable' ); $connection->engine = 'InnoDB';
foreach ( array( 'token=private-value', 'Bearer abcdefghijklmnop', '<script>alert(1)</script>', "control\x01character" ) as $text ) { denied( fn() => $service->setState( 3, 'suspend', 2, $text, 'Review required', str_repeat( '8', 32 ) ), str_contains( $text, '<' ) || str_contains( $text, "\x01" ) ? 'users_input' : 'users_sensitive_note' ); }
$GLOBALS['actor'] = 2; denied( fn() => $service->detail( 3 ), 'users_forbidden' ); denied( fn() => operation( 3, 'suspend', 2, str_repeat( '9', 32 ) ), 'users_forbidden' ); denied( fn() => $service->summaries( array( 3 ) ), 'users_forbidden' );
$GLOBALS['actor'] = 1;
foreach ( array( 'aish_manage_accounts', 'aish_manage_notifications', 'aish_view_operations', 'aish_view_analytics' ) as $cap ) { $legacy = new WP_User( 6, 'legacy-operator@example.test', array( $cap => true ) ); check( ! $accounts->restricted( $legacy ), 'New legacy operations capability retains native access without fabrication of verification.' ); }
operation( 2, 'suspend', 2, str_repeat( 'a1', 16 ) );
$data = $accounts->guardNativeWrite( array( 'user_email' => 'corrected@example.test' ), true, 2, array() ); $accounts->releaseNativeLocks();
$GLOBALS['users'][2]->user_email = $data['user_email'];
check( 'suspended' === $accounts->state( 2 ) && $accounts->restricted( $GLOBALS['users'][2] ), 'Native administrator email correction cannot lift a community suspension.' );
check( ! isset( $connection->meta[2]['_aish_verified_email_hash'], $connection->meta[2]['_aish_email_verified_at'] ), 'Native email correction revokes old verification even while paused.' );
operation( 2, 'resume', 3, str_repeat( 'a2', 16 ) );
check( 'pending_email' === $accounts->state( 2 ) && ! $accounts->verified( 2 ) && $accounts->restricted( $GLOBALS['users'][2] ), 'After email change manual recovery returns pending-email and cannot restore community privileges without new verification.' );
check( 'pending_email' === end( $connection->events )['to_state'], 'Recovery audit records the actual reduced eligibility state.' );
$connection->meta[1]['_aish_account_state'] = array( 'suspended' ); denied( fn() => $service->detail( 2 ), 'users_forbidden' ); unset( $connection->meta[1] );
$connection->meta[1]['_aish_contributions_erasing'] = array( '1' ); denied( fn() => $service->summaries( array( 2 ) ), 'users_forbidden' ); unset( $connection->meta[1] );
check( ! $connection->locks, 'All authorized, conflicting and rejected transitions release advisory locks.' );
echo json_encode( array( 'suite' => 'operations-users', 'kind' => 'isolated PHP/model WordPress and database', 'checks' => $checks, 'status' => 'passed' ), JSON_PRETTY_PRINT ) . PHP_EOL;
