<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Language;

defined( 'ABSPATH' ) || exit;

final class BuiltinLanguageProvider implements LanguageProvider {
	public function supportedLocales(): array {
		return array( 'zh_CN', 'en_US' );
	}

	public function normalize( string $locale ): string {
		return match ( strtolower( str_replace( '_', '-', $locale ) ) ) {
			'zh', 'zh-cn' => 'zh_CN',
			'en', 'en-us' => 'en_US',
			default => throw new \InvalidArgumentException( 'Unsupported content locale.' ),
		};
	}

	public function externalObjectId( int $skill_id, string $locale ): ?int {
		return null;
	}
}
