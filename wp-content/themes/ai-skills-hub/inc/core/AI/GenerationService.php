<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

use AISkillsHub\Core\Security\Access;

defined( 'ABSPATH' ) || exit;

/** Admin commands only capture approved configuration and enqueue bounded work. */
final class GenerationService {
	public function __construct( private Settings $settings, private SourceContext $sources, private GenerationStore $store ) {}
	public function enqueue( int $skillId, string $profileId, string $mode, string $requestKey ): int {
		Access::write( 'aish_generate_content' );
		if ( ! in_array( $mode, array( 'both', 'zh_CN', 'en_US', 'summary', 'use_cases' ), true ) ) { throw new \InvalidArgumentException( 'ai_invalid_mode' ); }
		$profile = $this->settings->get( $profileId ); $source = $this->sources->snapshot( $skillId );
		// Validate bounds before recording a paid task; no remote request occurs here.
		$messages = Prompt::build( $source, $mode ); Worker::checkBudget( $profile, $messages );
		$id = $this->store->enqueue( $source, $profile, $mode, self::requestKey( $requestKey ), Prompt::VERSION );
		do_action( 'aish_ai_wake' ); return $id;
	}
	public function enqueueConnection( string $profileId, string $requestKey ): int {
		Access::write( 'aish_manage_ai_settings' ); Access::write( 'aish_generate_content' );
		$profile = $this->settings->get( $profileId ); Worker::checkBudget( $profile, Worker::connectionMessages() );
		$id = $this->store->enqueue( array(), $profile, 'connection', self::requestKey( $requestKey ), Prompt::VERSION );
		do_action( 'aish_ai_wake' ); return $id;
	}
	private static function requestKey( string $key ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $key ) ) { throw new \InvalidArgumentException( 'ai_invalid_request_key' ); }
		return hash( 'sha256', $key );
	}
}
