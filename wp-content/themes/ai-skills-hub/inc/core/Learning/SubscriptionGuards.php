<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;

use AISkillsHub\Core\Database\Database;
defined( 'ABSPATH' ) || exit;

/** Consent and issue state are mutated only by the domain service, never native profile/editor APIs. */
final class SubscriptionGuards {
	private array $postTypes = array();
	public function __construct( private Database $db, private Subscriptions $subscriptions ) {}
	private static function key( string $key ): bool { return str_starts_with( strtolower( $key ), Subscriptions::META ); }
	private static function writing(): bool { return SubscriptionCampaigns::writing(); }
	public function register(): void {
		foreach ( array( 'clean_post_cache', 'wp_insert_post', 'post_updated', 'deleted_post' ) as $hook ) { add_action( $hook, array( $this, 'clearPostTypes' ), PHP_INT_MAX, 0 ); }
		foreach ( array( 'add_user_metadata', 'update_user_metadata', 'delete_user_metadata' ) as $hook ) { add_filter( $hook, array( $this, 'userMeta' ), PHP_INT_MAX, 5 ); }
		add_filter( 'get_user_metadata', array( $this, 'readUserMeta' ), PHP_INT_MAX, 5 );
		foreach ( array( 'update_user_metadata_by_mid', 'delete_user_metadata_by_mid', 'get_user_metadata_by_mid' ) as $hook ) { add_filter( $hook, array( $this, 'userMetaById' ), PHP_INT_MAX, 4 ); }
		add_filter( 'is_protected_meta', static fn( bool $protected, string $key, string $type ): bool => 'user' === $type && self::key( $key ) ? true : $protected, PHP_INT_MAX, 3 );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'rejectWrite' ), PHP_INT_MAX, 2 );
		add_filter( 'map_meta_cap', array( $this, 'caps' ), PHP_INT_MAX, 4 );
		add_filter( 'posts_where', array( $this, 'lists' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_count_posts', array( $this, 'counts' ), PHP_INT_MAX, 3 );
		foreach ( array( 'pre_delete_post', 'pre_trash_post', 'pre_untrash_post' ) as $hook ) { add_filter( $hook, array( $this, 'deletePost' ), PHP_INT_MAX, 3 ); }
		foreach ( array( 'add_post_metadata', 'update_post_metadata' ) as $hook ) { add_filter( $hook, array( $this, 'postMeta' ), PHP_INT_MAX, 5 ); }
		add_filter( 'delete_post_metadata', array( $this, 'deletePostMeta' ), PHP_INT_MAX, 5 );
		add_filter( 'get_post_metadata', array( $this, 'readPostMeta' ), PHP_INT_MAX, 5 );
		foreach ( array( 'update_post_metadata_by_mid', 'delete_post_metadata_by_mid', 'get_post_metadata_by_mid' ) as $hook ) { add_filter( $hook, array( $this, 'postMetaById' ), PHP_INT_MAX, 4 ); }
		add_filter( 'rest_pre_insert_' . SubscriptionCampaigns::TYPE, static fn() => new \WP_Error( 'aish_subscription_private', 'Use the newsletter management workflow.', array( 'status' => 403 ) ), PHP_INT_MAX );
		add_filter( 'rest_prepare_' . SubscriptionCampaigns::TYPE, static fn() => new \WP_Error( 'aish_subscription_private', 'Private newsletter issue.', array( 'status' => 403 ) ), PHP_INT_MAX );
		add_action( 'export_wp', array( $this, 'export' ) );
		add_filter( 'pre_update_option', static fn( mixed $value, string $option, mixed $old ): mixed => 0 === strcasecmp( $option, Subscriptions::OPTION ) ? $old : $value, PHP_INT_MAX, 3 );
		// Core has no short-circuit filter for add/delete_option. Throw before its SQL;
		// domain writes use bound DAL queries and are unaffected by these native hooks.
		foreach ( array( 'add_option', 'delete_option' ) as $hook ) { add_action( $hook, array( $this, 'optionMutation' ), PHP_INT_MAX, 1 ); }
	}
	public function userMeta( mixed $value, int $id, string $key, mixed $data = null, mixed $previous = null ): mixed { return self::key( $key ) ? false : $value; }
	public function readUserMeta( mixed $value, int $id, string $key, bool $single = false, string $type = 'user' ): mixed {
		if ( self::key( $key ) ) { return $single ? '' : array(); }
		if ( '' !== $key ) { return $value; }
		try {
			if ( null === $value ) {
				// Copy cached raw metadata; never remove consent state from a shared cache.
				$meta = wp_cache_get( $id, 'user_meta' );
				if ( ! is_array( $meta ) ) { $meta = array(); foreach ( $this->db->rows( 'SELECT meta_key,meta_value FROM ' . $this->db->connection()->usermeta . ' WHERE user_id=%d ORDER BY umeta_id', array( $id ) ) as $row ) { $meta[ $row['meta_key'] ][] = $row['meta_value']; } }
			} elseif ( is_array( $value ) ) { $meta = $value; } else { return false; }
			foreach ( array_keys( $meta ) as $name ) { if ( self::key( (string) $name ) ) { unset( $meta[ $name ] ); } }
			// get_metadata_raw unwraps the first array element for single=true.
			return $single ? array( $meta ) : $meta;
		} catch ( \Throwable $error ) { return $single ? array( array() ) : array(); }
	}
	public function userMetaById( mixed $value, int $mid, mixed $data = null, string|false $newKey = false ): mixed {
		if ( false !== $newKey && self::key( $newKey ) ) { return false; }
		try { $row = $this->db->row( 'SELECT meta_key FROM ' . $this->db->connection()->usermeta . ' WHERE umeta_id=%d', array( $mid ) ); return $row && self::key( $row['meta_key'] ) ? false : $value; } catch ( \Throwable $error ) { return false; }
	}
	public function clearPostTypes(): void { $this->postTypes = array(); }
	private function managed( int $id ): bool {
		if ( $id < 1 ) { return false; }
		if ( array_key_exists( $id, $this->postTypes ) ) { return $this->postTypes[ $id ]; }
		// Ordinary queried posts are already in WordPress's cache: avoid one extra
		// type query per metadata read. Trusted issue writes invalidate that cache.
		$cached = wp_cache_get( $id, 'posts' );
		if ( is_object( $cached ) && (int) ( $cached->ID ?? 0 ) === $id && is_string( $cached->post_type ?? null ) ) {
			if ( SubscriptionCampaigns::TYPE === $cached->post_type ) { return $this->postTypes[ $id ] = true; }
			if ( 'revision' !== $cached->post_type ) { return $this->postTypes[ $id ] = false; }
		}
		$n = $this->db->connection();
		return $this->postTypes[ $id ] = null !== $this->db->value( "SELECT p.ID FROM {$n->posts} p WHERE p.ID=%d AND (p.post_type='aish_mail_issue' OR (p.post_type='revision' AND EXISTS (SELECT 1 FROM {$n->posts} parent WHERE parent.ID=p.post_parent AND parent.post_type='aish_mail_issue'))) LIMIT 1", array( $id ) );
	}
	public function rejectWrite( bool $empty, array $post ): bool {
		if ( $empty || self::writing() ) { return $empty; }
		try { return SubscriptionCampaigns::TYPE === ( $post['post_type'] ?? '' ) || $this->managed( (int) ( $post['ID'] ?? 0 ) ) || ( 'revision' === ( $post['post_type'] ?? '' ) && $this->managed( (int) ( $post['post_parent'] ?? 0 ) ) ); } catch ( \Throwable $error ) { return true; }
	}
	public function caps( array $caps, string $cap, int $user, array $args ): array {
		if ( self::writing() || ! isset( $args[0] ) || ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post', 'do_not_allow' ), true ) ) { return $caps; }
		try { return $this->managed( (int) $args[0] ) ? array( 'do_not_allow' ) : $caps; } catch ( \Throwable $error ) { return array( 'do_not_allow' ); }
	}
	public function lists( string $where, \WP_Query $query ): string {
		if ( self::writing() ) { return $where; } $p = $this->db->connection()->posts;
		return $where . " AND $p.post_type<>'aish_mail_issue' AND NOT ($p.post_type='revision' AND EXISTS (SELECT 1 FROM $p newsletter_parent WHERE newsletter_parent.ID=$p.post_parent AND newsletter_parent.post_type='aish_mail_issue'))";
	}
	public function counts( object $counts, string $type, string $perm ): object {
		if ( SubscriptionCampaigns::TYPE !== $type || self::writing() ) { return $counts; } $safe = clone $counts; foreach ( array_keys( get_object_vars( $safe ) ) as $key ) { $safe->$key = 0; } return $safe;
	}
	public function deletePost( mixed $value, \WP_Post $post, mixed $unused = null ): mixed {
		if ( self::writing() ) { return $value; } try { return $this->managed( (int) $post->ID ) ? false : $value; } catch ( \Throwable $error ) { return false; }
	}
	public function postMeta( mixed $value, int $id, string $key, mixed $data = null, mixed $previous = null ): mixed {
		if ( self::writing() ) { return $value; } try { return $this->managed( $id ) ? false : $value; } catch ( \Throwable $error ) { return false; }
	}
	public function readPostMeta( mixed $value, int $id, string $key, bool $single = false, string $type = 'post' ): mixed {
		if ( self::writing() ) { return $value; } try { return $this->managed( $id ) ? ( $single ? '' : array() ) : $value; } catch ( \Throwable $error ) { return $single ? '' : array(); }
	}
	public function deletePostMeta( mixed $value, int $id, string $key, mixed $data = null, bool $all = false ): mixed {
		if ( self::writing() ) { return $value; }
		try {
			if ( $all ) { $n = $this->db->connection(); if ( null !== $this->db->value( "SELECT 1 FROM {$n->postmeta} m INNER JOIN {$n->posts} p ON p.ID=m.post_id WHERE m.meta_key=%s AND (p.post_type='aish_mail_issue' OR (p.post_type='revision' AND EXISTS (SELECT 1 FROM {$n->posts} parent WHERE parent.ID=p.post_parent AND parent.post_type='aish_mail_issue'))) LIMIT 1", array( $key ) ) ) { return false; } }
			return $this->postMeta( $value, $id, $key, $data );
		} catch ( \Throwable $error ) { return false; }
	}
	public function postMetaById( mixed $value, int $mid, mixed $data = null, string|false $newKey = false ): mixed {
		if ( self::writing() ) { return $value; }
		try { $row = $this->db->row( 'SELECT post_id FROM ' . $this->db->connection()->postmeta . ' WHERE meta_id=%d', array( $mid ) ); return $row ? $this->postMeta( $value, (int) $row['post_id'], '' ) : $value; } catch ( \Throwable $error ) { return false; }
	}
	public function export( array $args ): void {
		$content = $args['content'] ?? 'all';
		if ( SubscriptionCampaigns::TYPE === $content ) { wp_die( 'Private newsletter issues cannot be exported through WXR.', '', array( 'response' => 403 ) ); }
		if ( ! in_array( $content, array( 'all', 'revision' ), true ) ) { return; }
		$type = get_post_type_object( SubscriptionCampaigns::TYPE );
		if ( 'all' === $content && $type && false === $type->can_export ) { return; }
		try { $n = $this->db->connection(); if ( null === $this->db->value( "SELECT 1 FROM {$n->posts} WHERE post_type='aish_mail_issue' LIMIT 1" ) ) { return; } } catch ( \Throwable $error ) {}
		wp_die( 'Private newsletter issues cannot be exported through WXR.', '', array( 'response' => 403 ) );
	}
	public function optionMutation( string $option ): void { if ( 0 === strcasecmp( $option, Subscriptions::OPTION ) ) { throw new \RuntimeException( 'subscription_native_option' ); } }
}
