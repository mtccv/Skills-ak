<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use AISkillsHub\Core\Contributions\Privacy;
use AISkillsHub\Core\Submissions\Store as SubmissionStore;

$privacy = $core->get( 'contribution_privacy' );

p10_case( 'owner-bound-export-excludes-private-moderation-and-legacy-inbox', function () use ( $privacy, $submissions, $developers, $db, $admin ): void {
	$user = p10_user( 'privacy-export' ); $other = p10_user( 'privacy-foreign' ); $skill = p10_skill( 'privacy-export' );
	wp_set_current_user( $user ); $payload = p10_payload( 'privacy-export' ); $payload['notes'] = 'OWN_SUBMISSION_CONTENT';
	$row = $submissions->save( 0, $payload, 0, 'submit', p10_key() );
	$profile = $developers->saveProfile( p10_profile_payload( 'privacy-export' ), 0, p10_key() );
	$claim = $developers->submitClaim( $skill, p10_claim_payload( $skill ), p10_key() );
	wp_set_current_user( $other ); $payload = p10_payload( 'privacy-foreign' ); $payload['notes'] = 'FOREIGN_PRIVATE_CONTENT';
	$submissions->save( 0, $payload, 0, 'draft', p10_key() );
	wp_set_current_user( $admin );
	$submissions->moderate( $row['id'], $row['revision'], 'reject', 'Public submission feedback.', 'PRIVATE_MODERATION_NOTE', array(), p10_key() );
	$developers->moderateProfile( $user, $profile['revision'], 'approve', 'Public profile feedback.', 'PRIVATE_MODERATION_NOTE', p10_key() );
	$developers->moderateClaim( $claim['id'], 'pending', 'reject', 'Public claim feedback.', 'PRIVATE_MODERATION_NOTE', p10_key() );
	$legacy = wp_insert_post( array( 'post_type' => 'aish_message', 'post_status' => 'private', 'post_author' => 0, 'post_title' => 'LEGACY_ANONYMOUS_CONTENT', 'post_content' => 'LEGACY_ANONYMOUS_CONTENT' ), true );
	p10_assert( is_int( $legacy ) && $legacy > 0, 'Synthetic historical Inbox item can be created without account ownership.' );
	update_post_meta( $legacy, '_aish_message_kind', 'submit' ); update_post_meta( $legacy, '_aish_sender_email', get_userdata( $user )->user_email );
	$result = $privacy->export( array( 'data' => array(), 'done' => true ), get_userdata( $user ), 1 ); $json = (string) wp_json_encode( $result );
	p10_assert( $result['done'] && str_contains( $json, 'OWN_SUBMISSION_CONTENT' ) && str_contains( $json, 'Public claim feedback.' ) && str_contains( $json, 'Public profile feedback.' ), 'Own submission versions, claims, profile drafts and sanitized decisions are exported.' );
	foreach ( array( 'PRIVATE_MODERATION_NOTE', 'FOREIGN_PRIVATE_CONTENT', 'LEGACY_ANONYMOUS_CONTENT', 'request_key', 'reviewer_id', 'handler_id' ) as $secret ) { p10_assert( ! str_contains( $json, $secret ), 'Private export excludes ' . $secret . '.' ); }
	wp_set_current_user( $other ); p10_reject( fn() => $privacy->export( array( 'data' => array(), 'done' => true ), get_userdata( $user ), 1 ), 'Subscribers cannot request another account export.' );
	wp_set_current_user( $admin );
	p10_reject( fn() => $privacy->export( array(), get_userdata( $user ), 0 ), 'Invalid export offset is rejected.' );
	$stale = clone get_userdata( $user ); $stale->user_email = 'changed@example.invalid';
	p10_reject( fn() => $privacy->export( array(), $stale, 1 ), 'Pinned email mismatch cannot retarget a privacy export.' );
} );

p10_case( 'isolation-transaction-rollback-bounded-revision-and-claim-erasure', function () use ( $privacy, $submissions, $developers, $developerQuery, $db, $admin ): void {
	$user = p10_user( 'privacy-erase' ); $other = p10_user( 'privacy-preserve' ); $skill = p10_skill( 'privacy-erase' ); $fingerprint = p10_skill_fingerprint( $skill );
	wp_set_current_user( $user ); $payload = p10_payload( 'privacy-erase' ); $payload['notes'] = 'OWN_ERASE_CONTENT';
	$row = $submissions->save( 0, $payload, 0, 'draft', p10_key() );
	$profilePayload = p10_profile_payload( 'privacy-erase' ); $profile = $developers->saveProfile( $profilePayload, 0, p10_key() );
	$claim = $developers->submitClaim( $skill, p10_claim_payload( $skill ), p10_key() );
	wp_set_current_user( $other ); $foreignPayload = p10_payload( 'privacy-preserve' ); $foreignPayload['notes'] = 'FOREIGN_RETAINED_CONTENT'; $foreign = $submissions->save( 0, $foreignPayload, 0, 'draft', p10_key() );
	wp_set_current_user( $admin );
	$developers->moderateProfile( $user, $profile['revision'], 'approve', 'Reviewed developer for erasure.', 'PRIVATE_PROFILE_HISTORY', p10_key() );
	$developers->moderateClaim( $claim['id'], 'pending', 'approve', 'Verified author evidence for erasure.', 'PRIVATE_CLAIM_HISTORY', p10_key(), p10_verification() );
	$native = $db->connection(); $store = new SubmissionStore( $db );
	// More than one privacy batch, without bypassing the native immutable snapshot writer.
	$store->transaction( function ( SubmissionStore $store ) use ( $row, $user, $payload ): void { for ( $version = 2; $version <= 52; ++$version ) { $store->snapshot( $row['id'], $user, $version, $payload ); } } );
	$legacy = wp_insert_post( array( 'post_type' => 'aish_message', 'post_status' => 'private', 'post_author' => 0, 'post_title' => 'LEGACY_RETAINED_CONTENT', 'post_content' => 'LEGACY_RETAINED_CONTENT' ), true );
	$contact = wp_insert_post( array( 'post_type' => 'aish_message', 'post_status' => 'private', 'post_author' => $user, 'post_title' => 'CONTACT_RETAINED_CONTENT', 'post_content' => 'CONTACT_RETAINED_CONTENT' ), true );
	update_post_meta( $legacy, '_aish_message_kind', 'submit' ); update_post_meta( $legacy, '_aish_sender_email', get_userdata( $user )->user_email ); update_post_meta( $contact, '_aish_message_kind', 'contact' );
	$legacyBefore = serialize( $db->rows( "SELECT * FROM {$native->posts} WHERE ID IN (%d,%d)", array( $legacy, $contact ) ) );
	p10_assert( null !== $developerQuery->publicProfile( $profilePayload['handle'], 'en_US' ) && count( $developerQuery->skillContributors( $skill, 'en_US' ) ) === 1, 'Approved profile and author association begin publicly visible.' );
	p10_reject( fn() => $privacy->erase( true, $user ), 'An account cannot be erased before its durable quarantine marker.' );
	$privacy->isolate( $user );
	p10_assert( null === $developerQuery->publicProfile( $profilePayload['handle'], 'en_US' ) && $developerQuery->skillContributors( $skill, 'en_US' ) === array(), 'Isolation immediately hides the profile and attribution before batch redaction.' );
	wp_set_current_user( $user ); p10_reject( fn() => $submissions->save( $row['id'], $payload, $row['revision'], 'submit', p10_key() ), 'Quarantined identities cannot write new contribution content.' ); wp_set_current_user( $admin );
	$before = serialize( $db->rows( "SELECT * FROM {$native->posts} WHERE ID=%d OR post_parent=%d ORDER BY ID", array( $row['id'], $row['id'] ) ) );
	$fail = static function ( string $sql ) use ( $db, $user ): string { return str_starts_with( $sql, 'UPDATE ' . $db->table( 'author_claims' ) . ' SET claimant_user_id=NULL' ) && str_contains( $sql, 'claimant_user_id=' . $user ) ? 'SELECT FROM aish_qa_deliberately_invalid' : $sql; };
	add_filter( 'query', $fail, PHP_INT_MAX );
	try { p10_reject( fn() => $privacy->erase( true, $user ), 'A real SQL failure cannot claim successful erasure.' ); } finally { remove_filter( 'query', $fail, PHP_INT_MAX ); }
	p10_assert( $before === serialize( $db->rows( "SELECT * FROM {$native->posts} WHERE ID=%d OR post_parent=%d ORDER BY ID", array( $row['id'], $row['id'] ) ) ), 'Failed claim write rolls back message and revision redaction atomically.' );
	p10_assert( get_post( $row['id'] )->post_content === 'OWN_ERASE_CONTENT' && '1' === get_user_meta( $user, Privacy::ERASING, true ) && ! Privacy::writing(), 'Rollback invalidates object cache and retains quarantine without leaking writer scope.' );
	$previousInvalidation = wp_suspend_cache_invalidation(); wp_suspend_cache_invalidation( true );
	try {
		p10_assert( ! $privacy->erase( true, $user ), 'One 50-revision batch cannot report 52 revisions complete.' );
		p10_assert( wp_suspend_cache_invalidation(), 'Privacy restores the caller previous cache invalidation suspension state.' );
		p10_assert( get_post( $row['id'] )->post_content === '' && (int) get_post( $row['id'] )->post_author === 0, 'Even presuspended invalidation cannot keep cached private post text or ownership after erasure.' );
	} finally { wp_suspend_cache_invalidation( $previousInvalidation ); }
	p10_assert( (int) get_post_meta( $row['id'], Privacy::DETACHED_OWNER, true ) === $user && (int) get_post( $row['id'] )->post_author === 0, 'Partial cleanup preserves its private owner tombstone, not frontend ownership.' );
	p10_assert( ! $privacy->erase( false, $user ), 'The preceding account module pending state is retained.' );
	p10_assert( $privacy->erase( true, $user ), 'Remaining private rows finish on a later bounded request.' );
	$head = get_post( $row['id'] ); $children = $db->rows( "SELECT post_author,post_title,post_content,post_excerpt FROM {$native->posts} WHERE post_parent=%d AND post_type='revision'", array( $row['id'] ) );
	p10_assert( (int) $head->post_author === 0 && $head->post_status === 'private' && $head->post_content === '' && get_post_meta( $row['id'], '_aish_submission_state', true ) === 'withdrawn' && '' === get_post_meta( $row['id'], Privacy::DETACHED_OWNER, true ), 'Erased message retains its stable private ID and state, without content or identity.' );
	p10_assert( count( $children ) === 52 && ! str_contains( (string) wp_json_encode( $children ), 'OWN_ERASE_CONTENT' ) && array_unique( array_column( $children, 'post_author' ) ) === array( '0' ), 'All immutable revision IDs remain, with personal text and authorship removed.' );
	$claimRow = $db->row( 'SELECT * FROM ' . $db->table( 'author_claims' ) . ' WHERE id=%d', array( $claim['id'] ) );
	p10_assert( null === $claimRow['claimant_user_id'] && null === $claimRow['active_request_key'] && null === $claimRow['approved_skill_key'] && $claimRow['state'] === 'revoked' && strlen( $claimRow['request_key'] ) === 64, 'Erasure revokes attribution and releases ownership keys while preserving the replay tombstone.' );
	p10_assert( ! get_user_meta( $user, '_aish_profile_decision', false ) && ! get_user_meta( $user, '_aish_profile_approved_snapshot', false ), 'Private draft snapshots and profile moderation history are erased.' );
	p10_assert( 'FOREIGN_RETAINED_CONTENT' === get_post( $foreign['id'] )->post_content && $legacyBefore === serialize( $db->rows( "SELECT * FROM {$native->posts} WHERE ID IN (%d,%d)", array( $legacy, $contact ) ) ), 'Other users, historical anonymous Inbox and Contact records remain byte-for-byte unchanged.' );
	p10_assert( $fingerprint === p10_skill_fingerprint( $skill ), 'Skill ID, versions, approvals and bilingual translations are unchanged by erasure.' );
} );

p10_case( 'moderator-erasure-preserves-other-author-approval-evidence', function () use ( $privacy, $developers, $developerQuery, $submissions, $db, $admin ): void {
	$moderator = p10_user( 'privacy-moderator' ); $owner = p10_user( 'privacy-reviewed' ); $skill = p10_skill( 'privacy-reviewed' );
	$actor = get_userdata( $moderator ); foreach ( array( 'aish_manage_developers', 'aish_manage_author_claims', 'aish_manage_submissions' ) as $cap ) { $actor->add_cap( $cap ); }
	wp_set_current_user( $owner ); $payload = p10_profile_payload( 'privacy-reviewed' ); $profile = $developers->saveProfile( $payload, 0, p10_key() ); $claim = $developers->submitClaim( $skill, p10_claim_payload( $skill ), p10_key() );
	$proposal = $submissions->save( 0, p10_payload( 'privacy-moderated' ), 0, 'submit', p10_key() ); $proposalContent = get_post( $proposal['id'] )->post_content;
	wp_set_current_user( $moderator ); $developers->moderateProfile( $owner, $profile['revision'], 'approve', 'Public profile decision retained.', 'MODERATOR_PRIVATE_PERSONAL_TEXT', p10_key() ); $developers->moderateClaim( $claim['id'], 'pending', 'approve', 'Public claim decision retained.', 'MODERATOR_PRIVATE_PERSONAL_TEXT', p10_key(), p10_verification() );
	$submissions->moderate( $proposal['id'], $proposal['revision'], 'reject', 'Public submission decision retained.', 'MODERATOR_PRIVATE_PERSONAL_TEXT', array(), p10_key() );
	wp_set_current_user( $admin ); $privacy->isolate( $moderator ); p10_assert( $privacy->erase( true, $moderator ), 'Moderator private notes are erased in a bounded privacy batch.' );
	$proof = json_decode( (string) $db->value( 'SELECT proof_json FROM ' . $db->table( 'author_claims' ) . ' WHERE id=%d', array( $claim['id'] ) ), true );
	p10_assert( $proof['decisions'][0]['reviewer_id'] === $moderator && $proof['decisions'][0]['private_note'] === '' && $proof['decisions'][0]['reason'] === 'Public claim decision retained.', 'Claim moderation keeps minimal historical attribution and public reason but removes private note text.' );
	$history = (string) wp_json_encode( get_user_meta( $owner, '_aish_profile_decision', false ) );
	p10_assert( ! str_contains( $history, 'MODERATOR_PRIVATE_PERSONAL_TEXT' ) && get_user_meta( $owner, '_aish_profile_private_note', true ) === '', 'Both current and append-only profile moderator private notes are redacted.' );
	$native = $db->connection(); $snapshots = $db->rows( "SELECT post_content FROM {$native->posts} WHERE post_parent=%d AND post_type='revision'", array( $proposal['id'] ) );
	p10_assert( ! str_contains( (string) wp_json_encode( $snapshots ), 'MODERATOR_PRIVATE_PERSONAL_TEXT' ) && get_post_meta( $proposal['id'], '_aish_message_notes', true ) === '', 'Current submission notes and immutable moderation snapshots redact the deleted moderator private text.' );
	p10_assert( get_post( $proposal['id'] )->post_content === $proposalContent && get_post_meta( $proposal['id'], '_aish_user_visible_feedback', true ) === 'Public submission decision retained.' && get_post_meta( $proposal['id'], '_aish_submission_state', true ) === 'rejected', 'The other submitter keeps their original content, public feedback and review outcome.' );
	p10_assert( null !== $developerQuery->publicProfile( $payload['handle'], 'en_US' ) && count( $developerQuery->skillContributors( $skill, 'en_US' ) ) === 1, 'Another person retains their approved profile and attribution.' );
} );

p10_case( 'native-delete-reassign-cannot-transfer-owned-inbox-or-revision', function () use ( $privacy, $submissions, $db, $admin, $core, $submissionQuery ): void {
	$owner = p10_user( 'privacy-native' ); $recipient = p10_user( 'privacy-recipient' );
	wp_set_current_user( $owner ); $row = $submissions->save( 0, p10_payload( 'privacy-native' ), 0, 'draft', p10_key() );
	$beforeId = $row['id']; $native = $db->connection();
	wp_set_current_user( $admin ); p10_assert( wp_delete_user( $owner, $recipient ), 'The actual native WordPress user deletion completes with content reassignment requested.' );
	p10_assert( ! get_userdata( $owner ) && (int) get_post( $beforeId )->post_author === 0 && (int) get_post_meta( $beforeId, Privacy::DETACHED_OWNER, true ) === $owner, 'Private contribution is detached before native reassignment, retaining durable cleanup identity.' );
	p10_assert( 0 === (int) $db->value( "SELECT COUNT(*) FROM {$native->posts} WHERE post_parent=%d AND post_type='revision' AND post_author<>0", array( $beforeId ) ), 'Native revision authors cannot transfer to the reassignment recipient.' );
	wp_set_current_user( $recipient ); p10_assert( null === $submissionQuery->mineDetail( $beforeId, 'en_US' ), 'The reassigned account cannot read the detached private proposal.' ); wp_set_current_user( $admin );
	$core->get( 'account_privacy' )->tick();
	p10_assert( '' === get_post( $beforeId )->post_content && '' === get_post_meta( $beforeId, Privacy::DETACHED_OWNER, true ), 'The existing durable native account cleanup job finishes detached contribution redaction.' );
} );

p10_case( 'native-large-account-detachment-progress-survives-retry', function () use ( $privacy, $db, $admin ): void {
	$owner = p10_user( 'privacy-large' ); $native = $db->connection(); $ids = array(); $store = new SubmissionStore( $db );
	$store->transaction( function ( SubmissionStore $store ) use ( $owner, &$ids ): void {
		for ( $i = 0; $i < 101; ++$i ) {
			$id = $store->post( array( 'post_type' => 'aish_message', 'post_status' => 'private', 'post_author' => $owner, 'post_title' => 'Generated bounded cleanup fixture', 'post_content' => 'Private QA payload', 'post_excerpt' => '', 'comment_status' => 'closed', 'ping_status' => 'closed' ) );
			$store->set( $id, SubmissionStore::MARKER, '1' ); $store->set( $id, '_aish_message_kind', 'submit' ); $ids[] = $id;
		}
	} );
	wp_set_current_user( $admin ); p10_reject( fn() => wp_delete_user( $owner, $admin ), 'More than 100 owned records abort native reassignment until a bounded retry.' );
	p10_assert( get_userdata( $owner ) instanceof WP_User && '1' === get_user_meta( $owner, '_aish_account_deletion_pending', true ), 'The failed deletion retains the isolated native identity and durable pending marker.' );
	$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	p10_assert( 100 === (int) $db->value( "SELECT COUNT(*) FROM {$native->posts} WHERE ID IN ($marks) AND post_author=0", $ids ), 'The first bounded detachment commits progress before the retry signal.' );
	p10_assert( wp_delete_user( $owner, $admin ), 'A second native attempt completes the final owned contribution detachment.' );
	p10_assert( 101 === (int) $db->value( "SELECT COUNT(*) FROM {$native->posts} WHERE ID IN ($marks) AND post_author=0", $ids ), 'No private proposal is reassigned or deleted across native retries.' );
	$completed = false; for ( $batch = 0; $batch < 12 && ! $completed; ++$batch ) { $completed = $privacy->erase( true, $owner ); }
	p10_assert( $completed && 0 === (int) $db->value( "SELECT COUNT(*) FROM {$native->posts} WHERE ID IN ($marks) AND post_content<>''", $ids ), 'Existing owned IDs all finish bounded cleanup after the native account is gone.' );
} );

p10_report( 'Real WordPress/MySQL contribution export, erasure, rollback, native deletion and reassign protection; mock mail only' );
