<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

final class DatabaseModule implements Module {
	public function name(): string {
		return 'database';
	}

	public function dependencies(): array {
		return array();
	}

	public function register( Core $core ): void {
		$db = new Database( $GLOBALS['wpdb'] );
		$core->set( 'database', $db );
		$core->set( 'migrator', new Migrator( $db ) );
		$upgrade = static function () use ( $core ): void {
			$cli = defined( 'WP_CLI' ) && WP_CLI;
			if ( ! $cli && ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( $core->migrator()->isCurrent() ) {
				return;
			}
			$result = $core->migrator()->migrate();
			if ( is_wp_error( $result ) ) {
				update_option( 'aish_schema_error', $result->get_error_code(), false );
			} else {
				delete_option( 'aish_schema_error' );
			}
		};
		add_action( 'after_switch_theme', $upgrade );
		add_action( 'admin_init', $upgrade );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'after_setup_theme', $upgrade, 20 );
		}
		add_action( 'admin_notices', static function (): void {
			if ( current_user_can( 'manage_options' ) && get_option( 'aish_schema_error' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Skills Hub database upgrade is pending. Check database availability and reload this admin page to retry.', 'ai-skills-hub' ) . '</p></div>';
			}
		} );
	}
}
