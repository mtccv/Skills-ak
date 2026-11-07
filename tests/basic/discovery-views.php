<?php
declare(strict_types=1);

// Real Views/DAL/PublicCatalog with a transactional wpdb model; no network or real MySQL.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['view_options'] = array( 'home' => 'https://directory.example' );
$GLOBALS['view_caps'] = array(); $GLOBALS['view_meta'] = array(); $GLOBALS['view_test_skill'] = false;
$GLOBALS['view_scheduled'] = array(); $GLOBALS['view_schedule_calls'] = 0; $GLOBALS['view_actions'] = array();
function get_option( string $name, mixed $default = false ): mixed { return 'aish_schema_version' === $name ? \AISkillsHub\Core\Database\Schema::VERSION : ( $GLOBALS['view_options'][ $name ] ?? $default ); }
function current_user_can( string $cap ): bool { return in_array( $cap, $GLOBALS['view_caps'], true ); }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function wp_parse_url( string $url ): array|false { return parse_url( $url ); }
function wp_salt( string $name ): string { return 'isolated-test-salt-no-production-secrets'; }
function is_ssl(): bool { return true; }
function apply_filters( string $name, mixed $value, mixed ...$args ): mixed { return 'aish_discovery_skill_is_test' === $name ? $GLOBALS['view_test_skill'] : $value; }
function is_user_logged_in(): bool { return ! empty( $GLOBALS['view_meta'] ); }
function get_current_user_id(): int { return 11; }
function get_user_meta( int $id, string $key, bool $single = false ): mixed { return $GLOBALS['view_meta'][ $key ] ?? ''; }
function wp_cache_delete( string $key, string $group ): void {}
function nocache_headers(): void {}
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_verify_nonce( string $value, string $action ): bool { return 'test-nonce' === $value && 'aish_discovery_view' === $action; }
function wp_next_scheduled( string $hook ): int|false { return $GLOBALS['view_scheduled'][ $hook ] ?? false; }
function wp_schedule_single_event( int $time, string $hook ): bool { ++$GLOBALS['view_schedule_calls']; $GLOBALS['view_scheduled'][ $hook ] = $time; return true; }
function wp_clear_scheduled_hook( string $hook ): void { unset( $GLOBALS['view_scheduled'][ $hook ] ); }
function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void { $GLOBALS['view_actions'][ $hook ][] = $callback; }
final class JsonReply extends RuntimeException { public function __construct( public array $payload, public int $status, public bool $success ) { parent::__construct( 'isolated JSON transport' ); } }
function wp_send_json_error( array $value, int $status ): never { throw new JsonReply( $value, $status, false ); }
function wp_send_json_success( array $value, int $status ): never { throw new JsonReply( $value, $status, true ); }

final class wpdb {
	public string $prefix = 'views_'; public string $options = 'views_options'; public string $last_error = ''; public int $insert_id = 0;
	public array $data = array( 'options' => array(), 'events' => array(), 'daily' => array() );
	public array $queries = array(); public array $operations = array(); public bool $visible = true; public bool $skillExists = true; public bool $failDaily = false; public bool $gcBusy = false; public bool $failRetention = false; public string $engine = 'InnoDB';
	private array $prepared = array(); private ?array $snapshot = null;
	public function suppress_errors( bool $value ): bool { return false; }
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
	public function prepare( string $sql, array $values ): string { $key = 'prepared:' . count( $this->prepared ); $this->prepared[ $key ] = array( $sql, $values ); return $key; }
	private function unpack( string $query ): array { $this->last_error = ''; $decoded = $this->prepared[ $query ] ?? array( $query, array() ); $this->queries[] = $decoded; return $decoded; }
	public function get_var( string $query ): mixed {
		[ $sql, $args ] = $this->unpack( $query );
		if ( 'SELECT GET_LOCK(%s, 0)' === $sql ) { $this->operations[] = 'gc_lock'; return $this->gcBusy ? 0 : 1; }
		if ( 'SELECT RELEASE_LOCK(%s)' === $sql ) { $this->operations[] = 'gc_release'; return 1; }
		if ( str_starts_with( $sql, 'SELECT ENGINE FROM information_schema.TABLES' ) ) { return $this->engine; }
		if ( str_starts_with( $sql, 'SELECT option_value FROM views_options' ) && str_ends_with( $sql, 'FOR UPDATE' ) ) { $this->operations[] = 'budget_lock'; return $this->data['options'][ $args[0] ] ?? null; }
		throw new RuntimeException( 'Unexpected fixture scalar.' );
	}
	public function get_results( string $query, string $format ): array {
		[ $sql, $args ] = $this->unpack( $query );
		if ( str_starts_with( $sql, 'SELECT option_name FROM views_options' ) ) {
			$rows = array(); foreach ( $this->data['options'] as $key => $value ) { if ( preg_match( '/^_transient_timeout_aish_view_budget_[a-f0-9]{64}$/D', $key ) && (int) $value < $args[1] ) { $rows[] = array( 'option_name' => $key ); } } return array_slice( $rows, 0, 100 );
		}
		if ( 'SELECT id FROM `views_aish_skills` WHERE id=%d FOR UPDATE' === $sql ) { $this->operations[] = 'skill_lock'; return $this->skillExists ? array( array( 'id' => $args[0] ) ) : array(); }
		if ( str_starts_with( $sql, 'SELECT eligible.id FROM (' ) ) {
			$this->operations[] = 'public_gate';
			preg_match( '/\((prepared:[0-9]+)\)/', $sql, $m ); [ $gate, $locale ] = $this->prepared[ $m[1] ];
			check( str_contains( $gate, "s.status='published'" ) && str_contains( $gate, "s.review_status='approved'" ) && str_contains( $gate, 's.reviewed_revision=s.revision' ) && str_contains( $gate, 'tr.locale=%s' ) && str_contains( $gate, 'REGEXP_REPLACE' ), 'actual PublicCatalog gate includes reviewed revision and complete current-language translation' );
			return $this->visible ? array( array( 'id' => $args[0] ) ) : array();
		}
		if ( str_starts_with( $sql, 'SELECT id FROM `views_aish_metric_events`' ) ) {
			$this->operations[] = 'dedupe';
			foreach ( $this->data['events'] as $event ) { if ( $event['event_day'] === $args[0] && $event['dedupe_key'] === $args[1] ) { return array( array( 'id' => $event['id'] ) ); } } return array();
		}
		throw new RuntimeException( 'Unexpected fixture rows.' );
	}
	public function query( string $query ): int|false {
		[ $sql, $args ] = $this->unpack( $query );
		if ( 'START TRANSACTION' === $sql ) { $this->operations[] = 'begin'; $this->snapshot = $this->data; return 1; }
		if ( 'COMMIT' === $sql ) { $this->operations[] = 'commit'; $this->snapshot = null; return 1; }
		if ( 'ROLLBACK' === $sql ) { $this->operations[] = 'rollback'; $this->data = $this->snapshot ?? $this->data; $this->snapshot = null; return 1; }
		if ( str_starts_with( $sql, 'DELETE FROM `views_aish_metric_events`' ) ) {
			if ( $this->failRetention ) { $this->last_error = 'isolated GC failure'; return false; }
			$deleted = 0; foreach ( $this->data['events'] as $key => $event ) { if ( $deleted < 100 && 'skill_view' === $event['metric_name'] && $event['expires_at'] < $args[0] ) { unset( $this->data['events'][ $key ] ); ++$deleted; } } return $deleted;
		}
		if ( str_starts_with( $sql, 'DELETE FROM views_options' ) ) { foreach ( $args as $key ) { unset( $this->data['options'][ $key ] ); } return 2; }
		if ( str_starts_with( $sql, 'INSERT INTO views_options' ) ) {
			if ( str_contains( $sql, "VALUES (%s,'0','no')" ) ) { $this->data['options'][ $args[0] ] ??= '0'; }
			elseif ( str_contains( $sql, 'UPDATE option_name=VALUES(option_name)' ) ) { $this->data['options'][ $args[0] ] ??= $args[1]; }
			else { $this->data['options'][ $args[0] ] = $args[1]; }
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE views_options' ) ) { $this->data['options'][ $args[1] ] = $args[0]; return 1; }
		if ( str_starts_with( $sql, 'INSERT INTO `views_aish_daily_metrics`' ) ) {
			$this->operations[] = 'daily';
			if ( $this->failDaily ) { $this->last_error = 'simulated private SQL failure'; return false; }
			$key = $args[0] . ':' . $args[1]; $this->data['daily'][ $key ] = ( $this->data['daily'][ $key ] ?? 0 ) + 1; return 1;
		}
		throw new RuntimeException( 'Unexpected fixture write.' );
	}
	public function insert( string $table, array $data, array $formats ): int {
		if ( 'views_aish_metric_events' !== $table || null === $this->snapshot ) { throw new RuntimeException( 'Event requires its real transaction.' ); }
		$this->operations[] = 'event'; $this->insert_id = count( $this->data['events'] ) + 1; $this->data['events'][] = array( 'id' => $this->insert_id ) + $data; return 1;
	}
}

$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Security/Input.php', 'Frontend/Router.php', 'Frontend/PublicCatalog.php', 'Discovery/Settings.php', 'Discovery/Query.php', 'Discovery/Views.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Discovery\Settings;
use AISkillsHub\Core\Discovery\Views;
$checks = 0;
function check( bool $value, string $message ): void { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
function call( Views $views, string $method, mixed ...$args ): mixed { return ( new ReflectionMethod( $views, $method ) )->invoke( $views, ...$args ); }
function rejects( callable $operation, string $message ): void { try { $operation(); } catch ( Throwable $error ) { check( true, $message ); return; } throw new RuntimeException( $message ); }
function fixture(): array {
	$connection = new wpdb(); $db = new Database( $connection ); $migrator = new Migrator( $db );
	return array( $connection, new Views( $db, $migrator, new PublicCatalog( $db, $migrator ) ) );
}
function tracking( bool $enabled ): void { $data = Settings::defaults(); $data['tracking'] = $enabled; $GLOBALS['view_options'][ Settings::OPTION ] = json_encode( array( 'revision' => 1, 'published_revision' => 1, 'draft' => $data, 'published' => $data ) ); }
function endpoint( Views $views, array $changes = array() ): JsonReply {
	$_POST = array_replace( array( 'action' => 'aish_discovery_view', 'step' => 'start', 'skill' => '1', 'locale' => 'en_US', 'nonce' => 'test-nonce', 'ticket' => call( $views, 'ticket', 'page', 1, 'en_US' ) ), $changes );
	try { $views->handle(); } catch ( JsonReply $reply ) { return $reply; } throw new RuntimeException( 'Missing JSON response.' );
}

[ $db, $views ] = fixture();
check( false === Settings::tracking(), 'measurement is disabled by default' ); tracking( true );
$_SERVER = array( 'REQUEST_METHOD' => 'POST', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120 Safari/537.36', 'HTTP_ORIGIN' => 'https://directory.example' ); $_COOKIE = array();
$observer = str_repeat( 'a', 64 );
$body = gmdate( 'Y-m-d' ) . '.' . $observer;
$cookie = $body . '.' . call( $views, 'mac', 'cookie:' . $body );
check( $observer === call( $views, 'observer', $cookie ), 'valid daily server-signed cookie resolves only its random observer' );
foreach ( array( '', array(), $cookie . 'x', substr_replace( $cookie, 'b', -1 ), gmdate( 'Y-m-d', time() - 86400 ) . substr( $cookie, 10 ) ) as $invalid ) { check( null === call( $views, 'observer', $invalid ), 'missing, tampered, malformed and prior-day cookies fail closed' ); }
check( str_starts_with( call( $views, 'cookieName' ), '__Host-' ), 'HTTPS cookie requires host-only secure scope' );
$ticket = call( $views, 'ticket', 'view', 1, 'en_US', $observer, time() - 9 );
check( call( $views, 'validTicket', $ticket, 'view', 1, 'en_US', $observer ), 'server-issued observation ticket permits the minimum dwell' );
foreach ( array( array( 'view', 2, 'en_US', $observer ), array( 'view', 1, 'zh_CN', $observer ), array( 'view', 1, 'en_US', str_repeat( 'b', 64 ) ), array( 'page', 1, 'en_US', $observer ) ) as $args ) { check( ! call( $views, 'validTicket', $ticket, ...$args ), 'proof cannot change Skill, language, observer or purpose' ); }
foreach ( array( 0, 7, -10, 1801, 86400 ) as $age ) { check( ! call( $views, 'validTicket', call( $views, 'ticket', 'view', 1, 'en_US', $observer, time() - $age ), 'view', 1, 'en_US', $observer ), 'too-early, future, stale and prior-day proofs rejected' ); }
foreach ( array( 'https://evil.example', 'null', '', 'http://directory.example', 'https://directory.example:444', 'https://user@directory.example', 'https://directory.example/evil' ) as $origin ) { $_SERVER['HTTP_ORIGIN'] = $origin; check( ! call( $views, 'sameOrigin' ), 'foreign, malformed or non-canonical Origin rejected' ); }
$_SERVER['HTTP_ORIGIN'] = 'https://directory.example';
check( call( $views, 'sameOrigin' ), 'exact same origin accepted' );
foreach ( array( '', 'Googlebot', 'HeadlessChrome', 'curl/8.0', 'Python requests', str_repeat( 'x', 513 ) ) as $agent ) { $_SERVER['HTTP_USER_AGENT'] = $agent; check( ! call( $views, 'eligible', 1 ), 'obvious bot, empty or excessive user agent excluded' ); }
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120 Safari/537.36';
foreach ( array( 'manage_options', 'edit_posts', 'aish_manage_discovery', 'aish_manage_skills' ) as $cap ) { $GLOBALS['view_caps'] = array( $cap ); check( ! call( $views, 'eligible', 1 ), 'staff traffic excluded' ); } $GLOBALS['view_caps'] = array();
foreach ( array( 'HTTP_SEC_GPC', 'HTTP_DNT' ) as $key ) { $_SERVER[ $key ] = '1'; check( ! call( $views, 'eligible', 1 ), 'browser privacy preference excludes optional views' ); unset( $_SERVER[ $key ] ); }
$GLOBALS['view_options']['aish_discovery_excluded_skills'] = array( 1 ); check( ! call( $views, 'eligible', 1 ), 'trusted fixture exclusion applies' ); unset( $GLOBALS['view_options']['aish_discovery_excluded_skills'] );
foreach ( array( array( '1' ), array( '01' ), array( true ), 'invalid', array_fill( 0, 1001, 2 ) ) as $excluded ) { $GLOBALS['view_options']['aish_discovery_excluded_skills'] = $excluded; check( ! call( $views, 'eligible', 1 ), 'canonical string exclusions and malformed shared ranking exclusion configuration fail closed consistently' ); } unset( $GLOBALS['view_options']['aish_discovery_excluded_skills'] );
$GLOBALS['view_test_skill'] = true; check( ! call( $views, 'eligible', 1 ), 'server fixture filter excludes test data' ); $GLOBALS['view_test_skill'] = false;
$GLOBALS['view_meta'] = array( '_aish_account_deletion_pending' => '1' ); check( ! call( $views, 'eligible', 1 ), 'account erasure traffic is not measured' ); $GLOBALS['view_meta'] = array();
check( call( $views, 'eligible', 1 ), 'eligible consenting normal visitor can be measured' );

check( Settings::tracking(), 'fixture published tracking enabled' ); $reply = endpoint( $views ); check( $reply->success && 'consent_required' === $reply->payload['state'] && ! $db->queries && ! $db->data['events'], 'page initialization without consent writes nothing, not even a budget' );
foreach ( array( array( 'nonce' => 'invalid' ), array( 'step' => 'anything' ), array( 'count' => '999' ), array( 'dimension_key' => '2' ), array( 'skill' => '1 OR 1=1' ), array( 'locale' => 'fr_FR' ), array( 'ticket' => array() ) ) as $bad ) { $reply = endpoint( $views, $bad ); check( ! $reply->success && 400 === $reply->status && ! $db->queries, 'CSRF, arbitrary actions/counters/dimensions and malformed inputs rejected before SQL' ); }
$_COOKIE[ call( $views, 'cookieName' ) ] = $cookie;
$reply = endpoint( $views ); check( $reply->success && 'ready' === $reply->payload['state'] && ! $db->data['events'], 'existing explicit consent starts timer but does not count a request' );
$reply = endpoint( $views, array( 'step' => 'record', 'ticket' => $ticket ) );
check( $reply->success && 'recorded' === $reply->payload['state'] && 1 === count( $db->data['events'] ) && 1 === array_sum( $db->data['daily'] ), 'real service records modeled event and aggregate together' );
check( array( 'public_gate', 'begin', 'budget_lock', 'skill_lock', 'public_gate', 'dedupe', 'event', 'daily', 'commit' ) === $db->operations, 'fresh gate after budget/Skill row locks precedes event and aggregate commit' );
$event = $db->data['events'][0];
check( 'skill_view' === $event['metric_name'] && '' === $event['locale'] && Views::POLICY === $event['policy_version'] && 'skill' === $event['dimension_type'] && '1' === $event['dimension_key'], 'only fixed metric and stable Skill dimension are stored; languages merged' );
check( ! str_contains( json_encode( $db->data ), $observer ) && ! str_contains( json_encode( $db->data ), 'Mozilla' ) && ! isset( $event['user_id'], $event['ip'] ), 'random cookie, user ID, raw UA and IP are absent from persisted observations' );
$before = $db->data;
$reply = endpoint( $views, array( 'step' => 'record', 'ticket' => $ticket ) );
check( $reply->success && 'already_counted' === $reply->payload['state'] && $before === $db->data, 'refresh, retry and duplicate tab are idempotent' );
$zhTicket = call( $views, 'ticket', 'view', 1, 'zh_CN', $observer, time() - 9 );
$reply = endpoint( $views, array( 'step' => 'record', 'locale' => 'zh_CN', 'ticket' => $zhTicket ) );
check( 'already_counted' === $reply->payload['state'] && $before === $db->data, 'Chinese and English share one observer/Skill/day relation' );
$db->visible = false;
$reply = endpoint( $views, array( 'step' => 'record', 'ticket' => $ticket ) );
check( ! $reply->success && $before === $db->data && 'rollback' === end( $db->operations ), 'unpublished/incomplete current-language gate prevents even duplicate acceptance' ); $db->visible = true;
tracking( false ); $queryCount = count( $db->queries );
$reply = endpoint( $views, array( 'step' => 'record', 'ticket' => $ticket ) );
check( ! $reply->success && $before === $db->data && $queryCount === count( $db->queries ), 'editorial tracking disable preserves totals and stops all writes' ); tracking( true );

[ $db, $views ] = fixture(); $db->failDaily = true;
rejects( fn() => call( $views, 'persist', 1, 'en_US', $observer ), 'aggregate failure propagates' );
check( array( 'options' => array(), 'events' => array(), 'daily' => array() ) === $db->data && 'rollback' === end( $db->operations ), 'modeled rollback leaves no orphan event, budget or false coverage start' );
$db->failDaily = false;
check( call( $views, 'persist', 1, 'en_US', $observer ), 'retry after acknowledged rollback stores one observation' );
$coverage = $db->data['options'][ Views::COVERAGE_OPTION ];
check( call( $views, 'persist', 2, 'en_US', $observer ) && $coverage === $db->data['options'][ Views::COVERAGE_OPTION ], 'another Skill may count without resetting earliest coverage' );
check( $db->data['events'][0]['dedupe_key'] !== $db->data['events'][1]['dedupe_key'], 'per-Skill HMAC cannot serve as a shared browser history identifier' );
foreach ( $db->data['options'] as $key => &$value ) { if ( str_starts_with( $key, '_transient_aish_view_budget_' ) ) { $value = '100'; } } unset( $value );
$before = $db->data;
rejects( fn() => call( $views, 'persist', 3, 'en_US', $observer ), 'daily observer budget caps distinct accepted Skills' );
check( $before === $db->data, 'budget rejection creates no event or aggregate' );
check( false === call( $views, 'persist', 1, 'en_US', $observer ), 'an existing observation remains idempotent at its budget limit' );
check( call( $views, 'persist', 1, 'en_US', str_repeat( 'b', 64 ) ), 'independent consenting observer has an independent daily budget' );
check( call( $views, 'mac', 'daily:' . gmdate( 'Y-m-d' ) ) !== call( $views, 'mac', 'daily:' . gmdate( 'Y-m-d', time() - 86400 ) ), 'observer HMAC salt rotates across UTC days' );
$db->engine = 'MyISAM'; $before = $db->data;
rejects( fn() => call( $views, 'persist', 4, 'en_US', $observer ), 'non-transactional native option storage is refused' );
check( $before === $db->data, 'refused storage cannot partially count a view' );

[ $db, $views ] = fixture();
for ( $i = 0; $i < 101; ++$i ) { $key = 'aish_view_budget_' . hash( 'sha256', (string) $i ); $db->data['options'][ '_transient_' . $key ] = '1'; $db->data['options'][ '_transient_timeout_' . $key ] = (string) ( time() - 60 ); }
$db->data['options']['unrelated_native_option'] = 'preserve';
$key = 'aish_view_budget_' . hash( 'sha256', 'current' ); $db->data['options'][ '_transient_' . $key ] = '2'; $db->data['options'][ '_transient_timeout_' . $key ] = (string) ( time() + 3600 );
$before = $db->data; $views->cleanup();
check( $before === $db->data && ! $db->queries, 'cleanup does not execute in ordinary frontend requests' );
define( 'WP_CLI', true ); $views->cleanup();
check( 5 === count( $db->data['options'] ) && 'preserve' === $db->data['options']['unrelated_native_option'], 'bounded cleanup deletes at most 100 expired own budget pairs, preserving unrelated options' );
check( 1 === $GLOBALS['view_schedule_calls'] && $GLOBALS['view_scheduled'][ Views::RETENTION_HOOK ] >= time() + 59 && 'gc_release' === end( $db->operations ), 'full budget batch schedules one continuation after 60 seconds and releases lock' );
$views->cleanup();
check( 3 === count( $db->data['options'] ) && '2' === $db->data['options'][ '_transient_' . $key ], 'resumable cleanup preserves unexpired daily budgets even with persistent cache' );
check( 1 === $GLOBALS['view_schedule_calls'], 'partial final batch never creates an unnecessary second continuation' );
$GLOBALS['view_scheduled'] = array(); $GLOBALS['view_schedule_calls'] = 0;
for ( $i = 0; $i < 201; ++$i ) { $db->data['events'][ $i ] = array( 'metric_name' => 'skill_view', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ); }
$db->data['events']['community'] = array( 'metric_name' => 'favorite_add', 'expires_at' => '2020-01-01 00:00:00' );
$db->data['events']['current'] = array( 'metric_name' => 'skill_view', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) );
$db->gcBusy = true; $before = $db->data; $views->cleanup();
check( $before === $db->data && ! $GLOBALS['view_scheduled'] && 'gc_lock' === end( $db->operations ), 'a concurrent cleanup worker cannot take over the active lock or schedule duplicates' );
$db->gcBusy = false; $views->cleanup();
check( 103 === count( $db->data['events'] ) && 1 === $GLOBALS['view_schedule_calls'], 'full expired-view batch schedules bounded catch-up independently of budgets' );
$views->cleanup();
check( 3 === count( $db->data['events'] ) && 1 === $GLOBALS['view_schedule_calls'], 'already queued continuation is reused instead of duplicated' );
$GLOBALS['view_scheduled'] = array(); $views->cleanup();
check( 2 === count( $db->data['events'] ) && isset( $db->data['events']['community'], $db->data['events']['current'] ) && ! $GLOBALS['view_scheduled'], 'catch-up stops after backlog drains, preserving Phase 9 and unexpired events' );
$db->failRetention = true; $views->cleanup();
check( 'gc_release' === end( $db->operations ) && ! $GLOBALS['view_scheduled'], 'cleanup failure still releases lock and permits later hourly recovery' );
$db->failRetention = false; $views->register();
check( isset( $GLOBALS['view_actions'][ Views::RETENTION_HOOK ], $GLOBALS['view_actions']['aish_community_maintenance'] ), 'continuation and existing hourly hook share the same guarded bounded cleanup' );
$GLOBALS['view_scheduled'][ Views::RETENTION_HOOK ] = time() + 60;
foreach ( $GLOBALS['view_actions']['switch_theme'] as $callback ) { $callback(); }
check( ! $GLOBALS['view_scheduled'], 'switching the theme clears pending discovery retention jobs without deleting business data' );

echo "Discovery views: $checks isolated assertions passed (real service and transactional wpdb model; no real WordPress/MySQL/browser).\n";
