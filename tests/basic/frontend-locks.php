<?php
declare(strict_types=1);

/** Real frontend lock owners with a wpdb reconnect model; no WordPress or MySQL connection. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DB_NAME', 'fixture' ); define( 'HOUR_IN_SECONDS', 3600 );
if ( ! class_exists( 'mysqli_driver' ) ) {
	define( 'MYSQLI_REPORT_OFF', 0 ); define( 'MYSQLI_REPORT_ERROR', 1 ); define( 'MYSQLI_REPORT_STRICT', 2 );
	$GLOBALS['driver_mode'] = 0;
	class mysqli_driver { public int $report_mode; public function __construct() { $this->report_mode = $GLOBALS['driver_mode']; } }
	class mysqli_sql_exception extends RuntimeException {}
	function mysqli_report( int $mode ): bool { $GLOBALS['driver_mode'] = $mode; return true; }
}
final class RedirectIntercept extends RuntimeException {}
final class wpdb {
	public string $prefix = 'wp_'; public string $options = 'wp_options'; public string $last_error = '';
	public bool $suppressed = false; public bool $connected = true; public bool $locked = false; public bool $busy = false;
	public ?string $disconnectAt = null; public ?string $sqlErrorAt = null;
	public int $replays = 0; public int $unlockedWrites = 0; public int $reads = 0; public int $writes = 0;
	public array $events = array(); public array $modes = array(); public array $data = array();
	public function suppress_errors( bool $value ): bool { $previous = $this->suppressed; $this->suppressed = $value; return $previous; }
	public function prepare( string $sql, mixed ...$values ): string { return json_encode( array( $sql, is_array( $values[0] ?? null ) ? $values[0] : $values ), JSON_THROW_ON_ERROR ); }
	public function esc_like( string $value ): string { return $value; }
	private function error(): false {
		$this->last_error = 'private SQL connection diagnostic';
		if ( ( new mysqli_driver() )->report_mode & MYSQLI_REPORT_STRICT ) { throw new mysqli_sql_exception( $this->last_error, 2006 ); }
		return false;
	}
	public function step( string $event ): bool {
		$this->last_error = ''; $this->events[] = $event; $this->modes[] = ( new mysqli_driver() )->report_mode;
		if ( $event === $this->disconnectAt ) { $this->disconnectAt = null; $this->connected = false; $this->locked = false; }
		if ( ! $this->connected ) {
			$this->error(); // wpdb reaches reconnect/replay only if mysqli did not throw.
			$this->connected = true; ++$this->replays; $this->last_error = '';
		}
		if ( $event === $this->sqlErrorAt ) { return $this->error(); }
		return true;
	}
	public function get_var( string $prepared ): mixed {
		list( $sql, $args ) = json_decode( $prepared, true, 32, JSON_THROW_ON_ERROR );
		if ( str_contains( $sql, 'GET_LOCK' ) ) { if ( ! $this->step( 'lock' ) ) { return false; } return ( $this->locked = ! $this->busy ) ? '1' : '0'; }
		if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { if ( ! $this->step( 'release' ) ) { return false; } $this->locked = false; return '1'; }
		if ( str_contains( $sql, 'SELECT option_value' ) ) { if ( ! $this->step( 'rate-read-' . ++$this->reads ) ) { return null; } return $this->data[ $args[0] ] ?? null; }
		throw new RuntimeException( 'Unexpected fixture query.' );
	}
	public function query( string $prepared ): int|false {
		list( $sql, $args ) = json_decode( $prepared, true, 32, JSON_THROW_ON_ERROR );
		if ( str_starts_with( $sql, 'DELETE ' ) ) { return $this->step( 'cleanup' ) ? 0 : false; }
		if ( str_starts_with( $sql, 'INSERT ' ) ) {
			if ( ! $this->step( 'rate-write-' . ++$this->writes ) ) { return false; }
			if ( ! $this->locked ) { ++$this->unlockedWrites; } $this->data[ $args[0] ] = $args[1]; return 1;
		}
		throw new RuntimeException( 'Unexpected fixture mutation.' );
	}
}
function get_option( string $key, mixed $default = false ): mixed { $GLOBALS['wpdb']->step( 'seo-read' ); return $GLOBALS['wpdb']->data[ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload ): bool {
	$connection = $GLOBALS['wpdb']; $connection->step( 'seo-write' );
	if ( ! $connection->locked ) { ++$connection->unlockedWrites; }
	$changed = ( $connection->data[ $key ] ?? null ) !== $value; $connection->data[ $key ] = $value; return $changed;
}
function current_user_can( string $capability ): bool { return true; }
function check_admin_referer( string $action ): void {}
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_json_encode( mixed $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }
function wp_cache_delete( mixed $key, string $group ): void {}
function wp_salt( string $scheme ): string { return 'fixture-salt'; }
function get_current_user_id(): int { return 1; }
function set_transient( string $key, mixed $value, int $ttl ): void { $GLOBALS['transients'][ $key ] = $value; }
function admin_url( string $path ): string { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( array|string $key, mixed $value, ?string $url = null ): string {
	if ( is_array( $key ) ) { $url = $value; $values = $key; } else { $values = array( $key => $value ); }
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $values );
}
function wp_safe_redirect( string $url, int $status ): void { $GLOBALS['redirect'] = $url; throw new RedirectIntercept(); }
$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Database.php', 'Security/Access.php', 'Security/Input.php', 'Frontend/SeoSettings.php', 'Frontend/Forms.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Frontend\{SeoSettings, Forms};
$checks = 0;
function check( bool $condition, string $message ): void { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; }
function fixture(): wpdb {
	$connection = new wpdb(); $GLOBALS['wpdb'] = $connection; $GLOBALS['transients'] = array(); $GLOBALS['redirect'] = '';
	$connection->data['aish_seo_settings'] = array( 'zh_CN:home:0' => array( 'title' => '保留中文', 'description' => '', 'image' => '', 'noindex' => true ) );
	$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REMOTE_ADDR'] = '192.0.2.12';
	$_POST = array( 'seo_route' => 'home', 'seo_locale' => 'en_US', 'seo_id' => '0', 'seo_title' => 'English title', 'seo_revision' => hash( 'sha256', wp_json_encode( SeoSettings::defaults() ) ) );
	return $connection;
}
function saveSeo( wpdb $connection ): bool {
	try { ( new SeoSettings( new Database( $connection ) ) )->save(); } catch ( RedirectIntercept $expected ) {}
	return str_contains( $GLOBALS['redirect'], 'seo_saved=1' );
}
function rateLimit(): bool { return ( new ReflectionMethod( Forms::class, 'rateLimit' ) )->invoke( new Forms() ); }
function failure( callable $operation ): void { try { $operation(); } catch ( Throwable $expected ) { return; } throw new RuntimeException( 'Expected failure.' ); }
function restored( wpdb $connection, int $mode ): void {
	check( ( new mysqli_driver() )->report_mode === $mode && ! $connection->suppressed, 'Scoped driver/error modes were not restored.' );
	check( 0 === $connection->replays && 0 === $connection->unlockedWrites, 'Protected work reconnected or wrote without its advisory lock.' );
	check( array_unique( $connection->modes ) === array( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ), 'Every lock-scoped statement must use strict driver reporting.' );
}
$originalMode = ( new mysqli_driver() )->report_mode;
try {
	// Control: losing a session between option read and write used to replay the write without its lock.
	mysqli_report( MYSQLI_REPORT_OFF ); $connection = fixture(); $connection->get_var( $connection->prepare( 'SELECT GET_LOCK(%s, 0)', 'fixture' ) );
	$connection->disconnectAt = 'seo-write'; update_option( 'aish_seo_settings', array(), false );
	check( 1 === $connection->replays && 1 === $connection->unlockedWrites, 'Control must reproduce wpdb unlocked whole-option replay.' );
	foreach ( array( MYSQLI_REPORT_OFF, MYSQLI_REPORT_ERROR, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ) as $mode ) {
		mysqli_report( $mode ); $connection = fixture();
		check( saveSeo( $connection ) && isset( $connection->data['aish_seo_settings']['zh_CN:home:0'], $connection->data['aish_seo_settings']['en_US:home:0'] ), 'Normal SEO save must preserve unrelated locale settings.' ); restored( $connection, $mode );
		foreach ( array( 'lock', 'seo-read', 'seo-write', 'release' ) as $event ) {
			$connection = fixture(); $connection->disconnectAt = $event;
			check( ! saveSeo( $connection ) && isset( $GLOBALS['transients']['aish_seo_error_1'] ), 'Disconnected SEO save cannot show success.' );
			if ( 'release' !== $event ) { check( ! isset( $connection->data['aish_seo_settings']['en_US:home:0'] ), 'Failed SEO write changed stored settings.' ); }
			restored( $connection, $mode );
		}
		$connection = fixture(); $connection->busy = true;
		check( ! saveSeo( $connection ) && array( 'lock' ) === $connection->events, 'Busy SEO lock must stop before reads/writes or another owner release.' ); restored( $connection, $mode );
		$connection = fixture(); $_POST['seo_revision'] = str_repeat( '0', 64 );
		check( ! saveSeo( $connection ) && ! in_array( 'seo-write', $connection->events, true ) && 'release' === end( $connection->events ), 'Manual revision conflict must still prevent overwrite and release its lock.' ); restored( $connection, $mode );
		$connection = fixture();
		check( rateLimit() && 2 === $connection->writes && ! $connection->locked, 'Normal rate limit must update both counters and release.' ); restored( $connection, $mode );
		foreach ( array( 'lock', 'cleanup', 'rate-read-1', 'rate-read-2', 'rate-write-1', 'rate-write-2', 'release' ) as $event ) {
			$connection = fixture(); $connection->disconnectAt = $event;
			failure( fn() => rateLimit() ); restored( $connection, $mode );
			if ( 'rate-write-2' === $event ) { check( isset( $connection->data['_aish_public_rate_global'] ) && 2 === count( $connection->data ), 'Earlier conservative quota write is retained; failed second write is not replayed.' ); }
		}
		$connection = fixture(); $connection->busy = true;
		check( ! rateLimit() && array( 'lock' ) === $connection->events, 'Busy limiter must neither write nor release another owner lock.' ); restored( $connection, $mode );
		$connection = fixture(); $connection->data['_aish_public_rate_global'] = time() . ':120';
		check( ! rateLimit() && 0 === $connection->writes && 'release' === end( $connection->events ), 'Existing quota must still reject the request and release.' ); restored( $connection, $mode );
	}
} finally { mysqli_report( $originalMode ); }
echo 'Frontend advisory locks: ' . $checks . " isolated assertions passed (driver/wpdb model; no WordPress/MySQL integration).\n";
