<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

defined( 'ABSPATH' ) || exit;

/** Native admin controllers only enqueue work; untrusted source text is always escaped. */
final class Admin {
	private const CAP = 'aish_manage_repositories';
	private const VIEWS = array( 'repositories', 'jobs', 'imports', 'job', 'import', 'archive' );
	public function __construct( private ImportStore $store ) {}

	public function register(): void {
		add_action( 'admin_menu', function (): void {
			add_submenu_page( 'aish', 'GitHub Skills 采集', 'GitHub Imports', current_user_can( 'manage_options' ) ? 'manage_options' : self::CAP, 'aish-imports', array( $this, 'screen' ) );
		} );
		add_action( 'admin_post_aish_import_action', array( $this, 'handle' ) );
	}

	private static function can( string $cap = self::CAP ): bool { return current_user_can( $cap ) || current_user_can( 'manage_options' ); }
	private static function authorize(): void {
		if ( ! self::can() ) { wp_die( esc_html__( '无权管理 GitHub 采集。', 'ai-skills-hub' ), '', array( 'response' => 403 ) ); }
	}
	private static function input( array $data, string $key, string $default = '', int $limit = 2048 ): string {
		$value = $data[ $key ] ?? $default;
		if ( ! is_string( $value ) || strlen( $value ) > $limit || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'Invalid import input.' ); }
		return wp_unslash( $value );
	}
	private static function id( array $data, string $key, int $default = 0 ): int {
		$value = filter_var( self::input( $data, $key, (string) $default, 20 ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $value ) { throw new \InvalidArgumentException( 'Invalid import identity.' ); }
		return $value;
	}
	private static function url( string $view = 'repositories', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'aish-imports', 'view' => $view ), $args ), admin_url( 'admin.php' ) );
	}
	private static function hidden( string $key, string|int $value ): void { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">'; }
	private static function openForm( string $operation ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( 'action', 'aish_import_action' ); self::hidden( 'operation', $operation ); wp_nonce_field( 'aish_import_action' );
	}
	private static function label( string $status ): string {
		return array( 'queued' => '已排队', 'pending' => '待执行', 'running' => '执行中', 'retry' => '等待重试', 'complete' => '完整完成', 'incomplete' => '不完整，需处理', 'failed' => '失败', 'cancelled' => '已取消', 'idle' => '未开始', 'new' => '新增待审核', 'updated' => '来源更新待处理', 'inspection_updated' => '依赖或风险检查变化', 'missing' => '来源缺失待核实', 'restored' => '来源恢复待处理', 'handled' => '来源变更已处理', 'present' => '来源存在' )[ $status ] ?? $status;
	}
	private static function text( mixed $value ): string { return is_scalar( $value ) ? (string) $value : ''; }
	private static function date( mixed $value ): string { return self::text( $value ) ?: '—'; }
	private static function tableHeader( array $labels ): void {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $labels as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
	}
	private static function emptyRow( int $columns ): void { echo '<tr><td colspan="' . $columns . '">' . esc_html__( '暂无记录。', 'ai-skills-hub' ) . '</td></tr>'; }
	private static function pagination( array $result, string $view, array $args = array() ): void {
		$page = max( 1, (int) ( $result['page'] ?? 1 ) );
		$pages = (int) ceil( (int) ( $result['total'] ?? 0 ) / max( 1, (int) ( $result['per_page'] ?? 25 ) ) );
		if ( $pages < 2 ) { return; }
		echo '<div class="tablenav"><div class="tablenav-pages">';
		if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( $view, array_merge( $args, array( 'paged' => $page - 1 ) ) ) ) . '">' . esc_html__( '上一页', 'ai-skills-hub' ) . '</a> '; }
		echo esc_html( $page . ' / ' . $pages );
		if ( $page < $pages ) { echo ' <a class="button" href="' . esc_url( self::url( $view, array_merge( $args, array( 'paged' => $page + 1 ) ) ) ) . '">' . esc_html__( '下一页', 'ai-skills-hub' ) . '</a>'; }
		echo '</div></div>';
	}

	public function screen(): void {
		self::authorize();
		echo '<div class="wrap"><h1>AI Skills Hub · GitHub Imports</h1>';
		try {
			$view = self::input( $_GET, 'view', 'repositories', 20 );
			if ( ! in_array( $view, self::VIEWS, true ) ) { throw new \InvalidArgumentException( 'Invalid import view.' ); }
			$page = self::id( $_GET, 'paged', 1 );
			if ( $page < 1 || $page > 10000 ) { throw new \InvalidArgumentException( 'Invalid page.' ); }
			$this->notices();
			echo '<nav class="nav-tab-wrapper" aria-label="GitHub Imports">';
			foreach ( array( 'repositories' => '仓库与调度', 'jobs' => '任务与扫描结果', 'imports' => '来源变更' ) as $key => $title ) { echo '<a class="nav-tab' . ( $view === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $title ) . '</a>'; }
			echo '</nav><p>' . esc_html__( '采集只保存待审核来源，不执行脚本、不安装依赖，也不会覆盖人工译文或自动发布。', 'ai-skills-hub' ) . '</p>';
			match ( $view ) {
				'repositories' => $this->repositories( $page ),
				'jobs' => $this->jobs( $page, self::id( $_GET, 'repository_id' ), self::input( $_GET, 'status', '', 30 ) ),
				'imports' => $this->imports( $page, self::id( $_GET, 'repository_id' ), self::input( $_GET, 'change', '', 30 ) ),
				'job' => $this->job( self::id( $_GET, 'id' ), $page ),
				'import' => $this->import( self::id( $_GET, 'id' ) ),
				'archive' => $this->archive( self::id( $_GET, 'id' ) ),
			};
		} catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html__( '无法显示采集记录。请检查参数、权限和数据库升级状态。', 'ai-skills-hub' ) . '</p></div>'; }
		echo '</div>';
	}

	private function notices(): void {
		$key = 'aish_import_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) { return; }
		delete_transient( $key );
		$failed = max( 0, (int) ( $notice['failed'] ?? 0 ) ); $success = max( 0, (int) ( $notice['success'] ?? 0 ) );
		$message = match ( $notice['kind'] ?? '' ) {
			'queued' => '已受理任务（包含已有未完成任务）：' . $success . '。未完成：' . $failed . '。请在任务页核对结果；入队不代表扫描成功。',
			'handled' => '来源变更已标记处理。Skill 发布状态及译文未改变，发布仍需现有人工审核。',
			'archived' => '仓库已归档，采集已停用。Skills、历史版本及日志保留。',
			'saved' => '已保存：' . $success . '。未完成：' . $failed . '。',
			default => '操作未完成。请重新载入最新记录，检查字段、权限与数据库状态后重试。',
		};
		echo '<div class="notice ' . ( $failed || 'error' === ( $notice['kind'] ?? '' ) ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function rate(): void {
		$rate = GitHubClient::rateStatus();
		echo '<h2>' . esc_html__( 'API 状态', 'ai-skills-hub' ) . '</h2><p>' . esc_html__( 'Token 仅从服务器配置读取，不在此处输入或回显。未配置时使用公开 API 配额。', 'ai-skills-hub' ) . '</p><ul>';
		echo '<li>' . esc_html( 'Token：' . ( GitHubClient::tokenConfigured() ? '已配置' : '未配置（可选）' ) ) . '</li>';
		if ( ! empty( $rate['configuration_error'] ) ) { echo '<li>' . esc_html__( '服务器 Token 配置格式无效，请由管理员在服务器配置中修正。', 'ai-skills-hub' ) . '</li>'; }
		foreach ( array( 'remaining' => '最近剩余额度', 'reset' => '额度重置时间（UTC）', 'cooldown_until' => '冷却截止时间（UTC）' ) as $key => $label ) {
			$value = $rate[ $key ] ?? null;
			$text = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? ( 'remaining' === $key ? (string) $value : ( (int) $value > 0 ? gmdate( 'Y-m-d H:i:s', (int) $value ) : '—' ) ) : '未知';
			echo '<li>' . esc_html( $label . '：' . $text ) . '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( '这是最近请求的状态，不会为了刷新此页面而请求 GitHub。限流或短暂故障由后台任务退避重试。', 'ai-skills-hub' ) . '</p>';
	}

	private function repositories( int $page ): void {
		$this->rate();
		$id = self::id( $_GET, 'edit' ); $repository = $id ? $this->store->repository( $id ) : array();
		if ( $id && ! $repository ) { throw new \InvalidArgumentException( 'Missing repository.' ); }
		echo '<h2>' . esc_html( $id ? '编辑仓库 #' . $id : '添加 GitHub 仓库' ) . '</h2>';
		if ( ! empty( $repository['is_archived'] ) ) { echo '<p>' . esc_html__( '此仓库已归档；保存将恢复仓库记录，定期同步仍以所选配置为准。', 'ai-skills-hub' ) . '</p>'; }
		self::openForm( 'save' ); self::hidden( 'repository_id', $id );
		echo '<p><label for="aish-github-url">GitHub URL</label><br><input class="large-text" type="url" id="aish-github-url" name="repository_url" maxlength="2048" required value="' . esc_attr( $id ? 'https://github.com/' . $repository['owner'] . '/' . $repository['name'] : '' ) . '" placeholder="https://github.com/owner/repository"></p>';
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $repository['sync_enabled'] ), true, false ) . '> ' . esc_html__( '启用定期同步（默认关闭）', 'ai-skills-hub' ) . '</label></p>';
		echo '<p><label for="aish-sync-interval">' . esc_html__( '同步间隔（秒，3600–604800）', 'ai-skills-hub' ) . '</label> <input type="number" id="aish-sync-interval" name="interval" min="3600" max="604800" required value="' . (int) ( $repository['sync_interval'] ?? 86400 ) . '"></p>';
		echo '<p class="description">' . esc_html__( '保存只登记来源，不会在此请求中扫描仓库。定期同步依赖 WordPress Cron；已关联内容的仓库不能随意换成另一仓库。', 'ai-skills-hub' ) . '</p>';
		submit_button( __( '保存仓库', 'ai-skills-hub' ) ); echo '</form>';
		$result = $this->store->repositories( $page );
		echo '<h2>' . esc_html__( '现有仓库', 'ai-skills-hub' ) . '</h2>';
		self::openForm( 'batch' );
		echo '<p><label for="aish-import-batch">' . esc_html__( '选中仓库操作（最多 10 个）', 'ai-skills-hub' ) . '</label> <select id="aish-import-batch" name="batch_action"><option value="scan">完整扫描</option><option value="sync">增量同步</option><option value="disable">关闭定期同步</option></select> <button class="button" type="submit">' . esc_html__( '执行', 'ai-skills-hub' ) . '</button></p>';
		self::tableHeader( array( '选择', '仓库', 'GitHub ID / 默认分支', '调度 / 来源状态', '最后完整同步（UTC）', '操作' ) );
		foreach ( $result['items'] as $row ) {
			$rid = (int) $row['id'];
			echo '<tr><td><input type="checkbox" name="ids[]" value="' . $rid . '" aria-label="' . esc_attr( '选择仓库 #' . $rid ) . '"' . ( ! empty( $row['is_archived'] ) ? ' disabled' : '' ) . '></td><td><a href="' . esc_url( 'https://github.com/' . $row['owner'] . '/' . $row['name'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $row['owner'] . '/' . $row['name'] ) . '</a><br>#' . $rid . ( ! empty( $row['is_archived'] ) ? ' · 已归档' : '' ) . '</td><td>' . esc_html( self::text( $row['github_id'] ?? '' ) ?: '未获取' ) . '<br>' . esc_html( self::text( $row['default_branch'] ?? '' ) ?: '未获取' ) . '</td><td>' . esc_html( ! empty( $row['sync_enabled'] ) ? '定期：' . (int) $row['sync_interval'] . ' 秒' : '定期关闭' ) . '<br>' . esc_html( self::label( self::text( $row['sync_status'] ?? 'idle' ) ) ) . '</td><td>' . esc_html( self::date( $row['last_sync_at'] ?? null ) ) . '<br><code>' . esc_html( self::text( $row['last_sync_commit'] ?? '' ) ) . '</code></td><td><a href="' . esc_url( self::url( 'repositories', array( 'edit' => $rid ) ) ) . '">编辑</a> · <a href="' . esc_url( self::url( 'jobs', array( 'repository_id' => $rid ) ) ) . '">任务</a> · <a href="' . esc_url( self::url( 'imports', array( 'repository_id' => $rid ) ) ) . '">来源变更</a>';
			if ( empty( $row['is_archived'] ) ) { echo ' · <a href="' . esc_url( self::url( 'archive', array( 'id' => $rid ) ) ) . '">归档并停用</a>'; }
			echo '</td></tr>';
		}
		if ( ! $result['items'] ) { self::emptyRow( 6 ); }
		echo '</tbody></table></form>'; self::pagination( $result, 'repositories' );
	}

	private static function filterForm( string $view, int $repository, string $key, string $current, array $options ): void {
		echo '<form method="get"><p>'; self::hidden( 'page', 'aish-imports' ); self::hidden( 'view', $view );
		if ( $repository ) { self::hidden( 'repository_id', $repository ); }
		echo '<label for="aish-import-filter">' . esc_html__( '筛选状态', 'ai-skills-hub' ) . '</label> <select id="aish-import-filter" name="' . esc_attr( $key ) . '"><option value="">' . esc_html__( '全部', 'ai-skills-hub' ) . '</option>';
		foreach ( $options as $value ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $current, false ) . '>' . esc_html( self::label( $value ) ) . '</option>'; }
		echo '</select> <button class="button" type="submit">' . esc_html__( '筛选', 'ai-skills-hub' ) . '</button></p></form>';
	}

	private function jobs( int $page, int $repository, string $status = '' ): void {
		$result = $this->store->jobs( $page, $repository, $status );
		echo '<h2>' . esc_html( '任务与扫描结果' . ( $repository ? ' · Repository #' . $repository : '' ) ) . '</h2><p>' . esc_html__( '完整完成表示目录清单和候选文件均完成处理；不完整、失败、重试及取消均不代表完整采集。', 'ai-skills-hub' ) . '</p>';
		self::filterForm( 'jobs', $repository, 'status', $status, array( 'queued', 'running', 'retry', 'complete', 'incomplete', 'failed', 'cancelled' ) );
		self::tableHeader( array( '任务 / 仓库', '类型', '状态', '连续失败次数 / 错误', '可执行时间（UTC）', '更新（UTC）' ) );
		foreach ( $result['items'] as $row ) {
			echo '<tr><td><a href="' . esc_url( self::url( 'job', array( 'id' => (int) $row['id'] ) ) ) . '">#' . (int) $row['id'] . '</a> / #' . (int) $row['repository_id'] . '</td><td>' . esc_html( 'scan' === $row['kind'] ? '完整扫描' : '增量同步' ) . '</td><td>' . esc_html( self::label( (string) $row['status'] ) ) . '</td><td>' . (int) $row['attempts'] . '<br>' . esc_html( self::text( $row['last_error'] ?? '' ) ) . '</td><td>' . esc_html( self::date( $row['available_at'] ?? null ) ) . '</td><td>' . esc_html( self::date( $row['updated_at'] ?? null ) ) . '</td></tr>';
		}
		if ( ! $result['items'] ) { self::emptyRow( 6 ); }
		echo '</tbody></table>'; self::pagination( $result, 'jobs', array( 'repository_id' => $repository, 'status' => $status ) );
	}

	private function imports( int $page, int $repository, string $change = '' ): void {
		$result = $this->store->imports( $page, $repository, $change );
		echo '<h2>' . esc_html( '来源变更' . ( $repository ? ' · Repository #' . $repository : '' ) ) . '</h2>';
		self::filterForm( 'imports', $repository, 'change', $change, array( 'new', 'updated', 'inspection_updated', 'missing', 'restored', 'handled' ) );
		self::tableHeader( array( 'Skill / 来源记录', '文件路径', '来源与处理状态', 'File SHA', '更新时间（UTC）' ) );
		foreach ( $result['items'] as $row ) {
			echo '<tr><td><a href="' . esc_url( self::url( 'import', array( 'id' => (int) $row['id'] ) ) ) . '">Skill #' . (int) $row['skill_id'] . ' / Import #' . (int) $row['id'] . '</a></td><td>' . esc_html( self::text( $row['source_path'] ) ) . '</td><td>' . esc_html( self::label( (string) $row['source_status'] ) . ' · ' . self::label( (string) $row['change_status'] ) ) . '</td><td><code>' . esc_html( (string) $row['file_sha'] ) . '</code></td><td>' . esc_html( self::date( $row['updated_at'] ?? null ) ) . '</td></tr>';
		}
		if ( ! $result['items'] ) { self::emptyRow( 5 ); }
		echo '</tbody></table>'; self::pagination( $result, 'imports', array( 'repository_id' => $repository, 'change' => $change ) );
	}

	private function job( int $id, int $page ): void {
		$job = $this->store->job( $id ); if ( ! $job ) { throw new \InvalidArgumentException( 'Missing job.' ); }
		$state = is_array( $job['state'] ?? null ) ? $job['state'] : array();
		echo '<h2>' . esc_html( '任务 #' . $id . ' · ' . self::label( (string) $job['status'] ) ) . '</h2>';
		echo '<p>' . esc_html( 'Repository #' . (int) $job['repository_id'] . ' · ' . (string) $job['kind'] . ' · 错误代码：' . self::text( $job['last_error'] ?? '' ) ) . '</p>';
		echo '<dl><dt>Commit SHA</dt><dd><code>' . esc_html( self::text( $state['commit_sha'] ?? '' ) ) . '</code></dd><dt>目录清单 / 文件处理</dt><dd>' . esc_html( ( ! empty( $state['inventory_complete'] ) ? '目录完整' : '目录未完成' ) . ' / ' . ( ! empty( $state['files_complete'] ) ? '文件处理完整' : '文件未全部完成' ) ) . '</dd></dl>';
		if ( ! empty( $state['unchanged_head'] ) ) { echo '<p>' . esc_html__( '默认分支 Commit 未变化，本次增量同步复用上次完整扫描基线，没有重新读取全部文件。需要重新检查时请选择完整扫描。', 'ai-skills-hub' ) . '</p>'; }
		$counts = is_array( $state['stats'] ?? null ) ? $state['stats'] : array();
		echo '<p>';
		foreach ( array( 'new' => '新增', 'updated' => '更新', 'unchanged' => '未变', 'missing' => '缺失', 'errors' => '失败' ) as $key => $label ) { echo esc_html( $label . '：' . max( 0, (int) ( $counts[ $key ] ?? ( 'errors' === $key ? ( $state['errors'] ?? 0 ) : 0 ) ) ) ) . ' '; }
		echo '</p><p><a class="button" href="' . esc_url( self::url( 'imports', array( 'repository_id' => (int) $job['repository_id'] ) ) ) . '">' . esc_html__( '查看此仓库的来源记录', 'ai-skills-hub' ) . '</a></p><h3>' . esc_html__( '采集日志', 'ai-skills-hub' ) . '</h3>';
		$result = $this->store->logs( $id, $page ); self::tableHeader( array( '时间（UTC）', '级别', '代码', '文件路径 / Skill' ) );
		foreach ( $result['items'] as $row ) { echo '<tr><td>' . esc_html( self::date( $row['created_at'] ?? null ) ) . '</td><td>' . esc_html( self::text( $row['level'] ?? '' ) ) . '</td><td>' . esc_html( self::text( $row['code'] ?? '' ) ) . '</td><td>' . esc_html( self::text( $row['source_path'] ?? $row['path'] ?? '' ) ) . ( ! empty( $row['skill_id'] ) ? ' / #' . (int) $row['skill_id'] : '' ) . '</td></tr>'; }
		if ( ! $result['items'] ) { self::emptyRow( 4 ); }
		echo '</tbody></table>'; self::pagination( $result, 'job', array( 'id' => $id ) );
	}

	private function import( int $id ): void {
		$item = $this->store->import( $id ); if ( ! $item ) { throw new \InvalidArgumentException( 'Missing import.' ); }
		$parsed = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();
		$risk = is_array( $item['findings'] ?? null ) ? $item['findings'] : array();
		$license = is_array( $risk['license'] ?? null ) ? $risk['license'] : array();
		echo '<h2>' . esc_html( 'Skill #' . (int) $item['skill_id'] . ' · ' . self::text( $parsed['name'] ?? '' ) ) . '</h2><p>' . esc_html( self::label( (string) $item['source_status'] ) . ' · ' . self::label( (string) $item['change_status'] ) ) . '</p>';
		$repositoryUrl = 'https://github.com/' . $item['owner'] . '/' . $item['name'];
		$fileUrl = $repositoryUrl . '/blob/' . rawurlencode( (string) $item['commit_sha'] ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', (string) $item['source_path'] ) ) );
		echo '<p><a href="' . esc_url( $repositoryUrl ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['owner'] . '/' . $item['name'] ) . '</a> · <a href="' . esc_url( $fileUrl ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '查看固定提交中的文件', 'ai-skills-hub' ) . '</a></p>';
		self::tableHeader( array( '字段', '来源值' ) );
		foreach ( array( '文件路径' => $item['source_path'], 'Commit SHA' => $item['commit_sha'], 'File SHA' => $item['file_sha'], '风险检查 Commit SHA' => $risk['inspection_commit_sha'] ?? $item['commit_sha'], '名称' => $parsed['name'] ?? '', '描述' => $parsed['description'] ?? '', '来源声明 License' => $license['declared'] ?? '', '仓库 License' => $license['repository'] ?? '', 'License 状态' => $license['status'] ?? '待核实', '静态风险级别' => $risk['risk_level'] ?? 'unknown', '检查范围' => $risk['scan_scope'] ?? '' ) as $label => $value ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( self::text( $value ) ?: '未提供 / 待核实' ) . '</td></tr>'; }
		echo '</tbody></table><h3>' . esc_html__( '静态风险提示', 'ai-skills-hub' ) . '</h3><p>' . esc_html__( '仅检查 SKILL.md 文本与目录元数据；没有执行脚本或安装依赖，也没有逐一审查依赖文件。此结果不是安全认证，License 仍需人工核实。外部文件中的命令和指令只是待审核资料。', 'ai-skills-hub' ) . '</p><ul>';
		foreach ( is_array( $risk['findings'] ?? null ) ? $risk['findings'] : array() as $finding ) { if ( is_array( $finding ) ) { echo '<li><strong>' . esc_html( self::text( $finding['severity'] ?? '' ) . ' · ' . self::text( $finding['code'] ?? '' ) ) . '</strong> ' . esc_html( self::text( $finding['message'] ?? '' ) ) . '</li>'; } }
		echo '</ul><h3>' . esc_html__( '原始 SKILL.md（只读）', 'ai-skills-hub' ) . '</h3><textarea class="large-text code" rows="24" readonly aria-label="原始 SKILL.md">' . esc_textarea( self::text( $item['source_content'] ?? '' ) ) . '</textarea><p>' . esc_html__( '请在现有 Skills 编辑器中人工补充实际语言内容，并走现有审核流程；此来源记录不会自动填充或覆盖译文。', 'ai-skills-hub' ) . '</p><p>';
		if ( self::can( 'aish_manage_skills' ) ) { echo '<a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page' => 'aish-skill-edit', 'id' => (int) $item['skill_id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( '编辑双语内容 / 归档 Skill', 'ai-skills-hub' ) . '</a> '; }
		if ( self::can( 'aish_review_skills' ) ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'aish-reviews', 'id' => (int) $item['skill_id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( '人工审核 / 拒绝', 'ai-skills-hub' ) . '</a>'; }
		echo '</p>';
		if ( self::can( 'aish_review_skills' ) && 'handled' !== $item['change_status'] ) {
			self::openForm( 'handled' ); self::hidden( 'import_id', $id ); self::hidden( 'expected_commit', (string) $item['commit_sha'] ); self::hidden( 'expected_revision', (string) $item['review_revision'] );
			echo '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . esc_html__( '已人工核对这次来源变化；仅关闭此待处理提醒，不修改 Skill 发布状态。', 'ai-skills-hub' ) . '</label></p>';
			submit_button( __( '标记来源变更已处理', 'ai-skills-hub' ), 'secondary' ); echo '</form>';
		}
	}

	private function archive( int $id ): void {
		$row = $this->store->repository( $id ); if ( ! $row ) { throw new \InvalidArgumentException( 'Missing repository.' ); }
		echo '<h2>' . esc_html( '确认归档 ' . $row['owner'] . '/' . $row['name'] ) . '</h2><p>' . esc_html__( '这会停用仓库采集并取消未完成任务。原始仓库、Skills、已发布页面、版本和日志继续保留。', 'ai-skills-hub' ) . '</p>';
		self::openForm( 'archive' ); self::hidden( 'repository_id', $id );
		echo '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . esc_html__( '确认归档并停用此仓库', 'ai-skills-hub' ) . '</label></p>';
		submit_button( __( '确认归档', 'ai-skills-hub' ), 'secondary' ); echo '</form><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '取消并返回', 'ai-skills-hub' ) . '</a></p>';
	}

	public function handle(): never {
		self::authorize();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( '仅允许 POST 请求。', 'ai-skills-hub' ), '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aish_import_action' );
		$notice = array( 'kind' => 'error', 'success' => 0, 'failed' => 1 ); $redirect = self::url();
		try {
			$operation = self::input( $_POST, 'operation', '', 20 );
			if ( 'save' === $operation ) {
				$id = self::id( $_POST, 'repository_id' );
				$enabled = self::input( $_POST, 'enabled', '0', 1 );
				if ( ! in_array( $enabled, array( '0', '1' ), true ) ) { throw new \InvalidArgumentException( 'Invalid enabled flag.' ); }
				$id = $this->store->saveRepository( $id ?: null, self::input( $_POST, 'repository_url' ), '1' === $enabled, self::id( $_POST, 'interval', 86400 ) );
				$redirect = self::url( 'repositories', array( 'edit' => $id ) ); $notice = array( 'kind' => 'saved', 'success' => 1, 'failed' => 0 ); do_action( 'aish_import_wake' );
			} elseif ( 'batch' === $operation ) {
				$action = self::input( $_POST, 'batch_action', '', 20 ); $ids = $_POST['ids'] ?? array();
				if ( ! in_array( $action, array( 'scan', 'sync', 'disable' ), true ) || ! is_array( $ids ) || count( $ids ) < 1 || count( $ids ) > 10 ) { throw new \InvalidArgumentException( 'Invalid import batch.' ); }
				$checked = array(); foreach ( $ids as $raw ) { if ( ! is_string( $raw ) || ! ctype_digit( $raw ) || false === ( $id = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) ) { throw new \InvalidArgumentException( 'Invalid batch identity.' ); } $checked[ $id ] = $id; }
				$notice = array( 'kind' => 'disable' === $action ? 'saved' : 'queued', 'success' => 0, 'failed' => 0 );
				foreach ( $checked as $id ) {
					try {
						if ( 'disable' === $action ) { $repo = $this->store->repository( $id ); $this->store->configureRepository( $id, false, (int) ( $repo['sync_interval'] ?? 86400 ) ); }
						else { $this->store->enqueue( $id, $action ); }
						++$notice['success'];
					} catch ( \Throwable $error ) { ++$notice['failed']; }
				}
				if ( 'disable' !== $action ) { do_action( 'aish_import_wake' ); $redirect = self::url( 'jobs' ); }
			} elseif ( 'archive' === $operation ) {
				if ( '1' !== self::input( $_POST, 'confirmed', '0', 1 ) ) { throw new \InvalidArgumentException( 'Confirmation required.' ); }
				$this->store->archiveRepository( self::id( $_POST, 'repository_id' ) ); $notice = array( 'kind' => 'archived', 'success' => 1, 'failed' => 0 );
			} elseif ( 'handled' === $operation ) {
				if ( ! self::can( 'aish_review_skills' ) || '1' !== self::input( $_POST, 'confirmed', '0', 1 ) ) { throw new \DomainException( 'Review capability and confirmation required.' ); }
				$id = self::id( $_POST, 'import_id' );
				$redirect = self::url( 'import', array( 'id' => $id ) );
				$this->store->markHandled( $id, self::input( $_POST, 'expected_commit', '', 64 ), self::input( $_POST, 'expected_revision', '', 64 ) ); $notice = array( 'kind' => 'handled', 'success' => 1, 'failed' => 0 );
			} else { throw new \InvalidArgumentException( 'Unknown import action.' ); }
		} catch ( \Throwable $error ) { $notice = array( 'kind' => 'error', 'success' => 0, 'failed' => 1 ); }
		set_transient( 'aish_import_notice_' . get_current_user_id(), $notice, 120 );
		wp_safe_redirect( $redirect, 303 );
		exit;
	}
}
