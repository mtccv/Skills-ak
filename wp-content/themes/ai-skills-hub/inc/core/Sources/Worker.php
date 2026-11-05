<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

defined( 'ABSPATH' ) || exit;

/** One recoverable stage per Cron invocation; repository text never becomes executable instructions. */
final class Worker {
	private \Closure $clientFactory;
	public function __construct( private ImportStore $store, ?callable $clientFactory = null ) {
		$this->clientFactory = null === $clientFactory ? static fn(): GitHubClient => new GitHubClient() : \Closure::fromCallable( $clientFactory );
	}

	public static function initialState(): array {
		return array( 'stage' => 'repository', 'inventory_complete' => false, 'files_complete' => false, 'errors' => 0, 'stats' => array( 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ), 'cursor' => 0 );
	}

	public function run(): void {
		$job = $this->store->claim();
		if ( ! $job ) { return; }
		$id = (int) $job['id']; $token = $job['lease_token'];
		$state = $job['state'] ?: self::initialState();
		try {
			$repository = $this->store->repository( (int) $job['repository_id'] );
			if ( ! $repository || ! empty( $repository['is_archived'] ) ) { $this->store->finish( $id, $token, $state, 'cancelled' ); return; }
			$client = ( $this->clientFactory )();
			$owner = $repository['owner']; $name = $repository['name'];
			switch ( $state['stage'] ?? '' ) {
				case 'repository':
					$metadata = $client->repository( $owner, $name );
					$state['license_changed'] = (string) ( $repository['license_spdx'] ?? '' ) !== (string) ( $metadata['license'] ?? '' );
					$this->store->updateRepository( $id, $token, $metadata );
					$state['repository'] = $metadata;
					$state['stage'] = 'commit';
					break;
				case 'commit':
					$this->verifyIdentity( $client, $repository, $state );
					$commit = $client->commit( $owner, $name, $state['repository']['default_branch'] );
					$state['commit_sha'] = $commit['sha'];
					if ( 'sync' === $job['kind'] && empty( $state['license_changed'] ) && ! empty( $repository['last_sync_commit'] ) && hash_equals( $repository['last_sync_commit'], $commit['sha'] ) && $this->store->baselineReusable( $id, $token, $commit['sha'] ) ) {
						$state['unchanged_head'] = true; $state['inventory_complete'] = true; $state['files_complete'] = true;
						$this->store->log( $id, $token, 'unchanged_head', '', 'info' );
						$this->store->finish( $id, $token, $state, 'complete' ); return;
					}
					$state['discovery'] = Discovery::begin( $commit['tree_sha'] );
					$state['stage'] = 'tree';
					break;
				case 'tree':
					$this->verifyIdentity( $client, $repository, $state );
					$request = Discovery::request( $state['discovery'] );
					if ( null === $request ) { throw new \LogicException( 'Invalid discovery cursor.' ); }
					try {
						$tree = $client->tree( $owner, $name, $request['sha'], $request['recursive'] );
						$state['discovery'] = Discovery::accept( $state['discovery'], $tree, $request );
						if ( $tree['truncated'] ) { $this->store->log( $id, $token, 'recursive_tree_truncated' ); }
					} catch ( GitHubException $error ) {
						if ( 'response_too_large' !== $error->codeName || ! $request['recursive'] ) { throw $error; }
						$state['discovery'] = Discovery::fallback( $state['discovery'] );
						$this->store->log( $id, $token, 'recursive_tree_response_limit' );
					}
					if ( Discovery::complete( $state['discovery'] ) ) {
						$state['inventory_complete'] = true;
						$state['inventory_paths'] = array_column( Discovery::candidates( $state['discovery'] ), 'path' );
						$state['stage'] = 'files';
					}
					break;
				case 'files':
					$candidates = Discovery::candidates( $state['discovery'] );
					$entry = $candidates[ $state['cursor'] ] ?? null;
					if ( null === $entry ) { $state['files_complete'] = true; $state['stage'] = 'finalize'; break; }
					$path = $entry['path'];
					$this->store->seen( $id, $token, $path );
					if ( (int) ( $entry['size'] ?? 0 ) > 524288 ) {
						$this->fileError( $id, $token, $state, 'skill_too_large', $path );
						break;
					}
					$previous = $this->store->source( (int) $job['repository_id'], $path );
					$raw = null; $freshBlob = false;
					if ( 'sync' === $job['kind'] && $previous && hash_equals( (string) ( $previous['file_sha'] ?? '' ), $entry['sha'] ) ) {
						$raw = $this->store->sourceContent( (int) $job['repository_id'], $path, $entry['sha'] );
					}
					if ( null === $raw ) {
						$this->verifyIdentity( $client, $repository, $state );
						try { $raw = $client->blob( $owner, $name, $entry['sha'] ); $freshBlob = true; }
						catch ( GitHubException $error ) {
							if ( $error->retryable || ! in_array( $error->codeName, array( 'file_too_large', 'invalid_blob_response', 'blob_sha_mismatch', 'response_too_large' ), true ) ) { throw $error; }
							$this->fileError( $id, $token, $state, $error->codeName, $path );
							break;
						}
					}
					try {
						$parsed = Frontmatter::parse( $raw, $path );
						$directory = dirname( $path );
						$nearby = array_values( array_filter( Discovery::entries( $state['discovery'] ), static fn( array $item ): bool => '.' === $directory || str_starts_with( $item['path'], $directory . '/' ) ) );
						$inspection = RiskInspector::inspect( $raw, $parsed, $nearby, (string) ( $state['repository']['license'] ?? '' ) );
						$inspection['inspection_commit_sha'] = $state['commit_sha'];
					} catch ( \InvalidArgumentException $error ) {
						$code = preg_match( '/^(?:frontmatter|skill)_[a-z_]{1,64}$/D', $error->getMessage() ) ? $error->getMessage() : 'invalid_skill_frontmatter';
						$this->fileError( $id, $token, $state, $code, $path );
						break;
					}
					$result = $this->store->record( $id, $token, $path, $entry['sha'], $state['commit_sha'], $raw, $parsed, $inspection, $freshBlob );
					++$state['stats'][ $result['change'] ]; ++$state['cursor'];
					break;
				case 'finalize':
					if ( 0 === $state['errors'] && $state['inventory_complete'] && $state['files_complete'] ) {
						$state['stats']['missing'] = $this->store->completeInventory( $id, $token, $state['inventory_paths'], $state['commit_sha'] );
						$this->store->finish( $id, $token, $state, 'complete' );
					} else { $this->store->finish( $id, $token, $state, 'incomplete' ); }
					return;
				default: throw new \LogicException( 'Invalid import stage.' );
			}
			$this->store->checkpoint( $id, $token, $state, 60 );
		} catch ( GitHubException $error ) {
			try {
				$this->store->log( $id, $token, $error->codeName, '', 'error' );
				if ( $error->retryable ) { $this->store->retry( $id, $token, $state, $error->codeName, GitHubClient::retryDelay( (int) ( $job['attempts'] ?? 0 ) + 1, $error->retryAfter ) ); }
				else { ++$state['errors']; $this->store->finish( $id, $token, $state, 'incomplete' ); }
			} catch ( \Throwable $storageError ) { /* Lease expiry / storage failure leaves a recoverable job, never a success. */ }
		} catch ( \InvalidArgumentException | \DomainException $error ) {
			$code = preg_match( '/^discovery_[a-z_]{1,64}$/D', $error->getMessage() ) ? $error->getMessage() : 'invalid_or_conflicting_source';
			try { ++$state['errors']; $this->store->log( $id, $token, $code, '', 'error' ); $this->store->finish( $id, $token, $state, 'incomplete' ); }
			catch ( \Throwable $storageError ) { /* A revoked lease cannot mutate the task. */ }
		} catch ( \Throwable $error ) {
			try {
				if ( $error instanceof \RuntimeException && preg_match( '/^discovery_[a-z_]{1,64}$/D', $error->getMessage() ) ) {
					++$state['errors']; $this->store->log( $id, $token, $error->getMessage(), '', 'error' ); $this->store->finish( $id, $token, $state, 'incomplete' ); return;
				}
				$this->store->log( $id, $token, 'import_stage_failed', '', 'error' );
				$this->store->retry( $id, $token, $state, 'import_stage_failed', GitHubClient::retryDelay( (int) ( $job['attempts'] ?? 0 ) + 1 ) );
			} catch ( \Throwable $storageError ) { /* The bounded lease will be reclaimed; never report a failed write as complete. */ }
		}
	}

	private function fileError( int $id, string $token, array &$state, string $code, string $path ): void {
		$this->store->log( $id, $token, $code, $path, 'error' );
		++$state['errors']; ++$state['cursor'];
	}

	private function verifyIdentity( GitHubClient $client, array $repository, array $state ): void {
		$current = $client->repository( $repository['owner'], $repository['name'] );
		if ( (int) $current['id'] !== (int) ( $state['repository']['id'] ?? 0 ) || (int) $current['id'] !== (int) ( $repository['github_id'] ?? 0 ) ) {
			throw new GitHubException( 'repository_identity_changed' );
		}
	}
}
