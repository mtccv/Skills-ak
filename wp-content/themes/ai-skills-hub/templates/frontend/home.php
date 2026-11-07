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
<?php do_action( 'aish_discovery_home', Router::context()['locale'], $data ); ?>
<section class="aish-container section-space"><div class="submit-banner"><div class="submit-banner-art" aria-hidden="true"><?php echo View::icon( 'sparkles' ); ?></div><div><p class="eyebrow"><?php echo esc_html( View::t( 'home.submit.kicker' ) ); ?></p><h2><?php echo esc_html( View::t( 'home.submit.title' ) ); ?></h2><p><?php echo esc_html( View::t( 'home.submit.description' ) ); ?></p></div><a class="button button-light" href="<?php echo esc_url( View::url( 'submit' ) ); ?>"><?php echo esc_html( View::t( 'nav.submit' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div></section>
