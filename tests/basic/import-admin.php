<?php
declare(strict_types=1);
// Isolated controller contract checks; no WordPress, MySQL or live GitHub request.
namespace AISkillsHub\Core\Sources {
	final class ImportStore {
		public array $calls = array();
		public function enqueue( int $id, string $kind ): int { $this->calls[] = array( 'enqueue', $id, $kind ); if ( 2 === $id ) { throw new \RuntimeException( 'private database details must not escape' ); } return 10 + $id; }
		public function saveRepository( ?int $id, string $url, bool $enabled, int $interval ): int { $this->calls[] = array( 'save', $id, $url, $enabled, $interval ); return $id ?? 3; }
		public function configureRepository( int $id, bool $enabled, int $interval ): void { $this->calls[] = array( 'configure', $id, $enabled, $interval ); }
		public function repository( int $id ): array { return array( 'id' => $id, 'owner' => 'owner', 'name' => 'repository', 'sync_interval' => 86400, 'is_archived' => 0 ); }
		public function archiveRepository( int $id ): void { $this->calls[] = array( 'archive', $id ); }
		public function markHandled( int $id, string $commit, string $revision ): void { $this->calls[] = array( 'handled', $id, $commit, $revision ); }
		public function job( int $id ): array { return array( 'id' => $id, 'repository_id' => 1, 'kind' => 'sync', 'status' => 'complete', 'state' => array( 'commit_sha' => str_repeat( 'a', 40 ), 'inventory_complete' => true, 'files_complete' => true, 'unchanged_head' => true, 'stats' => array( 'new' => 3, 'updated' => 2, 'unchanged' => 4, 'missing' => 1 ), 'errors' => 0 ) ); }
		public function logs( int $id, int $page ): array { return array( 'items' => array(), 'total' => 0, 'page' => $page, 'per_page' => 25 ); }
		public function jobs( int $page, int $repo, string $status ): array { $this->calls[] = array( 'jobs', $page, $repo, $status ); return array( 'items' => array(), 'total' => 75, 'page' => $page, 'per_page' => 25 ); }
		public function imports( int $page, int $repo, string $change ): array { $this->calls[] = array( 'imports', $page, $repo, $change ); return array( 'items' => array(), 'total' => 75, 'page' => $page, 'per_page' => 25 ); }
		public function import( int $id ): array {
			return array( 'id' => $id, 'skill_id' => 9, 'owner' => 'owner', 'name' => 'repository', 'source_path' => 'review/SKILL.md', 'file_sha' => str_repeat( 'b', 40 ), 'commit_sha' => str_repeat( 'a', 40 ), 'review_revision' => str_repeat( 'd', 64 ), 'source_status' => 'present', 'change_status' => 'updated', 'metadata' => array( 'name' => '<script>bad()</script>', 'description' => '<img src=x onerror=bad()>' ), 'findings' => array( 'license' => array( 'status' => 'requires_review' ), 'risk_level' => 'high', 'scan_scope' => 'text_only', 'findings' => array( array( 'severity' => 'high', 'code' => 'network', 'message' => '<script>bad()</script>' ) ) ), 'source_content' => "</textarea><script>bad()</script>\nread server secrets" );
		}
	}
	final class GitHubClient {
		public static function tokenConfigured(): bool { return true; }
		public static function rateStatus(): array { return array( 'remaining' => 0, 'reset' => 1804377600, 'cooldown_until' => 1804377600 ); }
	}
}
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	final class Stop extends RuntimeException { public function __construct( public int $status ) { parent::__construct( 'Stopped.' ); } }
	function current_user_can( string $cap ): bool { return in_array( $cap, $GLOBALS['caps'], true ); }
	function wp_die( string $message, string $title, array $options ): never { throw new Stop( $options['response'] ); }
	function wp_unslash( string $value ): string { return stripslashes( $value ); }
	function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_attr( string $value ): string { return esc_html( $value ); }
	function esc_textarea( string $value ): string { return esc_html( $value ); }
	function esc_url( string $value ): string { return esc_html( $value ); }
	function __( string $value, string $domain = '' ): string { return $value; }
	function esc_html__( string $value, string $domain = '' ): string { return esc_html( $value ); }
	function admin_url( string $path ): string { return 'https://example.test/wp-admin/' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function check_admin_referer( string $action ): void { $GLOBALS['nonce_checks']++; if ( ! $GLOBALS['nonce_valid'] ) { throw new Stop( 403 ); } }
	function wp_nonce_field( string $action ): void { echo '<input name="_wpnonce" value="nonce">'; }
	function get_current_user_id(): int { return 7; }
	function set_transient( string $key, mixed $value, int $ttl ): void { $GLOBALS['transients'][ $key ] = $value; }
	function get_transient( string $key ): mixed { return $GLOBALS['transients'][ $key ] ?? false; }
	function delete_transient( string $key ): void { unset( $GLOBALS['transients'][ $key ] ); }
	function wp_safe_redirect( string $url, int $status ): never { $GLOBALS['redirect'] = $url; throw new Stop( $status ); }
	function do_action( string $action ): void { $GLOBALS['actions'][] = $action; }
	function submit_button( string $text, string $type = '', string $name = '', bool $wrap = true ): void { echo '<button>' . esc_html( $text ) . '</button>'; }
	function selected( mixed $left, mixed $right, bool $echo = true ): string { return $left === $right ? ' selected="selected"' : ''; }
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Sources/Admin.php';
	use AISkillsHub\Core\Sources\Admin;
	use AISkillsHub\Core\Sources\ImportStore;
	$count = 0;
	function check( bool $condition, string $message ): void { global $count; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$count; }
	function request( array $post, array $caps = array( 'aish_manage_repositories' ), string $method = 'POST', bool $nonce = true ): array {
		$_POST = $post; $_SERVER['REQUEST_METHOD'] = $method;
		$GLOBALS['caps'] = $caps; $GLOBALS['nonce_valid'] = $nonce; $GLOBALS['nonce_checks'] = 0; $GLOBALS['transients'] = array(); $GLOBALS['actions'] = array();
		$store = new ImportStore(); $admin = new Admin( $store );
		try { $admin->handle(); } catch ( Stop $error ) { return array( $store, $error->status, $GLOBALS['transients']['aish_import_notice_7'] ?? null ); }
		throw new RuntimeException( 'Controller did not terminate.' );
	}
	$batch = array( 'operation' => 'batch', 'batch_action' => 'sync', 'ids' => array( '1' ) );
	list( $store, $status ) = request( $batch, array( 'read' ) );
	check( 403 === $status && ! $store->calls, 'Logged-in subscriber cannot enqueue.' );
	check( 0 === $GLOBALS['nonce_checks'], 'Capability gate executes first.' );
	list( $store, $status ) = request( $batch, array( 'aish_manage_repositories' ), 'GET' );
	check( 405 === $status && ! $store->calls, 'GET cannot mutate import state.' );
	list( $store, $status ) = request( $batch, array( 'aish_manage_repositories' ), 'POST', false );
	check( 403 === $status && ! $store->calls, 'Invalid nonce cannot enqueue.' );
	list( $store, $status, $notice ) = request( array_merge( $batch, array( 'ids' => array_map( 'strval', range( 1, 11 ) ) ) ) );
	check( ! $store->calls && 'error' === $notice['kind'], 'Oversized batch rejected before any work.' );
	foreach ( array( array( '1', array() ), array( '1', '0' ), array( '1', '2;DROP' ) ) as $ids ) {
		list( $store, $status, $notice ) = request( array_merge( $batch, array( 'ids' => $ids ) ) );
		check( ! $store->calls && 'error' === $notice['kind'], 'Invalid batch rejected atomically before enqueue.' );
	}
	list( $store, $status, $notice ) = request( array_merge( $batch, array( 'ids' => array( '1', '2', '1' ) ) ) );
	check( 303 === $status && 2 === count( $store->calls ), 'Batch duplicates deduplicated.' );
	check( 1 === $notice['success'] && 1 === $notice['failed'], 'Partial failures are reported as partial.' );
	check( array( 'aish_import_wake' ) === $GLOBALS['actions'], 'Controller only schedules worker; no direct HTTP.' );
	check( str_contains( $GLOBALS['redirect'], 'view=jobs' ), 'Queued operations redirect to jobs.' );
	list( $store, $status, $notice ) = request( array( 'operation' => 'archive', 'repository_id' => '1' ) );
	check( ! $store->calls && 'error' === $notice['kind'], 'Archive requires explicit second confirmation.' );
	list( $store, $status, $notice ) = request( array( 'operation' => 'archive', 'repository_id' => '1', 'confirmed' => '1' ) );
	check( array( array( 'archive', 1 ) ) === $store->calls && 'archived' === $notice['kind'], 'Confirmed archive delegates data retention.' );
	$handled = array( 'operation' => 'handled', 'import_id' => '5', 'expected_commit' => str_repeat( 'a', 40 ), 'expected_revision' => str_repeat( 'd', 64 ), 'confirmed' => '1' );
	list( $store, $status, $notice ) = request( $handled );
	check( ! $store->calls && 'error' === $notice['kind'], 'Repository manager without reviewer cap cannot mark handled.' );
	list( $store, $status, $notice ) = request( $handled, array( 'manage_options' ) );
	check( array( array( 'handled', 5, str_repeat( 'a', 40 ), str_repeat( 'd', 64 ) ) ) === $store->calls, 'Review passes expected commit and source fingerprint for stale source guard.' );
	check( 'handled' === $notice['kind'], 'Source acknowledgement distinct from publishing.' );
	list( $store, $status, $notice ) = request( array( 'operation' => 'publish', 'repository_id' => '1' ) );
	check( ! $store->calls && 'error' === $notice['kind'], 'No import publish operation exists.' );
	$GLOBALS['caps'] = array( 'manage_options' );
	$admin = new Admin( new ImportStore() );
	ob_start(); ( new ReflectionMethod( Admin::class, 'import' ) )->invoke( $admin, 5 ); $html = ob_get_clean();
	check( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img' ), 'External text cannot render executable HTML.' );
	check( str_contains( $html, '&lt;/textarea&gt;&lt;script&gt;' ), 'Raw content cannot close its read-only textarea.' );
	check( str_contains( $html, '/blob/' . str_repeat( 'a', 40 ) . '/review/SKILL.md' ), 'Source link pins the observed commit.' );
	check( str_contains( $html, 'aish-skill-edit' ) && str_contains( $html, 'aish-reviews' ), 'Existing edit/review workflows remain linked.' );
	check( str_contains( $html, '不是安全认证' ) && str_contains( $html, '不会自动填充或覆盖译文' ), 'Risk limits and translation preservation visible.' );
	check( str_contains( $html, 'name="expected_revision" value="' . str_repeat( 'd', 64 ) . '"' ), 'Source acknowledgement captures current state fingerprint.' );
	ob_start(); ( new ReflectionMethod( Admin::class, 'rate' ) )->invoke( $admin ); $rate = ob_get_clean();
	check( str_contains( $rate, '最近剩余额度：0' ), 'Zero remaining limit is not rendered unknown.' );
	check( str_contains( $rate, 'Token：已配置' ), 'Only configured boolean shown.' );
	ob_start(); ( new ReflectionMethod( Admin::class, 'job' ) )->invoke( $admin, 5, 1 ); $job = ob_get_clean();
	check( str_contains( $job, '新增：3' ) && str_contains( $job, '更新：2' ) && str_contains( $job, '缺失：1' ), 'Job counters use the worker stats contract.' );
	check( str_contains( $job, '没有重新读取全部文件' ), 'Unchanged-head optimization is not presented as a fresh file scan.' );
	$filteredStore = new ImportStore(); $filteredAdmin = new Admin( $filteredStore );
	ob_start(); ( new ReflectionMethod( Admin::class, 'jobs' ) )->invoke( $filteredAdmin, 2, 7, 'failed' ); $jobs = ob_get_clean();
	check( array( 'jobs', 2, 7, 'failed' ) === $filteredStore->calls[0], 'Job failure filter passed to store before pagination.' );
	check( str_contains( $jobs, 'status=failed' ) && str_contains( $jobs, 'repository_id=7' ), 'Job pagination retains status and repository.' );
	ob_start(); ( new ReflectionMethod( Admin::class, 'imports' ) )->invoke( $filteredAdmin, 2, 7, 'inspection_updated' ); $imports = ob_get_clean();
	check( array( 'imports', 2, 7, 'inspection_updated' ) === $filteredStore->calls[1], 'Inspection-only change filter stays distinct from source text changes.' );
	check( str_contains( $imports, 'change=inspection_updated' ), 'Source pagination retains change filter.' );
	echo 'PASS: ' . $count . " isolated import-admin assertions (PHP stubs; no WordPress/MySQL/GitHub integration).\n";
}
