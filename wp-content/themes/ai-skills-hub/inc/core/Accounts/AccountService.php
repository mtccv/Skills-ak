<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Native WordPress identity, with explicit verification and private self-service. */
final class AccountService {
	private Locks $locks;
	private RateLimiter $rates;
	private int $internal = 0;
	private bool $signingIn = false;
	private bool $authenticationLimited = false;
	private array $nativeLocks = array();
	private array $touched = array();
	private bool $enginesChecked = false;

	public function __construct( private Database $database, private Store $store, private Mail $mail, ?Locks $locks = null ) {
		$this->locks = $locks ?? new Locks( $database );
		$this->rates = new RateLimiter( $database );
	}

	public function hooks(): void {
		add_filter( 'authenticate', array( $this, 'limitAuthentication' ), 1, 3 );
		add_filter( 'authenticate', array( $this, 'guardAuthentication' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_authentication_errors', array( $this, 'guardRest' ), PHP_INT_MAX );
		add_filter( 'wp_is_application_passwords_available_for_user', function ( bool $available, \WP_User $user ): bool {
			try { return $available && ! $this->restricted( $user ); }
			catch ( \Throwable $error ) { return false; }
		}, 100, 2 );
		add_filter( 'map_meta_cap', array( $this, 'guardCapabilities' ), 100, 4 );
		add_filter( 'wp_pre_insert_user_data', array( $this, 'guardNativeWrite' ), PHP_INT_MAX, 4 );
		add_action( 'user_register', array( $this, 'nativeCreated' ), PHP_INT_MAX, 2 );
		add_action( 'profile_update', array( $this, 'nativeUpdated' ), PHP_INT_MAX, 3 );
		add_action( 'wp_set_password', array( $this, 'nativePasswordSet' ), PHP_INT_MAX, 3 );
		add_action( 'shutdown', array( $this, 'releaseNativeLocks' ), PHP_INT_MAX );
		add_action( 'lostpassword_form', static function (): void { wp_nonce_field( 'aish_native_lostpassword', '_aish_lostpassword_nonce' ); } );
		add_action( 'login_form_lostpassword', array( $this, 'nativeForgot' ) );
		add_action( 'login_form_register', static function (): void {
			wp_safe_redirect( home_url( ( str_starts_with( get_locale(), 'zh' ) ? '/zh/' : '/en/' ) . 'register/' ), 303 ); exit;
		} );
		add_filter( 'pre_comment_approved', function ( mixed $approved ): mixed {
			$user = wp_get_current_user();
			try { $restricted = $user->exists() && $this->restricted( $user ); }
			catch ( \Throwable $error ) { $restricted = true; }
			if ( $restricted ) { return new \WP_Error( 'aish_verification_required', __( 'Account verification is required.', 'ai-skills-hub' ) ); }
			return $approved;
		}, 100 );
		add_filter( 'send_email_change_email', fn( bool $send ): bool => $this->internal > 0 ? false : $send );
		add_filter( 'send_password_change_email', fn( bool $send ): bool => $this->internal > 0 ? false : $send );
	}

	public static function locale( string $locale ): string {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new AccountException( 'invalid_input' ); }
		return $locale;
	}

	private static function password( #[\SensitiveParameter] string $password ): string {
		if ( strlen( $password ) > 256 || ! preg_match( '//u', $password ) || preg_match_all( '/./us', $password ) < 12 || str_contains( $password, "\0" ) || trim( $password ) !== $password ) { throw new AccountException( 'weak_password' ); }
		return $password;
	}

	private static function text( mixed $value, int $length, bool $required = false ): string {
		if ( ! is_string( $value ) || ! preg_match( '//u', $value ) || preg_match_all( '/./us', $value ) > $length || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) { throw new AccountException( 'invalid_input' ); }
		$value = trim( sanitize_text_field( $value ) );
		if ( $required && '' === $value ) { throw new AccountException( 'invalid_input' ); }
		return $value;
	}

	private function rate( string $action, string $identity = '' ): void {
		if ( ! $this->rates->allow( $action, $identity ) ) { throw new AccountException( 'rate_limited' ); }
	}

	private function storageReady(): bool { return Schema::VERSION === get_option( 'aish_schema_version', '' ); }

	public function ready(): bool { return $this->storageReady() && $this->mail->ready(); }

	private function requireMail(): void {
		if ( ! $this->ready() ) { throw new AccountException( 'service_unavailable' ); }
	}

	/** Do not trust stale shared user/meta cache at security boundaries. */
	private function fresh( int $id ): \WP_User {
		if ( $id < 1 ) { throw new AccountException( 'login_required' ); }
		if ( $this->internal > 0 ) {
			$table = $this->database->connection()->users;
			if ( ! $this->database->row( "SELECT ID FROM $table WHERE ID=%d FOR UPDATE", array( $id ) ) ) { throw new AccountException( 'login_required' ); }
		}
		clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' );
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User ) { throw new AccountException( 'login_required' ); }
		return $user;
	}

	private function meta( int $id, string $key ): mixed {
		$table = $this->database->connection()->usermeta;
		$values = array_column( $this->database->rows( "SELECT meta_value FROM $table WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id", array( $id, $key ) ), 'meta_value' );
		if ( ! $values ) { return ''; }
		if ( array_diff( $values, array( $values[0] ) ) ) { throw new AccountException( 'save_failed' ); }
		return maybe_unserialize( $values[0] );
	}

	private function setMeta( int $id, string $key, mixed $value ): void {
		$this->touched[ $id ] = true;
		$connection = $this->database->connection(); $previous = $connection->suppress_errors( true );
		try {
			$connection->last_error = '';
			update_user_meta( $id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
			if ( '' !== $connection->last_error || $this->meta( $id, $key ) !== $value ) { throw new AccountException( 'save_failed' ); }
		} finally { $connection->suppress_errors( $previous ); }
	}

	private function removeMeta( int $id, string $key ): void {
		$this->touched[ $id ] = true;
		$connection = $this->database->connection(); $previous = $connection->suppress_errors( true );
		try {
			$connection->last_error = '';
			delete_user_meta( $id, $key );
			if ( '' !== $connection->last_error || '' !== $this->meta( $id, $key ) ) { throw new AccountException( 'save_failed' ); }
		} finally { $connection->suppress_errors( $previous ); }
	}

	/** Users/usermeta and account records commit together; cache purged after outcome. */
	private function transaction( callable $operation ): mixed {
		if ( ! $this->storageReady() ) { throw new AccountException( 'service_unavailable' ); }
		if ( ! $this->enginesChecked ) {
			$connection = $this->database->connection();
			foreach ( array( $connection->users, $connection->usermeta ) as $table ) {
				$row = $this->database->row( 'SHOW TABLE STATUS WHERE Name=%s', array( $table ) );
				if ( ! $row || 'innodb' !== strtolower( (string) ( $row['Engine'] ?? '' ) ) ) { throw new AccountException( 'service_unavailable' ); }
			}
			$this->enginesChecked = true;
		}
		$suspended = wp_suspend_cache_addition(); wp_suspend_cache_addition( true );
		$previous = $this->database->connection()->suppress_errors( true ); ++$this->internal;
		try { return $this->store->transaction( $operation ); }
		finally {
			--$this->internal;
			foreach ( array_keys( $this->touched ) as $id ) { clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); }
			$this->touched = array(); wp_suspend_cache_addition( $suspended );
			$this->database->connection()->suppress_errors( $previous );
		}
	}

	private function emailOwner( string $email ): int {
		$table = $this->database->connection()->users;
		// email_exists is rechecked under the same lock, with its cache primed fresh.
		$rows = $this->database->rows( "SELECT ID FROM $table WHERE user_email=%s LIMIT 2", array( $email ) );
		if ( count( $rows ) > 1 ) { throw new AccountException( 'email_conflict' ); }
		foreach ( $rows as $row ) { clean_user_cache( (int) $row['ID'] ); }
		wp_cache_delete( $email, 'useremail' );
		return (int) email_exists( $email );
	}

	public function state( ?int $userId = null ): string {
		$id = $userId ?? get_current_user_id();
		if ( $id < 1 ) { return 'guest'; }
		if ( '1' === $this->meta( $id, '_aish_account_deletion_pending' ) ) { return 'suspended'; }
		$state = $this->meta( $id, '_aish_account_state' );
		return in_array( $state, array( 'pending_email', 'active', 'suspended' ), true ) ? $state : ( '' === $state ? 'legacy' : 'suspended' );
	}

	public function verified( ?int $userId = null ): bool {
		$id = $userId ?? get_current_user_id();
		if ( $id < 1 || 'active' !== $this->state( $id ) ) { return false; }
		$user = get_userdata( $id );
		$hash = $this->meta( $id, '_aish_verified_email_hash' );
		return $user instanceof \WP_User && is_string( $hash ) && strlen( $hash ) === 64 && hash_equals( Store::emailHash( $user->user_email ), $hash ) && is_string( $this->meta( $id, '_aish_email_verified_at' ) ) && '' !== $this->meta( $id, '_aish_email_verified_at' );
	}

	private static function manager( \WP_User $user ): bool {
		// Inspect established capabilities directly: calling user_can here recurses.
		return ! empty( $user->allcaps['manage_options'] );
	}

	public function restricted( \WP_User $user ): bool {
		$state = $this->state( (int) $user->ID );
		if ( 'suspended' === $state ) { return true; }
		// Management access is independent of new community verification evidence.
		$legacyCapabilities = array( 'edit_posts', 'aish_manage_skills', 'aish_review_skills', 'aish_manage_taxonomies', 'aish_manage_repositories', 'aish_manage_settings', 'aish_manage_ai_settings', 'aish_generate_content' );
		if ( self::manager( $user ) ) { return false; }
		if ( 'legacy' === $state ) { foreach ( $legacyCapabilities as $cap ) { if ( ! empty( $user->allcaps[ $cap ] ) ) { return false; } } }
		return ! $this->verified( (int) $user->ID );
	}

	public function requireOwner( bool $verified = true ): \WP_User {
		$user = $this->fresh( get_current_user_id() );
		if ( ! user_can( $user, 'read' ) ) { throw new AccountException( 'login_required' ); }
		if ( 'suspended' === $this->state( (int) $user->ID ) ) { throw new AccountException( 'suspended' ); }
		if ( $verified && ! $this->verified( (int) $user->ID ) ) { throw new AccountException( 'verification_required' ); }
		return $user;
	}

	private function issue( \WP_User $user, string $purpose, string $email, string $locale ): void {
		$token = bin2hex( random_bytes( 32 ) );
		$row = $this->store->issueVerification( (int) $user->ID, $purpose, $email, $token );
		if ( 'change_email' === $purpose ) {
			$this->setMeta( (int) $user->ID, '_aish_email_change_context', array( 'token_hash' => Store::tokenHash( $token ), 'password_fingerprint' => hash_hmac( 'sha256', $user->user_pass, wp_salt( 'auth' ) ), 'old_email_hash' => Store::emailHash( $user->user_email ) ) );
		}
		$this->mail->queueVerification( (int) $user->ID, $purpose, $email, $token, $locale, (int) $row['id'], $row['expires_at'] );
	}

	/** Existing and new addresses intentionally have the same outward outcome. */
	public function register( #[\SensitiveParameter] array $input, string $locale ): void {
		$locale = self::locale( $locale ); $this->requireMail();
		$email = Locks::email( is_string( $input['email'] ?? null ) ? $input['email'] : '' );
		$password = self::password( is_string( $input['password'] ?? null ) ? $input['password'] : '' );
		$name = self::text( $input['display_name'] ?? '', 60, true );
		if ( ! in_array( $input['terms'] ?? null, array( true, '1', 1 ), true ) || '' !== ( $input['honeypot'] ?? '' ) ) { throw new AccountException( 'invalid_input' ); }
		$this->rate( 'register', $email );
		$this->locks->with( array( $email ), null, function () use ( $email, $password, $name, $locale ): void {
			if ( $this->emailOwner( $email ) ) { wp_hash_password( $password ); return; }
			$this->transaction( function () use ( $email, $password, $name, $locale ): void {
				$id = wp_insert_user( array( 'user_login' => 'aish_' . bin2hex( random_bytes( 16 ) ), 'user_email' => wp_slash( $email ), 'user_pass' => $password, 'display_name' => wp_slash( $name ), 'nickname' => wp_slash( $name ), 'role' => 'subscriber', 'user_nicename' => 'member-' . bin2hex( random_bytes( 16 ) ) ) );
				if ( is_wp_error( $id ) || ! is_int( $id ) || $id < 1 ) { throw new AccountException( 'save_failed' ); }
				$this->touched[ $id ] = true;
				$this->setMeta( $id, '_aish_account_state', 'pending_email' );
				$this->setMeta( $id, '_aish_profile_locale', $locale );
				$this->setMeta( $id, '_aish_public_profile', '0' );
				$this->setMeta( $id, '_aish_onboarding_version', '1' );
				$this->setMeta( $id, '_aish_notification_preferences', array( 'version' => 1, 'account_notifications' => true, 'optional_email' => false, 'locale' => $locale ) );
				$user = $this->fresh( $id );
				if ( array( 'subscriber' ) !== $user->roles || $this->emailOwner( $email ) !== $id ) { throw new AccountException( 'save_failed' ); }
				$this->issue( $user, 'verify_email', $email, $locale );
			} );
		} );
	}

	public function login( #[\SensitiveParameter] string $email, #[\SensitiveParameter] string $password, bool $remember = false ): \WP_User {
		try { $email = Locks::email( $email ); } catch ( \Throwable $error ) { throw new AccountException( 'auth_failed' ); }
		$this->rate( 'login', $email );
		if ( '' === $password || strlen( $password ) > 4096 ) { throw new AccountException( 'auth_failed' ); }
		$this->signingIn = true;
		try { $user = wp_signon( array( 'user_login' => $email, 'user_password' => $password, 'remember' => $remember ), is_ssl() ); }
		finally { $this->signingIn = false; }
		if ( ! $user instanceof \WP_User ) { throw new AccountException( 'auth_failed' ); }
		wp_set_current_user( (int) $user->ID );
		return $user;
	}

	public function logout(): void { wp_logout(); }

	public function resend( #[\SensitiveParameter] string $email, string $locale ): void {
		$this->requireMail(); $locale = self::locale( $locale ); $email = Locks::email( $email );
		$this->rate( 'resend', $email );
		$this->locks->with( array( $email ), null, function () use ( $email, $locale ): void {
			$id = $this->emailOwner( $email );
			if ( ! $id ) { return; }
			$this->locks->withUser( $id, function () use ( $id, $email, $locale ): void {
				$user = $this->fresh( $id );
				if ( $email !== Locks::email( $user->user_email ) || $this->verified( $id ) || 'suspended' === $this->state( $id ) ) { return; }
				$table = $this->database->table( 'email_verifications' );
				$last = $this->database->value( "SELECT created_at FROM $table WHERE user_id=%d AND purpose=%s ORDER BY id DESC LIMIT 1", array( $id, 'verify_email' ) );
				if ( is_string( $last ) && strtotime( $last . ' UTC' ) > time() - 60 ) { return; }
				$this->transaction( fn() => $this->issue( $user, 'verify_email', $email, $locale ) );
			} );
		} );
	}

	public function verify( #[\SensitiveParameter] string $token ): void {
		$this->rate( 'verify' );
		$row = $this->store->verification( $token );
		if ( ! $row ) { throw new AccountException( 'invalid_token' ); }
		if ( get_current_user_id() && get_current_user_id() !== (int) $row['user_id'] ) { throw new AccountException( 'invalid_token' ); }
		$id = (int) $row['user_id']; $user = $this->fresh( $id ); $oldEmail = Locks::email( $user->user_email );
		$this->locks->with( array( $oldEmail, $row['target_email'] ), $id, function () use ( $token, $id, $oldEmail ): void {
			$this->transaction( function () use ( $token, $id, $oldEmail ): void {
				$user = $this->fresh( $id ); $row = $this->store->verification( $token, true );
				if ( ! $row || (int) $row['user_id'] !== $id || $oldEmail !== Locks::email( $user->user_email ) || 'suspended' === $this->state( $id ) ) { throw new AccountException( 'invalid_token' ); }
				$email = Locks::email( $row['target_email'] ); $owner = $this->emailOwner( $email );
				if ( 'change_email' === $row['purpose'] ) {
					$context = $this->meta( $id, '_aish_email_change_context' );
					if ( ! is_array( $context ) || ! is_string( $context['token_hash'] ?? null ) || ! is_string( $context['password_fingerprint'] ?? null ) || ! is_string( $context['old_email_hash'] ?? null ) || ! hash_equals( Store::tokenHash( $token ), $context['token_hash'] ) || ! hash_equals( hash_hmac( 'sha256', $user->user_pass, wp_salt( 'auth' ) ), $context['password_fingerprint'] ) || ! hash_equals( Store::emailHash( $oldEmail ), $context['old_email_hash'] ) ) { throw new AccountException( 'invalid_token' ); }
				}
				if ( ( $owner && $owner !== $id ) || ( 'verify_email' === $row['purpose'] && $email !== $oldEmail ) || ! in_array( $row['purpose'], array( 'verify_email', 'change_email' ), true ) || ! $this->store->consumeVerification( (int) $row['id'] ) ) { throw new AccountException( 'invalid_token' ); }
				if ( 'change_email' === $row['purpose'] ) {
					$this->requireMail(); $this->updateUser( $id, array( 'user_email' => $email ) );
					$this->mail->queueSecurity( $id, $oldEmail, $this->userLocale( $id ), 'email_changed' );
					$this->destroyAll( $id );
				}
				$this->setMeta( $id, '_aish_account_state', 'active' );
				$this->setMeta( $id, '_aish_email_verified_at', gmdate( 'Y-m-d H:i:s' ) );
				$this->setMeta( $id, '_aish_verified_email_hash', Store::emailHash( $email ) );
				$this->store->revokeVerifications( $id );
				$this->removeMeta( $id, '_aish_email_change_context' );
				$this->notify( $id, 'change_email' === $row['purpose'] ? 'email_changed' : 'email_verified' );
			} );
		} );
	}

	public function forgot( #[\SensitiveParameter] string $email, string $locale ): void {
		$this->requireMail(); $locale = self::locale( $locale ); $email = Locks::email( $email );
		$this->rate( 'forgot', $email );
		$this->locks->with( array( $email ), null, function () use ( $email, $locale ): void {
			$id = $this->emailOwner( $email ); if ( ! $id ) { return; }
			$this->locks->withUser( $id, function () use ( $id, $locale ): void {
				if ( 'suspended' === $this->state( $id ) ) { return; }
				$this->transaction( function () use ( $id, $locale ): void {
					$user = $this->fresh( $id ); $key = get_password_reset_key( $user );
					if ( is_wp_error( $key ) || ! is_string( $key ) ) { throw new AccountException( 'save_failed' ); }
					$this->touched[ $id ] = true;
					$this->mail->queuePasswordReset( $user, $key, $locale );
				} );
			} );
		} );
	}

	public function reset( #[\SensitiveParameter] string $login, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $password ): void {
		$this->rate( 'security' ); self::password( $password );
		if ( strlen( $login ) > 60 || strlen( $key ) > 128 || '' === $key ) { throw new AccountException( 'invalid_token' ); }
		$user = check_password_reset_key( $key, $login );
		if ( ! $user instanceof \WP_User ) { throw new AccountException( 'invalid_token' ); }
		$this->locks->with( array( $user->user_email ), (int) $user->ID, function () use ( $login, $key, $password ): void {
			$this->transaction( function () use ( $login, $key, $password ): void {
				$user = check_password_reset_key( $key, $login );
				if ( ! $user instanceof \WP_User || 'suspended' === $this->state( (int) $user->ID ) ) { throw new AccountException( 'invalid_token' ); }
				$id = (int) $user->ID; $this->touched[ $id ] = true;
				// This native callback sends immediately; the product records its
				// own security notice in the transactional outbox instead.
				$priority = has_action( 'after_password_reset', 'wp_password_change_notification' );
				if ( false !== $priority ) { remove_action( 'after_password_reset', 'wp_password_change_notification', $priority ); }
				try { reset_password( $user, $password ); }
				finally { if ( false !== $priority ) { add_action( 'after_password_reset', 'wp_password_change_notification', $priority ); } }
				$updated = $this->fresh( $id );
				if ( ! wp_check_password( $password, $updated->user_pass, $id ) || '' !== $updated->user_activation_key ) { throw new AccountException( 'save_failed' ); }
				$this->destroyAll( $id );
				$this->store->revokeVerifications( $id, 'change_email' );
				$this->removeMeta( $id, '_aish_email_change_context' );
				$this->notify( $id, 'password_changed' );
				$this->optionalSecurity( $updated, 'password_changed' );
			} );
		} );
	}

	private function currentPassword( \WP_User $user, #[\SensitiveParameter] string $password ): void {
		if ( '' === $password || strlen( $password ) > 4096 || ! wp_check_password( $password, $user->user_pass, (int) $user->ID ) ) { throw new AccountException( 'current_password' ); }
	}

	/** Reauthentication shared by privacy and other self-service security actions. */
	public function confirmPassword( #[\SensitiveParameter] string $password ): \WP_User {
		$user = $this->requireOwner( false ); $this->rate( 'security', (string) $user->ID );
		$this->currentPassword( $user, $password );
		return $user;
	}

	public function changePassword( #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new ): void {
		$user = $this->requireOwner( false ); self::password( $new ); $this->rate( 'security', (string) $user->ID );
		$this->locks->with( array( $user->user_email ), (int) $user->ID, function () use ( $user, $current, $new ): void {
			$this->transaction( function () use ( $user, $current, $new ): void {
				$user = $this->fresh( (int) $user->ID ); $this->currentPassword( $user, $current );
				$this->updateUser( (int) $user->ID, array( 'user_pass' => $new ) );
				$this->destroyAll( (int) $user->ID );
				$this->store->revokeVerifications( (int) $user->ID, 'change_email' );
				$this->removeMeta( (int) $user->ID, '_aish_email_change_context' );
				$this->notify( (int) $user->ID, 'password_changed' );
				$this->optionalSecurity( $user, 'password_changed' );
			} );
		} );
		wp_clear_auth_cookie(); wp_set_current_user( 0 );
	}

	public function requestEmailChange( #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $email, string $locale ): void {
		$user = $this->requireOwner( false ); $this->requireMail(); self::locale( $locale ); $email = Locks::email( $email );
		$this->rate( 'resend', (string) $user->ID );
		$this->locks->with( array( $user->user_email, $email ), (int) $user->ID, function () use ( $user, $current, $email, $locale ): void {
			$user = $this->fresh( (int) $user->ID ); $this->currentPassword( $user, $current );
			if ( $email === Locks::email( $user->user_email ) || $this->emailOwner( $email ) ) { return; }
			$table = $this->database->table( 'email_verifications' );
			$last = $this->database->value( "SELECT created_at FROM $table WHERE user_id=%d AND purpose=%s ORDER BY id DESC LIMIT 1", array( $user->ID, 'change_email' ) );
			if ( is_string( $last ) && strtotime( $last . ' UTC' ) > time() - 60 ) { return; }
			$this->transaction( fn() => $this->issue( $user, 'change_email', $email, $locale ) );
		} );
	}

	public function revokeOtherSessions( #[\SensitiveParameter] string $current ): void {
		$user = $this->requireOwner( false ); $this->rate( 'security', (string) $user->ID );
		$this->locks->withUser( (int) $user->ID, function () use ( $user, $current ): void {
			$this->transaction( function () use ( $user, $current ): void {
				$user = $this->fresh( (int) $user->ID ); $this->currentPassword( $user, $current );
				$id = (int) $user->ID; $this->touched[ $id ] = true;
				$manager = \WP_Session_Tokens::get_instance( $id ); $token = wp_get_session_token();
				$manager->destroy_others( $token ); wp_cache_delete( $id, 'user_meta' );
				$sessions = $manager->get_all();
				if ( count( $sessions ) > 1 || ( $sessions && ! $manager->verify( $token ) ) ) { throw new AccountException( 'save_failed' ); }
				$this->notify( $id, 'sessions_revoked' ); $this->optionalSecurity( $user, 'sessions_revoked' );
			} );
		} );
	}

	private function destroyAll( int $id ): void {
		$this->touched[ $id ] = true;
		$connection = $this->database->connection(); $previous = $connection->suppress_errors( true );
		try {
			\WP_Session_Tokens::get_instance( $id )->destroy_all();
			$sessions = $this->meta( $id, 'session_tokens' );
			if ( '' !== $sessions && array() !== $sessions ) { throw new AccountException( 'save_failed' ); }
		} finally { $connection->suppress_errors( $previous ); }
	}

	private function updateUser( int $id, array $data ): void {
		$this->touched[ $id ] = true;
		$this->database->connection()->last_error = '';
		$native = $data;
		// Native user APIs unslash ordinary SQL fields, but hash the password
		// before that step. Never add slashes to a plaintext password.
		foreach ( $native as $key => $value ) { if ( 'user_pass' !== $key && is_string( $value ) ) { $native[ $key ] = wp_slash( $value ); } }
		$result = wp_update_user( array_merge( $native, array( 'ID' => $id ) ) );
		if ( is_wp_error( $result ) || (int) $result !== $id || '' !== $this->database->connection()->last_error ) { throw new AccountException( 'save_failed' ); }
		$user = $this->fresh( $id );
		foreach ( $data as $key => $value ) {
			if ( 'user_pass' === $key ) { if ( ! wp_check_password( $value, $user->user_pass, $id ) ) { throw new AccountException( 'save_failed' ); } }
			elseif ( $user->$key !== $value ) { throw new AccountException( 'save_failed' ); }
		}
	}

	private function userLocale( int $id ): string {
		$preferences = $this->meta( $id, '_aish_notification_preferences' );
		if ( is_array( $preferences ) && in_array( $preferences['locale'] ?? '', array( 'zh_CN', 'en_US' ), true ) ) { return $preferences['locale']; }
		return 'en_US' === $this->meta( $id, '_aish_profile_locale' ) ? 'en_US' : 'zh_CN';
	}

	public function profile(): array {
		$user = $this->requireOwner( false ); $id = (int) $user->ID;
		$verified = $this->verified( $id ); $state = $this->state( $id );
		if ( 'active' === $state && ! $verified ) { $state = 'pending_email'; }
		return array( 'user_id' => $id, 'display_name' => $user->display_name, 'email' => $user->user_email, 'description' => (string) $this->meta( $id, 'description' ), 'url' => $user->user_url, 'locale' => 'en_US' === $this->meta( $id, '_aish_profile_locale' ) ? 'en_US' : 'zh_CN', 'state' => $state, 'verified' => $verified, 'verified_at' => $verified ? (string) $this->meta( $id, '_aish_email_verified_at' ) : '', 'registered_at' => $user->user_registered, 'public_profile' => '1' === $this->meta( $id, '_aish_public_profile' ), 'notification_preferences' => $this->meta( $id, '_aish_notification_preferences' ) );
	}

	public function saveProfile( array $data ): void {
		$user = $this->requireOwner();
		$name = self::text( $data['display_name'] ?? '', 60, true );
		$description = self::text( $data['description'] ?? '', 1000 );
		$locale = self::locale( is_string( $data['locale'] ?? null ) ? $data['locale'] : '' );
		$url = self::text( $data['url'] ?? '', 2048 );
		if ( '' !== $url ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'https', 'http' ), true ) || empty( $parts['host'] ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { throw new AccountException( 'invalid_input' ); }
			$url = esc_url_raw( $url, array( 'https', 'http' ) );
		}
		$this->locks->with( array( $user->user_email ), (int) $user->ID, function () use ( $user, $name, $description, $locale, $url ): void {
			$this->requireOwner();
			$this->transaction( function () use ( $user, $name, $description, $locale, $url ): void {
				$id = (int) $user->ID;
				$this->updateUser( $id, array( 'display_name' => $name, 'user_url' => $url ) );
				$this->setMeta( $id, 'description', $description );
				$this->setMeta( $id, '_aish_profile_locale', $locale );
			} );
		} );
	}

	private function optionalSecurity( \WP_User $user, string $event ): void {
		if ( $this->mail->ready() ) { $this->mail->queueSecurity( (int) $user->ID, $user->user_email, $this->userLocale( (int) $user->ID ), $event ); }
	}

	private function notify( int $id, string $event ): void {
		$this->store->notify( $id, hash( 'sha256', $event . ':' . $id . ':' . bin2hex( random_bytes( 16 ) ) ), $event, $this->userLocale( $id ) );
	}

	public function limitAuthentication( mixed $user, #[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password ): mixed {
		$this->authenticationLimited = false;
		if ( $this->signingIn || '' === $username || '' === $password ) { return $user; }
		try { $allowed = strlen( $username ) <= 254 && strlen( $password ) <= 4096 && $this->rates->allow( 'login', strtolower( trim( $username ) ) ); }
		catch ( \Throwable $error ) { $allowed = false; }
		if ( ! $allowed ) { $this->authenticationLimited = true; return new \WP_Error( 'aish_auth_failed', __( 'Unable to sign in. Check your details or try again later.', 'ai-skills-hub' ) ); }
		return $user;
	}

	public function guardAuthentication( mixed $user, #[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password ): mixed {
		if ( $this->authenticationLimited ) { $user = null; }
		if ( $user instanceof \WP_User ) {
			try {
				if ( 'suspended' === $this->state( (int) $user->ID ) || ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) && $this->restricted( $user ) ) { $user = null; }
			} catch ( \Throwable $error ) { $user = null; }
			if ( $user instanceof \WP_User ) { return $user; }
		}
		if ( '' !== $username || '' !== $password ) { return new \WP_Error( 'aish_auth_failed', __( 'Unable to sign in. Check your details or try again later.', 'ai-skills-hub' ) ); }
		return $user;
	}

	public function guardRest( mixed $result ): mixed {
		if ( is_wp_error( $result ) ) { return $result; }
		$user = wp_get_current_user();
		try { if ( $user->exists() && $this->restricted( $user ) ) { return new \WP_Error( 'aish_verification_required', __( 'Account verification is required.', 'ai-skills-hub' ), array( 'status' => 403 ) ); } }
		catch ( \Throwable $error ) { return new \WP_Error( 'aish_account_unavailable', __( 'Account access is unavailable.', 'ai-skills-hub' ), array( 'status' => 503 ) ); }
		return $result;
	}

	public function guardCapabilities( array $caps, string $cap, int $userId, array $args ): array {
		if ( $this->internal > 0 || $userId < 1 ) { return $caps; }
		try {
			$user = get_userdata( $userId );
			if ( $user instanceof \WP_User && $this->restricted( $user ) && 'read' !== $cap ) { return array( 'do_not_allow' ); }
		} catch ( \Throwable $error ) { return array( 'do_not_allow' ); }
		return $caps;
	}

	/** Enclose native WP writes in the same global email/account advisory locks. */
	public function guardNativeWrite( #[\SensitiveParameter] array $data, bool $update, ?int $userId, #[\SensitiveParameter] array $userdata ): array {
		if ( $this->internal > 0 ) { return $data; }
		$acquired = false;
		try {
			$old = $update && $userId ? $this->fresh( $userId ) : null;
			$rawEmail = (string) ( $data['user_email'] ?? '' );
			// Native legacy/service identities may have no email. Preserve an
			// unchanged empty value; front-end registration always requires email.
			$email = $old && '' === $old->user_email && '' === $rawEmail ? '' : Locks::email( $rawEmail );
			$emails = array_values( array_filter( $old ? array( $old->user_email, $email ) : array( $email ), static fn( string $address ): bool => '' !== $address ) );
			$this->nativeLocks[] = $this->locks->acquire( $emails, $update ? $userId : null ); $acquired = true;
			$owner = '' === $email ? 0 : $this->emailOwner( $email );
			if ( $owner && $owner !== (int) $userId ) { throw new AccountException( 'email_conflict' ); }
			if ( $old ) {
				$current = $this->fresh( (int) $userId );
				if ( $current->user_email !== $old->user_email ) { throw new AccountException( 'save_failed' ); }
				if ( $email !== ( '' === $old->user_email ? '' : Locks::email( $old->user_email ) ) ) {
					// Revoke before the native write: a native failure cannot grant verification.
					$this->setMeta( (int) $userId, '_aish_account_state', 'pending_email' );
					$this->removeMeta( (int) $userId, '_aish_verified_email_hash' );
					$this->removeMeta( (int) $userId, '_aish_email_verified_at' );
					if ( $this->storageReady() ) { $this->store->revokeVerifications( (int) $userId ); }
					$this->removeMeta( (int) $userId, '_aish_email_change_context' );
					$this->destroyAll( (int) $userId );
				}
			}
			$data['user_email'] = $email;
			return $data;
		} catch ( \Throwable $error ) {
			if ( $acquired ) { try { array_pop( $this->nativeLocks )(); } catch ( \Throwable $releaseError ) {} }
			$this->nativeFailure( $error );
		}
	}

	public function nativeCreated( int $id, #[\SensitiveParameter] array $userdata = array() ): void {
		if ( $this->internal > 0 ) { return; }
		try {
			$user = $this->fresh( $id );
			if ( ! self::manager( $user ) ) {
				$this->setMeta( $id, '_aish_account_state', 'pending_email' );
				$this->setMeta( $id, '_aish_public_profile', '0' );
			}
		} catch ( \Throwable $error ) { $this->nativeFailure( $error ); }
		finally { $this->releaseNativeLocks(); }
	}

	public function nativeUpdated( int $id, mixed $old = null, #[\SensitiveParameter] array $userdata = array() ): void {
		if ( $this->internal > 0 ) { return; }
		try {
			$user = $this->fresh( $id );
			if ( $old instanceof \WP_User && $old->user_pass !== $user->user_pass ) { $this->nativePasswordSet( '', $id, $old ); }
		} catch ( \Throwable $error ) { $this->nativeFailure( $error ); }
		finally { $this->releaseNativeLocks(); }
	}

	/** Native resets/rehashes revoke pending email changes, not verified ownership. */
	public function nativePasswordSet( #[\SensitiveParameter] string $password, int $id, mixed $old = null ): void {
		if ( $this->internal > 0 ) { return; }
		try {
			$this->locks->withUser( $id, function () use ( $id ): void {
				if ( $this->storageReady() ) { $this->store->revokeVerifications( $id, 'change_email' ); }
				$this->removeMeta( $id, '_aish_email_change_context' );
			} );
		} catch ( \Throwable $error ) { $this->nativeFailure( $error ); }
	}

	/** Preserve native administrator recovery without its distinct unknown-email response. */
	public function nativeForgot(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return; }
		try {
			$nonce = $_POST['_aish_lostpassword_nonce'] ?? null;
			$login = $_POST['user_login'] ?? null;
			if ( is_string( $nonce ) && wp_verify_nonce( wp_unslash( $nonce ), 'aish_native_lostpassword' ) && is_string( $login ) && strlen( $login ) <= 254 ) {
				$login = trim( wp_unslash( $login ) );
				if ( $this->rates->allow( 'forgot', strtolower( $login ) ) ) { retrieve_password( $login ); }
			}
		} catch ( \Throwable $error ) { /* Same response: do not expose account existence or mail errors. */ }
		nocache_headers();
		wp_safe_redirect( add_query_arg( 'checkemail', 'confirm', wp_login_url() ), 303 ); exit;
	}

	public function releaseNativeLocks(): void {
		while ( $this->nativeLocks ) { $release = array_pop( $this->nativeLocks ); try { $release(); } catch ( \Throwable $error ) {} }
	}

	private function nativeFailure( \Throwable $error ): never {
		// A filter cannot return WP_Error here. Abort HTTP cleanly rather than
		// letting an uncaught exception expose native wp_insert_user arguments.
		if ( 'cli' !== PHP_SAPI ) { wp_die( esc_html__( 'The account could not be saved. Refresh and try again.', 'ai-skills-hub' ), '', array( 'response' => 409 ) ); }
		throw new AccountException( $error instanceof AccountException ? $error->reason() : 'save_failed' );
	}
}
