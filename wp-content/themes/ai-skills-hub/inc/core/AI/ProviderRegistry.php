<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

defined( 'ABSPATH' ) || exit;

/** Provider injection is a trusted PHP extension seam, never a request-controlled class name. */
final class ProviderRegistry {
	public function get( string $name ): Provider {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $name ) ) { throw new ProviderException( 'invalid_provider' ); }
		$provider = in_array( $name, array( 'openai', 'openai_compatible' ), true ) ? new OpenAIProvider() : null;
		$provider = apply_filters( 'aish_ai_provider', $provider, $name );
		if ( ! $provider instanceof Provider ) { throw new ProviderException( 'provider_unavailable' ); }
		return $provider;
	}
}
