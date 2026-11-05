<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** WordPress Cron is available without installing Action Scheduler. No scans run on page hooks. */
final class Scheduler {
	public const HOOK = 'aish_import_tick';
	public function __construct( private Database $db, private Migrator $migrator, private ImportStore $store, private Worker $worker ) {}

	public function register(): void {
		add_filter( 'cron_schedules', static function ( array $schedules ): array {
			$schedules['aish_import_minute'] = array( 'interval' => 60, 'display' => 'AI Skills Hub import queue' ); return $schedules;
		} );
		add_action( 'admin_init', array( $this, 'ensure' ), 30 );
		add_action( 'aish_import_wake', array( $this, 'ensure' ) );
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'switch_theme', static function (): void { wp_clear_scheduled_hook( self::HOOK ); } );
	}

	public function ensure(): void {
		if ( ! $this->migrator->isCurrent() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'aish_manage_repositories' ) ) ) { return; }
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 60, 'aish_import_minute', self::HOOK ); }
	}

	public function tick(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) || ! $this->migrator->isCurrent() ) { return; }
		$lock = 'aish_worker:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 48 );
		// The worker lock belongs to this connection. Do not let wpdb reconnect
		// and continue after losing it, including native rate-limit transient reads.
		$driver_mode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $driver_mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		$held = false;
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) );
			if ( ! $held ) { return; }
			// Scheduling only: at most twenty repositories are considered, and one step runs globally.
			foreach ( $this->store->dueRepositories( 20 ) as $repository ) {
				try { $this->store->enqueue( (int) $repository['id'], 'sync', true ); }
				catch ( \DomainException $error ) { /* A concurrently disabled repository must not starve existing queued work. */ }
			}
			$this->worker->run();
		} catch ( \Throwable $error ) {
			// Do not send SQL, credentials, response bodies or a false-success notice to Cron output.
		} finally {
			if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $error ) {} }
			if ( null !== $driver_mode ) { mysqli_report( $driver_mode ); }
		}
	}
}
