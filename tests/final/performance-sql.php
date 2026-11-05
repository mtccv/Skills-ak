<?php
declare(strict_types=1);

// Actual MySQL measurements on a dedicated, explicitly synthetic QA data set.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( 'qa_security' !== DB_NAME || ! in_array( parse_url( (string) get_option( 'home' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Dedicated local qa_security database required.' ); }

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Frontend\Editorial;

$input = json_decode( stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
$core = Core::boot(); $db = $core->get( 'database' );
$admin = get_user_by( 'login', 'aish-security-administrator' );
if ( ! $admin ) { throw new RuntimeException( 'Run security fixtures first.' ); }
wp_set_current_user( $admin->ID );
$fixture = get_option( 'aish_final_qa_performance' );
if ( 'seed' === ( $input['mode'] ?? '' ) ) {
	if ( ! is_array( $fixture ) ) {
		$count = (int) ( $input['skills'] ?? 1000 );
		if ( $count < 100 || $count > 3000 ) { throw new RuntimeException( 'QA fixture count must be 100–3000.' ); }
		$run = 'bench-' . bin2hex( random_bytes( 4 ) ); $now = gmdate( 'Y-m-d H:i:s' );
		$categories = array(); $tags = array();
		for ( $i = 0; $i < 8; ++$i ) { $categories[] = $core->catalog()->createCategory( 'QA benchmark category ' . $i, $run . '-category-' . $i ); }
		foreach ( array( 'group', 'platform-qa', 'featured', 'license-mit' ) as $kind ) { $tags[ $kind ] = $core->catalog()->createTag( 'QA benchmark ' . $kind, in_array( $kind, array( 'featured', 'license-mit' ), true ) ? $kind : ( 'platform-qa' === $kind ? 'platform-' . $run : $run . '-group' ) ); }
		$ids = array(); $bytes = 0;
		$db->transaction( function () use ( $count, $run, $now, $admin, $db, $categories, $tags, &$ids, &$bytes ): void {
			for ( $i = 0; $i < $count; ++$i ) {
				$id = $db->insert( 'skills', array( 'repository_id' => null, 'source_path' => null, 'status' => 'published', 'revision' => 2, 'review_status' => 'approved', 'risk_level' => 'unknown', 'reviewer_id' => (int) $admin->ID, 'reviewed_at' => $now, 'reviewed_revision' => 2, 'review_notes' => 'Synthetic benchmark fixture, not a safety certification.', 'created_at' => $now, 'updated_at' => $now ) );
				$ids[] = $id;
				foreach ( array( 'en_US', 'zh_CN' ) as $locale ) {
					$text = 'en_US' === $locale ? 'QA benchmark instructions and documented testing workflow. ' : '中文性能测试说明，人工审核的独立测试数据。';
					$body = '<p>' . str_repeat( $text, $i >= $count - 24 ? (int) floor( 262000 / strlen( $text ) ) : 36 ) . '</p>';
					$bytes += strlen( $body );
					$db->insert( 'skill_translations', array( 'skill_id' => $id, 'locale' => $locale, 'title' => ( 'zh_CN' === $locale ? '中文 ' : '' ) . 'QA benchmark ' . $i, 'slug' => $run . '-' . $i, 'summary' => $text, 'content' => $body, 'seo_title' => '', 'seo_description' => '', 'created_at' => $now, 'updated_at' => $now ) );
				}
				$db->insert( 'skill_categories', array( 'skill_id' => $id, 'category_id' => $categories[ $i % 8 ] ) );
				foreach ( $tags as $kind => $tag ) { if ( 'featured' !== $kind || 0 === $i % 10 ) { $db->insert( 'skill_tags', array( 'skill_id' => $id, 'tag_id' => $tag ) ); } }
			}
		} );
		$tutorials = array();
		for ( $i = 0; $i < 80; ++$i ) {
			$id = wp_insert_post( array( 'post_type' => Editorial::TUTORIAL, 'post_status' => 'publish', 'post_title' => 'QA benchmark tutorial ' . $i, 'post_content' => '<p>' . str_repeat( 'Benchmark tutorial body. ', 0 === $i % 10 ? 10000 : 100 ) . '</p>', 'post_excerpt' => 'QA benchmark concise excerpt.', 'meta_input' => array( '_aish_locale' => 0 === $i % 2 ? 'en_US' : 'zh_CN', '_aish_related_skill' => $ids[ $i ] ) ), true );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Benchmark tutorial creation failed.' ); }
			$tutorials[] = (int) $id;
		}
		$fixture = array( 'run' => $run, 'skills' => $count, 'translations' => $count * 2, 'body_bytes' => $bytes, 'large_skills' => 24, 'large_body_bytes_approx' => 262000, 'tutorials' => count( $tutorials ), 'first_id' => $ids[0], 'last_id' => end( $ids ), 'last_slug' => $run . '-' . ( $count - 1 ), 'category' => $run . '-category-0', 'tag' => $run . '-group', 'platform' => 'platform-' . $run );
		update_option( 'aish_final_qa_performance', $fixture, false );
	}
	echo json_encode( $fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT ) . "\n"; exit;
}
if ( ! is_array( $fixture ) ) { throw new RuntimeException( 'Seed benchmark fixtures first.' ); }

$trials = array(); $plans = array();
$factories = array(
	'list_12' => static fn( PublicCatalog $public ) => $public->skills( 'en_US', array( 'per_page' => 12 ) ),
	'list_24' => static fn( PublicCatalog $public ) => $public->skills( 'en_US', array( 'per_page' => 24 ) ),
	'search_en' => static fn( PublicCatalog $public ) => $public->skills( 'en_US', array( 'q' => 'benchmark', 'sort' => 'title' ) ),
	'search_zh' => static fn( PublicCatalog $public ) => $public->skills( 'zh_CN', array( 'q' => '中文' ) ),
	'combined_filters' => static fn( PublicCatalog $public ) => $public->skills( 'en_US', array( 'category' => $fixture['category'], 'tag' => $fixture['tag'], 'platform' => $fixture['platform'], 'q' => 'benchmark' ) ),
	'pagination_20' => static fn( PublicCatalog $public ) => $public->skills( 'en_US', array( 'page' => 20 ) ),
	'detail' => static fn( PublicCatalog $public ) => $public->detail( $fixture['last_id'], 'en_US' ),
	'sitemap_skills' => static fn( PublicCatalog $public ) => $public->sitemapItems( 'skill', 'en_US', 1, 200 ),
	'tutorial_cards' => static fn( PublicCatalog $public ) => ( new Editorial( $public, $db ) )->tutorials( 'en_US', array( 'per_page' => 12 ) ),
	'tutorial_sitemap' => static fn( PublicCatalog $public ) => ( new Editorial( $public, $db ) )->sitemapItems( 'en_US', 1, 200 ),
);
foreach ( $factories as $name => $operation ) {
	for ( $round = 1; $round <= 3; ++$round ) {
		$queries = array(); $watch = static function ( string $sql ) use ( &$queries ): string { if ( preg_match( '/^\s*SELECT\b/i', $sql ) ) { $queries[] = $sql; } return $sql; };
		$public = new PublicCatalog( $db, $core->migrator() );
		memory_reset_peak_usage(); $before = memory_get_usage( true ); $started = hrtime( true );
		add_filter( 'query', $watch );
		try { $value = $operation( $public ); } finally { remove_filter( 'query', $watch ); }
		$elapsed = ( hrtime( true ) - $started ) / 1000000;
		$coreQueries = count( array_filter( $queries, static fn( string $sql ): bool => str_contains( $sql, $db->prefix() . 'aish_' ) ) );
		$trials[ $name ][] = array( 'round' => $round, 'application_ms' => round( $elapsed, 3 ), 'select_queries' => count( $queries ), 'core_select_queries' => $coreQueries, 'native_select_queries' => count( $queries ) - $coreQueries, 'returned_items' => isset( $value['items'] ) ? count( $value['items'] ) : ( 'detail' === $name ? (int) ( null !== $value ) : count( $value ) ), 'total_matches' => $value['total'] ?? null, 'payload_bytes' => strlen( json_encode( $value, JSON_THROW_ON_ERROR ) ), 'peak_growth_bytes' => max( 0, memory_get_peak_usage( true ) - $before ) );
		if ( 1 === $round ) {
			foreach ( $queries as $index => $sql ) {
				$plan = $db->row( 'EXPLAIN FORMAT=JSON ' . $sql );
				$plans[ $name ][] = array( 'index' => $index, 'sql' => $sql, 'plan' => json_decode( array_values( $plan )[0], true, 512, JSON_THROW_ON_ERROR ) );
			}
		}
	}
}
$public = new PublicCatalog( $db, $core->migrator() ); $public->skills( 'en_US' );
$before = $wpdb->num_queries; $public->skills( 'en_US' ); $memoQueries = $wpdb->num_queries - $before;
$environment = array( 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'mysql_buffer_pool_bytes' => $wpdb->get_var( 'SELECT @@innodb_buffer_pool_size' ), 'mysql_transaction_isolation' => $wpdb->get_var( 'SELECT @@transaction_isolation' ), 'memory_limit' => ini_get( 'memory_limit' ), 'opcache_cli' => ini_get( 'opcache.enable_cli' ), 'persistent_object_cache' => (bool) wp_using_ext_object_cache(), 'uname' => php_uname( 'm' ) );
$actual = array();
foreach ( array( 'skills', 'skill_translations', 'skill_categories', 'skill_tags', 'categories', 'tags' ) as $entity ) { $actual[ $entity ] = (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( $entity ) ); }
$actual['translation_body_bytes'] = (int) $db->value( 'SELECT SUM(OCTET_LENGTH(content)) FROM ' . $db->table( 'skill_translations' ) );
$actual['tutorials'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='aish_tutorial'" );
$bounded = $trials['list_12'][0]['core_select_queries'] <= 4 && $trials['list_12'][0]['core_select_queries'] === $trials['list_24'][0]['core_select_queries'];
$result = array( 'kind' => 'actual_mysql_queries_and_explain_on_synthetic_local_dataset', 'measured_at_utc' => gmdate( 'c' ), 'environment' => $environment, 'fixture' => $fixture, 'actual_database_rows' => $actual, 'trials' => $trials, 'plans' => $plans, 'checks' => array( 'core_list_query_count_bounded_and_independent_of_12_or_24_cards' => $bounded, 'same_request_memo_repeat_queries' => $memoQueries ), 'limits' => array( 'Three sequential rounds per case; not a throughput certification.', 'No buffer pool or operating-system cache flush; no cold-cache claim.', 'Application timings include SQL and PHP projection; plans are MySQL EXPLAIN, not simulated.', 'Native option/meta cache warmup queries are recorded separately from Core data queries.', 'Separate HTTP script measures bounded concurrency; this file is sequential.', 'Other isolated QA databases share this MySQL instance and host; timing can include their load.' ) );
echo json_encode( $result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
exit( $bounded && 0 === $memoQueries ? 0 : 1 );
