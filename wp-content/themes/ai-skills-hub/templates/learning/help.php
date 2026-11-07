<?php
use AISkillsHub\Core\Frontend\{Router, View};
use AISkillsHub\Core\Learning\Help;
defined( 'ABSPATH' ) || exit;
$context = Router::context(); $locale = $context['locale']; $zh = 'zh_CN' === $locale;
$data = $context['data']['help'] ?? array(); $filters = $data['filters'] ?? array(); $items = $data['items'] ?? array();
$t = static fn( string $cn, string $en ): string => $zh ? $cn : $en;
?>
<div class="aish-container learning-page">
	<?php echo View::breadcrumbs(); ?>
	<header class="learning-heading"><p class="eyebrow"><?php echo esc_html( $t( '学习与支持', 'Learn and get support' ) ); ?></p><h1><?php echo esc_html( $t( '帮助中心', 'Help center' ) ); ?></h1><p><?php echo esc_html( $t( '从认识 Agent Skills 到安全使用和参与社区，查找经过编辑发布的当前语言指南。', 'Find published guides in your language, from understanding Agent Skills to safer use and community participation.' ) ); ?></p></header>
	<form class="learning-search" action="<?php echo esc_url( Help::url( $locale ) ); ?>" method="get" role="search">
		<div><label class="field-label" for="help-search"><?php echo esc_html( $t( '搜索帮助', 'Search help' ) ); ?></label><input class="field-input" id="help-search" name="q" type="search" maxlength="100" value="<?php echo esc_attr( $filters['q'] ?? '' ); ?>" placeholder="<?php echo esc_attr( $t( '安装、投稿、许可…', 'Installation, submissions, licenses…' ) ); ?>"></div>
		<div><label class="field-label" for="help-category"><?php echo esc_html( $t( '主题分类', 'Topic category' ) ); ?></label><select class="field-input" id="help-category" name="help_category"><option value=""><?php echo esc_html( $t( '全部分类', 'All categories' ) ); ?></option><?php foreach ( $data['categories'] ?? Help::categories( $locale ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['help_category'] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
		<button class="button button-primary" type="submit"><?php echo esc_html( $t( '查找', 'Search' ) ); ?></button>
	</form>
	<div class="learning-layout">
		<aside class="learning-sidebar"><nav aria-label="<?php echo esc_attr( $t( '帮助主题', 'Help topics' ) ); ?>"><h2><?php echo esc_html( $t( '按主题浏览', 'Browse by topic' ) ); ?></h2><ul><?php foreach ( $data['categories'] ?? Help::categories( $locale ) as $value => $label ) : ?><li><a <?php if ( ( $filters['help_category'] ?? '' ) === $value ) { echo 'aria-current="page"'; } ?> href="<?php echo esc_url( Help::url( $locale, array( 'help_category' => $value ) ) ); ?>"><?php echo esc_html( $label ); ?></a></li><?php endforeach; ?></ul></nav><div class="learning-contact"><h2><?php echo esc_html( $t( '仍需帮助？', 'Still need help?' ) ); ?></h2><p><?php echo esc_html( $t( '提供页面地址和脱敏问题说明，请勿提交密钥或私人文件。', 'Share the page URL and a sanitized description. Keep credentials and private files out.' ) ); ?></p><a href="<?php echo esc_url( View::url( 'contact', array(), $locale ) ); ?>"><?php echo esc_html( $t( '联系与帮助', 'Contact and help' ) ); ?></a></div></aside>
		<section class="learning-results" aria-labelledby="help-results-title"><div class="learning-results-heading"><h2 id="help-results-title"><?php echo esc_html( $t( '学习指南与社区说明', 'Guides and community information' ) ); ?></h2><span><?php echo esc_html( sprintf( $t( '%d 篇已发布', '%d published articles' ), (int) ( $data['total'] ?? 0 ) ) ); ?></span></div>
			<?php if ( ! $items ) : ?><div class="learning-empty" role="status"><h3><?php echo esc_html( $t( '暂无符合条件的指南', 'No matching guides yet' ) ); ?></h3><p><?php echo esc_html( $t( '尝试其他关键词或减少筛选。未发布内容及缺少翻译的文章不会自动补入结果。', 'Try another term or fewer filters. Unpublished content and missing translations are not filled in automatically.' ) ); ?></p><a href="<?php echo esc_url( Help::url( $locale ) ); ?>"><?php echo esc_html( $t( '查看所有帮助', 'View all help' ) ); ?></a></div><?php else : ?><ul class="learning-article-list"><?php foreach ( $items as $item ) : ?><li><article><h3><a href="<?php echo esc_url( View::tutorialUrl( $item, $locale ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></h3><p><?php echo esc_html( $item['summary'] ); ?></p><small><?php echo esc_html( $t( '更新于 ', 'Updated ' ) . View::date( $item['updated_at'] ?? null ) ); ?></small></article></li><?php endforeach; ?></ul><?php endif; ?>
			<?php echo View::pagination( (int) ( $data['total'] ?? 0 ), (int) ( $data['page'] ?? 1 ), (int) ( $data['per_page'] ?? 12 ), $filters ); ?>
		</section>
	</div>
</div>
