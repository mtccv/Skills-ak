<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Submissions;

use AISkillsHub\Core\Admin\ContributionUi as Ui;

defined( 'ABSPATH' ) || exit;

/** Independent native queues. Acceptance never publishes or rewrites a Skill. */
final class Admin {
	public const SUBMISSIONS = 'aish_manage_submissions';
	public const UPDATES = 'aish_manage_update_suggestions';
	private const NONCE = 'aish_submission_admin';
	private const NOTICE = 'aish_submission_admin_notice_';
	private const SLUGS = array( 'submit' => 'aish-submissions', 'update_suggestion' => 'aish-update-suggestions' );
	public function __construct( private Service $service, private Query $query ) {}
	public function register(): void {
		add_action( 'admin_menu', function (): void {
			Ui::menu( Ui::t( '用户投稿', 'User Submissions' ), self::SUBMISSIONS, self::SLUGS['submit'], array( $this, 'submissionsScreen' ) );
			Ui::menu( Ui::t( '版本更新建议', 'Update Suggestions' ), self::UPDATES, self::SLUGS['update_suggestion'], array( $this, 'updatesScreen' ) );
		} );
		add_action( 'admin_post_aish_submission_admin', array( $this, 'handle' ) );
	}
	private static function cap( string $kind ): string { return 'submit' === $kind ? self::SUBMISSIONS : self::UPDATES; }
	private static function states(): array {
		return array( 'draft' => Ui::t( '草稿', 'Draft' ), 'submitted' => Ui::t( '待审核', 'Pending' ), 'in_review' => Ui::t( '审核中', 'Reviewing' ), 'needs_changes' => Ui::t( '需要补充资料', 'Needs Information' ), 'accepted' => Ui::t( '已接受', 'Accepted' ), 'rejected' => Ui::t( '已拒绝', 'Rejected' ), 'withdrawn' => Ui::t( '已撤回', 'Withdrawn' ) );
	}
	private static function decisions( bool $bulk ): array {
		$values = array( 'reviewing' => Ui::t( '开始审核', 'Start review' ), 'needs_information' => Ui::t( '要求补充资料', 'Request information' ), 'reject' => Ui::t( '拒绝建议', 'Reject proposal' ) );
		if ( ! $bulk ) { $values['accept'] = Ui::t( '接受进入后续编辑流程', 'Accept for the editorial workflow' ); $values['link_result'] = Ui::t( '更新已接受投稿的结果关联', 'Update accepted proposal associations' ); }
		return $values;
	}
	private static function payloadLabels(): array {
		return array( 'name' => Ui::t( 'Skill 名称', 'Skill name' ), 'summary' => Ui::t( '简介 / 变更摘要', 'Summary / Change summary' ), 'notes' => Ui::t( '投稿 / 更新说明', 'Proposal / Update notes' ), 'locale' => Ui::t( '原文语言', 'Original language' ), 'source_kind' => Ui::t( '来源类型', 'Source type' ), 'github_url' => 'GitHub URL', 'source_path' => Ui::t( '建议路径（空值为待确认）', 'Suggested path (blank means unconfirmed)' ), 'categories' => Ui::t( '分类建议', 'Category suggestions' ), 'platforms' => Ui::t( '平台建议', 'Platform suggestions' ), 'license' => 'License', 'target_skill_id' => 'Skill ID', 'target_skill_revision' => Ui::t( '目标修订', 'Target revision' ), 'commit_sha' => 'Commit SHA', 'evidence' => Ui::t( '证据（仅文字，不自动访问）', 'Evidence (text only; never fetched automatically)' ) );
	}
	public function submissionsScreen(): void { $this->screen( 'submit' ); }
	public function updatesScreen(): void { $this->screen( 'update_suggestion' ); }
	private function screen( string $kind ): void {
		Ui::screen( self::cap( $kind ), 'submit' === $kind ? Ui::t( '用户投稿', 'User Submissions' ) : Ui::t( '版本更新建议', 'Update Suggestions' ), self::NOTICE, function () use ( $kind ): void {
			echo '<div class="notice notice-info"><p>' . esc_html( Ui::t( '“已接受”仅表示进入后续编辑、采集或关联流程，不代表 Skill 已发布。此处不会扫描 GitHub、调用 AI、修改已审核译文或授予用户编辑权限。', 'Accepted means accepted for later editing, collection or association; it does not mean a Skill is published. This screen does not scan GitHub, call AI, alter approved translations or grant editing permissions.' ) ) . '</p></div>';
			$id = Ui::id( $_GET, 'id' );
			if ( $id ) { $this->detail( $kind, $id ); return; }
			$filters = Ui::filters( self::states(), 'submitted' ); $result = $this->query->adminList( $kind, $filters );
			Ui::filterForm( self::SLUGS[ $kind ], self::states(), $filters );
			echo '<p class="description">' . esc_html( Ui::t( '批量操作最多 20 项，各项按所见修订独立处理。接受和关联资源必须逐项检查。Contact 与历史匿名 Inbox 保留独立管理。', 'Bulk decisions handle up to 20 records independently using the captured revisions. Acceptance and resource association require individual inspection. Contact and legacy anonymous Inbox records remain separate.' ) ) . '</p>';
			Ui::form( 'aish_submission_admin', self::NONCE, $kind, true );
			echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
			foreach ( array( Ui::t( '选择', 'Select' ), Ui::t( '编号 / 名称', 'ID / Name' ), Ui::t( '投稿人', 'Submitter' ), Ui::t( '状态 / 修订', 'State / Revision' ), Ui::t( '来源 / 路径', 'Source / Path' ), Ui::t( '更新时间 (UTC)', 'Updated (UTC)' ) ) as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
			echo '</tr></thead><tbody>';
			foreach ( $result['items'] as $row ) {
				$id = (int) $row['id'];
				echo '<tr><td>';
				if ( in_array( $row['state'], array( 'submitted', 'in_review' ), true ) && (int) $row['user_id'] !== get_current_user_id() ) { echo '<input type="checkbox" name="ids[]" value="' . $id . '" aria-label="' . esc_attr( Ui::t( '选择投稿 #', 'Select proposal #' ) . $id ) . '">'; Ui::hidden( 'snapshots[' . $id . '][revision]', (int) $row['revision'] ); }
				echo '</td><td><a href="' . esc_url( Ui::url( self::SLUGS[ $kind ], array( 'id' => $id ) ) ) . '">#' . $id . ' ' . esc_html( Ui::text( $row['name'] ?? '' ) ) . '</a><br>' . esc_html( Ui::text( $row['locale'] ?? '' ) ) . '</td><td>#' . (int) ( $row['user_id'] ?? 0 ) . '</td><td>' . esc_html( self::states()[ $row['state'] ] ?? $row['state'] ) . '<br>r' . (int) $row['revision'] . '</td><td>' . esc_html( Ui::text( $row['source']['canonical_repository'] ?? $row['payload']['canonical_repository'] ?? '' ) ) . '<br>' . esc_html( Ui::text( $row['source']['source_path'] ?? $row['payload']['source_path'] ?? Ui::t( '路径待确认 / 无 GitHub 来源', 'Path unconfirmed / no GitHub source' ) ) ) . '</td><td>' . esc_html( Ui::text( $row['updated_at'] ?? '' ) ) . '</td></tr>';
			}
			if ( ! $result['items'] ) { echo '<tr><td colspan="6">' . esc_html( Ui::t( '没有符合条件的投稿。', 'No proposals match these filters.' ) ) . '</td></tr>'; }
			echo '</tbody></table></div><p>'; Ui::select( 'decision', self::decisions( true ), 'reviewing', Ui::t( '批量操作', 'Bulk action' ) ); echo '</p>'; Ui::notes(); Ui::confirm(); echo '</form>';
			Ui::pagination( self::SLUGS[ $kind ], $result, $filters );
		} );
	}
	private function detail( string $kind, int $id ): void {
		$row = $this->query->adminDetail( $kind, $id, Ui::page( $_GET, 'history_page' ) );
		if ( ! $row || $row['kind'] !== $kind ) { throw new \InvalidArgumentException( 'submission_admin_missing' ); }
		echo '<p><a href="' . esc_url( Ui::url( self::SLUGS[ $kind ] ) ) . '">' . esc_html( Ui::t( '返回列表', 'Back to list' ) ) . '</a></p><h2>#' . $id . ' · ' . esc_html( self::states()[ $row['state'] ] ?? $row['state'] ) . ' · r' . (int) $row['revision'] . '</h2>';
		echo '<h2>' . esc_html( Ui::t( '用户原始内容（只读）', 'Author’s original proposal (read-only)' ) ) . '</h2>';
		$payload = ( $row['payload'] ?? array() ) + $row;
		Ui::fields( $payload, self::payloadLabels() );
		echo '<h2>' . esc_html( Ui::t( '来源检查', 'Source inspection' ) ) . '</h2>';
		Ui::fields( $row['source'] ?? array(), array( 'canonical_repository' => Ui::t( '规范仓库身份', 'Canonical repository' ), 'repository_id' => Ui::t( '内部 Repository ID', 'Internal repository ID' ), 'source_path' => Ui::t( '规范路径', 'Canonical path' ), 'path_status' => Ui::t( '路径状态', 'Path state' ), 'existing_skill_ids' => Ui::t( '已有来源 Skill ID', 'Existing source Skill IDs' ) ) );
		foreach ( $row['source']['risk_summary'] ?? array() as $risk ) {
			echo '<h3>' . esc_html( Ui::t( '已记录的来源审核信息 · Skill #', 'Stored source review information · Skill #' ) . (int) $risk['skill_id'] ) . '</h3>';
			Ui::fields( $risk, array( 'status' => Ui::t( '资源状态', 'Skill state' ), 'review_status' => Ui::t( '内容审核状态', 'Content review state' ), 'risk_level' => Ui::t( '已记录风险等级', 'Stored risk level' ) ) );
		}
		if ( empty( $row['source']['risk_summary'] ) ) { echo '<p>' . esc_html( Ui::t( '暂无匹配来源的风险检查记录；风险未知，待核实。', 'No risk inspection record exists for a matching source. Risk is unknown and needs verification.' ) ) . '</p>'; }
		echo '<p class="description">' . esc_html( Ui::t( '来源规范化不是远程有效性或安全认证；未知路径、License、安装说明和风险仍须人工核实。接受不会修改任何 Skill 版本快照。', 'Source normalization is not a remote validity or safety certification. Unknown paths, licenses, installation instructions and risks still need manual verification. Acceptance does not change any Skill version snapshot.' ) ) . '</p>';
		Ui::fields( $row, array( 'feedback' => Ui::t( '给申请人的反馈', 'Feedback for applicant' ), 'private_note' => Ui::t( '内部备注', 'Internal note' ), 'result_skill_ids' => Ui::t( '关联 Skill ID（不表示公开）', 'Associated Skill IDs (not a publication status)' ) ) );
		if ( (int) $row['user_id'] === get_current_user_id() ) { echo '<p>' . esc_html( Ui::t( '不能审核自己的投稿或更新建议。', 'You cannot moderate your own submission or update suggestion.' ) ) . '</p>'; }
		if ( in_array( $row['state'], array( 'submitted', 'in_review', 'accepted' ), true ) && (int) $row['user_id'] !== get_current_user_id() ) {
			Ui::form( 'aish_submission_admin', self::NONCE, $kind ); Ui::hidden( 'id', $id ); Ui::hidden( 'revision', (int) $row['revision'] );
			$accepted = 'accepted' === $row['state'];
			$decisions = self::decisions( false ); if ( $accepted ) { $decisions = array( 'link_result' => $decisions['link_result'] ); } else { unset( $decisions['link_result'] ); } if ( 'in_review' === $row['state'] ) { unset( $decisions['reviewing'] ); }
			echo '<h2>' . esc_html( Ui::t( '审核当前修订', 'Review this revision' ) ) . '</h2><p>'; Ui::select( 'decision', $decisions, (string) array_key_first( $decisions ), Ui::t( '处理结果', 'Decision' ) ); echo '</p>';
			echo '<p><label>' . esc_html( Ui::t( '关联已有 Skill ID（可选，逗号分隔，最多 20 项）', 'Existing Skill IDs to associate (optional, comma-separated, up to 20)' ) ) . '<br><input class="large-text" type="text" name="result_skill_ids" maxlength="419" value="' . esc_attr( $accepted ? implode( ',', array_map( 'intval', $row['result_skill_ids'] ?? array() ) ) : '' ) . '"></label></p>';
			if ( $accepted ) { echo '<p class="description">' . esc_html( Ui::t( '此操作仅替换结果关联；留空可清除关联。投稿仍为已接受，不改变 Skill 内容或发布状态。用户只会看到当前允许公开的关联资源。', 'This only replaces result associations; an empty list removes them. The proposal remains accepted, and Skill content/publication is unchanged. Users see only currently public associated resources.' ) ) . '</p>'; }
			Ui::notes( $accepted ? 0 : 5 ); Ui::confirm(); echo '</form>';
		}
		Ui::history( self::SLUGS[ $kind ], $id, $row['history'] ?? array() );
		if ( isset( $row['snapshots'] ) ) {
			echo '<h2>' . esc_html( Ui::t( '原始投稿修订', 'Original proposal revisions' ) ) . '</h2>';
			foreach ( $row['snapshots']['items'] ?? array() as $snapshot ) {
				$moderation = 'moderation' === ( $snapshot['kind'] ?? '' );
				echo '<details><summary>' . esc_html( ( $moderation ? Ui::t( '处理快照 #', 'Decision snapshot #' ) : Ui::t( '原稿快照 #', 'Original snapshot #' ) ) . (int) $snapshot['id'] . ' · r' . (int) ( $snapshot['revision'] ?? 0 ) . ' · ' . Ui::text( $snapshot['created_at'] ?? '' ) . ' UTC' ) . '</summary>';
				Ui::fields( $snapshot['payload'] ?? array(), $moderation ? array( 'decision' => Ui::t( '处理结果', 'Decision' ), 'feedback' => Ui::t( '给申请人的反馈', 'Feedback for applicant' ), 'private_note' => Ui::t( '内部备注', 'Internal note' ), 'handler_id' => Ui::t( '处理人 ID', 'Moderator ID' ), 'revision' => Ui::t( '修订', 'Revision' ), 'result_skill_ids' => Ui::t( '当时关联 Skill ID', 'Skill IDs associated by this decision' ) ) : self::payloadLabels() ); echo '</details>';
			}
			Ui::pagination( self::SLUGS[ $kind ], $row['snapshots'], array(), 'history_page', array( 'id' => $id ) );
		}
	}
	/** Validate an entire batch before dispatch; the service then rechecks each locked record. */
	private function commands( array $post ): array {
		$kind = Ui::choice( $post, 'kind', array_keys( self::SLUGS ) ); Ui::authorize( self::cap( $kind ) );
		$key = Ui::request( $post ); $bulk = '1' === Ui::choice( $post, 'bulk', array( '0', '1' ), '0' );
		$decision = Ui::choice( $post, 'decision', array_keys( self::decisions( $bulk ) ) );
		$feedback = Ui::input( $post, 'feedback' ); $note = Ui::input( $post, 'private_note' );
		if ( 'link_result' !== $decision && preg_match_all( '/./us', trim( $feedback ) ) < 5 ) { throw new \InvalidArgumentException( 'submission_admin_feedback' ); }
		$raw = Ui::input( $post, 'result_skill_ids', '', 419 ); $results = array();
		if ( '' !== trim( $raw ) ) {
			if ( $bulk || ! in_array( $decision, array( 'accept', 'link_result' ), true ) ) { throw new \InvalidArgumentException( 'submission_admin_association' ); }
			foreach ( explode( ',', $raw ) as $value ) { $results[] = trim( $value ); }
			$results = Ui::ids( array( 'ids' => $results ), true );
		}
		$ids = Ui::ids( $post, $bulk ); $commands = array();
		if ( $bulk && ! is_array( $post['snapshots'] ?? null ) ) { throw new \InvalidArgumentException( 'submission_admin_snapshot' ); }
		foreach ( $ids as $id ) {
			$snapshot = $bulk ? ( $post['snapshots'][ $id ] ?? null ) : $post;
			if ( ! is_array( $snapshot ) ) { throw new \InvalidArgumentException( 'submission_admin_snapshot' ); }
			$revision = Ui::id( $snapshot, 'revision' );
			if ( $revision < 1 ) { throw new \InvalidArgumentException( 'submission_admin_revision' ); }
			$commands[] = array( $id, $revision, $decision, $feedback, $note, $results, $bulk ? $key . '_' . $id : $key );
		}
		return array( 'kind' => $kind, 'id' => $bulk ? 0 : $ids[0], 'commands' => $commands );
	}
	public function handle(): void {
		Ui::post( self::NONCE ); $notice = array( 'success' => 0, 'failed' => 0 ); $kind = 'submit'; $id = 0;
		if ( 'update_suggestion' === ( $_POST['kind'] ?? null ) && current_user_can( self::UPDATES ) ) { $kind = 'update_suggestion'; }
		try {
			$plan = $this->commands( $_POST ); $kind = $plan['kind']; $id = $plan['id'];
			foreach ( $plan['commands'] as $command ) {
				try {
					// The URL's queue kind cannot be used to operate on the other capability's records.
					$record = $this->query->adminDetail( $kind, $command[0] );
					if ( ! $record || $record['kind'] !== $kind ) { throw new \InvalidArgumentException( 'submission_admin_kind' ); }
					$this->service->moderate( ...$command ); ++$notice['success'];
				} catch ( \Throwable $error ) { ++$notice['failed']; }
			}
		} catch ( \Throwable $error ) { $notice['failed'] = 1; }
		set_transient( self::NOTICE . get_current_user_id(), $notice, 120 );
		wp_safe_redirect( Ui::url( self::SLUGS[ $kind ], $id ? array( 'id' => $id ) : array() ), 303 ); exit;
	}
}
