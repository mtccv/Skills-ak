<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Content;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Language\LanguageProvider;
use AISkillsHub\Core\Security\Access;
use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Internal application API, not a REST/admin endpoint. Raw DAL is for trusted Core code only. */
final class Catalog {
	public function __construct( private Database $db, private Migrator $migrator, private LanguageProvider $languages ) {}

	private function ready( bool $write = false ): void {
		if ( $write ) {
			Access::write();
		}
		if ( ! $this->migrator->isCurrent() ) {
			throw new \DomainException( 'Core schema is not ready.' );
		}
	}

	private function locale( string $locale ): string {
		$locale = $this->languages->normalize( $locale );
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || ! in_array( $locale, $this->languages->supportedLocales(), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported content locale.' );
		}
		return $locale;
	}

	private function requireRow( string $entity, int $id, bool $lock = false ): array {
		$id    = Input::id( $id );
		$table = $this->db->table( $entity );
		$row   = $this->db->row( "SELECT * FROM $table WHERE id = %d" . ( $lock ? ' FOR UPDATE' : '' ), array( $id ) );
		if ( null === $row ) {
			throw new \DomainException( 'Referenced Core record does not exist.' );
		}
		return $row;
	}

	public function createRepository( string $owner, string $name ): int {
		$this->ready( true );
		list( $owner, $name ) = Input::repository( $owner, $name );
		$table = $this->db->table( 'repositories' );
		$this->db->execute( "INSERT INTO $table (owner,name,created_at) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", array( $owner, $name, gmdate( 'Y-m-d H:i:s' ) ) );
		return (int) $this->db->value( "SELECT id FROM $table WHERE owner=%s AND name=%s", array( $owner, $name ) );
	}

	public function getRepository( int $id ): array {
		$this->ready();
		return $this->requireRow( 'repositories', $id );
	}

	public function createSkill( int $repository_id, string $source_path, string $status = 'draft' ): int {
		$this->ready( true );
		$source_path = Input::sourcePath( $source_path );
		if ( ! in_array( $status, array( 'draft', 'pending', 'published', 'archived' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Skill status.' );
		}
		return $this->db->transaction( function () use ( $repository_id, $source_path, $status ) {
			$this->requireRow( 'repositories', $repository_id, true );
			$table = $this->db->table( 'skills' );
			$now   = gmdate( 'Y-m-d H:i:s' );
			$this->db->execute( "INSERT INTO $table (repository_id,source_path,status,created_at,updated_at) VALUES (%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", array( $repository_id, $source_path, $status, $now, $now ) );
			return (int) $this->db->value( "SELECT id FROM $table WHERE repository_id=%d AND source_path=%s", array( $repository_id, $source_path ) );
		} );
	}

	public function getSkill( int $id ): array {
		$this->ready();
		return $this->requireRow( 'skills', $id );
	}

	public function findSkillBySource( int $repository_id, string $source_path ): ?array {
		$this->ready();
		$table = $this->db->table( 'skills' );
		return $this->db->row( "SELECT * FROM $table WHERE repository_id=%d AND source_path=%s", array( Input::id( $repository_id ), Input::sourcePath( $source_path ) ) );
	}

	public function listSkills( int $limit = 20, int $offset = 0, string $sort = 'id', string $direction = 'ASC', string $search = '' ): array {
		$this->ready();
		if ( $limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000 || ! in_array( $sort, array( 'id', 'created_at', 'updated_at' ), true ) || ! in_array( $direction, array( 'ASC', 'DESC' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid pagination or sorting.' );
		}
		$search = Input::utf8( $search, 768 );
		$table  = $this->db->table( 'skills' );
		$like   = '%' . $this->db->connection()->esc_like( $search ) . '%';
		return $this->db->rows( "SELECT * FROM $table WHERE source_path LIKE %s ORDER BY $sort $direction, repository_id ASC LIMIT %d OFFSET %d", array( $like, $limit, $offset ) );
	}

	public function saveTranslation( int $skill_id, string $locale, string $title, string $slug, string $summary = '', string $content = '' ): int {
		$this->ready( true );
		$locale = $this->locale( $locale );
		$data   = array(
			'skill_id' => Input::id( $skill_id ), 'locale' => $locale,
			'title' => Input::plain( $title ), 'slug' => Input::slug( $slug ),
			'summary' => sanitize_textarea_field( Input::utf8( $summary, 16000 ) ),
			'content' => wp_kses_post( Input::utf8( $content, 524288 ) ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		return $this->db->transaction( function () use ( $skill_id, $locale, $data ) {
			$this->requireRow( 'skills', $skill_id, true );
			$existing = $this->getTranslation( $skill_id, $locale );
			if ( $existing ) {
				$this->db->updateById( 'skill_translations', (int) $existing['id'], $data );
				return (int) $existing['id'];
			}
			$data['created_at'] = $data['updated_at'];
			return $this->db->insert( 'skill_translations', $data );
		} );
	}

	public function getTranslation( int $skill_id, string $locale ): ?array {
		$this->ready();
		$table = $this->db->table( 'skill_translations' );
		return $this->db->row( "SELECT * FROM $table WHERE skill_id=%d AND locale=%s", array( Input::id( $skill_id ), $this->locale( $locale ) ) );
	}

	/** Versions are immutable snapshots. A conflicting payload for a commit is an error. */
	public function addVersion( int $skill_id, string $commit_sha, string $source_content, string $version_label = '' ): int {
		$this->ready( true );
		if ( ! preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $commit_sha ) ) {
			throw new \InvalidArgumentException( 'Invalid commit SHA.' );
		}
		$source_content = Input::utf8( $source_content, 524288 );
		$version_label  = Input::plain( $version_label, 191, true );
		$hash           = hash( 'sha256', $source_content );
		return $this->db->transaction( function () use ( $skill_id, $commit_sha, $source_content, $version_label, $hash ) {
			$this->requireRow( 'skills', $skill_id, true );
			$table    = $this->db->table( 'skill_versions' );
			$existing = $this->db->row( "SELECT * FROM $table WHERE skill_id=%d AND commit_sha=%s", array( $skill_id, $commit_sha ) );
			if ( $existing ) {
				if ( $existing['content_hash'] !== $hash ) {
					throw new \DomainException( 'Commit snapshot content conflicts with an existing version.' );
				}
				return (int) $existing['id'];
			}
			return $this->db->insert( 'skill_versions', array( 'skill_id' => $skill_id, 'commit_sha' => $commit_sha, 'content_hash' => $hash, 'source_content' => $source_content, 'version_label' => $version_label, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		} );
	}

	public function versionsForSkill( int $skill_id ): array {
		$this->ready();
		$table = $this->db->table( 'skill_versions' );
		return $this->db->rows( "SELECT * FROM $table WHERE skill_id=%d ORDER BY id DESC", array( Input::id( $skill_id ) ) );
	}

	public function createCategory( string $name, string $slug, ?int $parent_id = null ): int {
		$this->ready( true );
		$data = array( 'name' => Input::plain( $name ), 'slug' => Input::slug( $slug ), 'parent_id' => $parent_id, 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
		return $this->db->transaction( function () use ( $parent_id, $data ) {
			if ( null !== $parent_id ) {
				$this->requireRow( 'categories', $parent_id, true );
			}
			return $this->db->insert( 'categories', $data );
		} );
	}

	public function createTag( string $name, string $slug ): int {
		$this->ready( true );
		return $this->db->insert( 'tags', array( 'name' => Input::plain( $name ), 'slug' => Input::slug( $slug ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	private function termConfig( string $kind ): array {
		return match ( $kind ) {
			'category' => array( 'categories', 'skill_categories', 'category_id' ),
			'tag' => array( 'tags', 'skill_tags', 'tag_id' ),
			default => throw new \InvalidArgumentException( 'Unsupported term kind.' ),
		};
	}

	public function assignTerm( int $skill_id, string $kind, int $term_id ): void {
		$this->ready( true );
		list( $terms, $links, $column ) = $this->termConfig( $kind );
		$this->db->transaction( function () use ( $skill_id, $term_id, $terms, $links, $column ) {
			$this->requireRow( 'skills', $skill_id, true );
			$this->requireRow( $terms, $term_id, true );
			$table = $this->db->table( $links );
			$this->db->execute( "INSERT INTO $table (skill_id,$column) VALUES (%d,%d) ON DUPLICATE KEY UPDATE skill_id=VALUES(skill_id)", array( $skill_id, $term_id ) );
		} );
	}

	public function termsForSkill( int $skill_id, string $kind ): array {
		$this->ready();
		list( $terms, $links, $column ) = $this->termConfig( $kind );
		$terms = $this->db->table( $terms );
		$links = $this->db->table( $links );
		return $this->db->rows( "SELECT t.* FROM $terms t INNER JOIN $links l ON t.id=l.$column WHERE l.skill_id=%d ORDER BY t.id ASC", array( Input::id( $skill_id ) ) );
	}
}
