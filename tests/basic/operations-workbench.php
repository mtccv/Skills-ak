<?php
declare(strict_types=1);
/** Isolated orchestration tests. Domain query/service substitutes do not constitute WordPress/MySQL tests. */
namespace AISkillsHub\Core\Submissions {
	final class Query { public int $calls = 0; public array $filters = array(); public string $kind = ''; public function ownedSql(): string { return "p.post_type='aish_message' AND p.post_status='private' AND p.post_author>0 AND marker_contract=1"; } public function adminList( string $kind, array $f ): array { ++$this->calls; $this->filters = $f; $this->kind = $kind; return array( 'items' => array( array( 'id' => 7, 'name' => '<script>private</script>', 'state' => 'submitted', 'revision' => 3, 'locale' => 'zh_CN', 'created_at' => '2026-01-01 00:00:00' ) ), 'total' => 3, 'page' => $f['page'] ?? 1, 'per_page' => $f['per_page'] ); } public function adminDetail( string $kind, int $id ): ?array { ++$this->calls; return 99 === $id ? null : array( 'id' => $id ); } public function history( int $id, string $kind, int $page ): array { return array( 'items' => array(), 'total' => 0, 'page' => $page, 'per_page' => 20 ); } }
	final class Service { public array $calls = array(); public bool $revoke = false; public function moderate( int $id, int $version, string $decision, string $feedback, string $note, array $skills, string $key ): array { $this->calls[] = func_get_args(); if ( $this->revoke ) { unset( $GLOBALS['caps']['aish_manage_submissions'] ); } if ( 8 === $id ) { throw new Exception( 'conflict' ); } return array(); } }
}
namespace AISkillsHub\Core\Community {
	final class Query { public int $calls = 0; public function adminReviews( array $f ): array { ++$this->calls; return array( 'items' => array( array( 'id' => 7, 'state' => 'approved', 'lock_version' => 4, 'pending_revision_id' => 12, 'pending' => array( 'id' => 12, 'revision' => 2, 'title' => 'A pending revision', 'created_at' => '2026-02-02 00:00:00', 'locale' => 'en_US' ), 'created_at' => '2026-01-01 00:00:00' ) ), 'total' => 1, 'page' => $f['page'] ?? 1, 'per_page' => $f['per_page'] ); } public function adminReports( string $kind, array $f ): array { ++$this->calls; return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 20 ); } }
	final class Service { public array $calls = array(); public function moderateReview( int $id, int $revision, int $lock, string $decision, string $reason, string $note, string $key ): array { $this->calls[] = func_get_args(); if ( 8 === $id ) { throw new \RuntimeException( 'secret-token-in-private-error' ); } return array(); } public function resolveReport( string $kind, int $id, string $decision, string $reason, string $note, string $key, string $state ): array { $this->calls[] = func_get_args(); return array(); } }
}
namespace AISkillsHub\Core\Developers {
	final class Query { public function adminClaims( array $f ): array { return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 20 ); } }
	final class Service { public array $calls = array(); public function moderateClaim( int $id, string $state, string $decision, string $reason, string $note, string $key, array $verification = array() ): array { $this->calls[] = func_get_args(); return array(); } }
}
namespace AISkillsHub\Core\Database {
	final class Migrator { public bool $current = true; public function isCurrent(): bool { return $this->current; } }
	final class Database {
		public array $calls = array(); public bool $failAggregate = false; public int $aggregateTotal = 3; public array $tables = array( 'repositories' => array(), 'jobs' => array(), 'imports' => array(), 'workflow_events' => array() );
		public function connection(): object { return (object) array( 'posts' => 'qa_posts', 'postmeta' => 'qa_postmeta' ); }
		public function rows( string $sql, array $args = array() ): array { $this->calls[] = $sql; if ( $this->failAggregate ) { throw new \RuntimeException( 'private SQL error' ); } $result = array(); foreach ( $args as $state ) { if ( in_array( $state, array( 'submitted', 'in_review', 'needs_changes', 'pending', 'reviewing' ), true ) ) { $result[] = array( 'state' => $state, 'total' => $this->aggregateTotal ); } } return $result; }
		public function prefix(): string { return 'qa_'; } public function table( string $name ): string { return 'qa_aish_' . $name; }
		public function value( string $sql, array $args = array() ): mixed { $this->calls[] = $sql; if ( str_contains( $sql, 'GET_LOCK' ) || str_contains( $sql, 'RELEASE_LOCK' ) ) { return 1; } return null; }
		public function row( string $sql, array $args = array() ): ?array { $this->calls[] = $sql;
			if ( str_contains( $sql, 'COALESCE(SUM(' ) && str_contains( $sql, 'reviews' ) ) { return array( 'count_0' => 1 ); }
			if ( str_contains( $sql, 'repositories' ) ) { return $this->tables['repositories'][ $args[0] ] ?? null; }
			if ( str_contains( $sql, 'imports' ) ) { return $this->tables['imports'][ $args[0] ] ?? null; }
			if ( str_contains( $sql, 'workflow_events' ) ) { foreach ( $this->tables['workflow_events'] as $event ) { if ( $event['event_key'] === $args[0] ) { return $event; } } return null; }
			if ( str_contains( $sql, 'jobs' ) ) { foreach ( $this->tables['jobs'] as $job ) { if ( $job['active_key'] === $args[0] ) { return $job; } } } return null;
		}
		public function transaction( callable $callback ): mixed { $old = $this->tables; try { return $callback(); } catch ( \Throwable $e ) { $this->tables = $old; throw $e; } }
		public function insert( string $table, array $row ): int { $id = count( $this->tables[ $table ] ) + 1; $this->tables[ $table ][ $id ] = array( 'id' => $id ) + $row; return $id; }
		public function updateById( string $table, int $id, array $data ): void { $this->tables[ $table ][ $id ] = array_replace( $this->tables[ $table ][ $id ], $data ); }
	}
}
namespace {
	define( 'ABSPATH', '/isolated/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
	$GLOBALS['caps'] = array(); $GLOBALS['locale'] = 'en_US'; $GLOBALS['transients'] = array(); $GLOBALS['hooks'] = array();
	function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['caps'][ $cap ] ); }
	function get_current_user_id(): int { return 9; }
	function get_user_locale(): string { return $GLOBALS['locale']; }
	function sanitize_text_field( string $v ): string { return trim( strip_tags( $v ) ); }
	function sanitize_textarea_field( string $v ): string { return trim( strip_tags( $v ) ); }
	function wp_json_encode( mixed $v ): string { return json_encode( $v, JSON_THROW_ON_ERROR ); }
	function admin_url( string $v ): string { return 'https://example.test/wp-admin/' . $v; }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function wp_unslash( mixed $v ): mixed { return is_string( $v ) ? stripslashes( $v ) : $v; }
	function esc_html( mixed $v ): string { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_attr( mixed $v ): string { return esc_html( $v ); }
	function esc_url( mixed $v ): string { return esc_html( $v ); }
	function selected( mixed $a, mixed $b, bool $echo = true ): string { return $a === $b ? ' selected' : ''; }
	function get_transient( string $key ): mixed { return $GLOBALS['transients'][ $key ] ?? false; }
	function delete_transient( string $key ): void { unset( $GLOBALS['transients'][ $key ] ); }
	function set_transient( string $key, mixed $v, int $ttl ): void { $GLOBALS['transients'][ $key ] = $v; }
	function wp_verify_nonce( string $nonce, string $action ): bool { return 'valid' === $nonce; }
	function wp_nonce_field( string $action ): void { echo '<input name="_wpnonce" value="valid">'; }
	function wp_die( string $message, string $title = '', array $args = array() ): never { throw new \RuntimeException( 'http_' . ( $args['response'] ?? 500 ) ); }
	final class Redirect extends \RuntimeException {}
	function wp_safe_redirect( string $url, int $status = 302 ): never { throw new Redirect( $url ); }
	function add_action( string $hook, callable $call ): void { $GLOBALS['hooks'][ $hook ][] = $call; }
	$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	foreach ( array( 'Admin/ContributionUi.php', 'Community/Input.php', 'Community/Exception.php', 'Submissions/Exception.php', 'Developers/Exception.php', 'Security/Input.php', 'Security/Access.php', 'Community/Events.php', 'Sources/ImportStore.php', 'Operations/Workbench.php', 'Operations/Health.php', 'Operations/Dashboard.php', 'Operations/Admin.php' ) as $file ) { require $root . $file; }
	use AISkillsHub\Core\Operations\Workbench; use AISkillsHub\Core\Operations\Health;
	$assertions = 0;
	function check( bool $ok, string $label ): void { ++$GLOBALS['assertions']; if ( ! $ok ) { throw new \RuntimeException( $label ); } }
	function rejects( callable $call, string $label ): void { try { $call(); } catch ( \Throwable $e ) { check( true, $label ); return; } check( false, $label ); }
	$sq = new AISkillsHub\Core\Submissions\Query(); $ss = new AISkillsHub\Core\Submissions\Service(); $cq = new AISkillsHub\Core\Community\Query(); $cs = new AISkillsHub\Core\Community\Service(); $dq = new AISkillsHub\Core\Developers\Query(); $ds = new AISkillsHub\Core\Developers\Service();
	$workbench = new Workbench( $sq, $ss, $cq, $cs, $dq, $ds );
	check( array() === Workbench::available(), 'no account grants no queue access' );
	$GLOBALS['caps'] = array( Workbench::CAP => true ); rejects( fn() => $workbench->listing( 'submissions' ), 'overview does not grant submission access' ); check( 0 === $sq->calls, 'denial occurs before private query' );
	$GLOBALS['caps']['aish_manage_submissions'] = true; check( array_keys( Workbench::available() ) === array( 'submissions' ), 'business capabilities independently filter queues' );
	$listed = $workbench->listing( 'submissions', array( 'state' => 'submitted', 'page' => 2, 'q' => '中文' ) ); check( 'submit' === $sq->kind && 2 === $listed['page'] && '中文' === $sq->filters['q'] && 'asc' === $sq->filters['direction'], 'pagination/search/order forwarded to existing private query' );
	$key = str_repeat( 'a', 32 ); $rows = array( array( 'id' => 7, 'revision' => 3 ), array( 'id' => 8, 'revision' => 4 ) );
	$result = $workbench->batch( 'submissions', $rows, 'reject', 'Please correct the source.', 'Internal only', 'other', $key ); check( $result[0]['success'] && ! $result[1]['success'] && 'conflict' === $result[1]['code'], 'partial batch preserves individual safe conflict outcomes' ); check( 3 === $ss->calls[0][1] && 4 === $ss->calls[1][1] && array() === $ss->calls[0][5], 'each revision is preserved and no content association is fabricated' );
	$firstKeys = array_column( $ss->calls, 6 ); $workbench->batch( 'submissions', $rows, 'reject', 'Please correct the source.', 'Internal only', 'other', $key ); check( $firstKeys === array_slice( array_column( $ss->calls, 6 ), 2 ), 'replayed batches forward stable per-object idempotency identities' );
	foreach ( array( 'accept', 'approve', 'publish', 'link_result' ) as $decision ) { rejects( fn() => $workbench->batch( 'submissions', $rows, $decision, 'Please correct the source.', '', 'other', $key ), 'no batch approval bypass' ); }
	rejects( fn() => $workbench->batch( 'updates', $rows, 'reject', 'Please correct the source.', '', 'other', $key ), 'submission capability cannot operate update suggestions' );
	rejects( fn() => $workbench->batch( 'submissions', array_fill( 0, 11, $rows[0] ), 'reject', 'Please correct the source.', '', 'other', $key ), 'batch has hard upper bound' ); rejects( fn() => $workbench->batch( 'submissions', array( $rows[0], $rows[0] ), 'reject', 'Please correct the source.', '', 'other', $key ), 'duplicate row selection rejected before writing' );
	$ss->revoke = true; $n = count( $ss->calls ); $r = $workbench->batch( 'submissions', $rows, 'reject', 'Please correct the source.', '', 'other', str_repeat( 'b', 32 ) ); check( count( $ss->calls ) === $n + 1 && 'forbidden' === $r[1]['code'], 'each row rechecks capabilities after previous row' ); $ss->revoke = false;
	$GLOBALS['caps']['aish_moderate_community'] = true; $r = $workbench->listing( 'reviews' ); check( $r['items'][0]['pending_revision'] && 'approved' === $r['items'][0]['state'] && 12 === $r['items'][0]['revision_id'] && '2026-02-02 00:00:00' === $r['items'][0]['waiting_since'], 'pending revision stays distinct from prior approved review and uses its own timestamp' );
	$r = $workbench->batch( 'reviews', array( array( 'id' => 7, 'revision_id' => 12, 'lock_version' => 4 ), array( 'id' => 8, 'revision_id' => 13, 'lock_version' => 5 ) ), 'reject', '', 'Private moderator note', 'spam', $key ); check( array_slice( $cs->calls[0], 0, 4 ) === array( 7, 12, 4, 'reject' ) && 'operation_failed' === $r[1]['code'] && ! str_contains( json_encode( $r ), 'secret' ), 'specific review revision/CAS forwarded; private error content redacted' );
	$GLOBALS['caps']['aish_manage_community_reports'] = true; $workbench->batch( 'skill_reports', array( array( 'id' => 2, 'state' => 'reviewing' ) ), 'closed', '', 'Staff only', 'no_action', $key ); check( array( 'skill', 2, 'closed', 'no_action', 'Staff only' ) === array_slice( end( $cs->calls ), 0, 5 ), 'skill correction uses separate existing report service' );
	$GLOBALS['caps']['aish_manage_author_claims'] = true; $workbench->batch( 'claims', array( array( 'id' => 3, 'state' => 'pending' ) ), 'reject', 'Evidence not sufficient.', 'Staff only', 'other', $key ); check( 1 === count( $ds->calls ) && 'pending' === $ds->calls[0][1], 'claim remains original state machine' );
	$db = new AISkillsHub\Core\Database\Database(); $migration = new AISkillsHub\Core\Database\Migrator(); $imports = new AISkillsHub\Core\Sources\ImportStore( $db, $migration ); $health = new Health( $db, $migration, $imports );
	$dashboard = new AISkillsHub\Core\Operations\Dashboard( $db, $migration, $workbench, $health ); $providerCalls = 0;
	$dashboard->addSummary( 'accounts', 'aish_manage_accounts', function () use ( &$providerCalls ): array { ++$providerCalls; return array(); } );
	$GLOBALS['caps'] = array( Workbench::CAP => true ); check( array() === $dashboard->snapshot()['metrics'] && 0 === $providerCalls && array() === $db->calls, 'overview alone exposes no counts and executes no restricted provider/query' );
	$GLOBALS['caps']['aish_manage_accounts'] = true; $dashboard->snapshot(); check( 1 === $providerCalls, 'provider executes only with its dedicated capability' );
	$GLOBALS['caps'] = array( Workbench::CAP => true, 'aish_manage_submissions' => true ); $snapshot = $dashboard->snapshot(); check( 3 === count( $snapshot['metrics'] ) && 'UTC' === $snapshot['timezone'] && 3 === $snapshot['metrics'][0]['value'], 'real query counts use separate state scopes and UTC snapshot' );
	$migration->current = false; check( 'unavailable' === $dashboard->snapshot()['metrics'][0]['status'], 'unavailable storage is not represented as zero' ); $migration->current = true;
	$beforeQueries = count( $db->calls ); $beforeProjections = $sq->calls; $dashboard->snapshot();
	check( 1 === count( $db->calls ) - $beforeQueries && $sq->calls === $beforeProjections, 'three submission state cards share one aggregate without private payload projection' );
	$sql = end( $db->calls ); check( str_contains( $sql, 'COUNT(DISTINCT p.ID)' ) && str_contains( $sql, "p.post_status='private'" ) && str_contains( $sql, 'marker_contract=1' ) && str_contains( $sql, '_aish_message_kind' ), 'count projection reuses ownership/type boundary and original EXISTS deduplication' );
	$db->aggregateTotal = 8; check( 8 === $dashboard->snapshot()['metrics'][0]['value'], 'next snapshot observes changed data instead of reusing stale count cache' ); $db->aggregateTotal = 3;
	$db->failAggregate = true; $failed = $dashboard->snapshot(); check( 'unavailable' === $failed['metrics'][0]['status'] && null === $failed['metrics'][0]['value'], 'failed grouped query remains unavailable rather than a cached or invented zero' ); $db->failAggregate = false;
	unset( $GLOBALS['caps']['aish_manage_submissions'] ); $beforeQueries = count( $db->calls );
	rejects( fn() => $workbench->counts( $db, $migration, 'submissions', array( 'submitted' ) ), 'direct count projection rechecks its business capability after a preceding authorized read' ); check( $beforeQueries === count( $db->calls ) && array() === $dashboard->snapshot()['metrics'], 'revoked permissions prevent aggregate SQL and suppress metric cards' );
	$GLOBALS['caps']['aish_moderate_community'] = true; $pending = $workbench->counts( $db, $migration, 'reviews', array( 'pending' ) ); check( 1 === $pending['pending'] && str_contains( end( $db->calls ), 'pending_revision_id IS NOT NULL' ), 'pending review counts use revision pointer, not published head state' );
	rejects( fn() => $workbench->counts( $db, $migration, 'reviews', array( 'pending', 'pending' ) ), 'duplicate state count request rejected' ); rejects( fn() => $workbench->counts( $db, $migration, 'reviews', array( 'pending); DROP TABLE' ) ), 'count states remain bounded validated inputs' );
	$GLOBALS['caps']['aish_manage_submissions'] = true;

	rejects( fn() => $health->retry( array( 1 ) ), 'health requires repository capability' );
	$GLOBALS['caps']['aish_manage_repositories'] = true; $db->tables['repositories'][1] = array( 'id' => 1, 'sync_enabled' => 0, 'is_archived' => 0 ); $db->tables['repositories'][2] = array( 'id' => 2, 'sync_enabled' => 1, 'is_archived' => 0 );
	$r = $health->retry( array( 1, 2 ) ); check( 'paused' === $r[0]['code'] && $r[1]['success'] && 1 === count( $db->tables['jobs'] ), 'retry refuses paused repository and queues enabled repository without HTTP' );
	$r = $health->retry( array( 2 ) ); check( 1 === count( $db->tables['jobs'] ) && 1 === $r[0]['job_id'], 'existing active job is reused' );
	$db->tables['repositories'][2]['sync_enabled'] = 0; rejects( fn() => $imports->enqueue( 2, 'sync', false, true ), 'atomic enabled guard runs before returning even an active job' );
	check( 1 === $imports->enqueue( 2, 'sync', false ), 'legacy explicit manual scan contract remains available' );
	$raw = array( 'id' => 4, 'repository_id' => 2, 'skill_id' => 10, 'commit_sha' => str_repeat( 'c', 40 ), 'file_sha' => str_repeat( 'f', 40 ), 'source_status' => 'missing', 'change_status' => 'missing', 'last_seen_job' => '8', 'updated_at' => '2026-02-03 00:00:00', 'metadata_json' => '{"name":"技能","url":"https://example.test/path"}', 'findings_json' => '[]' );
	$db->tables['imports'][4] = $raw; $fingerprint = AISkillsHub\Core\Sources\ImportStore::sourceRevision( $raw ); check( Health::fingerprint( $raw + array( 'metadata_hash' => hash( 'sha256', $raw['metadata_json'] ), 'findings_hash' => hash( 'sha256', $raw['findings_json'] ) ) ) === $fingerprint, 'bounded-page fingerprint matches authoritative import snapshot including Unicode JSON bytes' );
	$health->acknowledge( 4, $fingerprint, 'checked_source', $key ); $health->acknowledge( 4, $fingerprint, 'checked_source', $key ); check( 1 === count( $db->tables['workflow_events'] ) && $raw === $db->tables['imports'][4], 'acknowledgement is idempotent and does not mutate imported source or skill' );
	rejects( fn() => $health->acknowledge( 4, $fingerprint, 'false_alarm', $key ), 'changed payload cannot reuse acknowledgement key' );
	$db->tables['imports'][4]['file_sha'] = str_repeat( 'e', 40 ); rejects( fn() => $health->acknowledge( 4, $fingerprint, 'checked_source', str_repeat( 'd', 32 ) ), 'stale source snapshot cannot be acknowledged' );
	$admin = new AISkillsHub\Core\Operations\Admin( $dashboard, $workbench, $health ); $admin->register(); check( isset( $GLOBALS['hooks']['admin_post_aish_operations_action'] ) && ! isset( $GLOBALS['hooks']['wp_ajax_nopriv_aish_operations_action'] ), 'only authenticated admin POST entry registered' );
	$_GET = array( 'queue' => 'submissions' ); ob_start(); $admin->workbenchScreen(); $html = ob_get_clean(); check( str_contains( $html, '&lt;script&gt;private&lt;/script&gt;' ) && ! str_contains( $html, '<script>' ) && ! str_contains( $html, 'value="approve"' ), 'native workbench escapes untrusted titles and has no blanket approve action' );
	$GLOBALS['locale'] = 'zh_CN'; check( '用户投稿' === Workbench::label( 'submissions' ), 'queue labels support Chinese' ); $GLOBALS['locale'] = 'en_US';
	$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); rejects( fn() => $admin->handle(), 'GET mutation rejected' ); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( '_wpnonce' => 'invalid' ); rejects( fn() => $admin->handle(), 'missing or invalid nonce rejected before mutation' );
	echo 'PASS: ' . $assertions . " isolated operations workbench/health checks (mock services and storage; no real WordPress/MySQL/API).\n";
}
