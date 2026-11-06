<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Private SSR account routes. Authentication remains the WordPress Users/session system. */
final class Frontend {
	private const ROUTES = array( 'register', 'login', 'verify-email', 'forgot-password', 'reset-password', 'account', 'profile', 'settings', 'security', 'privacy', 'notifications' );
	private const PRIVATE_ROUTES = array( 'account', 'profile', 'settings', 'security', 'privacy', 'notifications' );
	private static bool $active = false;
	private static array $context = array( 'route' => 'login', 'locale' => 'zh_CN', 'status' => 200, 'error' => '', 'message' => '', 'values' => array(), 'profile' => array() );
	private static string $browser = '';

	public function __construct( private AccountService $accounts, private Store $store, private Privacy $privacy, private Avatar $avatar ) {}
	public static function active(): bool { return self::$active; }
	public static function context(): array { return self::$context; }
	public static function t( string $key ): string { return Strings::get( $key, self::$context['locale'] ); }

	public static function url( string $route, ?string $locale = null ): string {
		if ( ! in_array( $route, self::ROUTES, true ) ) { throw new \InvalidArgumentException( 'Invalid account route.' ); }
		$locale ??= self::$context['locale'];
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'Invalid locale.' ); }
		$path = in_array( $route, self::PRIVATE_ROUTES, true ) && 'account' !== $route ? 'account/' . $route : $route;
		$url = Router::siteUrl( '/' . ( 'zh_CN' === $locale ? 'zh' : 'en' ) . '/' . $path . '/' );
		// Compatible with the existing public URL adapter; account destinations stay on this site.
		try { $adjusted = apply_filters( 'aish_account_url', $url, $route, $locale ); }
		catch ( \Throwable $error ) { return $url; }
		if ( ! is_string( $adjusted ) || ! self::sameOrigin( $adjusted, Router::siteUrl() ) || preg_match( '/[\x00-\x20\x7f]/', $adjusted ) || false !== strpos( $adjusted, '?' ) || false !== strpos( $adjusted, '#' ) ) { return $url; }
		return $adjusted;
	}

	/** Only explicit public Skill/account workflows may become post-login destinations. */
	public static function safeReturn( mixed $url ): ?string {
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || ! self::sameOrigin( $url, Router::siteUrl() ) ) { return null; }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['fragment'] ) ) { return null; }
		$base = rtrim( (string) wp_parse_url( Router::siteUrl(), PHP_URL_PATH ), '/' );
		$path = $parts['path'] ?? '';
		if ( '' !== $base && ! str_starts_with( $path, $base . '/' ) ) { return null; }
		$relative = substr( $path, strlen( $base ) );
		if ( preg_match( '#^/(zh|en)/skills/[1-9][0-9]{0,18}/[a-z0-9-]{1,191}/$#D', $relative ) ) { return empty( $parts['query'] ) ? Router::siteUrl( $relative ) : null; }
		$isSubmit = (bool) preg_match( '#^/(zh|en)/submit/$#D', $relative );
		if ( ! $isSubmit && ! preg_match( '#^/(zh|en)/account(?:/(profile|settings|security|privacy|notifications|favorites|reviews|review|favorite|report-review|report-skill|submissions|submission|update-suggestion|developer|claim))?/$#D', $relative, $match ) ) { return null; }
		if ( empty( $parts['query'] ) ) { return Router::siteUrl( $relative ); }
		$query = array(); parse_str( $parts['query'], $query );
		$route = $isSubmit ? 'submit' : ( $match[2] ?? 'account' );
		$allowed = match ( $route ) { 'favorites' => array( 'page', 'q', 'status', 'sort' ), 'reviews' => array( 'page', 'status' ), 'review', 'favorite' => array( 'skill', 'page' ), 'report-review' => array( 'skill', 'review', 'revision', 'page' ), 'report-skill', 'update-suggestion' => array( 'skill', 'page' ), 'submissions' => array( 'page', 'status', 'kind' ), 'submission' => array( 'id', 'page' ), 'claim' => array( 'id', 'skill', 'page' ), 'developer' => array( 'page', 'state' ), default => array( 'page' ) };
		if ( array_diff( array_keys( $query ), $allowed ) ) { return null; }
		foreach ( $query as $key => $value ) {
			if ( ! is_string( $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) || ! preg_match( '//u', $value ) ) { return null; }
			if ( 'q' === $key ) { if ( strlen( $value ) > 400 ) { return null; } continue; }
			if ( 'status' === $key ) { $states = match ( $route ) { 'favorites' => array( 'all', 'available', 'unavailable' ), 'submissions' => array( 'all', 'draft', 'submitted', 'in_review', 'needs_changes', 'accepted', 'rejected', 'withdrawn' ), default => array( 'all', 'pending', 'approved', 'rejected', 'hidden', 'withdrawn' ) }; if ( ! in_array( $value, $states, true ) ) { return null; } continue; }
			if ( 'kind' === $key ) { if ( ! in_array( $value, array( 'all', 'submit', 'update_suggestion' ), true ) ) { return null; } continue; }
			if ( 'state' === $key ) { if ( ! in_array( $value, array( 'all', 'pending', 'approved', 'rejected', 'withdrawn', 'revoked', 'disputed' ), true ) ) { return null; } continue; }
			if ( 'sort' === $key ) { if ( ! in_array( $value, array( 'newest', 'title' ), true ) ) { return null; } continue; }
			if ( ! preg_match( '/^[1-9][0-9]{0,18}$/D', $value ) || false === filter_var( $value, FILTER_VALIDATE_INT ) || ( 'page' === $key && (int) $value > 1000 ) ) { return null; }
		}
		return $query ? add_query_arg( array_map( 'rawurlencode', $query ), Router::siteUrl( $relative ) ) : Router::siteUrl( $relative );
	}
	public static function loginUrl( string $destination, string $locale ): string {
		$destination = self::safeReturn( $destination );
		return $destination ? add_query_arg( 'return_to', rawurlencode( $destination ), self::url( 'login', $locale ) ) : self::url( 'login', $locale );
	}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'parse' ), 15 );
		add_action( 'template_redirect', array( $this, 'resolve' ), -10 );
		add_filter( 'posts_pre_query', static function ( $posts, $query ) { if ( self::$active && $query->is_main_query() ) { $query->found_posts = 0; $query->max_num_pages = 0; return array(); } return $posts; }, 9, 2 );
		add_filter( 'pre_handle_404', static fn( $value ) => self::$active ? true : $value );
		add_filter( 'redirect_canonical', static fn( $url ) => self::$active ? false : $url );
		add_filter( 'template_include', static fn( string $file ): string => self::$active ? get_template_directory() . '/templates/account/page.php' : $file, 99 );
		add_filter( 'pre_get_document_title', static fn( string $title ): string => self::$active ? self::t( self::$context['route'] ) . ' · AI Skills Hub' : $title, PHP_INT_MAX );
		add_filter( 'wp_robots', static function ( array $robots ): array { return self::$active ? array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true ) : $robots; }, PHP_INT_MAX );
		add_filter( 'wpseo_frontend_presenters', static fn( array $presenters ): array => self::$active ? array() : $presenters, PHP_INT_MAX );
		add_filter( 'wpml_hreflangs', static fn( $links ) => self::$active ? array() : $links, PHP_INT_MAX );
		add_filter( 'show_admin_bar', static fn( bool $show ): bool => self::$active ? false : $show );
		add_action( 'admin_init', function (): void {
			if ( ! is_user_logged_in() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || current_user_can( 'manage_options' ) ) { return; }
			if ( $this->accounts->restricted( wp_get_current_user() ) && 'admin-post.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
				$locale = 'en_US' === get_user_meta( get_current_user_id(), '_aish_profile_locale', true ) ? 'en_US' : 'zh_CN';
				nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); wp_safe_redirect( self::url( 'verify-email', $locale ), 302 ); exit;
			}
		} );
	}

	public function parse( \WP $wp ): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $wp->query_vars['rest_route'] ) || isset( $wp->query_vars['feed'] ) ) { return; }
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$path = is_string( $uri ) && strlen( $uri ) <= 4096 ? wp_parse_url( $uri, PHP_URL_PATH ) : null;
		$base = rtrim( (string) wp_parse_url( Router::siteUrl(), PHP_URL_PATH ), '/' );
		if ( ! is_string( $path ) || ( '' !== $base && ! str_starts_with( $path, $base . '/' ) ) ) { return; }
		$path = trim( substr( $path, strlen( $base ) ), '/' );
		$path = preg_replace( '#^index\.php(?:/|$)#', '', $path );
		if ( ! is_string( $path ) || ! preg_match( '#^(zh|en)/(register|login|verify-email|forgot-password|reset-password|account(?:/(?:profile|settings|security|privacy|notifications))?)$#D', $path, $parts ) ) { return; }
		$route = str_starts_with( $parts[2], 'account/' ) ? substr( $parts[2], 8 ) : $parts[2];
		self::$active = true;
		self::$context['route'] = $route;
		self::$context['locale'] = 'zh' === $parts[1] ? 'zh_CN' : 'en_US';
		$wp->query_vars = array( 'aish_route' => 'account', 'aish_language' => $parts[1] );
	}

	public function resolve(): void {
		if ( ! self::$active ) { return; }
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_all_actions( 'rank_math/head' );
		add_action( 'wp_head', '_wp_render_title_tag', 1 );
		$GLOBALS['wp_query']->is_404 = false;
		$GLOBALS['wp_query']->is_home = false;
		try {
			$this->browserContext();
			$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
			if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) ) { self::$context['status'] = 405; header( 'Allow: GET, HEAD, POST' ); throw new \RuntimeException( 'invalid_input' ); }
			if ( 'GET' === $method ) { $this->captureCredentials(); $this->readFlash(); }
			$route = self::$context['route'];
			if ( in_array( $route, self::PRIVATE_ROUTES, true ) && ! is_user_logged_in() ) { $this->storeCredential( 'return', $route ); $this->redirect( 'login' ); }
			if ( is_user_logged_in() && in_array( $route, self::PRIVATE_ROUTES, true ) && ! $this->accounts->verified() ) { $this->redirect( 'verify-email' ); }
			self::$context['ready'] = $this->accounts->ready();
			self::$context['verification_ready'] = '' !== $this->readCredential( 'verify' );
			self::$context['reset_ready'] = null !== $this->resetContext();
			self::$context['erase_confirm'] = ( '1' === ( $_GET['confirm'] ?? '' ) || 'erase' === ( $_POST['account_action'] ?? '' ) ) && 'privacy' === $route;
			if ( is_user_logged_in() ) { self::$context['profile'] = $this->accounts->profile(); }
			if ( 'POST' === $method ) { $this->handlePost(); }
			if ( is_user_logged_in() ) {
				self::$context['profile'] = $this->accounts->profile();
				if ( in_array( $route, self::PRIVATE_ROUTES, true ) ) {
					$user = $this->accounts->requireOwner();
					self::$context['avatar_url'] = $this->avatar->url();
					if ( in_array( $route, array( 'account', 'notifications' ), true ) ) {
						$page = filter_var( $_GET['page'] ?? '1', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 1000 ) ) );
						if ( false === $page ) { throw new \InvalidArgumentException( 'invalid_input' ); }
						self::$context['page'] = $page;
						self::$context['notifications'] = $this->store->notifications( (int) $user->ID, 'account' === $route ? 5 : 21, 'account' === $route ? 0 : ( $page - 1 ) * 20 );
					}
					if ( in_array( $route, array( 'settings', 'privacy' ), true ) ) { self::$context['preferences'] = $this->privacy->preferences(); }
					if ( 'privacy' === $route ) { self::$context['requests'] = $this->privacy->requests(); }
				}
				if ( in_array( $route, array( 'verify-email', 'security', 'account' ), true ) ) { self::$context['mail'] = $this->store->latestMail( get_current_user_id() ); }
			}
		} catch ( \Throwable $error ) {
			$reason = $error instanceof AccountException ? $error->reason() : ( in_array( $error->getMessage(), array( 'csrf', 'password_mismatch', 'invalid_input', 'avatar_invalid', 'rate_limited' ), true ) ? $error->getMessage() : 'unavailable' );
			if ( 'authentication_failed' === $error->getMessage() ) { $reason = 'current_password'; }
			if ( 'invalid_request' === $error->getMessage() ) { $reason = 'invalid_input'; }
			self::$context['error'] = $reason;
			self::$context['error_fields'] = match ( $reason ) {
				'auth_failed' => array( 'email', 'password' ), 'current_password' => array( 'current_password' ),
				'weak_password' => array( 'password', 'new_password' ), 'password_mismatch' => array( 'new_password', 'confirm_password' ),
				'email_conflict' => array( 'new_email' ), 'avatar_invalid' => array( 'avatar' ),
				'invalid_input' => array( 'email', 'display_name', 'description', 'url', 'new_email', 'terms' ), default => array(),
			};
			if ( 200 === self::$context['status'] ) { self::$context['status'] = in_array( $reason, array( 'unavailable', 'service_unavailable', 'save_failed' ), true ) ? 503 : ( 'rate_limited' === $reason ? 429 : ( 'csrf' === $reason ? 403 : 400 ) ); }
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { self::$context['values'] = $this->safeValues(); }
		}
		status_header( self::$context['status'] );
		if ( in_array( self::$context['status'], array( 429, 503 ), true ) ) { header( 'Retry-After: 60' ); }
	}

	private function handlePost(): void {
		$action = self::input( 'account_action', 32 );
		$allowed = array(
			'register' => array( 'register' ), 'login' => array( 'login' ), 'verify-email' => array( 'verify', 'resend', 'logout', 'email_change' ),
			'forgot-password' => array( 'forgot' ), 'reset-password' => array( 'reset' ), 'account' => array( 'logout' ),
			'profile' => array( 'profile', 'avatar_upload', 'avatar_remove', 'logout' ), 'settings' => array( 'preferences', 'logout' ),
			'security' => array( 'password', 'email_change', 'sessions', 'logout' ), 'privacy' => array( 'export', 'erase', 'logout' ),
			'notifications' => array( 'read', 'read_all', 'logout' ),
		);
		if ( ! in_array( $action, $allowed[ self::$context['route'] ], true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$this->checkCsrf( $action );
		$locale = self::$context['locale'];
		$message = 'saved'; $target = self::$context['route'];
		switch ( $action ) {
			case 'register':
				$this->accounts->register( array( 'email' => self::input( 'email', 254 ), 'password' => self::input( 'password', 4096 ), 'display_name' => self::input( 'display_name', 200 ), 'terms' => '1' === self::input( 'terms', 1 ), 'honeypot' => self::input( 'company', 200 ) ), self::localeInput() );
				$message = 'registration'; $target = 'verify-email'; break;
			case 'login':
				$this->accounts->login( self::input( 'email', 254 ), self::input( 'password', 4096 ), '1' === self::input( 'remember', 1 ) );
				$return = $this->readCredential( 'return' ); $this->clearCredential( 'return' ); $this->rotateBrowser();
				$destination = self::safeReturn( $return );
				if ( ! $this->accounts->verified() && $destination ) { $this->storeCredential( 'return', $destination ); }
				if ( $this->accounts->verified() && $destination ) { wp_safe_redirect( $destination, 303 ); exit; }
				$this->redirect( $this->accounts->verified() ? ( in_array( $return, self::PRIVATE_ROUTES, true ) ? $return : 'account' ) : 'verify-email' ); break;
			case 'logout': $this->accounts->logout(); $this->rotateBrowser(); $target = 'login'; $message = 'logout'; break;
			case 'verify':
				$token = $this->readCredential( 'verify' );
				if ( '' === $token ) { throw new AccountException( 'invalid_token' ); }
				$this->accounts->verify( $token ); $this->clearCredential( 'verify' );
				$message = 'verified'; $target = 'login'; break;
			case 'resend':
				$email = is_user_logged_in() ? (string) wp_get_current_user()->user_email : self::input( 'email', 254 );
				$this->accounts->resend( $email, $locale ); $message = 'resend'; break;
			case 'forgot': $this->accounts->forgot( self::input( 'email', 254 ), $locale ); $message = 'recovery'; break;
			case 'reset':
				$context = $this->resetContext();
				if ( ! $context ) { throw new AccountException( 'invalid_token' ); }
				$this->accounts->reset( $context['login'], $context['key'], self::newPassword() );
				$this->clearCredential( 'reset' ); $message = 'password'; $target = 'login'; break;
			case 'profile': $this->accounts->saveProfile( array( 'display_name' => self::input( 'display_name', 200 ), 'description' => self::input( 'description', 3000 ), 'url' => self::input( 'url', 2048 ), 'locale' => self::localeInput() ) ); break;
			case 'avatar_upload': $this->avatar->upload( $_FILES['avatar'] ?? array() ); break;
			case 'avatar_remove': $this->avatar->remove(); break;
			case 'password': $this->accounts->changePassword( self::input( 'current_password', 4096 ), self::newPassword() ); $message = 'password'; $target = 'login'; break;
			case 'email_change': $this->accounts->requestEmailChange( self::input( 'current_password', 4096 ), self::input( 'new_email', 254 ), $locale ); $message = 'email_change'; break;
			case 'sessions': $this->accounts->revokeOtherSessions( self::input( 'current_password', 4096 ) ); $message = 'sessions'; break;
			case 'preferences':
				$currentPreferences = $this->privacy->preferences()['notification_preferences'];
				$currentPreferences['locale'] = self::localeInput();
				$this->privacy->savePreferences( array( 'public_profile' => false, 'notification_preferences' => $currentPreferences ) ); break;
			case 'export': case 'erase':
				if ( 'erase' === $action && '1' !== self::input( 'confirm_erasure', 1 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
				$this->privacy->request( $action, self::input( 'current_password', 4096 ) ); $message = 'privacy'; break;
			case 'read': case 'read_all':
				$user = $this->accounts->requireOwner();
				$id = 'read_all' === $action ? null : filter_var( self::input( 'notification_id', 20 ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( false === $id || ! $this->store->markRead( (int) $user->ID, $id ) ) { throw new \RuntimeException( 'invalid_input' ); } break;
		}
		$this->flash( $message );
		$this->redirect( $target );
	}

	private static function newPassword(): string {
		$password = self::input( 'new_password', 4096 );
		if ( ! hash_equals( $password, self::input( 'confirm_password', 4096 ) ) ) { throw new \InvalidArgumentException( 'password_mismatch' ); }
		return $password;
	}
	private static function localeInput(): string { $locale = self::input( 'locale', 10 ); return in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ? $locale : self::$context['locale']; }
	private static function input( string $name, int $max ): string {
		$value = $_POST[ $name ] ?? '';
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$value = wp_unslash( $value );
		if ( strlen( $value ) > $max || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		return $value;
	}
	private function safeValues(): array {
		$values = array();
		foreach ( array( 'display_name' => 200, 'email' => 254, 'new_email' => 254, 'description' => 3000, 'url' => 2048, 'locale' => 10 ) as $field => $max ) {
			try { $values[ $field ] = self::input( $field, $max ); } catch ( \Throwable $error ) { $values[ $field ] = ''; }
		}
		return $values;
	}

	/** WP guest nonces are shared; add a browser-bound HttpOnly secret to every form. */
	private function browserContext(): void {
		$name = self::cookieName( 'browser' );
		$value = $_COOKIE[ $name ] ?? '';
		if ( is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) ) { self::$browser = $value; }
		elseif ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { throw new \RuntimeException( 'csrf' ); }
		else { $this->rotateBrowser(); }
	}
	private function rotateBrowser(): void { self::$browser = bin2hex( random_bytes( 32 ) ); self::cookie( 'browser', self::$browser, time() + DAY_IN_SECONDS ); }
	public static function fields( string $action ): string {
		$signature = hash_hmac( 'sha256', $action . '|' . get_current_user_id() . '|' . self::$browser, wp_salt( 'nonce' ) );
		return '<input type="hidden" name="account_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="account_csrf" value="' . esc_attr( $signature ) . '">' . wp_nonce_field( 'aish_account_' . $action, '_aish_nonce', false, false );
	}
	private function checkCsrf( string $action ): void {
		$expected = hash_hmac( 'sha256', $action . '|' . get_current_user_id() . '|' . self::$browser, wp_salt( 'nonce' ) );
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		// Chromium can send Origin:null for same-site forms under no-referrer. The
		// browser-bound HMAC and WP nonce remain mandatory; a foreign explicit origin is denied.
		if ( ( is_string( $origin ) && ! in_array( $origin, array( '', 'null' ), true ) && ! self::sameOrigin( $origin, Router::siteUrl() ) ) || ! wp_verify_nonce( self::input( '_aish_nonce', 64 ), 'aish_account_' . $action ) || ! hash_equals( $expected, self::input( 'account_csrf', 64 ) ) ) { throw new \RuntimeException( 'csrf' ); }
	}
	private static function sameOrigin( string $left, string $right ): bool {
		$a = wp_parse_url( $left ); $b = wp_parse_url( $right );
		return is_array( $a ) && is_array( $b ) && ! isset( $a['user'] ) && ! isset( $a['pass'] ) && strtolower( (string) ( $a['host'] ?? '' ) ) === strtolower( (string) ( $b['host'] ?? '' ) ) && ( $a['scheme'] ?? '' ) === ( $b['scheme'] ?? '' ) && (int) ( $a['port'] ?? ( 'https' === ( $a['scheme'] ?? '' ) ? 443 : 80 ) ) === (int) ( $b['port'] ?? ( 'https' === ( $b['scheme'] ?? '' ) ? 443 : 80 ) );
	}
	private static function cookieName( string $suffix ): string { return ( is_ssl() ? '__Host-' : '' ) . 'aish_account_' . $suffix; }
	private static function cookie( string $suffix, string $value, int $expires ): void {
		setcookie( self::cookieName( $suffix ), $value, array( 'expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		if ( '' === $value ) { unset( $_COOKIE[ self::cookieName( $suffix ) ] ); } else { $_COOKIE[ self::cookieName( $suffix ) ] = $value; }
	}
	private function captureCredentials(): void {
		$route = self::$context['route'];
		if ( in_array( $route, array( 'login', 'register', 'verify-email' ), true ) && array_key_exists( 'return_to', $_GET ) ) {
			$return = self::safeReturn( is_string( $_GET['return_to'] ) ? wp_unslash( $_GET['return_to'] ) : null );
			if ( $return ) { $this->storeCredential( 'return', $return ); }
			else { $this->clearCredential( 'return' ); }
			$this->redirect( $route );
		}
		if ( 'verify-email' === $route && array_key_exists( 'token', $_GET ) ) {
			$token = $_GET['token'];
			if ( is_string( $token ) && preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { $this->storeCredential( 'verify', $token ); }
			else { $this->clearCredential( 'verify' ); $this->flash( 'invalid_token', true ); }
			$this->redirect( $route );
		}
		if ( 'reset-password' === $route && ( isset( $_GET['key'] ) || isset( $_GET['login'] ) ) ) {
			$key = $_GET['key'] ?? ''; $login = $_GET['login'] ?? '';
			if ( is_string( $key ) && preg_match( '/^[a-zA-Z0-9]{16,128}$/D', $key ) && is_string( $login ) && strlen( $login ) <= 60 && '' !== $login && ! preg_match( '/[\x00-\x1f\x7f]/', $login ) ) { $this->storeCredential( 'reset', (string) wp_json_encode( array( 'key' => $key, 'login' => wp_unslash( $login ) ) ) ); }
			else { $this->clearCredential( 'reset' ); $this->flash( 'invalid_token', true ); }
			$this->redirect( $route );
		}
	}
	private function storeCredential( string $kind, #[\SensitiveParameter] string $value ): void {
		$payload = base64_encode( (string) wp_json_encode( array( 'value' => $value, 'expires' => time() + 1800, 'browser' => hash( 'sha256', self::$browser ) ) ) );
		self::cookie( $kind, $payload . '.' . hash_hmac( 'sha256', $kind . '|' . $payload, wp_salt( 'auth' ) ), time() + 1800 );
	}
	private function readCredential( string $kind ): string {
		$cookie = $_COOKIE[ self::cookieName( $kind ) ] ?? '';
		if ( ! is_string( $cookie ) || strlen( $cookie ) > 2048 || 1 !== substr_count( $cookie, '.' ) ) { return ''; }
		list( $payload, $signature ) = explode( '.', $cookie, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $kind . '|' . $payload, wp_salt( 'auth' ) ), $signature ) ) { return ''; }
		$data = json_decode( (string) base64_decode( $payload, true ), true );
		if ( ! is_array( $data ) || ! is_int( $data['expires'] ?? null ) || $data['expires'] < time() || ! is_string( $data['browser'] ?? null ) || ! hash_equals( hash( 'sha256', self::$browser ), $data['browser'] ) || ! is_string( $data['value'] ?? null ) ) { return ''; }
		return $data['value'];
	}
	private function clearCredential( string $kind ): void { self::cookie( $kind, '', time() - HOUR_IN_SECONDS ); }
	private function resetContext(): ?array {
		$value = $this->readCredential( 'reset' );
		$data = json_decode( $value, true );
		return is_array( $data ) && is_string( $data['login'] ?? null ) && is_string( $data['key'] ?? null ) ? $data : null;
	}
	private function flash( string $code, bool $error = false ): void {
		$payload = base64_encode( (string) wp_json_encode( array( 'code' => $code, 'error' => $error, 'expires' => time() + 120, 'browser' => hash( 'sha256', self::$browser ) ) ) );
		self::cookie( 'flash', $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ), time() + 120 );
	}
	private function readFlash(): void {
		$value = $_COOKIE[ self::cookieName( 'flash' ) ] ?? ''; self::cookie( 'flash', '', time() - HOUR_IN_SECONDS );
		if ( ! is_string( $value ) || strlen( $value ) > 1024 || 1 !== substr_count( $value, '.' ) ) { return; }
		list( $payload, $signature ) = explode( '.', $value, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ), $signature ) ) { return; }
		$data = json_decode( (string) base64_decode( $payload, true ), true );
		if ( is_array( $data ) && is_string( $data['code'] ?? null ) && is_string( $data['browser'] ?? null ) && is_int( $data['expires'] ?? null ) && $data['expires'] >= time() && hash_equals( hash( 'sha256', self::$browser ), $data['browser'] ) ) { self::$context[ ! empty( $data['error'] ) ? 'error' : 'message' ] = $data['code']; }
	}
	private function redirect( string $route ): never { wp_safe_redirect( self::url( $route ), 303 ); exit; }

	public static function maskEmail( string $email ): string {
		$parts = explode( '@', $email, 2 );
		if ( 2 !== count( $parts ) ) { return '•••'; }
		$local = preg_split( '//u', $parts[0], -1, PREG_SPLIT_NO_EMPTY );
		return ( $local[0] ?? '' ) . '•••@' . $parts[1];
	}
}
