<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

defined( 'ABSPATH' ) || exit;

/** OpenAI Chat Completions and explicitly configured compatible endpoints, with no tools or streaming. */
final class OpenAIProvider implements Provider {
	public const RESPONSE_BYTES = 1048576;
	public const MESSAGE_BYTES = 196608;
	private readonly \Closure $transport;
	private readonly \Closure $resolver;
	public function __construct( ?callable $transport = null, ?callable $resolver = null ) {
		$this->transport = null === $transport ? static fn( string $url, array $args ): mixed => wp_safe_remote_post( $url, $args ) : \Closure::fromCallable( $transport );
		$this->resolver = null === $resolver ? static function ( string $host ): array {
			$records = dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( false === $records ) { return array(); }
			$addresses = array();
			foreach ( $records as $record ) { if ( isset( $record['ip'] ) ) { $addresses[] = $record['ip']; } if ( isset( $record['ipv6'] ) ) { $addresses[] = $record['ipv6']; } }
			return array_values( array_unique( $addresses ) );
		} : \Closure::fromCallable( $resolver );
	}
	private function validateHost( string $host ): void {
		try { $addresses = ( $this->resolver )( $host ); } catch ( \Throwable $error ) { throw new ProviderException( 'dns_failed', true, 60 ); }
		if ( ! is_array( $addresses ) || ! $addresses || count( $addresses ) > 64 ) { throw new ProviderException( 'dns_failed', true, 60 ); }
		foreach ( $addresses as $ip ) { if ( ! is_string( $ip ) || ! self::publicAddress( $ip ) ) { throw new ProviderException( 'private_endpoint_refused' ); } }
	}
	private static function publicAddress( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE ) ) { return false; }
		$binary = inet_pton( $ip );
		if ( false === $binary ) { return false; }
		if ( 4 === strlen( $binary ) ) { return ord( $binary[0] ) < 224; }
		// Native global-unicast IPv6 only: exclude multicast, mapped/translated addresses and transition tunnels.
		return ( ord( $binary[0] ) & 0xe0 ) === 0x20 && substr( $binary, 0, 2 ) !== "\x20\x02" && substr( $binary, 0, 4 ) !== "\x20\x01\x00\x00";
	}
	public static function retryDelay( int $attempt, int $minimum = 0 ): int { return min( 86400, max( 1, $minimum, min( 3600, 30 * 2 ** min( 7, max( 0, $attempt - 1 ) ) ) + random_int( 0, 30 ) ) ); }
	private static function header( mixed $response, string $name ): string { $value = wp_remote_retrieve_header( $response, $name ); return is_scalar( $value ) && strlen( (string) $value ) <= 100 ? (string) $value : ''; }
	private static function retryAfter( string $value ): int {
		if ( ctype_digit( $value ) ) { return min( 86400, (int) $value ); }
		$timestamp = '' === $value ? false : strtotime( $value );
		return false === $timestamp ? 60 : min( 86400, max( 1, $timestamp - time() ) );
	}
	private static function usage( array $data, string $field, array $measured ): ?int {
		if ( isset( $data['usage'] ) && ! is_array( $data['usage'] ) ) { throw new ProviderException( 'invalid_token_usage', false, 0, false, $measured ); }
		$value = $data['usage'][ $field ] ?? null;
		if ( null === $value ) { return null; }
		if ( ! is_int( $value ) || $value < 0 || $value > 10000000 ) { throw new ProviderException( 'invalid_token_usage', false, 0, false, $measured ); }
		return $value;
	}
	private static function measuredUsage( mixed $data, int $elapsed ): array {
		$result = array( 'input_tokens' => null, 'output_tokens' => null, 'elapsed_ms' => $elapsed );
		$usage = is_array( $data ) && is_array( $data['usage'] ?? null ) ? $data['usage'] : array();
		foreach ( array( 'input_tokens' => 'prompt_tokens', 'output_tokens' => 'completion_tokens' ) as $key => $remote ) {
			$value = $usage[ $remote ] ?? null;
			if ( is_int( $value ) && $value >= 0 && $value <= 10000000 ) { $result[ $key ] = $value; }
		}
		return $result;
	}
	private static function containsSecret( mixed $value, string $key ): bool {
		if ( is_string( $value ) ) { return str_contains( $value, $key ); }
		if ( is_array( $value ) ) {
			foreach ( $value as $name => $member ) { if ( ( is_string( $name ) && str_contains( $name, $key ) ) || self::containsSecret( $member, $key ) ) { return true; } }
		}
		return false;
	}
	public function generate( array $profile, array $messages, string $requestId ): array {
		try { $profile = Settings::validateProfile( (string) ( $profile['id'] ?? '' ), $profile ); } catch ( \Throwable $error ) { throw new ProviderException( 'profile_invalid' ); }
		if ( ! $profile['enabled'] ) { throw new ProviderException( 'profile_disabled' ); }
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,100}$/D', $requestId ) ) { throw new ProviderException( 'invalid_request_identity' ); }
		if ( count( $messages ) !== 2 || array_keys( $messages ) !== array( 0, 1 ) ) { throw new ProviderException( 'invalid_messages' ); }
		foreach ( array( 'system', 'user' ) as $index => $role ) {
			$message = $messages[ $index ];
			if ( ! is_array( $message ) || array_diff( array_keys( $message ), array( 'role', 'content' ) ) || $role !== ( $message['role'] ?? null ) || ! is_string( $message['content'] ?? null ) || '' === $message['content'] || strlen( $message['content'] ) > self::MESSAGE_BYTES || str_contains( $message['content'], "\0" ) || ! preg_match( '//u', $message['content'] ) ) { throw new ProviderException( 'invalid_messages' ); }
		}
		$body = array( 'model' => $profile['model'], 'messages' => $messages, $profile['token_parameter'] => $profile['max_output_tokens'], 'response_format' => array( 'type' => 'json_object' ), 'stream' => false, 'n' => 1 );
		if ( null !== $profile['temperature'] ) { $body['temperature'] = $profile['temperature']; }
		$json = json_encode( $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) > 393216 ) { throw new ProviderException( 'request_too_large' ); }
		$key = Settings::key( $profile['id'] );
		$this->validateHost( (string) parse_url( $profile['base_url'], PHP_URL_HOST ) );
		$args = array( 'timeout' => $profile['timeout'], 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true, 'blocking' => true, 'limit_response_size' => self::RESPONSE_BYTES + 1, 'decompress' => false, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'Accept-Encoding' => 'identity', 'User-Agent' => 'AI-Skills-Hub/0.7', 'X-Client-Request-Id' => $requestId ), 'body' => $json, 'data_format' => 'body' );
		$started = microtime( true );
		try { $response = ( $this->transport )( $profile['base_url'] . '/chat/completions', $args ); }
		catch ( \Throwable $error ) { throw new ProviderException( 'transport_ambiguous', false, 0, true ); }
		$elapsed = max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
		if ( is_wp_error( $response ) ) { throw new ProviderException( 'transport_ambiguous', false, 0, true ); }
		$status = (int) wp_remote_retrieve_response_code( $response ); $raw = wp_remote_retrieve_body( $response );
		$length = self::header( $response, 'content-length' );
		if ( ! is_string( $raw ) || strlen( $raw ) > self::RESPONSE_BYTES || ( ctype_digit( $length ) && ( strlen( $length ) > 10 || (int) $length > self::RESPONSE_BYTES ) ) ) { throw new ProviderException( 'response_too_large' ); }
		// A hostile service may echo Authorization or a key. Never persist any such response as a draft.
		if ( str_contains( $raw, $key ) ) { throw new ProviderException( 'secret_response_refused' ); }
		if ( $status >= 300 && $status < 400 ) { throw new ProviderException( 'redirect_refused' ); }
		if ( in_array( $status, array( 401, 403 ), true ) ) { throw new ProviderException( 'authentication_failed' ); }
		if ( 429 === $status ) { throw new ProviderException( 'rate_limited', true, max( 60, self::retryAfter( self::header( $response, 'retry-after' ) ) ) ); }
		if ( $status >= 500 && $status <= 599 ) { throw new ProviderException( 'provider_unavailable', true, max( 30, self::retryAfter( self::header( $response, 'retry-after' ) ) ) ); }
		if ( 0 === $status || 408 === $status ) { throw new ProviderException( 'transport_ambiguous', false, 0, true ); }
		if ( 200 !== $status ) { throw new ProviderException( 'request_rejected' ); }
		try { $data = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR ); } catch ( \JsonException $error ) { throw new ProviderException( 'invalid_json_response' ); }
		$measured = self::measuredUsage( $data, $elapsed );
		if ( self::containsSecret( $data, $key ) ) { throw new ProviderException( 'secret_response_refused', false, 0, false, $measured ); }
		if ( ! is_array( $data ) || array_is_list( $data ) || ! is_array( $data['choices'] ?? null ) || count( $data['choices'] ) !== 1 ) { throw new ProviderException( 'invalid_provider_response', false, 0, false, $measured ); }
		$choice = $data['choices'][0] ?? null; $message = is_array( $choice ) ? ( $choice['message'] ?? null ) : null;
		if ( ! is_array( $message ) || isset( $message['tool_calls'] ) || isset( $message['function_call'] ) || ! empty( $message['refusal'] ) || 'stop' !== ( $choice['finish_reason'] ?? '' ) ) { throw new ProviderException( 'incomplete_or_unsafe_response', false, 0, false, $measured ); }
		$content = $message['content'] ?? null;
		if ( ! is_string( $content ) || '' === $content || strlen( $content ) > 262144 || str_contains( $content, "\0" ) || ! preg_match( '//u', $content ) ) { throw new ProviderException( 'invalid_provider_content', false, 0, false, $measured ); }
		// Check decoded strings too, since JSON escapes can conceal the key in the wire body.
		if ( str_contains( $content, $key ) ) { throw new ProviderException( 'secret_response_refused', false, 0, false, $measured ); }
		try { $structured = json_decode( $content, true, 32, JSON_THROW_ON_ERROR ); } catch ( \JsonException $error ) { $structured = null; }
		if ( self::containsSecret( $structured, $key ) ) { throw new ProviderException( 'secret_response_refused', false, 0, false, $measured ); }
		$model = $data['model'] ?? $profile['model'];
		if ( ! is_string( $model ) || strlen( $model ) > 128 || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,127}$/D', $model ) || str_contains( $model, $key ) ) { throw new ProviderException( 'invalid_returned_model', false, 0, false, $measured ); }
		return array( 'content' => $content, 'input_tokens' => self::usage( $data, 'prompt_tokens', $measured ), 'output_tokens' => self::usage( $data, 'completion_tokens', $measured ), 'elapsed_ms' => $elapsed, 'returned_model' => $model );
	}
}
