<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Discovery;

use AISkillsHub\Core\Security\Input as CoreInput;

defined( 'ABSPATH' ) || exit;

/** Bounded editorial input. Missing translations stay missing, never machine-filled. */
final class Input {
	public static function integer( mixed $value, int $min = 0, int $max = PHP_INT_MAX ): int {
		if ( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]{0,18})$/D', $value ) && (string) (int) $value === $value ) { $value = (int) $value; }
		if ( ! is_int( $value ) || $value < $min || $value > $max ) { throw new \InvalidArgumentException( 'Invalid editorial integer.' ); }
		return $value;
	}
	public static function kind( string $kind ): string {
		if ( ! in_array( $kind, array( 'collection', 'use_case' ), true ) ) { throw new \InvalidArgumentException( 'Unknown discovery entity.' ); }
		return $kind;
	}
	public static function termKind( string $kind ): string {
		if ( ! in_array( $kind, array( 'category', 'tag' ), true ) ) { throw new \InvalidArgumentException( 'Unknown discovery term.' ); }
		return $kind;
	}
	public static function text( mixed $value, int $max ): string {
		$value = CoreInput::utf8( $value, $max * 4 );
		if ( preg_match_all( '/./us', $value ) > $max ) { throw new \InvalidArgumentException( 'Editorial text is too long.' ); }
		return self::trim( sanitize_textarea_field( $value ) );
	}
	private static function trim( string $value ): string { return preg_replace( '/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value ); }
	public static function date( mixed $value ): ?string {
		if ( null === $value || '' === $value ) { return null; }
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'Invalid UTC date.' ); }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value || $value < '2000-01-01 00:00:00' || $value > '2100-12-31 23:59:59' ) { throw new \InvalidArgumentException( 'Expected a valid UTC date and time.' ); }
		return $value;
	}
	public static function ids( mixed $ids, int $max = 200 ): array {
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) || count( $ids ) > $max ) { throw new \InvalidArgumentException( 'Invalid editorial references.' ); }
		$seen = array();
		foreach ( $ids as $id ) { $id = self::integer( $id, 1 ); if ( isset( $seen[ $id ] ) ) { throw new \InvalidArgumentException( 'Duplicate editorial reference.' ); } $seen[ $id ] = $id; }
		return array_values( $seen );
	}
	public static function translations( mixed $input, string $kind ): array {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'zh_CN', 'en_US' ) ) ) { throw new \InvalidArgumentException( 'Unsupported editorial language.' ); }
		$result = array(); $name = 'collection' === $kind ? 'title' : 'name';
		foreach ( $input as $locale => $row ) {
			if ( ! is_array( $row ) ) { throw new \InvalidArgumentException( 'Invalid editorial translation.' ); }
			$fields = array( $name, 'description' );
			if ( in_array( $kind, array( 'collection', 'use_case' ), true ) ) { $fields = array_merge( $fields, array( 'slug', 'seo_title', 'seo_description' ) ); }
			if ( array_diff( array_keys( $row ), $fields ) ) { throw new \InvalidArgumentException( 'Unknown editorial translation field.' ); }
			$clean = array( $name => self::trim( CoreInput::plain( $row[ $name ] ?? '', 191, true ) ), 'description' => self::text( $row['description'] ?? '', 12000 ) );
			if ( isset( $fields[2] ) ) {
				$slug = $row['slug'] ?? '';
				$clean += array( 'slug' => '' === $slug || null === $slug ? null : CoreInput::slug( $slug ), 'seo_title' => CoreInput::plain( $row['seo_title'] ?? '', 191, true ), 'seo_description' => CoreInput::plain( $row['seo_description'] ?? '', 320, true ) );
			}
			// An entirely blank language form clears an unpublished translation, not a fake fallback.
			if ( ! array_filter( $clean, static fn( mixed $v ): bool => null !== $v && '' !== $v ) ) { continue; }
			if ( '' === $clean[ $name ] ) { throw new \InvalidArgumentException( 'A translation requires a name.' ); }
			$result[ $locale ] = $clean;
		}
		return $result;
	}
	public static function entity( string $kind, array $payload ): array {
		self::kind( $kind );
		if ( array_diff( array_keys( $payload ), array( 'status', 'sort_order', 'translations', 'skills', 'published_at', 'ends_at', 'tutorial_ids' ) ) ) { throw new \InvalidArgumentException( 'Unknown editorial field.' ); }
		$status = $payload['status'] ?? 'draft';
		if ( ! is_string( $status ) || ! in_array( $status, array( 'draft', 'published', 'archived' ), true ) ) { throw new \InvalidArgumentException( 'Invalid editorial status.' ); }
		$translations = self::translations( $payload['translations'] ?? array(), $kind );
		if ( 'published' === $status ) {
			// Like Skills, an entity may publish one complete language while another
			// language stays an incomplete editorial draft. Query checks each language.
			$complete = array_filter( $translations, static fn( array $row ): bool => null !== $row['slug'] && '' !== $row['description'] );
			if ( ! $complete ) { throw new \InvalidArgumentException( 'Publication requires at least one title, canonical slug and description.' ); }
		}
		$start = self::date( $payload['published_at'] ?? null ); $end = self::date( $payload['ends_at'] ?? null );
		if ( 'collection' !== $kind && null !== $start ) { throw new \InvalidArgumentException( 'Only collections have a scheduled start.' ); }
		if ( null !== $start && null !== $end && $end <= $start ) { throw new \InvalidArgumentException( 'Editorial end must follow its start.' ); }
		return array( 'status' => $status, 'sort_order' => self::integer( $payload['sort_order'] ?? 0, 0, 1000000 ), 'translations' => $translations, 'skills' => self::ids( $payload['skills'] ?? array() ), 'published_at' => $start, 'ends_at' => $end, 'tutorial_ids' => self::ids( $payload['tutorial_ids'] ?? array(), 20 ) );
	}
}
