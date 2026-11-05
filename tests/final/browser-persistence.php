<?php
declare(strict_types=1);

/** Inspect the actual database after browser actions; no synthetic writes or mock SQL. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'DB_NAME' ) || 'qa_browser' !== DB_NAME ) { throw new RuntimeException( 'Requires the disposable browser QA database.' ); }
$report = json_decode( file_get_contents( '/qa/browser-results.json' ), true, 32, JSON_THROW_ON_ERROR );
$run = $report['runId'] ?? '';
if ( ! is_string( $run ) || ! preg_match( '/^[a-z0-9]+$/D', $run ) ) { throw new RuntimeException( 'Invalid browser run identity.' ); }
global $wpdb;
$cases = array();
$check = static function ( string $name, bool $passed ) use ( &$cases ): void { $cases[] = array( 'name' => $name, 'status' => $passed ? 'passed' : 'failed' ); };
$messages = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_status,post_type,post_content FROM {$wpdb->posts} WHERE post_type='aish_message' AND post_content LIKE %s", '%' . $wpdb->esc_like( $run ) . '%' ), ARRAY_A );
$check( 'two-browser-submissions-persisted', 2 === count( $messages ) );
foreach ( $messages as $message ) {
	$id = (int) $message['ID'];
	$reviewed = false;
	foreach ( $report['cases'] as $case ) { if ( 'native-private-inbox-review' === $case['name'] && 'passed' === $case['status'] && $id === (int) ( $case['evidence']['id'] ?? 0 ) ) { $reviewed = true; } }
	$check( 'submission-private-' . $id, 'private' === $message['post_status'] );
	$check( 'submission-metadata-' . $id, ( $reviewed ? 'reviewed' : 'pending' ) === get_post_meta( $id, '_aish_message_state', true ) && 'en_US' === get_post_meta( $id, '_aish_message_locale', true ) && 'browser-qa@example.invalid' === get_post_meta( $id, '_aish_sender_email', true ) );
}
$skills = $wpdb->prefix . 'aish_skills'; $translations = $wpdb->prefix . 'aish_skill_translations';
$id = $wpdb->get_var( $wpdb->prepare( "SELECT skill_id FROM $translations WHERE locale='en_US' AND slug=%s", 'qa-ui-' . $run ) );
if ( $id ) {
	$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $skills WHERE id=%d", $id ), ARRAY_A );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT skill_id,locale,title,summary,slug FROM $translations WHERE skill_id=%d ORDER BY locale", $id ), ARRAY_A );
	$check( 'ui-skill-archive-preserves-stable-record', 'archived' === ( $record['status'] ?? '' ) && (int) $record['id'] === (int) $id );
	$check( 'ui-skill-bilingual-same-id', 2 === count( $rows ) && count( array_unique( array_column( $rows, 'skill_id' ) ) ) === 1 && array( 'en_US', 'zh_CN' ) === array_column( $rows, 'locale' ) );
	$check( 'ui-skill-human-edit-preserved', 'Changed human content needs another review.' === ( $rows[0]['summary'] ?? '' ) );
	$check( 'ui-skill-original-slug-preserved', count( array_unique( array_column( $rows, 'slug' ) ) ) === 1 && 'qa-ui-' . $run === ( $rows[0]['slug'] ?? '' ) );
} else { $cases[] = array( 'name' => 'ui-skill-persistence', 'status' => 'skipped', 'reason' => 'Browser create prerequisite did not persist a record.' ); }
foreach ( array( 'category' => 'categories', 'tag' => 'tags', 'repository' => 'repositories' ) as $kind => $entity ) {
	$id = null;
	foreach ( $report['cases'] as $case ) { if ( 'admin-' . $kind . '-create-edit-archive' === $case['name'] && 'passed' === $case['status'] ) { $id = (int) ( $case['evidence']['id'] ?? 0 ); } }
	if ( ! $id ) { $cases[] = array( 'name' => 'ui-' . $kind . '-archive-persistence', 'status' => 'skipped', 'reason' => 'Browser prerequisite did not supply a record.' ); continue; }
	$table = $wpdb->prefix . 'aish_' . $entity;
	$record = $wpdb->get_row( $wpdb->prepare( "SELECT id,name,is_archived FROM $table WHERE id=%d", $id ), ARRAY_A );
	$check( 'ui-' . $kind . '-archive-persistence', (int) ( $record['id'] ?? 0 ) === $id && 1 === (int) ( $record['is_archived'] ?? 0 ) && str_ends_with( (string) ( $record['name'] ?? '' ), 'edited' ) );
}
$fixture = get_option( 'aish_browser_qa_fixtures' );
$tutorial = get_post( (int) ( $fixture['tutorials']['en_US'] ?? 0 ) );
$related = array_map( 'intval', get_post_meta( $tutorial->ID ?? 0, '_aish_related_skill', false ) ); sort( $related );
$expected = array( $fixture['published'][0]['id'], $fixture['published'][1]['id'] ); sort( $expected );
$check( 'ui-native-tutorial-metadata-persistence', $tutorial instanceof WP_Post && 'QA Browser Tutorial UI verified' === $tutorial->post_title && 'en_US' === get_post_meta( $tutorial->ID, '_aish_locale', true ) && (int) $fixture['tutorials']['zh_CN'] === (int) get_post_meta( $tutorial->ID, '_aish_translation_id', true ) && $expected === $related );
$counts = array( 'passed' => 0, 'failed' => 0, 'skipped' => 0 ); foreach ( $cases as $case ) { ++$counts[ $case['status'] ]; }
echo wp_json_encode( array( 'runId' => $run, 'environment' => 'Actual WordPress/MySQL after real Chromium actions', 'counts' => $counts, 'cases' => $cases ), JSON_PRETTY_PRINT ), "\n";
if ( $counts['failed'] ) { WP_CLI::halt( 1 ); }
