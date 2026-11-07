<?php
use AISkillsHub\Core\Discovery\Frontend as Discovery;
use AISkillsHub\Core\Discovery\RankingPolicy;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$skill = $args['skill'] ?? array(); $locale = $args['locale']; $metric = $skill['metric'] ?? array();
if ( empty( $skill['id'] ) || empty( $skill['title'] ) ) { return; }
?>
<article class="discovery-resource">
	<div class="discovery-resource-heading"><h3><a href="<?php echo esc_url( View::skillUrl( $skill, $locale ) ); ?>"><?php echo esc_html( $skill['title'] ); ?></a></h3><span class="chip"><?php echo esc_html( 'zh_CN' === $locale ? '内容已审核' : 'Content reviewed' ); ?></span></div>
	<p class="discovery-summary"><?php echo esc_html( wp_strip_all_tags( (string) ( $skill['summary'] ?? '' ) ) ); ?></p>
	<div class="discovery-tags"><?php foreach ( array_slice( $skill['categories'] ?? array(), 0, 2 ) as $category ) : ?><a class="chip" href="<?php echo esc_url( View::url( 'category', array( 'slug' => $category['slug'] ), $locale ) ); ?>"><?php echo esc_html( $category['name'] ); ?><?php if ( ! empty( $category['translation_missing'] ) ) : ?><span title="<?php echo esc_attr( Discovery::t( 'translation-missing', $locale ) ); ?>"> *</span><?php endif; ?></a><?php endforeach; ?><?php foreach ( array_slice( $skill['platforms'] ?? array(), 0, 2 ) as $platform ) : ?><span class="chip"><?php echo esc_html( $platform['name'] ); ?></span><?php endforeach; ?></div>
	<?php if ( $metric ) : ?><dl class="discovery-metrics">
		<?php foreach ( array( 'views', 'favorites' ) as $key ) : if ( isset( $metric[ $key ] ) ) : ?><div><dt><?php echo esc_html( Discovery::t( $key, $locale ) ); ?></dt><dd><?php echo esc_html( number_format_i18n( $metric[ $key ] ) ); ?></dd></div><?php endif; endforeach; ?>
		<?php if ( isset( $metric['average'], $metric['reviews'] ) ) : ?><div><dt><?php echo esc_html( Discovery::t( 'reviews', $locale ) ); ?></dt><dd><?php echo esc_html( number_format_i18n( $metric['average'], 1 ) . ' / 5 · ' . number_format_i18n( $metric['reviews'] ) ); ?><?php if ( $metric['reviews'] < RankingPolicy::MIN_REVIEWS ) : ?> <span><?php echo esc_html( Discovery::t( 'limited-sample', $locale ) ); ?></span><?php endif; ?></dd></div><?php endif; ?>
		<?php foreach ( array( 'created_at' => 'added', 'approved_at' => 'approved-update' ) as $field => $label ) : if ( ! empty( $metric[ $field ] ) ) : ?><div><dt><?php echo esc_html( Discovery::t( $label, $locale ) ); ?></dt><dd><time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $metric[ $field ] ) . 'Z' ); ?>"><?php echo esc_html( View::date( $metric[ $field ] ) ); ?></time></dd></div><?php endif; endforeach; ?>
	</dl><?php endif; ?>
	<?php if ( ! empty( $metric['distribution'] ) ) : ?><details class="discovery-distribution"><summary><?php echo esc_html( 'zh_CN' === $locale ? '评分分布' : 'Rating distribution' ); ?></summary><dl><?php foreach ( array( 5, 4, 3, 2, 1 ) as $star ) : ?><div><dt><?php echo esc_html( $star . ' / 5' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $metric['distribution'][ $star ] ?? 0 ) ); ?></dd></div><?php endforeach; ?></dl></details><?php endif; ?>
</article>
