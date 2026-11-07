<?php
declare(strict_types=1);
// Isolated XML/pagination contracts only: no WordPress bootstrap, SQL or plugin runtime.
namespace AISkillsHub\Core\Frontend {
	final class PublicCatalog {
		public array $counts = array();
		public array $rows = array();
		public array $calls = array();
		public function sitemapCount( string $kind, string $locale ): int { $this->calls[] = array( $kind, $locale ); return $this->counts[ $kind ][ $locale ] ?? 0; }
		public function sitemapItems( string $kind, string $locale, int $page, int $perPage ): array { return array_slice( $this->rows[ $kind ][ $locale ] ?? array(), ( $page - 1 ) * $perPage, $perPage ); }
	}
	final class Editorial {
		public array $pages = array();
		public array $rows = array();
		public array $help = array();
		public function tutorials( string $locale, array $filters ): array { return array( 'items' => $this->help[ $locale ] ?? array() ); }
		public function sitemapCount( string $locale ): int { return count( $this->rows[ $locale ] ?? array() ); }
		public function sitemapItems( string $locale, int $page, int $perPage ): array { return array_slice( $this->rows[ $locale ] ?? array(), ( $page - 1 ) * $perPage, $perPage ); }
		public function page( string $route, string $locale ): ?array { return $this->pages[ $route ][ $locale ] ?? null; }
	}
	final class SeoSettings {
		public static array $settings = array();
		public static array $posts = array();
		public static function indexableSite(): bool { return '1' === (string) \get_option( 'blog_public', '1' ); }
		public static function values( string $route, string $locale, int $id = 0 ): array { return self::$settings[ $route ][ $locale ][ $id ] ?? array(); }
		public static function postValues( int $id ): array { return self::$posts[ $id ] ?? array(); }
	}
	final class Router {
		public static function siteUrl( string $path = '/' ): string { return \home_url( $path ); }
		public static function url( string $route, array $args, string $locale ): string {
			return \home_url( '/' . ( 'zh_CN' === $locale ? 'zh' : 'en' ) . '/' . $route . '/' . ( $args ? (string) ( $args['id'] ?? '' ) . '/' . rawurlencode( (string) ( $args['slug'] ?? '' ) ) : '' ) );
		}
	}
}
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	final class WP { public string $request = ''; public array $query_vars = array(); }
	final class WP_Post { public int $ID = 1; public string $post_type = 'page'; }
	function is_admin(): bool { return false; }
	function home_url( string $path ): string { return 'https://example.test' . ( $GLOBALS['home_path'] ?? '' ) . $path; }
	function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
	function get_post_meta( int $id, string $key, bool $single = true ): mixed { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted = 1 ): void { $GLOBALS['hooks'][ $hook ][ $priority ][] = $callback; }
	function add_action( mixed ...$args ): void {}
	function apply_filters( string $hook, mixed $value ): mixed { $priorities = $GLOBALS['hooks'][ $hook ] ?? array(); ksort( $priorities ); foreach ( $priorities as $callbacks ) { foreach ( $callbacks as $callback ) { $value = $callback( $value ); } } return $value; }
	function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Frontend/Sitemap.php';

	use AISkillsHub\Core\Frontend\Sitemap;
	use AISkillsHub\Core\Frontend\PublicCatalog;
	use AISkillsHub\Core\Frontend\Editorial;
	use AISkillsHub\Core\Frontend\SeoSettings;
	$count = 0;
	function check( bool $condition, string $message ): void { global $count; if ( ! $condition ) { throw new \RuntimeException( $message ); } ++$count; }
	$catalog = new PublicCatalog();
	$editorial = new Editorial();
	$catalog->counts = array( 'skill' => array( 'en_US' => 401 ), 'category' => array( 'zh_CN' => 1 ) );
	$catalog->rows['category']['zh_CN'] = array( array( 'id' => 9, 'slug' => 'real-category', 'updated_at' => '2026-01-01 01:02:03' ) );
	$editorial->rows['en_US'] = array( array( 'id' => 15, 'updated_at' => '2026-01-02 03:04:05' ) );
	$editorial->pages['about']['zh_CN'] = array( 'id' => 20, 'title' => '关于', 'content' => '<p>&nbsp;</p>', 'updated_at' => '2026-01-01 01:02:03' );
	$editorial->pages['contact']['en_US'] = array( 'id' => 21, 'title' => 'Contact', 'content' => '<p>Contact details</p>', 'updated_at' => '2026-01-01 01:02:03' );
	$sitemap = new Sitemap( $catalog, $editorial );
	$index = $sitemap->indexEntries();
	check( count( $index ) === 7, '401 Skills must create three 200-item chunks, not truncate.' );
	$links = array_column( $index, 'loc' );
	check( in_array( 'https://example.test/aish-sitemap-skills-en-3.xml', $links, true ), 'Final partial Skills chunk missing.' );
	check( ! in_array( 'https://example.test/aish-sitemap-skills-zh-1.xml', $links, true ), 'Missing language should not create a Skills sitemap.' );
	check( ! str_contains( $sitemap->indexEntriesXml(), '<lastmod>' ), 'Index must not fabricate a lastmod.' );
	$sitemap->indexEntries();
	check( count( $catalog->calls ) === 6, 'Duplicate index requests must reuse request-local counts.' );
	$pagesZh = $sitemap->urlEntries( 'pages', 'zh_CN', 1 );
	check( count( $pagesZh ) === 3, 'Blank/missing localized pages must not be indexed.' );
	$pagesEn = $sitemap->urlEntries( 'pages', 'en_US', 1 );
	check( count( $pagesEn ) === 4, 'Real Contact content must be included.' );
	check( ! isset( $pagesEn[0]['lastmod'] ), 'Generated home must not pretend to have modified content today.' );
	check( $pagesEn[3]['lastmod'] === '2026-01-01T01:02:03Z', 'Persisted page lastmod must use UTC.' );
	$terms = $sitemap->urlEntries( 'category', 'zh_CN', 1 );
	check( count( $terms ) === 1 && str_contains( $terms[0]['loc'], '/zh/category/' ), 'Category language URL incorrect.' );
	$tutorials = $sitemap->urlEntries( 'tutorials', 'en_US', 1 );
	check( count( $tutorials ) === 1 && str_contains( $tutorials[0]['loc'], '/en/tutorial/15/' ), 'Tutorial ID was lost.' );
	foreach ( array( array('skills','fr_FR',1), array('search','en_US',1), array('skills','en_US',0), array('skills','en_US',4), array('pages','en_US',2) ) as $arguments ) { check( $sitemap->urlEntries( ...$arguments ) === array(), 'Invalid/missing sitemap page should be empty.' ); }
	SeoSettings::$settings['home']['zh_CN'][0] = array( 'noindex' => true );
	SeoSettings::$posts[21] = array( 'noindex' => true );
	$withNoindex = new Sitemap( $catalog, $editorial );
	check( count( $withNoindex->urlEntries( 'pages', 'zh_CN', 1 ) ) === 2, 'Locale-specific noindex ignored.' );
	check( count( $withNoindex->urlEntries( 'pages', 'en_US', 1 ) ) === 3, 'Native page noindex ignored.' );
	check( Sitemap::lastmod( '2024-02-29 23:59:59' ) === '2024-02-29T23:59:59Z', 'Valid leap-year date rejected.' );
	foreach ( array( '', '0000-00-00 00:00:00', '2026-02-30 10:00:00', '2026-01-01', '<script>' ) as $invalid ) { check( null === Sitemap::lastmod( $invalid ), 'Invalid lastmod accepted.' ); }
	$nativeArgs = $sitemap->nativePostArguments( array( 'meta_query' => array( 'key' => 'custom', 'value' => 'original' ) ), 'page' );
	check( $nativeArgs['meta_query'][0]['value'] === 'original', 'Existing native sitemap conditions were overwritten.' );
	check( $nativeArgs['meta_query'][1][1]['compare'] === 'NOT IN', 'Managed native page URLs were not excluded.' );
	check( $sitemap->nativePostArguments( array( 'custom' => 1 ), 'post' ) === array( 'custom' => 1 ), 'Unrelated native posts changed.' );
	$post = new WP_Post();
	$GLOBALS['post_meta'][1]['_aish_page_kind'] = 'contact';
	check( false === $sitemap->pluginPostEntry( array( 'loc' => 'native-url' ), 'post', $post ), 'Plugin duplicate managed page must be excluded.' );
	$rawPost = (object) array( 'ID' => '1', 'post_type' => 'page' );
	check( false === $sitemap->pluginPostEntry( 'native-url', 'post', $rawPost ), 'Rank Math database post object follows the same managed-page exclusion.' );
	foreach ( array( array( 'user', $rawPost ), array( 'post', (object) array( 'ID' => 0, 'post_type' => 'page' ) ), array( 'post', (object) array( 'ID' => array( 1 ), 'post_type' => 'page' ) ), array( 'post', (object) array( 'ID' => 1 ) ), array( 'post', array( 'ID' => 1, 'post_type' => 'page' ) ) ) as $entry ) { check( 'keep' === $sitemap->pluginPostEntry( 'keep', ...$entry ), 'Unrelated or malformed plugin entry must remain untouched.' ); }
	$post->post_type = 'post';
	check( 'keep' === $sitemap->pluginPostEntry( 'keep', 'post', $post ), 'Plugin unrelated post must be preserved.' );
	foreach ( array( '/aish-sitemap.xml' => 'index', '/aish-sitemap-skills-en-1.xml' => 'skills', '/aish-sitemap-skills-en-0.xml' => 'invalid', '/aish-sitemap-skills-fr-1.xml' => 'invalid', '/aish-sitemap-skills-en-99999999.xml' => 'invalid' ) as $path => $kind ) {
		$parser = new Sitemap( $catalog, $editorial );
		$wp = new WP(); $wp->query_vars = array( 'p' => 123 ); $_SERVER['REQUEST_URI'] = $path; $parser->parse( $wp );
		$request = ( new ReflectionProperty( Sitemap::class, 'request' ) )->getValue( $parser );
		check( $request['kind'] === $kind && $wp->query_vars === array( 'aish_sitemap' => 1 ), 'Sitemap path parsing/query isolation failed.' );
	}
	$GLOBALS['home_path'] = '/site'; $_SERVER['REQUEST_URI'] = '/site/index.php/aish-sitemap-tutorials-zh-1.xml';
	$parser = new Sitemap( $catalog, $editorial ); $parser->parse( new WP() );
	check( ( new ReflectionProperty( Sitemap::class, 'request' ) )->getValue( $parser )['locale'] === 'zh_CN', 'Subdirectory/index.php routing failed.' );
	$GLOBALS['home_path'] = '/site&safe';
	$xml = ( new Sitemap( $catalog, $editorial ) )->indexEntriesXml();
	check( str_contains( $xml, 'site&amp;safe' ) && ! str_contains( $xml, 'site&safe' ), 'XML URL escaping failed.' );
	$GLOBALS['options']['blog_public'] = '0';
	check( ! Sitemap::enabled() && $sitemap->indexEntries() === array() && $sitemap->urlEntries('pages','en_US',1) === array(), 'Private-site policy must override cached public counts.' );
	$sitemap->register();
	foreach ( array( 'rank_math/sitemap/enable_caching', 'wpseo_enable_xml_sitemap_transient_caching' ) as $hook ) {
		add_filter( $hook, static fn( $enabled ): bool => true, 99 );
		check( false === apply_filters( $hook, true ), 'XML cache cannot bypass dynamic custom-table projection: ' . $hook );
	}
	$GLOBALS['options']['blog_public'] = '1';
	foreach ( array( array( 'wpseo_sitemap_index', 'rank_math/sitemap/index' ), array( 'rank_math/sitemap/index', 'wpseo_sitemap_index' ) ) as $indexHooks ) {
		$xml = apply_filters( $indexHooks[0], '<sitemap><loc>https://example.test/native.xml</loc></sitemap>' );
		check( str_contains( $xml, 'aish-sitemap-' ), 'Actual rendering plugin always receives Hub children.' );
		check( $xml === apply_filters( $indexHooks[1], $xml ) && $xml === apply_filters( $indexHooks[0], $xml ), 'Both hook orders and repeat calls preserve exact child uniqueness.' );
	}
	$helpEditorial = new Editorial(); $helpEditorial->help['en_US'] = array( array( 'id' => 90 ) );
	$helpMap = new Sitemap( new PublicCatalog(), $helpEditorial );
	check( in_array( 'https://example.test/site&safe/en/help/', array_column( $helpMap->urlEntries( 'pages', 'en_US', 1 ), 'loc' ), true ), 'Published help language should have its own index URL.' );
	check( ! in_array( 'https://example.test/site&safe/zh/help/', array_column( $helpMap->urlEntries( 'pages', 'zh_CN', 1 ), 'loc' ), true ), 'Missing help translations must not create a sitemap URL.' );
	echo 'PASS: ' . $count . " isolated Sitemap/XML assertions (PHP stubs; no WordPress/SQL/plugin integration).\n";
}
