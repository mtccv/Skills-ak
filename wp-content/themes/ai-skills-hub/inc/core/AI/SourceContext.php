<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Security\Access;
use AISkillsHub\Core\Security\Input;
use AISkillsHub\Core\Sources\ImportStore;

defined( 'ABSPATH' ) || exit;

/** Read-only snapshot of an imported Skill. No URLs are fetched and no source instructions run. */
final class SourceContext {
	public function __construct( private Database $db, private Migrator $migrator ) {}

	public function snapshot( int $skillId ): array {
		Access::write( 'aish_generate_content' ); Input::id( $skillId );
		if ( ! $this->migrator->isCurrent() ) { throw new \DomainException( 'ai_schema_not_current' ); }
		$imports = $this->db->table( 'imports' ); $skills = $this->db->table( 'skills' );
		$repos = $this->db->table( 'repositories' ); $versions = $this->db->table( 'skill_versions' );
		$row = $this->db->row( "SELECT i.*,s.revision AS skill_revision,s.status AS skill_status,r.owner,r.name,r.is_archived AS repository_archived,v.source_content FROM $imports i INNER JOIN $skills s ON s.id=i.skill_id AND s.repository_id=i.repository_id AND s.source_path=i.source_path INNER JOIN $repos r ON r.id=i.repository_id INNER JOIN $versions v ON v.skill_id=i.skill_id AND v.commit_sha=i.commit_sha AND v.file_sha=i.file_sha AND v.repository_id=i.repository_id AND v.source_path=i.source_path WHERE s.id=%d", array( $skillId ) );
		if ( ! $row || 'present' !== $row['source_status'] || (int) $row['repository_archived'] || in_array( $row['skill_status'], array( 'archived', 'rejected' ), true ) ) { throw new \DomainException( 'ai_source_unavailable' ); }
		$raw = Input::utf8( $row['source_content'], 524288 );
		if ( ! hash_equals( (string) $row['file_sha'], hash( 40 === strlen( $row['file_sha'] ) ? 'sha1' : 'sha256', 'blob ' . strlen( $raw ) . "\0" . $raw ) ) ) { throw new \DomainException( 'ai_source_hash_mismatch' ); }
		$metadata = self::decode( $row['metadata_json'], 65536 ); $findings = self::decode( $row['findings_json'], 131072 );
		$translations = $this->db->table( 'skill_translations' );
		$source = array(
			'skill_id' => $skillId, 'skill_revision' => (int) $row['skill_revision'],
			'translations' => GenerationStore::translationSnapshot( $this->db->rows( "SELECT * FROM $translations WHERE skill_id=%d", array( $skillId ) ) ),
			'import_id' => (int) $row['id'], 'source_fingerprint' => ImportStore::sourceRevision( $row ),
			'repository_id' => (int) $row['repository_id'], 'repository' => array( 'owner' => $row['owner'], 'name' => $row['name'] ),
			'source_path' => $row['source_path'], 'commit_sha' => $row['commit_sha'], 'file_sha' => $row['file_sha'],
			'raw' => $raw, 'metadata' => $metadata, 'static_findings' => $findings, 'license' => $findings['license'] ?? array(),
			'categories' => $this->terms( 'categories' ), 'tags' => $this->terms( 'tags' ), 'platforms' => array(), 'tree' => array(), 'tree_scope' => 'unavailable',
		);
		$tags = $this->db->table( 'tags' ); $links = $this->db->table( 'skill_tags' );
		$source['platforms'] = $this->db->rows( "SELECT t.name,t.slug FROM $tags t INNER JOIN $links l ON l.tag_id=t.id WHERE l.skill_id=%d AND t.is_archived=0 AND t.slug LIKE 'platform-%%' ORDER BY t.id LIMIT 100", array( $skillId ) );
		// Only a recorded, matching inspection tree can describe repository structure.
		if ( ! empty( $row['last_seen_job'] ) && ! empty( $findings['inspection_commit_sha'] ) ) {
			$jobs = $this->db->table( 'jobs' );
			$job = $this->db->row( "SELECT state_json FROM $jobs WHERE id=%d AND repository_id=%d", array( (int) $row['last_seen_job'], (int) $row['repository_id'] ) );
			if ( $job ) {
				$state = self::decode( $job['state_json'], 4194304 );
				if ( ! empty( $state['inventory_complete'] ) && ( $state['commit_sha'] ?? '' ) === $findings['inspection_commit_sha'] ) {
					$directory = dirname( $row['source_path'] ); $entries = array();
					foreach ( $state['discovery']['entries'] ?? array() as $entry ) {
						if ( ! is_array( $entry ) || ! isset( $entry['path'], $entry['type'] ) ) { continue; }
						if ( '.' === $directory || str_starts_with( $entry['path'], $directory . '/' ) ) { $entries[] = array_intersect_key( $entry, array_flip( array( 'path', 'type', 'mode', 'size' ) ) ); }
					}
					$source['tree_scope'] = count( $entries ) <= 200 ? 'skill_directory_metadata' : 'unavailable_due_to_limit';
					$source['tree'] = count( $entries ) <= 200 ? $entries : array(); $source['tree_commit_sha'] = $state['commit_sha'];
				}
			}
		}
		return $source;
	}

	private function terms( string $entity ): array {
		$table = $this->db->table( $entity );
		// Bounded candidate catalog; missing terms remain suggestions, never automatically created.
		return $this->db->rows( "SELECT id,name,slug FROM $table WHERE is_archived=0 ORDER BY id LIMIT 200" );
	}
	private static function decode( mixed $json, int $limit ): array {
		if ( ! is_string( $json ) || strlen( $json ) > $limit ) { throw new \DomainException( 'ai_invalid_source_snapshot' ); }
		$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) ) { throw new \DomainException( 'ai_invalid_source_snapshot' ); }
		return $value;
	}
}
