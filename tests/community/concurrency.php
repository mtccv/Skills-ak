<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function c9_race( array $inputs ): array {
	$directory = '/tmp/p9-race-' . bin2hex( random_bytes( 8 ) ); mkdir( $directory, 0700 ); $workers = array();
	foreach ( $inputs as $index => $input ) {
		$process = proc_open( array( PHP_BINARY, '/repo/tests/community/race-worker.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Concurrent worker could not start.' ); }
		fwrite( $pipes[0], json_encode( array_merge( $input, array( 'worker' => $index, 'barrier' => $directory ) ) ) ); fclose( $pipes[0] ); $workers[] = array( $process, $pipes );
	}
	$deadline = microtime( true ) + 12;
	while ( count( glob( $directory . '/*' ) ) < count( $inputs ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	file_put_contents( $directory . '/start', 'go' ); $outcomes = array();
	foreach ( $workers as [ $process, $pipes ] ) {
		$output = trim( stream_get_contents( $pipes[1] ) ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		$outcomes[] = array( 'outcome' => $output, 'clean_exit' => $exit === 0 && $stderr === '' );
	}
	foreach ( glob( $directory . '/*' ) as $file ) { unlink( $file ); } rmdir( $directory );
	c9_assert( ! in_array( false, array_column( $outcomes, 'clean_exit' ), true ), 'All independent PHP workers finish without warnings or unexpected errors.' );
	return array_column( $outcomes, 'outcome' );
}

foreach ( array( 'same-key-favorites' => true, 'different-key-favorites' => false ) as $name => $sameKey ) {
	c9_case( $name, function () use ( $name, $sameKey, $db ): void {
		$user = c9_user( $name ); $skill = c9_skill( $name ); $key = c9_key(); $inputs = array();
		for ( $i = 0; $i < 3; ++$i ) { $inputs[] = array( 'mode' => 'favorite', 'user' => $user, 'skill' => $skill, 'key' => $sameKey ? $key : c9_key() ); }
		$outcomes = c9_race( $inputs );
		c9_assert( count( array_filter( $outcomes, static fn( string $result ): bool => $result === 'accepted' ) ) === 3, 'All idempotent target-state additions succeed.' );
		c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'favorites' ) . ' WHERE user_id=%d AND skill_id=%d AND is_active=1', array( $user, $skill ) ), 'Unique user/Skill relation survives three concurrent requests.' );
		c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'metric_events' ) . ' WHERE metric_name=%s AND dimension_key=%s', array( 'favorite_add', (string) $skill ) ), 'Concurrent favorite adds contribute one deduped event.' );
	} );
}

foreach ( array( 'same-key-review-submission' => true, 'different-key-review-submission' => false ) as $name => $sameKey ) {
	c9_case( $name, function () use ( $name, $sameKey, $db ): void {
		$user = c9_user( $name ); $skill = c9_skill( $name ); $key = c9_key(); $inputs = array();
		for ( $i = 0; $i < 3; ++$i ) { $inputs[] = array( 'mode' => 'review', 'user' => $user, 'skill' => $skill, 'key' => $sameKey ? $key : c9_key() ); }
		$outcomes = c9_race( $inputs ); $accepted = count( array_filter( $outcomes, static fn( string $result ): bool => $result === 'accepted' ) );
		c9_assert( $accepted === ( $sameKey ? 3 : 1 ), 'Replay is idempotent while competing new revision claims obey compare-and-swap.' );
		$head = c9_head( $user, $skill );
		c9_assert( count( $db->rows( 'SELECT id FROM ' . $db->table( 'reviews' ) . ' WHERE user_id=%d AND skill_id=%d', array( $user, $skill ) ) ) === 1, 'Only one review identity exists for the pair.' );
		c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'review_revisions' ) . ' WHERE review_id=%d', array( $head['id'] ) ), 'Concurrent first submissions cannot create duplicate or orphan revisions.' );
		c9_assert( null === $head['approved_revision_id'] && (int) $head['pending_revision_id'] > 0, 'Concurrent first submission remains unapproved.' );
	} );
}

c9_case( 'withdraw-vs-approval-race', function () use ( $service, $query, $admin, $db ): void {
	$user = c9_user( 'withdraw-race' ); $skill = c9_skill( 'withdraw-race' ); wp_set_current_user( $user );
	$service->submitReview( $skill, c9_payload(), 0, c9_key() ); $head = c9_head( $user, $skill );
	$outcomes = c9_race( array(
		array( 'mode' => 'withdraw', 'user' => $user, 'skill' => $skill, 'review' => (int) $head['id'], 'version' => (int) $head['lock_version'], 'key' => c9_key() ),
		array( 'mode' => 'approve', 'user' => $admin, 'skill' => $skill, 'review' => (int) $head['id'], 'revision' => (int) $head['pending_revision_id'], 'version' => (int) $head['lock_version'], 'key' => c9_key() ),
	) );
	c9_assert( count( array_filter( $outcomes, static fn( string $result ): bool => $result === 'accepted' ) ) === 1, 'Exactly one concurrent state transition wins the same lock version.' );
	$final = c9_head( $user, $skill ); $summary = $query->publicSummary( $skill, 'en_US' );
	c9_assert( in_array( $final['state'], array( 'approved', 'withdrawn' ), true ), 'Final state is an actual committed transition.' );
	c9_assert( ( $final['state'] === 'approved' && (int) $summary['count'] === 1 && (int) $final['approved_revision_id'] > 0 ) || ( $final['state'] === 'withdrawn' && (int) $summary['count'] === 0 && null === $final['approved_revision_id'] ), 'Published pointer and aggregate cannot split after concurrent moderation and withdrawal.' );
} );

c9_report( 'Independent PHP processes and real MySQL sessions; bounded concurrency, not sustained load' );
