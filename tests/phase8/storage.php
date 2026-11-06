<?php
declare(strict_types=1);
/** Real MySQL/WordPress storage checks. Creates and removes ONLY its fresh random fixture prefix. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'AISH_PHASE8_STORAGE_QA' ) ) { throw new RuntimeException( 'Explicit isolated storage QA opt-in required.' ); }
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Accounts/Locks.php', 'Accounts/Crypto.php', 'Accounts/Store.php' ) as $file ) { require_once $base . $file; }
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Database\Schema;
use AISkillsHub\Core\Accounts\Store;
use AISkillsHub\Core\Accounts\Crypto;
$count = 0; $checks = array();
$check = static function ( bool $value, string $label ) use ( &$count, &$checks ): void { if ( ! $value ) { throw new RuntimeException( $label ); } ++$count; $checks[] = $label; };
$rejects = static function ( callable $callback, string $label ) use ( $check ): void { try { $callback(); } catch ( Throwable $error ) { $check( true, $label ); return; } throw new RuntimeException( $label ); };
$prefix = 'p8st_' . bin2hex( random_bytes( 5 ) ) . '_';
$option = 'aish_qa_schema_' . $prefix;
$db = new Database( $GLOBALS['wpdb'], $prefix ); $migrator = new Migrator( $db, $option ); $store = new Store( $db );
putenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION=qa_storage' ); putenv( 'AISH_ACCOUNT_MAIL_KEY_QA_STORAGE=' . base64_encode( random_bytes( 32 ) ) );
$tables = array(); $failure = null;
try {
	foreach ( Schema::definitions( $db, '1.6.0' ) as $entity => $definition ) {
		$name = trim( $db->table( $entity ), '`' );
		if ( $db->value( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $name ) ) ) { throw new RuntimeException( 'Fixture namespace collision; refusing to touch an existing table.' ); }
		$tables[] = $entity;
	}
	$check( true === $migrator->migrate( '1.5.0' ), 'Fresh historical schema 1.5.0 installs on real MySQL.' );
	$check( count( $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', array( $GLOBALS['wpdb']->esc_like( $prefix ) . '%' ) ) ) === 14, 'Historical installation has exactly fourteen fixture tables.' );
	$now = Store::now();
	$repository = $db->insert( 'repositories', array( 'owner' => 'qa-owner', 'name' => 'qa-repo', 'created_at' => $now ) );
	$skill = $db->insert( 'skills', array( 'repository_id' => $repository, 'source_path' => 'nested/SKILL.md', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now ) );
	foreach ( array( 'zh_CN', 'en_US' ) as $locale ) { $db->insert( 'skill_translations', array( 'skill_id' => $skill, 'locale' => $locale, 'title' => 'Private QA ' . $locale, 'slug' => 'stable-qa-slug', 'summary' => 'Preserve manual summary', 'content' => '<p>Preserve manual content</p>', 'seo_title' => 'Preserve SEO', 'created_at' => $now, 'updated_at' => $now ) ); }
	$db->insert( 'skill_versions', array( 'skill_id' => $skill, 'commit_sha' => str_repeat( 'a', 40 ), 'content_hash' => hash( 'sha256', 'QA source' ), 'source_content' => 'QA source', 'created_at' => $now ) );
	$before = array();
	foreach ( Schema::definitions( $db, '1.5.0' ) as $entity => $definition ) { $before[ $entity ] = hash( 'sha256', serialize( $db->rows( 'SELECT * FROM ' . $db->table( $entity ) ) ) ); }
	$check( true === $migrator->migrate( '1.6.0' ), 'Upgrade 1.5.0 to 1.6.0 succeeds.' );
	$check( '1.6.0' === $migrator->version(), 'Version advances only to the verified 1.6.0.' );
	Schema::verify( $db, '1.6.0' ); $check( true, 'Physical account columns, full indexes, binary hashes, InnoDB and charset verify.' );
	$check( count( $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', array( $GLOBALS['wpdb']->esc_like( $prefix ) . '%' ) ) ) === 17, 'Phase 8 has exactly seventeen tables, no Phase 9 tables.' );
	foreach ( $before as $entity => $fingerprint ) { $check( $fingerprint === hash( 'sha256', serialize( $db->rows( 'SELECT * FROM ' . $db->table( $entity ) ) ) ), 'Original data preserved: ' . $entity ); }
	$check( true === $migrator->migrate( '1.6.0' ) && true === $migrator->migrate( '1.6.0' ), 'Repeated installation and upgrade are idempotent.' );
	$check( is_wp_error( $migrator->migrate( '1.5.0' ) ), 'Downgrade rejected without deleting data.' );
	$token = bin2hex( random_bytes( 32 ) );
	$proof = $store->transaction( fn() => $store->issueVerification( 1, 'verify_email', 'qa@example.test', $token ) );
	$check( $proof['token_hash'] !== $token && $proof['token_hash'] === Store::tokenHash( $token ), 'Only secret-protected verification hash is persisted.' );
	$check( $store->verification( $token )['id'] == $proof['id'] && null === $store->verification( str_repeat( 'x', 64 ) ), 'High entropy verification lookup rejects malformed input.' );
	$check( $store->transaction( fn() => $store->consumeVerification( (int) $proof['id'] ) ), 'Verification is consumed once.' );
	$check( ! $store->transaction( fn() => $store->consumeVerification( (int) $proof['id'] ) ), 'Verification replay is rejected.' );
	$replacement = $store->transaction( fn() => $store->issueVerification( 1, 'verify_email', 'qa@example.test', bin2hex( random_bytes( 32 ) ) ) );
	$newest = $store->transaction( fn() => $store->issueVerification( 1, 'verify_email', 'qa@example.test', bin2hex( random_bytes( 32 ) ) ) );
	$check( null === $store->verificationById( (int) $replacement['id'] )['active_key'] && $store->activeVerification( 1, 'verify_email' )['id'] == $newest['id'], 'Resend invalidates previous proof and retains one active identity.' );
	$db->updateById( 'email_verifications', (int) $newest['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
	$check( ! $store->consumeVerification( (int) $newest['id'] ), 'Expired proof cannot be consumed.' );
	$active = $store->transaction( fn() => $store->issueVerification( 1, 'verify_email', 'qa@example.test', bin2hex( random_bytes( 32 ) ) ) );
	$rejects( fn() => $db->insert( 'email_verifications', array_diff_key( $active, array( 'id' => true ) ) ), 'Real unique token/active-key constraints reject a duplicate.' );
	$rowsBefore = (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'email_verifications' ) );
	$rejects( fn() => $store->transaction( function () use ( $store ): void { $store->issueVerification( 2, 'verify_email', 'second@example.test', bin2hex( random_bytes( 32 ) ) ); throw new RuntimeException( 'Intentional QA rollback.' ); } ), 'Account transaction failure returns failure.' );
	$check( $rowsBefore === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'email_verifications' ) ) && null === $store->activeVerification( 2, 'verify_email' ), 'Real rollback retains no partial verification.' );
	$queue = static function ( string $event = '' ) use ( $store ): int {
		return $store->queue( array( 'recipient_user_id' => 1, 'event_key' => $event ?: bin2hex( random_bytes( 32 ) ), 'template_key' => 'verify_email', 'locale' => 'zh_CN', 'destination_hash' => Store::emailHash( 'qa@example.test' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'destination' => 'qa@example.test', 'token' => str_repeat( 'a', 64 ), 'version' => 1 ) );
	};
	$event = bin2hex( random_bytes( 32 ) ); $mailId = $queue( $event );
	$check( $queue( $event ) === $mailId, 'Duplicate delivery event queues a single outbox row.' );
	$rejects( fn() => $store->queue( array( 'recipient_user_id' => 1, 'event_key' => $event, 'template_key' => 'verify_email', 'locale' => 'zh_CN', 'destination_hash' => Store::emailHash( 'qa@example.test' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'destination' => 'qa@example.test', 'token' => str_repeat( 'c', 64 ), 'version' => 1 ) ), 'Reusing an active mail event for different credential payload is rejected.' );
	$claimed = $store->claim();
	$check( $claimed && (int) $claimed['id'] === $mailId && 'claimed' === $claimed['status'] && 1 === (int) $claimed['attempts'], 'A due message receives its own bounded lease.' );
	$check( null === $store->claim(), 'A second claim cannot duplicate the leased message.' );
	$check( $store->decryptPayload( $claimed )['destination'] === 'qa@example.test' && ! str_contains( $claimed['payload_ciphertext'], 'qa@example.test' ), 'Outbox stores authenticated ciphertext and can decrypt inside worker.' );
	$check( ! $store->markSending( $mailId, str_repeat( 'b', 64 ) ), 'Wrong lease cannot mark a transport attempt.' );
	$check( $store->markSending( $mailId, $claimed['lease_token'] ), 'Sending intent is durable before the transport call.' );
	$check( $store->finish( $mailId, $claimed['lease_token'], 'accepted' ), 'Transport acceptance can be persisted with current lease.' );
	$terminal = $db->row( 'SELECT * FROM ' . $db->table( 'notification_outbox' ) . ' WHERE id=%d', array( $mailId ) );
	$check( 'accepted' === $terminal['status'] && null === $terminal['payload_ciphertext'] && null !== $terminal['accepted_at'], 'Acceptance destroys encrypted sensitive payload; it does not claim delivery.' );
	$check( ! $store->finish( $mailId, $claimed['lease_token'], 'failed' ), 'Completed lease cannot overwrite the result.' );
	$interrupted = $queue(); $claimed = $store->claim();
	$db->updateById( 'notification_outbox', $interrupted, array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
	$store->recoverExpired();
	$check( 'retry' === $db->value( 'SELECT status FROM ' . $db->table( 'notification_outbox' ) . ' WHERE id=%d', array( $interrupted ) ), 'Crash before sending intent is safely recoverable.' );
	$claimed = $store->claim(); $store->markSending( $interrupted, $claimed['lease_token'] );
	$db->updateById( 'notification_outbox', $interrupted, array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
	$store->recoverExpired();
	$terminal = $db->row( 'SELECT status,payload_ciphertext FROM ' . $db->table( 'notification_outbox' ) . ' WHERE id=%d', array( $interrupted ) );
	$check( 'uncertain' === $terminal['status'] && null === $terminal['payload_ciphertext'] && null === $store->claim(), 'Crash after sending intent becomes uncertain without automatic duplicate.' );
	$retryId = $queue(); $claimed = $store->claim();
	$check( $store->retry( $retryId, $claimed['lease_token'], 60, 'password=secret-value' ), 'Known transport failure can retry.' );
	$retry = $db->row( 'SELECT last_error,available_at FROM ' . $db->table( 'notification_outbox' ) . ' WHERE id=%d', array( $retryId ) );
	$check( 'mail_failed' === $retry['last_error'] && $retry['available_at'] > Store::now(), 'Errors are fixed-code only and retries have backoff.' );
	$store->cancelUserMail( 1 );
	$check( 'cancelled' === $store->latestMail( 1 )['status'], 'User cancellation stops pending mail.' );
	$noticeKey = bin2hex( random_bytes( 32 ) );
	$notice = $store->notify( 1, $noticeKey, 'email_verified', 'zh_CN' );
	$check( $notice === $store->notify( 1, $noticeKey, 'email_verified', 'zh_CN' ), 'Notification events are idempotent per user.' );
	$rejects( fn() => $store->notify( 1, $noticeKey, 'password_changed', 'zh_CN' ), 'Reusing a notification event for different intent is rejected.' );
	$check( 1 === count( $store->notifications( 1 ) ) && array() === $store->notifications( 2 ), 'Notifications project only the requested account.' );
	$check( ! $store->markRead( 2, $notice ) && $store->markRead( 1, $notice ), 'Read mutation includes its user ownership predicate.' );
	$check( $store->markRead( 1, $notice ) && $store->markRead( 1 ) && $store->markRead( 2 ), 'Mark-read retries and empty mark-all requests are idempotent.' );
	$rejects( fn() => $store->notifications( 1, 20, 20001 ), 'Out-of-range pagination fails explicitly rather than repeating a clamped page.' );
	$rejects( fn() => $store->notify( 1, bin2hex( random_bytes( 32 ) ), 'email_verified', 'en_US', array( 'token' => 'secret' ) ), 'Notifications reject credential-shaped parameters.' );
	$verificationTable = $db->table( 'email_verifications' );
	$db->execute( "ALTER TABLE $verificationTable MODIFY token_hash char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" );
	$rejects( fn() => Schema::verify( $db, '1.6.0' ), 'Verification detects a nonbinary hash collation.' );
	$db->execute( "ALTER TABLE $verificationTable MODIFY token_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL" );
	Schema::verify( $db, '1.6.0' ); $check( true, 'Correct account schema verifies after the isolated corruption is repaired.' );
} catch ( Throwable $error ) { $failure = $error; }
finally {
	// Only this invocation's random, preflight-empty namespace is eligible for fixture cleanup.
	foreach ( array_reverse( $tables ) as $entity ) { $db->execute( 'DROP TABLE IF EXISTS ' . $db->table( $entity ) ); }
	delete_option( $option ); putenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION' ); putenv( 'AISH_ACCOUNT_MAIL_KEY_QA_STORAGE' );
}
if ( $failure ) { throw $failure; }
echo wp_json_encode( array( 'suite' => 'phase8-storage', 'environment' => 'real WordPress/MySQL; no external mail', 'php' => PHP_VERSION, 'mysql' => $db->value( 'SELECT VERSION()' ), 'passed' => $count, 'failed' => 0, 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
