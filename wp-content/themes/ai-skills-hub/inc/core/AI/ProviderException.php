<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

defined( 'ABSPATH' ) || exit;

/** No remote body, headers, key or original exception is retained. Ambiguous requests require human retry. */
final class ProviderException extends \RuntimeException {
	public function __construct(
		public readonly string $codeName,
		public readonly bool $retryable = false,
		public readonly int $retryAfter = 0,
		public readonly bool $ambiguous = false,
		public readonly array $usage = array()
	) {
		parent::__construct( 'AI provider request could not be completed (' . $codeName . ').' );
	}
}
