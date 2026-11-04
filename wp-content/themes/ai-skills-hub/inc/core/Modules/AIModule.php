<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\AI\Admin;
use AISkillsHub\Core\AI\Settings;
use AISkillsHub\Core\AI\ProviderRegistry;
use AISkillsHub\Core\AI\GenerationStore;
use AISkillsHub\Core\AI\GenerationService;
use AISkillsHub\Core\AI\SourceContext;
use AISkillsHub\Core\AI\Worker;
use AISkillsHub\Core\AI\Scheduler;

defined( 'ABSPATH' ) || exit;

final class AIModule implements Module {
	public function name(): string { return 'ai'; }
	public function dependencies(): array { return array( 'database', 'catalog', 'admin', 'sources' ); }
	public function register( Core $core ): void {
		$db = $core->get( 'database' ); $settings = new Settings( $db ); $providers = new ProviderRegistry();
		$store = new GenerationStore( $db, $core->migrator() ); $source = new SourceContext( $db, $core->migrator() );
		$service = new GenerationService( $settings, $source, $store ); $worker = new Worker( $settings, $store, $providers );
		$scheduler = new Scheduler( $db, $core->migrator(), $settings, $worker );
		foreach ( array( 'ai_settings' => $settings, 'ai_providers' => $providers, 'ai_store' => $store, 'ai_generation' => $service, 'ai_worker' => $worker, 'ai_scheduler' => $scheduler ) as $name => $object ) { $core->set( $name, $object ); }
		add_action( 'admin_init', static function (): void {
			if ( ! current_user_can( 'manage_options' ) || '1' === get_option( 'aish_ai_caps_version' ) ) { return; }
			$role = get_role( 'administrator' ); if ( ! $role ) { return; }
			foreach ( array( 'aish_manage_ai_settings', 'aish_generate_content' ) as $cap ) { $role->add_cap( $cap ); }
			update_option( 'aish_ai_caps_version', '1', false );
		}, 6 );
		$scheduler->register(); ( new Admin( $settings, $store, $service ) )->register();
	}
}
