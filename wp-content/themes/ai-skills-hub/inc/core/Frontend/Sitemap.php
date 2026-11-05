<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

defined( 'ABSPATH' ) || exit;

/** One bounded, uncached XML source, advertised through the active sitemap owner's index. */
final class Sitemap {
	public const PAGE_SIZE = 200;
	private const KINDS = array( 'skills', 'category', 'tag', 'tutorials', 'pages' );
	private const LOCALES = array( 'zh' => 'zh_CN', 'en' => 'en_US' );
	private ?array $request = null;
	private array $memo = array();

	public function __construct( private PublicCatalog $catalog, private Editorial $editorial ) {}

	public function register(): void {
		add_filter( 'query_vars', static fn( array $vars ): array => array_merge( $vars, array( 'aish_sitemap' ) ) );
		add_action( 'init', array( $this, 'rewrites' ) );
		add_action( 'parse_request', array( $this, 'parse' ), 1 );
		add_action( 'template_redirect', array( $this, 'render' ), -100 );
		add_filter( 'redirect_canonical', fn( $url ) => null !== $this->request ? false : $url );
		add_filter( 'posts_pre_query', function ( $posts, $query ) {
			if ( null !== $this->request && $query->is_main_query() ) { $query->found_posts = 0; $query->max_num_pages = 0; return array(); }
			return $posts;
		}, 1, 2 );
		add_filter( 'pre_handle_404', fn( $preempt ) => null !== $this->request ? true : $preempt );
		add_action( 'wp_sitemaps_init', array( $this, 'nativeProvider' ) );
		add_filter( 'wpseo_sitemap_index', fn( string $xml ): string => $this->pluginIndex( $xml ) );
		add_filter( 'rank_math/sitemap/index', fn( string $xml ): string => $this->pluginIndex( $xml ) );
		// Plugin XML cache hits bypass index/entry hooks; custom-table changes do not
		// trigger their native-post invalidation. Keep this dynamic projection fresh.
		add_filter( 'rank_math/sitemap/enable_caching', static fn( $enabled ): bool => false, PHP_INT_MAX );
		add_filter( 'wpseo_enable_xml_sitemap_transient_caching', static fn( $enabled ): bool => false, PHP_INT_MAX );
		// Managed pages are represented by their real bilingual Hub URL, never their native duplicate.
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'nativePostArguments' ), 10, 2 );
		add_filter( 'wpseo_sitemap_entry', array( $this, 'pluginPostEntry' ), 10, 3 );
		add_filter( 'rank_math/sitemap/entry', array( $this, 'pluginPostEntry' ), 10, 3 );
	}

	public static function enabled(): bool {
		return SeoSettings::indexableSite() && (bool) apply_filters( 'aish_sitemaps_enabled', true );
	}

	/** Detect a plugin owner only to avoid registering a parallel native provider. */
	public static function owner(): string {
		if ( defined( 'WPSEO_VERSION' ) ) { return 'yoast'; }
		if ( defined( 'RANK_MATH_VERSION' ) ) { return 'rankmath'; }
		return 'wordpress';
	}

	public static function url(): string { return Router::siteUrl( '/aish-sitemap.xml' ); }

	public function rewrites(): void {
		add_rewrite_rule( '^aish-sitemap(?:-(?:skills|category|tag|tutorials|pages)-(?:zh|en)-[1-9][0-9]*)?\.xml$', 'index.php?aish_sitemap=1', 'top' );
	}

	/** The same parser also works before the next administrator rewrite refresh. */
	public function parse( \WP $wp ): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		$path = trim( (string) $wp->request, '/' );
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$uriPath = is_string( $uri ) ? wp_parse_url( $uri, PHP_URL_PATH ) : null;
		$homePath = rtrim( (string) wp_parse_url( Router::siteUrl( '/' ), PHP_URL_PATH ), '/' );
		if ( is_string( $uriPath ) && strlen( $uriPath ) <= 4096 && ( '' === $homePath || str_starts_with( $uriPath, $homePath . '/' ) ) ) { $path = trim( substr( $uriPath, strlen( $homePath ) ), '/' ); }
		$path = preg_replace( '#^index\.php(?:/|$)#', '', $path );
		if ( 'aish-sitemap.xml' === $path ) { $this->request = array( 'kind' => 'index' ); }
		elseif ( preg_match( '/^aish-sitemap-(skills|category|tag|tutorials|pages)-(zh|en)-([1-9][0-9]{0,6})\.xml$/D', $path, $matches ) ) {
			$this->request = array( 'kind' => $matches[1], 'locale' => self::LOCALES[ $matches[2] ], 'page' => (int) $matches[3] );
		} elseif ( str_starts_with( $path, 'aish-sitemap' ) ) { $this->request = array( 'kind' => 'invalid' ); }
		if ( null !== $this->request ) { $wp->query_vars = array( 'aish_sitemap' => 1 ); }
	}

	/** Core's provider only advertises our canonical XML files; it creates no parallel URL sets. */
	public function nativeProvider(): void {
		if ( self::owner() !== 'wordpress' || ! class_exists( '\WP_Sitemaps_Provider' ) ) { return; }
		try { if ( ! self::enabled() ) { return; } }
		catch ( \Throwable $error ) {
			// Register the lazy provider so the index request reports 503 on a persistent
			// policy failure; silently omitting it would advertise an incomplete 200 index.
		}
		$provider = new class( $this ) extends \WP_Sitemaps_Provider {
			public function __construct( private Sitemap $source ) { $this->name = 'aish'; $this->object_type = 'aish'; }
			public function get_url_list( $page_num, $object_subtype = '' ) { return array(); }
			public function get_max_num_pages( $object_subtype = '' ) { return 0; }
			public function get_sitemap_entries() { return $this->source->advertisedEntries(); }
		};
		wp_register_sitemap_provider( 'aish', $provider );
	}

	public function nativePostArguments( array $args, string $type ): array {
		if ( 'page' !== $type ) { return $args; }
		$exclude = array( 'relation' => 'OR', array( 'key' => '_aish_page_kind', 'compare' => 'NOT EXISTS' ), array( 'key' => '_aish_page_kind', 'value' => array( 'about', 'contact', 'submit' ), 'compare' => 'NOT IN' ) );
		$args['meta_query'] = empty( $args['meta_query'] ) ? array( $exclude ) : array( 'relation' => 'AND', $args['meta_query'], $exclude );
		return $args;
	}

	public function pluginPostEntry( mixed $url, mixed $type, mixed $object ): mixed {
		// Yoast supplies WP_Post; Rank Math's public entry hook supplies a database object.
		if ( 'post' === $type && is_object( $object ) && isset( $object->ID, $object->post_type ) && is_scalar( $object->ID ) && preg_match( '/^[1-9][0-9]*$/D', (string) $object->ID ) && 'page' === $object->post_type && in_array( get_post_meta( (int) $object->ID, '_aish_page_kind', true ), array( 'about', 'contact', 'submit' ), true ) ) { return false; }
		return $url;
	}

	private function count( string $kind, string $locale ): int {
		$key = $kind . ':' . $locale;
		if ( isset( $this->memo['count'][ $key ] ) ) { return $this->memo['count'][ $key ]; }
		return $this->memo['count'][ $key ] = match ( $kind ) {
			'tutorials' => $this->editorial->sitemapCount( $locale ),
			'pages' => count( $this->staticItems( $locale ) ),
			default => $this->catalog->sitemapCount( 'skills' === $kind ? 'skill' : $kind, $locale ),
		};
	}

	/** Bounded number of child files; no timestamp is fabricated for generated collection pages. */
	public function indexEntries(): array {
		if ( ! self::enabled() ) { return array(); }
		if ( isset( $this->memo['index'] ) ) { return $this->memo['index']; }
		$entries = array();
		foreach ( self::LOCALES as $language => $locale ) {
			foreach ( self::KINDS as $kind ) {
				$pages = (int) ceil( $this->count( $kind, $locale ) / self::PAGE_SIZE );
				if ( count( $entries ) + $pages > 50000 ) { throw new \RuntimeException( 'Sitemap index is too large.' ); }
				for ( $page = 1; $page <= $pages; ++$page ) { $entries[] = array( 'loc' => Router::siteUrl( '/aish-sitemap-' . $kind . '-' . $language . '-' . $page . '.xml' ) ); }
			}
		}
		return $this->memo['index'] = $entries;
	}

	private function staticItems( string $locale ): array {
		if ( isset( $this->memo['static'][ $locale ] ) ) { return $this->memo['static'][ $locale ]; }
		$items = array();
		foreach ( array( 'home', 'skills', 'tutorials', 'about', 'contact' ) as $route ) {
			$settings = SeoSettings::values( $route, $locale );
			if ( ! empty( $settings['noindex'] ) ) { continue; }
			$item = array( 'loc' => Router::url( $route, array(), $locale ) );
			if ( in_array( $route, array( 'about', 'contact' ), true ) ) {
				$post = $this->editorial->page( $route, $locale );
				if ( ! $post || ! self::hasText( $post['content'] ?? '' ) || ! self::hasText( $post['title'] ?? '' ) || ! empty( SeoSettings::postValues( (int) $post['id'] )['noindex'] ) ) { continue; }
				$lastmod = self::lastmod( (string) ( $post['updated_at'] ?? '' ) );
				if ( null !== $lastmod ) { $item['lastmod'] = $lastmod; }
			}
			$items[] = $item;
		}
		return $this->memo['static'][ $locale ] = $items;
	}

	private static function hasText( string $value ): bool {
		$plain = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return 1 === preg_match( '/[^\s\p{Z}]/u', $plain );
	}

	public function urlEntries( string $kind, string $locale, int $page ): array {
		if ( ! self::enabled() || ! in_array( $kind, self::KINDS, true ) || ! in_array( $locale, self::LOCALES, true ) || $page < 1 || $page > 1000000 ) { return array(); }
		if ( $page > (int) ceil( $this->count( $kind, $locale ) / self::PAGE_SIZE ) ) { return array(); }
		if ( 'pages' === $kind ) { return array_slice( $this->staticItems( $locale ), ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ); }
		$rows = 'tutorials' === $kind ? $this->editorial->sitemapItems( $locale, $page, self::PAGE_SIZE ) : $this->catalog->sitemapItems( 'skills' === $kind ? 'skill' : $kind, $locale, $page, self::PAGE_SIZE );
		$entries = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$route = match ( $kind ) { 'skills' => 'skill', 'tutorials' => 'tutorial', default => $kind };
			// The SQL projection excludes noindex before counting/paging; this check is a second boundary.
			if ( 'tutorials' !== $kind && ! empty( SeoSettings::values( $route, $locale, $id )['noindex'] ) ) { continue; }
			$item = array( 'loc' => Router::url( $route, array( 'id' => $id, 'slug' => (string) ( $row['slug'] ?? '' ) ), $locale ) );
			$lastmod = self::lastmod( (string) ( $row['updated_at'] ?? '' ) );
			if ( null !== $lastmod ) { $item['lastmod'] = $lastmod; }
			$entries[] = $item;
		}
		return $entries;
	}

	public static function lastmod( string $value ): ?string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value || $value < '1970-01-01 00:00:00' ) { return null; }
		return $date->format( 'Y-m-d\TH:i:s\Z' );
	}

	private static function xml( string $value ): string { return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }

	public function indexEntriesXml(): string {
		$xml = '';
		foreach ( $this->indexEntries() as $entry ) { $xml .= '<sitemap><loc>' . self::xml( $entry['loc'] ) . '</loc></sitemap>' . "\n"; }
		return $xml;
	}

	/** Third-party index hooks must fail closed too, rather than emitting a successful incomplete index. */
	public function advertisedEntries(): array {
		try { return $this->indexEntries(); }
		catch ( \Throwable $error ) { $this->unavailable(); }
	}

	private function pluginIndex( string $xml ): string {
		try {
			// Either plugin may actually render the shared endpoint. Both public hooks
			// can run on one buffer, so append each exact XML location only once.
			preg_match_all( '/<loc>([^<]*)<\/loc>/', $xml, $matches );
			$seen = array_fill_keys( $matches[1], true );
			foreach ( $this->indexEntries() as $entry ) {
				$location = self::xml( $entry['loc'] );
				if ( isset( $seen[ $location ] ) ) { continue; }
				$xml .= '<sitemap><loc>' . $location . '</loc></sitemap>' . "\n";
				$seen[ $location ] = true;
			}
			return $xml;
		}
		catch ( \Throwable $error ) { $this->unavailable(); }
	}

	private function unavailable(): never {
		status_header( 503 );
		nocache_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'Retry-After: 60' );
		header( 'X-Robots-Tag: noindex, follow', true );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'Sitemap temporarily unavailable.';
		exit;
	}

	/** Build the whole bounded response before writing bytes, so storage errors cannot produce partial XML. */
	public function render(): void {
		if ( null === $this->request ) { return; }
		$status = 200;
		$xml = '';
		try {
			if ( ! self::enabled() || 'invalid' === $this->request['kind'] ) { $status = 404; }
			elseif ( 'index' === $this->request['kind'] ) { $xml = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $this->indexEntriesXml() . '</sitemapindex>'; }
			else {
				$entries = $this->urlEntries( $this->request['kind'], $this->request['locale'], $this->request['page'] );
				if ( ! $entries ) { $status = 404; }
				else {
					$xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
					foreach ( $entries as $entry ) { $xml .= '<url><loc>' . self::xml( $entry['loc'] ) . '</loc>' . ( isset( $entry['lastmod'] ) ? '<lastmod>' . self::xml( $entry['lastmod'] ) . '</lastmod>' : '' ) . '</url>' . "\n"; }
					$xml .= '</urlset>';
				}
			}
		} catch ( \Throwable $error ) { $status = 503; $xml = ''; }
		status_header( $status );
		nocache_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex, follow', true );
		if ( 200 !== $status ) {
			header( 'Content-Type: text/plain; charset=UTF-8' );
			if ( 503 === $status ) { header( 'Retry-After: 60' ); }
			echo 503 === $status ? 'Sitemap temporarily unavailable.' : 'Sitemap not found.';
		} else { header( 'Content-Type: application/xml; charset=UTF-8' ); echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml; }
		exit;
	}
}
