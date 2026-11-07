<?php
use AISkillsHub\Core\Discovery\Frontend as Discovery;
use AISkillsHub\Core\Frontend\View;
defined( 'ABSPATH' ) || exit;
$topic = $args['topic']; $locale = $args['locale']; $route = $args['route'];
?>
<article class="discovery-topic"><h3><a href="<?php echo esc_url( Discovery::link( $route, $locale, array( 'id' => $topic['id'], 'slug' => $topic['slug'] ) ) ); ?>"><?php echo esc_html( $topic['title'] ?? $topic['name'] ); ?></a></h3><p><?php echo esc_html( wp_strip_all_tags( (string) $topic['description'] ) ); ?></p><div class="discovery-topic-footer"><span><?php echo esc_html( number_format_i18n( $topic['count'] ) . ' Skills' ); ?></span><?php echo View::icon( 'arrow-right' ); ?></div></article>
