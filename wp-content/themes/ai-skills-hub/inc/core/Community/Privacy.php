<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Extends the identity-pinned native privacy workflow, not a second deletion queue. */
final class Privacy {
	public const ERASING = '_aish_community_erasing';
	private const BATCH = 50;

	public function __construct( private Database $db, private Locks $locks ) {}

	public function register(): void {
		add_filter( 'aish_account_privacy_export', array( $this, 'export' ), 10, 3 );
		add_action( 'aish_account_privacy_isolate', array( $this, 'isolate' ) );
		add_filter( 'aish_account_privacy_erase_batch', array( $this, 'erase' ), 10, 2 );
	}

	private function ready(): bool {
		$version = get_option( 'aish_schema_version', '0' );
		if ( ! is_string( $version ) || ! preg_match( '/^\d+\.\d+\.\d+$/D', $version ) ) { return false; }
		return version_compare( $version, '1.7.0', '>=' );
	}

	/** The account lock is reentrant: native hooks and the eraser may already hold it. */
	public function isolate( int $id ): void {
		if ( ! $this->ready() ) { return; }
		$this->locks->withUser( $id, static function () use ( $id ): void {
			if ( ! get_userdata( $id ) ) { return; }
			delete_user_meta( $id, self::ERASING );
			if ( false === add_user_meta( $id, self::ERASING, '1', true ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
			wp_cache_delete( $id, 'user_meta' );
			if ( array( '1' ) !== get_user_meta( $id, self::ERASING, false ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
		} );
	}

	/** Export only the pinned account's fields; never join a private Skill title or another author. */
	public function export( array $result, \WP_User $user, int $page ): array {
		if ( ! $this->ready() ) { return $result; }
		if ( ! current_user_can( 'export_others_personal_data' ) || $page < 1 || $page > 100000 ) { throw new \RuntimeException( 'privacy_forbidden' ); }
		return $this->locks->withUser( (int) $user->ID, function () use ( $result, $user, $page ): array {
			clean_user_cache( $user->ID );
			$latest = get_userdata( $user->ID );
			if ( ! $latest instanceof \WP_User || strtolower( $latest->user_email ) !== strtolower( $user->user_email ) || '1' === get_user_meta( $user->ID, '_aish_account_deletion_pending', true ) ) { throw new \RuntimeException( 'privacy_identity_changed' ); }
			$offset = ( $page - 1 ) * self::BATCH;
			$favorites = $this->db->table( 'favorites' ); $reviews = $this->db->table( 'reviews' ); $revisions = $this->db->table( 'review_revisions' );
			$groups = array(
				'favorites' => array( 'AI Skills Hub private favorites', $this->db->rows( "SELECT skill_id,is_active,created_at,updated_at,cancelled_at FROM $favorites WHERE user_id=%d ORDER BY skill_id LIMIT 50 OFFSET %d", array( $user->ID, $offset ) ) ),
				'reviews' => array( 'AI Skills Hub own review revisions', $this->db->rows( "SELECT v.id,r.id AS review_id,r.skill_id,r.state,r.approved_revision_id,r.pending_revision_id,v.revision,v.locale,v.rating,v.title,v.comment,v.experience,v.pros,v.cons,v.platform,v.version_label,v.moderation_state,v.moderation_code,v.created_at FROM $reviews r INNER JOIN $revisions v ON v.review_id=r.id WHERE r.user_id=%d ORDER BY v.id LIMIT 50 OFFSET %d", array( $user->ID, $offset ) ) ),
			);
			foreach ( array( 'review_reports' => 'review_id,revision_id,reason_code', 'skill_reports' => 'skill_id,skill_revision,kind' ) as $entity => $columns ) {
				$table = $this->db->table( $entity );
				$groups[ $entity ] = array( 'AI Skills Hub own ' . str_replace( '_', ' ', $entity ), $this->db->rows( "SELECT id,$columns,locale,details,state,resolution_code,created_at,resolved_at FROM $table WHERE reporter_user_id=%d ORDER BY id LIMIT 50 OFFSET %d", array( $user->ID, $offset ) ) );
			}
			foreach ( $groups as $kind => [ $label, $rows ] ) {
				if ( count( $rows ) === self::BATCH ) { $result['done'] = false; }
				foreach ( $rows as $row ) {
					$fields = array(); foreach ( $row as $name => $value ) { $fields[] = array( 'name' => $name, 'value' => (string) $value ); }
					$result['data'][] = array( 'group_id' => 'aish-community-' . $kind, 'group_label' => $label, 'item_id' => 'aish-community-' . $kind . '-' . ( $row['id'] ?? $row['skill_id'] ), 'data' => $fields );
				}
			}
			return $result;
		} );
	}

	/** Bounded batches are incorporated into the account request's durable completion state. */
	public function erase( bool $done, int $id ): bool {
		if ( ! $this->ready() ) { return $done; }
		if ( $id < 1 ) { throw new \RuntimeException( 'privacy_forbidden' ); }
		return $this->locks->withUser( $id, function () use ( $done, $id ): bool {
			if ( get_userdata( $id ) && '1' !== get_user_meta( $id, self::ERASING, true ) ) { throw new \RuntimeException( 'privacy_forbidden' ); }
			$finished = $this->db->transaction( function () use ( $id ): bool {
				$favorites = $this->db->table( 'favorites' );
				$this->db->execute( "DELETE FROM $favorites WHERE user_id=%d LIMIT 50", array( $id ) );
				$finished = null === $this->db->value( "SELECT skill_id FROM $favorites WHERE user_id=%d LIMIT 1", array( $id ) );
				$reviews = $this->db->table( 'reviews' ); $revisions = $this->db->table( 'review_revisions' ); $skills = $this->db->table( 'skills' );
				$heads = $this->db->rows( "SELECT id,skill_id FROM $reviews WHERE user_id=%d ORDER BY id LIMIT 10", array( $id ) );
				if ( $heads ) {
					$skillIds = array_values( array_unique( array_map( 'intval', array_column( $heads, 'skill_id' ) ) ) ); sort( $skillIds );
					$placeholders = implode( ',', array_fill( 0, count( $skillIds ), '%d' ) );
					$this->db->rows( "SELECT id FROM $skills WHERE id IN ($placeholders) ORDER BY id FOR UPDATE", $skillIds );
					foreach ( $heads as $head ) {
						$reviewId = (int) $head['id'];
						$locked = $this->db->row( "SELECT id FROM $reviews WHERE id=%d AND user_id=%d FOR UPDATE", array( $reviewId, $id ) );
						if ( ! $locked ) { continue; }
						$this->db->execute( "UPDATE $reviews SET state='withdrawn',approved_revision_id=NULL,pending_revision_id=NULL,lock_version=lock_version+1,withdrawn_at=COALESCE(withdrawn_at,%s),updated_at=%s WHERE id=%d AND (state<>'withdrawn' OR approved_revision_id IS NOT NULL OR pending_revision_id IS NOT NULL)", array( gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ), $reviewId ) );
						$needs = "(moderation_state<>'withdrawn' OR moderation_code IS NULL OR moderation_code<>'privacy_erased' OR title<>'' OR comment<>'' OR experience<>'' OR pros<>'' OR cons<>'' OR platform<>'' OR version_label<>'' OR moderator_id IS NOT NULL OR private_note IS NOT NULL)";
						$this->db->execute( "UPDATE $revisions SET title='',comment='',experience='',pros='',cons='',platform='',version_label='',moderation_state='withdrawn',moderation_code='privacy_erased',moderator_id=NULL,private_note=NULL,request_key=SHA2(CONCAT('aish-erased-review-revision:',id),256) WHERE review_id=%d AND $needs ORDER BY id LIMIT 50", array( $reviewId ) );
						if ( null === $this->db->value( "SELECT id FROM $revisions WHERE review_id=%d AND $needs LIMIT 1", array( $reviewId ) ) ) {
							$this->db->execute( "UPDATE $reviews SET user_id=NULL,lock_version=lock_version+1,updated_at=%s WHERE id=%d AND user_id=%d", array( gmdate( 'Y-m-d H:i:s' ), $reviewId, $id ) );
						}
					}
				}
				$finished = null === $this->db->value( "SELECT id FROM $reviews WHERE user_id=%d LIMIT 1", array( $id ) ) && $finished;
				foreach ( array( 'review_reports', 'skill_reports' ) as $entity ) {
					$table = $this->db->table( $entity );
					$this->db->execute( "UPDATE $table SET reporter_user_id=NULL,details='',private_note=NULL,active_key=NULL,state='closed',resolution_code='privacy_erased',resolved_at=COALESCE(resolved_at,%s) WHERE reporter_user_id=%d ORDER BY id LIMIT 50", array( gmdate( 'Y-m-d H:i:s' ), $id ) );
					$this->db->execute( "UPDATE $table SET handler_id=NULL,private_note=NULL WHERE handler_id=%d ORDER BY id LIMIT 50", array( $id ) );
					$finished = null === $this->db->value( "SELECT id FROM $table WHERE reporter_user_id=%d OR handler_id=%d LIMIT 1", array( $id, $id ) ) && $finished;
				}
				// An immutable approval retains its minimal historical numeric actor, just
				// like Skill approval evidence. Removing it would hide another user's review.
				$this->db->execute( "UPDATE $revisions SET moderator_id=NULL,private_note=NULL WHERE moderator_id=%d AND moderation_state<>'approved' ORDER BY id LIMIT 50", array( $id ) );
				$this->db->execute( "UPDATE $revisions SET private_note=NULL WHERE moderator_id=%d AND moderation_state='approved' AND private_note IS NOT NULL ORDER BY id LIMIT 50", array( $id ) );
				$finished = null === $this->db->value( "SELECT id FROM $revisions WHERE moderator_id=%d AND (moderation_state<>'approved' OR private_note IS NOT NULL) LIMIT 1", array( $id ) ) && $finished;
				$events = $this->db->table( 'workflow_events' );
				$this->db->execute( "UPDATE $events SET actor_user_id=NULL WHERE actor_user_id=%d ORDER BY id LIMIT 50", array( $id ) );
				return null === $this->db->value( "SELECT id FROM $events WHERE actor_user_id=%d LIMIT 1", array( $id ) ) && $finished;
			} );
			if ( $finished && $done ) {
				delete_user_meta( $id, self::ERASING ); wp_cache_delete( $id, 'user_meta' );
				if ( get_user_meta( $id, self::ERASING, false ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
			}
			return $finished && $done;
		} );
	}

	/** Shared maintenance invokes this; keys survive anonymization so old requests cannot replay. */
	public function retention(): void {
		if ( ! $this->ready() ) { return; }
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS );
		foreach ( array( 'review_reports', 'skill_reports' ) as $entity ) {
			$table = $this->db->table( $entity );
			$this->db->execute( "UPDATE $table SET reporter_user_id=NULL,handler_id=NULL,details='',private_note=NULL,active_key=NULL WHERE state IN ('handled','closed') AND resolved_at<%s AND (reporter_user_id IS NOT NULL OR handler_id IS NOT NULL OR details<>'' OR private_note IS NOT NULL OR active_key IS NOT NULL) ORDER BY id LIMIT 50", array( $cutoff ) );
		}
		$events = $this->db->table( 'workflow_events' );
		$this->db->execute( "UPDATE $events SET actor_user_id=NULL,metadata_json=CASE WHEN subject_type='skill' AND action='public_content_approved' THEN metadata_json ELSE '{}' END,retain_until=NULL WHERE retain_until IS NOT NULL AND retain_until<%s ORDER BY id LIMIT 50", array( gmdate( 'Y-m-d H:i:s' ) ) );
	}
}
