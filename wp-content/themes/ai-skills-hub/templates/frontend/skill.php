<?php
/** Public data has already passed the publication and locale gates in the router. */
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;

defined( 'ABSPATH' ) || exit;
$context = Router::context();
$data = $context['data'] ?? array();
$skill = $data['skill'] ?? array();
$version = $skill['version'] ?? $skill['latest_version'] ?? null;
$risk = in_array( $skill['risk_level'] ?? '', array( 'unknown', 'low', 'medium', 'high' ), true ) ? $skill['risk_level'] : 'unknown';
$not_provided = View::t( 'detail_not_provided' );
?>
<div class="aish-container py-8 sm:py-12">
	<?php echo View::breadcrumbs(); ?>
	<header class="max-w-4xl pb-10 pt-8">
		<div class="mb-5 flex flex-wrap items-center gap-3">
			<span class="chip chip-success"><?php echo esc_html( View::t( 'detail_reviewed' ) ); ?></span>
			<span class="text-sm muted"><?php echo esc_html( View::t( 'detail_updated' ) . ' ' . View::date( $skill['updated_at'] ?? null ) ); ?></span>
		</div>
		<h1 class="break-words text-4xl font-semibold tracking-tight sm:text-5xl"><?php echo esc_html( (string) ( $skill['title'] ?? '' ) ); ?></h1>
		<p class="mt-5 max-w-3xl text-lg leading-8 muted"><?php echo esc_html( (string) ( $skill['summary'] ?? '' ) ); ?></p>
		<?php if ( ! empty( $context['alternates'] ) ) : ?>
			<div class="mt-6 flex flex-wrap items-center gap-3 text-sm">
				<span class="muted"><?php echo esc_html( View::t( 'detail_available_languages' ) ); ?></span>
				<?php foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $locale => $label ) : ?>
					<?php if ( ! empty( $context['alternates'][ $locale ] ) ) : ?>
						<a class="chip chip-soft" href="<?php echo esc_url( $context['alternates'][ $locale ] ); ?>" lang="<?php echo esc_attr( 'zh_CN' === $locale ? 'zh-CN' : 'en' ); ?>"<?php echo $context['locale'] === $locale ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php do_action( 'aish_skill_actions', $skill, $context['locale'] ); ?>
		<?php do_action( 'aish_skill_share', $skill, $context['locale'] ); ?>
	</header>
	<div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-10">
		<div class="min-w-0 space-y-8">
			<section class="surface surface-pad" aria-labelledby="skill-overview">
				<h2 id="skill-overview" class="mb-6 text-2xl font-semibold"><?php echo esc_html( View::t( 'detail_overview' ) ); ?></h2>
				<div class="prose-aish"><?php echo View::rich( (string) ( $skill['content'] ?? '' ) ); ?></div>
			</section>
			<section class="surface surface-pad" aria-labelledby="skill-use">
				<h2 id="skill-use" class="mb-6 text-2xl font-semibold"><?php echo esc_html( View::t( 'detail_working_with' ) ); ?></h2>
				<p class="mb-7 text-sm leading-7 muted"><?php echo esc_html( View::t( 'detail_read_source' ) ); ?></p>
				<div class="space-y-7">
					<?php foreach ( array( 'use_cases' => 'detail_use_cases', 'installation' => 'detail_installation', 'dependencies' => 'detail_dependencies' ) as $field => $label ) : ?>
						<div>
							<h3 class="mb-2 text-base font-semibold"><?php echo esc_html( View::t( $label ) ); ?></h3>
							<?php if ( ! empty( $data['sections'][ $field ] ) && is_string( $data['sections'][ $field ] ) ) : ?>
								<div class="prose-aish"><?php echo View::rich( $data['sections'][ $field ] ); ?></div>
							<?php else : ?>
								<p class="text-sm leading-7 muted"><?php echo esc_html( View::t( 'detail_section_unprovided' ) ); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<?php do_action( 'aish_skill_installation', $skill, $context['locale'] ); ?>
			</section>
			<section class="rounded-2xl border border-amber-200 bg-amber-50 p-6 sm:p-8" aria-labelledby="skill-risk">
				<div class="mb-3 flex flex-wrap items-center justify-between gap-3">
					<h2 id="skill-risk" class="text-lg font-semibold text-amber-950"><?php echo esc_html( View::t( 'detail_risk_title' ) ); ?></h2>
					<span class="rounded-full border border-amber-300 px-3 py-1 text-xs font-semibold text-amber-950"><?php echo esc_html( View::t( 'detail_risk_' . $risk ) ); ?></span>
				</div>
				<p class="text-sm leading-7 text-amber-950"><?php echo esc_html( View::t( 'detail_risk_copy' ) ); ?></p>
			</section>
			<?php do_action( 'aish_skill_community', $skill, $context['locale'] ); ?>
		</div>
		<aside class="space-y-6" aria-label="<?php echo esc_attr( View::t( 'detail_facts' ) ); ?>">
			<section class="surface surface-pad">
				<h2 class="mb-6 text-lg font-semibold"><?php echo esc_html( View::t( 'detail_source' ) ); ?></h2>
				<?php if ( ! empty( $skill['repository_url'] ) ) : ?>
					<a class="button button-primary mb-6 w-full" href="<?php echo esc_url( $skill['repository_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( View::t( 'detail_open_repository' ) ); ?><?php echo View::icon( 'external' ); ?></a>
				<?php endif; ?>
				<dl class="space-y-5 text-sm">
					<div><dt class="mb-1 muted"><?php echo esc_html( View::t( 'detail_author' ) ); ?></dt><dd class="break-words font-medium"><?php echo esc_html( ! empty( $skill['author'] ) && is_string( $skill['author'] ) ? $skill['author'] : $not_provided ); ?></dd></div>
					<?php if ( ! empty( $skill['repository_owner'] ) ) : ?>
						<div><dt class="mb-1 muted"><?php echo esc_html( View::t( 'detail_maintainer' ) ); ?></dt><dd class="break-words font-medium"><?php echo esc_html( (string) $skill['repository_owner'] ); ?></dd></div>
					<?php endif; ?>
					<div><dt class="mb-1 muted"><?php echo esc_html( View::t( 'detail_repository' ) ); ?></dt><dd class="break-all font-medium"><?php echo esc_html( ! empty( $skill['repository_name'] ) ? $skill['repository_owner'] . '/' . $skill['repository_name'] : View::t( 'detail_manual_source' ) ); ?></dd></div>
					<div><dt class="mb-1 muted"><?php echo esc_html( View::t( 'detail_source_path' ) ); ?></dt><dd class="break-all"><code><?php echo esc_html( (string) ( $skill['source_path'] ?? $not_provided ) ); ?></code></dd></div>
					<div><dt class="mb-1 muted"><?php echo esc_html( View::t( 'detail_license' ) ); ?></dt><dd class="font-medium"><?php echo esc_html( ! empty( $skill['licenses'] ) ? implode( ', ', array_column( $skill['licenses'], 'name' ) ) : View::t( 'detail_unknown_license' ) ); ?></dd></div>
					<div><dt class="mb-1 muted"><?php echo esc_html( View::t( 'detail_version' ) ); ?></dt><dd class="break-all"><code><?php echo esc_html( (string) ( $version['commit_sha'] ?? $not_provided ) ); ?></code></dd></div>
					<?php if ( ! empty( $version['version_label'] ) ) : ?>
						<div><dt class="mb-1 muted"><?php echo esc_html( View::t( 'detail_version_label' ) ); ?></dt><dd><?php echo esc_html( $version['version_label'] ); ?></dd></div>
					<?php endif; ?>
				</dl>
				<?php if ( $version ) : ?><p class="mt-5 text-xs leading-6 muted"><?php echo esc_html( View::t( 'detail_version_note' ) ); ?></p><?php endif; ?>
				<?php if ( ! empty( $skill['source_url'] ) ) : ?>
					<a class="mt-6 inline-flex items-center gap-2 text-sm font-semibold" href="<?php echo esc_url( $skill['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( View::t( 'detail_open_source' ) ); ?><?php echo View::icon( 'external' ); ?></a>
				<?php endif; ?>
			</section>
			<section class="surface surface-pad">
				<h2 class="mb-4 text-lg font-semibold"><?php echo esc_html( View::t( 'detail_platforms' ) ); ?></h2>
				<div class="flex flex-wrap gap-2">
					<?php foreach ( $skill['platforms'] ?? array() as $platform ) : ?>
						<a class="chip chip-soft" href="<?php echo esc_url( View::url( 'skills', array( 'platform' => $platform['slug'] ) ) ); ?>"><?php echo esc_html( $platform['name'] ); ?></a>
					<?php endforeach; ?>
					<?php if ( empty( $skill['platforms'] ) ) : ?><p class="text-sm muted"><?php echo esc_html( $not_provided ); ?></p><?php endif; ?>
				</div>
			</section>
			<section class="surface surface-pad">
				<?php foreach ( array( 'categories' => array( 'detail_categories', 'category' ), 'tags' => array( 'detail_tags', 'tag' ) ) as $field => $configuration ) : ?>
					<h2 class="mb-3 text-sm font-semibold"><?php echo esc_html( View::t( $configuration[0] ) ); ?></h2>
					<div class="mb-5 flex flex-wrap gap-2 last:mb-0">
						<?php foreach ( $skill[ $field ] ?? array() as $term ) : ?>
							<a class="chip chip-soft" href="<?php echo esc_url( View::url( $configuration[1], array( 'slug' => $term['slug'] ) ) ); ?>"><?php echo esc_html( $term['name'] ); ?></a>
						<?php endforeach; ?>
						<?php if ( empty( $skill[ $field ] ) ) : ?><p class="text-sm muted"><?php echo esc_html( $not_provided ); ?></p><?php endif; ?>
					</div>
				<?php endforeach; ?>
			</section>
			<section class="rounded-2xl border border-stone-200 bg-stone-50 p-6">
				<h2 class="mb-3 text-sm font-semibold text-slate-900"><?php echo esc_html( View::t( 'detail_review_information' ) ); ?></h2>
				<p class="text-sm leading-7 text-slate-700"><?php echo esc_html( View::t( 'detail_review_copy' ) ); ?></p>
				<p class="mt-3 text-xs text-slate-600"><?php echo esc_html( View::t( 'detail_reviewed_on' ) . ' ' . View::date( $skill['reviewed_at'] ?? null ) ); ?></p>
			</section>
		</aside>
	</div>
	<?php if ( has_action( 'aish_discovery_recommendations' ) ) : do_action( 'aish_discovery_recommendations', $skill, $context['locale'] ); else : ?>
	<section class="pt-16" aria-labelledby="related-skills">
		<div class="section-heading"><h2 id="related-skills"><?php echo esc_html( View::t( 'detail_related_skills' ) ); ?></h2><a href="<?php echo esc_url( View::url( 'skills' ) ); ?>"><?php echo esc_html( View::t( 'detail_browse_skills' ) ); ?> →</a></div>
		<?php if ( ! empty( $data['related'] ) ) : ?>
			<div class="skill-grid"><?php foreach ( $data['related'] as $related ) { get_template_part( 'template-parts/frontend/skill-card', null, array( 'skill' => $related ) ); } ?></div>
		<?php else : ?>
			<p class="surface surface-pad muted"><?php echo esc_html( View::t( 'detail_no_related_skills' ) ); ?></p>
		<?php endif; ?>
	</section>
	<?php endif; ?>
	<section class="pb-8 pt-12" aria-labelledby="related-tutorials">
		<div class="section-heading"><h2 id="related-tutorials"><?php echo esc_html( View::t( 'detail_related_tutorials' ) ); ?></h2><a href="<?php echo esc_url( View::url( 'tutorials' ) ); ?>"><?php echo esc_html( View::t( 'detail_browse_tutorials' ) ); ?> →</a></div>
		<?php if ( ! empty( $data['tutorials'] ) ) : ?>
			<div class="skill-grid"><?php foreach ( $data['tutorials'] as $tutorial ) { get_template_part( 'template-parts/frontend/tutorial-card', null, array( 'tutorial' => $tutorial ) ); } ?></div>
		<?php else : ?>
			<p class="surface surface-pad muted"><?php echo esc_html( View::t( 'detail_no_related_tutorials' ) ); ?></p>
		<?php endif; ?>
	</section>
	<?php do_action( 'aish_commercial_placement', 'skill_detail_footer', $context['locale'] ); ?>
</div>
