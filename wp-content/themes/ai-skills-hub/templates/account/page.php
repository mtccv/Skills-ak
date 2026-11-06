<?php
use AISkillsHub\Core\Accounts\Frontend as AccountView;
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;

defined( 'ABSPATH' ) || exit;
$context = AccountView::context();
$route = $context['route']; $locale = $context['locale'];
$profile = $context['profile'] ?? array();
$isAccount = in_array( $route, array( 'account', 'profile', 'settings', 'security', 'privacy', 'notifications' ), true );
$t = static fn( string $key ): string => AccountView::t( $key );
$url = static fn( string $target ): string => AccountView::url( $target, $locale );
$public = static fn( string $target ): string => Router::url( $target, array(), $locale );
$value = static fn( string $field, string $default = '' ): string => (string) ( $context['values'][ $field ] ?? $profile[ $field ] ?? $default );
$form = static function ( string $action, bool $multipart = false ) use ( $url, $route ): void {
	echo '<form method="post" action="' . esc_url( $url( $route ) ) . '" class="account-form" data-account-form' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . '>';
	echo AccountView::fields( $action );
};
$field = static function ( string $action, string $name, string $label, string $type = 'text', string $autocomplete = '', string $help = '', bool $required = true ) use ( $t, $value, $context ): void {
	$id = $action . '-' . $name;
	$isPassword = 'password' === $type;
	$invalid = in_array( $name, $context['error_fields'] ?? array(), true );
	echo '<div class="account-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $t( $label ) ) . '</label><div class="account-input-row">';
	$describedBy = array(); if ( $help ) { $describedBy[] = $id . '-help'; } if ( $invalid ) { $describedBy[] = 'account-feedback'; }
	echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '"' . ( $required ? ' required' : '' ) . ( $autocomplete ? ' autocomplete="' . esc_attr( $autocomplete ) . '"' : '' ) . ( $isPassword ? ( 'new-password' === $autocomplete ? ' minlength="12"' : '' ) . ' maxlength="256"' : ' maxlength="' . ( 'email' === $type ? '254' : ( 'url' === $type ? '2048' : '60' ) ) . '" value="' . esc_attr( $value( $name ) ) . '"' ) . ( $describedBy ? ' aria-describedby="' . esc_attr( implode( ' ', $describedBy ) ) . '"' : '' ) . ( $invalid ? ' aria-invalid="true"' : '' ) . '>';
	if ( $isPassword ) { echo '<button type="button" hidden data-password-toggle="' . esc_attr( $id ) . '" data-show="' . esc_attr( $t( 'show_password' ) ) . '" data-hide="' . esc_attr( $t( 'hide_password' ) ) . '" aria-pressed="false" class="account-password-toggle">' . esc_html( $t( 'show_password' ) ) . '</button>'; }
	echo '</div>';
	if ( $help ) { echo '<p class="account-help" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $t( $help ) ) . '</p>'; }
	echo '</div>';
};
$button = static function ( string $label, bool $disabled = false, bool $danger = false ) use ( $t ): void {
	echo '<button type="submit" class="account-button ' . ( $danger ? 'account-button-danger' : 'account-button-primary' ) . '" data-pending-label="' . esc_attr( $t( 'working' ) ) . '"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( $t( $label ) ) . '</button>';
};
$language = static function ( string $id, string $current ) use ( $t ): void {
	echo '<div class="account-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $t( 'language' ) ) . '</label><select name="locale" id="' . esc_attr( $id ) . '"><option value="zh_CN"' . selected( $current, 'zh_CN', false ) . '>简体中文</option><option value="en_US"' . selected( $current, 'en_US', false ) . '>English</option></select></div>';
};
$avatar = static function ( bool $large = false ) use ( $context, $profile ): void {
	echo '<span class="account-avatar' . ( $large ? ' account-avatar-large' : '' ) . '" aria-hidden="true">';
	if ( ! empty( $context['avatar_url'] ) ) { echo '<img src="' . esc_url( $context['avatar_url'] ) . '" width="80" height="80" alt="">'; }
	else { preg_match( '/^./us', $profile['display_name'] ?? 'A', $initial ); echo esc_html( strtoupper( $initial[0] ?? 'A' ) ); }
	echo '</span>';
};
$navigation = array( 'account' => 'overview', 'notifications' => 'notifications', 'profile' => 'profile', 'settings' => 'settings', 'security' => 'security', 'privacy' => 'privacy' );
$nav = static function ( string $label ) use ( $navigation, $route, $url, $t, $form ): void {
	echo '<nav class="account-nav" aria-label="' . esc_attr( $label ) . '">';
	foreach ( $navigation as $target => $key ) { echo '<a href="' . esc_url( $url( $target ) ) . '"' . ( $target === $route ? ' aria-current="page"' : '' ) . '>' . esc_html( $t( $key ) ) . '</a>'; if ( 'account' === $target ) { do_action( 'aish_account_navigation', AccountView::context()['locale'], $route ); } }
	echo '</nav>';
	$form( 'logout' ); echo '<button class="account-nav-logout" type="submit">' . esc_html( $t( 'logout' ) ) . '</button></form>';
};
$mailStatus = static function () use ( $context, $t ): void {
	$mail = $context['mail'] ?? null;
	if ( ! is_array( $mail ) ) { return; }
	echo '<div class="account-notice"><p>' . esc_html( $t( 'mail.' . $mail['status'] ) ) . '</p><p class="account-help">' . esc_html( $t( 'email_delivery' ) ) . '</p></div>';
};
$notifications = static function ( bool $full = false ) use ( $context, $t, $url, $form ): void {
	if ( ! array_key_exists( 'notifications', $context ) ) { echo '<p class="account-empty">' . esc_html( $t( 'error.unavailable' ) ) . '</p>'; return; }
	$items = $context['notifications'] ?? array();
	if ( ! $items ) { echo '<p class="account-empty">' . esc_html( $t( 'no_notifications' ) ) . '</p>'; return; }
	echo '<div class="account-notices-list">';
	foreach ( array_slice( $items, 0, $full ? 20 : 5 ) as $item ) {
		$communityKey = $item['template_key'] ?? '';
		$communityTitle = in_array( $communityKey, array( 'review_approved', 'review_rejected', 'review_hidden', 'review_withdrawn', 'report_resolved' ), true ) && class_exists( '\AISkillsHub\Core\Community\Strings' ) ? \AISkillsHub\Core\Community\Strings::get( 'notification.' . $communityKey, $context['locale'] ) : null;
		$contributionTitle = class_exists( '\AISkillsHub\Core\Contributions\Strings' ) ? \AISkillsHub\Core\Contributions\Strings::notification( (string) $communityKey, $context['locale'] ) : null;
		$titleKey = match ( $item['template_key'] ?? '' ) { 'email_verified' => 'state.active', 'email_changed' => 'change_email', 'password_changed' => 'change_password', 'sessions_revoked' => 'message.sessions', default => 'notifications' };
		echo '<article class="account-notification"><div><h3>' . esc_html( $contributionTitle ?? $communityTitle ?? $t( $titleKey ) ) . '</h3><p class="account-help">' . esc_html( $t( empty( $item['read_at'] ) ? 'unread' : 'read' ) ) . ' · ' . esc_html( $item['created_at'] ?? '' ) . ' UTC</p></div>';
		if ( $full && empty( $item['read_at'] ) ) { $form( 'read' ); echo '<input type="hidden" name="notification_id" value="' . esc_attr( (string) $item['id'] ) . '"><button class="account-text-action" type="submit">' . esc_html( $t( 'mark_read' ) ) . '</button></form>'; }
		echo '</article>';
	}
	echo '</div>';
	if ( ! $full ) { echo '<a class="account-text-action" href="' . esc_url( $url( 'notifications' ) ) . '">' . esc_html( $t( 'notifications' ) ) . ' →</a>'; }
};
?>
<!doctype html>
<html lang="<?php echo esc_attr( 'en_US' === $locale ? 'en' : 'zh-CN' ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#F9FAFB">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'aish-account-site' ); ?> data-unsaved-message="<?php echo esc_attr( $t( 'unsaved' ) ); ?>">
<?php wp_body_open(); ?>
<a class="account-skip" href="#account-main"><?php echo esc_html( $t( 'skip' ) ); ?></a>
<header class="account-site-header">
	<div class="account-container account-header-inner">
		<a class="account-brand" href="<?php echo esc_url( $public( 'home' ) ); ?>"><span class="account-brand-symbol" aria-hidden="true"><?php echo View::icon( 'layers' ); ?></span><span>AI Skills <span class="account-muted">Hub</span></span></a>
		<?php if ( $isAccount ) : ?><nav class="account-public-nav" aria-label="<?php echo esc_attr( $t( 'explore' ) ); ?>"><a href="<?php echo esc_url( $public( 'skills' ) ); ?>"><?php echo esc_html( $t( 'explore' ) ); ?></a><a href="<?php echo esc_url( $public( 'tutorials' ) ); ?>"><?php echo esc_html( $t( 'tutorials' ) ); ?></a></nav><?php endif; ?>
		<nav class="account-language" aria-label="<?php echo esc_attr( $t( 'language' ) ); ?>"><a href="<?php echo esc_url( AccountView::url( $route, 'zh_CN' ) ); ?>" lang="zh-CN" <?php echo 'zh_CN' === $locale ? 'aria-current="true"' : ''; ?>>中</a><span aria-hidden="true">/</span><a href="<?php echo esc_url( AccountView::url( $route, 'en_US' ) ); ?>" lang="en" <?php echo 'en_US' === $locale ? 'aria-current="true"' : ''; ?>>EN</a></nav>
	</div>
</header>
<main id="account-main" tabindex="-1" class="account-main">
	<div class="account-container">
	<?php if ( $isAccount && $profile ) : ?>
		<div class="account-breadcrumb"><a href="<?php echo esc_url( $public( 'home' ) ); ?>"><?php echo esc_html( $t( 'home' ) ); ?></a><span aria-hidden="true">/</span><span><?php echo esc_html( $t( 'account' ) ); ?></span><span class="account-badge"><?php echo esc_html( $t( 'private' ) ); ?></span></div>
		<div class="account-layout">
			<aside class="account-sidebar"><div class="account-panel account-sidebar-panel"><div class="account-identity"><?php $avatar(); ?><div><p class="account-strong"><?php echo esc_html( $profile['display_name'] ); ?></p><p class="account-help"><?php echo esc_html( $t( 'state.' . $profile['state'] ) ); ?></p></div></div><p class="account-eyebrow"><?php echo esc_html( $t( 'workspace' ) ); ?></p><?php $nav( $t( 'menu' ) ); ?></div><div class="account-sidebar-tip"><h2><?php echo esc_html( $t( 'explore' ) ); ?></h2><p><?php echo esc_html( $t( 'auth_description' ) ); ?></p><a class="account-text-action" href="<?php echo esc_url( $public( 'skills' ) ); ?>"><?php echo esc_html( $t( 'explore' ) ); ?> <?php echo View::icon( 'arrow-right' ); ?></a></div></aside>
			<div class="account-content">
				<details class="account-mobile-nav account-panel"><summary><?php echo esc_html( $t( 'menu' ) ); ?> <span class="account-muted"><?php echo esc_html( $t( $route ) ); ?></span></summary><?php $nav( $t( 'menu' ) ); ?></details>
	<?php else : ?><div class="account-auth-wrap"><a class="account-back" href="<?php echo esc_url( $public( 'skills' ) ); ?>">← <?php echo esc_html( $t( 'explore' ) ); ?></a><div class="account-panel account-auth-panel"><?php endif; ?>
	<?php if ( 'account' !== $route || ! $profile ) : ?><header class="account-page-heading"><h1><?php echo esc_html( $t( $route ) ); ?></h1><?php if ( ! $isAccount ) : ?><p><?php echo esc_html( $t( 'auth_description' ) ); ?></p><?php endif; ?></header><?php endif; ?>
	<?php if ( $context['error'] ) : ?><div class="account-notice account-error" id="account-feedback" role="alert" tabindex="-1" data-account-feedback><h2><?php echo esc_html( $t( 'error.title' ) ); ?></h2><p><?php echo esc_html( $t( 'error.' . $context['error'] ) ); ?></p><?php if ( $context['status'] >= 500 ) : ?><a class="account-text-action" href="<?php echo esc_url( $url( $route ) ); ?>"><?php echo esc_html( 'zh_CN' === $locale ? '重新加载' : 'Reload page' ); ?></a><?php endif; ?></div><?php endif; ?>
	<?php if ( $context['message'] ) : ?><div class="account-notice" role="status" tabindex="-1" data-account-feedback><p><?php echo esc_html( $t( 'message.' . $context['message'] ) ); ?></p></div><?php endif; ?>
	<?php if ( $isAccount && ! $profile ) : ?>
		<a class="account-text-action" href="<?php echo esc_url( $url( 'login' ) ); ?>"><?php echo esc_html( $t( 'back_login' ) ); ?></a>
	<?php elseif ( 'register' === $route ) : ?>
		<?php if ( empty( $context['ready'] ) ) : ?><div class="account-notice"><p><?php echo esc_html( $t( 'email_service' ) ); ?></p></div><?php endif; ?>
		<?php $form( 'register' ); $field( 'register', 'display_name', 'name', 'text', 'nickname' ); $field( 'register', 'email', 'email', 'email', 'email' ); $field( 'register', 'password', 'password', 'password', 'new-password', 'password_help' ); $language( 'register-locale', $value( 'locale', $locale ) ); ?>
		<div class="account-honeypot" aria-hidden="true"><label for="register-company">Company<input id="register-company" name="company" type="text" tabindex="-1" autocomplete="off"></label></div>
		<label class="account-check"><input type="checkbox" name="terms" value="1" required><span><?php echo esc_html( $t( 'terms' ) ); ?></span></label>
		<p class="account-help"><?php echo esc_html( $t( 'terms_info' ) ); ?></p>
		<?php $button( 'register', empty( $context['ready'] ) ); ?></form>
		<p class="account-auth-links"><a href="<?php echo esc_url( $url( 'login' ) ); ?>"><?php echo esc_html( $t( 'login' ) ); ?></a><a href="<?php echo esc_url( $public( 'contact' ) ); ?>"><?php echo esc_html( $t( 'contact' ) ); ?></a></p>
	<?php elseif ( 'login' === $route ) : ?>
		<?php $form( 'login' ); $field( 'login', 'email', 'email', 'email', 'username' ); $field( 'login', 'password', 'password', 'password', 'current-password' ); ?>
		<label class="account-check"><input type="checkbox" name="remember" value="1"><span><?php echo esc_html( $t( 'remember' ) ); ?></span></label>
		<?php $button( 'login' ); ?></form><p class="account-auth-links"><a href="<?php echo esc_url( $url( 'forgot-password' ) ); ?>"><?php echo esc_html( $t( 'forgot-password' ) ); ?></a><a href="<?php echo esc_url( $url( 'register' ) ); ?>"><?php echo esc_html( $t( 'register' ) ); ?></a></p>
	<?php elseif ( 'forgot-password' === $route ) : ?>
		<p class="account-intro"><?php echo esc_html( $t( 'forgot_description' ) ); ?></p><?php $form( 'forgot' ); $field( 'forgot', 'email', 'email', 'email', 'email' ); $button( 'send_reset' ); ?></form><a class="account-text-action" href="<?php echo esc_url( $url( 'login' ) ); ?>"><?php echo esc_html( $t( 'back_login' ) ); ?></a>
	<?php elseif ( 'reset-password' === $route ) : ?>
		<?php if ( ! empty( $context['reset_ready'] ) ) : ?><p class="account-intro"><?php echo esc_html( $t( 'reset_description' ) ); ?></p><?php $form( 'reset' ); $field( 'reset', 'new_password', 'new_password', 'password', 'new-password', 'password_help' ); $field( 'reset', 'confirm_password', 'confirm_password', 'password', 'new-password' ); $button( 'reset-password' ); ?></form><?php else : ?><p class="account-intro"><?php echo esc_html( $t( 'no_reset' ) ); ?></p><?php endif; ?><a class="account-text-action" href="<?php echo esc_url( $url( 'forgot-password' ) ); ?>"><?php echo esc_html( $t( 'send_reset' ) ); ?></a>
	<?php elseif ( 'verify-email' === $route ) : ?>
		<?php if ( $profile ) : ?><p class="account-badge"><?php echo esc_html( $t( 'state.' . $profile['state'] ) ); ?></p><p class="account-intro"><?php echo esc_html( AccountView::maskEmail( $profile['email'] ) ); ?></p><?php endif; ?>
		<?php if ( $profile && 'invalid_token' === $context['error'] ) : ?><p class="account-intro"><?php echo esc_html( $t( 'verify_account_help' ) ); ?></p><?php endif; ?>
		<?php if ( ! empty( $context['verification_ready'] ) ) : ?><p class="account-intro"><?php echo esc_html( $t( 'verify_ready' ) ); ?></p><?php $form( 'verify' ); $button( 'verify_confirm' ); ?></form><?php else : ?><p class="account-intro"><?php echo esc_html( $t( 'verify_instruction' ) ); ?></p><?php endif; ?>
		<?php $mailStatus(); ?>
		<section class="account-form-section"><h2><?php echo esc_html( $t( 'resend' ) ); ?></h2><p class="account-help"><?php echo esc_html( $t( 'verify_help' ) ); ?></p><?php $form( 'resend' ); if ( ! $profile ) { $field( 'resend', 'email', 'email', 'email', 'email' ); } $button( 'resend' ); ?></form></section>
		<?php if ( $profile && 'pending_email' === $profile['state'] ) : ?><details class="account-details"><summary><?php echo esc_html( $t( 'change_email' ) ); ?></summary><p class="account-help"><?php echo esc_html( $t( 'change_email_help' ) ); ?></p><?php $form( 'email_change' ); $field( 'verify-email-change', 'current_password', 'current_password', 'password', 'current-password' ); $field( 'verify-email-change', 'new_email', 'new_email', 'email', 'email' ); $button( 'request_email' ); ?></form></details><?php endif; ?>
		<p class="account-auth-links"><a href="<?php echo esc_url( $url( 'login' ) ); ?>"><?php echo esc_html( $t( 'back_login' ) ); ?></a><a href="<?php echo esc_url( $public( 'contact' ) ); ?>"><?php echo esc_html( $t( 'contact' ) ); ?></a></p><?php if ( $profile ) { $form( 'logout' ); echo '<button class="account-text-action" type="submit">' . esc_html( $t( 'logout' ) ) . '</button></form>'; } ?>
	<?php elseif ( 'account' === $route ) : ?>
		<section class="account-welcome"><div class="account-welcome-top"><p class="account-eyebrow"><?php echo esc_html( $t( 'private' ) ); ?></p><span class="account-badge"><?php echo esc_html( $t( 'state.' . $profile['state'] ) ); ?></span></div><h1><?php echo esc_html( sprintf( $t( 'welcome' ), $profile['display_name'] ) ); ?></h1><p><?php echo esc_html( $t( 'welcome_description' ) ); ?></p><div class="account-actions"><a class="account-button account-button-primary" href="<?php echo esc_url( $public( 'skills' ) ); ?>"><?php echo esc_html( $t( 'explore' ) ); ?> <?php echo View::icon( 'arrow-right' ); ?></a><a class="account-button account-button-secondary" href="<?php echo esc_url( $url( 'profile' ) ); ?>"><?php echo esc_html( $t( 'profile' ) ); ?></a></div></section>
		<?php do_action( 'aish_account_dashboard', $locale ); ?>
		<div class="account-summary"><section class="account-panel"><h2><?php echo esc_html( $t( 'email' ) ); ?></h2><p><?php echo esc_html( AccountView::maskEmail( $profile['email'] ) ); ?></p><a class="account-text-action" href="<?php echo esc_url( $url( 'security' ) ); ?>"><?php echo esc_html( $t( 'security' ) ); ?> →</a></section><section class="account-panel"><h2><?php echo esc_html( $t( 'created' ) ); ?></h2><p><?php echo esc_html( $profile['registered_at'] ?? $t( 'unknown' ) ); ?></p><a class="account-text-action" href="<?php echo esc_url( $url( 'privacy' ) ); ?>"><?php echo esc_html( $t( 'privacy' ) ); ?> →</a></section></div>
		<div class="account-dashboard-bottom"><section class="account-panel"><h2><?php echo esc_html( $t( 'notifications' ) ); ?></h2><?php $notifications(); ?></section><section class="account-panel account-privacy-panel"><?php echo View::icon( 'shield' ); ?><h2><?php echo esc_html( $t( 'privacy' ) ); ?></h2><p><?php echo esc_html( $t( 'visibility_help' ) ); ?></p><a class="account-text-action" href="<?php echo esc_url( $url( 'privacy' ) ); ?>"><?php echo esc_html( $t( 'privacy' ) ); ?> →</a></section></div>
	<?php elseif ( 'profile' === $route ) : ?>
		<section class="account-panel"><p class="account-intro"><?php echo esc_html( $t( 'profile_help' ) ); ?></p><?php $form( 'profile' ); $field( 'profile', 'display_name', 'name', 'text', 'nickname' ); ?><div class="account-field"><label for="profile-description"><?php echo esc_html( $t( 'description' ) ); ?></label><textarea id="profile-description" name="description" rows="5" maxlength="1000"><?php echo esc_textarea( $value( 'description' ) ); ?></textarea></div><?php $field( 'profile', 'url', 'website', 'url', 'url', '', false ); $language( 'profile-locale', $value( 'locale', $locale ) ); $button( 'save' ); ?></form></section>
		<section class="account-panel account-section"><h2><?php echo esc_html( $t( 'avatar' ) ); ?></h2><div class="account-avatar-editor"><?php $avatar( true ); ?><p class="account-help"><?php echo esc_html( $t( 'avatar_help' ) ); ?></p></div><?php $form( 'avatar_upload', true ); ?><div class="account-field"><label for="profile-avatar"><?php echo esc_html( $t( 'avatar' ) ); ?></label><input id="profile-avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required></div><?php $button( 'upload_avatar' ); ?></form><?php if ( ! empty( $context['avatar_url'] ) ) { $form( 'avatar_remove' ); echo '<button class="account-text-action" type="submit">' . esc_html( $t( 'remove_avatar' ) ) . '</button></form>'; } ?></section>
	<?php elseif ( 'security' === $route ) : ?>
		<?php $mailStatus(); ?><section class="account-panel"><h2><?php echo esc_html( $t( 'change_password' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'change_password_help' ) ); ?></p><?php $form( 'password' ); $field( 'password', 'current_password', 'current_password', 'password', 'current-password' ); $field( 'password', 'new_password', 'new_password', 'password', 'new-password', 'password_help' ); $field( 'password', 'confirm_password', 'confirm_password', 'password', 'new-password' ); $button( 'change_password' ); ?></form></section>
		<section class="account-panel account-section"><h2><?php echo esc_html( $t( 'change_email' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'change_email_help' ) ); ?></p><p class="account-help"><?php echo esc_html( $t( 'email' ) . ': ' . AccountView::maskEmail( $profile['email'] ) ); ?></p><?php $form( 'email_change' ); $field( 'email-change', 'current_password', 'current_password', 'password', 'current-password' ); $field( 'email-change', 'new_email', 'new_email', 'email', 'email' ); $button( 'request_email' ); ?></form></section>
		<section class="account-panel account-section"><h2><?php echo esc_html( $t( 'sessions' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'sessions_help' ) ); ?></p><?php $form( 'sessions' ); $field( 'sessions', 'current_password', 'current_password', 'password', 'current-password' ); $button( 'revoke_sessions' ); ?></form></section>
	<?php elseif ( 'settings' === $route ) : ?>
		<section class="account-panel"><h2><?php echo esc_html( $t( 'preferences' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'necessary_notifications' ) ); ?></p><?php $preferences = $context['preferences']['notification_preferences'] ?? array(); $form( 'preferences' ); $language( 'preferences-locale', $preferences['locale'] ?? $locale ); $button( 'save' ); ?></form></section>
	<?php elseif ( 'privacy' === $route ) : ?>
		<section class="account-panel"><h2><?php echo esc_html( $t( 'visibility' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'visibility_help' ) ); ?></p><p class="account-help"><?php echo esc_html( $t( 'privacy_retention' ) ); ?></p></section>
		<section class="account-panel account-section"><h2><?php echo esc_html( $t( 'export' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'export_help' ) ); ?></p><?php $form( 'export' ); $field( 'export', 'current_password', 'current_password', 'password', 'current-password' ); $button( 'export' ); ?></form></section>
		<section class="account-panel account-section"><h2><?php echo esc_html( $t( 'requests' ) ); ?></h2><?php if ( empty( $context['requests'] ) ) : ?><p class="account-empty"><?php echo esc_html( $t( 'no_requests' ) ); ?></p><?php else : ?><ul class="account-request-list"><?php foreach ( $context['requests'] as $request ) : ?><li><span><?php echo esc_html( $t( 'erase' === $request['kind'] ? 'erase' : 'export' ) ); ?></span><span class="account-badge"><?php echo esc_html( $t( 'request.' . $request['status'] ) ); ?></span><time><?php echo esc_html( $request['created_at'] ); ?> UTC</time></li><?php endforeach; ?></ul><?php endif; ?></section>
		<section class="account-panel account-section account-danger-zone"><h2><?php echo esc_html( $t( 'erase' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'erase_help' ) ); ?></p><?php if ( ! empty( $context['erase_confirm'] ) ) : ?><?php $form( 'erase' ); $field( 'erase', 'current_password', 'current_password', 'password', 'current-password' ); ?><label class="account-check"><input type="checkbox" name="confirm_erasure" value="1" required><span><?php echo esc_html( $t( 'erase_confirm' ) ); ?></span></label><div class="account-actions"><a class="account-button account-button-secondary" href="<?php echo esc_url( $url( 'privacy' ) ); ?>"><?php echo esc_html( $t( 'cancel' ) ); ?></a><?php $button( 'erase', false, true ); ?></div></form><?php else : ?><a class="account-text-action" href="<?php echo esc_url( add_query_arg( 'confirm', '1', $url( 'privacy' ) ) ); ?>"><?php echo esc_html( $t( 'review_erasure' ) ); ?> →</a><?php endif; ?></section>
	<?php elseif ( 'notifications' === $route ) : ?>
		<section class="account-panel"><?php if ( ! empty( $context['notifications'] ) ) { $form( 'read_all' ); echo '<button class="account-text-action" type="submit">' . esc_html( $t( 'mark_all_read' ) ) . '</button></form>'; } $notifications( true ); ?></section>
		<nav class="account-pagination" aria-label="<?php echo esc_attr( $t( 'notifications' ) ); ?>"><?php if ( ( $context['page'] ?? 1 ) > 1 ) : ?><a class="account-button account-button-secondary" href="<?php echo esc_url( add_query_arg( 'page', $context['page'] - 1, $url( 'notifications' ) ) ); ?>"><?php echo esc_html( $t( 'previous' ) ); ?></a><?php endif; ?><?php if ( count( $context['notifications'] ?? array() ) > 20 ) : ?><a class="account-button account-button-secondary" href="<?php echo esc_url( add_query_arg( 'page', $context['page'] + 1, $url( 'notifications' ) ) ); ?>"><?php echo esc_html( $t( 'next' ) ); ?></a><?php endif; ?></nav>
	<?php endif; ?>
	<?php if ( $isAccount && $profile ) : ?></div></div><?php else : ?></div></div><?php endif; ?>
	</div>
</main>
<footer class="account-footer"><div class="account-container"><p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> AI Skills Hub</p><nav aria-label="<?php echo esc_attr( $t( 'contact' ) ); ?>"><a href="<?php echo esc_url( $public( 'skills' ) ); ?>"><?php echo esc_html( $t( 'explore' ) ); ?></a><a href="<?php echo esc_url( $public( 'contact' ) ); ?>"><?php echo esc_html( $t( 'contact' ) ); ?></a></nav></div></footer>
<?php wp_footer(); ?>
</body>
</html>
