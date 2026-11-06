<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

defined( 'ABSPATH' ) || exit;

/** Native moderation UI. It can decide on immutable drafts, never author user text. */
final class Admin {
	public const REVIEWS = 'aish_moderate_community';
	public const REPORTS = 'aish_manage_community_reports';
	private const NONCE = 'aish_community_admin';
	private const LIMIT = 20;
	private const SLUGS = array( 'reviews' => 'aish-community-reviews', 'review' => 'aish-community-review-reports', 'skill' => 'aish-community-skill-reports' );

	public function __construct( private Service $service, private Query $query ) {}

	public function register(): void {
		add_action( 'admin_menu', function (): void {
			foreach ( array(
				array( 'reviews', self::REVIEWS, self::t( '社区评价', 'Community Reviews' ), 'reviewsScreen' ),
				array( 'review', self::REPORTS, self::t( '评价举报', 'Review Reports' ), 'reviewReportsScreen' ),
				array( 'skill', self::REPORTS, self::t( 'Skill 内容纠错', 'Skill Corrections' ), 'skillReportsScreen' ),
			) as [ $kind, $cap, $title, $callback ] ) {
				$hook = add_submenu_page( 'aish', $title, $title, $cap, self::SLUGS[ $kind ], array( $this, $callback ) );
				if ( is_string( $hook ) && '' !== $hook ) {
					add_action( 'load-' . $hook, static function (): void {
						nocache_headers();
						header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
					} );
				}
			}
		} );
		add_action( 'admin_post_aish_community_admin', array( $this, 'handle' ) );
	}

	private static function t( string $zh, string $en ): string { return str_starts_with( (string) get_user_locale(), 'zh' ) ? $zh : $en; }
	private static function authorize( string $cap ): void {
		if ( ! current_user_can( $cap ) ) { wp_die( esc_html( self::t( '无权管理此社区内容。', 'You cannot manage this community content.' ) ), '', array( 'response' => 403 ) ); }
	}
	private static function input( array $data, string $key, string $default = '', int $max = 2000 ): string {
		$value = $data[ $key ] ?? $default;
		if ( ! is_string( $value ) || strlen( $value ) > $max || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'community_admin_input' ); }
		return wp_unslash( $value );
	}
	private static function id( array $data, string $key, int $default = 0 ): int {
		$value = self::input( $data, $key, (string) $default, 20 );
		if ( ! preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) { throw new \InvalidArgumentException( 'community_admin_id' ); }
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => PHP_INT_MAX ) ) );
		if ( false === $id ) { throw new \InvalidArgumentException( 'community_admin_id' ); }
		return $id;
	}
	private static function choice( array $input, string $name, array $allowed, string $default = '' ): string {
		$value = self::input( $input, $name, $default, 64 );
		if ( ! in_array( $value, $allowed, true ) ) { throw new \InvalidArgumentException( 'community_admin_choice' ); }
		return $value;
	}
	private static function text( mixed $value ): string { return is_scalar( $value ) ? (string) $value : ''; }
	private static function url( string $kind = 'reviews', array $args = array() ): string { return add_query_arg( array_merge( array( 'page' => self::SLUGS[ $kind ] ), $args ), admin_url( 'admin.php' ) ); }
	private static function hidden( string $name, string|int $value ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">'; }
	private static function form( string $operation, string $kind = 'reviews' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( 'action', 'aish_community_admin' ); self::hidden( 'operation', $operation ); self::hidden( 'kind', $kind );
		self::hidden( 'request_key', bin2hex( random_bytes( 16 ) ) ); wp_nonce_field( self::NONCE );
	}
	private static function select( string $name, array $options, string $value, string $label ): void {
		static $sequence = 0;
		$id = 'aish-community-' . $name . '-' . ++$sequence;
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ' </label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $id => $caption ) { echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( (string) $id, $value, false ) . '>' . esc_html( (string) $caption ) . '</option>'; }
		echo '</select> ';
	}
	private static function states( bool $reports = false ): array {
		return $reports ? array( 'pending' => self::t( '待处理', 'Pending' ), 'reviewing' => self::t( '处理中', 'In review' ), 'handled' => self::t( '已处理', 'Handled' ), 'closed' => self::t( '已关闭', 'Closed' ) ) : array( 'pending' => self::t( '待审核', 'Pending' ), 'approved' => self::t( '已批准', 'Approved' ), 'rejected' => self::t( '已拒绝', 'Rejected' ), 'hidden' => self::t( '已隐藏', 'Hidden' ), 'withdrawn' => self::t( '已撤回', 'Withdrawn' ), 'superseded' => self::t( '被新修订替代', 'Superseded' ) );
	}
	private static function reasons( string $kind ): array {
		return match ( $kind ) {
			'moderation' => array( 'approved' => self::t( '符合社区规范', 'Meets community guidelines' ), 'spam' => self::t( '垃圾内容', 'Spam' ), 'false_claim' => self::t( '不实陈述', 'False claim' ), 'abuse' => self::t( '恶意攻击', 'Abuse' ), 'policy' => self::t( '违反社区规范', 'Policy violation' ), 'other' => self::t( '其他', 'Other' ) ),
			'resolution' => array( 'resolved' => self::t( '问题已处理', 'Resolved' ), 'no_action' => self::t( '无需操作', 'No action needed' ), 'duplicate' => self::t( '重复反馈', 'Duplicate' ), 'invalid' => self::t( '无效反馈', 'Invalid report' ), 'other' => self::t( '其他', 'Other' ) ),
			'review' => array( 'spam' => self::t( '垃圾内容', 'Spam' ), 'false_review' => self::t( '虚假评价', 'False review' ), 'attack' => self::t( '恶意攻击', 'Attack' ), 'violation' => self::t( '违规内容', 'Violation' ), 'other' => self::t( '其他', 'Other' ) ),
			'skill' => array( 'broken_source' => self::t( 'GitHub 来源失效', 'Broken GitHub source' ), 'installation' => self::t( '安装说明错误', 'Installation error' ), 'license' => self::t( '许可证错误', 'License error' ), 'outdated' => self::t( '内容过期', 'Outdated content' ), 'compatibility' => self::t( '兼容性错误', 'Compatibility error' ), 'security' => self::t( '潜在安全问题', 'Potential security issue' ), 'other' => self::t( '其他', 'Other' ) ),
		};
	}
	private static function state( mixed $state ): string { $value = self::text( $state ); return ( self::states() + self::states( true ) )[ $value ] ?? $value; }
	private static function error(): void { echo '<div class="notice notice-error"><p>' . esc_html( self::t( '无法完成操作。请核对字段、权限和数据库状态，重新载入最新修订后重试。', 'The operation could not be completed. Check the fields, permissions and database state, then reload the current revision.' ) ) . '</p></div>'; }
	private function screen( string $cap, string $title, callable $body ): void {
		self::authorize( $cap ); echo '<div class="wrap"><h1>AI Skills Hub · ' . esc_html( $title ) . '</h1>';
		try { $this->notices(); $body(); } catch ( \Throwable $error ) { self::error(); }
		echo '</div>';
	}
	private function notices(): void {
		$key = 'aish_community_admin_notice_' . get_current_user_id(); $notice = get_transient( $key );
		if ( ! is_array( $notice ) ) { return; }
		delete_transient( $key ); $success = max( 0, (int) ( $notice['success'] ?? 0 ) ); $failed = max( 0, (int) ( $notice['failed'] ?? 0 ) );
		echo '<div class="notice ' . ( $failed ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( sprintf( self::t( '已完成 %d 项，未完成 %d 项。各项分别处理；冲突项请重新打开最新记录，勿假定已生效。', '%d completed; %d not completed. Items are handled individually. Reload conflicting records before trying again.' ), $success, $failed ) ) . '</p></div>';
	}
	private function filters( bool $reports = false ): array {
		$page = self::id( $_GET, 'paged', 1 );
		if ( $page < 1 || $page > 1000 ) { throw new \InvalidArgumentException( 'community_admin_page' ); }
		return array(
			'page' => $page, 'per_page' => self::LIMIT, 'q' => self::input( $_GET, 's', '', 400 ),
			'status' => self::choice( $_GET, 'status', $reports ? array( 'all', 'pending', 'reviewing', 'handled', 'closed' ) : array( 'all', 'pending', 'approved', 'rejected', 'hidden', 'withdrawn' ), 'pending' ),
			'skill_id' => self::id( $_GET, 'skill_id' ), 'user_id' => self::id( $_GET, 'user_id' ),
			'locale' => self::choice( $_GET, 'locale', array( '', 'zh_CN', 'en_US' ) ),
			'sort' => self::choice( $_GET, 'orderby', $reports ? array( 'id', 'created_at' ) : array( 'id', 'created_at', 'updated_at' ), 'id' ),
			'direction' => self::choice( $_GET, 'order', array( 'asc', 'desc' ), 'desc' ),
			'reason' => self::input( $_GET, 'reason', '', 32 ),
		);
	}
	private function filterForm( string $kind, array $filter ): void {
		echo '<form method="get"><p>'; self::hidden( 'page', self::SLUGS[ $kind ] );
		echo '<label for="aish-community-search">' . esc_html( self::t( '搜索', 'Search' ) ) . ' </label><input id="aish-community-search" name="s" type="search" maxlength="100" value="' . esc_attr( $filter['q'] ) . '"> ';
		self::select( 'status', array( 'all' => self::t( '全部状态', 'All states' ) ) + array_diff_key( self::states( 'reviews' !== $kind ), array( 'superseded' => true ) ), $filter['status'], self::t( '状态', 'State' ) );
		echo '<label for="aish-community-skill">Skill ID </label><input class="small-text" id="aish-community-skill" name="skill_id" type="number" min="0" value="' . (int) $filter['skill_id'] . '"> ';
		if ( 'reviews' === $kind ) { self::select( 'locale', array( '' => self::t( '全部语言', 'All languages' ), 'zh_CN' => '简体中文', 'en_US' => 'English' ), $filter['locale'], self::t( '评价原文语言', 'Review language' ) ); }
		else { self::select( 'reason', array( '' => self::t( '全部类型', 'All reasons' ) ) + self::reasons( $kind ), $filter['reason'], self::t( '类型', 'Reason' ) ); }
		self::select( 'orderby', ( 'reviews' === $kind ? array( 'updated_at' => self::t( '更新时间', 'Updated' ) ) : array() ) + array( 'id' => 'ID', 'created_at' => self::t( '创建时间', 'Created' ) ), $filter['sort'], self::t( '排序', 'Sort' ) );
		self::select( 'order', array( 'desc' => self::t( '降序', 'Descending' ), 'asc' => self::t( '升序', 'Ascending' ) ), $filter['direction'], self::t( '顺序', 'Order' ) );
		echo '<button class="button" type="submit">' . esc_html( self::t( '筛选', 'Filter' ) ) . '</button> <a href="' . esc_url( self::url( $kind ) ) . '">' . esc_html( self::t( '重置', 'Reset' ) ) . '</a></p></form>';
	}
	private function pagination( string $kind, array $result, array $filter, string $parameter = 'paged', array $extra = array() ): void {
		$page = max( 1, (int) ( $result['page'] ?? 1 ) ); $total = max( 0, (int) ( $result['total'] ?? 0 ) ); $pages = (int) ceil( $total / max( 1, (int) ( $result['per_page'] ?? self::LIMIT ) ) );
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( self::t( '%d 条记录', '%d records' ), $total ) ) . '</span> ';
		$params = array( 's' => $filter['q'] ?? '', 'status' => $filter['status'] ?? '', 'skill_id' => $filter['skill_id'] ?? 0, 'user_id' => $filter['user_id'] ?? 0, 'locale' => $filter['locale'] ?? '', 'orderby' => $filter['sort'] ?? 'id', 'order' => $filter['direction'] ?? 'desc', 'reason' => $filter['reason'] ?? '' ) + $extra;
		foreach ( array( $page - 1 => self::t( '上一页', 'Previous' ), $page + 1 => self::t( '下一页', 'Next' ) ) as $target => $label ) {
			if ( $target >= 1 && $target <= min( 1000, $pages ) ) { echo '<a class="button" href="' . esc_url( self::url( $kind, array_merge( $params, array( $parameter => $target ) ) ) ) . '">' . esc_html( $label ) . '</a> '; }
		}
		echo esc_html( $page . ' / ' . max( 1, $pages ) ) . '</div></div>';
	}

	public function reviewsScreen(): void {
		$this->screen( self::REVIEWS, self::t( '社区评价', 'Community Reviews' ), function (): void {
			echo '<p>' . esc_html( self::t( '此处审核用户的星级和使用体验，与 Skill 内容发布审核、AI 内容审稿分别管理。审核不会改写作者原文。', 'Moderate user ratings and experiences here. Skill publication and AI draft reviews are separate workflows. Moderation never rewrites the author’s text.' ) ) . '</p>';
			$id = self::id( $_GET, 'id' );
			if ( $id ) { $this->reviewDetail( $id ); return; }
			$filters = $this->filters(); $result = $this->query->adminReviews( $filters );
			$this->filterForm( 'reviews', $filters );
			echo '<p class="description">' . esc_html( self::t( '“待审核”同时包含已公开评价的新修改。批量操作最多 20 项，依据当前列表捕获的修订逐项校验。批准前请查看完整原稿。', 'Pending also includes edits to published reviews. Bulk actions are limited to 20 records and validate each captured revision. Read the full draft before approving it.' ) ) . '</p>';
			self::form( 'review_bulk' ); $this->decisionFields( true );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::t( '选择', 'Select' ) ) . '</th><th>' . esc_html( self::t( '评价 / Skill', 'Review / Skill' ) ) . '</th><th>' . esc_html( self::t( '作者 / 状态', 'Author / State' ) ) . '</th><th>' . esc_html( self::t( '公开版本', 'Published version' ) ) . '</th><th>' . esc_html( self::t( '待审修改', 'Pending edit' ) ) . '</th><th>' . esc_html( self::t( '更新时间', 'Updated' ) ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $row ) {
				$id = (int) $row['id'];
				echo '<tr><td><input type="checkbox" name="ids[]" value="' . $id . '" aria-label="' . esc_attr( self::t( '选择评价 #', 'Select review #' ) . $id ) . '">';
				self::hidden( 'snapshots[' . $id . '][lock_version]', (int) $row['lock_version'] ); self::hidden( 'snapshots[' . $id . '][pending_revision_id]', (int) ( $row['pending_revision_id'] ?? 0 ) ); self::hidden( 'snapshots[' . $id . '][approved_revision_id]', (int) ( $row['approved_revision_id'] ?? 0 ) );
				echo '</td><td><a href="' . esc_url( self::url( 'reviews', array( 'id' => $id ) ) ) . '">#' . $id . '</a><br>Skill #' . (int) $row['skill_id'] . '</td><td>' . esc_html( null === ( $row['user_id'] ?? null ) ? self::t( '已去标识化', 'De-identified' ) : self::t( '用户 #', 'User #' ) . (int) $row['user_id'] ) . '<br>' . esc_html( self::state( $row['state'] ) ) . '</td><td>';
				$this->revisionSummary( $row['approved'] ?? null ); echo '</td><td>'; $this->revisionSummary( $row['pending'] ?? null );
				echo '</td><td>' . esc_html( self::text( $row['updated_at'] ) ) . ' UTC</td></tr>';
			}
			if ( ! $result['items'] ) { echo '<tr><td colspan="6">' . esc_html( self::t( '没有符合条件的评价。', 'No reviews match these filters.' ) ) . '</td></tr>'; }
			echo '</tbody></table>'; self::confirmation( true ); echo '</form>'; $this->pagination( 'reviews', $result, $filters );
		} );
	}
	private function revisionSummary( mixed $revision ): void {
		if ( ! is_array( $revision ) ) { echo esc_html( self::t( '无', 'None' ) ); return; }
		echo esc_html( self::t( '修订 ', 'Revision ' ) . (int) $revision['revision'] . ' · ' . (int) $revision['rating'] . '/5 · ' . self::text( $revision['locale'] ) ) . '<br>' . esc_html( self::text( $revision['title'] ?? '' ) );
	}
	private function revision( ?array $row, string $title, bool $moderation = true ): void {
		echo '<section><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $row ) { echo '<p>' . esc_html( self::t( '没有此版本。', 'No version is available.' ) ) . '</p></section>'; return; }
		echo '<p>' . esc_html( self::t( '修订 ', 'Revision ' ) . (int) $row['revision'] . ' (#' . (int) $row['id'] . ') · ' . self::state( $row['moderation_state'] ) . ' · ' . self::text( $row['created_at'] ) . ' UTC' ) . '</p><table class="widefat striped"><tbody>';
		foreach ( array( 'title' => self::t( '标题', 'Title' ), 'rating' => self::t( '星级（1–5）', 'Rating (1–5)' ), 'comment' => self::t( '评论', 'Comment' ), 'experience' => self::t( '使用体验', 'Experience' ), 'pros' => self::t( '优点', 'Pros' ), 'cons' => self::t( '缺点', 'Cons' ), 'platform' => self::t( '使用平台（用户陈述）', 'Platform (author’s statement)' ), 'version_label' => self::t( '版本（用户陈述）', 'Version (author’s statement)' ), 'locale' => self::t( '原文语言', 'Original language' ) ) as $field => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><div dir="auto" style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( self::text( $row[ $field ] ?? '' ) ) . '</div></td></tr>';
		}
		if ( $moderation ) {
			echo '<tr><th scope="row">' . esc_html( self::t( '审核记录', 'Moderation' ) ) . '</th><td>' . esc_html( empty( $row['moderator_id'] ) ? self::t( '尚无审核人员', 'Not moderated yet' ) : self::t( '用户 #', 'User #' ) . (int) $row['moderator_id'] ) . '<br>' . esc_html( self::text( $row['moderated_at'] ?? '' ) ) . ' ' . ( empty( $row['moderated_at'] ) ? '' : 'UTC' ) . '<br>' . esc_html( self::reasons( 'moderation' )[ $row['moderation_code'] ?? '' ] ?? self::text( $row['moderation_code'] ?? '' ) ) . '</td></tr>';
			echo '<tr><th scope="row">' . esc_html( self::t( '内部审核备注', 'Internal moderator note' ) ) . '</th><td><div style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( self::text( $row['private_note'] ?? '' ) ) . '</div></td></tr>';
		}
		echo '</tbody></table></section>';
	}
	private function reviewDetail( int $id ): void {
		$historyPage = self::id( $_GET, 'history_page', 1 ); if ( $historyPage < 1 || $historyPage > 1000 ) { throw new \InvalidArgumentException( 'community_admin_page' ); }
		$row = $this->query->adminReview( $id, $historyPage );
		if ( ! $row ) { throw new \InvalidArgumentException( 'community_admin_missing' ); }
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html( self::t( '返回评价列表', 'Back to reviews' ) ) . '</a></p><h2>' . esc_html( self::t( '评价 #', 'Review #' ) . $id . ' · Skill #' . (int) $row['skill_id'] ) . '</h2><p>' . esc_html( self::state( $row['state'] ) . ' · ' . self::t( '锁定版本 ', 'Lock version ' ) . (int) $row['lock_version'] ) . '</p>';
		echo '<p>' . esc_html( self::t( '新稿审核期间，旧批准版本保持展示和计分。拒绝新稿不会覆盖旧版本；隐藏立即移除公开展示和评分贡献。', 'The approved version remains visible and contributes to ratings while edits await review. Rejecting an edit preserves that version; hiding removes its public display and rating contribution immediately.' ) ) . '</p>';
		$this->revision( $row['approved'] ?? null, self::t( '当前公开版本', 'Current published version' ) );
		$this->revision( $row['pending'] ?? null, self::t( '本次待审修订（私有）', 'Pending revision (private)' ) );
		if ( empty( $row['pending'] ) && isset( $row['latest'] ) && (int) $row['latest']['id'] !== (int) ( $row['approved_revision_id'] ?? 0 ) ) { $this->revision( $row['latest'], self::t( '最近修订记录', 'Latest revision record' ) ); }
		if ( ! empty( $row['pending_revision_id'] ) ) {
			self::form( 'review_decision' ); self::hidden( 'id', $id ); self::hidden( 'revision_id', (int) $row['pending_revision_id'] ); self::hidden( 'lock_version', (int) $row['lock_version'] );
			$this->decisionFields( false, (int) ( $row['user_id'] ?? 0 ) === get_current_user_id() ); self::confirmation(); echo '</form>';
		}
		if ( ! in_array( $row['state'], array( 'hidden', 'withdrawn' ), true ) ) {
			self::form( 'review_decision' ); self::hidden( 'id', $id ); self::hidden( 'revision_id', (int) ( $row['approved_revision_id'] ?? 0 ) ); self::hidden( 'lock_version', (int) $row['lock_version'] ); self::hidden( 'decision', 'hide' );
			echo '<h2>' . esc_html( self::t( '隐藏违规评价', 'Hide a policy-violating review' ) ) . '</h2>'; $this->noteFields( 'moderation', 'policy' ); self::confirmation(); echo '</form>';
		}
		$this->history( 'reviews', $id, $row['history'] ?? $this->query->history( 'review', $id, $historyPage ) );
	}
	private function decisionFields( bool $bulk, bool $own = false ): void {
		$options = $own ? array() : array( 'approve' => self::t( '批准当前待审稿', 'Approve current pending revision' ) );
		$options['reject'] = self::t( '拒绝当前待审稿', 'Reject current pending revision' );
		if ( $bulk ) { $options['hide'] = self::t( '隐藏评价', 'Hide review' ); }
		echo '<p>'; self::select( 'decision', $options, (string) array_key_first( $options ), self::t( '审核操作', 'Moderation action' ) ); echo '</p>';
		if ( $own ) { echo '<p>' . esc_html( self::t( '不能批准自己撰写的评价。', 'You cannot approve your own review.' ) ) . '</p>'; }
		$this->noteFields( 'moderation', $own ? 'other' : 'approved' );
	}
	private function noteFields( string $kind, string $default ): void {
		echo '<p>'; self::select( 'reason', self::reasons( $kind ), $default, self::t( '处理原因（固定反馈）', 'Reason (standard feedback)' ) ); echo '</p><p><label>' . esc_html( self::t( '内部备注（仅审核人员可见，不修改用户原文）', 'Internal note (moderators only; does not edit the author’s text)' ) ) . '<br><textarea class="large-text" name="private_note" rows="3" maxlength="2000"></textarea></label></p>';
		if ( 'moderation' === $kind ) { echo '<p class="description">' . esc_html( self::t( '批准使用“符合社区规范”反馈；拒绝或隐藏时请选择具体原因或“其他”。', 'Approval uses “Meets community guidelines”; select an applicable reason or “Other” when rejecting or hiding.' ) ) . '</p>'; }
	}
	private static function confirmation( bool $bulk = false ): void {
		echo '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . esc_html( $bulk ? self::t( '已查看选中记录的内容并确认执行此批次（最多 20 项）。', 'I have reviewed the selected records and confirm this batch (up to 20).' ) : self::t( '已核对所示版本，并确认执行此操作。', 'I have checked the displayed version and confirm this action.' ) ) . '</label></p><p><button class="button button-primary" type="submit">' . esc_html( self::t( '确认处理', 'Confirm action' ) ) . '</button></p>';
	}

	public function reviewReportsScreen(): void { $this->reportsScreen( 'review' ); }
	public function skillReportsScreen(): void { $this->reportsScreen( 'skill' ); }
	private function reportsScreen( string $kind ): void {
		$this->screen( self::REPORTS, 'review' === $kind ? self::t( '评价举报', 'Review Reports' ) : self::t( 'Skill 内容纠错', 'Skill Corrections' ), function () use ( $kind ): void {
			echo '<p>' . esc_html( self::t( '举报和纠错是私有案件，不能自动下架评价或 Skill。处理案件不等于隐藏评价，也不直接修改 Skill 内容；请在对应工作流进行必要操作。', 'Reports and corrections are private cases. They do not automatically hide a review or Skill. Resolving a case neither hides its subject nor edits Skill content; use the relevant workflow for those actions.' ) ) . '</p>';
			$id = self::id( $_GET, 'id' ); if ( $id ) { $this->reportDetail( $kind, $id ); return; }
			$filters = $this->filters( true ); if ( '' !== $filters['reason'] && ! isset( self::reasons( $kind )[ $filters['reason'] ] ) ) { throw new \InvalidArgumentException( 'community_admin_reason' ); }
			$result = $this->query->adminReports( $kind, $filters ); $this->filterForm( $kind, $filters );
			self::form( 'report_bulk', $kind ); echo '<p>'; self::select( 'decision', array( 'handled' => self::t( '标记已处理', 'Mark handled' ), 'closed' => self::t( '关闭案件', 'Close case' ) ), 'handled', self::t( '批量操作', 'Bulk action' ) ); echo '</p>'; $this->noteFields( 'resolution', 'resolved' );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::t( '选择', 'Select' ) ) . '</th><th>' . esc_html( self::t( '案件 / 对象', 'Case / Subject' ) ) . '</th><th>' . esc_html( self::t( '类型 / 语言', 'Reason / Language' ) ) . '</th><th>' . esc_html( self::t( '状态 / 举报人', 'State / Reporter' ) ) . '</th><th>' . esc_html( self::t( '创建时间', 'Created' ) ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $row ) {
				$id = (int) $row['id']; $reason = self::text( $row[ 'review' === $kind ? 'reason_code' : 'kind' ] );
				echo '<tr><td><input type="checkbox" name="ids[]" value="' . $id . '" aria-label="' . esc_attr( self::t( '选择案件 #', 'Select case #' ) . $id ) . '">'; self::hidden( 'snapshots[' . $id . '][state]', self::text( $row['state'] ) );
				echo '</td><td><a href="' . esc_url( self::url( $kind, array( 'id' => $id ) ) ) . '">#' . $id . '</a><br>' . esc_html( 'review' === $kind ? 'Review #' . (int) $row['review_id'] . ' · Revision #' . (int) $row['revision_id'] : 'Skill #' . (int) $row['skill_id'] . ' · Revision ' . (int) $row['skill_revision'] ) . '</td><td>' . esc_html( self::reasons( $kind )[ $reason ] ?? $reason ) . '<br>' . esc_html( self::text( $row['locale'] ) ) . '</td><td>' . esc_html( self::state( $row['state'] ) ) . '<br>' . esc_html( empty( $row['reporter_user_id'] ) ? self::t( '已去标识化', 'De-identified' ) : self::t( '用户 #', 'User #' ) . (int) $row['reporter_user_id'] ) . '</td><td>' . esc_html( self::text( $row['created_at'] ) ) . ' UTC</td></tr>';
			}
			if ( ! $result['items'] ) { echo '<tr><td colspan="5">' . esc_html( self::t( '没有符合条件的案件。', 'No cases match these filters.' ) ) . '</td></tr>'; }
			echo '</tbody></table>'; self::confirmation( true ); echo '</form>'; $this->pagination( $kind, $result, $filters );
		} );
	}
	private function reportDetail( string $kind, int $id ): void {
		$page = self::id( $_GET, 'history_page', 1 ); if ( $page < 1 || $page > 1000 ) { throw new \InvalidArgumentException( 'community_admin_page' ); }
		$row = $this->query->adminReport( $kind, $id, $page );
		if ( ! $row ) { throw new \InvalidArgumentException( 'community_admin_missing' ); }
		$reason = self::text( $row[ 'review' === $kind ? 'reason_code' : 'kind' ] );
		echo '<p><a href="' . esc_url( self::url( $kind ) ) . '">' . esc_html( self::t( '返回案件列表', 'Back to cases' ) ) . '</a></p><h2>' . esc_html( self::t( '案件 #', 'Case #' ) . $id ) . '</h2><p>' . esc_html( self::state( $row['state'] ) . ' · ' . ( self::reasons( $kind )[ $reason ] ?? $reason ) . ' · ' . self::text( $row['locale'] ) ) . '</p>';
		echo '<p>' . esc_html( 'review' === $kind ? 'Review #' . (int) $row['review_id'] . ' · Revision #' . (int) $row['revision_id'] : 'Skill #' . (int) $row['skill_id'] . ' · Revision ' . (int) $row['skill_revision'] ) . '</p>';
		if ( 'review' === $kind ) { $this->revision( $row['reported_revision'] ?? null, self::t( '被举报的原始修订（只读）', 'Reported original revision (read-only)' ), false ); }
		if ( 'review' === $kind && current_user_can( self::REVIEWS ) ) { echo '<p><a class="button" href="' . esc_url( self::url( 'reviews', array( 'id' => (int) $row['review_id'] ) ) ) . '">' . esc_html( self::t( '打开评价审核', 'Open review moderation' ) ) . '</a></p>'; }
		echo '<p>' . esc_html( empty( $row['reporter_user_id'] ) ? self::t( '举报人已去标识化', 'Reporter de-identified' ) : self::t( '举报用户 #', 'Reporting user #' ) . (int) $row['reporter_user_id'] ) . '</p><h3>' . esc_html( self::t( '提交说明（不可信用户内容）', 'Report details (untrusted user content)' ) ) . '</h3><div class="postbox" style="padding:12px;white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( self::text( $row['details'] ) ) . '</div>';
		echo '<h3>' . esc_html( self::t( '处理记录', 'Resolution' ) ) . '</h3><p>' . esc_html( ( self::reasons( 'resolution' )[ $row['resolution_code'] ?? '' ] ?? '' ) . ' · ' . ( empty( $row['handler_id'] ) ? '' : self::t( '用户 #', 'User #' ) . (int) $row['handler_id'] ) . ' · ' . self::text( $row['resolved_at'] ?? '' ) ) . '</p><p style="white-space:pre-wrap">' . esc_html( self::text( $row['private_note'] ?? '' ) ) . '</p>';
		if ( in_array( $row['state'], array( 'pending', 'reviewing' ), true ) ) {
			self::form( 'report_resolve', $kind ); self::hidden( 'id', $id ); self::hidden( 'expected_state', self::text( $row['state'] ) ); echo '<p>';
			$actions = 'pending' === $row['state'] ? array( 'reviewing' => self::t( '开始处理', 'Start review' ) ) : array();
			self::select( 'decision', $actions + array( 'handled' => self::t( '标记已处理', 'Mark handled' ), 'closed' => self::t( '关闭案件', 'Close case' ) ), 'handled', self::t( '操作', 'Action' ) ); echo '</p>'; $this->noteFields( 'resolution', 'resolved' ); self::confirmation(); echo '</form>';
		}
		$this->history( $kind, $id, $row['history'] ?? $this->query->history( 'review' === $kind ? 'review_report' : 'skill_report', $id, $page ) );
	}
	private function history( string $kind, int $id, array $history ): void {
		echo '<h2>' . esc_html( self::t( '审核与处理历史', 'Moderation history' ) ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html( self::t( '时间（UTC）', 'Time (UTC)' ) ) . '</th><th>' . esc_html( self::t( '修订 / 操作', 'Revision / Action' ) ) . '</th><th>' . esc_html( self::t( '状态变更 / 人员', 'State change / Actor' ) ) . '</th></tr></thead><tbody>';
		foreach ( $history['items'] ?? array() as $event ) {
			echo '<tr><td>' . esc_html( self::text( $event['created_at'] ?? '' ) ) . '</td><td>' . esc_html( self::text( $event['subject_revision'] ?? '' ) . ' · ' . self::text( $event['action'] ?? '' ) ) . '</td><td>' . esc_html( self::state( $event['from_state'] ?? '' ) . ' → ' . self::state( $event['to_state'] ?? '' ) ) . '<br>' . esc_html( empty( $event['actor_user_id'] ) ? self::t( '系统 / 已去标识化', 'System / De-identified' ) : self::t( '用户 #', 'User #' ) . (int) $event['actor_user_id'] ) . '</td></tr>';
		}
		if ( empty( $history['items'] ) ) { echo '<tr><td colspan="3">' . esc_html( self::t( '暂无历史记录。', 'No history yet.' ) ) . '</td></tr>'; }
		echo '</tbody></table>'; $this->pagination( $kind, $history, array(), 'history_page', array( 'id' => $id ) );
	}

	/** Parse all commands before writing; service rechecks capabilities and captured versions. */
	private function commands( array $post ): array {
		$operation = self::choice( $post, 'operation', array( 'review_decision', 'review_bulk', 'report_resolve', 'report_bulk' ) );
		$reviews = str_starts_with( $operation, 'review_' ); self::authorize( $reviews ? self::REVIEWS : self::REPORTS );
		$kind = $reviews ? 'reviews' : self::choice( $post, 'kind', array( 'review', 'skill' ) );
		if ( '1' !== self::input( $post, 'confirmed', '', 1 ) ) { throw new \InvalidArgumentException( 'community_admin_confirmation' ); }
		$key = self::input( $post, 'request_key', '', 128 );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,100}$/D', $key ) ) { throw new \InvalidArgumentException( 'community_admin_request_key' ); }
		$bulk = str_ends_with( $operation, '_bulk' );
		$decision = self::choice( $post, 'decision', $reviews ? array( 'approve', 'reject', 'hide' ) : ( $bulk ? array( 'handled', 'closed' ) : array( 'reviewing', 'handled', 'closed' ) ) );
		$reason = self::choice( $post, 'reason', array_keys( self::reasons( $reviews ? 'moderation' : 'resolution' ) ) );
		if ( $reviews && 'approve' === $decision ) { $reason = 'approved'; }
		elseif ( $reviews && 'approved' === $reason ) { throw new \InvalidArgumentException( 'community_admin_reason' ); }
		$note = self::input( $post, 'private_note', '', 8000 );
		if ( preg_match_all( '/./us', $note ) > 2000 ) { throw new \InvalidArgumentException( 'community_admin_note' ); }
		$ids = $bulk ? ( $post['ids'] ?? array() ) : array( self::input( $post, 'id', '', 20 ) );
		if ( ! is_array( $ids ) || count( $ids ) < 1 || count( $ids ) > self::LIMIT ) { throw new \InvalidArgumentException( 'community_admin_batch' ); }
		$commands = array(); $seen = array();
		foreach ( $ids as $raw ) {
			$id = self::id( array( 'id' => $raw ), 'id' );
			if ( $id < 1 || isset( $seen[ $id ] ) ) { throw new \InvalidArgumentException( 'community_admin_batch' ); }
			$seen[ $id ] = true;
			$input = $bulk ? ( $post['snapshots'][ $id ] ?? null ) : $post;
			if ( ! is_array( $input ) ) { throw new \InvalidArgumentException( 'community_admin_snapshot' ); }
			if ( $reviews ) {
				$version = self::id( $input, 'lock_version' );
				$revision = self::id( $input, $bulk ? ( 'hide' === $decision ? 'approved_revision_id' : 'pending_revision_id' ) : 'revision_id' );
				if ( $version < 1 || ( 'hide' !== $decision && $revision < 1 ) ) { throw new \InvalidArgumentException( 'community_admin_snapshot' ); }
				$commands[] = array( $id, $revision, $version, $decision, $reason, $note, $bulk ? $key . '_' . $id : $key );
			} else {
				$state = self::choice( $input, $bulk ? 'state' : 'expected_state', array( 'pending', 'reviewing' ) );
				$commands[] = array( $kind, $id, $decision, $reason, $note, $bulk ? $key . '_' . $id : $key, $state );
			}
		}
		return array( 'kind' => $kind, 'review' => $reviews, 'commands' => $commands, 'id' => $bulk ? 0 : (int) $ids[0] );
	}
	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html( self::t( '仅允许 POST 请求。', 'POST required.' ) ), '', array( 'response' => 405 ) ); }
		try { $nonce = self::input( $_POST, '_wpnonce', '', 64 ); } catch ( \Throwable $error ) { $nonce = ''; }
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) { wp_die( esc_html( self::t( '表单已失效，请重新打开。', 'The form expired. Please reload it.' ) ), '', array( 'response' => 403 ) ); }
		$notice = array( 'success' => 0, 'failed' => 0 ); $target = 'reviews'; $id = 0;
		if ( in_array( $_POST['operation'] ?? null, array( 'report_resolve', 'report_bulk' ), true ) && in_array( $_POST['kind'] ?? null, array( 'review', 'skill' ), true ) && current_user_can( self::REPORTS ) ) { $target = $_POST['kind']; }
		try {
			$plan = $this->commands( $_POST ); $target = $plan['kind']; $id = $plan['id'];
			foreach ( $plan['commands'] as $command ) {
				try {
					if ( $plan['review'] ) { $this->service->moderateReview( ...$command ); }
					else { $this->service->resolveReport( ...$command ); }
					++$notice['success'];
				} catch ( \Throwable $error ) { ++$notice['failed']; }
			}
		} catch ( \Throwable $error ) { $notice['failed'] = 1; }
		set_transient( 'aish_community_admin_notice_' . get_current_user_id(), $notice, 120 );
		wp_safe_redirect( self::url( $target, $id ? array( 'id' => $id ) : array() ), 303 ); exit;
	}
}
