<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;

defined( 'ABSPATH' ) || exit;

/** Optional server-rendered placements. A disabled service produces no markup or tracking. */
final class SponsorFrontend {
	public function __construct( private Sponsors $sponsors ) {}
	public function register(): void { add_action( 'aish_commercial_placement', array( $this, 'render' ), 10, 2 ); }
	public function render( string $placement, string $locale ): void {
		// All draft/private previews remain outside commercial observations.
		if ( is_admin() || isset( $_GET['aish_home_preview'] ) || isset( $_GET['aish_preview'] ) ) { return; }
		foreach ( $this->sponsors->forPlacement( $placement, $locale ) as $entry ) { self::card( $entry, false ); }
	}
	public static function card( array $entry, bool $preview ): void {
		$attributes = array();
		if ( ! $preview ) {
			try { $raw = apply_filters( 'aish_sponsor_tracking_attributes', array(), $entry ); if ( is_array( $raw ) ) { foreach ( $raw as $key => $value ) { if ( is_string( $key ) && preg_match( '/^data-commercial-[a-z-]{1,32}$/D', $key ) && is_string( $value ) && strlen( $value ) <= 4096 ) { $attributes[ $key ] = $value; } } } } catch ( \Throwable $error ) { /* Metrics cannot break navigation or free content. */ }
		}
		get_template_part( 'templates/commercial/sponsorship', null, array( 'entry' => $entry, 'attributes' => $attributes, 'preview' => $preview ) );
	}
}
