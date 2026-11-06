<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Atomic bounded counters, with no raw address/email or authentication data. */
final class RateLimiter {
	public function __construct( private Database $database ) {}

	public function allow( string $action, string $identity = '' ): bool {
		$limits = array(
			'login' => array( 900, 30, 10, 1000 ),
			'register' => array( 3600, 10, 3, 100 ),
			'resend' => array( 3600, 15, 5, 200 ),
			'forgot' => array( 3600, 15, 5, 200 ),
			'verify' => array( 900, 30, 10, 1000 ),
			'security' => array( 900, 30, 10, 1000 ),
		);
		if ( ! isset( $limits[ $action ] ) ) { throw new \InvalidArgumentException( 'Unknown account rate scope.' ); }
		[ $window, $addressLimit, $identityLimit, $globalLimit ] = $limits[ $action ];
		$raw = $_SERVER['REMOTE_ADDR'] ?? '';
		$address = is_string( $raw ) && filter_var( $raw, FILTER_VALIDATE_IP ) ? $raw : 'unknown';
		$now = time();
		$scope = '_aish_account_rate_';
		// Fixed window is part of the HMAC: no persistent cross-window identifier.
		$bucket = (string) intdiv( $now, $window );
		$keys = array(
			$scope . $action . '_global' => $globalLimit,
			$scope . hash_hmac( 'sha256', $action . ':ip:' . $bucket . ':' . $address, wp_salt( 'nonce' ) ) => $addressLimit,
		);
		if ( '' !== $identity ) { $keys[ $scope . hash_hmac( 'sha256', $action . ':identity:' . $bucket . ':' . strtolower( $identity ), wp_salt( 'nonce' ) ) ] = $identityLimit; }
		$db = $this->database; $table = $db->connection()->options;
		$lock = 'aish_rate_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $table ), 0, 48 );
		$mode = class_exists( '\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		$locked = false;
		try {
			$locked = '1' === (string) $db->value( 'SELECT GET_LOCK(%s, 0)', array( $lock ) );
			if ( ! $locked ) { return false; }
			$db->execute( "DELETE FROM $table WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d LIMIT 100", array( $db->connection()->esc_like( $scope ) . '%', $now - 86400 ) );
			$next = array();
			foreach ( $keys as $key => $limit ) {
				$value = $db->value( "SELECT option_value FROM $table WHERE option_name=%s", array( $key ) );
				$parts = is_string( $value ) ? explode( ':', $value ) : array();
				$start = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
				$count = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : 0;
				if ( $start <= $now - $window || $start > $now ) { $start = $now; $count = 0; }
				if ( $count >= $limit ) { return false; }
				$next[ $key ] = $start . ':' . ( $count + 1 );
			}
			foreach ( $next as $key => $value ) {
				$db->execute( "INSERT INTO $table (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'", array( $key, $value ) );
				wp_cache_delete( $key, 'options' );
			}
			return true;
		} catch ( \Throwable $error ) { return false; }
		finally {
			try { if ( $locked ) { $db->value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ); } }
			finally { if ( null !== $mode ) { mysqli_report( $mode ); } }
		}
	}
}
