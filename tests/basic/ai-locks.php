<?php
declare(strict_types=1);

// Real Scheduler, Settings, Worker and OpenAIProvider; modeled connection loss,
// options, task persistence and HTTP. No WordPress, MySQL, network or concurrency.
namespace AISkillsHub\Core\Database {
	final class Database {
		public bool $busy = false; public bool $held = false; public bool $lost = false;
		public string $failAt = ''; public int $reconnects = 0; public array $calls = array(); public ?\Closure $onDrop = null;
		public function prefix(): string { return 'fixture_'; }
		public function query( string $point ): void {
			$mode = ( new \mysqli_driver() )->report_mode;
			$this->calls[] = array( $point, $mode );
			if ( $point === $this->failAt ) {
				$this->failAt = ''; $this->lost = true; $this->held = false;
				if ( $this->onDrop ) { ( $this->onDrop )(); }
			}
			if ( ! $this->lost ) { return; }
			// Model wpdb's error-2006 branch: mysqli STRICT throws before reconnect/replay.
			if ( $mode & MYSQLI_REPORT_STRICT ) { throw new \mysqli_sql_exception( 'fixture connection lost', 2006 ); }
			$this->lost = false; $this->held = false; ++$this->reconnects;
		}
		public function value( string $sql, array $args ): int {
			$acquire = str_contains( $sql, 'GET_LOCK' ); $this->query( $acquire ? 'lock' : 'release' );
			if ( $acquire && $this->busy ) { return 0; }
			$this->held = $acquire; return 1;
		}
		public function transaction( callable $work ): mixed {
			$mode = ( new \mysqli_driver() )->report_mode; mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
			try { return $work(); } finally { mysqli_report( $mode ); }
		}
	}
	final class Migrator { public function isCurrent(): bool { return true; } }
}

namespace AISkillsHub\Core\AI {
	final class GenerationStore {
		public string $status = 'queued'; public ?string $attemptStatus = null; public int $claimCalls = 0;
		public array $failures = array(); public array $completed = array();
		public function __construct( private \AISkillsHub\Core\Database\Database $db, private array $profile ) {}
		public function claim(): ?array {
			++$this->claimCalls;
			return $this->db->transaction( function (): ?array {
				$this->db->query( 'claim' );
				if ( 'queued' !== $this->status ) { return null; }
				$this->status = 'running';
				return array( 'id' => 1, 'lease_token' => 'fixture-lease', 'profile' => $this->profile, 'prompt_version' => Prompt::VERSION, 'mode' => 'connection' );
			} );
		}
		public function assertCurrent( int $id, string $lease ): void { $this->db->transaction( fn() => $this->db->query( 'baseline' ) ); }
		public function beginAttempt( int $id, string $lease ): array {
			return $this->db->transaction( function (): array { $this->db->query( 'attempt' ); $this->attemptStatus = 'inflight'; return array( 'id' => 1, 'request_key' => 'fixture-request-id' ); } );
		}
		public function completeAttempt( int $id, string $lease, int $attempt, array $output, array $translations, array $usage ): void {
			$this->db->transaction( function () use ( $translations, $usage ): void { $this->db->query( 'complete' ); $this->completed[] = compact( 'translations', 'usage' ); $this->attemptStatus = 'complete'; $this->status = 'complete'; } );
		}
		public function fail( int $id, string $lease, string $code ): void {
			$this->db->transaction( function () use ( $code ): void { $this->db->query( 'fail' ); $this->failures[] = $code; $this->status = 'failed'; } );
		}
		public function failAttempt( int $id, string $lease, int $attempt, string $code, bool $retryable, int $delay, bool $ambiguous, array $usage ): void {
			$this->failures[] = compact( 'code', 'retryable', 'ambiguous' );
			$this->db->transaction( function () use ( $ambiguous ): void { $this->db->query( 'fail_attempt' ); $this->attemptStatus = $ambiguous ? 'uncertain' : 'failed'; $this->status = 'failed'; } );
		}
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' || ! extension_loaded( 'mysqli' ) ) { throw new \RuntimeException( 'This isolated driver-mode model requires PHP CLI with mysqli; no database connection is opened.' ); }
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DOING_CRON', true ); define( 'AISH_AI_KEY_FIXTURE', 'fixture-key-not-a-real-secret' ); define( 'AISH_AI_ALLOWED_HOSTS', 'api.openai.com' );
	function current_user_can( string $cap ): bool { return true; }
	function get_option( string $name, mixed $default = false ): mixed { $GLOBALS['db']->query( 'get:' . $name ); return $GLOBALS['options'][ $name ] ?? $default; }
	function update_option( string $name, mixed $value, bool $autoload ): bool { $GLOBALS['db']->query( 'update:' . $name ); $GLOBALS['options'][ $name ] = $value; return true; }
	function wp_cache_delete( string $name, string $group ): bool { return true; }
	function apply_filters( string $name, mixed $value, mixed ...$args ): mixed { return $GLOBALS['provider']; }
	function is_wp_error( mixed $value ): bool { return false; }
	function wp_remote_retrieve_response_code( array $response ): int { return 200; }
	function wp_remote_retrieve_header( array $response, string $name ): string { return ''; }
	function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
	$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	require $root . 'Security/Access.php';
	foreach ( array( 'Provider', 'ProviderException', 'ProviderRegistry', 'Settings', 'Prompt', 'Output', 'OpenAIProvider', 'Worker', 'Scheduler' ) as $file ) { require $root . 'AI/' . $file . '.php'; }
	use AISkillsHub\Core\AI\{Settings,GenerationStore,ProviderRegistry,OpenAIProvider,Worker,Scheduler};
	use AISkillsHub\Core\Database\{Database,Migrator};
	$checks = 0;
	function check( bool $pass, string $message ): void { global $checks; ++$checks; if ( ! $pass ) { throw new \RuntimeException( $message ); } }
	function rejects( callable $work ): void { try { $work(); } catch ( \DomainException | \mysqli_sql_exception $error ) { check( true, 'Operation fails instead of reporting success.' ); return; } throw new \RuntimeException( 'Expected failure.' ); }
	function fixture(): array {
		$db = new Database(); $GLOBALS['db'] = $db;
		$profile = array_replace( Settings::defaults( 'fixture' ), array( 'model' => 'fixture-model', 'enabled' => true ) );
		$GLOBALS['options'] = array( 'aish_ai_profiles' => array( 'fixture' => $profile ), 'aish_ai_paused' => '0' ); $GLOBALS['sent'] = 0;
		$GLOBALS['provider'] = new OpenAIProvider( static function () use ( $db ): array {
			$db->query( 'http_before_send' ); ++$GLOBALS['sent']; $db->query( 'http_after_send' );
			return array( 'body' => json_encode( array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => '{"ok":true}' ) ) ), 'usage' => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ) ), JSON_THROW_ON_ERROR ) );
		}, static fn() => array( '1.1.1.1' ) );
		$settings = new Settings( $db ); $store = new GenerationStore( $db, $profile ); $worker = new Worker( $settings, $store, new ProviderRegistry() );
		return array( $db, $settings, $store, new Scheduler( $db, new Migrator(), $settings, $worker ), $profile );
	}

	$originalMode = ( new \mysqli_driver() )->report_mode;
	try {
		foreach ( array( MYSQLI_REPORT_OFF, MYSQLI_REPORT_ERROR, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ) as $initial ) {
			mysqli_report( $initial );
			list( $db, $settings, $store, $scheduler, $profile ) = fixture(); $scheduler->tick();
			check( 1 === $GLOBALS['sent'] && 'complete' === $store->status && array() === $store->completed[0]['translations'], 'Normal locked connection test completes without Skill writes.' );
			foreach ( $db->calls as list( $point, $mode ) ) { if ( in_array( $point, array( 'lock', 'get:aish_ai_profiles', 'http_before_send', 'http_after_send', 'release' ), true ) ) { check( (bool) ( $mode & MYSQLI_REPORT_STRICT ), 'Strict mode spans options, HTTP and lock release, including nested transactions.' ); } }
			check( $initial === ( new \mysqli_driver() )->report_mode && ! $db->held, 'Successful tick releases lock and restores exact prior driver mode.' );
			list( $db, $settings, $store, $scheduler ) = fixture(); $db->busy = true; $scheduler->tick();
			check( 0 === $store->claimCalls && 0 === $GLOBALS['sent'] && $initial === ( new \mysqli_driver() )->report_mode, 'Busy scheduler does no work and restores driver mode.' );
			check( ! in_array( 'release', array_column( $db->calls, 0 ), true ), 'Busy scheduler never releases an unowned lock.' );
			list( $db, $settings, $store, $scheduler ) = fixture(); $db->failAt = 'get:aish_ai_profiles'; $scheduler->tick();
			check( 0 === $db->reconnects && 0 === $GLOBALS['sent'] && null === $store->attemptStatus, 'Profile-read disconnect cannot silently reconnect and send a new request.' );
			check( 'running' === $store->status && ! $store->completed && $initial === ( new \mysqli_driver() )->report_mode, 'Failed failure-persistence retains pre-request lease state for later recovery.' );
			foreach ( array( 'http_before_send' => 0, 'http_after_send' => 1, 'complete' => 1 ) as $point => $sent ) {
				list( $db, $settings, $store, $scheduler ) = fixture(); $db->failAt = $point; $scheduler->tick();
				check( $sent === $GLOBALS['sent'] && 0 === $db->reconnects, 'Detected disconnect never replays a query or another HTTP request: ' . $point );
				check( 'running' === $store->status && 'inflight' === $store->attemptStatus && ! $store->completed, 'Unpersisted outcome preserves durable inflight evidence for lease-expiry recovery: ' . $point );
				check( $initial === ( new \mysqli_driver() )->report_mode && ! $store->failures[0]['retryable'], 'Failure restores prior mode and does not schedule a paid retry: ' . $point );
				if ( 'complete' !== $point ) { check( $store->failures[0]['ambiguous'], 'Transport failure conservatively retains ambiguous delivery.' ); }
			}
			list( $db, $settings, $store, $scheduler, $profile ) = fixture(); $settings->save( 'other', array_replace( $profile, array( 'id' => 'other' ) ) );
			check( isset( $GLOBALS['options']['aish_ai_profiles']['fixture'], $GLOBALS['options']['aish_ai_profiles']['other'] ) && ! $db->held, 'Normal settings write retains existing independent profiles and releases lock.' );
			check( $initial === ( new \mysqli_driver() )->report_mode, 'Settings success restores caller driver mode.' );
			list( $db, $settings, $store, $scheduler, $profile ) = fixture(); $db->busy = true; rejects( fn() => $settings->save( 'other', $profile ) );
			check( 1 === count( $GLOBALS['options']['aish_ai_profiles'] ) && $initial === ( new \mysqli_driver() )->report_mode, 'Busy settings lock leaves configuration unchanged and restores mode.' );
			foreach ( array( 'get:aish_ai_profiles', 'update:aish_ai_profiles' ) as $point ) {
				list( $db, $settings, $store, $scheduler, $profile ) = fixture(); $db->failAt = $point;
				$db->onDrop = static function () use ( $profile ): void { $GLOBALS['options']['aish_ai_profiles']['concurrent'] = array_replace( $profile, array( 'id' => 'concurrent' ) ); };
				rejects( fn() => $settings->save( 'other', $profile ) );
				check( 0 === $db->reconnects && array( 'fixture', 'concurrent' ) === array_keys( $GLOBALS['options']['aish_ai_profiles'] ), 'Lost settings lock never replays stale whole-option state over another writer: ' . $point );
				check( $initial === ( new \mysqli_driver() )->report_mode, 'Failed settings operation and failed release still restore exact driver mode.' );
			}
			list( $db, $settings, $store, $scheduler ) = fixture(); $settings->setPaused( true ); $settings->remove( 'fixture' );
			check( '1' === $GLOBALS['options']['aish_ai_paused'] && array() === $GLOBALS['options']['aish_ai_profiles'] && $initial === ( new \mysqli_driver() )->report_mode, 'Pause and removal share the same scoped settings lock contract.' );
		}
	} finally { mysqli_report( $originalMode ); }
	echo "PASS: $checks isolated AI lock assertions (modeled connection/options/leases/HTTP; no WordPress, MySQL, real API or concurrent integration).\n";
}
