<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

defined( 'ABSPATH' ) || exit;

/** Native admin UI. Requests enqueue work; only the background worker calls providers. */
final class Admin {
	private const GENERATE = 'aish_generate_content';
	private const SETTINGS = 'aish_manage_ai_settings';
	private const MODES = array( 'both' => '中英文完整草稿', 'zh_CN' => '重新生成中文', 'en_US' => '重新生成英文', 'summary' => '重新生成双语摘要', 'use_cases' => '重新生成双语使用场景' );
	private const STATES = array( 'queued', 'running', 'retry', 'paused', 'needs_review', 'complete', 'failed', 'cancelled' );

	public function __construct( private Settings $settings, private GenerationStore $store, private GenerationService $service ) {}

	public function register(): void {
		add_action( 'admin_menu', function (): void {
			add_submenu_page( 'aish', 'AI 配置', 'AI Settings', current_user_can( 'manage_options' ) ? 'manage_options' : self::SETTINGS, 'aish-ai-settings', array( $this, 'settingsScreen' ) );
			add_submenu_page( 'aish', 'AI 内容生成', 'AI Generation', current_user_can( 'manage_options' ) ? 'manage_options' : self::GENERATE, 'aish-ai-generation', array( $this, 'generationScreen' ) );
		} );
		add_action( 'admin_post_aish_ai_action', array( $this, 'handle' ) );
	}

	private static function can( string $cap ): bool { return current_user_can( $cap ) || current_user_can( 'manage_options' ); }
	private static function authorize( string $cap ): void {
		if ( ! self::can( $cap ) ) { wp_die( esc_html__( '无权执行此 AI 管理操作。', 'ai-skills-hub' ), '', array( 'response' => 403 ) ); }
	}
	private static function canReview(): bool { return self::can( self::GENERATE ) && self::can( 'aish_manage_skills' ) && self::can( 'aish_review_skills' ); }
	private static function input( array $data, string $key, string $default = '', int $limit = 2048 ): string {
		$value = $data[ $key ] ?? $default;
		if ( ! is_string( $value ) || strlen( $value ) > $limit || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'Invalid AI input.' ); }
		return wp_unslash( $value );
	}
	private static function id( array $data, string $key, int $default = 0 ): int {
		$value = filter_var( self::input( $data, $key, (string) $default, 20 ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $value ) { throw new \InvalidArgumentException( 'Invalid AI identity.' ); }
		return $value;
	}
	private static function number( array $data, string $key, bool $nullable = false ): ?float {
		$raw = self::input( $data, $key, '', 30 );
		if ( '' === $raw && $nullable ) { return null; }
		if ( ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $raw ) || ! is_finite( (float) $raw ) ) { throw new \InvalidArgumentException( 'Invalid AI numeric value.' ); }
		return (float) $raw;
	}
	private static function text( mixed $value ): string { return is_scalar( $value ) ? (string) $value : ''; }
	private static function url( string $screen = 'generation', array $args = array() ): string { return add_query_arg( array_merge( array( 'page' => 'aish-ai-' . $screen ), $args ), admin_url( 'admin.php' ) ); }
	private static function hidden( string $key, string|int $value ): void { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">'; }
	private static function openForm( string $operation ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( 'action', 'aish_ai_action' ); self::hidden( 'operation', $operation ); wp_nonce_field( 'aish_ai_action' );
	}
	private static function requestKey(): void { self::hidden( 'request_key', wp_generate_uuid4() ); }
	private static function requestId( array $input ): string {
		$value = self::input( $input, 'request_key', '', 80 );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,80}$/D', $value ) ) { throw new \InvalidArgumentException( 'Invalid AI request key.' ); }
		return $value;
	}
	private static function state( string $state ): string {
		return array( 'queued' => '已排队', 'running' => '执行中', 'retry' => '等待重试', 'paused' => '已暂停', 'needs_review' => '生成完成，待人工审核', 'complete' => '连接测试完成', 'failed' => '失败或结果待核实', 'cancelled' => '已取消', 'pending' => '待审核', 'edited' => '草稿已人工修改', 'accepted' => '已采用到待审核内容', 'rejected' => '已拒绝' )[ $state ] ?? $state;
	}
	private static function error(): void { echo '<div class="notice notice-error"><p>' . esc_html__( '无法完成操作。请核对字段、权限、数据库升级状态及最新记录后重试。', 'ai-skills-hub' ) . '</p></div>'; }
	private function notices(): void {
		$key = 'aish_ai_notice_' . get_current_user_id(); $notice = get_transient( $key );
		if ( ! is_array( $notice ) ) { return; }
		delete_transient( $key );
		$success = max( 0, (int) ( $notice['success'] ?? 0 ) ); $failed = max( 0, (int) ( $notice['failed'] ?? 0 ) );
		$message = match ( $notice['kind'] ?? '' ) {
			'queued' => '已受理任务：' . $success . '；未完成：' . $failed . '。可能复用已有活动任务，不替换其范围或配置。入队不代表 AI 调用或内容审核成功。',
			'saved' => '配置或任务操作已完成：' . $success . '；未完成：' . $failed . '。',
			'reviewed' => '人工草稿操作已保存。AI 内容没有自动发布；采用后仍须正常 Skills 审核。',
			default => '操作未完成。请核对字段、权限、最新草稿修订及来源状态后重试。',
		};
		echo '<div class="notice ' . ( $failed || 'error' === ( $notice['kind'] ?? '' ) ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}
	private static function field( string $name, string $label, mixed $value, string $type = 'text', string $description = '' ): void {
		echo '<tr><th scope="row"><label for="aish-ai-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="aish-ai-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( self::text( $value ) ) . '"' . ( 'number' === $type ? ' step="any" min="0"' : '' ) . '>';
		if ( '' !== $description ) { echo '<p class="description">' . esc_html( $description ) . '</p>'; }
		echo '</td></tr>';
	}
	private static function select( string $name, array $choices, string $current ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="aish-ai-' . esc_attr( $name ) . '">';
		foreach ( $choices as $key => $label ) { echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( (string) $key, $current, false ) . '>' . esc_html( (string) $label ) . '</option>'; }
		echo '</select>';
	}

	public function settingsScreen(): void {
		self::authorize( self::SETTINGS ); echo '<div class="wrap"><h1>AI Skills Hub · AI Settings</h1>';
		try {
			$this->notices(); $profiles = $this->settings->profiles(); $edit = self::input( $_GET, 'edit', '', 32 );
			$profile = Settings::defaults();
			if ( '' !== $edit ) {
				$found = false;
				foreach ( $profiles as $candidate ) { if ( $candidate['id'] === $edit ) { $profile = $candidate; $found = true; break; } }
				if ( ! $found ) { throw new \InvalidArgumentException( 'Unknown AI profile.' ); }
			}
			echo '<p>' . esc_html__( '每个配置保存 Provider、模型和价格。API Key 仅由服务器环境变量或常量提供，此页面不读取或回显密钥。', 'ai-skills-hub' ) . '</p>';
			echo '<h2>' . esc_html__( '任务总开关', 'ai-skills-hub' ) . '</h2><p>' . esc_html( $this->settings->paused() ? 'AI 队列已暂停，新任务可排队；恢复后由 Cron 分批处理。' : 'AI 队列已启用，后台 Cron 按任务逐个执行。' ) . '</p>';
			self::openForm( 'global_pause' ); self::hidden( 'paused', $this->settings->paused() ? '0' : '1' ); submit_button( $this->settings->paused() ? '启用队列' : '暂停队列', 'secondary' ); echo '</form>';
			echo '<h2>' . esc_html( '' === $edit ? '添加模型配置' : '编辑模型配置' ) . '</h2>'; self::openForm( 'save_profile' ); echo '<table class="form-table" role="presentation">';
			self::field( 'profile_id', '配置 ID', $profile['id'] ?? 'default', 'text', '小写字母开头，仅小写字母、数字、下划线，最多 32 字符。修改 ID 会建立另一配置。' );
			echo '<tr><th scope="row"><label for="aish-ai-provider">Provider</label></th><td>'; self::select( 'provider', array( 'openai' => 'OpenAI', 'openai_compatible' => 'OpenAI-compatible' ), (string) $profile['provider'] ); echo '</td></tr>';
			self::field( 'base_url', 'API Base URL', $profile['base_url'], 'url', '仅允许服务器 AISH_AI_ALLOWED_HOSTS 中的 HTTPS 主机；默认 api.openai.com。禁止内网、重定向及任意下载地址。' );
			self::field( 'model', 'Model', $profile['model'] );
			self::field( 'temperature', 'Temperature', $profile['temperature'], 'number', '可留空以省略此参数；不支持 temperature 的模型应留空。' );
			self::field( 'timeout', '请求超时（秒）', $profile['timeout'], 'number', '5–45 秒；超时可能已计费，不会自动重复发送不确定的请求。' );
			self::field( 'max_output_tokens', '最大输出 Token', $profile['max_output_tokens'], 'number' );
			echo '<tr><th scope="row"><label for="aish-ai-token_parameter">输出参数</label></th><td>'; self::select( 'token_parameter', array( 'max_tokens' => 'max_tokens', 'max_completion_tokens' => 'max_completion_tokens' ), (string) $profile['token_parameter'] ); echo '</td></tr>';
			self::field( 'input_price', '每百万输入 Token 价格（USD）', $profile['input_price'], 'number', '留空表示未知，不能把未知成本当作免费。按管理员配置估算，不是账单。' );
			self::field( 'output_price', '每百万输出 Token 价格（USD）', $profile['output_price'], 'number' );
			self::field( 'max_cost', '单任务估算费用上限（USD）', $profile['max_cost'], 'number', '0 表示不设金额上限，Token 上限仍有效；使用金额预算需配置输入和输出单价。' );
			echo '<tr><th scope="row">API Key</th><td><code>' . esc_html( Settings::keyName( (string) ( $profile['id'] ?? 'default' ) ) ) . '</code><p>' . esc_html( ! empty( $profile['key_configured'] ) ? '••••••••（服务器已配置）' : '服务器未配置或尚未验证' ) . '</p></td></tr>';
			echo '<tr><th scope="row">启用</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $profile['enabled'] ), true, false ) . '> ' . esc_html__( '允许此配置创建新任务', 'ai-skills-hub' ) . '</label></td></tr></table>'; submit_button( '保存配置' ); echo '</form>';
			echo '<h2>' . esc_html__( '已有模型配置', 'ai-skills-hub' ) . '</h2><table class="widefat striped"><thead><tr><th>ID / Provider</th><th>模型 / Base URL</th><th>状态 / API Key</th><th>操作</th></tr></thead><tbody>';
			foreach ( $profiles as $row ) {
				echo '<tr><td>' . esc_html( $row['id'] . ' / ' . $row['provider'] ) . '</td><td>' . esc_html( $row['model'] ) . '<br>' . esc_html( $row['base_url'] ) . '</td><td>' . esc_html( ! empty( $row['enabled'] ) ? '启用' : '停用' ) . '<br>' . esc_html( ! empty( $row['key_configured'] ) ? '••••••••（已配置）' : '未配置' ) . '</td><td><a href="' . esc_url( self::url( 'settings', array( 'edit' => $row['id'] ) ) ) . '">编辑</a>';
				if ( self::can( self::GENERATE ) ) { self::openForm( 'connection' ); self::hidden( 'profile_id', $row['id'] ); self::requestKey(); echo '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . esc_html__( '连接测试会发送最小模型请求，可能计费', 'ai-skills-hub' ) . '</label> <button class="button" type="submit">连接测试</button></p></form>'; }
				else { echo '<p>连接测试还需要 AI 生成权限。</p>'; }
				self::openForm( 'remove_profile' ); self::hidden( 'profile_id', $row['id'] ); echo '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . esc_html__( '确认移除此配置，保留生成历史', 'ai-skills-hub' ) . '</label> <button class="button" type="submit">移除配置</button></p></form></td></tr>';
			}
			if ( ! $profiles ) { echo '<tr><td colspan="4">尚未保存模型配置。</td></tr>'; }
			echo '</tbody></table>';
		} catch ( \Throwable $error ) { self::error(); }
		echo '</div>';
	}

	public function generationScreen(): void {
		self::authorize( self::GENERATE ); echo '<div class="wrap"><h1>AI Skills Hub · AI Generation</h1>';
		try {
			$this->notices(); $id = self::id( $_GET, 'id' );
			if ( $id ) {
				$reviewPage = self::id( $_GET, 'review_page', 1 );
				if ( $reviewPage < 1 || $reviewPage > 10000 ) { throw new \InvalidArgumentException( 'Invalid review page.' ); }
				$this->detail( $id, $reviewPage, self::id( $_GET, 'review_id' ) );
			}
			else {
				$this->generationForm(); $page = self::id( $_GET, 'paged', 1 ); if ( $page < 1 || $page > 10000 ) { throw new \InvalidArgumentException( 'Invalid page.' ); }
				$this->jobs( $page, self::input( $_GET, 'status', '', 30 ), self::id( $_GET, 'skill_id' ) );
			}
		} catch ( \Throwable $error ) { self::error(); }
		echo '</div>';
	}

	private function generationForm(): void {
		echo '<p>' . esc_html__( 'AI 仅分析 Phase 5 已采集来源并生成待审核草稿，不执行命令、不自动发布，也不会覆盖人工内容。', 'ai-skills-hub' ) . '</p>';
		if ( $this->settings->paused() ) { echo '<div class="notice notice-warning inline"><p>AI 队列已暂停；任务可排队，启用后再执行。</p></div>'; }
		$profiles = array(); foreach ( $this->settings->profiles() as $row ) { if ( ! empty( $row['enabled'] ) ) { $profiles[ $row['id'] ] = $row['id'] . ' · ' . $row['model']; } }
		if ( ! $profiles ) { echo '<p>尚无启用的模型配置，请联系有权限的管理员在 AI Settings 配置。</p>'; return; }
		echo '<h2>创建生成任务</h2>'; self::openForm( 'generate' ); self::requestKey();
		echo '<p><label for="aish-ai-skill-ids">Skill ID（最多 10 个，用逗号或空格分隔）</label><br><input class="regular-text" id="aish-ai-skill-ids" name="skill_ids" maxlength="220" required></p><p><label for="aish-ai-profile_id">模型配置 </label>'; self::select( 'profile_id', $profiles, (string) array_key_first( $profiles ) );
		echo '</p><p><label for="aish-ai-mode">生成范围 </label>'; self::select( 'mode', self::MODES, 'both' );
		echo '</p><p class="description">重新生成产生独立草稿和历史记录。摘要和使用场景只生成所选字段；现有人工字段保持原样。</p>'; submit_button( '创建任务（可能产生 API 费用）' ); echo '</form>';
	}
	private static function usage( array $job ): string {
		$in = null === ( $job['input_tokens'] ?? null ) ? '未知' : (string) (int) $job['input_tokens'];
		$out = null === ( $job['output_tokens'] ?? null ) ? '未知' : (string) (int) $job['output_tokens'];
		$cost = null === ( $job['cost_estimate'] ?? null ) ? '未知' : number_format( (float) $job['cost_estimate'], 6, '.', '' ) . ' USD（估算）';
		return '输入 / 输出 Token：' . $in . ' / ' . $out . '；成本：' . $cost;
	}
	private function jobs( int $page, string $status, int $skillId ): void {
		if ( '' !== $status && ! in_array( $status, self::STATES, true ) ) { throw new \InvalidArgumentException( 'Invalid job filter.' ); }
		$result = $this->store->listJobs( $page, $status, $skillId );
		echo '<h2>生成历史与任务</h2><form method="get">'; self::hidden( 'page', 'aish-ai-generation' );
		echo '<p><label for="aish-ai-status">状态 </label>'; $states = array( '' => '全部' ); foreach ( self::STATES as $value ) { $states[ $value ] = self::state( $value ); } self::select( 'status', $states, $status );
		echo ' <label for="aish-ai-skill-filter">Skill ID </label><input type="number" min="0" id="aish-ai-skill-filter" name="skill_id" value="' . $skillId . '"> <button class="button" type="submit">筛选</button></p></form>';
		self::openForm( 'batch_control' ); echo '<p><label for="aish-ai-control">选中任务操作（最多 10 个） </label>'; self::select( 'control', array( 'pause' => '暂停', 'resume' => '恢复', 'cancel' => '取消', 'retry' => '重试失败任务' ), 'pause' );
		echo ' <button class="button" type="submit">执行</button></p><p><label><input type="checkbox" name="confirmed" value="1"> 重试前已核对 Provider 记录，了解不确定请求可能已计费、重试可能再次计费。</label></p>';
		echo '<table class="widefat striped"><thead><tr><th>选择</th><th>任务 / Skill</th><th>配置 / 范围</th><th>状态</th><th>用量与时间</th></tr></thead><tbody>';
		foreach ( $result['items'] as $row ) {
			echo '<tr><td><input type="checkbox" name="ids[]" value="' . (int) $row['id'] . '" aria-label="' . esc_attr( '选择任务 #' . (int) $row['id'] ) . '"></td><td><a href="' . esc_url( self::url( 'generation', array( 'id' => (int) $row['id'] ) ) ) . '">#' . (int) $row['id'] . '</a><br>' . esc_html( empty( $row['skill_id'] ) ? '连接测试' : 'Skill #' . (int) $row['skill_id'] ) . '</td><td>' . esc_html( self::text( $row['provider'] ?? '' ) . ' / ' . self::text( $row['model'] ?? '' ) ) . '<br>' . esc_html( self::MODES[ $row['mode'] ] ?? '连接测试' ) . '</td><td>' . esc_html( self::state( (string) $row['status'] ) ) . '<br>' . esc_html( self::state( self::text( $row['review_status'] ?? '' ) ) ) . '<br><code>' . esc_html( self::text( $row['last_error'] ?? '' ) ) . '</code></td><td>' . esc_html( self::usage( $row ) ) . '<br>' . esc_html( self::text( $row['updated_at'] ?? '' ) ) . ' UTC</td></tr>';
		}
		if ( ! $result['items'] ) { echo '<tr><td colspan="5">暂无生成任务。</td></tr>'; }
		echo '</tbody></table></form>';
		$pages = (int) ceil( (int) $result['total'] / max( 1, (int) ( $result['per_page'] ?? 25 ) ) );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			foreach ( array( $page - 1 => '上一页', $page + 1 => '下一页' ) as $target => $label ) { if ( $target >= 1 && $target <= $pages ) { echo '<a class="button" href="' . esc_url( self::url( 'generation', array( 'paged' => $target, 'status' => $status, 'skill_id' => $skillId ) ) ) . '">' . esc_html( $label ) . '</a> '; } }
			echo esc_html( $page . ' / ' . $pages ) . '</div></div>';
		}
	}
	private static function json( mixed $value ): string { return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
	private function detail( int $id, int $reviewPage = 1, int $reviewId = 0 ): void {
		$job = $this->store->job( $id, $reviewPage ); $source = is_array( $job['source'] ?? null ) ? $job['source'] : array();
		$selectedReview = $reviewId ? $this->store->reviewSnapshot( $id, $reviewId ) : null;
		echo '<p><a href="' . esc_url( self::url() ) . '">返回生成历史</a></p><h2>' . esc_html( '任务 #' . $id . ' · ' . self::state( (string) $job['status'] ) ) . '</h2>';
		echo '<p>' . esc_html( 'Provider / Model：' . self::text( $job['provider'] ?? '' ) . ' / ' . self::text( $job['model'] ?? '' ) . '；Prompt：' . self::text( $job['prompt_version'] ?? '' ) ) . '</p><p>' . esc_html( self::usage( $job ) . '；执行耗时：' . (int) ( $job['elapsed_ms'] ?? 0 ) . ' ms' ) . '</p><p><code>' . esc_html( self::text( $job['last_error'] ?? '' ) ) . '</code></p>';
		if ( ! empty( $job['skill_id'] ) ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'aish-skill-edit', 'id' => (int) $job['skill_id'] ), admin_url( 'admin.php' ) ) ) . '">打开 Skill 编辑器</a> <a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'aish-reviews' ), admin_url( 'admin.php' ) ) ) . '">正常发布审核</a></p><h3>原始 GitHub 来源（不可信数据）</h3>';
			$repository = is_array( $source['repository'] ?? null ) ? $source['repository'] : array(); $owner = self::text( $repository['owner'] ?? '' ); $name = self::text( $repository['name'] ?? '' );
			if ( '' !== $owner && '' !== $name ) { echo '<p>来源仓库：<a target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://github.com/' . rawurlencode( $owner ) . '/' . rawurlencode( $name ) ) . '">' . esc_html( $owner . '/' . $name ) . '</a></p>'; }
			echo '<p>' . esc_html( '路径：' . self::text( $source['source_path'] ?? '' ) ) . '</p><p>Commit SHA：<code>' . esc_html( self::text( $job['source_commit'] ?? $source['commit_sha'] ?? '' ) ) . '</code><br>File SHA：<code>' . esc_html( self::text( $job['file_sha'] ?? $source['file_sha'] ?? '' ) ) . '</code></p>';
			echo '<textarea class="large-text code" rows="12" readonly aria-label="原始来源">' . esc_textarea( self::text( $source['raw'] ?? '' ) ) . '</textarea>';
			echo '<details><summary>Phase 5 静态检查与 License 证据（独立保留）</summary><pre>' . esc_html( self::json( $source['static_findings'] ?? array() ) ) . '</pre></details>';
		}
		$output = is_array( $job['output'] ?? null ) ? $job['output'] : array();
		if ( $output ) {
			echo '<h3>AI 原始建议（待核实）</h3><p>以下内容没有自动发布。AI 风险分析不能替代静态扫描和人工审核，也不是安全认证。新分类和标签仅为建议。</p>';
			$risk = is_array( $output['risk'] ?? null ) ? $output['risk'] : array(); $level = array( 'unknown' => 'Unknown / 待核实', 'low' => 'Low / 低', 'medium' => 'Medium / 中', 'high' => 'High / 高' )[ $risk['level'] ?? '' ] ?? 'Unknown / 待核实';
			echo '<p><strong>' . esc_html( 'AI 建议风险等级：' . $level ) . '</strong></p>';
			if ( is_array( $risk['limitations'] ?? null ) && $risk['limitations'] ) { echo '<ul>'; foreach ( $risk['limitations'] as $limitation ) { echo '<li>' . esc_html( self::text( $limitation ) ) . '</li>'; } echo '</ul>'; }
			echo '<details><summary>查看结构化原始结果、证据和解释</summary><pre>' . esc_html( self::json( $output ) ) . '</pre></details>';
			echo '<p><a href="' . esc_url( add_query_arg( 'page', 'aish-categories', admin_url( 'admin.php' ) ) ) . '">核对已有分类</a> · <a href="' . esc_url( add_query_arg( 'page', 'aish-tags', admin_url( 'admin.php' ) ) ) . '">核对已有标签</a>；创建新分类或标签须在对应原生管理页面人工确认。</p>';
		}
		$edited = is_array( $job['edited'] ?? null ) ? $job['edited'] : array(); $current = is_array( $job['current_translations'] ?? null ) ? $job['current_translations'] : array();
		if ( $edited ) {
			$editable = self::canReview() && 'needs_review' === $job['status'] && in_array( $job['review_status'] ?? '', array( 'pending', 'edited' ), true );
			echo '<h3>人工草稿与现有内容</h3><p>修改下面的草稿仅保存到生成历史。采用时仅允许填充当前空字段；已有人工内容需要在 Skill 编辑器中由编辑人员逐项合并。来源或内容版本变化时必须重新生成或核对。</p>';
			if ( $editable ) { self::openForm( 'save_review' ); self::hidden( 'job_id', $id ); self::hidden( 'revision', (int) $job['revision'] ); }
			foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $locale => $label ) {
				if ( ! isset( $edited[ $locale ] ) || ! is_array( $edited[ $locale ] ) ) { continue; }
				echo '<h4>' . esc_html( $label ) . '</h4><table class="widefat"><thead><tr><th>字段</th><th>人工编辑的待审核草稿</th><th>当前人工 / 已审核内容（只读）</th></tr></thead><tbody>';
				foreach ( array( 'title' => '标题', 'summary' => '简介', 'content' => '正文' ) as $field => $title ) {
					if ( ! array_key_exists( $field, $edited[ $locale ] ) ) { continue; }
					echo '<tr><th>' . esc_html( $title ) . '</th><td><textarea class="large-text" rows="' . ( 'content' === $field ? '14' : '4' ) . '" name="translations[' . esc_attr( $locale ) . '][' . esc_attr( $field ) . ']" aria-label="' . esc_attr( $label . '草稿' . $title ) . '"' . ( $editable ? '' : ' readonly' ) . '>' . esc_textarea( self::text( $edited[ $locale ][ $field ] ) ) . '</textarea></td><td><textarea class="large-text" rows="' . ( 'content' === $field ? '14' : '4' ) . '" readonly aria-label="' . esc_attr( $label . '现有' . $title ) . '">' . esc_textarea( self::text( $current[ $locale ][ $field ] ?? '' ) ) . '</textarea></td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( $editable ) {
				submit_button( '保存人工草稿' ); echo '</form>';
				if ( 'use_cases' !== $job['mode'] ) {
				self::openForm( 'accept' ); self::hidden( 'job_id', $id ); self::hidden( 'revision', (int) $job['revision'] ); echo '<h4>选择要填入 Skill 的空字段</h4><p>先保存上面的修改，再采用草稿。此操作将 Skill 转为待审核，不会发布。</p>';
				foreach ( $edited as $locale => $fields ) {
					foreach ( array( 'title', 'summary', 'content' ) as $field ) {
						if ( ! array_key_exists( $field, $fields ) || ( 'use_cases' === $job['mode'] && 'content' === $field ) ) { continue; }
						$available = '' === self::text( $current[ $locale ][ $field ] ?? '' );
						echo '<p><label><input type="checkbox" name="fields[]" value="' . esc_attr( $locale . '.' . $field ) . '"' . ( $available ? '' : ' disabled' ) . '> ' . esc_html( $locale . ' · ' . $field . ( $available ? '' : '（现有内容受保护）' ) ) . '</label></p>';
					}
				}
				echo '<p><label><input type="checkbox" name="confirmed" value="1" required> 已核实所选草稿、安装命令、License 和风险证据，确认送入正常待审核流程。</label></p>'; submit_button( '采用所选空字段', 'secondary' ); echo '</form>';
				} else { echo '<p>使用场景是局部正文建议，请在 Skill 编辑器中人工合并，不自动替换整篇正文。</p>'; }
				self::openForm( 'reject' ); self::hidden( 'job_id', $id ); self::hidden( 'revision', (int) $job['revision'] ); submit_button( '拒绝此 AI 草稿', 'secondary' ); echo '</form>';
			}
		}
		echo '<h3>请求尝试与用量记录</h3><table class="widefat striped"><thead><tr><th>尝试 / 状态</th><th>用量与耗时</th><th>错误 / 时间</th></tr></thead><tbody>';
		foreach ( $job['attempts_history'] ?? array() as $attempt ) { echo '<tr><td>' . (int) ( $attempt['attempt_number'] ?? $attempt['id'] ?? 0 ) . ' / ' . esc_html( self::text( $attempt['status'] ?? '' ) ) . '</td><td>' . esc_html( self::usage( $attempt ) . '；' . (int) ( $attempt['elapsed_ms'] ?? 0 ) . ' ms' ) . '</td><td><code>' . esc_html( self::text( $attempt['error_code'] ?? '' ) ) . '</code><br>' . esc_html( '开始：' . ( self::text( $attempt['started_at'] ?? '' ) ?: '—' ) . '；完成：' . ( self::text( $attempt['completed_at'] ?? '' ) ?: '—' ) ) . ' UTC</td></tr>'; }
		echo '</tbody></table><h3 id="aish-ai-review-history">人工操作历史</h3><p>历史记录全部保留；列表分页显示操作信息，选择一条记录后查看其内容快照。</p><table class="widefat striped"><thead><tr><th>修订 / 操作</th><th>操作者 / 时间</th><th>快照</th></tr></thead><tbody>';
		foreach ( $job['reviews'] as $review ) {
			$url = self::url( 'generation', array( 'id' => $id, 'review_page' => $reviewPage, 'review_id' => (int) $review['id'] ) ) . '#aish-ai-review-snapshot';
			echo '<tr><td>' . (int) $review['revision'] . ' / ' . esc_html( self::state( (string) $review['action'] ) ) . '</td><td>' . (int) $review['actor_id'] . ' / ' . esc_html( (string) $review['created_at'] ) . ' UTC</td><td><a href="' . esc_url( $url ) . '">查看此修订快照</a></td></tr>';
		}
		if ( ! $job['reviews'] ) { echo '<tr><td colspan="3">此页暂无人工操作记录。</td></tr>'; }
		echo '</tbody></table>';
		$pages = max( 1, (int) ceil( (int) $job['reviews_total'] / (int) $job['reviews_per_page'] ) );
		echo '<div class="tablenav"><div class="tablenav-pages">';
		foreach ( array( $reviewPage - 1 => '上一页', $reviewPage + 1 => '下一页' ) as $target => $label ) {
			if ( $target >= 1 && $target <= min( 10000, $pages ) ) { echo '<a class="button" href="' . esc_url( self::url( 'generation', array( 'id' => $id, 'review_page' => $target ) ) . '#aish-ai-review-history' ) . '">' . esc_html( $label ) . '</a> '; }
		}
		echo esc_html( $reviewPage . ' / ' . $pages . '（' . (int) $job['reviews_total'] . ' 条）' ) . '</div></div>';
		if ( $selectedReview ) {
			echo '<h4 id="aish-ai-review-snapshot">' . esc_html( '修订 #' . (int) $selectedReview['revision'] . ' 的历史快照（只读）' ) . '</h4><pre>' . esc_html( self::json( $selectedReview['snapshot'] ) ) . '</pre>';
		}
	}

	private static function ids( mixed $raw ): array {
		if ( ! is_array( $raw ) || count( $raw ) < 1 || count( $raw ) > 10 ) { throw new \InvalidArgumentException( 'Invalid AI batch.' ); }
		$result = array();
		foreach ( $raw as $id ) { if ( ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) || false === ( $value = filter_var( $id, FILTER_VALIDATE_INT ) ) ) { throw new \InvalidArgumentException( 'Invalid AI batch identity.' ); } $result[ $value ] = $value; }
		return array_values( $result );
	}
	private static function translations( array $input ): array {
		$translations = $input['translations'] ?? null;
		if ( ! is_array( $translations ) || ! $translations || array_diff( array_keys( $translations ), array( 'zh_CN', 'en_US' ) ) ) { throw new \InvalidArgumentException( 'Invalid review language.' ); }
		$result = array();
		foreach ( $translations as $locale => $fields ) {
			if ( ! is_array( $fields ) || ! $fields || array_diff( array_keys( $fields ), array( 'title', 'summary', 'content' ) ) ) { throw new \InvalidArgumentException( 'Invalid review fields.' ); }
			foreach ( $fields as $field => $value ) { $result[ $locale ][ $field ] = self::input( $fields, $field, '', 'content' === $field ? 524288 : 8192 ); }
		}
		return $result;
	}

	public function handle(): never {
		$rawOperation = is_string( $_POST['operation'] ?? null ) ? $_POST['operation'] : '';
		$isSettings = in_array( $rawOperation, array( 'save_profile', 'remove_profile', 'global_pause', 'connection' ), true );
		self::authorize( $isSettings ? self::SETTINGS : self::GENERATE );
		if ( 'connection' === $rawOperation ) { self::authorize( self::GENERATE ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( '仅允许 POST 请求。', 'ai-skills-hub' ), '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aish_ai_action' );
		$notice = array( 'kind' => 'error', 'success' => 0, 'failed' => 1 ); $redirect = self::url( $isSettings ? 'settings' : 'generation' );
		try {
			$operation = self::input( $_POST, 'operation', '', 30 );
			if ( 'save_profile' === $operation ) {
				$profile = array(); foreach ( array( 'provider', 'base_url', 'model', 'token_parameter' ) as $field ) { $profile[ $field ] = self::input( $_POST, $field ); }
				foreach ( array( 'temperature', 'input_price', 'output_price' ) as $field ) { $profile[ $field ] = self::number( $_POST, $field, true ); }
				$profile['max_cost'] = self::number( $_POST, 'max_cost' ); $profile['timeout'] = self::id( $_POST, 'timeout' ); $profile['max_output_tokens'] = self::id( $_POST, 'max_output_tokens' );
				$enabled = self::input( $_POST, 'enabled', '0', 1 ); if ( ! in_array( $enabled, array( '0', '1' ), true ) ) { throw new \InvalidArgumentException( 'Invalid profile flag.' ); } $profile['enabled'] = '1' === $enabled;
				$this->settings->save( self::input( $_POST, 'profile_id', '', 32 ), $profile ); $notice = array( 'kind' => 'saved', 'success' => 1, 'failed' => 0 );
			} elseif ( 'remove_profile' === $operation ) {
				if ( '1' !== self::input( $_POST, 'confirmed', '0', 1 ) ) { throw new \InvalidArgumentException( 'Confirmation required.' ); }
				$this->settings->remove( self::input( $_POST, 'profile_id', '', 32 ) ); $notice = array( 'kind' => 'saved', 'success' => 1, 'failed' => 0 );
			} elseif ( 'global_pause' === $operation ) {
				$value = self::input( $_POST, 'paused', '', 1 ); if ( ! in_array( $value, array( '0', '1' ), true ) ) { throw new \InvalidArgumentException( 'Invalid queue flag.' ); }
				$this->settings->setPaused( '1' === $value ); $notice = array( 'kind' => 'saved', 'success' => 1, 'failed' => 0 ); do_action( 'aish_ai_wake' );
			} elseif ( 'connection' === $operation ) {
				if ( '1' !== self::input( $_POST, 'confirmed', '0', 1 ) ) { throw new \InvalidArgumentException( 'Confirmation required.' ); }
				$id = $this->service->enqueueConnection( self::input( $_POST, 'profile_id', '', 32 ), hash( 'sha256', self::requestId( $_POST ) . ':connection' ) ); $notice = array( 'kind' => 'queued', 'success' => 1, 'failed' => 0 ); $redirect = self::url( 'generation', array( 'id' => $id ) ); do_action( 'aish_ai_wake' );
			} elseif ( 'generate' === $operation ) {
				$raw = trim( self::input( $_POST, 'skill_ids', '', 220 ) ); if ( ! preg_match( '/^[0-9,\s]+$/D', $raw ) ) { throw new \InvalidArgumentException( 'Invalid Skill list.' ); }
				$ids = self::ids( preg_split( '/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ); $mode = self::input( $_POST, 'mode', '', 20 ); $profile = self::input( $_POST, 'profile_id', '', 32 ); $key = self::requestId( $_POST );
				if ( ! isset( self::MODES[ $mode ] ) ) { throw new \InvalidArgumentException( 'Invalid generation mode.' ); }
				$notice = array( 'kind' => 'queued', 'success' => 0, 'failed' => 0 );
				foreach ( $ids as $id ) { try { $this->service->enqueue( $id, $profile, $mode, hash( 'sha256', $key . ':' . $id ) ); ++$notice['success']; } catch ( \Throwable $error ) { ++$notice['failed']; } }
				do_action( 'aish_ai_wake' );
			} elseif ( 'batch_control' === $operation ) {
				$control = self::input( $_POST, 'control', '', 20 ); if ( ! in_array( $control, array( 'pause', 'resume', 'cancel', 'retry' ), true ) ) { throw new \InvalidArgumentException( 'Invalid task control.' ); }
				$ids = self::ids( $_POST['ids'] ?? null ); if ( 'retry' === $control && '1' !== self::input( $_POST, 'confirmed', '0', 1 ) ) { throw new \InvalidArgumentException( 'Retry confirmation required.' ); }
				$notice = array( 'kind' => 'saved', 'success' => 0, 'failed' => 0 ); foreach ( $ids as $id ) { try { $this->store->control( $id, $control ); ++$notice['success']; } catch ( \Throwable $error ) { ++$notice['failed']; } } do_action( 'aish_ai_wake' );
			} elseif ( in_array( $operation, array( 'save_review', 'accept', 'reject' ), true ) ) {
				if ( ! self::canReview() ) { throw new \DomainException( 'Review capability required.' ); }
				$id = self::id( $_POST, 'job_id' ); $revision = self::id( $_POST, 'revision' ); $redirect = self::url( 'generation', array( 'id' => $id ) );
				if ( 'save_review' === $operation ) { $this->store->saveReview( $id, $revision, self::translations( $_POST ) ); }
				elseif ( 'reject' === $operation ) { $this->store->reject( $id, $revision ); }
				else {
					if ( '1' !== self::input( $_POST, 'confirmed', '0', 1 ) ) { throw new \InvalidArgumentException( 'Acceptance confirmation required.' ); }
					$fields = $_POST['fields'] ?? null; if ( ! is_array( $fields ) || ! $fields || count( $fields ) > 6 ) { throw new \InvalidArgumentException( 'Invalid selected fields.' ); }
					foreach ( $fields as $field ) { if ( ! is_string( $field ) || ! preg_match( '/^(?:zh_CN|en_US)\.(?:title|summary|content)$/D', $field ) ) { throw new \InvalidArgumentException( 'Invalid selected field.' ); } }
					$this->store->accept( $id, $revision, array_values( array_unique( $fields ) ) );
				}
				$notice = array( 'kind' => 'reviewed', 'success' => 1, 'failed' => 0 );
			} else { throw new \InvalidArgumentException( 'Unknown AI operation.' ); }
		} catch ( \Throwable $error ) { $notice = array( 'kind' => 'error', 'success' => 0, 'failed' => 1 ); }
		set_transient( 'aish_ai_notice_' . get_current_user_id(), $notice, 60 ); wp_safe_redirect( $redirect, 303 ); exit;
	}
}
