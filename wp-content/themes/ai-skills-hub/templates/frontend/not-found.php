<?php
use AISkillsHub\Core\Frontend\View;

defined( 'ABSPATH' ) || exit;
?>
<div class="aish-container py-20 sm:py-28">
	<div class="mx-auto max-w-2xl text-center">
		<div class="mx-auto mb-8 flex h-24 w-24 items-center justify-center rounded-3xl border border-violet-100 bg-violet-50 text-2xl font-semibold text-violet-700" aria-hidden="true">404</div>
		<p class="eyebrow mb-4"><?php echo esc_html( View::t( 'not_found_eyebrow' ) ); ?></p>
		<h1 class="text-4xl font-semibold tracking-tight sm:text-5xl"><?php echo esc_html( View::t( 'not_found_title' ) ); ?></h1>
		<p class="mx-auto mt-6 max-w-lg text-lg leading-8 muted"><?php echo esc_html( View::t( 'not_found_copy' ) ); ?></p>
		<div class="mt-9 flex flex-wrap justify-center gap-3">
			<a class="button button-primary" href="<?php echo esc_url( View::url( 'skills' ) ); ?>"><?php echo esc_html( View::t( 'detail_browse_skills' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a>
			<a class="button button-secondary" href="<?php echo esc_url( View::url( 'home' ) ); ?>"><?php echo esc_html( View::t( 'not_found_home' ) ); ?></a>
		</div>
	</div>
</div>
