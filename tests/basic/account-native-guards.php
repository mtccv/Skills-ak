<?php
declare(strict_types=1);

/** Isolated native-authentication hook contract; no WordPress/MySQL/mail service. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DB_NAME', 'account_guard_fixture' ); define( 'ARRAY_A', 'ARRAY_A' );
final class WP_Error { public function __construct( public string $code, public string $message = '', public mixed $data = null ) {} }
final class WP_User {
	public array $roles = array();
	public function __construct( public int $ID, public string $user_email, public array $allcaps = array() ) {}
	public function exists(): bool { return $this->ID > 0; }
}
final class wpdb {
	public string $users = 'wp_users'; public string $usermeta = 'wp_usermeta'; public string $options = 'wp_options'; public string $prefix = 'wp_'; public string $last_error = '';
	public array $meta = array(); public array $optionsData = array(); public array $sql = array(); private bool $suppressed = false;
	public bool $releaseThrows = false;
	public function suppress_errors( bool $suppress ): bool { $old = $this->suppressed; $this->suppressed = $suppress; return $old; }
	public function prepare( string $sql, mixed ...$args ): string { return json_encode( array( $sql, is_array( $args[0] ?? null ) ? $args[0] : $args ), JSON_THROW_ON_ERROR ); }
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
	public function get_var( string $prepared ): mixed {
		[ $sql, $args ] = json_decode( $prepared, true, 32, JSON_THROW_ON_ERROR ); $this->sql[] = $sql;
		if ( str_contains( $sql, 'RELEASE_LOCK' ) && $this->releaseThrows ) { throw new RuntimeException( 'private connection diagnostic' ); }
		if ( str_contains( $sql, 'GET_LOCK' ) || str_contains( $sql, 'RELEASE_LOCK' ) ) { return '1'; }
		if ( str_contains( $sql, 'SELECT option_value' ) ) { return $this->optionsData[ $args[0] ] ?? null; }
		throw new RuntimeException( 'Unexpected isolated variable query.' );
	}
	public function get_results( string $prepared, string $format ): array {
		[ $sql, $args ] = json_decode( $prepared, true, 32, JSON_THROW_ON_ERROR ); $this->sql[] = $sql;
		if ( str_contains( $sql, 'SELECT meta_value FROM wp_usermeta' ) ) { return isset( $this->meta[ $args[0] ][ $args[1] ] ) ? array_map( static fn( $v ) => array( 'meta_value' => $v ), $this->meta[ $args[0] ][ $args[1] ] ) : array(); }
		throw new RuntimeException( 'Unexpected isolated rows query.' );
	}
	public function query( string $prepared ): int {
		[ $sql, $args ] = json_decode( $prepared, true, 32, JSON_THROW_ON_ERROR ); $this->sql[] = $sql;
		if ( str_starts_with( $sql, 'DELETE FROM wp_options ' ) ) { return 0; }
		if ( str_starts_with( $sql, 'INSERT INTO wp_options ' ) ) { $this->optionsData[ $args[0] ] = $args[1]; return 1; }
		throw new RuntimeException( 'Unexpected isolated mutation.' );
	}
}
function __( string $message, string $domain ): string { return $message; }
function wp_salt( string $scheme ): string { return 'test-only-account-guard-' . $scheme; }
function is_email( string $email ): bool { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function wp_cache_delete( mixed $id, string $group ): bool { return true; }
function maybe_unserialize( mixed $value ): mixed { return $value; }
function get_userdata( int $id ): WP_User|false { return $GLOBALS['users'][ $id ] ?? false; }
function wp_get_current_user(): WP_User { return $GLOBALS['users'][ $GLOBALS['current'] ]; }
function get_current_user_id(): int { return $GLOBALS['current']; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function get_option( string $key, mixed $default = false ): mixed { return $default; }

$path = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Database.php', 'Database/Schema.php', 'Accounts/AccountException.php', 'Accounts/Locks.php', 'Accounts/Crypto.php', 'Accounts/Store.php', 'Accounts/Mail.php', 'Accounts/RateLimiter.php', 'Accounts/AccountService.php' ) as $file ) { require $path . $file; }
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Accounts\{AccountService, AccountException, Store, Mail};
$connection = new wpdb(); $database = new Database( $connection ); $store = new Store( $database ); $accounts = new AccountService( $database, $store, new Mail( $store ) );
$GLOBALS['users'] = array( 1 => new WP_User( 1, 'admin@example.test', array( 'manage_options' => true ) ), 2 => new WP_User( 2, 'pending@example.test', array( 'read' => true ) ), 3 => new WP_User( 3, 'active@example.test', array( 'read' => true ) ), 4 => new WP_User( 4, 'reviewer@example.test', array( 'aish_review_skills' => true ) ), 0 => new WP_User( 0, '' ) );
$GLOBALS['current'] = 0;
$connection->meta[2]['_aish_account_state'] = array( 'pending_email' );
$connection->meta[3] = array( '_aish_account_state' => array( 'active' ), '_aish_verified_email_hash' => array( Store::emailHash( 'active@example.test' ) ), '_aish_email_verified_at' => array( '2026-11-06 00:00:00' ) );
$_SERVER['REMOTE_ADDR'] = '192.0.2.88'; $checks = 0;
function check( bool $value, string $message ): void { global $checks; if ( ! $value ) { throw new RuntimeException( $message ); } ++$checks; }

check( ! $accounts->restricted( $GLOBALS['users'][1] ), 'Existing administrators retain management without fabricated verification.' );
check( ! $accounts->ready(), 'Missing schema/mail configuration does not advertise account registration readiness.' );
check( ! $accounts->verified( 1 ), 'Legacy administrator is not falsely email-verified.' );
check( ! $accounts->restricted( $GLOBALS['users'][4] ), 'Legacy specialized review capability retains original access.' );
foreach ( array( 'aish_moderate_community', 'aish_manage_community_reports' ) as $cap ) {
	$moderator = new WP_User( 5, 'community-moderator@example.test', array( $cap => true ) );
	check( ! $accounts->restricted( $moderator ), 'Legacy community-only moderators retain management access without false verification.' );
	$connection->meta[5]['_aish_account_state'] = array( 'pending_email' );
	check( $accounts->restricted( $moderator ), 'A community capability does not bypass an explicit pending-email state.' );
	unset( $connection->meta[5] );
}
check( $accounts->restricted( $GLOBALS['users'][2] ), 'Pending Subscriber is restricted.' );
check( array( 'do_not_allow' ) === $accounts->guardCapabilities( array( 'edit_user' ), 'edit_user', 2, array( 2 ) ), 'Pending user cannot edit native profile as bypass.' );
check( array( 'read' ) === $accounts->guardCapabilities( array( 'read' ), 'read', 2, array() ), 'Pending session retains restricted read identity.' );
check( $accounts->verified( 3 ) && ! $accounts->restricted( $GLOBALS['users'][3] ), 'Current bound verification grants self-service qualification.' );
$GLOBALS['current'] = 2; check( is_wp_error( $accounts->guardRest( null ) ), 'Pending session cannot bypass via native REST.' );
$GLOBALS['current'] = 3; check( null === $accounts->guardRest( null ), 'Verified native REST uses ordinary endpoint capabilities.' );
$GLOBALS['users'][3]->user_email = 'changed@example.test'; check( ! $accounts->verified( 3 ), 'Changed email cannot reuse old binding.' );
$GLOBALS['users'][3]->user_email = 'active@example.test';
$connection->meta[3]['_aish_account_deletion_pending'] = array( '1' );
check( 'suspended' === $accounts->state( 3 ) && is_wp_error( $accounts->guardRest( null ) ), 'Pending native deletion suppresses account access immediately.' );
unset( $connection->meta[3]['_aish_account_deletion_pending'] );
$connection->meta[3]['_aish_account_state'][] = 'pending_email';
check( is_wp_error( $accounts->guardRest( null ) ), 'Conflicting authoritative meta values fail closed.' );
$connection->meta[3]['_aish_account_state'] = array( 'active' );

for ( $i = 0; $i < 10; ++$i ) {
	$early = $accounts->limitAuthentication( null, 'admin@example.test', 'correct password' );
	check( null === $early, 'Allowed native authentication reaches WordPress.' );
	check( $accounts->guardAuthentication( $GLOBALS['users'][1], 'admin@example.test', 'correct password' ) instanceof WP_User, 'Valid native password remains accepted within quota.' );
}
$early = $accounts->limitAuthentication( null, 'admin@example.test', 'correct password' );
check( is_wp_error( $early ), 'Rate limiter rejects repeated native credential attempts.' );
// WordPress priority-20 authenticators can replace an earlier WP_Error with WP_User.
check( is_wp_error( $accounts->guardAuthentication( $GLOBALS['users'][1], 'admin@example.test', 'correct password' ) ), 'Final hook enforces rate decision even after a valid password replaces the early error.' );
$unknown = $accounts->guardAuthentication( new WP_Error( 'invalid_email', 'private original error' ), 'unknown@example.test', 'wrong' );
check( 'aish_auth_failed' === $unknown->code && ! str_contains( $unknown->message, 'private' ), 'Native error reasons do not enumerate accounts.' );
foreach ( array_keys( $connection->optionsData ) as $key ) { check( ! str_contains( $key, 'admin@example' ) && ! str_contains( $key, '192.0.2.' ), 'Counter keys contain no raw email/IP.' ); }
check( ! str_contains( json_encode( $connection->optionsData ), 'correct password' ), 'Rate records contain no passwords.' );
$connection->releaseThrows = true;
check( is_wp_error( $accounts->limitAuthentication( null, 'another@example.test', 'secret input' ) ), 'Limiter release failures become a generic native authentication error, not an uncaught trace.' );
check( is_wp_error( $accounts->guardAuthentication( $GLOBALS['users'][1], 'another@example.test', 'secret input' ) ), 'Database limiter failure remains enforced by the final authenticator.' );
echo json_encode( array( 'suite' => 'account-native-guards', 'kind' => 'isolated PHP/WP API substitutes', 'checks' => $checks, 'status' => 'passed' ), JSON_PRETTY_PRINT ) . PHP_EOL;
