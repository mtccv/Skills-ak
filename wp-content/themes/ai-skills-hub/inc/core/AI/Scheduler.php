<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

final class Scheduler {
	public const HOOK = 'aish_ai_tick';
	public function __construct( private Database $db, private Migrator $migrator, private Settings $settings, private Worker $worker ) {}
	public function register(): void {
		add_filter( 'cron_schedules', static function ( array $schedules ): array { $schedules['aish_ai_minute'] = array( 'interval' => 60, 'display' => 'AI Skills Hub generation queue' ); return $schedules; } );
		add_action( 'admin_init', array( $this, 'ensure' ), 31 ); add_action( 'aish_ai_wake', array( $this, 'ensure' ) ); add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'switch_theme', static function (): void { wp_clear_scheduled_hook( self::HOOK ); } );
	}
	public function ensure(): void {
		if ( ! $this->migrator->isCurrent() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'aish_generate_content' ) && ! current_user_can( 'aish_manage_ai_settings' ) ) ) { return; }
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 60, 'aish_ai_minute', self::HOOK ); }
	}
	public function tick(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) || ! $this->migrator->isCurrent() || $this->settings->paused() ) { return; }
		$name = 'aish_ai:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 48 ); $held = false;
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $name ) );
			if ( $held ) { $this->worker->run(); }
		} catch ( \Throwable $error ) { /* No provider/SQL secrets or false completion in Cron output. */ }
		finally { if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $name ) ); } catch ( \Throwable $error ) {} } }
	}
}
