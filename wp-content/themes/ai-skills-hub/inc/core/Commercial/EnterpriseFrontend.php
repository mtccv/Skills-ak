<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;
use AISkillsHub\Core\Frontend\{Forms,Router};
defined( 'ABSPATH' ) || exit;

/** Contact extension; no separate public landing page or invented service copy. */
final class EnterpriseFrontend {
	public function __construct( private Enterprise $service, private Forms $forms ) {}
	public function register(): void {
		add_action( 'aish_contact_enterprise', function ( string $locale ): void { try { $this->render( $locale ); } catch ( \Throwable $error ) { /* Optional inquiry rendering cannot interrupt Contact. */ } } );
		add_filter( 'aish_frontend_context', static function ( array $context ): array { if ( 'contact' === ( $context['route'] ?? '' ) && isset( $_GET['enterprise'] ) ) { $context['noindex'] = true; $context['canonical'] = ''; $context['seo_alternates'] = array(); $context['alternates'] = array(); $context['x_default'] = ''; $context['seo_managed'] = true; } return $context; } );
		add_action( 'template_redirect', static function (): void { $c = Router::context(); if ( 'contact' === ( $c['route'] ?? '' ) && isset( $_GET['enterprise'] ) ) { nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); \AISkillsHub\Core\Frontend\Seo::sendHeaders( $c ); } }, 1 );
		add_action( 'admin_post_aish_enterprise_message', array( $this, 'receive' ) );
		add_action( 'admin_post_nopriv_aish_enterprise_message', array( $this, 'receive' ) );
	}
	public function render( string $locale ): void {
		$copy = Enterprise::publicCopy( $locale ); if ( ! $copy ) { return; } $requestKey = bin2hex( random_bytes( 32 ) );
		$zh = 'zh_CN' === $locale; $t = static fn( string $cn, string $en ): string => $zh ? $cn : $en;
		$status = isset( $_GET['enterprise'] ) && is_string( $_GET['enterprise'] ) ? $_GET['enterprise'] : '';
		echo '<section id="enterprise-inquiry" class="mt-9 border-t border-slate-200 pt-8"><h2 class="text-xl font-semibold">' . esc_html( $copy['title'] ) . '</h2><p class="my-4 whitespace-pre-line text-sm leading-7 muted">' . esc_html( $copy['description'] ) . '</p>';
		echo '<p class="mb-5 text-sm leading-7">' . esc_html( $t( '提交仅表示发送私有咨询，交由人工处理；不构成订单、合同或服务交付。请勿填写密码、密钥或其他敏感凭据。', 'This sends a private inquiry for manual handling; it does not create an order, contract, or delivered service. Do not include passwords, keys, or other credentials.' ) ) . '</p>';
		if ( in_array( $status, array( 'sent', 'invalid', 'expired', 'limited', 'error' ), true ) ) {
			$message = match ( $status ) { 'sent' => $t( '咨询已保存，等待人工处理。未自动发送邮件或创建订单。', 'Your inquiry was saved for manual handling. No email or order was created automatically.' ), 'limited' => $t( '提交过于频繁，请稍后重试。', 'Too many requests. Please try again later.' ), 'expired' => $t( '表单已过期，请刷新后重试。', 'This form expired. Refresh and try again.' ), 'invalid' => $t( '请检查姓名、邮箱和需求描述。', 'Check your name, email and request.' ), default => $t( '未能确认保存成功，请稍后重试。', 'We could not confirm that your inquiry was saved. Try again later.' ) };
			echo '<p role="status" class="mb-5 border border-slate-300 bg-slate-50 p-4 text-sm">' . esc_html( $message ) . '</p>';
		}
		echo '<form id="aish-enterprise-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="space-y-4" x-data="{ submitting: false }" @submit="submitting = true" :aria-busy="submitting"><input type="hidden" name="action" value="aish_enterprise_message"><input type="hidden" name="locale" value="' . esc_attr( $locale ) . '"><input type="hidden" name="request_key" value="' . esc_attr( $requestKey ) . '">';
		wp_nonce_field( 'aish_enterprise_message' );
		echo '<div class="sr-only" aria-hidden="true"><label for="enterprise-website">Website</label><input id="enterprise-website" name="website" value="" tabindex="-1" autocomplete="off"></div>';
		foreach ( array( 'name' => array( $t( '联系人', 'Contact name' ), 'text', 120 ), 'email' => array( $t( '联系邮箱', 'Contact email' ), 'email', 254 ) ) as $field => $spec ) { echo '<div class="form-field"><label class="field-label" for="enterprise-' . esc_attr( $field ) . '">' . esc_html( $spec[0] ) . '</label><input class="field-input" id="enterprise-' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" type="' . esc_attr( $spec[1] ) . '" maxlength="' . esc_attr( (string) $spec[2] ) . '" autocomplete="' . esc_attr( $field ) . '" required></div>'; }
		echo '<div class="form-field"><label class="field-label" for="enterprise-message">' . esc_html( $t( '需求描述', 'What do you need help with?' ) ) . '</label><textarea id="enterprise-message" name="message" class="field-input" rows="5" maxlength="3000" required></textarea></div><p class="text-xs leading-6 muted">' . esc_html( $t( '联系方式仅用于人工处理本次咨询，不加入营销名单。', 'Contact details are used for this inquiry only and are not added to a marketing list.' ) ) . '</p><button class="button button-primary" type="submit" :disabled="submitting"><span x-show="!submitting">' . esc_html( $t( '发送企业咨询', 'Send enterprise inquiry' ) ) . '</span><span x-cloak x-show="submitting">' . esc_html( $t( '正在提交…', 'Submitting…' ) ) . '</span></button></form></section>';
	}
	public function receive(): never {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POST required.', '', array( 'response' => 405 ) ); }
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && ( ! ctype_digit( (string) $_SERVER['CONTENT_LENGTH'] ) || (int) $_SERVER['CONTENT_LENGTH'] > 65536 ) ) { wp_die( 'Request too large.', '', array( 'response' => 413 ) ); }
		$locale = 'en_US' === ( $_POST['locale'] ?? '' ) ? 'en_US' : 'zh_CN'; $status = 'error';
		try {
			$data = wp_unslash( $_POST );
			if ( array_diff( array_keys( $data ), array( 'action', 'locale', 'request_key', 'website', 'name', 'email', 'message', '_wpnonce', '_wp_http_referer' ) ) ) { throw new \InvalidArgumentException( 'invalid_request' ); }
			if ( ! is_string( $data['_wpnonce'] ?? null ) || ! wp_verify_nonce( $data['_wpnonce'], 'aish_enterprise_message' ) ) { $status = 'expired'; }
			elseif ( ! in_array( $data['locale'] ?? null, array( 'zh_CN', 'en_US' ), true ) || ! is_string( $data['request_key'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['request_key'] ) ) { $status = 'invalid'; }
			elseif ( ! Enterprise::publicCopy( $locale ) ) { $status = 'error'; }
			else { Forms::validateContact( $data ); if ( ! $this->forms->rateLimit() ) { $status = 'limited'; } else { $this->service->receive( $data, $locale, $data['request_key'] ); $status = 'sent'; } }
		} catch ( \InvalidArgumentException $error ) { $status = 'invalid'; } catch ( \Throwable $error ) { $status = 'error'; }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		try { $url = Router::url( 'contact', array(), $locale ); } catch ( \Throwable $error ) { $url = Router::siteUrl( '/' . ( 'en_US' === $locale ? 'en' : 'zh' ) . '/contact/' ); }
		wp_safe_redirect( add_query_arg( 'enterprise', $status, $url ) . '#enterprise-inquiry', 303 ); exit;
	}
}
