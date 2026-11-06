<?php
use AISkillsHub\Core\Accounts\Frontend as Accounts;
use AISkillsHub\Core\Accounts\Strings as AccountStrings;
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$frontend = $args['frontend'];
if ( ! in_array( $frontend, array( \AISkillsHub\Core\Submissions\Frontend::class, \AISkillsHub\Core\Developers\Frontend::class ), true ) ) { return; }
$c = $frontend::context(); $locale = $c['locale']; $route = $c['route']; $params = $c['params']; $profile = $c['profile'];
$t = static fn( string $key ): string => $frontend::t( $key, $locale );
$a = static fn( string $key ): string => AccountStrings::get( $key, $locale );
$url = static fn( string $target, array $query = array() ): string => $frontend::url( $target, $locale, $query );
$public = static fn( string $target ): string => Router::url( $target, array(), $locale );
$nav = static function () use ( $frontend, $a, $locale, $route, $url, $params ): void {
	echo '<nav class="account-nav" aria-label="' . esc_attr( $a( 'menu' ) ) . '"><a href="' . esc_url( Accounts::url( 'account', $locale ) ) . '">' . esc_html( $a( 'overview' ) ) . '</a>';
	do_action( 'aish_account_navigation', $locale, $route );
	foreach ( array( 'notifications', 'profile', 'settings', 'security', 'privacy' ) as $item ) { echo '<a href="' . esc_url( Accounts::url( $item, $locale ) ) . '">' . esc_html( $a( $item ) ) . '</a>'; }
	echo '</nav><form method="post" action="' . esc_url( $url( $route, $params ) ) . '" class="account-form" data-contribution-form>' . $frontend::fields( 'logout' ) . '<button type="submit" class="account-nav-logout">' . esc_html( $a( 'logout' ) ) . '</button></form>';
};
?>
<!doctype html>
<html lang="<?php echo esc_attr( 'en_US' === $locale ? 'en' : 'zh-CN' ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#F9FAFB"><?php wp_head(); ?></head>
<body <?php body_class( 'aish-account-site aish-contributions-site' ); ?>><?php wp_body_open(); ?>
<a class="account-skip" href="#account-main"><?php echo esc_html( $a( 'skip' ) ); ?></a>
<header class="account-site-header"><div class="account-container account-header-inner"><a class="account-brand" href="<?php echo esc_url( $public( 'home' ) ); ?>"><span class="account-brand-symbol" aria-hidden="true"><?php echo View::icon( 'layers' ); ?></span><span>AI Skills <span class="account-muted">Hub</span></span></a><nav class="account-public-nav" aria-label="<?php echo esc_attr( $a( 'explore' ) ); ?>"><a href="<?php echo esc_url( $public( 'skills' ) ); ?>"><?php echo esc_html( $a( 'explore' ) ); ?></a><a href="<?php echo esc_url( $public( 'tutorials' ) ); ?>"><?php echo esc_html( $a( 'tutorials' ) ); ?></a></nav><nav class="account-language" aria-label="<?php echo esc_attr( $a( 'language' ) ); ?>"><?php foreach ( array( 'zh_CN' => '中', 'en_US' => 'EN' ) as $language => $label ) : ?><a href="<?php echo esc_url( $frontend::url( $route, $language, $params ) ); ?>" lang="<?php echo esc_attr( 'zh_CN' === $language ? 'zh-CN' : 'en' ); ?>" <?php echo $language === $locale ? 'aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav></div></header>
<main id="account-main" class="account-main" tabindex="-1"><div class="account-container"><div class="account-breadcrumb"><a href="<?php echo esc_url( $public( 'home' ) ); ?>"><?php echo esc_html( $a( 'home' ) ); ?></a><span aria-hidden="true">/</span><a href="<?php echo esc_url( Accounts::url( 'account', $locale ) ); ?>"><?php echo esc_html( $a( 'account' ) ); ?></a><span class="account-badge"><?php echo esc_html( $a( 'private' ) ); ?></span></div>
<div class="account-layout"><aside class="account-sidebar"><div class="account-panel account-sidebar-panel"><div class="account-identity"><span class="account-avatar" aria-hidden="true"><?php echo View::icon( 'layers' ); ?></span><div><p class="account-strong"><?php echo esc_html( $profile['display_name'] ?? '' ); ?></p><p class="account-help"><?php echo esc_html( $a( 'private' ) ); ?></p></div></div><?php $nav(); ?></div></aside><div class="account-content">
<details class="account-mobile-nav account-panel"><summary><?php echo esc_html( $a( 'menu' ) ); ?> <span class="account-muted"><?php echo esc_html( $t( $route ) ); ?></span></summary><?php $nav(); ?></details>
<header class="account-page-heading"><h1><?php echo esc_html( $t( $route ) ); ?></h1><p><?php echo esc_html( $t( 'private_help' ) ); ?></p></header>
<?php if ( $c['error'] ) : ?><div class="account-notice account-error" id="contribution-feedback" role="alert" tabindex="-1" data-contribution-feedback><h2><?php echo esc_html( $t( 'error_title' ) ); ?></h2><p><?php echo esc_html( $t( 'error.' . $c['error'] ) ); ?></p><a class="account-text-action" href="<?php echo esc_url( $url( $route, $params ) ); ?>"><?php echo esc_html( $t( 'reload' ) ); ?></a></div><?php endif; ?>
<?php if ( $c['message'] ) : ?><div class="account-notice" role="status" tabindex="-1" data-contribution-feedback><p><?php echo esc_html( $t( 'message.' . $c['message'] ) ); ?></p></div><?php endif; ?>
<?php get_template_part( \AISkillsHub\Core\Submissions\Frontend::class === $frontend ? 'templates/submissions/content' : 'templates/developers/content' ); ?>
</div></div></div></main><footer class="account-site-footer"><div class="account-container"><p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> AI Skills Hub</p><a href="<?php echo esc_url( $public( 'contact' ) ); ?>"><?php echo esc_html( $a( 'contact' ) ); ?></a></div></footer><?php wp_footer(); ?></body></html>
