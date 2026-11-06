<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Submissions;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Frontend\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/** Private projections are pinned to the current native identity, never a request user ID. */
final class Query {
	private Store $store;
	public function __construct( private Database $db, private Migrator $migrator, private AccountService $accounts ) { $this->store = new Store( $db ); }
	private function ready(): void { if ( ! $this->migrator->isCurrent() ) { throw new Exception( 'storage_unavailable' ); } }
	public function erasing( int $id ): bool {
		return (bool) $this->db->value( 'SELECT 1 FROM ' . $this->db->connection()->usermeta . " WHERE user_id=%d AND meta_key IN ('_aish_contributions_erasing','_aish_account_deletion_pending') AND meta_value<>'' AND meta_value<>'0' LIMIT 1", array( $id ) );
	}
	private function owner(): int {
		$this->ready(); try { $id = (int) $this->accounts->requireOwner()->ID; } catch ( \Throwable $error ) { throw new Exception( 'forbidden' ); }
		if ( $this->erasing( $id ) ) { throw new Exception( 'forbidden' ); } return $id;
	}
	private function cap( string $kind ): void { $this->ready(); if ( ! current_user_can( Input::cap( $kind ) ) ) { throw new Exception( 'forbidden' ); } }
	public function publicSkillRevision( int $skillId, string $locale ): ?int {
		$this->ready(); Input::id( $skillId ); Input::locale( $locale );
		$ids = str_replace( '%', '%%', ( new PublicCatalog( $this->db, $this->migrator ) )->matchingIdsSql( $locale, array() ) );
		$v = $this->db->value( 'SELECT revision FROM ' . $this->db->table( 'skills' ) . " WHERE id=%d AND id IN ($ids)", array( $skillId ) ); return null === $v ? null : (int) $v;
	}
	/** This predicate distinguishes P10-owned records from all historical Inbox records. */
	public function ownedSql( string $alias = 'p' ): string {
		if ( 'p' !== $alias ) { throw new \LogicException( 'Unknown trusted alias.' ); }
		$m = $this->db->connection()->postmeta;
		return "p.post_type='aish_message' AND p.post_status='private' AND p.post_author>0 AND (SELECT COUNT(*) FROM $m mv WHERE mv.post_id=p.ID AND mv.meta_key='_aish_submission_version')=1 AND EXISTS (SELECT 1 FROM $m mv WHERE mv.post_id=p.ID AND mv.meta_key='_aish_submission_version' AND mv.meta_value='1')";
	}
	private function pagination( array $filters ): array { $page = Input::id( $filters['page'] ?? 1 ); $per = Input::id( $filters['per_page'] ?? 12 ); if ( $page > 10000 || $per > 24 ) { throw new Exception( 'invalid_input' ); } return array( $page, $per, ( $page - 1 ) * $per ); }
	private function list( ?int $owner, ?string $kind, string $locale, array $filters, bool $admin ): array {
		Input::locale( $locale ); [ $page, $per, $offset ] = $this->pagination( $filters );
		$where = $this->ownedSql(); $values = array(); $m = $this->db->connection()->postmeta;
		if ( null !== $owner ) { $where .= ' AND p.post_author=%d'; $values[] = $owner; }
		if ( null !== $kind ) { Input::choice( $kind, Input::KINDS ); $where .= " AND EXISTS (SELECT 1 FROM $m mk WHERE mk.post_id=p.ID AND mk.meta_key='_aish_message_kind' AND mk.meta_value=%s)"; $values[] = $kind; }
		$status = Input::choice( $filters['status'] ?? $filters['state'] ?? 'all', array_merge( array( 'all' ), Input::STATES ) );
		if ( 'all' !== $status ) { $where .= " AND EXISTS (SELECT 1 FROM $m ms WHERE ms.post_id=p.ID AND ms.meta_key='_aish_submission_state' AND ms.meta_value=%s)"; $values[] = $status; }
		if ( ! empty( $filters['locale'] ) ) { $where .= " AND EXISTS (SELECT 1 FROM $m ml WHERE ml.post_id=p.ID AND ml.meta_key='_aish_message_locale' AND ml.meta_value=%s)"; $values[] = Input::locale( $filters['locale'] ); }
		if ( ! empty( $filters['q'] ) ) { $where .= ' AND (p.post_title LIKE %s OR p.post_excerpt LIKE %s)'; $like = '%' . $this->db->connection()->esc_like( Input::text( $filters['q'], 100 ) ) . '%'; array_push( $values, $like, $like ); }
		$sort = Input::choice( $filters['sort'] ?? 'updated_at', array( 'id', 'created_at', 'updated_at' ) ); $dir = strtoupper( Input::choice( $filters['direction'] ?? 'desc', array( 'asc', 'desc' ) ) );
		$order = array( 'id' => 'ID', 'created_at' => 'post_date_gmt', 'updated_at' => 'post_modified_gmt' )[ $sort ];
		$table = $this->db->connection()->posts;
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $table p WHERE $where", $values );
		$rows = $offset >= $total ? array() : $this->db->rows( "SELECT p.* FROM $table p WHERE $where ORDER BY p.$order $dir,p.ID DESC LIMIT %d OFFSET %d", array_merge( $values, array( $per, $offset ) ) );
		$meta = $this->batchMeta( array_map( 'intval', array_column( $rows, 'ID' ) ) ); $items = array();
		foreach ( $rows as $row ) { $items[] = $this->project( $row, $meta[ $row['ID'] ] ?? array(), $admin ); }
		// Resolve the page's distinct result IDs in bounded catalog batches, not per post.
		$items = $this->results( $items, $locale, $admin );
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per );
	}
	private function batchMeta( array $ids ): array {
		if ( ! $ids ) { return array(); } $result = array();
		$rows = $this->db->rows( 'SELECT post_id,meta_key,meta_value FROM ' . $this->db->connection()->postmeta . ' WHERE post_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids );
		foreach ( $rows as $row ) { if ( ! in_array( $row['meta_key'], Store::META, true ) ) { continue; } if ( isset( $result[ $row['post_id'] ][ $row['meta_key'] ] ) ) { throw new Exception( 'storage_unavailable' ); } $result[ $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value']; } return $result;
	}
	private function project( array $post, array $meta, bool $admin ): array {
		if ( '1' !== ( $meta[ Store::MARKER ] ?? '' ) || (int) $post['post_author'] < 1 ) { throw new Exception( 'unavailable' ); }
		try { $payload = json_decode( $meta['_aish_submission_payload'] ?? '', true, 16, JSON_THROW_ON_ERROR ); $payload = Input::payload( $payload, false ); } catch ( \Throwable $error ) { throw new Exception( 'storage_unavailable' ); }
		if ( $payload['kind'] !== ( $meta['_aish_message_kind'] ?? '' ) ) { throw new Exception( 'storage_unavailable' ); }
		$ids = json_decode( $meta['_aish_result_skill_ids'] ?? '[]', true ); if ( ! is_array( $ids ) || count( $ids ) > 20 ) { throw new Exception( 'storage_unavailable' ); }
		$ids = array_values( array_unique( array_map( static fn( $id ) => Input::id( $id ), $ids ) ) );
		$row = array( 'id' => (int) $post['ID'], 'kind' => $payload['kind'], 'revision' => Input::id( $meta['_aish_submission_revision'] ?? null ), 'state' => Input::choice( $meta['_aish_submission_state'] ?? null, Input::STATES ), 'name' => $payload['name'], 'summary' => $payload['summary'], 'notes' => $payload['notes'], 'locale' => $payload['locale'], 'payload' => $payload, 'feedback' => $meta['_aish_user_visible_feedback'] ?? '', 'created_at' => $post['post_date_gmt'], 'updated_at' => $post['post_modified_gmt'], 'submitted_at' => $meta['_aish_submitted_at'] ?? '', 'resolved_at' => $meta['_aish_resolved_at'] ?? '', 'result_skill_ids' => $ids, 'source' => array( 'canonical_repository' => $meta['_aish_canonical_repository'] ?? '', 'source_path' => $payload['source_path'], 'path_status' => 'manual' === $payload['source_kind'] ? 'not_applicable' : ( $payload['source_path'] ? 'known' : 'pending_confirmation' ) ) );
		if ( $admin ) { $row['user_id'] = (int) $post['post_author']; $row['private_note'] = $meta['_aish_message_notes'] ?? ''; $row['handler_id'] = (int) ( $meta['_aish_handler_id'] ?? 0 ); }
		return $row;
	}
	private function results( array $rows, string $locale, bool $admin ): array {
		$ids = array(); foreach ( $rows as $row ) { if ( 'accepted' === $row['state'] ) { $ids = array_merge( $ids, $row['result_skill_ids'] ); } }
		$catalog = new PublicCatalog( $this->db, $this->migrator ); $cards = array();
		foreach ( array_chunk( array_values( array_unique( $ids ) ), 24 ) as $batch ) { foreach ( $catalog->cardsByIds( $batch, $locale ) as $card ) { $cards[ $card['id'] ] = $card; } }
		foreach ( $rows as &$row ) { $row['result_skills'] = array(); if ( 'accepted' === $row['state'] ) { foreach ( $row['result_skill_ids'] as $id ) { if ( isset( $cards[ $id ] ) ) { $row['result_skills'][] = $cards[ $id ]; } } } if ( ! $admin ) { unset( $row['result_skill_ids'] ); } } unset( $row ); return $rows;
	}
	public function mine( string $locale, array $filters = array() ): array { return $this->list( $this->owner(), empty( $filters['kind'] ) || 'all' === $filters['kind'] ? null : Input::choice( $filters['kind'], Input::KINDS ), $locale, $filters, false ); }
	public function mineDetail( int $id, string $locale ): ?array {
		$owner = $this->owner(); Input::id( $id ); Input::locale( $locale );
		$post = $this->db->row( 'SELECT p.* FROM ' . $this->db->connection()->posts . ' p WHERE p.ID=%d AND p.post_author=%d AND ' . $this->ownedSql(), array( $id, $owner ) );
		if ( ! $post ) { return null; } $row = $this->project( $post, $this->store->meta( $id ), false ); return $this->results( array( $row ), $locale, false )[0];
	}
	public function adminList( string $kind, array $filters = array() ): array { $this->cap( $kind ); return $this->list( empty( $filters['user_id'] ) ? null : Input::id( $filters['user_id'] ), $kind, empty( $filters['locale'] ) ? 'en_US' : $filters['locale'], $filters, true ); }
	public function adminDetail( string $kind, int $id, int $historyPage = 1 ): ?array {
		$this->cap( $kind ); Input::id( $id );
		$post = $this->db->row( 'SELECT p.* FROM ' . $this->db->connection()->posts . ' p WHERE p.ID=%d AND ' . $this->ownedSql(), array( $id ) ); if ( ! $post ) { return null; }
		$row = $this->project( $post, $this->store->meta( $id ), true ); if ( $kind !== $row['kind'] ) { return null; }
		$row = $this->results( array( $row ), $row['locale'], true )[0]; $row['history'] = $this->history( $id, $kind, $historyPage ); $row['snapshots'] = $this->snapshots( $id, $historyPage );
		$row['source'] = array_merge( $row['source'], $this->source( $row['payload'], true ) ); return $row;
	}
	private function snapshots( int $id, int $page ): array {
		[ $page, $per, $offset ] = $this->pagination( array( 'page' => $page, 'per_page' => 10 ) ); $p = $this->db->connection()->posts; $m = $this->db->connection()->postmeta;
		$where = "p.post_parent=%d AND p.post_type='revision' AND p.post_status='inherit' AND EXISTS (SELECT 1 FROM $m sm WHERE sm.post_id=p.ID AND sm.meta_key='_aish_submission_snapshot_revision')";
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $p p WHERE $where", array( $id ) );
		$items = $this->db->rows( "SELECT p.ID AS id,p.post_content AS payload_json,p.post_date_gmt AS created_at FROM $p p WHERE $where ORDER BY p.ID DESC LIMIT %d OFFSET %d", array( $id, $per, $offset ) );
		$metadata = $this->batchMeta( array_map( 'intval', array_column( $items, 'id' ) ) );
		foreach ( $items as &$item ) {
			try { $item['payload'] = json_decode( $item['payload_json'], true, 16, JSON_THROW_ON_ERROR ); if ( ! is_array( $item['payload'] ) ) { throw new \UnexpectedValueException(); } }
			catch ( \Throwable $error ) { throw new Exception( 'storage_unavailable' ); }
			$item['revision'] = Input::id( $metadata[ $item['id'] ]['_aish_submission_snapshot_revision'] ?? null ); $item['kind'] = Input::choice( $metadata[ $item['id'] ]['_aish_submission_snapshot_kind'] ?? 'content', array( 'content', 'moderation' ) ); unset( $item['payload_json'] );
		} unset( $item ); return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per );
	}
	public function history( int $id, string $kind, int $page = 1 ): array {
		$this->cap( $kind ); Input::id( $id ); [ $page, $per, $offset ] = $this->pagination( array( 'page' => $page, 'per_page' => 10 ) ); $table = $this->db->table( 'workflow_events' );
		$subject = 'submit' === $kind ? 'submission' : 'update_suggestion';
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $table WHERE subject_type=%s AND subject_id=%d", array( $subject, $id ) );
		$items = $this->db->rows( "SELECT id,subject_revision,action,actor_user_id,from_state,to_state,metadata_json,created_at FROM $table WHERE subject_type=%s AND subject_id=%d ORDER BY id DESC LIMIT %d OFFSET %d", array( $subject, $id, $per, $offset ) ); return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per );
	}
	/** Local source inspection only; no repository fetch, ingestion or Skill mutation. */
	public function source( array $payload, bool $admin = false ): array {
		$this->ready(); $payload = Input::payload( $payload, false ); if ( $admin ) { $this->cap( $payload['kind'] ); }
		$result = array( 'repository_id' => null, 'existing_skill_ids' => array(), 'existing_skills' => array() );
		if ( 'github' !== $payload['source_kind'] || ! $payload['github_url'] ) { return $result; }
		$identity = \AISkillsHub\Core\Sources\GitHubClient::repositoryIdentity( $payload['github_url'] );
		$repository = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'repositories' ) . ' WHERE owner=%s AND name=%s', array( $identity['owner'], $identity['name'] ) );
		if ( ! $repository ) { return $result; } $result['repository_id'] = (int) $repository['id'];
		if ( $payload['source_path'] ) {
			$matches = $this->db->rows( 'SELECT id,status,review_status,risk_level FROM ' . $this->db->table( 'skills' ) . ' WHERE repository_id=%d AND source_path=%s LIMIT 20', array( $result['repository_id'], $payload['source_path'] ) );
			$result['existing_skill_ids'] = array_map( 'intval', array_column( $matches, 'id' ) );
			if ( $admin ) { $result['risk_summary'] = array_map( static fn( array $match ): array => array( 'skill_id' => (int) $match['id'], 'status' => (string) $match['status'], 'review_status' => (string) $match['review_status'], 'risk_level' => in_array( $match['risk_level'], array( 'unknown', 'low', 'medium', 'high' ), true ) ? $match['risk_level'] : 'unknown' ), $matches ); }
		}
		$result['existing_skills'] = ( new PublicCatalog( $this->db, $this->migrator ) )->cardsByIds( $result['existing_skill_ids'], $payload['locale'] );
		if ( ! $admin ) { unset( $result['existing_skill_ids'], $result['repository_id'] ); } return $result;
	}
	public function dashboard( string $locale ): array { $id = $this->owner(); Input::locale( $locale ); return array( 'submission_count' => (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->connection()->posts . ' p WHERE p.post_author=%d AND ' . $this->ownedSql(), array( $id ) ) ); }
	/** Called by the approved opt-in developer projection, never a public identity directory. */
	public function publicContributions( int $userId, string $locale ): array {
		$this->ready(); Input::id( $userId ); Input::locale( $locale );
		if ( $this->erasing( $userId ) || ! $this->accounts->verified( $userId ) || 'active' !== $this->accounts->state( $userId ) ) { return array(); }
		$m = $this->db->connection()->postmeta;
		$rows = $this->db->rows( 'SELECT p.ID FROM ' . $this->db->connection()->posts . " p WHERE p.post_author=%d AND " . $this->ownedSql() . " AND EXISTS (SELECT 1 FROM $m sm WHERE sm.post_id=p.ID AND sm.meta_key='_aish_submission_state' AND sm.meta_value='accepted') ORDER BY p.ID DESC LIMIT 20", array( $userId ) );
		$meta = $this->batchMeta( array_map( 'intval', array_column( $rows, 'ID' ) ) ); $ids = array();
		foreach ( $meta as $values ) { $found = json_decode( $values['_aish_result_skill_ids'] ?? '[]', true ); if ( ! is_array( $found ) || count( $found ) > 20 ) { continue; } foreach ( $found as $id ) { if ( is_int( $id ) && $id > 0 ) { $ids[] = $id; } } }
		return ( new PublicCatalog( $this->db, $this->migrator ) )->cardsByIds( array_slice( array_values( array_unique( $ids ) ), 0, 20 ), $locale );
	}
}
