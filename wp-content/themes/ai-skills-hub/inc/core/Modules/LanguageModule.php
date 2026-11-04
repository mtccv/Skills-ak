<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Language\BuiltinLanguageProvider;
use AISkillsHub\Core\Language\LanguageProvider;

defined( 'ABSPATH' ) || exit;

final class LanguageModule implements Module {
	public function name(): string {
		return 'language';
	}

	public function dependencies(): array {
		return array();
	}

	public function register( Core $core ): void {
		$provider = apply_filters( 'aish_core_language_provider', new BuiltinLanguageProvider() );
		if ( ! $provider instanceof LanguageProvider ) {
			throw new \LogicException( 'Language adapter must implement LanguageProvider.' );
		}
		$core->set( 'language', $provider );
	}
}
