<?php
declare(strict_types=1);

// Isolated contract checks. The recording wpdb below does not execute MySQL or prove SQL results.
namespace AISkillsHub\Core\Frontend {
	final class SeoSettings {
		public static function excludedIds( string $kind, string $locale ): array { return 'en_US' === $locale ? array( 91 ) : array(); }
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	function get_option( string $key, mixed $default = false ): mixed { return 'aish_schema_version' === $key ? \AISkillsHub\Core\Database\Schema::VERSION : $default; }
	function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
	final class wpdb {
		public string $prefix = 'test_';
		public string $last_error = '';
		public array $prepared = array();
		public array $executed = array();
		public array $rows = array();
		public int $total = 2;
		public function suppress_errors( bool $value ): bool { return false; }
		public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
		public function prepare( string $sql, array $values ): string {
			$this->prepared[] = array( $sql, $values );
			return $sql;
		}
		public function get_var( string $sql ): int { $this->executed[] = $sql; return $this->total; }
		public function get_results( string $sql, string $format ): array {
			$this->executed[] = $sql;
			return str_contains( $sql, 'public_terms' ) ? array() : $this->rows;
		}
	}
	$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Security/Input.php', 'Admin/Management.php', 'Frontend/PublicCatalog.php' ) as $file ) { require $root . $file; }
	use AISkillsHub\Core\Database\Database;
	use AISkillsHub\Core\Database\Migrator;
	use AISkillsHub\Core\Database\Schema;
	use AISkillsHub\Core\Frontend\PublicCatalog;
	$count = 0;
	function check( bool $condition, string $message ): void { global $count; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$count; }
	function rejects( callable $callback ): void {
		try { $callback(); } catch ( InvalidArgumentException $error ) { check( true, 'Rejected invalid request' ); return; }
		throw new RuntimeException( 'Invalid public request was accepted.' );
	}
	function guard( string $sql ): void {
		foreach ( array( "s.status='published'", "s.review_status='approved'", 's.reviewed_revision=s.revision', 's.reviewer_id>0', 's.reviewed_at IS NOT NULL', 'tr.locale=%s', 'COALESCE(tr.content' ) as $required ) {
			check( str_contains( $sql, $required ), 'Missing public guard: ' . $required );
		}
	}
	$connection = new wpdb();
	$db = new Database( $connection );
	$catalog = new PublicCatalog( $db, new Migrator( $db ) );
	$filters = array( 'q' => "中文 %_\\' OR 1=1 --", 'category' => 'tools', 'tag' => 'safe', 'platform' => 'platform-cli' );
	$catalog->skills( 'zh_CN', $filters );
	list( $sql, $values ) = $connection->prepared[0];
	guard( $sql );
	check( ! str_contains( $sql, 'OR 1=1 --' ), 'Keyword must stay in bound values.' );
	check( $values[0] === 'zh_CN', 'Current locale bound.' );
	check( $values[1] === '%' . $connection->esc_like( $filters['q'] ) . '%', 'LIKE wildcard bytes escaped as literals.' );
	check( $values[1] === $values[2] && $values[2] === $values[3], 'Title, summary and body share keyword.' );
	check( str_contains( $sql, 'tr.content LIKE %s' ), 'Skill body is searched.' );
	check( substr_count( $sql, 'EXISTS (' ) === 3, 'Three filters combine in one query.' );
	check( ! str_contains( $sql, 'repositories' ), 'Count skips unused repository join.' );
	$executions = count( $connection->executed );
	$catalog->skills( 'zh_CN', $filters );
	check( count( $connection->executed ) === $executions, 'Repeated search is memoized within the request.' );
	$result = $catalog->skills( 'zh_CN', $filters + array( 'page' => 2 ) );
	check( count( $connection->executed ) === $executions && $result['items'] === array(), 'Empty page reuses count and avoids collection query.' );
	foreach ( array( array( 'q' => str_repeat( '中', 101 ) ), array( 'sort' => 'title DESC; DROP TABLE x' ), array( 'platform' => 'ordinary-tag' ), array( 'category' => array() ), array( 'page' => 0 ), array( 'per_page' => 25 ) ) as $invalid ) {
		rejects( fn() => $catalog->skills( 'en_US', $invalid ) );
	}
	rejects( fn() => $catalog->skills( 'fr_FR' ) );
	$subquery = $catalog->matchingIdsSql( 'en_US', array( 'category' => 'tools' ) );
	guard( $subquery );
	check( str_starts_with( $subquery, 'SELECT s.id FROM' ), 'Related tutorial filter has a bounded subquery, not an ID materialization.' );
	$base = array( 'title' => 'Real title', 'slug' => 'real-title', 'summary' => 'Summary', 'content' => '<p>Body</p>', 'locale' => 'en_US', 'risk_level' => 'low' );
	$connection->rows = array( array( 'id' => 2 ) + $base, array( 'id' => 1 ) + $base, array( 'id' => 3, 'content' => '<p>&nbsp;</p>' ) + $base );
	$before = count( $connection->executed );
	$cards = $catalog->cardsByIds( array( 1, 3, 2, 1 ), 'en_US' );
	check( array_column( $cards, 'id' ) === array( 1, 2 ), 'Batch preserves requested order/deduplication and rejects empty translated body defensively.' );
	check( count( $connection->executed ) - $before === 3, 'Batch takes one collection + two association queries independent of card count.' );
	check( ! isset( $cards[0]['content'], $cards[0]['review_notes'] ), 'Cards omit body and private review fields.' );
	$before = count( $connection->executed );
	$catalog->cardsByIds( array( 1, 3, 2, 1 ), 'en_US' );
	check( count( $connection->executed ) === $before, 'Repeated batch uses request memo.' );
	rejects( fn() => $catalog->cardsByIds( range( 1, 25 ), 'en_US' ) );
	rejects( fn() => $catalog->cardsByIds( array( '1' ), 'en_US' ) );
	$connection->rows = array( array( 'id' => 1, 'slug' => 'real-title', 'updated_at' => '2026-11-05 00:00:00' ) );
	foreach ( array( 'skill', 'category', 'tag' ) as $kind ) {
		$catalog->sitemapCount( $kind, 'en_US' );
		list( $sql, $values ) = end( $connection->prepared );
		guard( $sql );
		check( in_array( 91, $values, true ) && str_contains( $sql, 'NOT IN (%d)' ), 'Noindex removed before sitemap counting.' );
		$rows = $catalog->sitemapItems( $kind, 'en_US', 2, 200 );
		list( $sql, $values ) = end( $connection->prepared );
		guard( $sql );
		check( in_array( 91, $values, true ) && array_slice( $values, -2 ) === array( 200, 200 ), 'Sitemap noindex and pagination are bound.' );
		check( array_keys( $rows[0] ) === array( 'id', 'slug', 'updated_at' ), 'Sitemap projection stays compact.' );
		if ( 'skill' !== $kind ) { check( str_contains( $sql, 't.is_archived=0' ) && str_contains( $sql, 'GROUP BY t.id,t.slug' ), 'Taxonomy sitemap contains active terms with matching public content only.' ); }
	}
	rejects( fn() => $catalog->sitemapItems( 'skill', 'en_US', 0 ) );
	rejects( fn() => $catalog->sitemapCount( 'repository', 'en_US' ) );
	$previous = Schema::definitions( $db, '1.2.0' );
	$current = Schema::definitions( $db, '1.3.0' );
	check( ! str_contains( $previous['skills'], 'KEY public_updated' ) && str_contains( $current['skills'], 'KEY public_updated (status,review_status,updated_at,id)' ), 'Time index introduced only at 1.3.0.' );
	check( str_contains( $current['skill_translations'], 'KEY locale_title (locale,title,skill_id)' ), 'Language title index defined.' );
	check( str_contains( $current['skills'], 'UNIQUE KEY skill_source (repository_id,source_path)' ), 'Permanent source uniqueness preserved.' );
	echo 'PASS: ' . $count . " isolated public search and sitemap query-contract assertions (recording DB stub; no MySQL/WordPress integration).\n";
}
