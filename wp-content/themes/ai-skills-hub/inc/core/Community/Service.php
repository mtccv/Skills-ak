<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Store;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** Verified native identity, immutable user revisions and atomic manual moderation. */
final class Service {
	private Query $query;
	private Events $events;
	private Metrics $metrics;
	private array $lockedUsers = array();
	public function __construct( private Database $db, private Migrator $migrator, private AccountService $accounts, private Locks $locks, private Store $notifications ) {
		$this->query = new Query( $db, $migrator, $accounts ); $this->events = new Events( $db ); $this->metrics = new Metrics( $db );
	}
	private static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }
	private function actor( ?string $cap ): int {
		if ( ! $this->migrator->isCurrent() ) { throw new Exception( 'storage_unavailable' ); }
		try { $user = $this->accounts->requireOwner( null === $cap ); } catch ( \AISkillsHub\Core\Accounts\AccountException $error ) { throw new Exception( $error->reason() ); }
		$id = (int) $user->ID;
		if ( null !== $cap && ! current_user_can( $cap ) ) { throw new Exception( 'forbidden' ); }
		if ( $this->query->erasing( $id ) ) { throw new Exception( 'forbidden' ); }
		return $id;
	}
	private function head( int $id ): array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'reviews' ) . ' WHERE id=%d FOR UPDATE', array( Input::id( $id ) ) );
		if ( ! $row ) { throw new Exception( 'unavailable' ); } return $row;
	}
	private function reviewSkill( int $id ): int {
		$value = $this->db->value( 'SELECT skill_id FROM ' . $this->db->table( 'reviews' ) . ' WHERE id=%d', array( Input::id( $id ) ) );
		if ( null === $value ) { throw new Exception( 'unavailable' ); } return (int) $value;
	}
	private function skill( int $skillId, ?string $locale = null ): array {
		$row = $this->db->row( 'SELECT id,revision FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d FOR UPDATE', array( $skillId ) );
		if ( ! $row ) { throw new Exception( 'unavailable' ); }
		if ( null !== $locale && null === $this->query->publicSkillRevision( $skillId, $locale ) ) { throw new Exception( 'unavailable' ); }
		return $row;
	}
	private function version( array $head, int $expected ): void { if ( (int) $head['lock_version'] !== $expected ) { throw new Exception( 'conflict' ); } }
	private function rate( int $userId, string $action ): void {
		[ $seconds, $limit ] = match ( $action ) {
			'favorite_set' => array( 900, 60 ), 'review_submit' => array( DAY_IN_SECONDS, 20 ),
			'review_report', 'skill_report' => array( DAY_IN_SECONDS, 20 ), default => array( 900, 100 ),
		};
		$count = (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'workflow_events' ) . ' WHERE actor_user_id=%d AND action=%s AND created_at>=%s', array( $userId, $action, gmdate( 'Y-m-d H:i:s', time() - $seconds ) ) );
		if ( $count >= $limit ) { throw new Exception( 'rate_limited' ); }
	}
	/** No callback here can send mail or start a nested transaction. */
	private function write( string $action, string $request, array $payload, int $skillId, ?string $cap, callable $operation, callable $replay, array $relatedUsers = array() ): array {
		Input::request( $request ); Input::id( $skillId ); $actor = $this->actor( $cap );
		$key = hash_hmac( 'sha256', 'community:' . $actor . ':' . $action . ':' . $request, wp_salt( 'auth' ) );
		$fingerprint = hash( 'sha256', (string) wp_json_encode( $payload ) );
		try {
			$usersToLock = array_values( array_unique( array_merge( array( $actor ), array_map( array( Input::class, 'id' ), $relatedUsers ) ) ) ); sort( $usersToLock, SORT_NUMERIC );
			$result = $this->withUsers( $usersToLock, function () use ( $actor, $action, $key, $fingerprint, $skillId, $cap, $operation, $replay, $usersToLock ): array {
				return $this->db->transaction( function () use ( $actor, $action, $key, $fingerprint, $skillId, $cap, $operation, $replay, $usersToLock ): array {
					$users = $this->db->connection()->users;
					foreach ( $usersToLock as $userId ) {
						$exists = $this->db->row( "SELECT ID FROM $users WHERE ID=%d FOR UPDATE", array( $userId ) );
						if ( $userId === $actor && ! $exists ) { throw new Exception( 'forbidden' ); }
						wp_cache_delete( $userId, 'user_meta' );
					}
					if ( $actor !== $this->actor( $cap ) ) { throw new Exception( 'forbidden' ); }
					$this->skill( $skillId );
					$event = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'workflow_events' ) . ' WHERE event_key=%s', array( $key ) );
					if ( $event ) {
						$metadata = json_decode( (string) $event['metadata_json'], true );
						if ( ! is_array( $metadata ) || ! isset( $metadata['payload_hash'] ) ) { throw new Exception( 'expired_request' ); }
						if ( ! hash_equals( $fingerprint, (string) $metadata['payload_hash'] ) ) { throw new Exception( 'conflict' ); }
						return $replay( $event, $actor );
					}
					$this->rate( $actor, $action );
					return $operation( $actor, $key, $fingerprint );
				} );
			} );
		} catch ( Exception $error ) { throw $error; }
		catch ( \AISkillsHub\Core\Accounts\AccountException $error ) { throw new Exception( $error->reason() ); }
		catch ( \Throwable $error ) { throw new Exception( 'storage_unavailable' ); }
		// No persistent caches exist for these facts. Hooks run only after commit.
		try { do_action( 'aish_community_changed', $skillId ); } catch ( \Throwable $error ) {}
		return $result;
	}
	/** Multiple identities are always locked in numeric order before any Skill row. */
	private function withUsers( array $ids, callable $operation ): array {
		$release = array();
		try {
			foreach ( $ids as $id ) { $release[] = $this->locks->acquire( array(), $id ); }
			$this->lockedUsers = $ids;
			return $operation();
		} finally {
			$this->lockedUsers = array();
			foreach ( array_reverse( $release ) as $unlock ) { $unlock(); }
		}
	}
	private function favoriteResult( int $actor, int $skillId ): array {
		$row = $this->db->row( 'SELECT skill_id,is_active,created_at,updated_at,cancelled_at FROM ' . $this->db->table( 'favorites' ) . ' WHERE user_id=%d AND skill_id=%d', array( $actor, $skillId ) );
		return $row ? array_merge( $row, array( 'skill_id' => $skillId, 'is_active' => (bool) $row['is_active'] ) ) : array( 'skill_id' => $skillId, 'is_active' => false, 'created_at' => null );
	}
	public function setFavorite( int $skillId, bool $active, string $locale, string $requestKey ): array {
		Input::locale( $locale );
		return $this->write( 'favorite_set', $requestKey, array( $skillId, $active, $locale ), $skillId, null, function ( int $actor, string $key, string $hash ) use ( $skillId, $active, $locale ): array {
			if ( $active ) { $this->skill( $skillId, $locale ); }
			$table = $this->db->table( 'favorites' ); $now = self::now();
			$row = $this->db->row( "SELECT * FROM $table WHERE user_id=%d AND skill_id=%d FOR UPDATE", array( $actor, $skillId ) );
			$wasActive = $row && 1 === (int) $row['is_active'];
			if ( ! $row && $active ) { $this->db->insert( 'favorites', array( 'user_id' => $actor, 'skill_id' => $skillId, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now, 'cancelled_at' => null ) ); }
			elseif ( $row && $wasActive !== $active ) { $this->db->execute( "UPDATE $table SET is_active=%d,updated_at=%s,cancelled_at=" . ( $active ? 'NULL' : '%s' ) . ' WHERE user_id=%d AND skill_id=%d', $active ? array( 1, $now, $actor, $skillId ) : array( 0, $now, $now, $actor, $skillId ) ); }
			if ( $wasActive !== $active ) { $this->metrics->fact( $active ? 'favorite_add' : 'favorite_remove', $skillId, $actor ); }
			$this->events->record( $key, 'favorite', $skillId, null, 'favorite_set', $actor, $wasActive ? 'active' : 'inactive', $active ? 'active' : 'inactive', array( 'payload_hash' => $hash, 'skill_id' => $skillId ) );
			return $this->favoriteResult( $actor, $skillId );
		}, fn( array $event, int $actor ): array => $this->favoriteResult( $actor, $skillId ) );
	}
	public function submitReview( int $skillId, array $payload, int $expectedLockVersion, string $requestKey ): array {
		$data = Input::review( $payload ); Input::id( $expectedLockVersion, true );
		$this->write( 'review_submit', $requestKey, array( $skillId, $expectedLockVersion, $data ), $skillId, null, function ( int $actor, string $key, string $hash ) use ( $skillId, $expectedLockVersion, $data ): array {
			$this->skill( $skillId, $data['locale'] ); $table = $this->db->table( 'reviews' ); $now = self::now();
			$head = $this->db->row( "SELECT * FROM $table WHERE user_id=%d AND skill_id=%d FOR UPDATE", array( $actor, $skillId ) );
			if ( ! $head ) {
				if ( 0 !== $expectedLockVersion ) { throw new Exception( 'conflict' ); }
				$id = $this->db->insert( 'reviews', array( 'user_id' => $actor, 'skill_id' => $skillId, 'state' => 'pending', 'approved_revision_id' => null, 'pending_revision_id' => null, 'lock_version' => 1, 'first_published_at' => null, 'created_at' => $now, 'updated_at' => $now, 'withdrawn_at' => null ) ); $head = $this->head( $id ); $version = 1;
			} else {
				$this->version( $head, $expectedLockVersion );
				if ( 'hidden' === $head['state'] ) { throw new Exception( 'hidden' ); }
				$id = (int) $head['id']; $version = (int) $head['lock_version'] + 1;
			}
			$revisions = $this->db->table( 'review_revisions' );
			$number = 1 + (int) $this->db->value( "SELECT COALESCE(MAX(revision),0) FROM $revisions WHERE review_id=%d", array( $id ) );
			if ( ! empty( $head['pending_revision_id'] ) ) { $this->db->execute( "UPDATE $revisions SET moderation_state='superseded' WHERE id=%d AND review_id=%d AND moderation_state='pending'", array( (int) $head['pending_revision_id'], $id ) ); }
			$revisionId = $this->db->insert( 'review_revisions', array_merge( $data, array( 'review_id' => $id, 'revision' => $number, 'moderation_state' => 'pending', 'moderator_id' => null, 'moderated_at' => null, 'moderation_code' => null, 'private_note' => null, 'request_key' => $key, 'created_at' => $now ) ) );
			$state = empty( $head['approved_revision_id'] ) ? 'pending' : 'approved';
			$this->db->updateById( 'reviews', $id, array( 'state' => $state, 'pending_revision_id' => $revisionId, 'lock_version' => $version, 'updated_at' => $now, 'withdrawn_at' => null ) );
			$this->events->record( $key, 'review', $id, $number, 'review_submit', $actor, $head['state'], $state, array( 'payload_hash' => $hash, 'skill_id' => $skillId, 'revision_id' => $revisionId ) );
			return array( 'id' => $id );
		}, fn( array $event ): array => array( 'id' => (int) $event['subject_id'] ) );
		return $this->query->mineReview( $skillId ) ?? throw new Exception( 'storage_unavailable' );
	}
	public function withdrawReview( int $reviewId, int $expectedLockVersion, string $requestKey ): array {
		$skillId = $this->reviewSkill( $reviewId ); Input::id( $expectedLockVersion );
		$this->write( 'review_withdraw', $requestKey, array( $reviewId, $expectedLockVersion ), $skillId, null, function ( int $actor, string $key, string $hash ) use ( $reviewId, $expectedLockVersion, $skillId ): array {
			$head = $this->head( $reviewId ); if ( (int) $head['user_id'] !== $actor ) { throw new Exception( 'forbidden' ); } $this->version( $head, $expectedLockVersion );
			$now = self::now();
			if ( ! empty( $head['pending_revision_id'] ) ) { $this->db->execute( 'UPDATE ' . $this->db->table( 'review_revisions' ) . " SET moderation_state='withdrawn' WHERE id=%d AND review_id=%d AND moderation_state='pending'", array( (int) $head['pending_revision_id'], $reviewId ) ); }
			// A hidden decision cannot be undone by withdraw/resubmit. Its public pointer
			// is already gone, while the user can still request privacy erasure.
			$state = 'hidden' === $head['state'] ? 'hidden' : 'withdrawn';
			$this->db->updateById( 'reviews', $reviewId, array( 'state' => $state, 'approved_revision_id' => null, 'pending_revision_id' => null, 'lock_version' => (int) $head['lock_version'] + 1, 'withdrawn_at' => $now, 'updated_at' => $now ) );
			$this->events->record( $key, 'review', $reviewId, null, 'review_withdraw', $actor, $head['state'], $state, array( 'payload_hash' => $hash, 'skill_id' => $skillId ) );
			$this->notify( $actor, $key, 'review_withdrawn', $reviewId, 'review' ); return array( 'id' => $reviewId );
		}, fn( array $event ): array => array( 'id' => (int) $event['subject_id'] ) );
		return $this->query->mineReview( $skillId ) ?? throw new Exception( 'storage_unavailable' );
	}
	private function authorActive( array $head ): bool {
		return (bool) $this->db->value( 'SELECT r.id FROM ' . $this->db->table( 'reviews' ) . ' r WHERE r.id=%d AND ' . $this->query->liveUserSql( 'r.user_id' ), array( (int) $head['id'] ) );
	}
	private function notify( int $userId, string $key, string $template, int $subjectId, string $kind ): void {
		if ( ! in_array( $userId, $this->lockedUsers, true ) ) { throw new Exception( 'storage_unavailable' ); }
		$preferences = get_user_meta( $userId, '_aish_notification_preferences', true );
		$locale = is_array( $preferences ) && in_array( $preferences['locale'] ?? '', array( 'zh_CN', 'en_US' ), true ) ? $preferences['locale'] : ( 'en_US' === get_user_meta( $userId, '_aish_profile_locale', true ) ? 'en_US' : 'zh_CN' );
		$this->notifications->notifyCommunity( $userId, hash( 'sha256', $key . ':notice:' . $userId ), $template, $locale, $kind, $subjectId );
	}
	public function moderateReview( int $reviewId, int $revisionId, int $expectedLockVersion, string $decision, string $reason = '', string $privateNote = '', string $requestKey = '' ): array {
		Input::choice( $decision, array( 'approve', 'reject', 'hide' ) ); Input::id( $revisionId, 'hide' === $decision ); Input::id( $expectedLockVersion );
		$reason = '' === $reason ? ( 'approve' === $decision ? 'approved' : 'other' ) : Input::choice( $reason, Input::MODERATION_REASONS );
		if ( ( 'approve' === $decision ) !== ( 'approved' === $reason ) ) { throw new Exception( 'invalid_input' ); }
		$note = Input::text( $privateNote, 2000 ); $skillId = $this->reviewSkill( $reviewId );
		$authorId = (int) $this->db->value( 'SELECT user_id FROM ' . $this->db->table( 'reviews' ) . ' WHERE id=%d', array( $reviewId ) );
		$this->write( 'review_' . $decision, $requestKey, array( $reviewId, $revisionId, $expectedLockVersion, $decision, $reason, $note ), $skillId, 'aish_moderate_community', function ( int $actor, string $key, string $hash ) use ( $reviewId, $revisionId, $expectedLockVersion, $decision, $reason, $note, $skillId ): array {
			$head = $this->head( $reviewId ); $this->version( $head, $expectedLockVersion ); $now = self::now();
			if ( 'approve' === $decision && ( (int) $head['user_id'] === $actor || ! $this->authorActive( $head ) ) ) { throw new Exception( 'forbidden' ); }
			if ( in_array( $head['state'], array( 'hidden', 'withdrawn' ), true ) && 'hide' !== $decision ) { throw new Exception( 'conflict' ); }
			$revisions = $this->db->table( 'review_revisions' );
			$revision = $revisionId ? $this->db->row( "SELECT * FROM $revisions WHERE id=%d AND review_id=%d FOR UPDATE", array( $revisionId, $reviewId ) ) : null;
			if ( 'hide' === $decision ) {
				if ( (int) ( $head['approved_revision_id'] ?? 0 ) !== $revisionId || ( $revisionId && ! $revision ) ) { throw new Exception( 'conflict' ); }
				if ( $revision ) { $this->db->updateById( 'review_revisions', $revisionId, array( 'moderation_state' => 'hidden', 'moderator_id' => $actor, 'moderated_at' => $now, 'moderation_code' => $reason, 'private_note' => $note ) ); }
				if ( ! empty( $head['pending_revision_id'] ) ) { $this->db->execute( "UPDATE $revisions SET moderation_state='hidden',moderator_id=%d,moderated_at=%s,moderation_code=%s,private_note=%s WHERE id=%d AND review_id=%d AND moderation_state='pending'", array( $actor, $now, $reason, $note, (int) $head['pending_revision_id'], $reviewId ) ); }
				$state = 'hidden'; $changes = array( 'approved_revision_id' => null, 'pending_revision_id' => null ); $template = 'review_hidden';
			} else {
				if ( ! $revision || (int) $head['pending_revision_id'] !== $revisionId || 'pending' !== $revision['moderation_state'] ) { throw new Exception( 'conflict' ); }
				if ( 'approve' === $decision ) {
					$this->skill( $skillId, $revision['locale'] ); Input::review( array_intersect_key( $revision, array_flip( array( 'title', 'rating', 'comment', 'experience', 'pros', 'cons', 'platform', 'version_label', 'locale' ) ) ) );
					$state = 'approved'; $changes = array( 'approved_revision_id' => $revisionId, 'pending_revision_id' => null ); $template = 'review_approved';
					if ( null === $head['first_published_at'] ) { $changes['first_published_at'] = $now; $this->metrics->fact( 'first_review_approved', $skillId, $reviewId ); }
				} else { $state = empty( $head['approved_revision_id'] ) ? 'rejected' : 'approved'; $changes = array( 'pending_revision_id' => null ); $template = 'review_rejected'; }
				$this->db->updateById( 'review_revisions', $revisionId, array( 'moderation_state' => 'approve' === $decision ? 'approved' : 'rejected', 'moderator_id' => $actor, 'moderated_at' => $now, 'moderation_code' => $reason, 'private_note' => $note ) );
			}
			$this->db->updateById( 'reviews', $reviewId, array_merge( $changes, array( 'state' => $state, 'lock_version' => (int) $head['lock_version'] + 1, 'updated_at' => $now ) ) );
			$metadata = array( 'payload_hash' => $hash, 'skill_id' => $skillId, 'reason' => $reason ); if ( $revisionId ) { $metadata['revision_id'] = $revisionId; }
			$this->events->record( $key, 'review', $reviewId, $revision ? (int) $revision['revision'] : null, 'review_' . $decision, $actor, $head['state'], $state, $metadata );
			if ( ! empty( $head['user_id'] ) && $this->authorActive( $head ) ) { $this->notify( (int) $head['user_id'], $key, $template, $reviewId, 'review' ); }
			return array( 'id' => $reviewId );
		}, fn( array $event ): array => array( 'id' => (int) $event['subject_id'] ), $authorId > 0 ? array( $authorId ) : array() );
		return $this->query->adminReview( $reviewId ) ?? throw new Exception( 'storage_unavailable' );
	}
	public function reportReview( int $reviewId, int $revisionId, array $payload, string $requestKey ): array {
		$data = Input::report( $payload, 'review' ); Input::id( $revisionId ); $skillId = $this->reviewSkill( $reviewId );
		return $this->write( 'review_report', $requestKey, array( $reviewId, $revisionId, $data ), $skillId, null, function ( int $actor, string $key, string $hash ) use ( $reviewId, $revisionId, $skillId, $data ): array {
			$this->head( $reviewId ); if ( ! $this->query->publicReview( $reviewId, $revisionId, $data['locale'] ) ) { throw new Exception( 'unavailable' ); }
			$active = hash_hmac( 'sha256', 'review-report:' . $actor . ':' . $revisionId . ':' . $data['reason_code'], wp_salt( 'auth' ) ); $table = $this->db->table( 'review_reports' );
			$row = $this->db->row( "SELECT * FROM $table WHERE active_key=%s FOR UPDATE", array( $active ) );
			if ( $row ) { foreach ( $data as $field => $value ) { if ( (string) $row[ $field ] !== (string) $value ) { throw new Exception( 'conflict' ); } } }
			$state = $row ? $row['state'] : 'pending';
			$id = $row ? (int) $row['id'] : $this->db->insert( 'review_reports', array_merge( $data, array( 'review_id' => $reviewId, 'revision_id' => $revisionId, 'reporter_user_id' => $actor, 'state' => 'pending', 'active_key' => $active, 'request_key' => $key, 'handler_id' => null, 'resolution_code' => null, 'private_note' => null, 'created_at' => self::now(), 'resolved_at' => null ) ) );
			$this->events->record( $key, 'review_report', $id, null, 'review_report', $actor, $row ? $state : null, $state, array( 'payload_hash' => $hash, 'skill_id' => $skillId, 'revision_id' => $revisionId ) );
			return array( 'id' => $id, 'state' => $state, 'duplicate' => (bool) $row );
		}, fn( array $event ): array => $this->reportResult( 'review', (int) $event['subject_id'] ) );
	}
	public function reportSkill( int $skillId, array $payload, string $requestKey ): array {
		$data = Input::report( $payload, 'skill' );
		return $this->write( 'skill_report', $requestKey, array( $skillId, $data ), $skillId, null, function ( int $actor, string $key, string $hash ) use ( $skillId, $data ): array {
			$skill = $this->skill( $skillId, $data['locale'] ); if ( (int) $skill['revision'] !== $data['skill_revision'] ) { throw new Exception( 'conflict' ); }
			$active = hash_hmac( 'sha256', 'skill-report:' . $actor . ':' . $skillId . ':' . $data['locale'] . ':' . $data['kind'], wp_salt( 'auth' ) ); $table = $this->db->table( 'skill_reports' );
			$row = $this->db->row( "SELECT * FROM $table WHERE active_key=%s FOR UPDATE", array( $active ) );
			if ( $row ) { foreach ( $data as $field => $value ) { if ( (string) $row[ $field ] !== (string) $value ) { throw new Exception( 'conflict' ); } } }
			$state = $row ? $row['state'] : 'pending';
			$id = $row ? (int) $row['id'] : $this->db->insert( 'skill_reports', array_merge( $data, array( 'skill_id' => $skillId, 'reporter_user_id' => $actor, 'state' => 'pending', 'active_key' => $active, 'request_key' => $key, 'handler_id' => null, 'resolution_code' => null, 'private_note' => null, 'created_at' => self::now(), 'resolved_at' => null ) ) );
			$this->events->record( $key, 'skill_report', $id, (int) $skill['revision'], 'skill_report', $actor, $row ? $state : null, $state, array( 'payload_hash' => $hash, 'skill_id' => $skillId, 'kind' => $data['kind'] ) );
			return array( 'id' => $id, 'state' => $state, 'duplicate' => (bool) $row );
		}, fn( array $event ): array => $this->reportResult( 'skill', (int) $event['subject_id'] ) );
	}
	private function reportResult( string $kind, int $id ): array {
		$row = $this->db->row( 'SELECT id,state FROM ' . $this->db->table( $kind . '_reports' ) . ' WHERE id=%d', array( $id ) );
		return $row ?? array( 'id' => $id, 'state' => 'closed' );
	}
	public function resolveReport( string $kind, int $id, string $decision, string $resolution, string $privateNote, string $requestKey, string $expectedState = 'pending' ): array {
		Input::choice( $kind, array( 'review', 'skill' ) ); Input::id( $id ); Input::choice( $decision, array( 'reviewing', 'handled', 'closed' ) ); Input::choice( $expectedState, array( 'pending', 'reviewing' ) ); Input::choice( $resolution, Input::RESOLUTIONS );
		$note = Input::text( $privateNote, 2000 ); $table = $this->db->table( $kind . '_reports' );
		$lookup = $this->db->row( "SELECT * FROM $table WHERE id=%d", array( $id ) ); if ( ! $lookup ) { throw new Exception( 'unavailable' ); }
		$skillId = 'skill' === $kind ? (int) $lookup['skill_id'] : $this->reviewSkill( (int) $lookup['review_id'] );
		$this->write( $kind . '_report_resolve', $requestKey, array( $kind, $id, $decision, $resolution, $note, $expectedState ), $skillId, 'aish_manage_community_reports', function ( int $actor, string $key, string $hash ) use ( $kind, $id, $decision, $resolution, $note, $expectedState, $table, $skillId ): array {
			$row = $this->db->row( "SELECT * FROM $table WHERE id=%d FOR UPDATE", array( $id ) ); if ( ! $row || $row['state'] !== $expectedState ) { throw new Exception( 'conflict' ); }
			if ( 'reviewing' === $decision && 'reviewing' === $expectedState ) { throw new Exception( 'conflict' ); }
			$closed = 'reviewing' !== $decision;
			$this->db->updateById( $kind . '_reports', $id, array( 'state' => $decision, 'handler_id' => $actor, 'resolution_code' => $resolution, 'private_note' => $note, 'active_key' => $closed ? null : $row['active_key'], 'resolved_at' => $closed ? self::now() : null ) );
			$this->events->record( $key, $kind . '_report', $id, null, $kind . '_report_resolve', $actor, $row['state'], $decision, array( 'payload_hash' => $hash, 'skill_id' => $skillId, 'reason' => $resolution ) );
			if ( $closed && ! empty( $row['reporter_user_id'] ) ) {
				$reporter = get_userdata( (int) $row['reporter_user_id'] );
				if ( $reporter && 'active' === $this->accounts->state( (int) $reporter->ID ) && ! $this->query->erasing( (int) $reporter->ID ) ) { $this->notify( (int) $reporter->ID, $key, 'report_resolved', $id, $kind . '_report' ); }
			}
			return array( 'id' => $id, 'state' => $decision );
		}, fn( array $event ): array => $this->reportResult( $kind, $id ), ! empty( $lookup['reporter_user_id'] ) ? array( (int) $lookup['reporter_user_id'] ) : array() );
		return $this->query->adminReport( $kind, $id ) ?? throw new Exception( 'storage_unavailable' );
	}
}
