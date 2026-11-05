<?php
declare(strict_types=1);
// Isolated transaction/interleaving model. This is not a MySQL or WordPress runtime test.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
function get_option( string $name, mixed $default = false ): mixed { return 'aish_schema_version' === $name ? \AISkillsHub\Core\Database\Schema::VERSION : $default; }
function current_user_can( string $capability ): bool { return $GLOBALS['allow_management'] ?? true; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }

/** Model lazy REPEATABLE READ snapshots and commits occurring before a row lock is acquired. */
final class wpdb {
	public string $prefix = 't_';
	public string $last_error = '';
	public int $insert_id = 0;
	public array $tables = array();
	public array $prepared = array();
	public array $queries = array();
	public ?string $concurrentBinding = null;
	public bool $failCancellation = false;
	private ?array $rollback = null;
	private ?array $readView = null;
	public function suppress_errors( bool $suppress ): bool { return false; }
	public function prepare( string $sql, array $args ): string {
		$this->prepared[] = array( $sql, $args );
		return 'prepared:' . ( count( $this->prepared ) - 1 );
	}
	private function unpack( string $statement ): array {
		$this->last_error = '';
		$result = str_starts_with( $statement, 'prepared:' ) ? $this->prepared[ (int) substr( $statement, 9 ) ] : array( $statement, array() );
		$this->queries[] = $result;
		return $result;
	}
	private function rows( string $table, bool $locking ): array {
		if ( ! $locking && null !== $this->rollback ) {
			$this->readView ??= $this->tables;
			return array_values( $this->readView[ $table ] ?? array() );
		}
		return array_values( $this->tables[ $table ] ?? array() );
	}
	public function get_results( string $statement, string $format ): array {
		list( $sql, $args ) = $this->unpack( $statement );
		if ( preg_match( '/^SELECT \* FROM `t_aish_(repositories|jobs)` WHERE id=%d( FOR UPDATE)?$/D', $sql, $match ) ) {
			$locking = str_ends_with( $sql, ' FOR UPDATE' );
			if ( 'repositories' === $match[1] && $locking && null !== $this->concurrentBinding ) {
				// Another transaction held this repository lock, committed a binding, then released it.
				$table = $this->concurrentBinding;
				$this->concurrentBinding = null;
				$binding = array( 'id' => 99, 'repository_id' => $args[0] );
				$this->tables[ $table ][99] = $binding;
				$this->rollback[ $table ][99] = $binding; // Our rollback cannot undo the other commit.
			}
			return array_values( array_filter( $this->rows( $match[1], $locking ), fn( $row ) => $row['id'] === $args[0] ) );
		}
		if ( 'SELECT id FROM `t_aish_jobs` WHERE active_key=%d FOR UPDATE' === $sql ) {
			return array_values( array_filter( $this->rows( 'jobs', true ), fn( $row ) => ( $row['active_key'] ?? null ) === $args[0] ) );
		}
		throw new RuntimeException( 'Unexpected row query in management test.' );
	}
	public function get_var( string $statement ): mixed {
		list( $sql, $args ) = $this->unpack( $statement );
		if ( 'SELECT id FROM `t_aish_repositories` WHERE owner=%s AND name=%s' === $sql ) {
			foreach ( $this->rows( 'repositories', false ) as $row ) {
				if ( $row['owner'] === $args[0] && $row['name'] === $args[1] ) { return $row['id']; }
			}
			return null;
		}
		if ( preg_match( '/^SELECT COUNT\(\*\) FROM `t_aish_(skills|jobs)` WHERE repository_id=%d$/D', $sql, $match ) ) {
			return count( array_filter( $this->rows( $match[1], false ), fn( $row ) => $row['repository_id'] === $args[0] ) );
		}
		throw new RuntimeException( 'Unexpected scalar query in management test.' );
	}
	public function query( string $statement ): int|false {
		list( $sql, $args ) = $this->unpack( $statement );
		if ( 'START TRANSACTION' === $sql ) { $this->rollback = $this->tables; $this->readView = null; return 1; }
		if ( 'COMMIT' === $sql ) { $this->rollback = null; $this->readView = null; return 1; }
		if ( 'ROLLBACK' === $sql ) { $this->tables = $this->rollback ?? $this->tables; $this->rollback = null; $this->readView = null; return 1; }
		if ( str_starts_with( $sql, "UPDATE `t_aish_jobs` SET status='cancelled'" ) ) {
			if ( $this->failCancellation ) { $this->failCancellation = false; $this->last_error = 'simulated cancellation failure'; return false; }
			$count = 0;
			foreach ( $this->tables['jobs'] as $id => $row ) {
				if ( ( $row['active_key'] ?? null ) !== $args[2] ) { continue; }
				$this->tables['jobs'][ $id ] = array_merge( $row, array( 'status' => 'cancelled', 'active_key' => null, 'lease_token' => '', 'locked_until' => null, 'completed_at' => $args[0], 'updated_at' => $args[1], 'last_error' => 'repository_archived' ) );
				++$count;
			}
			return $count;
		}
		throw new RuntimeException( 'Unexpected write query in management test.' );
	}
	public function insert( string $table, array $data, array $formats ): int {
		$table = str_replace( 't_aish_', '', $table );
		$this->insert_id = empty( $this->tables[ $table ] ) ? 1 : max( array_keys( $this->tables[ $table ] ) ) + 1;
		$this->tables[ $table ][ $this->insert_id ] = array( 'id' => $this->insert_id ) + $data;
		return 1;
	}
	public function update( string $table, array $data, array $where, array $formats, array $whereFormats ): int {
		$table = str_replace( 't_aish_', '', $table );
		if ( ! isset( $this->tables[ $table ][ $where['id'] ] ) ) { return 0; }
		$this->tables[ $table ][ $where['id'] ] = array_merge( $this->tables[ $table ][ $where['id'] ], $data );
		return 1;
	}
}

$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Security/Input.php', 'Security/Access.php', 'Language/LanguageProvider.php', 'Language/BuiltinLanguageProvider.php', 'Content/Catalog.php', 'Sources/ImportStore.php', 'Admin/Management.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Admin\Management;
use AISkillsHub\Core\Content\Catalog;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Language\BuiltinLanguageProvider;
use AISkillsHub\Core\Sources\ImportStore;

$checks = 0;
function check( bool $condition, string $message ): void { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; }
function rejects( callable $operation, string $class, string $message ): void {
	try { $operation(); } catch ( Throwable $error ) {
		if ( ! $error instanceof $class ) { throw $error; }
		check( true, $message ); return;
	}
	throw new RuntimeException( $message );
}
function fixture(): array {
	$wpdb = new wpdb();
	$wpdb->tables = array(
		'repositories' => array( 1 => array( 'id' => 1, 'owner' => 'owner', 'name' => 'original', 'github_id' => null, 'is_archived' => 0, 'sync_enabled' => 1, 'sync_status' => 'running' ) ),
		'skills' => array(), 'jobs' => array(),
	);
	$db = new Database( $wpdb ); $migrator = new Migrator( $db );
	return array( $wpdb, new Management( $db, $migrator, new Catalog( $db, $migrator, new BuiltinLanguageProvider() ) ), new ImportStore( $db, $migrator ) );
}

foreach ( array( 'skills', 'jobs' ) as $binding ) {
	list( $wpdb, $management ) = fixture();
	$wpdb->concurrentBinding = $binding;
	rejects( fn() => $management->saveRepository( 1, 'owner', 'renamed' ), DomainException::class, 'A binding committed while waiting for the repository lock must prevent identity replacement: ' . $binding );
	check( 'original' === $wpdb->tables['repositories'][1]['name'], 'Rejected rename retains the original repository identity.' );
	check( isset( $wpdb->tables[ $binding ][99] ), 'Rejecting the rename retains the independently committed binding.' );
}
list( $wpdb, $management ) = fixture();
check( 1 === $management->saveRepository( 1, 'OWNER', 'renamed' ), 'An unbound repository can still be explicitly renamed without changing its ID.' );
check( 'renamed' === $wpdb->tables['repositories'][1]['name'], 'The permitted identity update is saved.' );
$wpdb->tables['skills'][1] = array( 'id' => 1, 'repository_id' => 1 );
check( 1 === $management->saveRepository( 1, 'owner', 'renamed' ), 'Saving the same identity remains valid after a Skill is attached.' );
rejects( fn() => $management->saveRepository( 1, 'other', 'renamed' ), DomainException::class, 'An existing Skill binding prevents identity replacement.' );
$wpdb->tables['skills'] = array();
$wpdb->tables['repositories'][1]['github_id'] = 123;
rejects( fn() => $management->saveRepository( 1, 'other', 'renamed' ), DomainException::class, 'An established GitHub numeric identity prevents replacement.' );
$wpdb->tables['repositories'][1]['github_id'] = null;
$created = $management->saveRepository( null, 'other', 'repo' );
check( 2 === $created, 'New repositories still receive their own ID.' );
rejects( fn() => $management->saveRepository( 1, 'other', 'repo' ), DomainException::class, 'Duplicate repository identity remains rejected.' );
rejects( fn() => $management->saveRepository( 42, 'other', 'missing' ), DomainException::class, 'Editing a missing repository remains rejected.' );

foreach ( array( 'queued', 'running', 'retry' ) as $status ) {
	list( $wpdb, $management, $imports ) = fixture();
	$token = str_repeat( 'a', 64 );
	$wpdb->tables['jobs'][1] = array( 'id' => 1, 'repository_id' => 1, 'active_key' => 1, 'status' => $status, 'lease_token' => $token, 'locked_until' => '2099-01-01 00:00:00', 'deadline_at' => '2099-01-02 00:00:00' );
	$wpdb->tables['jobs'][2] = array( 'id' => 2, 'repository_id' => 1, 'active_key' => null, 'status' => 'complete' );
	$wpdb->tables['jobs'][3] = array( 'id' => 3, 'repository_id' => 2, 'active_key' => 2, 'status' => 'queued' );
	foreach ( array( 'skills', 'skill_translations', 'skill_versions', 'imports' ) as $table ) { $wpdb->tables[ $table ][1] = array( 'id' => 1, 'retained' => $table ); }
	$before = $wpdb->tables;
	$management->archiveRepository( 1 );
	check( 1 === $wpdb->tables['repositories'][1]['is_archived'] && 0 === $wpdb->tables['repositories'][1]['sync_enabled'] && 'cancelled' === $wpdb->tables['repositories'][1]['sync_status'], 'The original repository menu disables synchronization and reports cancellation.' );
	$job = $wpdb->tables['jobs'][1];
	check( 'cancelled' === $job['status'] && null === $job['active_key'] && '' === $job['lease_token'] && null === $job['locked_until'], 'Archival cancels and fences the active ' . $status . ' job.' );
	check( 'repository_archived' === $job['last_error'] && isset( $job['completed_at'] ), 'Cancelled work records its reason and completion time.' );
	check( $before['jobs'][2] === $wpdb->tables['jobs'][2] && $before['jobs'][3] === $wpdb->tables['jobs'][3], 'Archival retains terminal history and other repositories\' work.' );
	foreach ( array( 'skills', 'skill_translations', 'skill_versions', 'imports' ) as $table ) { check( $before[ $table ] === $wpdb->tables[ $table ], 'Archival preserves ' . $table . '.' ); }
	$wpdb->tables['repositories'][1]['is_archived'] = 0; // Model an explicit later restore.
	rejects( fn() => $imports->seen( 1, $token, 'sample/SKILL.md' ), DomainException::class, 'Restoring the repository cannot revive a cancelled worker lease.' );
	check( 0 === $wpdb->tables['repositories'][1]['sync_enabled'], 'Restoring only visibility cannot implicitly resume periodic synchronization.' );
}

list( $wpdb, $management ) = fixture();
$wpdb->tables['jobs'][1] = array( 'id' => 1, 'repository_id' => 1, 'active_key' => 1, 'status' => 'running', 'lease_token' => str_repeat( 'b', 64 ) );
$before = $wpdb->tables; $wpdb->failCancellation = true;
rejects( fn() => $management->archiveRepository( 1 ), RuntimeException::class, 'Cancellation write failures are reported instead of partially archiving.' );
check( $before === $wpdb->tables, 'Repository and job updates roll back together on cancellation failure.' );
rejects( fn() => $management->archiveRepository( 99 ), DomainException::class, 'Archiving a missing repository remains rejected.' );
$GLOBALS['allow_management'] = false;
$queryCount = count( $wpdb->queries );
rejects( fn() => $management->archiveRepository( 1 ), DomainException::class, 'Unauthorized archival is rejected.' );
rejects( fn() => $management->saveRepository( 1, 'owner', 'blocked' ), DomainException::class, 'Unauthorized identity changes are rejected.' );
check( $queryCount === count( $wpdb->queries ), 'Authorization rejection occurs before database access.' );
echo "Management integrity contracts: $checks assertions passed (isolated model; no MySQL/WordPress).\n";
