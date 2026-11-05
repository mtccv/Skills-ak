<?php
declare(strict_types=1);
// Real Scheduler/DAL, modeled wpdb disconnects and worker boundaries; no WordPress, MySQL or HTTP.
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DOING_CRON', true );
	$nativeDriver = class_exists( 'mysqli_driver' );
	if ( ! $nativeDriver ) {
		define( 'MYSQLI_REPORT_OFF', 0 ); define( 'MYSQLI_REPORT_ERROR', 1 ); define( 'MYSQLI_REPORT_STRICT', 2 );
		$GLOBALS['scheduler_mysqli_mode'] = MYSQLI_REPORT_OFF;
		class mysqli_driver { public int $report_mode; public function __construct() { $this->report_mode = $GLOBALS['scheduler_mysqli_mode']; } }
		class mysqli_sql_exception extends \RuntimeException {}
		function mysqli_report( int $mode ): bool { $GLOBALS['scheduler_mysqli_mode'] = $mode; return true; }
	}
	final class wpdb {
		public string $prefix = 't_'; public string $last_error = ''; public bool $ready = true;
		public bool $connected = true; public bool $held = false; public bool $busy = false; public bool $suppressed = false;
		public string $failAt = ''; public int $reconnects = 0; public int $releaseAttempts = 0;
		public array $queries = array(); public array $modes = array();
		public array $job = array( 'status' => 'queued', 'lease_token' => '', 'checkpoints' => 0 );
		private ?array $snapshot = null;
		public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
		public function prepare( string $sql, array $values ): string { return $sql; }
		public function close(): void { $this->connected = false; $this->held = false; }
		public function get_var( string $sql ): int { return $this->query( $sql ); }
		public function get_results( string $sql, string $format ): array { $this->query( $sql ); return array(); }
		public function query( string $sql ): int {
			$this->queries[] = $sql; $mode = ( new mysqli_driver() )->report_mode; $this->modes[] = $mode; $this->last_error = '';
			if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { ++$this->releaseAttempts; }
			if ( '' !== $this->failAt && str_contains( $sql, $this->failAt ) ) {
				$this->failAt = ''; $this->connected = false; $this->held = false;
				if ( null !== $this->snapshot ) { $this->job = $this->snapshot; $this->snapshot = null; }
			}
			if ( ! $this->connected ) {
				$this->last_error = 'fixture disconnected query with private values';
				if ( $mode & MYSQLI_REPORT_STRICT ) { throw new mysqli_sql_exception( $this->last_error, 2006 ); }
				// Model wpdb's automatic connection replacement and replay of this statement.
				++$this->reconnects; $this->connected = true; $this->held = false; $this->last_error = '';
			}
			if ( str_contains( $sql, 'GET_LOCK' ) ) { $this->held = ! $this->busy; return $this->held ? 1 : 0; }
			if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $this->held = false; return 1; }
			if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $this->job; }
			if ( 'COMMIT' === $sql ) { $this->snapshot = null; }
			if ( 'ROLLBACK' === $sql && null !== $this->snapshot ) { $this->job = $this->snapshot; $this->snapshot = null; }
			if ( 'CLAIM_JOB' === $sql ) { $this->job['status'] = 'running'; $this->job['lease_token'] = 'durable-fixture-lease'; }
			if ( 'CHECKPOINT_JOB' === $sql ) { $this->job['status'] = 'queued'; $this->job['lease_token'] = ''; ++$this->job['checkpoints']; }
			return 1;
		}
	}
	function get_transient( string $name ): mixed { $GLOBALS['scheduler_db']->query( 'NATIVE_RATE_READ' ); return false; }
	function set_transient( string $name, mixed $value, int $ttl ): bool { $GLOBALS['scheduler_db']->query( 'NATIVE_RATE_WRITE' ); return true; }
}
namespace AISkillsHub\Core\Database {
	final class Migrator { public function isCurrent(): bool { return true; } }
}
namespace AISkillsHub\Core\Sources {
	final class ImportStore {
		public function __construct( private \AISkillsHub\Core\Database\Database $db ) {}
		public function dueRepositories( int $limit ): array { return $this->db->rows( 'DUE_REPOSITORIES' ); }
	}
	final class Worker {
		public int $runs = 0; public int $apiCalls = 0; public bool $ordinaryFailure = false;
		public function __construct( private \AISkillsHub\Core\Database\Database $db ) {}
		public function run(): void {
			++$this->runs;
			if ( $this->ordinaryFailure ) { throw new \RuntimeException( 'fixture private failure' ); }
			$this->db->transaction( fn() => $this->db->execute( 'CLAIM_JOB' ) );
			try {
				\get_transient( 'rate_limit' );
				++$this->apiCalls; // A mock response, never a network request.
				\set_transient( 'rate_limit', array(), 60 );
				$this->db->transaction( fn() => $this->db->execute( 'CHECKPOINT_JOB' ) );
			} catch ( \Throwable $error ) {
				// Match the real worker's best-effort failure persistence: a lost connection
				// leaves the previously committed lease for a later request to reclaim.
				try { $this->db->transaction( fn() => $this->db->execute( 'RECOVERY_WRITE' ) ); }
				catch ( \Throwable $storageError ) {}
			}
		}
	}
}
namespace {
	$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	require $root . 'Database/Database.php'; require $root . 'Sources/Scheduler.php';
	$count = 0;
	function check( bool $condition, string $message ): void { global $count; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$count; }
	function fixture( string $failure = '', bool $busy = false, bool $ordinaryFailure = false ): array {
		$connection = new wpdb(); $connection->failAt = $failure; $connection->busy = $busy; $GLOBALS['scheduler_db'] = $connection;
		$dal = new \AISkillsHub\Core\Database\Database( $connection ); $store = new \AISkillsHub\Core\Sources\ImportStore( $dal ); $worker = new \AISkillsHub\Core\Sources\Worker( $dal ); $worker->ordinaryFailure = $ordinaryFailure;
		$scheduler = new \AISkillsHub\Core\Sources\Scheduler( $dal, new \AISkillsHub\Core\Database\Migrator(), $store, $worker );
		ob_start(); $scheduler->tick(); $output = ob_get_clean();
		check( '' === $output, 'Cron must not print SQL, native driver errors or false-success notices.' );
		check( 0 === $connection->reconnects, 'No statement can reconnect and replay after the worker lock was lost.' );
		check( ! $connection->held && ! $connection->suppressed, 'Locks and DAL error reporting are released/restored.' );
		check( array_unique( $connection->modes ) === array( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ), 'The complete lock scope, native reads/writes and nested transactions remain strict.' );
		return array( $connection, $worker );
	}
	$originalMode = ( new mysqli_driver() )->report_mode;
	try {
		foreach ( array( MYSQLI_REPORT_OFF, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ) as $startingMode ) {
			mysqli_report( $startingMode );
			list( $connection, $worker ) = fixture();
			check( 1 === $worker->apiCalls && 1 === $connection->job['checkpoints'] && 'queued' === $connection->job['status'], 'Normal execution still performs and checkpoints one step.' );
			check( 1 === $connection->releaseAttempts && ( new mysqli_driver() )->report_mode === $startingMode, 'A normal run releases its lock and restores the original driver mode.' );
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		list( $connection, $worker ) = fixture( '', true );
		check( 0 === $worker->runs && 0 === $connection->releaseAttempts, 'A busy global lock prevents work and is not released by this process.' );
		check( MYSQLI_REPORT_OFF === ( new mysqli_driver() )->report_mode, 'The lock-busy early return restores driver mode.' );
		foreach ( array( 'GET_LOCK', 'DUE_REPOSITORIES', 'CLAIM_JOB', 'NATIVE_RATE_READ' ) as $failure ) {
			list( $connection, $worker ) = fixture( $failure );
			check( 0 === $worker->apiCalls && 0 === $connection->job['checkpoints'], 'A detected disconnect before HTTP cannot continue to API or checkpoint: ' . $failure );
			check( MYSQLI_REPORT_OFF === ( new mysqli_driver() )->report_mode, 'Pre-request disconnect restores driver mode: ' . $failure );
			if ( 'NATIVE_RATE_READ' === $failure ) { check( 'running' === $connection->job['status'] && 'durable-fixture-lease' === $connection->job['lease_token'], 'The committed lease survives a native transient-read failure for later recovery.' ); }
		}
		list( $connection, $worker ) = fixture( 'NATIVE_RATE_WRITE' );
		check( 1 === $worker->apiCalls && 0 === $connection->job['checkpoints'] && 'running' === $connection->job['status'], 'A post-response disconnect cannot undo sent HTTP, and leaves its lease without a false checkpoint.' );
		check( 'durable-fixture-lease' === $connection->job['lease_token'] && MYSQLI_REPORT_OFF === ( new mysqli_driver() )->report_mode, 'Post-response failure preserves the recovery token and restores driver mode.' );
		list( $connection, $worker ) = fixture( '', false, true );
		check( 0 === $worker->apiCalls && 1 === $connection->releaseAttempts && MYSQLI_REPORT_OFF === ( new mysqli_driver() )->report_mode, 'An ordinary worker exception releases the lock and restores mode.' );
		list( $connection, $worker ) = fixture( 'RELEASE_LOCK' );
		check( 1 === $worker->apiCalls && 1 === $connection->job['checkpoints'] && MYSQLI_REPORT_OFF === ( new mysqli_driver() )->report_mode, 'A failed release cannot replay work or prevent driver-mode restoration.' );
	} finally { mysqli_report( $originalMode ); }
	echo 'PASS: ' . $count . ' import-scheduler assertions (' . ( $nativeDriver ? 'native mysqli mode; modeled connection' : 'driver/connection fixtures' ) . "; no WordPress/MySQL/HTTP integration).\n";
}
