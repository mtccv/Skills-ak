<?php
declare(strict_types=1);

/** Real WordPress/MySQL privacy checks in a new random table namespace; no business rows touched. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE9_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
if ( get_option( 'aish_schema_version' ) !== '1.7.0' ) { exit( 3 ); }
require_once ABSPATH . 'wp-admin/includes/user.php';

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Store;
use AISkillsHub\Core\Community\Privacy;
use AISkillsHub\Core\Community\Query;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Database\Schema;

$prefix = 'p9privacy_' . bin2hex( random_bytes( 5 ) ) . '_'; $option = $prefix . 'version';
$db = new Database( $GLOBALS['wpdb'], $prefix ); $migrator = new Migrator( $db, $option ); $privacy = new Privacy( $db, new Locks( $db ) );
$query = new Query( $db, $migrator, Core::boot()->get( 'accounts' ) );
$checks = array(); $users = array(); $tables = array(); $failure = null;
$now = gmdate( 'Y-m-d H:i:s' ); $old = gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) )[0] ?? 0 );
if ( ! $admin ) { exit( 4 ); }
wp_set_current_user( $admin );
add_filter( 'pre_wp_mail', static fn() => true ); // Explicit Mock transport; no actual messages are sent.
add_filter( 'pre_http_request', static fn() => new WP_Error( 'qa_no_external_requests', 'External requests disabled.' ), 1 );
$check = static function ( bool $condition, string $label ) use ( &$checks ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$reject = static function ( callable $callback, string $label ) use ( $check ): void { try { $callback(); } catch ( Throwable $error ) { $check( true, $label ); return; } throw new RuntimeException( $label ); };
$user = static function ( string $suffix ) use ( &$users, $prefix ): int {
	$id = wp_insert_user( array( 'user_login' => $prefix . $suffix, 'user_email' => $prefix . $suffix . '@example.invalid', 'user_pass' => bin2hex( random_bytes( 20 ) ), 'role' => 'subscriber', 'display_name' => 'Community privacy QA' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'qa_user_unavailable' ); }
	$users[] = (int) $id;
	update_user_meta( $id, '_aish_account_state', 'active' ); update_user_meta( $id, '_aish_email_verified_at', gmdate( 'Y-m-d H:i:s' ) ); update_user_meta( $id, '_aish_verified_email_hash', Store::emailHash( get_userdata( $id )->user_email ) );
	return (int) $id;
};
try {
	foreach ( Schema::definitions( $db, '1.7.0' ) as $entity => $definition ) {
		if ( $db->value( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( trim( $db->table( $entity ), '`' ) ) ) ) { throw new RuntimeException( 'fixture_namespace_exists' ); }
		$tables[] = $entity;
	}
	$check( true === $migrator->migrate(), 'Fresh isolated privacy fixture uses physical schema 1.7.0.' );
	$owner = $user( 'owner' ); $other = $user( 'other' ); $moderator = $user( 'moderator' );
	$skill = $db->insert( 'skills', array( 'status' => 'published', 'review_status' => 'approved', 'revision' => 1, 'reviewed_revision' => 1, 'reviewer_id' => $admin, 'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
	foreach ( array( 'en_US', 'zh_CN' ) as $locale ) { $db->insert( 'skill_translations', array( 'skill_id' => $skill, 'locale' => $locale, 'title' => 'Privacy QA Skill', 'slug' => 'privacy-qa-skill', 'summary' => 'QA summary', 'content' => 'QA public content', 'created_at' => $now, 'updated_at' => $now ) ); }
	$beforeSkill = hash( 'sha256', serialize( $db->rows( 'SELECT * FROM ' . $db->table( 'skills' ) ) ) );
	foreach ( array_merge( array( $skill ), range( 1001, 1050 ) ) as $favoriteSkill ) { $db->insert( 'favorites', array( 'user_id' => $owner, 'skill_id' => $favoriteSkill, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) ); }
	$db->insert( 'favorites', array( 'user_id' => $other, 'skill_id' => $skill, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$ownReview = $db->insert( 'reviews', array( 'user_id' => $owner, 'skill_id' => $skill, 'state' => 'approved', 'created_at' => $now, 'updated_at' => $now, 'first_published_at' => $now ) );
	$foreignReview = $db->insert( 'reviews', array( 'user_id' => $other, 'skill_id' => $skill, 'state' => 'approved', 'created_at' => $now, 'updated_at' => $now, 'first_published_at' => $now ) );
	$firstRevision = 0; $lastRevision = 0;
	for ( $i = 1; $i <= 51; ++$i ) {
		$lastRevision = $db->insert( 'review_revisions', array( 'review_id' => $ownReview, 'revision' => $i, 'locale' => 'zh_CN', 'rating' => 5, 'title' => 'OWN_TITLE', 'comment' => 'OWN_COMMENT', 'experience' => 'OWN_EXPERIENCE', 'pros' => 'OWN_PROS', 'cons' => 'OWN_CONS', 'platform' => 'OWN_PLATFORM', 'version_label' => 'OWN_VERSION', 'moderation_state' => 1 === $i ? 'approved' : 'pending', 'moderator_id' => 1 === $i ? $admin : null, 'moderated_at' => 1 === $i ? $now : null, 'moderation_code' => 'approved', 'private_note' => 'PRIVATE_NOTE', 'request_key' => hash( 'sha256', $prefix . 'own-' . $i ), 'created_at' => $now ) );
		if ( 1 === $i ) { $firstRevision = $lastRevision; }
	}
	$foreignRevision = $db->insert( 'review_revisions', array( 'review_id' => $foreignReview, 'revision' => 1, 'locale' => 'en_US', 'rating' => 4, 'title' => 'FOREIGN_TITLE', 'comment' => 'FOREIGN_COMMENT', 'experience' => '', 'pros' => '', 'cons' => '', 'moderation_state' => 'approved', 'moderator_id' => $moderator, 'moderated_at' => $now, 'moderation_code' => 'approved', 'private_note' => 'PRIVATE_NOTE', 'request_key' => hash( 'sha256', $prefix . 'other' ), 'created_at' => $now ) );
	$db->updateById( 'reviews', $ownReview, array( 'approved_revision_id' => $firstRevision, 'pending_revision_id' => $lastRevision ) ); $db->updateById( 'reviews', $foreignReview, array( 'approved_revision_id' => $foreignRevision ) );
	$report = $db->insert( 'review_reports', array( 'review_id' => $foreignReview, 'revision_id' => $foreignRevision, 'reporter_user_id' => $owner, 'locale' => 'zh_CN', 'reason_code' => 'spam', 'details' => 'OWN_REPORT', 'state' => 'pending', 'active_key' => hash( 'sha256', $prefix . 'active-report' ), 'request_key' => hash( 'sha256', $prefix . 'report' ), 'private_note' => 'PRIVATE_NOTE', 'created_at' => $now ) );
	$correction = $db->insert( 'skill_reports', array( 'skill_id' => $skill, 'skill_revision' => 1, 'reporter_user_id' => $owner, 'locale' => 'zh_CN', 'kind' => 'broken_link', 'details' => 'OWN_CORRECTION', 'state' => 'pending', 'active_key' => hash( 'sha256', $prefix . 'active-skill' ), 'request_key' => hash( 'sha256', $prefix . 'skill-report' ), 'handler_id' => $moderator, 'private_note' => 'PRIVATE_NOTE', 'created_at' => $now ) );
	$check( 2 === $query->publicSummary( $skill, 'en_US' )['count'] && 2 === $query->publicSummary( $skill, 'zh_CN' )['count'], 'Approved reviews share one bilingual public population.' );
	$export = $privacy->export( array( 'data' => array(), 'done' => true ), get_userdata( $owner ), 1 ); $encoded = wp_json_encode( $export );
	$check( ! $export['done'] && 102 === count( $export['data'] ), 'First native export page includes at most 50 favorites and 50 revisions plus own reports.' );
	$check( str_contains( $encoded, 'OWN_COMMENT' ) && str_contains( $encoded, 'OWN_REPORT' ) && str_contains( $encoded, 'OWN_CORRECTION' ), 'Own UGC and reports export without public approval.' );
	$check( ! str_contains( $encoded, 'PRIVATE_NOTE' ) && ! str_contains( $encoded, 'FOREIGN_' ) && ! str_contains( $encoded, 'request_key' ) && ! str_contains( $encoded, 'moderator_id' ), 'Internal notes, foreign UGC, request keys and moderator identity are excluded.' );
	$last = $privacy->export( array( 'data' => array(), 'done' => true ), get_userdata( $owner ), 2 ); $check( $last['done'] && 2 === count( $last['data'] ), 'The next bounded native export page completes.' );
	wp_set_current_user( $other ); $reject( fn() => $privacy->export( array( 'data' => array(), 'done' => true ), get_userdata( $owner ), 1 ), 'Subscriber cannot call privacy export for another owner.' ); wp_set_current_user( $admin );
	$reject( fn() => $privacy->erase( true, $owner ), 'Erasure requires durable quarantine before private data changes.' );
	$privacy->isolate( $owner );
	$check( 1 === $query->publicSummary( $skill, 'en_US' )['count'] && 1 === $query->publicSummary( $skill, 'en_US' )['favorites'], 'Isolation immediately removes all review and favorite contributions before redaction finishes.' );
	$fav = $db->table( 'favorites' ); $temporary = '`' . $prefix . 'blocked_favorites`';
	$db->execute( "RENAME TABLE $fav TO $temporary" );
	try { $reject( fn() => $privacy->erase( true, $owner ), 'A real database failure refuses successful cleanup.' ); }
	finally { $db->execute( "RENAME TABLE $temporary TO $fav" ); }
	$check( '1' === get_user_meta( $owner, Privacy::ERASING, true ), 'Database failure keeps immediate public quarantine.' );
	$check( ! $privacy->erase( true, $owner ), 'First erasure batch stays pending for excess favorites and revisions.' );
	$head = $db->row( 'SELECT * FROM ' . $db->table( 'reviews' ) . ' WHERE id=%d', array( $ownReview ) );
	$check( 'withdrawn' === $head['state'] && null === $head['approved_revision_id'] && null === $head['pending_revision_id'] && (int) $head['user_id'] === $owner, 'Partial redaction clears public pointers but retains cleanup ownership.' );
	$check( $privacy->erase( true, $owner ), 'Subsequent batch completes and can safely release quarantine.' );
	$check( null === $db->value( 'SELECT user_id FROM ' . $db->table( 'reviews' ) . ' WHERE id=%d', array( $ownReview ) ) && '' === get_user_meta( $owner, Privacy::ERASING, true ), 'Finished review is anonymous and protected cleanup marker is removed.' );
	$redacted = wp_json_encode( $db->rows( 'SELECT * FROM ' . $db->table( 'review_revisions' ) . ' WHERE review_id=%d', array( $ownReview ) ) );
	$check( ! str_contains( $redacted, 'OWN_' ) && ! str_contains( $redacted, 'PRIVATE_NOTE' ), 'All title, body, experience, pros, cons, platform, version and notes are redacted.' );
	$check( 0 === (int) $db->value( "SELECT COUNT(*) FROM $fav WHERE user_id=%d", array( $owner ) ) && 1 === (int) $db->value( "SELECT COUNT(*) FROM $fav WHERE user_id=%d", array( $other ) ), 'Only the owner favorites are physically removed.' );
	foreach ( array( 'review_reports' => $report, 'skill_reports' => $correction ) as $entity => $id ) { $row = $db->row( 'SELECT * FROM ' . $db->table( $entity ) . ' WHERE id=%d', array( $id ) ); $check( null === $row['reporter_user_id'] && null === $row['active_key'] && '' === $row['details'] && 'closed' === $row['state'], 'Own report type is anonymized: ' . $entity ); }
	$privacy->isolate( $moderator ); $check( $privacy->erase( true, $moderator ), 'Historical moderator personal data cleanup completes.' );
	$approved = $db->row( 'SELECT * FROM ' . $db->table( 'review_revisions' ) . ' WHERE id=%d', array( $foreignRevision ) );
	$check( (int) $approved['moderator_id'] === $moderator && null === $approved['private_note'] && 'FOREIGN_COMMENT' === $approved['comment'], 'Another author keeps approval evidence and text while moderator private notes are removed.' );
	$check( wp_delete_user( $moderator, $admin ), 'Native deletion of the historical moderator succeeds.' );
	$check( 1 === $query->publicSummary( $skill, 'en_US' )['count'], 'Deleting a moderator does not remove another author approved rating.' );
	$oldReportKey = hash( 'sha256', $prefix . 'old-report' );
	$expiredReport = $db->insert( 'skill_reports', array( 'skill_id' => $skill, 'skill_revision' => 1, 'reporter_user_id' => $other, 'locale' => 'en_US', 'kind' => 'correction', 'details' => 'EXPIRED_PRIVATE', 'state' => 'handled', 'request_key' => $oldReportKey, 'handler_id' => $admin, 'private_note' => 'EXPIRED_PRIVATE', 'created_at' => $old, 'resolved_at' => $old ) );
	$futureReport = $db->insert( 'skill_reports', array( 'skill_id' => $skill, 'skill_revision' => 1, 'reporter_user_id' => $other, 'locale' => 'en_US', 'kind' => 'correction', 'details' => 'CURRENT_PRIVATE', 'state' => 'handled', 'request_key' => hash( 'sha256', $prefix . 'new-report' ), 'created_at' => $now, 'resolved_at' => $now ) );
	$eventKey = hash( 'sha256', $prefix . 'event' ); $contentKey = hash( 'sha256', $prefix . 'content' ); $fingerprint = hash( 'sha256', 'public content' );
	$event = $db->insert( 'workflow_events', array( 'event_key' => $eventKey, 'subject_type' => 'review', 'subject_id' => $foreignReview, 'action' => 'approve', 'actor_user_id' => $other, 'metadata_json' => wp_json_encode( array( 'payload_hash' => $fingerprint ) ), 'created_at' => $old, 'retain_until' => $old ) );
	$contentEvent = $db->insert( 'workflow_events', array( 'event_key' => $contentKey, 'subject_type' => 'skill', 'subject_id' => $skill, 'action' => 'public_content_approved', 'actor_user_id' => $other, 'metadata_json' => wp_json_encode( array( 'content_hash' => $fingerprint ) ), 'created_at' => $old, 'retain_until' => $old ) );
	$privacy->retention(); $privacy->retention();
	$row = $db->row( 'SELECT * FROM ' . $db->table( 'skill_reports' ) . ' WHERE id=%d', array( $expiredReport ) );
	$check( '' === $row['details'] && null === $row['reporter_user_id'] && null === $row['handler_id'] && null === $row['private_note'] && $oldReportKey === $row['request_key'], 'Handled report retention scrubs private data while preserving replay prevention.' );
	$check( 'CURRENT_PRIVATE' === $db->value( 'SELECT details FROM ' . $db->table( 'skill_reports' ) . ' WHERE id=%d', array( $futureReport ) ), 'Recent reports are not prematurely erased.' );
	$row = $db->row( 'SELECT * FROM ' . $db->table( 'workflow_events' ) . ' WHERE id=%d', array( $event ) );
	$check( '{}' === $row['metadata_json'] && null === $row['actor_user_id'] && $eventKey === $row['event_key'], 'Expired workflow evidence retains an idempotency tombstone, not personal metadata.' );
	$row = $db->row( 'SELECT * FROM ' . $db->table( 'workflow_events' ) . ' WHERE id=%d', array( $contentEvent ) );
	$check( null === $row['actor_user_id'] && str_contains( $row['metadata_json'], $fingerprint ), 'Public Skill content fingerprint survives actor anonymization.' );
	$check( $beforeSkill === hash( 'sha256', serialize( $db->rows( 'SELECT * FROM ' . $db->table( 'skills' ) ) ) ), 'Stable Skill row and approval evidence never change during privacy cleanup.' );
} catch ( Throwable $error ) { $failure = $error->getMessage(); }
finally {
	wp_set_current_user( $admin );
	foreach ( $users as $id ) { if ( get_userdata( $id ) ) { wp_delete_user( $id, $admin ); } }
	Core::boot()->get( 'account_privacy' )->tick();
	foreach ( array_reverse( $tables ) as $entity ) { $db->execute( 'DROP TABLE IF EXISTS ' . $db->table( $entity ) ); }
	delete_option( $option );
}
echo wp_json_encode( array( 'environment' => array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'mysql' => $GLOBALS['wpdb']->db_version() ), 'kind' => 'Real isolated WordPress/MySQL privacy; Mock mail, no external API', 'assertions' => count( $checks ), 'checks' => $checks, 'passed' => null === $failure, 'failure' => $failure ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure ? 0 : 1 );
