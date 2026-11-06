<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Frontend\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/** Fresh projections: no cross-account caches, no pending content in public queries. */
final class Query {
	public function __construct( private Database $db, private Migrator $migrator, private AccountService $accounts ) {}
	private function ready(): void { if ( ! $this->migrator->isCurrent() ) { throw new Exception( 'storage_unavailable' ); } }
	private function catalog(): PublicCatalog { return new PublicCatalog( $this->db, $this->migrator ); }
	public function publicIds( string $locale, array $filters = array() ): string {
		// This trusted subquery is embedded in a second prepared statement. Literal
		// percent characters from a search value must not become its placeholders.
		return str_replace( '%', '%%', $this->catalog()->matchingIdsSql( Input::locale( $locale ), $filters ) );
	}
	public function publicSkillRevision( int $skillId, string $locale ): ?int {
		$this->ready(); Input::id( $skillId );
		$value = $this->db->value( 'SELECT revision FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d AND id IN (' . $this->publicIds( $locale ) . ')', array( $skillId ) );
		return null === $value ? null : (int) $value;
	}
	/** Fixed SQL only. Native hooks revoke verification/state on identity changes. */
	public function liveUserSql( string $column ): string {
		if ( ! in_array( $column, array( 'r.user_id', 'f.user_id' ), true ) ) { throw new Exception( 'invalid_input' ); }
		$users = $this->db->connection()->users; $meta = $this->db->connection()->usermeta;
		return "EXISTS (SELECT 1 FROM $users u WHERE u.ID=$column) AND (SELECT COUNT(*) FROM $meta ua WHERE ua.user_id=$column AND ua.meta_key='_aish_account_state')=1 AND EXISTS (SELECT 1 FROM $meta ua WHERE ua.user_id=$column AND ua.meta_key='_aish_account_state' AND ua.meta_value='active') AND (SELECT COUNT(*) FROM $meta uv WHERE uv.user_id=$column AND uv.meta_key='_aish_verified_email_hash')=1 AND EXISTS (SELECT 1 FROM $meta uv WHERE uv.user_id=$column AND uv.meta_key='_aish_verified_email_hash' AND uv.meta_value REGEXP '^[a-f0-9]{64}$') AND (SELECT COUNT(*) FROM $meta ut WHERE ut.user_id=$column AND ut.meta_key='_aish_email_verified_at')=1 AND EXISTS (SELECT 1 FROM $meta ut WHERE ut.user_id=$column AND ut.meta_key='_aish_email_verified_at' AND ut.meta_value<>'') AND NOT EXISTS (SELECT 1 FROM $meta ux WHERE ux.user_id=$column AND ux.meta_key IN ('_aish_account_deletion_pending','_aish_community_erasing') AND ux.meta_value<>'0' AND ux.meta_value<>'')";
	}
	private function owner(): int {
		$this->ready(); $user = $this->accounts->requireOwner( false );
		if ( $this->erasing( (int) $user->ID ) ) { throw new Exception( 'forbidden' ); }
		return (int) $user->ID;
	}
	/** Any active/conflicting marker denies access; never trust first-value meta caches. */
	public function erasing( int $userId ): bool {
		Input::id( $userId ); $table = $this->db->connection()->usermeta;
		return (bool) $this->db->value( "SELECT 1 FROM $table WHERE user_id=%d AND meta_key='_aish_community_erasing' AND meta_value<>'' AND meta_value<>'0' LIMIT 1", array( $userId ) );
	}
	private function cap( string $cap ): void { $this->ready(); if ( ! current_user_can( $cap ) ) { throw new Exception( 'forbidden' ); } }
	private function pagination( array $filters ): array {
		$page = Input::id( $filters['page'] ?? 1 ); $limit = Input::id( $filters['per_page'] ?? 12 );
		if ( $page > 10000 || $limit > 24 ) { throw new Exception( 'invalid_input' ); }
		return array( $page, $limit, ( $page - 1 ) * $limit );
	}
	private function page( string $from, string $where, array $values, string $fields, string $order, array $filters ): array {
		[ $page, $limit, $offset ] = $this->pagination( $filters );
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $from WHERE $where", $values );
		$items = $offset >= $total ? array() : $this->db->rows( "SELECT $fields FROM $from WHERE $where ORDER BY $order LIMIT %d OFFSET %d", array_merge( $values, array( $limit, $offset ) ) );
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit );
	}
	private function publicSource( string $locale ): array {
		$this->ready(); $reviews = $this->db->table( 'reviews' ); $revisions = $this->db->table( 'review_revisions' );
		return array( "$reviews r INNER JOIN $revisions v ON v.id=r.approved_revision_id AND v.review_id=r.id", "r.state='approved' AND v.moderation_state='approved' AND v.moderator_id>0 AND v.moderated_at>='1970-01-01 00:00:00' AND v.rating BETWEEN 1 AND 5 AND r.skill_id IN (" . $this->publicIds( $locale ) . ') AND ' . $this->liveUserSql( 'r.user_id' ) );
	}
	private function publicFields(): string { return 'r.id,r.skill_id,v.id AS revision_id,v.locale,v.rating,v.title,v.comment,v.experience,v.pros,v.cons,v.platform,v.version_label,v.moderated_at AS published_at'; }
	public function publicSummary( int $skillId, string $locale ): array {
		Input::id( $skillId ); [ $from, $where ] = $this->publicSource( $locale );
		$row = $this->db->row( "SELECT COUNT(*) AS total,SUM(v.rating) AS rating_sum,SUM(v.rating=1) AS star1,SUM(v.rating=2) AS star2,SUM(v.rating=3) AS star3,SUM(v.rating=4) AS star4,SUM(v.rating=5) AS star5 FROM $from WHERE $where AND r.skill_id=%d", array( $skillId ) );
		$count = (int) ( $row['total'] ?? 0 ); $distribution = array();
		foreach ( range( 1, 5 ) as $star ) { $distribution[ $star ] = (int) ( $row[ 'star' . $star ] ?? 0 ); }
		$favorites = (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'favorites' ) . ' f WHERE f.skill_id=%d AND f.is_active=1 AND f.skill_id IN (' . $this->publicIds( $locale ) . ') AND ' . $this->liveUserSql( 'f.user_id' ), array( $skillId ) );
		return array( 'average' => $count ? round( (float) $row['rating_sum'] / $count, 2 ) : null, 'count' => $count, 'distribution' => $distribution, 'favorites' => $favorites );
	}
	public function publicReviews( int $skillId, string $locale, array $filters = array() ): array {
		Input::id( $skillId ); [ $from, $where ] = $this->publicSource( $locale ); $values = array( $skillId ); $where .= ' AND r.skill_id=%d';
		if ( ! empty( $filters['locale'] ) ) { $where .= ' AND v.locale=%s'; $values[] = Input::locale( $filters['locale'] ); }
		$sort = Input::choice( $filters['sort'] ?? 'newest', array( 'newest', 'oldest' ) );
		return $this->page( $from, $where, $values, $this->publicFields(), 'r.first_published_at ' . ( 'oldest' === $sort ? 'ASC' : 'DESC' ) . ',r.id DESC', $filters );
	}
	public function publicReview( int $reviewId, int $revisionId, string $locale ): ?array {
		Input::id( $reviewId ); Input::id( $revisionId ); [ $from, $where ] = $this->publicSource( $locale );
		return $this->db->row( 'SELECT ' . $this->publicFields() . " FROM $from WHERE $where AND r.id=%d AND v.id=%d", array( $reviewId, $revisionId ) );
	}
	public function favoriteState( int $skillId ): bool {
		$id = $this->owner(); Input::id( $skillId );
		return 1 === (int) $this->db->value( 'SELECT is_active FROM ' . $this->db->table( 'favorites' ) . ' WHERE user_id=%d AND skill_id=%d', array( $id, $skillId ) );
	}
	/** Private relation rows never carry a historical Skill title or source snapshot. */
	public function mineFavorites( string $locale, array $filters = array() ): array {
		$id = $this->owner(); Input::locale( $locale ); $status = Input::choice( $filters['status'] ?? 'all', array( 'all', 'available', 'unavailable' ) );
		$public = $this->publicIds( $locale ); $where = 'f.user_id=%d AND f.is_active=1'; $values = array( $id );
		if ( 'all' !== $status ) { $where .= ' AND f.skill_id ' . ( 'unavailable' === $status ? 'NOT ' : '' ) . "IN ($public)"; }
		if ( ! empty( $filters['q'] ) ) { $where .= ' AND f.skill_id IN (' . $this->publicIds( $locale, array( 'q' => Input::text( $filters['q'], 100 ) ) ) . ')'; }
		$sort = Input::choice( $filters['sort'] ?? 'newest', array( 'newest', 'title' ) );
		$from = $this->db->table( 'favorites' ) . ' f'; $order = 'f.created_at DESC,f.skill_id DESC';
		if ( 'title' === $sort ) {
			$tr = $this->db->table( 'skill_translations' );
			$from .= ' LEFT JOIN ' . $tr . ' st ON st.skill_id=f.skill_id AND st.locale=%s AND f.skill_id IN (' . $public . ')';
			array_unshift( $values, $locale ); $order = 'st.title ASC,f.skill_id ASC';
		}
		$result = $this->page( $from, $where, $values, 'f.skill_id,f.created_at', $order, $filters );
		$ids = array_map( 'intval', array_column( $result['items'], 'skill_id' ) );
		$cards = array_column( $this->catalog()->cardsByIds( $ids, $locale ), null, 'id' );
		$other = 'zh_CN' === $locale ? 'en_US' : 'zh_CN'; $missing = array_values( array_diff( $ids, array_keys( $cards ) ) );
		$alternatives = $missing ? array_column( $this->catalog()->cardsByIds( $missing, $other ), null, 'id' ) : array();
		foreach ( $result['items'] as &$item ) {
			$item['skill_id'] = (int) $item['skill_id']; $item['skill'] = $cards[ $item['skill_id'] ] ?? null; $item['available'] = null !== $item['skill'];
			$item['alternative'] = isset( $alternatives[ $item['skill_id'] ] ) ? array( 'locale' => $other, 'skill' => $alternatives[ $item['skill_id'] ] ) : null;
		} unset( $item );
		return $result;
	}
	private function headFields(): string { return 'r.*,(SELECT MAX(lv.id) FROM ' . $this->db->table( 'review_revisions' ) . ' lv WHERE lv.review_id=r.id) AS latest_revision_id'; }
	private function heads( array $rows, bool $admin, ?string $locale = null ): array {
		$ids = array();
		foreach ( $rows as $row ) { foreach ( array( 'approved_revision_id', 'pending_revision_id', 'latest_revision_id' ) as $field ) { if ( ! empty( $row[ $field ] ) ) { $ids[] = (int) $row[ $field ]; } } }
		$ids = array_values( array_unique( $ids ) ); $revisions = array();
		if ( $ids ) { $revisions = array_column( $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'review_revisions' ) . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ), null, 'id' ); }
		$cards = array(); if ( null !== $locale && $rows ) { $cards = array_column( $this->catalog()->cardsByIds( array_values( array_unique( array_map( 'intval', array_column( $rows, 'skill_id' ) ) ) ), $locale ), null, 'id' ); }
		foreach ( $rows as &$row ) {
			foreach ( array( 'id', 'skill_id', 'lock_version' ) as $key ) { $row[ $key ] = (int) $row[ $key ]; }
			foreach ( array( 'approved', 'pending', 'latest' ) as $kind ) {
				$revision = $revisions[ $row[ $kind . '_revision_id' ] ?? 0 ] ?? null;
				if ( $revision && (int) $revision['review_id'] !== $row['id'] ) { $revision = null; }
				if ( $revision && ! $admin ) { unset( $revision['private_note'], $revision['moderator_id'], $revision['request_key'] ); }
				$row[ $kind ] = $revision;
			}
			unset( $row['latest_revision_id'] ); if ( ! $admin ) { unset( $row['user_id'] ); }
			if ( null !== $locale ) { $row['skill'] = $cards[ $row['skill_id'] ] ?? null; }
		} unset( $row ); return $rows;
	}
	public function mineReview( int $skillId ): ?array {
		$id = $this->owner(); Input::id( $skillId );
		$rows = $this->db->rows( 'SELECT ' . $this->headFields() . ' FROM ' . $this->db->table( 'reviews' ) . ' r WHERE r.user_id=%d AND r.skill_id=%d', array( $id, $skillId ) );
		return $this->heads( $rows, false )[0] ?? null;
	}
	public function mineReviews( string $locale, array $filters = array() ): array {
		$id = $this->owner(); Input::locale( $locale ); $status = Input::choice( $filters['status'] ?? 'all', array( 'all', 'pending', 'approved', 'rejected', 'hidden', 'withdrawn' ) );
		$where = 'r.user_id=%d'; $values = array( $id );
		if ( 'pending' === $status ) { $where .= ' AND r.pending_revision_id IS NOT NULL'; }
		elseif ( 'all' !== $status ) { $where .= ' AND r.state=%s'; $values[] = $status; }
		$result = $this->page( $this->db->table( 'reviews' ) . ' r', $where, $values, $this->headFields(), 'r.updated_at DESC,r.id DESC', $filters );
		$result['items'] = $this->heads( $result['items'], false, $locale ); return $result;
	}
	public function dashboard( string $locale ): array {
		$id = $this->owner(); Input::locale( $locale );
		return array( 'favorite_count' => (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'favorites' ) . ' WHERE user_id=%d AND is_active=1', array( $id ) ), 'review_count' => (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'reviews' ) . ' WHERE user_id=%d', array( $id ) ) );
	}
	public function adminReviews( array $filters = array() ): array {
		$this->cap( 'aish_moderate_community' ); $where = '1=1'; $values = array();
		$status = Input::choice( $filters['status'] ?? 'all', array( 'all', 'pending', 'approved', 'rejected', 'hidden', 'withdrawn' ) );
		if ( 'pending' === $status ) { $where .= ' AND r.pending_revision_id IS NOT NULL'; }
		elseif ( 'all' !== $status ) { $where .= ' AND r.state=%s'; $values[] = $status; }
		foreach ( array( 'skill_id', 'user_id' ) as $field ) { if ( ! empty( $filters[ $field ] ) ) { $where .= " AND r.$field=%d"; $values[] = Input::id( $filters[ $field ] ); } }
		if ( ! empty( $filters['locale'] ) ) { $where .= ' AND EXISTS (SELECT 1 FROM ' . $this->db->table( 'review_revisions' ) . ' lv WHERE lv.review_id=r.id AND lv.locale=%s)'; $values[] = Input::locale( $filters['locale'] ); }
		if ( ! empty( $filters['q'] ) ) { $where .= ' AND EXISTS (SELECT 1 FROM ' . $this->db->table( 'review_revisions' ) . ' sv WHERE sv.review_id=r.id AND (sv.title LIKE %s OR sv.comment LIKE %s))'; $like = '%' . $this->db->connection()->esc_like( Input::text( $filters['q'], 100 ) ) . '%'; array_push( $values, $like, $like ); }
		$sort = Input::choice( $filters['sort'] ?? 'updated_at', array( 'id', 'created_at', 'updated_at' ) ); $direction = strtoupper( Input::choice( $filters['direction'] ?? 'desc', array( 'asc', 'desc' ) ) );
		$result = $this->page( $this->db->table( 'reviews' ) . ' r', $where, $values, $this->headFields(), "r.$sort $direction,r.id DESC", $filters );
		$result['items'] = $this->heads( $result['items'], true ); return $result;
	}
	public function adminReview( int $id, int $historyPage = 1 ): ?array {
		$this->cap( 'aish_moderate_community' ); Input::id( $id );
		$rows = $this->db->rows( 'SELECT ' . $this->headFields() . ' FROM ' . $this->db->table( 'reviews' ) . ' r WHERE r.id=%d', array( $id ) );
		$row = $this->heads( $rows, true )[0] ?? null; if ( $row ) { $row['history'] = $this->history( 'review', $id, $historyPage ); } return $row;
	}
	public function adminReports( string $kind, array $filters = array() ): array {
		$this->cap( 'aish_manage_community_reports' ); Input::choice( $kind, array( 'review', 'skill' ) ); $where = '1=1'; $values = array();
		$status = Input::choice( $filters['status'] ?? 'all', array( 'all', 'pending', 'reviewing', 'handled', 'closed' ) );
		if ( 'all' !== $status ) { $where .= ' AND p.state=%s'; $values[] = $status; }
		if ( ! empty( $filters['reason'] ) ) { $where .= ' AND p.' . ( 'review' === $kind ? 'reason_code' : 'kind' ) . '=%s'; $values[] = Input::choice( $filters['reason'], 'review' === $kind ? Input::REVIEW_REASONS : Input::SKILL_REASONS ); }
		if ( ! empty( $filters['skill_id'] ) ) { $where .= ' AND ' . ( 'skill' === $kind ? 'p.skill_id=%d' : 'EXISTS (SELECT 1 FROM ' . $this->db->table( 'reviews' ) . ' r WHERE r.id=p.review_id AND r.skill_id=%d)' ); $values[] = Input::id( $filters['skill_id'] ); }
		if ( ! empty( $filters['q'] ) ) { $where .= ' AND p.details LIKE %s'; $values[] = '%' . $this->db->connection()->esc_like( Input::text( $filters['q'], 100 ) ) . '%'; }
		$sort = Input::choice( $filters['sort'] ?? 'created_at', array( 'id', 'created_at' ) ); $direction = strtoupper( Input::choice( $filters['direction'] ?? 'desc', array( 'asc', 'desc' ) ) );
		return $this->page( $this->db->table( $kind . '_reports' ) . ' p', $where, $values, 'p.*', "p.$sort $direction,p.id DESC", $filters );
	}
	public function adminReport( string $kind, int $id, int $historyPage = 1 ): ?array {
		$this->cap( 'aish_manage_community_reports' ); Input::choice( $kind, array( 'review', 'skill' ) ); Input::id( $id );
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( $kind . '_reports' ) . ' WHERE id=%d', array( $id ) );
		if ( $row ) {
			$row['history'] = $this->history( $kind . '_report', $id, $historyPage );
			if ( 'review' === $kind ) {
				$row['reported_revision'] = $this->db->row( 'SELECT id,review_id,revision,locale,rating,title,comment,experience,pros,cons,platform,version_label,moderation_state,created_at FROM ' . $this->db->table( 'review_revisions' ) . ' WHERE id=%d AND review_id=%d', array( (int) $row['revision_id'], (int) $row['review_id'] ) );
			}
		} return $row;
	}
	public function history( string $subjectType, int $id, int $page = 1, int $perPage = 20 ): array {
		Input::choice( $subjectType, array( 'review', 'review_report', 'skill_report' ) ); $this->cap( 'review' === $subjectType ? 'aish_moderate_community' : 'aish_manage_community_reports' ); Input::id( $id );
		return $this->page( $this->db->table( 'workflow_events' ), 'subject_type=%s AND subject_id=%d', array( $subjectType, $id ), 'id,subject_type,subject_id,subject_revision,action,actor_user_id,from_state,to_state,metadata_json,created_at', 'id DESC', array( 'page' => $page, 'per_page' => $perPage ) );
	}
}
