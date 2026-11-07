<?php
/** Canonical public data only; no request parameters, previews or private notes. */
defined( 'ABSPATH' ) || exit;
$share = $args['share']; $zh = 'zh_CN' === $args['locale']; $t = static fn( string $cn, string $en ): string => $zh ? $cn : $en;
?>
<section class="aish-skill-share" aria-label="<?php echo esc_attr( $t( '分享资源', 'Share this Skill' ) ); ?>" data-skill-share data-share-title="<?php echo esc_attr( $share['title'] ); ?>" data-share-url="<?php echo esc_attr( $share['url'] ); ?>" data-copy-success="<?php echo esc_attr( $t( '已复制。请按资源许可证使用。', 'Copied. Follow the resource license when using it.' ) ); ?>" data-copy-failure="<?php echo esc_attr( $t( '未能复制。请选中下方完整文本手动复制。', 'Could not copy. Select the complete text below and copy it manually.' ) ); ?>" data-share-failure="<?php echo esc_attr( $t( '系统分享未完成，可使用下方链接。', 'System sharing did not complete. Use the links below.' ) ); ?>">
	<div class="aish-skill-share-actions">
		<button type="button" class="button button-secondary" data-native-share hidden><?php echo esc_html( $t( '系统分享', 'Share' ) ); ?></button>
		<button type="button" class="button button-secondary" data-copy-target="aish-share-public-url" hidden><?php echo esc_html( $t( '复制详情链接', 'Copy page URL' ) ); ?></button>
		<?php if ( $share['source_url'] ) : ?><button type="button" class="button button-secondary" data-copy-target="aish-share-source-url" hidden><?php echo esc_html( $t( '复制 GitHub 来源', 'Copy GitHub source' ) ); ?></button><?php endif; ?>
		<?php foreach ( $share['social'] as $label => $url ) : ?><a class="aish-share-network" href="<?php echo esc_url( $url ); ?>" rel="noopener noreferrer" target="_blank" aria-label="<?php echo esc_attr( $t( '分享到 ', 'Share on ' ) . $label ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
	</div>
	<p class="aish-copy-status" role="status" aria-live="polite" aria-atomic="true"></p>
	<details class="aish-share-text"><summary><?php echo esc_html( $t( '查看可复制的公开链接', 'View public links for copying' ) ); ?></summary>
		<label for="aish-share-public-url"><?php echo esc_html( $t( '当前语言详情页', 'Page in the current language' ) ); ?></label><input class="field-input" id="aish-share-public-url" type="url" readonly value="<?php echo esc_attr( $share['url'] ); ?>">
		<?php if ( $share['source_url'] ) : ?><label for="aish-share-source-url"><?php echo esc_html( $t( 'GitHub 来源（HEAD，可发生变化）', 'GitHub source (HEAD, may change)' ) ); ?></label><input class="field-input" id="aish-share-source-url" type="url" readonly value="<?php echo esc_attr( $share['source_url'] ); ?>"><?php endif; ?>
	</details>
</section>
