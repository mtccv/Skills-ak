<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

use AISkillsHub\Core\Security\Input;
use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Editorial content uses native WordPress posts; Skills remain exclusively in Core tables. */
final class Editorial {
	public const TUTORIAL = 'aish_tutorial';
	private const LOCALES = array( 'zh_CN', 'en_US' );
	private const PAGE_KINDS = array( 'about', 'contact', 'submit' );
	private array $cache = array();

	public function __construct( private ?PublicCatalog $catalog = null, private ?Database $writeDatabase = null ) {}

	public function clearCache(): void { $this->cache = array(); }

	private function remember( string $key, callable $read ): mixed {
		if ( ! array_key_exists( $key, $this->cache ) ) { $this->cache[ $key ] = $read(); }
		return $this->cache[ $key ];
	}

	public function register(): void {
		add_action( 'init', array( $this, 'registerType' ) );
		add_action( 'add_meta_boxes', array( $this, 'metaBoxes' ) );
		add_action( 'save_post', array( $this, 'saveMeta' ), 10, 2 );
		foreach ( array( 'clean_post_cache', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'clearCache' ) );
		}
		add_action( 'admin_notices', static function (): void {
			$key = 'aish_editorial_error_' . get_current_user_id();
			$message = get_transient( $key );
			if ( is_string( $message ) ) {
				delete_transient( $key );
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			}
		} );
	}

	public function registerType(): void {
		$capabilities = array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'aish_manage_skills' );
		register_post_type( self::TUTORIAL, array(
			'labels' => array( 'name' => __( '教程 / Tutorials', 'ai-skills-hub' ), 'singular_name' => __( '教程 / Tutorial', 'ai-skills-hub' ), 'add_new_item' => __( '添加教程 / Add Tutorial', 'ai-skills-hub' ), 'edit_item' => __( '编辑教程 / Edit Tutorial', 'ai-skills-hub' ) ),
			'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true,
			'show_ui' => true, 'show_in_menu' => 'aish', 'show_in_rest' => false,
			'query_var' => false, 'rewrite' => false, 'has_archive' => false,
			'capabilities' => $capabilities, 'map_meta_cap' => false,
			'supports' => array( 'title', 'editor', 'excerpt', 'author', 'revisions' ),
		) );
	}

	/** Native reads follow the same non-disclosing error boundary as the Core DAL. */
	private static function database( callable $operation ): mixed {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$wpdb->last_error = '';
			$result = $operation();
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Editorial storage is unavailable.' ); }
			return $result;
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	/** The token scopes our SQL to this native query, including when WPML is active. */
	private static function query( array $arguments, string $extra_where = '' ): \WP_Query {
		global $wpdb;
		$token = new \stdClass();
		$arguments['_aish_editorial_query'] = $token;
		// Native query-cache generations do not track changes to Core review/status tables.
		// Only request-local memoization may reuse these cross-store public queries.
		$arguments['cache_results'] = false;
		$posts = $wpdb->posts;
		$guard = static function ( array $sql, array $queries, string $type, string $table, string $column, mixed $context ) use ( $token, $posts, $extra_where ): array {
			if ( ! $context instanceof \WP_Query || $context->get( '_aish_editorial_query' ) !== $token || $posts !== $table || 'ID' !== $column ) { return $sql; }
			// Native posts are public only with actual title/body text, not HTML placeholders.
			$spaces = '&(nbsp|NonBreakingSpace|ensp|emsp|emsp13|emsp14|numsp|puncsp|thinsp|hairsp|MediumSpace|ThickSpace|Tab|NewLine|#0*(9|10|11|12|13|32|160|5760|819[2-9]|820[0-2]|8232|8233|8239|8287|12288)|#x0*(9|a|b|c|d|20|a0|1680|200[0-9a]|2028|2029|202f|205f|3000));';
			foreach ( array( 'post_title', 'post_content' ) as $field ) {
				$text = "REGEXP_REPLACE(REGEXP_REPLACE(COALESCE($posts.$field,''),'<script[^>]*>.*?</script>','',1,0,'in'),'<style[^>]*>.*?</style>','',1,0,'in')";
				$sql['where'] .= " AND REGEXP_REPLACE(REGEXP_REPLACE($text,'<[^>]*>',''),'$spaces','') REGEXP '[^[:space:]]'";
			}
			$sql['where'] .= $extra_where;
			return $sql;
		};
		add_filter( 'get_meta_sql', $guard, 10, 6 );
		try { return self::database( static fn() => new \WP_Query( $arguments ) ); }
		finally { remove_filter( 'get_meta_sql', $guard, 10 ); }
	}

	private static function prime( array $posts ): void {
		if ( ! $posts ) { return; }
		self::database( static function () use ( $posts ): void {
			update_meta_cache( 'post', array_map( static fn( \WP_Post $post ): int => $post->ID, $posts ) );
			update_post_author_caches( $posts );
		} );
	}

	private static function nonemptyText( string $value ): bool {
		$text = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return 1 === preg_match( '/[^\s\p{Z}]/u', $text );
	}

	private static function post( int $id ): ?\WP_Post {
		return self::database( static fn() => get_post( $id ) );
	}

	private static function meta( int $id, string $key, bool $single = true ): mixed {
		return self::database( static fn() => get_post_meta( $id, $key, $single ) );
	}

	private static function locale( string $locale ): string {
		if ( ! in_array( $locale, self::LOCALES, true ) ) { throw new \InvalidArgumentException( 'Unsupported editorial language.' ); }
		return $locale;
	}

	private static function publicPost( mixed $post, string $type, ?string $locale = null ): bool {
		return $post instanceof \WP_Post && $type === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password
			&& self::nonemptyText( $post->post_title ) && self::nonemptyText( $post->post_content )
			&& in_array( self::meta( $post->ID, '_aish_locale', true ), self::LOCALES, true )
			&& ( null === $locale || $locale === self::meta( $post->ID, '_aish_locale', true ) );
	}

	private static function ids( mixed $input ): array {
		if ( ! is_string( $input ) || strlen( $input ) > 2100 ) { throw new \InvalidArgumentException( 'Invalid related Skill IDs.' ); }
		if ( '' === trim( $input ) ) { return array(); }
		$parts = array_map( 'trim', explode( ',', $input ) );
		if ( count( $parts ) > 100 ) { throw new \InvalidArgumentException( 'Too many related Skills.' ); }
		$ids = array();
		foreach ( $parts as $part ) {
			$id = filter_var( $part, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $id ) { throw new \InvalidArgumentException( 'Invalid related Skill ID.' ); }
			$ids[] = $id;
		}
		return array_values( array_unique( $ids ) );
	}

	private function payload( \WP_Post $post ): array {
		$author = self::database( static fn() => get_userdata( (int) $post->post_author ) );
		$content = wp_kses_post( $post->post_content );
		$summary = '' !== $post->post_excerpt ? wp_strip_all_tags( $post->post_excerpt ) : wp_trim_words( wp_strip_all_tags( $content ), 32, '…' );
		$related = array();
		foreach ( self::meta( $post->ID, '_aish_related_skill', false ) as $id ) {
			if ( is_scalar( $id ) && ctype_digit( (string) $id ) && (int) $id > 0 ) { $related[] = (int) $id; }
		}
		return array(
			'id' => $post->ID, 'locale' => self::meta( $post->ID, '_aish_locale', true ),
			'title' => $post->post_title, 'slug' => $post->post_name, 'summary' => $summary, 'excerpt' => $summary,
			'content' => $content, 'updated_at' => $post->post_modified_gmt, 'published_at' => $post->post_date_gmt, 'date' => $post->post_date_gmt,
			'author' => $author ? $author->display_name : '', 'author_name' => $author ? $author->display_name : '',
			'related_skill_ids' => array_slice( array_values( array_unique( $related ) ), 0, 100 ),
		);
	}

	private function linkedFilter( string $locale, array $filters ): string {
		global $wpdb;
		$selected = array();
		foreach ( array( 'category', 'tag', 'platform' ) as $key ) {
			$value = $filters[ $key ] ?? '';
			if ( '' !== $value ) { $selected[ $key ] = Input::slug( $value ); }
		}
		if ( ! $selected ) { return ''; }
		if ( ! $this->catalog ) { throw new \RuntimeException( 'Public catalog is temporarily unavailable.' ); }
		$skill_sql = $this->catalog->matchingIdsSql( $locale, $selected );
		// A single related Skill must satisfy the whole combination. Duplicate links cannot duplicate posts.
		return " AND EXISTS (SELECT 1 FROM $wpdb->postmeta aish_link WHERE aish_link.post_id=$wpdb->posts.ID AND aish_link.meta_key='_aish_related_skill' AND aish_link.meta_value REGEXP '^[1-9][0-9]*$' AND CAST(aish_link.meta_value AS UNSIGNED) IN ($skill_sql))";
	}

	public function tutorials( string $locale, array $filters = array() ): array {
		$locale = self::locale( $locale );
		$page = filter_var( $filters['page'] ?? 1, FILTER_VALIDATE_INT );
		$limit = filter_var( $filters['per_page'] ?? 12, FILTER_VALIDATE_INT );
		if ( false === $page || $page < 1 || $page > 10000 || false === $limit || $limit < 1 || $limit > 24 ) { throw new \InvalidArgumentException( 'Invalid editorial pagination.' ); }
		$search = trim( Input::utf8( $filters['q'] ?? '', 400 ) );
		if ( preg_match_all( '/./us', $search ) > 100 ) { throw new \InvalidArgumentException( 'Editorial query is too long.' ); }
		$sort = $filters['sort'] ?? 'newest';
		$sorts = array( 'newest' => array( 'modified' => 'DESC', 'ID' => 'DESC' ), 'oldest' => array( 'modified' => 'ASC', 'ID' => 'ASC' ), 'title' => array( 'title' => 'ASC', 'ID' => 'ASC' ) );
		if ( ! is_string( $sort ) || ! isset( $sorts[ $sort ] ) ) { throw new \InvalidArgumentException( 'Invalid tutorial sort.' ); }
		$where = $this->linkedFilter( $locale, $filters );
		if ( '' !== $search ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// Literal substring semantics match Skills: no stopword or leading-minus interpretation.
			$where .= $wpdb->prepare( " AND ($wpdb->posts.post_title LIKE %s OR $wpdb->posts.post_excerpt LIKE %s OR $wpdb->posts.post_content LIKE %s)", $like, $like, $like );
		}
		$key = 'tutorials:' . md5( serialize( array( $locale, $page, $limit, $search, $sort, $where ) ) );
		return $this->remember( $key, function () use ( $locale, $page, $limit, $search, $sort, $sorts, $where ): array {
			$query = self::query( array(
				'post_type' => self::TUTORIAL, 'post_status' => 'publish', 'has_password' => false,
				'posts_per_page' => $limit, 'paged' => $page,
				'meta_key' => '_aish_locale', 'meta_value' => $locale,
				'orderby' => $sorts[ $sort ], 'ignore_sticky_posts' => true, 'suppress_filters' => true,
				'update_post_meta_cache' => true, 'update_post_term_cache' => false,
			), $where );
			self::prime( $query->posts );
			$items = array();
			foreach ( $query->posts as $post ) {
				if ( self::publicPost( $post, self::TUTORIAL, $locale ) ) { $items[] = $this->payload( $post ); }
			}
			return array( 'items' => $items, 'total' => (int) $query->found_posts, 'page' => $page, 'per_page' => $limit );
		} );
	}

	public function tutorial( int $id, string $locale ): ?array {
		$locale = self::locale( $locale ); Input::id( $id );
		return $this->remember( "tutorial:$id:$locale", function () use ( $id, $locale ): ?array {
			$post = self::post( $id );
			return self::publicPost( $post, self::TUTORIAL, $locale ) ? $this->payload( $post ) : null;
		} );
	}

	private static function translationId( int $id, string $locale ): mixed {
		$mapped = self::meta( $id, '_aish_translation_id', true );
		if ( has_filter( 'wpml_object_id' ) ) {
			// Public WPML API, never return the original as a fabricated translation.
			$language = 'zh_CN' === $locale ? 'zh-hans' : 'en';
			$language = apply_filters( 'aish_wpml_language_code', $language, $locale );
			$wpml_id = apply_filters( 'wpml_object_id', $id, self::TUTORIAL, false, $language );
			if ( is_scalar( $wpml_id ) && ctype_digit( (string) $wpml_id ) && (int) $wpml_id > 0 && (int) $wpml_id !== $id ) { $mapped = $wpml_id; }
		}
		return apply_filters( 'aish_editorial_translation_id', $mapped, $id, $locale );
	}

	/** Both native and WPML pairs must be reciprocal, public and in the explicitly stored language. */
	public function tutorialAlternatives( int $id ): array {
		Input::id( $id );
		return $this->remember( "tutorial-alternates:$id", function () use ( $id ): array {
			$post = self::post( $id );
			if ( ! self::publicPost( $post, self::TUTORIAL ) ) { return array(); }
			$locale = (string) self::meta( $id, '_aish_locale', true );
			$other_locale = 'zh_CN' === $locale ? 'en_US' : 'zh_CN';
			$result = array( $locale => $id );
			$other_id = self::translationId( $id, $other_locale );
			if ( ! is_scalar( $other_id ) || ! ctype_digit( (string) $other_id ) || (int) $other_id < 1 ) { return $result; }
			$other_id = (int) $other_id;
			$other = self::post( $other_id );
			$reverse = self::translationId( $other_id, $locale );
			if ( self::publicPost( $other, self::TUTORIAL, $other_locale ) && is_scalar( $reverse ) && (string) $id === (string) $reverse ) { $result[ $other_locale ] = $other_id; }
			return $result;
		} );
	}

	public function relatedTutorials( int $skill_id, string $locale, int $limit = 3 ): array {
		Input::id( $skill_id ); self::locale( $locale );
		if ( $limit < 1 || $limit > 12 ) { throw new \InvalidArgumentException( 'Invalid related tutorial limit.' ); }
		return $this->remember( "related:$skill_id:$locale:$limit", function () use ( $skill_id, $locale, $limit ): array {
			$query = self::query( array(
				'post_type' => self::TUTORIAL, 'post_status' => 'publish', 'has_password' => false,
				'posts_per_page' => $limit, 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => true,
				'update_post_meta_cache' => true, 'update_post_term_cache' => false,
				'orderby' => array( 'modified' => 'DESC', 'ID' => 'DESC' ),
				'meta_query' => array( 'relation' => 'AND', array( 'key' => '_aish_locale', 'value' => $locale ), array( 'key' => '_aish_related_skill', 'value' => $skill_id, 'type' => 'UNSIGNED' ) ),
			) );
			self::prime( $query->posts );
			$items = array();
			foreach ( $query->posts as $post ) { if ( self::publicPost( $post, self::TUTORIAL, $locale ) ) { $items[] = $this->payload( $post ); } }
			return $items;
		} );
	}

	public function page( string $kind, string $locale ): ?array {
		self::locale( $locale );
		if ( ! in_array( $kind, self::PAGE_KINDS, true ) ) { throw new \InvalidArgumentException( 'Unsupported editorial page.' ); }
		return $this->remember( "page:$kind:$locale", function () use ( $kind, $locale ): ?array {
			$query = self::query( array(
				'post_type' => 'page', 'post_status' => 'publish', 'has_password' => false,
				'posts_per_page' => 1, 'no_found_rows' => true, 'orderby' => array( 'ID' => 'ASC' ), 'suppress_filters' => true,
				'update_post_term_cache' => false,
				'meta_query' => array( 'relation' => 'AND', array( 'key' => '_aish_locale', 'value' => $locale ), array( 'key' => '_aish_page_kind', 'value' => $kind ) ),
			) );
			$post = $query->posts[0] ?? null;
			return self::publicPost( $post, 'page', $locale ) ? $this->payload( $post ) : null;
		} );
	}

	private static function sitemapArguments( string $locale ): array {
		return array(
			'post_type' => self::TUTORIAL, 'post_status' => 'publish', 'has_password' => false,
			'meta_key' => '_aish_locale', 'meta_value' => self::locale( $locale ),
			'orderby' => array( 'ID' => 'ASC' ), 'suppress_filters' => true,
			'update_post_term_cache' => false, 'ignore_sticky_posts' => true,
		);
	}

	private static function indexableWhere(): string {
		global $wpdb;
		return " AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta aish_noindex WHERE aish_noindex.post_id=$wpdb->posts.ID AND aish_noindex.meta_key='_aish_seo_noindex' AND aish_noindex.meta_value NOT IN ('','0'))";
	}

	public function sitemapCount( string $locale ): int {
		self::locale( $locale );
		return $this->remember( "sitemap-count:$locale", static function () use ( $locale ): int {
			$query = self::query( self::sitemapArguments( $locale ) + array( 'posts_per_page' => 1, 'fields' => 'ids', 'update_post_meta_cache' => false ), self::indexableWhere() );
			return (int) $query->found_posts;
		} );
	}

	public function sitemapItems( string $locale, int $page, int $per_page = 200 ): array {
		self::locale( $locale );
		if ( $page < 1 || $page > 100000 || $per_page < 1 || $per_page > 1000 ) { throw new \InvalidArgumentException( 'Invalid sitemap pagination.' ); }
		return $this->remember( "sitemap-items:$locale:$page:$per_page", static function () use ( $locale, $page, $per_page ): array {
			$query = self::query( self::sitemapArguments( $locale ) + array( 'posts_per_page' => $per_page, 'paged' => $page, 'no_found_rows' => true, 'update_post_meta_cache' => true ), self::indexableWhere() );
			if ( $query->posts ) { self::database( static fn() => update_meta_cache( 'post', array_map( static fn( \WP_Post $post ): int => $post->ID, $query->posts ) ) ); }
			$items = array();
			foreach ( $query->posts as $post ) {
				if ( self::publicPost( $post, self::TUTORIAL, $locale ) ) { $items[] = array( 'id' => $post->ID, 'locale' => $locale, 'updated_at' => $post->post_modified_gmt ); }
			}
			return $items;
		} );
	}

	public function metaBoxes(): void {
		foreach ( array( self::TUTORIAL, 'page' ) as $type ) {
			add_meta_box( 'aish-editorial-language', __( 'AI Skills Hub 双语内容', 'ai-skills-hub' ), array( $this, 'metaBox' ), $type, 'normal', 'high' );
		}
	}

	public function metaBox( \WP_Post $post ): void {
		wp_nonce_field( 'aish_editorial_' . $post->ID, '_aish_editorial_nonce' );
		$locale = (string) get_post_meta( $post->ID, '_aish_locale', true );
		echo '<p><label for="aish-editorial-locale">' . esc_html__( '内容语言（明确选择后才会出现在双语前台）', 'ai-skills-hub' ) . '</label><br /><select id="aish-editorial-locale" name="aish_editorial_locale"><option value="">—</option>';
		foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $locale, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></p>';
		if ( 'page' === $post->post_type ) {
			$kind = (string) get_post_meta( $post->ID, '_aish_page_kind', true );
			echo '<p><label for="aish-page-kind">' . esc_html__( '前台页面用途', 'ai-skills-hub' ) . '</label><br /><select id="aish-page-kind" name="aish_page_kind"><option value="">—</option>';
			foreach ( array( 'about' => 'About', 'contact' => 'Contact', 'submit' => 'Submit a Skill' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $kind, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
			echo '</select></p><p class="description">' . esc_html__( '每种语言及用途请只发布一个页面；若存在重复，前台使用 ID 最小的已发布页面。', 'ai-skills-hub' ) . '</p>';
		} else {
			echo '<p><label for="aish-editorial-translation">' . esc_html__( '对应另一语言的教程 ID（可选）', 'ai-skills-hub' ) . '</label><br /><input type="number" min="1" name="aish_editorial_translation" id="aish-editorial-translation" value="' . esc_attr( (string) get_post_meta( $post->ID, '_aish_translation_id', true ) ) . '" /></p>';
			$ids = array_map( 'intval', get_post_meta( $post->ID, '_aish_related_skill', false ) );
			echo '<p><label for="aish-editorial-skills">' . esc_html__( '相关 Skill ID（逗号分隔，最多 100 个）', 'ai-skills-hub' ) . '</label><br /><input class="widefat" maxlength="2100" name="aish_editorial_skills" id="aish-editorial-skills" value="' . esc_attr( implode( ',', $ids ) ) . '" /></p><p class="description">' . esc_html__( '教程保存在 WordPress 原生文章中，不创建 Skill 副本。前台只链接已审核发布的 Skills。', 'ai-skills-hub' ) . '</p>';
		}
	}

	public function saveMeta( int $id, \WP_Post $post ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || $id !== $post->ID || ! in_array( $post->post_type, array( self::TUTORIAL, 'page' ), true ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) ) { return; }
		$nonce = $_POST['_aish_editorial_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'aish_editorial_' . $id ) ) { return; }
		try {
			if ( ! $this->writeDatabase ) { throw new \RuntimeException( 'Editorial writes are unavailable.' ); }
			$metadata = new NativeMetadata( $this->writeDatabase );
			$locale = wp_unslash( $_POST['aish_editorial_locale'] ?? '' );
			if ( ! is_string( $locale ) || ( '' !== $locale && ! in_array( $locale, self::LOCALES, true ) ) ) { throw new \InvalidArgumentException(); }
			if ( 'page' === $post->post_type ) {
				$kind = wp_unslash( $_POST['aish_page_kind'] ?? '' );
				if ( ! is_string( $kind ) || ( '' !== $kind && ! in_array( $kind, self::PAGE_KINDS, true ) ) ) { throw new \InvalidArgumentException(); }
				$metadata->transaction( static function ( NativeMetadata $metadata ) use ( $id, $locale, $kind ): void {
					$metadata->set( $id, '_aish_locale', $locale );
					$metadata->set( $id, '_aish_page_kind', $kind );
				} );
				return;
			}
			$skills = self::ids( wp_unslash( $_POST['aish_editorial_skills'] ?? '' ) );
			$value = wp_unslash( $_POST['aish_editorial_translation'] ?? '' );
			if ( ! is_string( $value ) ) { throw new \InvalidArgumentException(); }
			$other_id = '' === $value ? 0 : filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $other_id || $other_id === $id ) { throw new \InvalidArgumentException(); }
			$metadata->transaction( function ( NativeMetadata $metadata ) use ( $id, $locale, $other_id, $skills ): void {
				// Pair validation must use fresh metadata while all Hub pair writes share the lock.
				$metadata->touch( $id );
				if ( $other_id ) {
					$metadata->touch( $other_id );
					$other = self::post( $other_id );
					$other_locale = self::meta( $other_id, '_aish_locale' );
					$existing_pair = (int) self::meta( $other_id, '_aish_translation_id' );
					if ( ! $other instanceof \WP_Post || self::TUTORIAL !== $other->post_type || ! current_user_can( 'edit_post', $other_id ) || ! in_array( $other_locale, self::LOCALES, true ) || '' === $locale || $locale === $other_locale || ( $existing_pair && $existing_pair !== $id ) ) { throw new \InvalidArgumentException(); }
				}
				if ( $skills ) {
					$placeholders = implode( ',', array_fill( 0, count( $skills ), '%d' ) );
					$rows = $this->writeDatabase->rows( 'SELECT id FROM ' . $this->writeDatabase->table( 'skills' ) . " WHERE id IN ($placeholders) FOR SHARE", $skills );
					if ( count( $rows ) !== count( $skills ) ) { throw new \InvalidArgumentException( 'Related Skill does not exist.' ); }
				}
				$previous = (int) self::meta( $id, '_aish_translation_id' );
				if ( $previous && $previous !== $other_id ) {
					$metadata->touch( $previous );
					if ( (int) self::meta( $previous, '_aish_translation_id' ) === $id ) {
						if ( ! current_user_can( 'edit_post', $previous ) ) { throw new \InvalidArgumentException(); }
						$metadata->remove( $previous, '_aish_translation_id' );
					}
				}
				$metadata->set( $id, '_aish_locale', $locale );
				if ( $other_id ) { $metadata->set( $id, '_aish_translation_id', (string) $other_id ); $metadata->set( $other_id, '_aish_translation_id', (string) $id ); }
				else { $metadata->remove( $id, '_aish_translation_id' ); }
				$metadata->replace( $id, '_aish_related_skill', array_map( 'strval', $skills ) );
			} );
		} catch ( \Throwable $error ) {
			set_transient( 'aish_editorial_error_' . get_current_user_id(), __( '正文已由 WordPress 保存，但 AI Skills Hub 语言或关联元数据未能整体保存。检查权限、语言、对应教程、Skill ID 和数据库状态后重试。', 'ai-skills-hub' ), 120 );
		} finally { $this->clearCache(); }
	}
}
