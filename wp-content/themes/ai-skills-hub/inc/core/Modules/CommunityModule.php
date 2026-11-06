<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Community\Admin;
use AISkillsHub\Core\Community\Events;
use AISkillsHub\Core\Community\Frontend;
use AISkillsHub\Core\Community\Metrics;
use AISkillsHub\Core\Community\Privacy;
use AISkillsHub\Core\Community\Publication;
use AISkillsHub\Core\Community\Query;
use AISkillsHub\Core\Community\Scheduler;
use AISkillsHub\Core\Community\Service;

defined( 'ABSPATH' ) || exit;

final class CommunityModule implements Module {
	public function name(): string { return 'community'; }
	public function dependencies(): array { return array( 'accounts', 'admin', 'frontend' ); }
	public function register( Core $core ): void {
		$db = $core->get( 'database' ); $migrator = $core->migrator();
		$accounts = $core->get( 'accounts' ); $locks = $core->get( 'account_locks' );
		$service = new Service( $db, $migrator, $accounts, $locks, $core->get( 'account_store' ) );
		$query = new Query( $db, $migrator, $accounts );
		$events = new Events( $db ); $metrics = new Metrics( $db );
		$privacy = new Privacy( $db, $locks );
		$frontend = new Frontend( $service, $query, $accounts, $core->get( 'public_catalog' ) );
		$admin = new Admin( $service, $query );
		$scheduler = new Scheduler( $db, $migrator, $metrics, $privacy );
		$publication = new Publication( $db, $events );
		foreach ( array( 'community' => $service, 'community_query' => $query, 'community_events' => $events, 'community_metrics' => $metrics, 'community_privacy' => $privacy, 'community_frontend' => $frontend, 'community_admin' => $admin, 'community_scheduler' => $scheduler ) as $key => $value ) { $core->set( $key, $value ); }
		$core->management()->setApprovalRecorder( array( $publication, 'approved' ) );
		$privacy->register(); $frontend->register(); $admin->register(); $scheduler->register();
		add_action( 'admin_init', static function () use ( $migrator ): void {
			if ( ! current_user_can( 'manage_options' ) || ! $migrator->isCurrent() || '1' === get_option( 'aish_community_caps_version' ) ) { return; }
			$role = get_role( 'administrator' ); if ( ! $role ) { return; }
			foreach ( array( Admin::REVIEWS, Admin::REPORTS ) as $cap ) { $role->add_cap( $cap ); }
			update_option( 'aish_community_caps_version', '1', false );
			wp_get_current_user()->get_role_caps();
		}, 15 );
		add_action( 'aish_community_admin_landing', static function () use ( $admin ): void {
			if ( current_user_can( Admin::REVIEWS ) ) { $admin->reviewsScreen(); }
			else { $admin->reviewReportsScreen(); }
		} );
	}
}
