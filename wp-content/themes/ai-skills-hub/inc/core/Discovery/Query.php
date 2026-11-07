<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Discovery;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Security\Input as SecurityInput;

defined( 'ABSPATH' ) || exit;

/** Public, fresh projections. Pagination is applied after public/language/statistical gates. */
final class Query {
	public const RANKINGS = array( 'trending', 'most-viewed', 'most-favorited', 'top-rated', 'recently-added', 'recently-updated' );
	public function __construct( private Database $db, private Migrator $migrator, private PublicCatalog $catalog ) {}
	private function ready( string $locale ): void {
		if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'Discovery storage is unavailable.' ); }
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'Unsupported discovery locale.' ); }
	}
	private function pagination( array $filters ): array {
		$page = filter_var( $filters['page'] ?? 1, FILTER_VALIDATE_INT ); $limit = filter_var( $filters['per_page'] ?? 12, FILTER_VALIDATE_INT );
		if ( false === $page || false === $limit || $page < 1 || $page > 10000 || $limit < 1 || $limit > 24 ) { throw new \InvalidArgumentException( 'Invalid discovery pagination.' ); }
		return array( $page, $limit, ( $page - 1 ) * $limit );
	}
	private function publicIds( string $locale, array $filters = array() ): string {
		$sql = $this->catalog->matchingIdsSql( $locale, $filters );
		$excluded = self::excludedSkillIds();
		if ( $excluded ) { $sql .= ' AND s.id NOT IN (' . implode( ',', $excluded ) . ')'; }
		return $sql;
	}
	/** Trusted fixture exclusions apply before counting and pagination; malformed configuration closes access. */
	public static function excludedSkillIds(): array {
		$value = get_option( 'aish_discovery_excluded_skills', array() );
		if ( ! is_array( $value ) || count( $value ) > 1000 ) { throw new \RuntimeException( 'Invalid discovery exclusions.' ); }
		$ids = array();
		foreach ( $value as $id ) {
			if ( is_string( $id ) && preg_match( '/^[1-9][0-9]*$/D', $id ) ) { $id = filter_var( $id, FILTER_VALIDATE_INT ); }
			if ( ! is_int( $id ) || $id < 1 ) { throw new \RuntimeException( 'Invalid discovery exclusions.' ); }
			$ids[ $id ] = $id;
		}
		return array_values( $ids );
	}
	/** Same identity gate as Community, plus contribution erasure introduced in Phase 10. */
	private function liveUser( string $column ): string {
		if ( ! in_array( $column, array( 'r.user_id', 'f.user_id' ), true ) ) { throw new \LogicException( 'Invalid identity column.' ); }
		$users = $this->db->connection()->users; $meta = $this->db->connection()->usermeta;
		return "EXISTS (SELECT 1 FROM $users u WHERE u.ID=$column) AND (SELECT COUNT(*) FROM $meta ua WHERE ua.user_id=$column AND ua.meta_key='_aish_account_state')=1 AND EXISTS (SELECT 1 FROM $meta ua WHERE ua.user_id=$column AND ua.meta_key='_aish_account_state' AND ua.meta_value='active') AND (SELECT COUNT(*) FROM $meta uv WHERE uv.user_id=$column AND uv.meta_key='_aish_verified_email_hash')=1 AND EXISTS (SELECT 1 FROM $meta uv WHERE uv.user_id=$column AND uv.meta_key='_aish_verified_email_hash' AND uv.meta_value REGEXP '^[a-f0-9]{64}$') AND (SELECT COUNT(*) FROM $meta ut WHERE ut.user_id=$column AND ut.meta_key='_aish_email_verified_at')=1 AND EXISTS (SELECT 1 FROM $meta ut WHERE ut.user_id=$column AND ut.meta_key='_aish_email_verified_at' AND ut.meta_value<>'') AND NOT EXISTS (SELECT 1 FROM $meta ux WHERE ux.user_id=$column AND ux.meta_key IN ('_aish_account_deletion_pending','_aish_community_erasing','_aish_contributions_erasing') AND ux.meta_value<>'0' AND ux.meta_value<>'')";
	}
	private function reviews(): array {
		$from = $this->db->table( 'reviews' ) . ' r INNER JOIN ' . $this->db->table( 'review_revisions' ) . ' v ON v.id=r.approved_revision_id AND v.review_id=r.id';
		$where = "r.state='approved' AND v.moderation_state='approved' AND v.moderator_id>0 AND v.moderated_at>='1970-01-01 00:00:00' AND v.rating BETWEEN 1 AND 5 AND " . $this->liveUser( 'r.user_id' );
		return array( $from, $where );
	}
	private function coverage( int $window ): array {
		$start = get_option( 'aish_discovery_views_started_at', null );
		if ( ! is_string( $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $start ) ) { $start = null; }
		return array( 'views_started_at' => $start, 'window_days' => $window, 'policy_version' => RankingPolicy::VERSION, 'minimum_reviews' => RankingPolicy::MIN_REVIEWS, 'through' => gmdate( 'Y-m-d', time() - 86400 ) );
	}
	private function views( int $days ): string {
		$daily = $this->db->table( 'daily_metrics' ); $today = gmdate( 'Y-m-d' ); $start = gmdate( 'Y-m-d', time() - $days * 86400 );
		return $this->db->connection()->prepare( "SELECT CAST(dimension_key AS UNSIGNED) AS skill_id,stat_day,metric_count AS views FROM $daily WHERE metric_name='skill_view' AND dimension_type='skill' AND locale='' AND policy_version=%s AND stat_day>=%s AND stat_day<%s AND dimension_key REGEXP '^[1-9][0-9]*$' AND metric_count>0", array( RankingPolicy::VERSION, $start, $today ) );
	}
	/** Six explained rankings. No score includes GitHub stars, task activity or private text. */
	public function list( string $kind, string $locale, array $filters = array() ): array {
		$this->ready( $locale );
		if ( ! in_array( $kind, self::RANKINGS, true ) ) { throw new \InvalidArgumentException( 'Invalid discovery ranking.' ); }
		$window = filter_var( $filters['window'] ?? 7, FILTER_VALIDATE_INT );
		if ( ! in_array( $window, array( 7, 30 ), true ) ) { throw new \InvalidArgumentException( 'Invalid discovery time window.' ); }
		$public = $this->publicIds( $locale, $filters ); $skills = $this->db->table( 'skills' ); $favorites = $this->db->table( 'favorites' );
		[ $reviews, $validReview ] = $this->reviews();
		$order = 'ranked.score DESC,ranked.skill_id ASC';
		switch ( $kind ) {
			case 'most-viewed':
				$sql = 'SELECT skill_id,SUM(views) AS views,SUM(views) AS score FROM (' . $this->views( $window ) . ') view_days GROUP BY skill_id'; break;
			case 'most-favorited':
				$sql = "SELECT f.skill_id,COUNT(*) AS favorites,COUNT(*) AS score FROM $favorites f WHERE f.is_active=1 AND " . $this->liveUser( 'f.user_id' ) . ' GROUP BY f.skill_id'; break;
			case 'top-rated':
				$sql = "SELECT r.skill_id,AVG(v.rating) AS average,COUNT(*) AS reviews,AVG(v.rating) AS score,SUM(v.rating=1) AS star1,SUM(v.rating=2) AS star2,SUM(v.rating=3) AS star3,SUM(v.rating=4) AS star4,SUM(v.rating=5) AS star5 FROM $reviews WHERE $validReview GROUP BY r.skill_id HAVING COUNT(*)>=" . RankingPolicy::MIN_REVIEWS;
				$order = 'ranked.average DESC,ranked.reviews DESC,ranked.skill_id ASC'; break;
			case 'recently-added':
				$sql = "SELECT id AS skill_id,created_at FROM $skills WHERE created_at>='1970-01-01 00:00:00' AND created_at<=UTC_TIMESTAMP()";
				$order = 'ranked.created_at DESC,ranked.skill_id ASC'; break;
			case 'recently-updated':
				$events = $this->db->table( 'workflow_events' );
				// First observed approval is a baseline, not an invented content update.
				$sql = "SELECT subject_id AS skill_id,MAX(created_at) AS approved_at FROM $events WHERE subject_type='skill' AND action='public_content_approved' AND subject_revision>0 AND created_at>='1970-01-01 00:00:00' AND created_at<=UTC_TIMESTAMP() AND JSON_VALID(metadata_json) AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE '{}' END,'$.content_hash')) REGEXP '^[a-f0-9]{64}$' GROUP BY subject_id HAVING COUNT(DISTINCT subject_revision)>1";
				$order = 'ranked.approved_at DESC,ranked.skill_id ASC'; break;
			case 'trending':
				$window = RankingPolicy::TREND_DAYS; $today = gmdate( 'Y-m-d' ); $start = gmdate( 'Y-m-d', time() - 7 * 86400 ); $yesterday = gmdate( 'Y-m-d', time() - 86400 );
				$favoriteDays = $this->db->connection()->prepare( "SELECT f.skill_id,DATE(f.created_at) AS stat_day,0 AS views,COUNT(*) AS favorites,0 AS reviews,0 AS rating_points FROM $favorites f WHERE f.is_active=1 AND " . $this->liveUser( 'f.user_id' ) . ' AND f.created_at>=%s AND f.created_at<%s GROUP BY f.skill_id,DATE(f.created_at)', array( $start, $today ) );
				$reviewDays = $this->db->connection()->prepare( "SELECT r.skill_id,DATE(r.first_published_at) AS stat_day,0 AS views,0 AS favorites,COUNT(*) AS reviews,SUM(v.rating) AS rating_points FROM $reviews WHERE $validReview AND r.first_published_at>=%s AND r.first_published_at<%s GROUP BY r.skill_id,DATE(r.first_published_at)", array( $start, $today ) );
				$days = 'SELECT skill_id,stat_day,SUM(views) AS views,SUM(favorites) AS favorites,SUM(reviews) AS reviews,SUM(rating_points) AS rating_points FROM (SELECT skill_id,stat_day,views,0 AS favorites,0 AS reviews,0 AS rating_points FROM (' . $this->views( 7 ) . ") trend_views UNION ALL $favoriteDays UNION ALL $reviewDays) trend_facts GROUP BY skill_id,stat_day";
				$sql = 'SELECT skill_id,SUM(views) AS views,SUM(favorites) AS favorites,SUM(reviews) AS reviews,SUM(rating_points) AS rating_points,SUM(POWER(2,-DATEDIFF(\'' . $yesterday . "',stat_day)/3.0)*(LN(1+views)+3*LN(1+favorites)+2*LN(1+rating_points/5.0))) AS score FROM ($days) trend_days GROUP BY skill_id HAVING score>0";
				break;
		}
		$result = $this->cardPage( "SELECT ranked.* FROM ($sql) ranked WHERE ranked.skill_id IN ($public)", $order, $locale, $filters );
		if ( 'top-rated' === $kind ) {
			$limited = str_replace( 'HAVING COUNT(*)>=' . RankingPolicy::MIN_REVIEWS, 'HAVING COUNT(*) BETWEEN 1 AND ' . ( RankingPolicy::MIN_REVIEWS - 1 ), $sql );
			$result['limited_samples'] = $this->cardPage( "SELECT ranked.* FROM ($limited) ranked WHERE ranked.skill_id IN ($public)", 'ranked.reviews DESC,ranked.skill_id ASC', $locale, array( 'page' => 1, 'per_page' => 6 ) );
		}
		$result['kind'] = $kind; $result['coverage'] = $this->coverage( $window ); return $result;
	}
	/** Count and order eligible IDs before the single bounded public-card projection. */
	private function cardPage( string $sql, string $order, string $locale, array $filters ): array {
		[ $page, $limit, $offset ] = $this->pagination( $filters );
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM ($sql) discovery_count" );
		$rows = $offset >= $total ? array() : $this->db->rows( str_replace( '%', '%%', $sql . ' ORDER BY ' . $order ) . ' LIMIT %d OFFSET %d', array( $limit, $offset ) );
		$items = $this->project( $rows, $locale );
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit );
	}
	private function project( array $rows, string $locale ): array {
		$cards = array_column( $this->catalog->cardsByIds( array_map( 'intval', array_column( $rows, 'skill_id' ) ), $locale ), null, 'id' ); $items = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['skill_id']; if ( ! isset( $cards[ $id ] ) ) { continue; } $card = $cards[ $id ]; $metric = array();
			foreach ( array( 'views', 'favorites', 'reviews', 'rating_points' ) as $key ) { if ( isset( $row[ $key ] ) ) { $metric[ $key ] = (int) $row[ $key ]; } }
			foreach ( array( 'score', 'average' ) as $key ) { if ( isset( $row[ $key ] ) ) { $metric[ $key ] = (float) $row[ $key ]; } }
			foreach ( array( 'created_at', 'approved_at' ) as $key ) { if ( isset( $row[ $key ] ) ) { $metric[ $key ] = $row[ $key ]; } }
			if ( isset( $row['star1'] ) ) { $metric['distribution'] = array(); foreach ( range( 1, 5 ) as $star ) { $metric['distribution'][ $star ] = (int) $row[ 'star' . $star ]; } }
			if ( isset( $metric['average'] ) ) { $metric['rankable'] = ( $metric['reviews'] ?? 0 ) >= RankingPolicy::MIN_REVIEWS; }
			$card['metric'] = $metric; $items[] = $card;
		}
		return $items;
	}
	private function topicConfig( string $kind ): array {
		return match ( $kind ) {
			'collection' => array( 'editorial_collections', 'collection_translations', 'collection_items', 'collection_id', 'title' ),
			'use_case' => array( 'use_cases', 'use_case_translations', 'use_case_skills', 'use_case_id', 'name' ),
			default => throw new \InvalidArgumentException( 'Invalid discovery topic.' ),
		};
	}
	private function topicSource( string $kind, string $locale ): array {
		[ $entity, $translations, $link, $column, $title ] = $this->topicConfig( $kind );
		$table = $this->db->table( $entity ); $tr = $this->db->table( $translations ); $options = $this->db->connection()->options;
		$from = "$table t INNER JOIN $tr tt ON tt.$column=t.id LEFT JOIN $options dx ON dx.option_name=CONCAT('aish_discovery_{$kind}_',t.id)";
		$end = "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(dx.option_value) THEN dx.option_value ELSE '{}' END,'$.ends_at'))";
		$where = $this->db->connection()->prepare( "t.status='published' AND tt.locale=%s AND TRIM(tt.$title)<>'' AND tt.slug IS NOT NULL AND tt.slug<>'' AND TRIM(tt.description)<>'' AND (dx.option_id IS NULL OR JSON_VALID(dx.option_value)) AND ($end IS NULL OR $end='null' OR ($end REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$' AND $end>UTC_TIMESTAMP()))", array( $locale ) );
		if ( 'collection' === $kind ) { $where .= " AND t.published_at IS NOT NULL AND t.published_at>='1970-01-01 00:00:00' AND t.published_at<=UTC_TIMESTAMP()"; }
		return array( $from, $where, $title, $link, $column );
	}
	public function topics( string $kind, string $locale ): array {
		$this->ready( $locale );
		if ( 'platform' === $kind ) { return $this->platforms( $locale ); }
		[ $from, $where, $title, $link, $column ] = $this->topicSource( $kind, $locale ); $links = $this->db->table( $link ); $public = $this->publicIds( $locale );
		$rows = $this->db->rows( "SELECT t.id,tt.$title AS title,tt.slug,tt.description,tt.seo_title,tt.seo_description,COALESCE(c.skill_count,0) AS skill_count FROM $from LEFT JOIN (SELECT $column,COUNT(*) AS skill_count FROM $links WHERE skill_id IN ($public) GROUP BY $column) c ON c.$column=t.id WHERE $where ORDER BY t.sort_order ASC,t.id ASC LIMIT 200" );
		return $this->topicRows( $rows, $kind );
	}
	private function topicRows( array $rows, string $kind ): array {
		if ( ! $rows ) { return array(); } $ids = array_map( 'intval', array_column( $rows, 'id' ) ); $alternates = array();
		foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
			[ $from, $where, , $link, $column ] = $this->topicSource( $kind, $locale ); $links = $this->db->table( $link ); $public = $this->publicIds( $locale );
			foreach ( $this->db->rows( "SELECT t.id,tt.slug FROM $from WHERE $where AND t.id IN (" . implode( ',', $ids ) . ") AND EXISTS (SELECT 1 FROM $links al WHERE al.$column=t.id AND al.skill_id IN ($public))" ) as $alternate ) { $alternates[ (int) $alternate['id'] ][ $locale ] = $alternate['slug']; }
		}
		foreach ( $rows as &$row ) { $row['id'] = (int) $row['id']; $row['count'] = (int) ( $row['skill_count'] ?? 0 ); $row['name'] = $row['title']; $row['translations'] = $alternates[ $row['id'] ] ?? array(); } unset( $row );
		return $rows;
	}
	public function detail( string $kind, string $slug, string $locale, array $filters = array() ): ?array {
		$this->ready( $locale ); SecurityInput::slug( $slug );
		if ( 'platform' === $kind ) {
			if ( isset( $filters['platform'] ) && '' !== $filters['platform'] && $slug !== $filters['platform'] ) { throw new \InvalidArgumentException( 'Conflicting platform filter.' ); }
			foreach ( $this->platforms( $locale ) as $platform ) {
				if ( $platform['slug'] !== $slug ) { continue; }
				$public = $this->publicIds( $locale, array_merge( $filters, array( 'platform' => $slug ) ) );
				$platform['skills'] = $this->cardPage( "SELECT id AS skill_id FROM ($public) ranked", 'ranked.id ASC', $locale, $filters ); $platform['tutorial_ids'] = array(); return $platform;
			} return null;
		}
		[ $from, $where, $title, $link, $column ] = $this->topicSource( $kind, $locale );
		$row = $this->db->row( str_replace( '%', '%%', "SELECT t.id,tt.$title AS title,tt.slug,tt.description,tt.seo_title,tt.seo_description,dx.option_value AS extras FROM $from WHERE $where" ) . ' AND tt.slug=%s', array( $slug ) );
		if ( ! $row ) { return null; } $row = $this->topicRows( array( $row ), $kind )[0]; $extras = json_decode( (string) ( $row['extras'] ?? '{}' ), true ); unset( $row['extras'] );
		$row['tutorial_ids'] = array_values( array_filter( is_array( $extras['tutorial_ids'] ?? null ) ? array_slice( $extras['tutorial_ids'], 0, 50 ) : array(), static fn( mixed $id ): bool => is_int( $id ) && $id > 0 ) );
		$links = $this->db->table( $link ); $skills = $this->db->table( 'skills' ); $public = $this->publicIds( $locale, $filters );
		$order = 'collection' === $kind ? 'ranked.sort_order ASC,ranked.skill_id ASC' : 'ranked.skill_id ASC'; $sort = 'collection' === $kind ? ',l.sort_order' : '';
		$sql = "SELECT l.skill_id$sort FROM $links l INNER JOIN $skills s ON s.id=l.skill_id WHERE l.$column=" . (int) $row['id'] . " AND l.skill_id IN ($public)";
		$row['skills'] = $this->cardPage( "SELECT ranked.* FROM ($sql) ranked", $order, $locale, $filters ); $row['count'] = $row['skills']['total']; return $row;
	}
	private function platforms( string $locale ): array {
		$config = Settings::platforms(); $rows = array(); $ids = array();
		foreach ( $config as $id => $platform ) { if ( (int) $id > 0 && ! empty( $platform['enabled'] ) && ! empty( $platform['verified'] ) ) { $ids[] = (int) $id; } }
		if ( ! $ids ) { return array(); }
		$tags = $this->db->table( 'tags' ); $links = $this->db->table( 'skill_tags' ); $byLocale = array();
		foreach ( array( 'zh_CN', 'en_US' ) as $language ) {
			$public = $this->publicIds( $language );
			$terms = $this->db->rows( "SELECT t.id,t.slug,COUNT(*) AS skill_count FROM $tags t INNER JOIN $links l ON l.tag_id=t.id WHERE t.id IN (" . implode( ',', array_slice( $ids, 0, 50 ) ) . ") AND t.is_archived=0 AND t.slug LIKE 'platform-%' AND l.skill_id IN ($public) GROUP BY t.id,t.slug ORDER BY skill_count DESC,t.id ASC" );
			$byLocale[ $language ] = array_column( $terms, null, 'id' );
		}
		foreach ( $byLocale[ $locale ] as $term ) {
			$platform = $config[ $term['id'] ] ?? null; $copy = $platform['translations'][ $locale ] ?? null;
			if ( ! is_array( $copy ) || '' === trim( (string) ( $copy['title'] ?? '' ) ) || '' === trim( (string) ( $copy['description'] ?? '' ) ) ) { continue; }
			$translations = array(); foreach ( array( 'zh_CN', 'en_US' ) as $language ) { $other = $platform['translations'][ $language ] ?? array(); if ( isset( $byLocale[ $language ][ $term['id'] ] ) && '' !== trim( (string) ( $other['title'] ?? '' ) ) && '' !== trim( (string) ( $other['description'] ?? '' ) ) ) { $translations[ $language ] = $term['slug']; } }
			$rows[] = array( 'id' => (int) $term['id'], 'slug' => $term['slug'], 'title' => $copy['title'], 'name' => $copy['title'], 'description' => $copy['description'], 'seo_title' => $copy['seo_title'] ?? '', 'seo_description' => $copy['seo_description'] ?? '', 'count' => (int) $term['skill_count'], 'skill_count' => (int) $term['skill_count'], 'evidence_url' => $platform['evidence_url'], 'translations' => $translations );
		} return $rows;
	}
	/** Unbounded SQL source for sitemap pagination; editorial navigation's 200-row cap is not reused. */
	private function sitemapSource( string $kind, string $locale ): string {
		[ $from, $where, , $link, $column ] = $this->topicSource( $kind, $locale ); $links = $this->db->table( $link ); $public = $this->publicIds( $locale );
		return "SELECT t.id,tt.slug,GREATEST(t.updated_at,tt.updated_at) AS updated_at FROM $from WHERE $where AND EXISTS (SELECT 1 FROM $links l WHERE l.$column=t.id AND l.skill_id IN ($public))";
	}
	public function sitemapCount( string $kind, string $locale ): int {
		$this->ready( $locale );
		if ( 'platform' === $kind ) { return count( $this->platforms( $locale ) ); }
		return (int) $this->db->value( 'SELECT COUNT(*) FROM (' . $this->sitemapSource( $kind, $locale ) . ') discovery_sitemap' );
	}
	public function sitemapItems( string $kind, string $locale, int $page, int $perPage = 200 ): array {
		$this->ready( $locale ); if ( $page < 1 || $page > 100000 || $perPage < 1 || $perPage > 1000 ) { throw new \InvalidArgumentException( 'Invalid discovery sitemap pagination.' ); }
		if ( 'platform' === $kind ) { return array_map( static fn( array $row ): array => array( 'id' => $row['id'], 'slug' => $row['slug'] ), array_slice( $this->platforms( $locale ), ( $page - 1 ) * $perPage, $perPage ) ); }
		$rows = $this->db->rows( str_replace( '%', '%%', $this->sitemapSource( $kind, $locale ) ) . ' ORDER BY t.id ASC LIMIT %d OFFSET %d', array( $perPage, ( $page - 1 ) * $perPage ) );
		foreach ( $rows as &$row ) { $row['id'] = (int) $row['id']; } unset( $row ); return $rows;
	}
	public function related( int $skillId, string $locale, int $limit = 6 ): array { return $this->recommend( $skillId, $locale, $limit, false ); }
	public function similar( int $skillId, string $locale, int $limit = 6 ): array { return $this->recommend( $skillId, $locale, $limit, true ); }
	private function recommend( int $skillId, string $locale, int $limit, bool $similar ): array {
		$this->ready( $locale ); SecurityInput::id( $skillId ); if ( $limit < 1 || $limit > 24 ) { throw new \InvalidArgumentException( 'Invalid recommendation limit.' ); }
		$public = $this->publicIds( $locale );
		if ( ! $this->db->value( "SELECT 1 FROM ($public) source_public WHERE id=" . $skillId ) ) { return array(); }
		$parts = array();
		foreach ( array( array( 'categories', 'skill_categories', 'category_id', 4 ), array( 'tags', 'skill_tags', 'tag_id', 2 ) ) as [ $entity, $link, $column, $weight ] ) {
			$terms = $this->db->table( $entity ); $links = $this->db->table( $link );
			$functional = 'tags' === $entity ? " AND t.slug<>'featured' AND t.slug NOT LIKE 'license-%'" : '';
			$parts[] = "SELECT a.skill_id,COUNT(*)*$weight AS points FROM $links a INNER JOIN $links b ON b.$column=a.$column AND b.skill_id=$skillId INNER JOIN $terms t ON t.id=a.$column WHERE t.is_archived=0$functional GROUP BY a.skill_id";
		}
		foreach ( array( 'use_case', 'collection' ) as $kind ) {
			if ( $similar && 'collection' === $kind ) { continue; }
			[ $from, $where, , $link, $column ] = $this->topicSource( $kind, $locale ); $links = $this->db->table( $link ); $weight = 'use_case' === $kind ? 3 : 1;
			$parts[] = "SELECT a.skill_id,COUNT(*)*$weight AS points FROM $links a INNER JOIN $links b ON b.$column=a.$column AND b.skill_id=$skillId WHERE a.$column IN (SELECT t.id FROM $from WHERE $where) GROUP BY a.skill_id";
		}
		$sql = 'SELECT skill_id,SUM(points) AS score FROM (' . implode( ' UNION ALL ', $parts ) . ") associations WHERE skill_id<>$skillId AND skill_id IN ($public) GROUP BY skill_id ORDER BY score DESC,skill_id ASC";
		$rows = $this->db->rows( str_replace( '%', '%%', $sql ) . ' LIMIT %d', array( $limit ) ); return $this->project( $rows, $locale );
	}
}
