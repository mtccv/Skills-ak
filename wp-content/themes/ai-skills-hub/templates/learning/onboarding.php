<?php
use AISkillsHub\Core\Learning\Onboarding;
defined( 'ABSPATH' ) || exit;
$step = $args['step']; $locale = $args['locale']; $copy = $args['copy']; $signedIn = ! empty( $args['signed_in'] ); $zh = 'zh_CN' === $locale;
?>
<aside class="aish-onboarding" data-onboarding-step="<?php echo esc_attr( $step ); ?>" data-onboarding-scope="<?php echo $signedIn ? 'account' : 'device'; ?>" data-onboarding-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-onboarding-state="<?php echo esc_attr( wp_json_encode( $args['state'] ) ); ?>" data-onboarding-failed="<?php echo esc_attr( $zh ? '未能保存引导偏好，请重试。' : 'Could not save this preference. Please retry.' ); ?>" aria-labelledby="onboarding-<?php echo esc_attr( $step ); ?>" <?php echo $signedIn ? '' : 'hidden'; ?>>
	<h2 id="onboarding-<?php echo esc_attr( $step ); ?>"><?php echo esc_html( $copy[0] ); ?></h2><p><?php echo esc_html( $copy[1] ); ?></p>
	<div class="aish-onboarding-actions"><a href="<?php echo esc_url( Onboarding::helpUrl( $locale ) ); ?>"><?php echo esc_html( $zh ? '打开帮助中心' : 'Open Help Center' ); ?></a>
	<?php if ( $signedIn ) : ?><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-onboarding-form><input type="hidden" name="action" value="aish_onboarding"><input type="hidden" name="operation" value="dismiss"><input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>"><input type="hidden" name="locale" value="<?php echo esc_attr( $locale ); ?>"><?php wp_nonce_field( Onboarding::ACTION ); ?><button type="submit" data-onboarding-dismiss><?php echo esc_html( $zh ? '知道了' : 'Got it' ); ?></button><button type="submit" data-onboarding-dismiss><?php echo esc_html( $zh ? '跳过' : 'Skip' ); ?></button></form>
	<?php else : ?><button type="button" data-onboarding-dismiss><?php echo esc_html( $zh ? '知道了' : 'Got it' ); ?></button><button type="button" data-onboarding-dismiss><?php echo esc_html( $zh ? '跳过' : 'Skip' ); ?></button><?php endif; ?>
	</div><p class="aish-onboarding-status" role="status" aria-live="polite" data-onboarding-status></p>
</aside>
<?php if ( ! $signedIn ) : ?><noscript><aside class="aish-onboarding"><h2><?php echo esc_html( $copy[0] ); ?></h2><p><?php echo esc_html( $copy[1] ); ?></p><a href="<?php echo esc_url( Onboarding::helpUrl( $locale ) ); ?>"><?php echo esc_html( $zh ? '打开帮助中心' : 'Open Help Center' ); ?></a></aside></noscript><?php endif; ?>
