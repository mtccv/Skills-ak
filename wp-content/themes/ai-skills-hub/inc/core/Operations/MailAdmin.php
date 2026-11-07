<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Accounts\Crypto;
use AISkillsHub\Core\Accounts\Mail;
use AISkillsHub\Core\Accounts\Store;
use AISkillsHub\Core\Accounts\Scheduler;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** Administration of the existing encrypted outbox, never a transport or business-action endpoint. */
final class MailAdmin {
	public const CAPABILITY = 'aish_manage_notifications';
	public const PAGE = 'aish-mail-operations';
	public const STATES = array( 'queued', 'retry', 'claimed', 'sending', 'accepted', 'failed', 'uncertain', 'cancelled', 'expired' );
	public function __construct( private Store $store, private Mail $mail, private Migrator $migrator ) {}
	public function register(): void {
		add_action( 'admin_menu', function (): void { $hook = add_submenu_page( 'aish', $this->t( '通知与邮件', 'Notifications and mail' ), $this->t( '通知与邮件', 'Notifications and mail' ), self::CAPABILITY, self::PAGE, array( $this, 'page' ) ); if ( $hook ) { add_action( 'load-' . $hook, static function (): void { nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); } ); } } );
		add_action( 'admin_post_aish_mail_operations', array( $this, 'handle' ) );
	}
	private function t( string $zh, string $en ): string { return str_starts_with( get_user_locale(), 'zh' ) ? $zh : $en; }
	private function guard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) { throw new \RuntimeException( 'mail_forbidden' ); }
		if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'mail_storage_unavailable' ); }
		$db = $this->store->database(); $id = get_current_user_id();
		if ( $id < 1 || ! $db->value( 'SELECT ID FROM ' . $db->connection()->users . ' WHERE ID=%d', array( $id ) ) ) { throw new \RuntimeException( 'mail_forbidden' ); }
		$meta = $db->rows( 'SELECT meta_key,meta_value FROM ' . $db->connection()->usermeta . " WHERE user_id=%d AND meta_key IN ('_aish_account_state','_aish_account_deletion_pending')", array( $id ) );
		$values = array();
		foreach ( $meta as $row ) {
			if ( array_key_exists( $row['meta_key'], $values ) ) { throw new \RuntimeException( 'mail_forbidden' ); }
			$values[ $row['meta_key'] ] = $row['meta_value'];
		}
		$state = $values['_aish_account_state'] ?? '';
		// Match the native manager recovery exception after an email change,
		// without allowing suspension, deletion or malformed account state.
		$managerPending = 'pending_email' === $state && current_user_can( 'manage_options' );
		if ( ( ! in_array( $state, array( '', 'active' ), true ) && ! $managerPending ) || ! in_array( $values['_aish_account_deletion_pending'] ?? '', array( '', '0' ), true ) ) { throw new \RuntimeException( 'mail_forbidden' ); }
	}
	public function configuration(): array {
		$this->guard();
		return array( 'enabled' => defined( 'AISH_ACCOUNT_MAIL_ENABLED' ) && true === AISH_ACCOUNT_MAIL_ENABLED, 'encryption_ready' => Crypto::ready(), 'paused' => '1' === get_option( 'aish_account_mail_paused', '0' ), 'cron_scheduled' => false !== wp_next_scheduled( Scheduler::HOOK ), 'external_cron_required' => defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON, 'transport_verified' => false );
	}
	public function listing( string $state = 'all', int $page = 1 ): array {
		$this->guard();
		if ( ! in_array( $state, array_merge( array( 'all' ), self::STATES ), true ) || $page < 1 || $page > 10000 ) { throw new \RuntimeException( 'mail_invalid_input' ); }
		$db = $this->store->database(); $where = "channel='email'"; $args = array();
		if ( 'all' !== $state ) { $where .= ' AND status=%s'; $args[] = $state; }
		// No email addresses, native logins, event hashes, leases, keys or ciphertext leave this projection.
		$rows = $db->rows( 'SELECT id,template_key,locale,status,attempts,available_at,expires_at,created_at,updated_at,accepted_at,last_error,(lease_token=\'\') AS lease_available,(payload_ciphertext IS NOT NULL AND payload_ciphertext<>\'\') AS recoverable_payload FROM ' . $db->table( 'notification_outbox' ) . " WHERE $where ORDER BY id DESC LIMIT 26 OFFSET %d", array_merge( $args, array( ( $page - 1 ) * 25 ) ) );
		foreach ( $rows as &$row ) { $row['last_error'] = Store::error( (string) $row['last_error'] ); $row['can_retry'] = self::retryable( $row ) && $this->mayRetryTemplate( (string) $row['template_key'] ); unset( $row['recoverable_payload'], $row['lease_available'] ); } unset( $row );
		return $rows;
	}
	public function statistics(): array {
		$this->guard(); $db = $this->store->database();
		$start = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
		$rows = $db->rows( 'SELECT status,COUNT(*) AS messages,SUM(attempts) AS attempts FROM ' . $db->table( 'notification_outbox' ) . " WHERE channel='email' AND created_at>=%s GROUP BY status", array( $start ) );
		$counts = array_fill_keys( self::STATES, 0 ); $attempts = 0;
		foreach ( $rows as $row ) { if ( isset( $counts[ $row['status'] ] ) ) { $counts[ $row['status'] ] = (int) $row['messages']; $attempts += (int) $row['attempts']; } }
		return array( 'since' => $start, 'until' => Store::now(), 'counts' => $counts, 'attempts' => $attempts );
	}
	public function summary(): array {
		$config = $this->configuration(); $stats = $this->statistics(); $url = admin_url( 'admin.php?page=' . self::PAGE );
		$cards = array();
		foreach ( array( 'queued' => $this->t( '邮件排队中', 'Queued mail' ), 'uncertain' => $this->t( '邮件结果不确定', 'Uncertain mail outcomes' ), 'failed' => $this->t( '邮件失败', 'Failed mail' ) ) as $state => $label ) {
			$cards[] = array( 'key' => 'mail_' . $state, 'label' => $label, 'unit' => $this->t( '条', 'messages' ), 'scope' => $this->t( '最近 30 天创建、当前状态；UTC', 'Created within 30 days, current state; UTC' ), 'value' => $stats['counts'][ $state ], 'status' => $config['enabled'] && $config['encryption_ready'] ? 'available' : 'not_configured', 'url' => $url );
		} return $cards;
	}
	/** Shared queue visibility never grants authority to dispatch marketing messages. */
	private function mayRetryTemplate( string $template ): bool {
		return ! str_starts_with( $template, 'newsletter_' ) || current_user_can( 'aish_manage_subscriptions' );
	}
	/** A claimed attempt is not a delivery. Only known-unsent durable live payloads may be scheduled. */
	public static function retryable( array $row ): bool {
		$attempts = (int) ( $row['attempts'] ?? 99 ); $error = (string) ( $row['last_error'] ?? '' );
		return 'retry' === ( $row['status'] ?? '' ) && ! empty( $row['recoverable_payload'] ) && ( $row['expires_at'] ?? '' ) > Store::now() && ( $row['available_at'] ?? '' ) > Store::now() && '' === ( $row['lease_token'] ?? '' ) && ( ! isset( $row['lease_available'] ) || (bool) $row['lease_available'] ) &&
			( ( 'mail_transport_rejected' === $error && $attempts > 0 && $attempts < 3 ) || ( 'mail_worker_interrupted' === $error && $attempts > 0 && $attempts < 5 ) );
	}
	/** Same named lock as Cron: pause never reports success while a worker still owns the queue. */
	private function locked( callable $callback ): mixed {
		$this->guard(); $db = $this->store->database(); $held = false;
		$lock = 'aish_mail:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $db->prefix() ), 0, 48 );
		$mode = class_exists( '\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try { $held = 1 === (int) $db->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) ); if ( ! $held ) { throw new \RuntimeException( 'mail_worker_busy' ); } return $callback(); }
		finally { if ( $held ) { try { $db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $ignored ) {} } if ( null !== $mode ) { mysqli_report( $mode ); } }
	}
	private function event( string $action, int $id, ?string $from, ?string $to ): void {
		$queue = 0 === $id;
		( new \AISkillsHub\Core\Community\Events( $this->store->database() ) )->record( hash( 'sha256', 'mail-operations:' . bin2hex( random_bytes( 16 ) ) ), $queue ? 'mail_queue' : 'mail_message', $queue ? 1 : $id, null, $action, get_current_user_id(), $from, $to, array() );
	}

	public function pause( bool $pause ): void {
		$this->locked( function () use ( $pause ): void {
			$db = $this->store->database(); $table = $db->connection()->options; $value = $pause ? '1' : '0';
			if ( 'innodb' !== strtolower( (string) $db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) ) ) ) { throw new \RuntimeException( 'mail_storage_unavailable' ); }
			$db->transaction( function () use ( $db, $table, $value ): void {
				$current = $db->value( "SELECT option_value FROM $table WHERE option_name='aish_account_mail_paused' FOR UPDATE" );
				if ( ( $current ?? '0' ) === $value ) { return; }
				$db->execute( "INSERT INTO $table (option_name,option_value,autoload) VALUES ('aish_account_mail_paused',%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", array( $value ) );
				$this->event( 'mail_queue_pause', 0, '1' === $current ? 'paused' : 'running', '1' === $value ? 'paused' : 'running' );
			} );
			wp_cache_delete( 'aish_account_mail_paused', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' );
		} );
	}
	public function retry( int $id ): void {
		if ( $id < 1 ) { throw new \RuntimeException( 'mail_invalid_input' ); }
		$this->locked( function () use ( $id ): void {
			$db = $this->store->database(); $table = $db->table( 'notification_outbox' );
			$db->transaction( function () use ( $id, $db, $table ): void {
				$row = $db->row( "SELECT id,template_key,status,attempts,expires_at,available_at,last_error,lease_token,(payload_ciphertext IS NOT NULL AND payload_ciphertext<>'') AS recoverable_payload FROM $table WHERE id=%d AND channel='email' FOR UPDATE", array( $id ) );
				if ( $row && ! $this->mayRetryTemplate( (string) $row['template_key'] ) ) { throw new \RuntimeException( 'mail_forbidden' ); }
				if ( ! $row || ! self::retryable( $row ) ) { throw new \RuntimeException( 'mail_not_retryable' ); }
				$db->execute( "UPDATE $table SET available_at=%s,updated_at=%s WHERE id=%d AND status='retry'", array( Store::now(), Store::now(), $id ) );
				$this->event( 'mail_retry_schedule', $id, 'retry', 'retry' );
			} );
		} );
		// Neither this path nor a queue resume performs a mail transport or business transition.
		do_action( 'aish_account_mail_wake' );
	}
	public function recover(): void { $this->locked( function (): void { $this->store->transaction( function (): void { $this->store->recoverExpired(); $this->event( 'mail_expiry_recover', 0, null, null ); } ); } ); }
	public function handle(): void {
		try {
			$this->guard(); if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { throw new \RuntimeException( 'mail_invalid_input' ); }
			check_admin_referer( 'aish_mail_operations' );
			$action = $_POST['mail_action'] ?? null;
			if ( 'pause' === $action ) { $this->pause( true ); }
			elseif ( 'resume' === $action ) { $this->pause( false ); }
			elseif ( 'recover' === $action ) { $this->recover(); }
			elseif ( 'retry' === $action ) { $id = filter_var( is_string( $_POST['mail_id'] ?? null ) ? $_POST['mail_id'] : '', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ); if ( false === $id ) { throw new \RuntimeException( 'mail_invalid_input' ); } $this->retry( $id ); }
			else { throw new \RuntimeException( 'mail_invalid_input' ); }
			$code = 'saved';
		} catch ( \Throwable $error ) { $code = in_array( $error->getMessage(), array( 'mail_forbidden', 'mail_storage_unavailable', 'mail_worker_busy', 'mail_invalid_input', 'mail_not_retryable' ), true ) ? $error->getMessage() : 'mail_operation_failed'; }
		wp_safe_redirect( add_query_arg( 'result', $code, admin_url( 'admin.php?page=' . self::PAGE ) ), 303 ); exit;
	}
	private function errorDescription( string $code ): string {
		return match ( $code ) {
			'' => $this->t( '没有错误记录。', 'No error recorded.' ),
			'mail_transport_rejected' => $this->t( '传输明确未受理；仅在有效期和原尝试预算内重试。', 'Transport definitely rejected the message; retries remain within expiry and the original attempt budget.' ),
			'mail_worker_interrupted' => $this->t( '发送前领取被中断；恢复既有任务，不创建新邮件。', 'A pre-send claim was interrupted; resume the existing task, without creating another message.' ),
			'mail_outcome_unknown', 'mail_result_uncertain' => $this->t( '结果无法确认，不能据此重发或认定送达。', 'The outcome cannot be confirmed; do not resend or infer delivery.' ),
			'mail_expired' => $this->t( '凭据已到期，用户需通过原流程申请新凭据。', 'Credential expired; the user needs a new credential through the original account flow.' ),
			'mail_retry_exhausted' => $this->t( '尝试预算耗尽；终态不可重发。', 'Attempt budget exhausted; this terminal message cannot be resent.' ),
			'account_unavailable', 'mail_recipient_unavailable', 'mail_context_changed' => $this->t( '当前账户、收件人或凭据上下文不再符合发送条件。', 'The current account, recipient or credential context is no longer eligible.' ),
			'mail_payload_unavailable' => $this->t( '加密负载不可用；检查服务端密钥配置，不重建旧凭据。', 'Encrypted payload unavailable; check server key configuration without rebuilding old credentials.' ),
			default => $this->t( '发送准备或处理失败；原始异常不对外展示。', 'Mail preparation or processing failed; raw exception details are withheld.' ),
		};
	}
	private function form( string $action, string $label, ?int $id = null ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aish_mail_operations"><input type="hidden" name="mail_action" value="' . esc_attr( $action ) . '">'; wp_nonce_field( 'aish_mail_operations' );
		if ( null !== $id ) { echo '<input type="hidden" name="mail_id" value="' . esc_attr( (string) $id ) . '">'; }
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}
	public function page(): void {
		try { $this->guard(); $config = $this->configuration(); $stats = $this->statistics(); $state = is_string( $_GET['state'] ?? null ) ? $_GET['state'] : 'all'; $page = filter_var( $_GET['paged'] ?? '1', FILTER_VALIDATE_INT ); if ( false === $page ) { throw new \RuntimeException( 'mail_invalid_input' ); } $rows = $this->listing( $state, $page ); }
		catch ( \Throwable $error ) { wp_die( esc_html( $this->t( '邮件运营不可用或无权访问。', 'Mail operations are unavailable or unauthorized.' ) ), '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>' . esc_html( $this->t( '通知与邮件运营', 'Notifications and mail operations' ) ) . '</h1><p>' . esc_html( $this->t( '队列不等于传输受理，受理不等于送达。没有可靠送达回执，不计算送达率。', 'Queued does not mean transport accepted, and accepted does not mean delivered. No delivery rate is available without reliable receipts.' ) ) . '</p>';
		if ( isset( $_GET['result'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( 'saved' === $_GET['result'] ? $this->t( '队列操作已保存；没有在此请求中发送邮件。', 'Queue operation saved; this request sent no mail.' ) : $this->t( '操作未执行。任务可能忙碌、过期、已执行，或无法安全重试；请刷新状态。', 'The operation was not performed. The task may be busy, expired, already scheduled or unsafe to retry; refresh its state.' ) ) . '</p></div>'; }
		echo '<h2>' . esc_html( $this->t( '配置诊断', 'Configuration diagnostics' ) ) . '</h2><ul>';
		foreach ( array( 'enabled' => $this->t( '账户邮件已启用', 'Account mail enabled' ), 'encryption_ready' => $this->t( '专用加密配置可用', 'Dedicated encryption ready' ), 'paused' => $this->t( '队列已暂停', 'Queue paused' ), 'cron_scheduled' => $this->t( 'Cron 已安排', 'Cron scheduled' ), 'external_cron_required' => $this->t( '需要外部 Cron 调度', 'External Cron scheduling required' ) ) as $key => $label ) { echo '<li>' . esc_html( $label . ': ' . ( $config[ $key ] ? $this->t( '是', 'Yes' ) : $this->t( '否', 'No' ) ) ) . '</li>'; }
		echo '</ul><p>' . esc_html( $this->t( '本界面不验证 SMTP 或真实送达，也不发送测试邮件。密钥及收件地址不展示。', 'This page does not verify SMTP or real delivery and sends no test mail. Keys and recipient addresses are not shown.' ) ) . '</p>';
		$this->form( $config['paused'] ? 'resume' : 'pause', $config['paused'] ? $this->t( '恢复邮件队列', 'Resume mail queue' ) : $this->t( '暂停邮件队列', 'Pause mail queue' ) ); $this->form( 'recover', $this->t( '处理到期与中断状态（每类最多 100 条）', 'Recover expiry and interruptions (up to 100 per state)' ) );
		echo '<h2>' . esc_html( $this->t( '当前状态统计', 'Current-state statistics' ) ) . '</h2><p>' . esc_html( $stats['since'] . ' → ' . $stats['until'] . ' UTC' ) . '</p><p>' . esc_html( $this->t( '统计最近 30 天创建的现存队列记录；每条只属于一个当前状态。尝试数是领取次数，包含发送前中断；不是送达量。终态保留 30 天，历史数据不回填。', 'Existing rows created in the last 30 days, each counted once in its current state. Attempts count claims, including pre-send interruptions, not deliveries. Terminal rows are retained for 30 days; history is not backfilled.' ) ) . '</p><ul>';
		foreach ( $stats['counts'] as $key => $count ) { echo '<li>' . esc_html( $key . ': ' . $count ) . '</li>'; } echo '<li>' . esc_html( $this->t( '领取尝试数', 'Claim attempts' ) . ': ' . $stats['attempts'] ) . '</li></ul>';
		echo '<h2>' . esc_html( $this->t( '脱敏邮件队列', 'Redacted outbox' ) ) . '</h2><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><label for="mail-state">' . esc_html( $this->t( '状态', 'State' ) ) . '</label> <select id="mail-state" name="state">';
		foreach ( array_merge( array( 'all' ), self::STATES ) as $key ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $state, $key, false ) . '>' . esc_html( $key ) . '</option>'; } echo '</select> <button class="button">' . esc_html( $this->t( '筛选', 'Filter' ) ) . '</button></form>';
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>'; foreach ( array( 'ID', $this->t( '模板 / 语言', 'Template / locale' ), $this->t( '状态 / 尝试', 'State / attempts' ), $this->t( '固定错误码', 'Fixed error code' ), $this->t( '时间（UTC）', 'Times (UTC)' ), $this->t( '操作', 'Action' ) ) as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 25 ) as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( $row['template_key'] . ' / ' . $row['locale'] ) . '</td><td>' . esc_html( $row['status'] . ' / ' . $row['attempts'] ) . '</td><td>' . esc_html( $row['last_error'] ?: '—' ) . '<br>' . esc_html( $this->errorDescription( $row['last_error'] ) ) . '</td><td>' . esc_html( $this->t( '创建', 'Created' ) . ': ' . $row['created_at'] ) . '<br>' . esc_html( $this->t( '可运行', 'Available' ) . ': ' . $row['available_at'] ) . '<br>' . esc_html( $this->t( '到期', 'Expires' ) . ': ' . $row['expires_at'] ) . '<br>' . esc_html( $this->t( '受理', 'Accepted' ) . ': ' . ( $row['accepted_at'] ?? '—' ) ) . '</td><td>';
			if ( $row['can_retry'] ) { $this->form( 'retry', $this->t( '安排一次有限重试', 'Schedule bounded retry' ), (int) $row['id'] ); }
			else { echo esc_html( $this->t( '无可用的安全手动重试', 'No safe manual retry available' ) ); } echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="6">' . esc_html( $this->t( '没有符合条件的队列记录。', 'No matching outbox records.' ) ) . '</td></tr>'; } echo '</tbody></table></div><p>';
		if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'state' => $state, 'paged' => $page - 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $this->t( '上一页', 'Previous' ) ) . '</a> '; }
		if ( count( $rows ) > 25 ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'state' => $state, 'paged' => $page + 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $this->t( '下一页', 'Next' ) ) . '</a>'; } echo '</p><p>' . esc_html( $this->t( '失败、过期、取消、结果不确定及已受理记录的密文已清除，不能重建凭证或重新发送。用户可通过原有验证或密码恢复流程申请新凭证，继续受限流保护；不得重复业务审核。', 'Failed, expired, cancelled, uncertain and accepted messages have their ciphertext cleared. Credentials cannot be reconstructed or resent. Users can request a new credential through the existing rate-limited verification or recovery flow. Business moderation must not be replayed.' ) ) . '</p>';
		echo '<h2>' . esc_html( $this->t( '中英文模板预览', 'Bilingual template previews' ) ) . '</h2>';
		foreach ( array( 'verify_email', 'change_email', 'password_reset', 'security_notice' ) as $template ) { foreach ( 'security_notice' === $template ? array( 'email_changed', 'password_changed', 'sessions_revoked' ) : array( 'password_changed' ) as $event ) { echo '<details><summary>' . esc_html( $template . ( 'security_notice' === $template ? ' / ' . $event : '' ) ) . '</summary>'; foreach ( array( 'zh_CN', 'en_US' ) as $locale ) { $preview = Mail::preview( $template, $locale, $event ); echo '<h3>' . esc_html( $locale . ' — ' . $preview['subject'] ) . '</h3><p style="white-space:pre-wrap">' . esc_html( $preview['body'] ) . '</p>'; } echo '</details>'; } }
		echo '</div>';
	}
}
