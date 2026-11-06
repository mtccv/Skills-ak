<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Submissions;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Store as AccountStore;
use AISkillsHub\Core\Community\Events;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** The only interactive write boundary for controlled native Inbox contributions. */
final class Service {
	private Store $store;
	private Query $query;
	private Events $events;
	public function __construct( private Database $db, private Migrator $migrator, private AccountService $accounts, private Locks $locks, private AccountStore $notifications ) { $this->store = new Store( $db ); $this->query = new Query( $db, $migrator, $accounts ); $this->events = new Events( $db ); }
	private static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }
	private function actor( ?string $cap ): int {
		if ( ! $this->migrator->isCurrent() ) { throw new Exception( 'storage_unavailable' ); }
		try { $user = $this->accounts->requireOwner( null === $cap ); } catch ( \Throwable $error ) { throw new Exception( 'forbidden' ); }
		$id = (int) $user->ID; if ( ( null !== $cap && ! current_user_can( $cap ) ) || $this->query->erasing( $id ) ) { throw new Exception( 'forbidden' ); } return $id;
	}
	private function record( int $id, bool $lock = false ): array {
		Input::id( $id );
		$post = $this->db->row( 'SELECT p.* FROM ' . $this->db->connection()->posts . ' p WHERE p.ID=%d AND ' . $this->query->ownedSql() . ( $lock ? ' FOR UPDATE' : '' ), array( $id ) );
		if ( ! $post ) { throw new Exception( 'unavailable' ); } $meta = $this->store->meta( $id );
		Input::choice( $meta['_aish_message_kind'] ?? null, Input::KINDS ); Input::choice( $meta['_aish_submission_state'] ?? null, Input::STATES ); Input::id( $meta['_aish_submission_revision'] ?? null );
		return array( $post, $meta );
	}
	private function payload( array $meta ): array { try { return Input::payload( json_decode( $meta['_aish_submission_payload'] ?? '', true, 16, JSON_THROW_ON_ERROR ), false ); } catch ( \Throwable $error ) { throw new Exception( 'storage_unavailable' ); } }
	private function version( array $meta, int $expected ): void { if ( (int) $meta['_aish_submission_revision'] !== $expected ) { throw new Exception( 'conflict' ); } }
	private function write( string $action, string $request, array $payload, ?string $cap, array $relatedUsers, array $skillIds, callable $operation ): array {
		Input::request( $request ); $actor = $this->actor( $cap );
		$key = hash_hmac( 'sha256', 'submission:' . $actor . ':' . $action . ':' . $request, wp_salt( 'auth' ) ); $hash = hash( 'sha256', (string) wp_json_encode( $payload ) );
		$ids = array_values( array_unique( array_merge( array( $actor ), array_filter( array_map( 'intval', $relatedUsers ) ) ) ) ); sort( $ids, SORT_NUMERIC );
		$skillIds = array_values( array_unique( array_filter( array_map( 'intval', $skillIds ) ) ) ); sort( $skillIds, SORT_NUMERIC ); $releases = array();
		try {
			foreach ( $ids as $id ) { Input::id( $id ); $releases[] = $this->locks->acquire( array(), $id ); }
			return $this->store->transaction( function () use ( $action, $key, $hash, $cap, $actor, $ids, $skillIds, $operation ): array {
				foreach ( $ids as $id ) { $this->db->row( 'SELECT ID FROM ' . $this->db->connection()->users . ' WHERE ID=%d FOR UPDATE', array( $id ) ); wp_cache_delete( $id, 'user_meta' ); }
				if ( $actor !== $this->actor( $cap ) ) { throw new Exception( 'forbidden' ); }
				foreach ( $skillIds as $id ) { if ( ! $this->db->row( 'SELECT id FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d FOR UPDATE', array( $id ) ) ) { throw new Exception( 'unavailable' ); } }
				$old = $this->db->row( 'SELECT subject_id,metadata_json FROM ' . $this->db->table( 'workflow_events' ) . ' WHERE event_key=%s', array( $key ) );
				if ( $old ) { $metadata = json_decode( $old['metadata_json'], true ); if ( empty( $metadata['payload_hash'] ) ) { throw new Exception( 'expired_request' ); } if ( ! hash_equals( $hash, (string) $metadata['payload_hash'] ) ) { throw new Exception( 'conflict' ); } return array( 'id' => (int) $old['subject_id'] ); }
				$count = (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'workflow_events' ) . " WHERE actor_user_id=%d AND subject_type IN ('submission','update_suggestion') AND created_at>=%s", array( $actor, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
				if ( $count >= ( null === $cap ? 60 : 200 ) ) { throw new Exception( 'rate_limited' ); }
				return $operation( $actor, $key, $hash );
			} );
		} catch ( Exception $error ) { throw $error; }
		catch ( \Throwable $error ) { throw new Exception( 'storage_unavailable' ); }
		finally { foreach ( array_reverse( $releases ) as $release ) { try { $release(); } catch ( \Throwable $ignored ) {} } }
	}
	private function source( array $payload ): array {
		$canonical = ''; $repositoryId = null; $existing = array();
		if ( $payload['github_url'] ) {
			$identity = \AISkillsHub\Core\Sources\GitHubClient::repositoryIdentity( $payload['github_url'] ); $canonical = $identity['owner'] . '/' . $identity['name'];
			$value = $this->db->value( 'SELECT id FROM ' . $this->db->table( 'repositories' ) . ' WHERE owner=%s AND name=%s', array( $identity['owner'], $identity['name'] ) ); $repositoryId = null === $value ? null : (int) $value;
			if ( $repositoryId && $payload['source_path'] ) { $existing = array_map( 'intval', array_column( $this->db->rows( 'SELECT id FROM ' . $this->db->table( 'skills' ) . ' WHERE repository_id=%d AND source_path=%s LIMIT 20', array( $repositoryId, $payload['source_path'] ) ), 'id' ) ); }
		}
		return array( 'canonical' => $canonical, 'repository_id' => $repositoryId, 'existing' => $existing );
	}
	private function duplicate( int $id, int $actor, array $data, array $source, bool $submit ): void {
		if ( 'submit' !== $data['kind'] || ! $source['canonical'] || ! $data['source_path'] ) { return; }
		if ( $submit && $source['existing'] ) { throw new Exception( 'source_exists' ); }
		$p = $this->db->connection()->posts; $m = $this->db->connection()->postmeta;
		$where = $this->query->ownedSql() . " AND p.ID<>%d AND EXISTS (SELECT 1 FROM $m mk WHERE mk.post_id=p.ID AND mk.meta_key='_aish_message_kind' AND mk.meta_value='submit') AND EXISTS (SELECT 1 FROM $m mr WHERE mr.post_id=p.ID AND mr.meta_key='_aish_canonical_repository' AND BINARY mr.meta_value=BINARY %s) AND EXISTS (SELECT 1 FROM $m mp WHERE mp.post_id=p.ID AND mp.meta_key='_aish_suggested_source_path' AND BINARY mp.meta_value=BINARY %s) AND EXISTS (SELECT 1 FROM $m ms WHERE ms.post_id=p.ID AND ms.meta_key='_aish_submission_state' AND (ms.meta_value IN ('submitted','in_review','needs_changes','accepted') OR (ms.meta_value='draft' AND p.post_author=%d)))";
		if ( ! $submit ) { $where .= ' AND p.post_author=%d'; }
		$values = array( $id, $source['canonical'], $data['source_path'], $actor ); if ( ! $submit ) { $values[] = $actor; }
		if ( $this->db->value( "SELECT p.ID FROM $p p WHERE $where LIMIT 1", $values ) ) { throw new Exception( 'duplicate_source' ); }
	}
	private function updateTarget( array $data, array $source ): void {
		if ( 'update_suggestion' !== $data['kind'] ) { return; }
		$id = $data['target_skill_id']; $revision = $this->query->publicSkillRevision( $id, $data['locale'] );
		if ( null === $revision ) { throw new Exception( 'unavailable' ); } if ( $revision !== $data['target_skill_revision'] ) { throw new Exception( 'conflict' ); }
		$skill = $this->db->row( 'SELECT repository_id,source_path FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d', array( $id ) );
		if ( $skill['repository_id'] ) { if ( (int) $skill['repository_id'] !== $source['repository_id'] || (string) $skill['source_path'] !== $data['source_path'] || 'github' !== $data['source_kind'] ) { throw new Exception( 'invalid_source' ); } }
		elseif ( 'manual' !== $data['source_kind'] || $data['source_path'] || $data['github_url'] ) { throw new Exception( 'invalid_source' ); }
	}
	private function notify( int $owner, string $key, string $template, string $kind, int $id, string $fallbackLocale ): void {
		if ( ! get_userdata( $owner ) || $this->query->erasing( $owner ) || 'active' !== $this->accounts->state( $owner ) ) { return; }
		$locale = get_user_meta( $owner, '_aish_profile_locale', true ); if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { $locale = $fallbackLocale; }
		$this->notifications->notifyContribution( $owner, hash( 'sha256', $key . ':notice:' . $owner ), $template, $locale, 'submit' === $kind ? 'submission' : 'update_suggestion', $id );
	}
	public function save( int $id, array $payload, int $expectedRevision, string $mode, string $requestKey ): array {
		Input::id( $id, true ); Input::id( $expectedRevision, true ); Input::choice( $mode, array( 'draft', 'submit' ) ); $data = Input::payload( $payload, 'submit' === $mode );
		$result = $this->write( 'contribution_save', $requestKey, array( $id, $expectedRevision, $mode, $data ), null, array(), array( $data['target_skill_id'] ), function ( int $actor, string $key, string $hash ) use ( $id, $expectedRevision, $mode, $data ): array {
			$oldState = null; $revision = 1;
			if ( $id ) {
				[ $post, $meta ] = $this->record( $id, true ); if ( (int) $post['post_author'] !== $actor ) { throw new Exception( 'forbidden' ); } $this->version( $meta, $expectedRevision );
				$oldState = $meta['_aish_submission_state']; if ( ! in_array( $oldState, array( 'draft', 'submitted', 'needs_changes' ), true ) || $data['kind'] !== $meta['_aish_message_kind'] ) { throw new Exception( 'conflict' ); }
				$original = $this->payload( $meta ); if ( $original['target_skill_id'] !== $data['target_skill_id'] ) { throw new Exception( 'invalid_input' ); } $revision = $expectedRevision + 1;
			} elseif ( 0 !== $expectedRevision ) { throw new Exception( 'conflict' ); }
			$source = $this->source( $data ); $this->updateTarget( $data, $source ); $this->duplicate( $id, $actor, $data, $source, 'submit' === $mode );
			$state = 'submit' === $mode ? 'submitted' : ( 'needs_changes' === $oldState ? 'needs_changes' : 'draft' );
			$record = array( 'post_type' => 'aish_message', 'post_status' => 'private', 'post_author' => $actor, 'post_title' => $data['name'] ?: ( 'zh_CN' === $data['locale'] ? '未命名投稿草稿' : 'Untitled submission draft' ), 'post_excerpt' => $data['summary'], 'post_content' => $data['notes'], 'comment_status' => 'closed', 'ping_status' => 'closed' ); if ( $id ) { $record['ID'] = $id; }
			$newId = $this->store->post( $record );
			$fields = array( Store::MARKER => '1', '_aish_message_kind' => $data['kind'], '_aish_message_locale' => $data['locale'], '_aish_github_url' => $data['github_url'], '_aish_submission_state' => $state, '_aish_submission_revision' => (string) $revision, '_aish_submission_request_key' => $key, '_aish_submission_payload' => (string) wp_json_encode( $data ), '_aish_canonical_repository' => $source['canonical'], '_aish_repository_id' => null === $source['repository_id'] ? '' : (string) $source['repository_id'], '_aish_suggested_source_path' => $data['source_path'] ?? '', '_aish_target_skill_id' => $data['target_skill_id'] ? (string) $data['target_skill_id'] : '', '_aish_target_skill_revision' => $data['target_skill_revision'] ? (string) $data['target_skill_revision'] : '' );
			if ( ! $id ) { $fields += array( '_aish_result_skill_ids' => '[]', '_aish_user_visible_feedback' => '', '_aish_message_notes' => '', '_aish_handler_id' => '', '_aish_submitted_at' => '', '_aish_resolved_at' => '' ); }
			if ( 'submit' === $mode ) { $fields['_aish_submitted_at'] = self::now(); }
			foreach ( $fields as $field => $value ) { $this->store->set( $newId, $field, $value ); }
			$snapshot = $this->store->snapshot( $newId, $actor, $revision, $data );
			$this->events->record( $key, 'submit' === $data['kind'] ? 'submission' : 'update_suggestion', $newId, $revision, 'submit' === $mode ? 'contribution_submitted' : 'contribution_saved', $actor, $oldState, $state, array( 'payload_hash' => $hash, 'revision_id' => $snapshot ) );
			if ( 'submit' === $mode ) { $this->notify( $actor, $key, 'contribution_submitted', $data['kind'], $newId, $data['locale'] ); }
			return array( 'id' => $newId );
		} );
		return $this->query->mineDetail( $result['id'], $data['locale'] ) ?? throw new Exception( 'unavailable' );
	}
	public function withdraw( int $id, int $expectedRevision, string $requestKey ): array {
		Input::id( $expectedRevision );
		$result = $this->write( 'contribution_withdraw', $requestKey, array( $id, $expectedRevision ), null, array(), array(), function ( int $actor, string $key, string $hash ) use ( $id, $expectedRevision ): array {
			[ $post, $meta ] = $this->record( $id, true ); if ( (int) $post['post_author'] !== $actor ) { throw new Exception( 'forbidden' ); } $this->version( $meta, $expectedRevision );
			if ( ! in_array( $meta['_aish_submission_state'], array( 'draft', 'submitted', 'needs_changes' ), true ) ) { throw new Exception( 'conflict' ); }
			$revision = $expectedRevision + 1; $kind = $meta['_aish_message_kind'];
			$this->store->set( $id, '_aish_submission_state', 'withdrawn' ); $this->store->set( $id, '_aish_submission_revision', (string) $revision ); $this->store->set( $id, '_aish_resolved_at', self::now() );
			$this->store->touch( $post );
			$this->events->record( $key, 'submit' === $kind ? 'submission' : 'update_suggestion', $id, $revision, 'contribution_withdrawn', $actor, $meta['_aish_submission_state'], 'withdrawn', array( 'payload_hash' => $hash ) );
			$this->notify( $actor, $key, 'contribution_withdrawn', $kind, $id, $meta['_aish_message_locale'] ); return array( 'id' => $id );
		} );
		return $this->query->mineDetail( $result['id'], 'en_US' ) ?? throw new Exception( 'unavailable' );
	}
	public function moderate( int $id, int $expectedRevision, string $decision, string $feedback, string $privateNote, array $resultSkillIds, string $requestKey ): array {
		Input::id( $expectedRevision ); Input::choice( $decision, array( 'reviewing', 'needs_information', 'accept', 'reject', 'link_result' ) );
		$feedback = Input::text( $feedback, 3000, in_array( $decision, array( 'needs_information', 'reject' ), true ) ? 5 : 0 ); $privateNote = Input::text( $privateNote, 3000 );
		if ( count( $resultSkillIds ) > 20 ) { throw new Exception( 'invalid_input' ); } $resultSkillIds = array_values( array_unique( array_map( static fn( $v ) => Input::id( $v ), $resultSkillIds ) ) ); sort( $resultSkillIds );
		if ( ! in_array( $decision, array( 'accept', 'link_result' ), true ) && $resultSkillIds ) { throw new Exception( 'invalid_input' ); }
		[ $lookup, $initialMeta ] = $this->record( $id ); $kind = $initialMeta['_aish_message_kind']; $initialPayload = $this->payload( $initialMeta );
		$this->write( 'contribution_moderate', $requestKey, array( $id, $expectedRevision, $decision, $feedback, $privateNote, $resultSkillIds ), Input::cap( $kind ), array( (int) $lookup['post_author'] ), array_merge( $resultSkillIds, array( $initialPayload['target_skill_id'] ) ), function ( int $actor, string $key, string $hash ) use ( $id, $expectedRevision, $decision, $feedback, $privateNote, $resultSkillIds, $kind ): array {
			[ $post, $meta ] = $this->record( $id, true ); if ( $meta['_aish_message_kind'] !== $kind ) { throw new Exception( 'conflict' ); } $this->version( $meta, $expectedRevision );
			if ( (int) $post['post_author'] === $actor || $this->query->erasing( (int) $post['post_author'] ) ) { throw new Exception( 'forbidden' ); }
			$from = $meta['_aish_submission_state'];
			if ( 'link_result' === $decision ? 'accepted' !== $from : ( ! in_array( $from, array( 'submitted', 'in_review' ), true ) || ( 'reviewing' === $decision && 'in_review' === $from ) ) ) { throw new Exception( 'conflict' ); }
			$data = $this->payload( $meta ); $source = $this->source( $data );
			if ( in_array( $decision, array( 'accept', 'link_result' ), true ) ) {
				Input::payload( $data, true ); if ( 'accept' === $decision ) { $this->updateTarget( $data, $source ); }
				foreach ( $resultSkillIds as $skillId ) {
					$skill = $this->db->row( 'SELECT repository_id,source_path FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d', array( $skillId ) ); if ( ! $skill ) { throw new Exception( 'unavailable' ); }
					if ( 'update_suggestion' === $kind && $skillId !== $data['target_skill_id'] ) { throw new Exception( 'invalid_source' ); }
					if ( 'github' === $data['source_kind'] && ( (int) $skill['repository_id'] !== $source['repository_id'] || ( $data['source_path'] && (string) $skill['source_path'] !== $data['source_path'] ) ) ) { throw new Exception( 'invalid_source' ); }
				}
			}
			$to = array( 'reviewing' => 'in_review', 'needs_information' => 'needs_changes', 'accept' => 'accepted', 'reject' => 'rejected', 'link_result' => 'accepted' )[ $decision ]; $revision = $expectedRevision + 1;
			foreach ( array( '_aish_submission_state' => $to, '_aish_submission_revision' => (string) $revision, '_aish_user_visible_feedback' => $feedback, '_aish_message_notes' => $privateNote, '_aish_handler_id' => (string) $actor ) as $field => $value ) { $this->store->set( $id, $field, $value ); }
			if ( 'link_result' !== $decision && in_array( $to, array( 'accepted', 'rejected' ), true ) ) { $this->store->set( $id, '_aish_resolved_at', self::now() ); }
			if ( 'accepted' === $to ) { $this->store->set( $id, '_aish_result_skill_ids', (string) wp_json_encode( $resultSkillIds ) ); }
			$this->store->touch( $post );
			$snapshot = $this->store->snapshot( $id, (int) $post['post_author'], $revision, array( 'decision' => $decision, 'feedback' => $feedback, 'private_note' => $privateNote, 'handler_id' => $actor, 'revision' => $revision, 'result_skill_ids' => $resultSkillIds ), 'moderation' );
			$this->events->record( $key, 'submit' === $kind ? 'submission' : 'update_suggestion', $id, $revision, 'link_result' === $decision ? 'contribution_results_linked' : 'contribution_' . $to, $actor, $from, $to, array( 'payload_hash' => $hash, 'revision_id' => $snapshot ) );
			if ( 'in_review' !== $to ) { $template = 'needs_changes' === $to ? 'contribution_needs_information' : 'contribution_' . $to; $this->notify( (int) $post['post_author'], $key, $template, $kind, $id, $data['locale'] ); }
			return array( 'id' => $id );
		} );
		return $this->query->adminDetail( $kind, $id ) ?? throw new Exception( 'unavailable' );
	}
}
