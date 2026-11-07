<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;
use AISkillsHub\Core\Database\Database;
defined( 'ABSPATH' ) || exit;

/** Private subtype protection also applies to the legacy native Inbox and editor. */
final class EnterpriseGuards {
	public function __construct( private Database $db ) {}
	public function managed( int $id ): bool {
		if ( $id < 1 ) { return false; } $n = $this->db->connection();
		return null !== $this->db->value( "SELECT p.ID FROM {$n->posts} p WHERE p.ID=%d AND ((p.post_type='aish_message' AND EXISTS (SELECT 1 FROM {$n->postmeta} em WHERE em.post_id=p.ID AND em.meta_key='_aish_enterprise_version')) OR (p.post_type='revision' AND EXISTS (SELECT 1 FROM {$n->postmeta} em WHERE em.post_id=p.post_parent AND em.meta_key='_aish_enterprise_version'))) LIMIT 1", array( $id ) );
	}
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'caps' ), PHP_INT_MAX, 4 );
		add_filter( 'posts_where', array( $this, 'lists' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_count_posts', array( $this, 'counts' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'rejectWrite' ), PHP_INT_MAX, 2 );
		foreach ( array( 'pre_delete_post', 'pre_trash_post', 'pre_untrash_post' ) as $hook ) { add_filter( $hook, array( $this, 'deletePost' ), PHP_INT_MAX, 3 ); }
		foreach ( array( 'add_post_metadata', 'update_post_metadata' ) as $hook ) { add_filter( $hook, array( $this, 'meta' ), PHP_INT_MAX, 5 ); }
		add_filter( 'delete_post_metadata', array( $this, 'deleteMeta' ), PHP_INT_MAX, 5 );
		add_filter( 'update_post_metadata_by_mid', array( $this, 'metaById' ), PHP_INT_MAX, 4 ); add_filter( 'delete_post_metadata_by_mid', array( $this, 'metaById' ), PHP_INT_MAX, 2 );
		// Native WXR bypasses per-object read checks. Export only a selected public
		// post type unless the operator also holds the explicit inquiry capability.
		add_action( 'export_wp', function ( array $args ): void { if ( ! in_array( $args['content'] ?? 'all', array( 'all', 'aish_message' ), true ) || current_user_can( Enterprise::CAPABILITY ) ) { return; } try { $m = $this->db->connection()->postmeta; if ( null === $this->db->value( "SELECT 1 FROM $m WHERE meta_key='_aish_enterprise_version' LIMIT 1" ) ) { return; } } catch ( \Throwable $error ) { /* Unknown storage state must not expose private text. */ } wp_die( 'Private inquiry export requires the enterprise capability. Select a different content type.', '', array( 'response' => 403 ) ); } );
	}
	public function caps( array $caps, string $cap, int $user, array $args ): array {
		if ( Enterprise::writing() || ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post', 'aish_manage_settings' ), true ) || ! isset( $args[0] ) ) { return $caps; }
		try { return $this->managed( (int) $args[0] ) ? array( 'do_not_allow' ) : $caps; } catch ( \Throwable $error ) { return array( 'do_not_allow' ); }
	}
	public function lists( string $where, \WP_Query $query ): string {
		if ( Enterprise::writing() ) { return $where; } $n = $this->db->connection();
		return $where . " AND NOT EXISTS (SELECT 1 FROM {$n->postmeta} enterprise_private WHERE enterprise_private.post_id={$n->posts}.ID AND enterprise_private.meta_key='_aish_enterprise_version') AND NOT ({$n->posts}.post_type='revision' AND EXISTS (SELECT 1 FROM {$n->postmeta} enterprise_parent WHERE enterprise_parent.post_id={$n->posts}.post_parent AND enterprise_parent.meta_key='_aish_enterprise_version'))";
	}
	/** Native Inbox totals must not disclose the size of the separately protected queue. */
	public function counts( object $counts, string $type, string $perm ): object {
		if ( 'aish_message' !== $type || Enterprise::writing() ) { return $counts; }
		$out = clone $counts; $n = $this->db->connection();
		try { foreach ( $this->db->rows( "SELECT p.post_status,COUNT(*) AS total FROM {$n->posts} p WHERE p.post_type='aish_message' AND EXISTS (SELECT 1 FROM {$n->postmeta} em WHERE em.post_id=p.ID AND em.meta_key='_aish_enterprise_version') GROUP BY p.post_status" ) as $row ) { $status = $row['post_status']; if ( property_exists( $out, $status ) ) { $out->$status = max( 0, (int) $out->$status - (int) $row['total'] ); } } }
		catch ( \Throwable $error ) { foreach ( get_object_vars( $out ) as $key => $value ) { $out->$key = 0; } }
		return $out;
	}

	public function rejectWrite( bool $empty, array $post ): bool {
		if ( $empty || Enterprise::writing() ) { return $empty; }
		try {
			if ( $this->managed( (int) ( $post['ID'] ?? 0 ) ) || ( 'revision' === ( $post['post_type'] ?? '' ) && $this->managed( (int) ( $post['post_parent'] ?? 0 ) ) ) ) { return true; }
			foreach ( is_array( $post['meta_input'] ?? null ) ? $post['meta_input'] : array() as $key => $value ) { if ( str_starts_with( (string) $key, '_aish_enterprise_' ) || ( '_aish_message_kind' === $key && 'enterprise' === $value ) ) { return true; } }
		} catch ( \Throwable $error ) { return true; } return false;
	}
	public function deletePost( mixed $value, \WP_Post $post, mixed $unused = null ): mixed { if ( Enterprise::writing() ) { return $value; } try { return $this->managed( (int) $post->ID ) ? false : $value; } catch ( \Throwable $error ) { return false; } }
	public function meta( mixed $value, int $id, string $key, mixed $data = null, mixed $previous = null ): mixed {
		if ( Enterprise::writing() ) { return $value; }
		try { return str_starts_with( $key, '_aish_enterprise_' ) || ( '_aish_message_kind' === $key && 'enterprise' === $data ) || $this->managed( $id ) ? false : $value; } catch ( \Throwable $error ) { return false; }
	}
	public function deleteMeta( mixed $value, int $id, string $key, mixed $data = null, bool $all = false ): mixed { if ( Enterprise::writing() ) { return $value; } if ( $all && str_starts_with( $key, '_aish_' ) ) { return false; } return $this->meta( $value, $id, $key, $data ); }
	public function metaById( mixed $value, int $metaId, mixed $data = null, string|false $newKey = false ): mixed {
		if ( Enterprise::writing() ) { return $value; } try { $m = $this->db->connection()->postmeta; $row = $this->db->row( "SELECT post_id,meta_key FROM $m WHERE meta_id=%d", array( $metaId ) ); if ( false !== $newKey && str_starts_with( $newKey, '_aish_enterprise_' ) ) { return false; } return $row ? $this->meta( $value, (int) $row['post_id'], $row['meta_key'], $data ) : $value; } catch ( \Throwable $error ) { return false; }
	}
}
