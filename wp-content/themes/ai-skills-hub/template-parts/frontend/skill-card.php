<?php
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$skill = $args['skill'] ?? array();
if ( empty( $skill['id'] ) || empty( $skill['title'] ) ) { return; }
$categories = $skill['categories'] ?? array();
$platforms = $skill['platforms'] ?? array();
?>
<article class="skill-card">
	<div class="flex items-start justify-between gap-4"><span class="resource-icon"><?php echo View::icon( 'code' ); ?></span><span class="chip chip-success"><?php echo View::icon( 'check' ); ?><?php echo esc_html( View::t( 'skill.reviewed' ) ); ?></span></div>
	<div class="mt-6"><h3><a class="card-title" href="<?php echo esc_url( View::skillUrl( $skill ) ); ?>"><?php echo esc_html( $skill['title'] ); ?></a></h3><p class="card-summary"><?php echo esc_html( wp_strip_all_tags( (string) ( $skill['summary'] ?? '' ) ) ); ?></p></div>
	<div class="card-tags">
		<?php foreach ( array_slice( $categories, 0, 2 ) as $category ) : ?><a class="chip chip-soft" href="<?php echo esc_url( View::url( 'category', array( 'slug' => $category['slug'] ) ) ); ?>"><?php echo esc_html( $category['name'] ); ?></a><?php endforeach; ?>
		<?php foreach ( array_slice( $platforms, 0, 2 ) as $platform ) : ?><span class="chip"><?php echo esc_html( $platform['name'] ); ?></span><?php endforeach; ?>
	</div>
	<div class="card-bottom"><span><?php if ( ! empty( $skill['updated_at'] ) ) { echo esc_html( View::date( $skill['updated_at'] ) ); } else { echo esc_html( View::t( 'skill.resource' ) ); } ?></span><a class="card-open" href="<?php echo esc_url( View::skillUrl( $skill ) ); ?>" aria-label="<?php echo esc_attr( View::t( 'skill.view' ) . ': ' . $skill['title'] ); ?>"><?php echo View::icon( 'arrow-right' ); ?></a></div>
</article>
