<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** A deliberately bounded YAML mapping subset. No YAML constructors, tags, object hooks or evaluation. */
final class Frontmatter {
	public const MAX_FILE_BYTES = 524288;
	public const MAX_HEADER_BYTES = 16384;
	private const FIELDS = array( 'name', 'description', 'license', 'compatibility', 'metadata', 'allowed-tools' );

	public static function parse( string $raw, string $sourcePath ): array {
		try { $raw = Input::utf8( $raw, self::MAX_FILE_BYTES ); $sourcePath = Input::sourcePath( $sourcePath ); }
		catch ( \InvalidArgumentException $error ) { throw new \InvalidArgumentException( 'skill_invalid_encoding_size_or_path' ); }
		if ( basename( $sourcePath ) !== 'SKILL.md' || preg_match( '/^[a-z]:/i', $sourcePath ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $raw ) ) { throw new \InvalidArgumentException( 'skill_invalid_file' ); }
		$text = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) { $text = substr( $text, 3 ); }
		if ( ! preg_match( '/\A---[ ]*\n/', $text, $opening ) ) { throw new \InvalidArgumentException( 'frontmatter_missing' ); }
		$start = strlen( $opening[0] );
		if ( ! preg_match( '/^---[ ]*(?:\n|\z)/m', $text, $ending, PREG_OFFSET_CAPTURE, $start ) ) { throw new \InvalidArgumentException( 'frontmatter_unclosed' ); }
		$end = $ending[0][1];
		if ( $end - $start > self::MAX_HEADER_BYTES ) { throw new \InvalidArgumentException( 'frontmatter_too_large' ); }
		$header = substr( $text, $start, $end - $start );
		if ( str_contains( $header, "\t" ) ) { throw new \InvalidArgumentException( 'frontmatter_tabs_unsupported' ); }
		$lines = explode( "\n", $header );
		if ( '' === end( $lines ) ) { array_pop( $lines ); }
		if ( count( $lines ) > 256 ) { throw new \InvalidArgumentException( 'frontmatter_too_many_lines' ); }
		$fields = array();
		for ( $index = 0; $index < count( $lines ); ++$index ) {
			$line = $lines[ $index ];
			if ( self::ignorable( $line ) ) { continue; }
			if ( ! preg_match( '/^([a-z][a-z0-9-]*):(?: +(.*)|())$/D', $line, $match ) ) { throw new \InvalidArgumentException( 'frontmatter_invalid_mapping' ); }
			$key = $match[1]; $value = $match[2] ?? '';
			if ( ! in_array( $key, self::FIELDS, true ) ) { throw new \InvalidArgumentException( 'frontmatter_unsupported_field' ); }
			if ( array_key_exists( $key, $fields ) ) { throw new \InvalidArgumentException( 'frontmatter_duplicate_key' ); }
			if ( 'metadata' === $key ) {
				if ( '' !== trim( self::stripComment( $value ) ) ) { throw new \InvalidArgumentException( 'frontmatter_metadata_requires_mapping' ); }
				$metadata = array();
				while ( isset( $lines[ $index + 1 ] ) ) {
					$next = $lines[ $index + 1 ];
					if ( self::ignorable( $next ) ) { ++$index; continue; }
					if ( ! str_starts_with( $next, ' ' ) ) { break; }
					++$index;
					if ( ! preg_match( '/^  ([A-Za-z0-9][A-Za-z0-9_.-]{0,63}):(?: +(.*)|())$/D', $next, $member ) ) { throw new \InvalidArgumentException( 'frontmatter_invalid_metadata_mapping' ); }
					if ( array_key_exists( $member[1], $metadata ) || count( $metadata ) >= 32 ) { throw new \InvalidArgumentException( 'frontmatter_metadata_duplicate_or_limit' ); }
					$metadata[ $member[1] ] = self::readValue( $member[2] ?? '', $lines, $index, 2 );
					self::length( $metadata[ $member[1] ], 1024, true );
				}
				if ( ! $metadata ) { throw new \InvalidArgumentException( 'frontmatter_metadata_requires_mapping' ); }
				$fields[ $key ] = $metadata;
			} else {
				$fields[ $key ] = self::readValue( $value, $lines, $index, 0 );
			}
		}
		if ( ! isset( $fields['name'], $fields['description'] ) ) { throw new \InvalidArgumentException( 'frontmatter_required_field_missing' ); }
		if ( strlen( $fields['name'] ) > 64 || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $fields['name'] ) ) { throw new \InvalidArgumentException( 'frontmatter_invalid_name' ); }
		self::length( $fields['description'], 1024 );
		foreach ( array( 'license' => 256, 'compatibility' => 500, 'allowed-tools' => 1024 ) as $key => $max ) { if ( isset( $fields[ $key ] ) ) { self::length( $fields[ $key ], $max ); } }
		$warnings = array();
		$directory = dirname( $sourcePath );
		if ( '.' === $directory ) { $warnings[] = 'root_skill_directory_unverified'; }
		elseif ( basename( $directory ) !== $fields['name'] ) { throw new \InvalidArgumentException( 'frontmatter_name_directory_mismatch' ); }
		$body = substr( $text, $end + strlen( $ending[0][0] ) );
		if ( ! preg_match( '/[^\s\p{Z}]/u', $body ) ) { $warnings[] = 'skill_body_empty'; }
		if ( substr_count( $sourcePath, '/' ) > 12 ) { $warnings[] = 'skill_directory_depth'; }
		$metadata = array_diff_key( $fields, array( 'name' => true, 'description' => true ) );
		return array( 'name' => $fields['name'], 'description' => $fields['description'], 'metadata' => $metadata, 'body' => $body, 'warnings' => $warnings, 'source_path' => $sourcePath );
	}

	private static function ignorable( string $line ): bool { return '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ); }

	private static function length( string $value, int $max, bool $empty = false ): void {
		if ( ( ! $empty && ! preg_match( '/[^\s\p{Z}]/u', $value ) ) || preg_match_all( '/./us', $value ) > $max ) { throw new \InvalidArgumentException( 'frontmatter_invalid_text_length' ); }
	}

	/** Plain scalars do not interpret colons without whitespace or hashes embedded in words. */
	private static function stripComment( string $value ): string { return rtrim( preg_replace( '/(?:^| +)#.*$/u', '', $value ) ?? '' ); }

	private static function readValue( string $value, array $lines, int &$index, int $parentIndent ): string {
		$indicator = trim( self::stripComment( $value ) );
		if ( preg_match( '/^[|>][+-]?$/D', $indicator ) ) { return self::block( $indicator, $lines, $index, $parentIndent ); }
		if ( '' === $value ) { throw new \InvalidArgumentException( 'frontmatter_string_required' ); }
		if ( "'" === $value[0] ) {
			if ( ! preg_match( "/^'((?:[^']|'')*)'(?: +#.*)? *$/uD", $value, $match ) ) { throw new \InvalidArgumentException( 'frontmatter_invalid_quoted_scalar' ); }
			return str_replace( "''", "'", $match[1] );
		}
		if ( '"' === $value[0] ) {
			if ( ! preg_match( '/^("(?:[^"\\\\]|\\\\.)*")(?: +#.*)? *$/uD', $value, $match ) ) { throw new \InvalidArgumentException( 'frontmatter_invalid_quoted_scalar' ); }
			try { $decoded = json_decode( $match[1], true, 4, JSON_THROW_ON_ERROR ); }
			catch ( \JsonException $error ) { throw new \InvalidArgumentException( 'frontmatter_unsupported_string_escape' ); }
			if ( ! is_string( $decoded ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $decoded ) ) { throw new \InvalidArgumentException( 'frontmatter_invalid_string' ); }
			return $decoded;
		}
		$value = trim( self::stripComment( $value ) );
		if ( '' === $value || preg_match( '/^(?:[-?:](?:\s|$)|[!&*\[\]{}%@`|>])/', $value ) || preg_match( '/:(?:\s|$)/', $value ) ) { throw new \InvalidArgumentException( 'frontmatter_unsupported_yaml_construct' ); }
		// Numeric/bool/null/date scalars are not strings. Quoting preserves deliberate textual values.
		if ( preg_match( '/^(?:null|~|true|false|yes|no|on|off|[+-]?(?:0x[0-9a-f_]+|0o[0-7_]+|0b[01_]+|[0-9][0-9_,]*(?:\.[0-9_]*)?(?:e[+-]?[0-9]+)?|\.[0-9]+|\.inf|\.nan)|[0-9]{4}-[0-9]{2}-[0-9]{2}(?:[Tt ].*)?)$/iD', $value ) ) { throw new \InvalidArgumentException( 'frontmatter_string_required' ); }
		return $value;
	}

	private static function block( string $indicator, array $lines, int &$index, int $parentIndent ): string {
		$values = array(); $indent = null;
		while ( isset( $lines[ $index + 1 ] ) ) {
			$line = $lines[ $index + 1 ];
			if ( '' !== trim( $line ) ) {
				$spaces = strlen( $line ) - strlen( ltrim( $line, ' ' ) );
				if ( $spaces <= $parentIndent ) { break; }
				$indent ??= $spaces;
				if ( $spaces < $indent ) { throw new \InvalidArgumentException( 'frontmatter_invalid_block_indent' ); }
			}
			++$index;
			$values[] = '' === trim( $line ) ? '' : substr( $line, $indent ?? 0 );
		}
		if ( null === $indent ) { throw new \InvalidArgumentException( 'frontmatter_empty_block' ); }
		$result = ''; $seenText = false;
		foreach ( $values as $number => $line ) {
			if ( '>' === $indicator[0] && str_starts_with( $line, ' ' ) ) { throw new \InvalidArgumentException( 'frontmatter_folded_indent_unsupported' ); }
			$result .= $line;
			$seenText = $seenText || '' !== $line;
			if ( $number < count( $values ) - 1 ) {
				$next = $values[ $number + 1 ];
				if ( '>' === $indicator[0] && '' !== $line && '' !== $next ) { $result .= ' '; }
				elseif ( '>' !== $indicator[0] || '' !== $line || '' === $next || ! $seenText ) { $result .= "\n"; }
			}
		}
		$chomp = $indicator[1] ?? '';
		return match ( $chomp ) { '-' => rtrim( $result, "\n" ), '+' => $result . "\n", default => rtrim( $result, "\n" ) . "\n" };
	}
}
