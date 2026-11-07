<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Trusted append-only audit helper; the calling domain service owns its transaction. */
final class Events {
	public function __construct( private Database $db ) {}
	public function record( string $eventKey, string $subjectType, int $subjectId, ?int $subjectRevision, string $action, ?int $actor, ?string $from, ?string $to, array $metadata ): int {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $eventKey ) || ! in_array( $subjectType, array( 'skill', 'review', 'review_report', 'skill_report', 'favorite', 'submission', 'update_suggestion', 'developer', 'claim', 'home_configuration', 'source_import', 'user', 'mail_queue', 'mail_message', 'sponsor_configuration', 'enterprise_configuration', 'enterprise_inquiry', 'bundle_configuration' ), true ) || ! preg_match( '/^[a-z_]{1,32}$/D', $action ) ) { throw new Exception( 'invalid_input' ); }
		Input::id( $subjectId ); if ( null !== $subjectRevision ) { Input::id( $subjectRevision ); } if ( null !== $actor ) { Input::id( $actor ); }
		foreach ( array( $from, $to ) as $state ) { if ( null !== $state && ! preg_match( '/^[a-z_]{1,20}$/D', $state ) ) { throw new Exception( 'invalid_input' ); } }
		if ( array_diff( array_keys( $metadata ), array( 'payload_hash', 'content_hash', 'skill_id', 'review_id', 'revision_id', 'report_id', 'submission_id', 'claim_id', 'reason', 'kind' ) ) ) { throw new Exception( 'invalid_input' ); }
		foreach ( $metadata as $key => $value ) {
			if ( str_ends_with( $key, '_hash' ) ) { if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) { throw new Exception( 'invalid_input' ); } }
			elseif ( str_ends_with( $key, '_id' ) ) { Input::id( $value ); }
			elseif ( ! is_string( $value ) || ! preg_match( '/^[a-z_]{1,64}$/D', $value ) ) { throw new Exception( 'invalid_input' ); }
		}
		$json = wp_json_encode( $metadata ); $now = gmdate( 'Y-m-d H:i:s' );
		$table = $this->db->table( 'workflow_events' );
		// INSERT is deliberate: callers look up idempotency before state changes. A
		// unique conflict is a transaction failure, never a license to swallow errors.
		$retain = 'skill' === $subjectType && 'public_content_approved' === $action && null === $actor && array_keys( $metadata ) === array( 'content_hash' ) ? null : gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS );
		return $this->db->insert( 'workflow_events', array( 'event_key' => $eventKey, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'subject_revision' => $subjectRevision, 'action' => $action, 'actor_user_id' => $actor, 'from_state' => $from, 'to_state' => $to, 'metadata_json' => $json, 'created_at' => $now, 'retain_until' => $retain ) );
	}
}
