<?php
declare(strict_types=1);
// Isolated native-controller fixtures. No WordPress, database, browser or real AI API.
namespace AISkillsHub\Core\AI {
	final class Settings {
		public array $calls = array();
		public static function defaults( string $id = 'default' ): array { return array( 'id' => $id, 'provider' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'fixture-model', 'temperature' => null, 'timeout' => 30, 'max_output_tokens' => 2048, 'token_parameter' => 'max_tokens', 'input_price' => null, 'output_price' => null, 'max_cost' => 0, 'enabled' => true ); }
		public function profiles(): array { return array( self::defaults() + array( 'key_configured' => true ) ); }
		public function paused(): bool { return true; }
		public static function keyName( string $id ): string { return 'AISH_AI_KEY_' . strtoupper( $id ); }
		public function save( string $id, array $input ): void { $this->calls[] = array( 'save', $id, $input ); }
		public function remove( string $id ): void { $this->calls[] = array( 'remove', $id ); }
		public function setPaused( bool $value ): void { $this->calls[] = array( 'paused', $value ); }
	}
	final class GenerationService {
		public array $calls = array();
		public function enqueue( int $skill, string $profile, string $mode, string $key ): int { $this->calls[] = array( 'enqueue', $skill, $profile, $mode, $key ); if ( 2 === $skill ) { throw new \RuntimeException( 'sk-secret-do-not-echo private response' ); } return 30 + $skill; }
		public function enqueueConnection( string $profile, string $key ): int { $this->calls[] = array( 'connection', $profile, $key ); return 31; }
	}
	final class GenerationStore {
		public array $calls = array();
		public string $mode = 'both';
		public function listJobs( int $page, string $status, int $skill ): array { $this->calls[] = array( 'list', $page, $status, $skill ); return array( 'items' => array(), 'total' => 75, 'per_page' => 25 ); }
		public function job( int $id ): array {
			return array( 'id' => $id, 'skill_id' => 9, 'mode' => $this->mode, 'provider' => 'openai', 'model' => 'fixture-model', 'prompt_version' => 'test-1', 'status' => 'needs_review', 'review_status' => 'pending', 'revision' => 7, 'source_commit' => str_repeat( 'a', 40 ), 'file_sha' => str_repeat( 'b', 40 ), 'input_tokens' => 0, 'output_tokens' => null, 'cost_estimate' => null, 'elapsed_ms' => 321,
				'source' => array( 'repository' => array( 'owner' => 'owner', 'name' => 'repo' ), 'source_path' => '<script>path</script>', 'raw' => '</textarea><script>raw()</script>', 'static_findings' => array( 'risk_level' => 'medium', 'message' => '<script>static()</script>' ) ), 'output' => array( 'risk' => array( 'level' => 'high', 'findings' => array( 'explanation' => '<img src=x onerror=bad()>' ), 'limitations' => array( '未执行脚本，不是安全认证。' ) ) ),
				'edited' => array( 'zh_CN' => array( 'title' => '中文草稿', 'summary' => '</textarea><script>draft()</script>', 'content' => '<h2>草稿正文</h2>' ), 'en_US' => array( 'title' => 'English draft', 'summary' => 'Summary', 'content' => '<h2>Use cases</h2>' ) ),
				'current_translations' => array( 'zh_CN' => array( 'title' => '人工标题', 'summary' => '', 'content' => '<h2>人工正文</h2>' ), 'en_US' => array( 'title' => ' ' ) ), 'attempts_history' => array( array( 'id' => 4, 'attempt_number' => 2, 'status' => 'failed', 'input_tokens' => null, 'output_tokens' => null, 'cost_estimate' => null, 'elapsed_ms' => 200, 'error_code' => 'ai_timeout', 'started_at' => '2026-11-05 01:02:03', 'completed_at' => '2026-11-05 01:02:04' ) ), 'reviews' => array( array( 'action' => 'edited', 'snapshot' => '<script>audit()</script>' ) ) );
		}
		public function saveReview( int $id, int $revision, array $translations ): void { $this->calls[] = array( 'save_review', $id, $revision, $translations ); }
		public function accept( int $id, int $revision, array $fields ): void { $this->calls[] = array( 'accept', $id, $revision, $fields ); }
		public function reject( int $id, int $revision ): void { $this->calls[] = array( 'reject', $id, $revision ); }
		public function control( int $id, string $action ): void { $this->calls[] = array( 'control', $id, $action ); if ( 2 === $id ) { throw new \RuntimeException( 'internal private error' ); } }
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
	function add_query_arg( array|string $args, string $value, string $url = '' ): string { return is_array( $args ) ? $value . '?' . http_build_query( $args ) : $url . '?' . http_build_query( array( $args => $value ) ); }
	function wp_generate_uuid4(): string { return '12345678-1234-4321-8765-123456789012'; }
	function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags | JSON_THROW_ON_ERROR ); }
	function check_admin_referer( string $action ): void { $GLOBALS['nonce_checks']++; if ( ! $GLOBALS['nonce_valid'] ) { throw new Stop( 403 ); } }
	function wp_nonce_field( string $action ): void { echo '<input name="_wpnonce" value="nonce">'; }
	function get_current_user_id(): int { return 7; }
	function set_transient( string $key, mixed $value, int $ttl ): void { $GLOBALS['transients'][ $key ] = $value; }
	function get_transient( string $key ): mixed { return $GLOBALS['transients'][ $key ] ?? false; }
	function delete_transient( string $key ): void { unset( $GLOBALS['transients'][ $key ] ); }
	function wp_safe_redirect( string $url, int $status ): never { $GLOBALS['redirect'] = $url; throw new Stop( $status ); }
	function do_action( string $action ): void { $GLOBALS['actions'][] = $action; }
	function add_action( string $name, callable $callback ): void { $GLOBALS['hooks'][ $name ][] = $callback; }
	function add_submenu_page( string $parent, string $title, string $label, string $cap, string $slug, callable $callback ): void { $GLOBALS['menus'][] = array( $parent, $cap, $slug ); }
	function submit_button( string $text, string $type = '', string $name = '', bool $wrap = true ): void { echo '<button>' . esc_html( $text ) . '</button>'; }
	function selected( mixed $left, mixed $right, bool $echo = true ): string { return $left === $right ? ' selected="selected"' : ''; }
	function checked( mixed $left, mixed $right, bool $echo = true ): string { return $left === $right ? ' checked="checked"' : ''; }
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/AI/Admin.php';
	use AISkillsHub\Core\AI\Admin;
	use AISkillsHub\Core\AI\Settings;
	use AISkillsHub\Core\AI\GenerationStore;
	use AISkillsHub\Core\AI\GenerationService;
	$count = 0;
	function check( bool $condition, string $message ): void { global $count; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$count; }
	function request( array $post, array $caps = array( 'aish_generate_content' ), string $method = 'POST', bool $nonce = true ): array {
		$_POST = $post; $_SERVER['REQUEST_METHOD'] = $method; $GLOBALS['caps'] = $caps; $GLOBALS['nonce_valid'] = $nonce; $GLOBALS['nonce_checks'] = 0; $GLOBALS['transients'] = array(); $GLOBALS['actions'] = array();
		$settings = new Settings(); $store = new GenerationStore(); $service = new GenerationService(); $admin = new Admin( $settings, $store, $service );
		try { $admin->handle(); } catch ( Stop $error ) { return array( $settings, $store, $service, $error->status, $GLOBALS['transients']['aish_ai_notice_7'] ?? null ); }
		throw new RuntimeException( 'Controller did not terminate.' );
	}
	$generate = array( 'operation' => 'generate', 'skill_ids' => '1', 'profile_id' => 'default', 'mode' => 'both', 'request_key' => wp_generate_uuid4() );
	list( $settings, $store, $service, $status ) = request( $generate, array( 'read' ) );
	check( 403 === $status && ! $service->calls, 'Subscriber cannot generate.' );
	check( 0 === $GLOBALS['nonce_checks'], 'Capability gate before nonce.' );
	list( $settings, $store, $service, $status ) = request( $generate, array( 'aish_generate_content' ), 'GET' );
	check( 405 === $status && ! $service->calls, 'GET cannot generate.' );
	list( $settings, $store, $service, $status ) = request( $generate, array( 'aish_generate_content' ), 'POST', false );
	check( 403 === $status && ! $service->calls, 'Invalid nonce cannot generate.' );
	foreach ( array( '1,2<script>', implode( ',', range( 1, 11 ) ), '1,0', '1,999999999999999999999999' ) as $ids ) {
		list( $settings, $store, $service, $status, $notice ) = request( array_merge( $generate, array( 'skill_ids' => $ids ) ) );
		check( ! $service->calls && 'error' === $notice['kind'], 'Malformed and oversized batches rejected before mutation.' );
	}
	list( $settings, $store, $service, $status, $notice ) = request( array_merge( $generate, array( 'skill_ids' => "1, 2 1" ) ) );
	check( 303 === $status && 2 === count( $service->calls ), 'Unique batch items queued once.' );
	check( 1 === $notice['success'] && 1 === $notice['failed'], 'Partial queue failure reported accurately.' );
	check( ! str_contains( json_encode( $notice ), 'sk-secret' ), 'Provider or storage details excluded from notice.' );
	check( array( 'aish_ai_wake' ) === $GLOBALS['actions'], 'Controller only wakes background scheduler.' );
	$stableKey = $service->calls[0][4];
	list( $settings, $store, $service ) = request( $generate );
	check( $stableKey === $service->calls[0][4] && 64 === strlen( $stableKey ), 'Same form has stable per-Skill idempotency key.' );
	foreach ( array( 'zh_CN', 'en_US', 'summary', 'use_cases' ) as $mode ) {
		list( $settings, $store, $service ) = request( array_merge( $generate, array( 'mode' => $mode ) ) );
		check( $mode === $service->calls[0][3], 'Selected regeneration scope forwarded without other field writes.' );
	}
	list( $settings, $store, $service, $status, $notice ) = request( array_merge( $generate, array( 'mode' => 'publish' ) ) );
	check( ! $service->calls && 'error' === $notice['kind'], 'No publish mode exists.' );
	$profile = Settings::defaults(); $profile['operation'] = 'save_profile'; $profile['profile_id'] = 'default'; $profile['timeout'] = '30'; $profile['max_output_tokens'] = '2048'; $profile['temperature'] = ''; $profile['input_price'] = ''; $profile['output_price'] = ''; $profile['max_cost'] = '0'; $profile['enabled'] = '1'; $profile['api_key'] = 'sk-secret-do-not-store';
	list( $settings, $store, $service, $status ) = request( $profile );
	check( 403 === $status && ! $settings->calls, 'Generator cannot edit provider settings.' );
	list( $settings, $store, $service, $status, $notice ) = request( $profile, array( 'aish_manage_ai_settings' ) );
	check( 'saved' === $notice['kind'] && 1 === count( $settings->calls ), 'Authorized settings saved.' );
	check( ! isset( $settings->calls[0][2]['api_key'] ) && null === $settings->calls[0][2]['input_price'], 'Secrets never forwarded; unknown price remains null.' );
	list( $settings, $store, $service, $status, $notice ) = request( array( 'operation' => 'remove_profile', 'profile_id' => 'default' ), array( 'manage_options' ) );
	check( ! $settings->calls && 'error' === $notice['kind'], 'Profile removal needs confirmation.' );
	list( $settings ) = request( array( 'operation' => 'remove_profile', 'profile_id' => 'default', 'confirmed' => '1' ), array( 'manage_options' ) );
	check( array( array( 'remove', 'default' ) ) === $settings->calls, 'Confirmed configuration removal preserves store history.' );
	$connection = array( 'operation' => 'connection', 'profile_id' => 'default', 'request_key' => wp_generate_uuid4() );
	list( $settings, $store, $service, $status ) = request( $connection + array( 'confirmed' => '1' ), array( 'aish_manage_ai_settings' ) );
	check( 403 === $status && ! $service->calls && 0 === $GLOBALS['nonce_checks'], 'Connection test requires generation permission as well as settings permission.' );
	list( $settings, $store, $service, $status, $notice ) = request( $connection, array( 'manage_options' ) );
	check( ! $service->calls && 'error' === $notice['kind'], 'Potentially billed connection test requires confirmation.' );
	list( $settings, $store, $service, $status, $notice ) = request( $connection + array( 'confirmed' => '1' ), array( 'manage_options' ) );
	check( 'connection' === $service->calls[0][0] && 'queued' === $notice['kind'], 'Connection test only queues a job.' );
	$control = array( 'operation' => 'batch_control', 'control' => 'retry', 'ids' => array( '1', '2' ) );
	list( $settings, $store, $service, $status, $notice ) = request( $control );
	check( ! $store->calls && 'error' === $notice['kind'], 'Manual retry requires billing acknowledgement.' );
	list( $settings, $store, $service, $status, $notice ) = request( $control + array( 'confirmed' => '1' ) );
	check( 2 === count( $store->calls ) && 1 === $notice['success'] && 1 === $notice['failed'], 'Batch task controls preserve partial outcome.' );
	foreach ( array( 'pause', 'resume', 'cancel' ) as $action ) {
		list( $settings, $store ) = request( array( 'operation' => 'batch_control', 'control' => $action, 'ids' => array( '1' ) ) );
		check( array( array( 'control', 1, $action ) ) === $store->calls, 'Task state action delegated to guarded store.' );
	}
	$accept = array( 'operation' => 'accept', 'job_id' => '9', 'revision' => '7', 'fields' => array( 'zh_CN.summary' ), 'confirmed' => '1' );
	list( $settings, $store, $service, $status, $notice ) = request( $accept );
	check( ! $store->calls && 'error' === $notice['kind'], 'Generation permission alone cannot accept content.' );
	list( $settings, $store ) = request( $accept, array( 'manage_options' ) );
	check( array( array( 'accept', 9, 7, array( 'zh_CN.summary' ) ) ) === $store->calls, 'Acceptance forwards exact draft revision and selected fields.' );
	list( $settings, $store, $service, $status, $notice ) = request( array_merge( $accept, array( 'fields' => array( 'zh_CN.slug' ) ) ), array( 'manage_options' ) );
	check( ! $store->calls && 'error' === $notice['kind'], 'AI acceptance cannot change slug or arbitrary fields.' );
	list( $settings, $store, $service, $status, $notice ) = request( array_merge( $accept, array( 'confirmed' => '0' ) ), array( 'manage_options' ) );
	check( ! $store->calls && 'error' === $notice['kind'], 'Accept requires explicit review confirmation.' );
	$review = array( 'operation' => 'save_review', 'job_id' => '9', 'revision' => '7', 'translations' => array( 'en_US' => array( 'summary' => 'Human edited summary' ) ) );
	list( $settings, $store ) = request( $review, array( 'manage_options' ) );
	check( array( array( 'save_review', 9, 7, $review['translations'] ) ) === $store->calls, 'Draft edits saved separately with revision.' );
	list( $settings, $store, $service, $status, $notice ) = request( array_merge( $review, array( 'translations' => array( 'fr_FR' => array( 'title' => 'fake' ) ) ) ), array( 'manage_options' ) );
	check( ! $store->calls && 'error' === $notice['kind'], 'Unsupported review language rejected.' );
	$GLOBALS['caps'] = array( 'manage_options' ); $_GET = array(); $GLOBALS['transients'] = array();
	$settings = new Settings(); $store = new GenerationStore(); $service = new GenerationService(); $admin = new Admin( $settings, $store, $service );
	ob_start(); $admin->settingsScreen(); $html = ob_get_clean();
	check( str_contains( $html, '••••••••' ) && str_contains( $html, 'AISH_AI_KEY_DEFAULT' ), 'Only mask and configuration reference displayed.' );
	check( ! str_contains( $html, 'name="api_key"' ), 'No secret input or persisted-key field exposed.' );
	check( str_contains( $html, '可能计费' ) && str_contains( $html, 'AI 队列已暂停' ), 'Cost and paused state visible.' );
	$GLOBALS['caps'] = array( 'aish_manage_ai_settings' ); ob_start(); $admin->settingsScreen(); $settingsOnly = ob_get_clean();
	check( ! str_contains( $settingsOnly, 'value="connection"' ) && str_contains( $settingsOnly, '连接测试还需要 AI 生成权限' ), 'Settings-only administrator sees no inaccessible connection form.' );
	$GLOBALS['caps'] = array( 'manage_options' );
	ob_start(); ( new ReflectionMethod( Admin::class, 'detail' ) )->invoke( $admin, 9 ); $html = ob_get_clean();
	check( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img' ), 'Source, AI output and audit text cannot execute HTML.' );
	check( str_contains( $html, '&lt;/textarea&gt;&lt;script&gt;' ), 'Untrusted content cannot close textarea.' );
	check( str_contains( $html, 'Token：0 / 未知' ) && str_contains( $html, '成本：未知' ), 'Zero tokens distinguished from unknown usage/cost.' );
	check( str_contains( $html, 'value="zh_CN.title" disabled' ) && str_contains( $html, 'value="zh_CN.summary">' ), 'Existing manual fields protected while empty fields selectable.' );
	check( str_contains( $html, 'value="en_US.title" disabled' ), 'Whitespace is existing content, consistent with store empty-field guard.' );
	check( str_contains( $html, 'https://github.com/owner/repo' ) && str_contains( $html, '静态检查与 License 证据' ) && str_contains( $html, '&lt;script&gt;static()' ), 'Repository and independently escaped static findings visible.' );
	check( str_contains( $html, 'AI 建议风险等级：High / 高' ) && str_contains( $html, '未执行脚本，不是安全认证。' ), 'Risk level and limitations readable outside JSON.' );
	check( str_contains( $html, '开始：2026-11-05 01:02:03' ) && str_contains( $html, '完成：2026-11-05 01:02:04' ), 'Attempt times use actual started/completed schema fields.' );
	check( str_contains( $html, 'name="revision" value="7"' ), 'Review forms carry current draft revision.' );
	check( str_contains( $html, '不是安全认证' ) && str_contains( $html, 'aish-skill-edit' ), 'Risk limits and normal manual editing workflow visible.' );
	$store->mode = 'use_cases'; ob_start(); ( new ReflectionMethod( Admin::class, 'detail' ) )->invoke( $admin, 9 ); $html = ob_get_clean();
	check( ! str_contains( $html, 'name="fields[]"' ) && ! str_contains( $html, 'value="accept"' ) && str_contains( $html, '使用场景是局部正文建议' ), 'Partial use-case draft cannot replace any content.' );
	ob_start(); ( new ReflectionMethod( Admin::class, 'jobs' ) )->invoke( $admin, 2, 'failed', 9 ); $html = ob_get_clean();
	check( array( 'list', 2, 'failed', 9 ) === end( $store->calls ), 'History filters delegated with pagination.' );
	check( str_contains( $html, 'status=failed' ) && str_contains( $html, 'skill_id=9' ), 'Pagination retains history filters.' );
	$GLOBALS['caps'] = array( 'aish_generate_content' ); $GLOBALS['hooks'] = array(); $GLOBALS['menus'] = array(); $admin->register(); foreach ( $GLOBALS['hooks']['admin_menu'] as $callback ) { $callback(); }
	check( array( 'aish', 'aish_manage_ai_settings', 'aish-ai-settings' ) === $GLOBALS['menus'][0] && array( 'aish', 'aish_generate_content', 'aish-ai-generation' ) === $GLOBALS['menus'][1], 'Menus use dedicated capabilities.' );
	check( isset( $GLOBALS['hooks']['admin_post_aish_ai_action'] ) && ! isset( $GLOBALS['hooks']['admin_post_nopriv_aish_ai_action'] ), 'No unauthenticated write route registered.' );
	echo 'PASS: ' . $count . " isolated AI admin assertions (PHP stubs; no WordPress/MySQL/browser/real AI API).\n";
}
