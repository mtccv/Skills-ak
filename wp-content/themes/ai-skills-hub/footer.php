<?php
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
?>
<footer class="site-footer">
	<div class="aish-container footer-grid">
		<div class="max-w-md">
			<a class="brand" href="<?php echo esc_url( View::url( 'home' ) ); ?>"><span class="brand-symbol" aria-hidden="true"><svg viewBox="0 0 40 40" fill="none"><path d="m20 5 13 7.5v15L20 35 7 27.5v-15L20 5Z" stroke="currentColor" stroke-width="2.2"/><path d="m13 16 7-4 7 4-7 4-7-4Z" fill="currentColor"/><path d="m13 23 7 4 7-4M20 20v7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span>AI Skills<span class="brand-hub"> Hub</span></span></a>
			<p class="mt-5 max-w-sm text-sm leading-7 muted"><?php echo esc_html( View::t( 'footer.description' ) ); ?></p>
		</div>
		<div><h2 class="footer-heading"><?php echo esc_html( View::t( 'footer.explore' ) ); ?></h2><nav class="footer-links" aria-label="<?php echo esc_attr( View::t( 'footer.explore' ) ); ?>"><a href="<?php echo esc_url( View::url( 'skills' ) ); ?>"><?php echo esc_html( View::t( 'nav.skills' ) ); ?></a><a href="<?php echo esc_url( View::url( 'tutorials' ) ); ?>"><?php echo esc_html( View::t( 'nav.tutorials' ) ); ?></a><a href="<?php echo esc_url( View::url( 'search' ) ); ?>"><?php echo esc_html( View::t( 'search.label' ) ); ?></a></nav></div>
		<div><h2 class="footer-heading"><?php echo esc_html( View::t( 'footer.community' ) ); ?></h2><nav class="footer-links" aria-label="<?php echo esc_attr( View::t( 'footer.community' ) ); ?>"><a href="<?php echo esc_url( View::url( 'about' ) ); ?>"><?php echo esc_html( View::t( 'nav.about' ) ); ?></a><a href="<?php echo esc_url( View::url( 'contact' ) ); ?>"><?php echo esc_html( View::t( 'nav.contact' ) ); ?></a><a href="<?php echo esc_url( View::url( 'submit' ) ); ?>"><?php echo esc_html( View::t( 'nav.submit' ) ); ?></a></nav></div>
	</div>
	<div class="aish-container footer-bottom"><p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> AI Skills Hub</p><p><?php echo esc_html( View::t( 'footer.note' ) ); ?></p></div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
