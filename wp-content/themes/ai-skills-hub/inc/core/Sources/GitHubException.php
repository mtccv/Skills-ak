<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

defined( 'ABSPATH' ) || exit;

/** Never carries a response body, request headers, credentials or a transport exception. */
final class GitHubException extends \RuntimeException {
	public function __construct(
		public readonly string $codeName,
		public readonly bool $retryable = false,
		public readonly int $retryAfter = 0,
		public readonly array $rate = array()
	) {
		parent::__construct( 'GitHub request could not be completed (' . $codeName . ').' );
	}
}
