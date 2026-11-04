<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Content\Catalog;

defined( 'ABSPATH' ) || exit;

final class CatalogModule implements Module {
	public function name(): string {
		return 'catalog';
	}

	public function dependencies(): array {
		return array( 'database', 'language' );
	}

	public function register( Core $core ): void {
		$core->set( 'catalog', new Catalog( $core->get( 'database' ), $core->migrator(), $core->languages() ) );
	}
}
