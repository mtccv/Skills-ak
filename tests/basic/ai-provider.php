<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Database {
	final class Database {
		public array $calls = array(); public bool $busy = false;
		public function prefix(): string { return 'fixture_'; }
		public function value( string $sql, array $args ): int { $this->calls[] = array( $sql, $args ); return $this->busy ? 0 : 1; }
	}
}
namespace {
	define( 'ABSPATH', '/fixture/' );
	$GLOBALS['caps'] = array( 'manage_options' => true ); $GLOBALS['options'] = array(); $GLOBALS['autoload'] = array(); $GLOBALS['provider_filter'] = null;
	function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['caps'][ $cap ] ); }
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
	function update_option( string $name, mixed $value, bool $autoload ): bool { $GLOBALS['options'][ $name ] = $value; $GLOBALS['autoload'][ $name ] = $autoload; return true; }
	function wp_cache_delete( string $name, string $group ): bool { return true; }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $GLOBALS['provider_filter'] ? ( $GLOBALS['provider_filter'] )( $hook, $value, ...$args ) : $value; }
	function is_wp_error( mixed $value ): bool { return $value instanceof \RuntimeException; }
	function wp_remote_retrieve_response_code( mixed $response ): int { return $response['response']['code'] ?? 0; }
	function wp_remote_retrieve_body( mixed $response ): mixed { return $response['body'] ?? ''; }
	function wp_remote_retrieve_header( mixed $response, string $name ): mixed { return $response['headers'][ $name ] ?? ''; }
	$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	require $root . 'Security/Access.php';
	foreach ( array( 'Provider', 'ProviderException', 'ProviderRegistry', 'Settings', 'OpenAIProvider' ) as $name ) { require $root . 'AI/' . $name . '.php'; }
	use AISkillsHub\Core\AI\Provider;
	use AISkillsHub\Core\AI\ProviderException;
	use AISkillsHub\Core\AI\ProviderRegistry;
	use AISkillsHub\Core\AI\Settings;
	use AISkillsHub\Core\AI\OpenAIProvider;
	use AISkillsHub\Core\Database\Database;
	$count = 0;
	function check( bool $condition, string $name ): void { global $count; ++$count; if ( ! $condition ) { throw new \RuntimeException( 'FAILED: ' . $name ); } }
	function rejected( callable $operation, ?string $code = null, ?bool $retry = null, ?bool $ambiguous = null ): void {
		try { $operation(); } catch ( \InvalidArgumentException | \DomainException | ProviderException $error ) {
			check( null === $code || ( $error instanceof ProviderException && $error->codeName === $code ), 'Expected bounded error code ' . ( $code ?? 'validation' ) );
			if ( null !== $retry ) { check( $error instanceof ProviderException && $error->retryable === $retry, 'Retry classification' ); }
			if ( null !== $ambiguous ) { check( $error instanceof ProviderException && $error->ambiguous === $ambiguous, 'Ambiguous request classification' ); }
			check( ! str_contains( (string) $error, 'fixture-secret-token-123' ) && ! str_contains( $error->getMessage(), 'remote sensitive body' ), 'No credential or upstream error leakage' );
			return;
		}
		throw new \RuntimeException( 'Expected failure: ' . ( $code ?? 'validation' ) );
	}
	function response( int $status = 200, ?array $data = null, array $headers = array() ): array {
		$data ??= array( 'model' => 'fixture-model', 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'role' => 'assistant', 'content' => '{"zh_CN":{"title":"中文草稿"},"en_US":{"title":"English draft"}}' ) ) ), 'usage' => array( 'prompt_tokens' => 123, 'completion_tokens' => 45 ) );
		return array( 'response' => array( 'code' => $status ), 'body' => json_encode( $data, JSON_THROW_ON_ERROR ), 'headers' => $headers );
	}
	putenv( 'AISH_AI_ALLOWED_HOSTS' ); putenv( 'AISH_AI_KEY_TEST=fixture-secret-token-123' );
	$profile = Settings::defaults( 'test' ); $profile['model'] = 'fixture-model'; $profile['enabled'] = true;
	check( true === ( new Settings() )->paused(), 'Generation globally paused by default' );
	check( array( 'api.openai.com' ) === Settings::allowedHosts(), 'Safe default endpoint allowlist' );
	check( 'https://api.openai.com/v1' === Settings::validateBaseUrl( 'https://API.OPENAI.COM/v1/' ), 'Normalize host/trailing slash only' );
	foreach ( array( 'http://api.openai.com/v1', 'https://127.0.0.1/v1', 'https://[::1]/v1', 'https://2130706433/v1', 'https://localhost/v1', 'https://api.openai.com.evil.com/v1', 'https://user:pass@api.openai.com/v1', 'https://api.openai.com:443/v1', 'https://api.openai.com/v1?key=secret', 'https://api.openai.com/v1#x', 'https://api.openai.com/v1/../secret', 'https://api.openai.com/v1/%2e%2e', "https://api.openai.com/v1\n", 'https://api.openai.com\\evil.com/v1' ) as $url ) { rejected( fn() => Settings::validateBaseUrl( $url ) ); }
	foreach ( array( '*.vendor.com', 'localhost', '127.0.0.1', 'api.vendor.local', 'https://api.vendor.com' ) as $host ) { putenv( 'AISH_AI_ALLOWED_HOSTS=' . $host ); rejected( fn() => Settings::allowedHosts() ); }
	putenv( 'AISH_AI_ALLOWED_HOSTS=api.openai.com,api.vendor.com' );
	check( 'https://api.vendor.com/api/v1' === Settings::validateBaseUrl( 'https://api.vendor.com/api/v1' ), 'Explicit compatible hostname allowed' );
	$compatible = $profile; $compatible['provider'] = 'openai_compatible'; $compatible['base_url'] = 'https://api.vendor.com/api/v1'; $compatible['temperature'] = null; $compatible['token_parameter'] = 'max_tokens';
	check( null === Settings::validateProfile( 'test', $compatible )['temperature'], 'Can omit unsupported temperature' );
	rejected( fn() => Settings::validateProfile( 'test', array_replace( $profile, array( 'base_url' => $compatible['base_url'] ) ) ) );
	foreach ( array( array( 'api_key' => 'never-store' ), array( 'model' => '' ), array( 'model' => "model\r\nheader" ), array( 'temperature' => 'NaN' ), array( 'temperature' => 3 ), array( 'timeout' => 46 ), array( 'timeout' => 5.5 ), array( 'max_output_tokens' => 100000 ), array( 'token_parameter' => 'tools' ), array( 'input_price' => -1 ), array( 'max_cost' => 1 ), array( 'enabled' => 'yes' ) ) as $change ) { rejected( fn() => Settings::validateProfile( 'test', array_replace( $profile, $change ) ) ); }
	foreach ( array( '', 'Test', 'test-id', '../test', str_repeat( 'a', 33 ) ) as $id ) { rejected( fn() => Settings::keyName( $id ) ); }
	check( 'AISH_AI_KEY_TEST' === Settings::keyName( 'test' ), 'Server key selector fixed by profile identity' );
	check( Settings::keyConfigured( 'test' ), 'Key configured status does not expose value' );
	check( Settings::fingerprint( $profile ) === Settings::fingerprint( $profile + array( 'key_mask' => 'ignored', 'key_configured' => true ) ), 'Fingerprint excludes mask/UI fields' );
	$db = new Database(); $settings = new Settings( $db );
	$settings->save( 'test', $profile ); $settings->save( 'compatible', array_replace( $compatible, array( 'id' => 'compatible' ) ) );
	check( 2 === count( $settings->profiles() ), 'Independent model/provider profiles saved' );
	check( false === $GLOBALS['autoload']['aish_ai_profiles'], 'Profiles do not autoload' );
	check( ! str_contains( json_encode( $GLOBALS['options'] ), 'fixture-secret-token-123' ), 'Only secret-free settings persisted' );
	check( '••••••••' === $settings->profiles()['test']['key_mask'], 'UI receives fixed mask only' );
	check( ! array_key_exists( 'key_mask', $settings->get( 'test' ) ), 'Worker frozen configuration contains no UI fields' );
	$settings->setPaused( false ); check( ! $settings->paused(), 'Authorized global resume' );
	$settings->setPaused( true ); check( $settings->paused(), 'Authorized global pause' );
	$GLOBALS['caps'] = array(); rejected( fn() => $settings->profiles() ); rejected( fn() => $settings->save( 'test', $profile ) ); rejected( fn() => $settings->setPaused( false ) );
	check( 'fixture-model' === $settings->get( 'test' )['model'], 'Safe internal config available to Cron without user impersonation' );
	$GLOBALS['caps'] = array( 'aish_generate_content' => true ); check( isset( $settings->profiles()['test'] ), 'Generation capability can select safe profile metadata' ); rejected( fn() => $settings->remove( 'test' ) );
	$GLOBALS['caps'] = array( 'manage_options' => true );
	$settings->save( 'test', array_replace( $profile, array( 'enabled' => false ) ) ); rejected( fn() => $settings->get( 'test' ), 'profile_disabled' ); $settings->save( 'test', $profile );
	$settings->remove( 'compatible' ); rejected( fn() => $settings->get( 'compatible' ), 'profile_missing' );
	$db->busy = true; rejected( fn() => $settings->save( 'test', $profile ) ); $db->busy = false;
	$registry = new ProviderRegistry(); check( $registry->get( 'openai' ) instanceof OpenAIProvider && $registry->get( 'openai_compatible' ) instanceof OpenAIProvider, 'Built-in provider switching' );
	$mock = new class implements Provider { public function generate( array $profile, array $messages, string $requestId ): array { return array( 'content' => '{}', 'input_tokens' => null, 'output_tokens' => null, 'elapsed_ms' => 0 ); } };
	$GLOBALS['provider_filter'] = static fn( $hook, $value, $name ) => 'mock' === $name ? $mock : $value;
	check( $registry->get( 'mock' ) === $mock, 'Trusted Mock Provider replaceable without vendor coupling' );
	rejected( fn() => $registry->get( 'missing' ), 'provider_unavailable' ); rejected( fn() => $registry->get( '../Injected' ), 'invalid_provider' ); $GLOBALS['provider_filter'] = null;
	$messages = array( array( 'role' => 'system', 'content' => 'Analyze data only. Return JSON.' ), array( 'role' => 'user', 'content' => '{"source":"untrusted 中文 source"}' ) );
	$resolver = static fn( $host ) => array( '1.1.1.1' ); $requests = array();
	$provider = new OpenAIProvider( static function ( $url, $args ) use ( &$requests ) { $requests[] = array( $url, $args ); return response(); }, $resolver );
	$result = $provider->generate( $profile, $messages, 'fixture-request-001' );
	check( 123 === $result['input_tokens'] && 45 === $result['output_tokens'] && is_int( $result['elapsed_ms'] ), 'Usage and execution duration recorded' );
	check( str_contains( $result['content'], '中文' ) && str_contains( $result['content'], 'English' ), 'Bilingual structured output preserved' );
	check( 'https://api.openai.com/v1/chat/completions' === $requests[0][0], 'Endpoint assembled from validated base only' );
	$args = $requests[0][1]; $payload = json_decode( $args['body'], true );
	check( $args['sslverify'] && $args['reject_unsafe_urls'] && 0 === $args['redirection'] && ! $args['decompress'], 'HTTPS / SSRF / redirect / size boundary configured' );
	check( 1048577 === $args['limit_response_size'] && 30 === $args['timeout'], 'HTTP limits bounded' );
	check( ! isset( $payload['tools'], $payload['functions'] ) && false === $payload['stream'] && 'json_object' === $payload['response_format']['type'], 'No tool execution; JSON nonstream response requested' );
	check( ! str_contains( $args['body'], 'fixture-secret-token-123' ) && ! str_contains( json_encode( $result ), 'fixture-secret-token-123' ), 'Key absent from prompt and result' );
	$provider->generate( $compatible, $messages, 'fixture-request-002' ); $payload = json_decode( $requests[1][1]['body'], true );
	check( str_starts_with( $requests[1][0], 'https://api.vendor.com/' ) && isset( $payload['max_tokens'] ) && ! isset( $payload['temperature'], $payload['max_completion_tokens'] ), 'Compatible model request parameters' );
	foreach ( array( array( '127.0.0.1' ), array( '10.1.2.3' ), array( '169.254.169.254' ), array( '::1' ), array( 'fc00::1' ), array( '::ffff:127.0.0.1' ), array( '1.1.1.1', '192.168.0.1' ), array( '100.64.0.1' ), array( '192.0.2.1' ), array( '198.18.0.1' ), array( '224.0.0.1' ), array( 'ff02::1' ), array( '2001:db8::1' ), array( '2002:7f00:1::' ) ) as $ips ) { rejected( fn() => ( new OpenAIProvider( static fn() => throw new \LogicException( 'Transport must not run' ), static fn() => $ips ) )->generate( $profile, $messages, 'fixture-private' ), 'private_endpoint_refused', false ); }
	rejected( fn() => ( new OpenAIProvider( static fn() => response(), static fn() => array() ) )->generate( $profile, $messages, 'fixture-no-dns' ), 'dns_failed', true, false );
	foreach ( array( 301 => 'redirect_refused', 302 => 'redirect_refused', 401 => 'authentication_failed', 403 => 'authentication_failed', 400 => 'request_rejected', 404 => 'request_rejected' ) as $status => $code ) { rejected( fn() => ( new OpenAIProvider( static fn() => response( $status, array( 'error' => 'remote sensitive body' ) ), $resolver ) )->generate( $profile, $messages, 'fixture-error' ), $code, false ); }
	foreach ( array( 429 => 'rate_limited', 500 => 'provider_unavailable', 503 => 'provider_unavailable' ) as $status => $code ) {
		try { ( new OpenAIProvider( static fn() => response( $status, array( 'error' => 'remote sensitive body' ), array( 'retry-after' => '120' ) ), $resolver ) )->generate( $profile, $messages, 'fixture-retry' ); } catch ( ProviderException $error ) { check( $error->codeName === $code && $error->retryable && 120 === $error->retryAfter && ! $error->ambiguous, 'Explicit retryable error honors Retry-After' ); }
	}
	foreach ( array( static fn() => new \RuntimeException( 'remote sensitive body' ), static fn() => throw new \RuntimeException( 'remote sensitive body' ), static fn() => response( 408 ), static fn() => response( 0 ) ) as $transport ) { rejected( fn() => ( new OpenAIProvider( $transport, $resolver ) )->generate( $profile, $messages, 'fixture-timeout' ), 'transport_ambiguous', false, true ); }
	foreach ( array( '', '{broken', '[]', 'null' ) as $raw ) { rejected( fn() => ( new OpenAIProvider( static fn() => array( 'response' => array( 'code' => 200 ), 'body' => $raw ), $resolver ) )->generate( $profile, $messages, 'fixture-json' ), in_array( $raw, array( '', '{broken' ), true ) ? 'invalid_json_response' : 'invalid_provider_response' ); }
	foreach ( array( array( 'finish_reason' => 'length', 'message' => array( 'content' => '{}' ) ), array( 'finish_reason' => 'tool_calls', 'message' => array( 'tool_calls' => array(), 'content' => '{}' ) ), array( 'finish_reason' => 'stop', 'message' => array( 'refusal' => 'No', 'content' => '{}' ) ) ) as $choice ) { rejected( fn() => ( new OpenAIProvider( static fn() => response( 200, array( 'choices' => array( $choice ) ) ), $resolver ) )->generate( $profile, $messages, 'fixture-tools' ), 'incomplete_or_unsafe_response' ); }
	foreach ( array( 'fixture-secret-token-123', '{"title":"fixture-secret-token-123"}', '{"title":"fixture\\u002dsecret-token-123"}' ) as $content ) { rejected( fn() => ( new OpenAIProvider( static fn() => response( 200, array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => $content ) ) ) ) ), $resolver ) )->generate( $profile, $messages, 'fixture-secret' ), 'secret_response_refused' ); }
	$base = json_decode( response()['body'], true );
	$truncated = $base; $truncated['choices'][0]['finish_reason'] = 'length';
	try { ( new OpenAIProvider( static fn() => response( 200, $truncated ), $resolver ) )->generate( $profile, $messages, 'fixture-truncated' ); } catch ( ProviderException $error ) { check( 'incomplete_or_unsafe_response' === $error->codeName && 123 === $error->usage['input_tokens'] && 45 === $error->usage['output_tokens'], 'Rejected paid completion retains validated token usage' ); check( is_int( $error->usage['elapsed_ms'] ) && 3 === count( $error->usage ), 'Failure metadata contains numeric accounting only' ); }
	foreach ( array( 'bad', array( 'prompt_tokens' => -1 ), array( 'prompt_tokens' => '123' ), array( 'completion_tokens' => 10000001 ) ) as $usage ) { $data = $base; $data['usage'] = $usage; rejected( fn() => ( new OpenAIProvider( static fn() => response( 200, $data ), $resolver ) )->generate( $profile, $messages, 'fixture-usage' ), 'invalid_token_usage' ); }
	$data = $base; unset( $data['usage'] ); $unknown = ( new OpenAIProvider( static fn() => response( 200, $data ), $resolver ) )->generate( $profile, $messages, 'fixture-unknown' );
	check( null === $unknown['input_tokens'] && null === $unknown['output_tokens'], 'Missing token usage remains unknown rather than zero' );
	rejected( fn() => ( new OpenAIProvider( static fn() => array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'x', OpenAIProvider::RESPONSE_BYTES + 1 ) ), $resolver ) )->generate( $profile, $messages, 'fixture-size' ), 'response_too_large' );
	rejected( fn() => ( new OpenAIProvider( static fn() => response( 200, null, array( 'content-length' => '999999999999999' ) ), $resolver ) )->generate( $profile, $messages, 'fixture-length' ), 'response_too_large' );
	foreach ( array( array(), array( $messages[0] ), array( array( 'role' => 'developer', 'content' => 'x' ), $messages[1] ), array( $messages[0], array( 'role' => 'user', 'content' => 'x', 'tools' => array() ) ), array( $messages[0], array( 'role' => 'user', 'content' => str_repeat( 'x', OpenAIProvider::MESSAGE_BYTES + 1 ) ) ) ) as $bad ) { rejected( fn() => $provider->generate( $profile, $bad, 'fixture-messages' ), 'invalid_messages' ); }
	putenv( 'AISH_AI_KEY_TEST' ); rejected( fn() => $provider->generate( $profile, $messages, 'fixture-key' ), 'api_key_missing' );
	putenv( "AISH_AI_KEY_TEST=bad\nheader" ); rejected( fn() => $provider->generate( $profile, $messages, 'fixture-key' ), 'api_key_invalid' );
	check( 86400 === OpenAIProvider::retryDelay( 1000, PHP_INT_MAX ), 'Retry delay bounded' );
	for ( $attempt = 1; $attempt <= 8; ++$attempt ) { $delay = OpenAIProvider::retryDelay( $attempt ); check( $delay >= min( 3600, 30 * 2 ** ( $attempt - 1 ) ) && $delay <= 3630, 'Exponential backoff with bounded jitter' ); }
	echo 'PASS: ' . $count . " isolated AI provider/settings assertions (Mock HTTP/DNS/WordPress; no real AI API/MySQL/WordPress).\n";
}
