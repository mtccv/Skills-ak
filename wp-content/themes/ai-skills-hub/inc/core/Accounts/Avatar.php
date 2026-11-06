<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Private, bounded native attachments. Image bytes never have a public uploads URL. */
final class Avatar {
	public const MAX_UPLOAD = 2097152;
	public const MAX_STORED = 262144;
	private const OWNER = '_aish_avatar_owner';
	private const BYTES = '_aish_avatar_bytes';
	private const POINTER = '_aish_avatar_attachment_id';

	public function __construct( private Database $database, private Locks $locks, private AccountService $accounts ) {}

	public function register(): void {
		add_action( 'admin_post_aish_account_avatar', array( $this, 'serve' ) );
		add_action( 'admin_post_nopriv_aish_account_avatar', array( $this, 'serve' ) );
		add_filter( 'pre_get_avatar_data', array( $this, 'nativeAvatar' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', function ( string $url, int $id ): string {
			return get_post_meta( $id, self::OWNER, true ) ? '' : $url;
		}, 10, 2 );
		add_filter( 'rest_pre_dispatch', function ( mixed $response, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
			if ( preg_match( '~^/wp/v2/media/([1-9][0-9]*)(?:/.*)?$~D', $request->get_route(), $matches ) && get_post_meta( (int) $matches[1], self::OWNER, true ) ) { return new \WP_Error( 'rest_not_found', 'Not found.', array( 'status' => 404 ) ); }
			return $response;
		}, 10, 3 );
		add_filter( 'rest_attachment_query', static function ( array $args ): array {
			$args['meta_query'] = array( 'relation' => 'AND', $args['meta_query'] ?? array(), array( 'key' => self::OWNER, 'compare' => 'NOT EXISTS' ) );
			return $args;
		} );
		add_action( 'template_redirect', static function (): void {
			if ( is_attachment() && get_post_meta( get_queried_object_id(), self::OWNER, true ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 ); nocache_headers();
			}
		}, 0 );
	}

	/** A real decoder and a fresh canvas remove metadata and do not preserve embedded payloads. */
	public static function normalize( string $bytes ): string {
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_UPLOAD ) { throw new \RuntimeException( 'avatar_invalid' ); }
		$info = @getimagesizefromstring( $bytes );
		if ( ! is_array( $info ) || ! in_array( $info['mime'] ?? '', array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 10000000 ) { throw new \RuntimeException( 'avatar_invalid' ); }
		if ( ! function_exists( 'imagecreatefromstring' ) ) { throw new \RuntimeException( 'avatar_unavailable' ); }
		$source = @imagecreatefromstring( $bytes );
		if ( false === $source ) { throw new \RuntimeException( 'avatar_invalid' ); }
		$target = null;
		try {
			$scale = min( 1, 256 / max( $info[0], $info[1] ) );
			$width = max( 1, (int) floor( $info[0] * $scale ) ); $height = max( 1, (int) floor( $info[1] * $scale ) );
			$target = imagecreatetruecolor( $width, $height );
			if ( false === $target ) { throw new \RuntimeException( 'avatar_unavailable' ); }
			imagealphablending( $target, false ); imagesavealpha( $target, true );
			imagefilledrectangle( $target, 0, 0, $width, $height, imagecolorallocatealpha( $target, 0, 0, 0, 127 ) );
			if ( ! imagecopyresampled( $target, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1] ) ) { throw new \RuntimeException( 'avatar_invalid' ); }
			ob_start();
			try { $ok = imagepng( $target, null, 8 ); $result = (string) ob_get_contents(); }
			finally { ob_end_clean(); }
			if ( ! $ok || '' === $result || strlen( $result ) > self::MAX_STORED ) { throw new \RuntimeException( 'avatar_invalid' ); }
			return $result;
		} finally {
			imagedestroy( $source );
			if ( $target instanceof \GdImage ) { imagedestroy( $target ); }
		}
	}

	public function upload( array $file ): int {
		$user = $this->accounts->requireOwner();
		if ( ! is_int( $file['error'] ?? null ) || UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || ! is_int( $file['size'] ?? null ) || $file['size'] < 1 || $file['size'] > self::MAX_UPLOAD ) { throw new \RuntimeException( 'avatar_invalid' ); }
		$bytes = file_get_contents( $file['tmp_name'], false, null, 0, self::MAX_UPLOAD + 1 );
		if ( ! is_string( $bytes ) ) { throw new \RuntimeException( 'avatar_invalid' ); }
		$encoded = base64_encode( self::normalize( $bytes ) );
		return $this->locks->withUser( (int) $user->ID, function () use ( $user, $encoded ): int {
			$this->accounts->requireOwner();
			$this->nativeReady();
			$created = 0;
			try {
				$result = $this->database->transaction( function () use ( $user, $encoded, &$created ): int {
					$id = wp_insert_attachment( wp_slash( array( 'post_author' => $user->ID, 'post_title' => 'Account avatar', 'post_status' => 'private', 'post_mime_type' => 'image/png', 'post_content' => '', 'post_excerpt' => '', 'comment_status' => 'closed', 'ping_status' => 'closed' ) ), false, 0, true );
					if ( is_wp_error( $id ) || $id < 1 ) { throw new \RuntimeException( 'avatar_unavailable' ); }
					$created = (int) $id;
					if ( false === add_post_meta( $created, self::OWNER, (int) $user->ID, true ) || false === add_post_meta( $created, self::BYTES, $encoded, true ) ) { throw new \RuntimeException( 'avatar_unavailable' ); }
					delete_user_meta( $user->ID, self::POINTER );
					if ( false === add_user_meta( $user->ID, self::POINTER, $created, true ) ) { throw new \RuntimeException( 'avatar_unavailable' ); }
					$db = $this->database->connection();
					$stored = $this->database->row( "SELECT post_author,post_type,post_status,post_mime_type FROM {$db->posts} WHERE ID=%d", array( $created ) );
					$owners = $this->database->rows( "SELECT meta_value FROM {$db->postmeta} WHERE post_id=%d AND meta_key=%s", array( $created, self::OWNER ) );
					$payloads = $this->database->rows( "SELECT meta_value FROM {$db->postmeta} WHERE post_id=%d AND meta_key=%s", array( $created, self::BYTES ) );
					$pointers = $this->database->rows( "SELECT meta_value FROM {$db->usermeta} WHERE user_id=%d AND meta_key=%s", array( $user->ID, self::POINTER ) );
					if ( ! $stored || (int) $stored['post_author'] !== (int) $user->ID || 'attachment' !== $stored['post_type'] || 'private' !== $stored['post_status'] || 'image/png' !== $stored['post_mime_type'] || array( (string) $user->ID ) !== array_column( $owners, 'meta_value' ) || array( $encoded ) !== array_column( $payloads, 'meta_value' ) || array( (string) $created ) !== array_column( $pointers, 'meta_value' ) ) { throw new \RuntimeException( 'avatar_unavailable' ); }
					return $created;
				} );
			} finally {
				clean_user_cache( $user->ID ); wp_cache_delete( $user->ID, 'user_meta' );
				if ( $created ) { clean_post_cache( $created ); wp_cache_delete( $created, 'post_meta' ); }
			}
			// A failed old-avatar removal remains private and is retried on replacement or privacy cleanup.
			try { $this->purgeOwned( (int) $user->ID, 10, $result ); } catch ( \Throwable $error ) {}
			return $result;
		} );
	}

	public function remove(): void {
		$user = $this->accounts->requireOwner();
		$this->locks->withUser( (int) $user->ID, function () use ( $user ): void {
			$this->accounts->requireOwner();
			delete_user_meta( $user->ID, self::POINTER );
			wp_cache_delete( $user->ID, 'user_meta' );
			if ( get_user_meta( $user->ID, self::POINTER, true ) ) { throw new \RuntimeException( 'avatar_unavailable' ); }
			$this->purgeOwned( (int) $user->ID, 25 );
		} );
	}

	/** The endpoint derives the owner from the session, never from a request user/attachment ID. */
	public function url( ?int $userId = null ): string {
		$id = $userId ?? get_current_user_id();
		if ( $id < 1 || $id !== get_current_user_id() || ! $this->accounts->verified( $id ) ) { return ''; }
		$attachment = (int) get_user_meta( $id, self::POINTER, true );
		if ( ! $this->owned( $attachment, $id ) ) { return ''; }
		return add_query_arg( 'action', 'aish_account_avatar', admin_url( 'admin-post.php' ) );
	}

	public function serve(): never {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Referrer-Policy: no-referrer' );
		try {
			if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) { throw new \RuntimeException(); }
			$user = $this->accounts->requireOwner();
			$attachment = (int) get_user_meta( $user->ID, self::POINTER, true );
			if ( ! $this->owned( $attachment, (int) $user->ID ) ) { throw new \RuntimeException(); }
			$encoded = get_post_meta( $attachment, self::BYTES, true );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > 4 * self::MAX_STORED / 3 + 8 ) { throw new \RuntimeException(); }
			$bytes = base64_decode( $encoded, true );
			if ( ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_STORED || ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) { throw new \RuntimeException(); }
			header( 'Content-Type: image/png' ); header( 'Content-Length: ' . strlen( $bytes ) ); header( 'Content-Disposition: inline; filename="avatar.png"' );
			if ( 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) { echo $bytes; }
		} catch ( \Throwable $error ) { status_header( 404 ); }
		exit;
	}

	public function nativeAvatar( array $args, mixed $subject ): array {
		$id = $subject instanceof \WP_User ? (int) $subject->ID : ( is_numeric( $subject ) ? (int) $subject : 0 );
		if ( $subject instanceof \WP_Comment ) { $id = (int) $subject->user_id; }
		elseif ( $subject instanceof \WP_Post ) { $id = (int) $subject->post_author; }
		elseif ( is_string( $subject ) && is_email( $subject ) ) { $user = get_user_by( 'email', $subject ); $id = $user instanceof \WP_User ? (int) $user->ID : 0; }
		if ( $id > 0 && get_user_meta( $id, '_aish_account_state', true ) ) {
			$args['url'] = $this->url( $id ); $args['found_avatar'] = '' !== $args['url'];
		}
		return $args;
	}

	private function owned( int $id, int $owner ): bool {
		if ( $id < 1 || $owner < 1 ) { return false; }
		$post = get_post( $id );
		return $post instanceof \WP_Post && 'attachment' === $post->post_type && 'private' === $post->post_status && (int) $post->post_author === $owner && array( (string) $owner ) === get_post_meta( $id, self::OWNER, false );
	}

	/** Used by the native, permission-checked personal data exporter. */
	public function export( int $owner ): string {
		if ( $owner !== get_current_user_id() && ! current_user_can( 'export_others_personal_data' ) ) { throw new \RuntimeException( 'account_unavailable' ); }
		$id = (int) get_user_meta( $owner, self::POINTER, true );
		if ( ! $this->owned( $id, $owner ) ) { return ''; }
		$value = get_post_meta( $id, self::BYTES, true );
		return is_string( $value ) && strlen( $value ) <= 4 * self::MAX_STORED / 3 + 8 ? $value : '';
	}

	/** Trusted cleanup caller must hold the user lock. Never follows an arbitrary attachment pointer. */
	public function purgeOwned( int $owner, int $limit = 25, int $keep = 0 ): bool {
		if ( $owner < 1 ) { return true; }
		$limit = max( 1, min( 50, $limit ) );
		$db = $this->database->connection();
		$rows = $this->database->rows( "SELECT p.ID,p.post_author FROM {$db->posts} p INNER JOIN {$db->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s WHERE p.post_type='attachment' AND p.post_status='private' AND m.meta_value=%s AND p.ID<>%d ORDER BY p.ID LIMIT %d", array( self::OWNER, (string) $owner, $keep, $limit ) );
		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			if ( ! in_array( (int) $row['post_author'], array( 0, $owner ), true ) ) { throw new \RuntimeException( 'avatar_owner_conflict' ); }
			$owners = get_post_meta( $id, self::OWNER, false );
			if ( array( (string) $owner ) !== $owners ) { throw new \RuntimeException( 'avatar_owner_conflict' ); }
			$shared = (int) $this->database->value( "SELECT COUNT(*) FROM {$db->usermeta} WHERE meta_key=%s AND meta_value=%s AND user_id<>%d", array( self::POINTER, (string) $id, $owner ) );
			if ( $shared > 0 ) { throw new \RuntimeException( 'avatar_owner_conflict' ); }
			if ( ! wp_delete_attachment( $id, true ) ) { throw new \RuntimeException( 'avatar_unavailable' ); }
		}
		return count( $rows ) < $limit;
	}

	private function nativeReady(): void {
		$db = $this->database->connection();
		foreach ( array( $db->posts, $db->postmeta, $db->usermeta ) as $table ) {
			$engine = $this->database->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) );
			if ( 'innodb' !== strtolower( (string) $engine ) ) { throw new \RuntimeException( 'avatar_unavailable' ); }
		}
	}
}
