<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Discovery;

use AISkillsHub\Core\Contributions\Ui;
use AISkillsHub\Core\Frontend\Editorial;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\Seo;
use AISkillsHub\Core\Frontend\View;

defined( 'ABSPATH' ) || exit;

/** Read-only discovery routes share the existing public catalog, shell and SEO owner. */
final class Frontend {
	public const RANKINGS = array( 'trending', 'most-viewed', 'most-favorited', 'top-rated', 'recently-added', 'recently-updated' );
	private static bool $active = false;
	private static array $context = array();
	private array $request = array();
	public function __construct( private Query $query, private PublicCatalog $catalog, private Editorial $editorial ) {}
	public static function active(): bool { return self::$active; }
	public static function context(): array { return self::$context; }
	public static function t( string $key, ?string $locale = null ): string { return Strings::get( $key, $locale ?? ( self::$context['locale'] ?? Router::context()['locale'] ) ); }
	public function register(): void {
		add_action( 'parse_request', array( $this, 'parse' ), 17 );
		add_action( 'template_redirect', array( $this, 'resolve' ), -8 );
		add_filter( 'aish_frontend_context', static fn( array $context ): array => self::$active ? self::$context : $context );
		add_filter( 'posts_pre_query', static function ( $posts, $query ) { if ( self::$active && $query->is_main_query() ) { $query->found_posts = 0; $query->max_num_pages = 0; return array(); } return $posts; }, 9, 2 );
		add_filter( 'pre_handle_404', static fn( $value ) => self::$active ? true : $value );
		add_filter( 'redirect_canonical', static fn( $value ) => self::$active ? false : $value );
		add_filter( 'template_include', static fn( string $file ): string => self::$active ? get_template_directory() . '/templates/discovery/page.php' : $file, 99 );
		add_action( 'aish_discovery_home', array( $this, 'home' ), 10, 2 );
		add_action( 'aish_discovery_recommendations', array( $this, 'recommendations' ), 10, 2 );
	}
	/** Strict URL contract; request filters are bounded and never become template paths. */
	public static function url( string $route, string $locale, array $args = array() ): string {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || ! in_array( $route, array_merge( self::RANKINGS, array( 'editors-picks', 'collections', 'use-cases', 'platforms' ) ), true ) ) { throw new \InvalidArgumentException( 'Invalid discovery route.' ); }
		if ( 'editors-picks' === $route ) { $route = 'collections'; }
		$path = '/' . ( 'zh_CN' === $locale ? 'zh' : 'en' ) . '/' . $route . '/';
		if ( isset( $args['slug'] ) ) {
			if ( ! is_string( $args['slug'] ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $args['slug'] ) || strlen( $args['slug'] ) > 191 || ! in_array( $route, array( 'collections', 'use-cases', 'platforms' ), true ) ) { throw new \InvalidArgumentException( 'Invalid discovery identity.' ); }
			if ( 'platforms' !== $route ) { $path .= Ui::integer( $args['id'] ?? null, 1 ) . '/'; }
			$path .= $args['slug'] . '/';
		}
		$params = self::filters( $args ); $query = array();
		foreach ( $params as $key => $value ) { if ( 'per_page' === $key || '' === $value || ( 'page' === $key && 1 === $value ) || ( 'window' === $key && 7 === $value ) ) { continue; } $query[ $key ] = rawurlencode( (string) $value ); }
		$url = Router::siteUrl( $path ); if ( $query ) { $url = add_query_arg( $query, $url ); }
		return Router::adaptedUrl( $url, $route, $args, $locale );
	}
	/** UI links retry the built-in path after a failed adapter, matching Frontend\View. */
	public static function link( string $route, string $locale, array $args = array() ): string {
		try { return self::url( $route, $locale, $args ); }
		catch ( \RuntimeException $error ) { return self::url( $route, $locale, $args ); }
	}
	public static function filters( array $input ): array {
		$result = array( 'page' => Ui::integer( $input['page'] ?? '1', 1, 1000 ), 'per_page' => 12, 'window' => Ui::integer( $input['window'] ?? '7', 7, 30 ) );
		if ( ! in_array( $result['window'], array( 7, 30 ), true ) ) { throw new \InvalidArgumentException( 'Invalid discovery window.' ); }
		foreach ( array( 'q', 'category', 'tag', 'platform' ) as $key ) { $value = $input[ $key ] ?? ''; if ( ! is_string( $value ) || strlen( $value ) > ( 'q' === $key ? 400 : 191 ) ) { throw new \InvalidArgumentException( 'Invalid discovery filter.' ); } $value = trim( $value ); if ( ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) || ( 'q' === $key && preg_match_all( '/./us', $value ) > 100 ) ) { throw new \InvalidArgumentException( 'Invalid discovery filter.' ); } if ( 'q' !== $key && '' !== $value && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value ) ) { throw new \InvalidArgumentException( 'Invalid discovery filter.' ); } $result[ $key ] = $value; }
		return $result;
	}
	/** Preserve safe search choices, reset pagination, and never invent a missing topic translation. */
	public static function switchUrl( string $locale ): string {
		$data = self::$context['data'] ?? array();
		if ( ! empty( self::$context['error'] ) || ! isset( $data['route'] ) ) { return View::url( 'home', array(), $locale ); }
		$args = $data['filters'] ?? array(); $args['page'] = 1;
		if ( isset( $data['topic'] ) ) {
			$slug = $data['topic']['translations'][ $locale ] ?? null;
			if ( ! is_string( $slug ) ) { return self::url( $data['route'], $locale ); }
			$args['id'] = $data['topic']['id']; $args['slug'] = $slug;
		}
		return self::url( $data['route'], $locale, $args );
	}
	public function parse( \WP $wp ): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $wp->query_vars['rest_route'] ) || isset( $wp->query_vars['feed'] ) ) { return; }
		$path = Ui::path(); if ( ! is_string( $path ) || ! preg_match( '#^(zh|en)/(trending|most-viewed|most-favorited|top-rated|recently-added|recently-updated|editors-picks|collections|use-cases|platforms)(?:/([1-9][0-9]{0,18}))?(?:/([a-z0-9]+(?:-[a-z0-9]+)*))?$#D', $path, $parts ) ) { return; }
		$route = $parts[2]; $id = $parts[3] ?? ''; $slug = $parts[4] ?? '';
		if ( ( $id || $slug ) && ! in_array( $route, array( 'collections', 'use-cases', 'platforms' ), true ) ) { return; }
		if ( 'platforms' !== $route && (bool) $id !== (bool) $slug ) { return; }
		if ( 'platforms' === $route && $id ) { return; }
		$adapterFailed = false;
		try { if ( ! apply_filters( 'aish_frontend_builtin_routing', true ) ) { return; } }
		catch ( \Throwable $error ) { $adapterFailed = true; }
		self::$active = true; $locale = 'zh' === $parts[1] ? 'zh_CN' : 'en_US';
		$this->request = array( 'route' => $route, 'id' => $id, 'slug' => $slug );
		if ( $adapterFailed ) { $this->request['adapter_failed'] = true; }
		self::$context = array( 'route' => 'discovery', 'locale' => $locale, 'language' => $parts[1], 'title' => 'AI Skills Hub', 'description' => '', 'canonical' => '', 'alternates' => array(), 'data' => array(), 'status' => 200, 'error' => false );
		$wp->query_vars = array( 'aish_route' => 'discovery', 'aish_language' => $parts[1] );
	}
	public function resolve(): void {
		if ( ! self::$active ) { return; }
		$GLOBALS['wp_query']->is_404 = false; $GLOBALS['wp_query']->is_home = false;
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Content-Type-Options: nosniff' );
		$locale = self::$context['locale']; $route = $this->request['route'];
		try {
			if ( ! empty( $this->request['adapter_failed'] ) ) { throw new \RuntimeException( 'Discovery adapter is unavailable.' ); }
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) { header( 'Allow: GET, HEAD' ); self::$context['status'] = 405; throw new \InvalidArgumentException( 'Invalid request.' ); }
			$filters = self::filters( wp_unslash( $_GET ) );
			if ( 'editors-picks' === $route ) { wp_safe_redirect( self::url( 'collections', $locale, $filters ), 301 ); exit; }
			if ( ! $this->request['slug'] && in_array( $route, array( 'collections', 'use-cases', 'platforms' ), true ) && $filters['page'] > 1 ) { self::$context['status'] = 404; throw new \InvalidArgumentException( 'Unavailable index page.' ); }
			$data = array( 'route' => $route, 'filters' => $filters, 'params' => $this->request );
			$data['discovery_title'] = self::t( $route, $locale ); $description = self::t( 'intro', $locale );
			if ( in_array( $route, self::RANKINGS, true ) ) { $data['result'] = $this->query->list( $route, $locale, $filters ); $description = self::t( 'method.' . $route, $locale ); }
			else {
				$kind = match ( $route ) { 'use-cases' => 'use_case', 'platforms' => 'platform', default => 'collection' };
				if ( $this->request['slug'] ) {
					if ( 'platform' === $kind && '' !== $filters['platform'] && $filters['platform'] !== $this->request['slug'] ) { throw new \InvalidArgumentException( 'Conflicting platform filter.' ); }
					$topic = $this->query->detail( $kind, $this->request['slug'], $locale, $filters );
					if ( ! $topic || ( 'platform' !== $kind && (int) $topic['id'] !== Ui::integer( $this->request['id'] ) ) ) { self::$context['status'] = 404; throw new \InvalidArgumentException( 'Unavailable topic.' ); }
					$data['topic'] = $topic; $data['result'] = $topic['skills']; $data['discovery_title'] = $topic['title'] ?? $topic['name']; $description = ( $topic['seo_description'] ?? '' ) ?: $topic['description'];
					$data['tutorials'] = $this->tutorials( $topic['tutorial_ids'] ?? array(), $locale );
					foreach ( $topic['translations'] ?? array() as $language => $slug ) { if ( in_array( $language, array( 'zh_CN', 'en_US' ), true ) && is_string( $slug ) ) { self::$context['alternates'][ $language ] = self::url( $route, $language, array( 'id' => $topic['id'], 'slug' => $slug ) ); } }
				} else { $data['topics'] = $this->query->topics( $kind, $locale ); }
			}
			if ( isset( $data['result'] ) && $filters['page'] > 1 && ! $data['result']['items'] ) { self::$context['status'] = 404; throw new \InvalidArgumentException( 'Unavailable page.' ); }
			if ( ! $this->request['slug'] && ! $_GET ) {
				foreach ( array( 'zh_CN', 'en_US' ) as $language ) {
					$eligible = $language === $locale ? ! empty( $data['result']['total'] ?? $data['topics'] ?? array() ) : ( in_array( $route, self::RANKINGS, true ) ? $this->query->list( $route, $language, array( 'per_page' => 1 ) )['total'] > 0 : count( $this->query->topics( $kind, $language ) ) > 0 );
					if ( $eligible ) { self::$context['alternates'][ $language ] = self::url( $route, $language ); }
				}
			}
			$data['categories'] = $this->catalog->terms( 'category', $locale ); $data['tags'] = $this->catalog->terms( 'tag', $locale ); $data['platforms'] = $this->catalog->platforms( $locale );
			self::$context['data'] = $data; self::$context['title'] = ( ( $data['topic']['seo_title'] ?? '' ) ?: $data['discovery_title'] ) . ' · AI Skills Hub'; self::$context['description'] = wp_strip_all_tags( $description );
			$args = array_merge( $filters, array_filter( $this->request, static fn( $value ): bool => '' !== $value ) ); self::$context['canonical'] = self::url( $route, $locale, $args );
			self::$context = Seo::prepare( self::$context, $_GET );
		} catch ( \Throwable $error ) {
			if ( 200 === self::$context['status'] ) { self::$context['status'] = $error instanceof \InvalidArgumentException ? 400 : 503; }
			self::$context['data'] = array( 'route' => $route ); self::$context['alternates'] = array(); self::$context['canonical'] = ''; self::$context['error'] = true;
			self::$context['title'] = self::t( 404 === self::$context['status'] ? 'not-found' : 'error', $locale ) . ' · AI Skills Hub';
			self::$context = array_merge( self::$context, array( 'noindex' => true, 'seo_managed' => true, 'seo_alternates' => array(), 'x_default' => '', 'image' => '', 'og_type' => 'website' ) );
		}
		status_header( self::$context['status'] ); Seo::sendHeaders( self::$context ); if ( 503 === self::$context['status'] ) { header( 'Retry-After: 60' ); }
	}
	private function tutorials( array $ids, string $locale ): array {
		return $this->editorial->cardsByIds( array_slice( $ids, 0, 6 ), $locale );
	}
	public function home( string $locale, array $existing ): void {
		try { $modules = Settings::home( $locale ); } catch ( \Throwable $ignored ) { echo '<p class="aish-container discovery-notice" role="status">' . esc_html( self::t( 'error', $locale ) ) . '</p>'; return; }
		foreach ( $modules as $module ) {
			if ( empty( $module['enabled'] ) ) { continue; } $key = $module['key']; $limit = max( 1, min( 12, (int) $module['limit'] ) ); $items = array(); $kind = 'skills'; $link = ''; $error = false;
			try {
				if ( 'categories' === $key ) { $kind = 'categories'; $allCategories = $existing['categories'] ?? $this->catalog->terms( 'category', $locale ); $items = array_slice( array_values( array_filter( $allCategories, static fn( array $term ): bool => (int) ( $term['count'] ?? 0 ) > 0 ) ), 0, $limit ); foreach ( $items as &$item ) { $item['display_name'] = View::termLabel( $item, $allCategories ); } unset( $item ); $link = View::url( 'skills', array(), $locale ); }
				elseif ( 'tutorials' === $key ) { $kind = 'tutorials'; $items = $this->editorial->tutorials( $locale, array( 'per_page' => $limit ) )['items']; $link = View::url( 'tutorials', array(), $locale ); }
				elseif ( in_array( $key, array( 'collections', 'use_cases', 'platforms' ), true ) ) { $kind = 'topics'; $topicKind = match ( $key ) { 'use_cases' => 'use_case', 'platforms' => 'platform', default => 'collection' }; $items = array_slice( $this->query->topics( $topicKind, $locale ), 0, $limit ); $link = self::url( str_replace( '_', '-', $key ), $locale ); }
				else { $ranking = str_replace( '_', '-', $key ); $result = $this->query->list( $ranking, $locale, array( 'per_page' => $limit, 'window' => 7 ) ); $items = $result['items']; $link = self::url( $ranking, $locale ); }
			} catch ( \Throwable $ignored ) { $error = true; }
			get_template_part( 'templates/discovery/home-module', null, array( 'module' => $module, 'items' => $items, 'kind' => $kind, 'link' => $link, 'locale' => $locale, 'error' => $error ) );
		}
	}
	public function recommendations( array $skill, string $locale ): void {
		foreach ( array( 'related', 'similar' ) as $kind ) {
			try { $items = $this->query->$kind( (int) $skill['id'], $locale, 3 ); $error = false; } catch ( \Throwable $ignored ) { $items = array(); $error = true; }
			get_template_part( 'templates/discovery/home-module', null, array( 'module' => array( 'key' => $kind, 'title' => self::t( $kind, $locale ), 'description' => '' ), 'items' => $items, 'kind' => 'skills', 'link' => View::url( 'skills', array(), $locale ), 'locale' => $locale, 'error' => $error, 'contained' => true ) );
		}
	}
	public static function pagination( array $result, string $route, string $locale, array $args ): string {
		$page = max( 1, (int) $result['page'] ); $pages = min( 1000, (int) ceil( (int) $result['total'] / max( 1, (int) $result['per_page'] ) ) ); if ( $pages < 2 ) { return ''; }
		$html = '<nav class="aish-pagination" aria-label="' . esc_attr( self::t( 'pagination', $locale ) ) . '">';
		$numbers = array_unique( array_merge( array( 1, $pages ), range( max( 1, $page - 2 ), min( $pages, $page + 2 ) ) ) ); sort( $numbers ); $last = 0;
		foreach ( $numbers as $number ) { if ( $number > $last + 1 ) { $html .= '<span aria-hidden="true">…</span>'; } $html .= $number === $page ? '<span class="aish-page-link is-current" aria-current="page">' . $number . '</span>' : '<a class="aish-page-link" href="' . esc_url( self::url( $route, $locale, array_merge( $args, array( 'page' => $number ) ) ) ) . '">' . $number . '</a>'; $last = $number; }
		return $html . '</nav>';
	}
}
