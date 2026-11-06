<?php
declare(strict_types=1);
/** Isolated HTTP boundary/template checks: no real WP, MySQL or external transport claim. */
define( 'ABSPATH', __DIR__ . '/' );
function get_option( string $name, mixed $default = false ): mixed { return 'home' === $name ? 'https://example.test/hub' : $default; }
function home_url( string $path = '/' ): string { return 'https://example.test/hub' . $path; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function is_admin(): bool { return false; }
function wp_unslash( string $value ): string { return stripslashes( $value ); }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( string $value ): string { return esc_attr( $value ); }
function esc_textarea( string $value ): string { return esc_attr( $value ); }
function esc_url( string $value, array $schemes = array() ): string { return esc_attr( $value ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }
function wp_nonce_field( string $action, string $name, bool $referer, bool $echo ): string { return '<input name="' . $name . '" value="' . hash( 'sha256', $action ) . '">'; }
function wp_verify_nonce( string $nonce, string $action ): bool { return hash_equals( hash( 'sha256', $action ), $nonce ); }
function add_query_arg( mixed ...$args ): string { if ( is_array( $args[0] ) ) { [ $query, $url ] = $args; } else { $query = array( $args[0] => $args[1] ); $url = $args[2]; } return $url . '?' . implode( '&', array_map( static fn( $key, $value ) => $key . '=' . $value, array_keys( $query ), array_values( $query ) ) ); }
function selected( mixed $actual, mixed $expected ): void { if ( $actual === $expected ) { echo 'selected'; } }
function checked( mixed $actual ): void { if ( $actual ) { echo 'checked'; } }
function bloginfo( string $key ): void { echo 'UTF-8'; }
function wp_head(): void {}
function wp_footer(): void {}
function wp_body_open(): void {}
function body_class( string $classes ): void { echo 'class="' . esc_attr( $classes ) . '"'; }
class WP { public array $query_vars = array(); }
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/';
foreach ( array( 'Frontend/Router', 'Frontend/View', 'Accounts/Frontend', 'Accounts/Strings', 'Contributions/Ui', 'Contributions/Strings', 'Submissions/Frontend', 'Submissions/Strings', 'Developers/Frontend', 'Developers/Strings' ) as $class ) { require $base . 'inc/core/' . $class . '.php'; }
use AISkillsHub\Core\Accounts\Frontend as Accounts;
use AISkillsHub\Core\Contributions\Ui;
use AISkillsHub\Core\Submissions\Frontend as Submissions;
use AISkillsHub\Core\Developers\Frontend as Developers;
$checks = 0;
$assert = static function ( bool $condition, string $label ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( 'Failed: ' . $label ); } };
$reject = static function ( callable $operation, string $label ) use ( $assert ): void { try { $operation(); } catch ( Throwable $error ) { $assert( true, $label ); return; } $assert( false, $label ); };
$_POST = $_GET = array(); $_SERVER = array( 'HTTP_ORIGIN' => 'https://example.test' );
foreach ( array( Submissions::class => array( '/hub/zh/submit/', '/hub/en/account/submissions/', '/hub/zh/account/submission/', '/hub/index.php/en/account/update-suggestion/' ), Developers::class => array( '/hub/zh/account/developer/', '/hub/en/account/claim/', '/hub/en/developers/public-name/', '/hub/zh/developers/public-name/avatar/' ) ) as $class => $paths ) {
	$r = new ReflectionClass( $class ); $controller = $r->newInstanceWithoutConstructor(); $initial = $class::context();
	foreach ( $paths as $path ) { $r->getProperty( 'active' )->setValue( null, false ); $r->getProperty( 'context' )->setValue( null, $initial ); $_SERVER['REQUEST_URI'] = $path; $controller->parse( new WP() ); $assert( $class::active(), 'known bilingual routes under WP subdirectory' ); }
	foreach ( array( '/en/account/submission/', '/hub/fr/account/developer/', '/hub/en/account/../submissions/', '/hub/en/account/%73ubmissions/', '/hub/en/developers/a/', '/hub/en/developers/private@name/', '/hub/en/account/submission/1/' ) as $path ) { $r->getProperty( 'active' )->setValue( null, false ); $_SERVER['REQUEST_URI'] = $path; $controller->parse( new WP() ); $assert( ! $class::active(), 'unknown/malformed paths cannot activate private workflow' ); }
	$r->getProperty( 'active' )->setValue( null, false ); $_SERVER['REQUEST_URI'] = $paths[0]; $wp = new WP(); $wp->query_vars['rest_route'] = '/private'; $controller->parse( $wp ); $assert( ! $class::active(), 'REST routes remain out of frontend parser' );
}
$url = Submissions::url( 'submission', 'en_US', array( 'id' => 19, 'user_id' => 10, 'token' => 'secret' ) );
$assert( $url === 'https://example.test/hub/en/account/submission/?id=19', 'owner and secret cannot enter generated URL' );
$assert( Accounts::safeReturn( $url ) === $url, 'owner-scoped submission detail return survives login' );
foreach ( array( Submissions::url( 'submit', 'zh_CN', array( 'page' => 1 ) ), Submissions::url( 'update-suggestion', 'en_US', array( 'skill' => 6 ) ), Submissions::url( 'submissions', 'zh_CN', array( 'page' => 2, 'status' => 'needs_changes', 'kind' => 'update_suggestion' ) ), Developers::url( 'claim', 'en_US', array( 'id' => 7 ) ), Developers::url( 'developer', 'zh_CN', array( 'state' => 'disputed' ) ) ) as $return ) { $assert( Accounts::safeReturn( $return ) === $return, 'safe return retains bounded action and filters' ); }
foreach ( array( 'https://evil.test/hub/en/submit/', 'https://example.test/hub/en/account/submission/?id[]=19', 'https://example.test/hub/en/account/submission/?id=19&user_id=2', 'https://example.test/hub/en/submit/?token=secret', 'https://example.test/hub/en/account/developer/?state=published', 'https://example.test/hub/en/account/submissions/?kind=contact', 'https://example.test/hub/en/account/submissions/?status=approved', 'https://example.test/hub/en/account/claim/?skill=0', 'https://example.test/hub/en/developers/user/?email=secret' ) as $return ) { $assert( null === Accounts::safeReturn( $return ), 'unsafe or cross-object return parameters rejected' ); }
$reject( static fn() => Submissions::url( '../wp-admin', 'en_US' ), 'route traversal rejected' );
$reject( static fn() => Developers::url( 'public', 'en_US', array( 'handle' => '../private' ) ), 'developer handle traversal rejected' );
$reject( static fn() => Ui::text( array( 'x' ), 20 ), 'array input rejected' );
foreach ( array( "x\0y", "\xff", str_repeat( 'a', 21 ) ) as $text ) { $reject( static fn() => Ui::text( $text, 20 ), 'unbounded or invalid input rejected' ); }
$_POST = array( '_aish_nonce' => hash( 'sha256', 'aish_submissions_save' ) ); Ui::csrf( 'submissions', 'save' ); $assert( true, 'action-specific native nonce accepted' );
$reject( static fn() => Ui::csrf( 'submissions', 'withdraw' ), 'save nonce cannot authorize withdrawal' );
$reject( static fn() => Ui::csrf( 'developers', 'save' ), 'submission nonce cannot authorize another domain' );
foreach ( array( 'https://evil.test', 'http://example.test', 'https://example.test:444', 'https://name@example.test', array( 'https://example.test' ) ) as $origin ) { $_SERVER['HTTP_ORIGIN'] = $origin; $reject( static fn() => Ui::csrf( 'submissions', 'save' ), 'cross-origin requests rejected' ); }
$_SERVER['HTTP_ORIGIN'] = 'null'; Ui::csrf( 'submissions', 'save' ); $assert( true, 'opaque Origin still requires nonce' ); $_POST['_aish_nonce'] = ''; $reject( static fn() => Ui::csrf( 'submissions', 'save' ), 'opaque Origin cannot replace nonce' );
$key = str_repeat( 'a', 32 ); $_POST = array( 'contribution_action' => 'save', 'request_key' => $key, 'revision' => '4', 'name' => 'Retained input', 'token' => 'PRIVATE-TOKEN', 'user_id' => '9' );
$assert( str_contains( Ui::fields( 'submissions', 'save', true ), $key ), 'failed mutation retains idempotency key' );
$assert( ! str_contains( Ui::fields( 'submissions', 'withdraw', true ), $key ), 'another action receives another key' );
$r = new ReflectionClass( Submissions::class ); $safe = $r->getMethod( 'safeValues' )->invoke( null );
$assert( $safe['revision'] === '4' && $safe['name'] === 'Retained input' && ! isset( $safe['token'], $safe['user_id'] ), 'failed POST preserves original revision and bounded fields without owner or token' );
$r->getProperty( 'context' )->setValue( null, array( 'route' => 'submit', 'locale' => 'en_US', 'params' => array(), 'status' => 422, 'error' => 'invalid_input', 'message' => '', 'profile' => array(), 'values' => array( 'name' => '<script>bad()</script>', 'notes' => '<img src=x onerror=bad()>', 'revision' => '4' ) ) );
ob_start(); include $base . 'templates/submissions/content.php'; $html = ob_get_clean();
$assert( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ) && ! str_contains( $html, '<img' ), 'draft errors escape untrusted text' );
$assert( str_contains( $html, 'name="revision" value="4"' ) && str_contains( $html, 'name="mode" value="draft"' ) && str_contains( $html, 'name="mode" value="submit"' ), 'SSR form keeps CAS and separate draft/review actions without JS' );
$r = new ReflectionClass( Developers::class );
$r->getProperty( 'context' )->setValue( null, array( 'route' => 'public', 'locale' => 'en_US', 'status' => 200, 'error' => '', 'public' => true, 'alternatives' => array( 'en_US' => Developers::url( 'public', 'en_US', array( 'handle' => 'public-name' ) ) ), 'params' => array( 'handle' => 'public-name', 'page' => 1 ), 'profile' => array( 'handle' => 'public-name', 'display_name' => '<script>name()</script>', 'bio' => '<img src=x onerror=bad()>', 'avatar' => false, 'github_url' => '', 'website_url' => '', 'skills' => array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 12 ), 'contributions' => array(), 'user_id' => 99, 'user_email' => 'PRIVATE-EMAIL', 'user_login' => 'PRIVATE-LOGIN', 'private_note' => 'PRIVATE-NOTE' ) ) );
ob_start(); include $base . 'templates/developers/public.php'; $html = ob_get_clean();
$assert( ! str_contains( $html, 'PRIVATE-' ) && ! str_contains( $html, 'user_id' ), 'public profile template never prints private identity or moderator fields' );
$assert( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img' ) && str_contains( $html, '&lt;script&gt;' ), 'approved profile text still escaped at output' );
$assert( ! str_contains( $html, '/zh/developers/' ) && str_contains( $html, 'No attributed Skills' ), 'missing translation is not fabricated and empty public relations honest' );
$assert( null === \AISkillsHub\Core\Contributions\Strings::notification( 'private_note', 'en_US' ) && str_contains( \AISkillsHub\Core\Contributions\Strings::notification( 'contribution_accepted', 'en_US' ), 'editorial' ), 'notification templates fixed and acceptance not publication' );
echo "Contribution frontend isolated: $checks assertions passed; no real WP/HTTP/database claim.\n";
