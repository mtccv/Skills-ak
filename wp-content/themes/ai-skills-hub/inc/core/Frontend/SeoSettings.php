<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Security\Access;
use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Bilingual presentation settings, separate from the reviewed Skill content. */
final class SeoSettings {
	private const OPTION = 'aish_seo_settings';
	private const LOCALES = array( 'zh_CN', 'en_US' );
	private const ROUTES = array( 'home', 'skills', 'tutorials', 'help', 'about', 'contact', 'submit', 'category', 'tag', 'skill' );
	private const ENTITIES = array( 'category' => 'categories', 'tag' => 'tags', 'skill' => 'skills' );
	private const MAX_OVERRIDES = 1000;

	public function __construct( private Database $database ) {}

	public function register(): void {
		add_action( 'admin_menu', function (): void {
			add_submenu_page( 'aish', 'SEO', 'SEO', current_user_can( 'manage_options' ) ? 'manage_options' : 'aish_manage_settings', 'aish-seo', array( $this, 'screen' ) );
		} );
		add_action( 'admin_post_aish_save_seo', array( $this, 'save' ) );
		add_action( 'add_meta_boxes', array( $this, 'metaBoxes' ) );
		add_action( 'save_post', array( $this, 'savePost' ), 20, 2 );
		add_action( 'admin_notices', static function (): void {
			$key = 'aish_seo_error_' . get_current_user_id();
			if ( get_transient( $key ) ) {
				delete_transient( $key );
				echo '<div class="notice notice-error"><p>' . esc_html__( 'SEO 设置未保存。请检查字段、权限和数据库状态后重试。', 'ai-skills-hub' ) . '</p></div>';
			}
		} );
	}

	public static function defaults(): array {
		return array( 'title' => '', 'description' => '', 'image' => '', 'noindex' => false );
	}

	/** Missing settings are valid; a failed native read is not permission to index. */
	private static function read( callable $operation ): mixed {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$wpdb->last_error = '';
			$value = $operation();
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'SEO storage is unavailable.' ); }
			return $value;
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	private static function option( string $name, mixed $default ): mixed {
		try { return self::read( static fn() => get_option( $name, $default ) ); }
		catch ( \Throwable $error ) {
			// The native API may cache a failed database read as a missing option.
			wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' );
			throw new \RuntimeException( 'SEO storage is unavailable.' );
		}
	}

	public static function indexableSite(): bool { return '1' === (string) self::option( 'blog_public', '1' ); }

	private static function stored(): array {
		$data = self::option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	private static function key( string $route, string $locale, int $id ): string {
		if ( ! in_array( $locale, self::LOCALES, true ) || ! in_array( $route, self::ROUTES, true ) || ( isset( self::ENTITIES[ $route ] ) ? $id < 1 : 0 !== $id ) ) {
			throw new \InvalidArgumentException( 'Invalid SEO identity.' );
		}
		return $locale . ':' . $route . ':' . $id;
	}

	/** Options API caches this single non-autoload option for the current request. */
	public static function values( string $route, string $locale, int $id = 0 ): array {
		$key = self::key( $route, $locale, $id );
		$value = self::stored()[ $key ] ?? array();
		$value = is_array( $value ) ? array_intersect_key( $value, self::defaults() ) : array();
		$result = array_merge( self::defaults(), $value );
		foreach ( array( 'title', 'description', 'image' ) as $field ) { if ( ! is_string( $result[ $field ] ) ) { $result[ $field ] = ''; } }
		$result['noindex'] = true === $result['noindex'] || '1' === $result['noindex'];
		// Reviewed translation fields are the sole authority for Skill titles/descriptions.
		if ( 'skill' === $route ) { $result['title'] = ''; $result['description'] = ''; }
		return $result;
	}

	public static function postValues( int $postId ): array {
		Input::id( $postId );
		$result = self::defaults();
		try {
			foreach ( array_keys( $result ) as $field ) {
				$value = self::read( static fn() => get_post_meta( $postId, '_aish_seo_' . $field, true ) );
				$result[ $field ] = 'noindex' === $field ? '1' === $value : ( is_string( $value ) ? $value : '' );
			}
		} catch ( \Throwable $error ) { wp_cache_delete( $postId, 'post_meta' ); throw new \RuntimeException( 'SEO storage is unavailable.' ); }
		return $result;
	}

	/** A single cached option read, suitable for adding bounded NOT IN conditions. */
	public static function excludedIds( string $kind, string $locale ): array {
		$kind = 'skills' === $kind ? 'skill' : $kind;
		if ( ! isset( self::ENTITIES[ $kind ] ) || ! in_array( $locale, self::LOCALES, true ) ) { throw new \InvalidArgumentException( 'Invalid SEO exclusion type.' ); }
		$prefix = $locale . ':' . $kind . ':';
		$result = array();
		foreach ( self::stored() as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, $prefix ) || ! is_array( $value ) || ! in_array( $value['noindex'] ?? false, array( true, '1' ), true ) ) { continue; }
			$id = filter_var( substr( $key, strlen( $prefix ) ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false !== $id ) { $result[] = $id; }
		}
		return array_values( array_unique( $result ) );
	}

	private static function input( array $source, string $key, string $default = '' ): string {
		$value = $source[ $key ] ?? $default;
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'Invalid SEO input.' ); }
		return wp_unslash( $value );
	}

	private static function selection( array $source ): array {
		$route = self::input( $source, 'seo_route', 'home' );
		$locale = self::input( $source, 'seo_locale', 'zh_CN' );
		$rawId = self::input( $source, 'seo_id', '0' );
		$id = filter_var( $rawId, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $id ) { throw new \InvalidArgumentException( 'Invalid SEO identity.' ); }
		if ( ! isset( self::ENTITIES[ $route ] ) ) { $id = 0; }
		self::key( $route, $locale, $id );
		return array( $route, $locale, $id );
	}

	private function entity( string $route, int $id ): ?array {
		if ( ! isset( self::ENTITIES[ $route ] ) ) { return null; }
		$table = $this->database->table( self::ENTITIES[ $route ] );
		$columns = 'skill' === $route ? 'id' : 'id, name, slug';
		$row = $this->database->row( "SELECT {$columns} FROM {$table} WHERE id = %d", array( $id ) );
		if ( ! $row ) { throw new \InvalidArgumentException( 'SEO target does not exist.' ); }
		return $row;
	}

	private static function validate( array $source, bool $skill = false ): array {
		$value = self::defaults();
		$value['title'] = $skill ? '' : Input::plain( self::input( $source, 'seo_title' ), 191, true );
		$value['description'] = $skill ? '' : Input::plain( self::input( $source, 'seo_description' ), 320, true );
		$image = Input::utf8( self::input( $source, 'seo_image' ), 2048 );
		if ( '' !== $image ) {
			$parts = wp_parse_url( $image );
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || preg_match( '/[\x00-\x20\x7f]/', $image ) || ! filter_var( $image, FILTER_VALIDATE_URL ) ) { throw new \InvalidArgumentException( 'Use an absolute HTTPS image URL.' ); }
			$value['image'] = esc_url_raw( $image, array( 'https' ) );
			if ( '' === $value['image'] ) { throw new \InvalidArgumentException( 'Invalid image URL.' ); }
		}
		$noindex = self::input( $source, 'seo_noindex', '0' );
		if ( ! in_array( $noindex, array( '0', '1' ), true ) ) { throw new \InvalidArgumentException( 'Invalid index policy.' ); }
		$value['noindex'] = '1' === $noindex;
		return $value;
	}

	private static function fingerprint( array $value ): string { return hash( 'sha256', (string) wp_json_encode( $value ) ); }

	public function screen(): void {
		try { Access::write( 'aish_manage_settings' ); } catch ( \DomainException $error ) { wp_die( esc_html__( '无权管理 SEO 设置。', 'ai-skills-hub' ), '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>AI Skills Hub · SEO</h1>';
		$labels = array( 'home' => '首页 / Home', 'skills' => 'Skills 列表 / Skills archive', 'tutorials' => '教程列表 / Tutorials', 'help' => '帮助中心 / Help center', 'about' => '关于 / About', 'contact' => '联系 / Contact', 'submit' => '投稿 / Submit', 'category' => '分类 / Category', 'tag' => '标签 / Tag', 'skill' => 'Skill 分享及索引 / Skill sharing and indexing' );
		echo '<p>' . esc_html__( '中文和英文独立配置。留空使用当前语言的已有标题和摘要；不会生成缺失译文。教程和说明页面也可在各自编辑器的 SEO 面板配置。', 'ai-skills-hub' ) . '</p>';
		try { list( $route, $locale, $id ) = self::selection( $_GET ); } catch ( \InvalidArgumentException $error ) { $route = 'home'; $locale = 'zh_CN'; $id = 0; }
		echo '<form method="get"><input type="hidden" name="page" value="aish-seo"><p><label for="aish-seo-route">' . esc_html__( '页面类型', 'ai-skills-hub' ) . '</label> <select name="seo_route" id="aish-seo-route">';
		foreach ( $labels as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $route, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select> <label for="aish-seo-locale">' . esc_html__( '语言', 'ai-skills-hub' ) . '</label> <select name="seo_locale" id="aish-seo-locale">';
		foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $locale, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select> <label for="aish-seo-id">' . esc_html__( '分类 / 标签 / Skill ID', 'ai-skills-hub' ) . '</label> <input type="number" class="small-text" min="0" name="seo_id" id="aish-seo-id" value="' . (int) $id . '"> ';
		submit_button( __( '打开 / Open', 'ai-skills-hub' ), 'secondary', 'open', false );
		echo '</p></form>';
		try {
			$entity = $this->entity( $route, $id );
			$value = self::values( $route, $locale, $id );
			if ( isset( $_GET['seo_saved'] ) && '1' === $_GET['seo_saved'] ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'SEO 设置已保存。', 'ai-skills-hub' ) . '</p></div>'; }
			echo '<h2>' . esc_html( $labels[ $route ] . ' · ' . $locale . ( $entity ? ' · #' . $id . ( isset( $entity['name'] ) ? ' ' . $entity['name'] : '' ) : '' ) ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aish_save_seo">';
			wp_nonce_field( 'aish_save_seo' );
			foreach ( array( 'seo_route' => $route, 'seo_locale' => $locale, 'seo_id' => (string) $id, 'seo_revision' => self::fingerprint( $value ) ) as $key => $field ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $field ) . '">'; }
			self::fields( $value, 'skill' === $route );
			submit_button( __( '保存 SEO / Save SEO', 'ai-skills-hub' ) );
			echo '</form>';
		} catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html__( '无法读取该记录。请填写存在的分类、标签或 Skill ID，并确认数据库已完成升级。', 'ai-skills-hub' ) . '</p></div>'; }
		echo '</div>';
	}

	private static function fields( array $value, bool $skill = false ): void {
		if ( $skill ) { echo '<p>' . esc_html__( 'Skill SEO 标题与描述请在 Skills 双语编辑器维护，并完成现有审核流程。', 'ai-skills-hub' ) . '</p>'; }
		echo '<table class="form-table" role="presentation"><tbody>';
		if ( ! $skill ) {
			echo '<tr><th scope="row"><label for="aish-seo-title">SEO Title</label></th><td><input class="large-text" id="aish-seo-title" name="seo_title" maxlength="191" value="' . esc_attr( $value['title'] ) . '"></td></tr>';
			echo '<tr><th scope="row"><label for="aish-seo-description">Meta Description</label></th><td><textarea class="large-text" id="aish-seo-description" name="seo_description" rows="3" maxlength="320">' . esc_textarea( $value['description'] ) . '</textarea></td></tr>';
		}
		echo '<tr><th scope="row"><label for="aish-seo-image">Open Graph / Twitter image</label></th><td><input type="url" class="large-text" id="aish-seo-image" name="seo_image" maxlength="2048" value="' . esc_attr( $value['image'] ) . '" placeholder="https://"><p class="description">' . esc_html__( '可选的 HTTPS 图片地址。分享标题和描述使用本语言 SEO 内容；未提供图片时不生成虚构图片。', 'ai-skills-hub' ) . '</p></td></tr>';
		echo '<tr><th scope="row">Canonical / hreflang</th><td>' . esc_html__( '由当前语言的真实公开地址自动生成。不可将中文 Canonical 指向英文；缺失译文不生成语言链接。', 'ai-skills-hub' ) . '</td></tr>';
		echo '<tr><th scope="row">Robots</th><td><label><input type="checkbox" name="seo_noindex" value="1"' . checked( $value['noindex'], true, false ) . '> ' . esc_html__( '不索引 / noindex，并从 Sitemap 排除', 'ai-skills-hub' ) . '</label><p class="description">' . esc_html__( '此选项不改变发布权限；未审核或私有内容始终不得公开。', 'ai-skills-hub' ) . '</p></td></tr></tbody></table>';
	}

	public function save(): void {
		try { Access::write( 'aish_manage_settings' ); } catch ( \DomainException $error ) { wp_die( esc_html__( '无权管理 SEO 设置。', 'ai-skills-hub' ), '', array( 'response' => 403 ) ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( '仅允许 POST 请求。', 'ai-skills-hub' ), '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aish_save_seo' );
		$redirect = add_query_arg( array( 'page' => 'aish-seo' ), admin_url( 'admin.php' ) );
		try {
			list( $route, $locale, $id ) = self::selection( $_POST );
			$this->entity( $route, $id );
			$value = self::validate( $_POST, 'skill' === $route );
			$revision = self::input( $_POST, 'seo_revision' );
			$redirect = add_query_arg( array( 'page' => 'aish-seo', 'seo_route' => $route, 'seo_locale' => $locale, 'seo_id' => $id ), admin_url( 'admin.php' ) );
			$key = self::key( $route, $locale, $id );
			$lock = 'aish_seo_' . hash( 'sha256', $this->database->prefix() . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
			$lock = substr( $lock, 0, 64 );
			// Native option reads/writes share this connection lock. Do not let wpdb
			// reconnect and replay a whole-option write after that lock has been lost.
			$driverMode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
			if ( null !== $driverMode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
			$locked = false;
			try {
				if ( '1' !== (string) $this->database->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) ) ) { throw new \RuntimeException( 'SEO settings are busy.' ); }
				$locked = true;
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$current = self::values( $route, $locale, $id );
				if ( ! hash_equals( self::fingerprint( $current ), $revision ) ) { throw new \DomainException( 'SEO settings have changed.' ); }
				$settings = self::stored();
				if ( ! isset( $settings[ $key ] ) && count( $settings ) >= self::MAX_OVERRIDES && $value !== self::defaults() ) { throw new \DomainException( 'SEO override capacity reached.' ); }
				if ( $value === self::defaults() ) { unset( $settings[ $key ] ); } else { $settings[ $key ] = $value; }
				$previous = $this->database->connection()->suppress_errors( true );
				try {
					$this->database->connection()->last_error = '';
					update_option( self::OPTION, $settings, false );
					if ( '' !== $this->database->connection()->last_error || self::stored() !== $settings ) { throw new \RuntimeException( 'SEO settings could not be stored.' ); }
				} finally { $this->database->connection()->suppress_errors( $previous ); }
			} finally {
				try { if ( $locked ) { $this->database->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } }
				finally { if ( null !== $driverMode ) { mysqli_report( $driverMode ); } }
			}
			$redirect = add_query_arg( 'seo_saved', '1', $redirect );
		} catch ( \Throwable $error ) { set_transient( 'aish_seo_error_' . get_current_user_id(), '1', 120 ); }
		wp_safe_redirect( $redirect, 303 );
		exit;
	}

	public function metaBoxes(): void {
		foreach ( array( Editorial::TUTORIAL, 'page' ) as $type ) {
			add_meta_box( 'aish-seo', 'AI Skills Hub · SEO', array( $this, 'metaBox' ), $type, 'normal', 'default' );
		}
	}

	public function metaBox( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) { return; }
		try { $value = self::postValues( $post->ID ); }
		catch ( \Throwable $error ) { echo '<p>' . esc_html__( 'SEO 配置暂时无法读取，请刷新后重试。', 'ai-skills-hub' ) . '</p>'; return; }
		wp_nonce_field( 'aish_post_seo_' . $post->ID, 'aish_seo_nonce' );
		echo '<p>' . esc_html__( '设置仅用于当前文章的语言。页面需要先在双语内容面板指定 About、Contact 或 Submit 用途。', 'ai-skills-hub' ) . '</p>';
		self::fields( $value );
	}

	public function savePost( int $postId, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, array( Editorial::TUTORIAL, 'page' ), true ) || wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
		if ( ! current_user_can( 'edit_post', $postId ) || ! isset( $_POST['aish_seo_nonce'] ) || ! is_string( $_POST['aish_seo_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['aish_seo_nonce'] ), 'aish_post_seo_' . $postId ) ) { return; }
		if ( ! in_array( get_post_meta( $postId, '_aish_locale', true ), self::LOCALES, true ) || ( 'page' === $post->post_type && ! in_array( get_post_meta( $postId, '_aish_page_kind', true ), array( 'about', 'contact', 'submit' ), true ) ) ) { return; }
		try {
			$value = self::validate( $_POST );
			$this->database->transaction( function () use ( $postId, $value ): void {
				$connection = $this->database->connection();
				$previous = $connection->suppress_errors( true );
				try {
					foreach ( $value as $field => $item ) {
						$connection->last_error = '';
						update_post_meta( $postId, '_aish_seo_' . $field, 'noindex' === $field ? ( $item ? '1' : '0' ) : $item );
						if ( '' !== $connection->last_error ) { throw new \RuntimeException( 'SEO metadata could not be stored.' ); }
					}
				} finally { $connection->suppress_errors( $previous ); }
			} );
		} catch ( \Throwable $error ) { clean_post_cache( $postId ); set_transient( 'aish_seo_error_' . get_current_user_id(), '1', 120 ); }
	}
}
