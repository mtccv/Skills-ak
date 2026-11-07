<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Commercial;

use AISkillsHub\Core\Community\Events;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Discovery\Query;
use AISkillsHub\Core\Discovery\Settings;

defined( 'ABSPATH' ) || exit;

/** Optional presentation over existing editorial collections, never a product or Skill store. */
final class Bundles {
	public const CAPABILITY = 'aish_manage_discovery';
	public const OPTION = 'aish_commercial_bundles';
	public const MAX_ITEMS = 12;
	public function __construct( private Database $db, private Migrator $migrator, private Query $query ) {}
	public static function defaults(): array { return array( 'revision' => 0, 'state' => 'draft', 'collection_ids' => array(), 'reason' => '', 'updated_at' => '', 'actor_id' => 0 ); }
	private function authorize(): int {
		if ( ! current_user_can( self::CAPABILITY ) || get_current_user_id() < 1 || ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'forbidden' ); }
		return get_current_user_id();
	}
	private function optionsTable(): string {
		$t = $this->db->connection()->options;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $t ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
		return '`' . $t . '`';
	}
	private static function decode( mixed $raw ): array {
		if ( false === $raw || null === $raw ) { return self::defaults(); }
		if ( ! is_string( $raw ) || strlen( $raw ) > 8192 ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$r = json_decode( $raw, true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $r ) || array_diff( array_keys( $r ), array_keys( self::defaults() ) ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$r['revision'] = Settings::integer( $r['revision'] ?? null, 0, PHP_INT_MAX - 1 );
		$r['actor_id'] = Settings::integer( $r['actor_id'] ?? null, 0, PHP_INT_MAX );
		$r['updated_at'] = Settings::text( $r['updated_at'] ?? '', 19 );
		return array_replace( $r, self::validate( array_intersect_key( $r, array_flip( array( 'state', 'collection_ids', 'reason' ) ) ) ) );
	}
	public static function validate( array $data ): array {
		if ( array_diff( array_keys( $data ), array( 'state', 'collection_ids', 'reason' ) ) || ! in_array( $data['state'] ?? '', array( 'draft', 'enabled', 'paused' ), true ) || ! is_array( $data['collection_ids'] ?? null ) || ! array_is_list( $data['collection_ids'] ) || count( $data['collection_ids'] ) > self::MAX_ITEMS ) { throw new \InvalidArgumentException( 'invalid_bundle_configuration' ); }
		$ids = array_map( static fn( mixed $id ): int => Settings::integer( $id, 1, PHP_INT_MAX ), $data['collection_ids'] );
		if ( count( array_unique( $ids ) ) !== count( $ids ) || ( 'enabled' === $data['state'] && ! $ids ) ) { throw new \InvalidArgumentException( 'invalid_bundle_selection' ); }
		return array( 'state' => $data['state'], 'collection_ids' => $ids, 'reason' => Settings::text( $data['reason'] ?? '', 500 ) );
	}
	private function read(): array {
		return self::decode( $this->db->value( 'SELECT option_value FROM ' . $this->optionsTable() . ' WHERE option_name=%s', array( self::OPTION ) ) );
	}
	public function configuration(): array { $this->authorize(); return $this->read(); }
	private static function digest( array $data ): string { return hash( 'sha256', wp_json_encode( array_intersect_key( $data, array_flip( array( 'state', 'collection_ids', 'reason' ) ) ) ) ); }

	/** Optimistic revision plus the options unique key serialize first and subsequent saves. */
	public function save( array $input, int $expectedRevision, bool $confirmed ): array {
		$actor = $this->authorize(); $data = self::validate( $input ); Settings::integer( $expectedRevision, 0, PHP_INT_MAX - 2 );
		if ( ! $confirmed || '' === $data['reason'] ) { throw new \InvalidArgumentException( 'review_confirmation_required' ); }
		$table = $this->optionsTable();
		if ( 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( trim( $table, '`' ) ) ) ) ) { throw new \RuntimeException( 'transactional_options_required' ); }
		try {
			return $this->db->transaction( function () use ( $actor, $data, $expectedRevision, $table ): array {
				$this->authorize();
				// A duplicate INSERT IGNORE takes a shared unique-key lock; concurrent
				// writers can deadlock while upgrading it for SELECT FOR UPDATE.
				// The no-op duplicate update takes the exclusive row lock directly,
				// without resetting the existing value, revision or autoload setting.
				$this->db->execute( "INSERT INTO $table (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)", array( self::OPTION, wp_json_encode( self::defaults() ) ) );
				$row = $this->db->row( "SELECT option_value FROM $table WHERE option_name=%s FOR UPDATE", array( self::OPTION ) );
				if ( ! $row ) { throw new \RuntimeException( 'storage_unavailable' ); }
				$old = self::decode( $row['option_value'] );
				if ( $old['revision'] === $expectedRevision + 1 && $old['actor_id'] === $actor && hash_equals( self::digest( $old ), self::digest( $data ) ) ) { return $old; }
				if ( $old['revision'] !== $expectedRevision ) { throw new \RuntimeException( 'revision_conflict' ); }
				if ( $data['collection_ids'] ) {
					$ids = $data['collection_ids']; sort( $ids, SORT_NUMERIC ); $holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					$found = $this->db->rows( 'SELECT id FROM ' . $this->db->table( 'editorial_collections' ) . " WHERE id IN ($holders) ORDER BY id FOR UPDATE", $ids );
					if ( count( $found ) !== count( $ids ) ) { throw new \InvalidArgumentException( 'collection_unavailable' ); }
					if ( 'enabled' === $data['state'] ) {
						$public = array(); foreach ( array( 'zh_CN', 'en_US' ) as $locale ) { $public = array_merge( $public, array_column( $this->query->topicsByIds( 'collection', $ids, $locale ), 'id' ) ); }
						if ( array_diff( $ids, $public ) ) { throw new \InvalidArgumentException( 'public_collection_required' ); }
					}
				}
				$next = $data + array( 'revision' => $expectedRevision + 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'actor_id' => $actor );
				$this->db->execute( "UPDATE $table SET option_value=%s,autoload='off' WHERE option_name=%s", array( wp_json_encode( $next ), self::OPTION ) );
				( new Events( $this->db ) )->record( hash( 'sha256', 'bundle-configuration:' . $next['revision'] . ':' . self::digest( $next ) ), 'bundle_configuration', 1, $next['revision'], 'bundle_save', $actor, $old['state'], $next['state'], array( 'payload_hash' => self::digest( $next ) ) );
				return $next;
			} );
		} finally { foreach ( array( self::OPTION, 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); } }
	}

	/** Always re-query current publication, language, schedule and member gates. */
	public function publicEntries( string $locale ): array {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { return array(); }
		try {
			if ( ! $this->migrator->isCurrent() ) { return array(); }
			$data = $this->read();
			if ( 'enabled' !== $data['state'] || ! $data['collection_ids'] ) { return array(); }
			return $this->query->topicsByIds( 'collection', $data['collection_ids'], $locale );
		} catch ( \Throwable $error ) { return array(); }
	}
	public function history(): array {
		$this->authorize();
		return $this->db->rows( 'SELECT subject_revision,actor_user_id,created_at,from_state,to_state FROM ' . $this->db->table( 'workflow_events' ) . " WHERE subject_type='bundle_configuration' AND subject_id=1 ORDER BY id DESC LIMIT 20" );
	}
}
