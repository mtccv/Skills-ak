<?php
use AISkillsHub\Core\Submissions\Frontend as Submissions;
use AISkillsHub\Core\Contributions\Ui;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$c = Submissions::context(); $locale = $c['locale']; $route = $c['route']; $params = $c['params'];
$t = static fn( string $key ): string => Submissions::t( $key, $locale );
$url = static fn( string $target, array $query = array() ): string => Submissions::url( $target, $locale, $query );
$record = $c['record'] ?? null; $payload = $record['payload'] ?? array();
$value = static fn( string $key, string $fallback = '' ): string => (string) ( $c['values'][ $key ] ?? $payload[ $key ] ?? $fallback );
$form = static function ( string $action, bool $dirty = false ) use ( $url, $route, $params ): void {
	echo '<form method="post" action="' . esc_url( $url( $route, $params ) ) . '" class="account-form contributions-form" data-contribution-form' . ( $dirty ? ' data-contribution-draft' : '' ) . '>' . Submissions::fields( $action ) . '<div class="account-honeypot" aria-hidden="true"><label>Company<input type="text" name="company" tabindex="-1" autocomplete="off"></label></div>';
};
?>
<?php if ( 'submissions' === $route && isset( $c['listing'] ) ) : $listing = $c['listing']; ?>
<div class="account-inline-actions"><a class="account-button account-button-primary" href="<?php echo esc_url( $url( 'submit' ) ); ?>"><?php echo esc_html( $t( 'submit' ) ); ?></a></div>
<form method="get" action="<?php echo esc_url( $url( 'submissions' ) ); ?>" class="contributions-filters">
<div class="account-field"><label for="submission-status"><?php echo esc_html( $t( 'status' ) ); ?></label><select name="status" id="submission-status"><?php foreach ( array( 'all', 'draft', 'submitted', 'in_review', 'needs_changes', 'accepted', 'rejected', 'withdrawn' ) as $state ) : ?><option value="<?php echo esc_attr( $state ); ?>" <?php selected( $params['status'] ?? 'all', $state ); ?>><?php echo esc_html( $t( 'all' === $state ? 'all' : 'state.' . $state ) ); ?></option><?php endforeach; ?></select></div>
<div class="account-field"><label for="submission-kind"><?php echo esc_html( $t( 'kind' ) ); ?></label><select name="kind" id="submission-kind"><?php foreach ( array( 'all', 'submit', 'update_suggestion' ) as $kind ) : ?><option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $params['kind'] ?? 'all', $kind ); ?>><?php echo esc_html( $t( 'all' === $kind ? 'all' : 'kind.' . $kind ) ); ?></option><?php endforeach; ?></select></div><button type="submit" class="account-button account-button-secondary"><?php echo esc_html( $t( 'apply' ) ); ?></button><a class="account-text-action" href="<?php echo esc_url( $url( 'submissions' ) ); ?>"><?php echo esc_html( $t( 'clear_filters' ) ); ?></a></form>
<?php if ( ! $listing['items'] ) : ?><div class="account-panel account-empty"><p><?php echo esc_html( $t( 'empty' ) ); ?></p></div><?php endif; ?>
<div class="contributions-records"><?php foreach ( $listing['items'] as $item ) : ?><article class="account-panel contributions-record"><div class="contributions-record-heading"><h2><a href="<?php echo esc_url( $url( 'submission', array( 'id' => $item['id'] ) ) ); ?>"><?php echo esc_html( $item['name'] ?: $t( 'untitled' ) ); ?></a></h2><span class="account-badge"><?php echo esc_html( $t( 'state.' . $item['state'] ) ); ?></span></div><p class="account-help"><?php echo esc_html( $t( 'number' ) . ' #' . $item['id'] . ' · ' . $t( 'kind.' . $item['kind'] ) . ' · ' . ( $item['submitted_at'] ?: $item['created_at'] ) . ' UTC' ); ?></p><?php if ( ! empty( $item['feedback'] ) ) : ?><p class="contributions-copy"><?php echo esc_html( $item['feedback'] ); ?></p><?php endif; ?><a class="account-text-action" href="<?php echo esc_url( $url( 'submission', array( 'id' => $item['id'] ) ) ); ?>"><?php echo esc_html( $t( 'view_detail' ) ); ?></a></article><?php endforeach; ?></div>
<?php Ui::pagination( $listing, $url, $route, $params, $t ); ?>
<?php elseif ( 'submissions' !== $route && ( isset( $c['record'] ) || 'submit' === $route || isset( $c['skill'] ) ) && ! in_array( $c['status'], array( 403, 404, 405 ), true ) ) :
	$isUpdate = 'update-suggestion' === $route || 'update_suggestion' === ( $record['kind'] ?? '' );
	$editable = ! $record || in_array( $record['state'], array( 'draft', 'submitted', 'needs_changes' ), true );
	$revision = $c['values']['revision'] ?? $record['revision'] ?? 0;
?>
<p><a class="account-text-action" href="<?php echo esc_url( $url( 'submissions' ) ); ?>"><?php echo esc_html( $t( 'back' ) ); ?></a></p>
<div class="account-notice"><p><?php echo esc_html( $t( $isUpdate ? 'update_help' : 'accepted_help' ) ); ?></p></div>
<?php if ( $record ) : ?>
<section class="account-panel account-section"><div class="contributions-record-heading"><h2><?php echo esc_html( $t( 'number' ) . ' #' . $record['id'] ); ?></h2><span class="account-badge"><?php echo esc_html( $t( 'state.' . $record['state'] ) ); ?></span></div><dl class="contributions-meta"><div><dt><?php echo esc_html( $t( 'created' ) ); ?></dt><dd><?php echo esc_html( $record['created_at'] . ' UTC' ); ?></dd></div><div><dt><?php echo esc_html( $t( 'revision' ) ); ?></dt><dd><?php echo esc_html( (string) $record['revision'] ); ?></dd></div><div><dt><?php echo esc_html( $t( 'submitted' ) ); ?></dt><dd><?php echo esc_html( $record['submitted_at'] ? $record['submitted_at'] . ' UTC' : '—' ); ?></dd></div><div><dt><?php echo esc_html( $t( 'locale' ) ); ?></dt><dd><?php echo esc_html( 'zh_CN' === $record['locale'] ? '简体中文' : 'English' ); ?></dd></div></dl><h3><?php echo esc_html( $t( 'feedback' ) ); ?></h3><p class="contributions-copy"><?php echo esc_html( $record['feedback'] ?: $t( 'no_feedback' ) ); ?></p>
<?php if ( 'accepted' === $record['state'] ) : ?><h3><?php echo esc_html( $t( 'results' ) ); ?></h3><?php if ( ! $record['result_skills'] ) : ?><p><?php echo esc_html( $t( 'no_results' ) ); ?></p><?php endif; ?><?php foreach ( $record['result_skills'] as $skill ) : ?><p><a class="account-text-action" href="<?php echo esc_url( View::skillUrl( $skill, $locale ) ); ?>"><?php echo esc_html( $skill['title'] ); ?></a></p><?php endforeach; ?><?php endif; ?></section>
<?php endif; ?>
<?php if ( isset( $c['skill'] ) ) : ?><section class="account-panel account-section"><h2><a class="account-text-action" href="<?php echo esc_url( View::skillUrl( $c['skill'], $locale ) ); ?>"><?php echo esc_html( $c['skill']['title'] ); ?></a></h2></section><?php endif; ?>
<?php if ( $editable ) : ?>
<section class="account-panel account-section"><h2><?php echo esc_html( $t( $isUpdate ? 'update-suggestion' : 'submit' ) ); ?></h2><p class="account-help"><?php echo esc_html( $t( 'source_help' ) ); ?></p>
<?php $form( 'save', true ); ?><input type="hidden" name="revision" value="<?php echo esc_attr( (string) $revision ); ?>">
<?php if ( $isUpdate ) : ?><input type="hidden" name="target_skill_revision" value="<?php echo esc_attr( $value( 'target_skill_revision', (string) ( $c['skill_revision'] ?? 0 ) ) ); ?>"><?php endif; ?>
<div class="account-field"><label for="submission-locale"><?php echo esc_html( $t( 'locale' ) ); ?></label><select id="submission-locale" name="locale"><?php foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $language => $label ) : ?><option value="<?php echo esc_attr( $language ); ?>" <?php selected( $value( 'locale', $locale ), $language ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
<div class="account-field"><label for="submission-source-kind"><?php echo esc_html( $t( 'source_kind' ) ); ?></label><select id="submission-source-kind" name="source_kind"><?php foreach ( array( 'github', 'manual' ) as $kind ) : ?><option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $value( 'source_kind', ! empty( $c['skill']['repository_url'] ) || ! $isUpdate ? 'github' : 'manual' ), $kind ); ?>><?php echo esc_html( $t( 'source.' . $kind ) ); ?></option><?php endforeach; ?></select></div>
<?php
Ui::field( 'github_url', $t( 'github_url' ), $value( 'github_url', (string) ( $c['skill']['repository_url'] ?? '' ) ), 2048 );
Ui::field( 'source_path', $t( 'source_path' ), $value( 'source_path', (string) ( $c['skill']['source_path'] ?? '' ) ), 768 );
Ui::field( 'name', $t( 'name' ), $value( 'name', (string) ( $c['skill']['title'] ?? '' ) ), 191 );
Ui::field( 'summary', $t( 'summary' ), $value( 'summary' ), 1200, 4 );
Ui::field( 'categories', $t( 'categories' ), $value( 'categories' ), 500 ); Ui::field( 'platforms', $t( 'platforms' ), $value( 'platforms' ), 500 ); Ui::field( 'license', $t( 'license' ), $value( 'license' ), 100 );
if ( $isUpdate ) { Ui::field( 'commit_sha', $t( 'commit_sha' ), $value( 'commit_sha' ), 64 ); Ui::field( 'evidence', $t( 'evidence' ), $value( 'evidence' ), 3000, 4 ); }
Ui::field( 'notes', $t( 'notes' ), $value( 'notes' ), 6000, 7 );
?>
<div class="account-inline-actions"><button type="submit" class="account-button account-button-secondary" name="mode" value="draft" data-pending-label="<?php echo esc_attr( $t( 'working' ) ); ?>"><?php echo esc_html( $t( 'save_draft' ) ); ?></button><button type="submit" class="account-button account-button-primary" name="mode" value="submit" data-pending-label="<?php echo esc_attr( $t( 'working' ) ); ?>"><?php echo esc_html( $t( 'submit_review' ) ); ?></button></div></form></section>
<?php else : ?>
<section class="account-panel account-section"><h2><?php echo esc_html( $record['name'] ?: $t( 'untitled' ) ); ?></h2><p class="account-help"><?php echo esc_html( $t( 'readonly' ) ); ?></p><dl class="contributions-readonly"><?php foreach ( array( 'summary', 'github_url', 'source_path', 'categories', 'platforms', 'license', 'commit_sha', 'evidence', 'notes' ) as $key ) : ?><div><dt><?php echo esc_html( $t( $key ) ); ?></dt><dd class="contributions-copy"><?php echo esc_html( $payload[ $key ] ?? ( 'source_path' === $key ? $t( 'unknown_path' ) : '—' ) ); ?></dd></div><?php endforeach; ?></dl></section>
<?php endif; ?>
<?php if ( $record && $editable ) : ?><section class="account-panel account-section"><h2><?php echo esc_html( $t( 'withdraw' ) ); ?></h2><?php $form( 'withdraw' ); ?><input type="hidden" name="revision" value="<?php echo esc_attr( (string) $revision ); ?>"><label class="account-check"><input type="checkbox" name="confirm" value="1" required><span><?php echo esc_html( $t( 'confirm_withdraw' ) ); ?></span></label><button type="submit" class="account-button account-button-secondary" data-pending-label="<?php echo esc_attr( $t( 'working' ) ); ?>"><?php echo esc_html( $t( 'withdraw' ) ); ?></button></form></section><?php endif; ?>
<?php endif; ?>
