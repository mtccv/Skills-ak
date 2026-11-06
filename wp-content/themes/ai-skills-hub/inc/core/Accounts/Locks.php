<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Native users are global on multisite: locks use the users table, not the blog prefix. */
final class Locks {
	private static array $held = array();
	private static int $guards = 0;
	private static ?int $driverMode = null;

	public function __construct( private Database $db ) {}

	public static function email( #[\SensitiveParameter] string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( strlen( $email ) > 100 || ! is_email( $email ) ) { throw new \InvalidArgumentException( 'Invalid account email.' ); }
		return $email;
	}

	/** Returns an idempotent release closure for native pre-write/post-write hooks. */
	public function acquire( array $emails, ?int $userId = null ): callable {
		$connection = $this->db->connection();
		$identity = spl_object_id( $connection );
		$scope = DB_NAME . ':' . $connection->users;
		$keys = array();
		foreach ( $emails as $email ) {
			if ( ! is_string( $email ) ) { throw new \InvalidArgumentException( 'Invalid account email.' ); }
			$key = 'aishacct:' . substr( hash( 'sha256', $scope . ':email:' . self::email( $email ) ), 0, 55 );
			$keys[ $key ] = 'email';
		}
		ksort( $keys, SORT_STRING );
		foreach ( self::$held[ $identity ] ?? array() as $held ) {
			if ( 'user' === $held['kind'] ) {
				foreach ( $keys as $key => $kind ) {
					if ( ! isset( self::$held[ $identity ][ $key ] ) ) { throw new \LogicException( 'Email locks must precede account locks.' ); }
				}
			}
		}
		if ( null !== $userId ) {
			if ( $userId < 1 ) { throw new \InvalidArgumentException( 'Invalid account identity.' ); }
			$keys[ 'aishacct:' . substr( hash( 'sha256', $scope . ':user:' . $userId ), 0, 55 ) ] = 'user';
		}
		if ( 0 === self::$guards && class_exists( '\mysqli_driver' ) ) {
			self::$driverMode = ( new \mysqli_driver() )->report_mode;
			mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
		}
		++self::$guards;
		$acquired = array();
		$released = false;
		$release = function () use ( &$released, &$acquired, $identity ): void {
			if ( $released ) { return; }
			$released = true;
			foreach ( array_reverse( $acquired ) as $key ) {
				if ( 0 === --self::$held[ $identity ][ $key ]['depth'] ) {
					unset( self::$held[ $identity ][ $key ] );
					try { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $key ) ); } catch ( \Throwable $error ) {}
				}
			}
			if ( empty( self::$held[ $identity ] ) ) { unset( self::$held[ $identity ] ); }
			if ( 0 === --self::$guards && null !== self::$driverMode ) {
				mysqli_report( self::$driverMode );
				self::$driverMode = null;
			}
		};
		try {
			foreach ( $keys as $key => $kind ) {
				if ( isset( self::$held[ $identity ][ $key ] ) ) { ++self::$held[ $identity ][ $key ]['depth']; }
				else {
					if ( 1 !== (int) $this->db->value( 'SELECT GET_LOCK(%s, 2)', array( $key ) ) ) { throw new \RuntimeException( 'Account is busy. Retry later.' ); }
					self::$held[ $identity ][ $key ] = array( 'kind' => $kind, 'depth' => 1 );
				}
				$acquired[] = $key;
			}
			return $release;
		} catch ( \Throwable $error ) {
			$release();
			throw $error instanceof \mysqli_sql_exception ? new \RuntimeException( 'Account storage is unavailable.' ) : $error;
		}
	}

	public function with( array $emails, ?int $userId, callable $operation ): mixed {
		$release = $this->acquire( $emails, $userId );
		try { return $operation(); } finally { $release(); }
	}

	public function withEmails( array $emails, callable $operation ): mixed { return $this->with( $emails, null, $operation ); }
	public function withUser( int $userId, callable $operation ): mixed { return $this->with( array(), $userId, $operation ); }
}
