<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

defined( 'ABSPATH' ) || exit;

/** Plain user-authored text only; rendering must still escape every field. */
final class Input {
	public const REVIEW_REASONS = array( 'spam', 'false_review', 'attack', 'violation', 'other' );
	public const SKILL_REASONS = array( 'broken_source', 'installation', 'license', 'outdated', 'compatibility', 'security', 'other' );
	public const MODERATION_REASONS = array( 'approved', 'spam', 'false_claim', 'abuse', 'policy', 'other' );
	public const RESOLUTIONS = array( 'resolved', 'no_action', 'duplicate', 'invalid', 'other' );

	public static function id( mixed $value, bool $zero = false ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^(0|[1-9][0-9]{0,18})$/D', $value ) ) ) { throw new Exception( 'invalid_input' ); }
		$id = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $id || $id < ( $zero ? 0 : 1 ) ) { throw new Exception( 'invalid_input' ); }
		return $id;
	}
	public static function locale( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'zh_CN', 'en_US' ), true ) ) { throw new Exception( 'invalid_input' ); }
		return $value;
	}
	public static function choice( mixed $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) { throw new Exception( 'invalid_input' ); }
		return $value;
	}
	public static function text( mixed $value, int $max, int $min = 0, bool $multiline = true ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $max * 4 || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) { throw new Exception( 'invalid_input' ); }
		$value = trim( $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) );
		$length = preg_match_all( '/./us', $value );
		if ( $length < $min || $length > $max ) { throw new Exception( 'invalid_input' ); }
		return $value;
	}
	public static function request( string $key ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $key ) ) { throw new Exception( 'invalid_input' ); }
		return $key;
	}
	public static function review( array $data ): array {
		if ( array_diff( array_keys( $data ), array( 'title', 'rating', 'comment', 'experience', 'pros', 'cons', 'platform', 'version_label', 'locale' ) ) ) { throw new Exception( 'invalid_input' ); }
		$rating = self::id( $data['rating'] ?? null );
		if ( $rating > 5 ) { throw new Exception( 'invalid_input' ); }
		$result = array( 'locale' => self::locale( $data['locale'] ?? null ), 'rating' => $rating, 'title' => self::text( $data['title'] ?? null, 191, 1, false ), 'comment' => self::text( $data['comment'] ?? null, 8000, 5 ) );
		foreach ( array( 'experience', 'pros', 'cons' ) as $key ) { $result[ $key ] = self::text( $data[ $key ] ?? '', 2000 ); }
		$result['platform'] = self::text( $data['platform'] ?? '', 100, 0, false );
		$result['version_label'] = self::text( $data['version_label'] ?? '', 191, 0, false );
		if ( preg_match_all( '~https?://~i', implode( '\n', $result ) ) > 5 ) { throw new Exception( 'invalid_input' ); }
		return $result;
	}
	public static function report( array $data, string $kind ): array {
		$keys = 'review' === $kind ? array( 'reason_code', 'details', 'locale' ) : array( 'kind', 'details', 'locale', 'skill_revision' );
		if ( array_diff( array_keys( $data ), $keys ) ) { throw new Exception( 'invalid_input' ); }
		$result = array( 'locale' => self::locale( $data['locale'] ?? null ), 'details' => self::text( $data['details'] ?? null, 4000, 5 ) );
		if ( 'review' === $kind ) { $result['reason_code'] = self::choice( $data['reason_code'] ?? null, self::REVIEW_REASONS ); }
		else { $result['kind'] = self::choice( $data['kind'] ?? null, self::SKILL_REASONS ); $result['skill_revision'] = self::id( $data['skill_revision'] ?? null ); }
		return $result;
	}
}
