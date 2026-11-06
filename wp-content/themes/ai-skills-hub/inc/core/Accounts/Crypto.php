<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Accounts;

defined( 'ABSPATH' ) || exit;

/** Authenticated outbox encryption. Keys live only in server configuration. */
final class Crypto {
	private const MAX_BYTES = 32768;

	private static function version(): string {
		$value = defined( 'AISH_ACCOUNT_MAIL_KEY_VERSION' ) ? constant( 'AISH_ACCOUNT_MAIL_KEY_VERSION' ) : getenv( 'AISH_ACCOUNT_MAIL_KEY_VERSION' );
		if ( ! is_string( $value ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $value ) ) { throw new \RuntimeException( 'Account mail encryption is not configured.' ); }
		return $value;
	}

	private static function key( string $version ): string {
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $version ) || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) { throw new \RuntimeException( 'Account mail encryption is unavailable.' ); }
		$keys = defined( 'AISH_ACCOUNT_MAIL_KEYS' ) ? constant( 'AISH_ACCOUNT_MAIL_KEYS' ) : array();
		// Environment references make rotations possible without storing keys in options.
		$encoded = is_array( $keys ) && isset( $keys[ $version ] ) ? $keys[ $version ] : getenv( 'AISH_ACCOUNT_MAIL_KEY_' . strtoupper( $version ) );
		$key = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
		if ( false === $key || 32 !== strlen( $key ) ) { throw new \RuntimeException( 'Account mail encryption is not configured.' ); }
		return $key;
	}

	public static function ready(): bool {
		try { self::key( self::version() ); return true; } catch ( \Throwable $error ) { return false; }
	}

	private static function aad( string $context, string $version ): string {
		if ( ! preg_match( '/^outbox:[a-f0-9]{64}$/D', $context ) ) { throw new \InvalidArgumentException( 'Invalid encrypted mail context.' ); }
		return 'aish-account-mail:v1:' . $version . ':' . $context;
	}

	public static function encrypt( #[\SensitiveParameter] array $payload, string $context ): array {
		$version = self::version();
		$key = self::key( $version );
		try {
			$json = json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( strlen( $json ) > self::MAX_BYTES ) { throw new \InvalidArgumentException( 'Account mail payload exceeds its limit.' ); }
			$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $json, self::aad( $context, $version ), $nonce, $key );
			return array( 'payload_ciphertext' => base64_encode( $nonce . $cipher ), 'key_version' => $version );
		} catch ( \JsonException $error ) { throw new \InvalidArgumentException( 'Invalid account mail payload.' ); }
		finally { sodium_memzero( $key ); }
	}

	public static function decrypt( #[\SensitiveParameter] string $ciphertext, string $keyVersion, string $context ): array {
		if ( strlen( $ciphertext ) > 44000 ) { throw new \RuntimeException( 'Account mail payload is invalid.' ); }
		$key = self::key( $keyVersion );
		try {
			$raw = base64_decode( $ciphertext, true );
			if ( false === $raw || strlen( $raw ) < 40 || strlen( $raw ) > self::MAX_BYTES + 40 ) { throw new \RuntimeException( 'Account mail payload is invalid.' ); }
			$json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, 24 ), self::aad( $context, $keyVersion ), substr( $raw, 0, 24 ), $key );
			if ( false === $json ) { throw new \RuntimeException( 'Account mail payload is invalid.' ); }
			$payload = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $payload ) ) { throw new \RuntimeException( 'Account mail payload is invalid.' ); }
			return $payload;
		} catch ( \JsonException | \SodiumException $error ) { throw new \RuntimeException( 'Account mail payload is invalid.' ); }
		finally { sodium_memzero( $key ); }
	}
}
