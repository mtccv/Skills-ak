<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;

use AISkillsHub\Core\Community\Events;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Discovery\Settings;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Small manual placements, separate from editorial selection and natural metrics. */
final class Sponsors {
	public const CAPABILITY = 'aish_manage_sponsors';
	public const OPTION = 'aish_commercial_sponsors';
	public const LIMIT = 20;
	public const PLACEMENTS = array( 'home_after_search', 'skill_detail_footer' );
	public const LOCALES = array( 'zh_CN', 'en_US' );
	public function __construct( private Database $db, private Migrator $migrator, private PublicCatalog $public ) {}
	public static function defaults(): array { return array( 'revision' => 0, 'enabled' => false, 'next_id' => 1, 'entries' => array() ); }
	public static function blank(): array { return array( 'status' => 'draft', 'placement' => 'home_after_search', 'name' => '', 'url' => '', 'skill_id' => 0, 'image_id' => 0, 'image_evidence' => '', 'starts_at' => '', 'ends_at' => '', 'review_note' => '', 'translations' => array( 'zh_CN' => array( 'title' => '', 'description' => '' ), 'en_US' => array( 'title' => '', 'description' => '' ) ) ); }
	private function authorize(): void { if ( ! current_user_can( self::CAPABILITY ) || ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'forbidden' ); } }
	/** URLs are displayed only, never fetched. Relative, credentialed, local and executable schemes are rejected. */
	public static function httpsUrl( mixed $value, bool $optional = true ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x20\x7f\\\\<>"\']/u', $value ) || preg_match( '/%(?:00|0a|0d|5c)/i', $value ) ) { throw new \InvalidArgumentException( 'invalid_url' ); }
		if ( '' === $value && $optional ) { return ''; }
		$p = wp_parse_url( $value ); $host = is_array( $p ) ? strtolower( $p['host'] ?? '' ) : '';
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || strlen( $host ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host ) || filter_var( $host, FILTER_VALIDATE_IP ) || preg_match( '/(?:^|\.)(?:localhost|local|internal|test|invalid|onion|lan|home|arpa)$/D', $host ) ) { throw new \InvalidArgumentException( 'invalid_url' ); }
		return $value;
	}
	/** Internal resources must use stable IDs; a raw URL cannot bypass PublicCatalog. */
	private static function externalTarget( string $url ): void {
		if ( '' === $url ) { return; }
		$host = strtolower( (string) ( wp_parse_url( $url )['host'] ?? '' ) );
		$homes = array( home_url( '/' ) );
		foreach ( self::LOCALES as $locale ) { $homes[] = Router::url( 'home', array(), $locale ); }
		foreach ( $homes as $home ) {
			$known = strtolower( (string) ( wp_parse_url( $home )['host'] ?? '' ) );
			if ( '' === $known ) { throw new \RuntimeException( 'site_origin_unavailable' ); }
			if ( $host === $known || str_ends_with( $host, '.' . $known ) ) { throw new \InvalidArgumentException( 'internal_target_requires_skill' ); }
		}
	}
	private static function date( mixed $value ): string {
		if ( '' === $value ) { return ''; }
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) { throw new \InvalidArgumentException( 'invalid_period' ); }
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $d || $d->format( 'Y-m-d H:i:s' ) !== $value || $value < '1970-01-01 00:00:00' ) { throw new \InvalidArgumentException( 'invalid_period' ); } return $value;
	}
	public static function validate( array $data ): array {
		if ( array_diff( array_keys( $data ), array_keys( self::blank() ) ) || ! in_array( $data['status'] ?? null, array( 'draft', 'enabled', 'paused' ), true ) || ! in_array( $data['placement'] ?? null, self::PLACEMENTS, true ) || ! is_array( $data['translations'] ?? null ) || array_diff( array_keys( $data['translations'] ), self::LOCALES ) ) { throw new \InvalidArgumentException( 'invalid_sponsor' ); }
		$r = self::blank(); $r['status'] = $data['status']; $r['placement'] = $data['placement'];
		foreach ( array( 'name' => 120, 'image_evidence' => 1000, 'review_note' => 1000 ) as $field => $limit ) { $r[ $field ] = Settings::text( $data[ $field ] ?? '', $limit ); }
		foreach ( array( 'skill_id', 'image_id' ) as $field ) { $r[ $field ] = Settings::integer( $data[ $field ] ?? 0, 0, PHP_INT_MAX ); }
		$r['url'] = self::httpsUrl( $data['url'] ?? '' ); self::externalTarget( $r['url'] );
		if ( $r['skill_id'] && $r['url'] ) { throw new \InvalidArgumentException( 'ambiguous_target' ); }
		$r['starts_at'] = self::date( $data['starts_at'] ?? '' ); $r['ends_at'] = self::date( $data['ends_at'] ?? '' );
		if ( $r['starts_at'] && $r['ends_at'] && $r['starts_at'] >= $r['ends_at'] ) { throw new \InvalidArgumentException( 'invalid_period' ); }
		$complete = 0;
		foreach ( self::LOCALES as $locale ) {
			$tr = $data['translations'][ $locale ] ?? array(); if ( ! is_array( $tr ) || array_diff( array_keys( $tr ), array( 'title', 'description' ) ) ) { throw new \InvalidArgumentException( 'invalid_translation' ); }
			$r['translations'][ $locale ] = array( 'title' => Settings::text( $tr['title'] ?? '', 160 ), 'description' => Settings::text( $tr['description'] ?? '', 400 ) );
			if ( '' !== $r['translations'][ $locale ]['title'] && '' !== $r['translations'][ $locale ]['description'] ) { ++$complete; }
		}
		if ( 'enabled' === $r['status'] && ( '' === $r['name'] || ! $complete || ( ! $r['skill_id'] && ! $r['url'] ) || ! $r['ends_at'] || '' === $r['review_note'] || ( $r['image_id'] && '' === $r['image_evidence'] ) ) ) { throw new \InvalidArgumentException( 'incomplete_sponsor' ); }
		return $r;
	}
	private static function decode( mixed $raw ): array {
		if ( false === $raw || '' === $raw ) { return self::defaults(); }
		if ( ! is_string( $raw ) || strlen( $raw ) > 196608 ) { throw new \RuntimeException( 'sponsors_unavailable' ); }
		$r = json_decode( $raw, true, 12, JSON_THROW_ON_ERROR );
		if ( ! is_array( $r ) || ! is_int( $r['revision'] ?? null ) || $r['revision'] < 0 || ! is_bool( $r['enabled'] ?? null ) || ! is_int( $r['next_id'] ?? null ) || $r['next_id'] < 1 || ! is_array( $r['entries'] ?? null ) || ! array_is_list( $r['entries'] ) || count( $r['entries'] ) > self::LIMIT ) { throw new \RuntimeException( 'sponsors_unavailable' ); }
		$ids = array(); foreach ( $r['entries'] as &$entry ) {
			if ( ! is_array( $entry ) || ! is_int( $entry['id'] ?? null ) || $entry['id'] < 1 || $entry['id'] >= $r['next_id'] || isset( $ids[ $entry['id'] ] ) || ! is_int( $entry['revision'] ?? null ) || $entry['revision'] < 1 || $entry['revision'] > $r['revision'] || ! is_int( $entry['reviewed_by'] ?? null ) || ! is_string( $entry['reviewed_at'] ?? null ) ) { throw new \RuntimeException( 'sponsors_unavailable' ); }
			$ids[ $entry['id'] ] = true; $payload = array_intersect_key( $entry, self::blank() ); $entry = array_merge( $entry, self::validate( $payload ) );
			if ( 'enabled' === $entry['status'] && ( $entry['reviewed_by'] < 1 || ! self::date( $entry['reviewed_at'] ) ) ) { throw new \RuntimeException( 'sponsors_unavailable' ); }
		} unset( $entry ); return $r;
	}
	private function state(): array {
		$table = $this->db->connection()->options; if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
		return self::decode( $this->db->value( "SELECT option_value FROM $table WHERE option_name=%s", array( self::OPTION ) ) ?? false );
	}
	public function snapshot(): array { $this->authorize(); return $this->state(); }
	public static function displayState( array $entry, ?string $now = null ): string {
		$now ??= gmdate( 'Y-m-d H:i:s' );
		if ( 'enabled' !== $entry['status'] ) { return $entry['status']; }
		if ( $entry['ends_at'] && $entry['ends_at'] <= $now ) { return 'expired'; }
		if ( $entry['starts_at'] && $entry['starts_at'] > $now ) { return 'scheduled'; }
		return 'enabled';
	}
	/** Only locally stored raster attachments; no remote URL, SVG, executable or symlink escape. */
	public static function image( int $id ): string {
		if ( ! $id ) { return ''; }
		$post = get_post( $id ); $uploads = wp_upload_dir(); $file = get_attached_file( $id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) || '' !== $post->post_password || metadata_exists( 'post', $id, '_aish_avatar_owner' ) || ! in_array( $post->post_mime_type, array( 'image/png', 'image/jpeg', 'image/webp', 'image/avif' ), true ) || ! empty( $uploads['error'] ) || ! is_string( $file ) ) { throw new \InvalidArgumentException( 'invalid_image' ); }
		if ( (int) $post->post_parent > 0 ) { $parent = get_post( (int) $post->post_parent ); if ( ! $parent instanceof \WP_Post || 'attachment' === $parent->post_type || ! is_post_publicly_viewable( $parent ) || '' !== $parent->post_password ) { throw new \InvalidArgumentException( 'invalid_image' ); } }
		$base = realpath( $uploads['basedir'] ); $path = realpath( $file );
		if ( ! is_string( $base ) || ! is_string( $path ) || ! str_starts_with( $path, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) || ! is_file( $path ) || filesize( $path ) > 1048576 ) { throw new \InvalidArgumentException( 'invalid_image' ); }
		$types = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif' );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! isset( $types[ $extension ] ) || $types[ $extension ] !== $post->post_mime_type || preg_match( '/\.(?:php[0-9]?|phtml|phar|cgi|pl|py|sh|shtml)(?:\.|$)/i', basename( $path ) ) ) { throw new \InvalidArgumentException( 'invalid_image' ); }
		$size = @getimagesize( $path );
		if ( ! is_array( $size ) || ! in_array( $size['mime'] ?? '', array( 'image/png', 'image/jpeg', 'image/webp', 'image/avif' ), true ) || $size['mime'] !== $post->post_mime_type || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096 ) { throw new \InvalidArgumentException( 'invalid_image' ); }
		$url = wp_get_attachment_url( $id ); $origin = wp_parse_url( home_url( '/' ) ); $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
		if ( ! is_array( $parts ) || ! is_array( $origin ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || ( $parts['scheme'] ?? '' ) !== ( $origin['scheme'] ?? '' ) || ( $parts['host'] ?? '' ) !== ( $origin['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $origin['port'] ?? null ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! str_starts_with( $url, rtrim( $uploads['baseurl'], '/' ) . '/' ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { throw new \InvalidArgumentException( 'invalid_image' ); }
		$uploadPath = (string) ( wp_parse_url( $uploads['baseurl'] )['path'] ?? '' );
		$expectedPath = rtrim( $uploadPath, '/' ) . '/' . str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( rtrim( $base, DIRECTORY_SEPARATOR ) ) + 1 ) );
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || rawurldecode( (string) ( $parts['path'] ?? '' ) ) !== $expectedPath ) { throw new \InvalidArgumentException( 'invalid_image' ); }
		return $url;
	}
	/** No projection uses stored Skill snapshots or borrowed translations. */
	private function project( array $entry, string $locale ): ?array {
		$tr = $entry['translations'][ $locale ]; if ( '' === $tr['title'] || '' === $tr['description'] || '' === $entry['name'] ) { return null; }
		$url = $entry['url'];
		if ( $entry['skill_id'] ) {
			// A fresh projection also protects callers that mutate a Skill earlier in this request.
			$cards = ( new PublicCatalog( $this->db, $this->migrator ) )->cardsByIds( array( $entry['skill_id'] ), $locale );
			if ( ! $cards ) { return null; } $url = Router::url( 'skill', array( 'id' => $entry['skill_id'], 'slug' => $cards[0]['slug'] ), $locale );
		}
		if ( '' === $url ) { return null; }
		return array( 'id' => $entry['id'], 'revision' => $entry['revision'], 'placement' => $entry['placement'], 'locale' => $locale, 'name' => $entry['name'], 'title' => $tr['title'], 'description' => $tr['description'], 'url' => $url, 'skill_id' => $entry['skill_id'], 'image_url' => self::image( $entry['image_id'] ), 'starts_at' => $entry['starts_at'], 'ends_at' => $entry['ends_at'] );
	}
	public function publicEntry( int $id, string $locale ): ?array {
		try {
			if ( ! in_array( $locale, self::LOCALES, true ) || ! $this->migrator->isCurrent() ) { return null; } $state = $this->state();
			if ( ! $state['enabled'] ) { return null; }
			foreach ( $state['entries'] as $entry ) { if ( $entry['id'] === $id && 'enabled' === self::displayState( $entry ) ) { return $this->project( $entry, $locale ); } }
		} catch ( \Throwable $error ) { /* Optional display fails closed without disrupting the free page. */ } return null;
	}
	public function forPlacement( string $placement, string $locale ): array {
		if ( ! in_array( $placement, self::PLACEMENTS, true ) || ! in_array( $locale, self::LOCALES, true ) ) { return array(); }
		try { $state = $this->state(); if ( ! $state['enabled'] || ! $this->migrator->isCurrent() ) { return array(); } $rows = array(); foreach ( $state['entries'] as $entry ) { if ( $placement === $entry['placement'] && 'enabled' === self::displayState( $entry ) ) { $row = $this->project( $entry, $locale ); if ( $row ) { $rows[] = $row; } if ( count( $rows ) >= 2 ) { break; } } } return $rows; } catch ( \Throwable $error ) { return array(); }
	}
	/** Administrator-only preview uses live Skill gates, never a public route. */
	public function preview( int $id, string $locale ): ?array { $this->authorize(); if ( ! in_array( $locale, self::LOCALES, true ) ) { throw new \InvalidArgumentException( 'invalid_locale' ); } foreach ( $this->state()['entries'] as $entry ) { if ( $entry['id'] === $id ) { return $this->project( $entry, $locale ); } } return null; }
	public function history(): array { $this->authorize(); return $this->db->rows( 'SELECT subject_id,subject_revision,action,actor_user_id,created_at,from_state,to_state,metadata_json FROM ' . $this->db->table( 'workflow_events' ) . " WHERE subject_type='sponsor_configuration' ORDER BY id DESC LIMIT 40" ); }
	private function change( int $expected, callable $operation ): array {
		$this->authorize(); Settings::integer( $expected, 0, PHP_INT_MAX - 2 ); $table = $this->db->connection()->options;
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$lock = 'aish_sponsors:' . substr( hash( 'sha256', $table ), 0, 45 ); $held = false;
		// Keep wpdb from reconnecting and replaying a write after its advisory lock was lost.
		$driverMode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $driverMode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 2)', array( $lock ) ); if ( ! $held ) { throw new \RuntimeException( 'busy' ); }
			if ( 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) ) ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
			return $this->db->transaction( function () use ( $expected, $operation, $table ): array {
				$this->authorize(); $row = $this->db->row( "SELECT option_value FROM $table WHERE option_name=%s FOR UPDATE", array( self::OPTION ) ); $state = self::decode( $row['option_value'] ?? false );
				if ( $state['revision'] !== $expected ) { throw new \RuntimeException( 'conflict' ); }
				$state = $operation( $state ); $json = wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $json ) || strlen( $json ) > 196608 ) { throw new \InvalidArgumentException( 'sponsors_too_large' ); }
				$this->db->execute( "INSERT INTO $table (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", array( self::OPTION, $json ) ); return $state;
			} );
		} finally { try { foreach ( array( self::OPTION, 'notoptions', 'alloptions' ) as $key ) { wp_cache_delete( $key, 'options' ); } if ( $held ) { try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $error ) {} } } finally { if ( null !== $driverMode ) { mysqli_report( $driverMode ); } } }
	}
	public function setEnabled( bool $enabled, int $expected ): array {
		return $this->change( $expected, function ( array $state ) use ( $enabled ): array { if ( $state['enabled'] === $enabled ) { return $state; } ++$state['revision']; $old = $state['enabled']; $state['enabled'] = $enabled;
			( new Events( $this->db ) )->record( hash( 'sha256', 'sponsors:' . $state['revision'] ), 'sponsor_configuration', 1, $state['revision'], $enabled ? 'sponsor_display_enabled' : 'sponsor_display_disabled', get_current_user_id(), $old ? 'enabled' : 'disabled', $enabled ? 'enabled' : 'disabled', array( 'kind' => 'global' ) ); return $state; } );
	}
	public function saveEntry( int $id, int $expected, array $payload, bool $reviewConfirmed ): array {
		Settings::integer( $id, 0, PHP_INT_MAX - 2 ); $data = self::validate( $payload );
		return $this->change( $expected, function ( array $state ) use ( $id, $data, $reviewConfirmed ): array {
			$index = null; foreach ( $state['entries'] as $i => $entry ) { if ( $entry['id'] === $id ) { $index = $i; break; } }
			if ( $id && null === $index ) { throw new \InvalidArgumentException( 'sponsor_missing' ); }
			if ( ! $id && count( $state['entries'] ) >= self::LIMIT ) { throw new \InvalidArgumentException( 'sponsor_limit' ); }
			$old = null === $index ? null : $state['entries'][ $index ];
			if ( $old && array_intersect_key( $old, self::blank() ) === $data ) { return $state; }
			if ( 'enabled' === $data['status'] ) {
				if ( ! $reviewConfirmed || $data['ends_at'] <= gmdate( 'Y-m-d H:i:s' ) ) { throw new \InvalidArgumentException( 'review_required' ); }
				self::image( $data['image_id'] );
				foreach ( self::LOCALES as $locale ) { $tr = $data['translations'][ $locale ]; if ( '' !== $tr['title'] && '' !== $tr['description'] && ! $this->project( $data + array( 'id' => $id ?: $state['next_id'], 'revision' => $state['revision'] + 1 ), $locale ) ) { throw new \InvalidArgumentException( 'skill_unavailable' ); } }
			}
			++$state['revision']; $id = $id ?: $state['next_id']++; $entry = $data + array( 'id' => $id, 'revision' => $state['revision'], 'reviewed_by' => 'enabled' === $data['status'] ? get_current_user_id() : 0, 'reviewed_at' => 'enabled' === $data['status'] ? gmdate( 'Y-m-d H:i:s' ) : '' );
			if ( null === $index ) { $state['entries'][] = $entry; } else { $state['entries'][ $index ] = $entry; }
			( new Events( $this->db ) )->record( hash( 'sha256', 'sponsors:' . $state['revision'] ), 'sponsor_configuration', $id, $state['revision'], 'sponsor_' . $data['status'] . '_saved', get_current_user_id(), $old['status'] ?? null, $data['status'], array( 'payload_hash' => hash( 'sha256', (string) wp_json_encode( $entry ) ), 'kind' => 'placement' ) ); return $state;
		} );
	}
}
