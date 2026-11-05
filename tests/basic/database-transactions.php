<?php
declare(strict_types=1);
// Driver/connection-loss model only. No WordPress bootstrap or MySQL socket is used.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
$native_driver = class_exists( 'mysqli_driver' );
if ( ! $native_driver ) {
	define( 'MYSQLI_REPORT_OFF', 0 ); define( 'MYSQLI_REPORT_ERROR', 1 ); define( 'MYSQLI_REPORT_STRICT', 2 );
	$GLOBALS['test_mysqli_mode'] = MYSQLI_REPORT_OFF;
	class mysqli_driver { public int $report_mode; public function __construct() { $this->report_mode = $GLOBALS['test_mysqli_mode']; } }
	class mysqli_sql_exception extends RuntimeException {}
	function mysqli_report( int $mode ): bool { $GLOBALS['test_mysqli_mode'] = $mode; return true; }
}

/** Emulate wpdb replaying its failed query after a lost connection, unless mysqli throws first. */
final class wpdb {
	public string $last_error = '';
	public array $data = array( 'first' => 0, 'second' => 0 );
	public array $queries = array();
	public array $modes = array();
	public ?string $disconnect_at = null;
	public ?string $sql_error_at = null;
	public bool $lose_commit_reply = false;
	public bool $fail_rollback = false;
	public bool $connected = true;
	public bool $ready = true;
	public bool $fail_close = false;
	public int $closed = 0;
	public int $implicit_commits = 0;
	public int $replays = 0;
	public bool $suppressed = false;
	private ?array $snapshot = null;
	public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	private function driverError(): int|false {
		$this->last_error = 'fixture SQL contains private-value';
		if ( ( new mysqli_driver() )->report_mode & MYSQLI_REPORT_STRICT ) { throw new mysqli_sql_exception( $this->last_error, 2006 ); }
		return false;
	}
	public function query( string $sql ): int|false {
		if ( ! $this->ready ) { return false; }
		$this->last_error = ''; $this->queries[] = $sql; $this->modes[] = ( new mysqli_driver() )->report_mode;
		if ( $sql === $this->disconnect_at ) {
			$this->disconnect_at = null;
			$this->connected = false;
			$this->data = $this->snapshot ?? $this->data;
			$this->snapshot = null; // The server rolled back the lost session.
		}
		if ( ! $this->connected ) {
			$this->driverError(); // In strict mode the wpdb reconnection branch is never reached.
			$this->connected = true; ++$this->replays; $this->last_error = '';
		}
		if ( $sql === $this->sql_error_at || ( 'ROLLBACK' === $sql && $this->fail_rollback ) ) { return $this->driverError(); }
		if ( 'START TRANSACTION' === $sql ) { if ( null !== $this->snapshot ) { ++$this->implicit_commits; } $this->snapshot = $this->data; }
		elseif ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
			if ( $this->lose_commit_reply ) { $this->lose_commit_reply = false; $this->connected = false; return $this->driverError(); }
		} elseif ( 'ROLLBACK' === $sql ) { $this->data = $this->snapshot ?? $this->data; $this->snapshot = null; }
		elseif ( 'FIRST' === $sql ) { $this->data['first'] = 1; }
		elseif ( 'SECOND' === $sql ) { $this->data['second'] = 1; }
		return 1;
	}
	public function close(): bool {
		++$this->closed;
		if ( $this->fail_close ) { throw new RuntimeException( 'fixture close error private-value' ); }
		$this->data = $this->snapshot ?? $this->data;
		$this->snapshot = null; $this->connected = false; $this->ready = false;
		return true;
	}
}

require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Database/Database.php';
use AISkillsHub\Core\Database\Database;
$count = 0;
function check( bool $value, string $message ): void { global $count; if ( ! $value ) { throw new RuntimeException( $message ); } ++$count; }
function failure( callable $operation ): Throwable {
	try { $operation(); } catch ( Throwable $error ) { return $error; }
	throw new RuntimeException( 'Expected the transaction to fail.' );
}
function work( Database $database ): string { $database->execute( 'FIRST' ); $database->execute( 'SECOND' ); return 'result'; }
function restored( wpdb $connection, int $mode ): void {
	check( ( new mysqli_driver() )->report_mode === $mode, 'The original driver reporting mode is restored.' );
	check( ! $connection->suppressed, 'The original wpdb error suppression is restored.' );
}

$original_mode = ( new mysqli_driver() )->report_mode;
try {
	// The control proves this model reproduces the old wpdb success/partial-commit failure mode.
	mysqli_report( MYSQLI_REPORT_OFF );
	$connection = new wpdb(); $connection->disconnect_at = 'SECOND';
	foreach ( array( 'START TRANSACTION', 'FIRST', 'SECOND', 'COMMIT' ) as $sql ) { $connection->query( $sql ); }
	check( array( 'first' => 0, 'second' => 1 ) === $connection->data && 1 === $connection->replays, 'Unprotected wpdb replay can acknowledge a partial write.' );
	$connection = new wpdb(); $connection->fail_rollback = true;
	foreach ( array( 'START TRANSACTION', 'FIRST', 'ROLLBACK', 'START TRANSACTION', 'COMMIT' ) as $sql ) { $connection->query( $sql ); }
	check( 1 === $connection->data['first'] && 1 === $connection->implicit_commits, 'An unprotected later START can commit writes abandoned by failed rollback.' );

	foreach ( array( MYSQLI_REPORT_OFF, MYSQLI_REPORT_ERROR, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ) as $saved_mode ) {
		mysqli_report( $saved_mode );
		$connection = new wpdb(); $database = new Database( $connection );
		check( 'result' === $database->transaction( fn() => work( $database ) ), 'Success returns the operation result.' );
		check( array( 'first' => 1, 'second' => 1 ) === $connection->data, 'Successful writes commit together.' );
		check( array( 'START TRANSACTION', 'FIRST', 'SECOND', 'COMMIT' ) === $connection->queries, 'The normal transaction is neither replayed nor duplicated.' );
		check( array_unique( $connection->modes ) === array( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ), 'Every statement, including START/COMMIT, uses strict driver reporting.' );
		restored( $connection, $saved_mode );

		$connection = new wpdb(); $connection->disconnect_at = 'SECOND'; $database = new Database( $connection );
		$error = failure( fn() => $database->transaction( fn() => work( $database ) ) );
		check( 'Core database operation failed.' === $error->getMessage() && null === $error->getPrevious(), 'A driver failure exposes neither its SQL text nor a chained raw error.' );
		check( array( 'first' => 0, 'second' => 0 ) === $connection->data && 0 === $connection->replays, 'A disconnected transaction cannot replay a partial autocommit.' );
		check( 1 === count( array_filter( $connection->queries, static fn( $sql ) => 'SECOND' === $sql ) ), 'A failed statement is not blindly sent again.' );
		restored( $connection, $saved_mode );
		check( 1 === $connection->closed && ! $connection->ready, 'An unconfirmed rollback closes and disables the original connection.' );
		$queries = count( $connection->queries );
		$error = failure( fn() => $database->transaction( fn() => work( $database ) ) );
		check( 'Core database connection requires a new request.' === $error->getMessage() && $queries === count( $connection->queries ), 'The failed connection cannot be reused by a later transaction.' );
		failure( fn() => $database->execute( 'FIRST' ) );
		check( $queries === count( $connection->queries ), 'Ordinary DAL calls also refuse the quarantined connection.' );
		check( false === $connection->query( 'FIRST' ), 'Native wpdb calls cannot reuse the disabled connection.' );
		$new_connection = new wpdb(); $new_database = new Database( $new_connection );
		check( 'result' === $new_database->transaction( fn() => work( $new_database ) ), 'Only a fresh connection can resume future work.' );
		restored( $connection, $saved_mode );
	}

	mysqli_report( MYSQLI_REPORT_OFF );
	$connection = new wpdb(); $connection->disconnect_at = 'START TRANSACTION'; $database = new Database( $connection ); $called = false;
	failure( function () use ( $database, &$called ) { $database->transaction( function () use ( &$called ) { $called = true; } ); } );
	check( ! $called && array( 'START TRANSACTION' ) === $connection->queries, 'A failed START does not run the callback or claim a rollback.' );
	check( 0 === $connection->replays, 'Even START cannot silently reconnect in the scoped transaction.' ); restored( $connection, MYSQLI_REPORT_OFF );
	$connection->connected = true; $connection->last_error = '';
	check( 'result' === $database->transaction( fn() => work( $database ) ), 'START failure also clears transaction state.' );

	$connection = new wpdb(); $connection->sql_error_at = 'SECOND'; $database = new Database( $connection );
	$error = failure( fn() => $database->transaction( fn() => work( $database ) ) );
	check( 'Core database operation failed.' === $error->getMessage(), 'An ordinary SQL failure uses the fixed public error.' );
	check( array( 'first' => 0, 'second' => 0 ) === $connection->data && 'ROLLBACK' === end( $connection->queries ), 'An ordinary SQL failure rolls back earlier statements.' ); restored( $connection, MYSQLI_REPORT_OFF );

	$connection = new wpdb(); $connection->disconnect_at = 'SECOND'; $database = new Database( $connection );
	$error = failure( fn() => $database->transaction( function () use ( $connection, $database ) { $database->execute( 'FIRST' ); $connection->query( 'SECOND' ); } ) );
	check( 'Core database operation failed.' === $error->getMessage(), 'Native wpdb calls inside the callback receive the same safe error boundary.' );
	check( array( 'first' => 0, 'second' => 0 ) === $connection->data && 0 === $connection->replays, 'Native metadata writes cannot reconnect around the DAL guard.' ); restored( $connection, MYSQLI_REPORT_OFF );

	$connection = new wpdb(); $connection->lose_commit_reply = true; $database = new Database( $connection );
	$error = failure( fn() => $database->transaction( fn() => work( $database ) ) );
	check( 'Core database operation failed.' === $error->getMessage(), 'An unacknowledged COMMIT is a failure, not a false success.' );
	check( array( 'first' => 1, 'second' => 1 ) === $connection->data, 'Unknown COMMIT may already be durable; the test does not pretend it rolled back.' );
	check( 0 === $connection->replays && 1 === count( array_filter( $connection->queries, static fn( $sql ) => 'COMMIT' === $sql ) ), 'Unknown COMMIT is not automatically replayed.' ); restored( $connection, MYSQLI_REPORT_OFF );

	$connection = new wpdb(); $connection->fail_rollback = true; $database = new Database( $connection );
	$original = new DomainException( 'Expected business conflict.' );
	$error = failure( fn() => $database->transaction( static function () use ( $database, $original ) { $database->execute( 'FIRST' ); throw $original; } ) );
	check( $error === $original, 'Rollback failure does not replace the primary business conflict with SQL details.' ); restored( $connection, MYSQLI_REPORT_OFF );
	check( 1 === $connection->closed && array( 'first' => 0, 'second' => 0 ) === $connection->data, 'Closing a live failed-rollback session discards its uncommitted writes.' );
	$queries = count( $connection->queries );
	failure( fn() => $database->transaction( static fn() => null ) );
	check( $queries === count( $connection->queries ) && 0 === $connection->implicit_commits, 'A failed rollback cannot be followed by an implicit-committing START.' );

	$connection = new wpdb(); $connection->fail_rollback = true; $connection->fail_close = true; $database = new Database( $connection );
	$error = failure( fn() => $database->transaction( static function () use ( $database, $original ) { $database->execute( 'FIRST' ); throw $original; } ) );
	check( $error === $original && 1 === $connection->closed && ! $connection->ready, 'Even close failure preserves the original error and disables native SQL.' );
	$queries = count( $connection->queries );
	failure( fn() => $database->transaction( static fn() => null ) );
	check( $queries === count( $connection->queries ) && 0 === $connection->implicit_commits, 'Close failure cannot reopen the quarantined connection.' ); restored( $connection, MYSQLI_REPORT_OFF );

	$connection = new wpdb(); $database = new Database( $connection );
	$error = failure( fn() => $database->transaction( static function () use ( $database, $original ) { $database->execute( 'FIRST' ); throw $original; } ) );
	check( $error === $original && 0 === $connection->closed && $connection->ready, 'A successful rollback leaves a healthy connection available.' );
	check( 'result' === $database->transaction( fn() => work( $database ) ), 'A healthy rolled-back transaction permits later work.' ); restored( $connection, MYSQLI_REPORT_OFF );

	$connection = new wpdb(); $database = new Database( $connection );
	$database->transaction( function () use ( $database ) {
		$error = failure( fn() => $database->transaction( static fn() => null ) );
		check( $error instanceof LogicException, 'Nested transactions remain rejected.' );
		check( ( new mysqli_driver() )->report_mode === ( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ), 'Rejecting a nested operation cannot disable its outer guard.' );
		work( $database );
	} );
	check( array( 'first' => 1, 'second' => 1 ) === $connection->data, 'An outer transaction can finish after rejecting a nested call.' ); restored( $connection, MYSQLI_REPORT_OFF );
} finally { mysqli_report( $original_mode ); }
echo 'Database transactions: ' . $count . ' isolated assertions passed (' . ( $native_driver ? 'native mysqli mode; modeled connection' : 'driver/connection fixtures' ) . "; no WordPress/MySQL connection).\n";
