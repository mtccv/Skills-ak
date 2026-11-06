<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Contributions;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Submissions\Store as SubmissionStore;
use AISkillsHub\Core\Developers\Service as Developers;

defined( 'ABSPATH' ) || exit;

/** Native editors cannot bypass the domain's immutable revisions and ownership checks. */
final class Guards {
	private const PROFILE_KEYS = array( '_aish_profile_revision', '_aish_profile_review_status', '_aish_profile_translations', '_aish_profile_candidate', '_aish_profile_approved_snapshot', '_aish_profile_reviewed_revision', '_aish_profile_reviewer_id', '_aish_profile_reviewed_at', '_aish_profile_feedback', '_aish_profile_private_note', '_aish_profile_decision', '_aish_profile_updated_at', '_aish_contributions_erasing' );
	public function __construct( private Database $db ) {}
	private static function submissionWriter(): bool { return ( class_exists( SubmissionStore::class ) && SubmissionStore::writing() ) || self::privacyWriter(); }
	private static function profileWriter(): bool { return ( class_exists( Developers::class ) && Developers::writing() ) || self::privacyWriter(); }
	private static function privacyWriter(): bool { return class_exists( Privacy::class ) && Privacy::writing(); }
	private static function submissionKey( string $key ): bool {
		return str_starts_with( $key, '_aish_submission_' ) || str_starts_with( $key, '_aish_target_skill_' ) || in_array( $key, array( '_aish_canonical_repository', '_aish_repository_id', '_aish_suggested_source_path', '_aish_result_skill_ids', '_aish_submitted_at', '_aish_resolved_at', '_aish_handler_id', '_aish_user_visible_feedback' ), true );
	}
	/** Any marker is protected, including conflicting/corrupt metadata; never infer ownership here. */
	public function managedPost( int $id ): bool {
		if ( $id < 1 ) { return false; }
		$c = $this->db->connection();
		return null !== $this->db->value( "SELECT p.ID FROM {$c->posts} p WHERE p.ID=%d AND ((p.post_type='aish_message' AND EXISTS (SELECT 1 FROM {$c->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_aish_submission_version')) OR (p.post_type='revision' AND EXISTS (SELECT 1 FROM {$c->posts} parent INNER JOIN {$c->postmeta} m ON m.post_id=parent.ID AND m.meta_key='_aish_submission_version' WHERE parent.ID=p.post_parent AND parent.post_type='aish_message'))) LIMIT 1", array( $id ) );
	}
	public function register(): void {
		add_filter( 'wp_insert_post_empty_content', array( $this, 'rejectNativeWrite' ), PHP_INT_MAX, 2 );
		add_filter( 'map_meta_cap', array( $this, 'capabilities' ), PHP_INT_MAX, 4 );
		foreach ( array( 'pre_delete_post', 'pre_trash_post', 'pre_untrash_post' ) as $hook ) { add_filter( $hook, array( $this, 'deletePost' ), PHP_INT_MAX, 3 ); }
		foreach ( array( 'add_post_metadata', 'update_post_metadata' ) as $hook ) { add_filter( $hook, array( $this, 'postMeta' ), PHP_INT_MAX, 5 ); }
		add_filter( 'delete_post_metadata', array( $this, 'deletePostMeta' ), PHP_INT_MAX, 5 );
		add_filter( 'update_post_metadata_by_mid', array( $this, 'postMetaById' ), PHP_INT_MAX, 4 );
		add_filter( 'delete_post_metadata_by_mid', array( $this, 'postMetaById' ), PHP_INT_MAX, 2 );
		foreach ( array( 'add_user_metadata', 'update_user_metadata' ) as $hook ) { add_filter( $hook, array( $this, 'userMeta' ), PHP_INT_MAX, 5 ); }
		add_filter( 'delete_user_metadata', array( $this, 'deleteUserMeta' ), PHP_INT_MAX, 5 );
		add_filter( 'update_user_metadata_by_mid', array( $this, 'userMetaById' ), PHP_INT_MAX, 4 );
		add_filter( 'delete_user_metadata_by_mid', array( $this, 'deleteUserMetaById' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_pre_insert_user_data', array( $this, 'nativeUser' ), PHP_INT_MAX, 4 );
		add_filter( 'posts_where', array( $this, 'nativeLists' ), PHP_INT_MAX, 2 );
	}
	public function rejectNativeWrite( bool $empty, array $post ): bool {
		if ( $empty || self::submissionWriter() ) { return $empty; }
		try {
			$id = (int) ( $post['ID'] ?? 0 ); $parent = (int) ( $post['post_parent'] ?? 0 );
			if ( $this->managedPost( $id ) || ( 'revision' === ( $post['post_type'] ?? '' ) && $this->managedPost( $parent ) ) ) { return true; }
			foreach ( array_keys( is_array( $post['meta_input'] ?? null ) ? $post['meta_input'] : array() ) as $key ) { if ( self::submissionKey( (string) $key ) ) { return true; } }
		} catch ( \Throwable $error ) { return true; }
		return false;
	}
	public function capabilities( array $caps, string $cap, int $userId, array $args ): array {
		// aish_message's legacy CPT registration maps object meta caps to this primitive
		// before the filter runs. Global manage-settings checks have no object argument.
		if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post', 'aish_manage_settings' ), true ) || ! isset( $args[0] ) || self::submissionWriter() ) { return $caps; }
		try { return $this->managedPost( (int) ( $args[0] ?? 0 ) ) ? array( 'do_not_allow' ) : $caps; }
		catch ( \Throwable $error ) { return array( 'do_not_allow' ); }
	}
	public function deletePost( mixed $value, \WP_Post $post, mixed $unused = null ): mixed {
		if ( self::submissionWriter() ) { return $value; }
		try { return $this->managedPost( (int) $post->ID ) ? false : $value; }
		catch ( \Throwable $error ) { return false; }
	}
	public function postMeta( mixed $value, int $id, string $key, mixed $data = null, mixed $previous = null ): mixed {
		if ( self::submissionWriter() ) { return $value; }
		try { return self::submissionKey( $key ) || $this->managedPost( $id ) ? false : $value; }
		catch ( \Throwable $error ) { return false; }
	}
	public function deletePostMeta( mixed $value, int $id, string $key, mixed $data = null, bool $all = false ): mixed {
		if ( self::submissionWriter() ) { return $value; }
		// Bulk deletion cannot select an unchecked set of controlled posts by metadata key.
		if ( $all && ( self::submissionKey( $key ) || str_starts_with( $key, '_aish_' ) ) ) { return false; }
		return $this->postMeta( $value, $id, $key, $data );
	}
	public function postMetaById( mixed $value, int $metaId, mixed $data = null, string|false $newKey = false ): mixed {
		if ( self::submissionWriter() ) { return $value; }
		try {
			$c = $this->db->connection(); $row = $this->db->row( "SELECT post_id,meta_key FROM {$c->postmeta} WHERE meta_id=%d", array( $metaId ) );
			if ( ! $row ) { return $value; }
			if ( false !== $newKey && self::submissionKey( $newKey ) ) { return false; }
			return $this->postMeta( $value, (int) $row['post_id'], $row['meta_key'], $data );
		} catch ( \Throwable $error ) { return false; }
	}
	public function userMeta( mixed $value, int $id, string $key, mixed $data = null, mixed $previous = null ): mixed {
		if ( '_aish_contributions_erasing' === $key ) { return self::privacyWriter() ? $value : false; }
		if ( self::profileWriter() ) { return $value; }
		if ( '_aish_public_profile' === $key ) { return in_array( $data, array( '0', 0, false, '' ), true ) ? $value : false; }
		return in_array( $key, self::PROFILE_KEYS, true ) ? false : $value;
	}
	public function deleteUserMeta( mixed $value, int $id, string $key, mixed $data = null, bool $all = false ): mixed {
		if ( '_aish_public_profile' === $key ) { return $value; } // Removing consent can only hide a profile.
		return $this->userMeta( $value, $id, $key, $data );
	}
	public function userMetaById( mixed $value, int $metaId, mixed $data = null, string|false $newKey = false ): mixed {
		if ( self::profileWriter() ) { return $value; }
		try {
			$c = $this->db->connection(); $row = $this->db->row( "SELECT user_id,meta_key FROM {$c->usermeta} WHERE umeta_id=%d", array( $metaId ) );
			if ( ! $row ) { return $value; }
			if ( false !== $newKey && $newKey !== $row['meta_key'] && ( in_array( $newKey, self::PROFILE_KEYS, true ) || '_aish_public_profile' === $newKey ) ) { return false; }
			return $this->userMeta( $value, (int) $row['user_id'], $row['meta_key'], $data );
		} catch ( \Throwable $error ) { return false; }
	}
	public function deleteUserMetaById( mixed $value, int $metaId ): mixed {
		if ( self::profileWriter() ) { return $value; }
		try {
			$c = $this->db->connection(); $row = $this->db->row( "SELECT user_id,meta_key FROM {$c->usermeta} WHERE umeta_id=%d", array( $metaId ) );
			return $row ? $this->deleteUserMeta( $value, (int) $row['user_id'], $row['meta_key'] ) : $value;
		} catch ( \Throwable $error ) { return false; }
	}
	public function nativeUser( array $data, bool $update, ?int $id, array $input = array() ): array {
		if ( ! $update || ! $id || self::profileWriter() || ! array_key_exists( 'user_nicename', $data ) ) { return $data; }
		$c = $this->db->connection();
		$protected = $this->db->value( "SELECT 1 FROM {$c->usermeta} WHERE user_id=%d AND meta_key='_aish_profile_approved_snapshot' LIMIT 1", array( $id ) );
		if ( $protected ) { $data['user_nicename'] = (string) $this->db->value( "SELECT user_nicename FROM {$c->users} WHERE ID=%d", array( $id ) ); }
		return $data;
	}
	public function nativeLists( string $where, \WP_Query $query ): string {
		if ( ! $query->is_main_query() || self::submissionWriter() ) { return $where; }
		$c = $this->db->connection();
		return $where . " AND NOT EXISTS (SELECT 1 FROM {$c->postmeta} aish_owned WHERE aish_owned.post_id={$c->posts}.ID AND aish_owned.meta_key='_aish_submission_version') AND NOT ({$c->posts}.post_type='revision' AND EXISTS (SELECT 1 FROM {$c->postmeta} aish_parent WHERE aish_parent.post_id={$c->posts}.post_parent AND aish_parent.meta_key='_aish_submission_version'))";
	}
}
