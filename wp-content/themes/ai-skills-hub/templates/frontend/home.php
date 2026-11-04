<?php
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$data = Router::context()['data'] ?? array();
$featured = $data['featured'] ?? array();
$latest = $data['latest'] ?? array();
$categories = $data['categories'] ?? array();
$platforms = $data['platforms'] ?? array();
$tutorials = $data['tutorials'] ?? array();
$spotlight = $featured[0] ?? $latest[0] ?? null;
?>
<section class="hero">
	<div class="hero-grid" aria-hidden="true"></div>
	<div class="aish-container hero-layout">
		<div class="hero-copy">
			<p class="hero-eyebrow"><span class="status-dot" aria-hidden="true"></span><?php echo esc_html( View::t( 'home.eyebrow' ) ); ?></p>
			<h1><?php echo esc_html( View::t( 'home.heading1' ) ); ?><br><span class="hero-accent"><?php echo esc_html( View::t( 'home.heading2' ) ); ?></span></h1>
			<p class="hero-description"><?php echo esc_html( View::t( 'home.description' ) ); ?></p>
			<form class="hero-search" method="get" action="<?php echo esc_url( View::url( 'skills' ) ); ?>" x-data="{ submitting: false }" @submit="submitting = true" :aria-busy="submitting">
				<label class="sr-only" for="hero-search"><?php echo esc_html( View::t( 'search.label' ) ); ?></label><?php echo View::icon( 'search' ); ?><input id="hero-search" type="search" name="q" placeholder="<?php echo esc_attr( View::t( 'search.placeholder' ) ); ?>" maxlength="100"><button type="submit" class="button button-primary"><span x-show="!submitting"><?php echo esc_html( View::t( 'search.action' ) ); ?></span><span x-cloak x-show="submitting"><?php echo esc_html( View::t( 'search.loading' ) ); ?></span><?php echo View::icon( 'arrow-right' ); ?></button>
			</form>
			<div class="hero-hints"><span><?php echo View::icon( 'shield' ); ?><?php echo esc_html( View::t( 'home.reviewed' ) ); ?></span><span><?php echo View::icon( 'globe' ); ?><?php echo esc_html( View::t( 'home.bilingual' ) ); ?></span><span><?php echo View::icon( 'github' ); ?><?php echo esc_html( View::t( 'home.sources' ) ); ?></span></div>
		</div>
		<div class="hero-visual">
			<div class="visual-orbit orbit-one" aria-hidden="true"></div><div class="visual-orbit orbit-two" aria-hidden="true"></div>
			<div class="visual-badge badge-code" aria-hidden="true"><?php echo View::icon( 'code' ); ?></div><div class="visual-badge badge-spark" aria-hidden="true"><?php echo View::icon( 'sparkles' ); ?></div>
			<div class="hero-document">
				<div class="document-top"><span class="document-dots" aria-hidden="true"><i></i><i></i><i></i></span><span>SKILL.md</span><?php echo View::icon( 'file' ); ?></div>
				<div class="document-body"><span class="document-symbol"><?php echo View::icon( 'layers' ); ?></span><p class="eyebrow"><?php echo esc_html( View::t( 'home.document.label' ) ); ?></p><h2><?php echo esc_html( $spotlight['title'] ?? View::t( 'home.document.title' ) ); ?></h2><p class="document-description"><?php echo esc_html( $spotlight['summary'] ?? View::t( 'home.document.description' ) ); ?></p>
					<?php if ( $spotlight ) : ?><a class="text-link" href="<?php echo esc_url( View::skillUrl( $spotlight ) ); ?>"><?php echo esc_html( View::t( 'skill.view' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a><?php else : ?><a class="text-link" href="<?php echo esc_url( View::url( 'skills' ) ); ?>"><?php echo esc_html( View::t( 'home.browse' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a><?php endif; ?>
				</div>
				<div class="document-bottom"><span class="status-dot" aria-hidden="true"></span><?php echo esc_html( View::t( 'home.document.note' ) ); ?><span class="ml-auto" aria-hidden="true">↗</span></div>
			</div>
			<div class="workflow-label"><span>01</span><?php echo esc_html( View::t( 'home.discover' ) ); ?><i aria-hidden="true">→</i><span>02</span><?php echo esc_html( View::t( 'home.understand' ) ); ?><i aria-hidden="true">→</i><span>03</span><?php echo esc_html( View::t( 'home.build' ) ); ?></div>
		</div>
	</div>
</section>
<section class="aish-container section-space">
	<div class="section-heading"><div><p class="eyebrow"><?php echo esc_html( View::t( 'home.featured.kicker' ) ); ?></p><h2><?php echo esc_html( View::t( 'home.featured.title' ) ); ?></h2><p><?php echo esc_html( View::t( 'home.featured.description' ) ); ?></p></div><a class="text-link" href="<?php echo esc_url( View::url( 'skills' ) ); ?>"><?php echo esc_html( View::t( 'home.browse' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div>
	<?php if ( $featured ) : ?><div class="skill-grid"><?php foreach ( $featured as $skill ) { get_template_part( 'template-parts/frontend/skill-card', null, array( 'skill' => $skill ) ); } ?></div><?php else : ?><div class="empty-state empty-state-compact"><?php echo View::icon( 'sparkles' ); ?><h3><?php echo esc_html( View::t( 'empty.featured.title' ) ); ?></h3><p><?php echo esc_html( View::t( 'empty.featured.description' ) ); ?></p></div><?php endif; ?>
</section>
<section class="category-section">
	<div class="aish-container section-space"><div class="section-heading"><div><p class="eyebrow"><?php echo esc_html( View::t( 'home.categories.kicker' ) ); ?></p><h2><?php echo esc_html( View::t( 'home.categories.title' ) ); ?></h2></div><p class="max-w-sm muted"><?php echo esc_html( View::t( 'home.categories.description' ) ); ?></p></div>
		<?php if ( $categories ) : ?><div class="category-grid"><?php foreach ( array_slice( $categories, 0, 8 ) as $category ) : ?><a class="category-card" href="<?php echo esc_url( View::url( 'category', array( 'slug' => $category['slug'] ) ) ); ?>"><span class="category-icon"><?php echo View::icon( 'grid' ); ?></span><span><strong><?php echo esc_html( $category['name'] ); ?></strong><small><?php echo esc_html( (string) ( $category['count'] ?? $category['skill_count'] ?? 0 ) . ' ' . View::t( 'common.resources' ) ); ?></small></span><?php echo View::icon( 'chevron-right' ); ?></a><?php endforeach; ?></div><?php else : ?><p class="empty-inline"><?php echo esc_html( View::t( 'empty.categories' ) ); ?></p><?php endif; ?>
	</div>
</section>
<section class="aish-container section-space">
	<div class="section-heading"><div><p class="eyebrow"><?php echo esc_html( View::t( 'home.latest.kicker' ) ); ?></p><h2><?php echo esc_html( View::t( 'home.latest.title' ) ); ?></h2><p><?php echo esc_html( View::t( 'home.latest.description' ) ); ?></p></div><a class="text-link" href="<?php echo esc_url( View::url( 'skills', array( 'sort' => 'newest' ) ) ); ?>"><?php echo esc_html( View::t( 'common.viewall' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div>
	<?php if ( $latest ) : ?><div class="skill-grid"><?php foreach ( $latest as $skill ) { get_template_part( 'template-parts/frontend/skill-card', null, array( 'skill' => $skill ) ); } ?></div><?php else : ?><div class="empty-state empty-state-compact"><?php echo View::icon( 'layers' ); ?><h3><?php echo esc_html( View::t( 'empty.skills.title' ) ); ?></h3><p><?php echo esc_html( View::t( 'empty.skills.description' ) ); ?></p><a class="text-link" href="<?php echo esc_url( View::url( 'submit' ) ); ?>"><?php echo esc_html( View::t( 'nav.submit' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div><?php endif; ?>
	<div class="platform-strip"><div><h2><?php echo esc_html( View::t( 'home.platforms.title' ) ); ?></h2><p><?php echo esc_html( View::t( 'home.platforms.description' ) ); ?></p></div><div class="platform-links"><?php if ( $platforms ) : foreach ( $platforms as $platform ) : ?><a class="chip" href="<?php echo esc_url( View::url( 'skills', array( 'platform' => $platform['slug'] ) ) ); ?>"><?php echo View::icon( 'code' ); ?><?php echo esc_html( $platform['name'] ); ?></a><?php endforeach; else : ?><span class="muted text-sm"><?php echo esc_html( View::t( 'empty.platforms' ) ); ?></span><?php endif; ?></div></div>
</section>
<section class="tutorial-section"><div class="aish-container section-space"><div class="section-heading"><div><p class="eyebrow"><?php echo esc_html( View::t( 'home.tutorials.kicker' ) ); ?></p><h2><?php echo esc_html( View::t( 'home.tutorials.title' ) ); ?></h2><p><?php echo esc_html( View::t( 'home.tutorials.description' ) ); ?></p></div><a class="text-link" href="<?php echo esc_url( View::url( 'tutorials' ) ); ?>"><?php echo esc_html( View::t( 'nav.tutorials' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div>
	<?php if ( $tutorials ) : ?><div class="skill-grid"><?php foreach ( $tutorials as $tutorial ) { get_template_part( 'template-parts/frontend/tutorial-card', null, array( 'tutorial' => $tutorial ) ); } ?></div><?php else : ?><div class="empty-state empty-state-compact"><?php echo View::icon( 'book' ); ?><h3><?php echo esc_html( View::t( 'empty.tutorials.title' ) ); ?></h3><p><?php echo esc_html( View::t( 'empty.tutorials.description' ) ); ?></p></div><?php endif; ?>
</div></section>
<section class="aish-container section-space"><div class="submit-banner"><div class="submit-banner-art" aria-hidden="true"><?php echo View::icon( 'sparkles' ); ?></div><div><p class="eyebrow"><?php echo esc_html( View::t( 'home.submit.kicker' ) ); ?></p><h2><?php echo esc_html( View::t( 'home.submit.title' ) ); ?></h2><p><?php echo esc_html( View::t( 'home.submit.description' ) ); ?></p></div><a class="button button-light" href="<?php echo esc_url( View::url( 'submit' ) ); ?>"><?php echo esc_html( View::t( 'nav.submit' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div></section>
