<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;

use AISkillsHub\Core\Database\{Database, Migrator};
use AISkillsHub\Core\Frontend\{Editorial, Router, View};
use AISkillsHub\Core\Submissions\Store;

defined( 'ABSPATH' ) || exit;

/** Learning is an editorial view; native tutorials remain the only content store. */
final class Help {
	public const CAPABILITY = 'aish_manage_skills';
	public function __construct( private Database $db, private Migrator $migrator, private Editorial $editorial ) {}
	public static function categories( string $locale ): array {
		self::locale( $locale );
		return array_combine( Editorial::HELP_CATEGORIES, 'zh_CN' === $locale ? array( '入门', '平台指南', '创建 Skills', '参与社区', '来源与安全', '社区规范' ) : array( 'Getting started', 'Platform guides', 'Creating Skills', 'Community', 'Sources and safety', 'Guidelines' ) );
	}
	private static function locale( string $locale ): void { if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_locale' ); } }
	public static function url( string $locale, array $args = array() ): string { self::locale( $locale ); return View::url( 'help', $args, $locale ); }
	public function listing( string $locale, array $filters = array() ): array {
		self::locale( $locale );
		$allowed = array( 'q', 'help_category', 'page', 'per_page' );
		if ( array_diff( array_keys( $filters ), $allowed ) ) { throw new \InvalidArgumentException( 'invalid_help_filter' ); }
		$query = array( 'q' => $filters['q'] ?? '', 'help_category' => $filters['help_category'] ?? '', 'page' => $filters['page'] ?? 1, 'per_page' => $filters['per_page'] ?? 12, 'help_only' => true, 'sort' => 'title' );
		$result = $this->editorial->tutorials( $locale, $query );
		$result['filters'] = array( 'q' => trim( (string) $query['q'] ), 'help_category' => $query['help_category'], 'page' => $result['page'] );
		$result['categories'] = self::categories( $locale );
		$result['topics'] = $this->topicLinks( $locale );
		return $result;
	}
	public function topicLinks( string $locale ): array {
		self::locale( $locale ); $cards = $this->editorial->helpTopicCards( $locale, array_keys( HelpContent::articles() ) );
		$links = array(); foreach ( $cards as $topic => $card ) { $links[ $topic ] = array( 'id' => $card['id'], 'title' => $card['title'], 'url' => View::tutorialUrl( $card, $locale ) ); } return $links;
	}
	public function topicUrl( string $topic, string $locale ): string { return $this->topicLinks( $locale )[ $topic ]['url'] ?? self::url( $locale ); }
	/** Plain heading text, safe generated anchors, and no script/shortcode execution. */
	public static function navigation( string $content ): array {
		$html = View::rich( $content ); $toc = array();
		if ( ! class_exists( '\DOMDocument' ) ) { return array( 'content' => $html, 'toc' => $toc ); }
		$dom = new \DOMDocument(); $old = libxml_use_internal_errors( true );
		try {
			// A parser-owned body keeps trailing text when older editorial HTML has an
			// unmatched closing div. A synthetic div could be closed by that content.
			$dom->loadHTML( '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
			$root = $dom->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $root ) { return array( 'content' => $html, 'toc' => $toc ); }
			$xpath = new \DOMXPath( $dom ); $nodes = $xpath->query( './/h2 | .//h3', $root );
			$used = array(); foreach ( $xpath->query( './/*[@id]', $root ) as $element ) { $key = $element->getAttribute( 'id' ); $used[ $key ] = ( $used[ $key ] ?? 0 ) + 1; }
			$sequence = 0;
			foreach ( $nodes as $node ) {
				if ( count( $toc ) >= 24 ) { break; }
				$title = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) ?? '' ); if ( '' === $title ) { continue; }
				// Retain existing editorial deep links; newly generated anchors must not
				// collide with another heading or a non-heading element in this article.
				$id = $node->getAttribute( 'id' );
				if ( 1 !== ( $used[ $id ] ?? 0 ) || ! preg_match( '/^[^\x00-\x20\x7f]{1,128}$/uD', $id ) ) {
					do { $id = 'help-section-' . ++$sequence; } while ( isset( $used[ $id ] ) );
					$used[ $id ] = 1; $node->setAttribute( 'id', $id );
				}
				$toc[] = array( 'id' => $id, 'title' => mb_substr( $title, 0, 160 ), 'level' => strtolower( $node->nodeName ) );
			}
			$html = ''; foreach ( $root->childNodes as $child ) { $html .= $dom->saveHTML( $child ); }
			return array( 'content' => wp_kses_post( $html ), 'toc' => $toc );
		} finally { libxml_clear_errors(); libxml_use_internal_errors( $old ); }
	}
	public function reading( array $tutorial, string $locale ): array {
		self::locale( $locale ); $id = (int) ( $tutorial['id'] ?? 0 );
		// Re-read through the existing public gate; caller cannot turn a private draft
		// payload into a public reading view or change the requested language.
		$public = $id > 0 ? $this->editorial->tutorial( $id, $locale ) : null;
		if ( ! $public ) { return array( 'content' => '', 'toc' => array(), 'related' => array(), 'category' => '' ); }
		$category = get_post_meta( $id, '_aish_help_category', true );
		$category = is_string( $category ) && in_array( $category, Editorial::HELP_CATEGORIES, true ) ? $category : '';
		$query = array( 'per_page' => 5 ); if ( '' !== $category ) { $query['help_category'] = $category; }
		$related = array_values( array_filter( $this->editorial->tutorials( $locale, $query )['items'], static fn( array $item ): bool => $item['id'] !== $id ) );
		return self::navigation( (string) $public['content'] ) + array( 'related' => array_slice( $related, 0, 4 ), 'category' => $category );
	}
	/** Explicit import only. Drafts are real editorial copy, never seeded public pages. */
	public function importDrafts(): array {
		if ( ! current_user_can( self::CAPABILITY ) ) { throw new \DomainException( 'forbidden' ); }
		if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$store = new Store( $this->db );
		try {
			return $store->transaction( function ( Store $store ): array {
				$created = array(); $existing = array(); $c = $this->db->connection();
				foreach ( HelpContent::articles() as $topic => $article ) {
					$newPair = array();
					foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
						$key = 'p14-1:' . $topic . ':' . $locale;
						$matches = $this->db->rows( "SELECT DISTINCT p.ID FROM $c->posts p INNER JOIN $c->postmeta m ON m.post_id=p.ID WHERE p.post_type=%s AND BINARY m.meta_key='_aish_help_seed' AND BINARY m.meta_value=BINARY %s ORDER BY p.ID LIMIT 2", array( Editorial::TUTORIAL, $key ) );
						if ( count( $matches ) > 1 ) { throw new \RuntimeException( 'duplicate_help_seed' ); }
						if ( $matches ) { $existing[] = (int) $matches[0]['ID']; continue; }
						$copy = $article[ $locale ];
						$id = $store->post( array( 'post_type' => Editorial::TUTORIAL, 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_title' => $copy['title'], 'post_content' => HelpContent::body( $topic, $locale ), 'post_excerpt' => $copy['summary'], 'post_name' => 'help-' . $topic . '-' . ( 'zh_CN' === $locale ? 'zh' : 'en' ), 'comment_status' => 'closed', 'ping_status' => 'closed' ) );
						foreach ( array( '_aish_locale' => $locale, '_aish_help_category' => $article['category'], '_aish_help_topic' => $topic, '_aish_help_seed' => $key, '_aish_seo_title' => $copy['title'], '_aish_seo_description' => $copy['summary'] ) as $name => $value ) { $this->setMeta( $id, $name, $value ); }
						$newPair[ $locale ] = $id; $created[] = $id;
					}
					// A pre-existing or edited translation link is never repaired implicitly.
					if ( 2 === count( $newPair ) ) { $this->setMeta( $newPair['zh_CN'], '_aish_translation_id', (string) $newPair['en_US'] ); $this->setMeta( $newPair['en_US'], '_aish_translation_id', (string) $newPair['zh_CN'] ); }
				}
				return array( 'created' => $created, 'existing' => $existing );
			} );
		} finally { $this->editorial->clearCache(); }
	}
	private function setMeta( int $id, string $key, string $value ): void {
		$c = $this->db->connection(); $c->last_error = ''; update_post_meta( $id, $key, wp_slash( $value ) );
		if ( '' !== $c->last_error ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$rows = $this->db->rows( "SELECT meta_value FROM $c->postmeta WHERE post_id=%d AND meta_key=%s", array( $id, $key ) );
		if ( 1 !== count( $rows ) || $rows[0]['meta_value'] !== $value ) { throw new \RuntimeException( 'storage_unavailable' ); }
	}
	public function inventory(): array {
		if ( ! current_user_can( self::CAPABILITY ) ) { throw new \DomainException( 'forbidden' ); }
		$c = $this->db->connection();
		return $this->db->rows( "SELECT p.ID,p.post_title,p.post_status,m.meta_value AS seed FROM $c->posts p INNER JOIN $c->postmeta m ON m.post_id=p.ID WHERE p.post_type=%s AND m.meta_key='_aish_help_seed' AND m.meta_value LIKE %s ORDER BY p.ID LIMIT 100", array( Editorial::TUTORIAL, $c->esc_like( 'p14-1:' ) . '%' ) );
	}
}
