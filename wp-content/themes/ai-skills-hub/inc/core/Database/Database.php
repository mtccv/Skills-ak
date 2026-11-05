<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Database;

defined( 'ABSPATH' ) || exit;

/** Internal SQL boundary. Callers supply static SQL and bound values, never request SQL. */
final class Database {
	private bool $in_transaction = false;
	private bool $usable = true;

	public function __construct( private \wpdb $connection, private ?string $prefix = null ) {}

	public function connection(): \wpdb {
		return $this->connection;
	}

	public function prefix(): string {
		$prefix = $this->prefix ?? $this->connection->prefix;
		if ( ! preg_match( '/^[a-zA-Z0-9_]{1,40}$/D', $prefix ) ) {
			throw new \InvalidArgumentException( 'Unsupported database prefix.' );
		}
		return $prefix;
	}

	public function table( string $entity ): string {
		if ( ! isset( Schema::COLUMNS[ $entity ] ) ) {
			throw new \InvalidArgumentException( 'Unknown Core table.' );
		}
		return '`' . $this->prefix() . 'aish_' . $entity . '`';
	}

	private function sql( string $sql, array $values ): string {
		return $values ? $this->connection->prepare( $sql, $values ) : $sql;
	}

	private function checked( callable $operation ): mixed {
		if ( ! $this->usable ) { throw new \RuntimeException( 'Core database connection requires a new request.' ); }
		$previous = $this->connection->suppress_errors( true );
		try {
			$result = $operation();
			if ( false === $result || '' !== $this->connection->last_error ) {
				throw new \RuntimeException( 'Core database operation failed.' );
			}
			return $result;
		} catch ( \mysqli_sql_exception $error ) {
			// Strict driver errors can contain SQL values; never expose the driver's message.
			throw new \RuntimeException( 'Core database operation failed.' );
		} finally {
			$this->connection->suppress_errors( $previous );
		}
	}

	public function execute( string $sql, array $values = array() ): int {
		return (int) $this->checked( fn() => $this->connection->query( $this->sql( $sql, $values ) ) );
	}

	public function rows( string $sql, array $values = array() ): array {
		return $this->checked( fn() => $this->connection->get_results( $this->sql( $sql, $values ), ARRAY_A ) );
	}

	public function row( string $sql, array $values = array() ): ?array {
		$rows = $this->rows( $sql, $values );
		return $rows[0] ?? null;
	}

	public function value( string $sql, array $values = array() ): mixed {
		return $this->checked( fn() => $this->connection->get_var( $this->sql( $sql, $values ) ) );
	}

	private function formats( string $entity, array $data ): array {
		$this->table( $entity );
		$formats = array();
		foreach ( $data as $column => $value ) {
			if ( 'id' === $column || ! isset( Schema::COLUMNS[ $entity ][ $column ] ) ) {
				throw new \InvalidArgumentException( 'Unknown or immutable column.' );
			}
			$formats[] = Schema::COLUMNS[ $entity ][ $column ];
		}
		return $formats;
	}

	public function insert( string $entity, array $data ): int {
		$formats = $this->formats( $entity, $data );
		$this->checked( fn() => $this->connection->insert( trim( $this->table( $entity ), '`' ), $data, $formats ) );
		return (int) $this->connection->insert_id;
	}

	public function updateById( string $entity, int $id, array $data ): void {
		$formats = $this->formats( $entity, $data );
		$this->checked( fn() => $this->connection->update( trim( $this->table( $entity ), '`' ), $data, array( 'id' => $id ), $formats, array( '%d' ) ) );
	}

	public function transaction( callable $operation ): mixed {
		if ( ! $this->usable ) { throw new \RuntimeException( 'Core database connection requires a new request.' ); }
		if ( $this->in_transaction ) {
			throw new \LogicException( 'Nested Core transactions are not supported.' );
		}
		// wpdb normally reconnects and replays a query after error 2006. A new
		// connection has lost this transaction and its row locks, so replaying a
		// write would autocommit only part of the operation. Make mysqli throw
		// before wpdb reaches that retry branch, including native postmeta writes.
		// Test adapters without mysqli still use the same transaction contract.
		$driver_mode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $driver_mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		$started = false;
		try {
			$this->execute( 'START TRANSACTION' );
			$this->in_transaction = true;
			$started = true;
			$result = $operation();
			$this->execute( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
			// A lost connection (including an unknown COMMIT response) may make
			// rollback impossible. Preserve the failure, never retry the transaction
			// or claim that an unacknowledged commit has been undone.
			if ( $started ) {
				try { $this->execute( 'ROLLBACK' ); }
				catch ( \Throwable $rollback_error ) { $this->invalidateConnection(); }
			}
			if ( $error instanceof \mysqli_sql_exception ) { throw new \RuntimeException( 'Core database operation failed.' ); }
			throw $error;
		} finally {
			$this->in_transaction = false;
			if ( null !== $driver_mode ) { mysqli_report( $driver_mode ); }
		}
	}

	/** A later START could implicitly commit abandoned writes after a failed ROLLBACK. */
	private function invalidateConnection(): void {
		$this->usable = false;
		// wpdb::close() is available since WordPress 4.5; closing a live session
		// releases uncommitted work and locks. Never promise that an unknown COMMIT
		// was undone, and never reuse this wrapper even if closing also fails.
		try { if ( is_callable( array( $this->connection, 'close' ) ) ) { $this->connection->close(); } }
		catch ( \Throwable $error ) {}
		// Also stop native WordPress APIs or another wrapper from reusing this
		// connection during the same request. A fresh request creates its own wpdb.
		try { if ( property_exists( $this->connection, 'ready' ) ) { $this->connection->ready = false; } }
		catch ( \Throwable $error ) {}
	}
}
