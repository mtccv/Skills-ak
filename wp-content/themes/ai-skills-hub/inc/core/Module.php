<?php
declare(strict_types=1);

namespace AISkillsHub\Core;

defined( 'ABSPATH' ) || exit;

interface Module {
	public function name(): string;
	public function dependencies(): array;
	public function register( Core $core ): void;
}
