<?php
declare(strict_types=1);

/** Isolated SEO projection/hooks. Does not load WPML, Yoast, Rank Math or WordPress. */
namespace AISkillsHub\Core\Developers {
	final class Frontend {
		public static bool $enabled = false;
		public static array $data = array();
		public static function active(): bool { return self::$enabled; }
		public static function context(): array { return self::$data; }
	}
}
namespace AISkillsHub\Core\Frontend {
	final class Router { public static string $locale = 'zh_CN'; public static bool $fail = false; public static function defaultLocale(): string { if ( self::$fail ) { throw new \RuntimeException( 'adapter unavailable' ); } return self::$locale; } }
	final class SeoSettings { public static bool $indexable = true; public static bool $fail = false; public static function indexableSite(): bool { if ( self::$fail ) { throw new \RuntimeException( 'settings unavailable' ); } return self::$indexable; } }
}
namespace {
	define( 'ABSPATH', '/isolated-wordpress/' );
	$GLOBALS['hooks'] = array(); $GLOBALS['removed'] = array();
	function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
	function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_url( string $value ): string { return esc_attr( $value ); }
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted = 1 ): void { $GLOBALS['hooks'][ $hook ][ $priority ][] = $callback; }
	function add_action( string $hook, callable|string $callback, int $priority = 10, int $accepted = 1 ): void { $GLOBALS['hooks'][ $hook ][ $priority ][] = $callback; }
	function remove_action( string $hook, string $callback ): void { $GLOBALS['removed'][] = $hook . ':' . $callback; }
	function remove_all_actions( string $hook ): void { $GLOBALS['removed'][] = $hook; }
	function _wp_render_title_tag(): void {}
	function apply_filters( string $hook, mixed $value ): mixed { $priorities = $GLOBALS['hooks'][ $hook ] ?? array(); ksort( $priorities ); foreach ( $priorities as $callbacks ) { foreach ( $callbacks as $callback ) { $value = $callback( $value ); } } return $value; }
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Developers/Seo.php';
	use AISkillsHub\Core\Developers\Frontend;
	use AISkillsHub\Core\Developers\Seo;
	use AISkillsHub\Core\Frontend\Router;
	use AISkillsHub\Core\Frontend\SeoSettings;
	$assertions = 0;
	function check( bool $condition, string $message ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
	function context( string $locale = 'en_US' ): array {
		$zh = 'zh_CN' === $locale; $url = 'https://example.test/' . ( $zh ? 'zh' : 'en' ) . '/developers/safe-handle/';
		return array( 'public' => true, 'route' => 'public', 'locale' => $locale, 'status' => 200, 'params' => array( 'page' => 1 ), 'canonical' => $url, 'alternatives' => array( $locale => $url ), 'profile' => array( 'display_name' => $zh ? '经核实昵称' : 'Reviewed name', 'bio' => $zh ? '真实中文介绍' : 'Actual reviewed biography', 'seo_title' => '', 'seo_description' => '', 'skills' => array( 'items' => array( array( 'id' => 4 ) ) ), 'candidate' => 'PRIVATE DRAFT MUST NOT APPEAR', 'private_note' => 'PRIVATE MODERATOR NOTE' ) );
	}
	function output( Seo $seo, array $context, array $query = array() ): string { Frontend::$data = $context; $_GET = $query; $seo->prepare(); ob_start(); $seo->head(); return (string) ob_get_clean(); }
	$seo = new Seo(); $seo->register(); Frontend::$data = context(); $_GET = array();
	$seo->prepare(); ob_start(); $seo->head(); $html = ob_get_clean();
	check( '' === $html && ! $GLOBALS['removed'], 'inactive module leaves metadata/head ownership unchanged' );
	check( apply_filters( 'pre_get_document_title', 'Original title' ) === 'Original title', 'unrelated route title untouched' );
	check( apply_filters( 'wpseo_frontend_presenters', array( 'YoastPresenter' ) ) === array( 'YoastPresenter' ), 'unrelated route retains SEO plugin presenters' );
	Frontend::$enabled = true; $private = context(); $private['public'] = false; $html = output( $seo, $private );
	check( '' === $html && apply_filters( 'pre_get_document_title', 'Account title' ) === 'Account title', 'public SEO never derives metadata from private account form' );
	$english = context(); $html = output( $seo, $english );
	check( substr_count( $html, 'rel="canonical"' ) === 1 && str_contains( $html, 'href="https://example.test/en/developers/safe-handle/"' ), 'exactly one English self canonical emitted' );
	check( str_contains( $html, 'name="description" content="Actual reviewed biography"' ), 'reviewed biography provides description fallback' );
	check( apply_filters( 'pre_get_document_title', '' ) === 'Reviewed name · AI Skills Hub', 'reviewed name provides unique title' );
	check( str_contains( $html, 'hreflang="en"' ) && ! str_contains( $html, 'hreflang="zh-CN"' ), 'missing Chinese translation cannot create a fake alternate' );
	check( str_contains( $html, 'hreflang="x-default" href="https://example.test/en/developers/safe-handle/"' ), 'missing default-language translation falls back to actual available language' );
	check( str_contains( $html, 'property="og:type" content="profile"' ) && str_contains( $html, 'name="twitter:card" content="summary"' ), 'public reviewed profile has Open Graph and Twitter metadata' );
	check( ! str_contains( $html, 'PRIVATE' ), 'candidate and internal note are not metadata inputs' );
	check( apply_filters( 'wpseo_frontend_presenters', array( 'Canonical', 'Description', 'OpenGraph' ) ) === array(), 'developer route disables duplicate Yoast presenters' );
	check( in_array( 'rank_math/head', $GLOBALS['removed'], true ) && in_array( 'wp_head:rel_canonical', $GLOBALS['removed'], true ) && in_array( 'wp_head:wp_shortlink_wp_head', $GLOBALS['removed'], true ), 'Rank Math/native canonical/shortlink ownership suppressed on dedicated public route' );
	check( apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) ) === array( 'max-image-preview' => 'large' ), 'valid indexable profile preserves platform robots policy' );
	$chinese = context( 'zh_CN' ); $chinese['alternatives']['en_US'] = $english['canonical']; $chinese['alternatives']['fr_FR'] = 'https://example.test/fr/fabricated/';
	$html = output( $seo, $chinese ); check( str_contains( $html, 'rel="canonical" href="https://example.test/zh/developers/safe-handle/"' ), 'Chinese canonical remains Chinese' );
	check( str_contains( $html, 'hreflang="zh-CN"' ) && str_contains( $html, 'hreflang="en"' ) && ! str_contains( $html, 'fabricated' ), 'only approved supported-language alternates are emitted' );
	check( str_contains( $html, 'hreflang="x-default" href="https://example.test/zh/developers/safe-handle/"' ), 'configured available default locale supplies x-default' );
	check( apply_filters( 'pre_get_document_title', '' ) === '经核实昵称 · AI Skills Hub' && str_contains( $html, 'content="真实中文介绍"' ), 'Chinese title and description use actual Chinese text' );
	$custom = context(); $custom['profile']['seo_title'] = 'Custom <b>reviewed</b> "title"'; $custom['profile']['seo_description'] = 'Description <img src=x onerror=alert(1)> "quoted"';
	$html = output( $seo, $custom ); check( apply_filters( 'pre_get_document_title', '' ) === 'Custom reviewed "title" · AI Skills Hub', 'reviewed explicit SEO title overrides display name and strips tags' );
	check( ! str_contains( $html, '<img' ) && str_contains( $html, '&quot;quoted&quot;' ) && str_contains( $html, '&quot;title&quot;' ), 'metadata values escape quotes and strip untrusted markup' );
	$missing = context(); $missing['status'] = 404; $missing['profile'] = array(); $missing['canonical'] = ''; $html = output( $seo, $missing );
	check( '' === $html && apply_filters( 'pre_get_document_title', '' ) === 'Developer profile unavailable · AI Skills Hub', 'missing translation/profile has unavailable title without canonical or stale metadata' );
	check( apply_filters( 'wp_robots', array() ) === array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true ), 'missing profile fails closed for indexing' );
	foreach ( array( 403, 405, 503 ) as $status ) { $invalid = context(); $invalid['status'] = $status; $html = output( $seo, $invalid ); check( '' === $html, 'non-success status cannot emit approved metadata: ' . $status ); }
	$avatar = context(); $avatar['route'] = 'avatar'; check( '' === output( $seo, $avatar ), 'image route never receives HTML profile metadata' );
	SeoSettings::$indexable = false; $html = output( $seo, $english ); check( str_contains( $html, 'rel="canonical"' ) && ! str_contains( $html, 'hreflang=' ) && apply_filters( 'wp_robots', array() )['noindex'], 'site indexing disabled suppresses alternates without crossing canonical language' );
	SeoSettings::$indexable = true; SeoSettings::$fail = true; output( $seo, $english ); check( apply_filters( 'wp_robots', array() )['noindex'], 'settings failure defaults to noindex' ); SeoSettings::$fail = false;
	Router::$fail = true; $html = output( $seo, $english ); check( str_contains( $html, 'hreflang="x-default" href="' . $english['canonical'] . '"' ), 'adapter default-locale failure falls back to current genuine translation' ); Router::$fail = false;
	$html = output( $seo, $english, array( 'sort' => 'anything', 'token' => 'secret-never-canonical' ) ); check( ! str_contains( $html, 'secret-never-canonical' ) && ! str_contains( $html, 'hreflang=' ) && apply_filters( 'wp_robots', array() )['noindex'], 'unrecognized/sensitive URL parameters do not enter metadata or indexed alternates' );
	$page = context(); $page['params']['page'] = 2; $page['canonical'] .= '?page=2'; $html = output( $seo, $page, array( 'page' => '2' ) );
	check( str_contains( $html, 'rel="canonical" href="' . $page['canonical'] . '"' ) && ! str_contains( $html, 'hreflang=' ), 'pagination stays self canonical without invalid language-page equivalence' );
	check( apply_filters( 'pre_get_document_title', '' ) === 'Reviewed name · Page 2 · AI Skills Hub', 'pagination title identifies actual page' );
	$page['profile']['skills']['items'] = array(); output( $seo, $page, array( 'page' => '2' ) ); check( apply_filters( 'wp_robots', array() )['noindex'], 'empty overflow page is noindex' );
	$diagnostics = 0; set_error_handler( static function ( int $severity, string $message ) use ( &$diagnostics ): never { ++$diagnostics; throw new RuntimeException( 'Unexpected PHP diagnostic' ); } );
	$optional = context(); unset( $optional['profile']['seo_title'], $optional['profile']['seo_description'] ); $html = output( $seo, $optional );
	check( 0 === $diagnostics && str_contains( $html, 'Actual reviewed biography' ), 'omitted optional SEO fields fall back without PHP warnings' ); restore_error_handler();
	echo "Developer SEO: {$assertions} isolated assertions passed (WP/router/settings/plugin-hook substitutes; no real third-party SEO/WPML integration).\n";
}
