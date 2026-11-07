<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Admin\ContributionUi as Ui;
use AISkillsHub\Core\Community\Input;

defined( 'ABSPATH' ) || exit;

/** Native admin screens; mutations are explicit, nonce-bound POST requests with per-object outcomes. */
final class Admin {
	private const NONCE = 'aish_operations_action';
	private const NOTICE = 'aish_operations_result_';
	public function __construct( private Dashboard $dashboard, private Workbench $workbench, private Health $health ) {}
	public function register(): void {
		add_action( 'admin_menu', function (): void {
			Ui::menu( Ui::t( '运营概览', 'Operations overview' ), Workbench::CAP, 'aish-operations', array( $this, 'dashboardScreen' ) );
			Ui::menu( Ui::t( '待处理工作台', 'Operations workbench' ), Workbench::CAP, 'aish-workbench', array( $this, 'workbenchScreen' ) );
			if ( current_user_can( Workbench::CAP ) ) { Ui::menu( Ui::t( '来源健康', 'Source health' ), Health::CAP, 'aish-source-health', array( $this, 'healthScreen' ) ); }
		} );
		add_action( 'admin_post_aish_operations_action', array( $this, 'handle' ) );
	}
	private function screen( string $title, callable $body ): void {
		Ui::screen( Workbench::CAP, $title, 'aish_operations_unused_', function () use ( $body ): void {
			$this->notices(); echo '<p class="description">' . esc_html( Ui::t( '当前权限范围内的真实记录 · 时间统一为 UTC。运营入口不代替 Skill 发布审核或 AI 审稿。', 'Actual records within your permissions · All times are UTC. Operations do not replace Skill publication review or AI draft review.' ) ) . '</p>'; $body();
		} );
	}
	private function notices(): void {
		$key = self::NOTICE . get_current_user_id(); $notice = get_transient( $key ); if ( ! is_array( $notice ) ) { return; } delete_transient( $key );
		if ( ! current_user_can( Workbench::CAP ) || ( ! empty( $notice['queue'] ) && ! array_key_exists( $notice['queue'], Workbench::available() ) ) || ( ! empty( $notice['health'] ) && ! current_user_can( Health::CAP ) ) ) { return; }
		$rows = $notice['items'] ?? array(); $success = count( array_filter( $rows, static fn( array $row ): bool => ! empty( $row['success'] ) ) );
		echo '<div class="notice ' . ( $success === count( $rows ) ? 'notice-success' : 'notice-warning' ) . '"><p>' . esc_html( sprintf( Ui::t( '完成 %d 项；未完成 %d 项。', '%d completed; %d not completed.' ), $success, count( $rows ) - $success ) ) . '</p><ul>';
		foreach ( array_slice( $rows, 0, Workbench::LIMIT ) as $row ) { echo '<li>#' . (int) ( $row['id'] ?? 0 ) . ' · ' . esc_html( Workbench::errorLabel( (string) ( $row['code'] ?? 'operation_failed' ) ) ); if ( ! empty( $row['job_id'] ) ) { echo ' · ' . esc_html( Ui::t( '任务已入队', 'Job queued' ) ) . ' #' . (int) $row['job_id']; } echo '</li>'; } echo '</ul></div>';
	}
	public function dashboardScreen(): void {
		$this->screen( Ui::t( '运营概览', 'Operations overview' ), function (): void {
			$snapshot = $this->dashboard->snapshot(); echo '<p>' . esc_html( Ui::t( '统计更新时间：', 'Snapshot updated: ' ) . $snapshot['updated_at'] . ' UTC' ) . '</p>';
			echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>'; foreach ( array( Ui::t( '指标', 'Metric' ), Ui::t( '数量或状态', 'Count or state' ), Ui::t( '单位', 'Unit' ), Ui::t( '统计范围', 'Scope' ) ) as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; } echo '</tr></thead><tbody>';
			foreach ( $snapshot['metrics'] as $metric ) {
				$value = match ( $metric['status'] ) { 'not_configured' => Ui::t( '尚未配置', 'Not configured' ), 'unavailable' => Ui::t( '数据暂不可用', 'Data temporarily unavailable' ), 'paused' => Ui::t( '已暂停', 'Paused' ), default => (string) ( $metric['value'] ?? 0 ) };
				echo '<tr><th scope="row">'; if ( $metric['url'] ) { echo '<a href="' . esc_url( $metric['url'] ) . '">' . esc_html( $metric['label'] ) . '</a>'; } else { echo esc_html( $metric['label'] ); } echo '</th><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( $metric['unit'] ) . '</td><td>' . esc_html( $metric['scope'] ) . '</td></tr>';
			}
			if ( ! $snapshot['metrics'] ) { echo '<tr><td colspan="4">' . esc_html( Ui::t( '此账户尚未获授任何业务统计能力。', 'This account has no business summary permissions.' ) ) . '</td></tr>'; } echo '</tbody></table></div>';
			echo '<h2>' . esc_html( Ui::t( '独立审核入口', 'Separate review workflows' ) ) . '</h2><ul>';
			foreach ( array( array( 'aish_review_skills', 'aish-reviews', Ui::t( 'Skill 内容发布审核', 'Skill publication review' ) ), array( 'aish_generate_content', 'aish-ai-generation', Ui::t( 'AI 草稿审稿', 'AI draft review' ) ), array( Workbench::CAP, 'aish-workbench', Ui::t( '投稿、社区评价、举报和认领', 'Submissions, community reviews, reports and claims' ) ) ) as [ $cap, $slug, $label ] ) { if ( current_user_can( $cap ) ) { echo '<li><a href="' . esc_url( Ui::url( $slug ) ) . '">' . esc_html( $label ) . '</a></li>'; } } echo '</ul>';
		} );
	}
	public function workbenchScreen(): void {
		$this->screen( Ui::t( '待处理工作台', 'Operations workbench' ), function (): void {
			$available = Workbench::available(); if ( ! $available ) { echo '<p>' . esc_html( Ui::t( '未授予审核队列访问权限。', 'No review queue access has been granted.' ) ) . '</p>'; return; }
			$type = Ui::choice( $_GET, 'queue', array_keys( $available ), array_key_first( $available ) );
			echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr( Ui::t( '审核类型', 'Review type' ) ) . '">'; foreach ( $available as $queue => $label ) { echo '<a class="nav-tab' . ( $queue === $type ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( 'aish-workbench', array( 'queue' => $queue ) ) ) . '">' . esc_html( $label ) . '</a>'; } echo '</nav>';
			echo '<p>' . esc_html( Ui::t( '接受投稿不代表发布；认领不授予编辑权限。批准操作在原详情页核对证据后完成。批量处理最多 10 项，不修改用户原文。', 'Accepting a submission does not publish it; claims do not grant editing rights. Approvals require evidence review on the original detail screen. Batches are limited to 10 and cannot edit user text.' ) ) . '</p>';
			$id = Ui::id( $_GET, 'id' );
			if ( $id ) { $this->history( $type, $id ); return; }
			$states = array(); foreach ( Workbench::states( $type ) as $state ) { $states[ $state ] = Workbench::stateLabel( $state ); }
			$filter = array( 'state' => Ui::choice( $_GET, 'state', array_merge( array( 'all' ), array_keys( $states ) ), Workbench::defaultState( $type ) ), 'page' => Ui::page( $_GET ), 'q' => Ui::input( $_GET, 's', '', 100 ), 'direction' => Ui::choice( $_GET, 'order', array( 'asc', 'desc' ), 'asc' ) );
			echo '<form method="get"><p>'; Ui::hidden( 'page', 'aish-workbench' ); Ui::hidden( 'queue', $type ); Ui::select( 'state', array( 'all' => Ui::t( '全部状态', 'All states' ) ) + $states, $filter['state'], Ui::t( '状态', 'State' ) );
			echo '<label>' . esc_html( Ui::t( '搜索', 'Search' ) ) . ' <input type="search" name="s" maxlength="100" value="' . esc_attr( $filter['q'] ) . '"></label> '; Ui::select( 'order', array( 'asc' => Ui::t( '最早创建优先', 'Oldest created first' ), 'desc' => Ui::t( '最新创建优先', 'Newest created first' ) ), $filter['direction'], Ui::t( '排序', 'Sort' ) ); echo '<button type="submit" class="button">' . esc_html( Ui::t( '筛选', 'Filter' ) ) . '</button></p></form>';
			$result = $this->workbench->listing( $type, $filter );
			Ui::form( 'aish_operations_action', self::NONCE, $type, true ); Ui::hidden( 'operation', 'workbench' );
			echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>'; foreach ( array( Ui::t( '选择', 'Select' ), 'ID', Ui::t( '对象', 'Subject' ), Ui::t( '状态 / 修订', 'State / revision' ), Ui::t( '等待时长', 'Waiting time' ), Ui::t( '下一步', 'Next step' ) ) as $caption ) { echo '<th scope="col">' . esc_html( $caption ) . '</th>'; } echo '</tr></thead><tbody>';
			foreach ( $result['items'] as $row ) {
				$open = $row['pending_revision'] || in_array( $row['state'], array( 'submitted', 'pending', 'in_review', 'reviewing', 'needs_changes' ), true ); $at = strtotime( $row['waiting_since'] . ' UTC' ); $age = $open && false !== $at ? sprintf( Ui::t( '%d 分钟', '%d minutes' ), (int) floor( max( 0, time() - $at ) / 60 ) ) : '—';
				echo '<tr><td><label><input type="checkbox" name="ids[]" value="' . (int) $row['id'] . '"><span class="screen-reader-text">' . esc_html( Ui::t( '选择记录', 'Select record' ) ) . ' #' . (int) $row['id'] . '</span></label>';
				foreach ( array( 'revision', 'revision_id', 'lock_version', 'state' ) as $field ) { Ui::hidden( 'records[' . $row['id'] . '][' . $field . ']', $row[ $field ] ); }
				echo '</td><td>#' . (int) $row['id'] . '</td><td><span dir="auto">' . esc_html( $row['title'] ) . '</span><br>' . esc_html( $row['locale'] ) . '</td><td>' . esc_html( Workbench::stateLabel( $row['state'] ) ) . ( $row['pending_revision'] ? '<br>' . esc_html( Ui::t( '待审修订：', 'Pending revision: ' ) ) . '#' . (int) $row['revision_id'] : '' ) . '<br>v' . (int) $row['revision'] . '</td><td>' . esc_html( $age ) . '<br><small>' . esc_html( $row['waiting_since'] . ' UTC' ) . '</small></td><td><a href="' . esc_url( $row['url'] ) . '">' . esc_html( Ui::t( '查看证据并处理', 'Review evidence and act' ) ) . '</a><br><a href="' . esc_url( Ui::url( 'aish-workbench', array( 'queue' => $type, 'id' => $row['id'] ) ) ) . '">' . esc_html( Ui::t( '处理记录', 'Decision history' ) ) . '</a></td></tr>';
			}
			if ( ! $result['items'] ) { echo '<tr><td colspan="6">' . esc_html( Ui::t( '此筛选条件下没有记录。', 'No records match these filters.' ) ) . '</td></tr>'; } echo '</tbody></table></div>';
			if ( $result['items'] ) {
				echo '<h2>' . esc_html( Ui::t( '批量处理所选记录（最多 10 项）', 'Process selected records (maximum 10)' ) ) . '</h2>'; Ui::select( 'decision', Workbench::decisions( $type ), array_key_first( Workbench::decisions( $type ) ), Ui::t( '操作', 'Action' ) );
				$reasons = 'reviews' === $type ? array( 'spam', 'false_claim', 'abuse', 'policy', 'other' ) : Input::RESOLUTIONS;
				$captions = array( 'spam' => Ui::t( '垃圾内容', 'Spam' ), 'false_claim' => Ui::t( '虚假陈述', 'False claim' ), 'abuse' => Ui::t( '滥用', 'Abuse' ), 'policy' => Ui::t( '规则违规', 'Policy violation' ), 'resolved' => Ui::t( '已解决', 'Resolved' ), 'no_action' => Ui::t( '无需进一步处理', 'No further action' ), 'duplicate' => Ui::t( '重复案件', 'Duplicate case' ), 'invalid' => Ui::t( '无效案件', 'Invalid case' ), 'other' => Ui::t( '其他', 'Other' ) );
				Ui::select( 'reason', array_intersect_key( $captions, array_flip( $reasons ) ), 'other', Ui::t( '处理原因', 'Resolution reason' ) );
				if ( in_array( $type, array( 'submissions', 'updates', 'claims' ), true ) ) { Ui::notes( 10 ); }
				else { echo '<p><label>' . esc_html( Ui::t( '内部备注（不会向举报人或评价作者披露）', 'Internal note (not disclosed to reporters or review authors)' ) ) . '<br><textarea name="private_note" class="large-text" rows="3" maxlength="2000"></textarea></label></p>'; Ui::hidden( 'feedback', '' ); }
				Ui::confirm();
			} echo '</form>'; Ui::pagination( 'aish-workbench', $result, $filter, 'paged', array( 'queue' => $type ) );
		} );
	}
	private function history( string $type, int $id ): void {
		$history = $this->workbench->history( $type, $id, Ui::page( $_GET, 'history_page' ) );
		echo '<p><a class="button" href="' . esc_url( Workbench::destination( $type, $id ) ) . '">' . esc_html( Ui::t( '打开原始详情并处理', 'Open the original detail and act' ) ) . '</a></p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
		foreach ( array( 'ID', Ui::t( '操作', 'Action' ), Ui::t( '修订', 'Revision' ), Ui::t( '状态', 'State' ), Ui::t( '处理人', 'Actor' ), 'UTC' ) as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( $history['items'] as $row ) { echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html( $row['action'] ) . '</td><td>' . (int) ( $row['subject_revision'] ?? 0 ) . '</td><td>' . esc_html( (string) ( $row['from_state'] ?? '' ) . ' → ' . (string) ( $row['to_state'] ?? '' ) ) . '</td><td>' . (int) ( $row['actor_user_id'] ?? 0 ) . '</td><td>' . esc_html( $row['created_at'] ) . '</td></tr>'; }
		if ( ! $history['items'] ) { echo '<tr><td colspan="6">' . esc_html( Ui::t( '尚无处理记录。', 'No decisions recorded.' ) ) . '</td></tr>'; } echo '</tbody></table></div>'; Ui::pagination( 'aish-workbench', $history, array(), 'history_page', array( 'queue' => $type, 'id' => $id ) );
	}
	public function healthScreen(): void {
		$this->screen( Ui::t( '来源健康', 'Source health' ), function (): void {
			Health::authorize(); $view = Ui::choice( $_GET, 'view', array( 'repositories', 'jobs', 'sources' ), 'repositories' );
			$tabs = array( 'repositories' => Ui::t( '仓库', 'Repositories' ), 'jobs' => Ui::t( '扫描任务', 'Scan jobs' ), 'sources' => Ui::t( '来源记录', 'Source records' ) ); echo '<nav class="nav-tab-wrapper">'; foreach ( $tabs as $key => $label ) { echo '<a class="nav-tab' . ( $key === $view ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( 'aish-source-health', array( 'view' => $key ) ) ) . '">' . esc_html( $label ) . '</a>'; } echo '</nav>';
			echo '<p>' . esc_html( Ui::t( '失败或未完成扫描不能证明永久失效。处理注记只对应此快照，不发布、不删除或修改 Skill，不改变作者关系。重试仅为已启用仓库入队，不在此页面访问外部 API。', 'Failed or incomplete scans do not prove permanent unavailability. An acknowledgement covers this snapshot only and never publishes, deletes or edits a Skill or author relationship. Retry queues enabled repositories only; this page makes no external API requests.' ) ) . '</p>';
			$rate = $this->health->rate(); echo '<p>' . esc_html( Ui::t( 'GitHub 限流观测（缓存，非连接测试）：', 'GitHub rate-limit observation (cached; not a connection test): ' ) );
			if ( ! empty( $rate['configuration_error'] ) ) { echo esc_html( Ui::t( '配置异常', 'Configuration error' ) ); }
			elseif ( empty( $rate['updated_at'] ) ) { echo esc_html( Ui::t( '暂无观测数据', 'No observation available' ) ); }
			else { echo esc_html( Ui::t( '剩余额度 ', 'Remaining ' ) . ( null === $rate['remaining'] ? Ui::t( '未知', 'unknown' ) : (string) $rate['remaining'] ) . ' · ' . gmdate( 'Y-m-d H:i:s', $rate['updated_at'] ) . ' UTC' ); if ( $rate['cooldown_until'] > time() ) { echo ' · ' . esc_html( Ui::t( '退避截止 ', 'Cooldown until ' ) . gmdate( 'Y-m-d H:i:s', $rate['cooldown_until'] ) . ' UTC' ); } } echo '</p>';
			$states = match ( $view ) { 'repositories' => array( '', 'enabled', 'paused', 'archived' ), 'jobs' => array( '', 'queued', 'running', 'retry', 'complete', 'incomplete', 'failed', 'cancelled' ), default => array( '', 'present', 'missing', 'needs_check', 'handled' ) };
			$state = Ui::choice( $_GET, 'state', $states ); $repository = Ui::id( $_GET, 'repository_id' );
			$labels = array(); foreach ( $states as $value ) { $labels[ $value ] = match ( $value ) { '' => Ui::t( '全部', 'All' ), 'enabled' => Ui::t( '已启用', 'Enabled' ), 'paused' => Ui::t( '已暂停', 'Paused' ), 'archived' => Ui::t( '已归档', 'Archived' ), 'needs_check' => Ui::t( '待采集审稿确认（含运营注记）', 'Awaiting import review (includes operational notes)' ), default => Health::label( $value ) }; }
			echo '<form method="get"><p>'; Ui::hidden( 'page', 'aish-source-health' ); Ui::hidden( 'view', $view ); Ui::select( 'state', $labels, $state, Ui::t( '状态', 'State' ) ); echo '<label>' . esc_html( Ui::t( '仓库 ID', 'Repository ID' ) ) . ' <input type="number" class="small-text" min="0" name="repository_id" value="' . $repository . '"></label> <button class="button" type="submit">' . esc_html( Ui::t( '筛选', 'Filter' ) ) . '</button></p></form>';
			$result = $this->health->listing( $view, Ui::page( $_GET ), $state, $repository );
			if ( 'repositories' === $view ) { Ui::form( 'aish_operations_action', self::NONCE, 'health', true ); Ui::hidden( 'operation', 'retry' ); }
			echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>'; foreach ( array( 'ID', Ui::t( '对象', 'Subject' ), Ui::t( '状态', 'State' ), Ui::t( '证据 / 操作', 'Evidence / action' ) ) as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; } echo '</tr></thead><tbody>';
			foreach ( $result['items'] as $row ) { $this->healthRow( $view, $row ); }
			if ( ! $result['items'] ) { echo '<tr><td colspan="4">' . esc_html( Ui::t( '没有匹配记录；不是已完成连接测试。', 'No matching records; this is not a completed connectivity test.' ) ) . '</td></tr>'; } echo '</tbody></table></div>';
			if ( 'repositories' === $view ) { if ( $result['items'] ) { echo '<p>' . esc_html( Ui::t( '所选仓库进入同步队列（最多 10 项）。暂停或归档的仓库不会恢复。', 'Queue selected repositories for synchronization (maximum 10). Paused or archived repositories remain stopped.' ) ) . '</p>'; Ui::confirm(); } echo '</form>'; }
			Ui::pagination( 'aish-source-health', $result, array( 'state' => $state ), 'paged', array( 'view' => $view, 'repository_id' => $repository ) );
		} );
	}
	private function healthRow( string $view, array $row ): void {
		$id = (int) $row['id']; echo '<tr><td>#' . $id . '</td><td>';
		if ( 'repositories' === $view ) {
			if ( ! empty( $row['sync_enabled'] ) && empty( $row['is_archived'] ) ) { echo '<label><input type="checkbox" name="ids[]" value="' . $id . '"><span class="screen-reader-text">' . esc_html( Ui::t( '同步仓库', 'Sync repository' ) ) . '</span></label> '; }
			echo esc_html( $row['owner'] . '/' . $row['name'] ) . '</td><td>' . esc_html( ! empty( $row['is_archived'] ) ? Ui::t( '已归档', 'Archived' ) : ( ! empty( $row['sync_enabled'] ) ? Ui::t( '已启用', 'Enabled' ) : Ui::t( '已暂停', 'Paused' ) ) ) . '<br>' . esc_html( Health::label( (string) $row['sync_status'] ) ) . '</td><td><a href="' . esc_url( Ui::url( 'aish-imports', array( 'view' => 'repositories', 'id' => $id ) ) ) . '">' . esc_html( Ui::t( '仓库管理', 'Manage repository' ) ) . '</a> · <a href="' . esc_url( Ui::url( 'aish-source-health', array( 'view' => 'jobs', 'repository_id' => $id ) ) ) . '">' . esc_html( Ui::t( '扫描证据', 'Scan evidence' ) ) . '</a><br>' . esc_html( Ui::t( '最后扫描：', 'Last scan: ' ) . ( $row['last_scan_at'] ?: Ui::t( '尚无记录', 'Not recorded' ) ) );
		} elseif ( 'jobs' === $view ) {
			echo esc_html( Ui::t( '仓库', 'Repository' ) ) . ' #' . (int) $row['repository_id'] . '</td><td>' . esc_html( Health::label( $row['status'] ) ) . '<br>' . esc_html( Ui::t( '尝试次数：', 'Attempts: ' ) . (int) $row['attempts'] ) . '</td><td><a href="' . esc_url( Ui::url( 'aish-imports', array( 'view' => 'job', 'id' => $id ) ) ) . '">' . esc_html( Ui::t( '任务及脱敏日志', 'Job and redacted logs' ) ) . '</a> · <a href="' . esc_url( Ui::url( 'aish-source-health', array( 'repository_id' => (int) $row['repository_id'] ) ) ) . '">' . esc_html( Ui::t( '核对仓库并重试', 'Check repository and retry' ) ) . '</a><br>' . esc_html( $row['updated_at'] . ' UTC' );
		} else {
			echo 'Skill #' . (int) $row['skill_id'] . '<br><code>' . esc_html( $row['source_path'] ) . '</code></td><td>' . esc_html( Health::label( $row['source_status'] ) ) . '<br>' . esc_html( $row['acknowledgement'] ? Health::label( 'acknowledged' ) : Health::label( $row['change_status'] ) ) . '</td><td><a href="' . esc_url( Ui::url( 'aish-imports', array( 'view' => 'import', 'id' => $id ) ) ) . '">' . esc_html( Ui::t( '原文、SHA 与静态检查', 'Source, SHA and static findings' ) ) . '</a><br>';
			if ( $row['acknowledgement'] ) { $ack = $row['acknowledgement']; echo esc_html( Health::reasons()[ $ack['reason'] ] . ' · #' . $ack['actor_id'] . ' · ' . $ack['created_at'] . ' UTC' ); }
			else { Ui::form( 'aish_operations_action', self::NONCE, 'health' ); Ui::hidden( 'operation', 'acknowledge' ); Ui::hidden( 'id', $id ); Ui::hidden( 'fingerprint', $row['fingerprint'] ); Ui::select( 'reason', Health::reasons(), 'checked_source', Ui::t( '运营处理结果', 'Operational result' ) ); Ui::confirm(); echo '</form>'; }
		} echo '</td></tr>';
	}
	public function handle(): void {
		Ui::authorize( Workbench::CAP ); Ui::post( self::NONCE ); $return = 'aish-workbench'; $args = array(); $notice = array( 'items' => array() );
		try {
			$key = Ui::request( $_POST ); $operation = Ui::choice( $_POST, 'operation', array( 'workbench', 'retry', 'acknowledge' ) );
			if ( 'workbench' === $operation ) {
				$type = Ui::choice( $_POST, 'kind', array_keys( Workbench::available() ) ); $args['queue'] = $type; $notice['queue'] = $type;
				$ids = Ui::ids( $_POST, true ); $records = $_POST['records'] ?? null; if ( ! is_array( $records ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } $rows = array();
				foreach ( $ids as $id ) { if ( ! isset( $records[ $id ] ) || ! is_array( $records[ $id ] ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } $r = $records[ $id ]; $rows[] = array( 'id' => $id, 'revision' => Ui::id( $r, 'revision' ), 'revision_id' => Ui::id( $r, 'revision_id' ), 'lock_version' => Ui::id( $r, 'lock_version' ), 'state' => Ui::input( $r, 'state', '', 32 ) ); }
				$notice['items'] = $this->workbench->batch( $type, $rows, Ui::choice( $_POST, 'decision', array_keys( Workbench::decisions( $type ) ) ), Ui::input( $_POST, 'feedback', '', 8000 ), Ui::input( $_POST, 'private_note', '', 8000 ), Ui::input( $_POST, 'reason', 'other', 32 ), $key );
			} else {
				Health::authorize(); $return = 'aish-source-health'; $notice['health'] = true;
				if ( 'retry' === $operation ) { $notice['items'] = $this->health->retry( Ui::ids( $_POST, true ) ); }
				else { $id = Ui::id( $_POST, 'id' ); $args['view'] = 'sources'; $this->health->acknowledge( $id, Ui::input( $_POST, 'fingerprint', '', 64 ), Ui::input( $_POST, 'reason', '', 64 ), $key ); $notice['items'] = array( array( 'id' => $id, 'success' => true, 'code' => 'completed' ) ); }
			}
		} catch ( \Throwable $error ) { $notice['items'] = array( array( 'id' => 0, 'success' => false, 'code' => Workbench::errorCode( $error ) ) ); }
		set_transient( self::NOTICE . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS ); wp_safe_redirect( Ui::url( $return, $args ), 303 ); exit;
	}
}
