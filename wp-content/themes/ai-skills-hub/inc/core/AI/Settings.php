<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Security\Access;

defined( 'ABSPATH' ) || exit;

/** Secret-free configuration in one non-autoload option. Keys stay in server constants/environment only. */
final class Settings {
	private const OPTION = 'aish_ai_profiles';
	private const PAUSED = 'aish_ai_paused';
	public function __construct( private ?Database $db = null ) {}

	public static function defaults( string $id = 'default' ): array {
		self::id( $id );
		return array( 'id' => $id, 'provider' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => '', 'temperature' => 0.2, 'timeout' => 30, 'max_output_tokens' => 4096, 'token_parameter' => 'max_completion_tokens', 'input_price' => null, 'output_price' => null, 'max_cost' => 0.0, 'enabled' => false );
	}
	private static function id( string $id ): void {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $id ) ) { throw new \InvalidArgumentException( 'Invalid AI profile identity.' ); }
	}
	public static function keyName( string $id ): string { self::id( $id ); return 'AISH_AI_KEY_' . strtoupper( $id ); }
	private static function server( string $name ): mixed { return defined( $name ) ? constant( $name ) : getenv( $name ); }
	public static function keyConfigured( string $id ): bool { $value = self::server( self::keyName( $id ) ); return is_string( $value ) && '' !== $value; }
	/** Internal transport use only; no HTTP/controller exposes this value. */
	public static function key( string $id ): string {
		$value = self::server( self::keyName( $id ) );
		if ( false === $value || null === $value || '' === $value ) { throw new ProviderException( 'api_key_missing' ); }
		if ( ! is_string( $value ) || strlen( $value ) < 8 || strlen( $value ) > 4096 || ! preg_match( '/^[\x21-\x7e]+$/D', $value ) ) { throw new ProviderException( 'api_key_invalid' ); }
		return $value;
	}
	private static function hostname( string $host ): bool {
		return strlen( $host ) <= 253 && ! filter_var( $host, FILTER_VALIDATE_IP ) && (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D', $host ) && ! preg_match( '/(?:^|\.)(?:localhost|local|internal|test|invalid|example|onion)$/D', $host );
	}
	public static function allowedHosts(): array {
		$value = self::server( 'AISH_AI_ALLOWED_HOSTS' );
		if ( false === $value || null === $value || '' === $value ) { return array( 'api.openai.com' ); }
		if ( ! is_string( $value ) || strlen( $value ) > 4096 ) { throw new \InvalidArgumentException( 'Invalid AI host allowlist.' ); }
		$hosts = array_values( array_unique( array_map( static fn( string $host ): string => strtolower( trim( $host ) ), explode( ',', $value ) ) ) );
		if ( count( $hosts ) > 20 ) { throw new \InvalidArgumentException( 'AI host allowlist is too large.' ); }
		foreach ( $hosts as $host ) { if ( ! self::hostname( $host ) ) { throw new \InvalidArgumentException( 'Invalid AI host allowlist.' ); } }
		return $hosts;
	}
	public static function validateBaseUrl( string $url ): string {
		if ( strlen( $url ) > 1024 || preg_match( '/[\x00-\x20\x7f\\\\%]/', $url ) ) { throw new \InvalidArgumentException( 'Invalid AI API URL.' ); }
		$parts = parse_url( $url );
		$host = is_array( $parts ) ? strtolower( $parts['host'] ?? '' ) : '';
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || ! self::hostname( $host ) || ! in_array( $host, self::allowedHosts(), true ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) { throw new \InvalidArgumentException( 'AI API URL is not an allowed HTTPS endpoint.' ); }
		$path = rtrim( $parts['path'] ?? '', '/' );
		if ( strlen( $path ) > 512 || ( '' !== $path && ! preg_match( '#^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$#D', $path ) ) ) { throw new \InvalidArgumentException( 'Invalid AI API base path.' ); }
		return 'https://' . $host . $path;
	}
	private static function number( mixed $value, float $min, float $max, bool $nullable = false ): ?float {
		if ( $nullable && ( null === $value || '' === $value ) ) { return null; }
		if ( is_bool( $value ) || ! ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^\d+(?:\.\d{1,8})?$/D', $value ) ) ) ) { throw new \InvalidArgumentException( 'Invalid AI numeric setting.' ); }
		$value = (float) $value;
		if ( ! is_finite( $value ) || $value < $min || $value > $max ) { throw new \InvalidArgumentException( 'AI numeric setting is out of range.' ); }
		return $value;
	}
	public static function validateProfile( string $id, array $input ): array {
		$value = self::defaults( $id );
		if ( array_diff( array_keys( $input ), array_merge( array_keys( $value ), array( 'key_configured', 'key_mask' ) ) ) ) { throw new \InvalidArgumentException( 'Unknown AI setting; secrets must use server configuration.' ); }
		foreach ( array_keys( $value ) as $field ) { if ( array_key_exists( $field, $input ) && 'id' !== $field ) { $value[ $field ] = $input[ $field ]; } }
		if ( ! is_string( $value['provider'] ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $value['provider'] ) ) { throw new \InvalidArgumentException( 'Invalid AI provider.' ); }
		if ( ! is_string( $value['base_url'] ) ) { throw new \InvalidArgumentException( 'Invalid AI API URL.' ); }
		$value['base_url'] = self::validateBaseUrl( $value['base_url'] );
		if ( 'openai' === $value['provider'] && 'https://api.openai.com/v1' !== $value['base_url'] ) { throw new \InvalidArgumentException( 'Use the compatible provider for a custom endpoint.' ); }
		if ( ! is_string( $value['model'] ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,127}$/D', $value['model'] ) ) { throw new \InvalidArgumentException( 'An explicit AI model is required.' ); }
		$value['temperature'] = self::number( $value['temperature'], 0, 2, true );
		foreach ( array( 'timeout' => array( 5, 45 ), 'max_output_tokens' => array( 256, 8192 ) ) as $field => $bounds ) {
			$number = self::number( $value[ $field ], $bounds[0], $bounds[1] );
			if ( floor( $number ) !== $number ) { throw new \InvalidArgumentException( 'AI limit must be a whole number.' ); }
			$value[ $field ] = (int) $number;
		}
		if ( ! in_array( $value['token_parameter'], array( 'max_tokens', 'max_completion_tokens' ), true ) ) { throw new \InvalidArgumentException( 'Invalid AI token parameter.' ); }
		foreach ( array( 'input_price', 'output_price' ) as $field ) { $value[ $field ] = self::number( $value[ $field ], 0, 10000, true ); }
		$value['max_cost'] = self::number( $value['max_cost'], 0, 1000 );
		if ( ! in_array( $value['enabled'], array( false, true, 0, 1, '0', '1' ), true ) ) { throw new \InvalidArgumentException( 'Invalid AI enabled setting.' ); }
		$value['enabled'] = in_array( $value['enabled'], array( true, 1, '1' ), true );
		if ( $value['max_cost'] > 0 && ( null === $value['input_price'] || null === $value['output_price'] ) ) { throw new \InvalidArgumentException( 'A cost ceiling requires both configured token prices.' ); }
		return $value;
	}
	public static function fingerprint( array $profile ): string {
		$profile = self::validateProfile( (string) ( $profile['id'] ?? '' ), $profile );
		return hash( 'sha256', json_encode( $profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}
	private static function stored(): array {
		$value = get_option( self::OPTION, array() );
		if ( ! is_array( $value ) || count( $value ) > 20 ) { throw new \RuntimeException( 'Invalid AI profile storage.' ); }
		return $value;
	}
	public function profiles(): array {
		if ( ! current_user_can( 'aish_generate_content' ) ) { Access::write( 'aish_manage_ai_settings' ); }
		$result = array();
		foreach ( self::stored() as $id => $value ) {
			if ( ! is_string( $id ) || ! is_array( $value ) ) { continue; }
			try { $value = self::validateProfile( $id, $value ); } catch ( \InvalidArgumentException $error ) { continue; }
			$value['key_configured'] = self::keyConfigured( $id ); $value['key_mask'] = $value['key_configured'] ? '••••••••' : '';
			$result[ $id ] = $value;
		}
		return $result;
	}
	/** Internal safe configuration read used by the trusted Cron worker. */
	public function get( string $id ): array {
		self::id( $id ); $value = self::stored()[ $id ] ?? null;
		if ( ! is_array( $value ) ) { throw new ProviderException( 'profile_missing' ); }
		try { $value = self::validateProfile( $id, $value ); } catch ( \InvalidArgumentException $error ) { throw new ProviderException( 'profile_invalid' ); }
		if ( ! $value['enabled'] ) { throw new ProviderException( 'profile_disabled' ); }
		return $value;
	}
	private function locked( callable $operation ): void {
		if ( null === $this->db ) {
			global $wpdb;
			if ( ! $wpdb instanceof \wpdb ) { throw new \RuntimeException( 'AI settings require the WordPress database.' ); }
			$this->db = new Database( $wpdb );
		}
		$name = 'aish_ai_settings_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->db->prefix() ), 0, 40 );
		$held = false;
		// Native option reads/writes must not reconnect and continue a read-modify-write
		// after MySQL has released the settings lock with the old connection.
		$driver_mode = class_exists( '\\mysqli_driver' ) ? ( new \mysqli_driver() )->report_mode : null;
		if ( null !== $driver_mode ) { mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); }
		try {
			if ( 1 !== (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $name ) ) ) { throw new \DomainException( 'AI settings are busy.' ); }
			$held = true;
			wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( self::PAUSED, 'options' ); wp_cache_delete( 'notoptions', 'options' ); $operation();
		} finally {
			try { if ( $held ) { $this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $name ) ); } }
			finally { if ( null !== $driver_mode ) { mysqli_report( $driver_mode ); } }
		}
	}
	private static function writeOption( string $name, mixed $value ): void {
		update_option( $name, $value, false );
		if ( get_option( $name, null ) !== $value ) { throw new \RuntimeException( 'AI settings could not be stored.' ); }
	}
	public function save( string $id, array $input ): void {
		Access::write( 'aish_manage_ai_settings' ); $value = self::validateProfile( $id, $input );
		( new ProviderRegistry() )->get( $value['provider'] );
		$this->locked( static function () use ( $id, $value ): void {
			$all = self::stored();
			if ( ! isset( $all[ $id ] ) && count( $all ) >= 20 ) { throw new \DomainException( 'AI profile limit reached.' ); }
			$all[ $id ] = $value; self::writeOption( self::OPTION, $all );
		} );
	}
	public function remove( string $id ): void {
		Access::write( 'aish_manage_ai_settings' ); self::id( $id );
		$this->locked( static function () use ( $id ): void { $all = self::stored(); unset( $all[ $id ] ); self::writeOption( self::OPTION, $all ); } );
	}
	public function paused(): bool { return ! in_array( get_option( self::PAUSED, true ), array( false, 0, '0' ), true ); }
	public function setPaused( bool $paused ): void {
		Access::write( 'aish_manage_ai_settings' );
		$this->locked( static function () use ( $paused ): void { self::writeOption( self::PAUSED, $paused ? '1' : '0' ); } );
	}
}
