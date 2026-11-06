<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Developers;
use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Store;
use AISkillsHub\Core\Community\Events;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
defined( 'ABSPATH' ) || exit;

/** Human-verified developer claims never grant a native role or mutate Skill content. */
final class Service {
	private static int $writeDepth = 0;
	private Metadata $meta;
	private Query $query;
	private Events $events;
	public function __construct( private Database $db, private Migrator $migrator, private AccountService $accounts, private Locks $locks, private Store $notifications ) { $this->meta = new Metadata( $db ); $this->query = new Query( $db, $migrator, $accounts ); $this->events = new Events( $db ); }
	public static function writing(): bool { return self::$writeDepth > 0; }
	private static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }
	private function actor( ?string $cap ): int {
		if ( ! $this->migrator->isCurrent() ) { throw new Exception( 'storage_unavailable' ); }
		try { $id = (int) $this->accounts->requireOwner( null === $cap )->ID; } catch ( \Throwable $e ) { throw new Exception( 'forbidden' ); }
		if ( ( null !== $cap && ! current_user_can( $cap ) ) || $this->query->erasing( $id ) ) { throw new Exception( 'forbidden' ); } return $id;
	}
	/** Handle -> subject email (only native rename) -> numeric account locks -> transaction. */
	private function write( string $action, string $request, array $payload, ?string $cap, array $users, callable $callback, ?string $handle = null, ?int $renameUser = null ): array {
		Input::request( $request ); $actor = $this->actor( $cap ); $ids = array_values( array_unique( array_merge( array( $actor ), $users ) ) ); sort( $ids, SORT_NUMERIC );
		$key = hash_hmac( 'sha256', 'developer:' . $actor . ':' . $action . ':' . $request, wp_salt( 'auth' ) ); $hash = hash( 'sha256', (string) wp_json_encode( $payload ) );
		$held = false; $releases = array(); $handleLock = null; $oldCache = wp_suspend_cache_addition(); $oldInvalidation = wp_suspend_cache_invalidation(); $cacheKeys = array();
		$denyHttp = static fn() => new \WP_Error( 'aish_transaction_network', 'Network operations are unavailable inside a developer transaction.' ); $denyMail = static fn() => false;
		try {
			if ( null !== $handle ) { $handleLock = 'aish_handle:' . substr( hash( 'sha256', DB_NAME . ':' . $this->db->connection()->users . ':' . $handle ), 0, 50 ); $held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 2)', array( $handleLock ) ); if ( ! $held ) { throw new Exception( 'conflict' ); } }
			$renameEmail = null;
			if ( null !== $renameUser ) { $target = get_userdata( $renameUser ); if ( ! $target ) { throw new Exception( 'unavailable' ); } $renameEmail = $target->user_email; $releases[] = $this->locks->acquire( array( $renameEmail ) ); }
			foreach ( $ids as $id ) { Input::id( $id ); $releases[] = $this->locks->acquire( array(), $id ); }
			$this->meta->nativeReady(); wp_suspend_cache_addition( true ); wp_suspend_cache_invalidation( true );
			add_filter( 'pre_http_request', $denyHttp, PHP_INT_MAX ); add_filter( 'pre_wp_mail', $denyMail, PHP_INT_MAX );
			return $this->db->transaction( function () use ( $ids, $actor, $cap, $key, $hash, $callback, $action, $renameUser, $renameEmail, &$cacheKeys ): array {
				$u = $this->db->connection()->users;
				foreach ( $ids as $id ) { $row = $this->db->row( "SELECT ID,user_email,user_login,user_nicename FROM $u WHERE ID=%d FOR UPDATE", array( $id ) ); if ( ! $row ) { throw new Exception( 'unavailable' ); } if ( $id === $renameUser && $row['user_email'] !== $renameEmail ) { throw new Exception( 'conflict' ); } $cacheKeys[ $id ] = $row; clean_user_cache( $id ); wp_cache_delete( $id, 'users' ); wp_cache_delete( $id, 'user_meta' ); }
				if ( $actor !== $this->actor( $cap ) ) { throw new Exception( 'forbidden' ); }
				$prior = $this->db->row( 'SELECT id,subject_type,subject_id,metadata_json FROM ' . $this->db->table( 'workflow_events' ) . ' WHERE event_key=%s', array( $key ) );
				if ( $prior ) { $m = json_decode( $prior['metadata_json'], true ); if ( ! is_array( $m ) || empty( $m['payload_hash'] ) ) { throw new Exception( 'expired_request' ); } if ( ! hash_equals( $hash, $m['payload_hash'] ) ) { throw new Exception( 'conflict' ); } return array( 'id' => (int) $prior['subject_id'], 'replayed' => true ); }
				$count = (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'workflow_events' ) . ' WHERE actor_user_id=%d AND action=%s AND created_at>=%s', array( $actor, $action, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
				if ( $count >= ( null === $cap ? 20 : 200 ) ) { throw new Exception( 'rate_limited' ); }
				++self::$writeDepth;
				try { return $callback( $actor, $key, $hash ); } finally { --self::$writeDepth; }
			} );
		} catch ( Exception $e ) { throw $e; } catch ( \Throwable $e ) { throw new Exception( 'storage_unavailable' ); }
		finally {
			try {
				// Invalidate committed and rolled-back native identities even when a caller
				// suspended invalidation. Retain old slug/email keys across native updates.
				wp_suspend_cache_invalidation( false );
				foreach ( $ids as $id ) { clean_user_cache( $id ); wp_cache_delete( $id, 'users' ); wp_cache_delete( $id, 'user_meta' ); }
				foreach ( $cacheKeys as $row ) { foreach ( array( 'user_nicename' => 'userslugs', 'user_login' => 'userlogins', 'user_email' => 'useremail' ) as $field => $group ) { wp_cache_delete( $row[ $field ], $group ); } }
				if ( null !== $handle ) { wp_cache_delete( $handle, 'userslugs' ); }
			} finally {
				remove_filter( 'pre_http_request', $denyHttp, PHP_INT_MAX ); remove_filter( 'pre_wp_mail', $denyMail, PHP_INT_MAX );
				wp_suspend_cache_addition( $oldCache ); wp_suspend_cache_invalidation( $oldInvalidation );
				foreach ( array_reverse( $releases ) as $release ) { $release(); }
				if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $handleLock ) ); } catch ( \Throwable $ignored ) {} }
			}
		}
	}
	private function notify( int $id, string $key, string $template, string $kind, int $subject ): void {
		if ( $this->query->erasing( $id ) || ! get_userdata( $id ) ) { return; }
		$locale = get_user_meta( $id, '_aish_profile_locale', true ); if ( ! in_array( $locale, Input::LOCALES, true ) ) { $locale = 'en_US'; }
		$this->notifications->notifyContribution( $id, hash( 'sha256', $key . ':notice:' . $id ), $template, $locale, $kind, $subject );
	}
	private function event( string $key, string $type, int $id, ?int $revision, string $action, int $actor, ?string $from, string $to, string $hash, array $metadata = array() ): void { $this->events->record( $key, $type, $id, $revision, $action, $actor, $from, $to, array_merge( array( 'payload_hash' => $hash ), $metadata ) ); }
	private function profileVersion( array $m, int $expected ): void { if ( (int) ( $m['_aish_profile_revision'] ?? 0 ) !== $expected ) { throw new Exception( 'conflict' ); } }
	private function identity( int $id, array $profile ): void {
		$u = get_userdata( $id ); if ( ! $u ) { throw new Exception( 'unavailable' ); }
		if ( strtolower( $profile['handle'] ) === strtolower( $u->user_login ) ) { throw new Exception( 'invalid_handle' ); }
		foreach ( $profile['translations'] as $t ) { foreach ( $t as $v ) { if ( ( '' !== $u->user_email && false !== stripos( $v, $u->user_email ) ) || ( '' !== $u->user_login && false !== stripos( $v, $u->user_login ) ) ) { throw new Exception( 'private_identity' ); } } }
	}
	private function avatarCandidate( int $id ): array {
		$c = $this->db->connection(); $pointers = $this->db->rows( "SELECT meta_value FROM {$c->usermeta} WHERE user_id=%d AND meta_key='_aish_avatar_attachment_id'", array( $id ) );
		if ( 1 !== count( $pointers ) ) { throw new Exception( 'avatar_unavailable' ); } $attachment = Input::id( $pointers[0]['meta_value'] );
		$post = $this->db->row( "SELECT post_author,post_type,post_status,post_mime_type FROM {$c->posts} WHERE ID=%d", array( $attachment ) );
		$owner = $this->db->rows( "SELECT meta_value FROM {$c->postmeta} WHERE post_id=%d AND meta_key='_aish_avatar_owner'", array( $attachment ) ); $payload = $this->db->rows( "SELECT meta_value FROM {$c->postmeta} WHERE post_id=%d AND meta_key='_aish_avatar_bytes'", array( $attachment ) );
		if ( ! $post || (int) $post['post_author'] !== $id || 'attachment' !== $post['post_type'] || 'private' !== $post['post_status'] || 'image/png' !== $post['post_mime_type'] || array( (string) $id ) !== array_column( $owner, 'meta_value' ) || 1 !== count( $payload ) || strlen( $payload[0]['meta_value'] ) > 349536 ) { throw new Exception( 'avatar_unavailable' ); }
		$bytes = base64_decode( $payload[0]['meta_value'], true ); if ( ! is_string( $bytes ) || strlen( $bytes ) > 262144 || ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) { throw new Exception( 'avatar_unavailable' ); }
		return array( 'attachment_id' => $attachment, 'sha256' => hash( 'sha256', $bytes ) );
	}
	public function saveProfile( array $payload, int $expectedRevision, string $requestKey ): array {
		$data = Input::profile( $payload ); Input::id( $expectedRevision, true );
		$this->write( 'profile_submit', $requestKey, array( $expectedRevision, $data ), null, array(), function ( int $actor, string $key, string $hash ) use ( $data, $expectedRevision ): array {
			$m = $this->meta->read( $actor ); $this->profileVersion( $m, $expectedRevision ); $this->identity( $actor, $data ); $next = $expectedRevision + 1;
			$candidate = array_diff_key( $data, array( 'translations' => true, 'opt_in' => true ) ); $candidate['avatar'] = $data['public_avatar'] ? $this->avatarCandidate( $actor ) : null;
			$state = $data['opt_in'] ? 'pending' : 'draft';
			foreach ( array( '_aish_public_profile' => $data['opt_in'] ? '1' : '0', '_aish_profile_revision' => $next, '_aish_profile_review_status' => $state, '_aish_profile_translations' => $data['translations'], '_aish_profile_candidate' => $candidate, '_aish_profile_updated_at' => self::now(), '_aish_profile_feedback' => '', '_aish_profile_private_note' => '' ) as $field => $value ) { $this->meta->set( $actor, $field, $value ); }
			if ( ! $data['opt_in'] || '1' !== (string) ( $m['_aish_public_profile'] ?? '' ) ) { $this->meta->remove( $actor, '_aish_profile_approved_snapshot' ); }
			$this->event( $key, 'developer', $actor, $next, 'profile_submit', $actor, $m['_aish_profile_review_status'] ?? null, $state, $hash ); return array( 'id' => $actor );
		} ); return $this->query->mineProfile();
	}
	public function withdrawProfile( int $expectedRevision, string $requestKey ): array {
		Input::id( $expectedRevision ); $this->write( 'profile_withdraw', $requestKey, array( $expectedRevision ), null, array(), function ( int $actor, string $key, string $hash ) use ( $expectedRevision ): array {
			$m = $this->meta->read( $actor ); $this->profileVersion( $m, $expectedRevision ); $this->meta->set( $actor, '_aish_public_profile', '0' ); $this->meta->set( $actor, '_aish_profile_review_status', 'withdrawn' ); $this->meta->set( $actor, '_aish_profile_revision', $expectedRevision + 1 ); $this->meta->set( $actor, '_aish_profile_updated_at', self::now() ); $this->meta->remove( $actor, '_aish_profile_approved_snapshot' );
			$this->event( $key, 'developer', $actor, $expectedRevision + 1, 'profile_withdraw', $actor, $m['_aish_profile_review_status'] ?? null, 'withdrawn', $hash ); return array( 'id' => $actor );
		} ); return $this->query->mineProfile();
	}
	public function moderateProfile( int $userId, int $expectedRevision, string $decision, string $feedback, string $privateNote, string $requestKey ): array {
		Input::id( $userId ); Input::id( $expectedRevision ); Input::choice( $decision, array( 'approve', 'reject', 'hide' ) ); $feedback = Input::text( $feedback, 2000, 5 ); $note = Input::text( $privateNote, 2000 );
		$this->actor( 'aish_manage_developers' ); $read = $this->meta->read( $userId ); $candidate = Metadata::draft( $read ); $handle = 'approve' === $decision ? Input::handle( $candidate['handle'] ) : null;
		$this->write( 'profile_' . $decision, $requestKey, array( $userId, $expectedRevision, $decision, $feedback, $note ), 'aish_manage_developers', array( $userId ), function ( int $actor, string $key, string $hash ) use ( $userId, $expectedRevision, $decision, $feedback, $note, $handle ): array {
			$m = $this->meta->read( $userId ); $this->profileVersion( $m, $expectedRevision );
			if ( $this->query->erasing( $userId ) || ( 'hide' !== $decision && ( $m['_aish_profile_review_status'] ?? '' ) !== 'pending' ) ) { throw new Exception( 'conflict' ); }
			$state = match ( $decision ) { 'approve' => 'approved', 'reject' => 'rejected', default => 'hidden' }; $now = self::now();
			if ( 'approve' === $decision ) {
				if ( $userId === $actor || ! $this->accounts->verified( $userId ) || '1' !== (string) ( $m['_aish_public_profile'] ?? '' ) ) { throw new Exception( 'forbidden' ); }
				$candidate = Metadata::draft( $m ); $avatar = $candidate['avatar'] ?? null; unset( $candidate['avatar'] ); $candidate['opt_in'] = true; $data = Input::profile( $candidate ); $this->identity( $userId, $data );
				if ( $data['handle'] !== $handle || ( $data['public_avatar'] && $this->avatarCandidate( $userId ) !== $avatar ) ) { throw new Exception( 'conflict' ); }
				$u = $this->db->connection()->users;
				if ( null !== $this->db->value( "SELECT ID FROM $u WHERE user_nicename=%s AND ID<>%d LIMIT 1 FOR UPDATE", array( $handle, $userId ) ) ) { throw new Exception( 'handle_taken' ); }
				$result = wp_update_user( array( 'ID' => $userId, 'user_nicename' => $handle ) );
				if ( is_wp_error( $result ) || $userId !== (int) $result || $handle !== $this->db->value( "SELECT user_nicename FROM $u WHERE ID=%d", array( $userId ) ) ) { throw new Exception( 'handle_taken' ); }
				$snapshot = array_merge( array_diff_key( $data, array( 'opt_in' => true ) ), array( 'avatar' => $avatar, 'revision' => $expectedRevision, 'reviewer_id' => $actor, 'reviewed_at' => $now ) ); $this->meta->set( $userId, '_aish_profile_approved_snapshot', $snapshot );
			} elseif ( 'hide' === $decision ) { $this->meta->set( $userId, '_aish_public_profile', '0' ); $this->meta->remove( $userId, '_aish_profile_approved_snapshot' ); }
			$this->meta->decision( $userId, array( 'v' => 1, 'revision' => $expectedRevision, 'decision' => $decision, 'snapshot' => Metadata::draft( $m ), 'reason' => $feedback, 'private_note' => $note, 'reviewer_id' => $actor, 'at' => $now ) );
			foreach ( array( '_aish_profile_review_status' => $state, '_aish_profile_revision' => $expectedRevision + 1, '_aish_profile_reviewed_revision' => $expectedRevision, '_aish_profile_reviewer_id' => $actor, '_aish_profile_reviewed_at' => $now, '_aish_profile_updated_at' => $now, '_aish_profile_feedback' => $feedback, '_aish_profile_private_note' => $note ) as $field => $value ) { $this->meta->set( $userId, $field, $value ); }
			$this->event( $key, 'developer', $userId, $expectedRevision, 'profile_' . $decision, $actor, $m['_aish_profile_review_status'] ?? null, $state, $hash );
			$this->notify( $userId, $key, 'profile_' . ( 'approve' === $decision ? 'approved' : ( 'reject' === $decision ? 'rejected' : 'hidden' ) ), 'developer', $userId ); return array( 'id' => $userId );
		}, $handle, 'approve' === $decision ? $userId : null ); return $this->query->adminProfile( $userId ) ?? throw new Exception( 'unavailable' );
	}
	private function skill( int $id, ?string $locale = null ): array { $s = $this->db->row( 'SELECT id,revision,repository_id,source_path FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d FOR UPDATE', array( Input::id( $id ) ) ); if ( ! $s || ( null !== $locale && ! $this->query->publicSkillRevision( $id, $locale ) ) ) { throw new Exception( 'unavailable' ); } return $s; }
	private function claim( int $id, bool $locked = false ): array { $r = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'author_claims' ) . ' WHERE id=%d' . ( $locked ? ' FOR UPDATE' : '' ), array( Input::id( $id ) ) ); if ( ! $r ) { throw new Exception( 'unavailable' ); } return $r; }
	public function submitClaim( int $skillId, array $payload, string $requestKey ): array {
		$data = Input::claim( $payload ); Input::id( $skillId );
		$result = $this->write( 'claim_submit', $requestKey, array( $skillId, $data ), null, array(), function ( int $actor, string $key, string $hash ) use ( $skillId, $data ): array {
			$s = $this->skill( $skillId, $data['locale'] ); if ( (int) $s['revision'] !== $data['source_revision'] ) { throw new Exception( 'conflict' ); }
			$t = $this->db->table( 'author_claims' ); $active = hash_hmac( 'sha256', 'claim:' . $actor . ':' . $skillId, wp_salt( 'auth' ) );
			if ( $this->db->row( "SELECT id FROM $t WHERE active_request_key=%s OR (claimant_user_id=%d AND skill_id=%d AND state IN ('pending','approved','disputed')) LIMIT 1 FOR UPDATE", array( $active, $actor, $skillId ) ) ) { throw new Exception( 'claim_exists' ); }
			$identity = null; if ( $s['repository_id'] ) { $identity = $this->db->row( 'SELECT id,owner,name,github_id FROM ' . $this->db->table( 'repositories' ) . ' WHERE id=%d', array( (int) $s['repository_id'] ) ); }
			$proof = array( 'v' => 1, 'application' => array( 'locale' => $data['locale'], 'reason' => $data['reason'], 'evidence_urls' => $data['evidence_urls'], 'source_path' => $s['source_path'], 'repository_identity' => $identity ), 'decisions' => array() ); $now = self::now();
			$id = $this->db->insert( 'author_claims', array( 'skill_id' => $skillId, 'claimant_user_id' => $actor, 'claim_role' => $data['claim_role'], 'repository_id' => $s['repository_id'], 'source_revision' => $data['source_revision'], 'state' => 'pending', 'proof_kind' => $data['proof_kind'], 'proof_json' => Input::proofDocument( $proof ), 'request_key' => $key, 'active_request_key' => $active, 'approved_skill_key' => null, 'reviewer_id' => null, 'reviewed_at' => null, 'expires_at' => null, 'created_at' => $now, 'updated_at' => $now ) );
			$this->event( $key, 'claim', $id, null, 'claim_submit', $actor, null, 'pending', $hash, array( 'skill_id' => $skillId ) ); return array( 'id' => $id );
		} ); return $this->query->mineClaim( $result['id'] ) ?? throw new Exception( 'unavailable' );
	}
	public function withdrawClaim( int $id, string $expectedState, string $requestKey ): array {
		$owner = $this->actor( null ); $r = $this->claim( $id ); if ( (int) $r['claimant_user_id'] !== $owner ) { throw new Exception( 'unavailable' ); } Input::choice( $expectedState, array( 'pending', 'approved', 'disputed' ) );
		$this->write( 'claim_withdraw', $requestKey, array( $id, $expectedState ), null, array(), function ( int $actor, string $key, string $hash ) use ( $id, $expectedState, $r ): array {
			$this->skill( (int) $r['skill_id'] ); $row = $this->claim( $id, true ); if ( (int) $row['claimant_user_id'] !== $actor ) { throw new Exception( 'forbidden' ); } if ( $row['state'] !== $expectedState ) { throw new Exception( 'conflict' ); }
			$this->db->updateById( 'author_claims', $id, array( 'state' => 'withdrawn', 'approved_skill_key' => null, 'active_request_key' => null, 'updated_at' => self::now() ) ); $this->event( $key, 'claim', $id, null, 'claim_withdraw', $actor, $row['state'], 'withdrawn', $hash, array( 'skill_id' => (int) $row['skill_id'] ) ); return array( 'id' => $id );
		} ); return $this->query->mineClaim( $id ) ?? throw new Exception( 'unavailable' );
	}
	public function moderateClaim( int $id, string $expectedState, string $decision, string $reason, string $privateNote, string $requestKey, array $verification = array() ): array {
		$this->actor( 'aish_manage_author_claims' ); $r = $this->claim( $id ); Input::choice( $decision, array( 'approve', 'reject', 'revoke', 'dispute' ) ); Input::choice( $expectedState, array( 'pending', 'approved', 'disputed' ) );
		$reason = Input::text( $reason, 2000, 10 ); $note = Input::text( $privateNote, 2000 );
		if ( array_diff( array_keys( $verification ), array( 'evidence_checked', 'role_checked', 'source_checked' ) ) ) { throw new Exception( 'invalid_input' ); }
		if ( 'approve' === $decision ) { foreach ( array( 'evidence_checked', 'role_checked', 'source_checked' ) as $field ) { if ( ! Input::flag( $verification[ $field ] ?? false ) ) { throw new Exception( 'evidence_required' ); } } }
		$owner = (int) ( $r['claimant_user_id'] ?? 0 ); if ( $owner < 1 ) { throw new Exception( 'unavailable' ); }
		$this->write( 'claim_' . $decision, $requestKey, array( $id, $expectedState, $decision, $reason, $note, $verification ), 'aish_manage_author_claims', array( $owner ), function ( int $actor, string $key, string $hash ) use ( $id, $expectedState, $decision, $reason, $note, $verification, $r, $owner ): array {
			$s = $this->skill( (int) $r['skill_id'] ); $row = $this->claim( $id, true ); if ( $row['state'] !== $expectedState || (int) $row['claimant_user_id'] !== $owner || $this->query->erasing( $owner ) ) { throw new Exception( 'conflict' ); }
			$allowed = match ( $decision ) { 'approve', 'reject' => array( 'pending' ), 'dispute' => array( 'approved' ), default => array( 'approved', 'disputed' ) }; if ( ! in_array( $row['state'], $allowed, true ) ) { throw new Exception( 'conflict' ); }
			$proof = json_decode( $row['proof_json'], true, 16, JSON_THROW_ON_ERROR ); if ( ! is_array( $proof ) || ! is_array( $proof['application'] ?? null ) || ! is_array( $proof['decisions'] ?? null ) || count( $proof['decisions'] ) >= 3 ) { throw new Exception( 'storage_unavailable' ); }
			if ( 'approve' === $decision ) {
				if ( $owner === $actor || ! $this->accounts->verified( $owner ) ) { throw new Exception( 'forbidden' ); }
				if ( (int) $s['revision'] !== (int) $row['source_revision'] || $s['repository_id'] !== $row['repository_id'] || $s['source_path'] !== ( $proof['application']['source_path'] ?? null ) || ! $this->query->publicSkillRevision( (int) $s['id'], $proof['application']['locale'] ) ) { throw new Exception( 'conflict' ); }
				$identity = $s['repository_id'] ? $this->db->row( 'SELECT id,owner,name,github_id FROM ' . $this->db->table( 'repositories' ) . ' WHERE id=%d', array( (int) $s['repository_id'] ) ) : null;
				if ( $identity !== ( $proof['application']['repository_identity'] ?? null ) ) { throw new Exception( 'conflict' ); }
				Input::claim( array( 'claim_role' => $row['claim_role'], 'locale' => $proof['application']['locale'], 'source_revision' => (int) $row['source_revision'], 'proof_kind' => $row['proof_kind'], 'evidence_urls' => $proof['application']['evidence_urls'], 'reason' => $proof['application']['reason'] ) );
				if ( null !== $this->db->value( 'SELECT id FROM ' . $this->db->table( 'author_claims' ) . ' WHERE approved_skill_key=%d AND id<>%d LIMIT 1 FOR UPDATE', array( (int) $s['id'], $id ) ) ) { throw new Exception( 'claim_conflict' ); }
			}
			$state = match ( $decision ) { 'approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked', default => 'disputed' }; $now = self::now();
			$proof['decisions'][] = array( 'decision' => $decision, 'reason' => $reason, 'private_note' => $note, 'reviewer_id' => $actor, 'at' => $now, 'verification' => $verification );
			$this->db->updateById( 'author_claims', $id, array( 'state' => $state, 'proof_json' => Input::proofDocument( $proof ), 'approved_skill_key' => 'approved' === $state ? (int) $s['id'] : null, 'active_request_key' => in_array( $state, array( 'approved', 'disputed' ), true ) ? $row['active_request_key'] : null, 'reviewer_id' => $actor, 'reviewed_at' => $now, 'updated_at' => $now ) );
			$this->event( $key, 'claim', $id, null, 'claim_' . $decision, $actor, $row['state'], $state, $hash, array( 'skill_id' => (int) $s['id'], 'reason' => $state ) );
			$this->notify( $owner, $key, 'claim_' . $state, 'claim', $id ); return array( 'id' => $id );
		} ); return $this->query->adminClaim( $id ) ?? throw new Exception( 'unavailable' );
	}
}
