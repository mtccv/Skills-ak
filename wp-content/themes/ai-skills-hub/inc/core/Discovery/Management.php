<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Discovery;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** Editorial domain only: never changes Skills, reviews, translations or source snapshots. */
final class Management {
	public function __construct( private Database $db, private Migrator $migrator ) {}
	private function authorize(): int {
		if ( ! current_user_can( 'aish_manage_discovery' ) || get_current_user_id() < 1 ) { throw new \RuntimeException( 'Discovery management permission required.' ); }
		if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'Discovery schema upgrade required.' ); }
		return get_current_user_id();
	}
	private function tables( string $kind ): array {
		Input::kind( $kind );
		return 'collection' === $kind ? array( 'editorial_collections', 'collection_translations', 'collection_items', 'collection_id' ) : array( 'use_cases', 'use_case_translations', 'use_case_skills', 'use_case_id' );
	}
	public static function optionName( string $kind, int $id ): string { return 'aish_discovery_' . Input::kind( $kind ) . '_' . Input::integer( $id, 1 ); }
	private function optionsTable(): string {
		$table = $this->db->connection()->options;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new \RuntimeException( 'Unsupported WordPress options table.' ); }
		return '`' . $table . '`';
	}
	private function transactionalOptions(): void {
		$table = trim( $this->optionsTable(), '`' );
		if ( 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) ) ) ) { throw new \RuntimeException( 'Editorial metadata requires transactional WordPress options.' ); }
	}
	private function clearOption( string $key ): void {
		foreach ( array( $key, 'alloptions', 'notoptions' ) as $cache ) { wp_cache_delete( $cache, 'options' ); }
	}
	/** Public, nonsensitive scheduling metadata. Invalid metadata fails closed at the reader. */
	public function extras( string $kind, int $id ): array {
		$key = self::optionName( $kind, $id );
		$value = $this->db->value( 'SELECT option_value FROM ' . $this->optionsTable() . ' WHERE option_name=%s', array( $key ) );
		if ( null === $value ) { return array( 'ends_at' => null, 'tutorial_ids' => array() ); }
		$data = json_decode( (string) $value, true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) ) { throw new \RuntimeException( 'Invalid editorial metadata.' ); }
		return array( 'ends_at' => Input::date( $data['ends_at'] ?? null ), 'tutorial_ids' => Input::ids( $data['tutorial_ids'] ?? array(), 20 ) );
	}
	public function detail( string $kind, int $id ): ?array {
		$this->authorize(); Input::integer( $id, 1 );
		[ $entity, $translations, $items, $foreign ] = $this->tables( $kind );
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( $entity ) . ' WHERE id=%d', array( $id ) );
		if ( ! $row ) { return null; }
		$row['translations'] = array();
		foreach ( $this->db->rows( 'SELECT * FROM ' . $this->db->table( $translations ) . " WHERE $foreign=%d ORDER BY locale", array( $id ) ) as $translation ) { $row['translations'][ $translation['locale'] ] = $translation; }
		$order = 'collection' === $kind ? 'sort_order,skill_id' : 'skill_id';
		$row['skills'] = array_map( 'intval', array_column( $this->db->rows( 'SELECT skill_id FROM ' . $this->db->table( $items ) . " WHERE $foreign=%d ORDER BY $order", array( $id ) ), 'skill_id' ) );
		return $row + $this->extras( $kind, $id );
	}
	/** Returns rows and total; translations are loaded in one bounded batch, never per row. */
	public function list( string $kind, array $filters = array() ): array {
		$this->authorize(); [ $entity, $translation, , $foreign ] = $this->tables( $kind );
		$page = Input::integer( $filters['page'] ?? 1, 1, 100000 ); $size = Input::integer( $filters['per_page'] ?? 20, 1, 100 );
		$where = '1=1'; $values = array();
		if ( ! empty( $filters['status'] ) ) { if ( ! in_array( $filters['status'], array( 'draft', 'published', 'archived' ), true ) ) { throw new \InvalidArgumentException( 'Invalid editorial filter.' ); } $where .= ' AND status=%s'; $values[] = $filters['status']; }
		$query = Input::text( $filters['q'] ?? '', 100 );
		if ( '' !== $query ) { $name = 'collection' === $kind ? 'title' : 'name'; $where .= ' AND EXISTS (SELECT 1 FROM ' . $this->db->table( $translation ) . " t WHERE t.$foreign=e.id AND t.$name LIKE %s)"; $values[] = '%' . addcslashes( $query, '_%\\' ) . '%'; }
		$total = (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( $entity ) . " e WHERE $where", $values );
		$rows = $this->db->rows( 'SELECT e.* FROM ' . $this->db->table( $entity ) . " e WHERE $where ORDER BY sort_order,id DESC LIMIT %d OFFSET %d", array_merge( $values, array( $size, ( $page - 1 ) * $size ) ) );
		$byId = array();
		if ( $rows ) {
			$ids = array_map( 'intval', array_column( $rows, 'id' ) ); $holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			foreach ( $this->db->rows( 'SELECT * FROM ' . $this->db->table( $translation ) . " WHERE $foreign IN ($holders)", $ids ) as $row ) { $byId[ $row[ $foreign ] ][ $row['locale'] ] = $row; }
		}
		foreach ( $rows as &$row ) { $row['translations'] = $byId[ $row['id'] ] ?? array(); } unset( $row );
		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $size );
	}
	public function save( string $kind, int $id, array $payload, int $expectedRevision ): array {
		$actor = $this->authorize(); Input::integer( $id ); Input::integer( $expectedRevision );
		$data = Input::entity( $kind, $payload ); [ $entity, $translations, $items, $foreign ] = $this->tables( $kind );
		$this->transactionalOptions(); $key = null;
		try {
			$id = $this->db->transaction( function () use ( $kind, $id, $data, $actor, $expectedRevision, $entity, $translations, $items, $foreign, &$key ): int {
				$table = $this->db->table( $entity ); $now = gmdate( 'Y-m-d H:i:s' );
				$row = $id ? $this->db->row( "SELECT * FROM $table WHERE id=%d FOR UPDATE", array( $id ) ) : null;
				if ( ( $id && ( ! $row || (int) $row['revision'] !== $expectedRevision ) ) || ( ! $id && 0 !== $expectedRevision ) ) { throw new \RuntimeException( 'Editorial revision conflict; reload before saving.' ); }
				// Lock references in one consistent order before replacing the membership set.
				$ids = $data['skills']; sort( $ids, SORT_NUMERIC );
				if ( $ids ) { $holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) ); $found = $this->db->rows( 'SELECT id FROM ' . $this->db->table( 'skills' ) . " WHERE id IN ($holders) ORDER BY id FOR UPDATE", $ids ); if ( count( $found ) !== count( $ids ) ) { throw new \InvalidArgumentException( 'An editorial Skill reference does not exist.' ); } }
				$tutorialIds = $data['tutorial_ids']; sort( $tutorialIds, SORT_NUMERIC );
				foreach ( $tutorialIds as $tutorial ) { if ( ! $this->db->value( 'SELECT ID FROM ' . $this->db->connection()->posts . " WHERE ID=%d AND post_type='aish_tutorial' FOR UPDATE", array( $tutorial ) ) ) { throw new \InvalidArgumentException( 'An editorial tutorial reference does not exist.' ); } }
				$update = array( 'status' => $data['status'], 'sort_order' => $data['sort_order'], 'updated_at' => $now );
				if ( 'collection' === $kind ) { $update['published_at'] = $data['published_at'] ?? ( 'published' === $data['status'] ? ( $row['published_at'] ?? $now ) : null ); }
				if ( $id ) { $update['revision'] = $expectedRevision + 1; $this->db->updateById( $entity, $id, $update ); }
				else { $update += array( 'revision' => 1, 'created_at' => $now ); if ( 'collection' === $kind ) { $update['owner_user_id'] = $actor; } $id = $this->db->insert( $entity, $update ); }
				$old = array();
				foreach ( $this->db->rows( 'SELECT * FROM ' . $this->db->table( $translations ) . " WHERE $foreign=%d", array( $id ) ) as $translation ) { $old[ $translation['locale'] ] = $translation; }
				foreach ( $old as $locale => $translation ) {
					// A chosen identity survives draft/archive/reactivation, including use cases
					// whose planned table has no first-publication timestamp.
					if ( null !== $translation['slug'] && ( ! isset( $data['translations'][ $locale ] ) || $data['translations'][ $locale ]['slug'] !== $translation['slug'] ) ) { throw new \InvalidArgumentException( 'Editorial slugs are stable; retain the translation and slug.' ); }
				}
				foreach ( $data['translations'] as $locale => $translation ) {
					$translation['updated_at'] = $now;
					if ( isset( $old[ $locale ] ) ) { $this->db->updateById( $translations, (int) $old[ $locale ]['id'], $translation ); }
					else { $this->db->insert( $translations, $translation + array( $foreign => $id, 'locale' => $locale, 'created_at' => $now ) ); }
				}
				foreach ( array_diff( array_keys( $old ), array_keys( $data['translations'] ) ) as $locale ) { $this->db->execute( 'DELETE FROM ' . $this->db->table( $translations ) . " WHERE $foreign=%d AND locale=%s", array( $id, $locale ) ); }
				$this->db->execute( 'DELETE FROM ' . $this->db->table( $items ) . " WHERE $foreign=%d", array( $id ) );
				foreach ( $data['skills'] as $position => $skill ) { $item = array( $foreign => $id, 'skill_id' => $skill, 'created_at' => $now ); if ( 'collection' === $kind ) { $item += array( 'sort_order' => $position, 'added_by' => $actor ); } else { $item['assigned_by'] = $actor; } $this->db->insert( $items, $item ); }
				$key = self::optionName( $kind, $id ); $json = json_encode( array( 'ends_at' => $data['ends_at'], 'tutorial_ids' => $data['tutorial_ids'] ), JSON_THROW_ON_ERROR );
				$this->db->execute( 'INSERT INTO ' . $this->optionsTable() . " (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", array( $key, $json ) );
				return $id;
			} );
		} finally { if ( $key ) { $this->clearOption( $key ); } }
		return $this->detail( $kind, $id ) ?? throw new \RuntimeException( 'Editorial record unavailable after save.' );
	}
	public function archive( string $kind, int $id, int $expectedRevision ): array {
		$this->authorize(); [ $entity ] = $this->tables( $kind ); Input::integer( $id, 1 ); Input::integer( $expectedRevision, 1 );
		$this->db->transaction( function () use ( $entity, $id, $expectedRevision ): void {
			$table = $this->db->table( $entity );
			if ( 1 !== $this->db->execute( "UPDATE $table SET status='archived',revision=revision+1,updated_at=%s WHERE id=%d AND revision=%d", array( gmdate( 'Y-m-d H:i:s' ), $id, $expectedRevision ) ) ) { throw new \RuntimeException( 'Editorial revision conflict; reload before archiving.' ); }
		} );
		return $this->detail( $kind, $id ) ?? throw new \RuntimeException( 'Editorial record unavailable.' );
	}
	public function termTranslations( string $kind, int $id ): array {
		$this->authorize(); Input::termKind( $kind ); Input::integer( $id, 1 ); $result = array();
		foreach ( $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'term_translations' ) . ' WHERE term_type=%s AND term_id=%d', array( $kind, $id ) ) as $row ) { $result[ $row['locale'] ] = $row; }
		return $result;
	}
	public static function termDigest( array $translations ): string {
		$fields = array();
		foreach ( array( 'en_US', 'zh_CN' ) as $locale ) { if ( isset( $translations[ $locale ] ) ) { $fields[ $locale ] = array( 'name' => (string) $translations[ $locale ]['name'], 'description' => (string) $translations[ $locale ]['description'] ); } }
		return hash( 'sha256', json_encode( $fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ) );
	}
	public function saveTerm( string $kind, int $id, array $translations, string $expectedDigest ): void {
		$this->authorize(); Input::termKind( $kind ); Input::integer( $id, 1 ); $clean = Input::translations( $translations, $kind );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expectedDigest ) ) { throw new \InvalidArgumentException( 'Invalid translation revision.' ); }
		$this->db->transaction( function () use ( $kind, $id, $clean, $expectedDigest ): void {
			$term = $this->db->table( 'category' === $kind ? 'categories' : 'tags' ); $table = $this->db->table( 'term_translations' ); $now = gmdate( 'Y-m-d H:i:s' );
			if ( ! $this->db->row( "SELECT id FROM $term WHERE id=%d FOR UPDATE", array( $id ) ) ) { throw new \InvalidArgumentException( 'Unknown discovery term.' ); }
			$existing = array(); foreach ( $this->db->rows( "SELECT * FROM $table WHERE term_type=%s AND term_id=%d", array( $kind, $id ) ) as $row ) { $existing[ $row['locale'] ] = $row; }
			if ( ! hash_equals( self::termDigest( $existing ), $expectedDigest ) ) { throw new \RuntimeException( 'Translation revision conflict; reload before saving.' ); }
			foreach ( $clean as $locale => $row ) { $this->db->execute( "INSERT INTO $table (term_type,term_id,locale,name,description,created_at,updated_at) VALUES (%s,%d,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),updated_at=VALUES(updated_at)", array( $kind, $id, $locale, $row['name'], $row['description'], $now, $now ) ); }
			foreach ( array_diff( array( 'zh_CN', 'en_US' ), array_keys( $clean ) ) as $locale ) { $this->db->execute( "DELETE FROM $table WHERE term_type=%s AND term_id=%d AND locale=%s", array( $kind, $id, $locale ) ); }
		} );
	}
}
