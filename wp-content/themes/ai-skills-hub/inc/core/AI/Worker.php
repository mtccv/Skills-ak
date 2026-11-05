<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

defined( 'ABSPATH' ) || exit;

/** One bounded model request per background lease; output never publishes content. */
final class Worker {
	public function __construct( private Settings $settings, private GenerationStore $store, private ProviderRegistry $providers ) {}

	public static function connectionMessages(): array {
		return array( array( 'role' => 'system', 'content' => 'Return only this JSON object: {"ok":true}. Do not use tools or perform external actions.' ), array( 'role' => 'user', 'content' => 'API connection and JSON response check.' ) );
	}

	/** Conservative byte-based input bound, not a claim to be a model tokenizer or billing quote. */
	public static function checkBudget( array $profile, array $messages, ?float $spent = 0.0 ): void {
		$budget = (float) ( $profile['max_cost'] ?? 0 );
		if ( $budget <= 0 ) { return; }
		if ( null === ( $profile['input_price'] ?? null ) || null === ( $profile['output_price'] ?? null ) || null === $spent ) { throw new \DomainException( 'ai_budget_usage_unknown' ); }
		$bound = strlen( json_encode( $messages, JSON_THROW_ON_ERROR ) ) + 2048;
		$estimate = ( $bound * (float) $profile['input_price'] + (int) $profile['max_output_tokens'] * (float) $profile['output_price'] ) / 1000000;
		if ( $spent + $estimate > $budget ) { throw new \DomainException( 'ai_budget_exceeded' ); }
	}

	public static function usage( array $result, array $profile ): array {
		$usage = array( 'input_tokens' => null, 'output_tokens' => null, 'elapsed_ms' => 0, 'cost_estimate' => null );
		foreach ( array( 'input_tokens', 'output_tokens' ) as $key ) {
			$value = $result[ $key ] ?? null;
			if ( is_int( $value ) && $value >= 0 && $value <= 100000000 ) { $usage[ $key ] = $value; }
		}
		if ( is_int( $result['elapsed_ms'] ?? null ) ) { $usage['elapsed_ms'] = max( 0, min( 3600000, $result['elapsed_ms'] ) ); }
		if ( null !== $usage['input_tokens'] && null !== $usage['output_tokens'] && null !== ( $profile['input_price'] ?? null ) && null !== ( $profile['output_price'] ?? null ) ) {
			$usage['cost_estimate'] = round( ( $usage['input_tokens'] * (float) $profile['input_price'] + $usage['output_tokens'] * (float) $profile['output_price'] ) / 1000000, 8 );
		}
		return $usage;
	}

	public function run(): void {
		if ( ! ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) || $this->settings->paused() ) { return; }
		$job = $this->store->claim(); if ( ! $job ) { return; }
		$id = (int) $job['id']; $lease = $job['lease_token']; $attempt = null; $responded = false; $started = microtime( true ); $usage = array();
		try {
			$profile = $job['profile'];
			if ( Settings::fingerprint( $profile ) !== Settings::fingerprint( $this->settings->get( $profile['id'] ) ) ) { throw new \DomainException( 'ai_configuration_changed' ); }
			if ( ( $job['prompt_version'] ?? '' ) !== Prompt::VERSION ) { throw new \DomainException( 'ai_prompt_version_changed' ); }
			$this->store->assertCurrent( $id, $lease );
			$messages = 'connection' === $job['mode'] ? self::connectionMessages() : Prompt::build( $job['source'], $job['mode'] );
			$spent = array_key_exists( 'cost_estimate', $job ) && null === $job['cost_estimate'] ? null : (float) ( $job['cost_estimate'] ?? 0 );
			self::checkBudget( $profile, $messages, $spent );
			$provider = $this->providers->get( $profile['provider'] );
			if ( $this->settings->paused() ) { $this->store->fail( $id, $lease, 'ai_paused_before_request' ); return; }
			// The durable attempt exists BEFORE the network request. Expired in-flight work is uncertain.
			$attempt = $this->store->beginAttempt( $id, $lease ); $started = microtime( true );
			$result = $provider->generate( $profile, $messages, $attempt['request_key'] ); $responded = true;
			$usage = self::usage( $result, $profile );
			if ( 'connection' === $job['mode'] ) {
				$output = Output::connection( $result['content'] ); $translations = array();
			} else {
				$output = Output::parse( $result['content'], $job['source'], $job['mode'] ); $translations = array();
				foreach ( $output['locales'] as $locale => $content ) {
					$translations[ $locale ] = array_intersect_key( $content, array_flip( array( 'title', 'summary' ) ) );
					if ( isset( $content['description'] ) || isset( $content['use_cases'] ) ) { $translations[ $locale ]['content'] = Output::content( $content, $locale ); }
				}
			}
			$this->store->completeAttempt( $id, $lease, (int) $attempt['id'], $output, $translations, $usage );
		} catch ( ProviderException $error ) {
			$usage = self::usage( $error->usage ?? array(), $job['profile'] );
			$usage['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			$this->failure( $job, $attempt, $error->codeName, $error->retryable && ! $error->ambiguous, min( 86400, max( $error->retryAfter, 30 * ( 2 ** min( 6, (int) ( $job['attempts'] ?? 0 ) ) ) + random_int( 0, 30 ) ) ), $error->ambiguous, $usage );
		} catch ( \Throwable $error ) {
			$code = preg_match( '/^ai_[a-z_]{1,56}$/D', $error->getMessage() ) ? $error->getMessage() : ( $responded ? 'ai_invalid_or_unpersisted_output' : 'ai_generation_failed' );
			$usage['elapsed_ms'] = max( (int) ( $usage['elapsed_ms'] ?? 0 ), (int) round( ( microtime( true ) - $started ) * 1000 ) );
			$this->failure( $job, $attempt, $code, false, 60, null !== $attempt && ! $responded, $usage );
		}
	}
	private function failure( array $job, ?array $attempt, string $code, bool $retryable, int $delay, bool $ambiguous, array $usage ): void {
		try {
			if ( null === $attempt ) { $this->store->fail( (int) $job['id'], $job['lease_token'], $code ); }
			else { $this->store->failAttempt( (int) $job['id'], $job['lease_token'], (int) $attempt['id'], $code, $retryable, $delay, $ambiguous, $usage ); }
		} catch ( \Throwable $error ) { /* Lost lease/storage failure stays recoverable or uncertain, never successful. */ }
	}
}
