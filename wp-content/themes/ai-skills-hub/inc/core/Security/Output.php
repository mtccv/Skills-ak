<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Security;

defined( 'ABSPATH' ) || exit;

/** Escape at the final output context, never before storage. */
final class Output {
	public static function text( string $value ): string {
		return esc_html( $value );
	}

	public static function attribute( string $value ): string {
		return esc_attr( $value );
	}

	public static function url( string $value ): string {
		return esc_url( $value, array( 'https', 'http' ) );
	}

	public static function rich( string $value ): string {
		return wp_kses_post( $value );
	}

	public static function json( mixed $value ): string {
		$json = wp_json_encode( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'Unable to encode JSON.' );
		}
		return $json;
	}
}
