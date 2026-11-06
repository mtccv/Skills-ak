<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

defined( 'ABSPATH' ) || exit;

/** Only stable, non-sensitive reasons cross the account HTTP boundary. */
final class AccountException extends \RuntimeException {
	public function __construct( private string $reason ) { parent::__construct( $reason ); }
	public function reason(): string { return $this->reason; }
}
