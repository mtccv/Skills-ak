<?php
use AISkillsHub\Core\Discovery\Frontend as Discovery;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$module = $args['module']; $locale = $args['locale']; $key = $module['key']; $items = $args['items']; $kind = $args['kind'];
$title = $module['title'] ?: Discovery::t( 'collections' === $key ? 'editors-picks' : str_replace( '_', '-', $key ), $locale );
$description = $module['description'] ?? ''; $contained = ! empty( $args['contained'] );
?>
<section class="discovery-module <?php echo $contained ? '' : 'aish-container'; ?>" aria-labelledby="discovery-<?php echo esc_attr( $key ); ?>">
	<div class="section-heading"><div><h2 id="discovery-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></h2><?php if ( $description ) : ?><p><?php echo esc_html( $description ); ?></p><?php endif; ?></div><?php if ( $args['link'] ) : ?><a class="text-link" href="<?php echo esc_url( $args['link'] ); ?>"><?php echo esc_html( Discovery::t( 'view-all', $locale ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a><?php endif; ?></div>
	<?php if ( in_array( $module['source'] ?? '', array( 'selected', 'skills' ), true ) ) : ?><p class="discovery-notice"><strong><?php echo esc_html( 'zh_CN' === $locale ? '人工精选 · 非自然排行' : 'Editorial selection · not a natural ranking' ); ?></strong><?php if ( ! empty( $module['editorial_reason'] ) ) : ?> — <?php echo esc_html( $module['editorial_reason'] ); ?><?php endif; ?></p><?php endif; ?>
	<?php if ( $args['error'] ) : ?><p class="discovery-notice" role="status"><?php echo esc_html( Discovery::t( 'error', $locale ) ); ?></p>
	<?php elseif ( ! $items ) : ?><p class="discovery-empty"><?php echo esc_html( Discovery::t( 'empty', $locale ) ); ?></p>
	<?php else : ?><div class="<?php echo 'tutorials' === $kind ? 'skill-grid' : 'discovery-grid'; ?>">
		<?php foreach ( $items as $item ) : ?>
			<?php if ( 'skills' === $kind ) { get_template_part( 'templates/discovery/skill', null, array( 'skill' => $item, 'locale' => $locale ) ); }
			elseif ( 'topics' === $kind ) { get_template_part( 'templates/discovery/topic', null, array( 'topic' => $item, 'locale' => $locale, 'route' => str_replace( '_', '-', $key ) ) ); }
			elseif ( 'tutorials' === $kind ) { get_template_part( 'template-parts/frontend/tutorial-card', null, array( 'tutorial' => $item ) ); }
			else { ?><a class="discovery-category" href="<?php echo esc_url( View::url( 'category', array( 'slug' => $item['slug'] ), $locale ) ); ?>"><strong><?php echo esc_html( $item['display_name'] ?? $item['name'] ); ?></strong><span><?php echo esc_html( number_format_i18n( $item['count'] ?? 0 ) . ' Skills' ); ?></span><?php if ( ! empty( $item['translation_missing'] ) ) : ?><small><?php echo esc_html( Discovery::t( 'translation-missing', $locale ) ); ?></small><?php endif; ?></a><?php } ?>
		<?php endforeach; ?>
	</div><?php endif; ?>
</section>
