<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Discovery\Admin;
use AISkillsHub\Core\Discovery\Frontend;
use AISkillsHub\Core\Discovery\Management;
use AISkillsHub\Core\Discovery\Query;
use AISkillsHub\Core\Discovery\Settings;
use AISkillsHub\Core\Discovery\Views;

defined( 'ABSPATH' ) || exit;

/** Projections reuse Community facts and the one current-language publication gate. */
final class DiscoveryModule implements Module {
	public function name(): string { return 'discovery'; }
	public function dependencies(): array { return array( 'community', 'contributions', 'frontend', 'admin' ); }
	public function register( Core $core ): void {
		$db = $core->get( 'database' ); $migrator = $core->migrator(); $catalog = $core->get( 'public_catalog' );
		$query = new Query( $db, $migrator, $catalog );
		$management = new Management( $db, $migrator ); $settings = new Settings( $db, $migrator );
		$views = new Views( $db, $migrator, $catalog );
		$frontend = new Frontend( $query, $catalog, $core->get( 'editorial' ) );
		$admin = new Admin( $management, $settings, $db );
		foreach ( array( 'discovery_query' => $query, 'discovery_management' => $management, 'discovery_settings' => $settings, 'discovery_views' => $views, 'discovery_frontend' => $frontend, 'discovery_admin' => $admin ) as $key => $service ) { $core->set( $key, $service ); }
		$core->get( 'sitemap' )->setDiscovery( $query );
		$views->register(); $frontend->register(); $admin->register();
		add_action( 'admin_init', static function () use ( $migrator ): void {
			if ( ! current_user_can( 'manage_options' ) || ! $migrator->isCurrent() || '1' === get_option( 'aish_discovery_caps_version' ) ) { return; }
			$role = get_role( 'administrator' ); if ( ! $role ) { return; }
			$role->add_cap( Admin::CAPABILITY ); update_option( 'aish_discovery_caps_version', '1', false );
			wp_get_current_user()->get_role_caps();
		}, 15 );
		add_action( 'aish_discovery_admin_landing', static fn() => $admin->screen() );
	}
}
