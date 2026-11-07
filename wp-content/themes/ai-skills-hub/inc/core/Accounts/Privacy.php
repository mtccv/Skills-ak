<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** WordPress privacy tools own processing; frontend actions only create authenticated requests. */
final class Privacy {
	public const CRON = 'aish_account_privacy_cleanup';
	private const TARGET = '_aish_request_user_id';
	private const CLEANUP_TARGET = '_aish_cleanup_target';
	private const META = array( '_aish_account_state', '_aish_account_deletion_pending', '_aish_email_verified_at', '_aish_verified_email_hash', '_aish_email_change_context', '_aish_profile_locale', '_aish_avatar_attachment_id', '_aish_public_profile', '_aish_notification_preferences', '_aish_onboarding_version', '_aish_operations_account' );
	private array $requestLocks = array();
	private static ?int $erasureTarget = null;

	public function __construct( private Database $database, private Store $store, private Locks $locks, private AccountService $accounts, private Avatar $avatar ) {}

	public function register(): void {
		( new PrivacyExports( $this->database, $this->locks ) )->register();
		add_action( 'wp_ajax_wp-privacy-export-personal-data', array( $this, 'guardNativeRequest' ), 0 );
		add_action( 'wp_ajax_wp-privacy-erase-personal-data', array( $this, 'guardNativeRequest' ), 0 );
		add_action( 'shutdown', function (): void { foreach ( array_reverse( $this->requestLocks ) as $release ) { $release(); } $this->requestLocks = array(); }, PHP_INT_MAX );
		add_filter( 'wp_privacy_personal_data_exporters', function ( array $exporters ): array {
			$exporters['ai-skills-hub-account'] = array( 'exporter_friendly_name' => 'AI Skills Hub account', 'callback' => function ( string $email, int $page = 1 ): mixed { try { return $this->exporter( $email, $page ); } catch ( \Throwable $error ) { return new \WP_Error( 'aish_privacy_unavailable', __( 'The account request could not be processed. Check its original account and confirmation, or create a new request.', 'ai-skills-hub' ) ); } } );
			return $exporters;
		} );
		add_filter( 'wp_privacy_personal_data_erasers', function ( array $erasers ): array {
			$erasers['ai-skills-hub-account'] = array( 'eraser_friendly_name' => 'AI Skills Hub account', 'callback' => function ( string $email, int $page = 1 ): mixed { try { return $this->eraser( $email, $page ); } catch ( \Throwable $error ) { return new \WP_Error( 'aish_privacy_unavailable', __( 'The account request could not be processed. Check its original account and confirmation, or create a new request.', 'ai-skills-hub' ) ); } } );
			return $erasers;
		} );
		add_action( 'delete_user', array( $this, 'beforeDelete' ), 1, 3 );
		add_action( 'deleted_user', array( $this, 'afterDelete' ), 10, 3 );
		add_action( 'wpmu_delete_user', function ( int $id, \WP_User $user ): void { $this->beforeDelete( $id, null, $user, true ); $this->schedule(); }, 1, 2 );
		add_action( 'save_post_user_request', function ( int $id, \WP_Post $post, bool $update ): void {
			if ( $update || ! in_array( $post->post_name, array( 'export_personal_data', 'remove_personal_data' ), true ) || (int) $post->post_author < 1 ) { return; }
			$user = get_userdata( (int) $post->post_author );
			if ( $user instanceof \WP_User && strtolower( $user->user_email ) === strtolower( $post->post_title ) ) { $this->postMeta( $id, self::TARGET, (string) $user->ID ); }
		}, 10, 3 );
		add_action( 'profile_update', function ( int $id, \WP_User $old ): void {
			$user = get_userdata( $id );
			if ( $user instanceof \WP_User && strtolower( $old->user_email ) !== strtolower( $user->user_email ) ) { $this->invalidateRequests( $id ); }
		}, 100, 2 );
		add_action( self::CRON, array( $this, 'tick' ) );
		add_action( 'admin_init', array( $this, 'schedule' ) );
		add_action( 'switch_theme', static function (): void { wp_clear_scheduled_hook( self::CRON ); } );
	}

	/** Validate before any native exporter/eraser runs, not after a destructive callback. */
	public function guardNativeRequest(): void {
		$action = $_POST['action'] ?? '';
		$erase = 'wp-privacy-erase-personal-data' === $action;
		if ( ! in_array( $action, array( 'wp-privacy-export-personal-data', 'wp-privacy-erase-personal-data' ), true ) ) { wp_send_json_error( 'Invalid request.' ); }
		$rawId = $_POST['id'] ?? ''; $nonce = $_POST['security'] ?? '';
		if ( ! is_scalar( $rawId ) || ! ctype_digit( (string) $rawId ) || (int) $rawId < 1 || ! is_string( $nonce ) || ! current_user_can( $erase ? 'erase_others_personal_data' : 'export_others_personal_data' ) || ( $erase && ! current_user_can( 'delete_users' ) ) || ! wp_verify_nonce( wp_unslash( $nonce ), ( $erase ? 'wp-privacy-erase-personal-data-' : 'wp-privacy-export-personal-data-' ) . (int) $rawId ) ) { wp_send_json_error( 'Invalid request.' ); }
		$request = wp_get_user_request( (int) $rawId );
		if ( ! $request ) { wp_send_json_error( 'Invalid request.' ); }
		$user = get_user_by( 'email', $request->email );
		$target = (int) get_post_meta( (int) $rawId, self::TARGET, true );
		// Non-account native requests (e.g. anonymous comments) retain WordPress processing.
		if ( ! $user instanceof \WP_User && ! $target ) { return; }
		try {
			if ( ! $user instanceof \WP_User ) { throw new \RuntimeException(); }
			$this->requestLocks[] = $this->locks->acquire( array( $request->email ), (int) $user->ID );
			clean_user_cache( $user->ID );
			$validated = $this->requestUser( $request->email, $erase ? 'remove_personal_data' : 'export_personal_data' );
			if ( ! $validated instanceof \WP_User || (int) $validated->ID !== (int) $user->ID ) { throw new \RuntimeException(); }
		} catch ( \Throwable $error ) { wp_send_json_error( __( 'This request no longer matches the original account. Create a new confirmed request.', 'ai-skills-hub' ) ); }
	}

	/** Password confirmation is an authenticated alternative to clicking an emailed request link. */
	public function request( string $kind, #[\SensitiveParameter] string $currentPassword ): int {
		$user = $this->accounts->requireOwner( false );
		if ( ! in_array( $kind, array( 'export', 'erase' ), true ) ) { throw new \RuntimeException( 'invalid_request' ); }
		$user = $this->accounts->confirmPassword( $currentPassword );
		return $this->locks->withUser( (int) $user->ID, function () use ( $user, $kind ): int {
			$latest = $this->accounts->requireOwner( false );
			if ( strtolower( $latest->user_email ) !== strtolower( $user->user_email ) ) { throw new \RuntimeException( 'privacy_identity_changed' ); }
			$existing = $this->findRequest( (int) $user->ID, $kind );
			if ( $existing ) { return $existing; }
			$id = 0;
			try {
				$this->nativeReady();
				return $this->database->transaction( function () use ( $user, $kind, &$id ): int {
					$id = $this->nativeRequest( $user, $kind );
					$this->postMeta( $id, self::TARGET, (string) $user->ID );
					$this->postMeta( $id, '_aish_request_kind', $kind );
					$this->postMeta( $id, '_aish_request_confirmed_by', 'authenticated_password' );
					$this->postMeta( $id, '_aish_request_confirmed_at', gmdate( 'Y-m-d H:i:s' ) );
					$this->postMeta( $id, '_wp_user_request_confirmed_timestamp', (string) time() );
					return $id;
				} );
			} finally { if ( $id ) { clean_post_cache( $id ); wp_cache_delete( $id, 'post_meta' ); } }
		} );
	}

	private function nativeRequest( \WP_User $user, string $kind ): int {
		$action = 'export' === $kind ? 'export_personal_data' : 'remove_personal_data';
		$db = $this->database->connection();
		// A request already created by the native privacy tools is the same request, not an error.
		$id = (int) $this->database->value( "SELECT ID FROM {$db->posts} WHERE post_type='user_request' AND post_name=%s AND post_title=%s AND post_status IN ('request-pending','request-confirmed') ORDER BY ID DESC LIMIT 1", array( $action, $user->user_email ) );
		if ( ! $id ) {
			$created = wp_create_user_request( (string) $user->user_email, $action, array( 'aish_scope' => 'account', 'aish_user_id' => (int) $user->ID ), 'confirmed' );
			if ( is_wp_error( $created ) || $created < 1 ) { throw new \RuntimeException( 'privacy_unavailable' ); }
			$id = (int) $created;
		}
		$owner = (int) get_post_meta( $id, self::TARGET, true );
		$cleanupOwner = (int) get_post_meta( $id, self::CLEANUP_TARGET, true );
		if ( ( $owner && $owner !== (int) $user->ID ) || ( $cleanupOwner && $cleanupOwner !== (int) $user->ID ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
		// A neutral author keeps this private request from native content reassignment/deletion.
		$updated = wp_update_post( array( 'ID' => $id, 'post_status' => 'request-confirmed', 'post_author' => 0 ), true );
		if ( is_wp_error( $updated ) || ! $updated ) { throw new \RuntimeException( 'privacy_unavailable' ); }
		return $id;
	}

	public function requests(): array {
		$user = $this->accounts->requireOwner( false );
		$db = $this->database->connection();
		$rows = $this->database->rows( "SELECT DISTINCT p.ID,p.post_status,p.post_name,p.post_date_gmt FROM {$db->posts} p INNER JOIN {$db->postmeta} m ON m.post_id=p.ID WHERE p.post_type='user_request' AND m.meta_key=%s AND m.meta_value=%s ORDER BY p.ID DESC LIMIT 20", array( self::TARGET, (string) $user->ID ) );
		return array_map( static fn( array $row ): array => array( 'id' => (int) $row['ID'], 'kind' => 'export_personal_data' === $row['post_name'] ? 'export' : 'erase', 'status' => in_array( $row['post_status'], array( 'request-pending', 'request-confirmed', 'request-completed', 'request-failed' ), true ) ? substr( $row['post_status'], 8 ) : 'pending', 'created_at' => $row['post_date_gmt'] ), $rows );
	}

	private function findRequest( int $userId, string $kind ): int {
		$db = $this->database->connection();
		return (int) $this->database->value( "SELECT p.ID FROM {$db->posts} p INNER JOIN {$db->postmeta} m ON m.post_id=p.ID WHERE p.post_type='user_request' AND p.post_name=%s AND p.post_status IN ('request-pending','request-confirmed') AND m.meta_key=%s AND m.meta_value=%s ORDER BY p.ID DESC LIMIT 1", array( 'export' === $kind ? 'export_personal_data' : 'remove_personal_data', self::TARGET, (string) $userId ) );
	}

	public function preferences(): array {
		$user = $this->accounts->requireOwner();
		$stored = get_user_meta( $user->ID, '_aish_notification_preferences', true );
		$stored = is_array( $stored ) ? $stored : array();
		return array( 'public_profile' => '1' === get_user_meta( $user->ID, '_aish_public_profile', true ), 'notification_preferences' => array( 'version' => 1, 'account_notifications' => (bool) ( $stored['account_notifications'] ?? true ), 'community_notifications' => (bool) ( $stored['community_notifications'] ?? true ), 'contribution_notifications' => (bool) ( $stored['contribution_notifications'] ?? true ), 'optional_email' => (bool) ( $stored['optional_email'] ?? false ), 'locale' => 'en_US' === ( $stored['locale'] ?? get_user_meta( $user->ID, '_aish_profile_locale', true ) ) ? 'en_US' : 'zh_CN' ) );
	}

	public function savePreferences( array $input ): void {
		$user = $this->accounts->requireOwner();
		$preferences = $input['notification_preferences'] ?? array();
		if ( ! is_array( $preferences ) || array_diff( array_keys( $input ), array( 'public_profile', 'notification_preferences' ) ) || array_diff( array_keys( $preferences ), array( 'version', 'account_notifications', 'community_notifications', 'contribution_notifications', 'optional_email', 'locale' ) ) || ! is_bool( $input['public_profile'] ?? false ) || ! is_bool( $preferences['account_notifications'] ?? true ) || ! is_bool( $preferences['community_notifications'] ?? true ) || ! is_bool( $preferences['contribution_notifications'] ?? true ) || ! is_bool( $preferences['optional_email'] ?? false ) || ! in_array( $preferences['locale'] ?? 'zh_CN', array( 'zh_CN', 'en_US' ), true ) ) { throw new \RuntimeException( 'invalid_request' ); }
		$value = array( 'version' => 1, 'account_notifications' => $preferences['account_notifications'] ?? true, 'community_notifications' => $preferences['community_notifications'] ?? true, 'contribution_notifications' => $preferences['contribution_notifications'] ?? true, 'optional_email' => $preferences['optional_email'] ?? false, 'locale' => $preferences['locale'] ?? 'zh_CN' );
		$this->locks->withUser( (int) $user->ID, function () use ( $user, $input, $value ): void {
			$this->accounts->requireOwner();
			try {
				$this->nativeReady();
				$this->database->transaction( function () use ( $user, $input, $value ): void {
					// Phase 10 owns developer consent. Rewriting its protected key here
					// would either withdraw a profile or fail its guarded opt-in path.
					$visibility = $this->database->rows( 'SELECT meta_value FROM ' . $this->database->connection()->usermeta . " WHERE user_id=%d AND meta_key='_aish_public_profile' FOR UPDATE", array( (int) $user->ID ) );
					if ( count( $visibility ) > 1 ) { throw new \RuntimeException( 'privacy_unavailable' ); }
					if ( ( $input['public_profile'] ?? false ) !== ( '1' === ( $visibility[0]['meta_value'] ?? '' ) ) ) { throw new \RuntimeException( 'invalid_request' ); }
					$this->userMeta( (int) $user->ID, '_aish_notification_preferences', $value );
				} );
			} finally { clean_user_cache( $user->ID ); wp_cache_delete( $user->ID, 'user_meta' ); }
		} );
	}

	/** Called by WordPress's administrator-authorized export process, paginated at 50 records. */
	public function exporter( string $email, int $page = 1 ): array {
		if ( ! current_user_can( 'export_others_personal_data' ) ) { throw new \RuntimeException( 'privacy_forbidden' ); }
		$user = $this->requestUser( $email, 'export_personal_data' );
		if ( ! $user instanceof \WP_User ) { return array( 'data' => array(), 'done' => true ); }
		$page = max( 1, $page ); $offset = ( $page - 1 ) * 50;
		$data = array();
		if ( 1 === $page ) {
			$fields = array();
			foreach ( array( '_aish_account_state', '_aish_email_verified_at', '_aish_profile_locale', '_aish_public_profile', '_aish_notification_preferences' ) as $key ) {
				$value = get_user_meta( $user->ID, $key, true );
				$fields[] = array( 'name' => $key, 'value' => is_array( $value ) ? wp_json_encode( $value ) : (string) $value );
			}
			$avatar = $this->avatar->export( (int) $user->ID );
			if ( '' !== $avatar ) { $fields[] = array( 'name' => 'Avatar (PNG, base64)', 'value' => $avatar ); }
			$data[] = array( 'group_id' => 'aish-account', 'group_label' => 'AI Skills Hub account', 'item_id' => 'aish-account-' . $user->ID, 'data' => $fields );
		}
		$table = $this->database->table( 'user_notifications' );
		$rows = $this->database->rows( "SELECT id,type,template_key,params_json,locale,read_at,created_at FROM $table WHERE user_id=%d ORDER BY id LIMIT 50 OFFSET %d", array( $user->ID, $offset ) );
		foreach ( $rows as $row ) {
			$fields = array(); foreach ( $row as $name => $value ) { $fields[] = array( 'name' => $name, 'value' => (string) $value ); }
			$data[] = array( 'group_id' => 'aish-notifications', 'group_label' => 'AI Skills Hub notifications', 'item_id' => 'aish-notification-' . $row['id'], 'data' => $fields );
		}
		// Exclude verification credentials, ciphertext, password hashes and internal destinations.
		$outbox = $this->database->table( 'notification_outbox' );
		$mailRows = $this->database->rows( "SELECT id,template_key,locale,status,created_at,accepted_at FROM $outbox WHERE recipient_user_id=%d ORDER BY id LIMIT 50 OFFSET %d", array( $user->ID, $offset ) );
		foreach ( $mailRows as $row ) {
			$fields = array(); foreach ( $row as $name => $value ) { $fields[] = array( 'name' => $name, 'value' => (string) $value ); }
			$data[] = array( 'group_id' => 'aish-mail-status', 'group_label' => 'AI Skills Hub mail transport status (not delivery)', 'item_id' => 'aish-mail-' . $row['id'], 'data' => $fields );
		}
		// Extensions receive the already identity-bound account, never a request-supplied user ID.
		return apply_filters( 'aish_account_privacy_export', array( 'data' => $data, 'done' => count( $rows ) < 50 && count( $mailRows ) < 50 ), $user, $page );
	}

	/** Erasing product data is not account deletion: native Users deletion requires an admin action. */
	public function eraser( string $email, int $page = 1 ): array {
		if ( ! current_user_can( 'erase_others_personal_data' ) ) { throw new \RuntimeException( 'privacy_forbidden' ); }
		$user = $this->requestUser( $email, 'remove_personal_data' );
		if ( ! $user instanceof \WP_User ) { return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ); }
		$done = $this->locks->with( array( $email ), (int) $user->ID, function () use ( $user, $email ): bool {
			clean_user_cache( $user->ID );
			$latest = get_userdata( $user->ID );
			if ( ! $latest instanceof \WP_User || strtolower( $latest->user_email ) !== strtolower( $email ) ) { throw new \RuntimeException( 'privacy_identity_changed' ); }
			$this->isolate( (int) $user->ID );
			$finished = $this->eraseBatch( (int) $user->ID );
			if ( $finished ) {
				foreach ( array( '_aish_profile_locale', '_aish_avatar_attachment_id', '_aish_notification_preferences', '_aish_onboarding_version', '_aish_operations_account', '_aish_privacy_auth_limit' ) as $key ) { delete_user_meta( $user->ID, $key ); }
				wp_cache_delete( $user->ID, 'user_meta' );
				// Display/login identity is native WordPress data. Private profile text is removable.
				delete_user_meta( $user->ID, 'description' );
				$result = wp_update_user( array( 'ID' => $user->ID, 'user_url' => '' ) );
				if ( is_wp_error( $result ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
			}
			return $finished;
		} );
		return array( 'items_removed' => true, 'items_retained' => true, 'messages' => array( __( 'AI Skills Hub account data is erased in batches. WordPress identity and required content audit evidence are retained. Account deletion requires a separate administrator action in Users. Controlled user submissions and developer evidence are anonymized or redacted in batches. Historical anonymous Inbox and Contact records remain untouched and are never claimed by matching email.', 'ai-skills-hub' ) ), 'done' => $done );
	}

	/** The native deletion action is trusted; stage durable work before WordPress removes identity. */
	public function beforeDelete( int $id, ?int $reassign = null, ?\WP_User $user = null, bool $networkDeletion = false ): void {
		// Removing membership of one blog is not deleting WordPress's global identity.
		if ( is_multisite() && ! $networkDeletion ) { return; }
		$user = $user ?? get_userdata( $id );
		if ( ! $user instanceof \WP_User ) { return; }
		$this->locks->withUser( $id, function () use ( $id, $user ): void {
			$this->userMeta( $id, '_aish_account_deletion_pending', '1' );
			$request = $this->cleanupRequest( $id );
			if ( ! $request ) {
				$request = $this->findRequest( $id, 'erase' );
				if ( ! $request ) {
					if ( is_email( $user->user_email ) ) { $request = $this->nativeRequest( $user, 'erase' ); }
					else {
						// WordPress supports historical/service identities without an email.
						// Native deletion cleanup is keyed to that real ID and must not invent
						// a recipient merely to satisfy the email-based privacy request API.
						$request = wp_insert_post( wp_slash( array( 'post_type' => 'user_request', 'post_status' => 'request-confirmed', 'post_name' => 'remove_personal_data', 'post_title' => '', 'post_author' => 0, 'post_content' => wp_json_encode( array( 'aish_scope' => 'native_user_deletion', 'aish_user_id' => $id ) ), 'comment_status' => 'closed', 'ping_status' => 'closed' ) ), true );
					}
				}
				if ( is_wp_error( $request ) || $request < 1 ) { throw new \RuntimeException( 'Account cleanup could not be recorded.' ); }
				$this->postMeta( (int) $request, self::CLEANUP_TARGET, (string) $id );
				$this->postMeta( (int) $request, '_aish_cleanup_state', 'awaiting_deletion' );
				$this->postMeta( (int) $request, '_aish_cleanup_started_at', gmdate( 'Y-m-d H:i:s' ) );
			}
			$detached = wp_update_post( array( 'ID' => (int) $request, 'post_author' => 0 ), true );
			if ( is_wp_error( $detached ) || ! $detached ) { throw new \RuntimeException( 'Account cleanup could not be recorded.' ); }
			$this->isolate( $id );
			$this->invalidateRequests( $id, (int) $request );
			// Native reassignment may not transfer private avatar ownership to another user.
			$db = $this->database->connection();
			$avatars = $this->database->rows( "SELECT p.ID FROM {$db->posts} p INNER JOIN {$db->postmeta} m ON m.post_id=p.ID WHERE p.post_type='attachment' AND m.meta_key='_aish_avatar_owner' AND m.meta_value=%s AND p.post_author=%d LIMIT 100", array( (string) $id, $id ) );
			foreach ( $avatars as $row ) { $changed = wp_update_post( array( 'ID' => (int) $row['ID'], 'post_author' => 0 ), true ); if ( is_wp_error( $changed ) || ! $changed ) { throw new \RuntimeException( 'Account cleanup could not be recorded.' ); } }
			if ( count( $avatars ) >= 100 ) { throw new \RuntimeException( 'Account avatar cleanup requires another deletion attempt.' ); }
		} );
		$this->schedule();
	}

	public function afterDelete( int $id, ?int $reassign = null, ?\WP_User $user = null ): void {
		if ( is_multisite() && get_userdata( $id ) ) { return; }
		$this->invalidateRequests( $id );
		$request = $this->cleanupRequest( $id );
		if ( $request ) { $this->postMeta( $request, '_aish_cleanup_state', 'pending' ); }
		$this->schedule();
	}

	private function isolate( int $id ): void {
		$this->store->revokeVerifications( $id ); $this->store->cancelUserMail( $id );
		$this->userMeta( $id, '_aish_public_profile', '0' );
		delete_user_meta( $id, '_aish_email_verified_at' ); delete_user_meta( $id, '_aish_verified_email_hash' ); delete_user_meta( $id, '_aish_email_change_context' );
		$user = get_userdata( $id );
		// Existing privileged WordPress management channels must remain available until native deletion.
		if ( $user instanceof \WP_User && ! user_can( $user, 'manage_options' ) && get_user_meta( $id, '_aish_account_state', true ) ) { $this->userMeta( $id, '_aish_account_state', 'suspended' ); }
		\WP_Session_Tokens::get_instance( $id )->destroy_all();
		wp_cache_delete( $id, 'user_meta' ); clean_user_cache( $id );
		do_action( 'aish_account_privacy_isolate', $id );
	}

	/** Read-only proof of the account-owned, identity-locked extension dispatch. */
	public static function isErasingAccount( int $id ): bool { return $id > 0 && self::$erasureTarget === $id; }

	/** Every call is bounded and restartable; it never touches Skills or legacy Inbox records. */
	private function eraseBatch( int $id ): bool {
		$done = true;
		foreach ( array( 'email_verifications' => 'user_id', 'user_notifications' => 'user_id', 'notification_outbox' => 'recipient_user_id' ) as $entity => $column ) {
			$table = $this->database->table( $entity );
			$this->database->execute( "DELETE FROM $table WHERE $column=%d LIMIT 50", array( $id ) );
			if ( null !== $this->database->value( "SELECT id FROM $table WHERE $column=%d LIMIT 1", array( $id ) ) ) { $done = false; }
		}
		$done = $this->avatar->purgeOwned( $id, 25 ) && $done;
		// False keeps the existing durable request pending until every module has finished.
		// Sibling modules may remove their own completion markers before later
		// callbacks run. Keep authority in this already validated/locked dispatch,
		// not in a mutable marker or a caller-supplied filter argument.
		$previous = self::$erasureTarget; self::$erasureTarget = $id;
		try { return (bool) apply_filters( 'aish_account_privacy_erase_batch', $done, $id ); }
		finally { self::$erasureTarget = $previous; }
	}

	private function cleanupRequest( int $id ): int {
		$db = $this->database->connection();
		return (int) $this->database->value( "SELECT p.ID FROM {$db->posts} p INNER JOIN {$db->postmeta} m ON m.post_id=p.ID WHERE p.post_type='user_request' AND m.meta_key=%s AND m.meta_value=%s ORDER BY p.ID DESC LIMIT 1", array( self::CLEANUP_TARGET, (string) $id ) );
	}

	/** Email alone cannot retarget an existing request to a newly registered owner of that address. */
	private function requestUser( string $email, string $action ): mixed {
		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof \WP_User ) { return false; }
		// WordPress validates nonce/capability before invoking exporters/erasers. Pin its
		// exact request as well: a historical email can belong to another user today.
		$nativeAction = 'export_personal_data' === $action ? 'wp-privacy-export-personal-data' : 'wp-privacy-erase-personal-data';
		$httpContext = wp_doing_ajax() || ( $_POST['action'] ?? '' ) === $nativeAction;
		if ( $httpContext ) {
			$rawId = $_POST['id'] ?? '';
			if ( ! is_scalar( $rawId ) || ! ctype_digit( (string) $rawId ) || (int) $rawId < 1 ) { throw new \RuntimeException( 'privacy_identity_changed' ); }
			$request = wp_get_user_request( (int) $rawId );
			$target = get_post_meta( (int) $rawId, self::TARGET, false );
			if ( ! $request || $request->action_name !== $action || strtolower( $request->email ) !== strtolower( $email ) || ! in_array( $request->status, array( 'request-confirmed', 'request-completed' ), true ) || array( (string) $user->ID ) !== $target || get_post_meta( (int) $rawId, '_aish_request_invalidated', true ) ) { throw new \RuntimeException( 'privacy_identity_changed' ); }
			return $user;
		}
		$db = $this->database->connection();
		// Direct trusted PHP callers still need capability checks; unfinished known
		// requests may not silently change owner. Completed requests do not block a new one.
		$rows = $this->database->rows( "SELECT m.meta_value FROM {$db->posts} p INNER JOIN {$db->postmeta} m ON m.post_id=p.ID WHERE p.post_type='user_request' AND p.post_name=%s AND p.post_title=%s AND p.post_status IN ('request-pending','request-confirmed') AND m.meta_key IN (%s,%s) LIMIT 100", array( $action, $email, self::TARGET, self::CLEANUP_TARGET ) );
		foreach ( $rows as $row ) { if ( (int) $row['meta_value'] !== (int) $user->ID ) { throw new \RuntimeException( 'privacy_identity_changed' ); } }
		return $user;
	}

	private function invalidateRequests( int $id, int $except = 0 ): bool {
		$db = $this->database->connection();
		$rows = $this->database->rows( "SELECT DISTINCT p.ID,p.post_status FROM {$db->posts} p INNER JOIN {$db->postmeta} m ON m.post_id=p.ID WHERE p.post_type='user_request' AND p.ID<>%d AND p.post_title<>'' AND m.meta_key IN (%s,%s) AND m.meta_value=%s AND p.post_status IN ('request-pending','request-confirmed','request-completed') LIMIT 100", array( $except, self::TARGET, self::CLEANUP_TARGET, (string) $id ) );
		foreach ( $rows as $row ) {
			PrivacyExports::invalidate( (int) $row['ID'] );
			$result = wp_update_post( array( 'ID' => (int) $row['ID'], 'post_title' => '', 'post_content' => '', 'post_password' => '', 'post_status' => 'request-completed' === $row['post_status'] ? 'request-completed' : 'request-failed', 'post_author' => 0 ), true );
			if ( is_wp_error( $result ) || ! $result ) { throw new \RuntimeException( 'privacy_unavailable' ); }
			$this->postMeta( (int) $row['ID'], '_aish_request_invalidated', 'identity_changed' );
		}
		return count( $rows ) < 100;
	}

	private function nativeReady(): void {
		$db = $this->database->connection();
		foreach ( array( $db->posts, $db->postmeta, $db->usermeta ) as $table ) {
			$engine = $this->database->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) );
			if ( 'innodb' !== strtolower( (string) $engine ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
		}
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) { wp_schedule_single_event( time() + 60, self::CRON ); }
	}

	public function tick(): void {
		try {
			$db = $this->database->connection();
			$rows = $this->database->rows( "SELECT p.ID,m.meta_value AS user_id FROM {$db->posts} p INNER JOIN {$db->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s INNER JOIN {$db->postmeta} s ON s.post_id=p.ID AND s.meta_key='_aish_cleanup_state' WHERE p.post_type='user_request' AND s.meta_value IN ('awaiting_deletion','pending','failed') AND NOT EXISTS (SELECT 1 FROM {$db->users} u WHERE u.ID=CAST(m.meta_value AS UNSIGNED)) ORDER BY p.post_modified_gmt,p.ID LIMIT 5", array( self::CLEANUP_TARGET ) );
			foreach ( $rows as $row ) {
				$id = (int) $row['user_id']; $request = (int) $row['ID'];
				if ( $id < 1 || get_userdata( $id ) ) { continue; }
				try {
					$this->locks->withUser( $id, function () use ( $id, $request ): void {
						if ( get_userdata( $id ) ) { return; }
						$this->store->cancelUserMail( $id );
						if ( ! $this->invalidateRequests( $id, $request ) ) { $this->postMeta( $request, '_aish_cleanup_state', 'pending' ); return; }
						if ( ! $this->eraseBatch( $id ) ) { $this->postMeta( $request, '_aish_cleanup_state', 'pending' ); return; }
						// Identity is gone: clear only this module's orphan metadata, never historical actor IDs.
						foreach ( array_merge( self::META, array( '_aish_privacy_auth_limit' ) ) as $key ) { delete_user_meta( $id, $key ); }
						$this->postMeta( $request, '_aish_cleanup_state', 'complete' );
						$this->postMeta( $request, '_aish_cleanup_completed_at', gmdate( 'Y-m-d H:i:s' ) );
						// Completion retains a minimal result, not the deleted email or request payload.
						$result = wp_update_post( wp_slash( array( 'ID' => $request, 'post_author' => 0, 'post_title' => 'Account cleanup completed', 'post_content' => '', 'post_password' => '', 'post_excerpt' => '', 'post_status' => 'request-completed' ) ), true );
						if ( is_wp_error( $result ) || ! $result ) { throw new \RuntimeException( 'privacy_unavailable' ); }
						foreach ( array( '_wp_user_request_user_email', '_wp_user_request_action_name', '_wp_user_request_confirmed_timestamp', '_wp_user_request_completed_timestamp', '_wp_user_request_confirmed_email', '_wp_user_request_user_email', self::TARGET, self::CLEANUP_TARGET ) as $key ) { delete_post_meta( $request, $key ); }
					} );
				} catch ( \Throwable $error ) { $this->postMeta( $request, '_aish_cleanup_state', 'failed' ); wp_update_post( array( 'ID' => $request, 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ) ); }
			}
			$expired = $this->database->rows( "SELECT p.ID FROM {$db->posts} p INNER JOIN {$db->postmeta} s ON s.post_id=p.ID AND s.meta_key='_aish_cleanup_state' AND s.meta_value='complete' INNER JOIN {$db->postmeta} t ON t.post_id=p.ID AND t.meta_key='_aish_cleanup_completed_at' WHERE p.post_type='user_request' AND t.meta_value<%s ORDER BY p.ID LIMIT 20", array( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
			foreach ( $expired as $row ) { wp_delete_post( (int) $row['ID'], true ); }
		} catch ( \Throwable $error ) { /* Fixed state only; no email, SQL, token, password or exception logging. */ }
		finally { $this->schedule(); }
	}

	private function userMeta( int $id, string $key, mixed $value ): void {
		delete_user_meta( $id, $key );
		if ( false === add_user_meta( $id, $key, $value, true ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
		wp_cache_delete( $id, 'user_meta' );
		if ( array( $value ) !== get_user_meta( $id, $key, false ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
	}

	private function postMeta( int $id, string $key, string $value ): void {
		update_post_meta( $id, $key, $value ); wp_cache_delete( $id, 'post_meta' );
		if ( array( $value ) !== get_post_meta( $id, $key, false ) ) { throw new \RuntimeException( 'privacy_unavailable' ); }
	}
}
