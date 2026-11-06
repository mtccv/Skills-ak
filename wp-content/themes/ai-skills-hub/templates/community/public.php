<?php
use AISkillsHub\Core\Community\Frontend as Community;
use AISkillsHub\Core\Frontend\Router;
defined( 'ABSPATH' ) || exit;
$skill = $args['skill']; $locale = $args['locale'];
$t = static fn( string $key ): string => Community::t( $key, $locale );
$target = static fn( string $route, array $extra = array() ): string => Community::url( $route, $locale, array_merge( array( 'skill' => $skill['id'] ), $extra ) );
$summary = $args['summary'] ?? null; $listing = $args['listing'] ?? null;
?>
<section id="community-reviews" class="surface surface-pad community-section" aria-labelledby="community-heading">
	<div class="community-heading"><div><h2 id="community-heading"><?php echo esc_html( $t( 'community' ) ); ?></h2><p class="community-muted"><?php echo esc_html( $t( 'community_help' ) ); ?></p></div><a class="account-button account-button-secondary" href="<?php echo esc_url( $target( 'review' ) ); ?>"><?php echo esc_html( $t( 'write_review' ) ); ?></a></div>
	<?php if ( ! empty( $args['error'] ) ) : ?><p class="account-notice"><?php echo esc_html( $t( 'error.storage_unavailable' ) ); ?></p>
	<?php else : ?>
	<div class="community-rating-summary">
		<div><p class="community-average"><?php echo null === $summary['average'] ? esc_html( $t( 'no_rating' ) ) : esc_html( number_format( (float) $summary['average'], 1 ) . ' / 5' ); ?></p><p><?php echo esc_html( sprintf( $t( 'rating_count' ), $summary['count'] ) ); ?></p><p class="community-muted"><?php echo esc_html( sprintf( $t( 'favorite_count' ), $summary['favorites'] ) ); ?></p></div>
		<div class="community-distribution" aria-label="<?php echo esc_attr( $t( 'rating' ) ); ?>"><?php for ( $star = 5; $star >= 1; --$star ) : $count = (int) ( $summary['distribution'][ $star ] ?? 0 ); $percent = $summary['count'] > 0 ? max( 0, min( 100, (int) round( 100 * $count / $summary['count'] ) ) ) : 0; ?><div><span><?php echo esc_html( sprintf( $t( 'stars' ), $star ) ); ?></span><span class="community-track" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span></span><span><?php echo esc_html( (string) $count ); ?></span></div><?php endfor; ?></div>
	</div>
	<?php if ( empty( $listing['items'] ) ) : ?><p class="account-empty"><?php echo esc_html( $t( 'empty_public' ) ); ?></p><?php endif; ?>
	<?php foreach ( $listing['items'] as $item ) : ?>
	<article class="community-review" lang="<?php echo esc_attr( 'zh_CN' === $item['locale'] ? 'zh-CN' : 'en' ); ?>">
		<header><span><?php echo esc_html( $t( 'community_member' ) ); ?></span><strong><?php echo esc_html( sprintf( $t( 'stars' ), (int) $item['rating'] ) ); ?></strong></header>
		<h3><?php echo esc_html( $item['title'] ); ?></h3><p class="community-copy"><?php echo esc_html( $item['comment'] ); ?></p>
		<?php foreach ( array( 'experience', 'pros', 'cons', 'platform', 'version_label' ) as $field ) : if ( '' === ( $item[ $field ] ?? '' ) ) { continue; } ?><div class="community-review-field"><h4><?php echo esc_html( $t( $field ) ); ?></h4><p class="community-copy"><?php echo esc_html( $item[ $field ] ); ?></p></div><?php endforeach; ?>
		<footer><span class="community-muted"><?php echo esc_html( 'zh_CN' === $item['locale'] ? '中文' : 'English' ); ?> · <?php echo esc_html( $item['published_at'] ?? '' ); ?> UTC</span><a class="account-text-action" data-community-dialog href="<?php echo esc_url( $target( 'report-review', array( 'review' => $item['id'], 'revision' => $item['revision_id'] ) ) ); ?>"><?php echo esc_html( $t( 'report' ) ); ?></a></footer>
	</article>
	<?php endforeach; ?>
	<?php if ( $listing['total'] > $listing['per_page'] ) : $base = Router::url( 'skill', array( 'id' => $skill['id'], 'slug' => $skill['slug'] ), $locale ); ?>
	<nav class="account-pagination" aria-label="<?php echo esc_attr( $t( 'community' ) ); ?>">
	<?php if ( $listing['page'] > 1 ) : ?><a class="account-button account-button-secondary" href="<?php echo esc_url( add_query_arg( 'reviews_page', $listing['page'] - 1, $base ) . '#community-reviews' ); ?>"><?php echo esc_html( $t( 'previous' ) ); ?></a><?php endif; ?>
	<span><?php echo esc_html( sprintf( $t( 'page' ), $listing['page'], $listing['total'] ) ); ?></span>
	<?php if ( $listing['page'] * $listing['per_page'] < $listing['total'] ) : ?><a class="account-button account-button-secondary" href="<?php echo esc_url( add_query_arg( 'reviews_page', $listing['page'] + 1, $base ) . '#community-reviews' ); ?>"><?php echo esc_html( $t( 'next' ) ); ?></a><?php endif; ?>
	</nav><?php endif; ?>
	<?php endif; ?>
	<p class="community-correction"><a class="account-text-action" data-community-dialog href="<?php echo esc_url( $target( 'report-skill' ) ); ?>"><?php echo esc_html( $t( 'report-skill' ) ); ?></a></p>
	<dialog class="community-dialog" id="community-report-dialog" aria-labelledby="community-dialog-title">
		<div class="community-dialog-header"><h2 id="community-dialog-title"><?php echo esc_html( $t( 'report' ) ); ?></h2><button type="button" class="account-button account-button-secondary" data-community-close><?php echo esc_html( $t( 'close' ) ); ?></button></div>
		<p class="community-dialog-loading" role="status"><?php echo esc_html( $t( 'loading' ) ); ?></p><div data-community-dialog-content></div>
	</dialog>
</section>
