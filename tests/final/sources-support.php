<?php
declare(strict_types=1);
// Test infrastructure only. Requires a disposable, explicitly enabled Final QA WordPress site.
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_FINAL_QA' ) ) { exit( 2 ); }
if ( ! defined( 'DOING_CRON' ) ) { define( 'DOING_CRON', true ); }
require_once ( getenv( 'AISH_QA_WP_ROOT' ) ?: '/var/www/html' ) . '/wp-load.php';

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Sources\GitHubClient;
use AISkillsHub\Core\Sources\ImportStore;

if ( ! str_starts_with( DB_NAME, 'qa_' ) ) { throw new RuntimeException( 'A disposable qa_ database is required.' ); }
$qaAdmins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $qaAdmins ) { throw new RuntimeException( 'QA administrator is required.' ); }
wp_set_current_user( (int) $qaAdmins[0] );
$core = Core::boot();
if ( ! $core->migrator()->isCurrent() ) { throw new RuntimeException( 'QA schema must be initialized first.' ); }
// These suites intentionally test real persistence with simulated HTTP. Fail closed
// if a fixture misses a URL instead of silently contacting an external service.
add_filter( 'pre_http_request', static fn( $preempt ) => false === $preempt ? new WP_Error( 'qa_unexpected_http', 'QA network fixture missing.' ) : $preempt, PHP_INT_MAX );

final class SourcesQA {
	public array $cases = array(); public int $assertions = 0; public array $repositories = array(); private array $apis = array();
	public readonly string $run;
	public function __construct() { $this->run = bin2hex( random_bytes( 5 ) ); }
	public function check( bool $condition, string $label ): void {
		++$this->assertions;
		if ( ! $condition ) { throw new RuntimeException( 'Assertion: ' . $label ); }
	}
	public function rejects( callable $call, string $class, string $label ): void {
		try { $call(); } catch ( Throwable $error ) { $this->check( $error instanceof $class, $label ); return; }
		$this->check( false, $label );
	}
	public function test( string $name, callable $call ): void {
		$before = $this->assertions; $started = microtime( true ); $status = 'passed'; $error = null;
		try { $call(); } catch ( Throwable $failure ) { $status = 'failed'; $error = str_starts_with( $failure->getMessage(), 'Assertion:' ) ? $failure->getMessage() : get_class( $failure ); }
		finally {
			foreach ( $this->apis as $api ) { $api->close(); } $this->apis = array();
			foreach ( $this->repositories as $repository ) { try { Core::boot()->get( 'imports' )->archiveRepository( $repository ); } catch ( Throwable $ignored ) {} }
			$this->repositories = array(); delete_transient( 'aish_github_rate_' . substr( hash( 'sha256', 'anonymous' ), 0, 24 ) );
		}
		$this->cases[] = array( 'name' => $name, 'status' => $status, 'assertions' => $this->assertions - $before, 'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'error' => $error );
	}
	public function repository( string $name ): array {
		$store = Core::boot()->get( 'imports' ); $owner = 'qa-' . $this->run;
		$id = $store->saveRepository( null, 'https://github.com/' . $owner . '/' . $name, false );
		$this->repositories[] = $id;
		$api = new SourcesMockGitHub( $id, $owner, $name ); $this->apis[] = $api;
		return array( $id, $api );
	}
	public function report( string $suite, string $network = 'mocked WordPress HTTP responses; no external GitHub requests' ): never {
		global $wpdb, $wp_version;
		$result = array( 'suite' => $suite, 'run' => $this->run, 'environment' => array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'schema' => get_option( 'aish_schema_version' ), 'database' => DB_NAME ), 'network' => $network, 'execution_notes' => array( 'Registered WordPress Cron callbacks are invoked directly; browser-triggered wp-cron.php timing is not measured.', 'Job due/lease times are advanced in disposable fixture rows to exercise recovery without long waits.', 'No configured dedicated GitHub credential: live GitHub API/authentication not verified; no gh credential was extracted.', 'Fixture records are retained and their repositories archived; no production data is touched.' ), 'cases' => $this->cases, 'case_count' => count( $this->cases ), 'assertions' => $this->assertions, 'failed' => count( array_filter( $this->cases, fn( $case ) => 'failed' === $case['status'] ) ), 'generated_at' => gmdate( 'c' ) );
		echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		exit( $result['failed'] ? 1 : 0 );
	}
}

function sources_db(): \AISkillsHub\Core\Database\Database { return Core::boot()->get( 'database' ); }
function sources_store(): ImportStore { return Core::boot()->get( 'imports' ); }
function sources_row( string $entity, int $id ): ?array { return sources_db()->row( 'SELECT * FROM ' . sources_db()->table( $entity ) . ' WHERE id=%d', array( $id ) ); }
function sources_rows( string $entity, string $where = '1=1', array $values = array() ): array { return sources_db()->rows( 'SELECT * FROM ' . sources_db()->table( $entity ) . ' WHERE ' . $where . ' ORDER BY id', $values ); }
function sources_raw( string $name, string $body = 'Private upstream content.' ): string { return "---\nname: $name\ndescription: QA source description.\nlicense: MIT\n---\n$body\n"; }
function sources_sha( string $raw ): string { return sha1( 'blob ' . strlen( $raw ) . "\0" . $raw ); }
function sources_due( int $job ): void { sources_db()->execute( 'UPDATE ' . sources_db()->table( 'jobs' ) . ' SET available_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id=%d', array( $job ) ); }
function sources_enqueue( int $repository, string $kind = 'sync' ): int {
	sources_db()->execute( 'UPDATE ' . sources_db()->table( 'jobs' ) . ' SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE repository_id=%d', array( $repository ) );
	return sources_store()->enqueue( $repository, $kind );
}
function sources_step( int $job, bool $cron = false ): array {
	sources_due( $job );
	if ( $cron ) { do_action( 'aish_import_tick' ); } else { Core::boot()->get( 'import_worker' )->run(); }
	return sources_row( 'jobs', $job );
}
function sources_finish( int $job, int $limit = 50, bool $cron = false ): array {
	for ( $i = 0; $i < $limit; ++$i ) {
		$row = sources_row( 'jobs', $job );
		if ( in_array( $row['status'], array( 'complete', 'failed', 'incomplete', 'cancelled' ), true ) ) { return $row; }
		sources_step( $job, $cron );
	}
	throw new RuntimeException( 'Assertion: bounded task steps exhausted.' );
}
function sources_until( int $job, string $stage ): array {
	for ( $i = 0; $i < 40; ++$i ) {
		$row = sources_row( 'jobs', $job ); $state = json_decode( $row['state_json'], true );
		if ( $stage === ( $state['stage'] ?? '' ) ) { return $row; }
		if ( in_array( $row['status'], array( 'complete', 'failed', 'incomplete', 'cancelled' ), true ) ) { break; }
		sources_step( $job );
	}
	throw new RuntimeException( 'Assertion: expected stage was not reached.' );
}
function sources_json( array $row ): array { return json_decode( $row['state_json'], true, 64, JSON_THROW_ON_ERROR ); }

/** Network fixtures enter through WordPress's HTTP API, while Client/Worker/Store remain real. */
final class SourcesMockGitHub {
	public array $files = array(); public array $extra = array(); public array $calls = array(); public array $responses = array();
	public string $head; public string $license = 'MIT'; public bool $truncated = false; public bool $nonrecursiveTruncated = false;
	public bool $private = false; public int $identity; public ?Closure $beforeResponse = null;
	private Closure $filter;
	public function __construct( public int $repository, public string $owner, public string $name ) {
		$this->head = sha1( 'initial-' . $repository ); $this->identity = 1000000000 + $repository;
		$this->filter = Closure::fromCallable( array( $this, 'request' ) ); add_filter( 'pre_http_request', $this->filter, 1, 3 );
		delete_transient( 'aish_github_rate_' . substr( hash( 'sha256', 'anonymous' ), 0, 24 ) );
	}
	public function close(): void { remove_filter( 'pre_http_request', $this->filter, 1 ); }
	public function __destruct() { $this->close(); }
	public function root(): string { return sha1( 'tree:' . $this->head . ':' ); }
	public function count( string $kind ): int { return count( array_filter( $this->calls, fn( $call ) => $kind === $call ) ); }
	private function inventory(): array {
		$entries = array();
		foreach ( $this->files as $path => $raw ) {
			$segments = explode( '/', $path ); array_pop( $segments ); $directory = '';
			foreach ( $segments as $segment ) { $directory = '' === $directory ? $segment : $directory . '/' . $segment; $entries[ $directory ] = array( 'path' => $directory, 'type' => 'tree', 'mode' => '040000', 'sha' => sha1( 'tree:' . $this->head . ':' . $directory ) ); }
			$entries[ $path ] = array( 'path' => $path, 'type' => 'blob', 'mode' => '100644', 'sha' => sources_sha( $raw ), 'size' => strlen( $raw ) );
		}
		foreach ( $this->extra as $entry ) { $entries[ $entry['path'] ] = $entry; }
		ksort( $entries ); return array_values( $entries );
	}
	public static function response( int $status, mixed $body, array $headers = array() ): array {
		return array( 'headers' => $headers + array( 'x-ratelimit-remaining' => '1000', 'x-ratelimit-reset' => (string) ( time() + 3600 ) ), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => 'QA fixture' ), 'cookies' => array(), 'filename' => null );
	}
	public function request( mixed $preempt, array $args, string $url ): mixed {
		$base = 'https://api.github.com/repos/' . $this->owner . '/' . $this->name;
		if ( ! str_starts_with( $url, $base ) ) { return $preempt; }
		$tail = substr( $url, strlen( $base ) ); $kind = '' === $tail ? 'repository' : ( str_contains( $tail, '/trees/' ) ? 'tree' : ( str_contains( $tail, '/blobs/' ) ? 'blob' : 'commit' ) );
		$this->calls[] = $kind;
		if ( $this->beforeResponse ) { ( $this->beforeResponse )( $kind, $this ); }
		if ( $this->responses ) { return array_shift( $this->responses ); }
		if ( '' === $tail ) { return self::response( 200, array( 'id' => $this->identity, 'full_name' => $this->owner . '/' . $this->name, 'private' => $this->private, 'visibility' => $this->private ? 'private' : 'public', 'archived' => false, 'disabled' => false, 'default_branch' => 'main', 'license' => array( 'spdx_id' => $this->license ) ) ); }
		if ( '/branches/main' === $tail ) { return self::response( 200, array( 'commit' => array( 'sha' => $this->head, 'commit' => array( 'tree' => array( 'sha' => $this->root() ) ) ) ) ); }
		if ( str_starts_with( $tail, '/git/blobs/' ) ) {
			$sha = substr( $tail, strlen( '/git/blobs/' ) );
			foreach ( $this->files as $raw ) { if ( sources_sha( $raw ) === $sha ) { return self::response( 200, array( 'sha' => $sha, 'encoding' => 'base64', 'size' => strlen( $raw ), 'content' => base64_encode( $raw ) ) ); } }
		}
		if ( str_starts_with( $tail, '/git/trees/' ) ) {
			$sha = substr( strtok( $tail, '?' ), strlen( '/git/trees/' ) ); $recursive = str_contains( $tail, '?recursive=1' ); $inventory = $this->inventory();
			if ( $recursive ) { return self::response( 200, array( 'sha' => $sha, 'truncated' => $this->truncated, 'tree' => $this->truncated ? array_slice( $inventory, 0, 1 ) : $inventory ) ); }
			$directory = ''; if ( $sha !== $this->root() ) { foreach ( $inventory as $entry ) { if ( 'tree' === $entry['type'] && $entry['sha'] === $sha ) { $directory = $entry['path']; break; } } }
			$children = array(); foreach ( $inventory as $entry ) { if ( ( '.' === dirname( $entry['path'] ) ? '' : dirname( $entry['path'] ) ) === $directory ) { $entry['path'] = basename( $entry['path'] ); $children[] = $entry; } }
			return self::response( 200, array( 'sha' => $sha, 'truncated' => $this->nonrecursiveTruncated, 'tree' => $children ) );
		}
		return self::response( 404, array( 'message' => 'QA object not found' ) );
	}
}
