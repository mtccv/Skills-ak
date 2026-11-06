<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

defined( 'ABSPATH' ) || exit;

/** Safe outward code: SQL, submitted text and credentials never become error messages. */
final class Exception extends \RuntimeException {
	public function __construct( public readonly string $reason ) { parent::__construct( $reason ); }
}
