<?php
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$context = Router::context();
$templates = array( 'home' => 'home', 'skills' => 'listing', 'category' => 'listing', 'tag' => 'listing', 'search' => 'listing', 'skill' => 'skill', 'tutorials' => 'tutorials', 'tutorial' => 'tutorial', 'about' => 'page', 'contact' => 'page', 'submit' => 'page', '404' => 'not-found' );
get_header();
?>
<main id="main">
<?php if ( ! empty( $context['data']['translation_missing'] ) ) : ?>
	<div class="aish-container pt-6"><p class="rounded-xl border border-violet-100 bg-violet-50 px-5 py-4 text-sm leading-7 text-violet-700" role="status"><?php echo esc_html( View::t( 'translation.missing' ) ); ?></p></div>
<?php endif; ?>
<?php if ( ! empty( $context['error'] ) && (int) ( $context['status'] ?? 200 ) >= 500 ) : ?>
	<div class="aish-container py-24"><div class="empty-state"><span class="empty-icon"><?php echo View::icon( 'layers' ); ?></span><h1><?php echo esc_html( View::t( 'error.title' ) ); ?></h1><p><?php echo esc_html( View::t( 'error.description' ) ); ?></p><a class="button button-secondary" href="<?php echo esc_url( View::url( 'home' ) ); ?>"><?php echo esc_html( View::t( 'nav.home' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div></div>
<?php else : ?>
	<?php get_template_part( 'templates/frontend/' . ( $templates[ $context['route'] ?? '404' ] ?? 'not-found' ) ); ?>
<?php endif; ?>
</main>
<?php get_footer(); ?>
