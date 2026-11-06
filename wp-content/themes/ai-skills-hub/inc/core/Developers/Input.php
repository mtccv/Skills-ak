<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Developers;
use AISkillsHub\Core\Community\Input as Common;
defined( 'ABSPATH' ) || exit;

/** Only explicit public fields and evidence locations; never fetch these URLs. */
final class Input {
	public const LOCALES = array( 'zh_CN', 'en_US' );
	public static function id( mixed $value, bool $zero = false ): int { try { return Common::id( $value, $zero ); } catch ( \Throwable $e ) { throw new Exception( 'invalid_input' ); } }
	public static function locale( mixed $value ): string { return self::choice( $value, self::LOCALES ); }
	public static function choice( mixed $value, array $allowed ): string { if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) { throw new Exception( 'invalid_input' ); } return $value; }
	public static function text( mixed $value, int $max, int $min = 0 ): string { try { $clean = Common::text( $value, $max, $min ); } catch ( \Throwable $e ) { throw new Exception( 'invalid_input' ); } self::noCredentials( $clean ); return $clean; }
	private static function noCredentials( string $value ): void {
		if ( preg_match( '/(?:-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----|(?:gh[pousr]_|github_pat_|sk-(?:proj-)?)[A-Za-z0-9_-]{16,}|\bBearer\s+[A-Za-z0-9._~-]{16,})/i', $value ) ) { throw new Exception( 'invalid_input' ); }
	}
	public static function request( string $value ): string { try { return Common::request( $value ); } catch ( \Throwable $e ) { throw new Exception( 'invalid_input' ); } }
	public static function flag( mixed $value ): bool { if ( ! in_array( $value, array( true, false, 0, 1, '0', '1' ), true ) ) { throw new Exception( 'invalid_input' ); } return in_array( $value, array( true, 1, '1' ), true ); }
	public static function handle( mixed $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{1,48})[a-z0-9]$/D', $value ) || str_contains( $value, '--' ) || in_array( $value, array( 'admin', 'administrator', 'account', 'support', 'api', 'feed', 'login', 'register' ), true ) ) { throw new Exception( 'invalid_handle' ); }
		return $value;
	}
	public static function url( mixed $value, bool $github = false ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 1000 || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) { throw new Exception( 'invalid_input' ); }
		if ( '' === $value ) { return ''; }
		self::noCredentials( rawurldecode( $value ) );
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || ! filter_var( $value, FILTER_VALIDATE_URL ) ) { throw new Exception( 'invalid_input' ); }
		$host = strtolower( $parts['host'] );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) || 'localhost' === $host || ! str_contains( $host, '.' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) { throw new Exception( 'invalid_input' ); }
		if ( isset( $parts['query'] ) && preg_match( '/(?:^|&)(?:access_token|token|api[_-]?key|password|secret|authorization)=/i', rawurldecode( $parts['query'] ) ) ) { throw new Exception( 'invalid_input' ); }
		if ( $github && ( 'github.com' !== $host || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! preg_match( '~^/[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?/?$~D', $parts['path'] ?? '' ) ) ) { throw new Exception( 'invalid_input' ); }
		return esc_url_raw( $value, array( 'https' ) );
	}
	public static function profile( array $data ): array {
		if ( array_diff( array_keys( $data ), array( 'handle', 'github_url', 'website_url', 'public_avatar', 'show_contributions', 'opt_in', 'translations' ) ) || ! is_array( $data['translations'] ?? null ) || array_diff( array_keys( $data['translations'] ), self::LOCALES ) ) { throw new Exception( 'invalid_input' ); }
		$translations = array(); $complete = false;
		foreach ( self::LOCALES as $locale ) {
			$row = $data['translations'][ $locale ] ?? array();
			if ( ! is_array( $row ) || array_diff( array_keys( $row ), array( 'display_name', 'bio', 'seo_title', 'seo_description' ) ) ) { throw new Exception( 'invalid_input' ); }
			$clean = array(); foreach ( array( 'display_name' => 100, 'bio' => 2000, 'seo_title' => 191, 'seo_description' => 320 ) as $field => $max ) { $clean[ $field ] = self::text( $row[ $field ] ?? '', $max ); }
			if ( '' !== $clean['display_name'] || '' !== $clean['bio'] || '' !== $clean['seo_title'] || '' !== $clean['seo_description'] ) { $translations[ $locale ] = $clean; }
			$complete = $complete || ( '' !== $clean['display_name'] && '' !== $clean['bio'] );
		}
		$optIn = self::flag( $data['opt_in'] ?? false );
		if ( $optIn && ! $complete ) { throw new Exception( 'translation_required' ); }
		return array( 'handle' => self::handle( $data['handle'] ?? null ), 'github_url' => self::url( $data['github_url'] ?? '', true ), 'website_url' => self::url( $data['website_url'] ?? '' ), 'public_avatar' => self::flag( $data['public_avatar'] ?? false ), 'show_contributions' => self::flag( $data['show_contributions'] ?? false ), 'opt_in' => $optIn, 'translations' => $translations );
	}
	public static function claim( array $data ): array {
		if ( array_diff( array_keys( $data ), array( 'claim_role', 'locale', 'source_revision', 'proof_kind', 'evidence_urls', 'reason' ) ) || ! is_array( $data['evidence_urls'] ?? null ) || ! array_is_list( $data['evidence_urls'] ) || count( $data['evidence_urls'] ) < 1 || count( $data['evidence_urls'] ) > 5 ) { throw new Exception( 'invalid_input' ); }
		$role = self::choice( $data['claim_role'] ?? null, array( 'author', 'maintainer' ) );
		$kind = self::choice( $data['proof_kind'] ?? null, array( 'authored_source', 'license_credit', 'repository_control', 'other' ) );
		if ( 'author' === $role && 'repository_control' === $kind ) { throw new Exception( 'author_evidence_required' ); }
		$urls = array(); foreach ( $data['evidence_urls'] as $url ) { $url = self::url( $url ); if ( '' === $url ) { throw new Exception( 'invalid_input' ); } $urls[] = $url; }
		return array( 'claim_role' => $role, 'locale' => self::locale( $data['locale'] ?? null ), 'source_revision' => self::id( $data['source_revision'] ?? null ), 'proof_kind' => $kind, 'evidence_urls' => array_values( array_unique( $urls ) ), 'reason' => self::text( $data['reason'] ?? null, 4000, 20 ) );
	}
	/** TEXT has a byte limit: reject oversized history before any storage mutation. */
	public static function proofDocument( array $proof ): string {
		try { $json = json_encode( $proof, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 16 ); } catch ( \Throwable $e ) { throw new Exception( 'invalid_input' ); }
		if ( strlen( $json ) > 60000 ) { throw new Exception( 'invalid_input' ); } return $json;
	}
}
