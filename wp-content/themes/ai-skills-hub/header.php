<?php
defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'bg-slate-50 text-slate-900' ); ?>>
<?php wp_body_open(); ?>
<a class="sr-only focus:not-sr-only" href="#main"><?php esc_html_e( 'Skip to content', 'ai-skills-hub' ); ?></a>
<header class="mx-auto max-w-5xl px-6 py-8">
	<a class="text-xl font-bold" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
</header>
