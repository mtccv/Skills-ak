<?php
declare(strict_types=1);

/** Isolated contracts only: no WordPress, SQL execution or real WPML plugin is loaded. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
$GLOBALS['posts'] = array();
$GLOBALS['postmeta'] = array();
$GLOBALS['checks'] = 0;
function check( bool $condition, string $message ): void {
	++$GLOBALS['checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function add_filter( string $name, callable $callback, int $priority = 10, int $accepted = 1 ): void { $GLOBALS['hooks'][ $name ][] = $callback; }
function remove_filter( string $name, callable $callback, int $priority = 10 ): void {
	$GLOBALS['hooks'][ $name ] = array_filter( $GLOBALS['hooks'][ $name ] ?? array(), static fn( callable $item ): bool => $item !== $callback );
}
function has_filter( string $name ): bool { return ! empty( $GLOBALS['hooks'][ $name ] ); }
function apply_filters( string $name, mixed $value, mixed ...$arguments ): mixed {
	foreach ( $GLOBALS['hooks'][ $name ] ?? array() as $callback ) { $value = $callback( $value, ...$arguments ); }
	return $value;
}
function wp_strip_all_tags( string $value ): string { return strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $value ) ); }
function wp_kses_post( string $value ): string { return $value; }
function wp_trim_words( string $value, int $length, string $suffix ): string { return $value; }
function get_post( int $id ): ?WP_Post { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_meta( int $id, string $key, bool $single = true ): mixed { return $GLOBALS['postmeta'][ $id ][ $key ] ?? ( $single ? '' : array() ); }
function get_userdata( int $id ): object { return (object) array( 'display_name' => 'Actual author' ); }
function update_meta_cache( string $type, array $ids ): void {}
function update_post_author_caches( array $posts ): void {}
class EditorialDatabaseStub {
	public string $posts = 'test_posts';
	public string $postmeta = 'test_postmeta';
	public string $last_error = '';
	public bool $suppressed = false;
	public function suppress_errors( bool $suppress ): bool { $previous = $this->suppressed; $this->suppressed = $suppress; return $previous; }
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
	public function prepare( string $sql, mixed ...$values ): string {
		return preg_replace_callback( '/%s/', static function () use ( &$values ): string { return "'" . str_replace( "'", "''", (string) array_shift( $values ) ) . "'"; }, $sql );
	}
}
class WP_Post {
	public int $ID;
	public string $post_type = 'aish_tutorial';
	public string $post_status = 'publish';
	public string $post_password = '';
	public string $post_title = '真实教程';
	public string $post_content = '<p>Actual content.</p>';
	public string $post_excerpt = '';
	public string $post_name = 'actual-tutorial';
	public string $post_modified_gmt = '2026-11-04 12:00:00';
	public string $post_date_gmt = '2026-11-04 12:00:00';
	public int $post_author = 1;
	public function __construct( int $id ) { $this->ID = $id; }
}
class WP_Query {
	public static array $calls = array();
	public static bool $fail = false;
	public array $posts = array();
	public int $found_posts = 0;
	private array $arguments;
	public function __construct( array $arguments ) {
		$this->arguments = $arguments;
		$sql = array( 'join' => '', 'where' => '' );
		// A different context must never receive editorial constraints.
		check( $sql === apply_filters( 'get_meta_sql', $sql, array(), 'post', 'test_posts', 'ID', null ), 'SQL filter escaped query scope.' );
		$sql = apply_filters( 'get_meta_sql', $sql, array(), 'post', 'test_posts', 'ID', $this );
		self::$calls[] = array( 'arguments' => $arguments, 'sql' => $sql );
		if ( self::$fail ) { $GLOBALS['wpdb']->last_error = 'sensitive query details'; }
	}
	public function get( string $key ): mixed { return $this->arguments[ $key ] ?? null; }
}
$GLOBALS['wpdb'] = new EditorialDatabaseStub();
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Security/Input.php';
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Frontend/Editorial.php';
use AISkillsHub\Core\Frontend\Editorial;
$editorial = new Editorial();
$result = $editorial->tutorials( 'zh_CN', array( 'q' => '-测试%_\'教程', 'sort' => 'title', 'page' => 2, 'per_page' => 12 ) );
$call = WP_Query::$calls[0];
check( 'publish' === $call['arguments']['post_status'] && false === $call['arguments']['has_password'], 'Public query permits nonpublic posts.' );
check( 'zh_CN' === $call['arguments']['meta_value'], 'Query lost explicit locale.' );
check( array( 'title' => 'ASC', 'ID' => 'ASC' ) === $call['arguments']['orderby'], 'Sort is not deterministic.' );
check( 2 === $call['arguments']['paged'], 'Pagination was not preserved.' );
check( ! isset( $call['arguments']['s'] ), 'Native minus/stopword parsing must not change literal search.' );
foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $column ) { check( str_contains( $call['sql']['where'], "test_posts.$column LIKE '%-测试\\%\\_''教程%'" ), 'Search literal not bound for ' . $column ); }
check( 2 === substr_count( $call['sql']['where'], "REGEXP '[^[:space:]]'" ) && str_contains( $call['sql']['where'], '<script[^>]*>.*?</script>' ), 'SQL title/body completeness guard missing.' );
check( false === $call['arguments']['cache_results'], 'Native persistent query cache may outlive Core publication changes.' );
check( array() === $result['items'] && 0 === $result['total'] && 2 === $result['page'], 'Empty result contract changed.' );
check( ! has_filter( 'get_meta_sql' ) && ! $GLOBALS['wpdb']->suppressed, 'Temporary state not restored.' );
$editorial->tutorials( 'zh_CN', array( 'q' => '-测试%_\'教程', 'sort' => 'title', 'page' => 2, 'per_page' => 12 ) );
check( 1 === count( WP_Query::$calls ), 'Identical request repeated query.' );
$editorial->tutorials( 'en_US', array( 'q' => 'the' ) );
check( 2 === count( WP_Query::$calls ) && 'en_US' === WP_Query::$calls[1]['arguments']['meta_value'], 'Locale cache leaked across languages.' );
foreach ( array( array( 'q' => str_repeat( '中', 101 ) ), array( 'q' => "bad\0query" ), array( 'sort' => 'RAND()' ), array( 'page' => 0 ), array( 'per_page' => 25 ), array( 'category' => "bad' OR 1=1" ) ) as $invalid ) {
	try { $editorial->tutorials( 'en_US', $invalid ); check( false, 'Invalid search accepted.' ); } catch ( InvalidArgumentException $expected ) { check( true, 'Invalid input rejected.' ); }
}
$editorial->sitemapCount( 'en_US' );
$call = end( WP_Query::$calls );
check( str_contains( $call['sql']['where'], "aish_noindex.meta_key='_aish_seo_noindex'" ) && str_contains( $call['sql']['where'], "NOT IN ('','0')" ), 'Sitemap permits explicitly noindex posts.' );
check( 'ids' === $call['arguments']['fields'] && 1 === $call['arguments']['posts_per_page'], 'Count unnecessarily loads payloads.' );
$editorial->sitemapItems( 'zh_CN', 2, 200 );
$call = end( WP_Query::$calls );
check( true === $call['arguments']['no_found_rows'] && 200 === $call['arguments']['posts_per_page'], 'Sitemap list must be bounded without duplicate count.' );
WP_Query::$fail = true;
try { $editorial->tutorials( 'en_US', array( 'q' => 'storage-failure' ) ); check( false, 'Storage failure ignored.' ); }
catch ( RuntimeException $error ) { check( 'Editorial storage is unavailable.' === $error->getMessage(), 'Database details leaked.' ); }
check( ! has_filter( 'get_meta_sql' ) && ! $GLOBALS['wpdb']->suppressed, 'Failure did not restore temporary state.' );
WP_Query::$fail = false;
foreach ( array( 10 => 'zh_CN', 11 => 'en_US' ) as $id => $locale ) { $GLOBALS['posts'][ $id ] = new WP_Post( $id ); $GLOBALS['postmeta'][ $id ]['_aish_locale'] = $locale; }
$GLOBALS['postmeta'][10]['_aish_translation_id'] = 11;
$GLOBALS['postmeta'][11]['_aish_translation_id'] = 10;
check( array( 'zh_CN' => 10, 'en_US' => 11 ) === $editorial->tutorialAlternatives( 10 ), 'Real reciprocal translation pair missing.' );
check( null === $editorial->tutorial( 10, 'en_US' ), 'Tutorial leaked into wrong locale.' );
foreach ( array( 'draft', 'pending', 'private', 'trash' ) as $status ) {
	$GLOBALS['posts'][11]->post_status = $status; $editorial->clearCache();
	check( null === $editorial->tutorial( 11, 'en_US' ) && array( 'zh_CN' => 10 ) === $editorial->tutorialAlternatives( 10 ), 'Nonpublic tutorial or alternate leaked.' );
}
$GLOBALS['posts'][11]->post_status = 'publish';
foreach ( array( '<p>&nbsp;</p>', '<p>&#x2000;</p>', '<script>untrusted()</script>', '<style>body{color:red}</style>', " \n\t", '' ) as $empty ) {
	$GLOBALS['posts'][11]->post_content = $empty; $editorial->clearCache();
	check( null === $editorial->tutorial( 11, 'en_US' ) && array( 'zh_CN' => 10 ) === $editorial->tutorialAlternatives( 10 ), 'Empty translation emitted.' );
}
$GLOBALS['posts'][11]->post_content = '<p>English tutorial</p>';
$GLOBALS['posts'][11]->post_password = 'secret'; $editorial->clearCache();
check( null === $editorial->tutorial( 11, 'en_US' ), 'Password-protected tutorial leaked.' );
$GLOBALS['posts'][11]->post_password = '';
$GLOBALS['postmeta'][10]['_aish_translation_id'] = '';
$GLOBALS['postmeta'][11]['_aish_translation_id'] = '';
add_filter( 'wpml_object_id', static function ( mixed $id, string $type, bool $return_original, string $language ): ?int {
	check( ! $return_original && 'aish_tutorial' === $type, 'WPML API may fabricate original as translation.' );
	return 'en' === $language ? 11 : 10;
}, 10, 4 );
$editorial->clearCache();
check( array( 'zh_CN' => 10, 'en_US' => 11 ) === $editorial->tutorialAlternatives( 10 ), 'Validated WPML public API pair rejected.' );
$GLOBALS['postmeta'][11]['_aish_locale'] = 'zh_CN'; $editorial->clearCache();
check( array( 'zh_CN' => 10 ) === $editorial->tutorialAlternatives( 10 ), 'WPML mapping bypassed explicit content locale.' );
echo 'Editorial search contracts: ' . $GLOBALS['checks'] . " assertions passed (isolated stubs; no WordPress/MySQL/WPML integration).\n";
