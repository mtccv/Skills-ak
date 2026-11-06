<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Contributions;

use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Submissions\Store;

defined( 'ABSPATH' ) || exit;

/** Extends the existing identity-pinned account eraser; never owns another cleanup queue. */
final class Privacy {
	public const ERASING = '_aish_contributions_erasing';
	public const DETACHED_OWNER = '_aish_submission_erasure_owner';
	private const BATCH = 50;
	private const PROFILE_META = array( '_aish_profile_translations', '_aish_profile_candidate', '_aish_profile_approved_snapshot', '_aish_profile_reviewed_revision', '_aish_profile_reviewer_id', '_aish_profile_reviewed_at', '_aish_profile_feedback', '_aish_profile_private_note', '_aish_profile_updated_at' );
	private const CONTENT_META = array( '_aish_submission_payload', '_aish_canonical_repository', '_aish_suggested_source_path', '_aish_user_visible_feedback', '_aish_message_notes', '_aish_github_url', '_aish_sender_name', '_aish_sender_email' );
	private const PAYLOAD_FIELDS = array( 'kind', 'locale', 'source_kind', 'github_url', 'source_path', 'name', 'summary', 'categories', 'platforms', 'license', 'notes', 'target_skill_id', 'target_skill_revision', 'commit_sha', 'evidence' );
	private static bool $writing = false;
	private array $touched = array();
	private array $touchedUsers = array();

	public function __construct( private Database $db, private Locks $locks ) {}
	public static function writing(): bool { return self::$writing; }

	public function register(): void {
		add_filter( 'aish_account_privacy_export', array( $this, 'export' ), 20, 3 );
		add_action( 'aish_account_privacy_isolate', array( $this, 'isolate' ), 20 );
		add_filter( 'aish_account_privacy_erase_batch', array( $this, 'erase' ), 20, 2 );
		// P8 priority 1 has already created the durable cleanup request and isolated identity.
		add_action( 'delete_user', array( $this, 'beforeDelete' ), 2, 3 );
		add_action( 'wpmu_delete_user', function ( int $id, \WP_User $user ): void { $this->beforeDelete( $id, null, $user, true ); }, 2, 2 );
	}

	private function ready(): bool {
		$version = get_option( 'aish_schema_version', '0' );
		return is_string( $version ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/D', $version ) && version_compare( $version, '1.8.0', '>=' );
	}

	/** Mark first, then remove approved projections; partial failures stay publicly isolated. */
	public function isolate( int $id ): void {
		if ( ! $this->ready() ) { return; }
		$this->locks->withUser( $id, function () use ( $id ): void {
			if ( ! get_userdata( $id ) ) { return; }
			$this->nativeReady();
			$this->withWriting( function () use ( $id ): void {
				$this->userMeta( $id, self::ERASING, '1' );
				$this->clearProfile( $id );
			} );
		} );
	}

	/** Native reassignment must not transfer private Inbox ownership or its revisions. */
	public function beforeDelete( int $id, ?int $reassign = null, ?\WP_User $user = null, bool $networkDeletion = false ): void {
		if ( ! $this->ready() || ( is_multisite() && ! $networkDeletion ) ) { return; }
		$user = $user ?? get_userdata( $id );
		if ( ! $user instanceof \WP_User ) { return; }
		$this->locks->withUser( $id, function () use ( $id ): void {
			if ( ! $this->marked( $id, '_aish_account_deletion_pending' ) || ! $this->marked( $id, self::ERASING ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
			$remaining = $this->transaction( function () use ( $id ): bool {
				$native = $this->db->connection();
				$rows = $this->db->rows( "SELECT p.ID FROM {$native->posts} p WHERE " . $this->owned() . ' AND p.post_author=%d ORDER BY p.ID LIMIT 100 FOR UPDATE', array( $id ) );
				foreach ( $rows as $row ) {
					$post = (int) $row['ID'];
					$this->postMeta( $post, self::DETACHED_OWNER, (string) $id );
					$this->detach( $post );
				}
				return null !== $this->db->value( "SELECT p.ID FROM {$native->posts} p WHERE " . $this->owned() . ' AND p.post_author=%d LIMIT 1', array( $id ) );
			} );
			// Throw after commit so a large account makes bounded progress on the next attempt.
			if ( $remaining ) { throw new \RuntimeException( 'Account contribution cleanup requires another deletion attempt.' ); }
		} );
	}

	/** Only the already identity-pinned user's data; no historical email matching. */
	public function export( array $result, \WP_User $user, int $page ): array {
		if ( ! $this->ready() ) { return $result; }
		if ( ! current_user_can( 'export_others_personal_data' ) || $page < 1 || $page > 100000 ) { throw new \RuntimeException( 'privacy_forbidden' ); }
		return $this->locks->withUser( (int) $user->ID, function () use ( $result, $user, $page ): array {
			clean_user_cache( $user->ID ); $latest = get_userdata( $user->ID );
			if ( ! $latest instanceof \WP_User || strtolower( $latest->user_email ) !== strtolower( $user->user_email ) || $this->marked( (int) $user->ID, '_aish_account_deletion_pending' ) ) { throw new \RuntimeException( 'privacy_identity_changed' ); }
			$native = $this->db->connection(); $offset = ( $page - 1 ) * self::BATCH;
			$rows = $this->db->rows( "SELECT p.ID,p.post_title,p.post_excerpt,p.post_content,p.post_date_gmt FROM {$native->posts} p WHERE " . $this->owned() . ' AND p.post_author=%d ORDER BY p.ID LIMIT 50 OFFSET %d', array( $user->ID, $offset ) );
			if ( count( $rows ) === self::BATCH ) { $result['done'] = false; }
			$metadata = $this->batchMeta( array_column( $rows, 'ID' ) );
			foreach ( $rows as $row ) {
				$meta = $metadata[ (int) $row['ID'] ] ?? array();
				$this->item( $result, 'submissions', (int) $row['ID'], array( 'id' => $row['ID'], 'title' => $row['post_title'], 'summary' => $row['post_excerpt'], 'notes' => $row['post_content'], 'created_at' => $row['post_date_gmt'], 'state' => $meta['_aish_submission_state'] ?? '', 'feedback' => $meta['_aish_user_visible_feedback'] ?? '', 'payload' => $this->payload( $meta['_aish_submission_payload'] ?? '' ) ) );
			}
			// Native moderation snapshots also hold private notes: export only content snapshots.
			$snapshots = $this->db->rows( "SELECT r.ID,r.post_parent,r.post_content,r.post_date_gmt FROM {$native->posts} r INNER JOIN {$native->posts} p ON p.ID=r.post_parent WHERE " . $this->owned() . " AND p.post_author=%d AND r.post_type='revision' AND EXISTS (SELECT 1 FROM {$native->postmeta} k WHERE k.post_id=r.ID AND k.meta_key='_aish_submission_snapshot_kind' AND k.meta_value='content') ORDER BY r.ID LIMIT 50 OFFSET %d", array( $user->ID, $offset ) );
			if ( count( $snapshots ) === self::BATCH ) { $result['done'] = false; }
			foreach ( $snapshots as $row ) { $this->item( $result, 'submission-versions', (int) $row['ID'], array( 'submission_id' => $row['post_parent'], 'created_at' => $row['post_date_gmt'], 'payload' => $this->payload( $row['post_content'] ) ) ); }
			$claims = $this->db->table( 'author_claims' );
			$rows = $this->db->rows( "SELECT id,skill_id,claim_role,source_revision,state,proof_json,created_at,reviewed_at,expires_at FROM $claims WHERE claimant_user_id=%d ORDER BY id LIMIT 50 OFFSET %d", array( $user->ID, $offset ) );
			if ( count( $rows ) === self::BATCH ) { $result['done'] = false; }
			foreach ( $rows as $row ) {
				$proof = $this->json( $row['proof_json'] ); unset( $row['proof_json'] );
				$row['application'] = array_intersect_key( is_array( $proof['application'] ?? null ) ? $proof['application'] : array(), array_flip( array( 'locale', 'reason', 'evidence_urls', 'source_path', 'repository_identity' ) ) );
				$row['decisions'] = array();
				foreach ( is_array( $proof['decisions'] ?? null ) ? $proof['decisions'] : array() as $decision ) { if ( is_array( $decision ) ) { $row['decisions'][] = array_intersect_key( $decision, array_flip( array( 'decision', 'reason', 'at' ) ) ); } }
				$this->item( $result, 'claims', (int) $row['id'], $row );
			}
			$history = $this->db->rows( "SELECT umeta_id,meta_value FROM {$native->usermeta} WHERE user_id=%d AND meta_key='_aish_profile_decision' ORDER BY umeta_id LIMIT 50 OFFSET %d", array( $user->ID, $offset ) );
			if ( count( $history ) === self::BATCH ) { $result['done'] = false; }
			foreach ( $history as $row ) {
				$decision = $this->json( $row['meta_value'] );
				$fields = array_intersect_key( $decision, array_flip( array( 'revision', 'decision', 'reason', 'at' ) ) );
				$fields['snapshot'] = $this->profile( $decision['snapshot'] ?? array() );
				$this->item( $result, 'developer-history', (int) $row['umeta_id'], $fields );
			}
			if ( 1 === $page ) {
				$fields = array();
				foreach ( array( '_aish_profile_translations', '_aish_profile_candidate', '_aish_profile_review_status', '_aish_profile_feedback' ) as $key ) { $fields[ $key ] = get_user_meta( $user->ID, $key, true ); }
				$fields['_aish_profile_approved_snapshot'] = $this->profile( get_user_meta( $user->ID, '_aish_profile_approved_snapshot', true ) );
				$this->item( $result, 'developer-profile', (int) $user->ID, $fields );
			}
			return $result;
		} );
	}

	/** Account lock precedes the shared submission lock, then row locks; one bounded batch. */
	public function erase( bool $done, int $id ): bool {
		if ( ! $this->ready() ) { return $done; }
		if ( $id < 1 ) { throw new \RuntimeException( 'privacy_forbidden' ); }
		return $this->locks->withUser( $id, function () use ( $done, $id ): bool {
			if ( get_userdata( $id ) && ! $this->marked( $id, self::ERASING ) ) { throw new \RuntimeException( 'privacy_forbidden' ); }
			$finished = $this->transaction( function () use ( $id ): bool {
				$this->clearProfile( $id );
				$native = $this->db->connection();
				$this->touchedUsers[ $id ] = true;
				$this->db->execute( "DELETE FROM {$native->usermeta} WHERE user_id=%d AND meta_key='_aish_profile_decision' ORDER BY umeta_id LIMIT 50", array( $id ) );
				$profileDone = null === $this->db->value( "SELECT umeta_id FROM {$native->usermeta} WHERE user_id=%d AND meta_key='_aish_profile_decision' LIMIT 1", array( $id ) );
				$where = $this->owned() . " AND (p.post_author=%d OR EXISTS (SELECT 1 FROM {$native->postmeta} o WHERE o.post_id=p.ID AND o.meta_key=%s AND o.meta_value=%s))";
				$values = array( $id, self::DETACHED_OWNER, (string) $id );
				$heads = $this->db->rows( "SELECT p.ID FROM {$native->posts} p WHERE $where ORDER BY p.ID LIMIT 10 FOR UPDATE", $values );
				foreach ( $heads as $row ) { $this->eraseSubmission( (int) $row['ID'], $id ); }
				$finished = null === $this->db->value( "SELECT p.ID FROM {$native->posts} p WHERE $where LIMIT 1", $values );
				$claims = $this->db->table( 'author_claims' );
				$this->db->execute( "UPDATE $claims SET claimant_user_id=NULL,state=CASE WHEN state IN ('approved','disputed','revoked') THEN 'revoked' ELSE 'withdrawn' END,proof_json=%s,active_request_key=NULL,approved_skill_key=NULL,reviewer_id=NULL,updated_at=%s WHERE claimant_user_id=%d ORDER BY id LIMIT 50", array( '{"v":1,"application":{},"decisions":[]}', gmdate( 'Y-m-d H:i:s' ), $id ) );
				$finished = null === $this->db->value( "SELECT id FROM $claims WHERE claimant_user_id=%d LIMIT 1", array( $id ) ) && $finished;
				$claimsDone = $this->eraseModeratorNotes( $id );
				$profileNotesDone = $this->eraseProfileModeratorNotes( $id );
				$submissionNotesDone = $this->eraseSubmissionModeratorNotes( $id );
				return $claimsDone && $profileNotesDone && $submissionNotesDone && $profileDone && $finished;
			} );
			if ( $done && $finished ) { $this->withWriting( function () use ( $id ): void { $this->deleteUserMeta( $id, self::ERASING ); } ); }
			return $done && $finished;
		} );
	}

	private function eraseSubmission( int $post, int $user ): void {
		$native = $this->db->connection();
		$this->postMeta( $post, self::DETACHED_OWNER, (string) $user );
		$this->touch( $post );
		$this->db->execute( "UPDATE {$native->posts} SET post_author=0,post_status='private',post_title='',post_excerpt='',post_content='',post_name=%s,post_password='' WHERE ID=%d", array( 'aish-erased-' . $post, $post ) );
		$this->postMeta( $post, '_aish_submission_state', 'withdrawn' );
		$keys = implode( ',', array_fill( 0, count( self::CONTENT_META ), '%s' ) );
		$this->db->execute( "DELETE FROM {$native->postmeta} WHERE post_id=%d AND meta_key IN ($keys)", array_merge( array( $post ), self::CONTENT_META ) );
		$needs = "(post_author<>0 OR post_title<>'' OR post_excerpt<>'' OR post_content<>'' OR post_password<>'')";
		$revisions = $this->db->rows( "SELECT ID FROM {$native->posts} WHERE post_parent=%d AND post_type='revision' AND $needs ORDER BY ID LIMIT 50 FOR UPDATE", array( $post ) );
		foreach ( $revisions as $revision ) {
			$child = (int) $revision['ID']; $this->touch( $child );
			$this->db->execute( "UPDATE {$native->posts} SET post_author=0,post_title='',post_excerpt='',post_content='',post_name=%s,post_password='' WHERE ID=%d", array( 'aish-erased-revision-' . $child, $child ) );
		}
		if ( null === $this->db->value( "SELECT ID FROM {$native->posts} WHERE post_parent=%d AND post_type='revision' AND $needs LIMIT 1", array( $post ) ) ) {
			$this->db->execute( "DELETE FROM {$native->postmeta} WHERE post_id=%d AND meta_key=%s", array( $post, self::DETACHED_OWNER ) );
		}
	}

	/** Preserve minimal historical reviewer/time evidence, erase the reviewer's private text. */
	private function eraseModeratorNotes( int $id ): bool {
		$table = $this->db->table( 'author_claims' ); $parts = array(); $values = array();
		// The claim contract permits at most three decisions; malformed JSON is never evaluated.
		$json = "CASE WHEN JSON_VALID(proof_json) THEN proof_json ELSE '{}' END";
		for ( $i = 0; $i < 3; ++$i ) {
			$parts[] = "(JSON_EXTRACT($json,'$.decisions[$i].reviewer_id')=%d AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($json,'$.decisions[$i].private_note')),'')<>'')";
			$values[] = $id;
		}
		$where = '(' . implode( ' OR ', $parts ) . ')';
		$rows = $this->db->rows( "SELECT id,proof_json FROM $table WHERE $where ORDER BY id LIMIT 50 FOR UPDATE", $values );
		foreach ( $rows as $row ) {
			$proof = $this->json( $row['proof_json'] );
			foreach ( $proof['decisions'] ?? array() as $index => $decision ) { if ( is_array( $decision ) && (int) ( $decision['reviewer_id'] ?? 0 ) === $id ) { $proof['decisions'][ $index ]['private_note'] = ''; } }
			$this->db->execute( "UPDATE $table SET proof_json=%s WHERE id=%d", array( (string) wp_json_encode( $proof ), $row['id'] ) );
		}
		return null === $this->db->value( "SELECT id FROM $table WHERE $where LIMIT 1", $values );
	}

	private function clearProfile( int $id ): void {
		if ( get_userdata( $id ) ) {
			$this->userMeta( $id, '_aish_public_profile', '0' );
			$this->userMeta( $id, '_aish_profile_review_status', 'withdrawn' );
		} else {
			foreach ( array( '_aish_public_profile', '_aish_profile_revision', '_aish_profile_review_status' ) as $key ) { $this->deleteUserMeta( $id, $key ); }
		}
		foreach ( self::PROFILE_META as $key ) { $this->deleteUserMeta( $id, $key ); }
	}

	/** Profile history is JSON, allowing bounded actor-specific erasure without copying drafts. */
	private function eraseProfileModeratorNotes( int $id ): bool {
		$meta = $this->db->connection()->usermeta;
		$json = "CASE WHEN JSON_VALID(meta_value) THEN meta_value ELSE '{}' END";
		$where = "meta_key='_aish_profile_decision' AND JSON_EXTRACT($json,'$.reviewer_id')=%d AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($json,'$.private_note')),'')<>''";
		$rows = $this->db->rows( "SELECT umeta_id,user_id,meta_value FROM $meta WHERE $where ORDER BY umeta_id LIMIT 50 FOR UPDATE", array( $id ) );
		foreach ( $rows as $row ) {
			$decision = $this->json( $row['meta_value'] ); $decision['private_note'] = '';
			$this->touchedUsers[ (int) $row['user_id'] ] = true;
			$this->db->execute( "UPDATE $meta SET meta_value=%s WHERE umeta_id=%d", array( (string) wp_json_encode( $decision ), $row['umeta_id'] ) );
		}
		$finished = null === $this->db->value( "SELECT umeta_id FROM $meta WHERE $where LIMIT 1", array( $id ) );
		$current = "FROM $meta n INNER JOIN $meta r ON r.user_id=n.user_id AND r.meta_key='_aish_profile_reviewer_id' WHERE r.meta_value=%s AND n.meta_key='_aish_profile_private_note' AND n.meta_value<>''";
		$rows = $this->db->rows( "SELECT n.umeta_id,n.user_id $current ORDER BY n.umeta_id LIMIT 50 FOR UPDATE", array( (string) $id ) );
		foreach ( $rows as $row ) { $this->touchedUsers[ (int) $row['user_id'] ] = true; $this->db->execute( "UPDATE $meta SET meta_value='' WHERE umeta_id=%d", array( $row['umeta_id'] ) ); }
		return null === $this->db->value( "SELECT n.umeta_id $current LIMIT 1", array( (string) $id ) ) && $finished;
	}

	/** A moderator's erasure cannot alter the other submitter's text or review outcome. */
	private function eraseSubmissionModeratorNotes( int $id ): bool {
		$native = $this->db->connection();
		$json = "CASE WHEN JSON_VALID(r.post_content) THEN r.post_content ELSE '{}' END";
		$where = $this->owned() . " AND r.post_type='revision' AND EXISTS (SELECT 1 FROM {$native->postmeta} k WHERE k.post_id=r.ID AND k.meta_key='_aish_submission_snapshot_kind' AND k.meta_value='moderation') AND JSON_EXTRACT($json,'$.handler_id')=%d AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($json,'$.private_note')),'')<>''";
		$from = "FROM {$native->posts} r INNER JOIN {$native->posts} p ON p.ID=r.post_parent";
		$rows = $this->db->rows( "SELECT r.ID,r.post_content $from WHERE $where ORDER BY r.ID LIMIT 50 FOR UPDATE", array( $id ) );
		foreach ( $rows as $row ) {
			$decision = $this->json( $row['post_content'] ); $decision['private_note'] = ''; $this->touch( (int) $row['ID'] );
			$this->db->execute( "UPDATE {$native->posts} SET post_content=%s WHERE ID=%d", array( (string) wp_json_encode( $decision ), $row['ID'] ) );
		}
		$finished = null === $this->db->value( "SELECT r.ID $from WHERE $where LIMIT 1", array( $id ) );
		$current = "FROM {$native->posts} p INNER JOIN {$native->postmeta} h ON h.post_id=p.ID AND h.meta_key='_aish_handler_id' INNER JOIN {$native->postmeta} n ON n.post_id=p.ID AND n.meta_key='_aish_message_notes' WHERE " . $this->owned() . " AND h.meta_value=%s AND n.meta_value<>''";
		$rows = $this->db->rows( "SELECT n.meta_id,p.ID $current ORDER BY n.meta_id LIMIT 50 FOR UPDATE", array( (string) $id ) );
		foreach ( $rows as $row ) { $this->touch( (int) $row['ID'] ); $this->db->execute( "UPDATE {$native->postmeta} SET meta_value='' WHERE meta_id=%d", array( $row['meta_id'] ) ); }
		return null === $this->db->value( "SELECT n.meta_id $current LIMIT 1", array( (string) $id ) ) && $finished;
	}

	private function detach( int $post ): void {
		$native = $this->db->connection(); $this->touch( $post );
		$children = $this->db->rows( "SELECT ID FROM {$native->posts} WHERE post_parent=%d AND post_type='revision'", array( $post ) );
		foreach ( $children as $child ) { $this->touch( (int) $child['ID'] ); }
		$this->db->execute( "UPDATE {$native->posts} SET post_author=0 WHERE ID=%d OR (post_parent=%d AND post_type='revision')", array( $post, $post ) );
	}

	private function owned(): string {
		$meta = $this->db->connection()->postmeta;
		return "p.post_type='aish_message' AND EXISTS (SELECT 1 FROM $meta m WHERE m.post_id=p.ID AND m.meta_key='_aish_submission_version' AND m.meta_value='1') AND EXISTS (SELECT 1 FROM $meta k WHERE k.post_id=p.ID AND k.meta_key='_aish_message_kind' AND k.meta_value IN ('submit','update_suggestion'))";
	}

	private function transaction( callable $operation ): mixed {
		$this->touched = array(); $this->touchedUsers = array();
		try { return ( new Store( $this->db ) )->transaction( function () use ( $operation ): mixed { $this->nativeReady(); return $this->withWriting( $operation ); } ); }
		finally {
			// Store restores the caller's cache mode first. Privacy also writes raw
			// rows, so its own cache cleanup must run even if that caller suspended it.
			$previousInvalidation = wp_suspend_cache_invalidation( false );
			try {
				foreach ( array_keys( $this->touched ) as $id ) { wp_cache_delete( $id, 'posts' ); clean_post_cache( $id ); wp_cache_delete( $id, 'post_meta' ); }
				foreach ( array_keys( $this->touchedUsers ) as $id ) { clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); }
			} finally {
				wp_suspend_cache_invalidation( $previousInvalidation );
				$this->touched = array(); $this->touchedUsers = array();
			}
		}
	}

	private function withWriting( callable $operation ): mixed {
		$previous = self::$writing; self::$writing = true;
		try { return $operation(); } finally { self::$writing = $previous; }
	}
	private function touch( int $id ): void { $this->touched[ $id ] = true; wp_cache_delete( $id, 'post_meta' ); }
	private function nativeReady(): void {
		$native = $this->db->connection();
		foreach ( array( $native->posts, $native->postmeta, $native->usermeta ) as $table ) {
			if ( 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) ) ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
		}
	}
	private function marked( int $id, string $key ): bool {
		return null !== $this->db->value( 'SELECT umeta_id FROM ' . $this->db->connection()->usermeta . " WHERE user_id=%d AND meta_key=%s AND meta_value<>'' AND meta_value<>'0' LIMIT 1", array( $id, $key ) );
	}
	private function userMeta( int $id, string $key, string $value ): void {
		wp_cache_delete( $id, 'user_meta' ); update_user_meta( $id, $key, $value ); wp_cache_delete( $id, 'user_meta' );
		$values = get_user_meta( $id, $key, false );
		if ( ! $values || array_filter( $values, static fn( $stored ): bool => $stored !== $value ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
	}
	private function deleteUserMeta( int $id, string $key ): void {
		delete_user_meta( $id, $key ); wp_cache_delete( $id, 'user_meta' );
		if ( get_user_meta( $id, $key, false ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
	}
	private function postMeta( int $id, string $key, string $value ): void {
		$this->touch( $id ); update_post_meta( $id, $key, wp_slash( $value ) );
		$rows = $this->db->rows( 'SELECT meta_value FROM ' . $this->db->connection()->postmeta . ' WHERE post_id=%d AND meta_key=%s', array( $id, $key ) );
		if ( 1 !== count( $rows ) || $rows[0]['meta_value'] !== $value ) { throw new \RuntimeException( 'privacy_unavailable' ); }
	}
	private function batchMeta( array $ids ): array {
		if ( ! $ids ) { return array(); }
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) ); $result = array();
		foreach ( $this->db->rows( 'SELECT post_id,meta_key,meta_value FROM ' . $this->db->connection()->postmeta . " WHERE post_id IN ($marks) AND meta_key IN ('_aish_submission_state','_aish_user_visible_feedback','_aish_submission_payload')", $ids ) as $row ) { $result[ (int) $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value']; }
		return $result;
	}
	private function json( string $json ): array {
		if ( strlen( $json ) > 65535 ) { return array(); }
		try { $data = json_decode( $json, true, 32, JSON_THROW_ON_ERROR ); return is_array( $data ) ? $data : array(); } catch ( \JsonException $error ) { return array(); }
	}
	private function payload( string $json ): array { return array_intersect_key( $this->json( $json ), array_flip( self::PAYLOAD_FIELDS ) ); }
	private function profile( mixed $data ): array { return is_array( $data ) ? array_intersect_key( $data, array_flip( array( 'handle', 'translations', 'github_url', 'website_url', 'public_avatar', 'show_contributions', 'avatar', 'revision', 'reviewed_at' ) ) ) : array(); }
	private function item( array &$result, string $kind, int $id, array $fields ): void {
		$data = array(); foreach ( $fields as $key => $value ) { $data[] = array( 'name' => $key, 'value' => is_array( $value ) ? (string) wp_json_encode( $value ) : (string) $value ); }
		$result['data'][] = array( 'group_id' => 'aish-contribution-' . $kind, 'group_label' => 'AI Skills Hub ' . str_replace( '-', ' ', $kind ), 'item_id' => 'aish-contribution-' . $kind . '-' . $id, 'data' => $data );
	}
}
