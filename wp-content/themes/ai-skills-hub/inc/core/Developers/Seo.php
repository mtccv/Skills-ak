<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Developers;

use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\SeoSettings;

defined( 'ABSPATH' ) || exit;

/** Metadata derives solely from the reviewed public projection, never usermeta candidates. */
final class Seo {
	private array $data = array();
	private static function active(): bool { return Frontend::active() && ! empty( Frontend::context()['public'] ); }
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'prepare' ), -8 );
		add_filter( 'pre_get_document_title', fn( string $title ): string => self::active() ? ( $this->data['title'] ?? 'AI Skills Hub' ) : $title, PHP_INT_MAX );
		add_filter( 'wp_robots', fn( array $robots ): array => self::active() && ( $this->data['noindex'] ?? true ) ? array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true ) : $robots, PHP_INT_MAX );
		add_filter( 'wpseo_frontend_presenters', static fn( array $items ): array => self::active() ? array() : $items, PHP_INT_MAX );
		add_action( 'wp_head', array( $this, 'head' ), 2 );
	}
	public function prepare(): void {
		if ( ! self::active() ) { return; }
		$c = Frontend::context(); $profile = $c['profile']; $locale = $c['locale']; $page = (int) ( $c['params']['page'] ?? 1 );
		$valid = 200 === $c['status'] && ! empty( $profile ) && 'public' === $c['route'];
		$title = $valid ? ( ( $profile['seo_title'] ?? '' ) ?: $profile['display_name'] ) : ( 'zh_CN' === $locale ? '开发者资料不可用' : 'Developer profile unavailable' );
		$description = $valid ? ( ( $profile['seo_description'] ?? '' ) ?: $profile['bio'] ) : '';
		$noindex = true;
		try { $noindex = ! $valid || ! SeoSettings::indexableSite(); } catch ( \Throwable $error ) { $noindex = true; }
		foreach ( array_keys( $_GET ) as $key ) { if ( 'page' !== $key ) { $noindex = true; } }
		if ( $page > 1 && empty( $profile['skills']['items'] ) ) { $noindex = true; }
		$canonical = $valid ? $c['canonical'] : ''; $alternates = array();
		if ( ! $noindex && 1 === $page ) {
			// Use only routes for already approved translations. A missing locale never gets invented.
			foreach ( $c['alternatives'] as $language => $url ) { if ( in_array( $language, array( 'zh_CN', 'en_US' ), true ) ) { $alternates[ $language ] = $url; } }
			$alternates[ $locale ] = $canonical;
		}
		try { $default = Router::defaultLocale(); } catch ( \Throwable $error ) { $default = $locale; }
		$this->data = array( 'title' => wp_strip_all_tags( $title ) . ( $page > 1 ? ( 'zh_CN' === $locale ? ' · 第 ' . $page . ' 页' : ' · Page ' . $page ) : '' ) . ' · AI Skills Hub', 'description' => wp_strip_all_tags( $description ), 'canonical' => $canonical, 'locale' => $locale, 'alternates' => $alternates, 'default' => $alternates[ $default ] ?? ( $alternates ? reset( $alternates ) : '' ), 'noindex' => $noindex, 'valid' => $valid );
		add_action( 'wp_head', '_wp_render_title_tag', 1 ); remove_action( 'wp_head', 'rel_canonical' ); remove_action( 'wp_head', 'wp_shortlink_wp_head' ); remove_all_actions( 'rank_math/head' );
		if ( $noindex && ! headers_sent() ) { header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); }
	}
	public function head(): void {
		if ( ! self::active() || ! $this->data ) { return; } $d = $this->data;
		if ( $d['description'] ) { echo '<meta name="description" content="' . esc_attr( $d['description'] ) . '">' . "\n"; }
		if ( $d['canonical'] ) { echo '<link rel="canonical" href="' . esc_url( $d['canonical'] ) . '">' . "\n"; }
		foreach ( $d['alternates'] as $locale => $url ) { echo '<link rel="alternate" hreflang="' . ( 'zh_CN' === $locale ? 'zh-CN' : 'en' ) . '" href="' . esc_url( $url ) . '">' . "\n"; }
		if ( $d['default'] ) { echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $d['default'] ) . '">' . "\n"; }
		if ( ! $d['valid'] ) { return; }
		foreach ( array( 'og:title' => $d['title'], 'og:description' => $d['description'], 'og:url' => $d['canonical'], 'og:locale' => $d['locale'], 'og:type' => 'profile', 'og:site_name' => 'AI Skills Hub' ) as $key => $value ) { if ( '' !== $value ) { echo '<meta property="' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '">' . "\n"; } }
		foreach ( array( 'card' => 'summary', 'title' => $d['title'], 'description' => $d['description'] ) as $key => $value ) { if ( '' !== $value ) { echo '<meta name="twitter:' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '">' . "\n"; } }
	}
}
