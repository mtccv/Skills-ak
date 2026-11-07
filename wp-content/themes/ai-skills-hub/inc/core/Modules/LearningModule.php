<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\{Core, Module};
use AISkillsHub\Core\Learning\{Help, HelpAdmin, Onboarding, SkillExperience, InstallationAdmin, Subscriptions, SubscriptionAdmin, SubscriptionFrontend};

defined( 'ABSPATH' ) || exit;

/** Optional learning views and consented mail over the existing content/account stores. */
final class LearningModule implements Module {
	public function name(): string { return 'learning'; }
	public function dependencies(): array { return array( 'commercial', 'operations', 'accounts', 'community', 'contributions', 'discovery', 'frontend', 'admin' ); }
	public function register( Core $core ): void {
		$db = $core->get( 'database' ); $migrator = $core->migrator();
		$help = new Help( $db, $migrator, $core->get( 'editorial' ) );
		$helpAdmin = new HelpAdmin( $help );
		$onboarding = new Onboarding( $db, $core->get( 'accounts' ), $core->get( 'account_locks' ) );
		$experience = new SkillExperience( $db, $migrator ); $installationAdmin = new InstallationAdmin( $experience );
		$subscriptions = new Subscriptions( $db, $migrator, $core->get( 'account_store' ), $core->get( 'account_mail' ), $core->get( 'account_locks' ), $core->get( 'accounts' ), $core->get( 'public_catalog' ), $core->get( 'editorial' ), $core->get( 'discovery_query' ) );
		$subscriptionAdmin = new SubscriptionAdmin( $subscriptions );
		$subscriptionFrontend = new SubscriptionFrontend( $subscriptions, $core->get( 'accounts' ) );
		foreach ( array( 'learning_help' => $help, 'learning_help_admin' => $helpAdmin, 'learning_onboarding' => $onboarding, 'learning_skill_experience' => $experience, 'learning_installation_admin' => $installationAdmin, 'learning_subscriptions' => $subscriptions, 'learning_subscription_admin' => $subscriptionAdmin, 'learning_subscription_frontend' => $subscriptionFrontend ) as $name => $service ) { $core->set( $name, $service ); }
		$helpAdmin->register(); $onboarding->register(); $experience->register(); $installationAdmin->register();
		$subscriptions->register( $core->get( 'account_mail_worker' ) ); $subscriptionAdmin->register(); $subscriptionFrontend->register();
		$grant = static function () use ( $migrator ): void {
			if ( ! current_user_can( 'manage_options' ) || ! $migrator->isCurrent() || '1' === get_option( 'aish_learning_caps_version' ) ) { return; }
			$role = get_role( 'administrator' ); if ( ! $role ) { return; }
			$role->add_cap( 'aish_manage_subscriptions' );
			update_option( 'aish_learning_caps_version', '1', false ); wp_get_current_user()->get_role_caps();
		};
		add_action( 'admin_menu', $grant, 0 ); add_action( 'admin_init', $grant, 18 );
		add_action( 'aish_learning_admin_landing', array( $subscriptionAdmin, 'screen' ) );
	}
}
