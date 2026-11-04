<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Editorial content uses native WordPress posts; Skills remain exclusively in Core tables. */
final class Editorial {
	public const TUTORIAL = 'aish_tutorial';
	private const LOCALES = array( 'zh_CN', 'en_US' );
	private const PAGE_KINDS = array( 'about', 'contact', 'submit' );

	public function register(): void {
		add_action( 'init', array( $this, 'registerType' ) );
		add_action( 'add_meta_boxes', array( $this, 'metaBoxes' ) );
		add_action( 'save_post', array( $this, 'saveMeta' ), 10, 2 );
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

	private static function query( array $arguments ): \WP_Query {
		return self::database( static fn() => new \WP_Query( $arguments ) );
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

	public function tutorials( string $locale, array $filters = array() ): array {
		$locale = self::locale( $locale );
		$page = filter_var( $filters['page'] ?? 1, FILTER_VALIDATE_INT );
		$limit = filter_var( $filters['per_page'] ?? 12, FILTER_VALIDATE_INT );
		if ( false === $page || $page < 1 || $page > 10000 || false === $limit || $limit < 1 || $limit > 24 ) { throw new \InvalidArgumentException( 'Invalid editorial pagination.' ); }
		$search = Input::plain( $filters['q'] ?? '', 191, true );
		$sort = $filters['sort'] ?? 'newest';
		$sorts = array( 'newest' => array( 'modified' => 'DESC', 'ID' => 'DESC' ), 'oldest' => array( 'modified' => 'ASC', 'ID' => 'ASC' ), 'title' => array( 'title' => 'ASC', 'ID' => 'ASC' ) );
		if ( ! is_string( $sort ) || ! isset( $sorts[ $sort ] ) ) { throw new \InvalidArgumentException( 'Invalid tutorial sort.' ); }
		$query = self::query( array(
			'post_type' => self::TUTORIAL, 'post_status' => 'publish', 'has_password' => false,
			'posts_per_page' => $limit, 'paged' => $page, 's' => $search,
			'meta_key' => '_aish_locale', 'meta_value' => $locale,
			'orderby' => $sorts[ $sort ], 'ignore_sticky_posts' => true, 'suppress_filters' => true,
		) );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( self::publicPost( $post, self::TUTORIAL, $locale ) ) { $items[] = $this->payload( $post ); }
		}
		return array( 'items' => $items, 'total' => (int) $query->found_posts );
	}

	public function tutorial( int $id, string $locale ): ?array {
		$locale = self::locale( $locale );
		$post = self::post( Input::id( $id ) );
		return self::publicPost( $post, self::TUTORIAL, $locale ) ? $this->payload( $post ) : null;
	}

	/** Filters may map native tutorial IDs for a future adapter; reciprocal verified mapping is mandatory. */
	public function tutorialAlternatives( int $id ): array {
		$post = self::post( Input::id( $id ) );
		if ( ! self::publicPost( $post, self::TUTORIAL ) ) { return array(); }
		$locale = (string) self::meta( $id, '_aish_locale', true );
		$other_locale = 'zh_CN' === $locale ? 'en_US' : 'zh_CN';
		$result = array( $locale => $id );
		$other_id = apply_filters( 'aish_editorial_translation_id', self::meta( $id, '_aish_translation_id', true ), $id, $other_locale );
		if ( ! is_scalar( $other_id ) || ! ctype_digit( (string) $other_id ) || (int) $other_id < 1 ) { return $result; }
		$other_id = (int) $other_id;
		$other = self::post( $other_id );
		$reverse = apply_filters( 'aish_editorial_translation_id', self::meta( $other_id, '_aish_translation_id', true ), $other_id, $locale );
		if ( self::publicPost( $other, self::TUTORIAL, $other_locale ) && is_scalar( $reverse ) && (string) $id === (string) $reverse ) { $result[ $other_locale ] = $other_id; }
		return $result;
	}

	public function relatedTutorials( int $skill_id, string $locale, int $limit = 3 ): array {
		Input::id( $skill_id ); self::locale( $locale );
		if ( $limit < 1 || $limit > 12 ) { throw new \InvalidArgumentException( 'Invalid related tutorial limit.' ); }
		$query = self::query( array(
			'post_type' => self::TUTORIAL, 'post_status' => 'publish', 'has_password' => false,
			'posts_per_page' => $limit, 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => true,
			'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ),
			'meta_query' => array( 'relation' => 'AND', array( 'key' => '_aish_locale', 'value' => $locale ), array( 'key' => '_aish_related_skill', 'value' => $skill_id, 'type' => 'UNSIGNED' ) ),
		) );
		$items = array();
		foreach ( $query->posts as $post ) { if ( self::publicPost( $post, self::TUTORIAL, $locale ) ) { $items[] = $this->payload( $post ); } }
		return $items;
	}

	public function page( string $kind, string $locale ): ?array {
		self::locale( $locale );
		if ( ! in_array( $kind, self::PAGE_KINDS, true ) ) { throw new \InvalidArgumentException( 'Unsupported editorial page.' ); }
		$query = self::query( array(
			'post_type' => 'page', 'post_status' => 'publish', 'has_password' => false,
			'posts_per_page' => 1, 'no_found_rows' => true, 'orderby' => array( 'ID' => 'ASC' ), 'suppress_filters' => true,
			'meta_query' => array( 'relation' => 'AND', array( 'key' => '_aish_locale', 'value' => $locale ), array( 'key' => '_aish_page_kind', 'value' => $kind ) ),
		) );
		$post = $query->posts[0] ?? null;
		return self::publicPost( $post, 'page', $locale ) ? $this->payload( $post ) : null;
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
		if ( ! in_array( $post->post_type, array( self::TUTORIAL, 'page' ), true ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) ) { return; }
		$nonce = $_POST['_aish_editorial_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'aish_editorial_' . $id ) ) { return; }
		try {
			$locale = wp_unslash( $_POST['aish_editorial_locale'] ?? '' );
			if ( ! is_string( $locale ) || ( '' !== $locale && ! in_array( $locale, self::LOCALES, true ) ) ) { throw new \InvalidArgumentException(); }
			if ( 'page' === $post->post_type ) {
				$kind = wp_unslash( $_POST['aish_page_kind'] ?? '' );
				if ( ! is_string( $kind ) || ( '' !== $kind && ! in_array( $kind, self::PAGE_KINDS, true ) ) ) { throw new \InvalidArgumentException(); }
				update_post_meta( $id, '_aish_locale', $locale );
				update_post_meta( $id, '_aish_page_kind', $kind );
				return;
			}
			$skills = self::ids( wp_unslash( $_POST['aish_editorial_skills'] ?? '' ) );
			$value = wp_unslash( $_POST['aish_editorial_translation'] ?? '' );
			if ( ! is_string( $value ) ) { throw new \InvalidArgumentException(); }
			$other_id = '' === $value ? 0 : filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $other_id || $other_id === $id ) { throw new \InvalidArgumentException(); }
			if ( $other_id ) {
				$other = get_post( $other_id );
				$other_locale = get_post_meta( $other_id, '_aish_locale', true );
				$existing_pair = (int) get_post_meta( $other_id, '_aish_translation_id', true );
				if ( ! $other instanceof \WP_Post || self::TUTORIAL !== $other->post_type || ! current_user_can( 'edit_post', $other_id ) || ! in_array( $other_locale, self::LOCALES, true ) || '' === $locale || $locale === $other_locale || ( $existing_pair && $existing_pair !== $id ) ) { throw new \InvalidArgumentException(); }
			}
			$previous = (int) get_post_meta( $id, '_aish_translation_id', true );
			if ( $previous && $previous !== $other_id && (int) get_post_meta( $previous, '_aish_translation_id', true ) === $id ) { delete_post_meta( $previous, '_aish_translation_id', $id ); }
			update_post_meta( $id, '_aish_locale', $locale );
			if ( $other_id ) { update_post_meta( $id, '_aish_translation_id', $other_id ); update_post_meta( $other_id, '_aish_translation_id', $id ); }
			else { delete_post_meta( $id, '_aish_translation_id' ); }
			delete_post_meta( $id, '_aish_related_skill' );
			foreach ( $skills as $skill_id ) { add_post_meta( $id, '_aish_related_skill', $skill_id ); }
		} catch ( \Throwable $error ) {
			set_transient( 'aish_editorial_error_' . get_current_user_id(), __( '正文已由 WordPress 保存，但 AI Skills Hub 语言或关联信息无效，未更新这些元数据。检查语言、对应教程权限和 Skill ID 后重新保存。', 'ai-skills-hub' ), 120 );
		}
	}
}
