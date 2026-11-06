<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Submissions;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Internal native storage; one site lock and one outer transaction own all writes. */
final class Store {
	public const MARKER = '_aish_submission_version';
	public const META = array( '_aish_submission_version', '_aish_message_kind', '_aish_message_locale', '_aish_github_url', '_aish_submission_state', '_aish_submission_revision', '_aish_submission_request_key', '_aish_submission_payload', '_aish_canonical_repository', '_aish_repository_id', '_aish_suggested_source_path', '_aish_target_skill_id', '_aish_target_skill_revision', '_aish_result_skill_ids', '_aish_submitted_at', '_aish_resolved_at', '_aish_handler_id', '_aish_user_visible_feedback', '_aish_message_notes', '_aish_submission_snapshot_revision', '_aish_submission_snapshot_kind' );
	private static bool $writing = false;
	private array $touched = array();
	public function __construct( private Database $db ) {}
	public static function writing(): bool { return self::$writing; }
	public static function lockName( Database $db ): string { return 'aish_submission:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $db->connection()->prefix ), 0, 46 ); }
	public function transaction( callable $operation ): mixed {
		$mode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		$held = false; $previous = $this->db->connection()->suppress_errors( true );
		$cacheAdd = wp_suspend_cache_addition(); $cacheInvalidate = wp_suspend_cache_invalidation();
		$denyHttp = static fn() => new \WP_Error( 'aish_transaction_network', 'Network operations are unavailable inside a contribution transaction.' );
		$denyMail = static fn() => false;
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( self::lockName( $this->db ) ) );
			if ( ! $held ) { throw new Exception( 'busy' ); }
			foreach ( array( $this->db->connection()->posts, $this->db->connection()->postmeta ) as $table ) {
				$engine = $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) );
				if ( 'innodb' !== strtolower( (string) $engine ) ) { throw new Exception( 'storage_unavailable' ); }
			}
			wp_suspend_cache_addition( true ); wp_suspend_cache_invalidation( true );
			add_filter( 'pre_http_request', $denyHttp, PHP_INT_MAX ); add_filter( 'pre_wp_mail', $denyMail, PHP_INT_MAX );
			$this->touched = array();
			return $this->db->transaction( function () use ( $operation ): mixed { self::$writing = true; return $operation( $this ); } );
		} finally {
			self::$writing = false;
			remove_filter( 'pre_http_request', $denyHttp, PHP_INT_MAX ); remove_filter( 'pre_wp_mail', $denyMail, PHP_INT_MAX );
			wp_suspend_cache_addition( $cacheAdd ); wp_suspend_cache_invalidation( $cacheInvalidate );
			foreach ( array_keys( $this->touched ) as $id ) { clean_post_cache( $id ); wp_cache_delete( $id, 'posts' ); wp_cache_delete( $id, 'post_meta' ); }
			try { if ( $held ) { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( self::lockName( $this->db ) ) ); } }
			finally { $this->db->connection()->suppress_errors( $previous ); if ( null !== $mode ) { mysqli_report( $mode ); } }
		}
	}
	public function meta( int $id ): array {
		$rows = $this->db->rows( 'SELECT meta_key,meta_value FROM ' . $this->db->connection()->postmeta . ' WHERE post_id=%d', array( $id ) ); $meta = array();
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['meta_key'], self::META, true ) ) { continue; }
			if ( array_key_exists( $row['meta_key'], $meta ) ) { throw new Exception( 'storage_unavailable' ); }
			$meta[ $row['meta_key'] ] = $row['meta_value'];
		} return $meta;
	}
	public function set( int $id, string $key, string $value ): void {
		if ( ! self::$writing || ! in_array( $key, self::META, true ) ) { throw new \LogicException( 'Native writes require the contribution transaction.' ); }
		$this->touched[ $id ] = true; wp_cache_delete( $id, 'post_meta' );
		$this->db->connection()->last_error = '';
		update_metadata( 'post', $id, $key, wp_slash( $value ) );
		if ( '' !== $this->db->connection()->last_error ) { throw new Exception( 'storage_unavailable' ); }
		$values = $this->db->rows( 'SELECT meta_value FROM ' . $this->db->connection()->postmeta . ' WHERE post_id=%d AND meta_key=%s', array( $id, $key ) );
		if ( 1 !== count( $values ) || (string) $values[0]['meta_value'] !== $value ) { throw new Exception( 'storage_unavailable' ); }
	}
	public function post( array $record ): int {
		if ( ! self::$writing ) { throw new \LogicException( 'Native writes require the contribution transaction.' ); }
		if ( isset( $record['ID'] ) ) { $this->touched[ (int) $record['ID'] ] = true; clean_post_cache( (int) $record['ID'] ); }
		$this->db->connection()->last_error = '';
		// Native revision snapshots are explicitly created below; automatic partial
		// text snapshots do not contain the controlled source/target fields.
		$revisionHook = has_action( 'post_updated', 'wp_save_post_revision' );
		if ( false !== $revisionHook ) { remove_action( 'post_updated', 'wp_save_post_revision', $revisionHook ); }
		try { $id = wp_insert_post( wp_slash( $record ), true, false ); }
		finally { if ( false !== $revisionHook ) { add_action( 'post_updated', 'wp_save_post_revision', $revisionHook, 1 ); } }
		if ( is_wp_error( $id ) || ! is_int( $id ) || $id < 1 || '' !== $this->db->connection()->last_error ) { throw new Exception( 'storage_unavailable' ); }
		$this->touched[ $id ] = true;
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->connection()->posts . ' WHERE ID=%d', array( $id ) );
		foreach ( $record as $key => $value ) { if ( isset( $row[ $key ] ) && (string) $row[ $key ] !== (string) $value ) { throw new Exception( 'storage_unavailable' ); } }
		if ( ! $row ) { throw new Exception( 'storage_unavailable' ); }
		return $id;
	}
	/** Preserve native text and ownership while updating the native modification time. */
	public function touch( array $post ): void {
		$this->post( array_intersect_key( $post, array_fill_keys( array( 'ID', 'post_type', 'post_status', 'post_author', 'post_title', 'post_excerpt', 'post_content', 'post_name', 'post_parent', 'comment_status', 'ping_status' ), true ) ) );
	}
	public function snapshot( int $id, int $owner, int $revision, array $payload, string $kind = 'content' ): int {
		Input::choice( $kind, array( 'content', 'moderation' ) );
		Input::id( $revision );
		$child = $this->post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_author' => $owner, 'post_title' => $payload['name'] ?? 'Contribution moderation', 'post_content' => (string) wp_json_encode( $payload ), 'post_excerpt' => '', 'post_name' => $id . '-aish-revision-' . $revision, 'comment_status' => 'closed', 'ping_status' => 'closed' ) );
		$this->set( $child, '_aish_submission_snapshot_revision', (string) $revision );
		$this->set( $child, '_aish_submission_snapshot_kind', $kind );
		return $child;
	}
}
