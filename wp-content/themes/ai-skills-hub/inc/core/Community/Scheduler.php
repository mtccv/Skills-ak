<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** Bounded retention only; no analysis dashboard, ranking job or external requests. */
final class Scheduler {
	public const HOOK = 'aish_community_maintenance';
	public function __construct( private Database $db, private Migrator $migrator, private Metrics $metrics, private Privacy $privacy ) {}
	public function register(): void {
		add_action( 'admin_init', array( $this, 'ensure' ), 33 );
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'switch_theme', static function (): void { wp_clear_scheduled_hook( self::HOOK ); } );
	}
	public function ensure(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->migrator->isCurrent() ) { return; }
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 300, 'hourly', self::HOOK ); }
	}
	public function tick(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) || ! $this->migrator->isCurrent() ) { return; }
		$lock = 'aish_community:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 48 );
		$mode = class_exists( '\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null; $held = false;
		if ( null !== $mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) );
			if ( ! $held ) { return; }
			$this->metrics->cleanup(); $this->privacy->retention();
		} catch ( \Throwable $error ) { /* Retry bounded work next tick; no raw SQL or personal data in logs. */ }
		finally {
			if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $ignored ) {} }
			if ( null !== $mode ) { mysqli_report( $mode ); }
		}
	}
}
