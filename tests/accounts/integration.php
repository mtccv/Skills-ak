<?php
declare(strict_types=1);

/** Real WordPress/MySQL tests, with explicitly mocked wp_mail transport. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE8_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
if ( ! defined( 'DOING_CRON' ) ) { define( 'DOING_CRON', true ); }
require_once ABSPATH . 'wp-admin/includes/user.php';

use AISkillsHub\Core\Accounts\Crypto;
use AISkillsHub\Core\Accounts\Store;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Mail;
use AISkillsHub\Core\Accounts\MailWorker;
use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\AccountException;
use AISkillsHub\Core\Accounts\Avatar;
use AISkillsHub\Core\Accounts\Privacy;
use AISkillsHub\Core\Database\Schema;
use AISkillsHub\Core\Core;

$core = Core::boot(); $db = $core->get( 'database' );
$store = new Store( $db ); $locks = new Locks( $db ); $mail = new Mail( $store );
try { $accounts = $core->get( 'accounts' ); }
catch ( LogicException $e ) { $accounts = new AccountService( $db, $store, $mail, $locks ); $accounts->hooks(); }
$worker = new MailWorker( $store, $mail, $locks );
try { $privacy = $core->get( 'account_privacy' ); $avatar = $core->get( 'account_avatar' ); }
catch ( LogicException $e ) { $avatar = new Avatar( $db, $locks, $accounts ); $privacy = new Privacy( $db, $store, $locks, $accounts, $avatar ); $avatar->register(); $privacy->register(); }
$run = bin2hex( random_bytes( 6 ) ); $cases = array(); $assertions = 0; $users = array();
// This disposable QA database has only our Phase 8 fixture counters. Reset them
// before a repeat run; individual anti-brute-force cases still exercise real limits.
$db->execute( 'DELETE FROM ' . $db->connection()->options . ' WHERE option_name LIKE %s', array( $db->connection()->esc_like( '_aish_account_rate_' ) . '%' ) );
// Only this synthetic rejection has proof that no transport accepted the mail.
add_filter( 'aish_account_mail_rejection_is_definite', static fn( bool $known ): bool => ( $GLOBALS['aish_phase8_qa_transport'] ?? '' ) === 'reject' );
$password = 'Aish-QA-\\' . bin2hex( random_bytes( 16 ) ); $failure = null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 250 );
function p8_assert( bool $condition, string $message ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function p8_reject( callable $fn, string $label ): void { try { $fn(); } catch ( Throwable $e ) { p8_assert( true, $label ); return; } throw new RuntimeException( $label ); }
function p8_case( string $id, callable $fn ): void { global $cases; try { $fn(); $cases[] = array( 'id' => $id, 'status' => 'passed' ); } catch ( Throwable $e ) { $cases[] = array( 'id' => $id, 'status' => 'failed', 'reason' => $e instanceof AccountException ? $e->reason() : $e->getMessage() ); } }
function p8_row( int $id ): array { global $db; return $db->row( 'SELECT * FROM ' . $db->table( 'notification_outbox' ) . ' WHERE id=%d', array( $id ) ) ?? array(); }
function p8_latest( int $id, string $template = 'verify_email' ): array { global $db; return $db->row( 'SELECT * FROM ' . $db->table( 'notification_outbox' ) . ' WHERE recipient_user_id=%d AND template_key=%s ORDER BY id DESC LIMIT 1', array( $id, $template ) ) ?? array(); }
function p8_payload( array $row ): array { return Crypto::decrypt( (string) $row['payload_ciphertext'], (string) $row['key_version'], 'outbox:' . $row['event_key'] ); }
function p8_only_due( int $id ): void { global $db; $table = $db->table( 'notification_outbox' ); $db->execute( "UPDATE $table SET available_at=%s WHERE status IN ('queued','retry')", array( gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) ); $db->updateById( 'notification_outbox', $id, array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ); }
function p8_register( string $suffix, string $locale = 'en_US' ): int {
	global $accounts, $run, $password, $users;
	wp_set_current_user( 0 );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 1, 250 );
	$email = 'p8-' . $run . '-' . $suffix . '@example.invalid';
	$accounts->register( array( 'email' => $email, 'password' => $password, 'display_name' => 'Account QA', 'terms' => '1', 'honeypot' => '', 'role' => 'administrator' ), $locale );
	$id = (int) email_exists( $email );
	p8_assert( $id > 0, 'Native user was created.' ); $users[] = $id;
	return $id;
}

p8_case( 'DB-UPGRADE-17-TABLES-PRESERVE-14', function () use ( $core, $db ): void {
	$before = json_decode( (string) file_get_contents( '/qa/accounts-baseline-' . ( PHP_VERSION_ID >= 80400 ? 'php84' : 'browser' ) . '.json' ), true );
	p8_assert( true === $core->migrator()->migrate(), '1.5 to 1.6 upgrade succeeds.' );
	p8_assert( '1.6.0' === get_option( 'aish_schema_version' ), 'Schema version is 1.6.0.' );
	Schema::verify( $db, '1.6.0' );
	p8_assert( true === $core->migrator()->migrate(), 'Repeated migration succeeds.' );
	$tables = $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', array( $db->connection()->esc_like( $db->prefix() . 'aish_' ) . '%' ) );
	p8_assert( count( $tables ) === 17, 'Exactly the three Phase 8 tables were added.' );
	foreach ( $before['tables'] as $entity => $saved ) {
		$rows = $db->rows( 'SELECT * FROM ' . $db->table( $entity ) ); $hashes = array_map( static fn( $row ) => hash( 'sha256', (string) json_encode( $row ) ), $rows ); sort( $hashes );
		p8_assert( count( $rows ) === $saved['count'] && hash_equals( $saved['sha256'], hash( 'sha256', implode( '', $hashes ) ) ), 'Prior entity is unchanged: ' . $entity );
	}
	$native = $db->connection(); foreach ( array( $native->users, $native->usermeta, $native->options, $native->posts, $native->postmeta ) as $table ) {
		p8_assert( 'InnoDB' === $db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $table ) ), 'Native table supports transactional changes.' );
	}
} );

p8_case( 'CRYPTO-AUTHENTICATED-ENCRYPTION', function (): void {
	$context = 'outbox:' . bin2hex( random_bytes( 32 ) ); $token = bin2hex( random_bytes( 32 ) );
	$one = Crypto::encrypt( array( 'token' => $token ), $context ); $two = Crypto::encrypt( array( 'token' => $token ), $context );
	p8_assert( $one['payload_ciphertext'] !== $two['payload_ciphertext'], 'Fresh nonces produce different ciphertext.' );
	p8_assert( ! str_contains( $one['payload_ciphertext'], $token ), 'Ciphertext does not expose token.' );
	p8_assert( Crypto::decrypt( $one['payload_ciphertext'], $one['key_version'], $context )['token'] === $token, 'Only matching key and context recover payload.' );
	p8_reject( fn() => Crypto::decrypt( $one['payload_ciphertext'], $one['key_version'], 'outbox:' . str_repeat( '0', 64 ) ), 'Cross-message ciphertext substitution is rejected.' );
	$raw = base64_decode( $one['payload_ciphertext'] ); $raw[30] = chr( ord( $raw[30] ) ^ 1 );
	p8_reject( fn() => Crypto::decrypt( base64_encode( $raw ), $one['key_version'], $context ), 'Tampered ciphertext is rejected.' );
} );

$primary = 0;
p8_case( 'SIGNUP-NATIVE-ROLE-HASH-ENCRYPTED-OUTBOX', function () use ( &$primary, $accounts, $db ): void {
	$primary = p8_register( 'primary', 'zh_CN' ); $user = get_userdata( $primary ); $row = p8_latest( $primary ); $payload = p8_payload( $row );
	p8_assert( array( 'subscriber' ) === $user->roles && ! user_can( $user, 'manage_options' ) && ! user_can( $user, 'upload_files' ), 'Client role never grants privileges.' );
	p8_assert( ! str_contains( $user->user_login, '@' ) && ! str_contains( $user->user_login, 'p8-' ), 'Internal login is not derived from email.' );
	p8_assert( ! $accounts->verified( $primary ) && 'pending_email' === $accounts->state( $primary ), 'New account starts pending with no fabricated verification.' );
	p8_assert( 'queued' === $row['status'] && 'zh_CN' === $row['locale'], 'Chinese verification persisted as queued.' );
	$verification = $db->row( 'SELECT * FROM ' . $db->table( 'email_verifications' ) . ' WHERE user_id=%d', array( $primary ) );
	p8_assert( is_array( $verification ) && $verification['token_hash'] !== $payload['token'] && ! str_contains( json_encode( $verification ), $payload['token'] ), 'Verification stores no plaintext token.' );
	p8_assert( ! str_contains( json_encode( $row ), $payload['token'] ) && ! str_contains( json_encode( $row ), $user->user_email ), 'Outbox does not contain plaintext target or token.' );
} );

p8_case( 'DUPLICATE-REGISTER-GENERIC-NATIVE-SAFETY', function () use ( &$primary, $accounts, $password, $db ): void {
	$user = get_userdata( $primary ); $before = p8_latest( $primary );
	$accounts->register( array( 'email' => strtoupper( $user->user_email ), 'password' => $password, 'display_name' => 'Do not replace', 'terms' => true ), 'en_US' );
	p8_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->connection()->users . ' WHERE user_email=%s', array( $user->user_email ) ), 'Repeated normalized email creates one native identity.' );
	p8_assert( get_userdata( $primary )->display_name === 'Account QA', 'Duplicate registration does not modify existing profile.' );
	p8_assert( p8_latest( $primary )['id'] === $before['id'], 'Duplicate registration does not flood verification mail.' );
} );

p8_case( 'TRANSPORT-ACCEPTANCE-VERIFY-REPLAY', function () use ( &$primary, $accounts, $worker, $password ): void {
	$row = p8_latest( $primary ); $payload = p8_payload( $row ); p8_only_due( (int) $row['id'] ); $before = count( $GLOBALS['aish_phase8_qa_mails'] ?? array() );
	$worker->run(); $after = p8_row( (int) $row['id'] );
	p8_assert( 'accepted' === $after['status'] && null === $after['payload_ciphertext'] && ! empty( $after['accepted_at'] ), 'Transport acceptance clears sensitive payload, does not claim delivery.' );
	p8_assert( count( $GLOBALS['aish_phase8_qa_mails'] ) === $before + 1, 'One explicit Mock mail call.' );
	$accounts->verify( $payload['token'] );
	p8_assert( $accounts->verified( $primary ) && 'active' === $accounts->state( $primary ), 'Verification activates the same native user.' );
	p8_reject( fn() => $accounts->verify( $payload['token'] ), 'Consumed token cannot be replayed.' );
	$logged = $accounts->login( get_userdata( $primary )->user_email, $password );
	p8_assert( $logged->ID === $primary, 'Email login uses the existing native identity.' );
	wp_set_current_user( $primary );
} );

p8_case( 'PROFILE-OWNERSHIP-SANITIZATION', function () use ( &$primary, $accounts ): void {
	$other = p8_register( 'other' ); wp_set_current_user( $primary );
	$accounts->saveProfile( array( 'display_name' => '<b>Developer \\ Notes</b>', 'description' => 'A "quoted" \\ biography', 'url' => 'https://example.com/', 'locale' => 'en_US', 'user_id' => $other, 'role' => 'administrator' ) );
	$profile = $accounts->profile();
	p8_assert( ! str_contains( get_userdata( $primary )->display_name, '<' ), 'Profile display name strips markup.' );
	p8_assert( get_userdata( $primary )->display_name === 'Developer \\ Notes' && get_user_meta( $primary, 'description', true ) === 'A "quoted" \\ biography', 'Native API preserves ordinary quotes and backslashes.' );
	p8_assert( get_userdata( $primary )->user_url === 'https://example.com/', 'Allowed profile URL persists in the native user field.' );
	p8_assert( get_userdata( $other )->display_name === 'Account QA', 'Forged target cannot change another user.' );
	p8_assert( ! user_can( $primary, 'manage_options' ), 'Profile cannot escalate role.' );
	wp_set_current_user( $other ); p8_reject( fn() => $accounts->requireOwner(), 'Pending account cannot access verified account operations.' );
	wp_set_current_user( $primary );
} );

p8_case( 'RESET-NATIVE-KEY-SINGLE-USE', function () use ( &$primary, $accounts, $password ): void {
	wp_set_current_user( 0 ); $user = get_userdata( $primary ); $accounts->forgot( $user->user_email, 'en_US' );
	$row = p8_latest( $primary, 'password_reset' ); $payload = p8_payload( $row );
	p8_assert( check_password_reset_key( $payload['key'], $payload['login'] ) instanceof WP_User, 'Key was generated by WordPress.' );
	$newPassword = $password . '-reset'; $accounts->reset( $payload['login'], $payload['key'], $newPassword );
	p8_assert( wp_check_password( $newPassword, get_userdata( $primary )->user_pass, $primary ), 'Native WordPress password is updated.' );
	p8_reject( fn() => $accounts->reset( $payload['login'], $payload['key'], $password ), 'Native reset key cannot be replayed.' );
	p8_reject( fn() => $accounts->login( $user->user_email, $password ), 'Old password no longer authenticates.' );
	$accounts->login( $user->user_email, $newPassword ); wp_set_current_user( $primary );
} );

p8_case( 'EMAIL-CHANGE-VERIFY-NATIVE-GUARD', function () use ( &$primary, $accounts, $password, $run ): void {
	wp_set_current_user( $primary ); $old = get_userdata( $primary )->user_email; $new = 'p8-' . $run . '-changed@example.invalid';
	p8_reject( fn() => $accounts->requestEmailChange( 'wrong', $new, 'en_US' ), 'Changing email requires recent password verification.' );
	$accounts->requestEmailChange( $password . '-reset', $new, 'en_US' );
	p8_assert( get_userdata( $primary )->user_email === $old, 'Current login email remains unchanged while new email pending.' );
	$payload = p8_payload( p8_latest( $primary, 'change_email' ) ); $accounts->verify( $payload['token'] );
	p8_assert( get_userdata( $primary )->user_email === $new && $accounts->verified( $primary ), 'Confirming new email changes current binding and preserves user ID.' );
	p8_reject( fn() => $accounts->verify( $payload['token'] ), 'Email change credential is single use.' );
	wp_set_current_user( 1 ); $nativeNew = 'p8-' . $run . '-admin-change@example.invalid'; $result = wp_update_user( array( 'ID' => $primary, 'user_email' => $nativeNew ) );
	p8_assert( ! is_wp_error( $result ) && ! $accounts->verified( $primary ), 'Native administrator email change invalidates old verification evidence.' );
} );

p8_case( 'EXPIRED-AND-REPLACED-VERIFICATION', function () use ( $accounts, $db, $store ): void {
	$id = p8_register( 'expired' ); $payload = p8_payload( p8_latest( $id ) );
	$verification = $store->verification( $payload['token'] ); $db->updateById( 'email_verifications', (int) $verification['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
	p8_reject( fn() => $accounts->verify( $payload['token'] ), 'Expired verification cannot activate user.' );
	p8_assert( ! $accounts->verified( $id ), 'Expired user remains unverified.' );
	$replacement = bin2hex( random_bytes( 32 ) ); $store->transaction( fn() => $store->issueVerification( $id, 'verify_email', get_userdata( $id )->user_email, $replacement ) );
	p8_reject( fn() => $accounts->verify( $payload['token'] ), 'Replacement invalidates previous link.' );
	$accounts->verify( $replacement ); p8_assert( $accounts->verified( $id ), 'Current replacement remains usable.' );
} );

p8_case( 'RESEND-LIMIT-AND-ROTATION', function () use ( $accounts, $db, $store ): void {
	$id = p8_register( 'resend' ); $row = p8_latest( $id ); $old = p8_payload( $row ); $email = get_userdata( $id )->user_email;
	$accounts->resend( $email, 'en_US' ); p8_assert( p8_latest( $id )['id'] === $row['id'], 'Resend within 60 seconds produces no new delivery.' );
	$verification = $store->verification( $old['token'] ); $db->updateById( 'email_verifications', (int) $verification['id'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 61 ) ) );
	$accounts->resend( $email, 'zh_CN' ); $new = p8_latest( $id );
	p8_assert( $new['id'] !== $row['id'] && $new['locale'] === 'zh_CN', 'Eligible resend creates current locale delivery.' );
	p8_reject( fn() => $accounts->verify( $old['token'] ), 'Resend revokes old link.' );
	$accounts->verify( p8_payload( $new )['token'] ); p8_assert( $accounts->verified( $id ), 'Replacement verification works.' );
} );

p8_case( 'MAIL-KNOWN-FAILURE-RETRY-UNCERTAIN', function () use ( $worker ): void {
	$id = p8_register( 'retry' ); $row = p8_latest( $id ); p8_only_due( (int) $row['id'] );
	$GLOBALS['aish_phase8_qa_transport'] = 'reject'; $worker->run(); $retry = p8_row( (int) $row['id'] );
	p8_assert( 'retry' === $retry['status'] && ! empty( $retry['payload_ciphertext'] ) && strtotime( $retry['available_at'] . ' UTC' ) > time(), 'Explicit Mock rejection keeps encrypted retry with backoff.' );
	$GLOBALS['aish_phase8_qa_transport'] = 'accept'; p8_only_due( (int) $row['id'] ); $worker->run();
	p8_assert( 'accepted' === p8_row( (int) $row['id'] )['status'], 'Known rejection can recover.' );
	$id = p8_register( 'uncertain' ); $row = p8_latest( $id ); p8_only_due( (int) $row['id'] );
	$GLOBALS['aish_phase8_qa_transport'] = 'throw'; $worker->run();
	p8_assert( 'uncertain' === p8_row( (int) $row['id'] )['status'], 'Ambiguous transport exception is uncertain.' );
	$before = count( $GLOBALS['aish_phase8_qa_mails'] ); $worker->run();
	p8_assert( count( $GLOBALS['aish_phase8_qa_mails'] ) === $before, 'Uncertain outcome is not blindly sent again.' );
	$GLOBALS['aish_phase8_qa_transport'] = 'accept';
} );

p8_case( 'MAIL-AMBIGUOUS-FALSE-NOT-RETRIED', function () use ( $worker ): void {
	$id = p8_register( 'ambiguous' ); $row = p8_latest( $id ); p8_only_due( (int) $row['id'] );
	$GLOBALS['aish_phase8_qa_transport'] = 'ambiguous_false'; $worker->run();
	p8_assert( 'uncertain' === p8_row( (int) $row['id'] )['status'], 'Bare wp_mail false cannot establish that transport did not accept.' );
	$before = count( $GLOBALS['aish_phase8_qa_mails'] ); $worker->run(); p8_assert( count( $GLOBALS['aish_phase8_qa_mails'] ) === $before, 'Ambiguous false is not automatically retried.' );
	$GLOBALS['aish_phase8_qa_transport'] = 'accept';
} );

p8_case( 'LEASE-FENCING-CRASH-RECOVERY', function () use ( $store, $db ): void {
	$id = p8_register( 'lease' ); $row = p8_latest( $id ); p8_only_due( (int) $row['id'] ); $claim = $store->claim();
	p8_assert( (int) $claim['id'] === (int) $row['id'], 'Due message is exclusively claimed.' );
	$db->updateById( 'notification_outbox', (int) $row['id'], array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ); $store->recoverExpired(); p8_only_due( (int) $row['id'] ); $next = $store->claim();
	p8_assert( $next && $next['lease_token'] !== $claim['lease_token'], 'Abandoned pre-send work can recover with new fencing lease.' );
	p8_assert( ! $store->markSending( (int) $row['id'], $claim['lease_token'] ), 'Expired worker cannot enter transport.' );
	p8_assert( $store->markSending( (int) $row['id'], $next['lease_token'] ), 'Current worker can enter transport.' );
	$db->updateById( 'notification_outbox', (int) $row['id'], array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ); $store->recoverExpired();
	p8_assert( 'uncertain' === p8_row( (int) $row['id'] )['status'], 'Crash after durable sending marker does not replay.' );
} );

p8_case( 'LEGACY-ADMIN-CAPABILITIES-UNCHANGED', function () use ( $accounts, $run, $password, $db ): void {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) ); p8_assert( ! empty( $admins ), 'Legacy administrator still exists.' );
	$admin = $admins[0]; wp_set_current_user( (int) $admin->ID );
	p8_assert( current_user_can( 'manage_options' ) && current_user_can( 'edit_users' ), 'Existing native admin capabilities remain usable.' );
	p8_assert( ! $accounts->verified( (int) $admin->ID ), 'Legacy admin missing evidence is not falsely marked email verified.' );
	// A historical native administrator may legitimately have no email. Use a
	// disposable native fixture, never alter the existing QA administrator.
	$fixture = wp_insert_user( array( 'user_login' => 'p8_legacy_' . $run, 'user_email' => 'p8-' . $run . '-legacy@example.invalid', 'user_pass' => $password, 'role' => 'administrator' ) );
	if ( ! is_int( $fixture ) || $fixture < 1 ) { throw new RuntimeException( 'Native legacy fixture could not be created.' ); }
	try {
		// Seed a historical pre-module state only on this generated fixture.
		// New-account validation intentionally requires a valid address.
		$native = $db->connection();
		$seeded = $native->update( $native->users, array( 'user_email' => '' ), array( 'ID' => $fixture ) ); clean_user_cache( $fixture );
		p8_assert( 1 === $seeded && '' === get_userdata( $fixture )->user_email, 'Historical empty-email fixture is established.' );
		$result = wp_update_user( array( 'ID' => $fixture, 'display_name' => 'Legacy empty email QA' ) );
		p8_assert( ! is_wp_error( $result ) && '' === get_userdata( $fixture )->user_email, 'Native legacy profile update preserves its empty email without failing.' );
		wp_set_current_user( 0 );
		p8_assert( wp_authenticate( 'p8_legacy_' . $run, $password ) instanceof WP_User, 'Legacy empty-email account can still authenticate by its native login.' );
		wp_set_current_user( $fixture );
		p8_assert( current_user_can( 'manage_options' ) && ! $accounts->verified( $fixture ), 'Legacy administrative access remains available without inventing email verification.' );
	} finally {
		wp_set_current_user( (int) $admin->ID );
		p8_assert( wp_delete_user( $fixture ) && ! get_userdata( $fixture ), 'Native deletion of a historical empty-email identity is recoverable and completes.' );
	}
} );

p8_case( 'PENDING-NATIVE-AUTH-REST-APPLICATION-PASSWORD', function () use ( $accounts, $password ): void {
	$id = p8_register( 'native-pending' ); $user = get_userdata( $id ); wp_set_current_user( 0 );
	p8_assert( wp_authenticate( $user->user_email, $password ) instanceof WP_User, 'Native authentication recognizes account without granting community verification.' );
	$accounts->login( $user->user_email, $password );
	p8_assert( get_current_user_id() === $id, 'Theme permits restricted pending verification session.' );
	p8_assert( is_wp_error( apply_filters( 'rest_authentication_errors', null ) ), 'Pending REST requests are denied.' );
	p8_assert( false === apply_filters( 'wp_is_application_passwords_available_for_user', true, $user ), 'Pending application passwords are disabled.' );
	p8_assert( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) && ! current_user_can( 'upload_files' ), 'Pending user cannot obtain management or broad upload capabilities.' );
	wp_set_current_user( 0 );
} );

p8_case( 'NATIVE-BRUTE-FORCE-CANNOT-BYPASS-LIMIT', function () use ( $password ): void {
	$id = p8_register( 'brute' ); $email = get_userdata( $id )->user_email;
	for ( $i = 0; $i < 10; ++$i ) { p8_assert( is_wp_error( wp_authenticate( $email, 'not-the-password' ) ), 'Incorrect password remains a generic authentication failure.' ); }
	p8_assert( is_wp_error( wp_authenticate( $email, $password ) ), 'Native authenticator cannot overwrite exhausted limiter with a successful password check.' );
} );

p8_case( 'NATIVE-PASSWORD-CHANGE-REVOKES-EMAIL-CHANGE', function () use ( $accounts, $password, $run ): void {
	$id = p8_register( 'password-invalidation' ); $accounts->verify( p8_payload( p8_latest( $id ) )['token'] ); wp_set_current_user( $id );
	$accounts->requestEmailChange( $password, 'p8-' . $run . '-before-native@example.invalid', 'en_US' ); $old = p8_payload( p8_latest( $id, 'change_email' ) );
	wp_set_password( $password . '-native', $id ); wp_set_current_user( 0 );
	p8_reject( fn() => $accounts->verify( $old['token'] ), 'Direct native password replacement invalidates outstanding email-change proof.' );
	wp_set_current_user( $id ); $accounts->requestEmailChange( $password . '-native', 'p8-' . $run . '-before-update@example.invalid', 'en_US' ); $old = p8_payload( p8_latest( $id, 'change_email' ) );
	wp_set_current_user( 1 ); $result = wp_update_user( array( 'ID' => $id, 'user_pass' => $password . '-native-again' ) );
	p8_assert( ! is_wp_error( $result ), 'Native administrator password update remains available.' ); wp_set_current_user( 0 );
	p8_reject( fn() => $accounts->verify( $old['token'] ), 'Native profile password update also invalidates old email-change proof.' );
} );

p8_case( 'PRIVACY-REQUEST-OWNERSHIP-EXPORT', function () use ( $accounts, $privacy, $password, $store ): void {
	$id = p8_register( 'privacy' ); $payload = p8_payload( p8_latest( $id ) ); $accounts->verify( $payload['token'] ); wp_set_current_user( $id );
	p8_reject( fn() => $privacy->request( 'export', 'wrong' ), 'Privacy request requires current password.' );
	$request = $privacy->request( 'export', $password );
	p8_assert( get_post_type( $request ) === 'user_request' && get_post_status( $request ) === 'request-confirmed', 'Authenticated request uses native confirmed privacy storage.' );
	p8_assert( $request === $privacy->request( 'export', $password ), 'Repeated request reuses native unfinished request.' );
	p8_assert( count( $privacy->requests() ) === 1, 'Requester sees own tracked request only.' );
	p8_reject( fn() => $privacy->exporter( get_userdata( $id )->user_email ), 'Subscriber cannot invoke administrator exporter.' );
	$privacy->savePreferences( array( 'public_profile' => false, 'notification_preferences' => array( 'version' => 1, 'account_notifications' => true, 'optional_email' => false, 'locale' => 'en_US' ) ) );
	p8_assert( ! $privacy->preferences()['public_profile'] && ! $privacy->preferences()['notification_preferences']['optional_email'], 'Private profile and marketing-free preference persist.' );
	$other = p8_register( 'privacy-other' ); wp_set_current_user( $other );
	p8_assert( array() === $privacy->requests(), 'Another user cannot list first user request.' );
	wp_set_current_user( 1 ); $export = $privacy->exporter( get_userdata( $id )->user_email );
	p8_assert( ! empty( $export['data'] ) && ! str_contains( json_encode( $export ), $payload['token'] ) && ! str_contains( json_encode( $export ), 'token_hash' ) && ! str_contains( json_encode( $export ), 'payload_ciphertext' ), 'Authorized export excludes verification and encryption credentials.' );
	$erased = $privacy->eraser( get_userdata( $id )->user_email );
	p8_assert( $erased['done'] && $erased['items_removed'] && $erased['items_retained'], 'Erasure explicitly retains native identity and content audit.' );
	p8_assert( get_userdata( $id ) instanceof WP_User && ! $accounts->verified( $id ), 'Erasure does not misreport account deletion.' );
} );

p8_case( 'NATIVE-DELETE-RECOVERABLE-CLEANUP', function () use ( $accounts, $privacy, $store, $db ): void {
	$id = p8_register( 'delete' ); $row = p8_latest( $id ); $oldEmail = get_userdata( $id )->user_email;
	wp_set_current_user( 1 ); p8_assert( wp_delete_user( $id, 1 ), 'Native WordPress deletion completes.' );
	p8_assert( ! get_userdata( $id ) && 'cancelled' === p8_row( (int) $row['id'] )['status'], 'Deletion immediately cancels pending private mail.' );
	$native = $db->connection();
	$request = (int) $db->value( "SELECT post_id FROM {$native->postmeta} WHERE meta_key='_aish_cleanup_target' AND meta_value=%s LIMIT 1", array( (string) $id ) );
	p8_assert( $request > 0 && get_post_type( $request ) === 'user_request', 'Durable cleanup request survives native user deletion and reassignment.' );
	$privacy->tick();
	p8_assert( ! p8_row( (int) $row['id'] ) && get_post_meta( $request, '_aish_cleanup_state', true ) === 'complete', 'Bounded cleanup resumes and clears private account rows.' );
	p8_assert( ! str_contains( json_encode( get_post( $request ) ), $oldEmail ), 'Completed cleanup request removes obsolete email.' );
} );

p8_case( 'AVATAR-DECODER-BOUNDS-PRIVATE-DEFAULT', function () use ( $avatar ): void {
	$source = imagecreatetruecolor( 700, 350 ); ob_start(); imagepng( $source ); $bytes = (string) ob_get_clean(); imagedestroy( $source );
	$normalized = Avatar::normalize( $bytes . '<?php sensitive_payload(); ?>' ); $size = getimagesizefromstring( $normalized );
	p8_assert( $size[0] === 256 && $size[1] === 128 && ! str_contains( $normalized, 'sensitive_payload' ), 'Decoder re-encodes and bounds dimensions without embedded trailing payload.' );
	p8_reject( fn() => Avatar::normalize( '<svg onload="alert(1)"></svg>' ), 'SVG is not accepted.' );
	p8_reject( fn() => Avatar::normalize( str_repeat( 'a', Avatar::MAX_UPLOAD + 1 ) ), 'Oversized input is rejected before decoding.' );
	wp_set_current_user( 0 ); p8_assert( '' === $avatar->url( 1 ), 'Avatar URL cannot target another account.' );
} );

$failed = count( array_filter( $cases, static fn( $case ) => 'failed' === $case['status'] ) );
$report = array( 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $db->value( 'SELECT VERSION()' ), 'schema' => get_option( 'aish_schema_version' ) ), 'transport' => 'Mock pre_wp_mail; no real SMTP or inbox delivery tested', 'cases' => $cases, 'passed' => count( $cases ) - $failed, 'failed' => $failed, 'assertions' => $assertions );
echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failed ? 1 : 0 );
