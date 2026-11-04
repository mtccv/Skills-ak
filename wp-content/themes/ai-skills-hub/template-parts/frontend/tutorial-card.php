<?php
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$tutorial = $args['tutorial'] ?? array();
if ( empty( $tutorial['id'] ) || empty( $tutorial['title'] ) ) { return; }
?>
<article class="tutorial-card">
	<a class="tutorial-art" href="<?php echo esc_url( View::tutorialUrl( $tutorial ) ); ?>" aria-label="<?php echo esc_attr( $tutorial['title'] ); ?>"><span class="tutorial-art-grid" aria-hidden="true"></span><span class="tutorial-art-icon" aria-hidden="true"><?php echo View::icon( 'book' ); ?></span><span class="tutorial-art-label"><?php echo esc_html( View::t( 'tutorial.guide' ) ); ?> <span aria-hidden="true">↗</span></span></a>
	<div class="p-6"><p class="eyebrow mb-3"><?php echo esc_html( View::t( 'tutorial.fieldnotes' ) ); ?></p><h3><a class="card-title" href="<?php echo esc_url( View::tutorialUrl( $tutorial ) ); ?>"><?php echo esc_html( $tutorial['title'] ); ?></a></h3><p class="card-summary"><?php echo esc_html( wp_strip_all_tags( (string) ( $tutorial['summary'] ?? $tutorial['excerpt'] ?? '' ) ) ); ?></p><div class="card-bottom"><span><?php echo esc_html( View::date( $tutorial['published_at'] ?? $tutorial['date'] ?? null ) ); ?></span><a class="text-link" href="<?php echo esc_url( View::tutorialUrl( $tutorial ) ); ?>"><?php echo esc_html( View::t( 'tutorial.read' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div></div>
</article>
