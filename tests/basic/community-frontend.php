<?php
declare(strict_types=1);

/** Isolated request-boundary/rendering tests. They do not simulate a real WP session or database. */
define( 'ABSPATH', __DIR__ . '/' );
function get_option( string $name, mixed $default = false ): mixed { return 'home' === $name ? 'https://example.test/hub' : $default; }
function home_url( string $path = '/' ): string { return 'https://example.test/hub' . $path; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function is_admin(): bool { return false; }
function wp_unslash( string $value ): string { return stripslashes( $value ); }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( string $value ): string { return esc_attr( $value ); }
function esc_url( string $value ): string { return esc_attr( $value ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }
function wp_nonce_field( string $action, string $name, bool $referer, bool $echo ): string { return '<input name="' . $name . '" value="' . hash( 'sha256', $action ) . '">'; }
function wp_verify_nonce( string $nonce, string $action ): bool { return hash_equals( hash( 'sha256', $action ), $nonce ); }
function add_query_arg( mixed ...$args ): string {
	if ( is_array( $args[0] ) ) { [ $query, $url ] = $args; } else { $query = array( $args[0] => $args[1] ); $url = $args[2]; }
	// WP accepts pre-encoded new values. This stub intentionally does not double encode them.
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', array_map( static fn( $key, $value ) => $key . '=' . $value, array_keys( $query ), array_values( $query ) ) );
}
class WP { public array $query_vars = array(); }
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/';
require $base . 'inc/core/Frontend/Router.php';
require $base . 'inc/core/Accounts/Frontend.php';
require $base . 'inc/core/Community/Frontend.php';
require $base . 'inc/core/Community/Strings.php';
use AISkillsHub\Core\Community\Frontend;
use AISkillsHub\Core\Accounts\Frontend as Accounts;

$reflection = new ReflectionClass( Frontend::class ); $controller = $reflection->newInstanceWithoutConstructor();
$invoke = static fn( string $method, mixed ...$args ): mixed => $reflection->getMethod( $method )->invoke( $controller, ...$args );
$checks = 0;
$assert = static function ( bool $condition, string $label ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( 'Failed: ' . $label ); } };
$reject = static function ( callable $operation, string $label ) use ( $assert ): void { try { $operation(); } catch ( Throwable $error ) { $assert( true, $label ); return; } $assert( false, $label ); };
$_POST = $_GET = array(); $_SERVER = array( 'REQUEST_URI' => '/hub/en/account/favorites/', 'HTTP_ORIGIN' => 'https://example.test' );
$controller->parse( new WP() );
$assert( Frontend::active() && Frontend::context()['route'] === 'favorites' && Frontend::context()['locale'] === 'en_US', 'private community route under a WordPress subdirectory' );
foreach ( array( '/hub/zh/account/review/', '/hub/en/account/report-skill/', '/hub/index.php/en/account/reviews/' ) as $path ) {
	$reflection->getProperty( 'active' )->setValue( null, false ); $_SERVER['REQUEST_URI'] = $path; $controller->parse( new WP() ); $assert( Frontend::active(), 'valid multilingual private route' );
}
foreach ( array( '/en/account/favorites/', '/hub/en/account/../favorites/', '/hub/en/account/%66avorites/', '/hub/en/account/favorites/extra/', '/hub/fr/account/favorites/', '/hub/en/account/submissions/' ) as $path ) {
	$reflection->getProperty( 'active' )->setValue( null, false ); $_SERVER['REQUEST_URI'] = $path; $controller->parse( new WP() ); $assert( ! Frontend::active(), 'malformed/unimplemented route rejected' );
}
$_SERVER['REQUEST_URI'] = '/hub/en/account/favorites/'; $wp = new WP(); $wp->query_vars = array( 'rest_route' => '/private' ); $controller->parse( $wp );
$assert( ! Frontend::active(), 'community routing cannot consume REST endpoints' );

$assert( Frontend::integer( '5', 1, 5 ) === 5 && Frontend::integer( '0', 0 ) === 0, 'bounded integer and first-write version accepted' );
foreach ( array( '5.0', '1e2', '0', '6', '-1', array( 5 ), 1.0, true, str_repeat( '9', 40 ) ) as $value ) { $reject( static fn() => Frontend::integer( $value, 1, 5 ), 'reject non-integer/out-of-range rating' ); }
$reject( static fn() => $invoke( 'text', array( 'payload' ), 10 ), 'array input rejected' );
$reject( static fn() => $invoke( 'text', "a\0b", 10 ), 'NUL input rejected' );
$reject( static fn() => $invoke( 'text', "\xFF", 10 ), 'invalid UTF-8 rejected' );
$reject( static fn() => $invoke( 'text', str_repeat( 'x', 11 ), 10 ), 'oversized input rejected' );
$assert( $invoke( 'text', '体验', 6 ) === '体验', 'valid UTF-8 retained' );

$nonce = hash( 'sha256', 'aish_community_review' ); $_POST = array( '_aish_nonce' => $nonce );
$invoke( 'checkCsrf', 'review' ); $assert( true, 'same-origin action-bound native nonce accepted' );
$reject( static fn() => $invoke( 'checkCsrf', 'withdraw' ), 'review nonce cannot authorize withdrawal' );
foreach ( array( 'https://attacker.test', 'http://example.test', 'https://example.test:444', 'https://name@example.test', array( 'https://example.test' ) ) as $origin ) { $_SERVER['HTTP_ORIGIN'] = $origin; $reject( static fn() => $invoke( 'checkCsrf', 'review' ), 'foreign or malformed Origin rejected' ); }
$_SERVER['HTTP_ORIGIN'] = 'null'; $invoke( 'checkCsrf', 'review' ); $assert( true, 'opaque no-referrer origin still requires action nonce' );
$_POST['_aish_nonce'] = ''; $reject( static fn() => $invoke( 'checkCsrf', 'review' ), 'opaque origin never replaces CSRF nonce' );
$_POST['_aish_nonce'] = array( $nonce ); $reject( static fn() => $invoke( 'checkCsrf', 'review' ), 'array nonce rejected' );

$fields = Frontend::fields( 'review' ); preg_match( '/name="request_key" value="([a-f0-9]{32})"/', $fields, $match );
$assert( isset( $match[1] ), 'form has random idempotency key' );
$first = $match[1]; preg_match( '/name="request_key" value="([a-f0-9]{32})"/', Frontend::fields( 'review' ), $match );
$assert( $match[1] !== $first, 'separate forms receive separate request keys' );
$context = Frontend::context(); $context['error'] = 'invalid_input'; $reflection->getProperty( 'context' )->setValue( null, $context );
$_POST = array( 'community_action' => 'review', 'request_key' => $first, 'title' => 'My draft', 'token' => 'DO-NOT-RENDER', '_aish_nonce' => $nonce );
$assert( str_contains( Frontend::fields( 'review' ), $first ), 'failed POST keeps key for safe retry' );
$assert( ! str_contains( Frontend::fields( 'withdraw' ), $first ), 'request key not reused for another action' );
$safe = $invoke( 'safeValues' ); $assert( $safe['title'] === 'My draft' && ! isset( $safe['token'] ) && ! isset( $safe['_aish_nonce'] ), 'draft preservation excludes secrets and credentials' );

$url = Frontend::url( 'report-review', 'zh_CN', array( 'skill' => 7, 'review' => 9, 'revision' => 11, 'user_id' => 123 ) );
$assert( $url === 'https://example.test/hub/zh/account/report-review/?skill=7&review=9&revision=11', 'URL retains exact target but no caller-supplied owner' );
$reject( static fn() => Frontend::url( '../wp-admin', 'en_US' ), 'route traversal rejected' );
$reject( static fn() => Frontend::url( 'favorites', 'fr_FR' ), 'unsupported interface locale rejected' );
$assert( Accounts::safeReturn( $url ) === $url, 'login can return to the exact current review report' );
$filtered = 'https://example.test/hub/en/account/favorites/?page=2&q=Agent%20tools&status=available&sort=title';
$assert( Accounts::safeReturn( $filtered ) === $filtered, 'login preserves safe private list filters' );
$assert( Accounts::safeReturn( 'https://example.test/hub/zh/skills/7/example-skill/' ) === 'https://example.test/hub/zh/skills/7/example-skill/', 'public Skill return supported' );
foreach ( array( '//attacker.test', 'https://attacker.test/hub/en/account/', 'https://example.test:444/hub/en/account/', 'https://attacker@example.test/hub/en/account/', 'https://example.test/hub/en/account/../profile/', 'https://example.test/hub/en/account/%2e%2e/profile/', 'https://example.test/wp-admin/', 'https://example.test/hub/en/account/?token=private', 'https://example.test/hub/en/account/favorite/?skill[]=7', 'https://example.test/hub/en/account/favorite/?skill=0', 'https://example.test/hub/en/account/favorite/?skill=7#private', 'https://example.test/hub/en/account/favorites/?q=%00bad', 'https://example.test/hub/en/account/favorites/?status=approved', 'https://example.test/hub/en/account/reviews/?page=1001', 'https://example.test/hub/en/account/favorites/?sort=RAND()', 'https://example.test/hub/en/skills/7/example/?key=secret', array( 'https://example.test/hub/en/account/' ) ) as $value ) { $assert( null === Accounts::safeReturn( $value ), 'unsafe login return rejected' ); }
$assert( str_contains( Accounts::loginUrl( $url, 'en_US' ), 'return_to=https%3A%2F%2Fexample.test' ), 'login link encodes the validated local destination' );

$args = array( 'skill' => array( 'id' => 7, 'slug' => 'example' ), 'locale' => 'en_US', 'summary' => array( 'average' => 4.0, 'count' => 1, 'favorites' => 3, 'distribution' => array( 4 => 1 ) ), 'listing' => array( 'total' => 1, 'page' => 1, 'per_page' => 10, 'items' => array( array( 'id' => 9, 'skill_id' => 7, 'revision_id' => 11, 'locale' => 'zh_CN', 'rating' => 4, 'title' => '<script>bad()</script>', 'comment' => '<img src=x onerror=bad()>', 'published_at' => '2026-01-01 00:00:00', 'user_id' => 123, 'user_email' => 'PRIVATE-EMAIL', 'display_name' => 'PRIVATE-NAME', 'private_note' => 'PRIVATE-MODERATOR-NOTE' ) ) ) );
ob_start(); include $base . 'templates/community/public.php'; $html = ob_get_clean();
$assert( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ) && ! str_contains( $html, '<img' ), 'untrusted review HTML rendered as escaped text' );
$assert( ! str_contains( $html, 'PRIVATE-' ) && str_contains( $html, 'Community member' ), 'public template never prints user identity or moderator metadata' );
$assert( str_contains( $html, 'lang="zh-CN"' ) && str_contains( $html, 'revision=11' ), 'original language and approved revision report target retained' );
$assert( str_contains( $html, 'id="community-report-dialog"' ) && str_contains( $html, '/account/report-review/' ), 'report dialog has progressive authenticated-page fallback' );
$args['summary']['average'] = null; $args['summary']['count'] = 0; $args['listing']['items'] = array();
ob_start(); include $base . 'templates/community/public.php'; $empty = ob_get_clean();
$assert( str_contains( $empty, 'No ratings yet' ) && ! str_contains( $empty, '0.0 / 5' ), 'empty ratings are not fabricated as a zero-star score' );
echo "Community frontend isolated: $checks assertions passed; no real WP/HTTP/database claim.\n";
