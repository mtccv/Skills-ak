<?php
declare(strict_types=1);

/** Isolated HTTP-boundary tests; no WordPress, MySQL or mail transport is simulated as real. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['account_test_user'] = 0;
$GLOBALS['account_test_filter'] = null;
function get_current_user_id(): int { return $GLOBALS['account_test_user']; }
function get_option( string $name, mixed $default = false ): mixed { return 'home' === $name ? 'https://example.test/hub' : $default; }
function home_url( string $path = '/' ): string { return 'https://example.test/hub' . $path; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function is_ssl(): bool { return true; }
function is_admin(): bool { return false; }
function wp_salt( string $scheme = 'auth' ): string { return 'isolated-test-non-secret-' . $scheme; }
function wp_unslash( string $value ): string { return stripslashes( $value ); }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( string $action, string $name, bool $referer, bool $echo ): string { return '<input name="' . $name . '" value="test-nonce">'; }
function wp_verify_nonce( string $nonce, string $action ): bool { return 'test-nonce' === $nonce; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return is_callable( $GLOBALS['account_test_filter'] ) ? ( $GLOBALS['account_test_filter'] )( $value ) : $value; }
class WP { public array $query_vars = array(); }
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Frontend/Router.php';
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Accounts/Frontend.php';
use AISkillsHub\Core\Accounts\Frontend;

$reflection = new ReflectionClass( Frontend::class );
$controller = $reflection->newInstanceWithoutConstructor();
$invoke = static fn( string $method, mixed ...$args ): mixed => $reflection->getMethod( $method )->invoke( $controller, ...$args );
$checks = 0;
$assert = static function ( bool $condition, string $label ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( 'Failed: ' . $label ); } };
$reject = static function ( callable $operation ) use ( $assert ): void {
	try { $operation(); } catch ( Throwable $error ) { $assert( true, 'rejected untrusted request' ); return; }
	$assert( false, 'request should be rejected' );
};
$_SERVER = array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hub/en/account/profile/', 'HTTP_ORIGIN' => 'https://example.test' );
$_COOKIE = $_POST = $_GET = array();
$controller->parse( new WP() );
$assert( Frontend::active() && 'profile' === Frontend::context()['route'] && 'en_US' === Frontend::context()['locale'], 'strict bilingual account path under subdirectory' );
$reflection->getProperty( 'active' )->setValue( null, false );
$_SERVER['REQUEST_URI'] = '/hub/en/account/favorites/';
$controller->parse( new WP() );
$assert( ! Frontend::active(), 'future community paths are not opened' );
$_SERVER['REQUEST_URI'] = '/hub/en/account/../profile/';
$controller->parse( new WP() );
$assert( ! Frontend::active(), 'path traversal is not normalized into an account route' );

$invoke( 'browserContext' );
$assert( isset( $_COOKIE['__Host-aish_account_browser'] ) && strlen( $_COOKIE['__Host-aish_account_browser'] ) === 64, 'secure browser-bound CSRF context generated' );
$fields = Frontend::fields( 'login' );
preg_match( '/name="account_csrf" value="([a-f0-9]+)"/', $fields, $match );
$_POST = array( 'account_csrf' => $match[1], '_aish_nonce' => 'test-nonce' );
$invoke( 'checkCsrf', 'login' );
$assert( true, 'same browser and action nonce accepted' );
$_SERVER['HTTP_ORIGIN'] = 'null';
$invoke( 'checkCsrf', 'login' );
$assert( true, 'opaque origin from no-referrer page still requires valid browser HMAC and WP nonce' );
$_POST['account_csrf'] = str_repeat( '0', 64 );
$reject( static fn() => $invoke( 'checkCsrf', 'login' ) );
$_POST['account_csrf'] = $match[1];
$_SERVER['HTTP_ORIGIN'] = 'https://example.test';
$reject( static fn() => $invoke( 'checkCsrf', 'register' ) );
$GLOBALS['account_test_user'] = 42;
$reject( static fn() => $invoke( 'checkCsrf', 'login' ) );
$GLOBALS['account_test_user'] = 0;
$_SERVER['HTTP_ORIGIN'] = 'https://attacker.test';
$reject( static fn() => $invoke( 'checkCsrf', 'login' ) );
$_SERVER['HTTP_ORIGIN'] = 'https://example.test';
$savedBrowser = $_COOKIE['__Host-aish_account_browser'];
$invoke( 'rotateBrowser' );
$reject( static fn() => $invoke( 'checkCsrf', 'login' ) );
$_COOKIE = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
$reject( static fn() => $invoke( 'browserContext' ) );
$_SERVER['REQUEST_METHOD'] = 'GET'; $invoke( 'browserContext' );

$token = str_repeat( 'ab', 32 );
$invoke( 'storeCredential', 'verify', $token );
$assert( $token === $invoke( 'readCredential', 'verify' ), 'signed credential context is usable without URL parameters' );
$cookie = $_COOKIE['__Host-aish_account_verify'];
$_COOKIE['__Host-aish_account_verify'] = $cookie . '0';
$assert( '' === $invoke( 'readCredential', 'verify' ), 'tampered credential signature rejected' );
$_COOKIE['__Host-aish_account_verify'] = $cookie;
$assert( '' === $invoke( 'readCredential', 'reset' ), 'verification cannot become password reset authorization' );
$invoke( 'rotateBrowser' );
$assert( '' === $invoke( 'readCredential', 'verify' ), 'credential cookie from another browser context rejected' );
$invoke( 'storeCredential', 'reset', '{"login":"test","key":"test-key"}' );
$assert( array( 'login' => 'test', 'key' => 'test-key' ) === $invoke( 'resetContext' ), 'reset payload parsed only after signature and browser checks' );
$invoke( 'clearCredential', 'reset' );
$assert( null === $invoke( 'resetContext' ), 'cleared sensitive context cannot be reused' );

$assert( 'https://example.test/hub/en/account/security/' === Frontend::url( 'security', 'en_US' ), 'private URL contains language and route only' );
$GLOBALS['account_test_filter'] = static fn() => 'https://attacker.test/?key=secret';
$assert( 'https://example.test/hub/en/account/security/' === Frontend::url( 'security', 'en_US' ), 'external account URL adapter rejected' );
$GLOBALS['account_test_filter'] = static fn() => 'https://example.test/hub/en/account/?token=secret';
$assert( 'https://example.test/hub/en/account/' === Frontend::url( 'account', 'en_US' ), 'sensitive adapter query rejected' );
$GLOBALS['account_test_filter'] = static fn() => array( 'url' => 'https://attacker.test/' );
$assert( 'https://example.test/hub/en/login/' === Frontend::url( 'login', 'en_US' ), 'malformed adapter return falls back to the validated local account route' );
$GLOBALS['account_test_filter'] = static function (): never { throw new RuntimeException( 'adapter unavailable' ); };
$assert( 'https://example.test/hub/en/login/' === Frontend::url( 'login', 'en_US' ), 'adapter exceptions do not expose private details or break recovery links' );
$reject( static fn() => Frontend::url( '../wp-admin', 'en_US' ) );
$reject( static fn() => Frontend::url( 'login', 'fr_FR' ) );
$assert( 'a•••@example.test' === Frontend::maskEmail( 'alice@example.test' ), 'verification screens mask email local part' );

echo "Account frontend isolated: $checks assertions passed; no real WP/HTTP/database/mail claim.\n";
