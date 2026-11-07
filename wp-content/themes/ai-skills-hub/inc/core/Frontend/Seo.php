<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

defined( 'ABSPATH' ) || exit;

/** One metadata owner for Hub SSR routes; other WordPress pages retain their SEO plugin. */
final class Seo {
	public function register(): void {
		add_action( 'wp_head', array( $this, 'head' ), 2 );
		add_filter( 'wp_robots', array( $this, 'robots' ), PHP_INT_MAX );
		add_filter( 'pre_get_document_title', static fn( string $title ): string => self::active() ? Router::context()['title'] : $title, PHP_INT_MAX );
		// Yoast's public presenter API: do not duplicate metadata on our virtual routes.
		add_filter( 'wpseo_frontend_presenters', static fn( array $presenters ): array => self::active() ? array() : $presenters, PHP_INT_MAX );
		add_filter( 'wpml_hreflangs', static fn( $links ) => self::active() ? array() : $links, PHP_INT_MAX );
		add_filter( 'robots_txt', array( $this, 'robotsTxt' ), PHP_INT_MAX, 2 );
		foreach ( array( 'admin_init', 'login_init' ) as $hook ) {
			add_action( $hook, static function (): void { if ( ! headers_sent() ) { header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); } }, 0 );
		}
	}

	private static function active(): bool { return ! empty( Router::context()['seo_managed'] ); }

	/** Called only after the router has validated public content and completed resolution. */
	public static function prepare( array $context, array $query ): array {
		$route = $context['route']; $locale = $context['locale']; $data = $context['data'];
		$id = (int) ( $data['skill']['id'] ?? $data['term']['id'] ?? 0 );
		$config = self::settings( $route, $locale, $id );
		$postId = (int) ( $data['tutorial']['id'] ?? $data['page']['id'] ?? 0 );
		if ( $postId ) {
			$post = SeoSettings::postValues( $postId );
			foreach ( array( 'title', 'description', 'image' ) as $key ) { if ( '' !== $post[ $key ] ) { $config[ $key ] = $post[ $key ]; } }
			$config['noindex'] = $config['noindex'] || $post['noindex'];
		}
		if ( 200 === $context['status'] && 'skill' !== $route && '' !== $config['title'] ) { $context['title'] = $config['title']; }
		if ( 200 === $context['status'] && 'skill' !== $route && '' !== $config['description'] ) { $context['description'] = $config['description']; }
		$page = (int) ( $data['filters']['page'] ?? 1 );
		if ( $page > 1 ) { $context['title'] .= ' · ' . ( 'zh_CN' === $locale ? '第 ' . $page . ' 页' : 'Page ' . $page ); }
		$context['title'] = wp_strip_all_tags( $context['title'] );
		$context['description'] = wp_strip_all_tags( $context['description'] );
		$context['noindex'] = self::noindex( $context, $query, $config['noindex'] );
		$context['image'] = $config['image'];
		$context['og_type'] = in_array( $route, array( 'skill', 'tutorial' ), true ) ? 'article' : 'website';
		$context['seo_managed'] = (bool) apply_filters( 'aish_frontend_seo_enabled', true, $context );
		// Extensions may adjust existing URLs, but cannot invent a missing translation.
		$adjusted = apply_filters( 'aish_frontend_alternates', $context['alternates'], $context );
		if ( ! is_array( $adjusted ) ) { throw new \RuntimeException( 'Frontend language links are unavailable.' ); }
		$alternates = array();
		if ( ! $context['noindex'] && 1 === $page ) {
			foreach ( $context['alternates'] as $language => $url ) {
				$otherId = 'tutorial' === $route ? (int) ( $context['alternate_ids'][ $language ] ?? 0 ) : $id;
				if ( ! in_array( $language, array( 'zh_CN', 'en_US' ), true ) || ! self::allows( $route, $language, $otherId ) ) { continue; }
				$candidate = $adjusted[ $language ] ?? '';
				if ( is_string( $candidate ) && self::validUrl( $candidate ) ) { $alternates[ $language ] = $candidate; }
			}
			// The current URL is always self referencing and uses its own locale.
			if ( $context['canonical'] && empty( $data['term']['translation_missing'] ) ) { $alternates[ $locale ] = $context['canonical']; }
		}
		$context['seo_alternates'] = $alternates;
		$context['x_default'] = $alternates[ Router::defaultLocale() ] ?? ( $alternates ? reset( $alternates ) : '' );
		return $context;
	}

	public static function allows( string $route, string $locale, int $id = 0 ): bool {
		if ( ! SeoSettings::indexableSite() || self::settings( $route, $locale, $id )['noindex'] ) { return false; }
		return 'tutorial' !== $route || ( $id > 0 && ! SeoSettings::postValues( $id )['noindex'] );
	}

	private static function settings( string $route, string $locale, int $id ): array {
		if ( in_array( $route, array( 'home', 'skills', 'tutorials', 'help', 'about', 'contact', 'submit' ), true ) ) { return SeoSettings::values( $route, $locale ); }
		if ( $id > 0 && in_array( $route, array( 'skill', 'category', 'tag' ), true ) ) { return SeoSettings::values( $route, $locale, $id ); }
		return SeoSettings::defaults();
	}

	/** Search/filter URLs stay crawlable so crawlers can actually see the noindex directive. */
	public static function noindex( array $context, array $query, bool $configured = false ): bool {
		if ( $configured || ! SeoSettings::indexableSite() || 200 !== $context['status'] || in_array( $context['route'], array( 'search', 'submit', '404' ), true ) ) { return true; }
		if ( in_array( $context['route'], array( 'about', 'contact' ), true ) && empty( $context['data']['page'] ) ) { return true; }
		if ( 'help' === $context['route'] && empty( $context['data']['help']['items'] ) ) { return true; }
		if ( isset( $context['data']['term'] ) && ( (int) ( $context['data']['term']['count'] ?? 0 ) < 1 || ! empty( $context['data']['term']['translation_missing'] ) ) ) { return true; }
		if ( 'discovery' === $context['route'] && ( ( isset( $context['data']['result'] ) && (int) ( $context['data']['result']['total'] ?? 0 ) < 1 ) || ( isset( $context['data']['topics'] ) && ! $context['data']['topics'] ) ) ) { return true; }
		foreach ( $query as $key => $value ) {
			if ( 'page' === $key && isset( $context['data']['filters'] ) && is_scalar( $value ) && preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) { continue; }
			// Includes form outcomes, previews, sort/default parameters and unknown URL combinations.
			return true;
		}
		return false;
	}

	public static function sendHeaders( array $context ): void {
		if ( $context['noindex'] && ! headers_sent() ) { header( 'X-Robots-Tag: noindex, follow, noarchive' ); }
		if ( ! empty( $context['seo_managed'] ) ) {
			// Yoast removes this native renderer; Hub ownership also suppresses its title presenter.
			add_action( 'wp_head', '_wp_render_title_tag', 1 );
			// Rank Math exposes its frontend render action publicly. Limit ownership to Hub routes.
			remove_all_actions( 'rank_math/head' );
			remove_action( 'wp_head', 'rel_canonical' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		}
	}

	public function robots( array $robots ): array {
		if ( ! self::active() ) { return $robots; }
		if ( Router::context()['noindex'] ) { unset( $robots['index'], $robots['nofollow'] ); $robots['noindex'] = true; $robots['follow'] = true; $robots['noarchive'] = true; }
		return $robots;
	}

	private static function validUrl( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	public function head(): void {
		if ( ! self::active() ) { return; }
		$c = Router::context();
		if ( $c['description'] ) { echo '<meta name="description" content="' . esc_attr( $c['description'] ) . '">' . "\n"; }
		if ( $c['canonical'] ) { echo '<link rel="canonical" href="' . esc_url( $c['canonical'] ) . '">' . "\n"; }
		foreach ( $c['seo_alternates'] as $locale => $url ) { echo '<link rel="alternate" hreflang="' . ( 'zh_CN' === $locale ? 'zh-CN' : 'en' ) . '" href="' . esc_url( $url ) . '">' . "\n"; }
		if ( $c['x_default'] ) { echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $c['x_default'] ) . '">' . "\n"; }
		if ( 200 !== $c['status'] ) { return; }
		$tags = array( 'og:title' => $c['title'], 'og:description' => $c['description'], 'og:url' => $c['canonical'], 'og:type' => $c['og_type'], 'og:site_name' => 'AI Skills Hub', 'og:locale' => $c['locale'] );
		if ( $c['image'] ) { $tags['og:image'] = $c['image']; }
		foreach ( $tags as $property => $value ) { if ( $value ) { echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $value ) . '">' . "\n"; } }
		foreach ( $c['seo_alternates'] as $locale => $url ) { if ( $locale !== $c['locale'] ) { echo '<meta property="og:locale:alternate" content="' . esc_attr( $locale ) . '">' . "\n"; } }
		$twitter = array( 'card' => $c['image'] ? 'summary_large_image' : 'summary', 'title' => $c['title'], 'description' => $c['description'], 'image' => $c['image'] );
		foreach ( $twitter as $name => $value ) { if ( $value ) { echo '<meta name="twitter:' . esc_attr( $name ) . '" content="' . esc_attr( $value ) . '">' . "\n"; } }
		$schema = self::structuredData( $c );
		if ( $schema ) {
			$json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( false !== $json ) { echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; }
		}
	}

	/** Describe only the current verified public page; never infer authors, ratings or safety. */
	private static function structuredData( array $context ): array {
		if ( $context['noindex'] || ! $context['canonical'] ) { return array(); }
		$locale = $context['locale']; $zh = 'zh_CN' === $locale; $canonical = $context['canonical'];
		try {
			$home = Router::url( 'home', array(), $locale );
			$items = array( array( $zh ? '首页' : 'Home', $home ) );
			if ( in_array( $context['route'], array( 'skill', 'category', 'tag' ), true ) ) { $items[] = array( 'Skills', Router::url( 'skills', array(), $locale ) ); }
			if ( 'tutorial' === $context['route'] ) { $items[] = array( $zh ? '教程' : 'Tutorials', Router::url( 'tutorials', array(), $locale ) ); }
		} catch ( \Throwable $error ) { return array(); }
		$language = $zh ? 'zh-CN' : 'en';
		$website = array( '@type' => 'WebSite', '@id' => $home . '#website', 'url' => $home, 'name' => 'AI Skills Hub', 'inLanguage' => $language );
		$page = array( '@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => $context['title'], 'inLanguage' => $language, 'isPartOf' => array( '@id' => $website['@id'] ) );
		if ( $context['description'] ) { $page['description'] = $context['description']; }
		$graph = array( $website );
		if ( 'home' !== $context['route'] ) {
			$title = $context['data']['discovery_title'] ?? $context['data']['skill']['title'] ?? $context['data']['tutorial']['title'] ?? $context['data']['term']['name'] ?? $context['data']['page']['title'] ?? Strings::get( 'page.' . $context['route'] . '.title', $locale );
			$items[] = array( $title, $canonical );
			$breadcrumbs = array( '@type' => 'BreadcrumbList', '@id' => $canonical . '#breadcrumb', 'itemListElement' => array() );
			foreach ( $items as $position => list( $label, $url ) ) { $breadcrumbs['itemListElement'][] = array( '@type' => 'ListItem', 'position' => $position + 1, 'name' => wp_strip_all_tags( $label ), 'item' => $url ); }
			$page['breadcrumb'] = array( '@id' => $breadcrumbs['@id'] );
			$graph[] = $breadcrumbs;
		}
		$graph[] = $page;
		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	public function robotsTxt( string $output, bool|string $public ): string {
		try {
			if ( ! (bool) $public || ! SeoSettings::indexableSite() ) { return "User-agent: *\nDisallow: /\n"; }
			// Some plugins replace WordPress' group; retain its administrative crawl policy.
			$adminPath = (string) wp_parse_url( site_url( '/wp-admin/' ), PHP_URL_PATH );
			$required = array( 'Disallow: ' . $adminPath, 'Allow: ' . $adminPath . 'admin-ajax.php' );
			$missing = array_filter( $required, static fn( string $rule ): bool => ! preg_match( '/^' . preg_quote( $rule, '/' ) . '\s*$/mi', $output ) );
			if ( $missing ) { $output = rtrim( $output ) . "\n\nUser-agent: *\n" . implode( "\n", $missing ) . "\n"; }
			// Keep WordPress/plugin directives and do not block query URLs that carry noindex.
			if ( ! preg_match( '/^Sitemap:/mi', $output ) && class_exists( Sitemap::class ) && Sitemap::enabled() ) { $output = rtrim( $output ) . "\nSitemap: " . Sitemap::url() . "\n"; }
			return $output;
		} catch ( \Throwable $error ) {
			status_header( 503 ); nocache_headers();
			if ( ! headers_sent() ) { header( 'Cache-Control: no-store, max-age=0' ); header( 'Retry-After: 60' ); }
			return "User-agent: *\nDisallow: /\n";
		}
	}
}
