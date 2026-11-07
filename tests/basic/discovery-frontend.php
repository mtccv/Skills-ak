<?php
declare(strict_types=1);
/** Isolated route, SEO and SSR contract tests. No WordPress/MySQL/browser claim. */
define( 'ABSPATH', __DIR__ . '/' );
class WP { public array $query_vars = array(); }
class DiscoverySeoDatabase { public string $last_error = ''; public function suppress_errors( bool $value ): bool { return false; } }
$GLOBALS['wpdb'] = new DiscoverySeoDatabase();
function is_admin(): bool { return false; }
function get_option( string $name, mixed $default = false ): mixed { return match ( $name ) { 'home' => 'https://example.test/hub', 'blog_public' => '1', default => $default }; }
function home_url( string $path = '/' ): string { return 'https://example.test/hub' . $path; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
 if ( 'aish_frontend_builtin_routing' === $hook && isset( $GLOBALS['routing_adapter'] ) ) { if ( $GLOBALS['routing_adapter'] instanceof Throwable ) { throw $GLOBALS['routing_adapter']; } return $GLOBALS['routing_adapter']; }
 if ( 'aish_frontend_url' === $hook && isset( $GLOBALS['url_adapter'] ) ) { return ( $GLOBALS['url_adapter'] )( $value, ...$args ); }
 return 'aish_frontend_context' === $hook && \AISkillsHub\Core\Discovery\Frontend::active() ? \AISkillsHub\Core\Discovery\Frontend::context() : $value;
}
function add_query_arg( array $args, string $url ): string { return $url . '?' . implode( '&', array_map( static fn( $key ) => $key . '=' . $args[ $key ], array_keys( $args ) ) ); }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( string $value ): string { return esc_attr( $value ); }
function esc_url( string $value ): string { return esc_attr( $value ); }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function number_format_i18n( int|float $value, int $decimals = 0 ): string { return number_format( $value, $decimals ); }
function selected( mixed $a, mixed $b ): void { if ( (string) $a === (string) $b ) { echo 'selected'; } }
function nocache_headers(): void {}
function status_header( int $status ): void { $GLOBALS['http_status'] = $status; }
function add_action( mixed ...$args ): void {}
function remove_action( mixed ...$args ): void {}
function remove_all_actions( mixed ...$args ): void {}
function wp_unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value ); }
function get_header(): void {}
function get_footer(): void {}
function get_template_part( string $file, mixed $name = null, array $args = array() ): void { include $GLOBALS['base'] . $file . '.php'; }
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/';
foreach ( array( 'Frontend/Router', 'Frontend/View', 'Frontend/Strings', 'Frontend/SeoSettings', 'Frontend/Seo', 'Contributions/Ui', 'Discovery/RankingPolicy', 'Discovery/Strings', 'Discovery/Frontend' ) as $file ) { require $base . 'inc/core/' . $file . '.php'; }
use AISkillsHub\Core\Discovery\Frontend as Discovery;
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\Seo;
use AISkillsHub\Core\Frontend\View;
$checks = 0;
$assert = static function ( bool $ok, string $label ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $label ); } };
$reject = static function ( callable $operation, string $label ) use ( $assert ): void { try { $operation(); } catch ( InvalidArgumentException $e ) { $assert( true, $label ); return; } $assert( false, $label ); };
$reflection = new ReflectionClass( Discovery::class ); $controller = $reflection->newInstanceWithoutConstructor();
foreach ( array( '/hub/zh/trending/', '/hub/en/most-viewed/', '/hub/en/most-favorited/', '/hub/en/top-rated/', '/hub/en/recently-added/', '/hub/en/recently-updated/', '/hub/en/editors-picks/', '/hub/en/collections/', '/hub/en/collections/12/editor-tools/', '/hub/zh/use-cases/7/development/', '/hub/en/platforms/platform-codex/', '/hub/index.php/en/use-cases/' ) as $path ) {
	$reflection->getProperty( 'active' )->setValue( null, false ); $_SERVER['REQUEST_URI'] = $path; $wp = new WP(); $wp->query_vars = array( 'p' => 22, 'post_type' => 'aish_message' ); $controller->parse( $wp );
	$assert( Discovery::active(), 'accepted bilingual discovery route: ' . $path ); $assert( array_keys( $wp->query_vars ) === array( 'aish_route', 'aish_language' ), 'native private query removed' );
}
foreach ( array( '/en/trending/', '/hub/fr/trending/', '/hub/en/trending/7/', '/hub/en/collections/0/slug/', '/hub/en/collections/slug/', '/hub/en/collections/1/../', '/hub/en/collections/1/%61/', '/hub/en/platforms/7/', '/hub/wp-admin/', '/hub/en/use-cases/7/unsafe<script>/' ) as $path ) { $reflection->getProperty( 'active' )->setValue( null, false ); $_SERVER['REQUEST_URI'] = $path; $controller->parse( new WP() ); $assert( ! Discovery::active(), 'malformed or foreign path rejected' ); }
$_SERVER['REQUEST_URI'] = '/hub/en/trending/'; $wp = new WP(); $wp->query_vars['rest_route'] = '/private'; $controller->parse( $wp ); $assert( ! Discovery::active(), 'REST not intercepted' );
$assert( Discovery::url( 'editors-picks', 'en_US', array( 'page' => 2 ) ) === 'https://example.test/hub/en/collections/?page=2', 'editor picks alias resolves to a single canonical collection index' );
$url = Discovery::url( 'collections', 'en_US', array( 'id' => 9, 'slug' => 'actual-title', 'page' => 3, 'q' => '中文 & PHP', 'tag' => 'writing', 'token' => 'SECRET', 'user_id' => 7 ) ); parse_str( parse_url( $url, PHP_URL_QUERY ), $params );
$assert( str_starts_with( $url, 'https://example.test/hub/en/collections/9/actual-title/' ) && $params === array( 'page' => '3', 'q' => '中文 & PHP', 'tag' => 'writing' ), 'canonical route and bounded filters, no private params' );
foreach ( array( array( 'route' => '../private', 'locale' => 'en_US' ), array( 'route' => 'trending', 'locale' => 'fr_FR' ) ) as $case ) { $reject( fn() => Discovery::url( $case['route'], $case['locale'] ), 'unknown URL contract rejected' ); }
foreach ( array( array( 'id' => 0, 'slug' => 'okay' ), array( 'id' => 1, 'slug' => '../secret' ), array( 'id' => 1, 'slug' => array() ) ) as $input ) { $reject( fn() => Discovery::url( 'collections', 'en_US', $input ), 'unsafe topic identity rejected' ); }
foreach ( array( array( 'q' => array() ), array( 'q' => "\xff" ), array( 'q' => "line\nfeed" ), array( 'q' => str_repeat( '界', 101 ) ), array( 'window' => '8' ), array( 'window' => array() ), array( 'page' => '1001' ), array( 'category' => 'x OR 1=1' ) ) as $input ) { $reject( fn() => Discovery::filters( $input ), 'invalid query rejected before public read' ); }
$term = array( 'id' => 2, 'name' => 'Child', 'parent_id' => 1, 'translation_missing' => true ); $terms = array( array( 'id' => 1, 'name' => 'Parent', 'parent_id' => 2 ), $term );
$assert( View::termLabel( $term, $terms ) === 'Parent / Child *', 'hierarchy rendered from loaded rows and cycles bounded' );
$context = array( 'route' => 'discovery', 'locale' => 'en_US', 'language' => 'en', 'title' => 'A real title', 'description' => 'A real description', 'canonical' => Discovery::url( 'collections', 'en_US', array( 'id' => 9, 'slug' => 'actual-title' ) ), 'alternates' => array( 'en_US' => $url ), 'status' => 200, 'error' => false, 'data' => array( 'result' => array( 'total' => 1 ), 'filters' => array( 'page' => 1 ), 'discovery_title' => 'A real title' ) );
$prepared = Seo::prepare( $context, array() );
$assert( ! $prepared['noindex'] && array_keys( $prepared['seo_alternates'] ) === array( 'en_US' ) && $prepared['seo_alternates']['en_US'] === $context['canonical'], 'missing translated topic cannot invent hreflang; self canonical remains English' );
$assert( $prepared['x_default'] === $context['canonical'], 'x-default uses a real available translation' );
$assert( Seo::prepare( $context, array( 'category' => 'writing' ) )['noindex'], 'filter combinations noindex' );
$context['data']['result']['total'] = 0; $assert( Seo::prepare( $context, array() )['noindex'], 'empty discovery result noindex' );
$context['data'] = array( 'topics' => array() ); $assert( Seo::prepare( $context, array() )['noindex'], 'empty topic directory noindex' );
$context['route'] = 'category'; $context['data'] = array( 'term' => array( 'count' => 2, 'translation_missing' => true ) ); $assert( Seo::noindex( $context, array() ), 'untranslated category fallback noindex' );
$reflection->getProperty( 'active' )->setValue( null, true ); $reflection->getProperty( 'context' )->setValue( null, array( 'locale' => 'en_US' ) );
$assert( View::url( 'skills' ) === 'https://example.test/hub/en/skills/', 'shared header and cards retain extension route locale' );
$skill = array( 'id' => 12, 'slug' => 'published-skill', 'title' => '<script>alert(1)</script>', 'summary' => '<img src=x onerror=bad()>Actual summary', 'categories' => array(), 'platforms' => array(), 'metric' => array( 'average' => 4.5, 'reviews' => 2, 'distribution' => array( 1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1 ) ), 'private_note' => 'SECRET', 'user_id' => 32 );
$args = array( 'skill' => $skill, 'locale' => 'en_US' ); ob_start(); include $base . 'templates/discovery/skill.php'; $html = ob_get_clean();
$assert( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img' ) && ! str_contains( $html, 'SECRET' ) && str_contains( $html, '&lt;script&gt;' ), 'public resource output escapes hostile text and ignores private fields' );
$assert( str_contains( $html, '4.5 / 5 · 2' ) && str_contains( $html, 'Limited sample' ) && str_contains( $html, 'Rating distribution' ), 'actual small samples and distribution remain explicit' );
$args = array( 'topic' => array( 'id' => 8, 'slug' => 'actual-topic', 'title' => '<img src=x>', 'description' => '<script>x</script>Text', 'count' => 2 ), 'route' => 'use-cases', 'locale' => 'en_US' ); ob_start(); include $base . 'templates/discovery/topic.php'; $html = ob_get_clean();
$assert( str_contains( $html, '/en/use-cases/8/actual-topic/' ) && ! str_contains( $html, '<img' ) && ! str_contains( $html, '<script>' ), 'topic output escaped and points to stable ID' );
$args = array( 'module' => array( 'key' => 'trending', 'title' => '', 'description' => '' ), 'items' => array(), 'locale' => 'en_US', 'kind' => 'skills', 'link' => Discovery::url( 'trending', 'en_US' ), 'error' => false ); ob_start(); include $base . 'templates/discovery/home-module.php'; $html = ob_get_clean();
$assert( str_contains( $html, 'No eligible public resources yet' ) && ! str_contains( $html, 'discovery-resource' ), 'empty homepage does not fabricate resources or counts' );
$filters = Discovery::filters( array( 'q' => 'C++ & 中文', 'category' => 'development', 'page' => 3 ) );
$state = array( 'route' => 'discovery', 'locale' => 'en_US', 'status' => 200, 'error' => false, 'data' => array( 'route' => 'platforms', 'filters' => $filters, 'params' => array( 'slug' => 'platform-codex' ), 'topic' => array( 'id' => 8, 'slug' => 'platform-codex', 'title' => 'Codex', 'description' => 'Verified platform collection', 'translations' => array( 'en_US' => 'platform-codex' ) ), 'discovery_title' => 'Codex', 'result' => array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 12 ), 'categories' => array(), 'tags' => array(), 'platforms' => array() ) );
$reflection->getProperty( 'context' )->setValue( null, $state );
$assert( View::switchUrl( 'zh_CN' ) === Discovery::url( 'platforms', 'zh_CN' ), 'missing topic language switches to real index, never a fabricated detail' );
$state['data']['topic']['translations']['zh_CN'] = 'platform-codex'; $reflection->getProperty( 'context' )->setValue( null, $state );
parse_str( parse_url( View::switchUrl( 'zh_CN' ), PHP_URL_QUERY ), $switch );
$assert( $switch === array( 'q' => 'C++ & 中文', 'category' => 'development' ), 'language switch retains valid filters and resets pagination' );
ob_start(); include $base . 'templates/discovery/page.php'; $html = ob_get_clean();
$assert( ! str_contains( $html, 'name="platform"' ) && str_contains( $html, 'name="category"' ) && str_contains( $html, 'name="tag"' ), 'fixed platform detail excludes contradictory platform selector' );
$assert( str_contains( $html, 'value="development" selected' ) || str_contains( $html, 'selected value="development"' ), 'selected filter outside bounded options is retained' );
$GLOBALS['routing_adapter'] = false; $reflection->getProperty( 'active' )->setValue( null, false ); $_SERVER['REQUEST_URI'] = '/hub/en/trending/'; $controller->parse( new WP() );
$assert( ! Discovery::active(), 'custom routing adapter can disable built-in discovery routes' );
$GLOBALS['routing_adapter'] = new RuntimeException( 'sensitive adapter error' ); $controller->parse( new WP() );
$assert( Discovery::active() && ! empty( $reflection->getProperty( 'request' )->getValue( $controller )['adapter_failed'] ), 'routing adapter exception becomes an owned fail-closed response' );
unset( $GLOBALS['routing_adapter'] );
$routerAdapter = new ReflectionProperty( Router::class, 'adapterFailed' ); $routerAdapter->setValue( null, false );
$GLOBALS['url_adapter'] = static fn( string $url, string $route, array $args, string $locale ): string => str_replace( 'example.test', 'en.example.test', $url );
$assert( Discovery::url( 'trending', 'en_US' ) === 'https://en.example.test/hub/en/trending/', 'existing URL adapter can supply a validated locale domain' );
foreach ( array( 'javascript:alert(1)', 'https://user:pass@example.test/en/', 'https://example.test/en/#secret', 'https://example.test/en/ bad' ) as $invalidUrl ) {
 $routerAdapter->setValue( null, false ); $GLOBALS['url_adapter'] = static fn() => $invalidUrl;
 try { Discovery::url( 'trending', 'en_US' ); $assert( false, 'malformed adapted URL accepted' ); } catch ( RuntimeException $expected ) { $assert( true, 'malformed URL adapter is rejected' ); }
 $assert( Discovery::link( 'trending', 'en_US' ) === 'https://example.test/hub/en/trending/', 'subsequent presentation link safely retries built-in URL' );
}
unset( $GLOBALS['url_adapter'] ); $routerAdapter->setValue( null, false );
$GLOBALS['wp_query'] = (object) array( 'is_404' => false, 'is_home' => true );
foreach ( array( 'collections', 'use-cases', 'platforms' ) as $indexRoute ) {
 $reflection->getProperty( 'active' )->setValue( null, false ); $_SERVER['REQUEST_URI'] = '/hub/en/' . $indexRoute . '/?page=999'; $_GET = array( 'page' => '999' ); $controller->parse( new WP() ); $controller->resolve();
 $assert( $GLOBALS['http_status'] === 404 && Discovery::context()['noindex'] && ! Discovery::context()['canonical'], 'unpaginated topic indexes reject invented pages before querying' );
}
$GLOBALS['routing_adapter'] = new RuntimeException( 'private adapter failure' ); $_GET = array(); $_SERVER['REQUEST_URI'] = '/hub/en/trending/'; $controller->parse( new WP() ); $controller->resolve();
$assert( $GLOBALS['http_status'] === 503 && Discovery::context()['noindex'] && ! str_contains( json_encode( Discovery::context() ), 'private adapter' ), 'routing adapter failure owns a nonindexable sanitized 503 response' );
unset( $GLOBALS['routing_adapter'] );
echo "Discovery frontend isolated: $checks assertions passed; no WordPress/MySQL/browser integration claim.\n";
