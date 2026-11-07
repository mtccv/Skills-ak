<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;
use AISkillsHub\Core\Discovery\Settings;
defined( 'ABSPATH' ) || exit;

/** Dedicated native admin controller; processing never implies a commercial transaction. */
final class EnterpriseAdmin {
	public function __construct( private Enterprise $service ) {}
	private static function t( string $cn, string $en ): string { return str_starts_with( get_user_locale(), 'zh' ) ? $cn : $en; }
	public function register(): void {
		add_action( 'admin_menu', function (): void { add_submenu_page( 'aish', self::t( '企业咨询', 'Enterprise inquiries' ), self::t( '企业咨询', 'Enterprise inquiries' ), Enterprise::CAPABILITY, 'aish-enterprise', array( $this, 'screen' ) ); } );
		add_action( 'admin_post_aish_enterprise_admin', array( $this, 'receive' ) );
	}
	public function receive(): never {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POST required.', '', array( 'response' => 405 ) ); }
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && ( ! ctype_digit( (string) $_SERVER['CONTENT_LENGTH'] ) || (int) $_SERVER['CONTENT_LENGTH'] > 65536 ) ) { wp_die( 'Request too large.', '', array( 'response' => 413 ) ); }
		if ( ! current_user_can( Enterprise::CAPABILITY ) || ! is_string( $_POST['_wpnonce'] ?? null ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'aish_enterprise_admin' ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		$data = wp_unslash( $_POST ); $id = 0; $status = 'error';
		try {
			if ( array_diff( array_keys( $data ), array( 'action', 'operation', 'revision', 'enabled', 'translations', 'inquiry', 'state', 'notes', '_wpnonce', '_wp_http_referer', 'submit' ) ) || ( isset( $data['enabled'] ) && '1' !== $data['enabled'] ) ) { throw new \InvalidArgumentException( 'invalid_request' ); }
			$revision = Settings::integer( $data['revision'] ?? '', 0, PHP_INT_MAX - 1 );
			if ( 'settings' === ( $data['operation'] ?? '' ) ) { $this->service->saveConfig( array( 'enabled' => '1' === ( $data['enabled'] ?? '' ), 'translations' => $data['translations'] ?? null ), $revision ); }
			elseif ( 'handle' === ( $data['operation'] ?? '' ) ) { $id = Settings::integer( $data['inquiry'] ?? '', 1, PHP_INT_MAX ); if ( ! is_string( $data['state'] ?? null ) || ! is_string( $data['notes'] ?? null ) ) { throw new \InvalidArgumentException(); } $this->service->moderate( $id, $revision, $data['state'], $data['notes'] ); }
			else { throw new \InvalidArgumentException(); }
			$status = 'saved';
		} catch ( \Throwable $error ) { $status = 'conflict' === $error->getMessage() ? 'conflict' : 'error'; }
		nocache_headers(); wp_safe_redirect( add_query_arg( array( 'page' => 'aish-enterprise', 'inquiry' => $id ?: null, 'result' => $status ), admin_url( 'admin.php' ) ), 303 ); exit;
	}
	private function openForm( string $operation, int $revision ): void { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aish_enterprise_admin"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="revision" value="' . esc_attr( (string) $revision ) . '">'; wp_nonce_field( 'aish_enterprise_admin' ); }
	public function screen(): void {
		if ( ! current_user_can( Enterprise::CAPABILITY ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		echo '<div class="wrap"><h1>' . esc_html( self::t( '企业咨询', 'Enterprise inquiries' ) ) . '</h1><p>' . esc_html( self::t( '只提供真实服务说明与私有人工联系。无订单、收费、自动邮件或营销名单。', 'Real service information and private manual contact only. No orders, billing, automatic email, or marketing list.' ) ) . '</p>';
		$result = is_string( $_GET['result'] ?? null ) ? $_GET['result'] : '';
		if ( in_array( $result, array( 'saved', 'error', 'conflict' ), true ) ) { echo '<div class="notice ' . ( 'saved' === $result ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( 'saved' === $result ? self::t( '已保存。', 'Saved.' ) : self::t( '未保存。请刷新检查修订和输入后重试。', 'Not saved. Refresh and check the revision and input before retrying.' ) ) . '</p></div>'; }
		try {
			$id = isset( $_GET['inquiry'] ) ? Settings::integer( $_GET['inquiry'], 0, PHP_INT_MAX ) : 0;
			if ( $id ) { $this->detail( $id ); }
			else { $this->settings(); $this->listing(); }
		} catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html( self::t( '此内容暂不可用。', 'This content is unavailable.' ) ) . '</p></div>'; }
		echo '</div>';
	}
	private function settings(): void {
		$s = Enterprise::snapshot(); echo '<h2>' . esc_html( self::t( 'Contact 服务说明', 'Service information on Contact' ) ) . '</h2>'; $this->openForm( 'settings', $s['revision'] );
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( $s['enabled'], true, false ) . '> ' . esc_html( self::t( '明确启用真实服务说明与企业咨询入口（默认关闭）', 'Explicitly enable real service information and enterprise inquiries (off by default)' ) ) . '</label></p><p class="description">' . esc_html( self::t( '仅有完整人工标题和说明的语言会展示；不得填写虚构客户、报价、案例或服务成果。', 'Only languages with a complete manual title and description are shown. Do not invent clients, prices, cases, or outcomes.' ) ) . '</p>';
		foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $locale => $label ) { echo '<fieldset><legend><strong>' . esc_html( $label ) . '</strong></legend><p><label>' . esc_html( self::t( '标题', 'Title' ) ) . '<br><input class="large-text" name="translations[' . esc_attr( $locale ) . '][title]" maxlength="120" value="' . esc_attr( $s['translations'][ $locale ]['title'] ) . '"></label></p><p><label>' . esc_html( self::t( '真实服务说明', 'Real service description' ) ) . '<br><textarea class="large-text" rows="4" maxlength="2000" name="translations[' . esc_attr( $locale ) . '][description]">' . esc_textarea( $s['translations'][ $locale ]['description'] ) . '</textarea></label></p></fieldset>'; }
		submit_button( self::t( '保存服务说明', 'Save service information' ) ); echo '</form>'; $this->history( 0 );
	}
	private function labels(): array { return array( 'pending' => self::t( '待处理', 'Pending' ), 'reviewing' => self::t( '处理中', 'Reviewing' ), 'closed' => self::t( '已关闭', 'Closed' ), 'archived' => self::t( '已归档', 'Archived' ) ); }
	private function listing(): void {
		$page = Settings::integer( $_GET['paged'] ?? 1, 1, 100000 ); $state = is_string( $_GET['state'] ?? '' ) ? $_GET['state'] : ''; $rows = $this->service->listing( $page, $state );
		echo '<h2>' . esc_html( self::t( '收到的私有咨询', 'Received private inquiries' ) ) . '</h2><form method="get"><input type="hidden" name="page" value="aish-enterprise"><label>' . esc_html( self::t( '状态', 'Status' ) ) . ' <select name="state"><option value="">' . esc_html( self::t( '全部', 'All' ) ) . '</option>'; foreach ( $this->labels() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $state, $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label> <button class="button">' . esc_html( self::t( '筛选', 'Filter' ) ) . '</button></form><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html( self::t( '来信', 'Inquiry' ) ) . '</th><th>UTC</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 20 ) as $row ) { echo '<tr><td>' . esc_html( (string) $row['ID'] ) . '</td><td><a href="' . esc_url( add_query_arg( array( 'page' => 'aish-enterprise', 'inquiry' => $row['ID'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $row['post_title'] ?: self::t( '已擦除的咨询', 'Erased inquiry' ) ) . '</a></td><td>' . esc_html( $row['post_date_gmt'] ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="3">' . esc_html( self::t( '暂无咨询。', 'No inquiries.' ) ) . '</td></tr>'; } echo '</tbody></table><p>';
		foreach ( array( $page > 1 ? $page - 1 : 0 => self::t( '上一页', 'Previous' ), count( $rows ) > 20 ? $page + 1 : 0 => self::t( '下一页', 'Next' ) ) as $number => $label ) { if ( $number ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'aish-enterprise', 'paged' => $number, 'state' => $state ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> '; } } echo '</p>';
	}
	private function detail( int $id ): void {
		$r = $this->service->detail( $id ); $m = $r['meta']; echo '<h2>' . esc_html( self::t( '咨询编号', 'Inquiry' ) . ' #' . $id ) . '</h2><dl>';
		foreach ( array( '_aish_sender_name' => self::t( '联系人', 'Contact name' ), '_aish_sender_email' => self::t( '邮箱', 'Email' ), '_aish_message_locale' => self::t( '语言', 'Language' ) ) as $field => $label ) { echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( $m[ $field ] ?? '' ) . '</dd>'; } echo '</dl><p>UTC: ' . esc_html( $r['post_date_gmt'] ) . '</p><div style="white-space:pre-wrap;overflow-wrap:anywhere;padding:16px;background:white">' . esc_html( $r['post_content'] ) . '</div>';
		if ( empty( $m['_aish_enterprise_erased'] ) ) { $this->openForm( 'handle', (int) $m['_aish_enterprise_revision'] ); echo '<input type="hidden" name="inquiry" value="' . esc_attr( (string) $id ) . '"><p><label>' . esc_html( self::t( '处理状态', 'Handling state' ) ) . ' <select name="state">'; foreach ( $this->labels() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $m['_aish_message_state'], $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label></p><p><label>' . esc_html( self::t( '内部备注，不会向咨询人发送', 'Internal notes; not sent to the sender' ) ) . '<br><textarea name="notes" class="large-text" rows="4" maxlength="2000">' . esc_textarea( $m['_aish_message_notes'] ) . '</textarea></label></p>'; submit_button( self::t( '保存处理结果', 'Save handling result' ) ); echo '</form>'; } $this->history( $id );
	}
	private function history( int $id ): void { echo '<details><summary>' . esc_html( self::t( '最近 20 条操作记录', 'Latest 20 operations' ) ) . '</summary><ul>'; foreach ( $this->service->history( $id ) as $row ) { echo '<li>' . esc_html( '#' . $row['subject_revision'] . ' · ' . $row['action'] . ' · ' . ( $row['to_state'] ?? '' ) . ' · actor ' . ( $row['actor_user_id'] ?? '—' ) . ' · ' . $row['created_at'] . ' UTC' ) . '</li>'; } echo '</ul></details>'; }
}
