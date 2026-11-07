<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Submissions;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Frontend as Accounts;
use AISkillsHub\Core\Contributions\Ui;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Private native SSR inbox workflows. Never collects sources or publishes Skills. */
final class Frontend {
	private const ROUTES = array( 'submit', 'submissions', 'submission', 'update-suggestion' );
	private static bool $active = false;
	private static array $context = array( 'route' => 'submissions', 'locale' => 'zh_CN', 'status' => 200, 'error' => '', 'message' => '', 'values' => array(), 'profile' => array(), 'params' => array() );
	public function __construct( private Service $service, private Query $query, private AccountService $accounts, private PublicCatalog $catalog ) {}
	public static function active(): bool { return self::$active; }
	public static function context(): array { return self::$context; }
	public static function t( string $key, ?string $locale = null ): string { return Strings::get( $key, $locale ?? self::$context['locale'] ); }
	public static function url( string $route, string $locale, array $args = array() ): string {
		if ( ! in_array( $route, self::ROUTES, true ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$url = Router::siteUrl( '/' . ( 'zh_CN' === $locale ? 'zh' : 'en' ) . '/' . ( 'submit' === $route ? 'submit' : 'account/' . $route ) . '/' ); $query = array();
		foreach ( array( 'id', 'skill', 'page', 'status', 'kind' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) { continue; }
			$query[ $key ] = in_array( $key, array( 'id', 'skill', 'page' ), true ) ? (string) Ui::integer( $args[ $key ], 1, 'page' === $key ? 1000 : PHP_INT_MAX ) : Ui::text( $args[ $key ], 32 );
		}
		return $query ? add_query_arg( array_map( 'rawurlencode', $query ), $url ) : $url;
	}
	public function register(): void {
		add_action( 'parse_request', array( $this, 'parse' ), 16 ); add_action( 'template_redirect', array( $this, 'resolve' ), -9 );
		add_filter( 'posts_pre_query', static function ( $posts, $query ) { if ( self::$active && $query->is_main_query() ) { $query->found_posts = 0; $query->max_num_pages = 0; return array(); } return $posts; }, 9, 2 );
		add_filter( 'pre_handle_404', static fn( $value ) => self::$active ? true : $value );
		add_filter( 'redirect_canonical', static fn( $value ) => self::$active ? false : $value );
		add_filter( 'template_include', static fn( string $file ): string => self::$active ? get_template_directory() . '/templates/submissions/page.php' : $file, 99 );
		add_filter( 'pre_get_document_title', static fn( string $title ): string => self::$active ? self::t( self::$context['route'] ) . ' · AI Skills Hub' : $title, PHP_INT_MAX );
		add_filter( 'wp_robots', static fn( array $robots ): array => self::$active ? array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true ) : $robots, PHP_INT_MAX );
		add_filter( 'wpseo_frontend_presenters', static fn( array $presenters ): array => self::$active ? array() : $presenters, PHP_INT_MAX );
		add_filter( 'wpml_hreflangs', static fn( $links ) => self::$active ? array() : $links, PHP_INT_MAX );
		add_filter( 'show_admin_bar', static fn( bool $show ): bool => self::$active ? false : $show );
		add_action( 'aish_account_navigation', array( $this, 'navigation' ), 20, 2 ); add_action( 'aish_account_dashboard', array( $this, 'dashboard' ), 20 );
		add_action( 'aish_skill_actions', array( $this, 'skillActions' ), 20, 2 );
	}
	public function parse( \WP $wp ): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $wp->query_vars['rest_route'] ) || isset( $wp->query_vars['feed'] ) ) { return; }
		$path = Ui::path(); if ( ! is_string( $path ) || ! preg_match( '#^(zh|en)/(submit|account/(submissions|submission|update-suggestion))$#D', $path, $parts ) ) { return; }
		self::$active = true; self::$context['route'] = $parts[3] ?? 'submit'; self::$context['locale'] = 'zh' === $parts[1] ? 'zh_CN' : 'en_US';
		$wp->query_vars = array( 'aish_route' => 'submissions', 'aish_language' => $parts[1] );
	}
	public function resolve(): void {
		if ( ! self::$active ) { return; } Ui::privateHeaders(); $GLOBALS['wp_query']->is_404 = false; $GLOBALS['wp_query']->is_home = false;
		try {
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
			if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) ) { header( 'Allow: GET, HEAD, POST' ); self::$context['status'] = 405; throw new \InvalidArgumentException( 'invalid_input' ); }
			$params = array( 'page' => Ui::integer( $_GET['page'] ?? '1', 1, 1000 ) );
			foreach ( array( 'id', 'skill' ) as $key ) { if ( isset( $_GET[ $key ] ) ) { $params[ $key ] = Ui::integer( $_GET[ $key ] ); } }
			foreach ( array( 'status', 'kind' ) as $key ) { if ( isset( $_GET[ $key ] ) ) { $params[ $key ] = Ui::text( $_GET[ $key ], 32 ); } }
			self::$context['params'] = $params; $locale = self::$context['locale']; $route = self::$context['route']; $destination = self::url( $route, $locale, $params );
			if ( ! is_user_logged_in() ) { wp_safe_redirect( Accounts::loginUrl( $destination, $locale ), 302 ); exit; }
			if ( ! $this->accounts->verified() ) { wp_safe_redirect( add_query_arg( 'return_to', rawurlencode( $destination ), Accounts::url( 'verify-email', $locale ) ), 302 ); exit; }
			if ( ! current_user_can( 'read' ) ) { throw new Exception( 'forbidden' ); }
			$this->accounts->requireOwner(); self::$context['profile'] = $this->accounts->profile();
			if ( 'GET' === $method ) { self::$context['message'] = Ui::readFlash( 'submissions', array( 'saved', 'submitted', 'withdrawn' ) ); }
			if ( 'submissions' === $route ) { self::$context['listing'] = $this->query->mine( $locale, $params ); }
			elseif ( 'submission' === $route ) {
				self::$context['record'] = $this->query->mineDetail( $params['id'] ?? 0, $locale );
				if ( ! self::$context['record'] ) { throw new Exception( 'unavailable' ); }
			} elseif ( 'update-suggestion' === $route ) {
				self::$context['skill'] = $this->catalog->detail( $params['skill'] ?? 0, $locale );
				self::$context['skill_revision'] = $this->query->publicSkillRevision( $params['skill'] ?? 0, $locale );
				if ( ! self::$context['skill'] || ! self::$context['skill_revision'] ) { throw new Exception( 'unavailable' ); }
			}
			if ( 'POST' === $method ) { $this->handlePost(); }
		} catch ( \Throwable $error ) {
			$reason = $error instanceof Exception ? $error->reason : ( in_array( $error->getMessage(), array( 'csrf', 'invalid_input' ), true ) ? $error->getMessage() : 'storage_unavailable' );
			self::$context['error'] = Strings::has( 'error.' . $reason ) ? $reason : 'invalid_input';
			if ( 200 === self::$context['status'] ) { self::$context['status'] = Ui::errorStatus( self::$context['error'] ); }
			self::$context['values'] = self::safeValues();
		}
		status_header( self::$context['status'] ); if ( in_array( self::$context['status'], array( 429, 503 ), true ) ) { header( 'Retry-After: 60' ); }
	}
	private function handlePost(): never {
		$action = Ui::input( 'contribution_action', 32 ); $route = self::$context['route']; $params = self::$context['params'];
		$allowed = match ( $route ) { 'submit', 'update-suggestion' => array( 'save' ), 'submission' => array( 'save', 'withdraw' ), default => array() };
		if ( ! in_array( $action, array_merge( $allowed, array( 'logout' ) ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		Ui::csrf( 'submissions', $action );
		if ( 'logout' === $action ) { $this->accounts->logout(); wp_safe_redirect( Accounts::url( 'login', self::$context['locale'] ), 303 ); exit; }
		if ( '' !== Ui::input( 'company', 200 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$id = 'submission' === $route ? (int) ( self::$context['record']['id'] ?? 0 ) : 0; $revision = Ui::integer( Ui::input( 'revision', 20 ), 0 ); $request = Ui::input( 'request_key', 64 );
		if ( 'withdraw' === $action ) {
			if ( '1' !== Ui::input( 'confirm', 1 ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
			$this->service->withdraw( $id, $revision, $request ); Ui::flash( 'submissions', 'withdrawn' );
		} else {
			$payload = self::payload(); $payload['kind'] = 'update-suggestion' === $route ? 'update_suggestion' : ( self::$context['record']['kind'] ?? 'submit' );
			$payload['target_skill_id'] = 'update_suggestion' === $payload['kind'] ? (int) ( self::$context['record']['payload']['target_skill_id'] ?? $params['skill'] ?? 0 ) : null;
			$payload['target_skill_revision'] = 'update_suggestion' === $payload['kind'] ? Ui::integer( Ui::input( 'target_skill_revision', 20 ) ) : null;
			$mode = Ui::input( 'mode', 10 ); if ( ! in_array( $mode, array( 'draft', 'submit' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
			$result = $this->service->save( $id, $payload, $revision, $mode, $request ); $id = (int) $result['id']; Ui::flash( 'submissions', 'submit' === $mode ? 'submitted' : 'saved' );
		}
		try { do_action( 'aish_account_interaction_completed', get_current_user_id() ); } catch ( \Throwable $ignored ) {}
		wp_safe_redirect( self::url( 'submission', self::$context['locale'], array( 'id' => $id ) ), 303 ); exit;
	}
	private static function payload(): array {
		$result = array(); foreach ( array( 'locale' => 10, 'source_kind' => 10, 'github_url' => 2048, 'source_path' => 768, 'name' => 764, 'summary' => 4800, 'categories' => 2000, 'platforms' => 2000, 'license' => 400, 'notes' => 24000, 'commit_sha' => 64, 'evidence' => 12000 ) as $key => $max ) { $result[ $key ] = Ui::input( $key, $max ); } return $result;
	}
	private static function safeValues(): array {
		$result = array(); foreach ( array( 'locale' => 10, 'source_kind' => 10, 'github_url' => 2048, 'source_path' => 768, 'name' => 764, 'summary' => 4800, 'categories' => 2000, 'platforms' => 2000, 'license' => 400, 'notes' => 24000, 'commit_sha' => 64, 'evidence' => 12000, 'revision' => 20, 'target_skill_revision' => 20 ) as $key => $max ) { try { $result[ $key ] = Ui::input( $key, $max ); } catch ( \Throwable $ignored ) { $result[ $key ] = ''; } } return $result;
	}
	public static function fields( string $action ): string { return Ui::fields( 'submissions', $action, '' !== self::$context['error'] ); }
	public function navigation( string $locale, string $route = '' ): void { echo '<a href="' . esc_url( self::url( 'submissions', $locale ) ) . '"' . ( in_array( $route, self::ROUTES, true ) ? ' aria-current="page"' : '' ) . '>' . esc_html( self::t( 'submissions', $locale ) ) . '</a>'; }
	public function dashboard( string $locale ): void {
		echo '<section class="account-panel account-section contributions-dashboard"><h2>' . esc_html( self::t( 'submissions', $locale ) ) . '</h2><p>' . esc_html( self::t( 'private_help', $locale ) ) . '</p><div class="account-inline-actions"><a class="account-button account-button-primary" href="' . esc_url( self::url( 'submit', $locale ) ) . '">' . esc_html( self::t( 'submit', $locale ) ) . '</a><a class="account-button account-button-secondary" href="' . esc_url( self::url( 'submissions', $locale ) ) . '">' . esc_html( self::t( 'view_all', $locale ) ) . '</a></div></section>';
	}
	public function skillActions( array $skill, string $locale ): void { echo '<a class="account-text-action" href="' . esc_url( self::url( 'update-suggestion', $locale, array( 'skill' => $skill['id'] ) ) ) . '">' . esc_html( self::t( 'update-suggestion', $locale ) ) . '</a>'; }
}
