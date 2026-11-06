<?php
declare(strict_types=1);

// Real Management/Publication/Events/Store code with a transactional wpdb model.
// These checks are isolated contracts, not real WordPress or MySQL integration.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DAY_IN_SECONDS', 86400 );
function get_option( string $name, mixed $default = false ): mixed { return 'aish_schema_version' === $name ? \AISkillsHub\Core\Database\Schema::VERSION : $default; }
function current_user_can( string $cap ): bool { return true; }
function get_current_user_id(): int { return 19; }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function wp_json_encode( mixed $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }

final class wpdb {
	public string $prefix = 'publication_'; public string $last_error = ''; public int $insert_id = 0;
	public array $tables = array(); public array $operations = array(); public bool $failEvent = false; public bool $eventSawPublishedInTransaction = false;
	private array $prepared = array(); private ?array $before = null;
	public function suppress_errors( bool $enabled ): bool { return false; }
	public function prepare( string $sql, array $values ): string { $key = 'prepared:' . count( $this->prepared ); $this->prepared[ $key ] = array( $sql, $values ); return $key; }
	private function unpack( string $query ): array { $this->last_error = ''; return $this->prepared[ $query ] ?? array( $query, array() ); }
	public function get_results( string $query, string $format ): array {
		[ $sql, $args ] = $this->unpack( $query );
		if ( 'SELECT * FROM `publication_aish_skills` WHERE id=%d FOR UPDATE' === $sql ) { $this->operations[] = 'skill_lock'; return isset( $this->tables['skills'][ $args[0] ] ) ? array( $this->tables['skills'][ $args[0] ] ) : array(); }
		if ( 'SELECT * FROM `publication_aish_skill_translations` WHERE skill_id=%d' === $sql ) { return array_values( array_filter( $this->tables['skill_translations'], fn( $r ) => $r['skill_id'] === $args[0] ) ); }
		if ( preg_match( '/^SELECT (category_id|tag_id) FROM `publication_aish_skill_(categories|tags)` WHERE skill_id=%d ORDER BY (?:category_id|tag_id)$/D', $sql, $match ) ) {
			$rows = array_values( array_filter( $this->tables[ 'skill_' . $match[2] ], fn( $r ) => $r['skill_id'] === $args[0] ) );
			usort( $rows, fn( $a, $b ) => $a[ $match[1] ] <=> $b[ $match[1] ] ); return $rows;
		}
		if ( str_starts_with( $sql, 'SELECT metadata_json FROM `publication_aish_workflow_events`' ) ) {
			foreach ( array_reverse( $this->tables['workflow_events'] ) as $row ) { if ( $row['subject_type'] === 'skill' && $row['subject_id'] === $args[0] && $row['action'] === 'public_content_approved' ) { return array( array( 'metadata_json' => $row['metadata_json'] ) ); } } return array();
		}
		if ( str_starts_with( $sql, 'SELECT id,type,subject_type,subject_id,template_key,locale,params_json FROM `publication_aish_user_notifications`' ) ) {
			foreach ( $this->tables['user_notifications'] as $row ) { if ( $row['user_id'] === $args[0] && $row['event_key'] === $args[1] ) { return array( $row ); } } return array();
		}
		throw new RuntimeException( 'Unexpected publication fixture read.' );
	}
	public function query( string $query ): int|false {
		[ $sql, $args ] = $this->unpack( $query );
		if ( 'START TRANSACTION' === $sql ) { $this->operations[] = 'begin'; $this->before = $this->tables; return 1; }
		if ( 'COMMIT' === $sql ) { $this->operations[] = 'commit'; $this->before = null; return 1; }
		if ( 'ROLLBACK' === $sql ) { $this->operations[] = 'rollback'; $this->tables = $this->before ?? $this->tables; $this->before = null; return 1; }
		if ( str_starts_with( $sql, 'INSERT INTO `publication_aish_user_notifications`' ) ) {
			foreach ( $this->tables['user_notifications'] as $row ) { if ( $row['user_id'] === $args[0] && $row['event_key'] === $args[1] ) { return 0; } }
			$id = count( $this->tables['user_notifications'] ) + 1;
			$this->tables['user_notifications'][ $id ] = array( 'id' => $id, 'user_id' => $args[0], 'event_key' => $args[1], 'type' => 'community', 'subject_type' => $args[2], 'subject_id' => $args[3], 'template_key' => $args[4], 'params_json' => '{}', 'locale' => $args[5], 'created_at' => $args[6], 'expires_at' => $args[7] ); return 1;
		}
		throw new RuntimeException( 'Unexpected publication fixture write.' );
	}
	public function insert( string $table, array $data, array $formats ): int|false {
		$entity = substr( $table, strlen( 'publication_aish_' ) );
		if ( 'workflow_events' !== $entity ) { throw new RuntimeException( 'Unexpected publication fixture insert.' ); }
		$this->operations[] = 'event_insert';
		$this->eventSawPublishedInTransaction = null !== $this->before && 'published' === $this->tables['skills'][ $data['subject_id'] ]['status'];
		if ( $this->failEvent ) { $this->failEvent = false; $this->last_error = 'simulated internal event failure'; return false; }
		$this->insert_id = count( $this->tables[ $entity ] ) + 1; $this->tables[ $entity ][ $this->insert_id ] = array( 'id' => $this->insert_id ) + $data; return 1;
	}
	public function update( string $table, array $data, array $where, array $formats, array $whereFormats ): int {
		$entity = substr( $table, strlen( 'publication_aish_' ) );
		if ( 'skills' !== $entity || ! isset( $this->tables[ $entity ][ $where['id'] ] ) ) { throw new RuntimeException( 'Unexpected publication fixture update.' ); }
		$this->operations[] = 'skill_update'; $this->tables[ $entity ][ $where['id'] ] = array_replace( $this->tables[ $entity ][ $where['id'] ], $data ); return 1;
	}
}

$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Security/Input.php', 'Security/Access.php', 'Language/LanguageProvider.php', 'Language/BuiltinLanguageProvider.php', 'Content/Catalog.php', 'Admin/Management.php', 'Community/Exception.php', 'Community/Input.php', 'Community/Events.php', 'Community/Publication.php', 'Accounts/Store.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Admin\Management;
use AISkillsHub\Core\Accounts\Store;
use AISkillsHub\Core\Community\Events;
use AISkillsHub\Core\Community\Publication;
use AISkillsHub\Core\Content\Catalog;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Language\BuiltinLanguageProvider;
$checks = 0;
function check( bool $condition, string $message ): void { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rejects( callable $call, string $message ): void { try { $call(); } catch ( Throwable $error ) { check( true, $message ); return; } throw new RuntimeException( $message ); }
function fixture(): array {
	$connection = new wpdb();
	$connection->tables = array(
		'skills' => array( 1 => array( 'id' => 1, 'status' => 'pending', 'review_status' => 'pending', 'revision' => 1, 'repository_id' => null, 'source_path' => null ) ),
		'skill_translations' => array(
			1 => array( 'id' => 1, 'skill_id' => 1, 'locale' => 'en_US', 'title' => 'Real resource', 'slug' => 'real-resource', 'summary' => 'Real summary', 'content' => 'Real content', 'seo_title' => '', 'seo_description' => '', 'updated_at' => '2026-01-01 00:00:00' ),
			2 => array( 'id' => 2, 'skill_id' => 1, 'locale' => 'zh_CN', 'title' => '未完成标题', 'slug' => '', 'summary' => '', 'content' => '' ),
		),
		'skill_categories' => array( array( 'skill_id' => 1, 'category_id' => 4 ), array( 'skill_id' => 1, 'category_id' => 2 ) ),
		'skill_tags' => array( array( 'skill_id' => 1, 'tag_id' => 8 ) ), 'workflow_events' => array(), 'user_notifications' => array(),
	);
	$db = new Database( $connection ); $migrator = new Migrator( $db ); $management = new Management( $db, $migrator, new Catalog( $db, $migrator, new BuiltinLanguageProvider() ) );
	$publication = new Publication( $db, new Events( $db ) ); $management->setApprovalRecorder( array( $publication, 'approved' ) );
	return array( $connection, $management, $publication, new Store( $db ) );
}
function pending( wpdb $connection ): int { $row = &$connection->tables['skills'][1]; $row['status'] = 'pending'; $row['review_status'] = 'pending'; return ++$row['revision']; }

[ $connection, $management, $publication, $store ] = fixture();
$observed = array();
$management->setApprovalRecorder( function ( int $id, int $revision, string $risk, array $skill, array $translations ) use ( &$observed, $publication ): void { $observed = array( $id, $revision, $risk, $skill, $translations ); $publication->approved( $id, $revision, $risk, $skill, $translations ); } );
$management->review( 1, 'approved', 'low', 'private editorial note', 1 );
check( count( $connection->tables['workflow_events'] ) === 1, 'real Management approval invokes Publication once' );
check( $observed[0] === 1 && $observed[1] === 2 && $observed[2] === 'low' && $observed[3]['status'] === 'pending' && count( $observed[4] ) === 2, 'callback receives approved revision, new risk, locked old head and real translations' );
check( $connection->operations === array( 'begin', 'skill_lock', 'skill_update', 'event_insert', 'commit' ), 'audit runs in existing transaction after locked update and before commit' );
check( $connection->eventSawPublishedInTransaction, 'event insertion observes updated publication inside transaction' );
$event = $connection->tables['workflow_events'][1]; $firstHash = json_decode( $event['metadata_json'], true )['content_hash'];
check( $event['subject_revision'] === 2 && $event['action'] === 'public_content_approved', 'event ties to exact newly approved Skill revision' );
check( null === $event['actor_user_id'] && null === $event['retain_until'] && array_keys( json_decode( $event['metadata_json'], true ) ) === array( 'content_hash' ), 'durable publication audit contains only de-identified content fingerprint' );
check( ! str_contains( json_encode( $event ), 'private editorial note' ) && ! str_contains( json_encode( $event ), 'Real content' ), 'workflow event never duplicates notes or content bodies' );
check( 1 === preg_match( '/^[a-f0-9]{64}$/D', $firstHash ), 'publication fingerprint is bounded SHA256' );

$management->review( 1, 'approved', 'low', 'new note only', pending( $connection ) );
check( count( $connection->tables['workflow_events'] ) === 1, 'unchanged reapproval does not claim a content update' );
$connection->tables['skill_translations'][2]['title'] = '另一个不完整标题';
$connection->tables['skill_translations'][1]['updated_at'] = '2026-02-01 00:00:00';
$management->review( 1, 'approved', 'low', '', pending( $connection ) );
check( count( $connection->tables['workflow_events'] ) === 1, 'incomplete translation and bookkeeping timestamps excluded from public content identity' );
$connection->tables['skill_translations'] = array_reverse( $connection->tables['skill_translations'], true );
foreach ( $connection->tables['skill_translations'] as &$row ) { $row = array_reverse( $row, true ); } unset( $row );
$connection->tables['skill_categories'] = array_reverse( $connection->tables['skill_categories'] );
$management->review( 1, 'approved', 'low', '', pending( $connection ) );
check( count( $connection->tables['workflow_events'] ) === 1, 'database row/key/relation order does not fabricate a content update' );
$connection->tables['skill_translations'][1]['content'] = 'Actually changed content';
$management->review( 1, 'approved', 'low', '', pending( $connection ) );
check( count( $connection->tables['workflow_events'] ) === 2 && json_decode( $connection->tables['workflow_events'][2]['metadata_json'], true )['content_hash'] !== $firstHash, 'actual published text change creates a new evidence event' );
$connection->tables['skill_categories'][] = array( 'skill_id' => 1, 'category_id' => 9 );
$management->review( 1, 'approved', 'low', '', pending( $connection ) );
check( count( $connection->tables['workflow_events'] ) === 3, 'actual approved taxonomy relationship change creates evidence event' );
$connection->tables['skill_translations'][2] = array( 'id' => 2, 'skill_id' => 1, 'locale' => 'zh_CN', 'title' => '真实中文标题', 'slug' => 'real-resource', 'summary' => '真实中文简介', 'content' => '真实中文内容', 'seo_title' => '', 'seo_description' => '' );
$management->review( 1, 'approved', 'low', '', pending( $connection ) );
check( count( $connection->tables['workflow_events'] ) === 4, 'new complete approved translation contributes to content fingerprint' );
$management->review( 1, 'approved', 'high', '', pending( $connection ) );
check( count( $connection->tables['workflow_events'] ) === 5, 'actual changed public risk label contributes to evidence' );
$count = count( $connection->tables['workflow_events'] );
$management->review( 1, 'rejected', 'high', 'Rejected pending content', pending( $connection ) );
check( count( $connection->tables['workflow_events'] ) === $count, 'rejection never records a public content approval' );
$connection->tables['skill_translations'][1]['content'] = ''; $connection->tables['skill_translations'][2]['content'] = '';
$revision = pending( $connection ); $before = $connection->tables;
rejects( fn() => $management->review( 1, 'approved', 'low', '', $revision ), 'all incomplete translations cannot be approved' );
check( $connection->tables === $before, 'no publicly complete translation leaves head/events unchanged' );

[ $connection, $management, $publication, $store ] = fixture();
$before = $connection->tables; $connection->failEvent = true;
rejects( fn() => $management->review( 1, 'approved', 'low', '', 1 ), 'event failure propagates through original management transaction' );
check( $connection->tables === $before && end( $connection->operations ) === 'rollback', 'modeled transaction restores head and event together on failed audit insert' );
$management->review( 1, 'approved', 'low', '', 1 );
check( count( $connection->tables['workflow_events'] ) === 1 && 'published' === $connection->tables['skills'][1]['status'], 'retry after acknowledged rollback approves and records once' );
$revision = pending( $connection ); $connection->tables['workflow_events'][1]['metadata_json'] = '{invalid'; $before = $connection->tables;
rejects( fn() => $management->review( 1, 'approved', 'low', '', $revision ), 'corrupt prior audit data does not silently fabricate successful publication' );
check( $connection->tables === $before, 'corrupt audit input leaves pending head unchanged' );

$key = str_repeat( 'a', 64 );
$notification = $store->notifyCommunity( 22, $key, 'review_approved', 'en_US', 'review', 7 );
check( $notification === $store->notifyCommunity( 22, $key, 'review_approved', 'en_US', 'review', 7 ) && count( $connection->tables['user_notifications'] ) === 1, 'identical community notification replay is idempotent' );
check( $store->notifyCommunity( 23, $key, 'review_approved', 'en_US', 'review', 7 ) !== $notification, 'same business event for a different recipient is independent' );
foreach ( array(
	array( 22, $key, 'review_hidden', 'en_US', 'review', 7 ),
	array( 22, $key, 'review_approved', 'zh_CN', 'review', 7 ),
	array( 22, $key, 'review_approved', 'en_US', 'review', 8 ),
) as $args ) { rejects( fn() => $store->notifyCommunity( ...$args ), 'same recipient event cannot change template/language/target silently' ); }
check( $connection->tables['user_notifications'][ $notification ]['template_key'] === 'review_approved' && $connection->tables['user_notifications'][ $notification ]['subject_id'] === 7, 'collision retains original frozen notification' );
check( '{}' === $connection->tables['user_notifications'][ $notification ]['params_json'], 'community notification has no free-form private content parameters' );
$connection->tables['user_notifications'][ $notification ]['type'] = 'account';
rejects( fn() => $store->notifyCommunity( 22, $key, 'review_approved', 'en_US', 'review', 7 ), 'account notification collision cannot be reinterpreted as a community event' );
$connection->tables['user_notifications'][ $notification ]['type'] = 'community';
foreach ( array(
	array( 22, str_repeat( 'b', 64 ), 'account_registered', 'en_US', 'review', 7 ),
	array( 22, str_repeat( 'b', 64 ), 'review_approved', 'en_US', 'skill', 7 ),
	array( 22, str_repeat( 'b', 64 ), 'report_resolved', 'en_US', 'review', 7 ),
	array( 22, str_repeat( 'b', 64 ), 'review_approved', 'fr_FR', 'review', 7 ),
	array( 0, str_repeat( 'b', 64 ), 'review_approved', 'en_US', 'review', 7 ),
	array( 22, 'short', 'review_approved', 'en_US', 'review', 7 ),
	array( 22, str_repeat( 'b', 64 ), 'review_approved', 'en_US', 'review', 0 ),
) as $args ) { rejects( fn() => $store->notifyCommunity( ...$args ), 'notification recipient/key/template/locale/subject are allowlisted' ); }
foreach ( array( 'review_report', 'skill_report' ) as $type ) { check( $store->notifyCommunity( 22, hash( 'sha256', $type ), 'report_resolved', 'zh_CN', $type, 7 ) > 0, 'both private report workflow outcomes use typed notifications' ); }
echo "Community publication and notifications: {$checks} isolated assertions passed (real services, modeled wpdb; no WordPress/MySQL/mail integration).\n";
