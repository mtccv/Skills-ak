<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Security;

defined( 'ABSPATH' ) || exit;

final class Input {
	public static function id( mixed $value ): int {
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new \InvalidArgumentException( 'Expected a positive internal ID.' );
		}
		return $value;
	}

	public static function utf8( mixed $value, int $bytes ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $bytes || ! preg_match( '//u', $value ) || str_contains( $value, "\0" ) ) {
			throw new \InvalidArgumentException( 'Invalid text encoding or size.' );
		}
		return $value;
	}

	public static function plain( mixed $value, int $length = 191, bool $empty = false ): string {
		$text = sanitize_text_field( self::utf8( $value, $length * 4 ) );
		if ( ( ! $empty && '' === $text ) || preg_match_all( '/./us', $text ) > $length ) {
			throw new \InvalidArgumentException( 'Invalid text length.' );
		}
		return $text;
	}

	public static function slug( mixed $value ): string {
		$value = self::utf8( $value, 191 );
		// Stable canonical slugs; never silently truncate or rewrite an identity.
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Expected a canonical ASCII slug.' );
		}
		return $value;
	}

	public static function sourcePath( mixed $value ): string {
		$value = self::utf8( $value, 768 );
		if ( '' === $value || preg_match( '/[\x00-\x1f\x7f\\\\]/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid relative source path.' );
		}
		foreach ( explode( '/', $value ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || trim( $part ) !== $part ) {
				throw new \InvalidArgumentException( 'Source path must be canonical and relative.' );
			}
		}
		return $value;
	}

	public static function repository( mixed $owner, mixed $name ): array {
		$owner = self::utf8( $owner, 39 );
		$name  = self::utf8( $name, 100 );
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?$/iD', $owner ) || str_contains( $owner, '--' ) || ! preg_match( '/^[a-z0-9_.-]+$/iD', $name ) || in_array( $name, array( '.', '..' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid GitHub repository identity.' );
		}
		return array( strtolower( $owner ), strtolower( $name ) );
	}
}
