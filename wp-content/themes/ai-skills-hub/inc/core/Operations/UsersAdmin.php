<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Operations;

defined( 'ABSPATH' ) || exit;

/** Native users columns and a capability-checked community eligibility console. */
final class UsersAdmin {
	private array $nativeRows = array();
	public function __construct( private Users $users ) {}
	private static function t( string $zh, string $en ): string { return str_starts_with( (string) get_user_locale(), 'zh' ) ? $zh : $en; }
	public function register(): void {
		add_action( 'admin_menu', function (): void {
			$title = self::t( '用户运营', 'User Operations' );
			$hook = add_submenu_page( 'aish', $title, $title, Users::CAPABILITY, 'aish-users', array( $this, 'screen' ) );
			if ( is_string( $hook ) && '' !== $hook ) { add_action( 'load-' . $hook, static function (): void { nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); } ); }
		} );
		add_filter( 'manage_users_columns', array( $this, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'column' ), 10, 3 );
		add_action( 'admin_post_aish_operate_user', array( $this, 'handle' ) );
		add_filter( 'aish_account_privacy_erase_batch', array( $this->users, 'erase' ), 30, 2 );
	}
	public function columns( array $columns ): array {
		if ( ! current_user_can( Users::CAPABILITY ) ) { return $columns; }
		return $columns + array( 'aish_verified' => self::t( '邮箱验证', 'Email verification' ), 'aish_state' => self::t( '业务账户状态', 'Business state' ), 'aish_registered' => self::t( '注册时间（UTC）', 'Registered (UTC)' ) );
	}
	public function column( string $output, string $column, int $id ): string {
		if ( ! current_user_can( Users::CAPABILITY ) || ! in_array( $column, array( 'aish_verified', 'aish_state', 'aish_registered' ), true ) ) { return $output; }
		try {
			if ( ! $this->nativeRows ) {
				global $wp_list_table;
				$ids = array(); foreach ( is_object( $wp_list_table ) && is_array( $wp_list_table->items ?? null ) ? $wp_list_table->items : array() as $item ) { $ids[] = (int) $item->ID; }
				foreach ( array_chunk( $ids ?: array( $id ), 100 ) as $batch ) { $this->nativeRows += $this->users->summaries( $batch ); }
			}
			$row = $this->nativeRows[ $id ] ?? null; if ( ! $row ) { return esc_html( self::t( '暂不可用', 'Unavailable' ) ); }
			return match ( $column ) {
				'aish_verified' => esc_html( $row['verified'] ? self::t( '当前邮箱已核验', 'Current email verified' ) : self::t( '未核验', 'Unverified' ) ),
				'aish_registered' => esc_html( $row['registered_at'] ),
				default => '<a href="' . esc_url( self::url( array( 'user_id' => $id ) ) ) . '">' . esc_html( self::states()[ $row['state'] ] ?? $row['state'] ) . '</a>',
			};
		} catch ( \Throwable $error ) { return esc_html( self::t( '暂不可用', 'Unavailable' ) ); }
	}
	private static function states(): array { return array( 'all' => self::t( '全部', 'All' ), 'active' => self::t( '正常', 'Active' ), 'pending_email' => self::t( '待验证邮箱', 'Awaiting verification' ), 'suspended' => self::t( '暂停', 'Suspended' ), 'legacy' => self::t( '历史账户', 'Legacy account' ) ); }
	private static function authorize(): void { if ( ! current_user_can( Users::CAPABILITY ) ) { wp_die( esc_html( self::t( '没有用户运营权限。', 'User operations permission is required.' ) ), '', array( 'response' => 403 ) ); } }
	private static function url( array $args = array() ): string { return add_query_arg( array_merge( array( 'page' => 'aish-users' ), $args ), admin_url( 'admin.php' ) ); }
	private static function input( array $data, string $key, string $default = '' ): string { $value = $data[ $key ] ?? $default; if ( ! is_string( $value ) || strlen( $value ) > 2200 ) { throw new \InvalidArgumentException( 'users_input' ); } return wp_unslash( $value ); }
	private static function number( array $data, string $key, int $default = 0 ): int { $value = self::input( $data, $key, (string) $default ); if ( ! preg_match( '/^(0|[1-9][0-9]{0,17})$/D', $value ) ) { throw new \InvalidArgumentException( 'users_input' ); } return (int) $value; }
	private static function hidden( string $name, string|int $value ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">'; }
	private static function error(): void { echo '<div class="notice notice-error"><p>' . esc_html( self::t( '操作未完成。请检查权限、原因、账户状态并重新打开当前修订。暂停或删除中的账户不能被强制恢复。', 'Operation not completed. Check permissions, reasons and account state, and reload the current revision. Existing suspensions or deletion cannot be overridden.' ) ) . '</p></div>'; }
	public function screen(): void {
		self::authorize(); echo '<div class="wrap"><h1>' . esc_html( self::t( 'AI Skills Hub · 用户运营', 'AI Skills Hub · User Operations' ) ) . '</h1>';
		try {
			$notice = get_transient( 'aish_users_notice_' . get_current_user_id() ); delete_transient( 'aish_users_notice_' . get_current_user_id() );
			if ( 'success' === $notice ) { echo '<div class="notice notice-success"><p>' . esc_html( self::t( '账户状态已处理并记录。原审核结果与邮箱验证证据保留。', 'Account state recorded. Existing moderation decisions and email verification evidence were preserved.' ) ) . '</p></div>'; }
			elseif ( 'failed' === $notice ) { self::error(); }
			$id = self::number( $_GET, 'user_id' ); if ( $id ) { $this->detail( $id ); } else { $this->listing(); }
		} catch ( \Throwable $error ) { self::error(); }
		echo '</div>';
	}
	private function listing(): void {
		$filters = array( 'page' => self::number( $_GET, 'paged', 1 ), 'state' => self::input( $_GET, 'state', 'all' ), 'q' => self::input( $_GET, 'q' ) ); $data = $this->users->listing( $filters );
		echo '<p>' . esc_html( self::t( '实时查询。汇总为本账户的当前收藏关系、全部评价主体、已受控归属的投稿数量；不是公开贡献或活跃用户指标。注册时间使用 UTC。', 'Live query. Counts show this account’s active favorite relationships, all review subjects and controlled owned submissions; these are not public contribution or active-user metrics. Registration timestamps use UTC.' ) ) . '</p><form method="get">'; self::hidden( 'page', 'aish-users' );
		echo '<label for="aish-user-state">' . esc_html( self::t( '账户状态', 'Account state' ) ) . ' </label><select id="aish-user-state" name="state">';
		foreach ( self::states() as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['state'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select> <label for="aish-user-q">' . esc_html( self::t( '昵称或用户编号', 'Display name or user ID' ) ) . ' </label><input id="aish-user-q" name="q" maxlength="100" value="' . esc_attr( $filters['q'] ) . '"> <button class="button">' . esc_html( self::t( '筛选', 'Filter' ) ) . '</button></form><table class="widefat striped"><thead><tr>';
		foreach ( array( self::t( '账户', 'Account' ), self::t( '验证 / 状态', 'Verification / state' ), self::t( '注册时间 UTC', 'Registered UTC' ), self::t( '收藏 / 评价 / 投稿', 'Favorites / reviews / submissions' ) ) as $title ) { echo '<th>' . esc_html( $title ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $data['items'] as $row ) { echo '<tr><td><a href="' . esc_url( self::url( array( 'user_id' => $row['id'] ) ) ) . '">#' . (int) $row['id'] . ' · ' . esc_html( $row['display_name'] ) . '</a></td><td>' . esc_html( ( $row['verified'] ? self::t( '已核验', 'Verified' ) : self::t( '未核验', 'Unverified' ) ) . ' / ' . self::states()[ $row['state'] ] ) . '</td><td>' . esc_html( $row['registered_at'] ) . '</td><td>' . (int) $row['favorites'] . ' / ' . (int) $row['reviews'] . ' / ' . (int) $row['submissions'] . '</td></tr>'; }
		if ( ! $data['items'] ) { echo '<tr><td colspan="4">' . esc_html( self::t( '没有符合条件的账户。', 'No matching accounts.' ) ) . '</td></tr>'; }
		echo '</tbody></table><p>' . esc_html( sprintf( self::t( '共 %d 个账户；第 %d 页。', '%d accounts; page %d.' ), $data['total'], $data['page'] ) ) . '</p>';
		foreach ( array( $data['page'] - 1 => self::t( '上一页', 'Previous' ), $data['page'] + 1 => self::t( '下一页', 'Next' ) ) as $page => $label ) { if ( $page > 0 && ( $page - 1 ) * 20 < $data['total'] ) { echo '<a class="button" href="' . esc_url( self::url( array( 'paged' => $page, 'state' => $filters['state'], 'q' => $filters['q'] ) ) ) . '">' . esc_html( $label ) . '</a> '; } }
	}
	private function detail( int $id ): void {
		$row = $this->users->detail( $id, self::number( $_GET, 'history_page', 1 ) );
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html( self::t( '返回用户列表', 'Back to users' ) ) . '</a></p><h2>#' . $id . ' · ' . esc_html( $row['display_name'] ) . '</h2><p>' . esc_html( self::states()[ $row['state'] ] . ' · ' . ( $row['verified'] ? self::t( '当前邮箱已核验', 'Current email verified' ) : self::t( '当前邮箱未核验', 'Current email unverified' ) ) ) . '</p>';
		echo '<p>' . esc_html( sprintf( self::t( '当前收藏关系 %d；全部评价主体 %d；受控投稿 %d。私有内容和邮箱不会在此公开。', 'Active favorite relationships: %d; all review subjects: %d; owned submissions: %d. This screen does not disclose private content or email addresses.' ), $row['favorites'], $row['reviews'], $row['submissions'] ) ) . '</p>';
		if ( current_user_can( 'aish_manage_submissions' ) ) { echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => 'aish-submissions', 'user_id' => $id, 'state' => 'all' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( self::t( '进入投稿处理', 'Open submission management' ) ) . '</a></p>'; }
		if ( current_user_can( 'aish_moderate_community' ) ) { echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => 'aish-community-reviews', 'user_id' => $id, 'status' => 'all' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( self::t( '查看本人评价及处理记录', 'View this user’s reviews and handling records' ) ) . '</a></p>'; }
		echo '<p>' . esc_html( self::t( '暂停复用原账户 suspended 状态：停止登录及社区操作，并从公开评价、收藏统计和开发者展示中排除。恢复仅还原原账户状态，不批准任何隐藏、拒绝或待审内容，也不新增邮箱验证。', 'Suspension reuses the existing account state: login and community actions stop, and public reviews, favorite totals and developer projections exclude the account. Restoration only restores its prior state; it never approves hidden, rejected or pending content or grants email verification.' ) ) . '</p>';
		$allowed = ! $row['protected'] && ( in_array( $row['state'], array( 'active', 'pending_email', 'legacy' ), true ) || ( 'suspended' === $row['state'] && $row['operations_paused'] ) );
		if ( $allowed ) {
			$action = 'suspended' === $row['state'] ? 'resume' : 'suspend'; echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			foreach ( array( 'action' => 'aish_operate_user', 'user_id' => $id, 'operation' => $action, 'revision' => $row['revision'], 'request_key' => bin2hex( random_bytes( 16 ) ) ) as $name => $value ) { self::hidden( $name, $value ); } wp_nonce_field( 'aish_operate_user_' . $id );
			echo '<p><label for="aish-user-reason">' . esc_html( self::t( '内部操作原因（必填；勿填写密码或 Token）', 'Internal reason (required; no passwords or tokens)' ) ) . '</label><br><textarea class="large-text" id="aish-user-reason" name="reason" rows="2" maxlength="500" required></textarea></p><p><label for="aish-user-recovery">' . esc_html( self::t( '恢复条件 / 已核实的处理依据', 'Recovery conditions / verified resolution' ) ) . '</label><br><textarea class="large-text" id="aish-user-recovery" name="recovery_condition" rows="2" maxlength="500"' . ( 'suspend' === $action ? ' required' : '' ) . '></textarea></p><p><label><input type="checkbox" name="confirmed" value="1" required> ' . esc_html( self::t( '已核对本账户及处理原因。', 'I checked this account and the reason for this action.' ) ) . '</label></p><button class="button button-primary">' . esc_html( 'suspend' === $action ? self::t( '暂停账户', 'Suspend account' ) : self::t( '恢复原账户状态', 'Restore prior account state' ) ) . '</button></form>';
		} else { echo '<p>' . esc_html( self::t( '此账户受保护、正在删除或不是由运营流程暂停，不能在此修改。', 'This account is protected, being erased, or was suspended outside this workflow and cannot be changed here.' ) ) . '</p>'; }
		echo '<h2>' . esc_html( self::t( '账户处理记录（UTC）', 'Account handling history (UTC)' ) ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html( self::t( '时间 / 修订 / 操作者 ID', 'Time / revision / operator ID' ) ) . '</th><th>' . esc_html( self::t( '状态变化', 'State change' ) ) . '</th><th>' . esc_html( self::t( '内部原因 / 恢复条件', 'Internal reason / recovery condition' ) ) . '</th></tr></thead><tbody>';
		foreach ( $row['history'] as $event ) { $meta = json_decode( $event['metadata_json'], true ); echo '<tr><td>' . esc_html( $event['created_at'] ) . ' / ' . (int) $event['subject_revision'] . ' / ' . (int) $event['actor_user_id'] . '</td><td>' . esc_html( ( self::states()[ $event['from_state'] ] ?? $event['from_state'] ) . ' → ' . ( self::states()[ $event['to_state'] ] ?? $event['to_state'] ) ) . '</td><td>' . esc_html( ( is_string( $meta['reason'] ?? null ) ? $meta['reason'] : '' ) . ' / ' . ( is_string( $meta['recovery_condition'] ?? null ) ? $meta['recovery_condition'] : '' ) ) . '</td></tr>'; }
		if ( ! $row['history'] ) { echo '<tr><td colspan="3">' . esc_html( self::t( '暂无记录。', 'No records.' ) ) . '</td></tr>'; } echo '</tbody></table><p>';
		if ( $row['history_page'] > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'user_id' => $id, 'history_page' => $row['history_page'] - 1 ) ) ) . '">' . esc_html( self::t( '较新记录', 'Newer records' ) ) . '</a> '; }
		if ( count( $row['history'] ) === 20 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'user_id' => $id, 'history_page' => $row['history_page'] + 1 ) ) ) . '">' . esc_html( self::t( '较早记录', 'Older records' ) ) . '</a>'; } echo '</p>';
		foreach ( array( 'export_others_personal_data' => array( 'export_personal_data', self::t( 'WordPress 个人数据导出请求', 'WordPress personal data export requests' ) ), 'erase_others_personal_data' => array( 'remove_personal_data', self::t( 'WordPress 个人数据删除请求', 'WordPress personal data erasure requests' ) ) ) as $cap => [ $page, $label ] ) { if ( current_user_can( $cap ) ) { echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => $page ), admin_url( 'tools.php' ) ) ) . '">' . esc_html( $label ) . '</a></p>'; } }
	}
	public function handle(): void {
		self::authorize(); $id = 0; $notice = 'failed';
		try {
			$id = self::number( $_POST, 'user_id' );
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || $id < 1 || ! wp_verify_nonce( self::input( $_POST, '_wpnonce' ), 'aish_operate_user_' . $id ) || '1' !== self::input( $_POST, 'confirmed' ) ) { throw new \RuntimeException( 'users_forbidden' ); }
			$this->users->setState( $id, self::input( $_POST, 'operation' ), self::number( $_POST, 'revision' ), self::input( $_POST, 'reason' ), self::input( $_POST, 'recovery_condition' ), self::input( $_POST, 'request_key' ) ); $notice = 'success';
		} catch ( \Throwable $error ) { /* Fixed notices contain no database diagnostics, notes or secrets. */ }
		set_transient( 'aish_users_notice_' . get_current_user_id(), $notice, 60 ); wp_safe_redirect( self::url( $id ? array( 'user_id' => $id ) : array() ), 303 ); exit;
	}
}
