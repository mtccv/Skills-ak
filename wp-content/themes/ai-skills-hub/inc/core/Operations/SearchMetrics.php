<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Discovery\Settings;
use AISkillsHub\Core\Discovery\Views;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Optional post-response measurement. No query/user identifier is accepted as an analytics API. */
final class SearchMetrics {
	public const POLICY = 'operations-1';
	public const COVERAGE = 'aish_operations_metric_coverage';
	public const HEALTH = 'aish_operations_metric_health';
	public const ACTION = 'aish_search_metric';
	public const CLEANUP = 'aish_operations_metric_cleanup';
	public function __construct( private Database $db, private Migrator $migrator, private Views $views ) {}
	public function register(): void {
		add_action( 'aish_search_observation', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_login', function ( string $login, \WP_User $user ): void { $this->active( (int) $user->ID ); }, 100, 2 );
		add_action( 'aish_account_interaction_completed', array( $this, 'active' ) );
		add_action( 'aish_community_maintenance', array( $this, 'cleanup' ), 25 );
		add_action( self::CLEANUP, array( $this, 'cleanup' ) );
		add_action( 'switch_theme', static function (): void { wp_clear_scheduled_hook( self::CLEANUP ); } );
	}
	private function mac( string $value ): string { return hash_hmac( 'sha256', self::POLICY . ':' . $this->db->prefix() . ':' . Router::siteUrl() . ':' . $value, wp_salt( 'auth' ) ); }
	private function proof( array $context ): string {
		$body = rtrim( strtr( base64_encode( json_encode( $context + array( 'issued' => time(), 'request' => bin2hex( random_bytes( 16 ) ) ), JSON_THROW_ON_ERROR ) ), '+/', '-_' ), '=' );
		return $body . '.' . $this->mac( 'proof:' . $body );
	}
	private function decode( string $proof ): array {
		if ( strlen( $proof ) > 1800 || ! preg_match( '/^([a-zA-Z0-9_-]+)\.([a-f0-9]{64})$/D', $proof, $m ) || ! hash_equals( $this->mac( 'proof:' . $m[1] ), $m[2] ) ) { throw new \InvalidArgumentException( 'invalid_proof' ); }
		$v = json_decode( (string) base64_decode( strtr( $m[1], '-_', '+/' ), true ), true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $v ) || ! is_int( $v['issued'] ?? null ) || time() - $v['issued'] < 0 || time() - $v['issued'] > 1800 || gmdate( 'Y-m-d', $v['issued'] ) !== gmdate( 'Y-m-d' ) || ! in_array( $v['locale'] ?? '', array( 'zh_CN', 'en_US' ), true ) || ! is_bool( $v['zero'] ?? null ) || ! is_string( $v['request'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $v['request'] ) || ( null !== ( $v['term'] ?? null ) && SearchPrivacy::term( $v['term'] ) !== $v['term'] ) ) { throw new \InvalidArgumentException( 'invalid_proof' ); }
		return $v;
	}
	public function render( array $context ): void {
		try {
			$data = SearchPrivacy::context( $context );
			if ( null === $data || ! Settings::tracking() || ! $this->migrator->isCurrent() || ! $this->views->analyticsEligible() ) { return; }
			$zh = 'zh_CN' === $data['locale'];
			echo '<aside class="mt-5 border-t border-slate-200 py-3 text-xs text-slate-500" data-search-metric data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::ACTION ) ) . '" data-locale="' . esc_attr( $data['locale'] ) . '" data-proof="' . esc_attr( $this->proof( $data ) ) . '"><p>' . esc_html( $zh ? '可选当天匿名统计：浏览、成功搜索和已验证账户的日活跃。复用当天随机 Cookie，不保存 IP、账户 ID 或个人搜索历史；词语仅在同日达到 5 个观察主体后进入汇总，可随时停止。' : 'Optional daily measurement: views, successful searches and verified account activity. One daily random cookie; no IP, account ID or personal search history. Terms enter reports only after 5 daily observers. Stop at any time.' ) . '</p><button type="button" class="mt-2 underline" data-search-consent>' . esc_html( $zh ? '允许当天匿名统计' : 'Allow daily measurement' ) . '</button><button type="button" class="mt-2 underline" data-search-withdraw hidden>' . esc_html( $zh ? '停止统计' : 'Stop measurement' ) . '</button><p role="status" aria-live="polite" data-search-status></p></aside>';
		} catch ( \Throwable $error ) { $this->health( 'render_failed' ); }
	}
	private function sameOrigin(): bool {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ( ! is_string( $origin ) || strlen( $origin ) > 512 ) { return false; }
		$a = wp_parse_url( $origin ); $b = wp_parse_url( Router::siteUrl() );
		return is_array( $a ) && is_array( $b ) && ! isset( $a['user'] ) && ! isset( $a['pass'] ) && ! isset( $a['query'] ) && ! isset( $a['fragment'] ) && empty( $a['path'] ) && in_array( $a['scheme'] ?? '', array( 'https', 'http' ), true ) && ( $a['scheme'] ?? '' ) === ( $b['scheme'] ?? '' ) && strtolower( $a['host'] ?? '' ) === strtolower( $b['host'] ?? '' ) && ( $a['port'] ?? ( 'https' === $a['scheme'] ? 443 : 80 ) ) === ( $b['port'] ?? ( 'https' === $b['scheme'] ? 443 : 80 ) );
	}
	public function handle(): void {
		nocache_headers(); header( 'X-Robots-Tag: noindex, noarchive' ); $result = null; $status = 200;
		try {
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $this->sameOrigin() || (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 4096 || array_diff( array_keys( $_POST ), array( 'action', 'step', 'nonce', 'proof' ) ) ) { throw new \InvalidArgumentException( 'invalid_request' ); }
			foreach ( $_POST as $v ) { if ( ! is_string( $v ) || strlen( $v ) > 1800 ) { throw new \InvalidArgumentException( 'invalid_request' ); } }
			$input = wp_unslash( $_POST );
			if ( ! wp_verify_nonce( $input['nonce'] ?? '', self::ACTION ) ) { throw new \InvalidArgumentException( 'invalid_request' ); }
			$step = $input['step'] ?? '';
			if ( 'withdraw' === $step ) { $this->views->withdrawAnalyticsConsent(); $result = array( 'state' => 'stopped' ); }
			else {
				if ( ! in_array( $step, array( 'start', 'consent', 'record' ), true ) || ! Settings::tracking() || ! $this->migrator->isCurrent() || ! $this->views->analyticsEligible() ) { throw new \InvalidArgumentException( 'unavailable' ); }
				$data = $this->decode( $input['proof'] ?? '' );
				$observer = $this->views->analyticsObserver();
				if ( 'consent' === $step ) { $observer = $this->views->grantAnalyticsConsent(); }
				if ( null === $observer ) { $result = array( 'state' => 'consent_required' ); }
				elseif ( 'record' !== $step ) { $result = array( 'state' => 'ready' ); }
				else { $result = array( 'state' => $this->persist( $data, $observer ) ? 'recorded' : 'already_counted' ); }
			}
		} catch ( \InvalidArgumentException $error ) { $status = 400; }
		catch ( \Throwable $error ) { $status = 503; $this->health( 'write_failed' ); }
		if ( null === $result ) { wp_send_json_error( array( 'state' => 'unavailable' ), $status ); }
		wp_send_json_success( $result, $status );
	}
	/** Strict mode prevents wpdb silently reconnecting after a session-level lock was acquired. */
	private function locked( callable $work ): mixed {
		$lock = 'aish_metrics:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 45 ); $held = false;
		$mode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s,0)', array( $lock ) );
			if ( ! $held ) { throw new \RuntimeException( 'measurement_busy' ); }
			return $work();
		} finally { if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $ignored ) {} } if ( null !== $mode ) { mysqli_report( $mode ); } }
	}
	private function options(): string {
		$table = $this->db->connection()->options;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) || 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) ) ) ) { throw new \RuntimeException( 'measurement_storage_unavailable' ); }
		return $table;
	}
	private function event( string $metric, string $dimension, string $key, string $locale, string $dedupe ): bool {
		$events = $this->db->table( 'metric_events' ); $day = gmdate( 'Y-m-d' );
		if ( $this->db->row( "SELECT id FROM $events WHERE event_day=%s AND metric_name=%s AND dedupe_key=%s", array( $day, $metric, $dedupe ) ) ) { return false; }
		$this->db->insert( 'metric_events', array( 'metric_name' => $metric, 'dimension_type' => $dimension, 'dimension_key' => $key, 'locale' => $locale, 'event_day' => $day, 'dedupe_key' => $dedupe, 'policy_version' => self::POLICY, 'occurred_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) );
		return true;
	}
	private function aggregate( string $metric, string $dimension, string $key, string $locale, int $count, bool $replace = false ): void {
		$daily = $this->db->table( 'daily_metrics' );
		$this->db->execute( "INSERT INTO $daily (stat_day,metric_name,dimension_type,dimension_key,locale,metric_count,policy_version,updated_at) VALUES (%s,%s,%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE metric_count=" . ( $replace ? 'GREATEST(metric_count,VALUES(metric_count))' : 'metric_count+VALUES(metric_count)' ) . ',updated_at=VALUES(updated_at)', array( gmdate( 'Y-m-d' ), $metric, $dimension, $key, $locale, $count, self::POLICY, gmdate( 'Y-m-d H:i:s' ) ) );
	}
	/** Exact total and dedupe commit together; sub-threshold terms never persist in plaintext. */
	private function persist( array $data, string $observer ): bool {
		try { return $this->locked( function () use ( $data, $observer ): bool {
			$options = $this->options();
			return $this->db->transaction( function () use ( $data, $observer, $options ): bool {
				$day = gmdate( 'Y-m-d' ); $locale = $data['locale']; $salt = $this->mac( 'daily:' . $day );
				$request = hash_hmac( 'sha256', 'request:' . $observer . ':' . $data['request'], $salt );
				$budget = 'aish_search_budget_' . hash_hmac( 'sha256', 'budget:' . $observer, $salt );
				$stored = $this->db->value( "SELECT option_value FROM $options WHERE option_name=%s", array( '_transient_' . $budget ) );
				if ( null !== $stored && ( ! is_scalar( $stored ) || ! preg_match( '/^(0|[1-9][0-9]{0,2})$/D', (string) $stored ) ) ) { throw new \RuntimeException( 'measurement_budget_unavailable' ); }
				$count = (int) $stored;
				if ( ! Settings::tracking() || ! $this->views->analyticsEligible() ) { throw new \InvalidArgumentException( 'measurement_unavailable' ); }
				if ( ! $this->event( 'search', 'site', 'all', $locale, $request ) ) { return false; }
				// Existing proofs remain idempotent even after the daily budget is exhausted.
				if ( $count >= 100 ) { throw new \InvalidArgumentException( 'measurement_limit' ); }
				$this->aggregate( 'search', 'site', 'all', $locale, 1 );
				if ( $data['zero'] ) { $this->event( 'search_no_result', 'site', 'all', $locale, $request ); $this->aggregate( 'search_no_result', 'site', 'all', $locale, 1 ); }
				foreach ( array( '_transient_' . $budget => (string) ( $count + 1 ), '_transient_timeout_' . $budget => (string) strtotime( 'tomorrow UTC' ) ) as $key => $value ) { $this->db->execute( "INSERT INTO $options (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", array( $key, $value ) ); }
				if ( null !== $data['term'] ) { $this->term( $data['term'], $locale, $observer, $data['zero'], $salt ); }
				$this->coverage( 'search', $options );
				return true;
			} );
		} ); } finally { $this->invalidateCoverage(); }
	}
	private function term( string $term, string $locale, string $observer, bool $zero, string $salt ): void {
		$events = $this->db->table( 'metric_events' ); $day = gmdate( 'Y-m-d' );
		$key = hash_hmac( 'sha256', 'term:' . $locale . ':' . $term, $salt );
		$dedupe = hash_hmac( 'sha256', 'term-observer:' . $observer . ':' . $key, $salt );
		// Fixed daily cardinality cap, including low-frequency hashes. Daily global lock serializes admission.
		$exists = $this->db->value( "SELECT id FROM $events WHERE dimension_type='search_hash' AND dimension_key=%s AND event_day=%s LIMIT 1", array( $key, $day ) );
		if ( null === $exists && (int) $this->db->value( "SELECT COUNT(DISTINCT dimension_key) FROM $events WHERE event_day=%s AND metric_name='search_term' AND dimension_type='search_hash' AND policy_version=%s", array( $day, self::POLICY ) ) >= SearchPrivacy::DAILY_TERMS ) { $this->health( 'term_cap' ); return; }
		$this->event( 'search_term', 'search_hash', $key, $locale, $dedupe );
		if ( $zero ) { $this->event( 'no_result_term', 'search_hash', $key, $locale, $dedupe ); }
		$counts = $this->db->row( "SELECT COUNT(CASE WHEN metric_name='search_term' THEN 1 END) AS searches,COUNT(CASE WHEN metric_name='no_result_term' THEN 1 END) AS zeros FROM $events WHERE dimension_type='search_hash' AND dimension_key=%s AND event_day=%s AND locale=%s AND policy_version=%s", array( $key, $day, $locale, self::POLICY ) );
		if ( (int) $counts['searches'] < SearchPrivacy::THRESHOLD ) { return; }
		$dimension = SearchPrivacy::dimension( $term );
		$this->aggregate( 'search_term', 'search_term', $dimension, $locale, (int) $counts['searches'], true );
		$this->aggregate( 'no_result_term', 'search_term', $dimension, $locale, (int) $counts['zeros'], true );
	}
	private function coverage( string $name, string $options ): void {
		$key = self::COVERAGE . '_' . $name;
		$this->db->execute( "INSERT INTO $options (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)", array( $key, gmdate( 'Y-m-d H:i:s' ) ) );
	}
	private function invalidateCoverage(): void {
		try {
			foreach ( array( 'search', 'active' ) as $name ) { wp_cache_delete( self::COVERAGE . '_' . $name, 'options' ); }
			wp_cache_delete( 'notoptions', 'options' );
		} catch ( \Throwable $ignored ) { /* Optional analytics cannot fail the committed account interaction. */ }
	}
	/** Called after locking the native user row, against fresh authoritative metadata. */
	private function activeIdentity( int $userId ): bool {
		$users = $this->db->connection()->users; $meta = $this->db->connection()->usermeta;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $users ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $meta ) ) { return false; }
		$user = $this->db->row( "SELECT ID,user_email FROM $users WHERE ID=%d FOR UPDATE", array( $userId ) );
		if ( null === $user ) { return false; }
		$rows = $this->db->rows( "SELECT meta_key,meta_value FROM $meta WHERE user_id=%d AND meta_key IN ('_aish_email_verified_at','_aish_verified_email_hash','_aish_account_state','_aish_account_deletion_pending','_aish_community_erasing','_aish_contributions_erasing','_aish_operations_test_data') FOR UPDATE", array( $userId ) );
		$values = array(); foreach ( $rows as $row ) { if ( isset( $values[ $row['meta_key'] ] ) ) { return false; } $values[ $row['meta_key'] ] = $row['meta_value']; }
		return 'active' === ( $values['_aish_account_state'] ?? '' ) && ! empty( $values['_aish_email_verified_at'] ) && preg_match( '/^[a-f0-9]{64}$/D', $values['_aish_verified_email_hash'] ?? '' ) && hash_equals( \AISkillsHub\Core\Accounts\Store::emailHash( $user['user_email'] ), $values['_aish_verified_email_hash'] ) && empty( $values['_aish_account_deletion_pending'] ) && empty( $values['_aish_community_erasing'] ) && empty( $values['_aish_contributions_erasing'] ) && empty( $values['_aish_operations_test_data'] );
	}
	/** Login or explicitly successful account interaction only; registration alone is not activity. */
	public function active( int $userId ): void {
		try {
			if ( $userId < 1 || ! Settings::tracking() || ! $this->migrator->isCurrent() || ! $this->views->analyticsEligible() || null === $this->views->analyticsObserver() ) { return; }
			$user = get_userdata( $userId );
			if ( ! $user instanceof \WP_User || user_can( $user, 'manage_options' ) || user_can( $user, 'edit_posts' ) || user_can( $user, Settings::CAPABILITY ) ) { return; }
			$this->locked( function () use ( $userId ): void {
				$options = $this->options();
				$this->db->transaction( function () use ( $userId, $options ): void {
					if ( ! Settings::tracking() || ! $this->views->analyticsEligible() || null === $this->views->analyticsObserver() || ! $this->activeIdentity( $userId ) ) { return; }
					$key = $this->mac( 'active:' . gmdate( 'Y-m-d' ) . ':' . $userId );
					if ( $this->event( 'active_user', 'site', 'all', '', $key ) ) { $this->aggregate( 'active_user', 'site', 'all', '', 1 ); $this->coverage( 'active', $options ); }
				} );
			} );
		} catch ( \Throwable $error ) { $this->health( 'active_failed' ); }
		finally { $this->invalidateCoverage(); }
	}
	/** Fixed codes/day-only health metadata: no exceptions, inputs, cookies or identities. */
	private function health( string $code ): void {
		if ( ! in_array( $code, array( 'render_failed', 'write_failed', 'active_failed', 'term_cap', 'cleanup_failed' ), true ) ) { return; }
		try {
			$state = get_option( self::HEALTH, array() ); if ( ! is_array( $state ) ) { $state = array(); }
			$state[ gmdate( 'Y-m-d' ) ][ $code ] = true; ksort( $state ); $state = array_slice( $state, -31, null, true ); update_option( self::HEALTH, $state, false );
		} catch ( \Throwable $ignored ) {}
	}
	public function cleanup(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) || ! $this->migrator->isCurrent() ) { return; }
		try {
			$this->locked( function (): void {
				$events = $this->db->table( 'metric_events' ); $daily = $this->db->table( 'daily_metrics' ); $options = $this->options();
				$n = $this->db->execute( "DELETE FROM $events WHERE policy_version=%s AND expires_at<%s ORDER BY id LIMIT 200", array( self::POLICY, gmdate( 'Y-m-d H:i:s' ) ) );
				$m = $this->db->execute( "DELETE FROM $daily WHERE policy_version=%s AND ((dimension_type='search_term' AND stat_day<%s) OR stat_day<%s) ORDER BY stat_day LIMIT 200", array( self::POLICY, gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) );
				$rows = $this->db->rows( "SELECT option_name FROM $options WHERE option_name LIKE %s AND option_name REGEXP '^_transient_timeout_aish_search_budget_[a-f0-9]{64}$' AND CAST(option_value AS UNSIGNED)<%d ORDER BY option_id LIMIT 100", array( $this->db->connection()->esc_like( '_transient_timeout_aish_search_budget_' ) . '%', time() ) );
				foreach ( $rows as $r ) { $this->db->execute( "DELETE FROM $options WHERE option_name IN (%s,%s)", array( $r['option_name'], str_replace( '_transient_timeout_', '_transient_', $r['option_name'] ) ) ); }
				if ( ( 200 === $n || 200 === $m || 100 === count( $rows ) ) && ! wp_next_scheduled( self::CLEANUP ) ) { wp_schedule_single_event( time() + 60, self::CLEANUP ); }
			} );
		} catch ( \Throwable $error ) { $this->health( 'cleanup_failed' ); }
	}
}
