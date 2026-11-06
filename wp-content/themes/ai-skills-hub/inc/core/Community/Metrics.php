<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Phase 9 fact-only counters. No browsing, search terms, rankings or analytics UI. */
final class Metrics {
	public function __construct( private Database $db ) {}
	/** Called only inside the successful interaction transaction, never with client counts. */
	public function fact( string $name, int $skillId, int $identity ): void {
		Input::choice( $name, array( 'favorite_add', 'favorite_remove', 'first_review_approved' ) ); Input::id( $skillId ); Input::id( $identity );
		$day = gmdate( 'Y-m-d' ); $now = gmdate( 'Y-m-d H:i:s' );
		$key = hash_hmac( 'sha256', 'community:1:' . $day . ':' . $name . ':' . $skillId . ':' . $identity, wp_salt( 'auth' ) );
		$table = $this->db->table( 'metric_events' );
		if ( $this->db->row( "SELECT id FROM $table WHERE event_day=%s AND metric_name=%s AND dedupe_key=%s", array( $day, $name, $key ) ) ) { return; }
		$this->db->insert( 'metric_events', array( 'metric_name' => $name, 'dimension_type' => 'skill', 'dimension_key' => (string) $skillId, 'locale' => '', 'event_day' => $day, 'dedupe_key' => $key, 'policy_version' => 'community-1', 'occurred_at' => $now, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) );
		$daily = $this->db->table( 'daily_metrics' );
		$this->db->execute( "INSERT INTO $daily (stat_day,metric_name,dimension_type,dimension_key,locale,metric_count,policy_version,updated_at) VALUES (%s,%s,'skill',%s,'',1,'community-1',%s) ON DUPLICATE KEY UPDATE metric_count=metric_count+1,updated_at=VALUES(updated_at)", array( $day, $name, (string) $skillId, $now ) );
	}
	/** Bounded maintenance, never reconstructing unknown historical observations. */
	public function cleanup(): void {
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'metric_events' ) . ' WHERE expires_at<%s ORDER BY id LIMIT 100', array( gmdate( 'Y-m-d H:i:s' ) ) );
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'daily_metrics' ) . ' WHERE stat_day<%s LIMIT 100', array( gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) );
	}
}
