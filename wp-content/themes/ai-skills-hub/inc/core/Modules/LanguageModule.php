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
		$builtin = new BuiltinLanguageProvider();
		try {
			$provider = apply_filters( 'aish_core_language_provider', $builtin );
			if ( ! $provider instanceof LanguageProvider ) {
				throw new \LogicException( 'Invalid language adapter.' );
			}
		} catch ( \Throwable $error ) {
			// Optional integration failure must not prevent the existing Core and admin
			// from loading. This supplies locale normalization, never translated content.
			$provider = $builtin;
			add_action( 'admin_notices', static function (): void {
				if ( ! current_user_can( 'manage_options' ) ) { return; }
				echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Skills Hub could not load the language adapter. Built-in Chinese/English locale handling remains active; review the adapter configuration. No content or language relationships were changed.', 'ai-skills-hub' ) . '</p></div>';
			} );
		}
		$core->set( 'language', $provider );
	}
}
