<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Operations\Admin;
use AISkillsHub\Core\Operations\Analytics;
use AISkillsHub\Core\Operations\AnalyticsAdmin;
use AISkillsHub\Core\Operations\Dashboard;
use AISkillsHub\Core\Operations\Health;
use AISkillsHub\Core\Operations\MailAdmin;
use AISkillsHub\Core\Operations\SearchMetrics;
use AISkillsHub\Core\Operations\Users;
use AISkillsHub\Core\Operations\UsersAdmin;
use AISkillsHub\Core\Operations\Workbench;

defined( 'ABSPATH' ) || exit;

/** Operational projections and controls over the existing domain services and storage. */
final class OperationsModule implements Module {
	public function name(): string { return 'operations'; }
	public function dependencies(): array { return array( 'discovery', 'accounts', 'contributions', 'sources', 'ai', 'admin' ); }
	public function register( Core $core ): void {
		$db = $core->get( 'database' ); $migrator = $core->migrator();
		$users = new Users( $db, $migrator, $core->get( 'accounts' ), $core->get( 'account_locks' ) );
		$userAdmin = new UsersAdmin( $users );
		$mail = new MailAdmin( $core->get( 'account_store' ), $core->get( 'account_mail' ), $migrator );
		$workbench = new Workbench( $core->get( 'submission_query' ), $core->get( 'submissions' ), $core->get( 'community_query' ), $core->get( 'community' ), $core->get( 'developer_query' ), $core->get( 'developers' ) );
		$health = new Health( $db, $migrator, $core->get( 'imports' ) );
		$dashboard = new Dashboard( $db, $migrator, $workbench, $health );
		$dashboard->addSummary( 'accounts', Users::CAPABILITY, array( $users, 'summary' ) );
		$dashboard->addSummary( 'mail', MailAdmin::CAPABILITY, array( $mail, 'summary' ) );
		$admin = new Admin( $dashboard, $workbench, $health );
		$analytics = new Analytics( $db, $migrator, $core->get( 'public_catalog' ), $core->get( 'discovery_query' ) );
		$analyticsAdmin = new AnalyticsAdmin( $analytics );
		$search = new SearchMetrics( $db, $migrator, $core->get( 'discovery_views' ) );
		foreach ( array( 'operations_users' => $users, 'operations_users_admin' => $userAdmin, 'operations_mail' => $mail, 'operations_workbench' => $workbench, 'operations_health' => $health, 'operations_dashboard' => $dashboard, 'operations_admin' => $admin, 'operations_analytics' => $analytics, 'operations_analytics_admin' => $analyticsAdmin, 'operations_search_metrics' => $search ) as $key => $service ) { $core->set( $key, $service ); }
		$userAdmin->register(); $mail->register(); $admin->register(); $analyticsAdmin->register(); $search->register();
		$grantCapabilities = static function () use ( $migrator ): void {
			if ( ! current_user_can( 'manage_options' ) || ! $migrator->isCurrent() || '1' === get_option( 'aish_operations_caps_version' ) ) { return; }
			$role = get_role( 'administrator' ); if ( ! $role ) { return; }
			foreach ( array( 'aish_view_operations', 'aish_manage_accounts', 'aish_manage_notifications', 'aish_view_analytics' ) as $cap ) { $role->add_cap( $cap ); }
			update_option( 'aish_operations_caps_version', '1', false ); wp_get_current_user()->get_role_caps();
		};
		// Native admin_menu runs before admin_init: upgrade requests need capabilities
		// before menu callbacks and their page access checks are registered.
		add_action( 'admin_menu', $grantCapabilities, 0 );
		add_action( 'admin_init', $grantCapabilities, 16 );
		add_action( 'aish_operations_admin_landing', static function () use ( $admin, $userAdmin, $mail, $analyticsAdmin ): void {
			if ( current_user_can( 'aish_view_operations' ) ) { $admin->dashboardScreen(); }
			elseif ( current_user_can( Users::CAPABILITY ) ) { $userAdmin->screen(); }
			elseif ( current_user_can( MailAdmin::CAPABILITY ) ) { $mail->page(); }
			else { $analyticsAdmin->screen(); }
		} );
	}
}
