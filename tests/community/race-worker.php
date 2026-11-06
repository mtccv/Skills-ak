<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$input = json_decode( (string) stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
if ( ! is_array( $input ) || ! preg_match( '#^/tmp/p9-race-[a-f0-9]{16}$#D', $input['barrier'] ?? '' ) || ! is_int( $input['worker'] ?? null ) || ! is_int( $input['user'] ?? null ) || ! is_dir( $input['barrier'] ) || ! in_array( $input['mode'] ?? '', array( 'favorite', 'review', 'withdraw', 'approve' ), true ) ) { exit( 3 ); }
wp_set_current_user( $input['user'] );
file_put_contents( $input['barrier'] . '/' . $input['worker'], 'ready' );
$deadline = microtime( true ) + 12;
while ( ! is_file( $input['barrier'] . '/start' ) ) { if ( microtime( true ) > $deadline ) { echo "timeout\n"; exit( 4 ); } usleep( 10000 ); }
try {
	match ( $input['mode'] ) {
		'favorite' => $service->setFavorite( (int) $input['skill'], (bool) ( $input['active'] ?? true ), 'en_US', $input['key'] ),
		'review' => $service->submitReview( (int) $input['skill'], c9_payload( 4, 'en_US', 'CONCURRENT' ), (int) ( $input['version'] ?? 0 ), $input['key'] ),
		'withdraw' => $service->withdrawReview( (int) $input['review'], (int) $input['version'], $input['key'] ),
		'approve' => $service->moderateReview( (int) $input['review'], (int) $input['revision'], (int) $input['version'], 'approve', 'approved', '', $input['key'] ),
	};
	echo "accepted\n";
} catch ( AISkillsHub\Core\Community\Exception $error ) { echo 'rejected:' . $error->reason . "\n"; }
catch ( Throwable $error ) { echo "unexpected\n"; exit( 5 ); }
