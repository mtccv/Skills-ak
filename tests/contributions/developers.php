<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

p10_case( 'profile-opt-in-reviewed-snapshot-and-language-boundary', function () use ( $developers, $developerQuery, $admin ): void {
	$user = p10_user( 'profile' ); wp_set_current_user( $user ); $data = p10_profile_payload( 'profile', false ); $key = p10_key();
	$draft = $developers->saveProfile( $data, 0, $key ); $again = $developers->saveProfile( $data, 0, $key );
	p10_assert( $draft['revision'] === $again['revision'] && $draft['state'] === 'pending', 'Profile creation replays into one pending revision.' );
	p10_assert( null === $developerQuery->publicProfile( $data['handle'], 'en_US' ), 'Opt-in alone cannot expose an unreviewed profile.' );
	p10_reject( fn() => $developers->moderateProfile( $user, $draft['revision'], 'approve', 'Self-approval attempt.', '', p10_key() ), 'A subscriber cannot approve their profile.' );
	wp_set_current_user( $admin ); $approved = $developers->moderateProfile( $user, $draft['revision'], 'approve', 'Reviewed public profile.', 'PRIVATE_PROFILE_NOTE', p10_key() );
	$public = $developerQuery->publicProfile( $data['handle'], 'en_US' ); $identity = get_userdata( $user ); $json = (string) wp_json_encode( $public );
	p10_assert( $public['display_name'] === $data['translations']['en_US']['display_name'] && ! str_contains( $json, $identity->user_email ) && ! str_contains( $json, $identity->user_login ) && ! str_contains( $json, 'PRIVATE_PROFILE_NOTE' ), 'The public projection contains only the reviewed public fields.' );
	p10_assert( null === $developerQuery->publicProfile( $data['handle'], 'zh_CN' ) && array_keys( $developerQuery->alternatives( $data['handle'] ) ) === array( 'en_US' ), 'An absent translation produces no fake page or hreflang.' );
	wp_set_current_user( $user ); $edit = $data; $edit['translations']['en_US']['bio'] = 'PENDING_REPLACEMENT_BIO'; $pending = $developers->saveProfile( $edit, $approved['revision'], p10_key() );
	p10_assert( $developerQuery->publicProfile( $data['handle'], 'en_US' )['bio'] === $data['translations']['en_US']['bio'], 'Pending changes retain the previous approved public snapshot.' );
	wp_set_current_user( $admin ); $rejected = $developers->moderateProfile( $user, $pending['revision'], 'reject', 'Please provide a clearer biography.', 'PRIVATE_REJECT_REASON', p10_key() );
	p10_assert( $developerQuery->publicProfile( $data['handle'], 'en_US' )['bio'] === $data['translations']['en_US']['bio'], 'Rejected changes also retain the approved snapshot.' );
	wp_set_current_user( $user ); $developers->withdrawProfile( $rejected['revision'], p10_key() );
	p10_assert( null === $developerQuery->publicProfile( $data['handle'], 'en_US' ) && $developerQuery->alternatives( $data['handle'] ) === array(), 'Withdrawal immediately hides all languages and alternate links.' );
} );

p10_case( 'profile-validation-ownership-and-private-identity', function () use ( $developers, $developerQuery ): void {
	$user = p10_user( 'identity' ); wp_set_current_user( $user ); $payload = p10_profile_payload( 'identity' ); $identity = get_userdata( $user );
	foreach ( array( $identity->user_email, $identity->user_login ) as $private ) { $bad = $payload; $bad['translations']['en_US']['bio'] .= ' ' . $private; p10_reject( fn() => $developers->saveProfile( $bad, 0, p10_key() ), 'Private email and native login cannot enter the public biography.' ); }
	$bad = $payload; $bad['user_id'] = 1; p10_reject( fn() => $developers->saveProfile( $bad, 0, p10_key() ), 'Profile owner cannot be supplied as a payload field.' );
	foreach ( array( 'javascript:alert(1)', 'https://127.0.0.1/', 'http://example.org/', 'https://user:pass@example.org/' ) as $url ) { $bad = $payload; $bad['website_url'] = $url; p10_reject( fn() => $developers->saveProfile( $bad, 0, p10_key() ), 'Unsafe profile URLs are rejected without fetching.' ); }
	$first = $developers->saveProfile( $payload, 0, p10_key() ); p10_reject( fn() => $developers->saveProfile( $payload, 0, p10_key() ), 'Stale profile forms cannot override a newer revision.' );
	$other = p10_user( 'identity-other' ); wp_set_current_user( $other ); p10_assert( $developerQuery->mineProfile()['revision'] === 0, 'Profile reads remain pinned to the current native identity.' );
	p10_reject( fn() => $developerQuery->adminProfile( $user ), 'A subscriber cannot use the administrative profile projection.' );
} );

p10_case( 'native-inbox-and-profile-bypass-protection', function () use ( $submissions, $submissionQuery, $developers, $developerQuery, $db, $admin ): void {
	$user = p10_user( 'native' ); wp_set_current_user( $user ); $row = $submissions->save( 0, p10_payload( 'native' ), 0, 'submit', p10_key() ); $before = $submissionQuery->mineDetail( $row['id'], 'en_US' );
	wp_set_current_user( $admin );
	p10_assert( ! current_user_can( 'edit_post', $row['id'] ) && ! current_user_can( 'read_post', $row['id'] ), 'Native post capabilities cannot bypass the dedicated review screens.' );
	$result = wp_update_post( array( 'ID' => $row['id'], 'post_status' => 'publish', 'post_author' => $admin, 'post_content' => 'FORGED_NATIVE_CONTENT' ), true ); p10_assert( is_wp_error( $result ), 'Even a native administrator post update cannot publish or replace managed content.' );
	p10_assert( false === update_post_meta( $row['id'], '_aish_submission_state', 'accepted' ), 'Direct postmeta updates cannot forge a review decision.' );
	p10_assert( false === delete_post_meta( $row['id'], '_aish_submission_version' ), 'Direct postmeta deletion cannot detach ownership guards.' );
	p10_assert( false === wp_trash_post( $row['id'] ) && false === wp_delete_post( $row['id'], true ), 'Native trash and permanent delete do not remove a proposal.' );
	$snapshot = (int) $db->value( 'SELECT ID FROM ' . $db->connection()->posts . ' WHERE post_parent=%d AND post_type=%s LIMIT 1', array( $row['id'], 'revision' ) );
	p10_assert( is_wp_error( wp_update_post( array( 'ID' => $snapshot, 'post_content' => 'FORGED_SNAPSHOT' ), true ) ), 'The immutable native revision child is protected too.' );
	wp_set_current_user( $user ); p10_assert( $before === $submissionQuery->mineDetail( $row['id'], 'en_US' ), 'All native bypass attempts leave the controlled record unchanged.' );
	$payload = p10_profile_payload( 'native' ); $profile = $developers->saveProfile( $payload, 0, p10_key() ); wp_set_current_user( $admin ); $developers->moderateProfile( $user, $profile['revision'], 'approve', 'Verified public fields.', '', p10_key() );
	p10_assert( false === update_user_meta( $user, '_aish_profile_approved_snapshot', array( 'handle' => 'forged' ) ), 'Native user metadata cannot fabricate an approved public snapshot.' );
	wp_update_user( array( 'ID' => $user, 'user_nicename' => 'forged-native-' . $user ) ); p10_assert( get_userdata( $user )->user_nicename === $payload['handle'], 'Native user editing cannot move an approved public profile handle.' );
	update_user_meta( $user, '_aish_public_profile', '0' ); p10_assert( null === $developerQuery->publicProfile( $payload['handle'], 'en_US' ), 'Existing privacy controls can still remove public consent immediately.' );
} );

p10_case( 'claim-evidence-role-review-and-no-privileges', function () use ( $developers, $developerQuery, $admin, $db ): void {
	$skill = p10_skill( 'claim' ); $user = p10_user( 'claim' ); $other = p10_user( 'claim-other' ); wp_set_current_user( $user );
	$profileData = p10_profile_payload( 'claim' ); $profile = $developers->saveProfile( $profileData, 0, p10_key() );
	$payload = p10_claim_payload( $skill ); $bad = $payload; $bad['proof_kind'] = 'repository_control'; p10_reject( fn() => $developers->submitClaim( $skill, $bad, p10_key() ), 'Repository control alone cannot establish Original Author.' );
	$key = p10_key(); $claim = $developers->submitClaim( $skill, $payload, $key ); $again = $developers->submitClaim( $skill, $payload, $key );
	p10_assert( $claim['state'] === 'pending' && $claim['id'] === $again['id'], 'Claims begin pending and repeat requests keep one identity.' );
	p10_assert( $developerQuery->mineClaims( array( 'display_locale' => 'zh_CN' ) )['total'] === 1 && $developerQuery->mineClaim( $claim['id'], 'zh_CN' )['id'] === $claim['id'], 'Switching the interface language retains the same private claim identity.' );
	p10_reject( fn() => $developers->submitClaim( $skill, $payload, p10_key() ), 'An active claimant cannot duplicate a claim with a new key.' );
	wp_set_current_user( $other ); p10_assert( null === $developerQuery->mineClaim( $claim['id'] ), 'Evidence from another user is not readable.' ); p10_reject( fn() => $developers->withdrawClaim( $claim['id'], 'pending', p10_key() ), 'Another user cannot withdraw the claim.' );
	wp_set_current_user( $admin ); p10_reject( fn() => $developers->moderateClaim( $claim['id'], 'pending', 'approve', 'Checked authorship evidence.', '', p10_key() ), 'Approving requires explicit evidence, role, and source checks.' );
	$approved = $developers->moderateClaim( $claim['id'], 'pending', 'approve', 'Checked original authorship evidence.', 'PRIVATE_CLAIM_NOTE', p10_key(), p10_verification() );
	p10_assert( $approved['state'] === 'approved' && empty( $developerQuery->skillContributors( $skill, 'en_US' ) ), 'Approved authorship does not reveal a pending public profile.' );
	$developers->moderateProfile( $user, $profile['revision'], 'approve', 'Checked bilingual public profile.', '', p10_key() );
	$public = $developerQuery->skillContributors( $skill, 'en_US' ); p10_assert( count( $public ) === 1 && $public[0]['claim_role'] === 'author', 'Reviewed opt-in author identity appears on the public Skill.' );
	p10_assert( $developerQuery->publicProfile( $profileData['handle'], 'zh_CN' )['skills']['total'] === 1, 'Both languages use one claim and stable Skill ID.' );
	wp_set_current_user( $user ); p10_assert( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'aish_manage_skills' ) && ! current_user_can( 'aish_review_skills' ) && ! current_user_can( 'manage_options' ), 'A successful claim grants no editorial or administrator capabilities.' );
	p10_assert( ! str_contains( (string) wp_json_encode( $developerQuery->mineClaim( $claim['id'] ) ), 'PRIVATE_CLAIM_NOTE' ), 'Owner claim projection excludes private moderator notes.' );
	wp_set_current_user( $admin ); $disputed = $developers->moderateClaim( $claim['id'], 'approved', 'dispute', 'Conflicting evidence requires follow-up.', '', p10_key() );
	p10_assert( $disputed['state'] === 'disputed' && $developerQuery->skillContributors( $skill, 'en_US' ) === array(), 'Disputing immediately hides the approved public association.' );
	$revoked = $developers->moderateClaim( $claim['id'], 'disputed', 'revoke', 'Resolved dispute by revoking the claim.', '', p10_key() ); p10_assert( $revoked['state'] === 'revoked' && count( $revoked['decisions'] ) === 3, 'Revoke preserves the full three-decision evidence history.' );
} );

p10_case( 'maintainer-private-target-and-source-change', function () use ( $developers, $developerQuery, $admin, $management ): void {
	$skill = p10_skill( 'maintainer', true, true, 'maintainer/SKILL.md' ); $private = p10_skill( 'private-claim', false ); $user = p10_user( 'maintainer' ); wp_set_current_user( $user );
	p10_reject( fn() => $developers->submitClaim( $private, p10_claim_payload( $private ), p10_key() ), 'A private Skill cannot be claimed through a guessed ID.' );
	$claim = $developers->submitClaim( $skill, p10_claim_payload( $skill, 'maintainer' ), p10_key() ); p10_assert( $claim['claim_role'] === 'maintainer', 'Repository maintainer remains distinct from original author.' );
	wp_set_current_user( $admin ); $management->archiveSkill( $skill, p10_skill_revision( $skill ) );
	p10_reject( fn() => $developers->moderateClaim( $claim['id'], 'pending', 'approve', 'Source must still be publicly current.', '', p10_key(), p10_verification() ), 'Changed or unpublished target cannot receive an approval based on old evidence.' );
	p10_assert( $developerQuery->skillContributors( $skill, 'en_US' ) === array(), 'Archived Skills never expose public contributor links.' );
	wp_set_current_user( $user ); $withdrawn = $developers->withdrawClaim( $claim['id'], 'pending', p10_key() ); p10_assert( $withdrawn['state'] === 'withdrawn', 'An unprocessed claim can be withdrawn without deleting history.' );
} );

p10_case( 'notification-idempotence-private-payload-and-no-external-side-effects', function () use ( $submissions, $developers, $db, $admin, &$externalRequests ): void {
	$user = p10_user( 'notification' ); wp_set_current_user( $user ); $row = $submissions->save( 0, p10_payload( 'notification' ), 0, 'submit', p10_key() );
	wp_set_current_user( $admin ); $key = p10_key(); $submissions->moderate( $row['id'], $row['revision'], 'reject', 'Public feedback only.', 'PRIVATE_NOTIFICATION_NOTE', array(), $key ); $submissions->moderate( $row['id'], $row['revision'], 'reject', 'Public feedback only.', 'PRIVATE_NOTIFICATION_NOTE', array(), $key );
	$rows = $db->rows( 'SELECT template_key,params_json FROM ' . $db->table( 'user_notifications' ) . " WHERE user_id=%d AND subject_type='submission' AND subject_id=%d", array( $user, $row['id'] ) );
	p10_assert( count( $rows ) === 2 && count( array_filter( $rows, static fn( array $r ): bool => $r['template_key'] === 'contribution_rejected' ) ) === 1, 'Submission and moderation each create one deduplicated notification.' );
	p10_assert( array_unique( array_column( $rows, 'params_json' ) ) === array( '{}' ), 'Notification payloads contain no proposal body, private notes, or evidence.' );
	p10_assert( $externalRequests === 0, 'Developer and claim validation do not fetch user-supplied URLs or external APIs.' );
} );
p10_case( 'privacy-consent-reenable-requires-new-review', function () use ( $developers, $developerQuery, $submissions, $admin ): void {
	$user = p10_user( 'consent' ); $skill = p10_skill( 'consent' ); wp_set_current_user( $user ); $payload = p10_payload( 'consent' ); $payload['source_kind'] = 'manual'; $payload['github_url'] = ''; $payload['source_path'] = null; $submission = $submissions->save( 0, $payload, 0, 'submit', p10_key() ); $profilePayload = p10_profile_payload( 'consent' ); $profile = $developers->saveProfile( $profilePayload, 0, p10_key() );
	wp_set_current_user( $admin ); $submissions->moderate( $submission['id'], $submission['revision'], 'accept', 'Published contribution link verified.', '', array( $skill ), p10_key() ); $approved = $developers->moderateProfile( $user, $profile['revision'], 'approve', 'Public profile and contribution consent reviewed.', '', p10_key() );
	p10_assert( count( $developerQuery->publicProfile( $profilePayload['handle'], 'en_US' )['contributions'] ) === 1, 'Only an accepted linked public Skill appears as a consented contribution.' );
	wp_set_current_user( $user ); $restricted = $profilePayload; $restricted['show_contributions'] = false; $pending = $developers->saveProfile( $restricted, $approved['revision'], p10_key() );
	p10_assert( $developerQuery->publicProfile( $profilePayload['handle'], 'en_US' )['contributions'] === array(), 'A restrictive privacy edit hides public contribution links immediately.' );
	update_user_meta( $user, '_aish_public_profile', '0' ); p10_assert( null === $developerQuery->publicProfile( $profilePayload['handle'], 'en_US' ), 'The original account privacy control still removes consent.' );
	$reenabled = $developers->saveProfile( $profilePayload, $pending['revision'], p10_key() ); p10_assert( $reenabled['state'] === 'pending' && null === $developerQuery->publicProfile( $profilePayload['handle'], 'en_US' ), 'Re-enabling consent cannot resurrect the old approved snapshot without review.' );
	wp_set_current_user( $admin ); $developers->moderateProfile( $user, $reenabled['revision'], 'approve', 'Renewed public consent independently reviewed.', '', p10_key() ); p10_assert( null !== $developerQuery->publicProfile( $profilePayload['handle'], 'en_US' ), 'New human approval can restore the newly consented public profile.' );
} );

p10_case( 'pre-suspended-cache-and-native-hook-network-guard', function () use ( $developers, $developerQuery, $admin, &$externalRequests, &$mockMail ): void {
	$user = p10_user( 'cache-hook' ); wp_set_current_user( $user ); $payload = p10_profile_payload( 'cache-hook' ); $oldSlug = get_userdata( $user )->user_nicename; get_user_by( 'slug', $oldSlug ); $developerQuery->mineProfile();
	$oldAdd = wp_suspend_cache_addition(); $oldInvalidation = wp_suspend_cache_invalidation(); wp_suspend_cache_addition( true ); wp_suspend_cache_invalidation( true );
	$blockedHttp = false; $blockedMail = false; $hookCalls = 0;
	$hook = static function ( int $id ) use ( $user, &$blockedHttp, &$blockedMail, &$hookCalls ): void { if ( $id !== $user ) { return; } ++$hookCalls; $response = wp_remote_get( 'https://example.invalid/phase10-guard-check', array( 'timeout' => 1 ) ); $blockedHttp = is_wp_error( $response ) && $response->get_error_code() === 'aish_transaction_network'; $blockedMail = false === wp_mail( 'nobody@example.invalid', 'Isolated transaction guard', 'Must never leave the transaction.' ); };
	try { $profile = $developers->saveProfile( $payload, 0, p10_key() ); wp_set_current_user( $admin ); add_action( 'profile_update', $hook, 10 ); try { $approved = $developers->moderateProfile( $user, $profile['revision'], 'approve', 'Cache and hook regression approval.', '', p10_key() ); } finally { remove_action( 'profile_update', $hook, 10 ); }
		p10_assert( $hookCalls === 1 && $blockedHttp && $blockedMail, 'Native profile hooks cannot initiate HTTP or mail inside the transaction.' );
		p10_assert( wp_suspend_cache_addition() && wp_suspend_cache_invalidation(), 'Caller cache-suspension settings survive the domain operation.' );
		p10_assert( get_userdata( $user )->user_nicename === $payload['handle'] && ! get_user_by( 'slug', $oldSlug ), 'Both previous and new native identity cache keys are invalidated despite suspension.' );
		p10_assert( $developerQuery->publicProfile( $payload['handle'], 'en_US' )['display_name'] === $payload['translations']['en_US']['display_name'], 'A freshly approved snapshot is available after the transaction.' );
	} finally { wp_suspend_cache_addition( $oldAdd ); wp_suspend_cache_invalidation( $oldInvalidation ); }
} );

p10_report( 'Real WordPress/MySQL reviewed developer profiles, claims, native bypass guards and notifications; mock mail only' );
