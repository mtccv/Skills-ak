<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Sources\Admin;
use AISkillsHub\Core\Sources\ImportStore;
use AISkillsHub\Core\Sources\Worker;
use AISkillsHub\Core\Sources\Scheduler;

defined( 'ABSPATH' ) || exit;

final class SourcesModule implements Module {
	public function name(): string { return 'sources'; }
	public function dependencies(): array { return array( 'database', 'catalog', 'admin' ); }
	public function register( Core $core ): void {
		$store = new ImportStore( $core->get( 'database' ), $core->migrator() );
		$worker = new Worker( $store );
		$scheduler = new Scheduler( $core->get( 'database' ), $core->migrator(), $store, $worker );
		$core->set( 'imports', $store ); $core->set( 'import_worker', $worker ); $core->set( 'import_scheduler', $scheduler );
		$scheduler->register();
		( new Admin( $store ) )->register();
	}
}
