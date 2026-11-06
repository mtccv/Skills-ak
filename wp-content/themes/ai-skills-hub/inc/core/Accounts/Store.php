<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Internal account persistence. HTTP ownership/authorization belongs to AccountService. */
final class Store {
	private const PURPOSES = array( 'verify_email', 'change_email' );
	private const MAIL_TEMPLATES = array( 'verify_email', 'change_email', 'password_reset', 'security_notice' );
	public function __construct( private Database $db ) {}
	public function database(): Database { return $this->db; }
	public function transaction( callable $operation ): mixed { return $this->db->transaction( $operation ); }
	public static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }
	public static function emailHash( #[\SensitiveParameter] string $email ): string { return hash_hmac( 'sha256', 'aish:email:' . Locks::email( $email ), wp_salt( 'auth' ) ); }
	public static function tokenHash( #[\SensitiveParameter] string $token ): string { return hash_hmac( 'sha256', 'aish:verification:' . $token, wp_salt( 'auth' ) ); }
	private static function user( int $id ): void { if ( $id < 1 ) { throw new \InvalidArgumentException( 'Invalid account identity.' ); } }
	private static function hash( string $value ): void { if ( ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) { throw new \InvalidArgumentException( 'Invalid account event identity.' ); } }
	private static function locale( string $value ): void { if ( ! in_array( $value, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'Unsupported account language.' ); } }
	private static function purpose( string $value ): void { if ( ! in_array( $value, self::PURPOSES, true ) ) { throw new \InvalidArgumentException( 'Unsupported verification purpose.' ); } }
	private static function error( string $value ): string {
		// Error *codes* only. Reject free-form driver/transport messages and credentials.
		return in_array( $value, array( '', 'mail_failed', 'mail_outcome_unknown', 'mail_expired', 'mail_worker_interrupted', 'mail_retry_exhausted', 'account_unavailable', 'mail_recipient_unavailable', 'mail_payload_unavailable', 'mail_context_changed', 'mail_transport_rejected', 'mail_result_uncertain', 'mail_preflight_failed' ), true ) ? $value : 'mail_failed';
	}

	/** Caller owns the email/account locks and the surrounding native-user/outbox transaction. */
	public function issueVerification( int $userId, string $purpose, #[\SensitiveParameter] string $email, #[\SensitiveParameter] string $token, int $ttl = 86400 ): array {
		self::user( $userId ); self::purpose( $purpose ); self::hash( $token );
		$email = Locks::email( $email );
		if ( $ttl < 60 || $ttl > 86400 ) { throw new \InvalidArgumentException( 'Invalid verification lifetime.' ); }
		$this->revokeVerifications( $userId, $purpose );
		$now = self::now();
		$data = array( 'user_id' => $userId, 'purpose' => $purpose, 'target_email' => $email, 'email_hash' => self::emailHash( $email ), 'token_hash' => self::tokenHash( $token ), 'active_key' => hash( 'sha256', $userId . ':' . $purpose ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ), 'attempt_count' => 0, 'consumed_at' => null, 'created_at' => $now, 'updated_at' => $now );
		$id = $this->db->insert( 'email_verifications', $data );
		return array( 'id' => $id ) + $data;
	}

	public function verification( #[\SensitiveParameter] string $token, bool $forUpdate = false ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return null; }
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'email_verifications' ) . ' WHERE token_hash=%s LIMIT 1' . ( $forUpdate ? ' FOR UPDATE' : '' ), array( self::tokenHash( $token ) ) );
	}

	public function verificationById( int $id ): ?array {
		self::user( $id );
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'email_verifications' ) . ' WHERE id=%d LIMIT 1', array( $id ) );
	}

	public function activeVerification( int $userId, string $purpose ): ?array {
		self::user( $userId ); self::purpose( $purpose );
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'email_verifications' ) . ' WHERE active_key=%s AND expires_at>%s AND consumed_at IS NULL LIMIT 1', array( hash( 'sha256', $userId . ':' . $purpose ), self::now() ) );
	}

	public function consumeVerification( int $id ): bool {
		self::user( $id ); $now = self::now();
		return 1 === $this->db->execute( 'UPDATE ' . $this->db->table( 'email_verifications' ) . ' SET active_key=NULL,consumed_at=%s,attempt_count=attempt_count+1,updated_at=%s WHERE id=%d AND active_key IS NOT NULL AND consumed_at IS NULL AND expires_at>%s', array( $now, $now, $id, $now ) );
	}

	public function revokeVerificationById( int $id ): bool {
		self::user( $id );
		return 1 === $this->db->execute( 'UPDATE ' . $this->db->table( 'email_verifications' ) . ' SET active_key=NULL,updated_at=%s WHERE id=%d AND active_key IS NOT NULL', array( self::now(), $id ) );
	}

	public function revokeVerifications( int $userId, ?string $purpose = null ): void {
		self::user( $userId ); if ( null !== $purpose ) { self::purpose( $purpose ); }
		$values = array( self::now(), $userId );
		if ( null !== $purpose ) { $values[] = $purpose; }
		$this->db->execute( 'UPDATE ' . $this->db->table( 'email_verifications' ) . ' SET active_key=NULL,updated_at=%s WHERE user_id=%d AND active_key IS NOT NULL' . ( null !== $purpose ? ' AND purpose=%s' : '' ), $values );
	}

	/** Queue inside the caller's transaction. Replayed event keys preserve the original delivery. */
	public function queue( array $fields, #[\SensitiveParameter] array $payload ): int {
		foreach ( array( 'event_key', 'destination_hash', 'template_key', 'locale', 'expires_at' ) as $required ) {
			if ( ! isset( $fields[ $required ] ) || ! is_string( $fields[ $required ] ) ) { throw new \InvalidArgumentException( 'Incomplete account mail event.' ); }
		}
		self::hash( $fields['event_key'] ); self::hash( $fields['destination_hash'] ); self::locale( $fields['locale'] );
		if ( ! in_array( $fields['template_key'], self::MAIL_TEMPLATES, true ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $fields['expires_at'] ) || $fields['expires_at'] <= self::now() || $fields['expires_at'] > gmdate( 'Y-m-d H:i:s', time() + 86400 ) ) { throw new \InvalidArgumentException( 'Invalid account mail event.' ); }
		foreach ( array( 'recipient_user_id', 'notification_id' ) as $key ) {
			if ( isset( $fields[ $key ] ) && ( ! is_int( $fields[ $key ] ) || $fields[ $key ] < 1 ) ) { throw new \InvalidArgumentException( 'Invalid account mail reference.' ); }
		}
		$encrypted = Crypto::encrypt( $payload, 'outbox:' . $fields['event_key'] );
		$table = $this->db->table( 'notification_outbox' );
		$now = self::now();
		$recipient = $fields['recipient_user_id'] ?? null;
		$notification = $fields['notification_id'] ?? null;
		// NULL references cannot be interpolated as request SQL. Only these fixed branches differ.
		$sql = "INSERT INTO $table (recipient_user_id,notification_id,event_key,channel,template_key,locale,destination_hash,payload_ciphertext,key_version,status,attempts,available_at,lease_token,created_at,updated_at,expires_at) VALUES (" . ( null === $recipient ? 'NULL' : '%d' ) . ',' . ( null === $notification ? 'NULL' : '%d' ) . ",%s,'email',%s,%s,%s,%s,%s,'queued',0,%s,'',%s,%s,%s) ON DUPLICATE KEY UPDATE id=id";
		$values = array(); if ( null !== $recipient ) { $values[] = $recipient; } if ( null !== $notification ) { $values[] = $notification; }
		$values = array_merge( $values, array( $fields['event_key'], $fields['template_key'], $fields['locale'], $fields['destination_hash'], $encrypted['payload_ciphertext'], $encrypted['key_version'], $now, $now, $now, $fields['expires_at'] ) );
		$this->db->execute( $sql, $values );
		$row = $this->db->row( "SELECT * FROM $table WHERE event_key=%s AND channel='email' LIMIT 1", array( $fields['event_key'] ) );
		if ( ! $row || (int) ( $row['recipient_user_id'] ?? 0 ) !== (int) ( $recipient ?? 0 ) || $row['template_key'] !== $fields['template_key'] || $row['locale'] !== $fields['locale'] || ! hash_equals( $row['destination_hash'], $fields['destination_hash'] ) ) { throw new \RuntimeException( 'Account mail event conflict.' ); }
		if ( ! empty( $row['payload_ciphertext'] ) && $this->decryptPayload( $row ) !== $payload ) { throw new \RuntimeException( 'Account mail event conflict.' ); }
		return (int) $row['id'];
	}

	public function decryptPayload( array $row ): array {
		return Crypto::decrypt( (string) ( $row['payload_ciphertext'] ?? '' ), (string) ( $row['key_version'] ?? '' ), 'outbox:' . (string) ( $row['event_key'] ?? '' ) );
	}

	/** A small lease is claimed under a row lock; no network work runs in this transaction. */
	public function claim(): ?array {
		return $this->db->transaction( function (): ?array {
			$table = $this->db->table( 'notification_outbox' ); $now = self::now();
			$row = $this->db->row( "SELECT * FROM $table WHERE status IN ('queued','retry') AND available_at<=%s AND expires_at>%s AND attempts<5 ORDER BY available_at,id LIMIT 1 FOR UPDATE", array( $now, $now ) );
			if ( ! $row ) { return null; }
			$lease = bin2hex( random_bytes( 32 ) );
			$until = gmdate( 'Y-m-d H:i:s', time() + 120 );
			$this->db->execute( "UPDATE $table SET status='claimed',attempts=attempts+1,lease_token=%s,locked_until=%s,updated_at=%s WHERE id=%d", array( $lease, $until, $now, (int) $row['id'] ) );
			return array_replace( $row, array( 'status' => 'claimed', 'attempts' => (int) $row['attempts'] + 1, 'lease_token' => $lease, 'locked_until' => $until, 'updated_at' => $now ) );
		} );
	}

	public function markSending( int $id, string $lease ): bool {
		self::user( $id ); self::hash( $lease ); $now = self::now();
		return 1 === $this->db->execute( 'UPDATE ' . $this->db->table( 'notification_outbox' ) . " SET status='sending',updated_at=%s WHERE id=%d AND status='claimed' AND lease_token=%s AND locked_until>%s AND expires_at>%s", array( $now, $id, $lease, $now, $now ) );
	}

	public function finish( int $id, string $lease, string $status, string $error = '' ): bool {
		self::user( $id ); self::hash( $lease );
		if ( ! in_array( $status, array( 'accepted', 'failed', 'uncertain', 'cancelled' ), true ) ) { throw new \InvalidArgumentException( 'Invalid account mail state.' ); }
		$now = self::now();
		return 1 === $this->db->execute( 'UPDATE ' . $this->db->table( 'notification_outbox' ) . ' SET status=%s,last_error=%s,payload_ciphertext=NULL,lease_token=%s,locked_until=NULL,updated_at=%s,accepted_at=' . ( 'accepted' === $status ? '%s' : 'NULL' ) . " WHERE id=%d AND status IN ('claimed','sending') AND lease_token=%s AND locked_until>%s", array_merge( array( $status, self::error( $error ), '', $now ), 'accepted' === $status ? array( $now ) : array(), array( $id, $lease, $now ) ) );
	}

	/** Only for a transport outcome known not to have accepted the message. */
	public function retry( int $id, string $lease, int $delay, string $error ): bool {
		self::user( $id ); self::hash( $lease );
		$delay = max( 60, min( 3600, $delay ) ); $now = self::now();
		return 1 === $this->db->execute( 'UPDATE ' . $this->db->table( 'notification_outbox' ) . " SET status=IF(attempts>=5,'failed','retry'),payload_ciphertext=IF(attempts>=5,NULL,payload_ciphertext),available_at=%s,lease_token='',locked_until=NULL,last_error=%s,updated_at=%s WHERE id=%d AND status IN ('claimed','sending') AND lease_token=%s AND locked_until>%s AND expires_at>%s", array( gmdate( 'Y-m-d H:i:s', time() + $delay ), self::error( $error ), $now, $id, $lease, $now, $now ) );
	}

	/** Unknown in-flight outcomes never become automatic sends again. Bounded, restartable cleanup. */
	public function recoverExpired(): void {
		$table = $this->db->table( 'notification_outbox' ); $now = self::now();
		$this->db->execute( "UPDATE $table SET status='uncertain',payload_ciphertext=NULL,lease_token='',locked_until=NULL,last_error='mail_outcome_unknown',updated_at=%s WHERE status='sending' AND locked_until<=%s ORDER BY id LIMIT 100", array( $now, $now ) );
		$this->db->execute( "UPDATE $table SET status='expired',payload_ciphertext=NULL,lease_token='',locked_until=NULL,last_error='mail_expired',updated_at=%s WHERE status IN ('queued','retry','claimed') AND expires_at<=%s ORDER BY id LIMIT 100", array( $now, $now ) );
		$this->db->execute( "UPDATE $table SET status=IF(attempts>=5,'failed','retry'),payload_ciphertext=IF(attempts>=5,NULL,payload_ciphertext),lease_token='',locked_until=NULL,last_error='mail_worker_interrupted',available_at=%s,updated_at=%s WHERE status='claimed' AND locked_until<=%s ORDER BY id LIMIT 100", array( $now, $now, $now ) );
		$this->db->execute( "UPDATE $table SET status='failed',payload_ciphertext=NULL,last_error='mail_retry_exhausted',updated_at=%s WHERE status IN ('queued','retry') AND attempts>=5 ORDER BY id LIMIT 100", array( $now ) );
	}

	public function cancelUserMail( int $userId ): int {
		self::user( $userId );
		return $this->db->execute( 'UPDATE ' . $this->db->table( 'notification_outbox' ) . " SET status='cancelled',payload_ciphertext=NULL,lease_token='',locked_until=NULL,last_error='account_unavailable',updated_at=%s WHERE recipient_user_id=%d AND status IN ('queued','retry','claimed','sending')", array( self::now(), $userId ) );
	}

	public function latestMail( int $userId ): ?array {
		self::user( $userId );
		return $this->db->row( 'SELECT id,status,last_error,updated_at,available_at,accepted_at,template_key FROM ' . $this->db->table( 'notification_outbox' ) . ' WHERE recipient_user_id=%d ORDER BY created_at DESC,id DESC LIMIT 1', array( $userId ) );
	}

	/** Only account event codes enter notifications; no free-form email/token/body parameters. */
	public function notify( int $userId, string $eventKey, string $template, string $locale, array $params = array() ): int {
		self::user( $userId ); self::hash( $eventKey ); self::locale( $locale );
		if ( ! in_array( $template, array( 'account_registered', 'email_verified', 'email_changed', 'password_changed', 'sessions_revoked', 'privacy_request', 'profile_updated', 'preferences_updated' ), true ) || array_diff( array_keys( $params ), array( 'request_type' ) ) || ( isset( $params['request_type'] ) && ! in_array( $params['request_type'], array( 'export_personal_data', 'remove_personal_data' ), true ) ) ) { throw new \InvalidArgumentException( 'Invalid account notification.' ); }
		$table = $this->db->table( 'user_notifications' ); $now = self::now();
		$json = wp_json_encode( $params );
		$this->db->execute( "INSERT INTO $table (user_id,event_key,type,subject_type,subject_id,template_key,params_json,locale,created_at,expires_at) VALUES (%d,%s,'account','account',%d,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id", array( $userId, $eventKey, $userId, $template, $json, $locale, $now, gmdate( 'Y-m-d H:i:s', time() + 180 * 86400 ) ) );
		$row = $this->db->row( "SELECT id,template_key,locale,params_json FROM $table WHERE user_id=%d AND event_key=%s", array( $userId, $eventKey ) );
		if ( ! $row || $row['template_key'] !== $template || $row['locale'] !== $locale || $row['params_json'] !== $json ) { throw new \RuntimeException( 'Account notification event conflict.' ); }
		return (int) $row['id'];
	}

	public function notifications( int $userId, int $limit = 20, int $offset = 0 ): array {
		self::user( $userId );
		if ( $offset < 0 || $offset > 20000 ) { throw new \InvalidArgumentException( 'Account notification page is out of range.' ); }
		return $this->db->rows( 'SELECT id,type,template_key,params_json,locale,read_at,created_at FROM ' . $this->db->table( 'user_notifications' ) . ' WHERE user_id=%d AND expires_at>%s ORDER BY id DESC LIMIT %d OFFSET %d', array( $userId, self::now(), max( 1, min( 50, $limit ) ), $offset ) );
	}

	public function markRead( int $userId, ?int $id = null ): bool {
		self::user( $userId ); if ( null !== $id ) { self::user( $id ); }
		$changed = $this->db->execute( 'UPDATE ' . $this->db->table( 'user_notifications' ) . ' SET read_at=%s WHERE user_id=%d AND read_at IS NULL' . ( null !== $id ? ' AND id=%d' : '' ), array_merge( array( self::now(), $userId ), null === $id ? array() : array( $id ) ) );
		return null === $id || $changed > 0 || null !== $this->db->value( 'SELECT id FROM ' . $this->db->table( 'user_notifications' ) . ' WHERE user_id=%d AND id=%d LIMIT 1', array( $userId, $id ) );
	}

	public function cleanup(): void {
		$verifications = $this->db->table( 'email_verifications' );
		$this->db->execute( "DELETE FROM $verifications WHERE (active_key IS NULL AND updated_at<%s) OR expires_at<%s ORDER BY id LIMIT 100", array( gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 ), gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 ) ) );
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'user_notifications' ) . ' WHERE expires_at<=%s ORDER BY id LIMIT 100', array( self::now() ) );
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'notification_outbox' ) . " WHERE status IN ('accepted','failed','uncertain','cancelled','expired') AND updated_at<%s ORDER BY id LIMIT 100", array( gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) ) );
	}
}
