<?php
declare(strict_types=1);

// Isolated contracts only. These WordPress stubs do not run WP, MySQL or SEO plugins.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['test_options'] = array( 'blog_public' => '1' );
$GLOBALS['test_meta'] = array();
$GLOBALS['test_hooks'] = array();
$GLOBALS['removed_actions'] = array();

function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['test_options'][ $name ] ?? $default; }
function get_post_meta( int $id, string $key, bool $single = false ): mixed { return $GLOBALS['test_meta'][ $id ][ $key ] ?? ( $single ? '' : array() ); }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_url( string $value ): string { return esc_attr( $value ); }
function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ); }
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted = 1 ): void { $GLOBALS['test_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted ); }
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted = 1 ): void { add_filter( $hook, $callback, $priority, $accepted ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$priorities = $GLOBALS['test_hooks'][ $hook ] ?? array(); ksort( $priorities );
	foreach ( $priorities as $callbacks ) { foreach ( $callbacks as list( $callback, $accepted ) ) { $value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted ) ); } }
	return $value;
}
function remove_all_actions( string $hook ): void { $GLOBALS['removed_actions'][] = $hook; }
function remove_action( string $hook, string $callback ): void { $GLOBALS['removed_actions'][] = $hook . ':' . $callback; }

$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
require $root . 'Security/Input.php';
require $root . 'Frontend/Router.php';
require $root . 'Frontend/SeoSettings.php';
require $root . 'Frontend/Seo.php';
require $root . 'Frontend/Sitemap.php';
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\Seo;
use AISkillsHub\Core\Frontend\SeoSettings;

$count = 0;
function check( bool $condition, string $message ): void { global $count; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$count; }
function context( string $route = 'home', string $locale = 'en_US', array $data = array(), int $status = 200 ): array {
	return array( 'route' => $route, 'locale' => $locale, 'language' => 'zh_CN' === $locale ? 'zh' : 'en', 'title' => 'Default title', 'description' => 'Default description', 'canonical' => 200 === $status ? Router::url( $route, array(), $locale ) : '', 'alternates' => array(), 'alternate_ids' => array(), 'data' => $data, 'status' => $status );
}
function activeContext( array $context ): void { ( new ReflectionProperty( Router::class, 'context' ) )->setValue( null, $context ); }
function rendered( Seo $seo, array $context ): string { activeContext( $context ); ob_start(); $seo->head(); return (string) ob_get_clean(); }

$GLOBALS['test_options']['aish_seo_settings'] = array(
	'zh_CN:home:0' => array( 'title' => '中文首页', 'description' => '中文描述' ),
	'en_US:home:0' => array( 'title' => 'English home', 'description' => 'English description' ),
	'en_US:skill:17' => array( 'title' => 'Forbidden shadow title', 'description' => 'Forbidden shadow description', 'image' => 'https://example.test/skill.png' ),
	'en_US:category:5' => array( 'noindex' => true ),
	'zh_CN:category:5' => array( 'noindex' => false ),
);
$en = Seo::prepare( context(), array() );
$zh = Seo::prepare( context( 'home', 'zh_CN' ), array() );
check( 'English home' === $en['title'] && 'English description' === $en['description'], 'English independent SEO values' );
check( '中文首页' === $zh['title'] && '中文描述' === $zh['description'], 'Chinese independent SEO values' );
check( 'https://example.test/en/' === $en['canonical'], 'English self canonical' );
check( 'https://example.test/zh/' === $zh['canonical'], 'Chinese self canonical' );
$skill = context( 'skill', 'en_US', array( 'skill' => array( 'id' => 17 ) ) );
$skill['title'] = 'Reviewed translation SEO'; $skill['description'] = 'Reviewed description';
$skill['canonical'] = Router::url( 'skill', array( 'id' => 17, 'slug' => 'actual-skill' ), 'en_US' );
$skill = Seo::prepare( $skill, array() );
check( 'Reviewed translation SEO' === $skill['title'] && 'Reviewed description' === $skill['description'], 'Reviewed Skill fields remain authoritative' );
check( 'https://example.test/skill.png' === $skill['image'], 'Skill image setting remains available' );
check( 'article' === $skill['og_type'], 'Skill OG type' );
check( array( 5 ) === SeoSettings::excludedIds( 'category', 'en_US' ), 'Term noindex exclusions use current language' );
check( array() === SeoSettings::excludedIds( 'category', 'zh_CN' ), 'Term noindex is not copied to other language' );

$pair = context();
$pair['alternates'] = array( 'zh_CN' => Router::url( 'home', array(), 'zh_CN' ), 'en_US' => Router::url( 'home', array(), 'en_US' ) );
$paired = Seo::prepare( $pair, array() );
check( 2 === count( $paired['seo_alternates'] ), 'Existing bilingual alternates retained' );
check( 'https://example.test/zh/' === $paired['x_default'], 'x-default uses available configured default language' );
$GLOBALS['test_options']['aish_admin_settings'] = array( 'default_locale' => 'en_US' );
check( 'https://example.test/en/' === Seo::prepare( $pair, array() )['x_default'], 'x-default tracks configured default language' );
$GLOBALS['test_options']['aish_admin_settings'] = array( 'default_locale' => 'zh_CN' );
$single = $skill; $single['alternates'] = array( 'en_US' => $skill['canonical'] );
add_filter( 'aish_frontend_alternates', static function ( array $links ): array { $links['zh_CN'] = 'https://example.test/zh/fabricated/'; return $links; } );
$single = Seo::prepare( $single, array() );
check( array( 'en_US' ) === array_keys( $single['seo_alternates'] ), 'Adapter cannot invent a missing translation' );
check( $skill['canonical'] === $single['x_default'], 'Missing default translation falls back to actual public URL' );
unset( $GLOBALS['test_hooks']['aish_frontend_alternates'] );
$GLOBALS['test_options']['aish_seo_settings']['zh_CN:home:0']['noindex'] = true;
$paired = Seo::prepare( $pair, array() );
check( ! isset( $paired['seo_alternates']['zh_CN'] ), 'Noindex counterpart excluded from hreflang' );
check( 'https://example.test/en/' === $paired['x_default'], 'x-default does not point to excluded language' );
unset( $GLOBALS['test_options']['aish_seo_settings']['zh_CN:home:0']['noindex'] );
add_filter( 'aish_frontend_alternates', static function ( array $links ): array { $links['zh_CN'] = 'javascript:alert(1)'; return $links; } );
check( ! isset( Seo::prepare( $pair, array() )['seo_alternates']['zh_CN'] ), 'Unsafe alternate URL rejected' );
unset( $GLOBALS['test_hooks']['aish_frontend_alternates'] );

$GLOBALS['test_meta'][91] = array( '_aish_seo_title' => 'Tutorial SEO', '_aish_seo_description' => 'Tutorial description' );
$GLOBALS['test_meta'][92] = array( '_aish_seo_noindex' => '1' );
$tutorial = context( 'tutorial', 'en_US', array( 'tutorial' => array( 'id' => 91 ) ) );
$tutorial['canonical'] = Router::url( 'tutorial', array( 'id' => 91 ), 'en_US' );
$tutorial['alternates'] = array( 'en_US' => $tutorial['canonical'], 'zh_CN' => Router::url( 'tutorial', array( 'id' => 92 ), 'zh_CN' ) );
$tutorial['alternate_ids'] = array( 'en_US' => 91, 'zh_CN' => 92 );
$tutorial = Seo::prepare( $tutorial, array() );
check( 'Tutorial SEO' === $tutorial['title'] && 'Tutorial description' === $tutorial['description'], 'Native post SEO overrides content fallback' );
check( array( 'en_US' ) === array_keys( $tutorial['seo_alternates'] ), 'Native noindex counterpart excluded' );
check( ! Seo::allows( 'tutorial', 'zh_CN', 92 ), 'Noindex tutorial is ineligible' );
$GLOBALS['test_meta'][93] = array( '_aish_seo_title' => 'Contact SEO', '_aish_seo_noindex' => '1' );
$contact = Seo::prepare( context( 'contact', 'en_US', array( 'page' => array( 'id' => 93 ) ) ), array() );
check( 'Contact SEO' === $contact['title'] && $contact['noindex'], 'Native page SEO and noindex apply to its route' );

foreach ( array( 'search', 'submit' ) as $route ) { check( Seo::prepare( context( $route ), array() )['noindex'], $route . ' excluded from indexing' ); }
foreach ( array( 'about', 'contact' ) as $route ) { check( Seo::prepare( context( $route ), array() )['noindex'], $route . ' without editorial translation excluded' ); }
foreach ( array( 404, 503 ) as $status ) {
	$error = Seo::prepare( context( '404', 'en_US', array(), $status ), array() );
	check( $error['noindex'] && '' === $error['canonical'] && array() === $error['seo_alternates'], 'Error status has no index or canonical language links: ' . $status );
}
$listing = context( 'skills', 'en_US', array( 'filters' => array( 'page' => 1 ) ) );
foreach ( array( array( 'q' => '中文' ), array( 'category' => 'coding', 'tag' => 'cli', 'platform' => 'platform-codex' ), array( 'sort' => 'title' ), array( 'sort' => 'newest' ), array( 'preview' => '1' ), array( 'form' => 'sent' ), array( 'translation' => 'missing' ), array( 'unknown' => array( 'value' ) ) ) as $query ) {
	$result = Seo::prepare( $listing, $query );
	check( $result['noindex'] && array() === $result['seo_alternates'], 'Query variant excluded: ' . json_encode( $query ) );
}
check( ! Seo::prepare( $listing, array() )['noindex'], 'Unfiltered public list remains indexable' );
$listing['data']['filters']['page'] = 2;
$listing['canonical'] = Router::url( 'skills', array( 'page' => 2 ), 'en_US' );
$page = Seo::prepare( $listing, array( 'page' => '2' ) );
check( ! $page['noindex'] && str_ends_with( $page['canonical'], '?page=2' ), 'Valid list pagination uses self canonical' );
check( str_ends_with( $page['title'], 'Page 2' ) && array() === $page['seo_alternates'], 'Pagination has distinct title without guessed cross-language page' );
check( Seo::prepare( context( 'category', 'en_US', array( 'term' => array( 'id' => 6, 'count' => 0 ) ) ), array() )['noindex'], 'Empty public category excluded' );
$GLOBALS['test_options']['blog_public'] = '0';
check( Seo::prepare( context(), array() )['noindex'], 'WordPress site visibility overrides index settings' );
check( ! Seo::allows( 'home', 'en_US' ), 'Non-public site cannot advertise indexable route' );
$GLOBALS['test_options']['blog_public'] = '1';

$seo = new Seo(); $seo->register();
$outputContext = $skill; $outputContext['title'] = 'A "title" & B'; $outputContext['description'] = 'Description " quoted & safe';
$html = rendered( $seo, $outputContext );
check( 1 === substr_count( $html, 'rel="canonical"' ), 'Exactly one theme canonical' );
check( 1 === substr_count( $html, 'name="description"' ), 'Exactly one theme description' );
check( 1 === substr_count( $html, 'property="og:title"' ) && 1 === substr_count( $html, 'name="twitter:title"' ), 'One OG and Twitter title' );
check( str_contains( $html, 'content="A &quot;title&quot; &amp; B"' ), 'Metadata attribute escaping' );
check( str_contains( $html, 'name="twitter:card" content="summary_large_image"' ), 'Configured social image enables large card' );
$outputContext['image'] = '';
$html = rendered( $seo, $outputContext );
check( ! str_contains( $html, 'og:image' ) && ! str_contains( $html, 'twitter:image' ), 'No fabricated social image' );
check( str_contains( $html, 'name="twitter:card" content="summary"' ), 'Image-free card uses summary' );
check( array() === apply_filters( 'wpseo_frontend_presenters', array( 'yoast-presenter' ) ), 'Theme ownership disables Yoast presenters on Hub route' );
check( array() === apply_filters( 'wpml_hreflangs', array( 'unverified' => 'url' ) ), 'Theme ownership prevents duplicate WPML hreflang' );
check( $outputContext['title'] === apply_filters( 'pre_get_document_title', 'plugin-title' ), 'Document title follows route SEO' );
Seo::sendHeaders( $outputContext );
check( in_array( 'rank_math/head', $GLOBALS['removed_actions'], true ), 'Theme ownership disables Rank Math render action on Hub route' );
$excluded = Seo::prepare( context( 'search' ), array( 'q' => 'anything' ) ); activeContext( $excluded );
$robots = $seo->robots( array( 'index' => true, 'nofollow' => true ) );
check( ! isset( $robots['index'] ) && ! isset( $robots['nofollow'] ) && ! empty( $robots['noindex'] ) && ! empty( $robots['follow'] ), 'Noindex directive supersedes conflicting index flags' );
$error = Seo::prepare( context( '404', 'en_US', array(), 404 ), array() );
check( ! str_contains( rendered( $seo, $error ), 'og:' ), '404 does not advertise article metadata' );
$unmanaged = $outputContext; $unmanaged['seo_managed'] = false; activeContext( $unmanaged );
check( array( 'yoast-presenter' ) === apply_filters( 'wpseo_frontend_presenters', array( 'yoast-presenter' ) ), 'Other routes retain Yoast metadata' );
check( array( 'en' => 'url' ) === apply_filters( 'wpml_hreflangs', array( 'en' => 'url' ) ), 'Other routes retain WPML metadata' );
check( 'plugin-title' === apply_filters( 'pre_get_document_title', 'plugin-title' ), 'Other routes retain plugin title' );
check( '' === rendered( $seo, $unmanaged ), 'Disabled owner emits no metadata' );
$GLOBALS['removed_actions'] = array(); Seo::sendHeaders( $unmanaged );
check( array() === $GLOBALS['removed_actions'], 'Other routes retain Rank Math actions' );
$robotsText = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nSitemap: https://example.test/sitemap_index.xml\n";
check( $robotsText === $seo->robotsTxt( $robotsText, '1' ), 'robots.txt preserves existing sitemap and admin directives' );
check( ! str_contains( $seo->robotsTxt( $robotsText, '1' ), '?q=' ), 'robots.txt does not hide noindex URLs from crawlers' );
check( "User-agent: *\nDisallow: /\n" === $seo->robotsTxt( $robotsText, '0' ), 'Private site robots.txt disallows all and advertises no sitemap' );
$withoutSitemap = "User-agent: *\nDisallow: /wp-admin/\n";
check( str_contains( $seo->robotsTxt( $withoutSitemap, '1' ), 'Sitemap: https://example.test/aish-sitemap.xml' ), 'Standalone sitemap advertised when there is no existing owner' );
add_filter( 'aish_sitemaps_enabled', static fn( bool $enabled ): bool => false );
check( $withoutSitemap === $seo->robotsTxt( $withoutSitemap, '1' ), 'Disabled sitemap is not advertised in robots.txt' );
unset( $GLOBALS['test_hooks']['aish_sitemaps_enabled'] );

echo 'PASS: ' . $count . " isolated SEO/index-policy assertions (PHP stubs; no WordPress/MySQL/plugin integration).\n";
