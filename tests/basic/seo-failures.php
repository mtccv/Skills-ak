<?php
declare(strict_types=1);

/** Native read and adapter failure contracts; no real WordPress, SQL, WPML or HTTP. */
namespace AISkillsHub\Core\Frontend {
	final class PublicCatalog {
		public function featured( mixed ...$args ): array { return array(); }
		public function latest( mixed ...$args ): array { return array(); }
		public function terms( mixed ...$args ): array { return array(); }
		public function platforms( mixed ...$args ): array { return array(); }
	}
	final class Editorial { public function tutorials( mixed ...$args ): array { return array( 'items' => array() ); } }
	function header( string $value, bool $replace = true ): void { $GLOBALS['headers'][ explode( ':', $value, 2 )[0] ] = $value; }
	function headers_sent(): bool { return false; }
}

namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	final class WP { public string $request = ''; public array $query_vars = array( 'p' => 99 ); }
	final class WP_Post { public function __construct( public int $ID ) {} }
	class WP_Sitemaps_Provider { public string $name = ''; public string $object_type = ''; }
	final class RedirectIntercept extends \Exception {}
	final class ReadDatabaseStub {
		public string $last_error = '';
		public bool $suppressed = false;
		public function suppress_errors( bool $value ): bool { $previous = $this->suppressed; $this->suppressed = $value; return $previous; }
	}
	function get_option( string $key, mixed $default = false ): mixed {
		if ( array_key_exists( $key, $GLOBALS['cache']['options']['alloptions'] ?? array() ) ) { return $GLOBALS['cache']['options']['alloptions'][ $key ]; }
		if ( isset( $GLOBALS['cache']['options']['notoptions'][ $key ] ) ) { return $default; }
		if ( $key === $GLOBALS['fail_option'] ) {
			$GLOBALS['wpdb']->last_error = 'private SQL credentials';
			$GLOBALS['cache']['options']['notoptions'][ $key ] = true;
			$GLOBALS['cache']['options']['alloptions'] = array();
			if ( ! $GLOBALS['wpdb']->suppressed ) { echo 'private SQL credentials'; }
			return $default;
		}
		return $GLOBALS['options'][ $key ] ?? $default;
	}
	function get_post_meta( int $id, string $key, bool $single = true ): mixed {
		if ( $key === $GLOBALS['fail_meta'] ) {
			$GLOBALS['wpdb']->last_error = 'private metadata SQL';
			$GLOBALS['cache']['post_meta'][ $id ] = array();
			if ( ! $GLOBALS['wpdb']->suppressed ) { echo 'private metadata SQL'; }
			return '';
		}
		return $GLOBALS['metadata'][ $id ][ $key ] ?? '';
	}
	function wp_cache_delete( int|string $key, string $group ): void { unset( $GLOBALS['cache'][ $group ][ $key ] ); $GLOBALS['invalidated'][] = array( $group, $key ); }
	function has_action( string $name ): bool { return false; }
	function apply_filters( string $name, mixed $value, mixed ...$args ): mixed {
		$GLOBALS['filter_calls'][ $name ] = ( $GLOBALS['filter_calls'][ $name ] ?? 0 ) + 1;
		return isset( $GLOBALS['filters'][ $name ] ) ? $GLOBALS['filters'][ $name ]( $value, ...$args ) : $value;
	}
	function get_current_user_id(): int { return 3; }
	function current_user_can( string $cap, mixed ...$args ): bool { return true; }
	function wp_nonce_field( string $action, string $name ): void { ++$GLOBALS['nonce_fields']; }
	function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
	function wp_parse_url( string $value, int $component = -1 ): mixed { return parse_url( $value, $component ); }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function home_url( string $path = '/' ): string { return 'https://example.test' . $path; }
	function add_query_arg( array|string $key, mixed $value, ?string $url = null ): string {
		if ( is_array( $key ) ) { $url = $value; $values = $key; } else { $values = array( $key => $value ); }
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', array_map( static fn( $key ) => $key . '=' . $values[ $key ], array_keys( $values ) ) );
	}
	function is_admin(): bool { return false; }
	function add_action( mixed ...$args ): void {}
	function remove_action( mixed ...$args ): void {}
	function remove_all_actions( mixed ...$args ): void {}
	function status_header( int $status ): void { $GLOBALS['status'] = $status; }
	function nocache_headers(): void { $GLOBALS['no_cache'] = true; }
	function add_rewrite_rule( mixed ...$args ): void { ++$GLOBALS['rewrites']; }
	function flush_rewrite_rules( bool $hard ): void { ++$GLOBALS['flushes']; }
	function update_option( string $key, mixed $value, bool $autoload ): void { $GLOBALS['options'][ $key ] = $value; }
	function wp_safe_redirect( string $url, int $status ): void { $GLOBALS['redirect'] = array( $url, $status ); throw new RedirectIntercept(); }
	function wp_register_sitemap_provider( string $name, WP_Sitemaps_Provider $provider ): void { $GLOBALS['providers'][ $name ] = $provider; }
	function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_url( string $value ): string { return esc_attr( $value ); }
	function esc_html__( string $value, string $domain = '' ): string { return esc_attr( $value ); }
	$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	foreach ( array( 'Security/Input.php', 'Frontend/Strings.php', 'Frontend/Router.php', 'Frontend/SeoSettings.php', 'Frontend/Seo.php', 'Frontend/View.php', 'Frontend/Forms.php', 'Frontend/Sitemap.php' ) as $file ) { require $root . $file; }
	use AISkillsHub\Core\Frontend\{Router, SeoSettings, Seo, View, Forms, Sitemap, PublicCatalog, Editorial};
	$checks = 0;
	function check( bool $value, string $message ): void { global $checks; if ( ! $value ) { throw new \RuntimeException( $message ); } ++$checks; }
	function resetFixture(): void {
		$GLOBALS['wpdb'] = new ReadDatabaseStub();
		$GLOBALS['options'] = array( 'home' => 'https://example.test', 'blog_public' => '1', 'permalink_structure' => '/%postname%/', 'aish_seo_settings' => array( 'en_US:home:0' => array( 'noindex' => true ), 'en_US:skill:5' => array( 'noindex' => true ) ) );
		$GLOBALS['metadata'] = array( 9 => array( '_aish_seo_noindex' => '1' ) );
		foreach ( array( 'filters', 'filter_calls', 'headers', 'cache', 'invalidated', 'providers' ) as $key ) { $GLOBALS[ $key ] = array(); }
		$GLOBALS['fail_option'] = $GLOBALS['fail_meta'] = ''; $GLOBALS['status'] = 0; $GLOBALS['no_cache'] = false;
		$GLOBALS['rewrites'] = $GLOBALS['flushes'] = $GLOBALS['nonce_fields'] = 0;
		$GLOBALS['wp_query'] = (object) array( 'is_404' => false, 'is_home' => false ); $_GET = array();
		( new \ReflectionProperty( Router::class, 'adapterFailed' ) )->setValue( null, false );
	}
	function router(): Router {
		$router = new Router( new PublicCatalog(), new Editorial() );
		( new \ReflectionProperty( $router, 'handled' ) )->setValue( $router, true );
		( new \ReflectionProperty( $router, 'request' ) )->setValue( $router, array( 'route' => 'home', 'locale' => 'en_US' ) );
		return $router;
	}
	function unavailable(): void {
		$c = Router::context();
		check( 503 === $GLOBALS['status'] && 503 === $c['status'] && $c['error'] && $c['noindex'], 'Failure must produce a controlled noindex 503.' );
		check( array() === $c['data'] && '' === $c['canonical'] && array() === $c['seo_alternates'] && '' === $c['x_default'], 'Failure retained content or claimed canonical/translation metadata.' );
		check( $GLOBALS['no_cache'] && str_contains( $GLOBALS['headers']['Cache-Control'], 'no-store' ) && str_contains( $GLOBALS['headers']['X-Robots-Tag'], 'noindex' ), 'Failure caching/index headers missing.' );
		ob_start(); ( new Seo() )->head(); $html = ob_get_clean();
		check( '' === $html, 'Failure emitted SEO or leaked an adapter exception.' );
	}
	// Real renderer exit is tested in isolated child PHP processes, still using only these stubs.
	if ( in_array( $argv[1] ?? '', array( 'sitemap-render-failure', 'sitemap-provider-failure' ), true ) ) {
		resetFixture(); $GLOBALS['fail_option'] = 'blog_public';
		register_shutdown_function( static function (): void {
			$output = ob_get_clean();
			check( 503 === $GLOBALS['status'] && $GLOBALS['no_cache'], 'Failed sitemap policy was presented as a successful or missing sitemap.' );
			check( 'Sitemap temporarily unavailable.' === $output && str_contains( $GLOBALS['headers']['Cache-Control'], 'no-store' ), 'Failed sitemap emitted partial XML, storage errors or cacheable output.' );
			echo "Sitemap failure response verified.\n";
		} );
		ob_start(); $sitemap = new Sitemap( new PublicCatalog(), new Editorial() );
		if ( 'sitemap-provider-failure' === $argv[1] ) {
			$sitemap->nativeProvider();
			check( isset( $GLOBALS['providers']['aish'] ), 'Unavailable provider policy was silently omitted from the native index.' );
			$GLOBALS['providers']['aish']->get_sitemap_entries();
		} else {
			( new \ReflectionProperty( $sitemap, 'request' ) )->setValue( $sitemap, array( 'kind' => 'index' ) );
			$sitemap->render();
		}
		throw new \RuntimeException( 'Expected renderer to terminate its response.' );
	}
	foreach ( array( 'route', 'exclusions', 'post' ) as $kind ) {
		resetFixture(); if ( 'post' === $kind ) { $GLOBALS['fail_meta'] = '_aish_seo_noindex'; } else { $GLOBALS['fail_option'] = 'aish_seo_settings'; }
		ob_start();
		try {
			match ( $kind ) { 'route' => SeoSettings::values( 'home', 'en_US' ), 'exclusions' => SeoSettings::excludedIds( 'skill', 'en_US' ), 'post' => SeoSettings::postValues( 9 ) };
			check( false, 'Failed storage silently became permission to index.' );
		} catch ( \RuntimeException $error ) { check( 'SEO storage is unavailable.' === $error->getMessage(), 'Raw native error escaped.' ); }
		check( '' === ob_get_clean() && ! $GLOBALS['wpdb']->suppressed, 'Native error printed or error mode was not restored.' );
		check( ! isset( $GLOBALS['cache']['options']['notoptions'] ) && ! isset( $GLOBALS['cache']['options']['alloptions'] ) && ! isset( $GLOBALS['cache']['post_meta'][9] ), 'Failed missing-value cache was retained.' );
		$GLOBALS['fail_meta'] = $GLOBALS['fail_option'] = '';
		check( SeoSettings::values( 'home', 'en_US' )['noindex'] && array( 5 ) === SeoSettings::excludedIds( 'skill', 'en_US' ) && SeoSettings::postValues( 9 )['noindex'], 'Retry lost saved noindex settings.' );
	}
	resetFixture(); $GLOBALS['options']['aish_seo_settings'] = array();
	check( ! SeoSettings::values( 'home', 'en_US' )['noindex'] && array() === SeoSettings::excludedIds( 'skill', 'en_US' ), 'Genuinely absent settings should retain defaults.' );
	foreach ( array( '0', '1' ) as $cached ) {
		resetFixture(); $GLOBALS['cache']['options']['alloptions']['blog_public'] = $cached; $GLOBALS['fail_option'] = 'blog_public'; $GLOBALS['wpdb']->last_error = 'Unrelated earlier query error';
		check( ( '1' === $cached ) === SeoSettings::indexableSite(), 'A valid native cache value was mistaken for a failed read.' );
	}
	resetFixture(); $GLOBALS['fail_option'] = 'blog_public';
	foreach ( array( 'allows', 'noindex', 'sitemap' ) as $boundary ) {
		try {
			match ( $boundary ) { 'allows' => Seo::allows( 'home', 'en_US' ), 'noindex' => Seo::noindex( array( 'status' => 200, 'route' => 'home', 'data' => array() ), array() ), 'sitemap' => Sitemap::enabled() };
			check( false, 'Failed site visibility silently allowed indexing or disabled its sitemap.' );
		} catch ( \RuntimeException $error ) { check( 'SEO storage is unavailable.' === $error->getMessage(), 'Site visibility storage details escaped.' ); }
	}
	$robots = ( new Seo() )->robotsTxt( "User-agent: *\nAllow: /\n", '1' );
	check( "User-agent: *\nDisallow: /\n" === $robots && 503 === $GLOBALS['status'] && $GLOBALS['no_cache'], 'Failed robots policy allowed crawling or claimed a successful response.' );
	resetFixture(); $GLOBALS['options']['aish_seo_settings'] = array(); $GLOBALS['fail_option'] = 'blog_public'; router()->resolve(); unavailable();
	foreach ( array( 'sitemap-render-failure', 'sitemap-provider-failure' ) as $scenario ) {
		$process = proc_open( array( PHP_BINARY, __FILE__, $scenario ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		check( is_resource( $process ), 'Could not start isolated renderer fixture.' );
		$output = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		check( 0 === proc_close( $process ) && "Sitemap failure response verified.\n" === $output && '' === $errors, 'Sitemap response fixture failed: ' . $output . $errors );
	}
	resetFixture(); $GLOBALS['fail_meta'] = '_aish_seo_title';
	$settings = ( new \ReflectionClass( SeoSettings::class ) )->newInstanceWithoutConstructor();
	ob_start(); $settings->metaBox( new WP_Post( 9 ) ); $html = ob_get_clean();
	check( 0 === $GLOBALS['nonce_fields'] && ! str_contains( $html, '<input' ) && ! str_contains( $html, 'SQL' ), 'Failed metabox exposed writable empty settings or database details.' );
	resetFixture(); $GLOBALS['fail_option'] = 'aish_seo_settings'; router()->resolve(); unavailable();
	foreach ( array( 'aish_frontend_alternates', 'aish_frontend_seo_enabled', 'aish_frontend_url' ) as $hook ) {
		resetFixture(); $GLOBALS['filters'][ $hook ] = static function (): never { throw new \RuntimeException( 'secret plugin details' ); };
		router()->resolve(); unavailable();
		$calls = $GLOBALS['filter_calls'][ $hook ];
		check( 'https://example.test/en/skills/' === View::url( 'skills', array(), 'en_US' ), 'Error page navigation failed to use a local language fallback.' );
		check( $calls === $GLOBALS['filter_calls'][ $hook ], 'Error navigation retried the broken adapter.' );
	}
	resetFixture(); $GLOBALS['filters']['aish_frontend_alternates'] = static fn() => 'bad-shape'; router()->resolve(); unavailable();
	foreach ( array( 'throws', 'invalid' ) as $case ) {
		resetFixture(); $GLOBALS['filters']['aish_frontend_request'] = 'throws' === $case ? static function (): never { throw new \RuntimeException( 'secret mapping failure' ); } : static fn() => new \stdClass();
		$_SERVER['REQUEST_URI'] = '/en/'; $wp = new WP(); $router = router(); $router->parse( $wp ); $router->resolve(); unavailable();
		check( ! isset( $wp->query_vars['p'] ), 'Failed mapping left a native content query enabled.' );
	}
	resetFixture(); $GLOBALS['filters']['aish_frontend_builtin_routing'] = static function (): never { throw new \RuntimeException( 'rewrite adapter failed' ); };
	$router = router(); $router->rewrites(); $router->refreshRewrites();
	check( 0 === $GLOBALS['rewrites'] && 0 === $GLOBALS['flushes'] && ! isset( $GLOBALS['options']['aish_frontend_routes_version'] ), 'Failed rewrite adapter was recorded as initialized.' );
	$router->resolve(); unavailable();
	foreach ( array( array(), 'javascript:alert(1)', 'https://user:pass@example.test/en/', "https://example.test/en/\n", 'https://example.test/en/#fragment' ) as $invalid ) {
		resetFixture(); $GLOBALS['filters']['aish_frontend_url'] = static fn() => $invalid;
		try { Router::url( 'home', array(), 'en_US' ); check( false, 'Invalid adapter URL accepted.' ); }
		catch ( \RuntimeException $error ) { check( 'Frontend URL adapter is unavailable.' === $error->getMessage(), 'URL adapter details leaked.' ); }
		check( 'https://example.test/en/' === Router::url( 'home', array(), 'en_US' ) && 1 === $GLOBALS['filter_calls']['aish_frontend_url'], 'Bad URL adapter was repeated during fallback.' );
	}
	resetFixture(); $GLOBALS['filters']['aish_frontend_url'] = static fn() => 'https://english.example.test/skills/';
	check( 'https://english.example.test/skills/' === Router::url( 'skills', array(), 'en_US' ), 'A valid language-domain URL adapter stopped working.' );
	resetFixture(); router()->resolve();
	$GLOBALS['filters']['aish_frontend_url'] = static function ( string $url, string $route ): string { if ( 'contact' === $route ) { throw new \RuntimeException( 'late adapter failure' ); } return $url; };
	check( 'https://example.test/en/contact/' === View::url( 'contact', array(), 'en_US' ), 'Late template adapter failure aborted page rendering.' );
	resetFixture(); $GLOBALS['filters']['aish_frontend_url'] = static function ( string $url, string $route ): string { if ( 'contact' === $route ) { throw new \RuntimeException( 'late submitted-message redirect failure' ); } return $url; };
	try { ( new \ReflectionMethod( Forms::class, 'redirect' ) )->invoke( null, 'en_US', 'contact', 'sent' ); }
	catch ( RedirectIntercept $expected ) {}
	check( array( 'https://example.test/en/contact/?form=sent', 303 ) === $GLOBALS['redirect'], 'A successful saved message was redirected as a failure.' );
	check( 1 === $GLOBALS['filter_calls']['aish_frontend_url'], 'Submitted-message redirect repeated a failed adapter.' );
	echo 'SEO and adapter failures: ' . $checks . " isolated assertions passed (no WordPress/MySQL/WPML integration).\n";
}
