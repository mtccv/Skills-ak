<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Database;

defined( 'ABSPATH' ) || exit;

/** Internal SQL boundary. Callers supply static SQL and bound values, never request SQL. */
final class Database {
	private bool $in_transaction = false;

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
		$previous = $this->connection->suppress_errors( true );
		try {
			$result = $operation();
			if ( false === $result || '' !== $this->connection->last_error ) {
				throw new \RuntimeException( 'Core database operation failed.' );
			}
			return $result;
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
		if ( $this->in_transaction ) {
			throw new \LogicException( 'Nested Core transactions are not supported.' );
		}
		$this->execute( 'START TRANSACTION' );
		$this->in_transaction = true;
		try {
			$result = $operation();
			$this->execute( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
			$this->execute( 'ROLLBACK' );
			throw $error;
		} finally {
			$this->in_transaction = false;
		}
	}
}
