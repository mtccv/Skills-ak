<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

defined( 'ABSPATH' ) || exit;

/** One encrypted account message per tick; no native mail or network work on a frontend request. */
final class MailWorker {
	private array $handlers = array();
	public function __construct( private Store $store, private Mail $mail, private Locks $locks ) {}
	/** Trusted Core extension; only these fixed opt-in templates may use a different final send gate. */
	public function registerNewsletterHandler( callable $handler ): void {
		foreach ( array( 'newsletter_confirm', 'newsletter_weekly', 'newsletter_new' ) as $template ) {
			if ( isset( $this->handlers[ $template ] ) ) { throw new \LogicException( 'Newsletter mail handler is already registered.' ); }
			$this->handlers[ $template ] = $handler;
		}
	}

	public function run(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) { return; }
		$this->store->recoverExpired();
		if ( ( function_exists( 'get_option' ) && '1' === get_option( 'aish_account_mail_paused', '0' ) ) || ! defined( 'AISH_ACCOUNT_MAIL_ENABLED' ) || true !== AISH_ACCOUNT_MAIL_ENABLED ) { return; }
		$row = $this->store->claim();
		if ( ! $row ) { return; }
		$id = (int) $row['id']; $lease = (string) $row['lease_token']; $sending = false;
		try {
			if ( isset( $this->handlers[ $row['template_key'] ] ) ) {
				// The handler owns the same Store lease/state machine, and catches uncertain sends.
				( $this->handlers[ $row['template_key'] ] )( $row ); return;
			}
			$userId = (int) ( $row['recipient_user_id'] ?? 0 );
			if ( $userId < 1 ) { $this->store->finish( $id, $lease, 'cancelled', 'mail_recipient_unavailable' ); return; }
			// Native email changes and deletion use the same user lock. A short external
			// send holds this named lock, never an open SQL transaction.
			$this->locks->withUser( $userId, function () use ( $row, $id, $lease, $userId, &$sending ): void {
				try { $payload = $this->store->decryptPayload( $row ); }
				catch ( \Throwable $error ) {
					$this->revokeLinkedVerification( $row );
					$this->store->finish( $id, $lease, 'cancelled', 'mail_payload_unavailable' ); return;
				}
				if ( ! $this->eligible( $row, $payload, $userId ) ) {
					$this->store->finish( $id, $lease, 'cancelled', 'mail_context_changed' ); return;
				}
				$message = $this->mail->render( $row, $payload );
				if ( ! $this->store->markSending( $id, $lease ) ) { return; }
				$sending = true;
				$result = $this->mail->send( $payload['destination'], $message );
				if ( 'accepted' === $result ) {
					$this->store->finish( $id, $lease, 'accepted' );
				} elseif ( 'rejected' !== $result ) {
					$this->store->finish( $id, $lease, 'uncertain', 'mail_result_uncertain' );
				} elseif ( (int) $row['attempts'] < 3 ) {
					// Only a definite non-acceptance supplied by a trusted transport can
					// retry. Native false, exceptions and interrupted sends are uncertain.
					$this->store->retry( $id, $lease, min( 900, 60 * ( 2 ** max( 0, (int) $row['attempts'] - 1 ) ) ), 'mail_transport_rejected' );
				} else { $this->store->finish( $id, $lease, 'failed', 'mail_transport_rejected' ); }
			} );
		} catch ( \Throwable $error ) {
			// Never log an exception that might contain a token, email, SQL or SMTP body.
			// A lost DB acknowledgement will be recovered from the durable sending marker.
			try { $this->store->finish( $id, $lease, $sending ? 'uncertain' : 'failed', $sending ? 'mail_result_uncertain' : 'mail_preflight_failed' ); }
			catch ( \Throwable $ignored ) {}
		}
	}

	private function eligible( array $row, #[\SensitiveParameter] array $payload, int $userId ): bool {
		$allowed = array( 'version', 'destination', 'current_email_hash' );
		$template = (string) ( $row['template_key'] ?? '' );
		$allowed = array_merge( $allowed, match ( $template ) {
			'verify_email', 'change_email' => array( 'verification_id', 'token' ),
			'password_reset' => array( 'login', 'key' ),
			'security_notice' => array( 'event' ),
			default => array(),
		} );
		if ( array_diff( array_keys( $payload ), $allowed ) || 1 !== ( $payload['version'] ?? null ) || ! is_string( $payload['destination'] ?? null ) || ! is_email( $payload['destination'] ) || ! is_string( $payload['current_email_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $payload['current_email_hash'] ) || ! hash_equals( (string) $row['destination_hash'], Store::emailHash( $payload['destination'] ) ) ) { return false; }
		if ( (string) $row['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) { return false; }
		clean_user_cache( $userId );
		$user = get_userdata( $userId );
		$states = get_user_meta( $userId, '_aish_account_state', false );
		$deletion = get_user_meta( $userId, '_aish_account_deletion_pending', false );
		if ( ! is_array( $states ) || ! is_array( $deletion ) || count( array_filter( $states, 'is_string' ) ) !== count( $states ) || count( array_filter( $deletion, 'is_string' ) ) !== count( $deletion ) || ( $states && ( array_diff( $states, array( $states[0] ) ) || ! in_array( $states[0], array( 'pending_email', 'active' ), true ) ) ) || array_diff( $deletion, array( '', '0' ) ) ) { return false; }
		if ( ! $user instanceof \WP_User || ! hash_equals( $payload['current_email_hash'], Store::emailHash( (string) $user->user_email ) ) ) { return false; }
		if ( in_array( $template, array( 'verify_email', 'change_email' ), true ) ) {
			if ( ! is_int( $payload['verification_id'] ?? null ) || ! is_string( $payload['token'] ?? null ) ) { return false; }
			$verification = $this->store->verification( $payload['token'] );
			return null !== $verification && (int) $verification['id'] === $payload['verification_id'] && (int) $verification['user_id'] === $userId
				&& $template === $verification['purpose'] && ! empty( $verification['active_key'] ) && empty( $verification['consumed_at'] )
				&& (string) $verification['expires_at'] > gmdate( 'Y-m-d H:i:s' )
				&& hash_equals( (string) $row['event_key'], Mail::verificationEventKey( (int) $verification['id'] ) )
				&& hash_equals( Store::emailHash( (string) $verification['target_email'] ), Store::emailHash( $payload['destination'] ) )
				&& ( 'change_email' === $template || hash_equals( Store::emailHash( (string) $user->user_email ), Store::emailHash( $payload['destination'] ) ) );
		}
		if ( 'password_reset' === $template ) {
			if ( ! is_string( $payload['key'] ?? null ) || ! is_string( $payload['login'] ?? null ) || ! hash_equals( (string) $user->user_login, $payload['login'] ) || ! hash_equals( Store::emailHash( (string) $user->user_email ), Store::emailHash( $payload['destination'] ) ) ) { return false; }
			$resetUser = check_password_reset_key( $payload['key'], $payload['login'] );
			return $resetUser instanceof \WP_User && (int) $resetUser->ID === $userId;
		}
		return 'security_notice' === $template && in_array( $payload['event'] ?? '', array( 'email_changed', 'password_changed', 'sessions_revoked' ), true );
	}

	private function revokeLinkedVerification( array $row ): void {
		if ( ! in_array( $row['template_key'], array( 'verify_email', 'change_email' ), true ) ) { return; }
		$verification = $this->store->activeVerification( (int) $row['recipient_user_id'], $row['template_key'] );
		if ( $verification && hash_equals( (string) $row['event_key'], Mail::verificationEventKey( (int) $verification['id'] ) ) ) {
			$this->store->revokeVerificationById( (int) $verification['id'] );
		}
	}
}
