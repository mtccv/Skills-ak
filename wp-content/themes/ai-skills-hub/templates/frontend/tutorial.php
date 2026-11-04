<?php
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;

defined( 'ABSPATH' ) || exit;
$context = Router::context();
$data = $context['data'] ?? array();
$tutorial = $data['tutorial'] ?? array();
?>
<div class="aish-container py-8 sm:py-12">
	<?php echo View::breadcrumbs(); ?>
	<article class="mx-auto max-w-3xl py-8 sm:py-12">
		<header class="mb-10">
			<p class="eyebrow mb-5"><?php echo esc_html( View::t( 'detail_tutorial_label' ) ); ?></p>
			<h1 class="break-words text-4xl font-semibold leading-tight tracking-tight sm:text-5xl"><?php echo esc_html( (string) ( $tutorial['title'] ?? '' ) ); ?></h1>
			<?php if ( ! empty( $tutorial['summary'] ) ) : ?><p class="mt-6 text-lg leading-8 muted"><?php echo esc_html( $tutorial['summary'] ); ?></p><?php endif; ?>
			<div class="mt-7 flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-slate-200 pb-7 text-sm muted">
				<?php if ( ! empty( $tutorial['author'] ) && is_string( $tutorial['author'] ) ) : ?><span><?php echo esc_html( View::t( 'detail_by' ) . ' ' . $tutorial['author'] ); ?></span><?php endif; ?>
				<span><?php echo esc_html( View::t( 'detail_updated' ) . ' ' . View::date( $tutorial['updated_at'] ?? null ) ); ?></span>
			</div>
		</header>
		<div class="prose-aish"><?php echo View::rich( (string) ( $tutorial['content'] ?? '' ) ); ?></div>
		<footer class="mt-12 border-t border-slate-200 pt-7">
			<a class="button button-secondary" href="<?php echo esc_url( View::url( 'tutorials' ) ); ?>">← <?php echo esc_html( View::t( 'detail_back_tutorials' ) ); ?></a>
		</footer>
	</article>
	<?php if ( ! empty( $data['relatedSkills'] ) ) : ?>
		<section class="pb-10 pt-8" aria-labelledby="tutorial-related-skills">
			<div class="section-heading"><h2 id="tutorial-related-skills"><?php echo esc_html( View::t( 'detail_tutorial_skills' ) ); ?></h2></div>
			<div class="skill-grid"><?php foreach ( $data['relatedSkills'] as $skill ) { get_template_part( 'template-parts/frontend/skill-card', null, array( 'skill' => $skill ) ); } ?></div>
		</section>
	<?php endif; ?>
</div>
