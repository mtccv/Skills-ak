<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Keep native export generation, but account ZIPs are private files with authenticated downloads. */
final class PrivacyExports {
	private ?int $context = null;
	private array $mailLocks = array();
	private const MAX_BYTES = 16777216;
	private const LIFETIME = 259200;

	public function __construct( private Database $database, private Locks $locks ) {}

	public function register(): void {
		add_action( 'wp_privacy_personal_data_export_file', array( $this, 'prepare' ), 1 );
		add_action( 'wp_privacy_personal_data_export_file_created', array( $this, 'created' ), 10, 5 );
		add_filter( 'wp_privacy_exports_dir', fn( string $directory ): string => null !== $this->context ? self::directory() . '/' : $directory );
		add_filter( 'wp_privacy_exports_url', fn( string $url ): string => null !== $this->context ? add_query_arg( array( 'action' => 'aish_account_export', 'request' => $this->context ), admin_url( 'admin-post.php' ) ) . '&file=' : $url );
		add_action( 'admin_post_aish_account_export', array( $this, 'download' ) );
		add_action( 'admin_post_nopriv_aish_account_export', array( $this, 'download' ) );
		add_action( Privacy::CRON, array( $this, 'cleanup' ), 20 );
		add_filter( 'wp_privacy_personal_data_email_to', array( $this, 'mailRecipient' ), 100, 2 );
		add_filter( 'wp_privacy_personal_data_email_subject', function ( string $subject, string $site, array $data ): string {
			$id = (int) ( $data['request']->ID ?? 0 );
			if ( ! get_post_meta( $id, '_aish_export_file', true ) ) { return $subject; }
			$user = self::bound( $id );
			return $user && 'zh_CN' === get_user_meta( $user->ID, '_aish_profile_locale', true ) ? 'AI Skills Hub：账户数据导出' : 'AI Skills Hub: Account data export';
		}, 100, 3 );
		add_filter( 'wp_privacy_personal_data_email_content', array( $this, 'mailContent' ), 100, 3 );
		$releaseMail = function (): void { foreach ( array_reverse( $this->mailLocks ) as $release ) { $release(); } $this->mailLocks = array(); };
		add_action( 'wp_mail_succeeded', $releaseMail, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $releaseMail, PHP_INT_MAX );
		add_action( 'shutdown', $releaseMail, PHP_INT_MAX );
	}

	/** Recheck the pinned recipient even when a native resend runs in a separate request. */
	public function mailRecipient( string $email, \WP_User_Request $request ): string {
		if ( ! get_post_meta( $request->ID, '_aish_request_user_id', true ) ) { return $email; }
		try {
			$user = self::bound( (int) $request->ID );
			if ( ! $user || ! current_user_can( 'export_others_personal_data' ) ) { return ''; }
			$this->mailLocks[] = $this->locks->acquire( array( $user->user_email ), (int) $user->ID );
			$user = self::bound( (int) $request->ID );
			if ( ! $user ) { return ''; }
			$this->authorizedFile( (int) $request->ID, (string) get_post_meta( $request->ID, '_aish_export_file', true ) );
			return (string) $user->user_email;
		} catch ( \Throwable $error ) { return ''; }
	}

	public function mailContent( string $content, int $requestId, array $data ): string {
		if ( ! get_post_meta( $requestId, '_aish_request_user_id', true ) ) { return $content; }
		try {
			$user = self::bound( $requestId );
			if ( ! $user ) { return 'This account export is no longer available.'; }
			$name = self::fileName( get_post_meta( $requestId, '_aish_export_file', true ) );
			$this->authorizedFile( $requestId, $name );
			$url = add_query_arg( array( 'action' => 'aish_account_export', 'request' => $requestId, 'file' => $name ), admin_url( 'admin-post.php' ) );
			$expires = gmdate( 'Y-m-d H:i:s', (int) get_post_meta( $requestId, '_aish_export_expires_at', true ) ) . ' UTC';
			return 'zh_CN' === get_user_meta( $user->ID, '_aish_profile_locale', true )
				? "您的账户数据导出文件已生成。请先登录您本人的 AI Skills Hub 账户，再打开以下链接。链接有效期至 {$expires}。\n\n{$url}\n\n请勿转发此邮件。"
				: "Your account data export has been generated. Sign in to your own AI Skills Hub account before opening the link below. The link expires at {$expires}.\n\n{$url}\n\nDo not forward this email.";
		} catch ( \Throwable $error ) { return 'This account export is no longer available.'; }
	}

	/** A server-controlled path; no request may choose a directory or filename. */
	public static function directory(): string {
		$path = defined( 'AISH_ACCOUNT_EXPORT_DIR' ) ? constant( 'AISH_ACCOUNT_EXPORT_DIR' ) : sys_get_temp_dir() . '/aish-private-exports-' . substr( hash_hmac( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . ABSPATH, wp_salt( 'auth' ) ), 0, 24 );
		if ( ! is_string( $path ) || ! str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) || str_contains( $path, '\\' ) || preg_match( '~(?:^|/)\.\.?(/|$)~', $path ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		$path = rtrim( $path, '/' ); $parent = realpath( dirname( $path ) );
		$systemTemporary = realpath( sys_get_temp_dir() );
		if ( '' === $path || ( false !== $systemTemporary && $path === $systemTemporary ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		if ( false === $parent || $parent !== dirname( $path ) || is_link( $path ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		foreach ( array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $webRoot ) {
			$root = is_string( $webRoot ) && '' !== $webRoot ? realpath( $webRoot ) : false;
			if ( false !== $root && ( $path === $root || str_starts_with( $path . '/', rtrim( $root, '/' ) . '/' ) || str_starts_with( rtrim( $root, '/' ) . '/', $path . '/' ) ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		}
		if ( ! is_dir( $path ) && ! @mkdir( $path, 0700 ) && ! is_dir( $path ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		if ( is_link( $path ) || realpath( $path ) !== $path || ! @chmod( $path, 0700 ) || ! is_writable( $path ) || ( fileperms( $path ) & 0777 ) !== 0700 ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		return $path;
	}

	private static function fileName( mixed $name ): string {
		if ( ! is_string( $name ) || ! preg_match( '/^aish-[a-f0-9]{64}\.zip$/D', $name ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		return $name;
	}

	private static function bound( int $id ): ?\WP_User {
		clean_post_cache( $id ); wp_cache_delete( $id, 'post_meta' );
		$request = wp_get_user_request( $id );
		$ids = get_post_meta( $id, '_aish_request_user_id', false );
		if ( ! $request || 'export_personal_data' !== $request->action_name || 1 !== count( $ids ) || ! ctype_digit( (string) $ids[0] ) || (int) $ids[0] < 1 || ! in_array( $request->status, array( 'request-confirmed', 'request-completed' ), true ) || get_post_meta( $id, '_aish_request_invalidated', true ) ) { return null; }
		clean_user_cache( (int) $ids[0] ); wp_cache_delete( (int) $ids[0], 'user_meta' );
		$user = get_userdata( (int) $ids[0] );
		return $user instanceof \WP_User && strtolower( $request->email ) === strtolower( $user->user_email ) && '1' !== get_user_meta( $user->ID, '_aish_account_deletion_pending', true ) ? $user : null;
	}

	/** Runs before WordPress opens its temporary HTML/JSON reports or archive. */
	public function prepare( int $requestId ): void {
		$this->context = null;
		if ( ! get_post_meta( $requestId, '_aish_request_user_id', true ) ) { return; }
		if ( ! current_user_can( 'export_others_personal_data' ) || ! self::bound( $requestId ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		self::directory();
		$this->context = $requestId;
		$name = get_post_meta( $requestId, '_aish_export_file', true );
		try { $name = self::fileName( $name ); } catch ( \Throwable $error ) { $name = 'aish-' . bin2hex( random_bytes( 32 ) ) . '.zip'; }
		update_post_meta( $requestId, '_export_file_name', $name );
		update_post_meta( $requestId, '_aish_export_file', $name );
		update_post_meta( $requestId, '_aish_export_expires_at', '0' );
		wp_cache_delete( $requestId, 'post_meta' );
		if ( get_post_meta( $requestId, '_export_file_name', true ) !== $name || get_post_meta( $requestId, '_aish_export_file', true ) !== $name || '0' !== get_post_meta( $requestId, '_aish_export_expires_at', true ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
	}

	public function created( string $archive, string $url, string $html, int $requestId, string $json = '' ): void {
		if ( $this->context !== $requestId ) { return; }
		$expected = self::directory() . '/' . self::fileName( get_post_meta( $requestId, '_aish_export_file', true ) );
		if ( ! self::bound( $requestId ) || $archive !== $expected || is_link( $archive ) || ! is_file( $archive ) || filesize( $archive ) > self::MAX_BYTES || ! chmod( $archive, 0600 ) ) { self::invalidate( $requestId ); throw new \RuntimeException( 'privacy_export_unavailable' ); }
		update_post_meta( $requestId, '_aish_export_expires_at', (string) ( time() + self::LIFETIME ) );
		wp_cache_delete( $requestId, 'post_meta' );
		if ( (int) get_post_meta( $requestId, '_aish_export_expires_at', true ) <= time() ) { self::invalidate( $requestId ); throw new \RuntimeException( 'privacy_export_unavailable' ); }
	}

	/** Returns a path only after native identity, ownership, expiration and opaque-name checks. */
	public function authorizedFile( int $requestId, string $file ): string {
		$user = self::bound( $requestId );
		if ( ! $user || ! is_user_logged_in() || ( get_current_user_id() !== (int) $user->ID && ! current_user_can( 'export_others_personal_data' ) ) ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		$name = self::fileName( get_post_meta( $requestId, '_aish_export_file', true ) );
		if ( ! hash_equals( $name, $file ) || (int) get_post_meta( $requestId, '_aish_export_expires_at', true ) < time() ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		$path = self::directory() . '/' . $name;
		if ( is_link( $path ) || ! is_file( $path ) || realpath( $path ) !== $path || filesize( $path ) > self::MAX_BYTES ) { throw new \RuntimeException( 'privacy_export_unavailable' ); }
		return $path;
	}

	public function download(): never {
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Referrer-Policy: no-referrer' );
		try {
			$id = $_GET['request'] ?? ''; $file = $_GET['file'] ?? '';
			if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) || ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id < 1 || ! is_string( $file ) ) { throw new \RuntimeException(); }
			$user = self::bound( (int) $id ); if ( ! $user ) { throw new \RuntimeException(); }
			$this->locks->withUser( (int) $user->ID, function () use ( $id, $file ): void {
				$path = $this->authorizedFile( (int) $id, $file );
				header( 'Content-Type: application/zip' ); header( 'Content-Disposition: attachment; filename="account-data.zip"' ); header( 'Content-Length: ' . filesize( $path ) );
				if ( 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) { readfile( $path ); }
			} );
		} catch ( \Throwable $error ) { status_header( 404 ); }
		exit;
	}

	public static function invalidate( int $requestId ): void {
		try {
			$name = self::fileName( get_post_meta( $requestId, '_aish_export_file', true ) ); $path = self::directory() . '/' . $name;
			if ( is_file( $path ) && ! is_link( $path ) ) { @unlink( $path ); }
		} catch ( \Throwable $error ) {}
		foreach ( array( '_export_file_name', '_export_file_url', '_export_file_path', '_export_data_raw', '_export_data_grouped', '_aish_export_file', '_aish_export_expires_at' ) as $key ) { delete_post_meta( $requestId, $key ); }
	}

	public function cleanup(): void {
		$locked = false;
		$lock = 'aish-export-cleanup-' . substr( hash( 'sha256', $this->database->prefix() . ':' . DB_NAME ), 0, 40 );
		try {
			$locked = 1 === (int) $this->database->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) );
			if ( ! $locked ) { return; }
			$directory = self::directory(); $processed = 0;
			$cursor = max( 0, (int) get_option( 'aish_account_export_cleanup_cursor', 0 ) );
			$iterator = new \DirectoryIterator( $directory );
			try { $iterator->seek( $cursor ); } catch ( \OutOfBoundsException $error ) { $iterator->rewind(); $cursor = 0; }
			while ( $iterator->valid() && $processed < 50 ) {
				if ( ! $iterator->isDot() && ! $iterator->isLink() && $iterator->isFile() && $iterator->getMTime() < time() - self::LIFETIME ) { @unlink( $iterator->getPathname() ); }
				++$processed; ++$cursor; $iterator->next();
			}
			update_option( 'aish_account_export_cleanup_cursor', $iterator->valid() ? $cursor : 0, false );
		} catch ( \Throwable $error ) {}
		finally { if ( $locked ) { try { $this->database->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } catch ( \Throwable $error ) {} } }
	}
}
