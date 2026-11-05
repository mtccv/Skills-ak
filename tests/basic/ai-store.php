<?php
/** In-memory persistence contracts only; no MySQL, WordPress or AI service is loaded. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DOING_CRON', true );
function get_option( string $key, mixed $default = false ): mixed { return 'aish_schema_version' === $key ? AISkillsHub\Core\Database\Schema::VERSION : $default; }
function current_user_can( string $cap ): bool { return $GLOBALS['caps'][ $cap ] ?? true; }
function get_current_user_id(): int { return 7; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_kses_post( string $value ): string { return preg_replace( '/<script.*?<\/script>/s', '', $value ); }
final class wpdb {
	public string $prefix = 't_'; public string $last_error = ''; public int $insert_id = 0; public array $tables = array(); public array $prepared = array(); public array $queries = array(); private ?array $snapshot = null;
	public function suppress_errors( bool $enabled ): bool { return false; }
	public function prepare( string $sql, array $values ): string { $this->prepared[] = array( $sql, $values ); return 'prepared:' . ( count( $this->prepared ) - 1 ); }
	private function unpack( string $sql ): array { return str_starts_with( $sql, 'prepared:' ) ? $this->prepared[ (int) substr( $sql, 9 ) ] : array( $sql, array() ); }
	private function entity( string $name ): string { return str_replace( array( '`', 't_aish_' ), '', $name ); }
	public function insert( string $table, array $data, array $formats ): int { $table = $this->entity( $table ); $id = count( $this->tables[ $table ] ?? array() ) + 1; $this->tables[ $table ][ $id ] = array( 'id' => $id ) + $data; $this->insert_id = $id; return 1; }
	public function update( string $table, array $data, array $where, array $formats, array $whereFormats ): int { $table = $this->entity( $table ); if ( ! isset( $this->tables[ $table ][ $where['id'] ] ) ) { return 0; } $this->tables[ $table ][ $where['id'] ] = array_merge( $this->tables[ $table ][ $where['id'] ], $data ); return 1; }
	public function get_results( string $prepared, string $format ): array {
		list( $sql, $args ) = $this->unpack( $prepared ); $this->queries[] = $sql;
		preg_match( '/FROM `t_aish_([a-z_]+)`/', $sql, $match ); $rows = array_values( $this->tables[ $match[1] ?? '' ] ?? array() );
		if ( 'ai_reviews' === ( $match[1] ?? '' ) && str_contains( $sql, 'WHERE job_id=%d' ) ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => (int) $r['job_id'] === $args[0] && ( ! str_contains( $sql, 'AND id=%d' ) || (int) $r['id'] === $args[1] ) ) );
			if ( str_contains( $sql, 'ORDER BY revision DESC' ) ) { usort( $rows, static fn( $a, $b ) => $b['revision'] <=> $a['revision'] ); }
			if ( str_contains( $sql, 'LIMIT %d OFFSET %d' ) ) { $rows = array_slice( $rows, $args[2], $args[1] ); }
			if ( str_contains( $sql, 'snapshot_json' ) || str_starts_with( $sql, 'SELECT *' ) ) {
				if ( ! str_contains( $sql, 'AND id=%d' ) ) { throw new RuntimeException( 'History may not bulk-read snapshot bodies.' ); }
			} else { $rows = array_map( static fn( $r ) => array_intersect_key( $r, array_flip( array( 'id', 'job_id', 'revision', 'action', 'actor_id', 'created_at' ) ) ), $rows ); }
			return $rows;
		}
		if ( str_contains( $sql, "status IN ('queued','retry')" ) ) { return array_slice( array_values( array_filter( $rows, fn( $r ) => ( in_array( $r['status'], array( 'queued', 'retry' ), true ) && $r['available_at'] <= $args[0] ) || ( 'running' === $r['status'] && $r['locked_until'] <= $args[1] ) ) ), 0, 1 ); }
		if ( str_contains( $sql, 'WHERE id=%d' ) ) { return array_values( array_filter( $rows, fn( $r ) => $r['id'] === $args[0] ) ); }
		if ( str_contains( $sql, 'WHERE request_key=%s' ) ) { return array_values( array_filter( $rows, fn( $r ) => ( $r['request_key'] ?? null ) === $args[0] ) ); }
		if ( str_contains( $sql, 'WHERE active_key=%s' ) ) { return array_values( array_filter( $rows, fn( $r ) => ( $r['active_key'] ?? null ) === $args[0] ) ); }
		if ( str_contains( $sql, 'WHERE skill_id=%d' ) ) { return array_values( array_filter( $rows, fn( $r ) => (int) $r['skill_id'] === $args[0] ) ); }
		if ( str_contains( $sql, 'WHERE job_id=%d' ) ) { return array_values( array_filter( $rows, fn( $r ) => (int) $r['job_id'] === $args[0] && ( ! str_contains( $sql, "status='inflight'" ) || 'inflight' === $r['status'] ) ) ); }
		return $rows;
	}
	public function get_var( string $prepared ): mixed { list( $sql, $args ) = $this->unpack( $prepared ); if ( str_contains( $sql, 'GET_LOCK(' ) || str_contains( $sql, 'RELEASE_LOCK(' ) ) { return 1; } return count( $this->get_results( $prepared, ARRAY_A ) ); }
	public function query( string $prepared ): int { list( $sql, $args ) = $this->unpack( $prepared ); if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $this->tables; } if ( 'ROLLBACK' === $sql ) { $this->tables = $this->snapshot; } if ( in_array( $sql, array( 'COMMIT', 'ROLLBACK' ), true ) ) { $this->snapshot = null; } return 1; }
}
$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Security/Input.php', 'Security/Access.php', 'Sources/ImportStore.php', 'AI/ProviderException.php', 'AI/Settings.php', 'AI/GenerationStore.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\AI\GenerationStore;
use AISkillsHub\Core\Sources\ImportStore;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Database\Schema;
$count = 0;
function check( bool $pass, string $message ): void { global $count; if ( ! $pass ) { throw new RuntimeException( $message ); } ++$count; }
function rejects( callable $call, string $message ): void { try { $call(); } catch ( InvalidArgumentException | DomainException $error ) { check( true, $message ); return; } throw new RuntimeException( $message ); }
function fixture(): array {
	$wpdb = new wpdb(); $db = new Database( $wpdb ); $store = new GenerationStore( $db, new Migrator( $db ) );
	$wpdb->tables['skills'][1] = array( 'id' => 1, 'repository_id' => 1, 'source_path' => 'sample/SKILL.md', 'revision' => 3, 'status' => 'pending', 'review_status' => 'pending', 'risk_level' => 'unknown' );
	$wpdb->tables['repositories'][1] = array( 'id' => 1, 'owner' => 'owner', 'name' => 'repo', 'is_archived' => 0 );
	$wpdb->tables['imports'][1] = array( 'id' => 1, 'skill_id' => 1, 'repository_id' => 1, 'source_path' => 'sample/SKILL.md', 'commit_sha' => str_repeat( 'a', 40 ), 'file_sha' => str_repeat( 'b', 40 ), 'source_status' => 'present', 'change_status' => 'new', 'last_seen_job' => 1, 'updated_at' => '2026-01-01 00:00:00', 'metadata_json' => '{}', 'findings_json' => '{}' );
	$source = array( 'skill_id' => 1, 'skill_revision' => 3, 'repository_id' => 1, 'source_path' => 'sample/SKILL.md', 'import_id' => 1, 'source_fingerprint' => ImportStore::sourceRevision( $wpdb->tables['imports'][1] ), 'commit_sha' => str_repeat( 'a', 40 ), 'file_sha' => str_repeat( 'b', 40 ), 'raw' => 'Untrusted original source', 'translations' => GenerationStore::translationSnapshot( array() ) );
	$profile = array( 'id' => 'test', 'provider' => 'openai', 'model' => 'gpt-test', 'enabled' => true, 'base_url' => 'https://api.openai.com/v1', 'temperature' => 0.2, 'timeout' => 30, 'max_output_tokens' => 1024, 'token_parameter' => 'max_tokens', 'input_price' => null, 'output_price' => null, 'max_cost' => 0 );
	return array( $wpdb, $db, $store, $source, $profile );
}
list( $wpdb, $db, $store, $source, $profile ) = fixture();
check( 14 === count( Schema::COLUMNS ), 'Eight business, three import and three AI tables remain distinct.' );
foreach ( array( '1.0.0' => 8, '1.1.0' => 8, '1.2.0' => 8, '1.3.0' => 8, '1.4.0' => 11, '1.5.0' => 14 ) as $version => $tables ) { check( count( Schema::definitions( $db, $version ) ) === $tables, 'Historical schemas retain their original table set.' ); }
foreach ( Schema::definitions( $db, '1.4.0' ) as $table => $definition ) { check( Schema::definitions( $db, '1.5.0' )[ $table ] === $definition, 'Phase 6 preserves each prior table definition.' ); }
$id = $store->enqueue( $source, $profile, 'both', str_repeat( '1', 64 ), 'v1' );
check( 1 === $id && 1 === count( $wpdb->tables['ai_jobs'] ), 'Generation is queued once.' );
check( $id === $store->enqueue( $source, $profile, 'both', str_repeat( '1', 64 ), 'v1' ), 'Repeated form requests preserve idempotency.' );
check( $id === $store->enqueue( $source, $profile, 'summary', str_repeat( '2', 64 ), 'v1' ), 'One active generation per Skill prevents overlapping modes.' );
rejects( fn() => $store->enqueue( $source, $profile, 'summary', str_repeat( '1', 64 ), 'v1' ), 'Reusing a request key for a different mode is rejected.' );
check( '0.00000000' === $wpdb->tables['ai_jobs'][1]['cost_estimate'], 'A fresh job has no spent budget.' );
$job = $store->claim(); $token = $job['lease_token'];
check( 'running' === $job['status'] && $job['source']['skill_id'] === 1, 'Claim freezes source and profile with a lease.' );
$store->assertCurrent( $id, $token ); check( true, 'Unchanged source and editorial baseline pass.' );
$wpdb->tables['skills'][1]['revision'] = 4;
rejects( fn() => $store->beginAttempt( $id, $token ), 'An edit after preflight is rechecked before registering a paid request.' );
check( empty( $wpdb->tables['ai_attempts'] ) && 0 === $wpdb->tables['ai_jobs'][1]['attempts'], 'Late preflight conflicts do not register or charge an API attempt.' );
$wpdb->tables['skills'][1]['revision'] = 3;
rejects( fn() => $store->beginAttempt( $id, str_repeat( 'f', 64 ) ), 'Wrong worker lease cannot start paid requests.' );
$attempt = $store->beginAttempt( $id, $token );
check( 1 === $attempt['id'] && 'inflight' === $wpdb->tables['ai_attempts'][1]['status'], 'An attempt is durably registered before HTTP.' );
rejects( fn() => $store->beginAttempt( $id, $token ), 'Duplicate inflight requests cannot be started.' );
rejects( fn() => $store->control( $id, 'cancel' ), 'Inflight requests cannot be silently discarded.' );
$output = array( 'original' => 'AI result' ); $draft = array( 'zh_CN' => array( 'title' => '中文标题', 'summary' => '中文摘要', 'content' => '<p>草稿内容</p>' ), 'en_US' => array( 'title' => 'English title', 'summary' => 'Summary', 'content' => '<p>Draft</p>' ) );
$store->completeAttempt( $id, $token, 1, $output, $draft, array( 'input_tokens' => 100, 'output_tokens' => 200, 'elapsed_ms' => 42, 'cost_estimate' => 0.002 ) );
check( 'needs_review' === $wpdb->tables['ai_jobs'][1]['status'] && null === $wpdb->tables['ai_jobs'][1]['active_key'], 'Completed generation only becomes a reviewable draft.' );
check( ! isset( $wpdb->tables['skill_translations'] ) && 3 === $wpdb->tables['skills'][1]['revision'], 'Generation never writes editorial content.' );
check( 100 === $wpdb->tables['ai_jobs'][1]['input_tokens'] && '0.00200000' === $wpdb->tables['ai_jobs'][1]['cost_estimate'], 'Usage and estimated cost are stored.' );
$details = $store->job( 1 );
check( $details['output'] === $output && $details['edited'] === $draft && ! isset( $details['lease_token'] ), 'Private detail separates original output from human editing and hides lease token.' );
$edited = $draft; $edited['zh_CN']['summary'] = '人工校订摘要'; $edited['zh_CN']['content'] .= '<script>bad()</script>';
$store->saveReview( 1, 1, $edited );
check( 2 === $wpdb->tables['ai_jobs'][1]['revision'] && 'edited' === $wpdb->tables['ai_jobs'][1]['review_status'], 'Human review edits create an optimistic revision.' );
check( ! str_contains( $wpdb->tables['ai_jobs'][1]['edited_json'], '<script' ) && $wpdb->tables['ai_jobs'][1]['output_json'] === json_encode( $output ), 'Manual draft is sanitized and original AI result remains immutable.' );
rejects( fn() => $store->accept( 1, 1, array( 'zh_CN.title' ) ), 'Stale review revisions cannot apply.' );
$store->accept( 1, 2, array( 'zh_CN.title', 'zh_CN.summary', 'zh_CN.content', 'en_US.title' ) );
check( 'pending' === $wpdb->tables['skills'][1]['status'] && 4 === $wpdb->tables['skills'][1]['revision'], 'Explicit accepted empty fields require normal Skill review.' );
check( null === $wpdb->tables['skill_translations'][1]['slug'] && '人工校订摘要' === $wpdb->tables['skill_translations'][1]['summary'], 'Accept creates no invented slug and uses reviewed values.' );
check( count( $wpdb->tables['ai_reviews'] ) === 2 && 'accepted' === $wpdb->tables['ai_jobs'][1]['review_status'], 'Review edits and adoption leave provenance history.' );
rejects( fn() => $store->accept( 1, 3, array( 'en_US.summary' ) ), 'Accepted draft cannot be applied twice.' );
check( 1 === $store->listJobs()['total'], 'Job history is paginated through the private store.' );

// Existing human text and concurrent edits are never replaced, including an approved Skill.
list( $wpdb, $db, $store, $source, $profile ) = fixture();
$wpdb->tables['skill_translations'][1] = array( 'id' => 1, 'skill_id' => 1, 'locale' => 'zh_CN', 'title' => '人工标题', 'summary' => '', 'content' => '', 'slug' => 'stable-slug', 'seo_title' => 'SEO' );
$source['translations'] = GenerationStore::translationSnapshot( $wpdb->tables['skill_translations'] );
$wpdb->tables['skills'][1]['status'] = 'published'; $wpdb->tables['skills'][1]['review_status'] = 'approved';
$id = $store->enqueue( $source, $profile, 'both', str_repeat( '3', 64 ), 'v1' ); $job = $store->claim(); $a = $store->beginAttempt( $id, $job['lease_token'] );
$store->completeAttempt( $id, $job['lease_token'], $a['id'], $output, $draft, array() );
rejects( fn() => $store->accept( $id, 1, array( 'zh_CN.title' ) ), 'Even explicit adoption cannot overwrite a nonempty human field.' );
check( '人工标题' === $wpdb->tables['skill_translations'][1]['title'] && 'published' === $wpdb->tables['skills'][1]['status'], 'Failed adoption preserves published manual content.' );
$wpdb->tables['skill_translations'][1]['summary'] = 'Concurrent human edit';
rejects( fn() => $store->accept( $id, 1, array( 'en_US.title' ) ), 'Any changed translation baseline blocks stale adoption.' );
$wpdb->tables['skill_translations'][1]['summary'] = '';
$wpdb->tables['imports'][1]['findings_json'] = '{"changed":true}';
rejects( fn() => $store->accept( $id, 1, array( 'en_US.title' ) ), 'Changed risk/source evidence blocks stale adoption.' );
$store->reject( $id, 1 );
check( 'rejected' === $wpdb->tables['ai_jobs'][1]['review_status'] && 'published' === $wpdb->tables['skills'][1]['status'], 'Rejecting an AI suggestion never rejects the existing Skill.' );

// Definite retryable failures and uncertain delivery have different billing safety behavior.
list( $wpdb, $db, $store, $source, $profile ) = fixture();
$id = $store->enqueue( $source, $profile, 'summary', str_repeat( '4', 64 ), 'v1' ); $job = $store->claim(); $a = $store->beginAttempt( $id, $job['lease_token'] );
$store->failAttempt( $id, $job['lease_token'], $a['id'], 'rate_limited', true, 60 );
check( 'retry' === $wpdb->tables['ai_jobs'][1]['status'] && null === $wpdb->tables['ai_jobs'][1]['cost_estimate'], 'Unknown failed-call usage stays unknown, not free.' );
$store->control( $id, 'pause' ); check( 'paused' === $wpdb->tables['ai_jobs'][1]['status'] && null === $store->claim(), 'Paused jobs cannot be claimed.' );
$store->control( $id, 'resume' ); $wpdb->tables['ai_jobs'][1]['available_at'] = '2000-01-01 00:00:00';
$job = $store->claim(); $a = $store->beginAttempt( $id, $job['lease_token'] );
$store->failAttempt( $id, $job['lease_token'], $a['id'], 'transport_timeout', true, 60, true, array( 'elapsed_ms' => 30000 ) );
check( 'failed' === $wpdb->tables['ai_jobs'][1]['status'] && 'uncertain' === $wpdb->tables['ai_attempts'][2]['status'], 'Ambiguous delivery never automatically repeats a potentially paid request.' );
$store->control( $id, 'retry' ); $wpdb->tables['ai_jobs'][1]['available_at'] = '2000-01-01 00:00:00';
$job = $store->claim(); $a = $store->beginAttempt( $id, $job['lease_token'] );
$store->failAttempt( $id, $job['lease_token'], $a['id'], 'invalid_json', false, 60, false, array( 'input_tokens' => 10, 'output_tokens' => 11, 'cost_estimate' => 0.1 ) );
check( 10 === $wpdb->tables['ai_attempts'][3]['input_tokens'] && 'failed' === $wpdb->tables['ai_jobs'][1]['status'], 'Rejected output still retains measured token usage.' );
rejects( fn() => $store->control( $id, 'retry' ), 'Manual retries retain the bounded attempt count.' );

list( $wpdb, $db, $store, $source, $profile ) = fixture();
$id = $store->enqueue( $source, $profile, 'both', str_repeat( '5', 64 ), 'v1' ); $job = $store->claim(); $a = $store->beginAttempt( $id, $job['lease_token'] );
$wpdb->tables['ai_jobs'][1]['locked_until'] = '2000-01-01 00:00:00';
check( null === $store->claim() && 'failed' === $wpdb->tables['ai_jobs'][1]['status'], 'An abandoned inflight request becomes uncertain instead of being replayed.' );
check( 'uncertain' === $wpdb->tables['ai_attempts'][1]['status'] && null === $wpdb->tables['ai_jobs'][1]['cost_estimate'], 'Recovered unknown delivery keeps billing unknown.' );
rejects( fn() => $store->completeAttempt( $id, $job['lease_token'], $a['id'], $output, $draft, array() ), 'Expired fenced workers cannot save late output.' );

// Scope enforcement, failures before sending, cancellation, settings-only connection checks.
list( $wpdb, $db, $store, $source, $profile ) = fixture();
$id = $store->enqueue( $source, $profile, 'use_cases', str_repeat( '6', 64 ), 'v1' ); $job = $store->claim(); $a = $store->beginAttempt( $id, $job['lease_token'] );
$store->completeAttempt( $id, $job['lease_token'], $a['id'], array( 'use_cases' => 'draft' ), array( 'zh_CN' => array( 'content' => '<h2>Use cases</h2><p>Partial</p>' ) ), array() );
rejects( fn() => $store->accept( $id, 1, array( 'zh_CN.content' ) ), 'A partial use-cases section cannot become an entire published body.' );
$id2 = $store->enqueue( array(), $profile, 'connection', str_repeat( '7', 64 ), 'v1' ); $job2 = $store->claim(); $a2 = $store->beginAttempt( $id2, $job2['lease_token'] );
$store->completeAttempt( $id2, $job2['lease_token'], $a2['id'], array( 'connected' => true ), array(), array( 'input_tokens' => 4, 'output_tokens' => 2, 'elapsed_ms' => 5, 'cost_estimate' => 0.001 ) );
check( 'complete' === $wpdb->tables['ai_jobs'][$id2]['status'] && null === $wpdb->tables['ai_jobs'][$id2]['skill_id'], 'Connection tests have usage history without a shadow Skill.' );
$id3 = $store->enqueue( array(), $profile, 'connection', str_repeat( '8', 64 ), 'v1' ); $store->control( $id3, 'cancel' );
check( 'cancelled' === $wpdb->tables['ai_jobs'][$id3]['status'] && null === $wpdb->tables['ai_jobs'][$id3]['active_key'], 'Queued cancellation releases its duplicate-work guard.' );
$id4 = $store->enqueue( array(), $profile, 'connection', str_repeat( '9', 64 ), 'v1' ); $job4 = $store->claim(); $store->fail( $id4, $job4['lease_token'], 'profile_changed' );
check( 'failed' === $wpdb->tables['ai_jobs'][$id4]['status'] && empty( array_filter( $wpdb->tables['ai_attempts'], fn( $r ) => $r['job_id'] === $id4 ) ), 'Preflight failures do not invent API attempts.' );
rejects( fn() => $store->enqueue( $source, $profile, 'bad', str_repeat( 'a', 64 ), 'v1' ), 'Unknown generation scope is rejected.' );
rejects( fn() => $store->listJobs( 0 ), 'Pagination is bounded.' );
// Before-HTTP source checks, no-attempt recovery, and edited-field scope protections.
list( $wpdb, $db, $store, $source, $profile ) = fixture();
$profile['api_key'] = 'must-not-persist';
$id = $store->enqueue( $source, $profile, 'zh_CN', str_repeat( 'd', 64 ), 'v1' );
check( ! str_contains( $wpdb->tables['ai_jobs'][1]['profile_json'], 'must-not-persist' ), 'Only public profile fields enter the database.' );
$job = $store->claim(); $oldToken = $job['lease_token'];
$wpdb->tables['ai_jobs'][1]['locked_until'] = '2000-01-01 00:00:00';
$job = $store->claim(); check( $job && $job['lease_token'] !== $oldToken && 0 === (int) $job['attempts'], 'A crash before beginAttempt is safely recoverable without an API replay.' );
$wpdb->tables['skills'][1]['revision'] = 4;
rejects( fn() => $store->assertCurrent( $id, $job['lease_token'] ), 'A concurrent human revision prevents sending stale data.' );
$wpdb->tables['skills'][1]['revision'] = 3;
$a = $store->beginAttempt( $id, $job['lease_token'] );
rejects( fn() => $store->completeAttempt( $id, $job['lease_token'], $a['id'], $output, $draft, array() ), 'A single-language request cannot save the other language.' );
$store->completeAttempt( $id, $job['lease_token'], $a['id'], $output, array( 'zh_CN' => $draft['zh_CN'] ), array() );
rejects( fn() => $store->accept( $id, 1, array( array( 'bad' ) ) ), 'Malformed nested field choices are rejected without type coercion.' );
$id2 = $store->enqueue( $source, $profile, 'summary', str_repeat( 'e', 64 ), 'v1' ); $job2 = $store->claim(); $a2 = $store->beginAttempt( $id2, $job2['lease_token'] );
rejects( fn() => $store->completeAttempt( $id2, $job2['lease_token'], $a2['id'], $output, array( 'zh_CN' => array( 'content' => 'Not a summary' ) ), array() ), 'Summary regeneration is confined to summary fields.' );
$store->completeAttempt( $id2, $job2['lease_token'], $a2['id'], $output, array( 'zh_CN' => array( 'summary' => '仅摘要' ) ), array() );
rejects( fn() => $store->saveReview( $id2, 1, array( 'zh_CN' => array( 'title' => 'Wrong scope' ) ) ), 'Manual review cannot silently broaden a summary regeneration.' );

// A long editing history never transfers every large snapshot into the detail response.
list( $wpdb, $db, $store, $source, $profile ) = fixture();
$id = $store->enqueue( $source, $profile, 'both', str_repeat( 'f', 64 ), 'v1' ); $job = $store->claim(); $attempt = $store->beginAttempt( $id, $job['lease_token'] );
$store->completeAttempt( $id, $job['lease_token'], $attempt['id'], $output, $draft, array() );
$largeDraft = array( 'zh_CN' => array( 'content' => str_repeat( 'z', 450000 ) ), 'en_US' => array( 'content' => str_repeat( 'e', 450000 ) ) );
$store->saveReview( $id, 1, $largeDraft );
$review = $wpdb->tables['ai_reviews'][1];
check( strlen( $review['snapshot_json'] ) > 900000, 'Large bilingual reviews are legal, so bounding only the row count is insufficient.' );
// Reuse the fixture string in memory; the recording adapter must project bodies out of SQL results.
for ( $i = 2; $i <= 103; ++$i ) { $wpdb->tables['ai_reviews'][$i] = array_replace( $review, array( 'id' => $i, 'revision' => $i + 1 ) ); }
$wpdb->tables['ai_reviews'][104] = array_replace( $review, array( 'id' => 104, 'job_id' => 2, 'snapshot_json' => '{invalid-other-job' ) );
$wpdb->tables['ai_reviews'][103]['snapshot_json'] = '{invalid-unselected-snapshot';
$details = $store->job( $id );
check( 103 === $details['reviews_total'] && 25 === count( $details['reviews'] ) && 103 === $details['reviews'][0]['id'] && 79 === $details['reviews'][24]['id'], 'History metadata is ordered and limited to the requested job and page.' );
check( ! isset( $details['reviews'][0]['snapshot'], $details['reviews'][0]['snapshot_json'] ) && strlen( json_encode( $details['reviews'] ) ) < 10000, 'Detail transfers compact metadata regardless of snapshot body size or validity.' );
$lastPage = $store->job( $id, 5 );
check( 3 === count( $lastPage['reviews'] ) && array( 3, 2, 1 ) === array_column( $lastPage['reviews'], 'id' ), 'Records older than the former 100-row cap remain reachable through pagination.' );
check( array() === $store->job( $id, 6 )['reviews'], 'Out-of-range history pages return an honest empty list.' );
check( $largeDraft === $store->reviewSnapshot( $id, 1 )['snapshot'], 'An explicitly selected snapshot is decoded intact.' );
rejects( fn() => $store->reviewSnapshot( $id, 104 ), 'Snapshot IDs belonging to another job cannot be read through this job.' );
rejects( fn() => $store->reviewSnapshot( $id, 999 ), 'Missing snapshots are rejected without fabricating content.' );
rejects( fn() => $store->reviewSnapshot( 0, 1 ), 'Snapshot lookup requires valid job identity.' );
rejects( fn() => $store->job( $id, 0 ), 'History page zero is rejected.' );
rejects( fn() => $store->job( $id, 10001 ), 'History offsets have a fixed upper bound.' );
check( 104 === count( $wpdb->tables['ai_reviews'] ), 'Viewing history does not delete old or unselected snapshots.' );
$GLOBALS['caps'] = array( 'aish_generate_content' => false, 'manage_options' => false );
rejects( fn() => $store->job( 1 ), 'Login alone cannot read source and draft history.' );
rejects( fn() => $store->reviewSnapshot( 1, 1 ), 'Direct single-snapshot reads independently enforce generation capability.' );
rejects( fn() => $store->enqueue( $source, $profile, 'both', str_repeat( 'c', 64 ), 'v1' ), 'Unauthorized users cannot enqueue paid jobs.' );
$GLOBALS['caps'] = array( 'aish_generate_content' => true, 'aish_review_skills' => false, 'manage_options' => false );
rejects( fn() => $store->saveReview( 1, 1, array() ), 'Generation permission is not review permission.' );
echo "PASS: $count isolated AI storage assertions (in-memory adapter; no MySQL/WordPress/real AI API).\n";
