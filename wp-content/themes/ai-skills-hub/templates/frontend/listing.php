<?php
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$context = Router::context();
$route = $context['route'] ?? 'skills';
$data = $context['data'] ?? array();
$result = $data['result'] ?? array();
$items = $result['items'] ?? array();
$total = (int) ( $result['total'] ?? 0 );
$page = max( 1, (int) ( $result['page'] ?? $data['filters']['page'] ?? 1 ) );
$per_page = max( 1, (int) ( $result['per_page'] ?? $data['filters']['per_page'] ?? 12 ) );
$filters = $data['filters'] ?? array();
$term = $data['term'] ?? array();
$title = $term['name'] ?? View::t( 'search' === $route ? 'page.search.heading' : 'page.skills.heading' );
$form_url = View::url( $route, ! empty( $term['slug'] ) ? array( 'slug' => $term['slug'] ) : array() );
?>
<div class="page-intro"><div class="aish-container"><?php echo View::breadcrumbs(); ?><p class="eyebrow"><?php echo esc_html( View::t( 'listing.kicker' ) ); ?></p><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( ( $term['description'] ?? '' ) ?: View::t( 'search' === $route ? 'page.search.description' : 'page.skills.description' ) ); ?></p></div></div>
<section class="aish-container pb-20">
	<?php do_action( 'aish_onboarding_context', 'listing', $context['locale'], $context ); ?>
	<?php if ( ! empty( $term['translation_missing'] ) ) : ?><p class="discovery-notice"><?php echo esc_html( 'zh_CN' === $context['locale'] ? '暂无中文翻译，显示原始名称。' : 'English translation unavailable; the original name is shown.' ); ?></p><?php endif; ?>
	<form class="filter-panel" method="get" action="<?php echo esc_url( $form_url ); ?>" x-data="{ submitting: false }" @submit="submitting = true" :aria-busy="submitting">
		<div class="filter-search-row"><div class="search-field"><?php echo View::icon( 'search' ); ?><label class="sr-only" for="skills-query"><?php echo esc_html( View::t( 'search.label' ) ); ?></label><input type="search" id="skills-query" name="q" maxlength="100" placeholder="<?php echo esc_attr( View::t( 'search.placeholder' ) ); ?>" value="<?php echo esc_attr( (string) ( $filters['q'] ?? '' ) ); ?>"></div><button class="button button-primary" type="submit"><span x-show="!submitting"><?php echo esc_html( View::t( 'search.action' ) ); ?></span><span x-cloak x-show="submitting"><?php echo esc_html( View::t( 'search.loading' ) ); ?></span><?php echo View::icon( 'arrow-right' ); ?></button></div>
		<div class="filter-controls">
			<?php foreach ( array( 'category' => 'categories', 'tag' => 'tags', 'platform' => 'platforms' ) as $filter => $field ) : ?>
				<?php if ( $filter === $route ) { continue; } ?>
				<div class="form-field"><label class="field-label" for="filter-<?php echo esc_attr( $filter ); ?>"><?php echo esc_html( View::t( 'filter.' . $filter ) ); ?></label><select class="field-input" id="filter-<?php echo esc_attr( $filter ); ?>" name="<?php echo esc_attr( $filter ); ?>"><option value=""><?php echo esc_html( View::t( 'filter.all.' . $filter ) ); ?></option><?php foreach ( $data[ $field ] ?? array() as $option ) : ?><option value="<?php echo esc_attr( $option['slug'] ); ?>" <?php selected( (string) ( $filters[ $filter ] ?? '' ), (string) $option['slug'] ); ?>><?php echo esc_html( View::termLabel( $option, $data[ $field ] ?? array() ) ); ?></option><?php endforeach; ?><?php $selectedFilter = (string) ( $filters[ $filter ] ?? '' ); if ( $selectedFilter && ! in_array( $selectedFilter, array_column( $data[ $field ] ?? array(), 'slug' ), true ) ) : ?><option value="<?php echo esc_attr( $selectedFilter ); ?>" selected><?php echo esc_html( $selectedFilter ); ?></option><?php endif; ?></select></div>
			<?php endforeach; ?>
			<div class="form-field"><label class="field-label" for="filter-sort"><?php echo esc_html( View::t( 'filter.sort' ) ); ?></label><select class="field-input" id="filter-sort" name="sort"><?php foreach ( array( 'newest', 'oldest', 'title' ) as $sort ) : ?><option value="<?php echo esc_attr( $sort ); ?>" <?php selected( (string) ( $filters['sort'] ?? 'newest' ), $sort ); ?>><?php echo esc_html( View::t( 'sort.' . $sort ) ); ?></option><?php endforeach; ?></select></div>
		</div>
		<div class="filter-footer"><span><?php echo esc_html( View::t( 'filter.hint' ) . ( 'zh_CN' === $context['locale'] ? ' * 表示该名称缺少中文翻译。' : ' * indicates an untranslated name.' ) ); ?></span><a href="<?php echo esc_url( $form_url ); ?>"><?php echo esc_html( View::t( 'filter.reset' ) ); ?></a></div>
	</form>
	<div class="results-heading"><h2><?php echo esc_html( View::t( 'listing.results' ) ); ?><span><?php echo esc_html( (string) $total ); ?></span></h2><p><?php echo esc_html( View::t( 'listing.locale_note' ) ); ?></p></div>
	<?php if ( $items ) : ?><div class="skill-grid"><?php foreach ( $items as $skill ) { get_template_part( 'template-parts/frontend/skill-card', null, array( 'skill' => $skill ) ); } ?></div><?php else : ?><div class="empty-state"><span class="empty-icon"><?php echo View::icon( 'search' ); ?></span><h2><?php echo esc_html( View::t( 'empty.search.title' ) ); ?></h2><p><?php echo esc_html( View::t( 'empty.search.description' ) ); ?></p><a class="button button-secondary" href="<?php echo esc_url( View::url( 'skills' ) ); ?>"><?php echo esc_html( View::t( 'filter.resetall' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div><?php endif; ?>
	<?php $params = $filters; unset( $params['page'], $params['per_page'] ); echo View::pagination( $total, $page, $per_page, $params ); ?>
	<?php
	// Observation is optional and can never turn a search failure into a valid zero.
	if ( 200 === ( $context['status'] ?? 0 ) && empty( $context['error'] ) && isset( $result['total'] ) ) {
		try { do_action( 'aish_search_observation', array( 'locale' => $context['locale'], 'route' => $route, 'query' => $filters['q'] ?? '', 'page' => $page, 'valid' => true, 'failed' => false, 'total' => $total + ( 'search' === $route ? (int) ( $data['tutorial_result']['total'] ?? 0 ) : 0 ) ) ); }
		catch ( \Throwable $ignored ) { /* Optional analytics must not disrupt public search. */ }
	}
	?>
	<?php if ( 'search' === $route && ! empty( $data['tutorials'] ) ) : ?><div class="section-heading mt-16"><div><h2><?php echo esc_html( View::t( 'search.tutorials' ) ); ?></h2></div><a class="text-link" href="<?php echo esc_url( View::url( 'tutorials', array_merge( $filters, array( 'page' => 1 ) ) ) ); ?>"><?php echo esc_html( View::t( 'common.viewall' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a></div><div class="skill-grid"><?php foreach ( $data['tutorials'] as $tutorial ) { get_template_part( 'template-parts/frontend/tutorial-card', null, array( 'tutorial' => $tutorial ) ); } ?></div><?php endif; ?>
</section>
