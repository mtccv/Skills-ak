<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Fixed-endpoint GitHub REST reader. All remote text remains untrusted data. */
final class GitHubClient {
	public const MAX_FILE_BYTES = 524288;
	public const MAX_TREE_BYTES = 4194304;
	public const TIMEOUT = 15;
	private const MAX_BLOB_RESPONSE = 786432;
	private readonly \Closure $transport;
	private readonly string $token;
	private readonly string $rateKey;
	private array $publicRepositories = array();

	/** The optional transport is a trusted test seam, not a request-facing callback. */
	public function __construct( ?callable $transport = null ) {
		$this->token = self::token();
		$this->rateKey = 'aish_github_rate_' . substr( hash( 'sha256', $this->token ?: 'anonymous' ), 0, 24 );
		$this->transport = null === $transport ? static fn( string $url, array $args ): mixed => wp_safe_remote_get( $url, $args ) : \Closure::fromCallable( $transport );
	}

	private static function token(): string {
		$value = defined( 'AISH_GITHUB_TOKEN' ) ? constant( 'AISH_GITHUB_TOKEN' ) : getenv( 'AISH_GITHUB_TOKEN' );
		if ( false === $value || null === $value || '' === $value ) { return ''; }
		if ( ! is_string( $value ) || strlen( $value ) > 4096 || ! preg_match( '/^[A-Za-z0-9_.-]+$/D', $value ) ) { throw new GitHubException( 'invalid_token_configuration' ); }
		return $value;
	}

	public static function tokenConfigured(): bool {
		$value = defined( 'AISH_GITHUB_TOKEN' ) ? constant( 'AISH_GITHUB_TOKEN' ) : getenv( 'AISH_GITHUB_TOKEN' );
		return is_string( $value ) && '' !== $value;
	}

	public static function repositoryIdentity( string $url ): array {
		Input::utf8( $url, 2048 );
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'github.com' !== strtolower( $parts['host'] ?? '' ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { throw new \InvalidArgumentException( 'Use a public HTTPS GitHub repository URL.' ); }
		if ( ! preg_match( '#^/([^/]+)/([^/]+)/?$#D', $parts['path'] ?? '', $matches ) ) { throw new \InvalidArgumentException( 'Use a repository root URL.' ); }
		$name = str_ends_with( $matches[2], '.git' ) ? substr( $matches[2], 0, -4 ) : $matches[2];
		list( $owner, $name ) = Input::repository( $matches[1], $name );
		return array( 'owner' => $owner, 'name' => $name );
	}

	private static function identity( string $owner, string $name ): string {
		list( $owner, $name ) = Input::repository( $owner, $name );
		return $owner . '/' . $name;
	}

	public static function sha( mixed $sha ): string {
		if ( ! is_string( $sha ) || ! preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $sha ) ) { throw new \InvalidArgumentException( 'Invalid Git object SHA.' ); }
		return $sha;
	}

	private static function branch( mixed $ref ): string {
		$ref = Input::utf8( $ref, 255 );
		if ( '' === $ref || '@' === $ref || str_starts_with( $ref, '-' ) || preg_match( '/[\x00-\x20\x7f~^:?*\[\\\\]/', $ref ) || str_contains( $ref, '..' ) || str_contains( $ref, '@{' ) || str_contains( $ref, '//' ) || str_ends_with( $ref, '.' ) ) { throw new \InvalidArgumentException( 'Invalid Git branch.' ); }
		foreach ( explode( '/', $ref ) as $part ) { if ( '' === $part || str_starts_with( $part, '.' ) || str_ends_with( $part, '.lock' ) ) { throw new \InvalidArgumentException( 'Invalid Git branch.' ); } }
		return $ref;
	}

	public function repository( string $owner, string $name ): array {
		$identity = self::identity( $owner, $name );
		$data = $this->request( '/repos/' . $identity, 131072 );
		if ( false !== ( $data['private'] ?? null ) || ( isset( $data['visibility'] ) && 'public' !== $data['visibility'] ) ) { throw new GitHubException( 'repository_not_public' ); }
		if ( true === ( $data['disabled'] ?? false ) ) { throw new GitHubException( 'repository_disabled' ); }
		try {
			$id = filter_var( $data['id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $id || ! is_string( $data['full_name'] ?? null ) || strtolower( $data['full_name'] ) !== $identity || ! is_bool( $data['archived'] ?? null ) ) { throw new \InvalidArgumentException(); }
			$branch = self::branch( $data['default_branch'] ?? null );
		} catch ( \InvalidArgumentException $error ) { throw new GitHubException( 'invalid_repository_response' ); }
		$license = is_array( $data['license'] ?? null ) ? ( $data['license']['spdx_id'] ?? '' ) : '';
		if ( ! is_string( $license ) || ! preg_match( '/^[A-Za-z0-9.+-]{1,100}$/D', $license ) || in_array( $license, array( 'NOASSERTION', 'NONE' ), true ) ) { $license = 'unknown'; }
		list( $owner, $name ) = explode( '/', $identity, 2 );
		$this->publicRepositories[ $identity ] = true;
		return array( 'id' => $id, 'owner' => $owner, 'name' => $name, 'full_name' => $identity, 'default_branch' => $branch, 'license' => $license, 'archived' => $data['archived'], 'private' => false );
	}

	private function publicIdentity( string $owner, string $name ): string {
		$identity = self::identity( $owner, $name );
		if ( empty( $this->publicRepositories[ $identity ] ) ) { $this->repository( $owner, $name ); }
		return $identity;
	}

	/** Branch API avoids the huge file/patch array returned by REST /commits/{ref}. */
	public function commit( string $owner, string $name, string $ref ): array {
		$isSha = 1 === preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $ref );
		$ref = $isSha ? self::sha( $ref ) : self::branch( $ref );
		$identity = $this->publicIdentity( $owner, $name );
		$data = $this->request( '/repos/' . $identity . ( $isSha ? '/git/commits/' : '/branches/' ) . rawurlencode( $ref ), 131072 );
		$commit = $isSha ? $data : ( $data['commit'] ?? null );
		try {
			if ( ! is_array( $commit ) ) { throw new \InvalidArgumentException(); }
			$sha = self::sha( $commit['sha'] ?? null );
			$tree = self::sha( $isSha ? ( $commit['tree']['sha'] ?? null ) : ( $commit['commit']['tree']['sha'] ?? null ) );
			if ( $isSha && ! hash_equals( $ref, $sha ) ) { throw new \InvalidArgumentException(); }
		} catch ( \InvalidArgumentException $error ) { throw new GitHubException( 'invalid_commit_response' ); }
		return array( 'sha' => $sha, 'tree_sha' => $tree );
	}

	public function tree( string $owner, string $name, string $sha, bool $recursive = true ): array {
		$sha = self::sha( $sha );
		$identity = $this->publicIdentity( $owner, $name );
		$data = $this->request( '/repos/' . $identity . '/git/trees/' . $sha . ( $recursive ? '?recursive=1' : '' ), self::MAX_TREE_BYTES );
		try {
			if ( ! hash_equals( $sha, self::sha( $data['sha'] ?? null ) ) || ! is_bool( $data['truncated'] ?? null ) || ! is_array( $data['tree'] ?? null ) || ! array_is_list( $data['tree'] ) || count( $data['tree'] ) > 50000 ) { throw new \InvalidArgumentException(); }
			$entries = array();
			foreach ( $data['tree'] as $entry ) {
				if ( ! is_array( $entry ) ) { throw new \InvalidArgumentException(); }
				$path = Input::utf8( $entry['path'] ?? null, 4096 );
				if ( '' === $path || ! in_array( $entry['type'] ?? null, array( 'tree', 'blob', 'commit' ), true ) || ! in_array( $entry['mode'] ?? null, array( '040000', '100644', '100755', '120000', '160000' ), true ) ) { throw new \InvalidArgumentException(); }
				$item = array( 'path' => $path, 'type' => $entry['type'], 'mode' => $entry['mode'], 'sha' => self::sha( $entry['sha'] ?? null ) );
				if ( 'blob' === $item['type'] ) {
					if ( ! is_int( $entry['size'] ?? null ) || $entry['size'] < 0 ) { throw new \InvalidArgumentException(); }
					$item['size'] = $entry['size'];
				}
				$entries[] = $item;
			}
		} catch ( \InvalidArgumentException $error ) { throw new GitHubException( 'invalid_tree_response' ); }
		return array( 'sha' => $sha, 'truncated' => $data['truncated'], 'tree' => $entries );
	}

	/** Only pinned git blobs are downloaded; response URLs and download_url are never followed. */
	public function blob( string $owner, string $name, string $sha ): string {
		$sha = self::sha( $sha );
		$identity = $this->publicIdentity( $owner, $name );
		$data = $this->request( '/repos/' . $identity . '/git/blobs/' . $sha, self::MAX_BLOB_RESPONSE );
		if ( ! is_int( $data['size'] ?? null ) || $data['size'] < 0 || $data['size'] > self::MAX_FILE_BYTES ) { throw new GitHubException( 'file_too_large' ); }
		if ( ( $data['sha'] ?? null ) !== $sha || 'base64' !== ( $data['encoding'] ?? null ) || ! is_string( $data['content'] ?? null ) ) { throw new GitHubException( 'invalid_blob_response' ); }
		$raw = base64_decode( str_replace( array( "\r", "\n" ), '', $data['content'] ), true );
		if ( false === $raw || strlen( $raw ) !== $data['size'] ) { throw new GitHubException( 'invalid_blob_response' ); }
		$actual = hash( 64 === strlen( $sha ) ? 'sha256' : 'sha1', 'blob ' . strlen( $raw ) . "\0" . $raw );
		if ( ! hash_equals( $sha, $actual ) ) { throw new GitHubException( 'blob_sha_mismatch' ); }
		return $raw;
	}

	/** No network request, and no token or request header is returned. */
	public static function rateStatus(): array {
		$result = array( 'remaining' => null, 'reset' => null, 'cooldown_until' => 0, 'status' => 0, 'updated_at' => 0 );
		try { $token = self::token(); } catch ( GitHubException $error ) { return $result + array( 'configuration_error' => true ); }
		$stored = get_transient( 'aish_github_rate_' . substr( hash( 'sha256', $token ?: 'anonymous' ), 0, 24 ) );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( array_keys( $result ) as $key ) { if ( isset( $stored[ $key ] ) && is_int( $stored[ $key ] ) && $stored[ $key ] >= 0 ) { $result[ $key ] = $stored[ $key ]; } }
		return $result;
	}

	public static function retryDelay( int $attempt, int $minimum = 0 ): int {
		return min( 86400, max( 1, $minimum, min( 3600, 30 * ( 2 ** max( 0, min( 7, $attempt - 1 ) ) ) ) + random_int( 0, 30 ) ) );
	}

	private static function header( mixed $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_scalar( $value ) && strlen( (string) $value ) <= 100 ? (string) $value : '';
	}

	private function request( string $path, int $limit ): array {
		// Paths are constructed only by the typed methods above. Defense in depth before transport.
		if ( ! preg_match( '#^/repos/[a-z0-9-]+/[a-z0-9_.-]+(?:/(?:branches/[A-Za-z0-9_.%~-]+|git/(?:commits|trees|blobs)/[a-f0-9]{40,64}))?(?:\?recursive=1)?$#D', $path ) ) { throw new GitHubException( 'invalid_api_path' ); }
		$rate = self::rateStatus();
		if ( $rate['cooldown_until'] > time() ) { throw new GitHubException( 'rate_limited', true, $rate['cooldown_until'] - time(), $rate ); }
		$headers = array( 'Accept' => 'application/vnd.github+json', 'Accept-Encoding' => 'identity', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'AI-Skills-Hub/0.6' );
		if ( '' !== $this->token ) { $headers['Authorization'] = 'Bearer ' . $this->token; }
		$args = array( 'timeout' => self::TIMEOUT, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true, 'blocking' => true, 'headers' => $headers, 'limit_response_size' => $limit + 1, 'decompress' => false );
		try { $response = ( $this->transport )( 'https://api.github.com' . $path, $args ); }
		catch ( \Throwable $error ) { throw new GitHubException( 'transport_failed', true, self::retryDelay( 1 ) ); }
		if ( is_wp_error( $response ) ) { throw new GitHubException( 'transport_failed', true, self::retryDelay( 1 ) ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$length = self::header( $response, 'content-length' );
		if ( ! is_string( $body ) || strlen( $body ) > $limit || ( ctype_digit( $length ) && ( strlen( $length ) > 10 || (int) $length > $limit ) ) ) { throw new GitHubException( 'response_too_large' ); }
		$remaining = self::header( $response, 'x-ratelimit-remaining' );
		$reset = self::header( $response, 'x-ratelimit-reset' );
		$retry = self::header( $response, 'retry-after' );
		$rate = array( 'remaining' => ctype_digit( $remaining ) && strlen( $remaining ) <= 9 ? (int) $remaining : null, 'reset' => ctype_digit( $reset ) && strlen( $reset ) <= 10 ? (int) $reset : null, 'cooldown_until' => 0, 'status' => $status, 'updated_at' => time() );
		$retryAfter = '' === $retry ? 0 : ( ctype_digit( $retry ) ? min( 86400, (int) $retry ) : max( 0, min( 86400, (int) strtotime( $retry ) - time() ) ) );
		$secondary = 403 === $status && ( $retryAfter > 0 || preg_match( '/(?:secondary rate limit|abuse detection)/i', $body ) );
		$limited = 429 === $status || $secondary || ( 403 === $status && 0 === $rate['remaining'] );
		if ( $limited || 0 === $rate['remaining'] ) {
			$delay = max( $retryAfter, 60, min( 86400, ( $rate['reset'] ?? time() ) - time() ) );
			$rate['cooldown_until'] = time() + $delay;
		}
		set_transient( $this->rateKey, $rate, 172800 );
		if ( $limited ) { throw new GitHubException( 'rate_limited', true, max( 60, $rate['cooldown_until'] - time() ), $rate ); }
		if ( $status >= 300 && $status < 400 ) { throw new GitHubException( 'redirect_refused', false, 0, $rate ); }
		if ( 401 === $status ) { throw new GitHubException( 'authentication_failed', false, 0, $rate ); }
		if ( 404 === $status ) { throw new GitHubException( 'repository_or_object_not_found', false, 0, $rate ); }
		if ( 403 === $status ) { throw new GitHubException( 'access_denied', false, 0, $rate ); }
		if ( $status >= 500 || in_array( $status, array( 0, 408, 425 ), true ) ) { throw new GitHubException( 'service_unavailable', true, max( $retryAfter, self::retryDelay( 1 ) ), $rate ); }
		if ( 200 !== $status ) { throw new GitHubException( 'unexpected_http_status', false, 0, $rate ); }
		try { $data = json_decode( $body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING ); }
		catch ( \JsonException $error ) { throw new GitHubException( 'invalid_json_response' ); }
		if ( ! is_array( $data ) || array_is_list( $data ) ) { throw new GitHubException( 'invalid_json_response' ); }
		return $data;
	}
}
