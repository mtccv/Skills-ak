<?php
use AISkillsHub\Core\Community\Frontend as Community;
use AISkillsHub\Core\Community\Input;
use AISkillsHub\Core\Accounts\Frontend as Accounts;
use AISkillsHub\Core\Accounts\Strings as AccountStrings;
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$c = Community::context(); $route = $c['route']; $locale = $c['locale']; $params = $c['params']; $profile = $c['profile'];
$t = static fn( string $key ): string => Community::t( $key, $locale );
$a = static fn( string $key ): string => AccountStrings::get( $key, $locale );
$url = static fn( string $target, array $query = array() ): string => Community::url( $target, $locale, $query );
$public = static fn( string $target ): string => Router::url( $target, array(), $locale );
$skill = $c['skill'] ?? null; $head = $c['head'] ?? null;
$latest = $head['pending'] ?? $head['latest'] ?? $head['approved'] ?? array();
$value = static fn( string $field, string $fallback = '' ): string => (string) ( $c['values'][ $field ] ?? $latest[ $field ] ?? $fallback );
$form = static function ( string $action, array $query = array(), string $id = '' ) use ( $route, $url, $params ): void {
	echo '<form method="post" action="' . esc_url( $url( $route, $query ?: $params ) ) . '" class="account-form community-form" data-community-form' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
	echo Community::fields( $action );
	echo '<div class="account-honeypot" aria-hidden="true"><label>Company<input name="company" type="text" tabindex="-1" autocomplete="off"></label></div>';
};
$button = static function ( string $key ): void { echo '<button type="submit" class="account-button account-button-primary" data-pending-label="' . esc_attr( Community::t( 'working' ) ) . '">' . esc_html( Community::t( $key ) ) . '</button>'; };
$field = static function ( string $key, int $max, int $rows = 0, bool $required = false, string $fallback = '' ) use ( $t, $value, $c ): void {
	$invalid = 'invalid_input' === $c['error'];
	echo '<div class="account-field"><label for="community-' . esc_attr( $key ) . '">' . esc_html( $t( $key ) ) . '</label>';
	$attrs = ' id="community-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" maxlength="' . esc_attr( (string) $max ) . '"' . ( $required ? ' required' : '' ) . ( $invalid ? ' aria-invalid="true" aria-describedby="community-feedback"' : '' );
	if ( $rows ) { echo '<textarea' . $attrs . ' rows="' . esc_attr( (string) $rows ) . '"' . ( $required ? ' minlength="5"' : '' ) . '>' . esc_textarea( $value( $key, $fallback ) ) . '</textarea>'; }
	else { echo '<input type="text"' . $attrs . ' value="' . esc_attr( $value( $key, $fallback ) ) . '">'; }
	echo '</div>';
};
$snapshot = static function ( array $item ) use ( $t ): void {
	echo '<article class="community-review"><header><strong>' . esc_html( sprintf( $t( 'stars' ), (int) $item['rating'] ) ) . '</strong><span class="account-badge">' . esc_html( $t( 'state.' . ( $item['moderation_state'] ?? 'pending' ) ) ) . '</span></header><h3>' . esc_html( $item['title'] ) . '</h3>';
	foreach ( array( 'comment', 'experience', 'pros', 'cons', 'platform', 'version_label' ) as $key ) { if ( '' !== ( $item[ $key ] ?? '' ) ) { echo '<div class="community-review-field"><h4>' . esc_html( $t( $key ) ) . '</h4><p class="community-copy">' . esc_html( $item[ $key ] ) . '</p></div>'; } }
	if ( in_array( $item['moderation_code'] ?? '', array( 'spam', 'false_claim', 'abuse', 'policy', 'other' ), true ) ) { echo '<p class="account-help">' . esc_html( $t( 'moderation_reason' ) . ': ' . $t( 'moderation.' . $item['moderation_code'] ) ) . '</p>'; }
	echo '<p class="community-muted">' . esc_html( ( 'zh_CN' === $item['locale'] ? '中文' : 'English' ) . ' · ' . $t( 'review_version' ) . ' ' . ( $item['revision'] ?? '' ) ) . '</p></article>';
};
$nav = static function () use ( $a, $t, $locale, $route, $url, $form ): void {
	echo '<nav class="account-nav" aria-label="' . esc_attr( $a( 'menu' ) ) . '"><a href="' . esc_url( Accounts::url( 'account', $locale ) ) . '">' . esc_html( $a( 'overview' ) ) . '</a>';
	foreach ( array( 'favorites', 'reviews' ) as $item ) { echo '<a href="' . esc_url( $url( $item ) ) . '"' . ( $item === $route || ( 'reviews' === $item && 'review' === $route ) ? ' aria-current="page"' : '' ) . '>' . esc_html( $t( $item ) ) . '</a>'; }
	foreach ( array( 'notifications', 'profile', 'settings', 'security', 'privacy' ) as $item ) { echo '<a href="' . esc_url( Accounts::url( $item, $locale ) ) . '">' . esc_html( $a( $item ) ) . '</a>'; }
	echo '</nav>'; $form( 'logout' ); echo '<button type="submit" class="account-nav-logout">' . esc_html( $a( 'logout' ) ) . '</button></form>';
};
$pagination = static function ( array $listing ) use ( $url, $route, $params, $t ): void {
	if ( $listing['total'] <= $listing['per_page'] && $listing['page'] <= 1 ) { return; }
	echo '<nav class="account-pagination" aria-label="' . esc_attr( $t( $route ) ) . '">';
	if ( $listing['page'] > 1 ) { echo '<a class="account-button account-button-secondary" href="' . esc_url( $url( $route, array_merge( $params, array( 'page' => $listing['page'] - 1 ) ) ) ) . '">' . esc_html( $t( 'previous' ) ) . '</a>'; }
	echo '<span>' . esc_html( sprintf( $t( 'page' ), $listing['page'], $listing['total'] ) ) . '</span>';
	if ( $listing['page'] * $listing['per_page'] < $listing['total'] ) { echo '<a class="account-button account-button-secondary" href="' . esc_url( $url( $route, array_merge( $params, array( 'page' => $listing['page'] + 1 ) ) ) ) . '">' . esc_html( $t( 'next' ) ) . '</a>'; }
	echo '</nav>';
};
?>
<!doctype html>
<html lang="<?php echo esc_attr( 'en_US' === $locale ? 'en' : 'zh-CN' ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#F9FAFB"><?php wp_head(); ?></head>
<body <?php body_class( 'aish-account-site aish-community-site' ); ?>>
<?php wp_body_open(); ?><a class="account-skip" href="#account-main"><?php echo esc_html( $a( 'skip' ) ); ?></a>
<header class="account-site-header"><div class="account-container account-header-inner"><a class="account-brand" href="<?php echo esc_url( $public( 'home' ) ); ?>"><span class="account-brand-symbol" aria-hidden="true"><?php echo View::icon( 'layers' ); ?></span><span>AI Skills <span class="account-muted">Hub</span></span></a><nav class="account-public-nav" aria-label="<?php echo esc_attr( $a( 'explore' ) ); ?>"><a href="<?php echo esc_url( $public( 'skills' ) ); ?>"><?php echo esc_html( $a( 'explore' ) ); ?></a><a href="<?php echo esc_url( $public( 'tutorials' ) ); ?>"><?php echo esc_html( $a( 'tutorials' ) ); ?></a></nav><nav class="account-language" aria-label="<?php echo esc_attr( $a( 'language' ) ); ?>"><a href="<?php echo esc_url( Community::url( $route, 'zh_CN', $params ) ); ?>" lang="zh-CN" <?php echo 'zh_CN' === $locale ? 'aria-current="true"' : ''; ?>>中</a><span aria-hidden="true">/</span><a href="<?php echo esc_url( Community::url( $route, 'en_US', $params ) ); ?>" lang="en" <?php echo 'en_US' === $locale ? 'aria-current="true"' : ''; ?>>EN</a></nav></div></header>
<main id="account-main" class="account-main" tabindex="-1"><div class="account-container">
	<div class="account-breadcrumb"><a href="<?php echo esc_url( $public( 'home' ) ); ?>"><?php echo esc_html( $a( 'home' ) ); ?></a><span aria-hidden="true">/</span><a href="<?php echo esc_url( Accounts::url( 'account', $locale ) ); ?>"><?php echo esc_html( $a( 'account' ) ); ?></a><span class="account-badge"><?php echo esc_html( $a( 'private' ) ); ?></span></div>
	<div class="account-layout"><aside class="account-sidebar"><div class="account-panel account-sidebar-panel"><div class="account-identity"><span class="account-avatar" aria-hidden="true"><?php echo View::icon( 'layers' ); ?></span><div><p class="account-strong"><?php echo esc_html( $profile['display_name'] ?? '' ); ?></p><p class="account-help"><?php echo esc_html( $a( 'private' ) ); ?></p></div></div><?php $nav(); ?></div></aside><div class="account-content">
	<details class="account-mobile-nav account-panel"><summary><?php echo esc_html( $a( 'menu' ) ); ?> <span class="account-muted"><?php echo esc_html( $t( $route ) ); ?></span></summary><?php $nav(); ?></details>
	<header class="account-page-heading"><h1><?php echo esc_html( $t( $route ) ); ?></h1><p><?php echo esc_html( $t( 'private_help' ) ); ?></p></header>
	<?php if ( $c['error'] ) : ?><div class="account-notice account-error" id="community-feedback" role="alert" tabindex="-1" data-community-feedback><h2><?php echo esc_html( $t( 'error_title' ) ); ?></h2><p><?php echo esc_html( $t( 'error.' . $c['error'] ) ); ?></p><a class="account-text-action" href="<?php echo esc_url( $url( $route, $params ) ); ?>"><?php echo esc_html( $t( 'reload' ) ); ?></a></div><?php endif; ?>
	<?php if ( $c['message'] ) : ?><div class="account-notice" role="status" tabindex="-1" data-community-feedback><p><?php echo esc_html( $t( 'message.' . $c['message'] ) ); ?></p></div><?php endif; ?>
	<?php if ( in_array( $route, array( 'favorites', 'reviews' ), true ) && isset( $c['listing'] ) ) : $listing = $c['listing']; ?>
	<form method="get" action="<?php echo esc_url( $url( $route ) ); ?>" class="community-filters">
		<?php if ( 'favorites' === $route ) : ?><div class="account-field"><label for="community-q"><?php echo esc_html( $t( 'search' ) ); ?></label><input id="community-q" type="search" name="q" maxlength="100" value="<?php echo esc_attr( $params['q'] ?? '' ); ?>"></div><?php endif; ?>
		<div class="account-field"><label for="community-status"><?php echo esc_html( $t( 'status' ) ); ?></label><select id="community-status" name="status"><?php foreach ( 'favorites' === $route ? array( 'all', 'available', 'unavailable' ) : array( 'all', 'pending', 'approved', 'rejected', 'hidden', 'withdrawn' ) as $state ) : ?><option value="<?php echo esc_attr( $state ); ?>" <?php selected( $params['status'] ?? 'all', $state ); ?>><?php echo esc_html( $t( in_array( $state, array( 'all', 'available', 'unavailable' ), true ) ? $state : 'state.' . $state ) ); ?></option><?php endforeach; ?></select></div>
		<?php if ( 'favorites' === $route ) : ?><div class="account-field"><label for="community-sort"><?php echo esc_html( $t( 'sort' ) ); ?></label><select id="community-sort" name="sort"><option value="newest" <?php selected( $params['sort'] ?? 'newest', 'newest' ); ?>><?php echo esc_html( $t( 'newest' ) ); ?></option><option value="title" <?php selected( $params['sort'] ?? '', 'title' ); ?>><?php echo esc_html( $t( 'title_sort' ) ); ?></option></select></div><?php endif; ?>
		<button class="account-button account-button-secondary" type="submit"><?php echo esc_html( $t( 'apply' ) ); ?></button><a class="account-text-action" href="<?php echo esc_url( $url( $route ) ); ?>"><?php echo esc_html( $t( 'clear_filters' ) ); ?></a>
	</form>
	<?php if ( ! $listing['items'] ) : ?><section class="account-panel account-empty"><h2><?php echo esc_html( $t( ! empty( $params['q'] ) || ( $params['status'] ?? 'all' ) !== 'all' ? 'no_results' : 'empty_' . $route ) ); ?></h2><p><?php echo esc_html( $t( 'empty_' . $route . '_help' ) ); ?></p><a class="account-button account-button-primary" href="<?php echo esc_url( $public( 'skills' ) ); ?>"><?php echo esc_html( $a( 'explore' ) ); ?></a></section><?php endif; ?>
	<div class="<?php echo 'favorites' === $route ? 'community-saved-grid' : 'community-my-reviews'; ?>">
	<?php foreach ( $listing['items'] as $item ) : $card = $item['skill'] ?? null; ?>
	<article class="account-panel community-record">
		<?php if ( $card ) : ?><h2><a href="<?php echo esc_url( Router::url( 'skill', array( 'id' => $card['id'], 'slug' => $card['slug'] ), $locale ) ); ?>"><?php echo esc_html( $card['title'] ); ?></a></h2><?php if ( 'favorites' === $route ) : ?><p class="community-muted"><?php echo esc_html( $card['summary'] ); ?></p><?php endif; ?>
		<?php else : ?><h2><?php echo esc_html( $t( ! empty( $item['alternative'] ) ? 'missing_translation' : 'unavailable_skill' ) ); ?></h2><p class="community-muted"><?php echo esc_html( $t( 'unavailable_help' ) ); ?></p><?php endif; ?>
		<?php if ( ! empty( $item['alternative']['skill'] ) ) : $alt = $item['alternative']; ?><a class="account-text-action" href="<?php echo esc_url( Router::url( 'skill', array( 'id' => $alt['skill']['id'], 'slug' => $alt['skill']['slug'] ), $alt['locale'] ) ); ?>"><?php echo esc_html( $t( 'alternative' ) ); ?> · <?php echo esc_html( 'zh_CN' === $alt['locale'] ? '中文' : 'English' ); ?></a><?php endif; ?>
		<?php if ( 'favorites' === $route ) : ?><p class="community-muted"><?php echo esc_html( $t( 'saved_since' ) . ': ' . $item['created_at'] ); ?> UTC</p><?php $form( 'favorite', array_intersect_key( $params, array_flip( array( 'page', 'q', 'status', 'sort' ) ) ) ); ?><input type="hidden" name="skill_id" value="<?php echo esc_attr( (string) $item['skill_id'] ); ?>"><input type="hidden" name="active" value="0"><button type="submit" class="account-text-action"><?php echo esc_html( $t( 'remove_favorite' ) ); ?></button></form>
		<?php else : $ownRevision = $item['pending'] ?? $item['latest'] ?? $item['approved']; ?><?php if ( $ownRevision ) : ?><p class="account-strong"><?php echo esc_html( sprintf( $t( 'stars' ), (int) $ownRevision['rating'] ) . ' · ' . $ownRevision['title'] ); ?></p><?php endif; ?><p><span class="account-badge"><?php echo esc_html( $t( 'state.' . $item['state'] ) ); ?></span><?php if ( $item['pending'] && $item['approved'] ) : ?> <span class="account-badge"><?php echo esc_html( $t( 'state.pending' ) ); ?></span><?php endif; ?></p><p class="community-muted"><?php echo esc_html( $t( 'updated' ) . ': ' . $item['updated_at'] ); ?> UTC</p><a class="account-text-action" href="<?php echo esc_url( $url( 'review', array( 'skill' => $item['skill_id'] ) ) ); ?>"><?php echo esc_html( $t( 'edit_review' ) ); ?></a><?php endif; ?>
	</article><?php endforeach; ?></div><?php $pagination( $listing ); ?>
	<?php elseif ( 'favorite' === $route && array_key_exists( 'is_favorite', $c ) ) : ?>
	<section class="account-panel"><h2><?php echo esc_html( $skill['title'] ?? $t( 'unavailable_skill' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'saved_help' ) ); ?></p><?php $form( 'favorite' ); ?><input type="hidden" name="active" value="<?php echo $c['is_favorite'] ? '0' : '1'; ?>"><?php $button( $c['is_favorite'] ? 'remove_favorite' : 'save_skill' ); ?></form></section>
	<?php elseif ( 'review' === $route && ( $skill || $head ) ) : ?>
	<section class="account-panel"><h2><?php echo esc_html( $skill['title'] ?? $t( 'unavailable_skill' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( $head && $head['approved'] ? 'edit_help' : 'first_help' ) ); ?></p>
	<?php if ( $head && $head['approved'] ) : ?><details class="account-details" open><summary><?php echo esc_html( $t( 'published_version' ) ); ?></summary><?php $snapshot( $head['approved'] ); ?></details><?php elseif ( $head ) : ?><p class="account-help"><?php echo esc_html( $t( 'no_public_version' ) ); ?></p><?php endif; ?>
	<?php if ( $head && $head['latest'] && ( ! $head['approved'] || $head['latest']['id'] !== $head['approved']['id'] ) ) : ?><details class="account-details"><summary><?php echo esc_html( $t( 'latest_version' ) ); ?></summary><?php $snapshot( $head['latest'] ); ?></details><?php endif; ?>
	<?php if ( $head && 'hidden' === $head['state'] ) : ?><p class="account-notice"><?php echo esc_html( $t( 'hidden_help' ) ); ?></p>
	<?php elseif ( ! $skill ) : ?><p class="account-notice"><?php echo esc_html( $t( 'unavailable_help' ) ); ?></p>
	<?php else : ?>
	<?php if ( $head && $head['pending'] && $head['approved'] ) : ?><p class="account-notice"><?php echo esc_html( $t( 'pending_help' ) ); ?></p><?php endif; ?>
	<?php $form( 'review' ); $lock = $head['lock_version'] ?? 0; if ( $c['error'] && isset( $_POST['lock_version'] ) && is_string( $_POST['lock_version'] ) && ctype_digit( $_POST['lock_version'] ) ) { $lock = $_POST['lock_version']; } ?>
	<input type="hidden" name="lock_version" value="<?php echo esc_attr( (string) $lock ); ?>">
	<fieldset class="community-rating-input"><legend><?php echo esc_html( $t( 'rating' ) ); ?></legend><div><?php for ( $star = 1; $star <= 5; ++$star ) : ?><label><input type="radio" name="rating" value="<?php echo esc_attr( (string) $star ); ?>" required <?php checked( $value( 'rating' ), (string) $star ); ?>><span><?php echo esc_html( sprintf( $t( 'stars' ), $star ) ); ?></span></label><?php endfor; ?></div></fieldset>
	<?php $field( 'title', 191, 0, true ); $field( 'comment', 8000, 7, true ); ?>
	<p class="account-help"><?php echo esc_html( $t( 'optional_help' ) ); ?></p>
	<?php $field( 'experience', 2000, 4 ); $field( 'pros', 2000, 3 ); $field( 'cons', 2000, 3 ); $field( 'platform', 100 ); $field( 'version_label', 191 ); ?>
	<div class="account-field"><label for="community-locale"><?php echo esc_html( $t( 'original_language' ) ); ?></label><select name="locale" id="community-locale"><option value="zh_CN" <?php selected( $value( 'locale', $locale ), 'zh_CN' ); ?>>简体中文</option><option value="en_US" <?php selected( $value( 'locale', $locale ), 'en_US' ); ?>>English</option></select></div>
	<?php $button( 'submit_review' ); ?></form><?php endif; ?></section>
	<?php if ( $head && ! in_array( $head['state'], array( 'withdrawn', 'hidden' ), true ) ) : ?><section class="account-panel account-section account-danger-zone"><h2><?php echo esc_html( $t( 'withdraw' ) ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'withdraw_help' ) ); ?></p><?php $form( 'withdraw' ); ?><input type="hidden" name="review_id" value="<?php echo esc_attr( (string) $head['id'] ); ?>"><input type="hidden" name="lock_version" value="<?php echo esc_attr( (string) $head['lock_version'] ); ?>"><label class="account-check"><input type="checkbox" name="confirm_withdraw" value="1" required><span><?php echo esc_html( $t( 'withdraw_confirm' ) ); ?></span></label><?php $button( 'withdraw' ); ?></form></section><?php endif; ?>
	<?php elseif ( in_array( $route, array( 'report-review', 'report-skill' ), true ) && $skill && ( 'report-skill' === $route || ! empty( $c['reported_review'] ) ) ) : ?>
	<section class="account-panel" id="community-report-panel" data-community-report-title="<?php echo esc_attr( $t( $route ) ); ?>"><h2><?php echo esc_html( $skill['title'] ); ?></h2><p class="account-intro"><?php echo esc_html( $t( 'report-review' === $route ? 'report_help' : 'correction_help' ) ); ?></p>
	<?php if ( 'report-review' === $route ) : ?><blockquote class="community-report-excerpt"><p><?php echo esc_html( $c['reported_review']['title'] ); ?></p><p class="community-muted"><?php echo esc_html( $t( 'reporting_revision' ) ); ?></p></blockquote><?php endif; ?>
	<?php $form( 'report-review' === $route ? 'report_review' : 'report_skill', array(), 'community-report-form' ); ?>
	<?php if ( 'report-review' === $route ) : ?><div class="account-field"><label for="community-reason"><?php echo esc_html( $t( 'reason' ) ); ?></label><select id="community-reason" name="reason_code"><?php foreach ( Input::REVIEW_REASONS as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>" <?php selected( $value( 'reason_code' ), $reason ); ?>><?php echo esc_html( $t( 'reason.' . $reason ) ); ?></option><?php endforeach; ?></select></div>
	<?php else : $expectedRevision = $c['skill_revision']; if ( $c['error'] && is_string( $_POST['skill_revision'] ?? null ) && ctype_digit( $_POST['skill_revision'] ) ) { $expectedRevision = $_POST['skill_revision']; } ?><input type="hidden" name="skill_revision" value="<?php echo esc_attr( (string) $expectedRevision ); ?>"><div class="account-field"><label for="community-kind"><?php echo esc_html( $t( 'issue_kind' ) ); ?></label><select id="community-kind" name="kind"><?php foreach ( Input::SKILL_REASONS as $kind ) : ?><option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $value( 'kind' ), $kind ); ?>><?php echo esc_html( $t( 'kind.' . $kind ) ); ?></option><?php endforeach; ?></select></div><div class="account-field"><label for="community-content-locale"><?php echo esc_html( $t( 'content_language' ) ); ?></label><select id="community-content-locale" name="content_locale"><?php foreach ( array_keys( $c['alternatives'] ?? array() ) as $language ) : ?><option value="<?php echo esc_attr( $language ); ?>" <?php selected( $value( 'content_locale', $locale ), $language ); ?>><?php echo esc_html( 'zh_CN' === $language ? '简体中文' : 'English' ); ?></option><?php endforeach; ?></select></div><?php endif; ?>
	<?php $field( 'details', 4000, 6, true ); $button( 'report-review' === $route ? 'send_report' : 'send_correction' ); ?></form></section>
	<?php endif; ?>
	<div class="community-actions"><?php if ( $skill ) : ?><a class="account-button account-button-secondary" href="<?php echo esc_url( Router::url( 'skill', array( 'id' => $skill['id'], 'slug' => $skill['slug'] ), $locale ) ); ?>"><?php echo esc_html( $t( 'view_skill' ) ); ?></a><?php endif; ?><a class="account-text-action" href="<?php echo esc_url( $public( 'skills' ) ); ?>"><?php echo esc_html( $a( 'explore' ) ); ?></a></div>
	</div></div>
</div></main>
<footer class="account-footer"><div class="account-container"><p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> AI Skills Hub</p><a href="<?php echo esc_url( $public( 'contact' ) ); ?>"><?php echo esc_html( $a( 'contact' ) ); ?></a></div></footer><?php wp_footer(); ?>
</body></html>
