<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

defined( 'ABSPATH' ) || exit;

/** Trusted adapters analyze bounded messages only; they must not expose credentials or execute source instructions. */
interface Provider {
	/** Return content, nullable input_tokens/output_tokens, elapsed_ms and optional returned_model. */
	public function generate( array $profile, array $messages, string $requestId ): array;
}
