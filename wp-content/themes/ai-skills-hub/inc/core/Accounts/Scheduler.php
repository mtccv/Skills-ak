<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** Account queue only. A page may arrange a Cron event, but never sends a queued message. */
final class Scheduler {
	public const HOOK = 'aish_account_mail_tick';
	public function __construct( private Database $db, private Migrator $migrator, private MailWorker $worker, private Store $store ) {}

	public function register(): void {
		add_filter( 'cron_schedules', static function ( array $schedules ): array {
			$schedules['aish_accounts_minute'] = array( 'interval' => 60, 'display' => 'AI Skills Hub account mail' ); return $schedules;
		} );
		add_action( 'admin_init', array( $this, 'ensure' ), 32 );
		add_action( 'aish_account_mail_wake', array( $this, 'ensure' ) );
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'switch_theme', static function (): void { wp_clear_scheduled_hook( self::HOOK ); } );
	}

	public function ensure(): void {
		if ( ! $this->migrator->isCurrent() ) { return; }
		// Anonymous registration may schedule this fixed hook after its validated write.
		// No user-controlled arguments, URLs, task kind or transport configuration enter Cron.
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 60, 'aish_accounts_minute', self::HOOK ); }
	}

	public function tick(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) || ! $this->migrator->isCurrent() ) { return; }
		$lock = 'aish_mail:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 48 );
		$driverMode = class_exists( '\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null; $held = false;
		if ( null !== $driverMode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) );
			if ( ! $held ) { return; }
			$this->worker->run();
			$this->store->cleanup();
			do_action( 'aish_account_cleanup_tick' );
		} catch ( \Throwable $error ) { /* Fixed state codes only; no sensitive Cron exception output. */ }
		finally {
			if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $ignored ) {} }
			if ( null !== $driverMode ) { mysqli_report( $driverMode ); }
		}
	}
}
