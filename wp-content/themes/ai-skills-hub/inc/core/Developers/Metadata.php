<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Developers;
use AISkillsHub\Core\Database\Database;
defined( 'ABSPATH' ) || exit;

/** Protected native usermeta, read without retaining uncommitted object-cache entries. */
final class Metadata {
	public const HISTORY_KEY = '_aish_profile_decision';
	public const KEYS = array( '_aish_public_profile', '_aish_profile_revision', '_aish_profile_review_status', '_aish_profile_translations', '_aish_profile_candidate', '_aish_profile_approved_snapshot', '_aish_profile_reviewed_revision', '_aish_profile_reviewer_id', '_aish_profile_reviewed_at', '_aish_profile_updated_at', '_aish_profile_feedback', '_aish_profile_private_note' );
	public function __construct( private Database $db ) {}
	public function read( int $id ): array {
		return $this->readMany( array( $id ) )[ $id ] ?? array();
	}
	public static function decode( string $raw ): mixed {
		if ( strlen( $raw ) > 131072 ) { throw new Exception( 'storage_unavailable' ); }
		$value = is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false, 'max_depth' => 16 ) ) : $raw;
		$inspect = function ( mixed $item, int $depth ) use ( &$inspect ): void { if ( $depth > 16 || is_object( $item ) || is_resource( $item ) ) { throw new Exception( 'storage_unavailable' ); } if ( is_array( $item ) ) { foreach ( $item as $child ) { $inspect( $child, $depth + 1 ); } } };
		$inspect( $value, 0 );
		if ( false === $value && 'b:0;' !== $raw ) { throw new Exception( 'storage_unavailable' ); }
		return $value;
	}
	/** One bounded read for an admin page; avoids one metadata query per result. */
	public function readMany( array $ids ): array {
		if ( ! $ids ) { return array(); } if ( count( $ids ) > 24 ) { throw new Exception( 'invalid_input' ); }
		foreach ( $ids as $id ) { Input::id( $id ); }
		$table = $this->db->connection()->usermeta;
		$rows = $this->db->rows( "SELECT user_id,meta_key,meta_value FROM $table WHERE user_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( self::KEYS ), '%s' ) ) . ')', array_merge( $ids, self::KEYS ) );
		$result = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['user_id'];
			if ( array_key_exists( $row['meta_key'], $result[ $id ] ?? array() ) ) { throw new Exception( 'storage_unavailable' ); }
			$result[ $id ][ $row['meta_key'] ] = self::decode( $row['meta_value'] );
		}
		return $result;
	}
	public function decision( int $id, array $decision ): void {
		if ( ! Service::writing() ) { throw new Exception( 'forbidden' ); }
		$json = wp_json_encode( $decision ); if ( ! is_string( $json ) || strlen( $json ) > 131072 ) { throw new Exception( 'storage_unavailable' ); }
		$metaId = add_user_meta( $id, self::HISTORY_KEY, wp_slash( $json ), false );
		if ( ! $metaId || $json !== $this->db->value( 'SELECT meta_value FROM ' . $this->db->connection()->usermeta . ' WHERE umeta_id=%d AND user_id=%d AND meta_key=%s', array( (int) $metaId, $id, self::HISTORY_KEY ) ) ) { throw new Exception( 'storage_unavailable' ); }
	}
	public function set( int $id, string $key, mixed $value ): void {
		if ( ! in_array( $key, self::KEYS, true ) || ! Service::writing() ) { throw new Exception( 'forbidden' ); }
		delete_user_meta( $id, $key );
		if ( false === add_user_meta( $id, $key, wp_slash( $value ), true ) ) { throw new Exception( 'storage_unavailable' ); }
		$table = $this->db->connection()->usermeta;
		$stored = array_column( $this->db->rows( "SELECT meta_value FROM $table WHERE user_id=%d AND meta_key=%s", array( $id, $key ) ), 'meta_value' );
		if ( array( (string) maybe_serialize( $value ) ) !== $stored ) { throw new Exception( 'storage_unavailable' ); }
	}
	public function remove( int $id, string $key ): void {
		if ( ! in_array( $key, self::KEYS, true ) || ! Service::writing() ) { throw new Exception( 'forbidden' ); }
		delete_user_meta( $id, $key );
		$table = $this->db->connection()->usermeta;
		if ( null !== $this->db->value( "SELECT umeta_id FROM $table WHERE user_id=%d AND meta_key=%s LIMIT 1", array( $id, $key ) ) ) { throw new Exception( 'storage_unavailable' ); }
	}
	public function nativeReady(): void {
		$c = $this->db->connection();
		foreach ( array( $c->users, $c->usermeta ) as $table ) {
			$row = $this->db->row( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) );
			if ( ! $row || 'innodb' !== strtolower( (string) $row['ENGINE'] ) ) { throw new Exception( 'storage_unavailable' ); }
		}
	}
	public static function draft( array $meta ): array {
		$candidate = $meta['_aish_profile_candidate'] ?? array(); $translations = $meta['_aish_profile_translations'] ?? array();
		if ( ! is_array( $candidate ) || ! is_array( $translations ) ) { throw new Exception( 'storage_unavailable' ); }
		return array_merge( array( 'handle' => '', 'github_url' => '', 'website_url' => '', 'public_avatar' => false, 'show_contributions' => false ), $candidate, array( 'translations' => $translations ) );
	}
}
