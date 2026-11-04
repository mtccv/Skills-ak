<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Pure, JSON-serializable Git Tree traversal. No HTTP, filesystem access or source execution. */
final class Discovery {
	public const MAX_ENTRIES = 10000;
	public const MAX_SKILLS = 500;
	public const MAX_DEPTH = 32;
	public const MAX_REQUESTS = 1000;
	public const MAX_STATE_BYTES = 2097152;
	public const MAX_SKILL_BYTES = 524288;

	private static function sha( mixed $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $value ) ) { throw new \InvalidArgumentException( 'discovery_invalid_sha' ); }
		return $value;
	}

	public static function begin( string $rootSha ): array {
		$rootSha = self::sha( $rootSha );
		return array( 'version' => 1, 'root_sha' => $rootSha, 'phase' => 'recursive', 'pending' => array( array( 'sha' => $rootSha, 'path' => '', 'recursive' => true, 'ancestors' => array( $rootSha ) ) ), 'accepted' => array(), 'entries' => array(), 'warnings' => array() );
	}

	/** A durable request descriptor; one descriptor needs at most one bounded HTTP request. */
	public static function request( array $state ): ?array {
		self::validateState( $state );
		if ( 'complete' === $state['phase'] ) { return null; }
		$next = $state['pending'][0];
		return array( 'sha' => $next['sha'], 'recursive' => $next['recursive'], 'path' => $next['path'], 'id' => self::requestId( $next ) );
	}

	private static function requestId( array $request ): string {
		return hash( 'sha256', $request['sha'] . "\n" . ( $request['recursive'] ? 'recursive' : 'direct' ) . "\n" . $request['path'] );
	}

	/** Oversized recursive HTTP bodies also require a real, complete direct-tree traversal. */
	public static function fallback( array $state ): array {
		self::validateState( $state );
		$request = self::request( $state );
		if ( 'recursive' !== $state['phase'] || null === $request || ! $request['recursive'] ) { throw new \InvalidArgumentException( 'discovery_fallback_not_available' ); }
		$state['phase'] = 'walk';
		$state['entries'] = array();
		$state['pending'] = array( array( 'sha' => $state['root_sha'], 'path' => '', 'recursive' => false, 'ancestors' => array( $state['root_sha'] ) ) );
		$state['warnings'][] = 'recursive_response_size_limit';
		self::validateState( $state );
		return $state;
	}

	/** Pass the captured request to detect stale work after a checkpoint or retry. */
	public static function accept( array $state, array $response, ?array $request = null ): array {
		self::validateState( $state );
		$current = self::request( $state );
		$request ??= $current;
		if ( null === $request || ! isset( $request['sha'], $request['recursive'], $request['path'], $request['id'] ) || ! is_bool( $request['recursive'] ) || ! is_string( $request['path'] ) || ! is_string( $request['id'] ) ) { throw new \InvalidArgumentException( 'discovery_invalid_request' ); }
		self::sha( $request['sha'] );
		if ( '' !== $request['path'] ) { Input::sourcePath( $request['path'] ); }
		if ( ! hash_equals( self::requestId( $request ), $request['id'] ) ) { throw new \InvalidArgumentException( 'discovery_invalid_request' ); }
		if ( self::sha( $response['sha'] ?? null ) !== $request['sha'] || ! is_bool( $response['truncated'] ?? null ) || ! is_array( $response['tree'] ?? null ) || ! array_is_list( $response['tree'] ) ) { throw new \InvalidArgumentException( 'discovery_invalid_response' ); }
		// A truncated recursive result is discarded in full. Partial paths can never authorize missing-source updates.
		if ( $response['truncated'] ) {
			if ( ! $request['recursive'] ) { throw new \RuntimeException( 'discovery_truncated_direct_tree' ); }
			$digest = hash( 'sha256', $request['sha'] . ':truncated' );
			$entries = array();
		} else {
			$entries = self::normalizeEntries( $response['tree'], $request );
			$digest = hash( 'sha256', json_encode( $entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		if ( isset( $state['accepted'][ $request['id'] ] ) ) {
			if ( ! hash_equals( $state['accepted'][ $request['id'] ], $digest ) ) { throw new \RuntimeException( 'discovery_conflicting_response' ); }
			return $state;
		}
		if ( null === $current || ! hash_equals( $current['id'], $request['id'] ) ) { throw new \RuntimeException( 'discovery_stale_response' ); }
		if ( count( $state['accepted'] ) >= self::MAX_REQUESTS ) { throw new \RuntimeException( 'discovery_request_limit' ); }
		$state['accepted'][ $request['id'] ] = $digest;
		$node = array_shift( $state['pending'] );
		if ( $response['truncated'] ) {
			$state['phase'] = 'walk';
			$state['entries'] = array();
			$state['pending'] = array( array( 'sha' => $state['root_sha'], 'path' => '', 'recursive' => false, 'ancestors' => array( $state['root_sha'] ) ) );
			$state['warnings'][] = 'recursive_tree_truncated_fallback';
		} else {
			foreach ( $entries as $entry ) {
				$path = $entry['path'];
				if ( isset( $state['entries'][ $path ] ) ) { throw new \RuntimeException( 'discovery_duplicate_path' ); }
				$state['entries'][ $path ] = $entry;
				if ( count( $state['entries'] ) > self::MAX_ENTRIES ) { throw new \RuntimeException( 'discovery_entry_limit' ); }
				if ( 'walk' === $state['phase'] && 'tree' === $entry['type'] ) {
					if ( in_array( $entry['sha'], $node['ancestors'], true ) ) { throw new \RuntimeException( 'discovery_tree_cycle' ); }
					$state['pending'][] = array( 'sha' => $entry['sha'], 'path' => $path, 'recursive' => false, 'ancestors' => array_merge( $node['ancestors'], array( $entry['sha'] ) ) );
				}
				if ( '120000' === $entry['mode'] ) { $state['warnings'][] = 'symlink_not_followed'; }
				if ( '160000' === $entry['mode'] ) { $state['warnings'][] = 'submodule_not_followed'; }
				if ( self::isSkill( $entry ) && null !== $entry['size'] && $entry['size'] > self::MAX_SKILL_BYTES ) { $state['warnings'][] = 'oversized_skill'; }
			}
			if ( $request['recursive'] ) { self::validateHierarchy( $state['entries'], $state['root_sha'] ); }
			if ( ! $state['pending'] ) { self::validateHierarchy( $state['entries'], $state['root_sha'] ); $state['phase'] = 'complete'; }
		}
		$state['warnings'] = array_values( array_unique( $state['warnings'] ) );
		if ( count( self::skillEntries( $state['entries'] ) ) > self::MAX_SKILLS ) { throw new \RuntimeException( 'discovery_skill_limit' ); }
		if ( count( $state['pending'] ) + count( $state['accepted'] ) > self::MAX_REQUESTS ) { throw new \RuntimeException( 'discovery_request_limit' ); }
		self::validateState( $state );
		return $state;
	}

	private static function normalizeEntries( array $entries, array $request ): array {
		if ( count( $entries ) > self::MAX_ENTRIES ) { throw new \RuntimeException( 'discovery_entry_limit' ); }
		$result = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) { throw new \InvalidArgumentException( 'discovery_invalid_entry' ); }
			try { $relative = Input::sourcePath( $entry['path'] ?? null ); }
			catch ( \InvalidArgumentException $error ) { throw new \InvalidArgumentException( 'discovery_invalid_path' ); }
			if ( ! $request['recursive'] && str_contains( $relative, '/' ) ) { throw new \InvalidArgumentException( 'discovery_nonlocal_entry' ); }
			try { $path = Input::sourcePath( '' === $request['path'] ? $relative : $request['path'] . '/' . $relative ); }
			catch ( \InvalidArgumentException $error ) { throw new \InvalidArgumentException( 'discovery_invalid_path' ); }
			if ( substr_count( $path, '/' ) + 1 > self::MAX_DEPTH ) { throw new \RuntimeException( 'discovery_depth_limit' ); }
			$mode = $entry['mode'] ?? null;
			$type = $entry['type'] ?? null;
			$expected = match ( $mode ) { '040000' => 'tree', '100644', '100755', '120000' => 'blob', '160000' => 'commit', default => null };
			if ( null === $expected || $expected !== $type ) { throw new \InvalidArgumentException( 'discovery_invalid_mode' ); }
			$size = $entry['size'] ?? null;
			if ( null !== $size && ( ! is_int( $size ) || $size < 0 ) ) { throw new \InvalidArgumentException( 'discovery_invalid_size' ); }
			if ( 'SKILL.md' === basename( $path ) && ( 'blob' !== $type || ! in_array( $mode, array( '100644', '100755' ), true ) ) ) { throw new \RuntimeException( 'discovery_unsafe_skill_entry' ); }
			if ( isset( $result[ $path ] ) ) { throw new \RuntimeException( 'discovery_duplicate_path' ); }
			$result[ $path ] = array( 'path' => $path, 'sha' => self::sha( $entry['sha'] ?? null ), 'mode' => $mode, 'type' => $type, 'size' => $size );
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private static function validateHierarchy( array $entries, string $rootSha ): void {
		foreach ( $entries as $entry ) {
			$path = $entry['path'];
			$ancestors = array( $rootSha );
			$parts = explode( '/', $path ); array_pop( $parts );
			$prefix = '';
			foreach ( $parts as $part ) {
				$prefix = '' === $prefix ? $part : $prefix . '/' . $part;
				if ( ! isset( $entries[ $prefix ] ) || 'tree' !== $entries[ $prefix ]['type'] ) { throw new \RuntimeException( 'discovery_incomplete_hierarchy' ); }
				$ancestors[] = $entries[ $prefix ]['sha'];
			}
			if ( 'tree' === $entry['type'] && in_array( $entry['sha'], $ancestors, true ) ) { throw new \RuntimeException( 'discovery_tree_cycle' ); }
		}
	}

	private static function isSkill( array $entry ): bool { return 'SKILL.md' === basename( $entry['path'] ) && 'blob' === $entry['type'] && in_array( $entry['mode'], array( '100644', '100755' ), true ); }
	private static function skillEntries( array $entries ): array { return array_filter( $entries, static fn( array $entry ): bool => self::isSkill( $entry ) ); }

	/** Tree completion is not file/Frontmatter processing success; the worker must track that separately. */
	public static function complete( array $state ): bool { self::validateState( $state ); return 'complete' === $state['phase']; }
	public static function candidates( array $state ): array { self::validateState( $state ); $entries = self::skillEntries( $state['entries'] ); ksort( $entries, SORT_STRING ); return array_values( $entries ); }
	public static function entries( array $state ): array { self::validateState( $state ); $entries = $state['entries']; ksort( $entries, SORT_STRING ); return array_values( $entries ); }

	private static function validateState( array $state ): void {
		if ( 1 !== ( $state['version'] ?? null ) || ! in_array( $state['phase'] ?? null, array( 'recursive', 'walk', 'complete' ), true ) || ! is_array( $state['pending'] ?? null ) || ! array_is_list( $state['pending'] ) || ! is_array( $state['accepted'] ?? null ) || ! is_array( $state['entries'] ?? null ) || ! is_array( $state['warnings'] ?? null ) ) { throw new \InvalidArgumentException( 'discovery_invalid_state' ); }
		self::sha( $state['root_sha'] ?? null );
		if ( ( 'complete' === $state['phase'] ) !== ( 0 === count( $state['pending'] ) ) ) { throw new \InvalidArgumentException( 'discovery_invalid_state' ); }
		foreach ( $state['entries'] as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['path'] ?? null ) || ! is_string( $entry['type'] ?? null ) || ! is_string( $entry['mode'] ?? null ) || ! array_key_exists( 'size', $entry ) ) { throw new \InvalidArgumentException( 'discovery_invalid_state' ); }
			Input::sourcePath( $entry['path'] ); self::sha( $entry['sha'] ?? null );
		}
		if ( count( $state['entries'] ) > self::MAX_ENTRIES || count( $state['pending'] ) + count( $state['accepted'] ) > self::MAX_REQUESTS || count( self::skillEntries( $state['entries'] ) ) > self::MAX_SKILLS ) { throw new \RuntimeException( 'discovery_state_limit' ); }
		foreach ( $state['pending'] as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['path'] ?? null ) || ! is_bool( $node['recursive'] ?? null ) || ! is_array( $node['ancestors'] ?? null ) || ! $node['ancestors'] || count( $node['ancestors'] ) > self::MAX_DEPTH ) { throw new \InvalidArgumentException( 'discovery_invalid_state' ); }
			self::sha( $node['sha'] ?? null );
			if ( '' !== $node['path'] ) { Input::sourcePath( $node['path'] ); }
			foreach ( $node['ancestors'] as $sha ) { self::sha( $sha ); }
		}
		array_walk_recursive( $state, static function ( mixed $value ): void { if ( null !== $value && ! is_scalar( $value ) ) { throw new \InvalidArgumentException( 'discovery_invalid_state' ); } } );
		if ( strlen( json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) > self::MAX_STATE_BYTES ) { throw new \RuntimeException( 'discovery_state_size_limit' ); }
	}
}
