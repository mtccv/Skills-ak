<?php
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;
use AISkillsHub\Core\Learning\Help;

defined( 'ABSPATH' ) || exit;
$context = Router::context();
$data = $context['data'] ?? array();
$tutorial = $data['tutorial'] ?? array();
$reading = Help::navigation( (string) ( $tutorial['content'] ?? '' ) ) + array( 'related' => array(), 'category' => '' );
try { $reading = \AISkillsHub\Core\Core::boot()->get( 'learning_help' )->reading( $tutorial, $context['locale'] ); } catch ( \Throwable $error ) { /* Optional related navigation cannot interrupt approved tutorial reading. */ }
$zh = 'zh_CN' === $context['locale'];
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
		<?php if ( $reading['toc'] ) : ?><nav class="learning-toc" aria-labelledby="tutorial-reading-nav"><h2 id="tutorial-reading-nav"><?php echo esc_html( $zh ? '本页内容' : 'On this page' ); ?></h2><ol><?php foreach ( $reading['toc'] as $heading ) : ?><li<?php if ( 'h3' === $heading['level'] ) { echo ' class="learning-toc-sub"'; } ?>><a href="#<?php echo esc_attr( $heading['id'] ); ?>"><?php echo esc_html( $heading['title'] ); ?></a></li><?php endforeach; ?></ol></nav><?php endif; ?>
		<div class="prose-aish learning-content"><?php echo $reading['content']; ?></div>
		<footer class="mt-12 flex flex-wrap gap-3 border-t border-slate-200 pt-7">
			<a class="button button-secondary" href="<?php echo esc_url( Help::url( $context['locale'], '' !== $reading['category'] ? array( 'help_category' => $reading['category'] ) : array() ) ); ?>"><?php echo esc_html( $zh ? '帮助中心' : 'Help center' ); ?></a>
			<a class="button button-secondary" href="<?php echo esc_url( View::url( 'tutorials' ) ); ?>">← <?php echo esc_html( View::t( 'detail_back_tutorials' ) ); ?></a>
		</footer>
	</article>
	<?php if ( $reading['related'] ) : ?><section class="mx-auto max-w-3xl pb-8" aria-labelledby="tutorial-related-reading"><h2 id="tutorial-related-reading" class="text-xl font-semibold"><?php echo esc_html( $zh ? '继续阅读' : 'Keep reading' ); ?></h2><ul class="learning-article-list"><?php foreach ( $reading['related'] as $item ) : ?><li><article><h3><a href="<?php echo esc_url( View::tutorialUrl( $item, $context['locale'] ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></h3><p><?php echo esc_html( $item['summary'] ); ?></p></article></li><?php endforeach; ?></ul></section><?php endif; ?>
	<?php if ( ! empty( $data['relatedSkills'] ) ) : ?>
		<section class="pb-10 pt-8" aria-labelledby="tutorial-related-skills">
			<div class="section-heading"><h2 id="tutorial-related-skills"><?php echo esc_html( View::t( 'detail_tutorial_skills' ) ); ?></h2></div>
			<div class="skill-grid"><?php foreach ( $data['relatedSkills'] as $skill ) { get_template_part( 'template-parts/frontend/skill-card', null, array( 'skill' => $skill ) ); } ?></div>
		</section>
	<?php endif; ?>
</div>
