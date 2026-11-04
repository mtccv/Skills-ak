<?php
declare(strict_types=1);

namespace AISkillsHub\Core;

use AISkillsHub\Core\Content\Catalog;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Language\LanguageProvider;
use AISkillsHub\Core\Modules\DatabaseModule;
use AISkillsHub\Core\Modules\LanguageModule;
use AISkillsHub\Core\Modules\CatalogModule;
use AISkillsHub\Core\Modules\AdminModule;
use AISkillsHub\Core\Modules\FrontendModule;
use AISkillsHub\Core\Modules\SourcesModule;
use AISkillsHub\Core\Admin\Management;

defined( 'ABSPATH' ) || exit;

final class Core {
	private static ?self $instance = null;
	private array $services = array();
	private array $modules = array();

	public static function boot(): self {
		if ( null === self::$instance ) {
			$core          = new self();
			$core->modules = ( new ModuleLoader() )->load( array( new DatabaseModule(), new LanguageModule(), new CatalogModule(), new AdminModule(), new FrontendModule(), new SourcesModule() ), $core );
			self::$instance = $core;
		}
		return self::$instance;
	}

	public function set( string $name, object $service ): void {
		if ( isset( $this->services[ $name ] ) ) {
			throw new \LogicException( 'Core service is already registered.' );
		}
		$this->services[ $name ] = $service;
	}

	public function get( string $name ): object {
		return $this->services[ $name ] ?? throw new \LogicException( 'Core service is unavailable.' );
	}

	public function modules(): array {
		return $this->modules;
	}

	public function catalog(): Catalog {
		return $this->get( 'catalog' );
	}

	public function management(): Management {
		return $this->get( 'management' );
	}

	public function migrator(): Migrator {
		return $this->get( 'migrator' );
	}

	public function languages(): LanguageProvider {
		return $this->get( 'language' );
	}
}
