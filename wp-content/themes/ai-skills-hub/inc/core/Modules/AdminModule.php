<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Admin\Actions;
use AISkillsHub\Core\Admin\Management;
use AISkillsHub\Core\Admin\Pages;

defined( 'ABSPATH' ) || exit;

final class AdminModule implements Module {
	public function name(): string { return 'admin'; }
	public function dependencies(): array { return array( 'database', 'language', 'catalog' ); }
	public function register( Core $core ): void {
		$management = new Management( $core->get( 'database' ), $core->migrator(), $core->catalog() );
		$core->set( 'management', $management );
		$actions = new Actions( $management );
		$pages = new Pages( $management, $actions );
		$actions->register();
		add_action( 'admin_init', static function (): void {
			if ( ! current_user_can( 'manage_options' ) || '1' === get_option( 'aish_admin_caps_version' ) ) { return; }
			$role = get_role( 'administrator' );
			if ( ! $role ) { return; }
			foreach ( Pages::CAPABILITIES as $cap ) { $role->add_cap( $cap ); }
			update_option( 'aish_admin_caps_version', '1', false );
		}, 5 );
		add_action( 'admin_menu', array( $pages, 'menu' ) );
		add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
			if ( ! str_contains( $hook, 'aish' ) ) { return; }
			wp_enqueue_style( 'aish-admin', get_template_directory_uri() . '/assets/admin.css', array(), '0.3.0' );
			wp_enqueue_script( 'aish-admin', get_template_directory_uri() . '/assets/admin.js', array(), '0.3.0', true );
		} );
	}
}
