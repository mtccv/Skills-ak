<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;

use AISkillsHub\Core\Accounts\{AccountService, Frontend as AccountFrontend};
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Private subscription choices; native accounts remain the only identity source. */
final class SubscriptionFrontend {
	private const ROUTES = array( 'newsletter', 'confirm', 'unsubscribe' );
	private static bool $active = false;
	private static string $browser = '';
	private static array $context = array( 'route' => 'newsletter', 'locale' => 'zh_CN', 'status' => 200, 'error' => '', 'message' => '', 'preferences' => null, 'credential' => false, 'values' => array() );
	public function __construct( private Subscriptions $service, private AccountService $accounts ) {}
	public static function active(): bool { return self::$active; }
	public static function context(): array { return self::$context; }
	public static function t( string $zh, string $en ): string { return 'zh_CN' === self::$context['locale'] ? $zh : $en; }
	public static function url( string $route = 'newsletter', string $locale = 'zh_CN' ): string {
		if ( ! in_array( $route, self::ROUTES, true ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_route' ); }
		return Router::siteUrl( '/' . ( 'zh_CN' === $locale ? 'zh' : 'en' ) . '/newsletter/' . ( 'newsletter' === $route ? '' : $route . '/' ) );
	}
	public function register(): void {
		add_action( 'parse_request', array( $this, 'parse' ), 14 );
		add_action( 'template_redirect', array( $this, 'resolve' ), -11 );
		add_filter( 'posts_pre_query', static function ( $posts, $query ) { if ( self::$active && $query->is_main_query() ) { $query->found_posts = 0; $query->max_num_pages = 0; return array(); } return $posts; }, 8, 2 );
		add_filter( 'pre_handle_404', static fn( $value ) => self::$active ? true : $value );
		add_filter( 'redirect_canonical', static fn( $url ) => self::$active ? false : $url );
		add_filter( 'template_include', static fn( string $file ): string => self::$active ? get_template_directory() . '/templates/learning/subscriptions.php' : $file, 100 );
		add_filter( 'pre_get_document_title', static fn( string $title ): string => self::$active ? self::t( '邮件订阅', 'Email subscriptions' ) . ' · AI Skills Hub' : $title, PHP_INT_MAX );
		add_filter( 'wp_robots', static fn( array $robots ): array => self::$active ? array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true ) : $robots, PHP_INT_MAX );
		add_filter( 'wpseo_frontend_presenters', static fn( array $presenters ): array => self::$active ? array() : $presenters, PHP_INT_MAX );
		add_filter( 'wpml_hreflangs', static fn( $links ) => self::$active ? array() : $links, PHP_INT_MAX );
		add_filter( 'show_admin_bar', static fn( bool $show ): bool => self::$active ? false : $show );
		add_action( 'aish_account_navigation', static function ( string $locale ): void { echo '<a href="' . esc_url( self::url( 'newsletter', $locale ) ) . '">' . esc_html( 'zh_CN' === $locale ? '邮件订阅' : 'Email subscriptions' ) . '</a>'; } );
		add_action( 'admin_post_aish_subscription_unsubscribe', array( $this, 'oneClick' ) );
		add_action( 'admin_post_nopriv_aish_subscription_unsubscribe', array( $this, 'oneClick' ) );
	}
	public function parse( \WP $wp ): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $wp->query_vars['rest_route'] ) || isset( $wp->query_vars['feed'] ) ) { return; }
		$uri = $_SERVER['REQUEST_URI'] ?? ''; $path = is_string( $uri ) && strlen( $uri ) <= 4096 ? wp_parse_url( $uri, PHP_URL_PATH ) : null;
		$base = rtrim( (string) wp_parse_url( Router::siteUrl(), PHP_URL_PATH ), '/' );
		if ( ! is_string( $path ) || ( '' !== $base && ! str_starts_with( $path, $base . '/' ) ) ) { return; }
		$path = preg_replace( '#^index\.php(?:/|$)#', '', trim( substr( $path, strlen( $base ) ), '/' ) );
		if ( ! is_string( $path ) || ! preg_match( '#^(zh|en)/newsletter(?:/(confirm|unsubscribe))?$#D', $path, $parts ) ) { return; }
		self::$active = true; self::$context['route'] = $parts[2] ?? 'newsletter'; self::$context['locale'] = 'zh' === $parts[1] ? 'zh_CN' : 'en_US';
		$wp->query_vars = array( 'aish_route' => 'newsletter', 'aish_language' => $parts[1] );
	}
	public static function privateHeaders(): void {
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); header( 'Referrer-Policy: no-referrer' ); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Frame-Options: SAMEORIGIN' );
	}
	public function resolve(): void {
		if ( ! self::$active ) { return; } self::privateHeaders();
		foreach ( array( 'rel_canonical', 'wp_shortlink_wp_head', 'rest_output_link_wp_head', 'wp_oembed_add_discovery_links' ) as $callback ) { remove_action( 'wp_head', $callback ); }
		remove_all_actions( 'rank_math/head' ); add_action( 'wp_head', '_wp_render_title_tag', 1 );
		$GLOBALS['wp_query']->is_404 = false; $GLOBALS['wp_query']->is_home = false;
		try {
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
			if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) ) { self::$context['status'] = 405; header( 'Allow: GET, HEAD, POST' ); throw new \InvalidArgumentException( 'invalid_input' ); }
			$this->browserContext( 'GET' === $method );
			if ( 'GET' === $method && array_key_exists( 'token', $_GET ) ) {
				$route = self::$context['route']; $raw = $_GET['token'];
				if ( in_array( $route, array( 'confirm', 'unsubscribe' ), true ) && is_string( $raw ) && preg_match( '/^[A-Za-z0-9._~-]{16,512}$/D', $raw ) ) { $this->storeCredential( $route, $raw ); }
				else { $this->clearCredential( $route ); }
				wp_safe_redirect( self::url( $route, self::$context['locale'] ), 303 ); exit;
			}
			self::$context['eligible'] = is_user_logged_in() && $this->accounts->verified();
			self::$context['availability'] = $this->service->publicStatus();
			if ( self::$context['eligible'] ) { self::$context['preferences'] = $this->service->accountPreferences(); }
			if ( 'GET' === $method ) { $this->readFlash(); }
			if ( 'POST' === $method ) { $this->receive(); $this->flash( self::$context['message'] ); wp_safe_redirect( self::url( self::$context['route'], self::$context['locale'] ), 303 ); exit; }
			if ( 'newsletter' !== self::$context['route'] ) {
				$token = $this->readCredential( self::$context['route'] );
				self::$context['credential'] = '' !== $token && null !== $this->service->tokenContext( $token, self::$context['route'] );
			}
		} catch ( \Throwable $error ) {
			$reason = $error->getMessage(); if ( in_array( $reason, array( 'revision_conflict', 'request_conflict' ), true ) ) { $reason = 'conflict'; }
			self::$context['error'] = in_array( $reason, array( 'csrf', 'invalid_input', 'invalid_token', 'rate_limited', 'forbidden', 'verification_required', 'conflict' ), true ) ? $reason : 'unavailable';
			if ( 200 === self::$context['status'] ) { self::$context['status'] = match ( self::$context['error'] ) { 'csrf', 'forbidden', 'verification_required' => 403, 'rate_limited' => 429, 'unavailable' => 503, 'conflict' => 409, default => 400 }; }
		}
		status_header( self::$context['status'] ); if ( in_array( self::$context['status'], array( 429, 503 ), true ) ) { header( 'Retry-After: 60' ); }
	}
	private function receive(): void {
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && ( ! ctype_digit( (string) $_SERVER['CONTENT_LENGTH'] ) || (int) $_SERVER['CONTENT_LENGTH'] > 8192 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$data = wp_unslash( $_POST );
		if ( array_diff( array_keys( $data ), array( 'subscription_action', 'locale', 'weekly', 'new_resources', 'consent', 'request_key', '_aish_nonce', 'subscription_csrf' ) ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$action = $data['subscription_action'] ?? null;
		$allowed = 'newsletter' === self::$context['route'] ? array( 'request', 'stop' ) : array( self::$context['route'] );
		if ( ! is_string( $action ) || ! in_array( $action, $allowed, true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$this->csrf( $action, $data );
		if ( 'request' === $action ) {
			if ( empty( self::$context['eligible'] ) ) { throw new \DomainException( 'forbidden' ); }
			$locale = $data['locale'] ?? null; $weekly = $data['weekly'] ?? ''; $new = $data['new_resources'] ?? ''; $consent = $data['consent'] ?? '';
			if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || ! in_array( $weekly, array( '', '1' ), true ) || ! in_array( $new, array( '', '1' ), true ) || ! in_array( $consent, array( '', '1' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
			self::$context['values'] = array( 'locale' => $locale, 'weekly' => '1' === $weekly, 'new_resources' => '1' === $new );
			$key = $data['request_key'] ?? null;
			if ( '1' !== $consent || ( '' === $weekly && '' === $new ) || ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
			$this->service->request( self::$context['values'] + array( 'consent' => true ), $key ); self::$context['message'] = 'requested';
		} elseif ( 'stop' === $action ) { $this->service->accountUnsubscribe(); self::$context['message'] = 'unsubscribed'; }
		else {
			$token = $this->readCredential( $action ); if ( '' === $token ) { throw new \DomainException( 'invalid_token' ); }
			if ( 'confirm' === $action ) { if ( empty( self::$context['eligible'] ) ) { throw new \DomainException( 'forbidden' ); } if ( 'active' !== $this->service->confirm( $token ) ) { throw new \DomainException( 'invalid_token' ); } self::$context['message'] = 'confirmed'; }
			else { if ( ! $this->service->unsubscribe( $token ) ) { throw new \DomainException( 'invalid_token' ); } self::$context['message'] = 'unsubscribed'; }
			$this->clearCredential( $action );
		}
		if ( ! empty( self::$context['eligible'] ) ) { self::$context['preferences'] = $this->service->accountPreferences(); }
	}
	/** RFC8058 cancellation has bearer authorization; it never reads or expands an account. */
	public function oneClick(): never {
		self::privateHeaders();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { header( 'Allow: POST' ); wp_die( 'POST required.', '', array( 'response' => 405 ) ); }
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && ( ! ctype_digit( (string) $_SERVER['CONTENT_LENGTH'] ) || (int) $_SERVER['CONTENT_LENGTH'] > 4096 ) ) { wp_die( 'Invalid request.', '', array( 'response' => 413 ) ); }
		$token = $_GET['token'] ?? ''; $body = wp_unslash( $_POST );
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9._~-]{16,512}$/D', $token ) || array_diff( array_keys( $body ), array( 'List-Unsubscribe' ) ) || 'One-Click' !== ( $body['List-Unsubscribe'] ?? null ) ) { wp_die( 'Invalid request.', '', array( 'response' => 400 ) ); }
		try { $result = $this->service->unsubscribe( $token ); } catch ( \Throwable $error ) { wp_die( 'Cancellation is temporarily unavailable.', '', array( 'response' => 503 ) ); }
		if ( ! $result ) { wp_die( 'This cancellation link is invalid or expired.', '', array( 'response' => 400 ) ); }
		status_header( 200 ); header( 'Content-Type: text/plain; charset=UTF-8' ); echo 'Unsubscribed.'; exit;
	}
	private function browserContext( bool $create ): void {
		$browser = $_COOKIE[ self::cookieName( 'browser' ) ] ?? '';
		if ( ! is_string( $browser ) || ! preg_match( '/^[a-f0-9]{64}$/D', $browser ) ) { $browser = $create ? bin2hex( random_bytes( 32 ) ) : ''; if ( $create ) { self::cookie( 'browser', $browser, time() + 1800 ); } }
		self::$browser = $browser;
	}
	public static function fields( string $action ): string {
		if ( ! in_array( $action, array( 'request', 'stop', 'confirm', 'unsubscribe' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$signature = hash_hmac( 'sha256', $action . '|' . get_current_user_id() . '|' . self::$browser, wp_salt( 'nonce' ) );
		return '<input type="hidden" name="subscription_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="subscription_csrf" value="' . esc_attr( $signature ) . '">' . wp_nonce_field( 'aish_subscription_' . $action, '_aish_nonce', false, false );
	}
	private function csrf( string $action, array $data ): void {
		$nonce = $data['_aish_nonce'] ?? null; $signature = $data['subscription_csrf'] ?? null; $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ( ! is_string( $origin ) || ( ! in_array( $origin, array( '', 'null' ), true ) && ! self::sameOrigin( $origin, Router::siteUrl() ) ) || '' === self::$browser || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'aish_subscription_' . $action ) || ! is_string( $signature ) || ! hash_equals( hash_hmac( 'sha256', $action . '|' . get_current_user_id() . '|' . self::$browser, wp_salt( 'nonce' ) ), $signature ) ) { throw new \DomainException( 'csrf' ); }
	}
	private static function sameOrigin( string $left, string $right ): bool {
		$a = wp_parse_url( $left ); $b = wp_parse_url( $right );
		return is_array( $a ) && is_array( $b ) && ! isset( $a['user'] ) && ! isset( $a['pass'] ) && strtolower( (string) ( $a['host'] ?? '' ) ) === strtolower( (string) ( $b['host'] ?? '' ) ) && ( $a['scheme'] ?? '' ) === ( $b['scheme'] ?? '' ) && (int) ( $a['port'] ?? ( 'https' === ( $a['scheme'] ?? '' ) ? 443 : 80 ) ) === (int) ( $b['port'] ?? ( 'https' === ( $b['scheme'] ?? '' ) ? 443 : 80 ) );
	}
	private static function cookieName( string $kind ): string { return ( is_ssl() ? '__Host-' : '' ) . 'aish_newsletter_' . $kind; }
	private static function cookie( string $kind, string $value, int $expires ): void { setcookie( self::cookieName( $kind ), $value, array( 'expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ); if ( '' === $value ) { unset( $_COOKIE[ self::cookieName( $kind ) ] ); } else { $_COOKIE[ self::cookieName( $kind ) ] = $value; } }
	private function storeCredential( string $kind, #[\SensitiveParameter] string $token ): void {
		$value = base64_encode( (string) wp_json_encode( array( 'token' => $token, 'expires' => time() + 900, 'browser' => hash( 'sha256', self::$browser ) ) ) );
		self::cookie( $kind, $value . '.' . hash_hmac( 'sha256', $kind . '|' . $value, wp_salt( 'auth' ) ), time() + 900 );
	}
	private function readCredential( string $kind ): string {
		$raw = $_COOKIE[ self::cookieName( $kind ) ] ?? ''; if ( ! is_string( $raw ) || strlen( $raw ) > 2048 || 1 !== substr_count( $raw, '.' ) ) { return ''; }
		[ $encoded, $signature ] = explode( '.', $raw, 2 ); if ( ! hash_equals( hash_hmac( 'sha256', $kind . '|' . $encoded, wp_salt( 'auth' ) ), $signature ) ) { return ''; }
		$data = json_decode( (string) base64_decode( $encoded, true ), true );
		return is_array( $data ) && is_int( $data['expires'] ?? null ) && $data['expires'] >= time() && is_string( $data['browser'] ?? null ) && hash_equals( hash( 'sha256', self::$browser ), $data['browser'] ) && is_string( $data['token'] ?? null ) ? $data['token'] : '';
	}
	private function flash( string $message ): void {
		if ( ! in_array( $message, array( 'requested', 'confirmed', 'unsubscribed' ), true ) ) { return; }
		$this->storeCredential( 'flash', $message );
	}
	private function readFlash(): void {
		$message = $this->readCredential( 'flash' ); $this->clearCredential( 'flash' );
		if ( in_array( $message, array( 'requested', 'confirmed', 'unsubscribed' ), true ) ) { self::$context['message'] = $message; }
	}

	private function clearCredential( string $kind ): void { self::cookie( $kind, '', time() - 3600 ); }
}
