<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;

defined( 'ABSPATH' ) || exit;

/** Native admin operations over the existing outbox; marketing authority is separate. */
final class SubscriptionAdmin {
	public function __construct( private Subscriptions $service ) {}
	private static function t( string $zh, string $en ): string { return str_starts_with( get_user_locale(), 'zh' ) ? $zh : $en; }
	public function register(): void {
		add_action( 'admin_menu', function (): void { $hook = add_submenu_page( 'aish', self::t( '邮件订阅与精选', 'Subscriptions and digests' ), self::t( '邮件订阅与精选', 'Subscriptions and digests' ), Subscriptions::CAPABILITY, 'aish-subscriptions', array( $this, 'screen' ) ); if ( is_string( $hook ) ) { add_action( 'load-' . $hook, array( SubscriptionFrontend::class, 'privateHeaders' ) ); } } );
		add_action( 'admin_post_aish_subscriptions_admin', array( $this, 'receive' ) );
	}
	private static function integer( mixed $value, int $min = 0 ): int {
		if ( ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,18})$/D', $value ) ) ) || false === filter_var( $value, FILTER_VALIDATE_INT ) || (int) $value < $min ) { throw new \InvalidArgumentException( 'invalid_input' ); } return (int) $value;
	}
	public function receive(): never {
		SubscriptionFrontend::privateHeaders();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POST required.', '', array( 'response' => 405 ) ); }
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && ( ! ctype_digit( (string) $_SERVER['CONTENT_LENGTH'] ) || (int) $_SERVER['CONTENT_LENGTH'] > 16384 ) ) { wp_die( 'Invalid request.', '', array( 'response' => 413 ) ); }
		if ( ! current_user_can( Subscriptions::CAPABILITY ) || ! is_string( $_POST['_wpnonce'] ?? null ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'aish_subscriptions_admin' ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		$data = wp_unslash( $_POST ); $status = 'error';
		try {
			if ( array_diff( array_keys( $data ), array( 'action', 'operation', '_wpnonce', 'revision', 'enabled', 'paused', 'confirm', 'id', 'state', 'reason', 'locale', 'kind', 'request_key', 'preview_fingerprint', 'submit' ) ) ) { throw new \InvalidArgumentException(); }
			foreach ( array( 'enabled', 'paused', 'confirm' ) as $key ) { if ( isset( $data[ $key ] ) && '1' !== $data[ $key ] ) { throw new \InvalidArgumentException(); } }
			$operation = $data['operation'] ?? '';
			if ( 'settings' === $operation ) {
				if ( '1' !== ( $data['confirm'] ?? '' ) ) { throw new \InvalidArgumentException(); }
				$this->service->saveConfig( array( 'enabled' => '1' === ( $data['enabled'] ?? '' ), 'paused' => '1' === ( $data['paused'] ?? '' ) ), self::integer( $data['revision'] ?? null ) );
			} elseif ( 'state' === $operation ) {
				if ( '1' !== ( $data['confirm'] ?? '' ) || ! in_array( $data['state'] ?? null, array( 'paused', 'unsubscribed', 'bounced' ), true ) || ! is_string( $data['reason'] ?? null ) || strlen( $data['reason'] ) > 600 || '' === trim( $data['reason'] ) ) { throw new \InvalidArgumentException(); }
				$id = self::integer( $data['id'] ?? null, 1 ); $revision = self::integer( $data['revision'] ?? null );
				if ( 'bounced' === $data['state'] ) { $this->service->markBounce( $id, $revision, $data['reason'] ); } else { $this->service->setState( $id, $revision, $data['state'], $data['reason'] ); }
			} elseif ( 'queue' === $operation ) {
				if ( '1' !== ( $data['confirm'] ?? '' ) || ! in_array( $data['locale'] ?? null, array( 'zh_CN', 'en_US' ), true ) || ! in_array( $data['kind'] ?? null, array( 'weekly', 'new_resources' ), true ) || ! is_string( $data['request_key'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['request_key'] ) || ! is_string( $data['preview_fingerprint'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['preview_fingerprint'] ) ) { throw new \InvalidArgumentException(); }
				$this->service->queueIssue( $data['locale'], $data['kind'], $data['request_key'], $data['preview_fingerprint'] ); $status = 'queued';
			} elseif ( 'retry' === $operation ) {
				if ( '1' !== ( $data['confirm'] ?? '' ) ) { throw new \InvalidArgumentException(); } $this->service->retryIssue( self::integer( $data['id'] ?? null, 1 ) );
			} else { throw new \InvalidArgumentException(); }
			if ( 'error' === $status ) { $status = 'saved'; }
		} catch ( \Throwable $error ) { $status = in_array( $error->getMessage(), array( 'conflict', 'revision_conflict', 'request_conflict' ), true ) ? 'conflict' : 'error'; }
		wp_safe_redirect( add_query_arg( array( 'page' => 'aish-subscriptions', 'result' => $status ), admin_url( 'admin.php' ) ), 303 ); exit;
	}
	private function form( string $operation ): void { echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="aish_subscriptions_admin"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'; wp_nonce_field( 'aish_subscriptions_admin', '_wpnonce', false ); }
	private function confirmation( string $label ): void { echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html( $label ) . '</label></p>'; }
	private function labels(): array { return array( 'pending' => self::t( '等待确认', 'Awaiting confirmation' ), 'active' => self::t( '已确认订阅', 'Confirmed subscription' ), 'paused' => self::t( '已暂停', 'Paused' ), 'unsubscribed' => self::t( '已退订', 'Unsubscribed' ), 'bounced' => self::t( '退信抑制', 'Bounce suppression' ) ); }
	public function screen(): void {
		if ( ! current_user_can( Subscriptions::CAPABILITY ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>' . esc_html( self::t( '邮件订阅与精选', 'Subscriptions and digests' ) ) . '</h1><p>' . esc_html( self::t( '仅向主动双重确认且当前有效的订阅发送。注册、Contact、企业咨询和普通通知偏好不会加入名单。', 'Only explicit, double-confirmed and currently eligible subscriptions receive digests. Registration, Contact, enterprise inquiries and ordinary notification preferences never opt people in.' ) ) . '</p>';
		$result = is_string( $_GET['result'] ?? null ) ? $_GET['result'] : '';
		if ( in_array( $result, array( 'saved', 'queued', 'error', 'conflict' ), true ) ) { echo '<div class="notice ' . ( in_array( $result, array( 'saved', 'queued' ), true ) ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( match ( $result ) { 'saved' => self::t( '操作已保存。', 'The operation was saved.' ), 'queued' => self::t( '分批任务已记录；不代表邮件已发送或送达。', 'A bounded dispatch task was recorded. This does not mean email was sent or delivered.' ), 'conflict' => self::t( '修订冲突，请刷新检查后再操作。', 'Revision conflict. Refresh and review before retrying.' ), default => self::t( '未能确认操作成功。请检查配置、当前状态和输入。', 'The operation could not be confirmed. Check configuration, current state and input.' ) } ) . '</p></div>'; }
		try {
			$this->configuration();
			$id = isset( $_GET['subscriber'] ) ? self::integer( $_GET['subscriber'], 1 ) : 0;
			if ( $id ) { $this->subscriber( $id ); } else { $this->subscribers(); }
			$this->campaigns(); $this->failures();
		} catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html( self::t( '暂时无法读取完整订阅数据，请稍后重试。', 'Subscription data is temporarily unavailable. Try again later.' ) ) . '</p></div>'; }
		echo '</div>';
	}
	private function configuration(): void {
		$s = $this->service->snapshot(); $ready = $this->service->publicStatus();
		echo '<h2>' . esc_html( self::t( '配置与暂停', 'Configuration and pause' ) ) . '</h2><div class="notice notice-warning inline"><p>' . esc_html( self::t( '配置可用只表示能够入队或调用传输，不证明真实送达。请先配置可信邮件服务并单独完成收件验证；没有验证时保持暂停。', 'Available configuration only establishes queue/transport readiness, not delivery. Configure a trusted mail service and verify receipt separately; keep dispatch paused until that validation.' ) ) . '</p></div><p>' . esc_html( ! empty( $ready['mail_ready'] ) ? self::t( '邮件配置：可用于排队；真实送达未知。', 'Mail configuration: available for queueing; real delivery unknown.' ) : self::t( '邮件配置尚未就绪。请核对账户邮件加密和传输配置。', 'Mail configuration is not ready. Check account-mail encryption and transport settings.' ) ) . '</p>';
		$this->form( 'settings' ); echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $s['revision'] ) . '">';
		foreach ( array( 'enabled' => self::t( '允许用户主动申请订阅（默认关闭）', 'Allow explicit subscription requests (off by default)' ), 'paused' => self::t( '暂停营销邮件派发', 'Pause marketing dispatch' ) ) as $name => $label ) { echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $s[ $name ] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
		$this->confirmation( self::t( '我已核对配置及发送范围；不会导入未经同意的邮箱。', 'I reviewed configuration and recipients; no addresses without consent will be imported.' ) ); submit_button( self::t( '保存配置', 'Save configuration' ) ); echo '</form>';
	}
	private function subscribers(): void {
		$page = self::integer( $_GET['sub_page'] ?? 1, 1 ); if ( $page > 10000 ) { throw new \InvalidArgumentException(); } $state = $_GET['state'] ?? ''; if ( ! is_string( $state ) || ( '' !== $state && ! isset( $this->labels()[ $state ] ) ) ) { throw new \InvalidArgumentException(); }
		$result = $this->service->listing( $page, $state ); $items = $result['items'];
		echo '<h2>' . esc_html( self::t( '订阅状态', 'Subscription states' ) ) . '</h2><form method="get"><input type="hidden" name="page" value="aish-subscriptions"><label>' . esc_html( self::t( '状态', 'State' ) ) . ' <select name="state"><option value="">' . esc_html( self::t( '全部', 'All' ) ) . '</option>'; foreach ( $this->labels() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $state, $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label> <button class="button">' . esc_html( self::t( '筛选', 'Filter' ) ) . '</button></form><div style="overflow-x:auto"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html( self::t( '私有订阅记录', 'Private subscription records' ) ) . '</caption><thead><tr><th scope="col">ID</th><th scope="col">' . esc_html( self::t( '状态', 'State' ) ) . '</th><th scope="col">' . esc_html( self::t( '语言', 'Language' ) ) . '</th><th scope="col">UTC</th></tr></thead><tbody>';
		foreach ( $items as $row ) { echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page' => 'aish-subscriptions', 'subscriber' => $row['id'] ), admin_url( 'admin.php' ) ) ) . '">#' . esc_html( (string) $row['id'] ) . '</a></td><td>' . esc_html( $this->labels()[ $row['state'] ] ?? self::t( '待核实', 'Unverified state' ) ) . '</td><td>' . esc_html( $row['locale'] ) . '</td><td>' . esc_html( $row['updated_at'] ?? '' ) . '</td></tr>'; }
		if ( ! $items ) { echo '<tr><td colspan="4">' . esc_html( self::t( '暂无符合条件的订阅。', 'No matching subscriptions.' ) ) . '</td></tr>'; } echo '</tbody></table></div>';
		$this->pagination( 'sub_page', $page, (int) $result['total'], 20, array( 'state' => $state ) );
	}
	private function subscriber( int $id ): void {
		$r = $this->service->detail( $id ); echo '<h2>' . esc_html( self::t( '订阅账户', 'Subscription account' ) . ' #' . $id ) . '</h2><p>' . esc_html( $this->labels()[ $r['state'] ] ?? self::t( '待核实', 'Unverified state' ) ) . ' · ' . esc_html( $r['locale'] ) . '</p><p>' . esc_html( self::t( '管理员只能暂停、取消或依据真实退信证据抑制，不能代替用户确认订阅。', 'Administrators may pause, unsubscribe or suppress using real bounce evidence. They cannot confirm consent for a user.' ) ) . '</p>';
		$this->form( 'state' ); echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="revision" value="' . esc_attr( (string) $r['revision'] ) . '"><p><label>' . esc_html( self::t( '处理方式', 'Action' ) ) . ' <select name="state">'; foreach ( array( 'paused', 'unsubscribed', 'bounced' ) as $state ) { echo '<option value="' . esc_attr( $state ) . '">' . esc_html( $this->labels()[ $state ] ) . '</option>'; } echo '</select></label></p><p><label>' . esc_html( self::t( '已核实的外部证据引用（仅保留指纹，不保存原文；不要填写凭据）', 'Verified external evidence reference (only its fingerprint is retained, not the text; never enter credentials)'  ) ) . '<br><textarea class="large-text" name="reason" rows="3" maxlength="180" required></textarea></label></p>'; $this->confirmation( self::t( '确认按当前修订处理这项订阅。', 'Apply this action to the current subscription revision.' ) ); submit_button( self::t( '保存处理', 'Save action' ) ); echo '</form>';
	}
	private function campaigns(): void {
		$locale = $_GET['preview_locale'] ?? 'zh_CN'; $kind = $_GET['preview_kind'] ?? 'weekly';
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || ! in_array( $kind, array( 'weekly', 'new_resources' ), true ) ) { throw new \InvalidArgumentException(); }
		echo '<h2>' . esc_html( self::t( '精选预览与分批任务', 'Digest preview and bounded tasks' ) ) . '</h2><form method="get"><input type="hidden" name="page" value="aish-subscriptions"><label>' . esc_html( self::t( '语言', 'Language' ) ) . ' <select name="preview_locale"><option value="zh_CN" ' . selected( $locale, 'zh_CN', false ) . '>简体中文</option><option value="en_US" ' . selected( $locale, 'en_US', false ) . '>English</option></select></label> <label>' . esc_html( self::t( '内容', 'Content' ) ) . ' <select name="preview_kind"><option value="weekly" ' . selected( $kind, 'weekly', false ) . '>' . esc_html( self::t( '每周精选', 'Weekly picks' ) ) . '</option><option value="new_resources" ' . selected( $kind, 'new_resources', false ) . '>' . esc_html( self::t( '新资源汇总', 'New resource digest' ) ) . '</option></select></label> <button class="button">' . esc_html( self::t( '读取当前公开内容预览', 'Preview current public content' ) ) . '</button></form>';
		$preview = $this->service->preview( $locale, $kind );
		echo '<p><strong>' . esc_html( $preview['subject'] ?? '' ) . '</strong></p><div style="white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #c3c4c7;background:#fff;padding:16px;max-height:28rem;overflow:auto">' . esc_html( $preview['body'] ?? '' ) . '</div><p class="description">' . esc_html( self::t( '预览不含真实退订凭证，也不会发送。派发前重新检查公开条件和订阅意愿；内容不足时不制造空邮件。', 'Preview contains no real unsubscribe credential and sends nothing. Dispatch rechecks public content and consent; insufficient content does not produce invented emails.' ) ) . '</p>';
		$this->form( 'queue' ); echo '<input type="hidden" name="preview_fingerprint" value="' . esc_attr( $preview['fingerprint'] ?? '' ) . '"><input type="hidden" name="locale" value="' . esc_attr( $locale ) . '"><input type="hidden" name="kind" value="' . esc_attr( $kind ) . '"><input type="hidden" name="request_key" value="' . esc_attr( bin2hex( random_bytes( 32 ) ) ) . '">'; $this->confirmation( self::t( '确认将此语言和类型的精选交给有界后台任务，只派发给有效订阅。', 'Queue a bounded task for this language and digest type, only for eligible subscriptions.' ) ); submit_button( self::t( '安排分批任务', 'Queue bounded task' ), 'secondary' ); echo '</form>';
		$page = self::integer( $_GET['issue_page'] ?? 1, 1 ); if ( $page > 10000 ) { throw new \InvalidArgumentException(); } $result = $this->service->issues( $page );
		echo '<h3>' . esc_html( self::t( '任务记录', 'Task history' ) ) . '</h3><p>' . esc_html( self::t( '完成分批只表示收件人已检查并按规则入队，不代表邮件送达。结果不确定的邮件禁止盲目重发。', 'A completed batch means recipients were checked and eligible messages queued, not delivered. Uncertain transport outcomes must not be blindly resent.' ) ) . '</p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th scope="col">ID</th><th scope="col">' . esc_html( self::t( '状态', 'State' ) ) . '</th><th scope="col">' . esc_html( self::t( '语言 / 类型', 'Language / type' ) ) . '</th><th scope="col">' . esc_html( self::t( '恢复', 'Recovery' ) ) . '</th></tr></thead><tbody>';
		foreach ( $result['items'] as $row ) { echo '<tr><td>#' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( (string) ( $row['state'] ?? $row['status'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['locale'] ?? '' ) . ' / ' . ( 'new_resources' === ( $row['kind'] ?? '' ) ? self::t( '新资源', 'New resources' ) : self::t( '每周精选', 'Weekly picks' ) ) ) . '</td><td>'; if ( 'failed' === ( $row['state'] ?? '' ) ) { $this->form( 'retry' ); echo '<input type="hidden" name="id" value="' . esc_attr( (string) $row['id'] ) . '">'; $this->confirmation( self::t( '仅恢复可重试的未完成批次', 'Resume only retryable unfinished batches' ) ); echo '<button class="button" type="submit">' . esc_html( self::t( '恢复任务', 'Resume task' ) ) . '</button></form>'; } else { echo '—'; } echo '</td></tr>'; }
		if ( ! $result['items'] ) { echo '<tr><td colspan="4">' . esc_html( self::t( '暂无任务。', 'No tasks.' ) ) . '</td></tr>'; } echo '</tbody></table></div>'; $this->pagination( 'issue_page', $page, (int) $result['total'], 20 );
	}
	private function failures(): void {
		$page = self::integer( $_GET['failure_page'] ?? 1, 1 ); if ( $page > 10000 ) { throw new \InvalidArgumentException(); } $result = $this->service->mailFailures( $page );
		echo '<h2>' . esc_html( self::t( '邮件失败与结果不确定', 'Mail failures and uncertain outcomes' ) ) . '</h2><p>' . esc_html( self::t( '复用原邮件队列，只显示受控状态和错误代码；传输受理不等于送达。', 'These are existing-outbox records with controlled statuses and error codes only. Transport acceptance is not delivery.' ) ) . '</p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th scope="col">ID</th><th scope="col">' . esc_html( self::t( '状态', 'State' ) ) . '</th><th scope="col">' . esc_html( self::t( '原因', 'Reason' ) ) . '</th></tr></thead><tbody>';
		foreach ( $result['items'] as $row ) { $code = (string) ( $row['last_error'] ?? $row['error_code'] ?? $row['error'] ?? '' ); $reason = match ( $code ) { 'mail_transport_rejected', 'transport_rejected' => self::t( '传输明确拒绝', 'Transport explicitly rejected' ), 'mail_outcome_unknown', 'transport_uncertain', 'send_uncertain' => self::t( '传输结果不确定', 'Transport result uncertain' ), 'mail_context_changed', 'account_unavailable', 'context_invalid', 'invalid_context' => self::t( '收件资格或上下文已失效', 'Recipient eligibility or context expired' ), 'mail_expired', 'expired' => self::t( '已过期', 'Expired' ), default => self::t( '已记录失败，请核对当前任务状态', 'Recorded failure; review the current task state' ) }; echo '<tr><td>#' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td><td>' . esc_html( $reason ) . '</td></tr>'; }
		if ( ! $result['items'] ) { echo '<tr><td colspan="3">' . esc_html( self::t( '暂无失败记录。', 'No failure records.' ) ) . '</td></tr>'; } echo '</tbody></table></div>'; $this->pagination( 'failure_page', $page, (int) $result['total'], 20 );
	}
	private function pagination( string $key, int $page, int $total, int $perPage, array $extra = array() ): void { echo '<p>'; if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( array( 'page' => 'aish-subscriptions', $key => $page - 1 ), $extra ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( self::t( '上一页', 'Previous' ) ) . '</a> '; } if ( $page * $perPage < $total ) { echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( array( 'page' => 'aish-subscriptions', $key => $page + 1 ), $extra ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( self::t( '下一页', 'Next' ) ) . '</a>'; } echo '</p>'; }
}
