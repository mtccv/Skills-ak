<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Community;

use AISkillsHub\Core\Admin\Management;
use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Records only observed human approvals; no migration invents historical publication dates. */
final class Publication {
	public function __construct( private Database $db, private Events $events ) {}

	/** Called after the locked Skill update, inside Management's single transaction. */
	public function approved( int $skillId, int $revision, string $risk, array $skill, array $translations ): void {
		$content = array();
		foreach ( $translations as $row ) {
			if ( ! Management::translationComplete( $row ) ) { continue; }
			$content[ $row['locale'] ] = array_intersect_key( $row, array_flip( array( 'title', 'slug', 'summary', 'content', 'seo_title', 'seo_description' ) ) );
			ksort( $content[ $row['locale'] ] );
		}
		ksort( $content );
		$content['source'] = array( 'repository_id' => $skill['repository_id'], 'source_path' => $skill['source_path'] );
		$content['risk'] = $risk;
		foreach ( array( 'categories' => 'category_id', 'tags' => 'tag_id' ) as $entity => $column ) {
			$content[ $entity ] = array_map( 'intval', array_column( $this->db->rows( 'SELECT ' . $column . ' FROM ' . $this->db->table( 'skill_' . $entity ) . " WHERE skill_id=%d ORDER BY $column", array( $skillId ) ), $column ) );
		}
		$hash = hash( 'sha256', json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
		$prior = $this->db->row( 'SELECT metadata_json FROM ' . $this->db->table( 'workflow_events' ) . " WHERE subject_type='skill' AND subject_id=%d AND action='public_content_approved' ORDER BY id DESC LIMIT 1", array( $skillId ) );
		if ( $prior ) {
			$metadata = json_decode( $prior['metadata_json'], true, 8, JSON_THROW_ON_ERROR );
			if ( is_array( $metadata ) && isset( $metadata['content_hash'] ) && hash_equals( $hash, (string) $metadata['content_hash'] ) ) { return; }
		}
		// Existing Skill reviewer fields retain the private human approval evidence.
		// This durable public-content fingerprint does not duplicate a personal identity.
		$this->events->record( hash( 'sha256', 'content-approval:' . $skillId . ':' . $revision . ':' . $hash ), 'skill', $skillId, $revision, 'public_content_approved', null, 'pending', 'published', array( 'content_hash' => $hash ) );
	}
}
