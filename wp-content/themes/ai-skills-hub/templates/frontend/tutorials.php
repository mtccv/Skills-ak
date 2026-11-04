<?php
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$data = Router::context()['data'] ?? array();
$result = $data['result'] ?? array();
$filters = $data['filters'] ?? array();
$total = (int) ( $result['total'] ?? 0 );
$page = max( 1, (int) ( $result['page'] ?? $filters['page'] ?? 1 ) );
$per_page = max( 1, (int) ( $result['per_page'] ?? $filters['per_page'] ?? 12 ) );
?>
<div class="page-intro"><div class="aish-container"><?php echo View::breadcrumbs(); ?><p class="eyebrow"><?php echo esc_html( View::t( 'tutorial.fieldnotes' ) ); ?></p><h1><?php echo esc_html( View::t( 'page.tutorials.heading' ) ); ?></h1><p><?php echo esc_html( View::t( 'page.tutorials.description' ) ); ?></p></div></div>
<section class="aish-container pb-20"><form class="filter-panel" method="get" action="<?php echo esc_url( View::url( 'tutorials' ) ); ?>" x-data="{ submitting: false }" @submit="submitting = true" :aria-busy="submitting"><div class="filter-search-row"><div class="search-field"><?php echo View::icon( 'search' ); ?><label class="sr-only" for="tutorial-query"><?php echo esc_html( View::t( 'tutorial.search' ) ); ?></label><input type="search" id="tutorial-query" name="q" maxlength="100" value="<?php echo esc_attr( (string) ( $filters['q'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( View::t( 'tutorial.search' ) ); ?>"></div><div class="form-field"><label class="sr-only" for="tutorial-sort"><?php echo esc_html( View::t( 'filter.sort' ) ); ?></label><select class="field-input" id="tutorial-sort" name="sort"><?php foreach ( array( 'newest', 'oldest', 'title' ) as $sort ) : ?><option value="<?php echo esc_attr( $sort ); ?>" <?php selected( (string) ( $filters['sort'] ?? 'newest' ), $sort ); ?>><?php echo esc_html( View::t( 'sort.' . $sort ) ); ?></option><?php endforeach; ?></select></div><button class="button button-primary" type="submit"><span x-show="!submitting"><?php echo esc_html( View::t( 'search.action' ) ); ?></span><span x-cloak x-show="submitting"><?php echo esc_html( View::t( 'search.loading' ) ); ?></span><?php echo View::icon( 'arrow-right' ); ?></button></div></form>
	<div class="results-heading"><h2><?php echo esc_html( View::t( 'nav.tutorials' ) ); ?><span><?php echo esc_html( (string) $total ); ?></span></h2><p><?php echo esc_html( View::t( 'tutorial.locale_note' ) ); ?></p></div>
	<?php if ( ! empty( $result['items'] ) ) : ?><div class="skill-grid"><?php foreach ( $result['items'] as $tutorial ) { get_template_part( 'template-parts/frontend/tutorial-card', null, array( 'tutorial' => $tutorial ) ); } ?></div><?php else : ?><div class="empty-state"><span class="empty-icon"><?php echo View::icon( 'book' ); ?></span><h2><?php echo esc_html( View::t( 'empty.tutorials.title' ) ); ?></h2><p><?php echo esc_html( View::t( 'empty.tutorials.description' ) ); ?></p><a class="button button-secondary" href="<?php echo esc_url( View::url( 'skills' ) ); ?>"><?php echo esc_html( View::t( 'home.browse' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div><?php endif; ?>
	<?php $params = $filters; unset( $params['page'], $params['per_page'] ); echo View::pagination( $total, $page, $per_page, $params ); ?>
</section>
