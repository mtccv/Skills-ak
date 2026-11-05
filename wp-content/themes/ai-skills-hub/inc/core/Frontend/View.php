<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

defined( 'ABSPATH' ) || exit;

/** Presentation helpers. Returned content is escaped by templates, except explicit HTML helpers. */
final class View {
	public static function t( string $key ): string { return Strings::get( $key, Router::context()['locale'] ); }
	public static function url( string $route, array $args = array(), ?string $locale = null ): string {
		try { return Router::url( $route, $args, $locale ); }
		// Router disables the failing adapter before throwing; the retry uses its built-in URL.
		catch ( \RuntimeException $error ) { return Router::url( $route, $args, $locale ); }
	}
	public static function skillUrl( array $skill, ?string $locale = null ): string {
		$locale ??= Router::context()['locale'];
		$slug = $skill['available_translations'][ $locale ] ?? $skill['slug'] ?? '';
		return self::url( 'skill', array( 'id' => $skill['id'], 'slug' => $slug ), $locale );
	}
	public static function tutorialUrl( array $tutorial, ?string $locale = null ): string { return self::url( 'tutorial', array( 'id' => $tutorial['id'] ), $locale ); }
	public static function switchUrl( string $locale ): string {
		$context = Router::context();
		if ( isset( $context['alternates'][ $locale ] ) ) { return $context['alternates'][ $locale ]; }
		if ( in_array( $context['route'], array( 'skills', 'category', 'tag', 'tutorials', 'search' ), true ) ) {
			$args = $context['data']['filters'] ?? array();
			$args['page'] = 1;
			if ( isset( $context['data']['term']['slug'] ) ) { $args['slug'] = $context['data']['term']['slug']; }
			return self::url( $context['route'], $args, $locale );
		}
		$route = match ( $context['route'] ) { 'skill' => 'skills', 'tutorial' => 'tutorials', default => 'home' };
		return self::url( $route, array( 'translation' => 'missing' ), $locale );
	}
	public static function date( ?string $value ): string {
		if ( ! $value ) { return '—'; }
		$time = strtotime( $value . ' UTC' );
		return false === $time ? '—' : gmdate( 'Y-m-d', $time );
	}

	public static function rich( string $content ): string {
		$html = wpautop( wp_kses_post( $content ) );
		$html = preg_replace( array( '/<h1\b([^>]*)>/i', '/<\/h1\s*>/i' ), array( '<h2$1>', '</h2>' ), $html );
		return wp_kses_post( $html ?? '' );
	}

	/** Trusted original line icons, with no caller-provided markup or paths. */
	public static function icon( string $name ): string {
		$paths = array(
			'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
			'arrow-right' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
			'arrow-left' => '<path d="M20 12H4m6-6-6 6 6 6"/>',
			'chevron-right' => '<path d="m9 5 7 7-7 7"/>',
			'sparkles' => '<path d="m12 3 2.8 6.2L21 12l-6.2 2.8L12 21l-2.8-6.2L3 12l6.2-2.8L12 3Z"/>',
			'grid' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
			'layers' => '<path d="m12 3 10 5-10 5L2 8l10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5"/>',
			'book' => '<path d="M12 6v15M3 3h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5v15h-5a4 4 0 0 0-4 3 4 4 0 0 0-4-3H3V3Z"/>',
			'code' => '<path d="m7 5-6 7 6 7m10-14 6 7-6 7M14 3l-4 18"/>',
			'github' => '<path d="M9 20c-5 1.5-5-2.5-7-3m14 5v-4a3.5 3.5 0 0 0-1-2.5c3.3-.4 6.7-1.6 6.7-7.4A5.8 5.8 0 0 0 20 4a5.4 5.4 0 0 0-.1-4s-1.3-.4-4.2 1.6a14 14 0 0 0-7.4 0C5.4-.4 4.1 0 4.1 0A5.4 5.4 0 0 0 4 4a5.8 5.8 0 0 0-1.7 4.1c0 5.8 3.4 7 6.7 7.4A3.5 3.5 0 0 0 8 18v4" transform="translate(1 1) scale(.9)"/>',
			'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9S4 17 4 12V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
			'globe' => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
			'plus' => '<path d="M12 5v14M5 12h14"/>',
			'external' => '<path d="M14 3h7v7m0-7L10 14M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5"/>',
			'check' => '<path d="m5 12 4 4L19 6"/>',
			'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
			'close' => '<path d="m5 5 14 14M19 5 5 19"/>',
			'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm0 0v6h6M8 13h8M8 17h6"/>',
			'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
		);
		return '<svg class="aish-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? $paths['grid'] ) . '</svg>';
	}

	public static function pagination( int $total, int $page, int $perPage, array $params = array() ): string {
		$pages = (int) ceil( $total / max( 1, $perPage ) );
		if ( $pages < 2 ) { return ''; }
		$context = Router::context();
		$params += $context['data']['filters'] ?? array();
		if ( isset( $context['data']['term']['slug'] ) ) { $params['slug'] = $context['data']['term']['slug']; }
		$zh = 'zh_CN' === $context['locale'];
		$html = '<nav class="aish-pagination" aria-label="' . esc_attr( $zh ? '分页导航' : 'Pagination' ) . '">';
		$makeLink = static fn( int $number, string $label, string $rel = '' ): string => '<a class="aish-page-link"' . ( $rel ? ' rel="' . esc_attr( $rel ) . '"' : '' ) . ' href="' . esc_url( self::url( $context['route'], array_merge( $params, array( 'page' => $number ) ) ) ) . '">' . esc_html( $label ) . '</a>';
		if ( $page > 1 ) { $html .= $makeLink( $page - 1, $zh ? '上一页' : 'Previous', 'prev' ); }
		$numbers = array_unique( array_merge( array( 1, $pages ), range( max( 1, $page - 2 ), min( $pages, $page + 2 ) ) ) );
		sort( $numbers );
		$previous = 0;
		foreach ( $numbers as $number ) {
			if ( $number > $previous + 1 ) { $html .= '<span aria-hidden="true">…</span>'; }
			$html .= $number === $page ? '<span class="aish-page-link is-current" aria-current="page">' . $number . '</span>' : $makeLink( $number, (string) $number );
			$previous = $number;
		}
		if ( $page < $pages ) { $html .= $makeLink( $page + 1, $zh ? '下一页' : 'Next', 'next' ); }
		return $html . '</nav>';
	}

	public static function breadcrumbs(): string {
		$context = Router::context();
		$zh = 'zh_CN' === $context['locale'];
		$items = array( array( $zh ? '首页' : 'Home', self::url( 'home' ) ) );
		if ( in_array( $context['route'], array( 'skill', 'category', 'tag' ), true ) ) { $items[] = array( 'Skills', self::url( 'skills' ) ); }
		if ( 'tutorial' === $context['route'] ) { $items[] = array( $zh ? '教程' : 'Tutorials', self::url( 'tutorials' ) ); }
		$title = $context['data']['skill']['title'] ?? $context['data']['tutorial']['title'] ?? $context['data']['term']['name'] ?? $context['data']['page']['title'] ?? Strings::get( 'page.' . $context['route'] . '.title', $context['locale'] );
		$items[] = array( $title, '' );
		$html = '<nav class="aish-breadcrumbs" aria-label="' . esc_attr( $zh ? '面包屑导航' : 'Breadcrumbs' ) . '"><ol>';
		foreach ( $items as list( $label, $url ) ) {
			$html .= '<li>' . ( $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : '<span aria-current="page">' . esc_html( $label ) . '</span>' ) . '</li>';
		}
		return $html . '</ol></nav>';
	}

	/** Optional structured H2 sections from the existing reviewed translation body; no new storage. */
	public static function sections( string $content ): array {
		$result = array( 'use_cases' => null, 'installation' => null, 'dependencies' => null );
		if ( ! class_exists( '\DOMDocument' ) ) { return $result; }
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( '<?xml encoding="UTF-8"><div id="aish-body">' . wp_kses_post( $content ) . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
			$names = array( '使用场景' => 'use_cases', '适用场景' => 'use_cases', 'use cases' => 'use_cases', '安装说明' => 'installation', '安装' => 'installation', 'installation' => 'installation', 'installation instructions' => 'installation', '依赖要求' => 'dependencies', '依赖' => 'dependencies', 'dependencies' => 'dependencies', 'requirements' => 'dependencies' );
			foreach ( $document->getElementsByTagName( 'h2' ) as $heading ) {
				$key = $names[ strtolower( trim( $heading->textContent ) ) ] ?? null;
				if ( null === $key || null !== $result[ $key ] ) { continue; }
				$html = '';
				for ( $node = $heading->nextSibling; $node; $node = $node->nextSibling ) {
					if ( $node instanceof \DOMElement && in_array( strtolower( $node->tagName ), array( 'h1', 'h2' ), true ) ) { break; }
					$html .= $document->saveHTML( $node );
				}
				$result[ $key ] = '' !== trim( $html ) ? wp_kses_post( $html ) : null;
			}
		} finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		return $result;
	}
}
