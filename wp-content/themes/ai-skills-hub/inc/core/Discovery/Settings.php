<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Discovery;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** Bounded editorial settings; counters and Skill publication are never configurable. */
final class Settings {
	public const CAPABILITY = 'aish_manage_discovery';
	public const OPTION = 'aish_discovery_settings';
	public const MODULES = array( 'trending', 'categories', 'use_cases', 'collections', 'recently_added', 'recently_updated', 'most_favorited', 'top_rated', 'platforms', 'tutorials' );
	public const LOCALES = array( 'zh_CN', 'en_US' );
	public function __construct( private Database $db, private Migrator $migrator ) {}
	public static function defaults(): array {
		$modules = array(); foreach ( self::MODULES as $key ) { $modules[] = array( 'key' => $key, 'enabled' => true, 'limit' => 6, 'title' => '', 'description' => '' ); }
		return array( 'tracking' => false, 'home' => array( 'zh_CN' => $modules, 'en_US' => $modules ), 'platforms' => array() );
	}
	private static function decode( mixed $raw ): array {
		if ( false === $raw || '' === $raw ) { return array( 'revision' => 0, 'draft' => self::defaults(), 'published' => self::defaults(), 'published_revision' => 0 ); }
		if ( ! is_string( $raw ) || strlen( $raw ) > 262144 ) { throw new \RuntimeException( 'discovery_settings_unavailable' ); }
		$r = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
		if ( ! is_array( $r ) || ! is_int( $r['revision'] ?? null ) || $r['revision'] < 0 || ! is_int( $r['published_revision'] ?? null ) || $r['published_revision'] < 0 || $r['published_revision'] > $r['revision'] || ! is_array( $r['draft'] ?? null ) || ! is_array( $r['published'] ?? null ) ) { throw new \RuntimeException( 'discovery_settings_unavailable' ); }
		$r['draft'] = self::validate( $r['draft'] ); $r['published'] = self::validate( $r['published'] ); return $r;
	}
	public static function snapshot(): array { return self::decode( get_option( self::OPTION, false ) ); }
	public static function home( string $locale ): array { if ( ! in_array( $locale, self::LOCALES, true ) ) { throw new \InvalidArgumentException( 'invalid_locale' ); } return self::snapshot()['published']['home'][ $locale ]; }
	public static function platforms(): array { return self::snapshot()['published']['platforms']; }
	public static function tracking(): bool { try { return self::snapshot()['published']['tracking']; } catch ( \Throwable $error ) { return false; } }
	public static function text( mixed $value, int $max ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $max * 4 || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) || preg_match_all( '/./us', $value ) > $max ) { throw new \InvalidArgumentException( 'invalid_text' ); }
		return trim( wp_strip_all_tags( $value ) );
	}
	public static function integer( mixed $value, int $min, int $max ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) ) { throw new \InvalidArgumentException( 'invalid_number' ); }
		$n = filter_var( $value, FILTER_VALIDATE_INT ); if ( false === $n || $n < $min || $n > $max ) { throw new \InvalidArgumentException( 'invalid_number' ); } return $n;
	}
	private static function flag( mixed $v ): bool { if ( ! in_array( $v, array( true, false, 0, 1, '0', '1' ), true ) ) { throw new \InvalidArgumentException( 'invalid_flag' ); } return in_array( $v, array( true, 1, '1' ), true ); }
	public static function validate( array $data ): array {
		if ( array_diff( array_keys( $data ), array( 'tracking', 'home', 'platforms' ) ) || ! is_array( $data['home'] ?? null ) || array_diff( array_keys( $data['home'] ), self::LOCALES ) || ! is_array( $data['platforms'] ?? null ) || count( $data['platforms'] ) > 50 ) { throw new \InvalidArgumentException( 'invalid_settings' ); }
		$result = array( 'tracking' => self::flag( $data['tracking'] ?? false ), 'home' => array(), 'platforms' => array() );
		foreach ( self::LOCALES as $locale ) {
			$rows = $data['home'][ $locale ] ?? null; if ( ! is_array( $rows ) || count( $rows ) !== count( self::MODULES ) ) { throw new \InvalidArgumentException( 'invalid_modules' ); } $seen = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! is_string( $row['key'] ?? null ) || ! in_array( $row['key'], self::MODULES, true ) || isset( $seen[ $row['key'] ] ) || array_diff( array_keys( $row ), array( 'key', 'enabled', 'limit', 'title', 'description' ) ) ) { throw new \InvalidArgumentException( 'invalid_module' ); }
				$seen[ $row['key'] ] = true; $result['home'][ $locale ][] = array( 'key' => $row['key'], 'enabled' => self::flag( $row['enabled'] ?? false ), 'limit' => self::integer( $row['limit'] ?? 6, 1, 12 ), 'title' => self::text( $row['title'] ?? '', 191 ), 'description' => self::text( $row['description'] ?? '', 500 ) );
			}
		}
		foreach ( $data['platforms'] as $id => $row ) {
			$id = self::integer( $id, 1, PHP_INT_MAX ); if ( ! is_array( $row ) || array_diff( array_keys( $row ), array( 'enabled', 'verified', 'evidence_url', 'translations' ) ) || ! is_array( $row['translations'] ?? null ) || array_diff( array_keys( $row['translations'] ), self::LOCALES ) ) { throw new \InvalidArgumentException( 'invalid_platform' ); }
			$enabled = self::flag( $row['enabled'] ?? false ); $verified = self::flag( $row['verified'] ?? false ); $url = self::text( $row['evidence_url'] ?? '', 2048 );
			if ( '' !== $url ) { $p = wp_parse_url( $url ); if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) || filter_var( trim( $p['host'], '[]' ), FILTER_VALIDATE_IP ) || preg_match( '/(?:^|&)(?:token|key|api_key|password|secret|authorization)=/i', rawurldecode( $p['query'] ?? '' ) ) ) { throw new \InvalidArgumentException( 'invalid_evidence' ); } }
			if ( $enabled && ( ! $verified || '' === $url ) ) { throw new \InvalidArgumentException( 'evidence_required' ); }
			$translations = array(); $complete = false;
			foreach ( self::LOCALES as $locale ) {
				$t = $row['translations'][ $locale ] ?? array(); if ( ! is_array( $t ) || array_diff( array_keys( $t ), array( 'title', 'description', 'seo_title', 'seo_description' ) ) ) { throw new \InvalidArgumentException( 'invalid_translation' ); }
				foreach ( array( 'title' => 191, 'description' => 4000, 'seo_title' => 191, 'seo_description' => 320 ) as $field => $max ) { $translations[ $locale ][ $field ] = self::text( $t[ $field ] ?? '', $max ); }
				$complete = $complete || ( '' !== $translations[ $locale ]['title'] && '' !== $translations[ $locale ]['description'] );
			}
			if ( $enabled && ! $complete ) { throw new \InvalidArgumentException( 'translation_required' ); }
			$result['platforms'][ $id ] = array( 'enabled' => $enabled, 'verified' => $verified, 'evidence_url' => $url, 'translations' => $translations );
		}
		return $result;
	}
	public function save( array $payload, int $expectedRevision, string $mode ): array {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'forbidden' ); }
		self::integer( $expectedRevision, 0, PHP_INT_MAX - 1 ); if ( ! in_array( $mode, array( 'draft', 'publish' ), true ) ) { throw new \InvalidArgumentException( 'invalid_mode' ); }
		$data = self::validate( $payload ); $table = $this->db->connection()->options; if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new \RuntimeException( 'storage_unavailable' ); } $lock = 'aish_discovery_settings:' . substr( hash( 'sha256', $table ), 0, 38 ); $held = false;
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 2)', array( $lock ) ); if ( ! $held ) { throw new \RuntimeException( 'busy' ); }
			if ( 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) ) ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
			return $this->db->transaction( function () use ( $data, $expectedRevision, $mode, $table ): array {
				if ( ! current_user_can( self::CAPABILITY ) ) { throw new \RuntimeException( 'forbidden' ); }
				$row = $this->db->row( "SELECT option_value FROM $table WHERE option_name=%s FOR UPDATE", array( self::OPTION ) ); $state = self::decode( $row['option_value'] ?? false );
				if ( $state['revision'] !== $expectedRevision ) { throw new \RuntimeException( 'conflict' ); }
				foreach ( $data['platforms'] as $id => $platform ) { $tag = $this->db->row( 'SELECT slug,is_archived FROM ' . $this->db->table( 'tags' ) . ' WHERE id=%d FOR UPDATE', array( $id ) ); if ( ! $tag || ! str_starts_with( $tag['slug'], 'platform-' ) || ( $platform['enabled'] && (int) $tag['is_archived'] ) ) { throw new \InvalidArgumentException( 'invalid_platform' ); } }
				++$state['revision']; $state['draft'] = $data; $state['updated_at'] = gmdate( 'Y-m-d H:i:s' ); $state['updated_by'] = get_current_user_id();
				if ( 'publish' === $mode ) { $state['published'] = $data; $state['published_revision'] = $state['revision']; }
				$json = wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); if ( ! is_string( $json ) || strlen( $json ) > 262144 ) { throw new \InvalidArgumentException( 'settings_too_large' ); }
				$this->db->execute( "INSERT INTO $table (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", array( self::OPTION, $json ) ); return $state;
			} );
		} finally { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' ); if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $error ) { /* A closed connection releases its advisory lock. */ } } }
	}
}
