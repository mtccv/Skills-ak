<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Language;

defined( 'ABSPATH' ) || exit;

/** WPML adapters may map external objects, but may never replace a Core Skill ID. */
interface LanguageProvider {
	public function supportedLocales(): array;
	public function normalize( string $locale ): string;
	public function externalObjectId( int $skill_id, string $locale ): ?int;
}
