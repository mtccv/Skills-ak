<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Commercial\{Entitlements, Sponsors, SponsorAdmin, SponsorFrontend, Bundles, BundleAdmin, BundleFrontend, Enterprise, EnterpriseAdmin, EnterpriseFrontend, Metrics, MetricsAdmin};
use AISkillsHub\Core\Frontend\Forms;

defined( 'ABSPATH' ) || exit;

/** Optional manual displays over existing free accounts, content, Inbox and event storage. */
final class CommercialModule implements Module {
	public function name(): string { return 'commercial'; }
	public function dependencies(): array { return array( 'operations', 'discovery', 'accounts', 'contributions', 'frontend', 'admin' ); }
	public function register( Core $core ): void {
		$db = $core->get( 'database' ); $migrator = $core->migrator();
		$sponsors = new Sponsors( $db, $migrator, $core->get( 'public_catalog' ) );
		$sponsorAdmin = new SponsorAdmin( $sponsors ); $sponsorFrontend = new SponsorFrontend( $sponsors );
		$bundles = new Bundles( $db, $migrator, $core->get( 'discovery_query' ) );
		$bundleAdmin = new BundleAdmin( $bundles ); $bundleFrontend = new BundleFrontend( $bundles );
		$enterprise = new Enterprise( $db, $migrator );
		$enterpriseAdmin = new EnterpriseAdmin( $enterprise ); $enterpriseFrontend = new EnterpriseFrontend( $enterprise, new Forms( $db ) );
		$metrics = new Metrics( $db, $migrator, $core->get( 'discovery_views' ), $sponsors );
		$metricsAdmin = new MetricsAdmin( $metrics, $enterprise );
		$core->set( 'commercial_entitlements', new Entitlements( $core->get( 'accounts' ), $db ) );
		foreach ( array( 'commercial_sponsors' => $sponsors, 'commercial_sponsor_admin' => $sponsorAdmin, 'commercial_sponsor_frontend' => $sponsorFrontend, 'commercial_bundles' => $bundles, 'commercial_bundle_admin' => $bundleAdmin, 'commercial_bundle_frontend' => $bundleFrontend, 'commercial_enterprise' => $enterprise, 'commercial_enterprise_admin' => $enterpriseAdmin, 'commercial_enterprise_frontend' => $enterpriseFrontend, 'commercial_metrics' => $metrics, 'commercial_metrics_admin' => $metricsAdmin ) as $name => $service ) { $core->set( $name, $service ); }
		$sponsorAdmin->register(); $sponsorFrontend->register(); $bundleAdmin->register(); $bundleFrontend->register();
		$enterprise->register(); $enterpriseAdmin->register(); $enterpriseFrontend->register(); $metrics->register(); $metricsAdmin->register();
		// Native admin_menu precedes admin_init; grant only existing administrators.
		$grant = static function () use ( $migrator ): void {
			if ( ! current_user_can( 'manage_options' ) || ! $migrator->isCurrent() || '1' === get_option( 'aish_commercial_caps_version' ) ) { return; }
			$role = get_role( 'administrator' ); if ( ! $role ) { return; }
			foreach ( array( Sponsors::CAPABILITY, Metrics::CAPABILITY, Enterprise::CAPABILITY ) as $cap ) { $role->add_cap( $cap ); }
			update_option( 'aish_commercial_caps_version', '1', false ); wp_get_current_user()->get_role_caps();
		};
		add_action( 'admin_menu', $grant, 0 ); add_action( 'admin_init', $grant, 17 );
		add_action( 'aish_commercial_admin_landing', static function () use ( $sponsorAdmin, $metricsAdmin, $enterpriseAdmin ): void {
			if ( current_user_can( Sponsors::CAPABILITY ) ) { $sponsorAdmin->screen(); }
			elseif ( current_user_can( Metrics::CAPABILITY ) ) { $metricsAdmin->screen(); }
			elseif ( current_user_can( Enterprise::CAPABILITY ) ) { $enterpriseAdmin->screen(); }
			else { wp_die( esc_html__( 'Access denied.', 'ai-skills-hub' ), '', array( 'response' => 403 ) ); }
		} );
	}
}
