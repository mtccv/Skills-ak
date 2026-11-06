<?php
declare(strict_types=1);

namespace {
	// Real queue producer, templates, authenticated encryption and worker. Mock WP mail,
	// native users, locks and persistence: no SMTP server or real delivery is asserted.
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' ); define( 'WP_CLI', true ); define( 'DAY_IN_SECONDS', 86400 ); define( 'AISH_ACCOUNT_MAIL_ENABLED', true );
	class WP_User {
		public function __construct( public int $ID, public string $user_email, public string $user_login ) {}
	}
	$GLOBALS['mail_users'] = array( 7 => new WP_User( 7, 'member@example.test', 'internal-user-seven' ) );
	$GLOBALS['mail_meta'] = array(); $GLOBALS['mail_responses'] = array(); $GLOBALS['mail_calls'] = array(); $GLOBALS['mail_hooks'] = array(); $GLOBALS['mail_definite_rejection'] = false;
	function apply_filters( string $name, mixed $value ): mixed { return 'aish_account_mail_rejection_is_definite' === $name ? $GLOBALS['mail_definite_rejection'] : $value; }
	function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function get_userdata( int $id ): ?WP_User { return $GLOBALS['mail_users'][ $id ] ?? null; }
	function clean_user_cache( int $id ): void {}
	function get_user_meta( int $id, string $key, bool $single = true ): mixed {
		$value = $GLOBALS['mail_meta'][ $id ][ $key ] ?? null;
		if ( $single ) { return $value ?? ''; }
		return null === $value ? array() : ( is_array( $value ) ? $value : array( $value ) );
	}
	function do_action( string $name, mixed ...$args ): void { foreach ( $GLOBALS['mail_hooks'][ $name ] ?? array() as $callback ) { $callback( ...$args ); } }
	function add_action( string $name, callable $callback, int $priority = 10 ): void { $GLOBALS['mail_hooks'][ $name ][] = $callback; }
	function remove_action( string $name, callable $callback, int $priority = 10 ): void { $GLOBALS['mail_hooks'][ $name ] = array_filter( $GLOBALS['mail_hooks'][ $name ] ?? array(), static fn( $item ) => $item !== $callback ); }
	function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
	function get_bloginfo( string $name ): string { return "AI Skills Hub\r\nInjected: bad"; }
	function home_url( string $path ): string { return 'https://accounts.example.test' . $path; }
	function add_query_arg( mixed $key, mixed $value, ?string $url = null ): string {
		if ( is_array( $key ) ) { $url = $value; $data = $key; } else { $data = array( $key => $value ); }
		return $url . '?' . http_build_query( $data );
	}
	function check_password_reset_key( string $key, string $login ): mixed {
		return 'nativeResetKey' === $key && 'internal-user-seven' === $login ? get_userdata( 7 ) : false;
	}
	function wp_mail( string $to, string $subject, string $message, array $headers ): mixed {
		$phpmailer = new class { public int $Timeout = 100; public int $Timelimit = 100; public int $SMTPDebug = 2; };
		do_action( 'phpmailer_init', $phpmailer );
		$GLOBALS['mail_calls'][] = array( 'destination' => $to, 'subject' => $subject, 'body' => $message, 'headers' => $headers, 'timeout' => $phpmailer->Timeout, 'debug' => $phpmailer->SMTPDebug );
		$response = array_shift( $GLOBALS['mail_responses'] );
		if ( $response instanceof \Throwable ) { throw $response; }
		return $response;
	}
}

namespace AISkillsHub\Core\Accounts {
	final class Frontend {
		public static function url( string $route, ?string $locale = null ): string { return \home_url( '/' . ( 'zh_CN' === $locale ? 'zh' : 'en' ) . '/' . $route . '/' ); }
	}
	final class Locks {
		public static function email( string $email ): string { return strtolower( trim( $email ) ); }
		public function withUser( int $id, callable $callback ): mixed { return $callback(); }
	}
	final class Store {
		public array $rows = array(); public array $verifications = array(); public array $events = array(); public bool $denyLease = false; public bool $failAck = false;
		public static function emailHash( string $email ): string { return hash_hmac( 'sha256', Locks::email( $email ), 'isolated-fixture-salt' ); }
		public static function tokenHash( string $token ): string { return hash_hmac( 'sha256', $token, 'isolated-fixture-salt' ); }
		public function queue( array $fields, array $payload ): int {
			$id = count( $this->rows ) + 1;
			$this->rows[ $id ] = array_merge( $fields, Crypto::encrypt( $payload, 'outbox:' . $fields['event_key'] ), array( 'id' => $id, 'status' => 'queued', 'lease_token' => '', 'attempts' => 0, 'last_error' => '' ) ); return $id;
		}
		public function claim(): ?array {
			foreach ( $this->rows as &$row ) {
				if ( ! in_array( $row['status'], array( 'queued', 'retry' ), true ) ) { continue; }
				$row['status'] = 'claimed'; $row['lease_token'] = bin2hex( random_bytes( 32 ) ); ++$row['attempts']; return $row;
			} unset( $row ); return null;
		}
		public function recoverExpired(): void {
			foreach ( $this->rows as &$row ) {
				if ( $row['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) && in_array( $row['status'], array( 'queued', 'retry', 'claimed', 'sending' ), true ) ) { $row['status'] = 'expired'; $row['payload_ciphertext'] = null; }
			} unset( $row );
		}
		public function decryptPayload( array $row ): array { return Crypto::decrypt( $row['payload_ciphertext'], $row['key_version'], 'outbox:' . $row['event_key'] ); }
		public function markSending( int $id, string $lease ): bool {
			if ( $this->denyLease ) { return false; }
			$this->events[] = 'sending'; $this->rows[ $id ]['status'] = 'sending'; return true;
		}
		public function finish( int $id, string $lease, string $status, string $error = '' ): bool {
			if ( $this->failAck ) { throw new \RuntimeException( 'test dropped DB acknowledgement' ); }
			$this->rows[ $id ]['status'] = $status; $this->rows[ $id ]['last_error'] = $error; $this->rows[ $id ]['payload_ciphertext'] = null; $this->events[] = $status; return true;
		}
		public function retry( int $id, string $lease, int $delay, string $error ): bool {
			$this->rows[ $id ]['status'] = 'retry'; $this->rows[ $id ]['last_error'] = $error; $this->rows[ $id ]['delay'] = $delay; return true;
		}
		public function verification( string $token ): ?array {
			foreach ( $this->verifications as $record ) { if ( hash_equals( $record['token_hash'], self::tokenHash( $token ) ) ) { return $record; } } return null;
		}
		public function activeVerification( int $uid, string $purpose ): ?array {
			foreach ( $this->verifications as $record ) { if ( $record['user_id'] === $uid && $record['purpose'] === $purpose && $record['active_key'] ) { return $record; } } return null;
		}
		public function revokeVerificationById( int $id ): bool { $this->verifications[ $id ]['active_key'] = null; return true; }
	}
}

namespace {
	$core = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Accounts/';
	foreach ( array( 'Crypto.php', 'Mail.php', 'MailWorker.php' ) as $file ) { require $core . $file; }
	use AISkillsHub\Core\Accounts\{Store,Crypto,Mail,MailWorker,Locks};
	$checks = 0;
	function check( bool $result, string $label ): void { global $checks; ++$checks; if ( ! $result ) { throw new \RuntimeException( $label ); } }
	function fixture( string $locale = 'en_US', string $purpose = 'verify_email' ): array {
		$store = new Store(); $mail = new Mail( $store ); $token = bin2hex( random_bytes( 32 ) ); $expires = gmdate( 'Y-m-d H:i:s', time() + 86400 );
		$destination = 'change_email' === $purpose ? 'new@example.test' : 'member@example.test';
		$store->verifications[1] = array( 'id' => 1, 'user_id' => 7, 'purpose' => $purpose, 'target_email' => $destination, 'token_hash' => Store::tokenHash( $token ), 'active_key' => hash( 'sha256', '7:' . $purpose ), 'expires_at' => $expires, 'consumed_at' => null );
		$id = $mail->queueVerification( 7, $purpose, $destination, $token, $locale, 1, $expires );
		return array( $store, $mail, new MailWorker( $store, $mail, new Locks() ), $id, $token );
	}
	putenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION' );
	check( ! ( new Mail( new Store() ) )->ready(), 'Missing dedicated key disables account mail.' );
	putenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION=fixture' ); putenv( 'AISH_ACCOUNT_MAIL_KEY_FIXTURE=' . base64_encode( random_bytes( 32 ) ) );
	check( ( new Mail( new Store() ) )->ready(), 'Explicit test transport and key enable queue.' );
	[ $store, $mail, $worker, $id, $token ] = fixture();
	check( ! str_contains( json_encode( $store->rows ), $token ) && ! str_contains( json_encode( $store->rows ), 'member@example.test' ), 'Durable queue contains no plaintext token or recipient.' );
	$GLOBALS['mail_responses'] = array( true ); $worker->run();
	check( 'accepted' === $store->rows[$id]['status'] && null === $store->rows[$id]['payload_ciphertext'], 'Accepted is transport-only and destroys sensitive payload.' );
	check( array( 'sending', 'accepted' ) === $store->events, 'Durable sending marker precedes acceptance.' );
	check( str_contains( end( $GLOBALS['mail_calls'] )['body'], '/en/verify-email/?token=' ), 'English native account confirmation URL.' );
	check( 20 === end( $GLOBALS['mail_calls'] )['timeout'] && 0 === end( $GLOBALS['mail_calls'] )['debug'], 'Bounded SMTP timeout and debug disabled.' );
	check( ! str_contains( end( $GLOBALS['mail_calls'] )['subject'], "\n" ), 'Header newline stripped from site name.' );
	$before = count( $GLOBALS['mail_calls'] ); $worker->run(); check( $before === count( $GLOBALS['mail_calls'] ), 'Accepted message is not sent twice.' );

	[ $store, $mail, $worker, $id ] = fixture(); $GLOBALS['mail_responses'] = array( false ); $worker->run();
	check( 'uncertain' === $store->rows[$id]['status'] && null === $store->rows[$id]['payload_ciphertext'], 'Unclassified native false cannot prove a retry is safe.' );
	$GLOBALS['mail_definite_rejection'] = true;
	[ $store, $mail, $worker, $id ] = fixture( 'zh_CN' ); $GLOBALS['mail_responses'] = array( false, true ); $worker->run();
	check( 'retry' === $store->rows[$id]['status'] && 60 === $store->rows[$id]['delay'] && null !== $store->rows[$id]['payload_ciphertext'], 'Explicit rejection retains encrypted payload and backs off.' );
	$worker->run(); check( 'accepted' === $store->rows[$id]['status'] && 2 === $store->rows[$id]['attempts'], 'Rejected transport can recover on next bounded attempt.' );
	check( str_contains( end( $GLOBALS['mail_calls'] )['body'], '/zh/verify-email/' ) && str_contains( end( $GLOBALS['mail_calls'] )['subject'], '验证' ), 'Chinese template and route.' );
	[ $store, $mail, $worker, $id ] = fixture(); $GLOBALS['mail_responses'] = array( false, false, false ); $worker->run(); $worker->run(); $worker->run();
	check( 'failed' === $store->rows[$id]['status'] && null === $store->rows[$id]['payload_ciphertext'] && 3 === $store->rows[$id]['attempts'], 'Three rejected attempts terminate and clear sensitive payload.' );
	$GLOBALS['mail_definite_rejection'] = false;

	[ $store, $mail, $worker, $id, $token ] = fixture(); $GLOBALS['mail_responses'] = array( new \RuntimeException( 'SMTP ambiguous; sensitive=' . $token ) ); $worker->run();
	check( 'uncertain' === $store->rows[$id]['status'] && 'mail_result_uncertain' === $store->rows[$id]['last_error'] && null === $store->rows[$id]['payload_ciphertext'], 'Ambiguous send is terminal with fixed non-sensitive error.' );
	check( ! str_contains( json_encode( $store->rows ), $token ), 'Exception token not persisted.' );
	$before = count( $GLOBALS['mail_calls'] ); $worker->run(); check( $before === count( $GLOBALS['mail_calls'] ), 'Uncertain send does not blindly retry.' );

	[ $store, $mail, $worker, $id ] = fixture(); $store->denyLease = true; $before = count( $GLOBALS['mail_calls'] ); $worker->run();
	check( $before === count( $GLOBALS['mail_calls'] ), 'Stale lease cannot issue external mail.' );
	[ $store, $mail, $worker, $id ] = fixture(); $store->failAck = true; $GLOBALS['mail_responses'] = array( true ); $worker->run();
	check( 'sending' === $store->rows[$id]['status'], 'Unknown DB acknowledgement leaves durable sending marker for uncertain recovery.' );

	[ $store, $mail, $worker, $id ] = fixture(); $store->verifications[1]['active_key'] = null; $before = count( $GLOBALS['mail_calls'] ); $worker->run();
	check( 'cancelled' === $store->rows[$id]['status'] && $before === count( $GLOBALS['mail_calls'] ), 'Superseded verification does not send.' );
	[ $store, $mail, $worker, $id ] = fixture(); $store->verifications[1]['expires_at'] = '2000-01-01 00:00:00'; $worker->run(); check( 'cancelled' === $store->rows[$id]['status'], 'Expired credential does not send.' );
	[ $store, $mail, $worker, $id ] = fixture(); $store->rows[$id]['expires_at'] = '2000-01-01 00:00:00'; $worker->run(); check( 'expired' === $store->rows[$id]['status'] && null === $store->rows[$id]['payload_ciphertext'], 'Expired queued payload is destroyed.' );
	[ $store, $mail, $worker, $id ] = fixture(); $GLOBALS['mail_users'][7]->user_email = 'changed@example.test'; $worker->run(); check( 'cancelled' === $store->rows[$id]['status'], 'Queued context is cancelled after native email change.' ); $GLOBALS['mail_users'][7]->user_email = 'member@example.test';
	[ $store, $mail, $worker, $id ] = fixture(); $user = $GLOBALS['mail_users'][7]; unset( $GLOBALS['mail_users'][7] ); $worker->run(); check( 'cancelled' === $store->rows[$id]['status'], 'Deleted recipient does not receive queued messages.' ); $GLOBALS['mail_users'][7] = $user;
	[ $store, $mail, $worker, $id ] = fixture(); $GLOBALS['mail_meta'][7]['_aish_account_state'] = 'suspended'; $worker->run(); check( 'cancelled' === $store->rows[$id]['status'], 'Suspended account queue is cancelled.' ); unset( $GLOBALS['mail_meta'][7] );
	[ $store, $mail, $worker, $id ] = fixture(); $GLOBALS['mail_meta'][7]['_aish_account_deletion_pending'] = '1'; $worker->run(); check( 'cancelled' === $store->rows[$id]['status'], 'Native deletion marker blocks mail even for legacy privileged accounts.' ); unset( $GLOBALS['mail_meta'][7] );
	[ $store, $mail, $worker, $id ] = fixture(); $GLOBALS['mail_meta'][7]['_aish_account_state'] = array( 'active', 'suspended' ); $worker->run(); check( 'cancelled' === $store->rows[$id]['status'], 'Conflicting account metadata fails closed before transport.' ); unset( $GLOBALS['mail_meta'][7] );

	[ $store, $mail, $worker, $id ] = fixture(); $store->rows[$id]['payload_ciphertext'] = 'tampered'; $worker->run();
	check( 'cancelled' === $store->rows[$id]['status'] && null === $store->verifications[1]['active_key'], 'Authenticated ciphertext failure cancels and revokes corresponding credential.' );
	[ $store, $mail, $worker, $id ] = fixture(); $fixtureKey = getenv( 'AISH_ACCOUNT_MAIL_KEY_FIXTURE' ); putenv( 'AISH_ACCOUNT_MAIL_KEY_FIXTURE' ); $worker->run(); putenv( 'AISH_ACCOUNT_MAIL_KEY_FIXTURE=' . $fixtureKey );
	check( 'cancelled' === $store->rows[$id]['status'] && null === $store->verifications[1]['active_key'], 'Lost historical encryption key revokes only its linked verification and cancels mail.' );
	[ $store, $mail, $worker, $id ] = fixture(); $store->rows[$id]['payload_ciphertext'] = 'tampered'; $store->verifications[1]['id'] = 2; $worker->run();
	check( null !== $store->verifications[1]['active_key'], 'Corrupt older job cannot revoke a replacement verification.' );
	[ $store, $mail, $worker, $id ] = fixture( 'en_US', 'change_email' ); $GLOBALS['mail_responses'] = array( true ); $worker->run();
	check( 'accepted' === $store->rows[$id]['status'] && 'new@example.test' === end( $GLOBALS['mail_calls'] )['destination'], 'Email change freezes new target while binding old account email.' );

	$store = new Store(); $mail = new Mail( $store ); $worker = new MailWorker( $store, $mail, new Locks() ); $id = $mail->queuePasswordReset( $user, 'nativeResetKey', 'en_US' ); $GLOBALS['mail_responses'] = array( true ); $worker->run();
	check( 'accepted' === $store->rows[$id]['status'] && str_contains( end( $GLOBALS['mail_calls'] )['body'], '/en/reset-password/?key=nativeResetKey' ), 'Native reset key sends only while WP validates it.' );
	$id = $mail->queuePasswordReset( $user, 'obsoleteKey', 'en_US' ); $worker->run(); check( 'cancelled' === $store->rows[$id]['status'], 'Invalidated WP reset key is not mailed.' );
	$id = $mail->queueSecurity( 7, 'old@example.test', 'zh_CN', 'email_changed' ); $GLOBALS['mail_responses'] = array( true ); $worker->run();
	check( 'accepted' === $store->rows[$id]['status'] && 'old@example.test' === end( $GLOBALS['mail_calls'] )['destination'], 'Email-change security notice can target frozen old address.' );
	check( empty( $GLOBALS['mail_hooks']['phpmailer_init'] ), 'Temporary transport hook always removed, including after errors.' );
	putenv( 'AISH_ACCOUNT_MAIL_KEY_FIXTURE' ); putenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION' );
	echo "Account mail: {$checks} isolated/Mock checks passed; no SMTP delivery tested.\n";
}
