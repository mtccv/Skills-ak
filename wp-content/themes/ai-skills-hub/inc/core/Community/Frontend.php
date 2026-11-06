<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Community;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Frontend as Accounts;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Small private SSR workflows; all authorization and state changes also live in Service. */
final class Frontend {
	private const ROUTES = array( 'favorites', 'reviews', 'review', 'favorite', 'report-review', 'report-skill' );
	private static bool $active = false;
	private static array $context = array( 'route' => 'favorites', 'locale' => 'zh_CN', 'status' => 200, 'error' => '', 'message' => '', 'values' => array(), 'profile' => array(), 'params' => array() );
	public function __construct( private Service $service, private Query $query, private AccountService $accounts, private PublicCatalog $catalog ) {}
	public static function active(): bool { return self::$active; }
	public static function context(): array { return self::$context; }
	public static function t( string $key, ?string $locale = null ): string { return Strings::get( $key, $locale ?? self::$context['locale'] ); }

	public static function url( string $route, string $locale, array $args = array() ): string {
		if ( ! in_array( $route, self::ROUTES, true ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$url = Router::siteUrl( '/' . ( 'zh_CN' === $locale ? 'zh' : 'en' ) . '/account/' . $route . '/' );
		$query = array();
		foreach ( array( 'skill', 'review', 'revision', 'page', 'q', 'status', 'sort' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || ! is_scalar( $args[ $key ] ) || '' === (string) $args[ $key ] ) { continue; }
			if ( in_array( $key, array( 'skill', 'review', 'revision', 'page' ), true ) && ! preg_match( '/^[1-9][0-9]{0,18}$/D', (string) $args[ $key ] ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
			$query[ $key ] = rawurlencode( (string) $args[ $key ] );
		}
		return $query ? add_query_arg( $query, $url ) : $url;
	}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'parse' ), 16 );
		add_action( 'template_redirect', array( $this, 'resolve' ), -9 );
		add_filter( 'posts_pre_query', static function ( $posts, $query ) { if ( self::$active && $query->is_main_query() ) { $query->found_posts = 0; $query->max_num_pages = 0; return array(); } return $posts; }, 9, 2 );
		add_filter( 'pre_handle_404', static fn( $value ) => self::$active ? true : $value );
		add_filter( 'redirect_canonical', static fn( $value ) => self::$active ? false : $value );
		add_filter( 'template_include', static fn( string $file ): string => self::$active ? get_template_directory() . '/templates/community/page.php' : $file, 99 );
		add_filter( 'pre_get_document_title', static fn( string $title ): string => self::$active ? self::t( self::$context['route'] ) . ' · AI Skills Hub' : $title, PHP_INT_MAX );
		add_filter( 'wp_robots', static fn( array $robots ): array => self::$active ? array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true ) : $robots, PHP_INT_MAX );
		add_filter( 'wpseo_frontend_presenters', static fn( array $presenters ): array => self::$active ? array() : $presenters, PHP_INT_MAX );
		add_filter( 'wpml_hreflangs', static fn( $links ) => self::$active ? array() : $links, PHP_INT_MAX );
		add_filter( 'show_admin_bar', static fn( bool $show ): bool => self::$active ? false : $show );
		add_action( 'aish_skill_actions', array( $this, 'skillActions' ), 10, 2 );
		add_action( 'aish_skill_community', array( $this, 'publicSection' ), 10, 2 );
		add_action( 'aish_account_navigation', array( $this, 'navigation' ), 10, 2 );
		add_action( 'aish_account_dashboard', array( $this, 'dashboard' ) );
	}

	public function parse( \WP $wp ): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $wp->query_vars['rest_route'] ) || isset( $wp->query_vars['feed'] ) ) { return; }
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$path = is_string( $uri ) && strlen( $uri ) <= 4096 ? wp_parse_url( $uri, PHP_URL_PATH ) : null;
		$base = rtrim( (string) wp_parse_url( Router::siteUrl(), PHP_URL_PATH ), '/' );
		if ( ! is_string( $path ) || ( '' !== $base && ! str_starts_with( $path, $base . '/' ) ) ) { return; }
		$path = preg_replace( '#^index\.php(?:/|$)#', '', trim( substr( $path, strlen( $base ) ), '/' ) );
		if ( ! is_string( $path ) || ! preg_match( '#^(zh|en)/account/(favorites|reviews|review|favorite|report-review|report-skill)$#D', $path, $parts ) ) { return; }
		self::$active = true; self::$context['route'] = $parts[2]; self::$context['locale'] = 'zh' === $parts[1] ? 'zh_CN' : 'en_US';
		$wp->query_vars = array( 'aish_route' => 'community', 'aish_language' => $parts[1] );
	}

	public function resolve(): void {
		if ( ! self::$active ) { return; }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' ); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Frame-Options: SAMEORIGIN' );
		foreach ( array( 'rel_canonical', 'wp_shortlink_wp_head', 'rest_output_link_wp_head', 'wp_oembed_add_discovery_links' ) as $callback ) { remove_action( 'wp_head', $callback ); }
		remove_all_actions( 'rank_math/head' ); add_action( 'wp_head', '_wp_render_title_tag', 1 );
		$GLOBALS['wp_query']->is_404 = false; $GLOBALS['wp_query']->is_home = false;
		try {
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
			if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) ) { header( 'Allow: GET, HEAD, POST' ); self::$context['status'] = 405; throw new \InvalidArgumentException( 'invalid_input' ); }
			self::$context['params'] = self::parameters();
			$route = self::$context['route']; $locale = self::$context['locale']; $params = self::$context['params'];
			$destination = self::url( $route, $locale, $params );
			if ( ! is_user_logged_in() ) { wp_safe_redirect( Accounts::loginUrl( $destination, $locale ), 302 ); exit; }
			if ( ! $this->accounts->verified() ) { wp_safe_redirect( add_query_arg( 'return_to', rawurlencode( $destination ), Accounts::url( 'verify-email', $locale ) ), 302 ); exit; }
			if ( ! current_user_can( 'read' ) ) { throw new Exception( 'forbidden' ); }
			$this->accounts->requireOwner();
			self::$context['profile'] = $this->accounts->profile();
			if ( 'GET' === $method ) { $this->readFlash(); }
			if ( 'favorites' === $route ) { self::$context['listing'] = $this->query->mineFavorites( $locale, $params ); }
			elseif ( 'reviews' === $route ) { self::$context['listing'] = $this->query->mineReviews( $locale, $params ); }
			else {
				$skillId = $params['skill'] ?? 0;
				if ( $skillId < 1 ) { throw new Exception( 'unavailable' ); }
				self::$context['skill'] = $this->catalog->detail( $skillId, $locale );
				if ( 'favorite' === $route ) {
					self::$context['is_favorite'] = $this->query->favoriteState( $skillId );
					if ( ! self::$context['skill'] && ! self::$context['is_favorite'] ) { throw new Exception( 'unavailable' ); }
				} elseif ( 'review' === $route ) {
					self::$context['head'] = $this->query->mineReview( $skillId );
					if ( ! self::$context['skill'] && ! self::$context['head'] ) { throw new Exception( 'unavailable' ); }
				} elseif ( 'report-review' === $route ) {
					self::$context['reported_review'] = $this->query->publicReview( $params['review'] ?? 0, $params['revision'] ?? 0, $locale );
					if ( ! self::$context['skill'] || ! self::$context['reported_review'] || (int) self::$context['reported_review']['skill_id'] !== $skillId ) { throw new Exception( 'unavailable' ); }
				} elseif ( 'report-skill' === $route ) {
					if ( ! self::$context['skill'] ) { throw new Exception( 'unavailable' ); }
					self::$context['alternatives'] = $this->catalog->alternatives( $skillId );
					self::$context['skill_revision'] = $this->query->publicSkillRevision( $skillId, $locale );
					if ( ! self::$context['skill_revision'] ) { throw new Exception( 'unavailable' ); }
				}
			}
			if ( 'POST' === $method ) { $this->handlePost(); }
		} catch ( \Throwable $error ) {
			$reason = $error instanceof Exception ? $error->reason : ( in_array( $error->getMessage(), array( 'csrf', 'invalid_input' ), true ) ? $error->getMessage() : 'storage_unavailable' );
			if ( ! in_array( $reason, array( 'csrf', 'invalid_input', 'conflict', 'hidden', 'expired_request', 'forbidden', 'unavailable', 'rate_limited', 'storage_unavailable' ), true ) ) { $reason = 'forbidden'; }
			self::$context['error'] = $reason;
			if ( 200 === self::$context['status'] ) { self::$context['status'] = match ( $reason ) { 'csrf', 'forbidden', 'hidden' => 403, 'unavailable' => 404, 'conflict', 'expired_request' => 409, 'rate_limited' => 429, 'storage_unavailable' => 503, default => 422 }; }
			self::$context['values'] = self::safeValues();
			// On write failure render the submitted draft without issuing an unsafe second mutation.
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'review' === self::$context['route'] ) {
				try { self::$context['head'] = $this->query->mineReview( self::$context['params']['skill'] ?? 0 ); self::$context['skill'] = $this->catalog->detail( self::$context['params']['skill'] ?? 0, self::$context['locale'] ); } catch ( \Throwable $ignored ) {}
			}
		}
		status_header( self::$context['status'] );
		if ( in_array( self::$context['status'], array( 429, 503 ), true ) ) { header( 'Retry-After: 60' ); }
	}

	private static function parameters(): array {
		$params = array( 'page' => self::integer( $_GET['page'] ?? '1', 1, 1000 ) );
		foreach ( array( 'skill', 'review', 'revision' ) as $key ) { if ( isset( $_GET[ $key ] ) ) { $params[ $key ] = self::integer( $_GET[ $key ], 1, PHP_INT_MAX ); } }
		foreach ( array( 'q' => 400, 'status' => 24, 'sort' => 16 ) as $key => $max ) { if ( isset( $_GET[ $key ] ) ) { $params[ $key ] = self::text( $_GET[ $key ], $max ); } }
		return $params;
	}
	public static function integer( mixed $value, int $min = 1, int $max = PHP_INT_MAX ): int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$value = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => $min, 'max_range' => $max ) ) );
		if ( false === $value ) { throw new \InvalidArgumentException( 'invalid_input' ); } return $value;
	}
	private static function text( mixed $value, int $max ): string {
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$value = wp_unslash( $value );
		if ( strlen( $value ) > $max || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		return $value;
	}
	private static function input( string $name, int $max = 20000 ): string { return self::text( $_POST[ $name ] ?? '', $max ); }
	public static function fields( string $action ): string {
		$key = bin2hex( random_bytes( 16 ) );
		if ( self::$context['error'] && ( $_POST['community_action'] ?? '' ) === $action && is_string( $_POST['request_key'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $_POST['request_key'] ) ) { $key = $_POST['request_key']; }
		return '<input type="hidden" name="community_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="request_key" value="' . esc_attr( $key ) . '">' . wp_nonce_field( 'aish_community_' . $action, '_aish_nonce', false, false );
	}
	private static function checkCsrf( string $action ): void {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ( ! is_string( $origin ) || ( ! in_array( $origin, array( '', 'null' ), true ) && ! self::sameOrigin( $origin, Router::siteUrl() ) ) || ! wp_verify_nonce( self::input( '_aish_nonce', 64 ), 'aish_community_' . $action ) ) { throw new \RuntimeException( 'csrf' ); }
	}
	private static function sameOrigin( string $origin, string $site ): bool {
		$a = wp_parse_url( $origin ); $b = wp_parse_url( $site );
		return is_array( $a ) && is_array( $b ) && ! isset( $a['user'] ) && ! isset( $a['pass'] ) && strtolower( (string) ( $a['host'] ?? '' ) ) === strtolower( (string) ( $b['host'] ?? '' ) ) && ( $a['scheme'] ?? '' ) === ( $b['scheme'] ?? '' ) && (int) ( $a['port'] ?? ( 'https' === ( $a['scheme'] ?? '' ) ? 443 : 80 ) ) === (int) ( $b['port'] ?? ( 'https' === ( $b['scheme'] ?? '' ) ? 443 : 80 ) );
	}
	private function handlePost(): never {
		$route = self::$context['route']; $locale = self::$context['locale']; $params = self::$context['params']; $action = self::input( 'community_action', 32 );
		$allowed = array( 'favorites' => array( 'favorite' ), 'reviews' => array(), 'favorite' => array( 'favorite' ), 'review' => array( 'review', 'withdraw' ), 'report-review' => array( 'report_review' ), 'report-skill' => array( 'report_skill' ) );
		if ( ! in_array( $action, array_merge( $allowed[ $route ], array( 'logout' ) ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		self::checkCsrf( $action );
		if ( 'logout' === $action ) { $this->accounts->logout(); wp_safe_redirect( Accounts::url( 'login', $locale ), 303 ); exit; }
		if ( '' !== self::input( 'company', 200 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$request = self::input( 'request_key', 64 ); $skillId = $params['skill'] ?? 0;
		if ( 'favorite' === $action ) {
			$target = self::input( 'active', 1 ); if ( ! in_array( $target, array( '0', '1' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
			if ( 'favorites' === $route ) { $skillId = self::integer( self::input( 'skill_id', 20 ) ); }
			$this->service->setFavorite( $skillId, '1' === $target, $locale, $request );
			$this->flash( '1' === $target ? 'favorite_saved' : 'favorite_removed' );
			$this->redirect( 'favorites', array() );
		}
		if ( 'review' === $action ) {
			$payload = array(); foreach ( array( 'title' => 764, 'comment' => 32000, 'experience' => 8000, 'pros' => 8000, 'cons' => 8000, 'platform' => 400, 'version_label' => 764, 'locale' => 10 ) as $key => $max ) { $payload[ $key ] = self::input( $key, $max ); }
			$payload['rating'] = self::integer( self::input( 'rating', 1 ), 1, 5 );
			$this->service->submitReview( $skillId, $payload, self::integer( self::input( 'lock_version', 20 ), 0 ), $request );
			$this->flash( 'review_submitted' ); $this->redirect( 'review', array( 'skill' => $skillId ) );
		}
		if ( 'withdraw' === $action ) {
			if ( '1' !== self::input( 'confirm_withdraw', 1 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
			$reviewId = self::integer( self::input( 'review_id', 20 ) );
			if ( $reviewId !== (int) ( self::$context['head']['id'] ?? 0 ) ) { throw new Exception( 'forbidden' ); }
			$this->service->withdrawReview( $reviewId, self::integer( self::input( 'lock_version', 20 ), 1 ), $request );
			$this->flash( 'review_withdrawn' ); $this->redirect( 'reviews', array() );
		}
		if ( 'report_review' === $action ) {
			$this->service->reportReview( $params['review'] ?? 0, $params['revision'] ?? 0, array( 'reason_code' => self::input( 'reason_code', 32 ), 'details' => self::input( 'details', 16000 ), 'locale' => $locale ), $request );
		} elseif ( 'report_skill' === $action ) {
			$this->service->reportSkill( $skillId, array( 'kind' => self::input( 'kind', 32 ), 'details' => self::input( 'details', 16000 ), 'locale' => self::input( 'content_locale', 10 ), 'skill_revision' => self::integer( self::input( 'skill_revision', 20 ) ) ), $request );
		}
		$this->flash( 'report_submitted' ); $this->redirect( 'reviews', array() );
	}
	private static function safeValues(): array {
		$result = array();
		foreach ( array( 'title' => 764, 'comment' => 32000, 'experience' => 8000, 'pros' => 8000, 'cons' => 8000, 'platform' => 400, 'version_label' => 764, 'locale' => 10, 'rating' => 1, 'details' => 16000, 'reason_code' => 32, 'kind' => 32, 'content_locale' => 10 ) as $key => $max ) {
			try { $result[ $key ] = self::input( $key, $max ); } catch ( \Throwable $ignored ) { $result[ $key ] = ''; }
		}
		return $result;
	}
	private function redirect( string $route, array $params ): never { wp_safe_redirect( self::url( $route, self::$context['locale'], $params ), 303 ); exit; }
	private static function flashName(): string { return ( is_ssl() ? '__Host-' : '' ) . 'aish_community_flash'; }
	private function flash( string $code ): void {
		$data = base64_encode( (string) wp_json_encode( array( 'code' => $code, 'user' => get_current_user_id(), 'expires' => time() + 120 ) ) );
		setcookie( self::flashName(), $data . '.' . hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) ), array( 'expires' => time() + 120, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}
	private function readFlash(): void {
		$cookie = $_COOKIE[ self::flashName() ] ?? '';
		setcookie( self::flashName(), '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		if ( ! is_string( $cookie ) || strlen( $cookie ) > 512 || 1 !== substr_count( $cookie, '.' ) ) { return; }
		[ $data, $signature ] = explode( '.', $cookie, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) ), $signature ) ) { return; }
		$value = json_decode( (string) base64_decode( $data, true ), true );
		if ( ! is_array( $value ) || ( $value['user'] ?? null ) !== get_current_user_id() || ! is_int( $value['expires'] ?? null ) || $value['expires'] < time() || ! in_array( $value['code'] ?? '', array( 'favorite_saved', 'favorite_removed', 'review_submitted', 'review_withdrawn', 'report_submitted' ), true ) ) { return; }
		self::$context['message'] = $value['code'];
	}

	public function skillActions( array $skill, string $locale ): void {
		echo '<div class="community-actions"><a class="account-button account-button-primary" href="' . esc_url( self::url( 'favorite', $locale, array( 'skill' => $skill['id'] ) ) ) . '">' . esc_html( self::t( 'save_skill', $locale ) ) . '</a><a class="account-button account-button-secondary" href="#community-reviews">' . esc_html( self::t( 'community', $locale ) ) . '</a></div>';
	}
	public function publicSection( array $skill, string $locale ): void {
		$data = array( 'skill' => $skill, 'locale' => $locale, 'error' => false );
		try {
			$page = self::integer( $_GET['reviews_page'] ?? '1', 1, 1000 );
			$data['summary'] = $this->query->publicSummary( (int) $skill['id'], $locale );
			$data['listing'] = $this->query->publicReviews( (int) $skill['id'], $locale, array( 'page' => $page, 'per_page' => 10 ) );
		} catch ( \Throwable $error ) { $data['error'] = true; }
		get_template_part( 'templates/community/public', null, $data );
	}
	public function navigation( string $locale, string $route = '' ): void {
		foreach ( array( 'favorites', 'reviews' ) as $item ) { echo '<a href="' . esc_url( self::url( $item, $locale ) ) . '"' . ( $route === $item ? ' aria-current="page"' : '' ) . '>' . esc_html( self::t( $item, $locale ) ) . '</a>'; }
	}
	public function dashboard( string $locale ): void {
		try { $data = $this->query->dashboard( $locale ); }
		catch ( \Throwable $error ) { echo '<p class="account-notice">' . esc_html( self::t( 'error.storage_unavailable', $locale ) ) . '</p>'; return; }
		echo '<section class="account-summary community-dashboard">';
		foreach ( array( 'favorites' => 'favorite_count', 'reviews' => 'review_count' ) as $route => $key ) {
			echo '<a class="account-panel" href="' . esc_url( self::url( $route, $locale ) ) . '"><h2>' . esc_html( self::t( $route, $locale ) ) . '</h2><p class="community-count">' . esc_html( (string) $data[ $key ] ) . '</p></a>';
		}
		echo '</section>';
	}
}
