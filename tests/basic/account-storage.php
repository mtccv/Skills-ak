<?php
declare(strict_types=1);
// Isolated contracts: real PHP sodium, modeled wpdb; no WordPress/MySQL/mail service.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'isolated_account_test' );
define( 'ARRAY_A', 'ARRAY_A' );
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_salt( string $scheme ): string { return 'isolated-account-test-salt-with-no-production-use'; }
class wpdb {
	public string $prefix = 'fixture_'; public string $users = 'fixture_users'; public string $last_error = '';
	public array $queries = array(); private array $prepared = array(); public bool $busy = false;
	public function suppress_errors( bool $value ): bool { return false; }
	public function prepare( string $sql, array $values ): string { $key = 'p:' . count( $this->prepared ); $this->prepared[ $key ] = array( $sql, $values ); return $key; }
	public function get_var( string $key ): int {
		[ $sql, $values ] = $this->prepared[ $key ]; $this->queries[] = array( $sql, $values );
		return $this->busy && str_contains( $sql, 'GET_LOCK' ) ? 0 : 1;
	}
}
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Accounts/Locks.php', 'Accounts/Crypto.php', 'Accounts/Store.php' ) as $file ) { require $base . $file; }
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Schema;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Crypto;
use AISkillsHub\Core\Accounts\Store;
$count = 0;
function check( bool $value, string $message ): void { global $count; if ( ! $value ) { throw new RuntimeException( $message ); } ++$count; }
function rejects( callable $callback, string $message ): void { try { $callback(); } catch ( Throwable $error ) { check( true, $message ); return; } throw new RuntimeException( $message ); }
$connection = new wpdb(); $db = new Database( $connection );
foreach ( array( '1.0.0' => 8, '1.1.0' => 8, '1.2.0' => 8, '1.3.0' => 8, '1.4.0' => 11, '1.5.0' => 14, '1.6.0' => 17 ) as $version => $tables ) { check( count( Schema::definitions( $db, $version ) ) === $tables, 'Version-specific table count.' ); }
foreach ( Schema::definitions( $db, '1.5.0' ) as $entity => $definition ) { check( Schema::definitions( $db, '1.6.0' )[ $entity ] === $definition, 'Existing fourteen definitions unchanged.' ); }
$current = Schema::definitions( $db, '1.6.0' );
foreach ( array( 'email_verifications', 'user_notifications', 'notification_outbox' ) as $entity ) {
	check( str_contains( $current[ $entity ], 'ENGINE=InnoDB' ) && str_contains( $current[ $entity ], 'ascii_bin' ), 'Account tables preserve transactional binary identities.' );
}
check( ! isset( Schema::COLUMNS['favorites'], Schema::COLUMNS['reviews'] ), 'Later-phase tables absent.' );
check( Store::emailHash( ' User@EXAMPLE.test ' ) === Store::emailHash( 'user@example.test' ), 'Email canonicalization agrees across service paths.' );
check( Store::emailHash( 'a.b@example.test' ) !== Store::emailHash( 'ab@example.test' ), 'Provider-specific dot folding is not performed.' );
check( Store::emailHash( 'user@example.test' ) !== hash( 'sha256', 'user@example.test' ), 'Email hash is server-secret protected.' );
$token = bin2hex( random_bytes( 32 ) );
check( Store::tokenHash( $token ) !== hash( 'sha256', $token ), 'Verification hash is server-secret protected.' );
rejects( fn() => Locks::email( "name@example.test\r\nBcc:evil@example.test" ), 'Header injection rejected.' );
rejects( fn() => Locks::email( str_repeat( 'a', 90 ) . '@example.test' ), 'Native WordPress email column bound enforced.' );
$locks = new Locks( $db );
$originalMode = class_exists( 'mysqli_driver' ) ? ( new mysqli_driver() )->report_mode : null;
$locks->with( array( 'b@example.test', 'a@example.test' ), 42, function () use ( $locks, $connection ): void {
	check( 3 === count( $connection->queries ), 'Two sorted email locks precede account lock.' );
	$locks->with( array( 'a@example.test' ), 42, function () use ( $connection ): void { check( 3 === count( $connection->queries ), 'Nested native hooks are reentrant without reacquiring SQL locks.' ); } );
	rejects( fn() => $locks->withEmails( array( 'new@example.test' ), static fn() => null ), 'A new email lock cannot invert account lock ordering.' );
	if ( class_exists( 'mysqli_driver' ) ) { check( ( new mysqli_driver() )->report_mode === ( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ), 'Held locks forbid wpdb automatic reconnect replay.' ); }
} );
check( 6 === count( $connection->queries ), 'Each actual named lock is released once.' );
check( $connection->queries[0][1][0] < $connection->queries[1][1][0], 'Email locks use stable lexical order.' );
if ( null !== $originalMode ) { check( ( new mysqli_driver() )->report_mode === $originalMode, 'The driver reporting mode is restored.' ); }
$release = $locks->acquire( array(), 42 ); $release(); $release();
check( 8 === count( $connection->queries ), 'Native-hook release closure is idempotent.' );
$connection->busy = true;
rejects( fn() => $locks->withUser( 42, static fn() => null ), 'Lock contention fails closed.' );
if ( null !== $originalMode ) { check( ( new mysqli_driver() )->report_mode === $originalMode, 'Lock failure restores reporting guard.' ); }
$connection->busy = false;
putenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION' );
check( ! Crypto::ready(), 'No mail key means no encrypted queue readiness.' );
putenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION=qa_storage' );
putenv( 'AISH_ACCOUNT_MAIL_KEY_QA_STORAGE=' . base64_encode( random_bytes( 32 ) ) );
check( Crypto::ready(), 'Valid dedicated 32-byte server key is available.' );
$event = bin2hex( random_bytes( 32 ) ); $context = 'outbox:' . $event;
$payload = array( 'destination' => 'user@example.test', 'token' => $token, 'version' => 1, 'label' => '邮箱验证' );
$encrypted = Crypto::encrypt( $payload, $context );
check( Crypto::decrypt( $encrypted['payload_ciphertext'], $encrypted['key_version'], $context ) === $payload, 'Authenticated bilingual payload round trip.' );
check( ! str_contains( $encrypted['payload_ciphertext'], $token ) && ! str_contains( $encrypted['payload_ciphertext'], 'user@example.test' ), 'Persistent ciphertext contains neither readable address nor credential.' );
check( Crypto::encrypt( $payload, $context )['payload_ciphertext'] !== $encrypted['payload_ciphertext'], 'Fresh nonces prevent deterministic ciphertext reuse.' );
rejects( fn() => Crypto::decrypt( $encrypted['payload_ciphertext'], $encrypted['key_version'], 'outbox:' . str_repeat( 'b', 64 ) ), 'Ciphertext cannot be transplanted to another event.' );
rejects( fn() => Crypto::decrypt( $encrypted['payload_ciphertext'], 'missing', $context ), 'Missing old rotation key fails closed.' );
$raw = base64_decode( $encrypted['payload_ciphertext'] ); $raw[30] = chr( ord( $raw[30] ) ^ 1 );
rejects( fn() => Crypto::decrypt( base64_encode( $raw ), $encrypted['key_version'], $context ), 'Tampered ciphertext authentication fails.' );
rejects( fn() => Crypto::decrypt( 'not-base64!', $encrypted['key_version'], $context ), 'Malformed ciphertext fails.' );
rejects( fn() => Crypto::encrypt( array( 'large' => str_repeat( 'x', 32769 ) ), $context ), 'Payload size has a hard bound.' );
rejects( fn() => Crypto::encrypt( $payload, 'untrusted-context' ), 'AAD context is typed and bounded.' );
putenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION' ); putenv( 'AISH_ACCOUNT_MAIL_KEY_QA_STORAGE' );
echo 'Account storage: ' . $count . " isolated assertions passed (real sodium; modeled wpdb, no WordPress/MySQL/mail).\n";
