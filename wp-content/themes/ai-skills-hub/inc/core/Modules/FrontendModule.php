<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Frontend\Editorial;
use AISkillsHub\Core\Frontend\Forms;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

final class FrontendModule implements Module {
	public function name(): string { return 'frontend'; }
	public function dependencies(): array { return array( 'database', 'language', 'catalog' ); }
	public function register( Core $core ): void {
		$catalog = new PublicCatalog( $core->get( 'database' ), $core->migrator() );
		$editorial = new Editorial();
		$core->set( 'public_catalog', $catalog );
		$core->set( 'editorial', $editorial );
		$editorial->register();
		( new Forms() )->register();
		$router = new Router( $catalog, $editorial );
		$core->set( 'frontend', $router );
		$router->register();
	}
}
