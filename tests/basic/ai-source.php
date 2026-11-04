<?php
declare(strict_types=1);

namespace {
	// Actual snapshot/enqueue services and budget/prompt code; recording DB, settings and queue stubs.
	// This does not execute joins, transactions, WordPress hooks, a provider or network requests.
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['ai_source_caps'] = array( 'aish_generate_content' => true, 'aish_manage_ai_settings' => true );
	$GLOBALS['ai_source_hooks'] = array(); $GLOBALS['ai_source_http_calls'] = 0;
	function current_user_can( string $cap ): bool { return $GLOBALS['ai_source_caps'][ $cap ] ?? false; }
	function do_action( string $hook, mixed ...$args ): void { $GLOBALS['ai_source_hooks'][] = $hook; }
	function wp_safe_remote_post( mixed ...$args ): never { ++$GLOBALS['ai_source_http_calls']; throw new \RuntimeException( 'Enqueue must not make HTTP calls.' ); }
	function wp_safe_remote_get( mixed ...$args ): never { ++$GLOBALS['ai_source_http_calls']; throw new \RuntimeException( 'Source snapshot must not fetch URLs.' ); }
	$core = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	foreach ( array( 'Security/Access.php', 'Security/Input.php', 'Sources/ImportStore.php', 'AI/Prompt.php' ) as $file ) { require $core . $file; }
}

namespace AISkillsHub\Core\Database {
	final class Migrator { public bool $current = true; public function isCurrent(): bool { return $this->current; } }
	final class Database {
		public ?array $source = null; public array $translations = array(); public array $queries = array();
		public function table( string $entity ): string { return '`fixture_' . $entity . '`'; }
		public function row( string $sql, array $values = array() ): ?array {
			$this->queries[] = array( $sql, $values );
			if ( str_starts_with( $sql, 'SELECT i.*' ) ) { return $this->source; }
			throw new \LogicException( 'Unexpected query in source fixture.' );
		}
		public function rows( string $sql, array $values = array() ): array {
			$this->queries[] = array( $sql, $values );
			if ( str_contains( $sql, 'FROM `fixture_skill_translations`' ) ) { return $this->translations; }
			if ( str_contains( $sql, 'FROM `fixture_categories`' ) || str_contains( $sql, 'FROM `fixture_tags`' ) ) { return array(); }
			throw new \LogicException( 'Unexpected list query in source fixture.' );
		}
	}
}

namespace AISkillsHub\Core\AI {
	final class Settings {
		public array $profiles = array();
		public function get( string $id ): array { return $this->profiles[ $id ] ?? throw new \DomainException( 'ai_profile_unavailable' ); }
	}
	final class GenerationStore {
		public array $enqueued = array(); public bool $reject = false;
		public static function translationSnapshot( array $rows ): array {
			$empty = array_fill_keys( array( 'title', 'slug', 'summary', 'content', 'seo_title', 'seo_description' ), '' );
			$result = array( 'zh_CN' => $empty, 'en_US' => $empty );
			foreach ( $rows as $row ) { if ( isset( $result[ $row['locale'] ] ) ) { foreach ( $empty as $key => $unused ) { $result[ $row['locale'] ][ $key ] = (string) ( $row[ $key ] ?? '' ); } } }
			return $result;
		}
		public function enqueue( array $source, array $profile, string $mode, string $requestKey, string $version ): int {
			if ( $this->reject ) { throw new \DomainException( 'ai_source_or_editorial_changed' ); }
			$this->enqueued[] = compact( 'source', 'profile', 'mode', 'requestKey', 'version' ); return count( $this->enqueued ) + 40;
		}
	}
}

namespace {
	foreach ( array( 'AI/Worker.php', 'AI/SourceContext.php', 'AI/GenerationService.php' ) as $file ) { require $core . $file; }
	use AISkillsHub\Core\AI\{Settings,GenerationStore,GenerationService,SourceContext,Prompt};
	use AISkillsHub\Core\Database\{Database,Migrator};
	use AISkillsHub\Core\Sources\ImportStore;
	$checks = 0;
	function check( bool $pass, string $message ): void { global $checks; ++$checks; if ( ! $pass ) { throw new \RuntimeException( $message ); } }
	function rejects( callable $work, string $code ): void {
		try { $work(); } catch ( \Throwable $error ) { check( $error->getMessage() === $code, 'Expected ' . $code . ', got ' . $error->getMessage() ); return; }
		throw new \RuntimeException( 'Expected ' . $code );
	}
	function encoded( array $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
	function original( string $raw = "---\nname: review\ndescription: Review changes\n---\nSummarize a code review." ): array {
		return array( 'id' => 13, 'skill_id' => 7, 'skill_revision' => 4, 'skill_status' => 'pending', 'repository_id' => 2, 'repository_archived' => 0, 'owner' => 'example', 'name' => 'skills', 'source_path' => 'review/SKILL.md', 'source_status' => 'present', 'change_status' => 'new', 'source_content' => $raw, 'file_sha' => hash( 'sha1', 'blob ' . strlen( $raw ) . "\0" . $raw ), 'commit_sha' => str_repeat( 'a', 40 ), 'metadata_json' => encoded( array( 'name' => 'review', 'description' => 'Review changes' ) ), 'findings_json' => '{}', 'last_seen_job' => null, 'updated_at' => '2026-11-05 00:00:00' );
	}
	$db = new Database(); $db->source = original();
	$db->translations = array( array( 'locale' => 'zh_CN', 'title' => '人工标题', 'slug' => 'stable-source', 'content' => 'PRIVATE HUMAN EDITORIAL BODY' ) );
	$settings = new Settings(); $settings->profiles['primary'] = array( 'id' => 'primary', 'provider' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'fixture-model', 'temperature' => 0.2, 'timeout' => 30, 'max_output_tokens' => 1024, 'input_price' => 1.0, 'output_price' => 2.0, 'max_cost' => 0.0, 'enabled' => true );
	$migrator = new Migrator(); $sourceContext = new SourceContext( $db, $migrator ); $store = new GenerationStore(); $service = new GenerationService( $settings, $sourceContext, $store );
	$key = 'source-service-key-00001'; $id = $service->enqueue( 7, 'primary', 'both', $key ); $job = $store->enqueued[0];
	check( $id === 41 && $job['mode'] === 'both' && $job['version'] === Prompt::VERSION, 'service queues current prompt version and returns durable job identity' );
	check( $job['requestKey'] === hash( 'sha256', $key ) && strlen( $job['requestKey'] ) === 64, 'request key normalized into stable bounded hash' );
	check( $job['source']['skill_id'] === 7 && $job['source']['import_id'] === 13 && $job['source']['repository_id'] === 2, 'source uses stable Core IDs' );
	check( $job['source']['source_fingerprint'] === ImportStore::sourceRevision( $db->source ), 'captured source fingerprint accompanies enqueue for transactional store validation' );
	check( $job['source']['translations']['zh_CN']['title'] === '人工标题' && $job['source']['translations']['en_US']['title'] === '', 'private human baseline retained without inventing missing English' );
	$messages = Prompt::build( $job['source'], 'both' );
	check( ! str_contains( encoded( $messages ), 'PRIVATE HUMAN EDITORIAL BODY' ) && ! str_contains( encoded( $messages ), 'stable-source' ), 'editorial baseline excluded from actual provider messages' );
	check( $GLOBALS['ai_source_hooks'] === array( 'aish_ai_wake' ), 'successful enqueue wakes scheduler instead of doing work inline' );
	check( $GLOBALS['ai_source_http_calls'] === 0, 'generation enqueue performs no HTTP' );
	$service->enqueue( 7, 'primary', 'both', $key ); check( $store->enqueued[1]['requestKey'] === $job['requestKey'], 'repeated form submission preserves idempotency identity' );
	foreach ( array( 'zh_CN', 'en_US', 'summary', 'use_cases' ) as $mode ) { $service->enqueue( 7, 'primary', $mode, 'mode-request-00000-' . $mode ); check( end( $store->enqueued )['mode'] === $mode, 'service preserves requested partial scope: ' . $mode ); }
	$before = count( $store->enqueued ); $hooks = count( $GLOBALS['ai_source_hooks'] );
	foreach ( array( 'publish', 'connection', '' ) as $mode ) { rejects( fn() => $service->enqueue( 7, 'primary', $mode, $key ), 'ai_invalid_mode' ); }
	foreach ( array( 'short', str_repeat( 'a', 129 ), 'request-key-with space' ) as $badKey ) { rejects( fn() => $service->enqueue( 7, 'primary', 'both', $badKey ), 'ai_invalid_request_key' ); }
	check( count( $store->enqueued ) === $before && count( $GLOBALS['ai_source_hooks'] ) === $hooks, 'invalid task input neither queues nor wakes' );
	$settings->profiles['primary']['max_cost'] = 0.000001;
	rejects( fn() => $service->enqueue( 7, 'primary', 'both', $key ), 'ai_budget_exceeded' );
	check( count( $store->enqueued ) === $before, 'real budget preflight rejects before queue writes' );
	$settings->profiles['primary']['input_price'] = null;
	rejects( fn() => $service->enqueue( 7, 'primary', 'both', $key ), 'ai_budget_usage_unknown' );
	$settings->profiles['primary']['max_cost'] = 0.0; $settings->profiles['primary']['input_price'] = 1.0;
	$db->source = original( str_repeat( 'x', 65537 ) ); rejects( fn() => $service->enqueue( 7, 'primary', 'both', $key ), 'ai_invalid_source_text' );
	check( count( $store->enqueued ) === $before, 'source prompt bound enforced before queue writes' );
	$db->source = original(); $db->source['source_content'] .= 'changed without hash'; rejects( fn() => $service->enqueue( 7, 'primary', 'both', $key ), 'ai_source_hash_mismatch' );
	$db->source = null; rejects( fn() => $service->enqueue( 7, 'primary', 'both', $key ), 'ai_source_unavailable' );
	$db->source = original(); $migrator->current = false; rejects( fn() => $service->enqueue( 7, 'primary', 'both', $key ), 'ai_schema_not_current' ); $migrator->current = true;
	$store->reject = true; rejects( fn() => $service->enqueue( 7, 'primary', 'both', $key ), 'ai_source_or_editorial_changed' ); $store->reject = false;
	check( count( $GLOBALS['ai_source_hooks'] ) === $hooks, 'snapshot race rejected by queue does not wake or imply success' );
	$queries = count( $db->queries ); rejects( fn() => $service->enqueue( 7, 'missing', 'both', $key ), 'ai_profile_unavailable' );
	check( count( $db->queries ) === $queries, 'unavailable profile rejected before reading source' );
	$GLOBALS['ai_source_caps']['aish_generate_content'] = false; rejects( fn() => $service->enqueue( 7, 'primary', 'both', $key ), 'Core write permission denied.' );
	$GLOBALS['ai_source_caps']['aish_generate_content'] = true; $GLOBALS['ai_source_caps']['aish_manage_ai_settings'] = false;
	rejects( fn() => $service->enqueueConnection( 'primary', $key ), 'Core write permission denied.' );
	$GLOBALS['ai_source_caps']['aish_manage_ai_settings'] = true; $queries = count( $db->queries );
	$GLOBALS['ai_source_caps']['aish_generate_content'] = false; $before = count( $store->enqueued );
	rejects( fn() => $service->enqueueConnection( 'primary', 'connection-test-00001' ), 'Core write permission denied.' );
	check( count( $store->enqueued ) === $before && count( $db->queries ) === $queries, 'settings-only user cannot queue paid connection generation' );
	$GLOBALS['ai_source_caps']['aish_generate_content'] = true;
	$service->enqueueConnection( 'primary', 'connection-test-00001' ); $connection = end( $store->enqueued );
	check( $connection['mode'] === 'connection' && $connection['source'] === array() && count( $db->queries ) === $queries, 'connection test queues no Skill data and reads no private source' );
	$settings->profiles['primary']['max_cost'] = 0.000001; rejects( fn() => $service->enqueueConnection( 'primary', 'connection-test-00002' ), 'ai_budget_exceeded' );
	check( $GLOBALS['ai_source_http_calls'] === 0, 'connection test and source service never call network inline' );
	echo "PASS: $checks isolated AI source/service assertions (recorded DB/queue stubs; no SQL, WordPress or real AI API).\n";
}
