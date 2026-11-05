<?php
declare(strict_types=1);

/** Query/payload contracts using isolated WP/SQL substitutes, never real WordPress/MySQL. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['checks'] = 0; $GLOBALS['hooks'] = array(); $GLOBALS['posts'] = array(); $GLOBALS['meta'] = array();
$GLOBALS['selected_ids'] = array(); $GLOBALS['after_query'] = null; $GLOBALS['kses_calls'] = 0; $GLOBALS['meta_primes'] = 0;
function check( bool $value, string $message ): void { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function add_filter( string $name, callable $callback, int $priority = 10, int $accepted = 1 ): void { $GLOBALS['hooks'][ $name ][] = $callback; }
function remove_filter( string $name, callable $callback, int $priority = 10 ): void { $GLOBALS['hooks'][ $name ] = array_filter( $GLOBALS['hooks'][ $name ] ?? array(), static fn( $item ): bool => $item !== $callback ); }
function apply_filters( string $name, mixed $value, mixed ...$arguments ): mixed { foreach ( $GLOBALS['hooks'][ $name ] ?? array() as $callback ) { $value = $callback( $value, ...$arguments ); } return $value; }
function wp_strip_all_tags( string $value ): string { return strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $value ) ); }
function wp_kses_post( string $value ): string { ++$GLOBALS['kses_calls']; return str_replace( '<script>bad()</script>', '', $value ); }
function wp_trim_words( string $value, int $length, string $suffix ): string { $parts = preg_split( '/\s+/', trim( $value ) ); return count( $parts ) > $length ? implode( ' ', array_slice( $parts, 0, $length ) ) . $suffix : $value; }
function get_post( int $id ): ?WP_Post { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_meta( int $id, string $key, bool $single = true ): mixed { $value = $GLOBALS['meta'][ $id ][ $key ] ?? ( $single ? '' : array() ); return $single && is_array( $value ) ? ( $value[0] ?? '' ) : $value; }
function get_userdata( int $id ): object { return (object) array( 'display_name' => 'Existing author' ); }
function update_meta_cache( string $type, array $ids ): void { ++$GLOBALS['meta_primes']; }
function update_post_author_caches( array $posts ): void {}
class WP_Post {
	public string $post_type = 'aish_tutorial'; public string $post_status = 'publish'; public string $post_password = '';
	public string $post_title = 'Existing tutorial'; public string $post_content = '<p>Existing body</p>'; public string $post_excerpt = 'Actual excerpt';
	public string $post_name = 'existing'; public string $post_modified_gmt = '2026-11-05 01:02:03'; public string $post_date_gmt = '2026-11-04 01:02:03'; public int $post_author = 1;
	public function __construct( public int $ID ) {}
}
class WP_Query {
	public static array $calls = array(); public array $posts; public int $found_posts;
	public function __construct( private array $arguments ) {
		$sql = apply_filters( 'get_meta_sql', array( 'join' => '', 'where' => '' ), array(), 'post', 'test_posts', 'ID', $this );
		self::$calls[] = array( 'arguments' => $arguments, 'sql' => $sql );
		$this->posts = 'ids' === ( $arguments['fields'] ?? '' ) ? $GLOBALS['selected_ids'] : array_map( 'get_post', $GLOBALS['selected_ids'] );
		$this->found_posts = count( $this->posts );
		if ( is_callable( $GLOBALS['after_query'] ) ) { ( $GLOBALS['after_query'] )(); }
	}
	public function get( string $key ): mixed { return $this->arguments[ $key ] ?? null; }
}
class ProjectionDatabase {
	public string $posts = 'test_posts'; public string $postmeta = 'test_postmeta'; public string $last_error = '';
	public bool $suppressed = false; public bool $fail = false; public array $reads = array(); private array $prepared = array();
	public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function prepare( string $sql, array $values ): string { $key = 'prepared-' . count( $this->prepared ); $this->prepared[ $key ] = array( $sql, $values ); return $key; }
	public function get_results( string $key, string $output ): ?array {
		[ $sql, $values ] = $this->prepared[ $key ]; $this->reads[] = array( 'sql' => $sql, 'values' => $values );
		if ( $this->fail ) { $this->last_error = 'sensitive SQL failure'; return null; }
		$locale = array_pop( $values ); $type = array_pop( $values ); $rows = array();
		// Independent fixture model of the predicates whose SQL presence is checked below.
		foreach ( $values as $id ) {
			$post = get_post( $id ); $meta = $GLOBALS['meta'][ $id ] ?? array();
			if ( ! $post || $post->post_type !== $type || 'publish' !== $post->post_status || '' !== $post->post_password || get_post_meta( $id, '_aish_locale' ) !== $locale || ! in_array( $meta['_aish_seo_noindex'] ?? '', array( '', '0' ), true ) || ! trim( wp_strip_all_tags( $post->post_title ) ) || ! preg_match( '/[^\s\p{Z}]/u', html_entity_decode( wp_strip_all_tags( $post->post_content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) { continue; }
			$rows[] = array( 'ID' => $post->ID, 'post_modified_gmt' => $post->post_modified_gmt );
		}
		usort( $rows, static fn( array $a, array $b ): int => $a['ID'] <=> $b['ID'] );
		return $rows;
	}
}
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Security/Input.php';
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Frontend/Editorial.php';
use AISkillsHub\Core\Frontend\Editorial;
$GLOBALS['wpdb'] = $db = new ProjectionDatabase();
for ( $id = 1; $id <= 8; ++$id ) { $GLOBALS['posts'][ $id ] = new WP_Post( $id ); $GLOBALS['meta'][ $id ] = array( '_aish_locale' => 'en_US' ); }
$editorial = new Editorial();
$GLOBALS['selected_ids'] = array( 1 );
$GLOBALS['posts'][1]->post_content = str_repeat( 'Actual text ', 100000 );
$cards = $editorial->tutorials( 'en_US' )['items'];
check( ! array_key_exists( 'content', $cards[0] ), 'Card retains a full body.' );
check( 'Actual excerpt' === $cards[0]['summary'] && 0 === $GLOBALS['kses_calls'], 'Card sanitized its unused body despite having an excerpt.' );
check( 'Existing author' === $cards[0]['author'] && '2026-11-04 01:02:03' === $cards[0]['date'], 'Card metadata changed.' );
$related = $editorial->relatedTutorials( 1, 'en_US' );
check( ! array_key_exists( 'content', $related[0] ) && 0 === $GLOBALS['kses_calls'], 'Related cards still retain/sanitize unused full content.' );
$detail = $editorial->tutorial( 1, 'en_US' );
check( $GLOBALS['posts'][1]->post_content === $detail['content'] && 1 === $GLOBALS['kses_calls'], 'Detail lost its sanitized real body.' );
$GLOBALS['posts'][1]->post_excerpt = '';
$GLOBALS['posts'][1]->post_content = '<p>' . implode( ' ', range( 1, 40 ) ) . '</p><script>bad()</script>';
$editorial->clearCache();
$cards = $editorial->tutorials( 'en_US' )['items'];
check( implode( ' ', range( 1, 32 ) ) . '…' === $cards[0]['summary'], 'Existing KSES/strip/32-word summary fallback changed.' );
check( ! array_key_exists( 'content', $cards[0] ) && 2 === $GLOBALS['kses_calls'], 'Fallback card must sanitize for its summary without keeping the body.' );

$GLOBALS['selected_ids'] = range( 1, 8 );
$GLOBALS['after_query'] = static function (): void {
	$GLOBALS['posts'][2]->post_status = 'draft';
	$GLOBALS['posts'][3]->post_password = 'private';
	$GLOBALS['meta'][4]['_aish_locale'] = 'zh_CN';
	$GLOBALS['meta'][5]['_aish_seo_noindex'] = '1';
	$GLOBALS['posts'][6]->post_content = '<script>bad()</script>';
	$GLOBALS['posts'][7]->post_title = '';
	$GLOBALS['posts'][8]->post_type = 'aish_message';
};
$primed = $GLOBALS['meta_primes']; $sanitized = $GLOBALS['kses_calls'];
$items = $editorial->sitemapItems( 'en_US', 2, 200 );
$call = end( WP_Query::$calls ); $read = end( $db->reads );
check( 'ids' === $call['arguments']['fields'] && false === $call['arguments']['update_post_meta_cache'], 'Sitemap selection loads full post bodies/meta.' );
check( false === $call['arguments']['cache_results'] && true === $call['arguments']['suppress_filters'], 'Existing native query/plugin/cache semantics changed.' );
check( 2 === $call['arguments']['paged'] && 200 === $call['arguments']['posts_per_page'] && true === $call['arguments']['no_found_rows'], 'Pagination/order query changed.' );
check( 'en_US' === $call['arguments']['meta_value'] && 'publish' === $call['arguments']['post_status'] && false === $call['arguments']['has_password'], 'Selection public language gates missing.' );
check( str_contains( $call['sql']['where'], "'_aish_seo_noindex'" ) && 2 === substr_count( $call['sql']['where'], "REGEXP '[^[:space:]]'" ), 'Native selection lost indexability/text guards.' );
check( str_starts_with( $read['sql'], 'SELECT test_posts.ID,test_posts.post_modified_gmt FROM ' ) && ! str_contains( $read['sql'], 'SELECT test_posts.*' ), 'Sitemap projection is not compact.' );
foreach ( array( 'BINARY test_posts.post_type=BINARY %s', "BINARY test_posts.post_status='publish'", 'OCTET_LENGTH(test_posts.post_password)=0', "BINARY aish_locale.meta_key='_aish_locale'", 'ORDER BY aish_locale.meta_id ASC LIMIT 1) AS BINARY)=CAST(%s AS BINARY)', "aish_noindex.meta_key='_aish_seo_noindex'", "NOT IN ('','0')", 'test_posts.ID IN (%d,%d,%d,%d,%d,%d,%d,%d)' ) as $guard ) { check( str_contains( $read['sql'], $guard ), 'Missing projection gate: ' . $guard ); }
check( 2 === substr_count( $read['sql'], "REGEXP '[^[:space:]]'" ) && str_contains( $read['sql'], '<script[^>]*>.*?</script>' ), 'Second read omitted real title/body checks.' );
check( array_merge( range( 1, 8 ), array( 'aish_tutorial', 'en_US' ) ) === $read['values'], 'IDs/type/locale were not bound independently.' );
check( array( array( 'id' => 1, 'locale' => 'en_US', 'updated_at' => '2026-11-05 01:02:03' ) ) === $items, 'Withdrawn/changed rows leaked after ID selection.' );
check( 1 === count( $db->reads ) && $primed === $GLOBALS['meta_primes'] && $sanitized === $GLOBALS['kses_calls'], 'Projection does N+1 or primes/sanitizes bodies.' );
$editorial->sitemapItems( 'en_US', 2, 200 );
check( 1 === count( $db->reads ), 'Repeated request did not reuse its compact projection.' );
$GLOBALS['after_query'] = null; $GLOBALS['selected_ids'] = array();
check( array() === $editorial->sitemapItems( 'zh_CN', 1 ) && 1 === count( $db->reads ), 'Empty selection made an unnecessary query.' );
for ( $id = 9; $id <= 13; ++$id ) { $GLOBALS['posts'][ $id ] = new WP_Post( $id ); $GLOBALS['meta'][ $id ] = array( '_aish_locale' => 'en_US' ); }
$GLOBALS['meta'][9]['_aish_locale'] = array( 'zh_CN', 'en_US' );
$GLOBALS['meta'][10]['_aish_locale'] = array( 'en_US', 'zh_CN' );
$GLOBALS['meta'][11]['_aish_locale'] = 'EN_US';
$GLOBALS['posts'][12]->post_password = ' ';
$GLOBALS['posts'][13]->post_status = 'PUBLISH';
$GLOBALS['selected_ids'] = range( 9, 13 );
check( array( 10 ) === array_column( $editorial->sitemapItems( 'en_US', 4 ), 'id' ), 'Compact gate must match first locale and exact password/status/language strings.' );
foreach ( array( array( 'fr_FR', 1, 200 ), array( 'en_US', 0, 200 ), array( 'en_US', 100001, 200 ), array( 'en_US', 1, 1001 ) ) as $args ) {
	try { $editorial->sitemapItems( ...$args ); check( false, 'Invalid pagination/locale accepted.' ); } catch ( InvalidArgumentException $expected ) { check( true, 'Invalid parameter rejected.' ); }
}
$GLOBALS['selected_ids'] = array( 1, 2 );
try { $editorial->sitemapItems( 'en_US', 1, 1 ); check( false, 'Adapter may exceed requested page size.' ); } catch ( RuntimeException $expected ) { check( true, 'Oversize selection rejected.' ); }
$GLOBALS['selected_ids'] = array( 0 );
try { $editorial->sitemapItems( 'en_US', 1 ); check( false, 'Invalid selected ID accepted.' ); } catch ( InvalidArgumentException $expected ) { check( true, 'Invalid ID rejected.' ); }
$GLOBALS['selected_ids'] = array( 1 ); $db->fail = true;
try { $editorial->sitemapItems( 'en_US', 3 ); check( false, 'Projection query failure ignored.' ); }
catch ( RuntimeException $error ) { check( 'Editorial storage is unavailable.' === $error->getMessage(), 'Projection leaked raw SQL failure.' ); }
check( ! $db->suppressed && empty( $GLOBALS['hooks']['get_meta_sql'] ), 'Query failure left temporary state behind.' );
echo 'Editorial projections: ' . $GLOBALS['checks'] . " isolated assertions passed (no WordPress/MySQL or performance benchmark).\n";
