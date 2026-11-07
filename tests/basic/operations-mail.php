<?php
declare(strict_types=1);
/** Isolated real operations service/template policy with modeled wpdb. No transport is called. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'DB_NAME', 'operations_mail_fixture' );
$GLOBALS['mail_newsletter_cap'] = false; $GLOBALS['mail_cap'] = true; $GLOBALS['mail_manager'] = false; $GLOBALS['mail_duplicate_state'] = false; $GLOBALS['mail_deletion'] = ''; $GLOBALS['mail_actor_state'] = ''; $GLOBALS['mail_options'] = array(); $GLOBALS['mail_wake'] = 0;
function get_option( string $key, mixed $default = false ): mixed { return 'aish_schema_version' === $key ? '1.9.0' : ( $GLOBALS['mail_options'][ $key ] ?? $default ); }
function current_user_can( string $key ): bool { return match ( $key ) { 'manage_options' => $GLOBALS['mail_manager'], 'aish_manage_notifications' => $GLOBALS['mail_cap'], 'aish_manage_subscriptions' => $GLOBALS['mail_newsletter_cap'], default => false }; }
function get_current_user_id(): int { return 7; }
function get_user_locale(): string { return 'en_US'; }
function wp_cache_delete( mixed $key, string $group ): void {}
function wp_json_encode( mixed $v ): string { return json_encode( $v, JSON_THROW_ON_ERROR ); }
function wp_next_scheduled( string $hook ): bool { return false; }
function wp_mail( mixed ...$v ): never { throw new RuntimeException( 'Mail operations must never call transport.' ); }
function do_action( string $hook ): void { if ( 'aish_account_mail_wake' === $hook ) { ++$GLOBALS['mail_wake']; } }
final class wpdb {
	public string $prefix = 'wp_'; public string $users = 'wp_users'; public string $usermeta = 'wp_usermeta'; public string $options = 'wp_options'; public string $last_error = ''; public int $insert_id = 0;
	public array $rows = array(); public array $events = array(); public array $sql = array(); public bool $busy = false; public bool $failAudit = false; public string $engine = 'InnoDB'; private array $before = array();
	public function suppress_errors( bool $v ): bool { return false; }
	public function prepare( string $sql, array $v ): string { return json_encode( array( $sql, $v ), JSON_THROW_ON_ERROR ); }
	private function unpack( string $value ): array { $r = str_starts_with( $value, '[' ) ? json_decode( $value, true, 32, JSON_THROW_ON_ERROR ) : array( $value, array() ); $this->sql[] = $r[0]; return $r; }
	public function get_var( string $sql ): mixed {
		[ $q, $v ] = $this->unpack( $sql );
		if ( str_contains( $q, 'GET_LOCK' ) ) { return $this->busy ? 0 : 1; }
		if ( str_contains( $q, 'RELEASE_LOCK' ) || str_contains( $q, 'SELECT ID FROM wp_users' ) ) { return 1; }
		if ( str_contains( $q, 'information_schema.TABLES' ) ) { return $this->engine; }
		if ( str_contains( $q, 'SELECT option_value' ) ) { return $GLOBALS['mail_options']['aish_account_mail_paused'] ?? null; }
		throw new RuntimeException( 'Unexpected fixture scalar query.' );
	}
	public function get_results( string $sql, string $format ): array {
		[ $q, $v ] = $this->unpack( $sql );
		if ( str_contains( $q, 'wp_usermeta' ) ) { $meta = $GLOBALS['mail_actor_state'] ? array( array( 'meta_key' => '_aish_account_state', 'meta_value' => $GLOBALS['mail_actor_state'] ) ) : array(); if ( $meta && $GLOBALS['mail_duplicate_state'] ) { $meta[] = $meta[0]; } if ( '' !== $GLOBALS['mail_deletion'] ) { $meta[] = array( 'meta_key' => '_aish_account_deletion_pending', 'meta_value' => $GLOBALS['mail_deletion'] ); } return $meta; }
		if ( str_contains( $q, 'FOR UPDATE' ) ) { return isset( $this->rows[ $v[0] ] ) ? array( $this->rows[ $v[0] ] + array( 'recoverable_payload' => ! empty( $this->rows[ $v[0] ]['payload_ciphertext'] ) ) ) : array(); }
		if ( str_contains( $q, 'GROUP BY status' ) ) { $result = array(); foreach ( $this->rows as $r ) { if ( $r['created_at'] < $v[0] ) { continue; } $state = $r['status']; $result[ $state ] ??= array( 'status' => $state, 'messages' => 0, 'attempts' => 0 ); ++$result[ $state ]['messages']; $result[ $state ]['attempts'] += $r['attempts']; } return array_values( $result ); }
		if ( str_contains( $q, 'SELECT id,template_key' ) ) {
			$rows = array_values( $this->rows ); if ( str_contains( $q, 'AND status=%s' ) ) { $rows = array_values( array_filter( $rows, fn( $r ) => $r['status'] === $v[0] ) ); }
			return array_map( function ( $r ) { $out = array_intersect_key( $r, array_flip( array( 'id','template_key','locale','status','attempts','available_at','expires_at','created_at','updated_at','accepted_at','last_error' ) ) ); return $out + array( 'lease_available' => '' === $r['lease_token'], 'recoverable_payload' => ! empty( $r['payload_ciphertext'] ) ); }, array_slice( $rows, end( $v ), 26 ) );
		}
		throw new RuntimeException( 'Unexpected fixture rows query.' );
	}
	public function query( string $sql ): int {
		[ $q, $v ] = $this->unpack( $sql );
		if ( 'START TRANSACTION' === $q ) { $this->before = array( $this->rows, $this->events, $GLOBALS['mail_options'] ); return 0; }
		if ( 'ROLLBACK' === $q ) { [ $this->rows, $this->events, $GLOBALS['mail_options'] ] = $this->before; return 0; }
		if ( 'COMMIT' === $q ) { return 0; }
		if ( str_starts_with( $q, 'UPDATE' ) && str_contains( $q, 'available_at=' ) ) { $this->rows[ $v[2] ]['available_at'] = $v[0]; $this->rows[ $v[2] ]['updated_at'] = $v[1]; return 1; }
		if ( str_starts_with( $q, 'INSERT INTO wp_options' ) ) { $GLOBALS['mail_options']['aish_account_mail_paused'] = $v[0]; return 1; }
		throw new RuntimeException( 'Unexpected fixture mutation.' );
	}
	public function insert( string $table, array $data, array $formats ): int { if ( $this->failAudit ) { throw new RuntimeException( 'Audit unavailable.' ); } if ( 'wp_aish_workflow_events' !== $table ) { throw new RuntimeException( 'Unexpected business mutation.' ); } $this->events[] = $data; ++$this->insert_id; return 1; }
}
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Accounts/Store.php', 'Accounts/Mail.php', 'Accounts/Crypto.php', 'Accounts/Scheduler.php', 'Community/Input.php', 'Community/Events.php', 'Community/Exception.php', 'Operations/MailAdmin.php' ) as $file ) { require $base . $file; }
use AISkillsHub\Core\Accounts\{Store,Mail}; use AISkillsHub\Core\Database\{Database,Migrator}; use AISkillsHub\Core\Operations\MailAdmin;
$native = new wpdb(); $db = new Database( $native ); $store = new Store( $db ); $admin = new MailAdmin( $store, new Mail( $store ), new Migrator( $db ) ); $checks = 0;
function check( bool $ok, string $label ): void { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $label ); } }
function reject( callable $f, ?string $code = null ): void { try { $f(); } catch ( Throwable $e ) { check( null === $code || $code === $e->getMessage(), 'Expected safe rejection ' . (string) $code ); return; } throw new RuntimeException( 'Unexpected acceptance.' ); }
$fixture = array( 'id' => 1, 'status' => 'retry', 'attempts' => 1, 'lease_token' => '', 'payload_ciphertext' => 'secret-fixture-never-in-projection', 'event_key' => 'secret-event', 'template_key' => 'verify_email', 'locale' => 'en_US', 'destination_hash' => 'private-destination', 'last_error' => 'mail_transport_rejected', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'available_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'accepted_at' => null );
$native->rows[1] = $fixture;
$list = $admin->listing(); check( true === $list[0]['can_retry'], 'Known rejected retry with retained live payload is recoverable.' );
check( ! str_contains( json_encode( $list ), 'secret-' ) && ! str_contains( json_encode( $list ), 'destination_hash' ) && ! isset( $list[0]['lease_token'] ), 'Admin projection excludes ciphertext, lease, event and destination identity.' );
$admin->retry( 1 ); check( $native->rows[1]['available_at'] <= Store::now() && 1 === $native->rows[1]['attempts'] && 1 === count( $native->events ), 'Manual retry only schedules existing attempt budget and writes fixed audit.' );
reject( fn() => $admin->retry( 1 ), 'mail_not_retryable' ); check( 1 === count( $native->events ), 'Duplicate retry does not repeat business events or reset attempt budget.' );
check( 1 === $GLOBALS['mail_wake'], 'Only successful scheduling wakes Cron; no transport executes.' );
foreach ( array( 'accepted','uncertain','failed','expired','cancelled','queued','claimed','sending' ) as $state ) { $native->rows[1] = array_replace( $fixture, array( 'status' => $state ) ); reject( fn() => $admin->retry( 1 ), 'mail_not_retryable' ); }
foreach ( array( array( 'payload_ciphertext' => null ), array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'attempts' => 3 ), array( 'last_error' => 'unknown-provider-secret' ), array( 'lease_token' => 'secret-lease' ) ) as $override ) { $native->rows[1] = array_replace( $fixture, $override ); reject( fn() => $admin->retry( 1 ), 'mail_not_retryable' ); }
$native->rows[1] = array_replace( $fixture, array( 'last_error' => 'mail_worker_interrupted', 'attempts' => 4 ) ); $admin->retry( 1 ); check( 4 === $native->rows[1]['attempts'], 'Pre-send interrupted lease can resume within original maximum without resetting attempts.' );
$native->rows[1] = array_replace( $fixture, array( 'last_error' => 'mail_worker_interrupted', 'attempts' => 5 ) ); reject( fn() => $admin->retry( 1 ), 'mail_not_retryable' );
$native->rows[1] = $fixture; $native->failAudit = true; reject( fn() => $admin->retry( 1 ) ); check( $fixture['available_at'] === $native->rows[1]['available_at'], 'Audit failure rolls back retry schedule.' );
reject( fn() => $admin->pause( true ) ); check( ! isset( $GLOBALS['mail_options']['aish_account_mail_paused'] ), 'Pause and audit roll back together.' ); $native->failAudit = false;
$admin->pause( true ); check( '1' === $GLOBALS['mail_options']['aish_account_mail_paused'], 'Queue pause stored without rewriting outbox states.' ); $events = count( $native->events ); $admin->pause( true ); check( $events === count( $native->events ), 'Repeated same pause is idempotent.' );
$admin->pause( false ); check( '0' === $GLOBALS['mail_options']['aish_account_mail_paused'], 'Resume does not touch source or AI scheduler options.' );
$native->engine = 'MyISAM'; reject( fn() => $admin->pause( true ), 'mail_storage_unavailable' ); $native->engine = 'InnoDB';
$native->busy = true; reject( fn() => $admin->retry( 1 ), 'mail_worker_busy' ); reject( fn() => $admin->pause( true ), 'mail_worker_busy' ); $native->busy = false;
$native->rows[1] = array_replace( $fixture, array( 'last_error' => 'SECRET_KEY=do-not-render' ) ); check( 'mail_failed' === $admin->listing()[0]['last_error'], 'Unknown provider/driver strings become fixed safe error code.' );
$config = $admin->configuration(); check( false === $config['enabled'] && false === $config['transport_verified'], 'Missing mail configuration is not reported as real delivery success.' );
$stats = $admin->statistics(); check( 1 === $stats['counts']['retry'] && 1 === $stats['attempts'] && ! isset( $stats['delivery_rate'] ), 'Statistics retain explicit row/attempt denominators without fake delivery rate.' );
foreach ( array( 'verify_email','change_email','password_reset','security_notice' ) as $template ) { foreach ( array( 'zh_CN','en_US' ) as $locale ) { $preview = Mail::preview( $template, $locale ); check( '' !== $preview['subject'] && '' !== $preview['body'] && ! preg_match( '#https?://|token=|key=|@#', implode( ' ', $preview ) ), 'Bilingual preview never builds credential or recipient URLs.' ); } }
reject( fn() => Mail::preview( 'custom', 'en_US' ) ); reject( fn() => Mail::preview( 'verify_email', 'fr_FR' ) ); reject( fn() => Mail::preview( 'security_notice', 'en_US', 'arbitrary' ) );
$GLOBALS['mail_cap'] = false; reject( fn() => $admin->listing(), 'mail_forbidden' ); reject( fn() => $admin->pause( false ), 'mail_forbidden' ); $GLOBALS['mail_cap'] = true;
$GLOBALS['mail_actor_state'] = 'suspended'; reject( fn() => $admin->statistics(), 'mail_forbidden' ); $GLOBALS['mail_actor_state'] = '';
$GLOBALS['mail_actor_state'] = 'pending_email'; reject( fn() => $admin->listing(), 'mail_forbidden' );
$GLOBALS['mail_manager'] = true; check( 1 === count( $admin->listing() ), 'Native manager awaiting email verification retains mail-repair access.' );
$GLOBALS['mail_actor_state'] = 'suspended'; reject( fn() => $admin->statistics(), 'mail_forbidden' );
$GLOBALS['mail_actor_state'] = 'corrupt_state'; reject( fn() => $admin->listing(), 'mail_forbidden' );
$GLOBALS['mail_actor_state'] = 'active'; $GLOBALS['mail_duplicate_state'] = true; reject( fn() => $admin->configuration(), 'mail_forbidden' ); $GLOBALS['mail_duplicate_state'] = false;
$GLOBALS['mail_actor_state'] = 'pending_email'; $GLOBALS['mail_deletion'] = '1'; reject( fn() => $admin->listing(), 'mail_forbidden' ); $GLOBALS['mail_deletion'] = '';
$GLOBALS['mail_actor_state'] = ''; $GLOBALS['mail_manager'] = false;
check( ! array_filter( $native->sql, fn( $sql ) => preg_match( '/(?:UPDATE|INSERT|DELETE).*aish_(skills|reviews|imports|ai_jobs)/i', $sql ) ), 'Mail recovery never replays moderation, resource creation or AI/import work.' );
// Phase14 keeps newsletter dispatch authorization separate from queue maintenance.
foreach ( array( 'newsletter_confirm', 'newsletter_weekly', 'newsletter_new', 'newsletter_future' ) as $template ) {
 $native->rows[1] = array_replace( $fixture, array( 'template_key' => $template ) ); $GLOBALS['mail_newsletter_cap'] = false;
 $before = $native->rows[1]; $events = count( $native->events ); $wake = $GLOBALS['mail_wake'];
 check( false === $admin->listing()[0]['can_retry'], 'Queue-only manager sees no newsletter retry affordance.' );
 reject( fn() => $admin->retry( 1 ), 'mail_forbidden' );
 check( $native->rows[1] === $before && count( $native->events ) === $events && $GLOBALS['mail_wake'] === $wake, 'Forged direct newsletter retry cannot schedule, audit or wake a worker.' );
 $GLOBALS['mail_newsletter_cap'] = true; check( true === $admin->listing()[0]['can_retry'], 'Newsletter retry requires both capabilities.' );
 $admin->retry( 1 ); check( $native->rows[1]['available_at'] <= Store::now() && 1 === $native->rows[1]['attempts'] && count( $native->events ) === $events + 1, 'Authorized marketing retry remains existing bounded outbox operation.' );
}
$GLOBALS['mail_cap'] = false; reject( fn() => $admin->retry( 1 ), 'mail_forbidden' ); $GLOBALS['mail_cap'] = true; $GLOBALS['mail_newsletter_cap'] = false;
$native->rows[1] = $fixture; check( true === $admin->listing()[0]['can_retry'], 'Ordinary account messages preserve existing queue capability.' );
$admin->retry( 1 ); $admin->pause( true ); $admin->pause( false ); check( '0' === $GLOBALS['mail_options']['aish_account_mail_paused'], 'Global queue maintenance remains available without marketing capability.' );
check( count( array_filter( $native->sql, fn( $sql ) => str_contains( $sql, 'SELECT id,template_key,status' ) && str_contains( $sql, 'FOR UPDATE' ) ) ) >= 4, 'Authorization checks actual locked template rather than caller parameter.' );
echo "Operations mail: $checks isolated assertions passed (modeled WordPress/SQL, no real mail).\n";
