<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Security;

defined( 'ABSPATH' ) || exit;

final class Access {
	public static function write( string $capability = 'aish_manage_skills' ): void {
		if ( ! current_user_can( $capability ) && ! current_user_can( 'manage_options' ) ) {
			throw new \DomainException( 'Core write permission denied.' );
		}
	}
}
