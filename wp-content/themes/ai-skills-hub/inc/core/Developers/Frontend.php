<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Developers;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Frontend as Accounts;
use AISkillsHub\Core\Contributions\Ui;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Public pages contain only Query's approved projection; private forms remain owner-scoped. */
final class Frontend {
	private static bool $active = false;
	private static array $context = array( 'route' => 'developer', 'locale' => 'zh_CN', 'status' => 200, 'error' => '', 'message' => '', 'values' => array(), 'profile' => array(), 'params' => array(), 'alternatives' => array(), 'canonical' => '', 'public' => false );
	public function __construct( private Service $service, private Query $query, private AccountService $accounts, private PublicCatalog $catalog ) {}
	public static function active(): bool { return self::$active; }
	public static function context(): array { return self::$context; }
	public static function t( string $key, ?string $locale = null ): string { return Strings::get( $key, $locale ?? self::$context['locale'] ); }
	public static function url( string $route, string $locale, array $args = array() ): string {
		if ( ! in_array( $route, array( 'developer', 'claim', 'public', 'avatar' ), true ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$base = '/' . ( 'zh_CN' === $locale ? 'zh' : 'en' ) . '/'; $query = array();
		if ( in_array( $route, array( 'public', 'avatar' ), true ) ) {
			$handle = $args['handle'] ?? ''; if ( ! is_string( $handle ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/D', $handle ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
			$base .= 'developers/' . $handle . '/' . ( 'avatar' === $route ? 'avatar/' : '' );
		} else { $base .= 'account/' . $route . '/'; }
		foreach ( array( 'id', 'skill', 'page', 'state' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] || ( in_array( $route, array( 'public', 'avatar' ), true ) && 'page' !== $key ) ) { continue; }
			$query[ $key ] = 'state' === $key ? Ui::text( $args[ $key ], 20 ) : (string) Ui::integer( $args[ $key ], 1, 'page' === $key ? 1000 : PHP_INT_MAX );
		}
		$url = Router::siteUrl( $base ); return $query ? add_query_arg( array_map( 'rawurlencode', $query ), $url ) : $url;
	}
	public function register(): void {
		add_action( 'parse_request', array( $this, 'parse' ), 16 ); add_action( 'template_redirect', array( $this, 'resolve' ), -9 );
		add_filter( 'posts_pre_query', static function ( $posts, $query ) { if ( self::$active && $query->is_main_query() ) { $query->found_posts = 0; $query->max_num_pages = 0; return array(); } return $posts; }, 9, 2 );
		add_filter( 'pre_handle_404', static fn( $value ) => self::$active ? true : $value ); add_filter( 'redirect_canonical', static fn( $value ) => self::$active ? false : $value );
		add_filter( 'template_include', static fn( string $file ): string => self::$active ? get_template_directory() . '/templates/developers/' . ( self::$context['public'] ? 'public' : 'page' ) . '.php' : $file, 99 );
		add_filter( 'pre_get_document_title', static fn( string $title ): string => self::$active && ! self::$context['public'] ? self::t( self::$context['route'] ) . ' · AI Skills Hub' : $title, PHP_INT_MAX );
		add_filter( 'wp_robots', static fn( array $robots ): array => self::$active && ! self::$context['public'] ? array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true ) : $robots, PHP_INT_MAX );
		add_filter( 'wpseo_frontend_presenters', static fn( array $presenters ): array => self::$active && ! self::$context['public'] ? array() : $presenters, PHP_INT_MAX );
		add_filter( 'wpml_hreflangs', static fn( $links ) => self::$active ? array() : $links, PHP_INT_MAX ); add_filter( 'show_admin_bar', static fn( bool $show ): bool => self::$active ? false : $show );
		add_action( 'aish_account_navigation', array( $this, 'navigation' ), 30, 2 ); add_action( 'aish_skill_actions', array( $this, 'skillActions' ), 30, 2 );
	}
	public function parse( \WP $wp ): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $wp->query_vars['rest_route'] ) || isset( $wp->query_vars['feed'] ) ) { return; }
		$path = Ui::path(); if ( ! is_string( $path ) ) { return; }
		if ( preg_match( '#^(zh|en)/account/(developer|claim)$#D', $path, $parts ) ) { self::$context['route'] = $parts[2]; }
		elseif ( preg_match( '#^(zh|en)/developers/([a-z0-9][a-z0-9-]{1,48}[a-z0-9])(?:/(avatar))?$#D', $path, $parts ) ) { self::$context['public'] = true; self::$context['route'] = isset( $parts[3] ) ? 'avatar' : 'public'; self::$context['handle'] = $parts[2]; }
		else { return; }
		self::$active = true; self::$context['locale'] = 'zh' === $parts[1] ? 'zh_CN' : 'en_US'; $wp->query_vars = array( 'aish_route' => 'developers', 'aish_language' => $parts[1] );
	}
	public function resolve(): void {
		if ( ! self::$active ) { return; }
		$GLOBALS['wp_query']->is_404 = false; $GLOBALS['wp_query']->is_home = false;
		if ( self::$context['public'] ) { $this->resolvePublic(); return; }
		Ui::privateHeaders();
		try {
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) ) { header( 'Allow: GET, HEAD, POST' ); self::$context['status'] = 405; throw new \InvalidArgumentException( 'invalid_input' ); }
			$params = array( 'page' => Ui::integer( $_GET['page'] ?? '1', 1, 1000 ) ); foreach ( array( 'id', 'skill' ) as $key ) { if ( isset( $_GET[ $key ] ) ) { $params[ $key ] = Ui::integer( $_GET[ $key ] ); } }
			if ( isset( $_GET['state'] ) ) { $params['state'] = Ui::text( $_GET['state'], 20 ); }
			self::$context['params'] = $params; $locale = self::$context['locale']; $route = self::$context['route']; $destination = self::url( $route, $locale, $params );
			if ( ! is_user_logged_in() ) { wp_safe_redirect( Accounts::loginUrl( $destination, $locale ), 302 ); exit; }
			if ( ! $this->accounts->verified() ) { wp_safe_redirect( add_query_arg( 'return_to', rawurlencode( $destination ), Accounts::url( 'verify-email', $locale ) ), 302 ); exit; }
			if ( ! current_user_can( 'read' ) ) { throw new Exception( 'forbidden' ); } $this->accounts->requireOwner(); self::$context['profile'] = $this->accounts->profile();
			if ( 'GET' === $method ) { self::$context['message'] = Ui::readFlash( 'developers', array( 'profile_submitted', 'profile_withdrawn', 'claim_submitted', 'claim_withdrawn' ) ); }
			if ( 'developer' === $route ) { self::$context['developer'] = $this->query->mineProfile(); self::$context['claims'] = $this->query->mineClaims( array_merge( $params, array( 'display_locale' => $locale ) ) ); }
			elseif ( isset( $params['id'] ) ) { self::$context['claim'] = $this->query->mineClaim( $params['id'], $locale ); if ( ! self::$context['claim'] ) { throw new Exception( 'unavailable' ); } }
			else {
				self::$context['skill'] = $this->catalog->detail( $params['skill'] ?? 0, $locale ); self::$context['source_revision'] = $this->query->publicSkillRevision( $params['skill'] ?? 0, $locale );
				if ( ! self::$context['skill'] || ! self::$context['source_revision'] ) { throw new Exception( 'unavailable' ); }
			}
			if ( 'POST' === $method ) { $this->handlePost(); }
		} catch ( \Throwable $error ) {
			$reason = $error instanceof Exception ? $error->reason : ( in_array( $error->getMessage(), array( 'csrf', 'invalid_input' ), true ) ? $error->getMessage() : 'storage_unavailable' );
			self::$context['error'] = Strings::has( 'error.' . $reason ) ? $reason : 'invalid_input'; if ( 200 === self::$context['status'] ) { self::$context['status'] = Ui::errorStatus( self::$context['error'] ); }
			self::$context['values'] = self::safeValues();
		}
		status_header( self::$context['status'] ); if ( in_array( self::$context['status'], array( 429, 503 ), true ) ) { header( 'Retry-After: 60' ); }
	}
	private function resolvePublic(): void {
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		try {
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) { header( 'Allow: GET, HEAD' ); self::$context['status'] = 405; throw new \InvalidArgumentException( 'invalid_input' ); }
			$page = Ui::integer( $_GET['page'] ?? '1', 1, 1000 ); $locale = self::$context['locale']; $handle = self::$context['handle'];
			self::$context['params'] = array( 'handle' => $handle, 'page' => $page );
			if ( 'avatar' === self::$context['route'] ) {
				$data = $this->query->publicAvatar( $handle, $locale );
				if ( ! is_array( $data ) || 'image/png' !== ( $data['mime'] ?? '' ) || ! is_string( $data['bytes'] ?? null ) || strlen( $data['bytes'] ) > 524288 || ! str_starts_with( $data['bytes'], "\x89PNG\r\n\x1a\n" ) ) { throw new Exception( 'unavailable' ); }
				header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); header( 'Content-Type: image/png' ); header( 'Content-Disposition: inline; filename="developer.png"' ); header( 'Content-Length: ' . strlen( $data['bytes'] ) ); if ( 'HEAD' !== $method ) { echo $data['bytes']; } exit;
			}
			self::$context['profile'] = $this->query->publicProfile( $handle, $locale, array( 'page' => $page, 'per_page' => 12 ) ) ?? array();
			$alternatives = $this->query->alternatives( $handle );
			foreach ( $alternatives as $language => $slug ) { if ( in_array( $language, array( 'zh_CN', 'en_US' ), true ) ) { self::$context['alternatives'][ $language ] = self::url( 'public', $language, array( 'handle' => $slug ) ); } }
			if ( ! self::$context['profile'] ) { throw new Exception( 'unavailable' ); }
			self::$context['canonical'] = self::url( 'public', $locale, $page > 1 ? array( 'handle' => $handle, 'page' => $page ) : array( 'handle' => $handle ) );
		} catch ( \Throwable $error ) {
			$reason = $error instanceof Exception && in_array( $error->reason, array( 'unavailable', 'invalid_handle' ), true ) ? 'unavailable' : ( $error instanceof \InvalidArgumentException ? 'invalid_input' : 'storage_unavailable' );
			self::$context['error'] = $reason; if ( 200 === self::$context['status'] ) { self::$context['status'] = Ui::errorStatus( $reason ); }
			self::$context['profile'] = array(); self::$context['canonical'] = '';
			header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		}
		status_header( self::$context['status'] ); if ( 503 === self::$context['status'] ) { header( 'Retry-After: 60' ); }
	}
	private function handlePost(): never {
		$action = Ui::input( 'contribution_action', 32 ); $route = self::$context['route'];
		$allowed = 'developer' === $route ? array( 'profile', 'profile_withdraw' ) : ( isset( self::$context['claim'] ) ? array( 'claim_withdraw' ) : array( 'claim' ) );
		if ( ! in_array( $action, array_merge( $allowed, array( 'logout' ) ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } Ui::csrf( 'developers', $action );
		if ( 'logout' === $action ) { $this->accounts->logout(); wp_safe_redirect( Accounts::url( 'login', self::$context['locale'] ), 303 ); exit; }
		if ( '' !== Ui::input( 'company', 200 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } $request = Ui::input( 'request_key', 64 );
		if ( 'profile' === $action ) { $this->service->saveProfile( self::profilePayload(), Ui::integer( Ui::input( 'revision', 20 ), 0 ), $request ); $message = 'profile_submitted'; }
		elseif ( 'profile_withdraw' === $action ) { if ( '1' !== Ui::input( 'confirm', 1 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } $this->service->withdrawProfile( Ui::integer( Ui::input( 'revision', 20 ), 1 ), $request ); $message = 'profile_withdrawn'; }
		elseif ( 'claim_withdraw' === $action ) { if ( '1' !== Ui::input( 'confirm', 1 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } $this->service->withdrawClaim( (int) self::$context['claim']['id'], Ui::input( 'expected_state', 20 ), $request ); $message = 'claim_withdrawn'; }
		else {
			$evidence = Ui::input( 'evidence_urls', 12000 ); $urls = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $evidence ) ?: array() ), static fn( string $value ): bool => '' !== $value ) );
			$this->service->submitClaim( (int) self::$context['params']['skill'], array( 'claim_role' => Ui::input( 'claim_role', 20 ), 'locale' => Ui::input( 'locale', 10 ), 'source_revision' => Ui::integer( Ui::input( 'source_revision', 20 ) ), 'proof_kind' => Ui::input( 'proof_kind', 32 ), 'reason' => Ui::input( 'reason', 16000 ), 'evidence_urls' => $urls ), $request ); $message = 'claim_submitted';
		}
		Ui::flash( 'developers', $message ); wp_safe_redirect( self::url( 'developer', self::$context['locale'] ), 303 ); exit;
	}
	private static function profilePayload(): array {
		$result = array(); foreach ( array( 'handle' => 50, 'github_url' => 1000, 'website_url' => 1000 ) as $key => $max ) { $result[ $key ] = Ui::input( $key, $max ); }
		foreach ( array( 'opt_in', 'public_avatar', 'show_contributions' ) as $key ) { $value = Ui::input( $key, 1 ); if ( ! in_array( $value, array( '', '1' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } $result[ $key ] = '1' === $value; }
		$result['translations'] = array(); foreach ( array( 'zh_CN', 'en_US' ) as $locale ) { foreach ( array( 'display_name' => 400, 'bio' => 8000, 'seo_title' => 764, 'seo_description' => 1280 ) as $key => $max ) { $result['translations'][ $locale ][ $key ] = Ui::input( $locale . '_' . $key, $max ); } }
		return $result;
	}
	private static function safeValues(): array {
		$result = array(); $fields = array( 'handle' => 50, 'github_url' => 1000, 'website_url' => 1000, 'revision' => 20, 'opt_in' => 1, 'public_avatar' => 1, 'show_contributions' => 1, 'claim_role' => 20, 'proof_kind' => 32, 'reason' => 16000, 'evidence_urls' => 12000, 'locale' => 10, 'source_revision' => 20, 'expected_state' => 20 );
		foreach ( array( 'zh_CN', 'en_US' ) as $locale ) { foreach ( array( 'display_name' => 400, 'bio' => 8000, 'seo_title' => 764, 'seo_description' => 1280 ) as $key => $max ) { $fields[ $locale . '_' . $key ] = $max; } }
		foreach ( $fields as $key => $max ) { try { $result[ $key ] = Ui::input( $key, $max ); } catch ( \Throwable $ignored ) { $result[ $key ] = ''; } } return $result;
	}
	public static function fields( string $action ): string { return Ui::fields( 'developers', $action, '' !== self::$context['error'] ); }
	public function navigation( string $locale, string $route = '' ): void { echo '<a href="' . esc_url( self::url( 'developer', $locale ) ) . '"' . ( in_array( $route, array( 'developer', 'claim' ), true ) ? ' aria-current="page"' : '' ) . '>' . esc_html( self::t( 'developer', $locale ) ) . '</a>'; }
	public function skillActions( array $skill, string $locale ): void {
		try {
			$contributors = $this->query->skillContributors( (int) $skill['id'], $locale );
			foreach ( $contributors as $contributor ) {
				echo '<p class="account-help"><a class="account-text-action" href="' . esc_url( self::url( 'public', $locale, array( 'handle' => $contributor['handle'] ) ) ) . '">' . esc_html( $contributor['display_name'] ) . '</a> · ' . esc_html( self::t( 'role.' . $contributor['claim_role'], $locale ) ) . '</p>';
			}
		} catch ( \Throwable $ignored ) { /* Never substitute private claims or a repository owner. */ }
		echo '<a class="account-text-action" href="' . esc_url( self::url( 'claim', $locale, array( 'skill' => $skill['id'] ) ) ) . '">' . esc_html( self::t( 'claim', $locale ) ) . '</a>';
	}
}
