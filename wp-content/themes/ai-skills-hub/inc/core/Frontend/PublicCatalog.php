<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

use AISkillsHub\Core\Admin\Management;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Read-only public projection of Core data. Never expose the administrative Catalog directly. */
final class PublicCatalog {
	public function __construct( private Database $db, private Migrator $migrator ) {}

	private function ready(): void {
		if ( ! $this->migrator->isCurrent() ) {
			throw new \RuntimeException( 'Public catalog is temporarily unavailable.' );
		}
	}

	private function locale( string $locale ): string {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported public locale.' );
		}
		return $locale;
	}

	/** All public queries, including counts and alternative languages, share this predicate. */
	private function visibility(): string {
		return "s.status='published' AND s.review_status='approved' AND s.reviewed_revision=s.revision AND s.revision>0 AND s.reviewer_id>0 AND s.reviewed_at IS NOT NULL AND s.reviewed_at>='1970-01-01 00:00:00'";
	}

	private function translationVisibility(): string {
		// HTML-only and whitespace-only draft translations must not become public merely because
		// the other language was approved. MySQL 8's Unicode regexp handles stored HTML spaces.
		$spaces = '&(nbsp|NonBreakingSpace|ensp|emsp|emsp13|emsp14|numsp|puncsp|thinsp|hairsp|MediumSpace|ThickSpace|Tab|NewLine|#0*(9|10|11|12|13|32|160|5760|819[2-9]|820[0-2]|8232|8233|8239|8287|12288)|#x0*(9|a|b|c|d|20|a0|1680|200[0-9a]|2028|2029|202f|205f|3000));';
		$checks = array();
		foreach ( array( 'title', 'slug', 'summary', 'content' ) as $field ) {
			$checks[] = "REGEXP_REPLACE(REGEXP_REPLACE(COALESCE(tr.$field,''),'<[^>]*>',''),'$spaces','') REGEXP '[^[:space:]]'";
		}
		return implode( ' AND ', $checks );
	}

	private function tables(): string {
		$skills = $this->db->table( 'skills' );
		$translations = $this->db->table( 'skill_translations' );
		$repositories = $this->db->table( 'repositories' );
		return "$skills s INNER JOIN $translations tr ON tr.skill_id=s.id LEFT JOIN $repositories r ON r.id=s.repository_id";
	}

	private function fields( bool $detail = false ): string {
		$fields = 's.id,s.updated_at,s.risk_level,tr.locale,tr.title,tr.slug,tr.summary,tr.content,r.owner AS repository_owner,r.name AS repository_name';
		if ( $detail ) {
			$fields .= ',s.source_path,s.reviewed_at,tr.seo_title,tr.seo_description';
		}
		return $fields;
	}

	private function pagination( array $filters ): array {
		$page = filter_var( $filters['page'] ?? 1, FILTER_VALIDATE_INT );
		$limit = filter_var( $filters['per_page'] ?? 12, FILTER_VALIDATE_INT );
		if ( false === $page || false === $limit || $page < 1 || $page > 10000 || $limit < 1 || $limit > 24 ) {
			throw new \InvalidArgumentException( 'Invalid public pagination.' );
		}
		return array( $page, $limit, ( $page - 1 ) * $limit );
	}

	private function termConfig( string $kind ): array {
		return match ( $kind ) {
			'category' => array( 'categories', 'skill_categories', 'category_id' ),
			'tag' => array( 'tags', 'skill_tags', 'tag_id' ),
			default => throw new \InvalidArgumentException( 'Unsupported public taxonomy.' ),
		};
	}

	private function termFilter( string $kind ): string {
		list( $entity, $link, $column ) = $this->termConfig( $kind );
		$terms = $this->db->table( $entity );
		$links = $this->db->table( $link );
		return "EXISTS (SELECT 1 FROM $links sf INNER JOIN $terms tf ON tf.id=sf.$column WHERE sf.skill_id=s.id AND tf.is_archived=0 AND tf.slug=%s)";
	}

	/** Filters never accept status, SQL fragments, raw column names or unpublished IDs. */
	public function skills( string $locale, array $filters = array() ): array {
		$this->ready();
		$locale = $this->locale( $locale );
		list( $page, $limit, $offset ) = $this->pagination( $filters );
		$clauses = array( $this->visibility(), 'tr.locale=%s', $this->translationVisibility() );
		$values = array( $locale );
		$query = trim( Input::utf8( $filters['q'] ?? '', 400 ) );
		if ( '' !== $query ) {
			$like = '%' . $this->db->connection()->esc_like( $query ) . '%';
			$clauses[] = '(tr.title LIKE %s OR tr.summary LIKE %s)';
			array_push( $values, $like, $like );
		}
		foreach ( array( 'category', 'tag', 'platform' ) as $filter ) {
			$value = $filters[ $filter ] ?? '';
			if ( '' === $value ) { continue; }
			$slug = Input::slug( $value );
			if ( 'platform' === $filter && ! str_starts_with( $slug, 'platform-' ) ) {
				throw new \InvalidArgumentException( 'Invalid platform filter.' );
			}
			$clauses[] = $this->termFilter( 'category' === $filter ? 'category' : 'tag' );
			$values[] = $slug;
		}
		$orders = array( 'newest' => 's.updated_at DESC,s.id DESC', 'oldest' => 's.updated_at ASC,s.id ASC', 'title' => 'tr.title ASC,s.id ASC' );
		$sort = $filters['sort'] ?? 'newest';
		if ( ! is_string( $sort ) || ! isset( $orders[ $sort ] ) ) {
			throw new \InvalidArgumentException( 'Invalid public sort.' );
		}
		$from = $this->tables();
		$where = implode( ' AND ', $clauses );
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $from WHERE $where", $values );
		$rows = $this->db->rows( 'SELECT ' . $this->fields() . " FROM $from WHERE $where ORDER BY {$orders[$sort]} LIMIT %d OFFSET %d", array_merge( $values, array( $limit, $offset ) ) );
		return array( 'items' => $this->cards( $rows ), 'total' => $total, 'page' => $page, 'per_page' => $limit );
	}

	public function featured( string $locale, int $limit = 3 ): array {
		return $this->skills( $locale, array( 'tag' => 'featured', 'per_page' => $limit ) )['items'];
	}

	public function latest( string $locale, int $limit = 6 ): array {
		return $this->skills( $locale, array( 'per_page' => $limit ) )['items'];
	}

	/** A partial/missing translation is unavailable; routing may link to an actual alternative. */
	public function detail( int $id, string $locale ): ?array {
		$this->ready();
		$locale = $this->locale( $locale );
		$from = $this->tables();
		$where = $this->visibility() . ' AND tr.locale=%s AND s.id=%d AND ' . $this->translationVisibility();
		$row = $this->db->row( 'SELECT ' . $this->fields( true ) . " FROM $from WHERE $where", array( $locale, Input::id( $id ) ) );
		if ( ! $row || ! Management::translationComplete( $row ) ) { return null; }
		$skill = $this->publicRow( $row, true );
		$this->attachTerms( $skill );
		$versions = $this->db->table( 'skill_versions' );
		$skills = $this->db->table( 'skills' );
		// Expose only metadata already recorded before this approval. Raw source snapshots and
		// later versions remain internal; the schema has no reviewed snapshot foreign key.
		$version = $this->db->row( "SELECT v.commit_sha,v.version_label,v.created_at FROM $versions v INNER JOIN $skills s ON s.id=v.skill_id WHERE s.id=%d AND " . $this->visibility() . ' AND v.created_at<s.reviewed_at ORDER BY v.created_at DESC,v.id DESC LIMIT 1', array( $id ) );
		if ( $version && ! preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $version['commit_sha'] ) ) { $version = null; }
		$skill['version'] = $version;
		$skill['latest_version'] = $version;
		$skill['recorded_version'] = $version;
		$skill['version_source_verified'] = false;
		$skill['available_translations'] = $this->alternatives( $id );
		$skill['repository_url'] = $this->repositoryUrl( $skill );
		$skill['source_url'] = null;
		if ( $skill['repository_url'] && $skill['source_path'] ) {
			try {
				$source = Input::sourcePath( $skill['source_path'] );
				$path = implode( '/', array_map( 'rawurlencode', explode( '/', $source ) ) );
				// Snapshots have no repository/path linkage; never pin a changed source to an old SHA.
				$skill['source_url'] = $skill['repository_url'] . '/blob/HEAD/' . $path;
			} catch ( \InvalidArgumentException $error ) {
				$skill['source_url'] = null;
			}
		}
		// These have no dedicated field in the accepted schema. Keep missing values explicit.
		$skill['author'] = null;
		$skill['usage_scenarios'] = null;
		$skill['installation'] = null;
		$skill['dependencies'] = null;
		return $skill;
	}

	/** Only public, complete translations appear in switching links/hreflang. */
	public function alternatives( int $id ): array {
		$this->ready();
		$from = $this->tables();
		$where = $this->visibility() . " AND s.id=%d AND tr.locale IN ('zh_CN','en_US') AND " . $this->translationVisibility();
		$rows = $this->db->rows( "SELECT tr.locale,tr.slug,tr.title,tr.summary,tr.content FROM $from WHERE $where", array( Input::id( $id ) ) );
		$alternatives = array();
		foreach ( $rows as $row ) {
			if ( Management::translationComplete( $row ) ) { $alternatives[ $row['locale'] ] = $row['slug']; }
		}
		return $alternatives;
	}

	/** Counts are derived from publicly visible translations, never administrative totals. */
	public function terms( string $kind, string $locale ): array {
		return $this->termCounts( $kind, $locale );
	}

	private function termCounts( string $kind, string $locale, bool $platforms = false ): array {
		$this->ready();
		$locale = $this->locale( $locale );
		list( $entity, $link, $column ) = $this->termConfig( $kind );
		$terms = $this->db->table( $entity );
		$links = $this->db->table( $link );
		$from = $this->tables();
		$where = $this->visibility() . ' AND tr.locale=%s AND ' . $this->translationVisibility();
		$values = array( $locale );
		if ( $platforms ) { $where .= ' AND t.slug LIKE %s'; $values[] = 'platform-%'; }
		$rows = $this->db->rows( "SELECT t.id,t.name,t.slug,COUNT(DISTINCT s.id) AS skill_count FROM $from INNER JOIN $links l ON l.skill_id=s.id INNER JOIN $terms t ON t.id=l.$column WHERE $where AND t.is_archived=0 GROUP BY t.id,t.name,t.slug ORDER BY skill_count DESC,t.name ASC,t.id ASC LIMIT 200", $values );
		return array_map( static fn( array $row ): array => array( 'id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'], 'skill_count' => (int) $row['skill_count'], 'count' => (int) $row['skill_count'] ), $rows );
	}

	public function platforms( string $locale ): array {
		return $this->termCounts( 'tag', $locale, true );
	}

	/** An active empty category/tag may render an honest empty state without revealing drafts. */
	public function term( string $kind, string $slug, string $locale ): ?array {
		$this->ready();
		$locale = $this->locale( $locale );
		list( $entity, $link, $column ) = $this->termConfig( $kind );
		$terms = $this->db->table( $entity );
		$row = $this->db->row( "SELECT id,name,slug FROM $terms WHERE slug=%s AND is_archived=0", array( Input::slug( $slug ) ) );
		if ( ! $row ) { return null; }
		$result = $this->skills( $locale, array( $kind => $slug, 'per_page' => 1 ) );
		return array( 'id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'], 'skill_count' => $result['total'], 'count' => $result['total'] );
	}

	public function related( int $id, string $locale, int $limit = 3 ): array {
		$this->ready();
		$locale = $this->locale( $locale );
		Input::id( $id );
		if ( $limit < 1 || $limit > 12 ) { throw new \InvalidArgumentException( 'Invalid related limit.' ); }
		if ( ! $this->alternatives( $id ) ) { return array(); }
		$similar = array();
		foreach ( array( 'category', 'tag' ) as $kind ) {
			list( $entity, $link, $column ) = $this->termConfig( $kind );
			$terms = $this->db->table( $entity );
			$links = $this->db->table( $link );
			$similar[] = "EXISTS (SELECT 1 FROM $links a INNER JOIN $links b ON b.$column=a.$column INNER JOIN $terms t ON t.id=a.$column WHERE a.skill_id=s.id AND b.skill_id=%d AND t.is_archived=0)";
		}
		$from = $this->tables();
		$where = $this->visibility() . ' AND tr.locale=%s AND ' . $this->translationVisibility() . ' AND s.id<>%d AND (' . implode( ' OR ', $similar ) . ')';
		$rows = $this->db->rows( 'SELECT ' . $this->fields() . " FROM $from WHERE $where ORDER BY s.updated_at DESC,s.id DESC LIMIT %d", array( $locale, $id, $id, $id, $limit ) );
		return $this->cards( $rows );
	}

	private function publicRow( array $row, bool $detail = false ): array {
		$keys = array( 'title', 'slug', 'summary', 'locale', 'updated_at', 'repository_owner', 'repository_name' );
		if ( $detail ) { $keys = array_merge( $keys, array( 'content', 'source_path', 'reviewed_at', 'seo_title', 'seo_description' ) ); }
		$result = array( 'id' => (int) $row['id'], 'risk_level' => in_array( $row['risk_level'], Management::RISKS, true ) ? $row['risk_level'] : 'unknown' );
		foreach ( $keys as $key ) { $result[ $key ] = $row[ $key ] ?? null; }
		return $result;
	}

	private function cards( array $rows ): array {
		$cards = array();
		foreach ( $rows as $row ) {
			if ( ! Management::translationComplete( $row ) ) { continue; }
			$card = $this->publicRow( $row );
			$cards[] = $card;
		}
		$this->attachTermsToRows( $cards );
		return $cards;
	}

	private function attachTerms( array &$skill ): void {
		$rows = array( $skill );
		$this->attachTermsToRows( $rows );
		$skill = $rows[0];
	}

	/** Two bounded queries per card collection rather than two queries per card. */
	private function attachTermsToRows( array &$items ): void {
		if ( ! $items ) { return; }
		$indices = array();
		foreach ( $items as $index => $item ) {
			$indices[ $item['id'] ] = $index;
			$items[ $index ]['categories'] = array();
			$items[ $index ]['tags'] = array();
		}
		$ids = array_keys( $indices );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		foreach ( array( 'category' => 'categories', 'tag' => 'tags' ) as $kind => $key ) {
			list( $entity, $link, $column ) = $this->termConfig( $kind );
			$terms = $this->db->table( $entity );
			$links = $this->db->table( $link );
			$skills = $this->db->table( 'skills' );
			$sql = "SELECT skill_id,id,name,slug FROM (SELECT l.skill_id,t.id,t.name,t.slug,ROW_NUMBER() OVER (PARTITION BY l.skill_id ORDER BY t.name ASC,t.id ASC) AS term_position FROM $terms t INNER JOIN $links l ON l.$column=t.id INNER JOIN $skills s ON s.id=l.skill_id WHERE s.id IN ($placeholders) AND t.is_archived=0 AND " . $this->visibility() . ') public_terms WHERE term_position<=100 ORDER BY skill_id,term_position';
			foreach ( $this->db->rows( $sql, $ids ) as $row ) {
				$index = $indices[ (int) $row['skill_id'] ];
				$items[ $index ][ $key ][] = array( 'id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'] );
			}
		}
		foreach ( $items as &$item ) {
			$item['platforms'] = array_values( array_filter( $item['tags'], static fn( array $term ): bool => str_starts_with( $term['slug'], 'platform-' ) ) );
			$item['licenses'] = array_values( array_filter( $item['tags'], static fn( array $term ): bool => str_starts_with( $term['slug'], 'license-' ) ) );
			$item['license'] = $item['licenses'];
		}
		unset( $item );
	}

	private function repositoryUrl( array $skill ): ?string {
		if ( ! $skill['repository_owner'] || ! $skill['repository_name'] ) { return null; }
		try {
			list( $owner, $name ) = Input::repository( $skill['repository_owner'], $skill['repository_name'] );
			return 'https://github.com/' . rawurlencode( $owner ) . '/' . rawurlencode( $name );
		} catch ( \InvalidArgumentException $error ) {
			return null;
		}
	}
}
