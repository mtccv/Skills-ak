<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

defined( 'ABSPATH' ) || exit;

/** Public HTML routing; all Skill reads go through the restricted PublicCatalog. */
final class Router {
	private const ROUTES = array( 'home', 'skills', 'skill', 'category', 'tag', 'tutorials', 'tutorial', 'help', 'search', 'about', 'contact', 'submit', '404' );
	private static array $context = array( 'route' => '404', 'locale' => 'zh_CN', 'language' => 'zh', 'title' => 'AI Skills Hub', 'description' => '', 'canonical' => '', 'alternates' => array(), 'data' => array(), 'error' => false, 'status' => 404 );
	private static bool $adapterFailed = false;
	private bool $handled = false;
	private array $request = array();

	public function __construct( private PublicCatalog $catalog, private Editorial $editorial ) {}

	/** Shared presentation context also serves bounded Core extension routes. */
	public static function context(): array { return apply_filters( 'aish_frontend_context', self::$context ); }

	/** Site root before language plugins decorate home_url; adapters still own the final URL filter. */
	public static function siteUrl( string $path = '/' ): string {
		$base = get_option( 'home', '' );
		if ( ! is_string( $base ) || '' === $base ) { return home_url( $path ); }
		if ( function_exists( 'set_url_scheme' ) ) { $base = set_url_scheme( $base ); }
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	public function register(): void {
		add_action( 'init', array( $this, 'rewrites' ) );
		add_action( 'admin_init', array( $this, 'refreshRewrites' ) );
		add_filter( 'query_vars', static fn( array $vars ): array => array_merge( $vars, array( 'aish_route', 'aish_language' ) ) );
		add_action( 'parse_request', array( $this, 'parse' ), 20 );
		add_filter( 'posts_pre_query', function ( $posts, $query ) {
			if ( $this->handled && $query->is_main_query() ) { $query->found_posts = 0; $query->max_num_pages = 0; return array(); }
			return $posts;
		}, 10, 2 );
		add_filter( 'pre_handle_404', fn( $preempt ) => $this->handled ? true : $preempt );
		add_filter( 'redirect_canonical', fn( $url ) => $this->handled ? false : $url );
		add_action( 'template_redirect', array( $this, 'resolve' ), 0 );
		add_filter( 'template_include', fn( string $file ): string => $this->handled ? get_template_directory() . '/index.php' : $file );
		add_filter( 'pre_get_document_title', fn( string $title ): string => $this->handled ? self::$context['title'] : $title );
		add_filter( 'language_attributes', fn( string $attributes ): string => $this->handled ? 'lang="' . ( 'zh_CN' === self::$context['locale'] ? 'zh-CN' : 'en-US' ) . '" dir="ltr"' : $attributes );
	}

	public function rewrites(): void {
		try { if ( ! apply_filters( 'aish_frontend_builtin_routing', true ) ) { return; } }
		catch ( \Throwable $error ) { self::$adapterFailed = true; return; }
		// Request parsing below also supports newly activated themes before a rewrite flush.
		add_rewrite_rule( '^(zh|en)(?:/.*)?/?$', 'index.php?aish_language=$matches[1]&aish_route=frontend', 'top' );
	}

	public function refreshRewrites(): void {
		if ( ! current_user_can( 'manage_options' ) || self::$adapterFailed ) { return; }
		try { if ( ! apply_filters( 'aish_frontend_builtin_routing', true ) || '2' === get_option( 'aish_frontend_routes_version' ) ) { return; } }
		catch ( \Throwable $error ) { self::$adapterFailed = true; return; }
		// Only the former plain-permalink setup needs a structure; preserve an existing choice.
		if ( '' === (string) get_option( 'permalink_structure' ) ) { $GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' ); }
		$this->rewrites();
		if ( self::$adapterFailed ) { return; }
		flush_rewrite_rules( true );
		update_option( 'aish_frontend_routes_version', '2', false );
	}

	public function parse( \WP $wp ): void {
		try { $this->parseRequest( $wp ); }
		catch ( \Throwable $error ) {
			self::$adapterFailed = true;
			$locale = $this->request['locale'] ?? self::$context['locale'];
			$locale = in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ? $locale : 'zh_CN';
			$this->request = array( 'route' => '404', 'locale' => $locale );
			$this->handled = true;
			self::$context['locale'] = $locale;
			self::$context['language'] = 'zh_CN' === $locale ? 'zh' : 'en';
			$wp->query_vars = array( 'aish_route' => '404', 'aish_language' => self::$context['language'] );
		}
	}

	private function parseRequest( \WP $wp ): void {
		if ( class_exists( \AISkillsHub\Core\Learning\SubscriptionFrontend::class ) && \AISkillsHub\Core\Learning\SubscriptionFrontend::active() ) { return; }
		if ( class_exists( \AISkillsHub\Core\Discovery\Frontend::class ) && \AISkillsHub\Core\Discovery\Frontend::active() ) { return; }
		// Account routes own their private response, authentication and metadata.
		if ( class_exists( \AISkillsHub\Core\Accounts\Frontend::class ) && \AISkillsHub\Core\Accounts\Frontend::active() ) { return; }
		if ( class_exists( \AISkillsHub\Core\Community\Frontend::class ) && \AISkillsHub\Core\Community\Frontend::active() ) { return; }
		if ( class_exists( \AISkillsHub\Core\Submissions\Frontend::class ) && \AISkillsHub\Core\Submissions\Frontend::active() ) { return; }
		if ( class_exists( \AISkillsHub\Core\Developers\Frontend::class ) && \AISkillsHub\Core\Developers\Frontend::active() ) { return; }
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $wp->query_vars['rest_route'] ) || isset( $wp->query_vars['robots'] ) || isset( $wp->query_vars['sitemap'] ) || isset( $wp->query_vars['feed'] ) ) { return; }
		$path = trim( (string) $wp->request, '/' );
		// WP may leave request empty before pretty permalinks are configured. Never redirect /en/ to itself.
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$uriPath = is_string( $uri ) ? wp_parse_url( $uri, PHP_URL_PATH ) : null;
		$homePath = rtrim( (string) wp_parse_url( self::siteUrl(), PHP_URL_PATH ), '/' );
		if ( is_string( $uriPath ) && strlen( $uriPath ) <= 4096 && ( '' === $homePath || $uriPath === $homePath || str_starts_with( $uriPath, $homePath . '/' ) ) ) {
			$path = trim( substr( $uriPath, strlen( $homePath ) ), '/' );
			$path = preg_replace( '#^index\.php(?:/|$)#', '', $path );
		}
		if ( preg_match( '#^(wp-json|wp-admin|wp-login\.php|[a-z0-9_-]*sitemap[a-z0-9_-]*\.(?:xml|xsl)|robots\.txt|favicon\.ico|feed)(/|$)#', $path ) ) { return; }
		$this->request = array( 'route' => '404', 'locale' => preg_match( '#^en(?:/|$)#D', $path ) ? 'en_US' : self::defaultLocale() );
		if ( self::$adapterFailed ) { throw new \RuntimeException( 'Frontend adapter is unavailable.' ); }
		$mapped = apply_filters( 'aish_frontend_request', null, $path );
		if ( null !== $mapped && ! is_array( $mapped ) ) { throw new \RuntimeException( 'Frontend request adapter is unavailable.' ); }
		if ( is_array( $mapped ) ) { $this->request = $mapped; }
		elseif ( ! apply_filters( 'aish_frontend_builtin_routing', true ) ) { return; }
		elseif ( '' === $path ) { $this->request = array( 'route' => 'root', 'locale' => self::defaultLocale() ); }
		else {
			$this->request = array( 'route' => '404', 'locale' => self::defaultLocale() );
			if ( preg_match( '#^(zh|en)(?:/(.*))?$#D', $path, $matches ) ) {
				$this->request['locale'] = 'zh' === $matches[1] ? 'zh_CN' : 'en_US';
				$tail = trim( $matches[2] ?? '', '/' );
				if ( '' === $tail ) { $this->request['route'] = 'home'; }
				elseif ( in_array( $tail, array( 'skills', 'tutorials', 'help', 'search', 'about', 'contact', 'submit' ), true ) ) { $this->request['route'] = $tail; }
				elseif ( preg_match( '#^skills/([1-9][0-9]{0,18})(?:/([a-z0-9]+(?:-[a-z0-9]+)*))?$#D', $tail, $parts ) ) { $this->request += array( 'id' => $parts[1], 'slug' => $parts[2] ?? '' ); $this->request['route'] = 'skill'; }
				elseif ( preg_match( '#^(category|tag)/([a-z0-9]+(?:-[a-z0-9]+)*)$#D', $tail, $parts ) ) { $this->request['route'] = $parts[1]; $this->request['slug'] = $parts[2]; }
				elseif ( preg_match( '#^tutorials/([1-9][0-9]{0,18})$#D', $tail, $parts ) ) { $this->request['route'] = 'tutorial'; $this->request['id'] = $parts[1]; }
			}
		}
		if ( ! in_array( $this->request['locale'] ?? '', array( 'zh_CN', 'en_US' ), true ) || ! in_array( $this->request['route'] ?? '', array_merge( self::ROUTES, array( 'root' ) ), true ) ) { $this->request = array( 'route' => '404', 'locale' => self::defaultLocale() ); }
		if ( in_array( $this->request['route'], array( 'skill', 'tutorial' ), true ) && false === filter_var( $this->request['id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) { $this->request['route'] = '404'; }
		if ( in_array( $this->request['route'], array( 'category', 'tag' ), true ) && ( ! is_string( $this->request['slug'] ?? null ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $this->request['slug'] ) ) ) { $this->request['route'] = '404'; }
		if ( 'skill' === $this->request['route'] && ! is_string( $this->request['slug'] ?? '' ) ) { $this->request['route'] = '404'; }
		$this->handled = true;
		self::$context['locale'] = $this->request['locale'];
		self::$context['language'] = 'zh_CN' === $this->request['locale'] ? 'zh' : 'en';
		$wp->query_vars = array( 'aish_route' => $this->request['route'], 'aish_language' => self::$context['language'] );
	}

	public static function defaultLocale(): string {
		$settings = get_option( 'aish_admin_settings', array() );
		return is_array( $settings ) && 'en_US' === ( $settings['default_locale'] ?? '' ) ? 'en_US' : 'zh_CN';
	}

	private static function query( string $key, string $default = '', int $max = 768 ): string {
		$value = $_GET[ $key ] ?? $default;
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'Invalid query.' ); }
		$value = wp_unslash( $value );
		if ( strlen( $value ) > $max || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'Invalid query.' ); }
		return $value;
	}

	private static function filters(): array {
		$page = filter_var( self::query( 'page', '1', 8 ), FILTER_VALIDATE_INT );
		if ( false === $page || $page < 1 || $page > 10000 ) { throw new \InvalidArgumentException( 'Invalid page.' ); }
		$search = trim( self::query( 'q', '', 400 ) );
		if ( preg_match_all( '/./us', $search ) > 100 ) { throw new \InvalidArgumentException( 'Query too long.' ); }
		$filters = array( 'q' => $search, 'sort' => self::query( 'sort', 'newest', 10 ), 'page' => $page, 'per_page' => 12 );
		if ( ! in_array( $filters['sort'], array( 'newest', 'oldest', 'title' ), true ) ) { throw new \InvalidArgumentException( 'Invalid sort.' ); }
		foreach ( array( 'category', 'tag', 'platform' ) as $key ) {
			$filters[ $key ] = self::query( $key, '', 191 );
			if ( '' !== $filters[ $key ] && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $filters[ $key ] ) ) { throw new \InvalidArgumentException( 'Invalid filter.' ); }
		}
		return $filters;
	}

	public function resolve(): void {
		if ( ! $this->handled ) { return; }
		try {
			if ( self::$adapterFailed ) { throw new \RuntimeException( 'Frontend adapter is unavailable.' ); }
			$this->resolveRequest();
		} catch ( \Throwable $error ) { $this->unavailable(); }
	}

	/** The error response must not read failing storage or call the same adapter again. */
	private function unavailable(): void {
		self::$adapterFailed = true;
		$locale = 'en_US' === ( $this->request['locale'] ?? '' ) ? 'en_US' : 'zh_CN';
		self::$context = array(
			'route' => '404', 'locale' => $locale, 'language' => 'zh_CN' === $locale ? 'zh' : 'en',
			'title' => ( 'zh_CN' === $locale ? '暂时无法加载' : 'Temporarily unavailable' ) . ' · AI Skills Hub',
			'description' => '', 'canonical' => '', 'alternates' => array(), 'alternate_ids' => array(), 'data' => array(),
			'error' => true, 'status' => 503, 'noindex' => true, 'image' => '', 'og_type' => 'website',
			'seo_managed' => true, 'seo_alternates' => array(), 'x_default' => '',
		);
		$GLOBALS['wp_query']->is_404 = false;
		$GLOBALS['wp_query']->is_home = false;
		status_header( 503 ); nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Retry-After: 60' );
		Seo::sendHeaders( self::$context );
	}

	private function resolveRequest(): void {
		$locale = $this->request['locale'];
		$route = $this->request['route'];
		if ( 'root' === $route ) {
			$args = array();
			if ( isset( $_GET['s'] ) && is_string( $_GET['s'] ) ) {
				preg_match_all( '/./us', sanitize_text_field( wp_unslash( $_GET['s'] ) ), $characters );
				$args['q'] = implode( '', array_slice( $characters[0], 0, 100 ) );
			}
			wp_safe_redirect( self::url( $args ? 'search' : 'home', $args, $locale ), 302 );
			exit;
		}
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		$data = array();
		$status = '404' === $route ? 404 : 200;
		$alternates = array();
		$canonicalArgs = array();
		try {
			if ( 'home' === $route ) {
				$data = has_action( 'aish_discovery_home' )
					? array( 'featured' => $this->catalog->featured( $locale, 1 ), 'latest' => $this->catalog->latest( $locale, 1 ), 'categories' => $this->catalog->terms( 'category', $locale ) )
					: array( 'featured' => $this->catalog->featured( $locale, 3 ), 'latest' => $this->catalog->latest( $locale, 6 ), 'categories' => $this->catalog->terms( 'category', $locale ), 'platforms' => $this->catalog->platforms( $locale ), 'tutorials' => $this->editorial->tutorials( $locale, array( 'per_page' => 3 ) )['items'] );
			} elseif ( in_array( $route, array( 'skills', 'category', 'tag', 'search' ), true ) ) {
				$filters = self::filters();
				if ( in_array( $route, array( 'category', 'tag' ), true ) ) {
					$term = $this->catalog->term( $route, (string) $this->request['slug'], $locale );
					if ( ! $term ) { $route = '404'; $status = 404; }
					else { $filters[ $route ] = $term['slug']; $data['term'] = $term; $canonicalArgs['slug'] = $term['slug']; }
				}
				if ( '404' !== $route ) {
					$data += array( 'result' => $this->catalog->skills( $locale, $filters ), 'filters' => $filters, 'categories' => $this->catalog->terms( 'category', $locale ), 'tags' => $this->catalog->terms( 'tag', $locale ), 'platforms' => $this->catalog->platforms( $locale ) );
					if ( $filters['page'] > 1 && ! $data['result']['items'] ) { $route = '404'; $status = 404; $data = array(); }
					$canonicalArgs += $filters;
					if ( 'search' === $route ) { $data['tutorial_result'] = $this->editorial->tutorials( $locale, array_merge( $filters, array( 'page' => 1, 'per_page' => 3 ) ) ); $data['tutorials'] = $data['tutorial_result']['items']; }
				}
			} elseif ( 'skill' === $route ) {
				$id = filter_var( $this->request['id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( false === $id ) { throw new \InvalidArgumentException( 'Invalid identity.' ); }
				$skill = $this->catalog->detail( $id, $locale );
				$available = $this->catalog->alternatives( $id );
				if ( ! $skill ) {
					if ( $available ) { $fallback = array_key_first( $available ); wp_safe_redirect( self::url( 'skill', array( 'id' => $id, 'slug' => $available[ $fallback ], 'translation' => 'missing' ), $fallback ), 302 ); exit; }
					$route = '404'; $status = 404;
				} else {
					$canonicalArgs = array( 'id' => $id, 'slug' => $skill['slug'] );
					if ( ( $this->request['slug'] ?? '' ) !== $skill['slug'] ) { wp_safe_redirect( self::url( 'skill', $canonicalArgs, $locale ), 301 ); exit; }
					$data = array( 'skill' => $skill, 'sections' => View::sections( $skill['content'] ), 'related' => has_action( 'aish_discovery_recommendations' ) ? array() : $this->catalog->related( $id, $locale, 3 ), 'tutorials' => $this->editorial->relatedTutorials( $id, $locale, 3 ) );
					foreach ( $available as $language => $slug ) { $alternates[ $language ] = self::url( 'skill', array( 'id' => $id, 'slug' => $slug ), $language ); }
				}
			} elseif ( 'help' === $route ) {
				$help = \AISkillsHub\Core\Core::boot()->get( 'learning_help' );
				$validated = self::filters();
				$filters = array( 'q' => $validated['q'], 'help_category' => self::query( 'help_category', '', 32 ), 'page' => $validated['page'] );
				$data = array( 'help' => $help->listing( $locale, $filters ), 'filters' => $filters );
				if ( $filters['page'] > 1 && ! $data['help']['items'] ) { $route = '404'; $status = 404; $data = array(); }
				$canonicalArgs = $filters;
			} elseif ( 'tutorials' === $route ) {
				$filters = self::filters();
				$data = array( 'result' => $this->editorial->tutorials( $locale, $filters ), 'filters' => $filters, 'categories' => $this->catalog->terms( 'category', $locale ), 'tags' => $this->catalog->terms( 'tag', $locale ), 'platforms' => $this->catalog->platforms( $locale ) );
				if ( $filters['page'] > 1 && ! $data['result']['items'] ) { $route = '404'; $status = 404; $data = array(); }
				$canonicalArgs = $filters;
			} elseif ( 'tutorial' === $route ) {
				$id = filter_var( $this->request['id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( false === $id ) { throw new \InvalidArgumentException( 'Invalid identity.' ); }
				$tutorial = $this->editorial->tutorial( $id, $locale );
				$available = $this->editorial->tutorialAlternatives( $id );
				if ( ! $tutorial ) {
					if ( $available ) { $fallback = isset( $available[ $locale ] ) ? $locale : array_key_first( $available ); wp_safe_redirect( self::url( 'tutorial', array( 'id' => $available[ $fallback ], 'translation' => $fallback !== $locale ? 'missing' : '' ), $fallback ), 302 ); exit; }
					$route = '404'; $status = 404;
				} else {
					$data = array( 'tutorial' => $tutorial, 'relatedSkills' => $this->catalog->cardsByIds( array_slice( $tutorial['related_skill_ids'] ?? array(), 0, 6 ), $locale ) );
					$canonicalArgs = array( 'id' => $id );
					foreach ( $available as $language => $translationId ) { $alternates[ $language ] = self::url( 'tutorial', array( 'id' => $translationId ), $language ); }
				}
			} elseif ( in_array( $route, array( 'about', 'contact', 'submit' ), true ) ) {
				$data = array( 'page' => $this->editorial->page( $route, $locale ), 'form_status' => self::query( 'form', '', 20 ) );
				if ( ! in_array( $data['form_status'], array( '', 'sent', 'invalid', 'expired', 'limited', 'error' ), true ) ) { $data['form_status'] = ''; }
			}
			$data['translation_missing'] = 'missing' === self::query( 'translation', '', 10 );
		} catch ( \InvalidArgumentException $error ) { $route = '404'; $status = 404; $data = array(); }
		catch ( \Throwable $error ) { $status = 503; $data = array(); $alternates = array(); }
		$filtered = isset( $data['filters'] ) && ( (int) $data['filters']['page'] > 1 || 'newest' !== ( $data['filters']['sort'] ?? 'newest' ) || '' !== $data['filters']['q'] || '' !== ( $data['filters']['platform'] ?? '' ) || '' !== ( $data['filters']['help_category'] ?? '' ) || ( 'category' !== $route && '' !== ( $data['filters']['category'] ?? '' ) ) || ( 'tag' !== $route && '' !== ( $data['filters']['tag'] ?? '' ) ) );
		if ( ! $alternates && ! $filtered && 200 === $status && ! in_array( $route, array( 'skill', 'tutorial' ), true ) ) {
			try {
				foreach ( array( 'zh_CN', 'en_US' ) as $language ) {
					if ( 'help' === $route && ! $this->editorial->tutorials( $language, array( 'help_only' => true, 'per_page' => 1 ) )['items'] ) { continue; }
					if ( in_array( $route, array( 'about', 'contact', 'submit' ), true ) ) {
						$translated = $this->editorial->page( $route, $language );
						if ( ! $translated || SeoSettings::postValues( (int) $translated['id'] )['noindex'] ) { continue; }
					}
					if ( in_array( $route, array( 'category', 'tag' ), true ) ) {
						$translated = $this->catalog->term( $route, $data['term']['slug'], $language );
						if ( ! $translated || (int) $translated['count'] < 1 || ! empty( $translated['translation_missing'] ) ) { continue; }
					}
					$alternates[ $language ] = self::url( $route, $canonicalArgs, $language );
				}
			} catch ( \Throwable $error ) { $status = 503; $data = array(); $alternates = array(); }
		}
		$title = Strings::get( 'page.' . $route . '.title', $locale );
		$description = Strings::get( 'page.' . $route . '.description', $locale );
		if ( ! empty( $data['skill'] ) ) { $title = $data['skill']['seo_title'] ?: $data['skill']['title']; $description = $data['skill']['seo_description'] ?: $data['skill']['summary']; }
		elseif ( ! empty( $data['tutorial'] ) ) { $title = $data['tutorial']['title']; $description = $data['tutorial']['summary']; }
		elseif ( ! empty( $data['term'] ) ) { $title = $data['term']['name'] . ' · ' . $title; $description = ( $data['term']['description'] ?? '' ) ?: $description; }
		elseif ( ! empty( $data['page'] ) ) { $title = $data['page']['title']; $description = $data['page']['summary']; }
		if ( $status >= 500 ) { $title = 'zh_CN' === $locale ? '暂时无法加载' : 'Temporarily unavailable'; $description = ''; }
		self::$context = array( 'route' => $route, 'locale' => $locale, 'language' => 'zh_CN' === $locale ? 'zh' : 'en', 'title' => $title . ' · AI Skills Hub', 'description' => wp_strip_all_tags( $description ), 'canonical' => 200 === $status ? self::url( $route, $canonicalArgs, $locale ) : '', 'alternates' => 200 === $status ? $alternates : array(), 'data' => $data, 'error' => $status >= 500, 'status' => $status );
		self::$context['alternate_ids'] = 'tutorial' === $route ? ( $available ?? array() ) : array();
		if ( self::$adapterFailed ) { throw new \RuntimeException( 'Frontend adapter is unavailable.' ); }
		self::$context = Seo::prepare( self::$context, $_GET );
		$GLOBALS['wp_query']->is_404 = 404 === $status;
		$GLOBALS['wp_query']->is_home = 'home' === $route;
		status_header( $status );
		// Unpublishing must take effect immediately; shared caches may not retain a public snapshot.
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		Seo::sendHeaders( self::$context );
		if ( $status >= 500 ) { header( 'Retry-After: 60' ); }
	}

	public static function url( string $route, array $args = array(), ?string $locale = null ): string {
		$locale ??= self::context()['locale'];
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || ! in_array( $route, self::ROUTES, true ) ) { throw new \InvalidArgumentException( 'Invalid route.' ); }
		$prefix = 'zh_CN' === $locale ? 'zh' : 'en';
		$path = match ( $route ) {
			'home' => '',
			'skill' => 'skills/' . max( 1, (int) ( $args['id'] ?? 0 ) ) . '/' . rawurlencode( (string) ( $args['slug'] ?? '' ) ) . '/',
			'tutorial' => 'tutorials/' . max( 1, (int) ( $args['id'] ?? 0 ) ) . '/',
			'category', 'tag' => $route . '/' . rawurlencode( (string) ( $args['slug'] ?? '' ) ) . '/',
			'404' => 'not-found/',
			default => $route . '/',
		};
		$query = array();
		foreach ( array( 'q', 'category', 'tag', 'platform', 'sort', 'page', 'translation', 'help_category' ) as $key ) {
			if ( 'help_category' === $key && 'help' !== $route ) { continue; }
			if ( ! isset( $args[ $key ] ) || ! is_scalar( $args[ $key ] ) || '' === (string) $args[ $key ] ) { continue; }
			if ( ( 'page' === $key && (int) $args[ $key ] <= 1 ) || ( 'sort' === $key && 'newest' === $args[ $key ] ) || $key === $route ) { continue; }
			$query[ $key ] = (string) $args[ $key ];
		}
		$url = self::siteUrl( '/' . $prefix . '/' . $path );
		// WordPress requires newly added query values to be URL encoded by the caller.
		if ( $query ) { $url = add_query_arg( array_map( 'rawurlencode', $query ), $url ); }
		return self::adaptedUrl( $url, $route, $args, $locale );
	}

	/** Shared adapter validation for bounded extension routes and their sitemap URLs. */
	public static function adaptedUrl( string $url, string $route, array $args, string $locale ): string {
		if ( self::$adapterFailed ) { return $url; }
		try {
			$adjusted = apply_filters( 'aish_frontend_url', $url, $route, $args, $locale );
			$parts = is_string( $adjusted ) && ! preg_match( '/[\x00-\x20\x7f]/', $adjusted ) ? wp_parse_url( $adjusted ) : false;
			if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { throw new \RuntimeException( 'Invalid frontend URL.' ); }
			return $adjusted;
		} catch ( \Throwable $error ) { self::$adapterFailed = true; throw new \RuntimeException( 'Frontend URL adapter is unavailable.' ); }
	}

}
