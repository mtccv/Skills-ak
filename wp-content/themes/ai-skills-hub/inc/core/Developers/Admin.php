<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Developers;

use AISkillsHub\Core\Admin\ContributionUi as Ui;

defined( 'ABSPATH' ) || exit;

/** Public-profile review and evidence-based claims are distinct native workflows. */
final class Admin {
	public const PROFILES = 'aish_manage_developers';
	public const CLAIMS = 'aish_manage_author_claims';
	private const NONCE = 'aish_developer_admin';
	private const NOTICE = 'aish_developer_admin_notice_';
	private const SLUGS = array( 'profile' => 'aish-developers', 'claim' => 'aish-author-claims' );
	public function __construct( private Service $service, private Query $query ) {}
	public function register(): void {
		add_action( 'admin_menu', function (): void {
			Ui::menu( Ui::t( '开发者资料', 'Developer Profiles' ), self::PROFILES, self::SLUGS['profile'], array( $this, 'profilesScreen' ) );
			Ui::menu( Ui::t( '作者认领', 'Author Claims' ), self::CLAIMS, self::SLUGS['claim'], array( $this, 'claimsScreen' ) );
		} );
		add_action( 'admin_post_aish_developer_admin', array( $this, 'handle' ) );
	}
	private static function cap( string $kind ): string { return 'profile' === $kind ? self::PROFILES : self::CLAIMS; }
	private static function states( string $kind ): array {
		$states = array( 'pending' => Ui::t( '待审核', 'Pending' ), 'approved' => Ui::t( '已批准', 'Approved' ), 'rejected' => Ui::t( '已拒绝', 'Rejected' ) );
		return 'profile' === $kind ? $states + array( 'hidden' => Ui::t( '已隐藏', 'Hidden' ), 'draft' => Ui::t( '草稿', 'Draft' ), 'withdrawn' => Ui::t( '已撤回', 'Withdrawn' ) ) : $states + array( 'withdrawn' => Ui::t( '已撤回', 'Withdrawn' ), 'revoked' => Ui::t( '已撤销', 'Revoked' ), 'disputed' => Ui::t( '争议中', 'Disputed' ) );
	}
	private static function decisions( string $kind, bool $bulk ): array {
		$values = array( 'reject' => Ui::t( '拒绝当前申请', 'Reject this application' ) );
		if ( 'profile' === $kind ) { $values['hide'] = Ui::t( '隐藏公开资料', 'Hide public profile' ); }
		else { $values += array( 'revoke' => Ui::t( '撤销已核实身份', 'Revoke verified attribution' ), 'dispute' => Ui::t( '标记争议并停止公开身份', 'Mark disputed and stop public attribution' ) ); }
		if ( ! $bulk ) { $values['approve'] = Ui::t( '批准已核对的申请', 'Approve the inspected application' ); }
		return $values;
	}
	private static function available( string $kind, string $state ): array {
		if ( 'profile' === $kind ) { return 'pending' === $state ? array( 'approve', 'reject', 'hide' ) : array( 'hide' ); }
		return match ( $state ) { 'pending' => array( 'approve', 'reject' ), 'approved' => array( 'dispute', 'revoke' ), 'disputed' => array( 'revoke' ), default => array() };
	}
	public function profilesScreen(): void { $this->screen( 'profile' ); }
	public function claimsScreen(): void { $this->screen( 'claim' ); }
	private function screen( string $kind ): void {
		Ui::screen( self::cap( $kind ), 'profile' === $kind ? Ui::t( '开发者资料', 'Developer Profiles' ) : Ui::t( '作者认领', 'Author Claims' ), self::NOTICE, function () use ( $kind ): void {
			echo '<div class="notice notice-info"><p>' . esc_html( Ui::t( '公开资料审核、作者/维护者认领、用户投稿与 Skill 发布分别管理。批准身份不会授予编辑、采集、AI 或发布权限；仓库维护者和投稿者不自动成为原创作者。', 'Public profiles, author/maintainer claims, submissions and Skill publication are separate workflows. Attribution grants no editing, collection, AI or publishing capabilities. Maintainers and submitters are not automatically original authors.' ) ) . '</p></div>';
			$id = Ui::id( $_GET, 'id' );
			if ( $id ) { $this->detail( $kind, $id ); return; }
			$filter = Ui::filters( self::states( $kind ) );
			$result = 'profile' === $kind ? $this->query->adminProfiles( $filter ) : $this->query->adminClaims( $filter );
			Ui::filterForm( self::SLUGS[ $kind ], self::states( $kind ), $filter, 'claim' === $kind );
			echo '<p class="description">' . esc_html( Ui::t( '批量处理最多 20 项；批准必须逐项查看原始资料或证据。部分失败不会被标记为全部成功。', 'Bulk decisions handle at most 20 records. Approval requires individual inspection of original details or evidence. Partial failures are reported explicitly.' ) ) . '</p>';
			Ui::form( 'aish_developer_admin', self::NONCE, $kind, true );
			echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
			foreach ( array( Ui::t( '选择', 'Select' ), Ui::t( '编号 / 申请人', 'ID / Applicant' ), Ui::t( '资料 / 认领对象', 'Profile / Claim target' ), Ui::t( '状态', 'State' ), Ui::t( '更新时间 (UTC)', 'Updated (UTC)' ) ) as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
			echo '</tr></thead><tbody>';
			foreach ( $result['items'] as $row ) {
				$id = (int) ( 'profile' === $kind ? $row['user_id'] : $row['id'] );
				echo '<tr><td>';
				if ( self::available( $kind, $row['state'] ) ) {
					echo '<input type="checkbox" name="ids[]" value="' . $id . '" aria-label="' . esc_attr( Ui::t( '选择记录 #', 'Select record #' ) . $id ) . '">';
					if ( 'profile' === $kind ) { Ui::hidden( 'snapshots[' . $id . '][revision]', (int) $row['revision'] ); }
					else { Ui::hidden( 'snapshots[' . $id . '][expected_state]', $row['state'] ); }
				}
				echo '</td><td><a href="' . esc_url( Ui::url( self::SLUGS[ $kind ], array( 'id' => $id ) ) ) . '">#' . $id . '</a><br>' . esc_html( Ui::t( '用户 #', 'User #' ) . (int) ( $row['user_id'] ?? $row['claimant_user_id'] ?? 0 ) ) . '</td><td>';
				if ( 'profile' === $kind ) { echo esc_html( Ui::text( $row['draft']['handle'] ?? '' ) ) . '<br>r' . (int) $row['revision']; }
				else { echo 'Skill #' . (int) $row['skill_id'] . '<br>' . esc_html( self::role( $row['claim_role'] ) ); }
				echo '</td><td>' . esc_html( self::states( $kind )[ $row['state'] ] ?? $row['state'] ) . '</td><td>' . esc_html( Ui::text( $row['updated_at'] ?? '' ) ) . '</td></tr>';
			}
			if ( ! $result['items'] ) { echo '<tr><td colspan="5">' . esc_html( Ui::t( '没有符合条件的记录。', 'No records match these filters.' ) ) . '</td></tr>'; }
			echo '</tbody></table></div><p>'; Ui::select( 'decision', self::decisions( $kind, true ), 'reject', Ui::t( '批量操作', 'Bulk action' ) ); echo '</p>'; Ui::notes( 'claim' === $kind ? 10 : 5 ); Ui::confirm(); echo '</form>';
			Ui::pagination( self::SLUGS[ $kind ], $result, $filter );
		} );
	}
	private static function role( string $role ): string { return 'author' === $role ? Ui::t( '原创作者', 'Original Author' ) : ( 'maintainer' === $role ? Ui::t( '仓库维护者', 'Repository Maintainer' ) : Ui::t( '未知身份', 'Unknown role' ) ); }
	private function profile( ?array $snapshot, string $title ): void {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		if ( ! $snapshot ) { echo '<p>' . esc_html( Ui::t( '暂无此版本。', 'No version is available.' ) ) . '</p>'; return; }
		Ui::fields( $snapshot, array( 'handle' => Ui::t( '公开标识', 'Public handle' ), 'github_url' => Ui::t( 'GitHub 主页', 'GitHub profile' ), 'website_url' => Ui::t( '网站', 'Website' ), 'public_avatar' => Ui::t( '允许公开头像', 'Public avatar consent' ), 'show_contributions' => Ui::t( '允许展示公开贡献', 'Public contribution consent' ) ) );
		foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $locale => $label ) {
			echo '<h3>' . esc_html( $label ) . '</h3>';
			$translation = $snapshot['translations'][ $locale ] ?? null;
			if ( ! is_array( $translation ) || '' === trim( Ui::text( $translation['display_name'] ?? '' ) ) ) { echo '<p>' . esc_html( Ui::t( '未提供此语言内容，不生成虚假翻译。', 'This language has not been provided; no translation is fabricated.' ) ) . '</p>'; continue; }
			Ui::fields( $translation, array( 'display_name' => Ui::t( '公开昵称', 'Public display name' ), 'bio' => Ui::t( '简介', 'Biography' ), 'seo_title' => 'SEO Title', 'seo_description' => 'Meta Description' ) );
		}
	}
	private function avatar( mixed $preview, string $label ): void {
		if ( ! is_string( $preview ) || strlen( $preview ) > 1500000 || ! preg_match( '~^data:image/png;base64,[A-Za-z0-9+/]+=*$~D', $preview ) ) { return; }
		echo '<p>' . esc_html( $label ) . '<br><img src="' . esc_attr( $preview ) . '" alt="' . esc_attr( $label ) . '" width="96" height="96"></p>';
	}
	private function detail( string $kind, int $id ): void {
		$page = Ui::page( $_GET, 'history_page' ); $row = 'profile' === $kind ? $this->query->adminProfile( $id, $page ) : $this->query->adminClaim( $id, $page );
		if ( ! $row ) { throw new \InvalidArgumentException( 'developer_admin_missing' ); }
		echo '<p><a href="' . esc_url( Ui::url( self::SLUGS[ $kind ] ) ) . '">' . esc_html( Ui::t( '返回列表', 'Back to list' ) ) . '</a></p><h2>#' . $id . ' · ' . esc_html( self::states( $kind )[ $row['state'] ] ?? $row['state'] ) . '</h2>';
		if ( 'profile' === $kind ) {
			echo '<p>' . esc_html( Ui::t( '资料仅由本人编辑；批准快照与新待审资料独立。退出公开展示或隐藏立即生效。', 'Only the owner edits profile content. The approved snapshot is separate from pending edits. Opting out or hiding takes effect immediately.' ) ) . '</p>';
			Ui::fields( $row, array( 'opt_in' => Ui::t( '当前公开同意', 'Current public opt-in' ), 'revision' => Ui::t( '待审修订', 'Draft revision' ) ) );
			$this->profile( $row['approved'] ?? null, Ui::t( '已批准快照（不保证当前可公开）', 'Approved snapshot (current public eligibility still applies)' ) );
			$this->avatar( $row['approved_avatar_preview'] ?? null, Ui::t( '已批准头像', 'Approved avatar' ) );
			$this->profile( $row['draft'] ?? null, Ui::t( '用户原始待审资料（只读）', 'Original pending profile (read-only)' ) );
			$this->avatar( $row['avatar_preview'] ?? null, Ui::t( '待审头像', 'Pending avatar' ) );
		} else {
			echo '<h2>' . esc_html( Ui::t( '认领与证据（只读）', 'Claim and evidence (read-only)' ) ) . '</h2>';
			$values = $row; $values['role_label'] = self::role( $row['claim_role'] );
			Ui::fields( $values, array( 'skill_id' => 'Skill ID', 'claimant_user_id' => Ui::t( '申请人 ID', 'Applicant ID' ), 'role_label' => Ui::t( '声明身份', 'Claimed role' ), 'repository_id' => 'Repository ID', 'source_path' => Ui::t( '绑定来源路径', 'Bound source path' ), 'source_revision' => Ui::t( '绑定 Skill 修订', 'Bound Skill revision' ), 'proof_kind' => Ui::t( '证明类型', 'Evidence type' ), 'created_at' => Ui::t( '申请时间 (UTC)', 'Applied (UTC)' ), 'reviewer_id' => Ui::t( '审核人 ID', 'Reviewer ID' ), 'reviewed_at' => Ui::t( '审核时间 (UTC)', 'Reviewed (UTC)' ) ) );
			Ui::fields( $row['proof'] ?? array(), array( 'reason' => Ui::t( '认领理由', 'Claim reason' ), 'evidence_urls' => Ui::t( '证据地址（仅文字，不自动访问）', 'Evidence URLs (text only; never fetched automatically)' ), 'locale' => Ui::t( '原文语言', 'Original language' ) ) );
			echo '<p class="description">' . esc_html( Ui::t( '审核人员须独立核验证据。仓库控制仅支持维护者身份，原创作者另需署名或创作授权依据。系统不会访问证据网址或执行内容。争议和撤销停止公开身份，但不删除 Skill。', 'Verify evidence independently. Repository control supports a maintainer claim; original authorship requires attribution or creation-rights evidence. The system does not fetch evidence URLs or execute their content. Disputes and revocations stop public attribution without deleting the Skill.' ) ) . '</p>';
		}
		Ui::fields( $row, array( 'feedback' => Ui::t( '给申请人的反馈', 'Feedback for applicant' ), 'private_note' => Ui::t( '内部备注', 'Internal note' ) ) );
		$decisionsPage = $row['decisions'] ?? array();
		foreach ( ( 'profile' === $kind ? ( $decisionsPage['items'] ?? array() ) : $decisionsPage ) as $index => $record ) {
			echo '<details><summary>' . esc_html( Ui::t( '审核证据记录 #', 'Review evidence record #' ) . ( $index + 1 ) ) . '</summary>';
			Ui::fields( $record, array( 'revision' => Ui::t( '修订', 'Revision' ), 'decision' => Ui::t( '处理结果', 'Decision' ), 'reason' => Ui::t( '给申请人的反馈', 'Feedback for applicant' ), 'private_note' => Ui::t( '内部备注', 'Internal note' ), 'reviewer_id' => Ui::t( '审核人 ID', 'Reviewer ID' ), 'at' => Ui::t( '时间 (UTC)', 'Time (UTC)' ) ) );
			if ( isset( $record['verification'] ) && is_array( $record['verification'] ) ) { Ui::fields( $record['verification'], array( 'evidence_checked' => Ui::t( '人工证据核验', 'Evidence checked' ), 'role_checked' => Ui::t( '身份依据核验', 'Role checked' ), 'source_checked' => Ui::t( '来源绑定核验', 'Source checked' ) ) ); }
			if ( 'profile' === $kind ) { $this->profile( $record['snapshot'] ?? null, Ui::t( '当时受审原稿', 'Original draft inspected for this decision' ) ); }
			echo '</details>';
		}
		if ( 'profile' === $kind && isset( $decisionsPage['items'] ) ) { Ui::pagination( self::SLUGS[ $kind ], $decisionsPage, array(), 'history_page', array( 'id' => $id ) ); }
		$decisions = array_intersect_key( self::decisions( $kind, false ), array_flip( self::available( $kind, $row['state'] ) ) );
		if ( 'profile' === $kind && (int) $row['revision'] < 1 ) { $decisions = array(); }
		if ( ! $decisions ) { Ui::history( self::SLUGS[ $kind ], $id, $row['history'] ?? array() ); return; }
		Ui::form( 'aish_developer_admin', self::NONCE, $kind ); Ui::hidden( 'id', $id );
		if ( 'profile' === $kind ) { Ui::hidden( 'revision', (int) $row['revision'] ); }
		else { Ui::hidden( 'expected_state', $row['state'] ); }
		if ( (int) ( $row['user_id'] ?? $row['claimant_user_id'] ?? 0 ) === get_current_user_id() ) { unset( $decisions['approve'] ); echo '<p>' . esc_html( Ui::t( '不能批准自己的公开资料或认领。', 'You cannot approve your own profile or claim.' ) ) . '</p>'; }
		echo '<h2>' . esc_html( Ui::t( '处理当前记录', 'Decide on this record' ) ) . '</h2><p>'; Ui::select( 'decision', $decisions, (string) array_key_first( $decisions ), Ui::t( '处理结果', 'Decision' ) ); echo '</p>';
		if ( 'claim' === $kind && isset( $decisions['approve'] ) ) {
			echo '<fieldset><legend>' . esc_html( Ui::t( '批准认领前必须完成以下三项核验', 'All three checks are required before approving a claim' ) ) . '</legend>';
			foreach ( array( 'evidence_checked' => Ui::t( '已独立检查可核实证据。', 'I independently checked verifiable evidence.' ), 'role_checked' => Ui::t( '证据支持所声明的作者或维护者身份。', 'The evidence supports the claimed author or maintainer role.' ), 'source_checked' => Ui::t( '已核对当前 Skill、仓库、路径及绑定修订。', 'I checked the current Skill, repository, path and bound revision.' ) ) as $field => $label ) { echo '<p><label><input type="checkbox" name="verification[' . esc_attr( $field ) . ']" value="1"> ' . esc_html( $label ) . '</label></p>'; }
			echo '</fieldset>';
		}
		Ui::notes( 'claim' === $kind ? 10 : 5 ); Ui::confirm(); echo '</form>'; Ui::history( self::SLUGS[ $kind ], $id, $row['history'] ?? array() );
	}
	private function commands( array $post ): array {
		$kind = Ui::choice( $post, 'kind', array_keys( self::SLUGS ) ); Ui::authorize( self::cap( $kind ) );
		$key = Ui::request( $post ); $bulk = '1' === Ui::choice( $post, 'bulk', array( '0', '1' ), '0' );
		$decision = Ui::choice( $post, 'decision', array_keys( self::decisions( $kind, $bulk ) ) );
		$feedback = Ui::input( $post, 'feedback' ); $note = Ui::input( $post, 'private_note' );
		if ( preg_match_all( '/./us', trim( $feedback ) ) < ( 'claim' === $kind ? 10 : 5 ) ) { throw new \InvalidArgumentException( 'developer_admin_reason' ); }
		$verification = array();
		if ( 'claim' === $kind && 'approve' === $decision ) {
			if ( ! is_array( $post['verification'] ?? null ) ) { throw new \InvalidArgumentException( 'developer_admin_verification' ); }
			foreach ( array( 'evidence_checked', 'role_checked', 'source_checked' ) as $check ) {
				if ( '1' !== Ui::input( $post['verification'], $check, '', 1 ) ) { throw new \InvalidArgumentException( 'developer_admin_verification' ); }
				$verification[ $check ] = true;
			}
		}
		$ids = Ui::ids( $post, $bulk ); $commands = array();
		if ( $bulk && ! is_array( $post['snapshots'] ?? null ) ) { throw new \InvalidArgumentException( 'developer_admin_snapshot' ); }
		foreach ( $ids as $id ) {
			$snapshot = $bulk ? ( $post['snapshots'][ $id ] ?? null ) : $post;
			if ( ! is_array( $snapshot ) ) { throw new \InvalidArgumentException( 'developer_admin_snapshot' ); }
			$request = $bulk ? $key . '_' . $id : $key;
			if ( 'profile' === $kind ) {
				$revision = Ui::id( $snapshot, 'revision' );
				if ( $revision < 1 ) { throw new \InvalidArgumentException( 'developer_admin_revision' ); }
				$commands[] = array( $id, $revision, $decision, $feedback, $note, $request );
			} else {
				$state = Ui::choice( $snapshot, 'expected_state', array( 'pending', 'approved', 'disputed' ) );
				if ( ! in_array( $decision, self::available( 'claim', $state ), true ) ) { throw new \InvalidArgumentException( 'developer_admin_state' ); }
				$commands[] = array( $id, $state, $decision, $feedback, $note, $request, $verification );
			}
		}
		return array( 'kind' => $kind, 'id' => $bulk ? 0 : $ids[0], 'commands' => $commands );
	}
	public function handle(): void {
		Ui::post( self::NONCE ); $notice = array( 'success' => 0, 'failed' => 0 ); $kind = 'profile'; $id = 0;
		if ( 'claim' === ( $_POST['kind'] ?? null ) && current_user_can( self::CLAIMS ) ) { $kind = 'claim'; }
		try {
			$plan = $this->commands( $_POST ); $kind = $plan['kind']; $id = $plan['id'];
			foreach ( $plan['commands'] as $command ) {
				try {
					if ( 'profile' === $kind ) { $this->service->moderateProfile( ...$command ); }
					else { $this->service->moderateClaim( ...$command ); }
					++$notice['success'];
				} catch ( \Throwable $error ) { ++$notice['failed']; }
			}
		} catch ( \Throwable $error ) { $notice['failed'] = 1; }
		set_transient( self::NOTICE . get_current_user_id(), $notice, 120 );
		wp_safe_redirect( Ui::url( self::SLUGS[ $kind ], $id ? array( 'id' => $id ) : array() ), 303 ); exit;
	}
}
