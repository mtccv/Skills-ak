<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;

use AISkillsHub\Core\Accounts\{AccountService, Crypto, Locks, Mail, MailWorker, RateLimiter, Store};
use AISkillsHub\Core\Database\{Database, Migrator};
use AISkillsHub\Core\Frontend\{Editorial, PublicCatalog};
use AISkillsHub\Core\Discovery\Query;
use AISkillsHub\Core\Community\Events;

defined( 'ABSPATH' ) || exit;

/** Explicit, double-confirmed newsletter consent for existing verified WordPress users only. */
final class Subscriptions {
	public const CAPABILITY = 'aish_manage_subscriptions';
	public const OPTION = 'aish_newsletter_settings';
	public const META = '_aish_subscription';
	public const CONSENT = 'newsletter-1';
	public const TEMPLATES = array( 'newsletter_confirm', 'newsletter_weekly', 'newsletter_new' );
	public const STATES = array( 'pending', 'active', 'unsubscribed', 'paused', 'bounced' );
	private SubscriptionCampaigns $campaigns;
	private int $policyDepth = 0;
	private bool $enginesChecked = false;

	public function __construct( private Database $db, private Migrator $migrator, private Store $store, private Mail $mail, private Locks $locks, private AccountService $accounts, PublicCatalog $catalog, Editorial $editorial, Query $discovery ) {
		$this->campaigns = new SubscriptionCampaigns( $db, $this, $catalog, $editorial, $discovery );
	}
	public function register( MailWorker $worker ): void {
		$worker->registerNewsletterHandler( array( $this, 'handleMail' ) );
		( new SubscriptionGuards( $this->db, $this ) )->register();
		$this->campaigns->register();
		// Accounts registers its native lock guard first. Internal verified changes already own that same lock/transaction.
		add_filter( 'wp_pre_insert_user_data', array( $this, 'nativeEmailChanging' ), PHP_INT_MAX, 4 );
		add_action( 'aish_account_cleanup_tick', array( $this, 'tick' ) );
		add_filter( 'aish_account_privacy_export', array( $this, 'exportAccount' ), 30, 3 );
		add_action( 'aish_account_privacy_isolate', array( $this, 'eraseAccount' ), 30 );
		add_filter( 'aish_account_privacy_erase_batch', function ( bool $done, int $id ): bool { $this->eraseAccount( $id ); return $done; }, 30, 2 );
	}
	/** Revoke before identity changes. Joining the caller's transaction avoids nested Core transactions. */
	public function nativeEmailChanging( #[\SensitiveParameter] array $data, bool $update, ?int $id, #[\SensitiveParameter] array $userdata ): array {
		if ( ! $update || ! $id || ! array_key_exists( 'user_email', $data ) ) { return $data; }
		$this->locks->withUser( $id, function () use ( $data, $id ): void {
			$old = $this->db->value( 'SELECT user_email FROM ' . $this->db->connection()->users . ' WHERE ID=%d', array( $id ) );
			if ( ! is_string( $old ) || strtolower( trim( $old ) ) === strtolower( trim( (string) $data['user_email'] ) ) ) { return; }
			$s = $this->rawState( $id, true ); if ( ! $s || ! in_array( $s['state'], array( 'pending', 'active' ), true ) ) { return; }
			$from = $s['state']; $s['state'] = 'paused'; ++$s['revision']; $s['confirmed_at'] = ''; $s['updated_at'] = self::now(); $s['reason_hash'] = hash( 'sha256', 'account_email_identity_changed' );
			// State is withdrawn first: a later native failure cannot restore marketing consent.
			$this->writeState( $id, $s ); $this->cancelMail( $id ); $this->store->revokeVerifications( $id, 'newsletter' ); $this->audit( $id, $s, 'newsletter_email_changed', $from, $id );
		} ); return $data;
	}
	public function storageKey(): string { return self::META . '_' . substr( hash( 'sha256', $this->db->prefix() ), 0, 16 ); }
	private function tokenKey( string $token ): string { return self::META . '_token_' . substr( hash( 'sha256', $this->db->prefix() ), 0, 16 ) . '_' . self::tokenHash( $token ); }
	private static function tokenHash( string $token ): string { return hash_hmac( 'sha256', 'aish-newsletter-unsubscribe:' . $token, wp_salt( 'auth' ) ); }
	private static function hashToken( mixed $token ): bool { return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $token ); }
	private static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }
	public static function defaults(): array { return array( 'revision' => 0, 'enabled' => false, 'paused' => true ); }
	public static function validateConfig( array $input ): array {
		if ( array_diff( array_keys( $input ), array( 'enabled', 'paused' ) ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$result = array(); foreach ( array( 'enabled', 'paused' ) as $key ) {
			$value = $input[ $key ] ?? false; if ( ! is_bool( $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } $result[ $key ] = $value;
		} return $result;
	}
	public function snapshot(): array {
		// Security gates deliberately read the database, not a possibly stale object-cache option.
		$raw = $this->db->value( 'SELECT option_value FROM ' . $this->db->connection()->options . ' WHERE option_name=%s', array( self::OPTION ) );
		if ( null === $raw ) { return self::defaults(); }
		$data = is_string( $raw ) ? json_decode( $raw, true, 8 ) : null;
		if ( ! is_array( $data ) || ! is_int( $data['revision'] ?? null ) || $data['revision'] < 0 ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$revision = $data['revision']; unset( $data['revision'] ); return array( 'revision' => $revision ) + self::validateConfig( $data );
	}
	public function publicStatus(): array {
		try { $config = $this->snapshot(); return array( 'enabled' => $this->migrator->isCurrent() && $config['enabled'], 'paused' => $config['paused'], 'mail_ready' => $this->mail->ready() ); }
		catch ( \Throwable $error ) { return array( 'enabled' => false, 'paused' => true, 'mail_ready' => false ); }
	}
	public function requireManager(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->migrator->isCurrent() || $this->accounts->restricted( wp_get_current_user() ) ) { throw new \DomainException( 'forbidden' ); }
	}
	/** Internal ordering: fixed policy lock, then native user lock; never a transaction across transport. */
	public function withPolicy( callable $operation ): mixed {
		if ( $this->policyDepth > 0 ) { return $operation(); }
		$name = 'aish_newsletter:' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 46 );
		$mode = class_exists( '\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null; $held = false;
		if ( null !== $mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try {
			$held = 1 === (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $name ) ); if ( ! $held ) { throw new \RuntimeException( 'busy' ); }
			++$this->policyDepth; return $operation();
		} finally {
			if ( $held ) { --$this->policyDepth; try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $name ) ); } catch ( \Throwable $ignored ) {} }
			if ( null !== $mode ) { mysqli_report( $mode ); }
		}
	}
	private function transaction( callable $operation, int $userId = 0 ): mixed {
		if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'storage_unavailable' ); }
		if ( ! $this->enginesChecked ) {
			$n = $this->db->connection(); foreach ( array( $n->users, $n->usermeta, $n->options ) as $table ) {
				$engine = $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) );
				if ( 'innodb' !== strtolower( (string) $engine ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
			} $this->enginesChecked = true;
		}
		$suspend = wp_suspend_cache_addition(); wp_suspend_cache_addition( true );
		$denyMail = static fn() => false; $denyHttp = static fn() => new \WP_Error( 'aish_transaction_network', 'Network unavailable in newsletter transaction.' );
		add_filter( 'pre_wp_mail', $denyMail, PHP_INT_MAX ); add_filter( 'pre_http_request', $denyHttp, PHP_INT_MAX );
		try { return $this->db->transaction( $operation ); }
		finally {
			remove_filter( 'pre_wp_mail', $denyMail, PHP_INT_MAX ); remove_filter( 'pre_http_request', $denyHttp, PHP_INT_MAX ); wp_suspend_cache_addition( $suspend );
			wp_cache_delete( self::OPTION, 'options' ); if ( $userId ) { wp_cache_delete( $userId, 'user_meta' ); clean_user_cache( $userId ); }
		}
	}
	public function saveConfig( array $input, int $revision ): array {
		$this->requireManager(); $config = self::validateConfig( $input );
		return $this->withPolicy( function () use ( $config, $revision ): array {
			return $this->transaction( function () use ( $config, $revision ): array {
				$this->requireManager(); $old = $this->snapshot(); if ( $old['revision'] !== $revision ) { throw new \RuntimeException( 'revision_conflict' ); }
				if ( $config['enabled'] && ! $this->mail->ready() ) { throw new \RuntimeException( 'mail_unavailable' ); }
				$next = array( 'revision' => $revision + 1 ) + $config;
				$this->db->execute( 'INSERT INTO ' . $this->db->connection()->options . " (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'", array( self::OPTION, wp_json_encode( $next ) ) );
				( new Events( $this->db ) )->record( hash( 'sha256', 'newsletter-config:' . $next['revision'] ), 'mail_queue', 1, $next['revision'], 'newsletter_configured', get_current_user_id(), null, $next['paused'] ? 'paused' : 'enabled', array( 'kind' => 'newsletter', 'payload_hash' => hash( 'sha256', wp_json_encode( $config ) ) ) );
				return $next;
			} );
		} );
	}
	public static function preferences( array $input ): array {
		if ( array_diff( array_keys( $input ), array( 'locale', 'weekly', 'new_resources', 'consent' ) ) || ! in_array( $input['locale'] ?? null, array( 'zh_CN', 'en_US' ), true ) || ! is_bool( $input['weekly'] ?? null ) || ! is_bool( $input['new_resources'] ?? null ) || true !== ( $input['consent'] ?? null ) || ( ! $input['weekly'] && ! $input['new_resources'] ) ) { throw new \InvalidArgumentException( 'consent_required' ); }
		return array( 'locale' => $input['locale'], 'weekly' => $input['weekly'], 'new_resources' => $input['new_resources'] );
	}
	/** Internal storage read; never projected directly into a response. Duplicate protected metadata fails closed. */
	public function rawState( int $userId, bool $lock = false ): ?array {
		if ( $userId < 1 ) { return null; }
		$rows = $this->db->rows( 'SELECT meta_value FROM ' . $this->db->connection()->usermeta . ' WHERE user_id=%d AND meta_key=%s' . ( $lock ? ' FOR UPDATE' : '' ), array( $userId, $this->storageKey() ) );
		if ( ! $rows ) { return null; } if ( count( $rows ) !== 1 ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$s = json_decode( (string) $rows[0]['meta_value'], true, 8 );
		if ( ! is_array( $s ) || 1 !== ( $s['version'] ?? null ) || ! is_int( $s['revision'] ?? null ) || $s['revision'] < 1 || ! is_int( $s['generation'] ?? null ) || $s['generation'] < 1 || ! in_array( $s['state'] ?? null, self::STATES, true ) || ! in_array( $s['locale'] ?? null, array( 'zh_CN', 'en_US' ), true ) || ! is_bool( $s['weekly'] ?? null ) || ! is_bool( $s['new_resources'] ?? null ) || ! self::hashToken( $s['email_hash'] ?? null ) || ! self::hashToken( $s['nonce'] ?? null ) || ! self::hashToken( $s['unsubscribe_hash'] ?? null ) || ! is_string( $s['key_version'] ?? null ) || ! is_int( $s['verification_id'] ?? null ) || $s['verification_id'] < 1 || self::CONSENT !== ( $s['consent_version'] ?? null ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
		foreach ( array( 'requested_at', 'confirmed_at', 'unsubscribed_at', 'updated_at', 'expires_at', 'request_hash', 'payload_hash' ) as $key ) { if ( ! is_string( $s[ $key ] ?? null ) ) { throw new \RuntimeException( 'storage_unavailable' ); } }
		$allowed = array( 'version', 'revision', 'generation', 'state', 'locale', 'weekly', 'new_resources', 'consent_version', 'email_hash', 'nonce', 'key_version', 'unsubscribe_hash', 'verification_id', 'requested_at', 'confirmed_at', 'unsubscribed_at', 'updated_at', 'expires_at', 'request_hash', 'payload_hash', 'reason_hash' );
		if ( array_diff( array_keys( $s ), $allowed ) || strlen( $s['key_version'] ) > 48 || ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $s['key_version'] ) || ! self::hashToken( $s['request_hash'] ) || ! self::hashToken( $s['payload_hash'] ) || ( isset( $s['reason_hash'] ) && ! self::hashToken( $s['reason_hash'] ) ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
		foreach ( array( 'requested_at', 'confirmed_at', 'unsubscribed_at', 'updated_at', 'expires_at' ) as $key ) { if ( '' === $s[ $key ] && in_array( $key, array( 'confirmed_at', 'unsubscribed_at' ), true ) ) { continue; } if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $s[ $key ] ) ) { throw new \RuntimeException( 'storage_unavailable' ); } }
		return $s;
	}
	private function writeState( int $id, array $state, ?string $newToken = null ): void {
		$n = $this->db->connection(); $key = $this->storageKey(); $json = wp_json_encode( $state );
		$rows = $this->db->rows( "SELECT umeta_id FROM {$n->usermeta} WHERE user_id=%d AND meta_key=%s FOR UPDATE", array( $id, $key ) );
		if ( count( $rows ) > 1 ) { throw new \RuntimeException( 'storage_unavailable' ); }
		if ( $rows ) { $this->db->execute( "UPDATE {$n->usermeta} SET meta_value=%s WHERE umeta_id=%d", array( $json, (int) $rows[0]['umeta_id'] ) ); }
		else { $this->db->execute( "INSERT INTO {$n->usermeta} (user_id,meta_key,meta_value) VALUES (%d,%s,%s)", array( $id, $key, $json ) ); }
		if ( null !== $newToken ) {
			$prefix = self::META . '_token_' . substr( hash( 'sha256', $this->db->prefix() ), 0, 16 ) . '_';
			$this->db->execute( "DELETE FROM {$n->usermeta} WHERE user_id=%d AND meta_key LIKE %s", array( $id, $n->esc_like( $prefix ) . '%' ) );
			$this->db->execute( "INSERT INTO {$n->usermeta} (user_id,meta_key,meta_value) VALUES (%d,%s,%s)", array( $id, $this->tokenKey( $newToken ), (string) $state['generation'] ) );
		}
		wp_cache_delete( $id, 'user_meta' );
	}
	private function audit( int $id, array $state, string $action, ?string $from, ?int $actor = null ): void {
		( new Events( $this->db ) )->record( hash( 'sha256', 'newsletter:' . $id . ':' . $state['nonce'] . ':' . $state['revision'] . ':' . $action ), 'user', $id, $state['revision'], $action, $actor, $from, $state['state'], array_filter( array( 'kind' => 'newsletter', 'payload_hash' => $state['reason_hash'] ?? '' ) ) );
	}
	public function eligibleUser( int $id ): ?\WP_User {
		$n = $this->db->connection(); if ( ! $this->db->row( "SELECT ID FROM {$n->users} WHERE ID=%d", array( $id ) ) ) { return null; }
		clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); $user = get_userdata( $id );
		return $user instanceof \WP_User && $this->accounts->verified( $id ) && ! $this->accounts->restricted( $user ) ? $user : null;
	}
	/** Current session only; no user/email identifier from the form can choose a subscriber. */
	public function request( array $input, string $requestKey ): void {
		$prefs = self::preferences( $input ); if ( ! preg_match( '/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $requestKey ) ) { throw new \InvalidArgumentException( 'invalid_request' ); }
		$user = $this->accounts->requireOwner(); $id = (int) $user->ID; $email = Locks::email( $user->user_email );
		$requestHash = hash_hmac( 'sha256', 'newsletter-request:' . $id . ':' . $requestKey, wp_salt( 'nonce' ) ); $payloadHash = hash( 'sha256', wp_json_encode( $prefs ) );
		$this->withPolicy( function () use ( $id, $email, $prefs, $requestHash, $payloadHash ): void {
			$config = $this->snapshot(); if ( ! $config['enabled'] || ! $this->mail->ready() ) { throw new \RuntimeException( 'mail_unavailable' ); }
			$this->locks->with( array( $email ), $id, function () use ( $id, $email, $prefs, $requestHash, $payloadHash ): void {
				$previous = $this->rawState( $id );
				if ( $previous && hash_equals( $previous['request_hash'], $requestHash ) ) { if ( ! hash_equals( $previous['payload_hash'], $payloadHash ) ) { throw new \RuntimeException( 'request_conflict' ); } return; }
				if ( ! ( new RateLimiter( $this->db ) )->allow( 'newsletter', 'user:' . $id ) ) { throw new \RuntimeException( 'rate_limited' ); }
				$this->transaction( function () use ( $id, $email, $prefs, $requestHash, $payloadHash ): void {
					$user = $this->eligibleUser( $id ); if ( ! $user || Locks::email( $user->user_email ) !== $email ) { throw new \DomainException( 'verification_required' ); }
					$old = $this->rawState( $id, true );
					if ( $old && hash_equals( $old['request_hash'], $requestHash ) ) { if ( ! hash_equals( $old['payload_hash'], $payloadHash ) ) { throw new \RuntimeException( 'request_conflict' ); } return; }
					if ( $old && 'active' === $old['state'] && $old['email_hash'] === Store::emailHash( $email ) && hash_equals( $old['payload_hash'], $payloadHash ) ) { return; }
					if ( $old && $old['requested_at'] > gmdate( 'Y-m-d H:i:s', time() - 60 ) ) { throw new \RuntimeException( 'rate_limited' ); }
					// Existing duplicate native addresses are ambiguous, not two subscriptions to one inbox.
					if ( 1 !== (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->connection()->users . ' WHERE LOWER(user_email)=%s', array( $email ) ) ) { throw new \RuntimeException( 'account_unavailable' ); }
					$this->cancelMail( $id ); $generation = ( $old['generation'] ?? 0 ) + 1; $nonce = bin2hex( random_bytes( 32 ) ); $derived = Crypto::newsletterToken( $id, $generation, $nonce ); $token = bin2hex( random_bytes( 32 ) );
					$verification = $this->store->issueVerification( $id, 'newsletter', $email, $token ); $now = self::now();
					$state = array( 'version' => 1, 'revision' => ( $old['revision'] ?? 0 ) + 1, 'generation' => $generation, 'state' => 'pending' ) + $prefs + array( 'consent_version' => self::CONSENT, 'email_hash' => Store::emailHash( $email ), 'nonce' => $nonce, 'key_version' => $derived['key_version'], 'unsubscribe_hash' => self::tokenHash( $derived['token'] ), 'verification_id' => (int) $verification['id'], 'requested_at' => $now, 'confirmed_at' => '', 'unsubscribed_at' => '', 'updated_at' => $now, 'expires_at' => $verification['expires_at'], 'request_hash' => $requestHash, 'payload_hash' => $payloadHash );
					$this->writeState( $id, $state, $derived['token'] );
					$this->store->queue( array( 'recipient_user_id' => $id, 'notification_id' => null, 'event_key' => self::confirmationEvent( (int) $verification['id'] ), 'template_key' => 'newsletter_confirm', 'locale' => $state['locale'], 'destination_hash' => Store::emailHash( $email ), 'expires_at' => $verification['expires_at'] ), array( 'version' => 1, 'destination' => $email, 'email_hash' => Store::emailHash( $email ), 'generation' => $generation, 'verification_id' => (int) $verification['id'], 'token' => $token ) );
					$this->audit( $id, $state, 'newsletter_requested', $old['state'] ?? null, $id );
				}, $id );
			} );
		} ); do_action( 'aish_account_mail_wake' );
	}
	private static function confirmationEvent( int $id ): string { return hash( 'sha256', 'newsletter-confirmation:' . $id ); }
	public function confirm( #[\SensitiveParameter] string $token ): string {
		if ( ! self::hashToken( $token ) ) { return 'invalid'; } try { $owner = $this->accounts->requireOwner(); } catch ( \Throwable $error ) { return 'invalid'; } $v = $this->store->verification( $token );
		if ( ! $v || 'newsletter' !== $v['purpose'] || (int) $owner->ID !== (int) $v['user_id'] ) { return 'invalid'; }
		if ( $v['expires_at'] <= self::now() ) { return 'expired'; }
		$id = (int) $v['user_id'];
		return $this->locks->with( array( $v['target_email'] ), $id, function () use ( $id, $token ): string {
			return $this->transaction( function () use ( $id, $token ): string {
				$v = $this->store->verification( $token, true ); $s = $this->rawState( $id, true ); $user = $this->eligibleUser( $id );
				if ( ! $v || ! $s || ! $user || 'newsletter' !== $v['purpose'] || (int) $v['id'] !== $s['verification_id'] || ! hash_equals( $s['email_hash'], Store::emailHash( $user->user_email ) ) || ! hash_equals( $s['email_hash'], Store::emailHash( $v['target_email'] ) ) ) { return 'invalid'; }
				if ( $v['expires_at'] <= self::now() ) { return 'expired'; }
				if ( ! empty( $v['consumed_at'] ) ) { return 'active' === $s['state'] ? 'active' : 'invalid'; }
				if ( 'pending' !== $s['state'] || ! $this->store->consumeVerification( (int) $v['id'] ) ) { return 'invalid'; }
				// Missing historical token keys cannot silently replace older cancellation links.
				$derived = Crypto::newsletterToken( $id, $s['generation'], $s['nonce'], $s['key_version'] ); if ( ! hash_equals( $s['unsubscribe_hash'], self::tokenHash( $derived['token'] ) ) ) { throw new \RuntimeException( 'mail_unavailable' ); }
				$s['state'] = 'active'; ++$s['revision']; $s['confirmed_at'] = $s['updated_at'] = self::now(); $this->writeState( $id, $s ); $this->audit( $id, $s, 'newsletter_confirmed', 'pending', null ); return 'active';
			}, $id );
		} );
	}
	private function unsubscribeIdentity( #[\SensitiveParameter] string $token ): ?array {
		if ( ! self::hashToken( $token ) ) { return null; }
		$rows = $this->db->rows( 'SELECT user_id,meta_value FROM ' . $this->db->connection()->usermeta . ' WHERE meta_key=%s LIMIT 2', array( $this->tokenKey( $token ) ) );
		if ( 1 !== count( $rows ) ) { return null; } $id = (int) $rows[0]['user_id']; $s = $this->rawState( $id );
		if ( ! $s || (string) $s['generation'] !== $rows[0]['meta_value'] || ! hash_equals( $s['unsubscribe_hash'], self::tokenHash( $token ) ) ) { return null; }
		try { $derived = Crypto::newsletterToken( $id, $s['generation'], $s['nonce'], $s['key_version'] ); if ( ! hash_equals( $derived['token'], $token ) ) { return null; } } catch ( \Throwable $error ) { return null; }
		return array( 'id' => $id, 'state' => $s );
	}
	public function tokenContext( #[\SensitiveParameter] string $token, string $purpose ): ?array {
		if ( ! self::hashToken( $token ) ) { return null; }
		if ( 'unsubscribe' === $purpose ) { $found = $this->unsubscribeIdentity( $token ); return $found ? array( 'locale' => $found['state']['locale'], 'state' => $found['state']['state'] ) : null; }
		if ( 'confirm' !== $purpose ) { return null; } try { $owner = $this->accounts->requireOwner(); } catch ( \Throwable $error ) { return null; } $v = $this->store->verification( $token ); if ( ! $v || 'newsletter' !== $v['purpose'] || (int) $owner->ID !== (int) $v['user_id'] || $v['expires_at'] <= self::now() ) { return null; }
		$s = $this->rawState( (int) $v['user_id'] ); $user = $this->eligibleUser( (int) $v['user_id'] );
		return $s && $user && in_array( $s['state'], array( 'pending', 'active' ), true ) && $s['verification_id'] === (int) $v['id'] && hash_equals( $s['email_hash'], Store::emailHash( $user->user_email ) ) ? array_intersect_key( $s, array_flip( array( 'locale', 'state', 'weekly', 'new_resources', 'expires_at' ) ) ) : null;
	}
	private function cancelMail( int $id ): void {
		$this->db->execute( 'UPDATE ' . $this->db->table( 'notification_outbox' ) . " SET status='cancelled',payload_ciphertext=NULL,lease_token='',locked_until=NULL,last_error='mail_context_changed',updated_at=%s WHERE recipient_user_id=%d AND template_key IN ('newsletter_confirm','newsletter_weekly','newsletter_new') AND status IN ('queued','retry','claimed')", array( self::now(), $id ) );
		// A completed cancellation cannot erase evidence of an already initiated/accepted transport.
	}
	private function stop( int $id, string $state, ?int $revision = null, ?int $actor = null, string $reasonHash = '' ): void {
		$this->locks->withUser( $id, function () use ( $id, $state, $revision, $actor, $reasonHash ): void {
			$this->transaction( function () use ( $id, $state, $revision, $actor, $reasonHash ): void {
				$s = $this->rawState( $id, true ); if ( ! $s ) { return; } if ( null !== $revision && $revision !== $s['revision'] ) { throw new \RuntimeException( 'revision_conflict' ); }
				$this->cancelMail( $id ); $this->store->revokeVerifications( $id, 'newsletter' ); if ( $state === $s['state'] && ( '' === $reasonHash || hash_equals( $s['reason_hash'] ?? '', $reasonHash ) ) ) { return; }
				$from = $s['state']; $s['state'] = $state; if ( '' !== $reasonHash ) { $s['reason_hash'] = $reasonHash; } ++$s['revision']; $s['updated_at'] = self::now(); if ( 'unsubscribed' === $state ) { $s['unsubscribed_at'] = self::now(); }
				$this->writeState( $id, $s ); $this->audit( $id, $s, 'newsletter_' . $state, $from, $actor );
			}, $id );
		} );
	}
	public function unsubscribe( #[\SensitiveParameter] string $token ): bool {
		$found = $this->unsubscribeIdentity( $token ); if ( ! $found ) { return false; } $id = $found['id'];
		return $this->locks->withUser( $id, function () use ( $token, $id ): bool { if ( ! $this->unsubscribeIdentity( $token ) ) { return false; } $this->stop( $id, 'unsubscribed' ); return true; } );
	}
	public function accountUnsubscribe(): void {
		$id = get_current_user_id(); if ( $id < 1 || ! ( get_userdata( $id ) instanceof \WP_User ) ) { throw new \DomainException( 'login_required' ); } $this->stop( $id, 'unsubscribed', null, $id );
	}
	private function project( int $id, ?array $s, bool $manager = false ): ?array {
		if ( ! $s ) { return null; } $result = array_intersect_key( $s, array_flip( array( 'revision', 'state', 'locale', 'weekly', 'new_resources', 'consent_version', 'requested_at', 'confirmed_at', 'unsubscribed_at', 'updated_at', 'expires_at' ) ) );
		$user = $this->eligibleUser( $id ); if ( in_array( $s['state'], array( 'active', 'pending' ), true ) && ( ! $user || ! hash_equals( $s['email_hash'], Store::emailHash( $user->user_email ) ) ) ) { $result['state'] = 'needs_confirmation'; }
		elseif ( 'pending' === $s['state'] && $s['expires_at'] <= self::now() ) { $result['state'] = 'expired'; }
		if ( $manager ) { $result['id'] = $id; $user = get_userdata( $id ); $result['email'] = $user ? '***@' . substr( $user->user_email, (int) strpos( $user->user_email, '@' ) + 1 ) : ''; }
		return $result;
	}
	public function accountPreferences(): ?array { $id = get_current_user_id(); if ( $id < 1 || ! ( get_userdata( $id ) instanceof \WP_User ) ) { throw new \DomainException( 'login_required' ); } return $this->project( $id, $this->rawState( $id ) ); }
	public function updatePreferences( array $input, string $requestKey ): void { $this->request( $input, $requestKey ); }
	public function listing( int $page = 1, string $state = '' ): array {
		$this->requireManager(); if ( $page < 1 || $page > 10000 || ( '' !== $state && ! in_array( $state, self::STATES, true ) ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$n = $this->db->connection(); $where = 'meta_key=%s'; $values = array( $this->storageKey() ); if ( '' !== $state ) { $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(meta_value) THEN meta_value ELSE '{}' END,'$.state'))=%s"; $values[] = $state; }
		$total = (int) $this->db->value( "SELECT COUNT(DISTINCT user_id) FROM {$n->usermeta} WHERE $where", $values );
		$rows = $this->db->rows( "SELECT DISTINCT user_id FROM {$n->usermeta} WHERE $where ORDER BY user_id DESC LIMIT 20 OFFSET %d", array_merge( $values, array( ( $page - 1 ) * 20 ) ) ); $items = array();
		foreach ( $rows as $row ) { $item = $this->project( (int) $row['user_id'], $this->rawState( (int) $row['user_id'] ), true ); if ( $item ) { $items[] = $item; } }
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => 20 );
	}
	public function detail( int $id ): array { $this->requireManager(); $result = $this->project( $id, $this->rawState( $id ), true ); if ( ! $result ) { throw new \RuntimeException( 'not_found' ); } return $result; }
	public function setState( int $id, int $revision, string $state, string $reason ): void {
		$this->requireManager(); if ( ! in_array( $state, array( 'paused', 'unsubscribed', 'bounced' ), true ) || '' === trim( $reason ) || strlen( $reason ) > 600 ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$this->stop( $id, $state, $revision, get_current_user_id(), hash( 'sha256', trim( $reason ) ) );
	}
	public function markBounce( int $id, int $revision, string $reason ): void { $this->setState( $id, $revision, 'bounced', $reason ); }
	public function preview( string $locale, string $kind = 'weekly' ): array { $this->requireManager(); return $this->campaigns->preview( $locale, $kind ); }
	public function queueIssue( string $locale, string $kind, string $requestKey, string $fingerprint = '' ): int { $this->requireManager(); return $this->campaigns->queueIssue( $locale, $kind, $requestKey, $fingerprint ); }
	public function issues( int $page = 1 ): array { $this->requireManager(); return $this->campaigns->listing( $page ); }
	public function issueDetail( int $id ): array { $this->requireManager(); return $this->campaigns->detail( $id ); }
	public function retryIssue( int $id ): void { $this->requireManager(); $this->campaigns->retry( $id ); }
	public function tick(): void { if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) { $this->cleanup(); $this->campaigns->tick(); } }
	private function cleanup(): void {
		$n = $this->db->connection(); $state = "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(meta_value) THEN meta_value ELSE '{}' END,'$.state'))"; $updated = "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(meta_value) THEN meta_value ELSE '{}' END,'$.updated_at'))";
		$rows = $this->db->rows( "SELECT DISTINCT user_id FROM {$n->usermeta} WHERE meta_key=%s AND (($state='pending' AND $updated<%s) OR ($state IN ('unsubscribed','bounced','paused') AND $updated<%s)) ORDER BY user_id LIMIT 10", array( $this->storageKey(), gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ), gmdate( 'Y-m-d H:i:s', time() - 180 * 86400 ) ) );
		foreach ( $rows as $row ) { $id = (int) $row['user_id']; try { $this->locks->withUser( $id, function () use ( $id ): void { $s = $this->rawState( $id ); if ( ! $s ) { return; } $days = 'pending' === $s['state'] ? 30 : 180; if ( 'active' === $s['state'] || $s['updated_at'] >= gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ) ) { return; } $this->eraseAccount( $id ); } ); } catch ( \Throwable $ignored ) { /* Retry next bounded Cron pass without sensitive logging. */ } }
	}
	public function mailFailures( int $page = 1 ): array {
		$this->requireManager(); if ( $page < 1 || $page > 10000 ) { throw new \InvalidArgumentException( 'invalid_input' ); } $table = $this->db->table( 'notification_outbox' );
		$where = "template_key IN ('newsletter_confirm','newsletter_weekly','newsletter_new') AND status IN ('retry','failed','uncertain','cancelled','expired')";
		$rows = $this->db->rows( "SELECT id,template_key,locale,status,last_error,attempts,created_at,updated_at,available_at FROM $table WHERE $where ORDER BY id DESC LIMIT 20 OFFSET %d", array( ( $page - 1 ) * 20 ) );
		foreach ( $rows as &$row ) { $row['last_error'] = Store::error( (string) $row['last_error'] ); } unset( $row );
		return array( 'items' => $rows, 'total' => (int) $this->db->value( "SELECT COUNT(*) FROM $table WHERE $where" ), 'page' => $page, 'per_page' => 20 );
	}
	/** Internal bounded campaign recipient operation, guarded by the same native lock as cancellation/deletion. */
	public function withRecipient( int $id, callable $operation ): mixed { return $this->locks->withUser( $id, fn() => $operation( $this->rawState( $id ), $this->eligibleUser( $id ) ) ); }
	public function queueRecipient( int $issueId, array $issue, int $id, ?array $s, ?\WP_User $user ): bool {
		if ( ! $s || ! $user || 'active' !== $s['state'] || $issue['locale'] !== $s['locale'] || ! $s[ $issue['kind'] ] || ! hash_equals( $s['email_hash'], Store::emailHash( $user->user_email ) ) || '' === $s['confirmed_at'] || $s['confirmed_at'] > $issue['created_at'] ) { return false; }
		$derived = Crypto::newsletterToken( $id, $s['generation'], $s['nonce'], $s['key_version'] ); if ( ! hash_equals( $s['unsubscribe_hash'], self::tokenHash( $derived['token'] ) ) ) { throw new \RuntimeException( 'mail_unavailable' ); }
		$template = 'weekly' === $issue['kind'] ? 'newsletter_weekly' : 'newsletter_new'; $event = hash( 'sha256', 'newsletter-issue:' . $issueId . ':' . $id . ':' . $s['generation'] . ':' . $s['email_hash'] );
		$this->store->queue( array( 'recipient_user_id' => $id, 'notification_id' => null, 'event_key' => $event, 'template_key' => $template, 'locale' => $s['locale'], 'destination_hash' => $s['email_hash'], 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'version' => 1, 'destination' => Locks::email( $user->user_email ), 'email_hash' => $s['email_hash'], 'generation' => $s['generation'], 'issue_id' => $issueId ) ); return true;
	}
	/** Existing MailWorker hands its leased row here; no second queue or unbounded worker is introduced. */
	public function handleMail( #[\SensitiveParameter] array $row ): void {
		$id = (int) $row['id']; $lease = (string) $row['lease_token']; $sending = false;
		try {
			$this->withPolicy( function () use ( $row, $id, $lease, &$sending ): void {
				$config = $this->snapshot(); $confirmation = 'newsletter_confirm' === $row['template_key'];
				if ( ! $config['enabled'] ) { $this->store->finish( $id, $lease, 'cancelled', 'mail_context_changed' ); return; }
				if ( ! $confirmation && $config['paused'] ) { $this->store->deferNewsletter( $id, $lease ); return; }
				$userId = (int) $row['recipient_user_id']; if ( $userId < 1 ) { $this->store->finish( $id, $lease, 'cancelled', 'mail_recipient_unavailable' ); return; }
				$this->locks->withUser( $userId, function () use ( $row, $id, $lease, $userId, $confirmation, &$sending ): void {
					$p = $this->store->decryptPayload( $row ); $s = $this->rawState( $userId ); $user = $this->eligibleUser( $userId );
					$allowed = array( 'version', 'destination', 'email_hash', 'generation' ); $allowed = array_merge( $allowed, $confirmation ? array( 'verification_id', 'token' ) : array( 'issue_id' ) );
					if ( ! $s || ! $user || array_diff( array_keys( $p ), $allowed ) || 1 !== ( $p['version'] ?? null ) || ! is_string( $p['destination'] ?? null ) || ! self::hashToken( $p['email_hash'] ?? null ) || ! is_int( $p['generation'] ?? null ) || $p['generation'] !== $s['generation'] || ! hash_equals( $s['email_hash'], Store::emailHash( $user->user_email ) ) || ! hash_equals( $s['email_hash'], Store::emailHash( $p['destination'] ) ) || ! hash_equals( $s['email_hash'], $p['email_hash'] ) || ! hash_equals( $s['email_hash'], (string) $row['destination_hash'] ) || $s['locale'] !== $row['locale'] || $row['expires_at'] <= self::now() ) { $this->store->finish( $id, $lease, 'cancelled', 'mail_context_changed' ); return; }
					$derived = Crypto::newsletterToken( $userId, $s['generation'], $s['nonce'], $s['key_version'] ); if ( ! hash_equals( $s['unsubscribe_hash'], self::tokenHash( $derived['token'] ) ) ) { throw new \RuntimeException( 'mail_unavailable' ); }
					if ( $confirmation ) {
						$v = is_string( $p['token'] ?? null ) ? $this->store->verification( $p['token'] ) : null;
						if ( 'pending' !== $s['state'] || ! $v || 'newsletter' !== $v['purpose'] || $v['expires_at'] <= self::now() || empty( $v['active_key'] ) || ! empty( $v['consumed_at'] ) || (int) $v['user_id'] !== $userId || (int) $v['id'] !== ( $p['verification_id'] ?? null ) || (int) $v['id'] !== $s['verification_id'] || ! hash_equals( $row['event_key'], self::confirmationEvent( (int) $v['id'] ) ) || ! hash_equals( $s['email_hash'], Store::emailHash( $v['target_email'] ) ) ) { $this->store->finish( $id, $lease, 'cancelled', 'mail_context_changed' ); return; }
						$zh = 'zh_CN' === $row['locale']; $url = add_query_arg( 'token', $p['token'], SubscriptionFrontend::url( 'confirm', $row['locale'] ) );
						$message = array( 'subject' => '[AI Skills Hub] ' . ( $zh ? '确认自愿邮件订阅' : 'Confirm your optional email subscription' ), 'body' => ( $zh ? '您主动请求了邮件订阅。账户注册本身不会订阅。请打开以下链接并确认您的选择：' : 'You requested optional emails. Registration alone does not subscribe you. Open this link and confirm your choices:' ) . "\n\n" . $url . "\n\n" . ( $zh ? '每周精选：' : 'Weekly selections: ' ) . ( $s['weekly'] ? ( $zh ? '是' : 'yes' ) : ( $zh ? '否' : 'no' ) ) . "\n" . ( $zh ? '新资源通知：' : 'New resources: ' ) . ( $s['new_resources'] ? ( $zh ? '是' : 'yes' ) : ( $zh ? '否' : 'no' ) ) . "\n\n" . ( $zh ? '链接24小时内有效；若非本人操作请忽略。' : 'The link expires within 24 hours. Ignore this message if you did not request it.' ) );
					} else {
						$kind = 'newsletter_weekly' === $row['template_key'] ? 'weekly' : 'new_resources';
						if ( 'active' !== $s['state'] || ! $s[ $kind ] || ! is_int( $p['issue_id'] ?? null ) || ! hash_equals( $row['event_key'], hash( 'sha256', 'newsletter-issue:' . $p['issue_id'] . ':' . $userId . ':' . $s['generation'] . ':' . $s['email_hash'] ) ) ) { $this->store->finish( $id, $lease, 'cancelled', 'mail_context_changed' ); return; }
						$message = $this->campaigns->message( $p['issue_id'], $row['locale'], $kind );
						if ( ! $message['items'] ) { $this->store->finish( $id, $lease, 'cancelled', 'mail_context_changed' ); return; }
						$unsubscribe = add_query_arg( 'token', $derived['token'], SubscriptionFrontend::url( 'unsubscribe', $row['locale'] ) );
						$message['body'] .= "\n\n" . ( 'zh_CN' === $row['locale'] ? '取消订阅：' : 'Unsubscribe: ' ) . $unsubscribe;
						$message['unsubscribe_url'] = add_query_arg( array( 'action' => 'aish_subscription_unsubscribe', 'token' => $derived['token'] ), admin_url( 'admin-post.php' ) );
					}
					if ( ! $this->store->markSending( $id, $lease ) ) { return; } $sending = true;
					$result = $this->mail->send( $p['destination'], $message );
					if ( 'accepted' === $result ) { $this->store->finish( $id, $lease, 'accepted' ); }
					elseif ( 'rejected' !== $result ) { $this->store->finish( $id, $lease, 'uncertain', 'mail_result_uncertain' ); }
					elseif ( (int) $row['attempts'] < 3 ) { $this->store->retry( $id, $lease, 60 * ( 2 ** max( 0, (int) $row['attempts'] - 1 ) ), 'mail_transport_rejected' ); }
					else { $this->store->finish( $id, $lease, 'failed', 'mail_transport_rejected' ); }
				} );
			} );
		} catch ( \Throwable $error ) { try {
			if ( ! $sending && in_array( $error->getMessage(), array( 'busy', 'Account is busy. Retry later.' ), true ) && (int) $row['attempts'] < 5 ) { $this->store->retry( $id, $lease, 60 * ( 2 ** max( 0, (int) $row['attempts'] - 1 ) ), 'mail_worker_interrupted' ); }
			else { $this->store->finish( $id, $lease, $sending ? 'uncertain' : 'failed', $sending ? 'mail_result_uncertain' : 'mail_preflight_failed' ); }
		} catch ( \Throwable $ignored ) {} }
	}
	public function exportAccount( array $result, \WP_User $user, int $page ): array {
		if ( 1 !== $page ) { return $result; } $s = $this->project( (int) $user->ID, $this->rawState( (int) $user->ID ) );
		if ( $s ) { $fields = array(); foreach ( $s as $key => $value ) { $fields[] = array( 'name' => $key, 'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ); } $result['data'][] = array( 'group_id' => 'aish-newsletter', 'group_label' => 'AI Skills Hub optional newsletter consent', 'item_id' => 'aish-newsletter-' . $user->ID, 'data' => $fields ); } return $result;
	}
	/** Called by the already identity-bound native account privacy lifecycle, under its user lock. */
	public function eraseAccount( int $id ): void {
		$this->locks->withUser( $id, function () use ( $id ): void {
			$this->cancelMail( $id ); $this->store->revokeVerifications( $id, 'newsletter' ); $n = $this->db->connection();
			$prefix = self::META . '_token_' . substr( hash( 'sha256', $this->db->prefix() ), 0, 16 ) . '_';
			$this->db->execute( "DELETE FROM {$n->usermeta} WHERE user_id=%d AND (meta_key=%s OR meta_key LIKE %s)", array( $id, $this->storageKey(), $n->esc_like( $prefix ) . '%' ) ); wp_cache_delete( $id, 'user_meta' );
		} );
	}
}
