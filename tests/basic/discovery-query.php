<?php
declare(strict_types=1);
// Recording adapter: validates projection/SQL contracts, not a real MySQL integration test.
namespace AISkillsHub\Core\Database {
	final class Migrator { public bool $current = true; public function isCurrent(): bool { return $this->current; } }
}
namespace AISkillsHub\Core\Frontend {
	final class PublicCatalog {
		public array $cards = array(); public array $calls = array(); public array $platformRows = array();
		public function matchingIdsSql( string $locale, array $filters ): string { return "SELECT s.id FROM fixture_public s WHERE locale='$locale' AND keyword='" . str_replace( "'", "''", $filters['q'] ?? '' ) . "'"; }
		public function cardsByIds( array $ids, string $locale ): array { $this->calls[] = array( $ids, $locale ); return array_values( array_filter( $this->cards, static fn( array $card ): bool => in_array( $card['id'], $ids, true ) ) ); }
		public function platforms( string $locale ): array { return $this->platformRows; }
	}
}
namespace AISkillsHub\Core\Discovery {
	final class Settings { public static array $platforms = array(); public static function platforms(): array { return self::$platforms; } }
}
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['options'] = array();
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
	final class wpdb {
		public string $prefix = 'fixture_'; public string $users = 'fixture_users'; public string $usermeta = 'fixture_usermeta'; public string $options = 'fixture_options'; public string $last_error = '';
		public array $queries = array(); public array $rows = array(); public array $values = array();
		public function suppress_errors( bool $suppress ): bool { return false; }
		public function prepare( string $sql, array $values ): string {
			$index = 0; $result = preg_replace_callback( '/%%|%[sdf]/', static function ( array $match ) use ( &$index, $values ): string {
				if ( '%%' === $match[0] ) { return '%'; }
				if ( ! array_key_exists( $index, $values ) ) { throw new \RuntimeException( 'Missing placeholder value.' ); }
				$value = $values[ $index++ ]; return '%s' === $match[0] ? "'" . str_replace( "'", "''", (string) $value ) . "'" : (string) $value;
			}, $sql );
			if ( count( $values ) !== $index ) { throw new \RuntimeException( 'Unused placeholder value.' ); } return $result;
		}
		public function get_results( string $sql, string $format ): array { $this->queries[] = $sql; if ( ! $this->rows ) { throw new \RuntimeException( 'Unexpected rows query.' ); } return array_shift( $this->rows ); }
		public function get_var( string $sql ): mixed { $this->queries[] = $sql; if ( ! $this->values ) { throw new \RuntimeException( 'Unexpected scalar query.' ); } return array_shift( $this->values ); }
		public function reset( array $values = array(), array $rows = array() ): void { $this->queries = array(); $this->values = $values; $this->rows = $rows; }
	}
	$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Security/Input.php', 'Discovery/RankingPolicy.php', 'Discovery/Query.php' ) as $file ) { require $base . $file; }
	use AISkillsHub\Core\Database\Database; use AISkillsHub\Core\Database\Migrator; use AISkillsHub\Core\Frontend\PublicCatalog; use AISkillsHub\Core\Discovery\Query; use AISkillsHub\Core\Discovery\Settings;
	$count = 0;
	function check( bool $ok, string $message ): void { global $count; if ( ! $ok ) { throw new \RuntimeException( $message ); } ++$count; }
	function rejects( callable $call ): void { try { $call(); } catch ( \InvalidArgumentException $error ) { check( true, 'Input rejected.' ); return; } throw new \RuntimeException( 'Input was not rejected.' ); }
	$db = new wpdb(); $migrator = new Migrator(); $catalog = new PublicCatalog(); $query = new Query( new Database( $db ), $migrator, $catalog );
	$catalog->cards = array( array( 'id' => 12, 'title' => 'Twelve' ), array( 'id' => 7, 'title' => 'Seven' ) );
	foreach ( array( 'trending', 'most-viewed', 'most-favorited', 'top-rated', 'recently-added', 'recently-updated' ) as $kind ) {
		$db->reset( 'top-rated' === $kind ? array( 1, 0 ) : array( 1 ), array( array( array( 'skill_id' => 7, 'score' => 2 ) ) ) );
		$result = $query->list( $kind, 'en_US', array( 'q' => '100% %s', 'per_page' => 6 ) );
		check( 7 === $result['items'][0]['id'] && 1 === $result['total'] && 6 === $result['per_page'], 'Ranking preserves public card identity and pagination.' );
		check( str_contains( $db->queries[0], "locale='en_US'" ) && str_contains( $db->queries[0], "keyword='100% %s'" ) && ! str_contains( $db->queries[0], 'LIMIT' ), 'Public/current-language gate precedes count and limit.' );
		check( str_contains( $db->queries[1], "keyword='100% %s'" ) && str_ends_with( $db->queries[1], 'LIMIT 6 OFFSET 0' ), 'Literal percent cannot become a second-stage placeholder.' );
		check( str_contains( $db->queries[1], 'ranked.skill_id ASC' ), 'Ranking ties are stable by Skill ID.' );
		check( null === $result['coverage']['views_started_at'], 'Unknown coverage is not fabricated.' );
	}
	$db->reset( array( 2 ), array( array( array( 'skill_id' => 7, 'favorites' => 4, 'score' => 4 ), array( 'skill_id' => 12, 'favorites' => 3, 'score' => 3 ) ) ) );
	$result = $query->list( 'most-favorited', 'zh_CN' );
	check( array( 7, 12 ) === array_column( $result['items'], 'id' ), 'Projection preserves ranking order even when cards arrive unordered.' );
	check( array( array( 7, 12 ), 'zh_CN' ) === end( $catalog->calls ), 'Cards resolve in one bounded collection.' );
	$sql = $db->queries[0];
	foreach ( array( 'f.is_active=1', '_aish_account_state', '_aish_verified_email_hash', '_aish_email_verified_at', '_aish_account_deletion_pending', '_aish_community_erasing', '_aish_contributions_erasing' ) as $gate ) { check( str_contains( $sql, $gate ), 'Favorite identity/contribution gate: ' . $gate ); }
	$db->reset( array( 0 ) ); $result = $query->list( 'most-favorited', 'zh_CN' );
	check( 0 === $result['total'] && array() === $result['items'] && 1 === count( $db->queries ), 'Cancelled/removed aggregates re-read without stale cache or invented cards.' );
	$db->reset( array( 1, 0 ), array( array( array( 'skill_id' => 12, 'average' => '4.2', 'reviews' => '5', 'star1' => 0, 'star2' => 0, 'star3' => 1, 'star4' => 2, 'star5' => 2 ) ) ) );
	$result = $query->list( 'top-rated', 'en_US' ); $sql = $db->queries[0];
	foreach ( array( 'v.id=r.approved_revision_id AND v.review_id=r.id', "r.state='approved'", "v.moderation_state='approved'", 'v.moderator_id>0', 'v.rating BETWEEN 1 AND 5', 'HAVING COUNT(*)>=5' ) as $gate ) { check( str_contains( $sql, $gate ), 'Approved rating source gate: ' . $gate ); }
	check( 4.2 === $result['items'][0]['metric']['average'] && 5 === $result['items'][0]['metric']['reviews'] && array( 1 => 0, 2 => 0, 3 => 1, 4 => 2, 5 => 2 ) === $result['items'][0]['metric']['distribution'], 'Actual average, count and distribution are preserved.' );
	$db->reset( array( 0 ) ); $query->list( 'trending', 'en_US' ); $sql = $db->queries[0];
	check( str_contains( $sql, 'f.created_at' ) && ! str_contains( $sql, 'f.updated_at' ) && str_contains( $sql, 'r.first_published_at' ), 'Cancel/restore and review edits cannot refresh first contribution date.' );
	check( str_contains( $sql, "stat_day<'" . gmdate( 'Y-m-d' ) . "'" ) && str_contains( $sql, 'POWER(2,-DATEDIFF(' ) && str_contains( $sql, '3*LN(1+favorites)+2*LN(1+rating_points/5.0)' ), 'Seven completed UTC days and documented decay weights.' );
	$db->reset( array( 0 ) ); $query->list( 'recently-updated', 'en_US' ); $sql = $db->queries[0];
	check( str_contains( $sql, "action='public_content_approved'" ) && str_contains( $sql, 'COUNT(DISTINCT subject_revision)>1' ) && str_contains( $sql, 'content_hash' ) && ! str_contains( $sql, 'updated_at' ), 'Only evidenced content approvals beyond baseline count as public updates.' );
	$db->reset( array( 0 ) ); $query->list( 'recently-added', 'en_US' );
	check( str_contains( $db->queries[0], 'created_at' ) && ! str_contains( $db->queries[0], 'published_at' ), 'Recently added uses creation, not fabricated publication dates.' );
	$db->reset( array( 0 ) ); $query->list( 'most-viewed', 'en_US', array( 'window' => 30 ) );
	check( str_contains( $db->queries[0], "locale=''" ) && str_contains( $db->queries[0], "policy_version='discovery-1'" ) && str_contains( $db->queries[0], gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ), 'Cross-language daily view facts use the selected policy/window.' );
	$GLOBALS['options']['aish_discovery_excluded_skills'] = array( 12, '7', 12 ); $db->reset( array( 0 ) ); $query->list( 'most-viewed', 'en_US' );
	check( str_contains( $db->queries[0], 's.id NOT IN (12,7)' ), 'Integer-string exclusions are normalized identically to tracking.' ); unset( $GLOBALS['options']['aish_discovery_excluded_skills'] );
	foreach ( array( 'broken', array( 0 ), array( '-1' ), array( '01' ), array( true ), array_fill( 0, 1001, 1 ) ) as $invalid ) {
		$GLOBALS['options']['aish_discovery_excluded_skills'] = $invalid; $db->reset();
		try { $query->list( 'most-viewed', 'en_US' ); throw new \LogicException( 'Invalid exclusion configuration was ignored.' ); } catch ( \RuntimeException $error ) { check( ! $db->queries, 'Malformed exclusion configuration fails before SQL execution.' ); }
	} unset( $GLOBALS['options']['aish_discovery_excluded_skills'] );
	rejects( fn() => $query->detail( 'platform', 'platform-codex', 'en_US', array( 'platform' => 'platform-cursor' ) ) );
	foreach ( array( array( 'wrong', 'en_US', array() ), array( 'trending', 'xx', array() ), array( 'trending', 'en_US', array( 'per_page' => 25 ) ), array( 'trending', 'en_US', array( 'page' => 0 ) ), array( 'most-viewed', 'en_US', array( 'window' => 1 ) ) ) as $arguments ) { $db->reset(); rejects( fn() => $query->list( ...$arguments ) ); check( ! $db->queries, 'Invalid input never executes SQL.' ); }
	$db->reset( array(), array( array( array( 'id' => 3, 'title' => 'A collection', 'slug' => 'curated', 'description' => 'Reviewed selection', 'skill_count' => 2 ) ), array( array( 'id' => 3, 'slug' => 'jing-xuan' ) ), array( array( 'id' => 3, 'slug' => 'curated' ) ) ) );
	$topics = $query->topics( 'collection', 'en_US' );
	check( array( 'zh_CN' => 'jing-xuan', 'en_US' => 'curated' ) === $topics[0]['translations'] && 2 === $topics[0]['count'], 'Stable topic ID associates real bilingual slugs and count.' );
	foreach ( $db->queries as $sql ) { check( str_contains( $sql, "t.status='published'" ) && str_contains( $sql, 't.published_at<=UTC_TIMESTAMP()' ) && str_contains( $sql, '$.ends_at' ) && str_contains( $sql, 'JSON_VALID(dx.option_value)' ), 'Scheduled/expired/malformed topics excluded on every locale projection.' ); }
	$db->reset( array(), array( array( array( 'id' => 3, 'title' => 'A use case', 'slug' => 'build', 'description' => 'Reviewed selection', 'skill_count' => 0 ) ), array(), array( array( 'id' => 3, 'slug' => 'build' ) ) ) );
	$topics = $query->topics( 'use_case', 'en_US' ); check( array( 'en_US' => 'build' ) === $topics[0]['translations'], 'Missing translation does not create an alternate.' );
	$db->reset( array(), array( array( array( 'id' => 202, 'title' => 'Second', 'slug' => 'second', 'description' => 'Existing topic', 'skill_count' => 1 ), array( 'id' => 201, 'title' => 'First', 'slug' => 'first', 'description' => 'Existing topic', 'skill_count' => 2 ) ), array(), array( array( 'id' => 201, 'slug' => 'first' ), array( 'id' => 202, 'slug' => 'second' ) ) ) );
	$selected = $query->topicsByIds( 'collection', array( 201, 202, 999 ), 'en_US' );
	check( array_column( $selected, 'id' ) === array( 201, 202 ), 'Selected existing topics preserve requested order and omit unavailable IDs.' );
	check( count( $db->queries ) === 3 && ! str_contains( $db->queries[0], 'LIMIT 200' ) && str_contains( $db->queries[0], 't.id IN (201,202,999)' ), 'Selected topics are batched and can address identities beyond directory cap.' );
	check( str_contains( $db->queries[0], 'INNER JOIN (SELECT collection_id,COUNT(*) AS skill_count' ) && str_contains( $db->queries[0], "locale='en_US'" ) && str_contains( $db->queries[0], 't.published_at<=UTC_TIMESTAMP()' ), 'Curated topics require current-language public membership and live publication period.' );
	$db->reset(); check( array() === $query->topicsByIds( 'use_case', array(), 'zh_CN' ) && ! $db->queries, 'Empty selection does not query.' );
	rejects( fn() => $query->topicsByIds( 'collection', range( 1, 13 ), 'en_US' ) ); rejects( fn() => $query->topicsByIds( 'platform', array( 201 ), 'en_US' ) ); rejects( fn() => $query->topicsByIds( 'collection', array( '201 OR 1=1' ), 'en_US' ) );
	$gate = $query->publicTopicIdsSql( 'use_case', 'en_US' );
	check( str_contains( $gate, "t.status='published'" ) && str_contains( $gate, "tt.locale='en_US'" ) && str_contains( $gate, '$.ends_at' ) && ! str_contains( $gate, 'LIMIT' ), 'Topic publication/translation/expiry subquery can gate analytics before top-N pagination.' );
	rejects( fn() => $query->publicTopicIdsSql( 'platform', 'en_US' ) ); rejects( fn() => $query->publicTopicIdsSql( 'use_case', 'fr_FR' ) );
	$catalog->platformRows = array( array( 'id' => 20, 'slug' => 'platform-codex', 'count' => 2 ), array( 'id' => 21, 'slug' => 'platform-inferred', 'count' => 7 ) );
	Settings::$platforms = array( 20 => array( 'enabled' => true, 'verified' => true, 'evidence_url' => 'https://example.test/docs', 'translations' => array( 'en_US' => array( 'title' => 'Codex', 'description' => 'Verified description.' ) ) ), 21 => array( 'enabled' => true, 'verified' => false, 'translations' => array() ) );
	$db->reset( array(), array( array(), array( array( 'id' => 20, 'slug' => 'platform-codex', 'skill_count' => 2 ) ) ) );
	$topics = $query->topics( 'platform', 'en_US' ); check( 1 === count( $topics ) && 20 === $topics[0]['id'] && array( 'en_US' => 'platform-codex' ) === $topics[0]['translations'], 'Only explicitly verified platform descriptions appear; no guessed language.' );
	$db->reset( array( null ) ); check( array() === $query->related( 7, 'zh_CN' ) && 1 === count( $db->queries ), 'Unavailable source cannot expose relation graph.' );
	$db->reset( array( 1 ), array( array( array( 'skill_id' => 12, 'score' => 6 ) ) ) ); $result = $query->related( 7, 'en_US' ); $sql = $db->queries[1];
	check( 12 === $result[0]['id'] && str_contains( $sql, 'skill_id<>7' ) && str_contains( $sql, 'ORDER BY score DESC,skill_id ASC' ), 'Related excludes self and sorts deterministic weighted associations.' );
	check( str_contains( $sql, 'fixture_aish_use_case_skills' ) && str_contains( $sql, 'fixture_aish_collection_items' ) && str_contains( $sql, "locale='en_US'" ), 'Related combines reviewed use cases and editorial associations behind language gate.' );
	$db->reset( array( 1 ), array( array() ) ); $query->similar( 7, 'en_US' ); check( ! str_contains( $db->queries[1], 'fixture_aish_collection_items' ) && str_contains( $db->queries[1], 'fixture_aish_use_case_skills' ), 'Similar uses actual functional taxonomy/use case overlap, not editorial selection alone.' );
	$db->reset( array( 0, 1 ), array( array( array( 'skill_id' => 7, 'reviews' => 1, 'average' => 5, 'star1' => 0, 'star2' => 0, 'star3' => 0, 'star4' => 0, 'star5' => 1 ) ) ) );
	$result = $query->list( 'top-rated', 'en_US' );
	check( array() === $result['items'] && 0 === $result['total'] && 1 === $result['limited_samples']['total'] && false === $result['limited_samples']['items'][0]['metric']['rankable'], 'Insufficient samples retain real scores separately without fake ranking.' );
	check( str_contains( $db->queries[1], 'HAVING COUNT(*) BETWEEN 1 AND 4' ), 'Insufficient review sample filter is applied before its count.' );
	$db->reset( array( 350 ) ); check( 350 === $query->sitemapCount( 'collection', 'en_US' ), 'Sitemap count does not inherit navigation limit 200.' );
	check( ! str_contains( $db->queries[0], 'LIMIT' ) && str_contains( $db->queries[0], 'AND EXISTS (SELECT 1' ) && str_contains( $db->queries[0], "locale='en_US'" ), 'Sitemap count requires actual current-language public membership.' );
	$db->reset( array(), array( array( array( 'id' => '201', 'slug' => 'next', 'updated_at' => '2026-11-01 00:00:00' ) ) ) );
	$rows = $query->sitemapItems( 'use_case', 'en_US', 2, 200 ); check( 201 === $rows[0]['id'] && str_ends_with( $db->queries[0], 'ORDER BY t.id ASC LIMIT 200 OFFSET 200' ), 'Sitemap page 2 remains stable and includes eligible rows beyond first 200.' );
	rejects( fn() => $query->sitemapItems( 'collection', 'en_US', 0 ) );

	// PublicCatalog itself is covered by frontend fixtures; source assertion protects term identity SQL.
	$source = file_get_contents( $base . 'Frontend/PublicCatalog.php' );
	check( str_contains( $source, 'term_translations' ) && str_contains( $source, 'tt.term_id=t.id AND tt.term_type=%s AND tt.locale=%s' ) && str_contains( $source, "'translation_missing' => \$missing" ) && str_contains( $source, "'parent_id'" ), 'Taxonomy projection retains identity, locale, hierarchy and explicit missing-translation status.' );
	echo "Discovery query isolated contracts: $count passed; no live MySQL assertions.\n";
}
