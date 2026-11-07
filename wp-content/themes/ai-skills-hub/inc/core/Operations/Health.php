<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Admin\ContributionUi as Ui;
use AISkillsHub\Core\Community\Events;
use AISkillsHub\Core\Community\Input;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Sources\GitHubClient;
use AISkillsHub\Core\Sources\ImportStore;

defined( 'ABSPATH' ) || exit;

/** Evidence and operational acknowledgements; never edits Skill, translations, versions or author relationships. */
final class Health {
	public const CAP = 'aish_manage_repositories';
	public function __construct( private Database $db, private Migrator $migrator, private ImportStore $imports ) {}
	public static function authorize(): void { if ( ! current_user_can( Workbench::CAP ) || ! current_user_can( self::CAP ) ) { throw new \DomainException( 'forbidden' ); } }
	private function ready(): void { self::authorize(); if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'storage_unavailable' ); } }
	public static function reasons(): array { return array( 'checked_source' => Ui::t( '已人工核对来源', 'Source manually checked' ), 'awaiting_upstream' => Ui::t( '等待上游处理', 'Awaiting upstream action' ), 'linked_existing_case' => Ui::t( '已在原流程跟进', 'Followed up in the existing workflow' ), 'no_change_required' => Ui::t( '无需内容变更', 'No content change required' ), 'false_alarm' => Ui::t( '本次异常已核实', 'This alert has been investigated' ) ); }
	public static function label( string $status ): string {
		return match ( $status ) { 'complete' => Ui::t( '扫描完整完成', 'Complete scan' ), 'present' => Ui::t( '来源最近存在（不代表整仓完整扫描）', 'Source last observed present (not a complete-repository claim)' ), 'incomplete' => Ui::t( '扫描未完成', 'Scan incomplete' ), 'failed' => Ui::t( '请求或任务失败', 'Request or job failed' ), 'retry' => Ui::t( '等待重试／可能限流', 'Waiting to retry / possible rate limit' ), 'missing' => Ui::t( '来源疑似缺失，需人工核实', 'Source suspected missing; verification required' ), 'handled' => Ui::t( '采集变化已确认', 'Import change acknowledged' ), 'acknowledged' => Ui::t( '此快照已记录运营处理', 'Operational handling recorded for this snapshot' ), 'queued', 'running' => Ui::t( '任务等待或执行中', 'Job queued or running' ), 'cancelled' => Ui::t( '已取消', 'Cancelled' ), default => Ui::t( '需要人工核实', 'Manual verification needed' ) };
	}
	/** Mirrors ImportStore::sourceRevision using DB hashes, without loading untrusted JSON for an entire page. */
	public static function fingerprint( array $row ): string {
		$data = array(); foreach ( array( 'commit_sha', 'file_sha', 'source_status', 'change_status', 'last_seen_job', 'updated_at' ) as $key ) { $data[ $key ] = (string) ( $row[ $key ] ?? '' ); }
		foreach ( array( 'metadata', 'findings' ) as $key ) { $value = $row[ $key . '_hash' ] ?? ''; if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) { throw new \RuntimeException( 'storage_unavailable' ); } $data[ $key . '_hash' ] = $value; }
		return hash( 'sha256', json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
	public function listing( string $view, int $page = 1, string $state = '', int $repositoryId = 0 ): array {
		$this->ready(); Input::choice( $view, array( 'repositories', 'jobs', 'sources' ) ); Input::id( $page ); Input::id( $repositoryId, true ); if ( $page > 1000 ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		if ( 'jobs' === $view ) { return $this->imports->jobs( $page, $repositoryId, $state ); }
		if ( 'repositories' === $view ) {
			Input::choice( $state, array( '', 'enabled', 'paused', 'archived' ) ); $t = $this->db->table( 'repositories' );
			$where = match ( $state ) { 'enabled' => 'is_archived=0 AND sync_enabled=1', 'paused' => 'is_archived=0 AND sync_enabled=0', 'archived' => 'is_archived=1', default => '1=1' };
			$args = array(); if ( $repositoryId ) { $where .= ' AND id=%d'; $args[] = $repositoryId; }
			$total = (int) $this->db->value( "SELECT COUNT(*) FROM $t WHERE $where", $args );
			$rows = $this->db->rows( "SELECT id,owner,name,sync_status,sync_enabled,is_archived,last_scan_at,last_sync_at FROM $t WHERE $where ORDER BY id DESC LIMIT 20 OFFSET %d", array_merge( $args, array( ( $page - 1 ) * 20 ) ) );
		} else {
			Input::choice( $state, array( '', 'present', 'missing', 'needs_check', 'handled' ) ); $t = $this->db->table( 'imports' );
			$where = match ( $state ) { 'present' => "i.source_status='present'", 'missing' => "i.source_status='missing'", 'needs_check' => "i.change_status<>'handled'", 'handled' => "i.change_status='handled'", default => '1=1' };
			$args = array(); if ( $repositoryId ) { $where .= ' AND i.repository_id=%d'; $args[] = $repositoryId; }
			$total = (int) $this->db->value( "SELECT COUNT(*) FROM $t i WHERE $where", $args );
			$rows = $this->db->rows( "SELECT i.id,i.skill_id,i.repository_id,i.source_path,i.file_sha,i.commit_sha,i.source_status,i.change_status,i.last_seen_job,i.updated_at,SHA2(COALESCE(i.metadata_json,'[]'),256) AS metadata_hash,SHA2(COALESCE(i.findings_json,'[]'),256) AS findings_hash FROM $t i WHERE $where ORDER BY i.id DESC LIMIT 20 OFFSET %d", array_merge( $args, array( ( $page - 1 ) * 20 ) ) );
			$ids = array_map( 'intval', array_column( $rows, 'id' ) ); $events = array();
			if ( $ids ) {
				$events = $this->db->rows( 'SELECT e.subject_id,e.metadata_json,e.actor_user_id,e.created_at FROM ' . $this->db->table( 'workflow_events' ) . ' e INNER JOIN (SELECT subject_id,MAX(id) AS event_id FROM ' . $this->db->table( 'workflow_events' ) . " WHERE subject_type='source_import' AND action='source_health_handled' AND subject_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY subject_id) last_event ON last_event.event_id=e.id', $ids );
				$events = array_column( $events, null, 'subject_id' );
			}
			foreach ( $rows as &$row ) {
				$row['fingerprint'] = self::fingerprint( $row ); $row['acknowledgement'] = null; $event = $events[ $row['id'] ] ?? null;
				if ( $event ) { $meta = json_decode( (string) $event['metadata_json'], true ); if ( is_array( $meta ) && isset( $meta['content_hash'] ) && is_string( $meta['content_hash'] ) && hash_equals( $row['fingerprint'], $meta['content_hash'] ) && isset( self::reasons()[ $meta['reason'] ?? '' ] ) ) { $row['acknowledgement'] = array( 'reason' => $meta['reason'], 'actor_id' => (int) $event['actor_user_id'], 'created_at' => $event['created_at'] ); } }
			} unset( $row );
		}
		return array( 'items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => 20 );
	}
	public function rate(): array { $this->ready(); return GitHubClient::rateStatus(); }
	/** At most ten repository IDs; only existing fixed-host background import jobs are queued. */
	public function retry( array $ids ): array {
		$this->ready(); if ( ! $ids || count( $ids ) > Workbench::LIMIT ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$normalized = array_map( static fn( $id ): int => Input::id( $id ), $ids ); if ( count( array_unique( $normalized ) ) !== count( $normalized ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$results = array(); foreach ( $normalized as $id ) {
			try { self::authorize(); $repo = $this->imports->repository( $id ); if ( empty( $repo['sync_enabled'] ) || ! empty( $repo['is_archived'] ) ) { throw new \DomainException( 'paused' ); } $job = $this->imports->enqueue( $id, 'sync', false, true ); $results[] = array( 'id' => $id, 'success' => true, 'code' => 'completed', 'job_id' => $job ); }
			catch ( \Throwable $error ) { $code = match ( $error->getMessage() ) { 'Repository synchronization is not available.' => 'paused', 'Wait before starting another repository scan.', 'Import scheduling is busy.' => 'busy', 'Repository not found.' => 'unavailable', default => Workbench::errorCode( $error ) }; $results[] = array( 'id' => $id, 'success' => false, 'code' => $code ); }
		} return $results;
	}
	/** CAS against the imported snapshot; append-only operational fact, not a content approval. */
	public function acknowledge( int $id, string $expectedFingerprint, string $reason, string $requestKey ): void {
		$this->ready(); Input::id( $id ); Input::choice( $reason, array_keys( self::reasons() ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expectedFingerprint ) || ! preg_match( '/^[a-f0-9]{32}$/D', $requestKey ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$actor = (int) get_current_user_id(); Input::id( $actor ); $key = hash( 'sha256', 'source_health:' . $actor . ':' . $requestKey ); $payload = hash( 'sha256', $id . ':' . $expectedFingerprint . ':' . $reason );
		$this->db->transaction( function () use ( $id, $expectedFingerprint, $reason, $actor, $key, $payload ): void {
			self::authorize(); $row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'imports' ) . ' WHERE id=%d FOR UPDATE', array( $id ) ); if ( ! $row ) { throw new \DomainException( 'unavailable' ); }
			$old = $this->db->row( 'SELECT subject_type,subject_id,metadata_json FROM ' . $this->db->table( 'workflow_events' ) . ' WHERE event_key=%s', array( $key ) );
			if ( $old ) { $data = json_decode( (string) $old['metadata_json'], true ); if ( 'source_import' !== $old['subject_type'] || (int) $old['subject_id'] !== $id || ! is_array( $data ) || ( $data['payload_hash'] ?? '' ) !== $payload ) { throw new \DomainException( 'conflict' ); } return; }
			if ( ! hash_equals( ImportStore::sourceRevision( $row ), $expectedFingerprint ) ) { throw new \DomainException( 'source_changed' ); }
			( new Events( $this->db ) )->record( $key, 'source_import', $id, null, 'source_health_handled', $actor, null, 'handled', array( 'payload_hash' => $payload, 'content_hash' => $expectedFingerprint, 'reason' => $reason, 'kind' => 'source_health' ) );
		} );
	}
}
