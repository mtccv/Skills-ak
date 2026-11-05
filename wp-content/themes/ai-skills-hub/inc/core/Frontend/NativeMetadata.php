<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Frontend;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Small write boundary for native postmeta. Requires transactional WordPress tables. */
final class NativeMetadata {
	private array $touched = array();
	private bool $active = false;

	public function __construct( private Database $database ) {}

	/** Serialize pair changes, then commit all metadata or roll it back together. */
	public function transaction( callable $operation ): void {
		$lock = 'aish_native_meta_' . substr( hash( 'sha256', $this->database->prefix() . ':' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 47 );
		if ( '1' !== (string) $this->database->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) ) ) { throw new \RuntimeException( 'Editorial metadata is busy.' ); }
		$connection = $this->database->connection();
		$previous = $connection->suppress_errors( true );
		$cache_additions_suspended = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );
		$this->touched = array();
		try {
			$this->database->transaction( function () use ( $operation ): void {
				$this->active = true;
				$operation( $this );
			} );
		} finally {
			$this->active = false;
			// Native APIs invalidate during writes; repeat after commit/rollback so another
			// request cannot leave its pre-commit snapshot in a persistent object cache.
			try { foreach ( array_keys( $this->touched ) as $id ) { clean_post_cache( $id ); wp_cache_delete( $id, 'post_meta' ); } }
			finally {
				wp_suspend_cache_addition( $cache_additions_suspended );
				try { $this->database->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); }
				finally { $connection->suppress_errors( $previous ); }
			}
		}
	}

	/** Invalidate cached metadata before reading relationship state under the lock. */
	public function touch( int $id ): void {
		if ( ! $this->active ) { throw new \LogicException( 'Metadata writes require a transaction.' ); }
		Input::id( $id );
		$this->touched[ $id ] = true;
		wp_cache_delete( $id, 'post_meta' );
	}

	private function write( callable $operation ): mixed {
		$connection = $this->database->connection();
		$connection->last_error = '';
		$result = $operation();
		if ( '' !== $connection->last_error ) { throw new \RuntimeException( 'Editorial metadata could not be stored.' ); }
		return $result;
	}

	/** Read raw stored values, without caching uncommitted metadata in shared caches. */
	private function values( int $id, string $key ): array {
		$table = $this->database->connection()->postmeta;
		return array_column( $this->database->rows( "SELECT meta_value FROM $table WHERE post_id=%d AND meta_key=%s ORDER BY meta_id", array( $id, $key ) ), 'meta_value' );
	}

	public function set( int $id, string $key, string $value ): void {
		$this->touch( $id );
		$this->write( static fn() => update_post_meta( $id, $key, wp_slash( $value ) ) );
		$values = $this->values( $id, $key );
		// update_post_meta returns false for unchanged values as well as errors.
		if ( ! $values || array_diff( $values, array( maybe_serialize( $value ) ) ) ) { throw new \RuntimeException( 'Editorial metadata could not be verified.' ); }
	}

	public function remove( int $id, string $key ): void {
		$this->touch( $id );
		$this->write( static fn() => delete_post_meta( $id, $key ) );
		if ( $this->values( $id, $key ) ) { throw new \RuntimeException( 'Editorial metadata could not be verified.' ); }
	}

	public function replace( int $id, string $key, array $values ): void {
		$this->remove( $id, $key );
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || false === $this->write( static fn() => add_post_meta( $id, $key, wp_slash( $value ) ) ) ) { throw new \RuntimeException( 'Editorial metadata could not be stored.' ); }
		}
		if ( $this->values( $id, $key ) !== array_map( 'maybe_serialize', $values ) ) { throw new \RuntimeException( 'Editorial metadata could not be verified.' ); }
	}
}
