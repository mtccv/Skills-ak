<?php
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;

defined( 'ABSPATH' ) || exit;
$context = Router::context();
$locale = $context['locale'] ?? 'zh_CN';
$route = $context['route'] ?? 'home';
$navigation = array( 'home' => 'nav.home', 'skills' => 'nav.skills', 'tutorials' => 'nav.tutorials', 'about' => 'nav.about' );
?>
<!doctype html>
<html lang="<?php echo esc_attr( 'en_US' === $locale ? 'en' : 'zh-CN' ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#f6f7fb">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'aish-site' ); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main"><?php echo esc_html( View::t( 'nav.skip' ) ); ?></a>
<header class="site-header">
	<div class="aish-container header-inner">
		<a class="brand" href="<?php echo esc_url( View::url( 'home' ) ); ?>" aria-label="AI Skills Hub">
			<span class="brand-symbol" aria-hidden="true"><svg viewBox="0 0 40 40" fill="none"><path d="m20 5 13 7.5v15L20 35 7 27.5v-15L20 5Z" stroke="currentColor" stroke-width="2.2"/><path d="m13 16 7-4 7 4-7 4-7-4Z" fill="currentColor"/><path d="m13 23 7 4 7-4M20 20v7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
			<span>AI Skills<span class="brand-hub"> Hub</span></span>
		</a>
		<nav class="desktop-nav" aria-label="<?php echo esc_attr( View::t( 'nav.main' ) ); ?>">
			<?php foreach ( $navigation as $target => $key ) : ?>
			<a href="<?php echo esc_url( View::url( $target ) ); ?>" <?php echo $target === $route || ( 'skills' === $target && in_array( $route, array( 'skill', 'category', 'tag' ), true ) ) || ( 'tutorials' === $target && 'tutorial' === $route ) ? 'aria-current="page"' : ''; ?>><?php echo esc_html( View::t( $key ) ); ?></a>
			<?php endforeach; ?>
		</nav>
		<div class="header-actions">
			<nav class="language-switch" aria-label="<?php echo esc_attr( View::t( 'nav.language' ) ); ?>">
				<a lang="zh-CN" hreflang="zh-CN" href="<?php echo esc_url( View::switchUrl( 'zh_CN' ) ); ?>" <?php echo 'zh_CN' === $locale ? 'aria-current="true"' : ''; ?>>中</a>
				<span aria-hidden="true">/</span>
				<a lang="en" hreflang="en" href="<?php echo esc_url( View::switchUrl( 'en_US' ) ); ?>" <?php echo 'en_US' === $locale ? 'aria-current="true"' : ''; ?>>EN</a>
			</nav>
			<a class="button button-primary button-small hidden sm:inline-flex" href="<?php echo esc_url( View::url( 'submit' ) ); ?>"><?php echo View::icon( 'plus' ); ?><?php echo esc_html( View::t( 'nav.submit' ) ); ?></a>
		</div>
	</div>
	<details class="mobile-navigation" x-data @keydown.escape.window="$el.open = false">
		<summary class="aish-container"><?php echo View::icon( 'menu' ); ?><?php echo esc_html( View::t( 'nav.menu' ) ); ?></summary>
		<nav class="aish-container" aria-label="<?php echo esc_attr( View::t( 'nav.mobile' ) ); ?>">
			<?php foreach ( $navigation as $target => $key ) : ?><a href="<?php echo esc_url( View::url( $target ) ); ?>"><?php echo esc_html( View::t( $key ) ); ?></a><?php endforeach; ?>
			<a href="<?php echo esc_url( View::url( 'submit' ) ); ?>"><?php echo esc_html( View::t( 'nav.submit' ) ); ?></a>
		</nav>
	</details>
</header>
