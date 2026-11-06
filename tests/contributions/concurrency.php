<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
function p10_race( array $inputs ): array {
	$directory = '/tmp/p10-race-' . bin2hex( random_bytes( 8 ) ); mkdir( $directory, 0700 ); $workers = array();
	foreach ( $inputs as $index => $input ) {
		$process = proc_open( array( PHP_BINARY, '/repo/tests/contributions/race-worker.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Concurrent worker unavailable.' ); }
		fwrite( $pipes[0], json_encode( array_merge( $input, array( 'worker' => $index, 'barrier' => $directory ) ) ) ); fclose( $pipes[0] ); $workers[] = array( $process, $pipes );
	}
	$deadline = microtime( true ) + 15;
	while ( count( glob( $directory . '/*' ) ) < count( $inputs ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	file_put_contents( $directory . '/start', 'go' ); $outcomes = array();
	foreach ( $workers as [ $process, $pipes ] ) {
		$output = trim( stream_get_contents( $pipes[1] ) ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		$outcomes[] = array( 'outcome' => $output, 'clean_exit' => $exit === 0 && $stderr === '' );
	}
	foreach ( glob( $directory . '/*' ) as $file ) { unlink( $file ); } rmdir( $directory );
	p10_assert( ! in_array( false, array_column( $outcomes, 'clean_exit' ), true ), 'Independent PHP workers finish without warnings or unexpected errors.' );
	return array_column( $outcomes, 'outcome' );
}
function p10_successes( array $outcomes ): int { return count( array_filter( $outcomes, static fn( string $outcome ): bool => $outcome === 'accepted' ) ); }

p10_case( 'same-request-key-concurrent-submission-recovery', function () use ( $submissions, $submissionQuery, $db ): void {
	$user = p10_user( 'race-same' ); $payload = p10_payload( 'race-same' ); $key = p10_key();
	$input = array( 'mode' => 'save', 'user' => $user, 'payload' => $payload, 'key' => $key ); $outcomes = p10_race( array( $input, $input, $input ) );
	p10_assert( p10_successes( $outcomes ) >= 1, 'At least one initial concurrent request commits.' );
	wp_set_current_user( $user ); $row = $submissions->save( 0, $payload, 0, 'submit', $key ); $again = $submissions->save( 0, $payload, 0, 'submit', $key );
	p10_assert( $row['id'] === $again['id'] && $submissionQuery->mine( 'en_US' )['total'] === 1, 'Any bounded lock conflict is safely retried into one proposal identity.' );
	p10_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->connection()->posts . ' WHERE post_parent=%d AND post_type=%s', array( $row['id'], 'revision' ) ), 'Concurrent retries create one immutable snapshot.' );
} );

p10_case( 'different-users-same-known-source-concurrency', function () use ( $submissions, $submissionQuery ): void {
	$users = array( p10_user( 'race-a' ), p10_user( 'race-b' ), p10_user( 'race-c' ) ); $payload = p10_payload( 'race-shared', 'shared/SKILL.md' ); $inputs = array();
	foreach ( $users as $user ) { $inputs[] = array( 'mode' => 'save', 'user' => $user, 'payload' => $payload, 'key' => p10_key() ); }
	$outcomes = p10_race( $inputs ); p10_assert( p10_successes( $outcomes ) === 1, 'Only one same-source pending proposal wins across native user identities.' );
	$total = 0; foreach ( $users as $user ) { wp_set_current_user( $user ); $total += $submissionQuery->mine( 'en_US' )['total']; }
	p10_assert( $total === 1, 'The native Inbox has no duplicate same-source records after the race.' );
} );

p10_case( 'different-paths-allowed-after-concurrent-contention', function () use ( $submissions, $submissionQuery ): void {
	$user = p10_user( 'race-paths' ); $inputs = array();
	foreach ( array( 'one/SKILL.md', 'two/SKILL.md', 'One/SKILL.md' ) as $path ) { $inputs[] = array( 'mode' => 'save', 'user' => $user, 'payload' => p10_payload( 'race-paths', $path ), 'key' => p10_key() ); }
	p10_race( $inputs ); wp_set_current_user( $user ); foreach ( $inputs as $input ) { $submissions->save( 0, $input['payload'], 0, 'submit', $input['key'] ); }
	p10_assert( $submissionQuery->mine( 'en_US' )['total'] === 3, 'Retrying transient contention preserves all three distinct exact paths.' );
} );

p10_case( 'owner-edit-versus-review-cas', function () use ( $submissions, $submissionQuery, $admin ): void {
	$user = p10_user( 'race-review' ); wp_set_current_user( $user ); $payload = p10_payload( 'race-review' ); $row = $submissions->save( 0, $payload, 0, 'submit', p10_key() ); $edit = $payload; $edit['notes'] = 'Changed notes in one concurrent user request.';
	$outcomes = p10_race( array(
		array( 'mode' => 'save', 'user' => $user, 'id' => $row['id'], 'payload' => $edit, 'revision' => $row['revision'], 'save_mode' => 'submit', 'key' => p10_key() ),
		array( 'mode' => 'moderate', 'user' => $admin, 'id' => $row['id'], 'revision' => $row['revision'], 'decision' => 'reviewing', 'key' => p10_key() ),
	) );
	p10_assert( p10_successes( $outcomes ) === 1, 'Exactly one operation wins the same proposal revision.' ); wp_set_current_user( $user ); $final = $submissionQuery->mineDetail( $row['id'], 'en_US' );
	p10_assert( $final['revision'] === $row['revision'] + 1 && in_array( $final['state'], array( 'submitted', 'in_review' ), true ), 'The visible proposal is one coherent committed revision.' );
} );

p10_case( 'withdraw-versus-accept-cas', function () use ( $submissions, $submissionQuery, $admin, $db ): void {
	$user = p10_user( 'race-withdraw' ); wp_set_current_user( $user ); $row = $submissions->save( 0, p10_payload( 'race-withdraw' ), 0, 'submit', p10_key() );
	$outcomes = p10_race( array(
		array( 'mode' => 'withdraw', 'user' => $user, 'id' => $row['id'], 'revision' => $row['revision'], 'key' => p10_key() ),
		array( 'mode' => 'moderate', 'user' => $admin, 'id' => $row['id'], 'revision' => $row['revision'], 'decision' => 'accept', 'key' => p10_key() ),
	) );
	p10_assert( p10_successes( $outcomes ) === 1, 'Exactly one terminal state transition wins.' ); wp_set_current_user( $user ); $final = $submissionQuery->mineDetail( $row['id'], 'en_US' );
	p10_assert( in_array( $final['state'], array( 'accepted', 'withdrawn' ), true ) && $final['revision'] === $row['revision'] + 1, 'Concurrent acceptance and withdrawal cannot split state and revision.' );
	p10_assert( get_post_status( $row['id'] ) === 'private', 'The winning moderation transition cannot publish the private Inbox item.' );
} );
p10_case( 'claim-approval-unique-primary-holder', function () use ( $developers, $developerQuery, $db, $admin ): void {
	$skill = p10_skill( 'race-claim' ); $users = array( p10_user( 'race-claim-a' ), p10_user( 'race-claim-b' ) ); $claims = array();
	foreach ( $users as $user ) { wp_set_current_user( $user ); $claims[] = $developers->submitClaim( $skill, p10_claim_payload( $skill ), p10_key() ); }
	$inputs = array(); foreach ( $claims as $claim ) { $inputs[] = array( 'mode' => 'claim_moderate', 'user' => $admin, 'id' => $claim['id'], 'key' => p10_key() ); }
	$outcomes = p10_race( $inputs ); p10_assert( p10_successes( $outcomes ) === 1, 'Only one competing claim receives the primary approved binding.' );
	p10_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'author_claims' ) . ' WHERE approved_skill_key=%d', array( $skill ) ), 'The MySQL unique approved-Skill index enforces one primary holder.' );
} );

p10_case( 'profile-handle-approval-collision', function () use ( $developers, $developerQuery, $db, $admin ): void {
	$users = array( p10_user( 'race-profile-a' ), p10_user( 'race-profile-b' ) ); $payload = p10_profile_payload( 'race-profile' ); $inputs = array();
	foreach ( $users as $user ) { wp_set_current_user( $user ); $profile = $developers->saveProfile( $payload, 0, p10_key() ); $inputs[] = array( 'mode' => 'profile_moderate', 'user' => $admin, 'owner' => $user, 'revision' => $profile['revision'], 'key' => p10_key() ); }
	$outcomes = p10_race( $inputs ); p10_assert( p10_successes( $outcomes ) === 1, 'Only one reviewed profile wins the same requested public handle.' );
	p10_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->connection()->users . ' WHERE user_nicename=%s', array( $payload['handle'] ) ), 'A public handle resolves one native identity after concurrent approval.' );
} );

p10_report( 'Independent PHP processes / real MySQL connections; bounded races, not sustained-load benchmarking' );
