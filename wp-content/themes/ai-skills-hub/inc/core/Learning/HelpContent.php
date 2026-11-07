<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;

/** Original bilingual starter articles, imported as native drafts only after confirmation. */
final class HelpContent {
	public static function articles(): array {
		static $articles;
		if ( null === $articles ) {
			$value = file_get_contents( __DIR__ . '/help-content.json' );
			if ( false === $value || strlen( $value ) > 180000 ) { throw new \RuntimeException( 'help_library_unavailable' ); }
			$articles = json_decode( $value, true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $articles ) || 21 !== count( $articles ) ) { throw new \RuntimeException( 'help_library_unavailable' ); }
		}
		return $articles;
	}
	public static function body( string $topic, string $locale ): string {
		$article = self::articles()[ $topic ] ?? null;
		if ( ! $article || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_help_topic' ); }
		$html = '';
		foreach ( $article[ $locale ]['sections'] as [ $heading, $text ] ) { $html .= '<h2>' . esc_html( $heading ) . '</h2><p>' . esc_html( $text ) . '</p>'; }
		if ( $article['links'] ) {
			$html .= '<h2>' . esc_html( 'zh_CN' === $locale ? '继续阅读与操作入口' : 'Further reading and next steps' ) . '</h2><ul>';
			$labels = array( 'skills' => array( '浏览 Skills', 'Browse Skills' ), 'search' => array( '搜索资源', 'Search resources' ), 'submit' => array( '提交 Skill', 'Submit a Skill' ), 'contact' => array( '联系与帮助', 'Contact and help' ) );
			foreach ( $article['links'] as $link ) {
				if ( str_starts_with( $link, 'route:' ) ) { $route = substr( $link, 6 ); if ( ! isset( $labels[ $route ] ) ) { continue; } $url = View::url( $route, array(), $locale ); $label = $labels[ $route ][ 'zh_CN' === $locale ? 0 : 1 ]; }
				else { $url = $link; $label = $link; }
				$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		return wp_kses_post( $html );
	}
}
