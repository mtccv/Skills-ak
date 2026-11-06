<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE8_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
$input = json_decode( (string) stream_get_contents( STDIN ), true );
if ( ! is_array( $input ) || ! preg_match( '#^/tmp/p8-race-[a-f0-9]{16}$#D', $input['barrier'] ?? '' ) || ! is_int( $input['worker'] ?? null ) || ! is_dir( $input['barrier'] ) ) { exit( 3 ); }
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( $input['worker'] + 10 );
$accounts = AISkillsHub\Core\Core::boot()->get( 'accounts' );
file_put_contents( $input['barrier'] . '/' . $input['worker'], 'ready' );
$deadline = microtime( true ) + 10;
while ( ! is_file( $input['barrier'] . '/start' ) ) { if ( microtime( true ) > $deadline ) { echo "timeout\n"; exit( 4 ); } usleep( 10000 ); }
// Avoid having only the zero-wait rate-counter lock decide the race. Native
// password hashing keeps the first email write in progress during this stagger.
usleep( $input['worker'] * 25000 );
try {
	if ( $input['mode'] === 'native' ) {
		$result = wp_insert_user( array( 'user_login' => 'race_' . bin2hex( random_bytes( 12 ) ), 'user_email' => $input['email'], 'user_pass' => $input['password'], 'role' => 'subscriber' ) );
		echo is_wp_error( $result ) ? "rejected\n" : "accepted\n";
	} else {
		$accounts->register( array( 'email' => $input['email'], 'password' => $input['password'], 'display_name' => 'Concurrency QA', 'terms' => true ), 'en_US' );
		echo "accepted\n";
	}
} catch ( Throwable $e ) { echo "rejected\n"; }
