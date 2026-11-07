<?php
declare(strict_types=1);
/** Real aggregate equivalence and freshness. Synthetic rows and faults are rolled back. */
require __DIR__ . '/bootstrap.php';
wp_set_current_user( $admin );
$workbench = $core->get( 'operations_workbench' ); $dashboard = $core->get( 'operations_dashboard' );
function metric_values( object $dashboard ): array { return array_column( $dashboard->snapshot()['metrics'], 'value', 'key' ); }
function queue_counts_match(): void {
	global $workbench, $db, $core;
	foreach ( array_keys( \AISkillsHub\Core\Operations\Workbench::available() ) as $type ) {
		$states = \AISkillsHub\Core\Operations\Workbench::states( $type ); $counts = $workbench->counts( $db, $core->migrator(), $type, $states );
		foreach ( $states as $state ) { p13_assert( $counts[ $state ] === $workbench->listing( $type, array( 'state' => $state, 'per_page' => 1 ) )['total'], $type . '/' . $state . ' equals existing queue total.' ); }
	}
}
p13_case( 'unchanged-domain-queue-counts', static function (): void { queue_counts_match(); } );
$wpdb->query( 'START TRANSACTION' ); $inserted = array();
try {
	$now = gmdate( 'Y-m-d H:i:s' ); $repo = (int) $db->value( 'SELECT MIN(id) FROM ' . $db->table( 'repositories' ) ); $skill = (int) $db->value( 'SELECT MIN(id) FROM ' . $db->table( 'skills' ) ); $review = $db->row( 'SELECT id,skill_id FROM ' . $db->table( 'reviews' ) . ' ORDER BY id LIMIT 1' );
	if ( ! $repo || ! $skill || ! $review ) { throw new RuntimeException( 'Preserved QA fixture references unavailable.' ); }
	$base = metric_values( $dashboard );
	foreach ( array( 'pending', 'reviewing', 'handled', 'closed' ) as $state ) {
		foreach ( array( 'review_reports', 'skill_reports' ) as $table ) {
			$data = array( 'locale' => 'en_US', 'details' => 'Synthetic rollback-only aggregate fixture.', 'state' => $state, 'request_key' => bin2hex( random_bytes( 32 ) ), 'created_at' => $now );
			$data += 'review_reports' === $table ? array( 'review_id' => (int) $review['id'], 'revision_id' => 1, 'reason_code' => 'other' ) : array( 'skill_id' => $skill, 'skill_revision' => 1, 'kind' => 'other' );
			$inserted[ $table ][] = $db->insert( $table, $data );
		}
	}
	foreach ( array( 'pending', 'approved', 'rejected', 'withdrawn', 'revoked', 'disputed' ) as $state ) {
		$inserted['author_claims'][] = $db->insert( 'author_claims', array( 'skill_id' => $skill, 'claim_role' => 'original_author', 'source_revision' => 1, 'state' => $state, 'proof_kind' => 'manual', 'proof_json' => wp_json_encode( array( 'v' => 1, 'application' => array( 'locale' => 'en_US', 'reason' => 'Synthetic aggregate fixture', 'evidence_urls' => array() ), 'decisions' => array() ) ), 'request_key' => bin2hex( random_bytes( 32 ) ), 'created_at' => $now, 'updated_at' => $now ) );
	}
	foreach ( array( 'incomplete', 'failed', 'retry', 'complete' ) as $state ) { $inserted['jobs'][] = $db->insert( 'jobs', array( 'repository_id' => $repo, 'kind' => 'sync', 'status' => $state, 'state_json' => '{}', 'available_at' => $now, 'deadline_at' => $now, 'created_at' => $now, 'updated_at' => $now ) ); }
	$aiJobs = array();
	foreach ( array( array( 'needs_review', 'pending' ), array( 'needs_review', 'edited' ), array( 'needs_review', 'accepted' ), array( 'failed', 'pending' ), array( 'failed', 'pending' ), array( 'complete', 'accepted' ) ) as [ $state, $reviewState ] ) {
		$id = $db->insert( 'ai_jobs', array( 'mode' => 'both', 'request_key' => bin2hex( random_bytes( 32 ) ), 'profile_json' => '{}', 'profile_hash' => str_repeat( 'a', 64 ), 'provider' => 'mock', 'model' => 'never-requested', 'prompt_version' => 'qa-only', 'source_json' => '{}', 'status' => $state, 'review_status' => $reviewState, 'requester_id' => $admin, 'available_at' => $now, 'deadline_at' => $now, 'created_at' => $now, 'updated_at' => $now ) ); $aiJobs[] = $id; $inserted['ai_jobs'][] = $id;
	}
	foreach ( array( array( $aiJobs[3], 1 ), array( $aiJobs[3], 2 ), array( $aiJobs[5], 1 ) ) as [ $job, $attempt ] ) { $inserted['ai_attempts'][] = $db->insert( 'ai_attempts', array( 'job_id' => $job, 'attempt_number' => $attempt, 'request_key' => bin2hex( random_bytes( 32 ) ), 'status' => 'uncertain', 'started_at' => $now ) ); }
	p13_case( 'nonzero-state-aggregate-equivalence', static function () use ( $base, $dashboard ): void {
		queue_counts_match(); $current = metric_values( $dashboard );
		foreach ( array( 'review_reports_pending', 'review_reports_reviewing', 'skill_reports_pending', 'skill_reports_reviewing', 'claims_pending', 'github_incomplete', 'github_failed', 'github_retry' ) as $key ) { p13_assert( $current[ $key ] === $base[ $key ] + 1, $key . ' keeps its state and record unit.' ); }
		p13_assert( $current['ai_drafts'] === $base['ai_drafts'] + 2, 'Only pending/edited needs-review drafts counted.' );
		p13_assert( $current['ai_failed'] === $base['ai_failed'] + 2, 'Failed jobs counted once per job.' );
		p13_assert( $current['ai_uncertain'] === $base['ai_uncertain'] + 1, 'Multiple uncertain attempts count one failed job; completed jobs excluded.' );
	} );
	p13_case( 'publication-freshness-no-security-cache', static function () use ( $dashboard, $db ): void {
		$ids = ( new \AISkillsHub\Core\Frontend\PublicCatalog( $db, $GLOBALS['core']->migrator() ) )->matchingIdsSql( 'en_US', array() );
		$id = (int) $db->value( 'SELECT MIN(id) FROM ' . $db->table( 'skills' ) . " WHERE id IN ($ids)" ); p13_assert( $id > 0, 'Published QA resource available.' );
		$before = metric_values( $dashboard ); $db->updateById( 'skills', $id, array( 'review_status' => 'pending' ) ); $after = metric_values( $dashboard );
		p13_assert( $after['public_en_US'] === $before['public_en_US'] - 1, 'New snapshot immediately excludes a withdrawn content approval.' );
		$db->updateById( 'skills', $id, array( 'review_status' => 'approved' ) ); p13_assert( metric_values( $dashboard )['public_en_US'] === $before['public_en_US'], 'Subsequent current-state snapshot rechecks publication.' );
	} );
	p13_case( 'fresh-actor-restriction-before-counts', static function () use ( $admin, $wpdb, $dashboard ): void {
		$dashboard->snapshot();
		// A rollback-only fault injection tests fresh account checks even for a
		// previously authorized object; public account APIs protect administrators.
		$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $admin, 'meta_key' => '_aish_account_state', 'meta_value' => 'suspended' ) ); $id = (int) $wpdb->insert_id;
		p13_reject( fn() => $dashboard->snapshot(), 'Changed actor state immediately denies a subsequent snapshot.', array( 'forbidden' ) );
		$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => $id ) ); p13_assert( count( $dashboard->snapshot()['metrics'] ) > 0, 'Restored transaction state is freshly checked.' );
	} );
} finally { $wpdb->query( 'ROLLBACK' ); }
p13_case( 'rollback-preserved-existing-data', static function () use ( $inserted, $db ): void {
	foreach ( $inserted as $table => $ids ) { p13_assert( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( $table ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' ), 'No synthetic ' . $table . ' rows survived rollback.' ); }
	queue_counts_match();
} );
p13_assert( 0 === $externalRequests && 0 === $mockMail, 'Dashboard reads do not initiate external HTTP or mail.' );
p13_report( 'Real WordPress/MySQL Dashboard aggregate scopes, current publication and account authorization; rolled-back synthetic fixtures.' );
