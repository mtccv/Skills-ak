<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use AISkillsHub\Core\Submissions\Input;

p10_case( 'repository-root-path-and-untrusted-input', function (): void {
	$valid = p10_payload( 'identity', 'skills/Case/SKILL.md' );
	$valid['github_url'] = 'https://github.com/Example/Repository.git';
	$clean = Input::payload( $valid, true );
	p10_assert( $clean['github_url'] === 'https://github.com/example/repository' && $clean['source_path'] === 'skills/Case/SKILL.md', 'Repository identity is normalized while path case is preserved.' );
	foreach ( array( 'http://github.com/example/repository', 'https://127.0.0.1/repo/test', 'https://github.com@example.invalid/a/b', 'https://github.com/example/repository/tree/main', 'https://github.com/example/repository?token=secret' ) as $url ) { $bad = $valid; $bad['github_url'] = $url; p10_reject( fn() => Input::payload( $bad, true ), 'Only canonical public GitHub repository roots are accepted.' ); }
	foreach ( array( '../SKILL.md', '/SKILL.md', 'nested/../SKILL.md', 'nested\\SKILL.md', 'nested/skill.md', 'SKILL.md' . "\0" ) as $path ) { $bad = $valid; $bad['source_path'] = $path; p10_reject( fn() => Input::payload( $bad, true ), 'Malformed relative Skill paths are rejected.' ); }
	$bad = $valid; $bad['user_id'] = 1; p10_reject( fn() => Input::payload( $bad, true ), 'User identity cannot enter through the submission payload.' );
	$bad = $valid; $bad['notes'] = array( 'untrusted' ); p10_reject( fn() => Input::payload( $bad, true ), 'Array input cannot coerce into content.' );
	$clean = Input::payload( array_merge( $valid, array( 'name' => '<script>window.__p10xss=1</script>Real name' ) ), true );
	p10_assert( ! str_contains( $clean['name'], '<script' ), 'Stored plain text cannot contain an executable script element.' );
} );

p10_case( 'authentication-verification-and-ownership', function () use ( $submissions, $submissionQuery ): void {
	wp_set_current_user( 0 ); p10_reject( fn() => $submissions->save( 0, p10_payload( 'guest' ), 0, 'submit', p10_key() ), 'Anonymous submission is denied.' );
	$pending = p10_user( 'unverified', false ); wp_set_current_user( $pending ); p10_reject( fn() => $submissions->save( 0, p10_payload( 'unverified' ), 0, 'draft', p10_key() ), 'Even a draft requires verified email.' );
	$user = p10_user( 'owner' ); $other = p10_user( 'intruder' ); wp_set_current_user( $user );
	$row = $submissions->save( 0, p10_payload( 'owner' ), 0, 'draft', p10_key() );
	p10_assert( (int) get_post( $row['id'] )->post_author === $user && get_post_status( $row['id'] ) === 'private', 'The current native identity owns a private Inbox record.' );
	p10_assert( $submissionQuery->mineDetail( $row['id'], 'zh_CN' )['id'] === $row['id'], 'Both languages resolve one private submission identity.' );
	wp_set_current_user( $other ); p10_assert( null === $submissionQuery->mineDetail( $row['id'], 'en_US' ), 'Other users cannot read by changing the ID.' );
	p10_reject( fn() => $submissions->save( $row['id'], p10_payload( 'owner' ), $row['revision'], 'draft', p10_key() ), 'Other users cannot edit by changing the ID.' );
	p10_reject( fn() => $submissions->withdraw( $row['id'], $row['revision'], p10_key() ), 'Other users cannot withdraw by changing the ID.' );
	p10_reject( fn() => $submissionQuery->adminList( 'submit' ), 'Subscriber cannot access administrative submissions.' );
	p10_assert( $submissionQuery->mine( 'en_US', array( 'user_id' => $user ) )['total'] === 0, 'A user_id query parameter never overrides the native owner.' );
} );

p10_case( 'draft-revisions-compare-and-swap-and-replay', function () use ( $submissions, $submissionQuery, $db, $admin ): void {
	$user = p10_user( 'draft' ); wp_set_current_user( $user ); $payload = p10_payload( 'draft' ); $payload['name'] = ''; $payload['summary'] = ''; $payload['notes'] = '';
	$key = p10_key(); $first = $submissions->save( 0, $payload, 0, 'draft', $key );
	$replay = $submissions->save( 0, $payload, 0, 'draft', $key );
	p10_assert( $first['id'] === $replay['id'] && $first['revision'] === $replay['revision'], 'A retried creation returns the same immutable revision.' );
	$complete = p10_payload( 'draft' ); $edited = $submissions->save( $first['id'], $complete, $first['revision'], 'draft', p10_key() );
	p10_assert( $edited['revision'] === $first['revision'] + 1 && $edited['state'] === 'draft', 'Draft updates advance one revision.' );
	p10_reject( fn() => $submissions->save( $first['id'], $complete, $first['revision'], 'submit', p10_key() ), 'A stale form cannot overwrite a newer draft.' );
	$submitted = $submissions->save( $first['id'], $complete, $edited['revision'], 'submit', p10_key() );
	p10_assert( $submitted['state'] === 'submitted', 'Submitting a completed draft enters Pending.' );
	wp_set_current_user( $admin ); $detail = $submissionQuery->adminDetail( 'submit', $first['id'] );
	p10_assert( $detail['snapshots']['total'] === 3 && $detail['history']['total'] === 3, 'Each content revision has exactly one immutable snapshot and audit event.' );
	p10_assert( '' === get_post_meta( $first['id'], '_aish_submission_snapshot_revision', true ), 'Native revision metadata is never remapped onto the parent proposal.' );
	p10_assert( 3 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->connection()->postmeta . ' m INNER JOIN ' . $db->connection()->posts . ' r ON r.ID=m.post_id WHERE r.post_parent=%d AND r.post_type=%s AND m.meta_key=%s', array( $first['id'], 'revision', '_aish_submission_snapshot_revision' ) ), 'Each native revision child carries its own immutable snapshot marker.' );
	$oldest = $db->row( 'SELECT post_content FROM ' . $db->connection()->posts . ' WHERE post_parent=%d AND post_type=%s ORDER BY ID ASC LIMIT 1', array( $first['id'], 'revision' ) );
	p10_assert( json_decode( $oldest['post_content'], true )['name'] === '', 'Editing the current proposal does not rewrite the initial snapshot.' );
} );

p10_case( 'source-dedup-multi-skill-case-and-unconfirmed-path', function () use ( $submissions ): void {
	$user = p10_user( 'source-a' ); $other = p10_user( 'source-b' ); wp_set_current_user( $user ); $payload = p10_payload( 'source', 'skills/Alpha/SKILL.md' );
	$first = $submissions->save( 0, $payload, 0, 'submit', p10_key() );
	wp_set_current_user( $other ); p10_reject( fn() => $submissions->save( 0, $payload, 0, 'submit', p10_key() ), 'The same canonical repository and known path are deduplicated across users.', array( 'duplicate_source' ) );
	$payload['source_path'] = 'skills/Beta/SKILL.md'; $second = $submissions->save( 0, $payload, 0, 'submit', p10_key() );
	$payload['source_path'] = 'skills/alpha/SKILL.md'; $case = $submissions->save( 0, $payload, 0, 'submit', p10_key() );
	p10_assert( count( array_unique( array( $first['id'], $second['id'], $case['id'] ) ) ) === 3, 'Different exact paths inside one repository remain distinct.' );
	$payload['source_path'] = null; $unknown = $submissions->save( 0, $payload, 0, 'submit', p10_key() );
	p10_assert( $unknown['source']['path_status'] === 'pending_confirmation' && $unknown['payload']['source_path'] === null, 'An unknown path is marked for confirmation, never invented.' );
	wp_set_current_user( $user ); $unknown2 = $submissions->save( 0, $payload, 0, 'submit', p10_key() ); p10_assert( $unknown['id'] !== $unknown2['id'], 'An unknown path cannot force a false repository-wide merge.' );
} );

p10_case( 'formal-source-gate-hides-private-skill-identity', function () use ( $submissions, $run ): void {
	$skill = p10_skill( 'private-source', false, true, 'private/SKILL.md' ); $user = p10_user( 'private-source' ); wp_set_current_user( $user );
	$payload = p10_payload( 'private-source', 'private/SKILL.md' ); $payload['github_url'] = 'https://github.com/qa-' . $run . '/phase10-skills';
	p10_reject( fn() => $submissions->save( 0, $payload, 0, 'submit', p10_key() ), 'An existing private source produces only a generic source-exists rejection.', array( 'source_exists' ) );
	$payload['source_path'] = 'another/SKILL.md'; p10_assert( $submissions->save( 0, $payload, 0, 'submit', p10_key() )['state'] === 'submitted', 'Another Skill path in that repository remains eligible.' );
} );

p10_case( 'historical-anonymous-and-contact-isolation', function () use ( $submissions, $submissionQuery, $db, $admin ): void {
	$user = p10_user( 'legacy' ); wp_set_current_user( $admin ); $ids = array();
	foreach ( array( 'submit', 'contact' ) as $kind ) {
		$id = wp_insert_post( array( 'post_type' => 'aish_message', 'post_status' => 'private', 'post_author' => 0, 'post_title' => 'Legacy private inbox QA', 'post_content' => 'LEGACY_ANONYMOUS_PRIVATE_BODY' ) );
		update_post_meta( $id, '_aish_message_kind', $kind ); update_post_meta( $id, '_aish_message_email', get_userdata( $user )->user_email ); $ids[] = $id;
	}
	$before = hash( 'sha256', (string) wp_json_encode( $db->rows( 'SELECT * FROM ' . $db->connection()->posts . ' WHERE ID IN (%d,%d)', $ids ) ) );
	wp_set_current_user( $user ); p10_assert( $submissionQuery->mine( 'en_US' )['total'] === 0, 'Matching email does not claim any historical anonymous Inbox item.' );
	foreach ( $ids as $id ) { p10_assert( null === $submissionQuery->mineDetail( $id, 'en_US' ), 'Legacy private record cannot be fetched by a verified owner.' ); p10_reject( fn() => $submissions->save( $id, p10_payload( 'legacy' ), 1, 'draft', p10_key() ), 'Legacy and Contact items cannot be rewritten through submissions.' ); }
	$after = hash( 'sha256', (string) wp_json_encode( $db->rows( 'SELECT * FROM ' . $db->connection()->posts . ' WHERE ID IN (%d,%d)', $ids ) ) ); p10_assert( hash_equals( $before, $after ), 'Historical records remain byte-identical after attempted ownership bypass.' );
} );

p10_case( 'manual-review-state-machine-and-publication-boundary', function () use ( $submissions, $submissionQuery, $db, $admin ): void {
	$skill = p10_skill( 'accepted-public' ); $privateSkill = p10_skill( 'accepted-private', false ); $user = p10_user( 'review' ); wp_set_current_user( $user );
	$reviewPayload = p10_payload( 'review' ); $reviewPayload['source_kind'] = 'manual'; $reviewPayload['github_url'] = ''; $reviewPayload['source_path'] = null; $row = $submissions->save( 0, $reviewPayload, 0, 'submit', p10_key() );
	p10_reject( fn() => $submissions->moderate( $row['id'], $row['revision'], 'accept', 'Forged', '', array(), p10_key() ), 'A verified subscriber cannot self-accept.' );
	wp_set_current_user( $admin ); $review = $submissions->moderate( $row['id'], $row['revision'], 'reviewing', 'Review begun.', 'PRIVATE_ADMIN_ONLY', array(), p10_key() );
	wp_set_current_user( $user ); p10_reject( fn() => $submissions->save( $row['id'], $reviewPayload, $review['revision'], 'draft', p10_key() ), 'A reviewing proposal is frozen against user changes.' );
	p10_reject( fn() => $submissions->withdraw( $row['id'], $review['revision'], p10_key() ), 'A reviewing proposal cannot be withdrawn behind the reviewer.' );
	wp_set_current_user( $admin ); $info = $submissions->moderate( $row['id'], $review['revision'], 'needs_information', 'Please clarify the source.', 'PRIVATE_ADMIN_ONLY', array(), p10_key() );
	wp_set_current_user( $user ); $mine = $submissionQuery->mineDetail( $row['id'], 'en_US' );
	p10_assert( $mine['state'] === 'needs_changes' && $mine['feedback'] === 'Please clarify the source.' && ! str_contains( (string) wp_json_encode( $mine ), 'PRIVATE_ADMIN_ONLY' ), 'The user sees actionable feedback without private moderator notes.' );
	$resubmit = $submissions->save( $row['id'], $reviewPayload, $info['revision'], 'submit', p10_key() );
	$before = (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skills' ) ); $skillHash = p10_skill_fingerprint( $skill );
	wp_set_current_user( $admin ); $key = p10_key(); $accepted = $submissions->moderate( $row['id'], $resubmit['revision'], 'accept', 'Accepted into editorial handling.', 'PRIVATE_ADMIN_ONLY', array( $skill, $privateSkill ), $key );
	$repeat = $submissions->moderate( $row['id'], $resubmit['revision'], 'accept', 'Accepted into editorial handling.', 'PRIVATE_ADMIN_ONLY', array( $skill, $privateSkill ), $key );
	p10_assert( $accepted['revision'] === $repeat['revision'] && $accepted['state'] === 'accepted', 'A retried moderation decision is idempotent.' );
	p10_assert( $before === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skills' ) ) && hash_equals( $skillHash, p10_skill_fingerprint( $skill ) ), 'Acceptance neither creates nor changes a formal Skill.' );
	wp_set_current_user( $user ); $mine = $submissionQuery->mineDetail( $row['id'], 'en_US' ); p10_assert( count( $mine['result_skills'] ) === 1 && $mine['result_skills'][0]['id'] === $skill && ! array_key_exists( 'result_skill_ids', $mine ), 'Only current public result cards are projected to the owner.' );
	p10_reject( fn() => $submissions->save( $row['id'], $reviewPayload, $accepted['revision'], 'draft', p10_key() ), 'An accepted proposal cannot overwrite the audit record.' );
} );

p10_case( 'withdraw-rejection-manual-source-and-private-pagination', function () use ( $submissions, $submissionQuery, $admin ): void {
	$user = p10_user( 'withdraw' ); wp_set_current_user( $user ); $payload = p10_payload( 'manual' ); $payload['source_kind'] = 'manual'; $payload['github_url'] = ''; $payload['source_path'] = null;
	$row = $submissions->save( 0, $payload, 0, 'submit', p10_key() ); $key = p10_key(); $withdrawn = $submissions->withdraw( $row['id'], $row['revision'], $key );
	p10_assert( $withdrawn['state'] === 'withdrawn' && $submissions->withdraw( $row['id'], $row['revision'], $key )['revision'] === $withdrawn['revision'], 'Withdrawal is a retained, idempotent state transition.' );
	$row = $submissions->save( 0, p10_payload( 'rejected' ), 0, 'submit', p10_key() ); wp_set_current_user( $admin ); $rejected = $submissions->moderate( $row['id'], $row['revision'], 'reject', 'Source evidence insufficient.', 'PRIVATE_REASON', array(), p10_key() );
	p10_assert( $rejected['state'] === 'rejected', 'A rejected record remains available for its audit history.' );
	wp_set_current_user( $user ); $page = $submissionQuery->mine( 'zh_CN', array( 'page' => 2, 'per_page' => 1 ) ); p10_assert( $page['total'] === 2 && count( $page['items'] ) === 1, 'Private lists paginate across the same bilingual identity.' );
} );

p10_case( 'update-suggestions-preserve-skill-version-and-translations', function () use ( $submissions, $submissionQuery, $admin, $run ): void {
	$skill = p10_skill( 'update', true, true, 'update/SKILL.md' ); $user = p10_user( 'update' ); wp_set_current_user( $user );
	$payload = p10_payload( 'update', 'update/SKILL.md' ); $payload['kind'] = 'update_suggestion'; $payload['github_url'] = 'https://github.com/qa-' . $run . '/phase10-skills'; $payload['target_skill_id'] = $skill; $payload['target_skill_revision'] = p10_skill_revision( $skill ); $payload['commit_sha'] = str_repeat( 'b', 40 ); $payload['evidence'] = 'The source commit has a documented change for human review.';
	$before = p10_skill_fingerprint( $skill ); $row = $submissions->save( 0, $payload, 0, 'submit', p10_key() );
	p10_assert( $row['kind'] === 'update_suggestion' && $row['state'] === 'submitted', 'An update suggestion is a separate private review type.' );
	wp_set_current_user( $admin ); $submissions->moderate( $row['id'], $row['revision'], 'accept', 'Accepted for review by editors.', '', array( $skill ), p10_key() );
	p10_assert( hash_equals( $before, p10_skill_fingerprint( $skill ) ), 'Accepting an update cannot mutate the Skill, version snapshots, or either translation.' );
	wp_set_current_user( $user ); $bad = $payload; $bad['target_skill_revision'] += 1; p10_reject( fn() => $submissions->save( 0, $bad, 0, 'submit', p10_key() ), 'Stale target revisions require a fresh review.' );
	$bad = $payload; $bad['github_url'] = 'https://github.com/other/source'; p10_reject( fn() => $submissions->save( 0, $bad, 0, 'submit', p10_key() ), 'A proposed update cannot switch the target repository identity.' );
	$bad = $payload; $bad['source_path'] = 'different/SKILL.md'; p10_reject( fn() => $submissions->save( 0, $bad, 0, 'submit', p10_key() ), 'A proposed update cannot switch the target source path.' );
} );

p10_case( 'accepted-proposal-later-result-association', function () use ( $submissions, $submissionQuery, $admin, $run, $db ): void {
	$user = p10_user( 'late-result' ); wp_set_current_user( $user ); $payload = p10_payload( 'late-result', 'late/SKILL.md' ); $payload['github_url'] = 'https://github.com/qa-' . $run . '/phase10-skills';
	$row = $submissions->save( 0, $payload, 0, 'submit', p10_key() ); wp_set_current_user( $admin ); $accepted = $submissions->moderate( $row['id'], $row['revision'], 'accept', 'Accepted for later editorial processing.', '', array(), p10_key() );
	$skill = p10_skill( 'late-result', true, true, 'late/SKILL.md' ); $wrong = p10_skill( 'late-result-wrong', true, true, 'wrong/SKILL.md' ); $fingerprint = p10_skill_fingerprint( $skill );
	p10_reject( fn() => $submissions->moderate( $row['id'], $accepted['revision'], 'link_result', 'Linking a different source is forbidden.', '', array( $wrong ), p10_key() ), 'Late association cannot link a different Skill source.' );
	$key = p10_key(); $linked = $submissions->moderate( $row['id'], $accepted['revision'], 'link_result', 'Editorially published result is now linked.', '', array( $skill ), $key );
	$replay = $submissions->moderate( $row['id'], $accepted['revision'], 'link_result', 'Editorially published result is now linked.', '', array( $skill ), $key );
	p10_assert( $linked['state'] === 'accepted' && $linked['revision'] === $replay['revision'], 'Late linking preserves acceptance and supports safe request replay.' );
	p10_reject( fn() => $submissions->moderate( $row['id'], $accepted['revision'], 'link_result', 'Stale association must not win.', '', array(), p10_key() ), 'Late association also enforces compare-and-swap.' );
	wp_set_current_user( $user ); $mine = $submissionQuery->mineDetail( $row['id'], 'en_US' ); p10_assert( count( $mine['result_skills'] ) === 1 && $mine['result_skills'][0]['id'] === $skill, 'The submitter can discover the later published result.' );
	p10_assert( hash_equals( $fingerprint, p10_skill_fingerprint( $skill ) ), 'Association never modifies the formally published Skill.' );
	p10_assert( 3 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'user_notifications' ) . " WHERE user_id=%d AND subject_type='submission' AND subject_id=%d", array( $user, $row['id'] ) ), 'Late association produces one deduplicated additional outcome notice.' );
} );

p10_case( 'result-card-batching-over-catalog-limit', function () use ( $submissions, $submissionQuery, $admin ): void {
	$skills = array(); for ( $i = 0; $i < 26; ++$i ) { $skills[] = p10_skill( 'batch-result-' . $i, false ); }
	$user = p10_user( 'batch-result' );
	foreach ( array_chunk( $skills, 13 ) as $index => $ids ) { wp_set_current_user( $user ); $payload = p10_payload( 'batch-result-' . $index ); $payload['source_kind'] = 'manual'; $payload['github_url'] = ''; $payload['source_path'] = null; $row = $submissions->save( 0, $payload, 0, 'submit', p10_key() ); wp_set_current_user( $admin ); $submissions->moderate( $row['id'], $row['revision'], 'accept', 'Linked editorial fixtures for batching.', '', $ids, p10_key() ); }
	wp_set_current_user( $user ); $page = $submissionQuery->mine( 'en_US' ); p10_assert( $page['total'] === 2 && count( $page['items'] ) === 2, 'A page with 26 distinct linked IDs respects the catalog query batch limit.' );
	foreach ( $page['items'] as $item ) { p10_assert( $item['result_skills'] === array(), 'Private linked results stay hidden even when projections require multiple batches.' ); }
} );

p10_case( 'native-write-failure-rolls-back-and-retries', function () use ( $submissions, $submissionQuery, $db ): void {
	$user = p10_user( 'rollback' ); wp_set_current_user( $user ); $payload = p10_payload( 'rollback' ); $key = p10_key();
	$fail = static function ( $value, $id, $field ) { return '_aish_submission_state' === $field ? false : $value; };
	add_filter( 'update_post_metadata', $fail, PHP_INT_MAX, 3 );
	try { p10_reject( fn() => $submissions->save( 0, $payload, 0, 'submit', $key ), 'A controlled native metadata failure aborts the whole proposal.' ); }
	finally { remove_filter( 'update_post_metadata', $fail, PHP_INT_MAX ); }
	p10_assert( $submissionQuery->mine( 'en_US' )['total'] === 0, 'No partial Inbox head is visible after rollback.' );
	p10_assert( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'workflow_events' ) . ' WHERE actor_user_id=%d', array( $user ) ), 'Rollback retains no audit event claiming a successful write.' );
	p10_assert( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'user_notifications' ) . " WHERE user_id=%d AND type='contribution'", array( $user ) ), 'Rollback retains no success notification.' );
	$row = $submissions->save( 0, $payload, 0, 'submit', $key ); p10_assert( $row['state'] === 'submitted' && $row['revision'] === 1 && $submissionQuery->mine( 'en_US' )['total'] === 1, 'The same request key can recover after a confirmed rollback.' );
} );

p10_case( 'no-fetch-no-automatic-publication', function () use ( &$externalRequests, $db ): void {
	p10_assert( $externalRequests === 0, 'Submission validation and review perform zero external API requests.' );
	p10_assert( (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->connection()->posts . " p JOIN " . $db->connection()->postmeta . " m ON m.post_id=p.ID AND m.meta_key='_aish_submission_version' AND m.meta_value='1' WHERE p.post_status<>'private'" ) === 0, 'All managed proposal records remain private.' );
} );

p10_report( 'Real WordPress/PHP/MySQL submission service integration; mock mail only; no external API' );
