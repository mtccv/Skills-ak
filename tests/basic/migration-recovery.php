<?php
declare(strict_types=1);

// Migration control-flow model only: no real WordPress bootstrap, DDL, or MySQL socket.
namespace AISkillsHub\Core\Database {
	final class Schema {
		public const VERSION = '1.5.0';
		public const COLUMNS = array( 'skills' => array(), 'skill_translations' => array(), 'ai_jobs' => array(), 'ai_attempts' => array(), 'ai_reviews' => array(), 'email_verifications' => array(), 'user_notifications' => array(), 'notification_outbox' => array(), 'favorites' => array(), 'reviews' => array(), 'review_revisions' => array(), 'review_reports' => array(), 'skill_reports' => array(), 'workflow_events' => array(), 'metric_events' => array(), 'daily_metrics' => array(), 'author_claims' => array(), 'editorial_collections' => array(), 'collection_translations' => array(), 'collection_items' => array(), 'use_cases' => array(), 'use_case_translations' => array(), 'use_case_skills' => array(), 'term_translations' => array() );
		public static bool $verify_failure = false;
		public static bool $nested_transaction = false;
		public static function definitions( Database $database, string $version ): array {
			$definitions = array( 'skills' => 'DDL:' . $version );
			if ( version_compare( $version, '1.5.0', '>=' ) ) {
				foreach ( array( 'ai_jobs', 'ai_attempts', 'ai_reviews' ) as $entity ) { $definitions[ $entity ] = 'DDL:' . $version . ':' . $entity; }
			}
			if ( version_compare( $version, '1.6.0', '>=' ) ) {
				foreach ( array( 'email_verifications', 'user_notifications', 'notification_outbox' ) as $entity ) { $definitions[ $entity ] = 'DDL:' . $version . ':' . $entity; }
			}
			if ( version_compare( $version, '1.7.0', '>=' ) ) {
				foreach ( array( 'favorites', 'reviews', 'review_revisions', 'review_reports', 'skill_reports', 'workflow_events', 'metric_events', 'daily_metrics' ) as $entity ) { $definitions[ $entity ] = 'DDL:' . $version . ':' . $entity; }
			}
			if ( version_compare( $version, '1.8.0', '>=' ) ) { $definitions['author_claims'] = 'DDL:' . $version . ':author_claims'; }
			if ( version_compare( $version, '1.9.0', '>=' ) ) { foreach ( array( 'editorial_collections', 'collection_translations', 'collection_items', 'use_cases', 'use_case_translations', 'use_case_skills', 'term_translations' ) as $entity ) { $definitions[ $entity ] = 'DDL:' . $version . ':' . $entity; } }
			return $definitions;
		}
		public static function verify( Database $database, string $version ): void {
			$database->execute( 'VERIFY:' . $version );
			if ( self::$nested_transaction ) {
				$database->transaction( static fn() => $database->execute( 'VERIFY_TRANSACTION' ) );
				\check( ( new \mysqli_driver() )->report_mode === ( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ), 'An inner transaction restores the outer migration guard.' );
			}
			if ( self::$verify_failure ) { throw new \RuntimeException( 'fixture verification private-value' ); }
		}
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'DB_NAME', 'isolated_test_database' );
	define( 'ARRAY_A', 'ARRAY_A' );
	$native_driver = class_exists( 'mysqli_driver' );
	if ( ! $native_driver ) {
		define( 'MYSQLI_REPORT_OFF', 0 ); define( 'MYSQLI_REPORT_ERROR', 1 ); define( 'MYSQLI_REPORT_STRICT', 2 );
		$GLOBALS['test_mysqli_mode'] = MYSQLI_REPORT_OFF;
		class mysqli_driver { public int $report_mode; public function __construct() { $this->report_mode = $GLOBALS['test_mysqli_mode']; } }
		class mysqli_sql_exception extends \RuntimeException {}
		function mysqli_report( int $mode ): bool { $GLOBALS['test_mysqli_mode'] = $mode; return true; }
	}

	final class WP_Error {
		public function __construct( public string $code, public string $message ) {}
	}
	/** wpdb reconnect/replay model: a dropped session loses GET_LOCK, while DDL can remain applied. */
	final class wpdb {
		public string $prefix = 'test_';
		public string $last_error = '';
		public array $queries = array();
		public array $modes = array();
		public array $ddl = array();
		public array $created = array();
		public array $tables = array( 'test_aish_skills' => '1.0.0' );
		public int $lower_case_table_names = 0;
		public bool $dbdelta = false;
		private array $prepared = array();
		public bool $connected = true;
		public bool $locked = false;
		public bool $lock_busy = false;
		public bool $suppressed = false;
		public bool $release_failure = false;
		public bool $write_failure = false;
		public ?string $disconnect_at = null;
		public int $replays = 0;
		public int $unlocked_ddl = 0;
		public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
		public function prepare( string $sql, array $values ): string { $this->prepared[] = array( $sql, $values ); return 'prepared:' . ( count( $this->prepared ) - 1 ); }
		private function driverError( int $code = 2006 ): int|false {
			$this->last_error = 'fixture SQL contains private-value';
			if ( ( new mysqli_driver() )->report_mode & MYSQLI_REPORT_STRICT ) { throw new mysqli_sql_exception( $this->last_error, $code ); }
			return false;
		}
		public function definitionTable( string $definition ): string { $parts = explode( ':', $definition ); return $this->prefix . 'aish_' . ( $parts[2] ?? 'skills' ); }
		public function existingTable( string $table ): ?string {
			foreach ( array_keys( $this->tables ) as $name ) {
				if ( 0 === $this->lower_case_table_names ? $name === $table : strtolower( $name ) === strtolower( $table ) ) { return $name; }
			}
			return null;
		}
		private function applyDdl( string $sql ): void {
			$table = $this->definitionTable( $sql );
			$existing = $this->existingTable( $table );
			if ( null !== $existing ) { $table = $existing; }
			elseif ( 1 === $this->lower_case_table_names ) { $table = strtolower( $table ); }
			if ( null === $existing ) { $this->created[] = $table; }
			$this->tables[ $table ] = explode( ':', $sql )[1]; $this->ddl[] = $sql;
		}
		public function get_var( string $sql ): mixed { return $this->query( $sql ); }
		public function query( string $sql ): int|false|null {
			$values = array();
			if ( str_starts_with( $sql, 'prepared:' ) ) { [ $sql, $values ] = $this->prepared[ (int) substr( $sql, 9 ) ]; }
			$this->last_error = ''; $this->queries[] = $sql; $this->modes[] = ( new mysqli_driver() )->report_mode;
			if ( $sql === $this->disconnect_at ) {
				$this->disconnect_at = null;
				// DDL may already be committed when its acknowledgement is lost.
				if ( str_starts_with( $sql, 'DDL:' ) ) { $this->applyDdl( $sql ); }
				$this->connected = false; $this->locked = false;
			}
			if ( ! $this->connected ) {
				$this->driverError(); // Strict mode stops before the wpdb reconnection branch.
				$this->connected = true; ++$this->replays; $this->last_error = '';
			}
			if ( str_contains( $sql, 'information_schema.TABLES' ) ) {
				$exists = str_contains( $sql, '@@lower_case_table_names' ) ? null !== $this->existingTable( $values[0] ) : isset( $this->tables[ $values[0] ] );
				return $exists ? 1 : null;
			}
			if ( str_starts_with( $sql, 'DESCRIBE:' ) ) { return null !== $this->existingTable( substr( $sql, 9 ) ) ? 1 : $this->driverError( 1146 ); }
			if ( str_starts_with( $sql, 'SELECT GET_LOCK' ) ) { if ( $this->lock_busy ) { return 0; } $this->locked = true; }
			elseif ( str_starts_with( $sql, 'SELECT RELEASE_LOCK' ) ) {
				if ( $this->release_failure ) { return $this->driverError(); }
				$this->locked = false;
			} elseif ( 'WRITE_VERSION' === $sql && $this->write_failure ) { return $this->driverError(); }
			elseif ( str_starts_with( $sql, 'DDL:' ) ) {
				if ( ! $this->locked ) { ++$this->unlocked_ddl; }
				if ( ! $this->dbdelta && null !== $this->existingTable( $this->definitionTable( $sql ) ) ) { return $this->driverError( 1050 ); }
				$this->applyDdl( $sql );
			}
			return 1;
		}
	}
	$GLOBALS['options'] = array();
	$GLOBALS['invalidated_options'] = array();
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( string $key, mixed $value, bool $autoload = false ): bool {
		if ( false === $GLOBALS['wpdb']->query( 'WRITE_VERSION' ) ) { return false; }
		$GLOBALS['options'][ $key ] = $value; return true;
	}
	function wp_cache_delete( string $key, string $group ): void { $GLOBALS['invalidated_options'][] = $group . ':' . $key; }
	function dbDelta( string $definition ): void {
		$connection = $GLOBALS['wpdb']; $table = $connection->definitionTable( $definition );
		// WordPress dbDelta probes missing tables with suppressed DESCRIBE before CREATE.
		$previous = $connection->suppress_errors( true );
		$connection->query( 'DESCRIBE:' . $table );
		$connection->suppress_errors( $previous );
		$existing = $connection->existingTable( $table );
		if ( ( $connection->tables[ $existing ?? '' ] ?? null ) !== explode( ':', $definition )[1] ) {
			$connection->dbdelta = true;
			try { $connection->query( $definition ); } finally { $connection->dbdelta = false; }
		}
	}

	// Migrator requires this path; the empty fixture contains no WordPress implementation.
	$fixture = sys_get_temp_dir() . '/aish-migration-' . bin2hex( random_bytes( 10 ) );
	if ( ! mkdir( $fixture . '/wp-admin/includes', 0700, true ) || false === file_put_contents( $fixture . '/wp-admin/includes/upgrade.php', "<?php\n" ) ) {
		throw new RuntimeException( 'Cannot create isolated upgrade fixture.' );
	}
	define( 'ABSPATH', $fixture . '/' );
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Database/Database.php';
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Database/Migrator.php';
	use AISkillsHub\Core\Database\Database;
	use AISkillsHub\Core\Database\Migrator;
	use AISkillsHub\Core\Database\Schema;
	$count = 0;
	function check( bool $value, string $message ): void { global $count; if ( ! $value ) { throw new RuntimeException( $message ); } ++$count; }
	function setup( string $version = '1.0.0' ): array {
		$connection = new wpdb(); $GLOBALS['wpdb'] = $connection;
		$connection->tables = '0' === $version ? array() : array( 'test_aish_skills' => $version );
		$GLOBALS['options'] = array( 'aish_schema_version' => $version ); $GLOBALS['invalidated_options'] = array();
		Schema::$verify_failure = false; Schema::$nested_transaction = false;
		$database = new Database( $connection );
		return array( $connection, $database, new Migrator( $database ) );
	}
	function restored( wpdb $connection, int $mode ): void {
		check( ( new mysqli_driver() )->report_mode === $mode, 'The previous driver reporting mode is restored.' );
		check( ! $connection->suppressed, 'The previous wpdb error suppression is restored.' );
	}
	function failed( mixed $result, string $code = 'aish_schema_failed' ): void {
		check( $result instanceof WP_Error && $result->code === $code, 'The migration returns its expected structured failure.' );
		check( ! str_contains( $result->message, 'private-value' ), 'Driver/verification details are absent from the public failure.' );
	}
	$original_mode = ( new mysqli_driver() )->report_mode;
	try {
		mysqli_report( MYSQLI_REPORT_OFF );
		$control = new wpdb(); $control->disconnect_at = 'DDL:1.1.0'; $control->dbdelta = true;
		$control->query( 'SELECT GET_LOCK(%s, 0)' ); $control->query( 'DDL:1.1.0' );
		check( 1 === $control->replays && 1 === $control->unlocked_ddl && ! $control->locked, 'The control reproduces replayed DDL after loss of the migration lock.' );
		[ $connection, $database, $migrator ] = setup( '0' );
		mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
		try { dbDelta( 'DDL:1.0.0' ); throw new RuntimeException( 'Expected dbDelta missing-table probe to throw.' ); }
		catch ( mysqli_sql_exception $error ) { check( 1146 === $error->getCode(), 'Strict mysqli exposes dbDelta\'s expected missing-table DESCRIBE despite wpdb error suppression.' ); }
		check( array() === $connection->tables, 'The unguarded dbDelta probe aborts before creating the first table.' );

		foreach ( array( MYSQLI_REPORT_OFF, MYSQLI_REPORT_ERROR, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ) as $mode ) {
			mysqli_report( $mode );
			[ $connection, $database, $migrator ] = setup();
			check( true === $migrator->migrate( '1.1.0' ) && '1.1.0' === $migrator->version(), 'A successful migration verifies and advances the version.' );
			check( array( 'SELECT GET_LOCK(%s, 0)', 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND IF(@@lower_case_table_names=0, BINARY TABLE_NAME=BINARY %s, LOWER(TABLE_NAME)=LOWER(%s)) LIMIT 1', 'DESCRIBE:test_aish_skills', 'DDL:1.1.0', 'VERIFY:1.1.0', 'WRITE_VERSION', 'SELECT RELEASE_LOCK(%s)' ) === $connection->queries, 'The lock covers existence probe, dbDelta, verification, version write, and release in order.' );
			check( ! $connection->locked && 0 === $connection->replays && 0 === $connection->unlocked_ddl, 'Normal migration does not reconnect or write unlocked.' );
			check( array_unique( $connection->modes ) === array( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ), 'Every migration statement uses strict driver reporting.' );
			check( array( 'options:aish_schema_version', 'options:alloptions', 'options:notoptions' ) === $GLOBALS['invalidated_options'], 'Cached schema options are invalidated after obtaining the lock.' );
			restored( $connection, $mode );
			$ddl = $connection->ddl;
			check( true === $migrator->migrate( '1.1.0' ) && $ddl === $connection->ddl, 'Repeated installation does not apply DDL again at the same version.' ); restored( $connection, $mode );

			[ $connection, $database, $migrator ] = setup(); $connection->disconnect_at = 'DDL:1.1.0';
			failed( $migrator->migrate( '1.1.0' ) );
			check( '1.0.0' === $migrator->version(), 'A lost DDL reply cannot advance the unverified schema version.' );
			check( array( 'DDL:1.1.0' ) === $connection->ddl, 'Already applied additive DDL is retained, not described as rolled back.' );
			check( 0 === $connection->replays && 0 === $connection->unlocked_ddl, 'Neither DDL nor lock release reconnects after session loss.' );
			check( ! in_array( 'WRITE_VERSION', $connection->queries, true ), 'An interrupted migration never reaches the version write.' ); restored( $connection, $mode );
			// A subsequent request can safely rerun additive DDL using a new session.
			$partial = $connection->ddl; $partialTables = $connection->tables;
			[ $connection, $database, $migrator ] = setup(); $connection->ddl = $partial; $connection->tables = $partialTables;
			check( true === $migrator->migrate( '1.1.0' ) && '1.1.0' === $migrator->version(), 'A fresh session can finish an interrupted additive migration.' );
			check( 1 === count( $connection->ddl ) && ! $connection->locked, 'Retry obtains its own lock and dbDelta leaves already-applied additive DDL intact.' ); restored( $connection, $mode );
		}

		mysqli_report( MYSQLI_REPORT_OFF );
		[ $connection, $database, $migrator ] = setup();
		failed( $migrator->migrate( 'unknown' ), 'aish_schema_target' );
		check( array() === $connection->queries, 'An unsupported target never touches the database.' ); restored( $connection, MYSQLI_REPORT_OFF );
		foreach ( array( '2.0.0', '1.5.0' ) as $version ) {
			[ $connection, $database, $migrator ] = setup( $version );
			failed( $migrator->migrate( '1.1.0' ), 'aish_schema_version' );
			check( array() === $connection->ddl && $version === $migrator->version() && ! $connection->locked, 'Unknown schema and downgrade attempts retain data/version and release the lock.' ); restored( $connection, MYSQLI_REPORT_OFF );
		}
		[ $connection, $database, $migrator ] = setup(); $connection->lock_busy = true;
		failed( $migrator->migrate( '1.1.0' ), 'aish_schema_busy' );
		check( array( 'SELECT GET_LOCK(%s, 0)' ) === $connection->queries, 'A busy lock performs no DDL and does not release another migration lock.' ); restored( $connection, MYSQLI_REPORT_OFF );

		[ $connection, $database, $migrator ] = setup(); Schema::$verify_failure = true; $connection->release_failure = true;
		failed( $migrator->migrate( '1.1.0' ) );
		check( '1.0.0' === $migrator->version() && ! in_array( 'WRITE_VERSION', $connection->queries, true ), 'Failed verification retains the version even if lock release also fails.' ); restored( $connection, MYSQLI_REPORT_OFF );
		[ $connection, $database, $migrator ] = setup(); $connection->write_failure = true;
		failed( $migrator->migrate( '1.1.0' ) );
		check( '1.0.0' === $migrator->version() && ! $connection->locked, 'A failed version write returns failure and releases its lock.' ); restored( $connection, MYSQLI_REPORT_OFF );
		[ $connection, $database, $migrator ] = setup(); $connection->release_failure = true;
		check( true === $migrator->migrate( '1.1.0' ) && '1.1.0' === $migrator->version(), 'A release error after verified version persistence does not pretend DDL was undone.' ); restored( $connection, MYSQLI_REPORT_OFF );
		[ $connection, $database, $migrator ] = setup(); $connection->disconnect_at = 'SELECT GET_LOCK(%s, 0)';
		failed( $migrator->migrate( '1.1.0' ) );
		check( 0 === $connection->replays && 1 === count( $connection->queries ) && array() === $connection->ddl, 'A failed lock acquisition cannot reconnect or continue migration.' ); restored( $connection, MYSQLI_REPORT_OFF );
		[ $connection, $database, $migrator ] = setup(); $GLOBALS['wpdb'] = new wpdb();
		failed( $migrator->migrate( '1.1.0' ) );
		check( array() === $connection->ddl && ! $connection->locked, 'A different global WordPress connection cannot perform the DDL.' ); restored( $connection, MYSQLI_REPORT_OFF );

		[ $connection, $database, $migrator ] = setup(); Schema::$nested_transaction = true;
		check( true === $migrator->migrate( '1.1.0' ), 'A nested transaction guard does not end the migration scope.' );
		check( array_unique( $connection->modes ) === array( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ), 'The version update and lock release retain strict mode after the transaction returns.' ); restored( $connection, MYSQLI_REPORT_OFF );
		[ $connection, $database, $migrator ] = setup( '0' );
		check( true === $migrator->migrate() && $migrator->isCurrent(), 'All existing version steps can complete in their original order.' );
		check( array( 'DDL:1.0.0', 'DDL:1.1.0', 'DDL:1.2.0', 'DDL:1.3.0', 'DDL:1.4.0', 'DDL:1.5.0', 'DDL:1.5.0:ai_jobs', 'DDL:1.5.0:ai_attempts', 'DDL:1.5.0:ai_reviews' ) === $connection->ddl, 'The migration guard does not reorder or skip defined schema versions.' );
		check( array( 'test_aish_skills', 'test_aish_ai_jobs', 'test_aish_ai_attempts', 'test_aish_ai_reviews' ) === $connection->created, 'First installation creates only absent trusted definitions before the dbDelta probes.' );
		check( 1 === count( array_filter( $connection->ddl, static fn( $sql ) => 'DDL:1.0.0' === $sql ) ), 'dbDelta does not duplicate CREATE after the missing-table preflight.' ); restored( $connection, MYSQLI_REPORT_OFF );

		[ $connection, $database, $migrator ] = setup( '1.4.0' );
		check( true === $migrator->migrate(), 'The existing 1.4 schema can add the three AI operational tables under strict mode.' );
		check( array( 'test_aish_ai_jobs', 'test_aish_ai_attempts', 'test_aish_ai_reviews' ) === $connection->created, 'Upgrade creates only the three new tables, without rebuilding the existing content table.' );
		$created = $connection->created;
		check( true === $migrator->migrate() && $created === $connection->created, 'Repeated current-version installation creates no additional tables.' ); restored( $connection, MYSQLI_REPORT_OFF );

		[ $connection, $database, $migrator ] = setup( '1.4.0' ); $connection->disconnect_at = 'DDL:1.5.0:ai_attempts';
		failed( $migrator->migrate() );
		check( '1.4.0' === $migrator->version() && 0 === $connection->replays, 'Interrupted CREATE never advances the schema or reconnects to replay DDL.' );
		check( isset( $connection->tables['test_aish_ai_jobs'], $connection->tables['test_aish_ai_attempts'] ) && ! isset( $connection->tables['test_aish_ai_reviews'] ), 'Partial CREATE state remains available for a later additive retry.' );
		$partialTables = $connection->tables; $partialDdl = $connection->ddl;
		restored( $connection, MYSQLI_REPORT_OFF );
		[ $connection, $database, $migrator ] = setup( '1.4.0' ); $connection->tables = $partialTables; $connection->ddl = $partialDdl;
		check( true === $migrator->migrate() && $migrator->isCurrent(), 'A new connection can finish the interrupted three-table upgrade.' );
		check( array( 'test_aish_ai_reviews' ) === $connection->created, 'Recovery creates only the still-missing table and preserves previously applied CREATE results.' );
		check( count( array_unique( $connection->ddl ) ) === count( $connection->ddl ), 'Recovery does not replay already-applied additive definitions.' ); restored( $connection, MYSQLI_REPORT_OFF );

		[ $connection, $database, $migrator ] = setup( '1.5.0' );
		foreach ( array( 'ai_jobs', 'ai_attempts', 'ai_reviews' ) as $entity ) { $connection->tables[ 'test_aish_' . $entity ] = '1.5.0'; }
		check( true === $migrator->migrate( '1.6.0' ) && '1.6.0' === $migrator->version(), 'Phase 8 adds only its three account tables after historical migrations.' );
		check( array( 'test_aish_email_verifications', 'test_aish_user_notifications', 'test_aish_notification_outbox' ) === $connection->created, 'The current upgrade does not recreate existing tables.' );
		$created = $connection->created;
		check( true === $migrator->migrate( '1.6.0' ) && $created === $connection->created, 'Phase 8 repeated installation is idempotent.' );

		[ $connection, $database, $migrator ] = setup( '1.5.0' );
		foreach ( array( 'ai_jobs', 'ai_attempts', 'ai_reviews' ) as $entity ) { $connection->tables[ 'test_aish_' . $entity ] = '1.5.0'; }
		$connection->disconnect_at = 'DDL:1.6.0:user_notifications';
		failed( $migrator->migrate( '1.6.0' ) );
		check( '1.5.0' === $migrator->version() && 0 === $connection->replays, 'Interrupted account DDL retains the old verified version without replay.' );
		check( isset( $connection->tables['test_aish_email_verifications'], $connection->tables['test_aish_user_notifications'] ) && ! isset( $connection->tables['test_aish_notification_outbox'] ), 'Partial account CREATE state remains additive and retryable.' );
		$partialTables = $connection->tables;
		[ $connection, $database, $migrator ] = setup( '1.5.0' ); $connection->tables = $partialTables;
		check( true === $migrator->migrate( '1.6.0' ) && array( 'test_aish_notification_outbox' ) === $connection->created, 'A new request completes only the still-missing account table.' );
		restored( $connection, MYSQLI_REPORT_OFF );


		[ $connection, $database, $migrator ] = setup( '1.6.0' );
		foreach ( array( 'ai_jobs', 'ai_attempts', 'ai_reviews', 'email_verifications', 'user_notifications', 'notification_outbox' ) as $entity ) { $connection->tables[ 'test_aish_' . $entity ] = '1.6.0'; }
		$connection->disconnect_at = 'DDL:1.7.0:review_reports';
		failed( $migrator->migrate( '1.7.0' ) );
		check( '1.6.0' === $migrator->version() && 0 === $connection->replays, 'Interrupted community DDL does not advance or replay the schema.' );
		check( isset( $connection->tables['test_aish_favorites'], $connection->tables['test_aish_reviews'], $connection->tables['test_aish_review_revisions'], $connection->tables['test_aish_review_reports'] ) && ! isset( $connection->tables['test_aish_skill_reports'] ), 'Partial community tables remain available without claiming complete installation.' );
		$partialTables = $connection->tables;
		[ $connection, $database, $migrator ] = setup( '1.6.0' ); $connection->tables = $partialTables;
		check( true === $migrator->migrate( '1.7.0' ) && '1.7.0' === $migrator->version(), 'A new request verifies and completes community installation.' );
		check( array( 'test_aish_skill_reports', 'test_aish_workflow_events', 'test_aish_metric_events', 'test_aish_daily_metrics' ) === $connection->created, 'Recovery creates only missing community tables, preserving existing definitions.' );
		$created = $connection->created; $ddl = $connection->ddl;
		check( true === $migrator->migrate( '1.7.0' ) && $created === $connection->created && $ddl === $connection->ddl, 'Repeated community migration performs no additional DDL.' );
		failed( $migrator->migrate( '1.6.0' ), 'aish_schema_version' );
		check( '1.7.0' === $migrator->version() && $ddl === $connection->ddl, 'A requested community downgrade preserves all tables and version.' );
		restored( $connection, MYSQLI_REPORT_OFF );

		[ $connection, $database, $migrator ] = setup( '1.7.0' );
		foreach ( Schema::definitions( $database, '1.7.0' ) as $entity => $definition ) { $connection->tables[ 'test_aish_' . $entity ] = '1.7.0'; }
		$connection->disconnect_at = 'DDL:1.8.0:author_claims';
		failed( $migrator->migrate( '1.8.0' ) );
		check( '1.7.0' === $migrator->version() && 0 === $connection->replays, 'An interrupted claim CREATE preserves the verified version and cannot reconnect under a lost lock.' );
		check( array( 'test_aish_author_claims' ) === $connection->created, 'Phase 10 only creates its one absent table.' );
		$partialTables = $connection->tables;
		[ $connection, $database, $migrator ] = setup( '1.7.0' ); $connection->tables = $partialTables;
		Schema::$verify_failure = true;
		failed( $migrator->migrate( '1.8.0' ) );
		check( '1.7.0' === $migrator->version() && array() === $connection->created, 'Failed verification retains the old version and existing claim table for recovery.' );
		Schema::$verify_failure = false;
		check( true === $migrator->migrate( '1.8.0' ) && '1.8.0' === $migrator->version(), 'A fresh verified retry completes the claim migration.' );
		$ddl = $connection->ddl;
		check( true === $migrator->migrate( '1.8.0' ) && $ddl === $connection->ddl, 'Repeated Phase 10 installation applies no DDL.' );
		failed( $migrator->migrate( '1.7.0' ), 'aish_schema_version' );
		check( '1.8.0' === $migrator->version() && $ddl === $connection->ddl, 'A Phase 10 downgrade cannot remove claims or previous data.' );
		restored( $connection, MYSQLI_REPORT_OFF );

		[ $connection, $database, $migrator ] = setup( '1.8.0' );
		foreach ( Schema::definitions( $database, '1.8.0' ) as $entity => $definition ) { $connection->tables[ 'test_aish_' . $entity ] = '1.8.0'; }
		$connection->disconnect_at = 'DDL:1.9.0:use_cases';
		failed( $migrator->migrate( '1.9.0' ) );
		check( '1.8.0' === $migrator->version() && 0 === $connection->replays, 'Interrupted discovery DDL retains the verified version without lockless replay.' );
		check( array( 'test_aish_editorial_collections', 'test_aish_collection_translations', 'test_aish_collection_items', 'test_aish_use_cases' ) === $connection->created, 'Only absent discovery tables were created before interruption.' );
		$partialTables = $connection->tables;
		[ $connection, $database, $migrator ] = setup( '1.8.0' ); $connection->tables = $partialTables;
		Schema::$verify_failure = true;
		failed( $migrator->migrate( '1.9.0' ) );
		check( '1.8.0' === $migrator->version() && array( 'test_aish_use_case_translations', 'test_aish_use_case_skills', 'test_aish_term_translations' ) === $connection->created, 'Partial retry only creates remaining tables; verification failure never advances schema.' );
		Schema::$verify_failure = false;
		check( true === $migrator->migrate( '1.9.0' ) && '1.9.0' === $migrator->version(), 'A subsequent verified request completes all discovery definitions.' );
		$ddl = $connection->ddl;
		check( true === $migrator->migrate( '1.9.0' ) && $ddl === $connection->ddl, 'Repeated discovery migration performs no extra DDL.' );
		failed( $migrator->migrate( '1.8.0' ), 'aish_schema_version' );
		check( '1.9.0' === $migrator->version() && $ddl === $connection->ddl, 'Downgrading cannot remove discovery tables or existing data.' );
		restored( $connection, MYSQLI_REPORT_OFF );

		foreach ( array( 0, 1, 2 ) as $caseMode ) {
			[ $connection, $database, $migrator ] = setup(); $connection->prefix = 'TEST_'; $connection->lower_case_table_names = $caseMode;
			check( true === $migrator->migrate( '1.1.0' ), 'An allowed uppercase prefix respects the configured table-name case mode: ' . $caseMode );
			if ( 0 === $caseMode ) {
				check( array( 'TEST_aish_skills' ) === $connection->created && '1.0.0' === $connection->tables['test_aish_skills'], 'Case-sensitive mode creates the distinct uppercase table without touching the lowercase table.' );
			} else {
				check( array() === $connection->created && array( 'test_aish_skills' => '1.1.0' ) === $connection->tables, 'Case-insensitive modes upgrade the existing table without duplicate CREATE: ' . $caseMode );
			}
			restored( $connection, MYSQLI_REPORT_OFF );
		}
	} finally {
		mysqli_report( $original_mode );
		unlink( $fixture . '/wp-admin/includes/upgrade.php' );
		rmdir( $fixture . '/wp-admin/includes' ); rmdir( $fixture . '/wp-admin' ); rmdir( $fixture );
	}
	echo 'Migration recovery: ' . $count . ' isolated assertions passed (' . ( $native_driver ? 'native mysqli mode; modeled migration' : 'driver/migration fixtures' ) . "; no WordPress/MySQL connection).\n";
}
