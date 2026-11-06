<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$input = json_decode( (string) stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
if ( ! is_array( $input ) || ! preg_match( '#^/tmp/p10-race-[a-f0-9]{16}$#D', $input['barrier'] ?? '' ) || ! is_int( $input['worker'] ?? null ) || ! is_int( $input['user'] ?? null ) || ! is_dir( $input['barrier'] ) || ! in_array( $input['mode'] ?? '', array( 'save', 'withdraw', 'moderate', 'claim', 'claim_moderate', 'profile_moderate' ), true ) ) { exit( 3 ); }
wp_set_current_user( $input['user'] ); file_put_contents( $input['barrier'] . '/' . $input['worker'], 'ready' );
$deadline = microtime( true ) + 15;
while ( ! is_file( $input['barrier'] . '/start' ) ) { if ( microtime( true ) > $deadline ) { echo 'timeout'; exit( 4 ); } usleep( 10000 ); }
try {
	match ( $input['mode'] ) {
		'save' => $submissions->save( (int) ( $input['id'] ?? 0 ), $input['payload'], (int) ( $input['revision'] ?? 0 ), $input['save_mode'] ?? 'submit', $input['key'] ),
		'withdraw' => $submissions->withdraw( (int) $input['id'], (int) $input['revision'], $input['key'] ),
		'claim' => $developers->submitClaim( (int) $input['skill'], $input['payload'], $input['key'] ),
		'claim_moderate' => $developers->moderateClaim( (int) $input['id'], 'pending', 'approve', 'Independently verified concurrent claim evidence.', '', $input['key'], p10_verification() ),
		'profile_moderate' => $developers->moderateProfile( (int) $input['owner'], (int) $input['revision'], 'approve', 'Reviewed profile identity in race.', '', $input['key'] ),
		'moderate' => $submissions->moderate( (int) $input['id'], (int) $input['revision'], $input['decision'], 'Concurrent review.', '', array(), $input['key'] ),
	};
	echo 'accepted';
} catch ( AISkillsHub\Core\Submissions\Exception | AISkillsHub\Core\Developers\Exception $error ) { echo 'rejected:' . $error->reason; }
catch ( Throwable $error ) { echo 'unexpected'; exit( 5 ); }
