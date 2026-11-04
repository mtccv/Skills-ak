<?php
declare(strict_types=1);

namespace {
	// Isolated orchestration: real Worker/Discovery/parser/heuristics, in-memory store and HTTP fixtures.
	// This does not prove transactional MySQL persistence, WordPress Cron delivery or live GitHub behavior.
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Security/Input.php';
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Sources/GitHubException.php';
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Sources/Discovery.php';
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Sources/Frontmatter.php';
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Sources/RiskInspector.php';
}

namespace AISkillsHub\Core\Sources {
	final class ImportStore {
		public array $repo = array( 'id' => 1, 'owner' => 'fixture', 'name' => 'skills', 'is_archived' => 0, 'last_sync_commit' => '' );
		public array $job;
		public array $sources = array();
		public array $recordCalls = array();
		public array $logs = array();
		public array $retries = array();
		public array $finishes = array();
		public array $seenPaths = array();
		public array $baselineChecks = array();
		public bool $baselineIsReusable = true;
		public int $inventoryCalls = 0;
		public int $failCheckpoint = 0;
		public int $failRetry = 0;
		public int $failRecord = 0;
		public int $failFinish = 0;
		private int $leases = 0;
		public function __construct( string $kind = 'sync' ) { $this->resetJob( $kind ); }
		public function resetJob( string $kind = 'sync' ): void { $this->job = array( 'id' => ( $this->job['id'] ?? 0 ) + 1, 'repository_id' => 1, 'kind' => $kind, 'state' => array(), 'status' => 'queued', 'attempts' => 0, 'lease_token' => '' ); }
		public function claim(): ?array {
			if ( in_array( $this->job['status'], array( 'complete', 'incomplete', 'failed', 'cancelled' ), true ) ) { return null; }
			// A remaining running job is explicitly treated as an expired lease in this fixture.
			$this->job['status'] = 'running'; $this->job['lease_token'] = hash( 'sha256', (string) ++$this->leases );
			return $this->job;
		}
		private function lease( int $id, string $token ): void { if ( $id !== $this->job['id'] || $token !== $this->job['lease_token'] || 'running' !== $this->job['status'] ) { throw new \DomainException( 'Fixture lease invalid.' ); } }
		public function repository( int $id ): array { return $this->repo; }
		public function baselineReusable( int $id, string $token, string $sha ): bool { $this->lease( $id, $token ); $this->baselineChecks[] = $sha; return $this->baselineIsReusable; }
		public function updateRepository( int $id, string $token, array $metadata ): void { $this->lease( $id, $token ); $this->repo['github_id'] = $metadata['id']; $this->repo += $metadata; }
		public function checkpoint( int $id, string $token, array $state, int $delay ): void {
			$this->lease( $id, $token );
			if ( $this->failCheckpoint > 0 ) { --$this->failCheckpoint; throw new \RuntimeException( 'Fixture checkpoint failed.' ); }
			$this->job['state'] = $state; $this->job['status'] = 'queued'; $this->job['attempts'] = 0;
		}
		public function retry( int $id, string $token, array $state, string $code, int $delay ): void {
			$this->lease( $id, $token );
			if ( $this->failRetry > 0 ) { --$this->failRetry; throw new \RuntimeException( 'Fixture retry persistence failed.' ); }
			$this->retries[] = array( 'code' => $code, 'delay' => $delay, 'state' => $state );
			$this->job['state'] = $state; $this->job['status'] = ++$this->job['attempts'] >= 5 ? 'failed' : 'retry';
		}
		public function finish( int $id, string $token, array $state, string $status ): void {
			$this->lease( $id, $token );
			if ( $this->failFinish > 0 ) { --$this->failFinish; throw new \RuntimeException( 'Fixture finish failed.' ); }
			if ( 'complete' === $status && ( empty( $state['inventory_complete'] ) || empty( $state['files_complete'] ) || 0 !== $state['errors'] ) ) { throw new \DomainException( 'Incomplete source must not be complete.' ); }
			if ( 'complete' === $status && empty( $state['unchanged_head'] ) && empty( $this->job['state']['inventory_reconciled'] ) ) { throw new \DomainException( 'Missing durable inventory reconciliation.' ); }
			$this->job['state'] = $state; $this->job['status'] = $status; $this->finishes[] = $status;
			if ( 'complete' === $status ) { $this->repo['last_sync_commit'] = $state['commit_sha']; $this->repo['license_spdx'] = (string) ( $state['repository']['license'] ?? '' ); }
		}
		public function log( int $id, string $token, string $code, string $path = '', string $level = 'warning' ): void { $this->lease( $id, $token ); $this->logs[] = array( 'code' => $code, 'path' => $path, 'level' => $level ); }
		public function seen( int $id, string $token, string $path ): void { $this->lease( $id, $token ); $this->seenPaths[] = $path; }
		public function source( int $repository, string $path ): ?array { return $this->sources[ $path ] ?? null; }
		public function sourceContent( int $repository, string $path, string $sha ): ?string { $source = $this->sources[ $path ] ?? array(); return ( $source['file_sha'] ?? '' ) === $sha ? ( $source['raw'] ?? null ) : null; }
		public function record( int $id, string $token, string $path, string $fileSha, string $commitSha, string $raw, array $parsed, array $inspection ): array {
			$this->lease( $id, $token );
			if ( $this->failRecord > 0 ) { --$this->failRecord; throw new \RuntimeException( 'Fixture source transaction failed.' ); }
			$old = $this->sources[ $path ] ?? null;
			$change = ! $old ? 'new' : ( $old['file_sha'] === $fileSha ? 'unchanged' : 'updated' );
			$this->recordCalls[] = compact( 'path', 'fileSha', 'commitSha', 'raw', 'parsed', 'inspection', 'change' );
			$this->sources[ $path ] = array( 'skill_id' => $old['skill_id'] ?? count( $this->sources ) + 1, 'file_sha' => $fileSha, 'raw' => $raw );
			return array( 'skill_id' => $this->sources[ $path ]['skill_id'], 'change' => $change );
		}
		public function completeInventory( int $id, string $token, array $paths, string $sha ): int {
			$this->lease( $id, $token );
			if ( empty( $this->job['state']['inventory_complete'] ) || empty( $this->job['state']['files_complete'] ) || 0 !== $this->job['state']['errors'] ) { throw new \DomainException( 'Incomplete inventory cannot infer deletion.' ); }
			++$this->inventoryCalls; $this->job['state']['inventory_reconciled'] = true;
			return count( array_diff( array_keys( $this->sources ), $paths ) );
		}
	}

	final class GitHubClient {
		public array $calls = array();
		public array $trees = array();
		public array $blobs = array();
		public array $failures = array();
		public string $head;
		public string $root;
		public int $repositoryId = 100;
		public string $license = 'MIT';
		public function __construct() { $this->head = str_repeat( 'c', 40 ); $this->root = str_repeat( 'd', 40 ); }
		private function call( string $method, string $argument = '' ): void {
			$this->calls[] = array( $method, $argument );
			$key = $method . ':' . $argument;
			if ( ! empty( $this->failures[ $key ] ) ) { throw array_shift( $this->failures[ $key ] ); }
		}
		public function repository( string $owner, string $name ): array { $this->call( 'repository' ); return array( 'id' => $this->repositoryId, 'default_branch' => 'main', 'license' => $this->license, 'full_name' => 'fixture/skills' ); }
		public function commit( string $owner, string $name, string $ref ): array { $this->call( 'commit', $ref ); return array( 'sha' => $this->head, 'tree_sha' => $this->root ); }
		public function tree( string $owner, string $name, string $sha, bool $recursive ): array { $key = $sha . ':' . (int) $recursive; $this->call( 'tree', $key ); return $this->trees[ $key ] ?? throw new \RuntimeException( 'Missing tree fixture.' ); }
		public function blob( string $owner, string $name, string $sha ): string { $this->call( 'blob', $sha ); return $this->blobs[ $sha ] ?? throw new \RuntimeException( 'Missing blob fixture.' ); }
		public static function retryDelay( int $attempt, int $minimum = 0 ): int { return max( $minimum, min( 3600, 60 * 2 ** max( 0, $attempt - 1 ) ) ); }
	}
}

namespace {
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Sources/Worker.php';
	use AISkillsHub\Core\Sources\Worker;
	use AISkillsHub\Core\Sources\ImportStore;
	use AISkillsHub\Core\Sources\GitHubClient;
	use AISkillsHub\Core\Sources\GitHubException;
	$assertions = 0;
	set_error_handler( static function ( int $severity, string $message ): never { throw new RuntimeException( $message ); } );
	function check( bool $ok, string $message ): void { global $assertions; if ( ! $ok ) { throw new RuntimeException( $message ); } ++$assertions; }
	function rawSkill( string $name, string $body = 'Read the documented input.' ): string { return "---\nname: $name\ndescription: A real source description.\nlicense: MIT\n---\n$body"; }
	function blobSha( string $body ): string { return sha1( 'blob ' . strlen( $body ) . "\0" . $body ); }
	function treeResponse( string $sha, array $entries, bool $truncated = false ): array { return array( 'sha' => $sha, 'truncated' => $truncated, 'tree' => $entries ); }
	function entry( string $path, string $sha, string $type = 'blob', ?int $size = null ): array { return array( 'path' => $path, 'sha' => $sha, 'type' => $type, 'mode' => 'tree' === $type ? '040000' : '100644', 'size' => $size ); }
	function fixture( array $files = array(), string $kind = 'sync' ): array {
		$store = new ImportStore( $kind ); $client = new GitHubClient(); $entries = array();
		foreach ( $files as $name => $raw ) { $sha = blobSha( $raw ); $client->blobs[ $sha ] = $raw; $entries[] = entry( $name, sha1( $name ), 'tree' ); $entries[] = entry( $name . '/SKILL.md', $sha, 'blob', strlen( $raw ) ); }
		$client->trees[ $client->root . ':1' ] = treeResponse( $client->root, $entries );
		return array( $store, $client, new Worker( $store, static fn(): GitHubClient => $client ) );
	}
	function untilStage( Worker $worker, ImportStore $store, string $stage ): void { for ( $i = 0; $i < 30; ++$i ) { if ( $stage === ( $store->job['state']['stage'] ?? '' ) ) { return; } $worker->run(); } throw new RuntimeException( 'Stage was not reached: ' . $stage ); }
	function complete( Worker $worker, ImportStore $store ): void { for ( $i = 0; $i < 80; ++$i ) { if ( in_array( $store->job['status'], array( 'complete', 'incomplete', 'failed', 'cancelled' ), true ) ) { return; } $worker->run(); } throw new RuntimeException( 'Fixture did not terminate.' ); }
	function countCalls( GitHubClient $client, string $method ): int { return count( array_filter( $client->calls, static fn( array $call ): bool => $call[0] === $method ) ); }

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ), 'beta' => rawSkill( 'beta' ) ) );
	complete( $worker, $store );
	check( 'complete' === $store->job['status'] && $store->inventoryCalls === 1, 'Only a completed inventory reaches reconciliation.' );
	check( $store->job['state']['stats']['new'] === 2 && count( $store->recordCalls ) === 2, 'One repository discovers multiple stable source paths.' );
	check( array_column( $store->recordCalls, 'path' ) === array( 'alpha/SKILL.md', 'beta/SKILL.md' ), 'Records use full relative path, not Skill names as identity.' );
	check( $store->recordCalls[0]['raw'] === rawSkill( 'alpha' ) && $store->recordCalls[0]['commitSha'] === $client->head && $store->recordCalls[0]['fileSha'] === blobSha( rawSkill( 'alpha' ) ), 'Original source and both SHAs are passed to the persistence boundary.' );
	check( $store->recordCalls[0]['inspection']['status'] === 'requires_review' && ! $store->recordCalls[0]['inspection']['scripts_executed'], 'Worker supplies mandatory review evidence, never an approval.' );
	check( $store->job['state']['inventory_complete'] && $store->job['state']['files_complete'], 'Inventory and file completion are separate recorded facts.' );
	$before = countCalls( $client, 'blob' ); $store->resetJob(); complete( $worker, $store );
	check( ! empty( $store->job['state']['unchanged_head'] ) && countCalls( $client, 'blob' ) === $before, 'A previous complete identical HEAD skips blob requests.' );
	check( $store->inventoryCalls === 1 && count( $store->recordCalls ) === 2, 'Same HEAD does not infer another deletion or create another Skill.' );
	$store->resetJob( 'scan' ); complete( $worker, $store );
	check( countCalls( $client, 'blob' ) === $before + 2 && $store->job['state']['stats']['unchanged'] === 2, 'Explicit scan revalidates identical source, persistence classifies unchanged.' );
	check( count( $store->sources ) === 2, 'Repeated scan has no new source identities in the fixture contract.' );
	$client->head = str_repeat( 'e', 40 ); $store->resetJob(); $before = countCalls( $client, 'blob' ); complete( $worker, $store );
	check( $store->job['state']['stats']['unchanged'] === 2 && countCalls( $client, 'blob' ) === $before, 'Changed commit with identical file SHAs skips redundant blobs.' );
	check( $store->repo['last_sync_commit'] === $client->head, 'Only completed sync updates the trusted HEAD baseline.' );
	$newRaw = rawSkill( 'alpha', 'Updated upstream text; never replace manually edited translations.' ); $newSha = blobSha( $newRaw );
	$client->blobs[ $newSha ] = $newRaw; $client->trees[ $client->root . ':1' ]['tree'][1] = entry( 'alpha/SKILL.md', $newSha, 'blob', strlen( $newRaw ) );
	$client->head = str_repeat( 'f', 40 ); $store->resetJob(); complete( $worker, $store );
	check( $store->job['state']['stats']['updated'] === 1 && $store->sources['alpha/SKILL.md']['skill_id'] === 1, 'Changed file is sent through the same source binding.' );
	check( $store->recordCalls[ count( $store->recordCalls ) - 2 ]['raw'] === $newRaw, 'New source snapshot reaches the staging boundary; translator/editor behavior is tested separately.' );
	$client->head = str_repeat( '9', 40 );
	$client->trees[ $client->root . ':1' ]['tree'][] = entry( 'alpha/run.sh', str_repeat( '8', 40 ), 'blob', 100 );
	$store->resetJob(); $before = countCalls( $client, 'blob' ); complete( $worker, $store );
	$alphaInspection = $store->recordCalls[ count( $store->recordCalls ) - 2 ]['inspection'];
	$betaInspection = end( $store->recordCalls )['inspection'];
	check( in_array( 'script_or_executable', array_column( $alphaInspection['findings'], 'code' ), true ) && countCalls( $client, 'blob' ) === $before, 'Same file SHA reuses source text but still inspects newly added neighboring scripts.' );
	check( ! in_array( 'script_or_executable', array_column( $betaInspection['findings'], 'code' ), true ), 'An unrelated Skill is not labeled with another directory’s script finding.' );
	check( $alphaInspection['inspection_commit_sha'] === $client->head, 'Risk evidence identifies the currently inspected commit.' );
	$client->license = 'Apache-2.0'; $store->resetJob(); $beforeTrees = countCalls( $client, 'tree' ); $beforeBaselines = count( $store->baselineChecks ); complete( $worker, $store );
	check( empty( $store->job['state']['unchanged_head'] ) && countCalls( $client, 'tree' ) === $beforeTrees + 1, 'Changed repository license prevents a same-HEAD shortcut.' );
	check( $store->baselineIsReusable && count( $store->baselineChecks ) === $beforeBaselines, 'A reusable baseline cannot override the changed-license rescan requirement.' );
	check( in_array( 'license_mismatch', array_column( end( $store->recordCalls )['inspection']['findings'], 'code' ), true ), 'Current license declarations are reviewed even when source text is unchanged.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	complete( $worker, $store ); $client->license = 'Apache-2.0'; $store->resetJob();
	untilStage( $worker, $store, 'commit' );
	check( 'MIT' === $store->repo['license_spdx'] && $store->job['state']['license_changed'], 'Fetching a new license does not promote the successful sync baseline.' );
	$store->failRecord = 5; complete( $worker, $store );
	check( 'failed' === $store->job['status'] && 'MIT' === $store->repo['license_spdx'], 'Failed source persistence keeps the last successfully scanned license.' );
	$store->resetJob(); untilStage( $worker, $store, 'commit' ); $worker->run();
	check( 'tree' === $store->job['state']['stage'] && $store->job['state']['license_changed'] && empty( $store->job['state']['unchanged_head'] ), 'A later same-commit task still detects the uncompleted license change.' );
	complete( $worker, $store );
	check( 'complete' === $store->job['status'] && 'Apache-2.0' === $store->repo['license_spdx'], 'Only successful finalization promotes the inspected license baseline.' );

	// A completed A baseline is no longer reusable after a newer partial B job modified staged sources.
	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ), 'beta' => rawSkill( 'beta' ) ) );
	$commitA = $client->head; $treeA = $client->trees[ $client->root . ':1' ]; complete( $worker, $store );
	$rawB = rawSkill( 'alpha', 'Changed only in commit B.' ); $shaB = blobSha( $rawB );
	$invalidB = "---\nname: beta\n---\nMissing description"; $invalidShaB = blobSha( $invalidB );
	$client->head = str_repeat( 'b', 40 ); $client->blobs[ $shaB ] = $rawB; $client->blobs[ $invalidShaB ] = $invalidB;
	$client->trees[ $client->root . ':1' ]['tree'][1] = entry( 'alpha/SKILL.md', $shaB, 'blob', strlen( $rawB ) );
	$client->trees[ $client->root . ':1' ]['tree'][3] = entry( 'beta/SKILL.md', $invalidShaB, 'blob', strlen( $invalidB ) );
	$store->resetJob(); complete( $worker, $store );
	check( 'incomplete' === $store->job['status'] && $store->repo['last_sync_commit'] === $commitA && $store->sources['alpha/SKILL.md']['file_sha'] === $shaB, 'Fixture preserves A as the trusted baseline while B partially changes staged source.' );
	$client->head = $commitA; $client->trees[ $client->root . ':1' ] = $treeA; $store->baselineIsReusable = false; $store->resetJob();
	untilStage( $worker, $store, 'commit' ); $beforeFinishes = count( $store->finishes ); $worker->run();
	check( 'tree' === $store->job['state']['stage'] && 'queued' === $store->job['status'] && empty( $store->job['state']['unchanged_head'] ), 'Returning to A after partial B must traverse the tree when the store rejects baseline reuse.' );
	check( count( $store->finishes ) === $beforeFinishes && end( $store->baselineChecks ) === $commitA, 'Rejected reusable baseline never takes the success shortcut.' );
	complete( $worker, $store );
	check( 'complete' === $store->job['status'] && $store->sources['alpha/SKILL.md']['file_sha'] === blobSha( rawSkill( 'alpha' ) ), 'The required recovery scan restores staged source to actual A before success.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	$subtree = sha1( 'alpha' ); $blob = blobSha( rawSkill( 'alpha' ) );
	$client->trees[ $client->root . ':1' ] = treeResponse( $client->root, array( entry( 'phantom/SKILL.md', str_repeat( 'a', 40 ) ) ), true );
	$client->trees[ $client->root . ':0' ] = treeResponse( $client->root, array( entry( 'alpha', $subtree, 'tree' ) ) );
	$client->trees[ $subtree . ':0' ] = treeResponse( $subtree, array( entry( 'SKILL.md', $blob, 'blob', 100 ) ) );
	untilStage( $worker, $store, 'tree' ); $worker->run();
	check( ! $store->job['state']['inventory_complete'] && 'walk' === $store->job['state']['discovery']['phase'], 'A truncated response never marks the inventory complete.' );
	complete( $worker, $store );
	check( $store->job['status'] === 'complete' && countCalls( $client, 'tree' ) === 3, 'Direct child-tree fallback completes before processing files.' );
	check( array_column( $store->recordCalls, 'path' ) === array( 'alpha/SKILL.md' ), 'Discarded partial recursive paths never become Skills.' );
	check( in_array( 'recursive_tree_truncated', array_column( $store->logs, 'code' ), true ), 'Truncation is recorded in sanitized logs.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	$client->failures[ 'tree:' . $client->root . ':1' ] = array( new GitHubException( 'response_too_large' ) );
	$client->trees[ $client->root . ':0' ] = treeResponse( $client->root, array( entry( 'alpha', sha1( 'alpha' ), 'tree' ) ) );
	$client->trees[ sha1( 'alpha' ) . ':0' ] = treeResponse( sha1( 'alpha' ), array( entry( 'SKILL.md', blobSha( rawSkill( 'alpha' ) ), 'blob', 100 ) ) );
	complete( $worker, $store );
	check( $store->job['status'] === 'complete' && in_array( 'recursive_tree_response_limit', array_column( $store->logs, 'code' ), true ), 'Oversized recursive HTTP responses use the same complete direct walk.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => "---\nname: alpha\n---\nMissing description", 'beta' => rawSkill( 'beta' ) ) );
	$store->sources['retained/SKILL.md'] = array( 'skill_id' => 90, 'file_sha' => str_repeat( '1', 40 ) ); complete( $worker, $store );
	check( $store->job['status'] === 'incomplete' && $store->inventoryCalls === 0, 'Invalid YAML prevents all deletion inference.' );
	check( count( $store->recordCalls ) === 1 && $store->recordCalls[0]['path'] === 'beta/SKILL.md', 'One parse failure does not stop other valid files.' );
	check( $store->job['state']['errors'] === 1 && $store->job['state']['cursor'] === 2 && '' === $store->repo['last_sync_commit'], 'Failed scan does not advance trusted sync HEAD.' );
	check( in_array( 'frontmatter_required_field_missing', array_column( $store->logs, 'code' ), true ), 'Parser failure is a fixed diagnostic, not raw external content.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	$client->trees[ $client->root . ':1' ]['tree'][1]['size'] = 524289; complete( $worker, $store );
	check( 'incomplete' === $store->job['status'] && 0 === countCalls( $client, 'blob' ) && 0 === $store->inventoryCalls, 'Oversized files are recorded as incomplete without a blob fetch or deletion inference.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	$blob = blobSha( rawSkill( 'alpha' ) ); $client->failures[ 'blob:' . $blob ] = array( new GitHubException( 'rate_limited', true, 900 ) );
	untilStage( $worker, $store, 'files' ); $worker->run();
	check( $store->job['status'] === 'retry' && $store->job['state']['cursor'] === 0 && ! $store->finishes, 'Rate limit retains the file cursor and never finishes early.' );
	check( $store->retries[0]['delay'] >= 900 && $store->retries[0]['code'] === 'rate_limited', 'Worker respects server minimum retry delay.' );
	complete( $worker, $store );
	check( 'complete' === $store->job['status'] && count( $store->recordCalls ) === 1 && countCalls( $client, 'blob' ) === 2, 'Retried blob is eventually staged once.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	$client->failures['repository:'] = array_fill( 0, 5, new GitHubException( 'transport_error', true ) ); complete( $worker, $store );
	check( 'failed' === $store->job['status'] && ! $store->finishes && $store->inventoryCalls === 0, 'Repeated API transport failures exhaust retry budget without success.' );
	check( array_column( $store->retries, 'delay' ) === array( 60, 120, 240, 480, 960 ), 'Attempts are passed to exponential retry scheduling.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	$client->failures['repository:'] = array( new GitHubException( 'private_repository' ) ); complete( $worker, $store );
	check( 'incomplete' === $store->job['status'] && ! $store->recordCalls && ! $store->retries, 'Private or unauthorized repository response is not retried as public content.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	untilStage( $worker, $store, 'tree' ); $client->repositoryId = 101; $worker->run();
	check( 'incomplete' === $store->job['status'] && 0 === countCalls( $client, 'tree' ) && 0 === countCalls( $client, 'blob' ), 'Reused owner/name with a different GitHub ID stops before consuming replacement repository content.' );
	check( in_array( 'repository_identity_changed', array_column( $store->logs, 'code' ), true ), 'Repository identity drift is logged without raw response details.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	$client->trees[ $client->root . ':1' ] = treeResponse( $client->root, array(), true );
	$client->trees[ $client->root . ':0' ] = treeResponse( $client->root, array(), true );
	complete( $worker, $store );
	check( 'incomplete' === $store->job['status'] && ! $store->retries && 0 === $store->inventoryCalls, 'A truncated nonrecursive tree is an explicit incomplete scan, never a successful empty inventory.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	untilStage( $worker, $store, 'files' ); $store->failCheckpoint = 1; $worker->run();
	check( count( $store->recordCalls ) === 1 && 'retry' === $store->job['status'] && ! $store->finishes, 'A source write followed by checkpoint failure is not a completed job.' );
	check( $store->job['state']['cursor'] === 1 && $store->inventoryCalls === 0, 'Successful source write can be recovered from retry state without losing the next cursor.' );
	complete( $worker, $store );
	check( 'complete' === $store->job['status'] && count( $store->recordCalls ) === 1, 'Recovery finishes only after the durable inventory stage.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	untilStage( $worker, $store, 'files' ); $store->failCheckpoint = 1; $store->failRetry = 1; $worker->run();
	check( 'running' === $store->job['status'] && $store->job['state']['cursor'] === 0 && ! $store->finishes, 'If both progress writes fail, the old durable cursor remains recoverable by lease expiry.' );
	complete( $worker, $store );
	check( 'complete' === $store->job['status'] && count( $store->sources ) === 1 && count( $store->recordCalls ) === 2 && countCalls( $client, 'blob' ) === 1, 'Lease recovery reuses raw source, rechecks it and avoids duplicate source identities.' );

	list( $store, $client, $worker ) = fixture( array( 'alpha' => rawSkill( 'alpha' ) ) );
	untilStage( $worker, $store, 'files' ); $store->failRecord = 1; $worker->run();
	check( 'retry' === $store->job['status'] && $store->job['state']['cursor'] === 0 && ! $store->recordCalls, 'Failed source transaction retains the same file cursor.' );
	complete( $worker, $store ); check( 'complete' === $store->job['status'] && count( $store->recordCalls ) === 1, 'Source transaction retry recovers.' );

	list( $store, $client, $worker ) = fixture(); $store->repo['is_archived'] = 1; complete( $worker, $store );
	check( $store->job['status'] === 'cancelled' && ! $client->calls, 'Archived repository cannot initiate any GitHub request.' );
	list( $store, $client, $worker ) = fixture(); $worker->run();
	check( countCalls( $client, 'repository' ) === 1 && count( $client->calls ) === 1 && 'commit' === $store->job['state']['stage'], 'Each invocation makes bounded progress instead of scanning synchronously.' );
	complete( $worker, $store ); check( 'complete' === $store->job['status'] && $store->inventoryCalls === 1, 'An actually empty complete tree is a valid inventory.' );
	restore_error_handler();
	echo 'PASS: ' . $assertions . " isolated import-worker workflow assertions (in-memory store/API fixtures; no MySQL/WordPress/live GitHub).\n";
}
