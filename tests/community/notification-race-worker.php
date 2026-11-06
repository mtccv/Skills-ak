<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

/** Independent real WordPress/MySQL process; barriers contain no personal data. */
$input = json_decode( (string) stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
if ( ! is_array( $input ) || ! preg_match( '#^/tmp/p9-notice-race-[a-f0-9]{16}$#D', $input['barrier'] ?? '' ) || ! is_dir( $input['barrier'] ) || ! in_array( $input['mode'] ?? '', array( 'approve', 'approve-held', 'erase', 'erase-held' ), true ) || ! in_array( $input['worker'] ?? '', array( 'first', 'second' ), true ) || ! is_int( $input['owner'] ?? null ) || $input['owner'] < 1 ) { exit( 3 ); }
$directory = $input['barrier'];
$hold = static function () use ( $directory ): void {
	file_put_contents( $directory . '/held', 'ready' );
	$deadline = microtime( true ) + 8;
	while ( ! is_file( $directory . '/release' ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Barrier timeout.' ); }
		usleep( 10000 );
	}
};
wp_set_current_user( $admin );
try {
	if ( str_starts_with( $input['mode'], 'erase' ) ) {
		$user = get_userdata( $input['owner'] );
		if ( ! $user instanceof WP_User ) { throw new RuntimeException( 'Fixture identity unavailable.' ); }
		// Runs after real P8 notification cleanup, before P9 review cleanup.
		// The actual eraser already holds its email and recipient account locks.
		$once = false;
		add_filter( 'aish_account_privacy_erase_batch', static function ( bool $done, int $owner ) use ( $input, $directory, $hold, &$once ): bool {
			if ( $owner === $input['owner'] && ! $once ) {
				$once = true; file_put_contents( $directory . '/privacy-cleanup', 'reached' );
				if ( 'erase-held' === $input['mode'] ) { $hold(); }
			}
			return $done;
		}, 1, 2 );
		file_put_contents( $directory . '/started-' . $input['worker'], 'started' );
		$done = false;
		for ( $page = 1; $page <= 8 && ! $done; ++$page ) { $done = (bool) $core->get( 'account_privacy' )->eraser( $user->user_email, $page )['done']; }
		if ( ! $done ) { throw new RuntimeException( 'Privacy cleanup did not complete.' ); }
	} else {
		if ( 'approve-held' === $input['mode'] ) {
			$prefix = 'INSERT INTO ' . $db->table( 'user_notifications' ) . ' ';
			$once = false;
			// Pause before the real INSERT while approval's transaction and locks are
			// still held; this hook never emits SQL text or modifies the query.
			add_filter( 'query', static function ( string $sql ) use ( $prefix, $hold, &$once ): string {
				if ( ! $once && str_starts_with( $sql, $prefix ) ) { $once = true; $hold(); }
				return $sql;
			} );
		}
		file_put_contents( $directory . '/started-' . $input['worker'], 'started' );
		$service->moderateReview( (int) $input['review'], (int) $input['revision'], (int) $input['version'], 'approve', 'approved', '', $input['key'] );
	}
	$result = 'accepted';
} catch ( AISkillsHub\Core\Community\Exception $error ) { $result = 'rejected:' . $error->reason; }
catch ( Throwable $error ) { $result = 'unexpected'; }
file_put_contents( $directory . '/finished-' . $input['worker'], 'finished' );
echo $result . "\n";
exit( 'unexpected' === $result ? 5 : 0 );
