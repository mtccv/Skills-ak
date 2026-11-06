<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Admin;

defined( 'ABSPATH' ) || exit;

/** Native UI helpers only. Domain services own authorization, state and transactions. */
final class ContributionUi {
	public const LIMIT = 20;
	public static function t( string $zh, string $en ): string { return str_starts_with( (string) get_user_locale(), 'zh' ) ? $zh : $en; }
	public static function authorize( string $cap ): void {
		if ( ! current_user_can( $cap ) ) { wp_die( esc_html( self::t( '无权处理此类记录。', 'You cannot manage this type of record.' ) ), '', array( 'response' => 403 ) ); }
	}
	public static function input( array $data, string $name, string $default = '', int $max = 2000 ): string {
		$value = $data[ $name ] ?? $default;
		if ( ! is_string( $value ) || strlen( $value ) > $max * 4 || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'contribution_admin_input' ); }
		$value = wp_unslash( $value );
		if ( preg_match_all( '/./us', $value ) > $max ) { throw new \InvalidArgumentException( 'contribution_admin_input' ); }
		return $value;
	}
	public static function id( array $data, string $name, int $default = 0 ): int {
		$value = self::input( $data, $name, (string) $default, 20 );
		if ( ! preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) { throw new \InvalidArgumentException( 'contribution_admin_id' ); }
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => PHP_INT_MAX ) ) );
		if ( false === $id ) { throw new \InvalidArgumentException( 'contribution_admin_id' ); }
		return $id;
	}
	public static function choice( array $data, string $name, array $allowed, string $default = '' ): string {
		$value = self::input( $data, $name, $default, 64 );
		if ( ! in_array( $value, $allowed, true ) ) { throw new \InvalidArgumentException( 'contribution_admin_choice' ); }
		return $value;
	}
	public static function page( array $data, string $name = 'paged' ): int {
		$page = self::id( $data, $name, 1 );
		if ( $page < 1 || $page > 1000 ) { throw new \InvalidArgumentException( 'contribution_admin_page' ); }
		return $page;
	}
	public static function text( mixed $value ): string { return is_scalar( $value ) ? (string) $value : ''; }
	public static function url( string $slug, array $args = array() ): string { return add_query_arg( array_merge( $args, array( 'page' => $slug ) ), admin_url( 'admin.php' ) ); }
	public static function hidden( string $name, string|int $value ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">'; }
	public static function select( string $name, array $choices, string $value, string $label ): void {
		static $sequence = 0;
		$id = 'aish-contribution-' . ++$sequence;
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ' </label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $choices as $key => $caption ) { echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( (string) $key, $value, false ) . '>' . esc_html( $caption ) . '</option>'; }
		echo '</select> ';
	}
	public static function form( string $action, string $nonce, string $kind, bool $bulk = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( 'action', $action ); self::hidden( 'kind', $kind ); self::hidden( 'bulk', $bulk ? '1' : '0' );
		self::hidden( 'request_key', bin2hex( random_bytes( 16 ) ) ); wp_nonce_field( $nonce );
	}
	public static function notes( int $min = 5 ): void {
		$label = $min ? sprintf( self::t( '给申请人的反馈（用户可见，至少 %d 个字符）', 'Feedback for the applicant (visible to the user, at least %d characters)' ), $min ) : self::t( '给申请人的反馈（可选，用户可见）', 'Feedback for the applicant (optional, visible to the user)' );
		echo '<p><label>' . esc_html( $label ) . '<br><textarea class="large-text" name="feedback" rows="3" minlength="' . $min . '" maxlength="2000"' . ( $min ? ' required' : '' ) . '></textarea></label></p>';
		echo '<p><label>' . esc_html( self::t( '内部备注（仅授权审核人员可见）', 'Internal note (authorized moderators only)' ) ) . '<br><textarea class="large-text" name="private_note" rows="3" maxlength="2000"></textarea></label></p>';
	}
	public static function confirm(): void {
		echo '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . esc_html( self::t( '已核对当前记录、修订和处理结果，确认执行。', 'I have checked the record, revision and result, and confirm this action.' ) ) . '</label></p><p><button class="button button-primary" type="submit">' . esc_html( self::t( '确认处理', 'Confirm decision' ) ) . '</button></p>';
	}
	public static function request( array $data ): string {
		if ( '1' !== self::input( $data, 'confirmed', '', 1 ) ) { throw new \InvalidArgumentException( 'contribution_admin_confirmation' ); }
		$key = self::input( $data, 'request_key', '', 64 );
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $key ) ) { throw new \InvalidArgumentException( 'contribution_admin_key' ); }
		return $key;
	}
	public static function post( string $nonce ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html( self::t( '仅允许 POST 请求。', 'POST required.' ) ), '', array( 'response' => 405 ) ); }
		try { $value = self::input( $_POST, '_wpnonce', '', 64 ); } catch ( \Throwable $error ) { $value = ''; }
		if ( ! wp_verify_nonce( $value, $nonce ) ) { wp_die( esc_html( self::t( '表单已失效，请重新打开。', 'The form expired. Please reload it.' ) ), '', array( 'response' => 403 ) ); }
	}
	public static function ids( array $data, bool $bulk ): array {
		$values = $bulk ? ( $data['ids'] ?? null ) : array( self::input( $data, 'id', '', 20 ) );
		if ( ! is_array( $values ) || ! $values || count( $values ) > self::LIMIT ) { throw new \InvalidArgumentException( 'contribution_admin_batch' ); }
		$ids = array();
		foreach ( $values as $value ) {
			$id = self::id( array( 'id' => $value ), 'id' );
			if ( $id < 1 || isset( $ids[ $id ] ) ) { throw new \InvalidArgumentException( 'contribution_admin_batch' ); }
			$ids[ $id ] = $id;
		}
		return array_values( $ids );
	}
	public static function menu( string $title, string $cap, string $slug, callable $callback ): void {
		$hook = add_submenu_page( 'aish', $title, $title, $cap, $slug, $callback );
		if ( is_string( $hook ) && '' !== $hook ) { add_action( 'load-' . $hook, static function (): void { nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); } ); }
	}
	public static function screen( string $cap, string $title, string $notice, callable $body ): void {
		self::authorize( $cap ); echo '<div class="wrap"><h1>AI Skills Hub · ' . esc_html( $title ) . '</h1>';
		try {
			$key = $notice . get_current_user_id(); $result = get_transient( $key );
			if ( is_array( $result ) ) {
				delete_transient( $key );
				echo '<div class="notice ' . ( empty( $result['failed'] ) ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( sprintf( self::t( '已完成 %d 项，未完成 %d 项。未完成项请重新打开最新记录，核对状态、权限与输入后重试。', '%d completed; %d not completed. Reload failed records and check the current state, permissions and input before retrying.' ), max( 0, (int) ( $result['success'] ?? 0 ) ), max( 0, (int) ( $result['failed'] ?? 0 ) ) ) ) . '</p></div>';
			}
			$body();
		} catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html( self::t( '无法读取记录。请核对权限或筛选条件，然后重试。', 'The record could not be read. Check permissions and filters, then retry.' ) ) . '</p></div>'; }
		echo '</div>';
	}
	public static function filters( array $states, string $default = 'pending' ): array {
		return array( 'page' => self::page( $_GET ), 'per_page' => self::LIMIT, 'q' => self::input( $_GET, 's', '', 100 ), 'state' => self::choice( $_GET, 'state', array_merge( array( 'all' ), array_keys( $states ) ), $default ), 'user_id' => self::id( $_GET, 'user_id' ), 'skill_id' => self::id( $_GET, 'skill_id' ), 'locale' => self::choice( $_GET, 'locale', array( '', 'zh_CN', 'en_US' ) ), 'sort' => self::choice( $_GET, 'orderby', array( 'id', 'created_at', 'updated_at' ), 'id' ), 'direction' => self::choice( $_GET, 'order', array( 'asc', 'desc' ), 'desc' ) );
	}
	public static function filterForm( string $slug, array $states, array $filter, bool $skillFilter = false ): void {
		echo '<form method="get"><p>'; self::hidden( 'page', $slug );
		echo '<label>' . esc_html( self::t( '搜索', 'Search' ) ) . ' <input type="search" name="s" maxlength="100" value="' . esc_attr( $filter['q'] ) . '"></label> ';
		self::select( 'state', array( 'all' => self::t( '全部状态', 'All states' ) ) + $states, $filter['state'], self::t( '状态', 'State' ) );
		echo '<label>' . esc_html( self::t( '用户 ID', 'User ID' ) ) . ' <input type="number" class="small-text" name="user_id" min="0" value="' . (int) $filter['user_id'] . '"></label> ';
		if ( $skillFilter ) { echo '<label>Skill ID <input type="number" class="small-text" name="skill_id" min="0" value="' . (int) $filter['skill_id'] . '"></label> '; }
		self::select( 'locale', array( '' => self::t( '全部语言', 'All languages' ), 'zh_CN' => '简体中文', 'en_US' => 'English' ), $filter['locale'], self::t( '原文语言', 'Original language' ) );
		self::select( 'orderby', array( 'id' => 'ID', 'created_at' => self::t( '创建时间', 'Created' ), 'updated_at' => self::t( '更新时间', 'Updated' ) ), $filter['sort'], self::t( '排序', 'Sort' ) );
		self::select( 'order', array( 'desc' => self::t( '降序', 'Descending' ), 'asc' => self::t( '升序', 'Ascending' ) ), $filter['direction'], self::t( '顺序', 'Order' ) );
		echo '<button class="button" type="submit">' . esc_html( self::t( '筛选', 'Filter' ) ) . '</button> <a href="' . esc_url( self::url( $slug ) ) . '">' . esc_html( self::t( '重置', 'Reset' ) ) . '</a></p></form>';
	}
	public static function pagination( string $slug, array $result, array $filters = array(), string $parameter = 'paged', array $extra = array() ): void {
		$page = max( 1, (int) ( $result['page'] ?? 1 ) ); $total = max( 0, (int) ( $result['total'] ?? 0 ) );
		$pages = (int) ceil( $total / max( 1, (int) ( $result['per_page'] ?? self::LIMIT ) ) );
		$params = array( 's' => $filters['q'] ?? '', 'state' => $filters['state'] ?? 'all', 'user_id' => $filters['user_id'] ?? 0, 'skill_id' => $filters['skill_id'] ?? 0, 'locale' => $filters['locale'] ?? '', 'orderby' => $filters['sort'] ?? 'id', 'order' => $filters['direction'] ?? 'desc' ) + $extra;
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( self::t( '%d 条记录', '%d records' ), $total ) ) . '</span> ';
		foreach ( array( $page - 1 => self::t( '上一页', 'Previous' ), $page + 1 => self::t( '下一页', 'Next' ) ) as $target => $label ) {
			if ( $target >= 1 && $target <= min( 1000, $pages ) ) { echo '<a class="button" href="' . esc_url( self::url( $slug, array_merge( $params, array( $parameter => $target ) ) ) ) . '">' . esc_html( $label ) . '</a> '; }
		}
		echo esc_html( $page . ' / ' . max( 1, $pages ) ) . '</div></div>';
	}
	public static function fields( array $values, array $labels ): void {
		echo '<table class="widefat striped"><tbody>';
		foreach ( $labels as $key => $label ) {
			$value = $values[ $key ] ?? null;
			if ( is_bool( $value ) ) { $value = $value ? self::t( '是', 'Yes' ) : self::t( '否', 'No' ); }
			if ( is_array( $value ) ) { $value = implode( "\n", array_map( self::text( ... ), $value ) ); }
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><div dir="auto" style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( self::text( $value ) ) . '</div></td></tr>';
		}
		echo '</tbody></table>';
	}
	public static function history( string $slug, int $id, array $history ): void {
		echo '<h2>' . esc_html( self::t( '处理记录', 'Decision history' ) ) . '</h2><div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
		foreach ( array( 'ID', self::t( '操作', 'Action' ), self::t( '修订', 'Revision' ), self::t( '状态变更', 'State change' ), self::t( '处理人', 'Moderator' ), self::t( '时间 (UTC)', 'Time (UTC)' ) ) as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $history['items'] ?? array() as $event ) {
			echo '<tr><td>' . (int) ( $event['id'] ?? 0 ) . '</td><td>' . esc_html( self::text( $event['action'] ?? '' ) ) . '</td><td>' . (int) ( $event['subject_revision'] ?? 0 ) . '</td><td>' . esc_html( self::text( $event['from_state'] ?? '' ) . ' → ' . self::text( $event['to_state'] ?? '' ) ) . '</td><td>' . (int) ( $event['actor_user_id'] ?? 0 ) . '</td><td>' . esc_html( self::text( $event['created_at'] ?? '' ) ) . '</td></tr>';
		}
		if ( empty( $history['items'] ) ) { echo '<tr><td colspan="6">' . esc_html( self::t( '暂无处理记录。', 'No decisions recorded.' ) ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $slug, $history, array(), 'history_page', array( 'id' => $id ) );
	}
}
