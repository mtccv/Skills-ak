<?php
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
$entry = $args['entry']; $preview = ! empty( $args['preview'] ); $zh = 'zh_CN' === $entry['locale'];
?>
<aside class="aish-container my-6" aria-label="<?php echo esc_attr( $zh ? '赞助展示' : 'Sponsored placement' ); ?>" data-sponsor-id="<?php echo (int) $entry['id']; ?>" <?php foreach ( $args['attributes'] as $key => $value ) { echo esc_attr( $key ) . '="' . esc_attr( $value ) . '" '; } ?>>
	<div class="border border-slate-200 bg-white p-4 sm:p-5" style="overflow-wrap:anywhere">
		<p class="mb-2 text-xs font-semibold text-slate-600" data-sponsor-label><?php echo esc_html( $zh ? '赞助' : 'Sponsored' ); ?> · <span dir="auto"><?php echo esc_html( $entry['name'] ); ?></span></p>
		<div class="flex items-start gap-4">
			<?php if ( $entry['image_url'] ) : ?><img src="<?php echo esc_url( $entry['image_url'] ); ?>" alt="" class="h-12 w-12 shrink-0 object-contain" width="48" height="48" loading="lazy" decoding="async"><?php endif; ?>
			<div class="min-w-0"><h2 class="m-0 text-base font-semibold text-slate-900"><a data-commercial-link href="<?php echo esc_url( $entry['url'] ); ?>" rel="sponsored nofollow noopener" style="color:#AD5E48" dir="auto"><?php echo esc_html( $entry['title'] ); ?></a></h2><p class="mb-0 mt-1 text-sm text-slate-600" dir="auto"><?php echo esc_html( $entry['description'] ); ?></p></div>
		</div>
		<?php if ( ! $preview ) { try { do_action( 'aish_sponsor_tracking_controls', $entry ); } catch ( \Throwable $error ) { /* Optional observations cannot block the link. */ } } ?>
	</div>
</aside>
