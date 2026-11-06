<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function c9_notice_start( array $input ): array {
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/notification-race-worker.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Notification race worker could not start.' ); }
	fwrite( $pipes[0], json_encode( $input, JSON_THROW_ON_ERROR ) ); fclose( $pipes[0] );
	return array( $process, $pipes );
}
function c9_notice_wait( string $path ): void {
	$deadline = microtime( true ) + 6;
	while ( ! is_file( $path ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Notification race barrier unavailable.' ); }
		usleep( 10000 );
	}
}
function c9_notice_finish( array $worker ): array {
	[ $process, $pipes ] = $worker;
	$output = trim( stream_get_contents( $pipes[1] ) ); $stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	return array( 'outcome' => $output, 'clean' => 0 === proc_close( $process ) && '' === $stderr );
}

foreach ( array( 'moderation-before-erasure', 'erasure-before-moderation' ) as $ordering ) {
	c9_case( $ordering, function () use ( $ordering, $service, $query, $db ): void {
		$owner = c9_user( $ordering ); $skill = c9_skill( $ordering ); wp_set_current_user( $owner );
		$service->submitReview( $skill, c9_payload(), 0, c9_key() ); $head = c9_head( $owner, $skill );
		$skillBefore = $db->row( 'SELECT * FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) );
		$directory = '/tmp/p9-notice-race-' . bin2hex( random_bytes( 8 ) ); mkdir( $directory, 0700 );
		$workers = array(); $outcomes = array(); $blocked = false;
		$common = array( 'barrier' => $directory, 'owner' => $owner, 'review' => (int) $head['id'], 'revision' => (int) $head['pending_revision_id'], 'version' => (int) $head['lock_version'] );
		try {
			$workers[] = c9_notice_start( $common + array( 'worker' => 'first', 'mode' => 'moderation-before-erasure' === $ordering ? 'approve-held' : 'erase-held', 'key' => c9_key() ) );
			c9_notice_wait( $directory . '/held' );
			$workers[] = c9_notice_start( $common + array( 'worker' => 'second', 'mode' => 'moderation-before-erasure' === $ordering ? 'erase' : 'approve', 'key' => c9_key() ) );
			c9_notice_wait( $directory . '/started-second' );
			usleep( 200000 );
			// Without recipient locking, privacy can delete notices while approval is
			// paused before its INSERT, then leave the newly inserted notice behind.
			$blocked = ! is_file( $directory . '/finished-second' ) && ( 'erasure-before-moderation' === $ordering || ! is_file( $directory . '/privacy-cleanup' ) );
		} finally {
			file_put_contents( $directory . '/release', 'go' );
			foreach ( $workers as $worker ) { $outcomes[] = c9_notice_finish( $worker ); }
			foreach ( glob( $directory . '/*' ) as $path ) { unlink( $path ); } rmdir( $directory );
		}
		c9_assert( $blocked, 'The second independent process waits for the shared recipient account lock.' );
		c9_assert( 2 === count( $outcomes ) && ! in_array( false, array_column( $outcomes, 'clean' ), true ), 'Both workers finish without PHP warnings or unexpected errors.' );
		c9_assert( 'accepted' === $outcomes[0]['outcome'], 'The first real operation completes.' );
		c9_assert( 'moderation-before-erasure' === $ordering ? 'accepted' === $outcomes[1]['outcome'] : str_starts_with( $outcomes[1]['outcome'], 'rejected:' ), 'Erasure completes after approval, or stale approval is rejected after erasure.' );
		$final = $db->row( 'SELECT * FROM ' . $db->table( 'reviews' ) . ' WHERE id=%d', array( $head['id'] ) );
		c9_assert( 'withdrawn' === $final['state'] && null === $final['approved_revision_id'] && null === $final['pending_revision_id'] && null === $final['user_id'], 'Finished erasure leaves an anonymous withdrawn head with no public pointer.' );
		c9_assert( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'user_notifications' ) . ' WHERE user_id=%d', array( $owner ) ), 'No recipient notification survives or arrives after completed erasure.' );
		c9_assert( 0 === $query->publicSummary( $skill, 'en_US' )['count'] && 0 === $query->publicSummary( $skill, 'zh_CN' )['count'], 'Erased review contributes to neither language rating.' );
		$revision = $db->row( 'SELECT comment,moderation_code FROM ' . $db->table( 'review_revisions' ) . ' WHERE id=%d', array( $head['pending_revision_id'] ) );
		c9_assert( $revision && '' === $revision['comment'] && 'privacy_erased' === $revision['moderation_code'], 'The immutable revision is redacted by the real privacy path.' );
		c9_assert( $skillBefore === $db->row( 'SELECT * FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) ), 'The stable Skill and its publication evidence remain unchanged.' );
	} );
}

c9_report( 'Real WordPress/MySQL independent-process notification versus erasure regression; mail mocked, no external API or sustained load' );
