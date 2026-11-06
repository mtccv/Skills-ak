<?php
declare(strict_types=1);

/** Native admin boundary contracts with isolated WP/query substitutes, not integration. */
namespace AISkillsHub\Core\Community {
	final class Service {
		public array $calls = array();
		public function moderateReview( mixed ...$args ): array { $this->calls[] = $args; if ( 8 === $args[0] ) { throw new \RuntimeException( 'simulated private database error' ); } return array(); }
		public function resolveReport( mixed ...$args ): array { $this->calls[] = $args; return array(); }
	}
	final class Query {
		public int $calls = 0;
		public ?array $review = array();
		public ?array $report = array();
		public array $filters = array();
		public function adminReview( int $id, int $page = 1 ): ?array { ++$this->calls; return $this->review; }
		public function adminReviews( array $filters ): array { ++$this->calls; $this->filters = $filters; return array( 'items' => array( $this->review ), 'total' => 1, 'page' => $filters['page'], 'per_page' => 20 ); }
		public function adminReport( string $kind, int $id, int $page = 1 ): ?array { ++$this->calls; return $this->report; }
		public function adminReports( string $kind, array $filters ): array { ++$this->calls; return array( 'items' => array( $this->report ), 'total' => 1, 'page' => 1, 'per_page' => 20 ); }
		public function history( string $type, int $id, int $page = 1 ): array { ++$this->calls; return array( 'items' => array(), 'total' => 0, 'page' => $page, 'per_page' => 20 ); }
	}
}
namespace {
	define( 'ABSPATH', '/isolated-wordpress/' );
	$GLOBALS['caps'] = array(); $GLOBALS['locale'] = 'en_US'; $GLOBALS['hooks'] = array(); $GLOBALS['menus'] = array();
	function get_user_locale(): string { return $GLOBALS['locale']; }
	function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['caps'][ $cap ] ); }
	function get_current_user_id(): int { return 19; }
	function wp_die( string $message, string $title = '', array $args = array() ): never { throw new RuntimeException( 'http_' . ( $args['response'] ?? 500 ) ); }
	function wp_unslash( mixed $value ): mixed { return is_string( $value ) ? stripslashes( $value ) : $value; }
	function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_attr( string $value ): string { return esc_html( $value ); }
	function esc_url( string $value ): string { return esc_attr( $value ); }
	function selected( string $value, string $selected, bool $echo = true ): string { return $value === $selected ? ' selected="selected"' : ''; }
	function admin_url( string $path ): string { return 'https://admin.example/wp-admin/' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
	function wp_verify_nonce( string $nonce, string $action ): bool { return 'fixture' === $nonce; }
	function get_transient( string $key ): bool { return false; }
	function delete_transient( string $key ): bool { return true; }
	function set_transient( string $key, mixed $value, int $expiry ): bool { $GLOBALS['notice'] = $value; return true; }
	final class RedirectReached extends RuntimeException { public function __construct( public readonly string $url, public readonly int $status ) { parent::__construct( 'redirect' ); } }
	function wp_safe_redirect( string $url, int $status = 302 ): never { throw new RedirectReached( $url, $status ); }
	function add_action( string $name, callable $callback ): void { $GLOBALS['hooks'][ $name ][] = $callback; }
	function add_submenu_page( string $parent, string $title, string $menu, string $cap, string $slug, callable $callback ): string { $GLOBALS['menus'][] = compact( 'parent', 'title', 'cap', 'slug' ); return $parent . '_page_' . $slug; }

	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Community/Admin.php';
	use AISkillsHub\Core\Community\Admin;
	use AISkillsHub\Core\Community\Query;
	use AISkillsHub\Core\Community\Service;
	$assertions = 0;
	function check( bool $condition, string $message ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
	function rejected( callable $callback, string $message ): void { try { $callback(); } catch ( Throwable $error ) { check( true, $message ); return; } check( false, $message ); }
	$query = new Query(); $service = new Service(); $admin = new Admin( $service, $query );
	$commands = new ReflectionMethod( $admin, 'commands' );
	$input = array( 'operation' => 'review_decision', 'id' => '7', 'lock_version' => '5', 'revision_id' => '12', 'decision' => 'approve', 'reason' => 'approved', 'private_note' => '', 'confirmed' => '1', 'request_key' => str_repeat( 'a', 32 ) );
	rejected( fn() => $commands->invoke( $admin, $input ), 'logged-in without capability cannot moderate' );
	$GLOBALS['caps'] = array( 'aish_review_skills' => true, 'aish_generate_content' => true, 'manage_options' => true );
	rejected( fn() => $commands->invoke( $admin, $input ), 'editorial/AI/manage_options alone does not bypass dedicated community capability' );
	$GLOBALS['caps'] = array( Admin::REVIEWS => true );
	$plan = $commands->invoke( $admin, $input );
	check( $plan['commands'][0] === array( 7, 12, 5, 'approve', 'approved', '', str_repeat( 'a', 32 ) ), 'exact immutable revision and lock version forwarded' );
	check( $plan['kind'] === 'reviews' && $plan['id'] === 7, 'redirect target chosen internally' );
	check( $commands->invoke( $admin, $input + array( 'comment' => 'forged', 'rating' => '5', 'user_id' => '42' ) ) === $plan, 'moderator payload cannot author review text or identity' );
	foreach ( array(
		array( 'confirmed' => '0' ), array( 'id' => '0' ), array( 'id' => '-1' ), array( 'id' => '7e0' ), array( 'id' => '99999999999999999999' ),
		array( 'id' => array( '7' ) ), array( 'lock_version' => '0' ), array( 'revision_id' => '0' ), array( 'decision' => 'publish' ),
		array( 'reason' => 'arbitrary_reason' ), array( 'request_key' => 'short' ), array( 'request_key' => str_repeat( 'a', 129 ) ),
		array( 'private_note' => str_repeat( 'x', 2001 ) ), array( 'private_note' => "bad\0note" ), array( 'private_note' => "\xFF" ),
	) as $change ) { rejected( fn() => $commands->invoke( $admin, array_replace( $input, $change ) ), 'reject malformed or unconfirmed mutation' ); }
	$reject = array_replace( $input, array( 'decision' => 'reject', 'reason' => 'spam' ) );
	check( $commands->invoke( $admin, $reject )['commands'][0][3] === 'reject', 'reject exact pending revision supported' );
	rejected( fn() => $commands->invoke( $admin, array_replace( $reject, array( 'reason' => 'approved' ) ) ), 'rejection cannot claim approval reason' );
	$hide = array_replace( $input, array( 'decision' => 'hide', 'reason' => 'policy', 'revision_id' => '0' ) );
	check( $commands->invoke( $admin, $hide )['commands'][0][1] === 0, 'hiding a never-published review uses captured zero public pointer' );
	$bulk = array_replace( $input, array( 'operation' => 'review_bulk', 'ids' => array( '7', '8' ), 'snapshots' => array( 7 => array( 'lock_version' => '5', 'pending_revision_id' => '12', 'approved_revision_id' => '11' ), 8 => array( 'lock_version' => '6', 'pending_revision_id' => '15', 'approved_revision_id' => '14' ) ) ) );
	$batch = $commands->invoke( $admin, $bulk );
	check( count( $batch['commands'] ) === 2 && $batch['commands'][1][1] === 15 && $batch['commands'][1][2] === 6, 'bulk forwards individual immutable snapshots' );
	check( $batch['commands'][0][6] !== $batch['commands'][1][6], 'batch retry keys are stable and distinct per record' );
	check( $commands->invoke( $admin, $bulk ) === $batch, 'same submitted batch uses identical request keys' );
	check( $commands->invoke( $admin, array_replace( $bulk, array( 'decision' => 'hide', 'reason' => 'policy' ) ) )['commands'][0][1] === 11, 'bulk hide binds published pointer rather than pending edit' );
	rejected( fn() => $commands->invoke( $admin, array_replace( $bulk, array( 'ids' => array( '7', '7' ) ) ) ), 'duplicate bulk IDs rejected before executing commands' );
	rejected( fn() => $commands->invoke( $admin, array_replace( $bulk, array( 'ids' => array() ) ) ), 'empty batch rejected' );
	rejected( fn() => $commands->invoke( $admin, array_replace( $bulk, array( 'ids' => array_fill( 0, 21, '7' ) ) ) ), 'overlarge batch rejected' );
	rejected( fn() => $commands->invoke( $admin, array_replace( $bulk, array( 'snapshots' => array() ) ) ), 'missing captured revisions rejected' );
	$reportInput = array( 'operation' => 'report_resolve', 'kind' => 'review', 'id' => '4', 'decision' => 'closed', 'reason' => 'duplicate', 'private_note' => 'moderator only', 'expected_state' => 'reviewing', 'confirmed' => '1', 'request_key' => str_repeat( 'b', 32 ) );
	rejected( fn() => $commands->invoke( $admin, $reportInput ), 'review moderation capability cannot operate reports' );
	$GLOBALS['caps'] = array( Admin::REPORTS => true );
	$reportPlan = $commands->invoke( $admin, $reportInput );
	check( $reportPlan['commands'][0] === array( 'review', 4, 'closed', 'duplicate', 'moderator only', str_repeat( 'b', 32 ), 'reviewing' ), 'report resolves captured state with private note' );
	rejected( fn() => $commands->invoke( $admin, $input ), 'report capability cannot operate review moderation' );
	foreach ( array( array( 'kind' => 'ai' ), array( 'decision' => 'hide' ), array( 'reason' => 'approved' ), array( 'expected_state' => 'closed' ) ) as $change ) { rejected( fn() => $commands->invoke( $admin, array_replace( $reportInput, $change ) ), 'report action enum cannot target other workflows' ); }
	$admin->register(); $GLOBALS['hooks']['admin_menu'][0]();
	check( count( $GLOBALS['menus'] ) === 3, 'three distinct native community menus registered' );
	check( array_column( $GLOBALS['menus'], 'slug' ) === array( 'aish-community-reviews', 'aish-community-review-reports', 'aish-community-skill-reports' ), 'community menu identities do not collide with editorial/AI reviews' );
	check( isset( $GLOBALS['hooks']['admin_post_aish_community_admin'] ) && ! isset( $GLOBALS['hooks']['admin_post_nopriv_aish_community_admin'] ), 'no anonymous mutation endpoint registered' );
	$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); rejected( fn() => $admin->handle(), 'GET mutation rejected' );
	$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( '_wpnonce' => 'invalid' ); rejected( fn() => $admin->handle(), 'invalid nonce rejected before dispatch' );
	$_POST = array( '_wpnonce' => array( 'fixture' ) ); rejected( fn() => $admin->handle(), 'array nonce rejected before dispatch' );
	$GLOBALS['caps'] = array( Admin::REVIEWS => true ); $_POST = $bulk + array( '_wpnonce' => 'fixture' );
	try { $admin->handle(); } catch ( RedirectReached $redirect ) { check( $redirect->status === 303 && str_contains( $redirect->url, 'page=aish-community-reviews' ), 'successful POST redirects only to fixed internal screen' ); }
	check( $GLOBALS['notice'] === array( 'success' => 1, 'failed' => 1 ) && count( $service->calls ) === 2, 'batch reports actual partial service failures without exposing error text' );
	$GLOBALS['caps'] = array( Admin::REPORTS => true ); $_POST = array_replace( $reportInput, array( '_wpnonce' => 'fixture', 'confirmed' => '0' ) );
	try { $admin->handle(); } catch ( RedirectReached $redirect ) { check( str_contains( $redirect->url, 'page=aish-community-review-reports' ) && ! str_contains( $redirect->url, 'error' ), 'invalid report form returns to authorized report list with generic notice' ); }
	check( $GLOBALS['notice'] === array( 'success' => 0, 'failed' => 1 ) && count( $service->calls ) === 2, 'unconfirmed report never reaches mutation service' );
	$GLOBALS['caps'] = array(); $_GET = array();
	rejected( fn() => $admin->reviewsScreen(), 'unauthorized admin screen blocked before querying private records' );
	check( $query->calls === 0, 'unauthorized screens do not query private data' );
	$approved = array( 'id' => 11, 'revision' => 1, 'rating' => 5, 'locale' => 'en_US', 'title' => '<script>old</script>', 'comment' => '<img src=x onerror=alert(1)>', 'experience' => 'real experience', 'pros' => '', 'cons' => '', 'platform' => 'Local CLI', 'version_label' => 'v1', 'moderation_state' => 'approved', 'moderator_id' => 9, 'moderated_at' => '2026-11-06 00:00:00', 'moderation_code' => 'approved', 'private_note' => '<script>private</script>', 'created_at' => '2026-11-06 00:00:00' );
	$pending = array_replace( $approved, array( 'id' => 12, 'revision' => 2, 'rating' => 1, 'title' => '<script>pending</script>', 'moderation_state' => 'pending' ) );
	$query->review = array( 'id' => 7, 'skill_id' => 4, 'user_id' => 19, 'state' => 'approved', 'lock_version' => 5, 'approved_revision_id' => 11, 'pending_revision_id' => 12, 'approved' => $approved, 'pending' => $pending, 'latest' => $pending, 'created_at' => '2026-11-06 00:00:00', 'updated_at' => '2026-11-06 00:00:00', 'history' => array( 'items' => array(), 'page' => 1, 'per_page' => 20, 'total' => 0 ) );
	$GLOBALS['caps'] = array( Admin::REVIEWS => true ); $_GET = array( 'id' => '7' );
	ob_start(); $admin->reviewsScreen(); $html = ob_get_clean();
	check( str_contains( $html, '&lt;script&gt;old&lt;/script&gt;' ) && str_contains( $html, '&lt;script&gt;pending&lt;/script&gt;' ), 'published and pending immutable text rendered independently and escaped' );
	check( ! str_contains( $html, '<img src=x' ) && ! str_contains( $html, '<script>' ), 'untrusted review and private-note HTML cannot execute' );
	check( ! str_contains( $html, 'name="comment"' ) && ! str_contains( $html, 'name="rating"' ), 'moderator does not receive author editing fields' );
	check( ! str_contains( $html, 'value="approve"' ), 'self approval control excluded' );
	check( str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, 'name="lock_version" value="5"' ), 'mutation form contains nonce and captured CAS token' );
	preg_match_all( '/ id="([^"]+)"/', $html, $matches ); check( count( $matches[1] ) === count( array_unique( $matches[1] ) ), 'multiple moderation forms use unique accessible input IDs' );
	$query->report = array( 'id' => 4, 'review_id' => 7, 'revision_id' => 11, 'reason_code' => 'spam', 'locale' => 'en_US', 'details' => '<img src=x onerror=alert(1)>', 'state' => 'pending', 'reporter_user_id' => 88, 'created_at' => '2026-11-06 00:00:00', 'private_note' => '<script>internal</script>', 'reported_revision' => $approved );
	$GLOBALS['caps'] = array( Admin::REPORTS => true ); $_GET = array( 'id' => '4' );
	ob_start(); $admin->reviewReportsScreen(); $html = ob_get_clean();
	check( str_contains( $html, '&lt;img src=x onerror=alert(1)&gt;' ) && ! str_contains( $html, '<script>' ), 'report evidence and internal notes escaped' );
	check( str_contains( $html, 'name="expected_state" value="pending"' ), 'report forms bind expected state' );
	check( ! str_contains( $html, 'Open review moderation' ), 'report-only moderator cannot open review workflow link' );
	check( str_contains( $html, 'Reported original revision (read-only)' ) && str_contains( $html, '&lt;script&gt;old&lt;/script&gt;' ) && ! str_contains( $html, '&lt;script&gt;pending&lt;/script&gt;' ), 'report displays exact original revision independently of newer pending edit' );
	check( ! str_contains( $html, '&lt;script&gt;private&lt;/script&gt;' ), 'report-only context never renders separate review moderation note' );
	$GLOBALS['locale'] = 'zh_CN'; ob_start(); $admin->reviewReportsScreen(); $html = ob_get_clean(); check( str_contains( $html, '评价举报' ) && str_contains( $html, '确认处理' ), 'native admin labels follow Chinese user locale' );
	$GLOBALS['caps'] = array( Admin::REVIEWS => true ); $_GET = array( 'status' => 'all' );
	ob_start(); $admin->reviewsScreen(); $html = ob_get_clean(); check( $query->filters['status'] === 'all' && str_contains( $html, '#7' ), 'all-state filter follows query contract and renders actual rows' );
	$query->review = null; $_GET = array( 'id' => '999' );
	$diagnostics = 0;
	set_error_handler( static function ( int $severity, string $message ) use ( &$diagnostics ): never { ++$diagnostics; throw new RuntimeException( 'Unexpected PHP diagnostic: ' . $message ); } );
	ob_start(); $admin->reviewsScreen(); $html = ob_get_clean(); check( str_contains( $html, 'notice-error' ) && 0 === $diagnostics, 'missing review gives controlled error without accessing null data' );
	$query->report = null; $GLOBALS['caps'] = array( Admin::REPORTS => true );
	ob_start(); $admin->reviewReportsScreen(); $html = ob_get_clean(); check( str_contains( $html, 'notice-error' ) && 0 === $diagnostics, 'missing report gives controlled error' );
	restore_error_handler();
	echo "Community admin: {$assertions} isolated assertions passed (WP/service/query substitutes; no real WordPress/MySQL).\n";
}
