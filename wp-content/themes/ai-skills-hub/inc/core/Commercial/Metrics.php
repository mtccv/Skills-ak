<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Discovery\Settings;
use AISkillsHub\Core\Discovery\Views;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Optional sponsorship observations, never natural ranking inputs or a record of purchases. */
final class Metrics {
	public const POLICY = 'commercial-1';
	public const ACTION = 'aish_commercial_metric';
	public const CAPABILITY = 'aish_view_commercial_metrics';
	public const COVERAGE = 'aish_commercial_metric_started_at';
	public const HEALTH = 'aish_commercial_metric_health';
	public const CLEANUP = 'aish_commercial_metric_cleanup';
	private const METRICS = array( 'sponsor_impression', 'sponsor_click', 'sponsor_impression_unique', 'sponsor_click_unique' );
	public function __construct( private Database $db, private Migrator $migrator, private Views $views, private Sponsors $sponsors ) {}
	public function register(): void {
		add_filter( 'aish_sponsor_tracking_attributes', fn( array $attributes, array $entry ): array => $this->attributes( $entry ), 10, 2 );
		add_action( 'aish_sponsor_tracking_controls', array( $this, 'controls' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'aish_community_maintenance', array( $this, 'cleanup' ), 30 );
		add_action( self::CLEANUP, array( $this, 'cleanup' ) );
		add_action( 'switch_theme', static function (): void { wp_clear_scheduled_hook( self::CLEANUP ); } );
	}
	private function mac( string $value ): string { return hash_hmac( 'sha256', self::POLICY . ':' . $this->db->prefix() . ':' . Router::siteUrl() . ':' . $value, wp_salt( 'auth' ) ); }
	private function proof( array $entry ): string {
		$data = array( 'id' => $entry['id'], 'revision' => $entry['revision'], 'placement' => $entry['placement'], 'skill' => (int) ( $entry['skill_id'] ?? 0 ), 'locale' => $entry['locale'], 'issued' => time(), 'request' => bin2hex( random_bytes( 16 ) ) );
		$body = rtrim( strtr( base64_encode( json_encode( $data, JSON_THROW_ON_ERROR ) ), '+/', '-_' ), '=' );
		return $body . '.' . $this->mac( 'proof:' . $body );
	}
	private function decode( string $proof ): array {
		if ( strlen( $proof ) > 1200 || ! preg_match( '/^([a-zA-Z0-9_-]+)\.([a-f0-9]{64})$/D', $proof, $m ) || ! hash_equals( $this->mac( 'proof:' . $m[1] ), $m[2] ) ) { throw new \InvalidArgumentException( 'invalid_proof' ); }
		$data = json_decode( (string) base64_decode( strtr( $m[1], '-_', '+/' ), true ), true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), array( 'id', 'revision', 'placement', 'skill', 'locale', 'issued', 'request' ) ) || ! is_int( $data['id'] ?? null ) || $data['id'] < 1 || ! is_int( $data['revision'] ?? null ) || $data['revision'] < 1 || ! is_int( $data['skill'] ?? null ) || $data['skill'] < 0 || ! in_array( $data['placement'] ?? '', array( 'home_after_search', 'skill_detail_footer' ), true ) || ! in_array( $data['locale'] ?? '', array( 'zh_CN', 'en_US' ), true ) || ! is_int( $data['issued'] ?? null ) || time() - $data['issued'] < 0 || time() - $data['issued'] > 1800 || gmdate( 'Y-m-d', $data['issued'] ) !== gmdate( 'Y-m-d' ) || ! is_string( $data['request'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $data['request'] ) ) { throw new \InvalidArgumentException( 'invalid_proof' ); }
		return $data;
	}
	private function eligible(): bool {
		return Settings::tracking() && $this->migrator->isCurrent() && $this->views->analyticsEligible() && ! current_user_can( Sponsors::CAPABILITY ) && ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'aish_manage_enterprise' );
	}
	private function current( array $data ): array {
		$entry = $this->sponsors->publicEntry( $data['id'], $data['locale'] );
		if ( null === $entry || $entry['revision'] !== $data['revision'] || $entry['placement'] !== $data['placement'] || ( isset( $data['skill'] ) && (int) $entry['skill_id'] !== $data['skill'] ) ) { throw new \InvalidArgumentException( 'display_unavailable' ); }
		return $entry;
	}
	/** Contains no destination URL, observer identity, query text or private sponsor fields. */
	public function attributes( array $entry ): array {
		try {
			if ( ! $this->eligible() ) { return array(); }
			$this->current( $entry );
			return array( 'data-commercial-metric' => '1', 'data-commercial-endpoint' => admin_url( 'admin-ajax.php' ), 'data-commercial-nonce' => wp_create_nonce( self::ACTION ), 'data-commercial-proof' => $this->proof( $entry ), 'data-commercial-locale' => $entry['locale'] );
		} catch ( \Throwable $error ) { return array(); }
	}
	public function controls( array $entry ): void {
		try {
			if ( ! $this->eligible() ) { return; } $this->current( $entry ); $zh = 'zh_CN' === $entry['locale'];
			echo '<div class="mt-3 border-t border-slate-200 pt-2 text-xs text-slate-500"><p>' . esc_html( $zh ? '可选：允许当天赞助展示与点击统计，复用当天随机 Cookie。不会订阅邮件、识别个人或记录购买；无需同意即可使用链接。' : 'Optional daily sponsorship impressions and clicks, using the shared daily random cookie. No email subscription, personal identification or purchase tracking. Links work without consent.' ) . '</p><button type="button" class="mt-2 underline" data-commercial-consent>' . esc_html( $zh ? '允许当天赞助统计' : 'Allow sponsorship measurement today' ) . '</button><button type="button" class="mt-2 underline" data-commercial-withdraw hidden>' . esc_html( $zh ? '停止所有当天统计' : 'Stop all daily measurement' ) . '</button><p role="status" aria-live="polite" data-commercial-status></p></div>';
		} catch ( \Throwable $error ) { /* Optional controls cannot prevent a normal link from rendering. */ }
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
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $this->sameOrigin() || (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 4096 || array_diff( array_keys( $_POST ), array( 'action', 'step', 'nonce', 'proof', 'event' ) ) ) { throw new \InvalidArgumentException( 'invalid_request' ); }
			foreach ( $_POST as $v ) { if ( ! is_string( $v ) || strlen( $v ) > 1200 ) { throw new \InvalidArgumentException( 'invalid_request' ); } }
			$input = wp_unslash( $_POST );
			if ( ! wp_verify_nonce( $input['nonce'] ?? '', self::ACTION ) ) { throw new \InvalidArgumentException( 'invalid_request' ); }
			$step = $input['step'] ?? '';
			if ( 'withdraw' === $step ) { $this->views->withdrawAnalyticsConsent(); $result = array( 'state' => 'stopped' ); }
			else {
				if ( ! in_array( $step, array( 'start', 'consent', 'record' ), true ) || ! $this->eligible() ) { throw new \InvalidArgumentException( 'unavailable' ); }
				$data = $this->decode( $input['proof'] ?? '' ); $this->current( $data );
				$observer = $this->views->commercialObserver();
				if ( 'consent' === $step ) { $observer = $this->views->grantCommercialConsent(); }
				if ( null === $observer ) { $result = array( 'state' => 'consent_required' ); }
				elseif ( 'record' !== $step ) { $result = array( 'state' => 'ready' ); }
				else {
					$event = $input['event'] ?? ''; if ( ! in_array( $event, array( 'impression', 'click' ), true ) || ( 'impression' === $event && time() - $data['issued'] < 1 ) ) { throw new \InvalidArgumentException( 'invalid_event' ); }
					$result = array( 'state' => $this->persist( $data, $event, $observer ) ? 'recorded' : 'already_counted' );
				}
			}
		} catch ( \InvalidArgumentException $error ) { $status = 400; if ( 'measurement_limit' === $error->getMessage() ) { $this->health( 'daily_cap' ); } }
		catch ( \Throwable $error ) { $status = 503; $this->health( 'write_failed' ); }
		if ( null === $result ) { wp_send_json_error( array( 'state' => 'unavailable' ), $status ); }
		wp_send_json_success( $result, $status );
	}
	private function locked( callable $work ): mixed {
		$lock = 'aish_commercial_metrics:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 39 ); $held = false;
		$mode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null; if ( null !== $mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try { $held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s,0)', array( $lock ) ); if ( ! $held ) { throw new \RuntimeException( 'measurement_busy' ); } return $work(); }
		finally { if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $ignored ) {} } if ( null !== $mode ) { mysqli_report( $mode ); } }
	}
	private function options(): string {
		$table = $this->db->connection()->options;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) || 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) ) ) ) { throw new \RuntimeException( 'measurement_storage_unavailable' ); }
		return $table;
	}
	private function event( string $metric, string $key, string $locale, string $dedupe ): bool {
		$events = $this->db->table( 'metric_events' ); $day = gmdate( 'Y-m-d' );
		if ( $this->db->row( "SELECT id FROM $events WHERE event_day=%s AND metric_name=%s AND dedupe_key=%s", array( $day, $metric, $dedupe ) ) ) { return false; }
		$this->db->insert( 'metric_events', array( 'metric_name' => $metric, 'dimension_type' => 'sponsor', 'dimension_key' => $key, 'locale' => $locale, 'event_day' => $day, 'dedupe_key' => $dedupe, 'policy_version' => self::POLICY, 'occurred_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) );
		return true;
	}
	private function aggregate( string $metric, string $key, string $locale ): void {
		$daily = $this->db->table( 'daily_metrics' );
		$this->db->execute( "INSERT INTO $daily (stat_day,metric_name,dimension_type,dimension_key,locale,metric_count,policy_version,updated_at) VALUES (%s,%s,'sponsor',%s,%s,1,%s,%s) ON DUPLICATE KEY UPDATE metric_count=metric_count+1,updated_at=VALUES(updated_at)", array( gmdate( 'Y-m-d' ), $metric, $key, $locale, self::POLICY, gmdate( 'Y-m-d H:i:s' ) ) );
	}
	/** Response observations and daily observer dedupe are distinct, committed with bounded quotas. */
	private function persist( array $data, string $event, string $observer ): bool {
		try { return $this->locked( function () use ( $data, $event, $observer ): bool {
			$options = $this->options();
			return $this->db->transaction( function () use ( $data, $event, $observer, $options ): bool {
				$day = gmdate( 'Y-m-d' ); $locale = $data['locale']; $metric = 'sponsor_' . $event; $key = $data['id'] . ':' . $data['placement']; $salt = $this->mac( 'daily:' . $day );
				$observation = hash_hmac( 'sha256', 'response:' . $observer . ':' . $data['request'] . ':' . $key . ':' . $locale, $salt );
				$unique = hash_hmac( 'sha256', 'daily:' . $observer . ':' . $key . ':' . $locale, $salt );
				$budget = '_transient_aish_commercial_budget_' . hash_hmac( 'sha256', 'budget:' . $observer, $salt );
				$count = $this->db->value( "SELECT option_value FROM $options WHERE option_name=%s FOR UPDATE", array( $budget ) );
				if ( null !== $count && ( ! is_scalar( $count ) || ! preg_match( '/^(0|[1-9][0-9]{0,2})$/D', (string) $count ) ) ) { throw new \RuntimeException( 'measurement_budget_unavailable' ); }
				// Acquire authoritative locks before any consistent read establishes a repeatable-read snapshot.
				$this->db->row( "SELECT option_value FROM $options WHERE option_name=%s FOR UPDATE", array( Sponsors::OPTION ) );
				if ( $data['skill'] && ! $this->db->row( 'SELECT id FROM ' . $this->db->table( 'skills' ) . ' WHERE id=%d FOR UPDATE', array( $data['skill'] ) ) ) { throw new \InvalidArgumentException( 'display_unavailable' ); }
				if ( ! $this->eligible() || ! hash_equals( $observer, $this->views->commercialObserver() ?? '' ) ) { throw new \InvalidArgumentException( 'measurement_unavailable' ); }
				$this->current( $data );
				if ( ! $this->event( $metric, $key, $locale, $observation ) ) { return false; }
				$daily = $this->db->table( 'daily_metrics' );
				$total = (int) $this->db->value( "SELECT COALESCE(SUM(metric_count),0) FROM $daily WHERE stat_day=%s AND policy_version=%s AND metric_name IN ('sponsor_impression','sponsor_click') AND dimension_type='sponsor'", array( $day, self::POLICY ) );
				if ( (int) $count >= 100 || $total >= 10000 ) { throw new \InvalidArgumentException( 'measurement_limit' ); }
				$this->aggregate( $metric, $key, $locale );
				if ( $this->event( $metric . '_unique', $key, $locale, $unique ) ) { $this->aggregate( $metric . '_unique', $key, $locale ); }
				foreach ( array( $budget => (string) ( (int) $count + 1 ), str_replace( '_transient_', '_transient_timeout_', $budget ) => (string) strtotime( 'tomorrow UTC' ) ) as $name => $value ) { $this->db->execute( "INSERT INTO $options (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", array( $name, $value ) ); }
				$this->db->execute( "INSERT INTO $options (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)", array( self::COVERAGE, gmdate( 'Y-m-d H:i:s' ) ) );
				return true;
			} );
		} ); } finally { wp_cache_delete( self::COVERAGE, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
	}
	private function health( string $code ): void {
		if ( ! in_array( $code, array( 'write_failed', 'cleanup_failed', 'daily_cap' ), true ) ) { return; }
		try { $state = get_option( self::HEALTH, array() ); if ( ! is_array( $state ) ) { $state = array(); } $state[ gmdate( 'Y-m-d' ) ][ $code ] = true; ksort( $state ); update_option( self::HEALTH, array_slice( $state, -31, null, true ), false ); } catch ( \Throwable $ignored ) {}
	}
	public function summary( int $days, string $locale ): array {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'forbidden' ); }
		if ( ! in_array( $days, array( 7, 30 ), true ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_window' ); }
		$start = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days midnight UTC' ) ); $end = gmdate( 'Y-m-d' ); $coverage = get_option( self::COVERAGE, null );
		if ( ! is_string( $coverage ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $coverage ) ) { $coverage = null; }
		$rows = $this->db->rows( 'SELECT dimension_key,metric_name,SUM(metric_count) AS total FROM ' . $this->db->table( 'daily_metrics' ) . " WHERE dimension_type='sponsor' AND policy_version=%s AND locale=%s AND stat_day>=%s AND stat_day<=%s AND metric_name IN ('sponsor_impression','sponsor_click','sponsor_impression_unique','sponsor_click_unique') GROUP BY dimension_key,metric_name ORDER BY dimension_key,metric_name LIMIT 200", array( self::POLICY, $locale, $start, $end ) );
		$totals = array_fill_keys( self::METRICS, 0 ); $valid = array();
		$all = $this->db->rows( 'SELECT metric_name,SUM(metric_count) AS total FROM ' . $this->db->table( 'daily_metrics' ) . " WHERE dimension_type='sponsor' AND dimension_key REGEXP '^[1-9][0-9]*:(home_after_search|skill_detail_footer)$' AND policy_version=%s AND locale=%s AND stat_day>=%s AND stat_day<=%s AND metric_name IN ('sponsor_impression','sponsor_click','sponsor_impression_unique','sponsor_click_unique') GROUP BY metric_name", array( self::POLICY, $locale, $start, $end ) );
		foreach ( $all as $row ) { if ( isset( $totals[ $row['metric_name'] ] ) ) { $totals[ $row['metric_name'] ] = (int) $row['total']; } }
		foreach ( $rows as $row ) { if ( ! preg_match( '/^[1-9][0-9]*:(home_after_search|skill_detail_footer)$/D', $row['dimension_key'] ) || ! isset( $totals[ $row['metric_name'] ] ) ) { continue; } $row['total'] = (int) $row['total']; $valid[] = $row; }
		$health = get_option( self::HEALTH, array() ); $partial = null === $coverage || $coverage > $start . ' 00:00:00';
		foreach ( is_array( $health ) ? $health : array() as $day => $codes ) { if ( is_string( $day ) && $day >= $start && $day <= $end ) { $partial = true; } }
		return array( 'state' => null === $coverage ? ( Settings::tracking() ? 'not_collected' : 'disabled' ) : 'available', 'tracking_enabled' => Settings::tracking(), 'start' => $start . ' 00:00:00', 'end' => gmdate( 'Y-m-d H:i:s' ), 'locale' => $locale, 'coverage' => $coverage, 'partial' => $partial, 'totals' => $totals, 'rows' => $valid );
	}
	public function cleanup(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) || ! $this->migrator->isCurrent() ) { return; }
		try { $this->locked( function (): void {
			$options = $this->options(); $events = $this->db->table( 'metric_events' ); $daily = $this->db->table( 'daily_metrics' );
			$n = $this->db->execute( "DELETE FROM $events WHERE policy_version=%s AND expires_at<%s ORDER BY id LIMIT 200", array( self::POLICY, gmdate( 'Y-m-d H:i:s' ) ) );
			$m = $this->db->execute( "DELETE FROM $daily WHERE policy_version=%s AND stat_day<%s ORDER BY stat_day LIMIT 200", array( self::POLICY, gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) );
			$rows = $this->db->rows( "SELECT option_name FROM $options WHERE option_name LIKE %s AND option_name REGEXP '^_transient_timeout_aish_commercial_budget_[a-f0-9]{64}$' AND CAST(option_value AS UNSIGNED)<%d ORDER BY option_id LIMIT 100", array( $this->db->connection()->esc_like( '_transient_timeout_aish_commercial_budget_' ) . '%', time() ) );
			foreach ( $rows as $r ) { $this->db->execute( "DELETE FROM $options WHERE option_name IN (%s,%s)", array( $r['option_name'], str_replace( '_transient_timeout_', '_transient_', $r['option_name'] ) ) ); }
			if ( ( 200 === $n || 200 === $m || 100 === count( $rows ) ) && ! wp_next_scheduled( self::CLEANUP ) ) { wp_schedule_single_event( time() + 60, self::CLEANUP ); }
		} ); } catch ( \Throwable $error ) { $this->health( 'cleanup_failed' ); }
	}
}
