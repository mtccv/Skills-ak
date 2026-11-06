<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE8_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
$cases = array();
foreach ( array( 'three-theme-registrations' => array( 'theme', 'theme', 'theme' ), 'four-native-registrations' => array( 'native', 'native', 'native', 'native' ), 'native-and-theme-race' => array( 'native', 'theme' ) ) as $name => $modes ) {
	$run = bin2hex( random_bytes( 8 ) ); $directory = '/tmp/p8-race-' . $run; mkdir( $directory, 0700 );
	$email = 'race-' . $run . '@example.invalid'; $password = 'Race-QA-' . bin2hex( random_bytes( 20 ) ); $workers = array();
	foreach ( $modes as $index => $mode ) {
		$process = proc_open( array( PHP_BINARY, '/repo/tests/accounts/race-worker.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		fwrite( $pipes[0], json_encode( array( 'mode' => $mode, 'worker' => $index, 'barrier' => $directory, 'email' => $email, 'password' => $password ) ) ); fclose( $pipes[0] );
		$workers[] = array( $process, $pipes );
	}
	$deadline = microtime( true ) + 10;
	while ( count( glob( $directory . '/*' ) ) < count( $modes ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	file_put_contents( $directory . '/start', 'go' ); $outcomes = array(); $errors = 0;
	foreach ( $workers as [ $process, $pipes ] ) {
		$output = trim( stream_get_contents( $pipes[1] ) ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		$outcomes[] = in_array( $output, array( 'accepted', 'rejected' ), true ) ? $output : 'unexpected';
		if ( 0 !== $exit || '' !== $stderr ) { ++$errors; }
	}
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email=%s", $email ) );
	$case = array( 'id' => $name, 'status' => $count === 1 && $errors === 0 && ! in_array( 'unexpected', $outcomes, true ) ? 'passed' : 'failed', 'workers' => count( $modes ), 'native_accounts_created' => $count, 'worker_outcomes' => $outcomes, 'worker_errors' => $errors );
	$cases[] = $case;
	foreach ( glob( $directory . '/*' ) as $file ) { unlink( $file ); } rmdir( $directory );
}
$failed = count( array_filter( $cases, static fn( array $c ): bool => $c['status'] === 'failed' ) );
echo json_encode( array( 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ) ), 'kind' => 'independent concurrent PHP processes and real MySQL sessions; Mock mail queue transport', 'cases' => $cases, 'passed' => count( $cases ) - $failed, 'failed' => $failed ), JSON_PRETTY_PRINT ) . "\n";
exit( $failed ? 1 : 0 );
