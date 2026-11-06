<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Submissions;
defined( 'ABSPATH' ) || exit;
final class Exception extends \RuntimeException {
	public function __construct( public readonly string $reason ) { parent::__construct( $reason ); }
}
