<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

defined( 'ABSPATH' ) || exit;

/** Account-only transactional templates. Secrets exist only inside the encrypted outbox payload. */
final class Mail {
	public function __construct( private Store $store ) {}

	public function ready(): bool {
		return defined( 'AISH_ACCOUNT_MAIL_ENABLED' ) && true === AISH_ACCOUNT_MAIL_ENABLED && Crypto::ready();
	}

	public static function verificationEventKey( int $id ): string {
		return hash( 'sha256', 'aish-account-verification:' . $id );
	}

	public function queueVerification( int $userId, string $purpose, #[\SensitiveParameter] string $email, #[\SensitiveParameter] string $token, string $locale, int $verificationId, string $expiresAt ): int {
		if ( ! in_array( $purpose, array( 'verify_email', 'change_email' ), true ) || $verificationId < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			throw new \InvalidArgumentException( 'mail_invalid_payload' );
		}
		return $this->queue( $userId, $email, $purpose, $locale, self::verificationEventKey( $verificationId ), $expiresAt,
			array( 'verification_id' => $verificationId, 'token' => $token ) );
	}

	/** Native WordPress generated password-reset keys remain the sole reset credential. */
	public function queuePasswordReset( #[\SensitiveParameter] \WP_User $user, #[\SensitiveParameter] string $key, string $locale ): int {
		if ( ! preg_match( '/^[a-zA-Z0-9]{1,128}$/D', $key ) ) { throw new \InvalidArgumentException( 'mail_invalid_payload' ); }
		return $this->queue( (int) $user->ID, (string) $user->user_email, 'password_reset', $locale,
			hash( 'sha256', 'aish-native-reset:' . $user->ID . ':' . $key ), gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			array( 'login' => (string) $user->user_login, 'key' => $key ) );
	}

	/** Destination can be the old address for an email-change security notice. */
	public function queueSecurity( int $userId, #[\SensitiveParameter] string $destination, string $locale, string $event ): int {
		if ( ! in_array( $event, array( 'email_changed', 'password_changed', 'sessions_revoked' ), true ) ) { throw new \InvalidArgumentException( 'mail_invalid_payload' ); }
		return $this->queue( $userId, $destination, 'security_notice', $locale, bin2hex( random_bytes( 32 ) ),
			gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), array( 'event' => $event ) );
	}

	private function queue( int $userId, #[\SensitiveParameter] string $destination, string $template, string $locale, string $eventKey, string $expiresAt, #[\SensitiveParameter] array $data ): int {
		if ( ! $this->ready() ) { throw new \DomainException( 'mail_unavailable' ); }
		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || $userId < 1 || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \DomainException( 'mail_invalid_recipient' ); }
		$destination = Locks::email( $destination );
		if ( ! is_email( $destination ) || strlen( $destination ) > 254 ) { throw new \DomainException( 'mail_invalid_recipient' ); }
		$payload = array_merge( array( 'version' => 1, 'destination' => $destination, 'current_email_hash' => Store::emailHash( (string) $user->user_email ) ), $data );
		$id = $this->store->queue( array(
			'recipient_user_id' => $userId, 'notification_id' => null, 'event_key' => $eventKey, 'template_key' => $template,
			'locale' => $locale, 'destination_hash' => Store::emailHash( $destination ), 'expires_at' => $expiresAt,
		), $payload );
		// Scheduling is cheap; processing remains exclusively in a separate Cron/CLI request.
		do_action( 'aish_account_mail_wake' );
		return $id;
	}

	/** Payload is decrypted only in the protected worker, never returned by a public projection. */
	public function render( array $row, #[\SensitiveParameter] array $payload ): array {
		$zh = 'zh_CN' === ( $row['locale'] ?? '' );
		if ( ! in_array( $row['locale'] ?? '', array( 'zh_CN', 'en_US' ), true ) ) { throw new \DomainException( 'mail_invalid_payload' ); }
		$lang = $zh ? 'zh' : 'en';
		$site = preg_replace( '/[\r\n\x00-\x1f\x7f]+/u', ' ', wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		preg_match( '/^.{0,100}/us', $site ?: 'AI Skills Hub', $siteMatch );
		$site = $siteMatch[0] ?? 'AI Skills Hub';
		$template = $row['template_key'] ?? '';
		if ( in_array( $template, array( 'verify_email', 'change_email' ), true ) ) {
			if ( ! is_string( $payload['token'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $payload['token'] ) ) { throw new \DomainException( 'mail_invalid_payload' ); }
			$url = add_query_arg( 'token', $payload['token'], Frontend::url( 'verify-email', $row['locale'] ) );
			$change = 'change_email' === $template;
			$subject = $zh ? ( $change ? '确认新的账户邮箱' : '验证您的邮箱' ) : ( $change ? 'Confirm your new account email' : 'Verify your email' );
			$intro = $zh ? ( $change ? '请打开以下链接并确认，将此邮箱设为您的账户邮箱。确认之前原邮箱保持不变。' : '请打开以下链接，然后在页面上确认邮箱验证。' ) : ( $change ? 'Open the link and confirm to make this your account email. Your existing address remains unchanged until you confirm.' : 'Open the link, then confirm email verification on the page.' );
			$body = $intro . "\n\n" . $url . "\n\n" . ( $zh ? '链接仅可使用一次，将于 ' : 'This single-use link expires at ' ) . (string) $row['expires_at'] . " UTC.\n\n" . ( $zh ? '如果不是您本人发起，请忽略此邮件。不要转发此验证链接。' : 'If you did not request this, ignore this email. Do not forward this verification link.' );
		} elseif ( 'password_reset' === $template ) {
			if ( ! is_string( $payload['key'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9]{1,128}$/D', $payload['key'] ) || ! is_string( $payload['login'] ?? null ) || strlen( $payload['login'] ) > 60 ) { throw new \DomainException( 'mail_invalid_payload' ); }
			$url = add_query_arg( array( 'key' => $payload['key'], 'login' => $payload['login'] ), Frontend::url( 'reset-password', $row['locale'] ) );
			$subject = $zh ? '重置账户密码' : 'Reset your account password';
			$body = ( $zh ? '请打开以下链接设置新的密码。链接使用 WordPress 原生一次性重置凭证。' : 'Open the link to choose a new password. This link uses the native WordPress single-use reset credential.' ) . "\n\n" . $url . "\n\n" . ( $zh ? '如果不是您本人发起，请忽略此邮件。您的密码不会因此改变。' : 'If you did not request this, ignore this email. Your password will remain unchanged.' );
		} elseif ( 'security_notice' === $template ) {
			$events = array(
				'email_changed' => array( '账户邮箱已更改。此安全通知发送至原邮箱。', 'Your account email was changed. This security notice is sent to your previous address.' ),
				'password_changed' => array( '账户密码已更改。', 'Your account password was changed.' ),
				'sessions_revoked' => array( '其他登录会话已退出。', 'Other signed-in sessions were revoked.' ),
			);
			if ( ! isset( $events[ $payload['event'] ?? '' ] ) ) { throw new \DomainException( 'mail_invalid_payload' ); }
			$subject = $zh ? '账户安全通知' : 'Account security notice';
			$body = $events[ $payload['event'] ][ $zh ? 0 : 1 ] . "\n\n" . ( $zh ? '如果不是您本人操作，请通过网站联系管理员。' : 'If this was not you, contact the site administrator through the website.' ) . "\n" . home_url( '/' . $lang . '/contact/' );
		} else { throw new \DomainException( 'mail_invalid_payload' ); }
		return array( 'subject' => '[' . $site . '] ' . $subject, 'body' => $body );
	}

	/** Acceptance is not delivery. Native false can also hide an unknown SMTP outcome. */
	public function send( #[\SensitiveParameter] string $destination, #[\SensitiveParameter] array $message ): string {
		$timeout = static function ( $mailer ): void { $mailer->Timeout = 20; $mailer->Timelimit = 30; $mailer->SMTPDebug = 0; };
		add_action( 'phpmailer_init', $timeout, PHP_INT_MAX );
		try {
			if ( true === wp_mail( $destination, $message['subject'], $message['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) { return 'accepted'; }
			// SMTP may accept DATA before the acknowledgement is lost. A provider
			// integration may explicitly classify a failure it KNOWS was not accepted;
			// a plain wp_mail false, timeout, or exception cannot establish that fact.
			return true === apply_filters( 'aish_account_mail_rejection_is_definite', false ) ? 'rejected' : 'uncertain';
		}
		finally { remove_action( 'phpmailer_init', $timeout, PHP_INT_MAX ); }
	}
}
