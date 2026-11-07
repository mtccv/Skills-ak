<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

defined( 'ABSPATH' ) || exit;

/** Conservative suppression: unclassified/sensitive text contributes only to site totals. */
final class SearchPrivacy {
	public const THRESHOLD = 5;
	public const DAILY_TERMS = 2000;
	public static function term( mixed $query ): ?string {
		if ( ! is_string( $query ) || '' === trim( $query ) || strlen( $query ) > 191 || ! preg_match( '//u', $query ) || preg_match_all( '/./us', $query ) > 64 ) { return null; }
		// Avoid rewriting sensitive text into an apparently innocent term. No raw rejected text persists.
		if ( preg_match( '/[\x00-\x1f\x7f@\/\\\\:=<>\[\]{}]|www\.|(?:api[ _-]?key|secret|password|passwd|token|bearer|authorization|private[ _-]?key)|(?:gh[pousr]_|github_pat_|sk-|AKIA)[a-z0-9_-]*|\p{N}{5,}|(?:\p{N}[\s().+\-]*){7,}|[a-f0-9]{20,}|[a-z0-9_+-]{24,}/iu', $query ) ) { return null; }
		// Letters, modest numbers and everyday punctuation only; no encoded payloads or identifiers.
		if ( ! preg_match( '/^[\p{L}\p{N}\p{Zs}.,!?()\x{2019}\x{2018}\x{3001}\x{3002}\x{ff0c}\x{ff1f}\x{ff01}+\-#]{1,64}$/uD', $query ) ) { return null; }
		$value = preg_replace( '/\p{Zs}+/u', ' ', trim( $query ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
	/** Byte identity precedes display text so the existing accent-insensitive SQL key cannot merge terms. */
	public static function dimension( string $term ): string {
		if ( self::term( $term ) !== $term ) { throw new \InvalidArgumentException( 'invalid_term' ); }
		// 64 ASCII hash characters + ':' + at most 64 Unicode characters fits varchar(191).
		return hash( 'sha256', $term ) . ':' . $term;
	}
	public static function displayDimension( mixed $key ): ?string {
		if ( ! is_string( $key ) || ! preg_match( '/^([a-f0-9]{64}):(.+)$/usD', $key, $parts ) || self::term( $parts[2] ) !== $parts[2] || ! hash_equals( hash( 'sha256', $parts[2] ), $parts[1] ) ) { return null; }
		return $parts[2];
	}
	/** Only a genuine successful first-page search may issue an observation proof. */
	public static function context( array $context ): ?array {
		if ( true !== ( $context['valid'] ?? false ) || false !== ( $context['failed'] ?? true ) || 1 !== ( $context['page'] ?? null ) || ! in_array( $context['route'] ?? '', array( 'skills', 'category', 'tag', 'search', 'tutorials' ), true ) || ! in_array( $context['locale'] ?? '', array( 'zh_CN', 'en_US' ), true ) || ! is_int( $context['total'] ?? null ) || $context['total'] < 0 || ! is_string( $context['query'] ?? null ) || '' === trim( $context['query'] ) || strlen( $context['query'] ) > 800 || ! preg_match( '//u', $context['query'] ) ) { return null; }
		return array( 'locale' => $context['locale'], 'zero' => 0 === $context['total'], 'term' => self::term( $context['query'] ) );
	}
}
