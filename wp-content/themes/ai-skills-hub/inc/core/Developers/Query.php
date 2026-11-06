<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Developers;
use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Frontend\PublicCatalog;
defined( 'ABSPATH' ) || exit;

/** Explicit private projections and opt-in, approved public snapshots. No cross-user cache. */
final class Query {
	private Metadata $meta;
	public function __construct( private Database $db, private Migrator $migrator, private AccountService $accounts ) { $this->meta = new Metadata( $db ); }
	private function ready(): void { if ( ! $this->migrator->isCurrent() ) { throw new Exception( 'storage_unavailable' ); } }
	public function erasing( int $id ): bool {
		$t = $this->db->connection()->usermeta;
		return null !== $this->db->value( "SELECT umeta_id FROM $t WHERE user_id=%d AND meta_key IN ('_aish_account_deletion_pending','_aish_community_erasing','_aish_contributions_erasing') AND meta_value<>'' AND meta_value<>'0' LIMIT 1", array( $id ) );
	}
	private function owner(): int { $this->ready(); try { $id = (int) $this->accounts->requireOwner()->ID; } catch ( \Throwable $e ) { throw new Exception( 'forbidden' ); } if ( $this->erasing( $id ) ) { throw new Exception( 'forbidden' ); } return $id; }
	private function cap( string $cap ): void { $this->ready(); if ( ! current_user_can( $cap ) ) { throw new Exception( 'forbidden' ); } }
	private function catalog(): PublicCatalog { return new PublicCatalog( $this->db, $this->migrator ); }
	private function publicIds( string $locale ): string { return str_replace( '%', '%%', $this->catalog()->matchingIdsSql( Input::locale( $locale ), array() ) ); }
	public function publicSkillRevision( int $skillId, string $locale ): ?int { $this->ready(); Input::id( $skillId ); $v = $this->db->value( 'SELECT revision FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d AND id IN (' . $this->publicIds( $locale ) . ')', array( $skillId ) ); return null === $v ? null : (int) $v; }
	private function page( array $f ): array { $p = Input::id( $f['page'] ?? 1 ); $n = Input::id( $f['per_page'] ?? 12 ); if ( $p > 10000 || $n > 24 ) { throw new Exception( 'invalid_input' ); } return array( $p, $n, ( $p - 1 ) * $n ); }
	private function profile( int $id, bool $admin, ?array $m = null ): array {
		$m ??= $this->meta->read( $id ); $approved = $m['_aish_profile_approved_snapshot'] ?? null;
		if ( null !== $approved && ! is_array( $approved ) ) { throw new Exception( 'storage_unavailable' ); }
		$r = array( 'revision' => (int) ( $m['_aish_profile_revision'] ?? 0 ), 'state' => $m['_aish_profile_review_status'] ?? 'draft', 'opt_in' => '1' === (string) ( $m['_aish_public_profile'] ?? '' ), 'draft' => Metadata::draft( $m ), 'approved' => $approved, 'feedback' => (string) ( $m['_aish_profile_feedback'] ?? '' ), 'reviewed_at' => $m['_aish_profile_reviewed_at'] ?? null, 'updated_at' => $m['_aish_profile_updated_at'] ?? null );
		if ( $approved && ! $admin ) { unset( $r['approved']['reviewer_id'] ); }
		if ( $admin ) { $r['user_id'] = $id; $r['private_note'] = (string) ( $m['_aish_profile_private_note'] ?? '' ); $r['reviewer_id'] = (int) ( $m['_aish_profile_reviewer_id'] ?? 0 ); }
		return $r;
	}
	public function mineProfile(): array { return $this->profile( $this->owner(), false ); }
	public function adminProfiles( array $filters = array() ): array {
		$this->cap( 'aish_manage_developers' ); [ $page, $limit, $offset ] = $this->page( $filters );
		$users = $this->db->connection()->users; $meta = $this->db->connection()->usermeta;
		$state = Input::choice( $filters['state'] ?? 'all', array( 'all', 'draft', 'pending', 'approved', 'rejected', 'hidden', 'withdrawn' ) );
		$where = "EXISTS (SELECT 1 FROM $meta m WHERE m.user_id=u.ID AND m.meta_key='_aish_profile_revision')"; $args = array();
		if ( 'all' !== $state ) { $where .= " AND EXISTS (SELECT 1 FROM $meta st WHERE st.user_id=u.ID AND st.meta_key='_aish_profile_review_status' AND st.meta_value=%s)"; $args[] = $state; }
		if ( ! empty( $filters['user_id'] ) ) { $where .= ' AND u.ID=%d'; $args[] = Input::id( $filters['user_id'] ); }
		if ( ! empty( $filters['q'] ) ) { $where .= " AND EXISTS (SELECT 1 FROM $meta mt WHERE mt.user_id=u.ID AND mt.meta_key IN ('_aish_profile_candidate','_aish_profile_translations') AND mt.meta_value LIKE %s)"; $args[] = '%' . $this->db->connection()->esc_like( Input::text( $filters['q'], 100 ) ) . '%'; }
		if ( ! empty( $filters['locale'] ) ) { $where .= " AND EXISTS (SELECT 1 FROM $meta ml WHERE ml.user_id=u.ID AND ml.meta_key='_aish_profile_translations' AND ml.meta_value LIKE %s)"; $args[] = '%"' . Input::locale( $filters['locale'] ) . '";%'; }
		$sort = Input::choice( $filters['sort'] ?? 'id', array( 'id', 'created_at', 'updated_at' ) ); $direction = strtoupper( Input::choice( $filters['direction'] ?? 'desc', array( 'asc', 'desc' ) ) );
		$events = $this->db->table( 'workflow_events' );
		$order = match ( $sort ) { 'updated_at' => "(SELECT MAX(mu.meta_value) FROM $meta mu WHERE mu.user_id=u.ID AND mu.meta_key='_aish_profile_updated_at')", 'created_at' => "(SELECT MIN(we.created_at) FROM $events we WHERE we.subject_type='developer' AND we.subject_id=u.ID AND we.action='profile_submit')", default => 'u.ID' };
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $users u WHERE $where", $args );
		$ids = $offset >= $total ? array() : $this->db->rows( "SELECT u.ID FROM $users u WHERE $where ORDER BY $order $direction,u.ID $direction LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) );
		$metadata = $this->meta->readMany( array_map( 'intval', array_column( $ids, 'ID' ) ) );
		$items = array(); foreach ( $ids as $r ) { $items[] = $this->profile( (int) $r['ID'], true, $metadata[ $r['ID'] ] ?? array() ); }
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit );
	}
	public function adminProfile( int $id, int $historyPage = 1 ): ?array {
		$this->cap( 'aish_manage_developers' ); Input::id( $id ); if ( ! get_userdata( $id ) ) { return null; }
		$r = $this->profile( $id, true ); $r['history'] = $this->history( 'developer', $id, $historyPage ); $r['decisions'] = $this->profileDecisions( $id, $historyPage );
		$bytes = $this->avatar( $id, $r['draft']['avatar'] ?? null ); $r['avatar_preview'] = $bytes ? 'data:image/png;base64,' . base64_encode( $bytes ) : null;
		$approvedBytes = $this->avatar( $id, $r['approved']['avatar'] ?? null ); $r['approved_avatar_preview'] = $approvedBytes ? 'data:image/png;base64,' . base64_encode( $approvedBytes ) : null; return $r;
	}
	private function profileDecisions( int $id, int $page ): array {
		[ $page, $limit, $offset ] = $this->page( array( 'page' => $page, 'per_page' => 20 ) ); $m = $this->db->connection()->usermeta;
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $m WHERE user_id=%d AND meta_key=%s", array( $id, Metadata::HISTORY_KEY ) );
		$rows = $offset >= $total ? array() : $this->db->rows( "SELECT meta_value FROM $m WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id DESC LIMIT %d OFFSET %d", array( $id, Metadata::HISTORY_KEY, $limit, $offset ) ); $items = array();
		foreach ( $rows as $row ) { try { $value = json_decode( $row['meta_value'], true, 16, JSON_THROW_ON_ERROR ); } catch ( \Throwable $e ) { throw new Exception( 'storage_unavailable' ); } if ( ! is_array( $value ) || 1 !== ( $value['v'] ?? null ) ) { throw new Exception( 'storage_unavailable' ); } $items[] = $value; }
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit );
	}
	private function claimProjection( array $r, bool $admin ): array {
		try { $proof = json_decode( $r['proof_json'], true, 16, JSON_THROW_ON_ERROR ); } catch ( \Throwable $e ) { throw new Exception( 'storage_unavailable' ); }
		if ( ! is_array( $proof ) || 1 !== ( $proof['v'] ?? null ) || ! is_array( $proof['application'] ?? null ) || ! is_array( $proof['decisions'] ?? null ) ) { throw new Exception( 'storage_unavailable' ); }
		$last = $proof['decisions'] ? end( $proof['decisions'] ) : array(); $application = $proof['application'];
		$r['proof'] = array_intersect_key( $application, array_flip( array( 'locale', 'reason', 'evidence_urls' ) ) ); $r['feedback'] = (string) ( $last['reason'] ?? '' );
		if ( $admin ) { $r['private_note'] = (string) ( $last['private_note'] ?? '' ); $r['decisions'] = $proof['decisions']; $r['source_path'] = $application['source_path'] ?? null; }
		else { unset( $r['claimant_user_id'], $r['request_key'], $r['active_request_key'], $r['reviewer_id'] ); }
		unset( $r['proof_json'] ); foreach ( array( 'id', 'skill_id', 'source_revision' ) as $key ) { $r[ $key ] = (int) $r[ $key ]; } return $r;
	}
	private function claims( array $filters, ?int $owner ): array {
		[ $page, $limit, $offset ] = $this->page( $filters ); $where = '1=1'; $args = array();
		if ( null !== $owner ) { $where .= ' AND claimant_user_id=%d'; $args[] = $owner; }
		$state = Input::choice( $filters['state'] ?? 'all', array( 'all', 'pending', 'approved', 'rejected', 'withdrawn', 'revoked', 'disputed' ) );
		if ( 'all' !== $state ) { $where .= ' AND state=%s'; $args[] = $state; }
		foreach ( array( 'skill_id', 'user_id' ) as $field ) { if ( ! empty( $filters[ $field ] ) && ( null === $owner || 'user_id' !== $field ) ) { $where .= ' AND ' . ( 'user_id' === $field ? 'claimant_user_id' : $field ) . '=%d'; $args[] = Input::id( $filters[ $field ] ); } }
		if ( ! empty( $filters['q'] ) ) { $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(proof_json,'$.application.reason')) LIKE %s"; $args[] = '%' . $this->db->connection()->esc_like( Input::text( $filters['q'], 100 ) ) . '%'; }
		if ( ! empty( $filters['locale'] ) ) { $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(proof_json,'$.application.locale'))=%s"; $args[] = Input::locale( $filters['locale'] ); }
		$sort = Input::choice( $filters['sort'] ?? 'id', array( 'id', 'created_at', 'updated_at' ) ); $direction = strtoupper( Input::choice( $filters['direction'] ?? 'desc', array( 'asc', 'desc' ) ) );
		$table = $this->db->table( 'author_claims' ); $total = (int) $this->db->value( "SELECT COUNT(*) FROM $table WHERE $where", $args );
		$rows = $offset >= $total ? array() : $this->db->rows( "SELECT * FROM $table WHERE $where ORDER BY $sort $direction,id $direction LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) );
		$items = array_map( fn( array $r ): array => $this->claimProjection( $r, null === $owner ), $rows );
		$locale = Input::locale( $filters['display_locale'] ?? ( empty( $filters['locale'] ) ? 'en_US' : $filters['locale'] ) ); $cards = array_column( $this->catalog()->cardsByIds( array_values( array_unique( array_map( 'intval', array_column( $rows, 'skill_id' ) ) ) ), $locale ), null, 'id' );
		foreach ( $items as &$r ) { $r['skill'] = $cards[ $r['skill_id'] ] ?? null; } unset( $r );
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit );
	}
	public function mineClaims( array $filters = array() ): array { return $this->claims( $filters, $this->owner() ); }
	public function adminClaims( array $filters = array() ): array { $this->cap( 'aish_manage_author_claims' ); return $this->claims( $filters, null ); }
	public function mineClaim( int $id, ?string $displayLocale = null ): ?array { $owner = $this->owner(); Input::id( $id ); $row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'author_claims' ) . ' WHERE id=%d AND claimant_user_id=%d', array( $id, $owner ) ); if ( ! $row ) { return null; } $r = $this->claimProjection( $row, false ); $r['skill'] = $this->catalog()->detail( $r['skill_id'], Input::locale( $displayLocale ?? ( $r['proof']['locale'] ?? 'en_US' ) ) ); return $r; }
	public function adminClaim( int $id, int $historyPage = 1 ): ?array { $this->cap( 'aish_manage_author_claims' ); Input::id( $id ); $row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'author_claims' ) . ' WHERE id=%d', array( $id ) ); if ( ! $row ) { return null; } $r = $this->claimProjection( $row, true ); $r['history'] = $this->history( 'claim', $id, $historyPage ); return $r; }
	public function history( string $type, int $id, int $page = 1 ): array {
		$this->cap( 'developer' === Input::choice( $type, array( 'developer', 'claim' ) ) ? 'aish_manage_developers' : 'aish_manage_author_claims' ); Input::id( $id ); [ $page, $limit, $offset ] = $this->page( array( 'page' => $page, 'per_page' => 20 ) );
		$t = $this->db->table( 'workflow_events' ); $total = (int) $this->db->value( "SELECT COUNT(*) FROM $t WHERE subject_type=%s AND subject_id=%d", array( $type, $id ) );
		$items = $offset >= $total ? array() : $this->db->rows( "SELECT id,action,actor_user_id,subject_revision,from_state,to_state,metadata_json,created_at FROM $t WHERE subject_type=%s AND subject_id=%d ORDER BY id DESC LIMIT %d OFFSET %d", array( $type, $id, $limit, $offset ) ); return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit );
	}
	private function approved( string $handle ): ?array {
		$this->ready(); try { Input::handle( $handle ); } catch ( Exception $e ) { return null; } $u = $this->db->connection()->users;
		$rows = $this->db->rows( "SELECT ID,user_nicename FROM $u WHERE user_nicename=%s LIMIT 2", array( $handle ) ); if ( 1 !== count( $rows ) ) { return null; }
		$id = (int) $rows[0]['ID']; if ( $this->erasing( $id ) || ! $this->accounts->verified( $id ) || 'active' !== $this->accounts->state( $id ) ) { return null; }
		$m = $this->meta->read( $id ); $s = $m['_aish_profile_approved_snapshot'] ?? null;
		if ( '1' !== (string) ( $m['_aish_public_profile'] ?? '' ) || ! is_array( $s ) || ( $s['handle'] ?? '' ) !== $handle || (int) ( $s['revision'] ?? 0 ) < 1 || (int) ( $s['reviewer_id'] ?? 0 ) < 1 || ! is_string( $s['reviewed_at'] ?? null ) || '' === $s['reviewed_at'] || ! is_array( $s['translations'] ?? null ) ) { return null; }
		// Restrictive privacy choices take effect immediately; opting back in never
		// exposes new text, contribution permission, or an unreviewed avatar.
		$candidate = $m['_aish_profile_candidate'] ?? array();
		if ( ! is_array( $candidate ) || empty( $candidate['public_avatar'] ) || empty( $s['public_avatar'] ) ) { $s['avatar'] = null; }
		if ( ! is_array( $candidate ) || empty( $candidate['show_contributions'] ) ) { $s['show_contributions'] = false; }
		return array( 'id' => $id, 'snapshot' => $s );
	}
	private function language( array $snapshot, string $locale ): ?array {
		$row = $snapshot['translations'][ Input::locale( $locale ) ] ?? null;
		if ( ! is_array( $row ) || ! is_string( $row['display_name'] ?? null ) || '' === trim( $row['display_name'] ) || ! is_string( $row['bio'] ?? null ) || '' === trim( $row['bio'] ) ) { return null; }
		return array_intersect_key( $row, array_flip( array( 'display_name', 'bio', 'seo_title', 'seo_description' ) ) );
	}
	public function alternatives( string $handle ): array { $r = $this->approved( $handle ); $result = array(); if ( $r ) { foreach ( Input::LOCALES as $locale ) { if ( $this->language( $r['snapshot'], $locale ) ) { $result[ $locale ] = $handle; } } } return $result; }
	private function sourceBinding(): string {
		$repositories = $this->db->table( 'repositories' );
		return "JSON_UNQUOTE(JSON_EXTRACT(c.proof_json,'$.application.source_path')) <=> COALESCE(s.source_path,'null') AND ((c.repository_id IS NULL AND JSON_TYPE(JSON_EXTRACT(c.proof_json,'$.application.repository_identity'))='NULL') OR EXISTS (SELECT 1 FROM $repositories rb WHERE rb.id=c.repository_id AND rb.owner=JSON_UNQUOTE(JSON_EXTRACT(c.proof_json,'$.application.repository_identity.owner')) AND rb.name=JSON_UNQUOTE(JSON_EXTRACT(c.proof_json,'$.application.repository_identity.name')) AND COALESCE(CAST(rb.github_id AS CHAR),'null')=JSON_UNQUOTE(JSON_EXTRACT(c.proof_json,'$.application.repository_identity.github_id'))))";
	}
	private function eligibleClaims( int $id, string $locale ): string { return 'c.claimant_user_id=' . $id . " AND c.claim_role IN ('author','maintainer') AND c.state='approved' AND c.approved_skill_key=c.skill_id AND c.reviewer_id>0 AND c.reviewed_at IS NOT NULL AND (c.expires_at IS NULL OR c.expires_at>UTC_TIMESTAMP()) AND c.source_revision=s.revision AND (c.repository_id<=>s.repository_id) AND (" . $this->sourceBinding() . ') AND c.skill_id IN (' . $this->publicIds( $locale ) . ')'; }
	public function publicProfile( string $handle, string $locale, array $filters = array() ): ?array {
		$r = $this->approved( $handle ); if ( ! $r || ! ( $text = $this->language( $r['snapshot'], $locale ) ) ) { return null; }
		[ $page, $limit, $offset ] = $this->page( $filters ); $s = $r['snapshot']; $from = $this->db->table( 'author_claims' ) . ' c INNER JOIN ' . $this->db->table( 'skills' ) . ' s ON s.id=c.skill_id'; $where = $this->eligibleClaims( $r['id'], $locale );
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $from WHERE $where" ); $rows = $offset >= $total ? array() : $this->db->rows( "SELECT c.skill_id,c.claim_role FROM $from WHERE $where ORDER BY c.reviewed_at DESC,c.id DESC LIMIT %d OFFSET %d", array( $limit, $offset ) );
		$cards = array_column( $this->catalog()->cardsByIds( array_map( 'intval', array_column( $rows, 'skill_id' ) ), $locale ), null, 'id' ); $items = array();
		foreach ( $rows as $row ) { if ( isset( $cards[ $row['skill_id'] ] ) ) { $items[] = array_merge( $cards[ $row['skill_id'] ], array( 'claim_role' => $row['claim_role'] ) ); } }
		$contributions = ! empty( $s['show_contributions'] ) ? apply_filters( 'aish_developer_public_contributions', array(), $r['id'], $locale ) : array(); if ( ! is_array( $contributions ) ) { $contributions = array(); }
		return array_merge( array( 'handle' => $handle, 'locale' => $locale, 'github_url' => (string) ( $s['github_url'] ?? '' ), 'website_url' => (string) ( $s['website_url'] ?? '' ), 'avatar' => null !== $this->avatar( $r['id'], $s['avatar'] ?? null ), 'updated_at' => $s['reviewed_at'], 'skills' => array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit ), 'contributions' => $contributions ), $text );
	}
	/** No attachment ID is accepted from the browser; this is the reviewed snapshot only. */
	private function avatar( int $user, mixed $avatar ): ?string {
		if ( ! is_array( $avatar ) || ! is_int( $avatar['attachment_id'] ?? null ) || ! is_string( $avatar['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $avatar['sha256'] ) ) { return null; }
		$c = $this->db->connection(); $id = $avatar['attachment_id'];
		$pointer = $this->db->rows( "SELECT meta_value FROM {$c->usermeta} WHERE user_id=%d AND meta_key='_aish_avatar_attachment_id'", array( $user ) );
		if ( array( (string) $id ) !== array_column( $pointer, 'meta_value' ) ) { return null; }
		$post = $this->db->row( "SELECT post_author,post_type,post_status,post_mime_type FROM {$c->posts} WHERE ID=%d", array( $id ) );
		if ( ! $post || (int) $post['post_author'] !== $user || 'attachment' !== $post['post_type'] || 'private' !== $post['post_status'] || 'image/png' !== $post['post_mime_type'] ) { return null; }
		$owners = $this->db->rows( "SELECT meta_value FROM {$c->postmeta} WHERE post_id=%d AND meta_key='_aish_avatar_owner'", array( $id ) ); $rows = $this->db->rows( "SELECT meta_value FROM {$c->postmeta} WHERE post_id=%d AND meta_key='_aish_avatar_bytes'", array( $id ) );
		if ( array( (string) $user ) !== array_column( $owners, 'meta_value' ) || 1 !== count( $rows ) || strlen( $rows[0]['meta_value'] ) > 349536 ) { return null; }
		$bytes = base64_decode( $rows[0]['meta_value'], true ); if ( ! is_string( $bytes ) || strlen( $bytes ) > 262144 || ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) || ! hash_equals( $avatar['sha256'], hash( 'sha256', $bytes ) ) ) { return null; } return $bytes;
	}
	public function publicAvatar( string $handle, string $locale ): ?array { $r = $this->approved( $handle ); if ( ! $r || ! $this->language( $r['snapshot'], $locale ) ) { return null; } $bytes = $this->avatar( $r['id'], $r['snapshot']['avatar'] ?? null ); return null === $bytes ? null : array( 'bytes' => $bytes, 'mime' => 'image/png' ); }
	public function skillContributors( int $skillId, string $locale ): array {
		$this->ready(); Input::id( $skillId ); if ( ! $this->publicSkillRevision( $skillId, $locale ) ) { return array(); }
		$c = $this->db->table( 'author_claims' ); $s = $this->db->table( 'skills' ); $u = $this->db->connection()->users;
		$rows = $this->db->rows( "SELECT u.user_nicename,c.claim_role,c.claimant_user_id FROM $c c INNER JOIN $s s ON s.id=c.skill_id INNER JOIN $u u ON u.ID=c.claimant_user_id WHERE c.skill_id=%d AND c.claim_role IN ('author','maintainer') AND c.state='approved' AND c.approved_skill_key=c.skill_id AND c.reviewer_id>0 AND c.reviewed_at IS NOT NULL AND c.source_revision=s.revision AND (c.repository_id<=>s.repository_id) AND (c.expires_at IS NULL OR c.expires_at>UTC_TIMESTAMP()) AND (" . $this->sourceBinding() . ') LIMIT 1', array( $skillId ) );
		$result = array(); foreach ( $rows as $row ) { $p = $this->approved( $row['user_nicename'] ); if ( $p && ( $t = $this->language( $p['snapshot'], $locale ) ) ) { $result[] = array( 'handle' => $row['user_nicename'], 'display_name' => $t['display_name'], 'claim_role' => $row['claim_role'] ); } } return $result;
	}
}
