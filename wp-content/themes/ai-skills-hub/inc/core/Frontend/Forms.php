<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Public requests enter a private native inbox. No email, network request or Skill mutation occurs. */
final class Forms {
	public const POST_TYPE = 'aish_message';
	private const GLOBAL_LIMIT = 120;
	private const ADDRESS_LIMIT = 5;

	public function __construct( private ?Database $database = null ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'registerType' ) );
		add_action( 'admin_post_aish_public_message', array( $this, 'receive' ) );
		add_action( 'admin_post_nopriv_aish_public_message', array( $this, 'receive' ) );
		add_filter( 'wp_insert_post_data', static function ( array $data ): array {
			if ( self::POST_TYPE === ( $data['post_type'] ?? '' ) && 'trash' !== ( $data['post_status'] ?? '' ) ) {
				$data['post_status'] = 'private';
				$data['post_password'] = '';
			}
			return $data;
		} );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, function ( \WP_Post $post ): void {
			add_meta_box( 'aish-message-details', __( '来信与人工处理', 'ai-skills-hub' ), array( $this, 'metaBox' ), self::POST_TYPE, 'normal', 'high' );
		} );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'saveState' ) );
		add_action( 'admin_notices', static function (): void {
			if ( ! current_user_can( 'aish_manage_settings' ) && ! current_user_can( 'manage_options' ) ) { return; }
			$key = 'aish_message_error_' . get_current_user_id();
			if ( '1' !== get_transient( $key ) ) { return; }
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html__( '来信处理状态和内部备注未保存。请刷新页面，核对输入后重试。', 'ai-skills-hub' ) . '</p></div>';
		} );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', static function ( array $columns ): array {
			return array( 'cb' => $columns['cb'] ?? '', 'title' => __( '来信', 'ai-skills-hub' ), 'aish_kind' => __( '类型', 'ai-skills-hub' ), 'aish_state' => __( '处理状态', 'ai-skills-hub' ), 'date' => __( '收到时间', 'ai-skills-hub' ) );
		} );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', static function ( string $column, int $id ): void {
			if ( 'aish_kind' === $column ) { echo esc_html( 'submit' === get_post_meta( $id, '_aish_message_kind', true ) ? __( 'Skill 投稿', 'ai-skills-hub' ) : __( '联系来信', 'ai-skills-hub' ) ); }
			if ( 'aish_state' === $column ) { echo esc_html( self::states()[ get_post_meta( $id, '_aish_message_state', true ) ] ?? self::states()['pending'] ); }
		}, 10, 2 );
	}

	public function registerType(): void {
		$capabilities = array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts' ), 'aish_manage_settings' );
		$capabilities['create_posts'] = 'do_not_allow';
		register_post_type( self::POST_TYPE, array(
			'labels' => array( 'name' => __( '来信与投稿 / Inbox', 'ai-skills-hub' ), 'singular_name' => __( '私有来信', 'ai-skills-hub' ), 'edit_item' => __( '处理来信', 'ai-skills-hub' ) ),
			'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true,
			'show_ui' => true, 'show_in_menu' => 'aish', 'show_in_rest' => false,
			'query_var' => false, 'rewrite' => false, 'has_archive' => false,
			'capabilities' => $capabilities, 'map_meta_cap' => false,
			'supports' => array( 'title' ),
		) );
	}

	private static function states(): array {
		return array( 'pending' => __( '待处理', 'ai-skills-hub' ), 'reviewed' => __( '已处理', 'ai-skills-hub' ), 'archived' => __( '已归档', 'ai-skills-hub' ) );
	}

	public function metaBox( \WP_Post $post ): void {
		if ( ! current_user_can( 'aish_manage_settings' ) && ! current_user_can( 'manage_options' ) ) { return; }
		if ( get_post_meta( $post->ID, '_aish_enterprise_version', true ) ) { echo '<p>' . esc_html__( '请在企业咨询管理中处理此记录。 / Use Enterprise inquiries.', 'ai-skills-hub' ) . '</p>'; return; }
		if ( get_post_meta( $post->ID, '_aish_submission_version', true ) ) { echo '<p>' . esc_html__( '请在专用投稿管理中处理此记录。', 'ai-skills-hub' ) . '</p>'; return; }
		$fields = array( '_aish_sender_name' => 'Name', '_aish_sender_email' => 'Email', '_aish_message_locale' => 'Language', '_aish_github_url' => 'GitHub URL' );
		echo '<dl>';
		foreach ( $fields as $key => $label ) { echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( (string) get_post_meta( $post->ID, $key, true ) ) . '</dd>'; }
		echo '</dl><h3>' . esc_html__( '原始来信', 'ai-skills-hub' ) . '</h3><div style="white-space:pre-wrap;overflow-wrap:anywhere;padding:16px;background:#f6f7f7">' . esc_html( $post->post_content ) . '</div>';
		wp_nonce_field( 'aish_message_state_' . $post->ID, '_aish_message_nonce' );
		$state = (string) get_post_meta( $post->ID, '_aish_message_state', true );
		echo '<p><label for="aish-message-state">' . esc_html__( '处理状态', 'ai-skills-hub' ) . '</label><br /><select id="aish-message-state" name="aish_message_state">';
		foreach ( self::states() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $state ?: 'pending', $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></p><p><label for="aish-message-notes">' . esc_html__( '内部处理备注（不会公开）', 'ai-skills-hub' ) . '</label><br /><textarea id="aish-message-notes" name="aish_message_notes" class="widefat" rows="5" maxlength="3000">' . esc_textarea( (string) get_post_meta( $post->ID, '_aish_message_notes', true ) ) . '</textarea></p><p class="description">' . esc_html__( '处理投稿不会自动创建或发布 Skill。需要时请在 Skills 后台人工录入并审核。', 'ai-skills-hub' ) . '</p>';
	}

	public function saveState( int $id ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || $id < 1 || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) || ( ! current_user_can( 'aish_manage_settings' ) && ! current_user_can( 'manage_options' ) ) ) { return; }
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) { return; }
		if ( get_post_meta( $id, '_aish_submission_version', true ) || get_post_meta( $id, '_aish_enterprise_version', true ) ) { return; }
		$nonce = $_POST['_aish_message_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'aish_message_state_' . $id ) ) { return; }
		$key = 'aish_message_error_' . get_current_user_id();
		try {
			$state = wp_unslash( $_POST['aish_message_state'] ?? '' );
			$notes = Input::utf8( wp_unslash( $_POST['aish_message_notes'] ?? '' ), 12000 );
			if ( ! is_string( $state ) || ! isset( self::states()[ $state ] ) || preg_match_all( '/./us', $notes ) > 3000 ) { throw new \InvalidArgumentException( 'Invalid inbox metadata.' ); }
			if ( ! $this->database ) { throw new \RuntimeException( 'Inbox metadata storage is unavailable.' ); }
			( new NativeMetadata( $this->database ) )->transaction( static function ( NativeMetadata $metadata ) use ( $id, $state, $notes ): void {
				$metadata->set( $id, '_aish_message_state', $state );
				$metadata->set( $id, '_aish_message_notes', sanitize_textarea_field( $notes ) );
			} );
			delete_transient( $key );
		} catch ( \Throwable $error ) { set_transient( $key, '1', 120 ); }
	}

	private static function redirect( string $locale, string $kind, string $status ): never {
		$language = 'en_US' === $locale ? 'en' : 'zh';
		$route = 'submit' === $kind ? 'submit' : 'contact';
		try { $url = Router::url( $route, array(), $locale ); }
		// Preserve the saved result; Router's retry skips the failed adapter without another insert.
		catch ( \RuntimeException $error ) { $url = Router::url( $route, array(), $locale ); }
		if ( ! is_string( $url ) ) { $url = Router::siteUrl( '/' . $language . '/' . $route . '/' ); }
		nocache_headers();
		wp_safe_redirect( add_query_arg( 'form', $status, $url ), 303 );
		exit;
	}

	private static function githubUrl( mixed $value ): string {
		$value = trim( Input::utf8( $value, 2048 ) );
		if ( '' === $value ) { return ''; }
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'github.com' !== strtolower( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || preg_match( '/[\x00-\x20\x7f]/', $value ) ) { throw new \InvalidArgumentException( 'Invalid GitHub URL.' ); }
		if ( ! preg_match( '~^/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[^\s]*)?$~D', $parts['path'] ?? '' ) ) { throw new \InvalidArgumentException( 'Invalid repository URL.' ); }
		return esc_url_raw( $value, array( 'https' ) );
	}

	/** A single connection lock protects both counters. Only hashes, time and counts are stored. */
	public function rateLimit(): bool {
		global $wpdb;
		$raw_address = $_SERVER['REMOTE_ADDR'] ?? '';
		$address = is_string( $raw_address ) && filter_var( $raw_address, FILTER_VALIDATE_IP ) ? $raw_address : 'unknown';
		$fingerprint = hash_hmac( 'sha256', $address, wp_salt( 'nonce' ) );
		$prefix = '_aish_public_rate_';
		$global_key = $prefix . 'global';
		$address_key = $prefix . 'address_' . $fingerprint;
		$lock = 'aish_forms_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 48 );
		$now = time();
		$locked = false;
		// A reconnect loses the advisory lock; never replay counter writes in a new session.
		$driverMode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $driverMode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		$previous = $wpdb->suppress_errors( true );
		try {
			$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Rate limiter unavailable.' ); }
			if ( ! $locked ) { return false; }
			// Cleanup is bounded. A global quota prevents unbounded new address rows per hour.
			$cleaned = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d LIMIT 200", $wpdb->esc_like( $prefix . 'address_' ) . '%', $now - 2 * HOUR_IN_SECONDS ) );
			if ( false === $cleaned ) { throw new \RuntimeException( 'Rate limiter unavailable.' ); }
			$next = array();
			foreach ( array( $global_key => self::GLOBAL_LIMIT, $address_key => self::ADDRESS_LIMIT ) as $key => $limit ) {
				$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $key ) );
				if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Rate limiter unavailable.' ); }
				$parts = is_string( $value ) ? explode( ':', $value ) : array();
				$window = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
				$count = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : 0;
				if ( $window <= $now - HOUR_IN_SECONDS || $window > $now ) { $window = $now; $count = 0; }
				if ( $count >= $limit ) { return false; }
				$next[ $key ] = $window . ':' . ( $count + 1 );
			}
			foreach ( $next as $key => $value ) {
				$written = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'", $key, $value ) );
				if ( false === $written ) { throw new \RuntimeException( 'Rate limiter unavailable.' ); }
				wp_cache_delete( $key, 'options' );
			}
			return true;
		} finally {
			try { if ( $locked ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); } }
			finally {
				$wpdb->suppress_errors( $previous );
				if ( null !== $driverMode ) { mysqli_report( $driverMode ); }
			}
		}
	}

	/** A failed insert or metadata write is an error, never a successful public submission. */
	private function storeMessage( array $record ): void {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$wpdb->last_error = '';
			$id = wp_insert_post( wp_slash( $record ), true );
			if ( is_wp_error( $id ) || $id < 1 || '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Message storage unavailable.' ); }
			$stored = get_post( $id );
			if ( '' !== $wpdb->last_error || ! $stored instanceof \WP_Post || self::POST_TYPE !== $stored->post_type || 'private' !== $stored->post_status ) { throw new \RuntimeException( 'Message storage unavailable.' ); }
			foreach ( $record['meta_input'] as $key => $value ) {
				$actual = get_post_meta( $id, $key, true );
				if ( '' !== $wpdb->last_error || $value !== $actual ) { throw new \RuntimeException( 'Message metadata unavailable.' ); }
			}
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	/** Shared input contract for Contact and the separate enterprise subtype. */
	public static function validateContact( array $data ): array {
		if ( '' !== Input::utf8( $data['website'] ?? '', 200 ) ) { throw new \InvalidArgumentException(); }
		$name = Input::plain( $data['name'] ?? '', 120 );
		$email = Input::utf8( $data['email'] ?? '', 254 );
		if ( ! is_email( $email ) ) { throw new \InvalidArgumentException(); }
		$message = sanitize_textarea_field( Input::utf8( $data['message'] ?? '', 12000 ) );
		if ( '' === trim( $message ) || preg_match_all( '/./us', $message ) > 3000 ) { throw new \InvalidArgumentException(); }
		$github_url = self::githubUrl( $data['github_url'] ?? '' );
		return array( 'name' => $name, 'email' => sanitize_email( $email ), 'message' => $message, 'github_url' => $github_url );
	}

	public function receive(): never {
		$locale = 'zh_CN'; $kind = 'contact';
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Submit this form using POST.', 'ai-skills-hub' ), '', array( 'response' => 405 ) ); }
		try {
			$data = wp_unslash( $_POST );
			if ( ! is_string( $data['locale'] ?? null ) || ! in_array( $data['locale'], array( 'zh_CN', 'en_US' ), true ) || ! is_string( $data['kind'] ?? null ) || ! in_array( $data['kind'], array( 'contact', 'submit' ), true ) ) { throw new \InvalidArgumentException(); }
			$locale = $data['locale']; $kind = $data['kind'];
			// Registered submissions have one verified, ownership-aware entry point.
			// This historical endpoint remains available for private Contact messages only.
			if ( 'submit' === $kind ) { self::redirect( $locale, $kind, 'account_required' ); }
			$nonce = $data['_wpnonce'] ?? null;
			if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'aish_public_message' ) ) { self::redirect( $locale, $kind, 'expired' ); }
			$validated = self::validateContact( $data );
			[ 'name' => $name, 'email' => $email, 'message' => $message, 'github_url' => $github_url ] = $validated;
			if ( ! $this->rateLimit() ) { self::redirect( $locale, $kind, 'limited' ); }
			$this->storeMessage( array(
				'post_type' => self::POST_TYPE, 'post_status' => 'private', 'post_author' => 0,
				'post_title' => ( 'submit' === $kind ? '[Skill] ' : '[Contact] ' ) . $name,
				'post_content' => $message, 'comment_status' => 'closed', 'ping_status' => 'closed',
				'meta_input' => array( '_aish_message_kind' => $kind, '_aish_message_locale' => $locale, '_aish_sender_name' => $name, '_aish_sender_email' => sanitize_email( $email ), '_aish_github_url' => $github_url, '_aish_message_state' => 'pending' ),
			) );
			self::redirect( $locale, $kind, 'sent' );
		} catch ( \InvalidArgumentException $error ) { self::redirect( $locale, $kind, 'invalid' ); }
		catch ( \Throwable $error ) { self::redirect( $locale, $kind, 'error' ); }
	}
}
