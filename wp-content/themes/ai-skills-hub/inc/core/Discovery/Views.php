<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Discovery;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Consenting, visible detail observations, not unique people or a safety certification. */
final class Views {
	public const POLICY = 'discovery-1';
	public const COVERAGE_OPTION = 'aish_discovery_views_started_at';
	public const RETENTION_HOOK = 'aish_discovery_retention';
	private const ACTION = 'aish_discovery_view';
	private const MIN_SECONDS = 8;
	private const MAX_SECONDS = 1800;
	private const DAILY_LIMIT = 100;

	public function __construct( private Database $db, private Migrator $migrator, private PublicCatalog $catalog ) {}

	public function register(): void {
		add_action( 'aish_skill_actions', array( $this, 'render' ), 50, 2 );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'aish_community_maintenance', array( $this, 'cleanup' ), 20 );
		add_action( self::RETENTION_HOOK, array( $this, 'cleanup' ) );
		add_action( 'switch_theme', static function (): void { wp_clear_scheduled_hook( self::RETENTION_HOOK ); } );
	}

	/** Bounded catch-up: native transient GC can skip the DB with persistent object caching. */
	public function cleanup(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) || ! $this->migrator->isCurrent() ) { return; }
		$lock = 'aish_view_gc:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 48 );
		$mode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		$held = false;
		if ( null !== $mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) );
			if ( ! $held ) { return; }
			$options = $this->db->connection()->options;
			if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $options ) ) { return; }
			$prefix = $this->db->connection()->esc_like( '_transient_timeout_aish_view_budget_' ) . '%';
			$rows = $this->db->rows( "SELECT option_name FROM $options WHERE option_name LIKE %s AND option_name REGEXP '^_transient_timeout_aish_view_budget_[a-f0-9]{64}$' AND CAST(option_value AS UNSIGNED)<%d ORDER BY option_id LIMIT 100", array( $prefix, time() ) );
			foreach ( $rows as $row ) {
				if ( ! preg_match( '/^_transient_timeout_(aish_view_budget_[a-f0-9]{64})$/D', $row['option_name'], $match ) ) { continue; }
				$this->db->execute( "DELETE FROM $options WHERE option_name IN (%s,%s)", array( $row['option_name'], '_transient_' . $match[1] ) );
			}
			$deleted = $this->db->execute( 'DELETE FROM ' . $this->db->table( 'metric_events' ) . " WHERE metric_name='skill_view' AND expires_at<%s ORDER BY id LIMIT 100", array( gmdate( 'Y-m-d H:i:s' ) ) );
			// A full batch may have more work. At most one continuation is queued,
			// under the same lock; the hourly hook remains recovery after failure.
			if ( ( 100 === count( $rows ) || 100 === $deleted ) && ! wp_next_scheduled( self::RETENTION_HOOK ) ) { wp_schedule_single_event( time() + 60, self::RETENTION_HOOK ); }
		} catch ( \Throwable $error ) { /* Retry idempotent work on the next scheduled/hourly maintenance tick. */ }
		finally {
			if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $ignored ) {} }
			if ( null !== $mode ) { mysqli_report( $mode ); }
		}
	}

	/** This hook is rendered on public details only. The endpoint independently checks publication. */
	public function render( array $skill, string $locale ): void {
		try {
			$id = $this->id( $skill['id'] ?? 0 );
			if ( ! Settings::tracking() || ! $this->eligible( $id ) || ! $this->migrator->isCurrent() ) { return; }
			$this->locale( $locale );
			$zh = 'zh_CN' === $locale;
			$help = $zh ? '可自愿允许当天匿名去重浏览统计：仅在页面可见停留 8 秒后计数。不保存 IP、账户 ID 或跨日浏览历史，可随时停止。' : 'Optional anonymous, daily-deduplicated views: counted after 8 visible seconds. No IP, account ID or cross-day history is stored. You can stop at any time.';
			$allow = $zh ? '允许当天匿名浏览统计' : 'Allow anonymous views today';
			$stop = $zh ? '停止浏览统计' : 'Stop view measurement';
			echo '<div class="mt-4 border-t border-slate-200 pt-3 text-xs text-slate-500" data-discovery-view data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::ACTION ) ) . '" data-skill="' . esc_attr( (string) $id ) . '" data-locale="' . esc_attr( $locale ) . '" data-ticket="' . esc_attr( $this->ticket( 'page', $id, $locale ) ) . '">';
			echo '<p>' . esc_html( $help ) . '</p><button type="button" class="mt-2 underline" data-discovery-consent>' . esc_html( $allow ) . '</button><button type="button" class="mt-2 underline" data-discovery-withdraw hidden>' . esc_html( $stop ) . '</button><p class="mt-2" role="status" aria-live="polite" data-discovery-status></p><noscript>' . esc_html( $zh ? '未启用 JavaScript，不记录浏览。' : 'Views are not recorded without JavaScript.' ) . '</noscript></div>';
		} catch ( \Throwable $error ) { /* Optional measurement must not break the public page. */ }
	}

	/** Fixed actions and dimensions; request bodies can never choose metrics or counts. */
	public function handle(): void {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, noarchive' );
		$result = null;
		$status = 200;
		try {
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $this->sameOrigin() || (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 4096 ) { throw new \InvalidArgumentException( 'request_rejected' ); }
			$allowed = array( 'action', 'step', 'nonce', 'skill', 'locale', 'ticket' );
			if ( array_diff( array_keys( $_POST ), $allowed ) ) { throw new \InvalidArgumentException( 'request_rejected' ); }
			foreach ( $_POST as $value ) { if ( ! is_string( $value ) || strlen( $value ) > 512 ) { throw new \InvalidArgumentException( 'request_rejected' ); } }
			$input = wp_unslash( $_POST );
			if ( ! wp_verify_nonce( $input['nonce'] ?? '', self::ACTION ) ) { throw new \InvalidArgumentException( 'request_rejected' ); }
			$step = $input['step'] ?? '';
			if ( 'withdraw' === $step ) {
				$this->writeCookie( '', time() - 3600 );
				$result = array( 'state' => 'stopped' );
			} else {
				if ( ! in_array( $step, array( 'consent', 'start', 'record' ), true ) ) { throw new \InvalidArgumentException( 'request_rejected' ); }
				$id = $this->id( $input['skill'] ?? '' );
				$locale = $this->locale( $input['locale'] ?? '' );
				if ( ! Settings::tracking() || ! $this->eligible( $id ) || ! $this->migrator->isCurrent() ) { throw new \InvalidArgumentException( 'measurement_unavailable' ); }
				$observer = $this->observer( $_COOKIE[ $this->cookieName() ] ?? '' );
				if ( 'start' === $step && null === $observer ) {
					$result = array( 'state' => 'consent_required' );
				} elseif ( 'record' === $step ) {
					if ( null === $observer || ! $this->validTicket( $input['ticket'] ?? '', 'view', $id, $locale, $observer ) ) { throw new \InvalidArgumentException( 'request_rejected' ); }
					$result = array( 'state' => $this->persist( $id, $locale, $observer ) ? 'recorded' : 'already_counted' );
				} else {
					if ( ! $this->validTicket( $input['ticket'] ?? '', 'page', $id, $locale ) || ! $this->publicSkill( $id, $locale ) ) { throw new \InvalidArgumentException( 'request_rejected' ); }
					if ( 'consent' === $step && null === $observer ) {
						$observer = bin2hex( random_bytes( 32 ) );
						$body = gmdate( 'Y-m-d' ) . '.' . $observer;
						$this->writeCookie( $body . '.' . $this->mac( 'cookie:' . $body ), strtotime( 'tomorrow UTC' ) );
					}
					$result = array( 'state' => 'ready', 'ticket' => $this->ticket( 'view', $id, $locale, $observer ), 'minimum_seconds' => self::MIN_SECONDS );
				}
			}
		} catch ( \InvalidArgumentException $error ) {
			$status = 400;
		} catch ( \Throwable $error ) {
			$status = 503;
			// No exception messages, input, cookies, tokens or browsing trails in logs.
		}
		if ( null === $result ) { wp_send_json_error( array( 'state' => 'unavailable' ), $status ); }
		wp_send_json_success( $result, $status );
	}

	private function id( mixed $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) { throw new \InvalidArgumentException( 'invalid_skill' ); }
		$id = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $id || $id < 1 ) { throw new \InvalidArgumentException( 'invalid_skill' ); }
		return $id;
	}

	private function locale( string $locale ): string {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_locale' ); }
		return $locale;
	}

	private function sameOrigin(): bool {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ( ! is_string( $origin ) || strlen( $origin ) > 512 || '' === $origin ) { return false; }
		$parts = wp_parse_url( $origin );
		$site = wp_parse_url( Router::siteUrl() );
		if ( ! is_array( $parts ) || ! is_array( $site ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! empty( $parts['path'] ) ) { return false; }
		return in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ( $parts['scheme'] ?? '' ) === ( $site['scheme'] ?? '' ) && strtolower( $parts['host'] ?? '' ) === strtolower( $site['host'] ?? '' ) && ( $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 ) ) === ( $site['port'] ?? ( 'https' === ( $site['scheme'] ?? '' ) ? 443 : 80 ) );
	}

	private function eligible( int $skillId ): bool {
		if ( '1' === ( $_SERVER['HTTP_SEC_GPC'] ?? '' ) || '1' === ( $_SERVER['HTTP_DNT'] ?? '' ) ) { return false; }
		if ( ( defined( 'AISH_DISCOVERY_TEST_DATA' ) && AISH_DISCOVERY_TEST_DATA ) || current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' ) || current_user_can( Settings::CAPABILITY ) || current_user_can( 'aish_manage_skills' ) ) { return false; }
		$agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		if ( ! is_string( $agent ) || '' === $agent || strlen( $agent ) > 512 || preg_match( '/bot|crawl|spider|slurp|headless|curl|wget|python|scrapy|httpclient|lighthouse|pagespeed/i', $agent ) ) { return false; }
		try { if ( in_array( $skillId, Query::excludedSkillIds(), true ) ) { return false; } }
		catch ( \Throwable $error ) { return false; }
		// This optional server filter only blocks measurement. Use the shared ID
		// option when a fixture must also be excluded from public SQL rankings.
		if ( apply_filters( 'aish_discovery_skill_is_test', false, $skillId ) ) { return false; }
		if ( is_user_logged_in() ) {
			$id = get_current_user_id();
			if ( get_user_meta( $id, '_aish_account_deletion_pending', true ) || get_user_meta( $id, '_aish_community_erasing', true ) || get_user_meta( $id, '_aish_contributions_erasing', true ) || 'active' !== ( get_user_meta( $id, '_aish_account_state', true ) ?: 'active' ) ) { return false; }
		}
		return true;
	}

	private function cookieName(): string { return ( is_ssl() ? '__Host-' : '' ) . 'aish_discovery_observer'; }

	private function writeCookie( string $value, int $expires ): void {
		if ( headers_sent() || ! setcookie( $this->cookieName(), $value, array( 'expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ) ) { throw new \RuntimeException( 'cookie_unavailable' ); }
	}

	private function mac( string $value ): string {
		return hash_hmac( 'sha256', $this->db->prefix() . ':' . Router::siteUrl() . ':' . self::POLICY . ':' . $value, wp_salt( 'auth' ) );
	}

	private function observer( mixed $cookie ): ?string {
		if ( ! is_string( $cookie ) || ! preg_match( '/^(\d{4}-\d{2}-\d{2})\.([a-f0-9]{64})\.([a-f0-9]{64})$/D', $cookie, $matches ) || gmdate( 'Y-m-d' ) !== $matches[1] || ! hash_equals( $this->mac( 'cookie:' . $matches[1] . '.' . $matches[2] ), $matches[3] ) ) { return null; }
		return $matches[2];
	}

	private function ticket( string $kind, int $id, string $locale, string $observer = '', ?int $now = null ): string {
		$now ??= time();
		return $now . '.' . $this->mac( $kind . ':' . gmdate( 'Y-m-d', $now ) . ':' . $id . ':' . $locale . ':' . $now . ':' . $observer );
	}

	private function validTicket( string $ticket, string $kind, int $id, string $locale, string $observer = '' ): bool {
		if ( ! preg_match( '/^([0-9]{10})\.([a-f0-9]{64})$/D', $ticket, $matches ) ) { return false; }
		$issued = (int) $matches[1]; $age = time() - $issued;
		if ( $age < ( 'view' === $kind ? self::MIN_SECONDS : 0 ) || $age > self::MAX_SECONDS || gmdate( 'Y-m-d', $issued ) !== gmdate( 'Y-m-d' ) ) { return false; }
		return hash_equals( $this->ticket( $kind, $id, $locale, $observer, $issued ), $ticket );
	}

	/** No request memo: rechecks the current-language gate after the Skill row is locked. */
	private function publicSkill( int $id, string $locale ): bool {
		$sql = str_replace( '%', '%%', $this->catalog->matchingIdsSql( $locale, array() ) );
		return null !== $this->db->row( "SELECT eligible.id FROM ($sql) eligible WHERE eligible.id=%d LIMIT 1", array( $id ) );
	}

	/** One budget row lock serializes retries/tabs; event, daily total and budget commit together. */
	private function persist( int $id, string $locale, string $observer ): bool {
		$day = gmdate( 'Y-m-d' ); $now = gmdate( 'Y-m-d H:i:s' );
		$dailySalt = $this->mac( 'daily:' . $day );
		$identity = hash_hmac( 'sha256', $observer, $dailySalt );
		$dedupe = hash_hmac( 'sha256', $id . ':' . $observer, $dailySalt );
		$budget = 'aish_view_budget_' . $identity;
		$options = $this->db->connection()->options;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $options ) || 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $options ) ) ) ) { throw new \RuntimeException( 'measurement_storage_unavailable' ); }
		try {
			return $this->db->transaction( function () use ( $id, $locale, $day, $now, $dedupe, $budget, $options ): bool {
				$this->db->execute( "INSERT INTO $options (option_name,option_value,autoload) VALUES (%s,'0','no') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)", array( '_transient_' . $budget ) );
				$count = $this->db->value( "SELECT option_value FROM $options WHERE option_name=%s FOR UPDATE", array( '_transient_' . $budget ) );
				if ( ! is_scalar( $count ) || ! preg_match( '/^(0|[1-9][0-9]{0,2})$/D', (string) $count ) ) { throw new \RuntimeException( 'measurement_budget_unavailable' ); }
				if ( ! $this->db->row( 'SELECT id FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d FOR UPDATE', array( $id ) ) || ! $this->publicSkill( $id, $locale ) || ! Settings::tracking() || ! $this->eligible( $id ) ) { throw new \InvalidArgumentException( 'measurement_unavailable' ); }
				$events = $this->db->table( 'metric_events' );
				if ( $this->db->row( "SELECT id FROM $events WHERE event_day=%s AND metric_name='skill_view' AND dedupe_key=%s", array( $day, $dedupe ) ) ) { return false; }
				if ( (int) $count >= self::DAILY_LIMIT ) { throw new \InvalidArgumentException( 'measurement_limit' ); }
				$this->db->insert( 'metric_events', array( 'metric_name' => 'skill_view', 'dimension_type' => 'skill', 'dimension_key' => (string) $id, 'locale' => '', 'event_day' => $day, 'dedupe_key' => $dedupe, 'policy_version' => self::POLICY, 'occurred_at' => $now, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) );
				$daily = $this->db->table( 'daily_metrics' );
				$this->db->execute( "INSERT INTO $daily (stat_day,metric_name,dimension_type,dimension_key,locale,metric_count,policy_version,updated_at) VALUES (%s,'skill_view','skill',%s,'',1,%s,%s) ON DUPLICATE KEY UPDATE metric_count=metric_count+1,updated_at=VALUES(updated_at)", array( $day, (string) $id, self::POLICY, $now ) );
				$this->db->execute( "UPDATE $options SET option_value=%s WHERE option_name=%s", array( (string) ( (int) $count + 1 ), '_transient_' . $budget ) );
				$this->db->execute( "INSERT INTO $options (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", array( '_transient_timeout_' . $budget, (string) strtotime( 'tomorrow UTC' ) ) );
				$this->db->execute( "INSERT INTO $options (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)", array( self::COVERAGE_OPTION, $now ) );
				return true;
			} );
		} finally { wp_cache_delete( self::COVERAGE_OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
	}
}
