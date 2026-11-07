<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Admin\ContributionUi as Ui;
use AISkillsHub\Core\Community;
use AISkillsHub\Core\Developers;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Submissions;

defined( 'ABSPATH' ) || exit;

/** One navigation surface; each domain remains the authority for reads and writes. */
final class Workbench {
	public const CAP = 'aish_view_operations';
	public const LIMIT = 10;
	private const QUEUES = array(
		'submissions' => array( 'aish_manage_submissions', 'aish-submissions', 'submitted' ),
		'reviews' => array( 'aish_moderate_community', 'aish-community-reviews', 'pending' ),
		'review_reports' => array( 'aish_manage_community_reports', 'aish-community-review-reports', 'pending' ),
		'skill_reports' => array( 'aish_manage_community_reports', 'aish-community-skill-reports', 'pending' ),
		'claims' => array( 'aish_manage_author_claims', 'aish-author-claims', 'pending' ),
		'updates' => array( 'aish_manage_update_suggestions', 'aish-update-suggestions', 'submitted' ),
	);
	public function __construct( private Submissions\Query $submissionQuery, private Submissions\Service $submissions, private Community\Query $communityQuery, private Community\Service $community, private Developers\Query $developerQuery, private Developers\Service $developers ) {}
	public static function label( string $type ): string {
		return match ( $type ) { 'submissions' => Ui::t( '用户投稿', 'User submissions' ), 'reviews' => Ui::t( '社区评价与修订', 'Community reviews and revisions' ), 'review_reports' => Ui::t( '评价举报', 'Review reports' ), 'skill_reports' => Ui::t( 'Skill 纠错', 'Skill corrections' ), 'claims' => Ui::t( '作者认领', 'Author claims' ), 'updates' => Ui::t( '版本更新建议', 'Update suggestions' ), default => throw new \InvalidArgumentException( 'invalid_queue' ) };
	}
	public static function available(): array {
		if ( ! current_user_can( self::CAP ) ) { return array(); }
		$result = array(); foreach ( self::QUEUES as $type => $config ) { if ( current_user_can( $config[0] ) ) { $result[ $type ] = self::label( $type ); } } return $result;
	}
	public static function authorize( string $type ): void {
		if ( ! isset( self::QUEUES[ $type ] ) || ! current_user_can( self::CAP ) || ! current_user_can( self::QUEUES[ $type ][0] ) ) { throw new \DomainException( 'forbidden' ); }
	}
	public static function defaultState( string $type ): string { self::authorize( $type ); return self::QUEUES[ $type ][2]; }
	public static function states( string $type ): array {
		self::authorize( $type ); return self::stateValues( $type );
	}
	private static function stateValues( string $type ): array {
		return match ( $type ) {
			'submissions', 'updates' => array( 'draft', 'submitted', 'in_review', 'needs_changes', 'accepted', 'rejected', 'withdrawn' ),
			'reviews' => array( 'pending', 'approved', 'rejected', 'hidden', 'withdrawn' ),
			'claims' => array( 'pending', 'approved', 'rejected', 'withdrawn', 'revoked', 'disputed' ),
			default => array( 'pending', 'reviewing', 'handled', 'closed' ),
		};
	}
	public static function stateLabel( string $state ): string {
		return match ( $state ) { 'draft' => Ui::t( '草稿', 'Draft' ), 'submitted' => Ui::t( '待审核', 'Submitted' ), 'pending' => Ui::t( '待处理', 'Pending' ), 'in_review', 'reviewing' => Ui::t( '处理中', 'In review' ), 'needs_changes' => Ui::t( '需要补充资料', 'Needs information' ), 'accepted' => Ui::t( '已接受（不等于发布）', 'Accepted (not published)' ), 'approved' => Ui::t( '已批准', 'Approved' ), 'rejected' => Ui::t( '已拒绝', 'Rejected' ), 'withdrawn' => Ui::t( '已撤回', 'Withdrawn' ), 'hidden' => Ui::t( '已隐藏', 'Hidden' ), 'revoked' => Ui::t( '已撤销', 'Revoked' ), 'disputed' => Ui::t( '有争议', 'Disputed' ), 'handled' => Ui::t( '已处理', 'Handled' ), 'closed' => Ui::t( '已关闭', 'Closed' ), default => Ui::t( '未知', 'Unknown' ) };
	}
	public static function destination( string $type, int $id = 0, string $state = '' ): string {
		self::authorize( $type ); $args = $id > 0 ? array( 'id' => $id ) : array();
		if ( '' !== $state ) { $args[ in_array( $type, array( 'reviews', 'review_reports', 'skill_reports' ), true ) ? 'status' : 'state' ] = $state; }
		return Ui::url( self::QUEUES[ $type ][1], $args );
	}
	public function listing( string $type, array $filters = array() ): array {
		self::authorize( $type );
		$state = Community\Input::choice( $filters['state'] ?? self::defaultState( $type ), array_merge( array( 'all' ), self::states( $type ) ) );
		$filters['state'] = $state; $filters['status'] = $state; $filters['per_page'] = min( 20, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) );
		$filters['sort'] = 'created_at'; $filters['direction'] = Community\Input::choice( $filters['direction'] ?? 'asc', array( 'asc', 'desc' ) );
		$result = match ( $type ) {
			'submissions', 'updates' => $this->submissionQuery->adminList( 'submissions' === $type ? 'submit' : 'update_suggestion', $filters ),
			'reviews' => $this->communityQuery->adminReviews( $filters ),
			'review_reports', 'skill_reports' => $this->communityQuery->adminReports( 'review_reports' === $type ? 'review' : 'skill', $filters ),
			'claims' => $this->developerQuery->adminClaims( $filters ),
		};
		$result['items'] = array_map( fn( array $row ): array => $this->project( $type, $row ), $result['items'] ); return $result;
	}
	/**
	 * Dashboard counts only: one freshly authorized aggregate per domain, no private
	 * payload/translation projection. Values are local to one snapshot, never cached.
	 * The filters match the existing queue queries (including the owned Inbox guard).
	 */
	public function counts( Database $db, Migrator $migrator, string $type, array $states ): array {
		self::authorize( $type ); if ( ! $migrator->isCurrent() ) { throw new \RuntimeException( 'storage_unavailable' ); }
		if ( ! $states || count( $states ) > 7 || count( array_unique( $states, SORT_REGULAR ) ) !== count( $states ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		foreach ( $states as $state ) { Community\Input::choice( $state, self::stateValues( $type ) ); }
		$counts = array_fill_keys( $states, 0 ); $holders = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		if ( in_array( $type, array( 'submissions', 'updates' ), true ) ) {
			$c = $db->connection(); $kind = 'submissions' === $type ? 'submit' : 'update_suggestion';
			// DISTINCT preserves the original EXISTS semantics even when repeated
			// matching postmeta rows occur; neither Contact nor anonymous Inbox enters.
			$rows = $db->rows( "SELECT ms.meta_value AS state,COUNT(DISTINCT p.ID) AS total FROM {$c->posts} p INNER JOIN {$c->postmeta} ms ON ms.post_id=p.ID AND ms.meta_key='_aish_submission_state' AND ms.meta_value IN ($holders) WHERE " . $this->submissionQuery->ownedSql() . " AND EXISTS (SELECT 1 FROM {$c->postmeta} mk WHERE mk.post_id=p.ID AND mk.meta_key='_aish_message_kind' AND mk.meta_value=%s) GROUP BY ms.meta_value", array_merge( $states, array( $kind ) ) );
		} elseif ( 'reviews' === $type ) {
			// One head can expose a pending revision while its approved revision stays
			// public. Pending is a pointer predicate, not r.state='pending'.
			$parts = array(); $values = array();
			foreach ( $states as $index => $state ) { $parts[] = 'COALESCE(SUM(' . ( 'pending' === $state ? 'pending_revision_id IS NOT NULL' : 'state=%s' ) . '),0) AS count_' . $index; if ( 'pending' !== $state ) { $values[] = $state; } }
			$row = $db->row( 'SELECT ' . implode( ',', $parts ) . ' FROM ' . $db->table( 'reviews' ), $values ); if ( ! $row ) { throw new \RuntimeException( 'storage_unavailable' ); }
			foreach ( $states as $index => $state ) { $counts[ $state ] = (int) $row[ 'count_' . $index ]; } return $counts;
		} else {
			$table = $db->table( array( 'claims' => 'author_claims', 'review_reports' => 'review_reports', 'skill_reports' => 'skill_reports' )[ $type ] );
			$rows = $db->rows( "SELECT state,COUNT(*) AS total FROM $table WHERE state IN ($holders) GROUP BY state", $states );
		}
		foreach ( $rows as $row ) { if ( array_key_exists( $row['state'], $counts ) ) { $counts[ $row['state'] ] = (int) $row['total']; } } return $counts;
	}
	private function project( string $type, array $row ): array {
		$id = (int) $row['id']; $revision = 'reviews' === $type ? ( $row['pending'] ?? $row['approved'] ?? $row['latest'] ?? array() ) : array();
		$waiting = 'reviews' === $type ? ( $revision['created_at'] ?? $row['created_at'] ) : ( $row['submitted_at'] ?? $row['created_at'] );
		if ( ! is_string( $waiting ) || '' === $waiting ) { $waiting = (string) ( $row['created_at'] ?? '' ); }
		$title = match ( $type ) { 'submissions', 'updates' => $row['name'], 'reviews' => $revision['title'] ?? '', 'claims' => 'Skill #' . (int) $row['skill_id'], 'review_reports' => Ui::t( '评价', 'Review' ) . ' #' . (int) $row['review_id'], default => 'Skill #' . (int) $row['skill_id'] };
		return array( 'id' => $id, 'type' => $type, 'title' => (string) $title, 'state' => (string) $row['state'], 'revision' => (int) ( $row['revision'] ?? $revision['revision'] ?? 0 ), 'revision_id' => (int) ( $row['pending_revision_id'] ?? $row['revision_id'] ?? 0 ), 'lock_version' => (int) ( $row['lock_version'] ?? 0 ), 'locale' => (string) ( $row['locale'] ?? $revision['locale'] ?? $row['proof']['locale'] ?? '' ), 'waiting_since' => $waiting, 'created_at' => (string) $row['created_at'], 'url' => self::destination( $type, $id ), 'pending_revision' => 'reviews' === $type && ! empty( $row['pending_revision_id'] ) );
	}
	public function history( string $type, int $id, int $page = 1 ): array {
		self::authorize( $type ); Community\Input::id( $id );
		return match ( $type ) {
			'submissions', 'updates' => $this->submissionQuery->history( $id, 'submissions' === $type ? 'submit' : 'update_suggestion', $page ),
			'claims' => $this->developerQuery->history( 'claim', $id, $page ),
			default => $this->communityQuery->history( array( 'reviews' => 'review', 'review_reports' => 'review_report', 'skill_reports' => 'skill_report' )[ $type ], $id, $page ),
		};
	}
	public static function decisions( string $type ): array {
		self::authorize( $type );
		return match ( $type ) {
			'submissions', 'updates' => array( 'reviewing' => Ui::t( '开始审核', 'Start review' ), 'needs_information' => Ui::t( '要求补充资料', 'Request information' ), 'reject' => Ui::t( '拒绝', 'Reject' ) ),
			'reviews', 'claims' => array( 'reject' => Ui::t( '拒绝所选申请或修订', 'Reject selected application or revision' ) ),
			default => array( 'reviewing' => Ui::t( '开始处理', 'Start handling' ), 'handled' => Ui::t( '标记已处理', 'Mark handled' ), 'closed' => Ui::t( '关闭', 'Close' ) ),
		};
	}
	/** Positive approvals stay in detailed evidence/revision screens. Every row is independently authorized. */
	public function batch( string $type, array $rows, string $decision, string $feedback, string $privateNote, string $reason, string $requestKey ): array {
		self::authorize( $type ); Community\Input::choice( $decision, array_keys( self::decisions( $type ) ) );
		if ( ! $rows || count( $rows ) > self::LIMIT || ! preg_match( '/^[a-f0-9]{32}$/D', $requestKey ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$feedback = Community\Input::text( $feedback, 2000, in_array( $type, array( 'submissions', 'updates', 'claims' ), true ) ? 10 : 0 );
		$privateNote = Community\Input::text( $privateNote, 2000 );
		$reason = Community\Input::choice( $reason, 'reviews' === $type ? array_diff( Community\Input::MODERATION_REASONS, array( 'approved' ) ) : Community\Input::RESOLUTIONS );
		$seen = array(); foreach ( $rows as $row ) { if ( ! is_array( $row ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } $id = Community\Input::id( $row['id'] ?? null ); if ( isset( $seen[ $id ] ) ) { throw new \InvalidArgumentException( 'duplicate_selection' ); } $seen[ $id ] = true; }
		$result = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			try {
				self::authorize( $type ); $key = substr( hash( 'sha256', $requestKey . ':' . $type . ':' . $id ), 0, 32 );
				switch ( $type ) {
					case 'submissions': case 'updates':
						// Check object kind before dispatching the shared submission service.
						if ( ! $this->submissionQuery->adminDetail( 'submissions' === $type ? 'submit' : 'update_suggestion', $id ) ) { throw new \DomainException( 'unavailable' ); }
						$this->submissions->moderate( $id, Community\Input::id( $row['revision'] ?? null ), $decision, $feedback, $privateNote, array(), $key ); break;
					case 'reviews':
						$this->community->moderateReview( $id, Community\Input::id( $row['revision_id'] ?? null ), Community\Input::id( $row['lock_version'] ?? null ), $decision, $reason, $privateNote, $key ); break;
					case 'claims':
						$this->developers->moderateClaim( $id, Community\Input::choice( $row['state'] ?? null, array( 'pending' ) ), $decision, $feedback, $privateNote, $key ); break;
					default:
						$this->community->resolveReport( 'review_reports' === $type ? 'review' : 'skill', $id, $decision, $reason, $privateNote, $key, Community\Input::choice( $row['state'] ?? null, array( 'pending', 'reviewing' ) ) );
				}
				$result[] = array( 'id' => $id, 'success' => true, 'code' => 'completed' );
			} catch ( \Throwable $error ) { $result[] = array( 'id' => $id, 'success' => false, 'code' => self::errorCode( $error ) ); }
		} return $result;
	}
	public static function errorCode( \Throwable $error ): string {
		$code = $error instanceof Community\Exception || $error instanceof Submissions\Exception || $error instanceof Developers\Exception ? $error->reason : $error->getMessage();
		return in_array( $code, array( 'forbidden', 'conflict', 'invalid_input', 'unavailable', 'storage_unavailable', 'evidence_required', 'invalid_source', 'claim_conflict', 'paused', 'source_changed', 'already_handled', 'busy' ), true ) ? $code : 'operation_failed';
	}
	public static function errorLabel( string $code ): string {
		return match ( $code ) { 'completed' => Ui::t( '已完成', 'Completed' ), 'forbidden' => Ui::t( '权限不足或账户不可用', 'Permission denied or account unavailable' ), 'conflict', 'source_changed' => Ui::t( '记录已变化，请重新打开核对', 'Record changed; reload and review it' ), 'invalid_input' => Ui::t( '输入或所选修订无效', 'Invalid input or selected revision' ), 'paused' => Ui::t( '仓库已暂停或归档，请由原管理流程处理', 'Repository is paused or archived; use its management workflow' ), 'already_handled' => Ui::t( '当前来源快照已处理', 'This source snapshot was already handled' ), 'busy' => Ui::t( '任务忙碌或频率受限，请稍后重试', 'Task busy or rate limited; retry later' ), 'unavailable' => Ui::t( '对象已不可用', 'Object unavailable' ), 'evidence_required' => Ui::t( '缺少核实证据', 'Verification evidence required' ), 'invalid_source', 'claim_conflict' => Ui::t( '来源或认领状态冲突', 'Source or claim conflict' ), default => Ui::t( '暂时无法完成，请检查当前记录后重试', 'Temporarily unavailable; check the current record before retrying' ) };
	}
}
