<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Submissions;
use AISkillsHub\Core\Developers;
use AISkillsHub\Core\Contributions;

defined( 'ABSPATH' ) || exit;

/** Native private Inbox workflows and opt-in reviewed developer identities. */
final class ContributionsModule implements Module {
	public function name(): string { return 'contributions'; }
	public function dependencies(): array { return array( 'accounts', 'community', 'admin', 'frontend' ); }
	public function register( Core $core ): void {
		$db = $core->get( 'database' ); $migrator = $core->migrator(); $accounts = $core->get( 'accounts' ); $locks = $core->get( 'account_locks' ); $store = $core->get( 'account_store' );
		$submissions = new Submissions\Service( $db, $migrator, $accounts, $locks, $store );
		$submissionQuery = new Submissions\Query( $db, $migrator, $accounts );
		$developers = new Developers\Service( $db, $migrator, $accounts, $locks, $store );
		$developerQuery = new Developers\Query( $db, $migrator, $accounts );
		$submissionAdmin = new Submissions\Admin( $submissions, $submissionQuery );
		$developerAdmin = new Developers\Admin( $developers, $developerQuery );
		$services = array(
			'submissions' => $submissions, 'submission_query' => $submissionQuery, 'developers' => $developers, 'developer_query' => $developerQuery,
			'contribution_guards' => new Contributions\Guards( $db ), 'contribution_privacy' => new Contributions\Privacy( $db, $locks ),
			'submission_frontend' => new Submissions\Frontend( $submissions, $submissionQuery, $accounts, $core->get( 'public_catalog' ) ),
			'developer_frontend' => new Developers\Frontend( $developers, $developerQuery, $accounts, $core->get( 'public_catalog' ) ),
			'developer_seo' => new Developers\Seo(),
			'submission_admin' => $submissionAdmin, 'developer_admin' => $developerAdmin,
		);
		foreach ( $services as $name => $service ) { $core->set( $name, $service ); }
		foreach ( array( 'contribution_guards', 'contribution_privacy', 'submission_frontend', 'developer_frontend', 'developer_seo', 'submission_admin', 'developer_admin' ) as $name ) { $services[ $name ]->register(); }
		add_filter( 'aish_developer_public_contributions', static fn( array $items, int $id, string $locale ): array => $submissionQuery->publicContributions( $id, $locale ), 10, 3 );
		add_action( 'admin_init', static function () use ( $migrator ): void {
			if ( ! current_user_can( 'manage_options' ) || ! $migrator->isCurrent() || '1' === get_option( 'aish_contribution_caps_version' ) ) { return; }
			$role = get_role( 'administrator' ); if ( ! $role ) { return; }
			foreach ( array( Submissions\Admin::SUBMISSIONS, Submissions\Admin::UPDATES, Developers\Admin::PROFILES, Developers\Admin::CLAIMS ) as $cap ) { $role->add_cap( $cap ); }
			update_option( 'aish_contribution_caps_version', '1', false ); wp_get_current_user()->get_role_caps();
		}, 16 );
		add_action( 'aish_contribution_admin_landing', static function () use ( $submissionAdmin, $developerAdmin ): void {
			if ( current_user_can( Submissions\Admin::SUBMISSIONS ) ) { $submissionAdmin->submissionsScreen(); }
			elseif ( current_user_can( Submissions\Admin::UPDATES ) ) { $submissionAdmin->updatesScreen(); }
			elseif ( current_user_can( Developers\Admin::PROFILES ) ) { $developerAdmin->profilesScreen(); }
			else { $developerAdmin->claimsScreen(); }
		} );
	}
}
