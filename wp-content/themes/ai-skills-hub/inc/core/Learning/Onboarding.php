<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Learning;

use AISkillsHub\Core\Accounts\{AccountException,AccountService,Frontend as Accounts,Locks};
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Optional help preferences, never evidence of account verification or a business action. */
final class Onboarding {
	public const META = '_aish_onboarding_steps';
	public const STEPS = array( 'welcome', 'registration', 'verified', 'search', 'favorite', 'review', 'submission', 'account' );
	public const ACTION = 'aish_onboarding';

	public function __construct( private Database $db, private AccountService $accounts, private Locks $locks ) {}

	public function register(): void {
		add_action( 'aish_onboarding_context', array( $this, 'renderContext' ), 10, 3 );
		add_action( 'aish_onboarding_settings', array( $this, 'settings' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'receive' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'receiveForm' ) );
		add_filter( 'aish_account_privacy_export', function ( array $result, \WP_User $user, int $page ): array {
			if ( 1 !== $page ) { return $result; }
			if ( ! current_user_can( 'export_others_personal_data' ) ) { throw new \RuntimeException( 'onboarding_forbidden' ); }
			$state = $this->stored( (int) $user->ID );
			if ( $state['completed'] ) { $result['data'][] = array( 'group_id' => 'aish-help-preferences', 'group_label' => 'AI Skills Hub help preferences', 'item_id' => 'aish-help-' . $user->ID, 'data' => array( array( 'name' => 'Dismissed or completed help introductions', 'value' => implode( ', ', $state['completed'] ) ) ) ); }
			return $result;
		}, 10, 3 );
		add_filter( 'aish_account_privacy_erase_batch', function ( bool $done, int $userId ): bool {
			// The existing privacy workflow has already bound the request and account lock.
			if ( $userId < 1 ) { return false; }
			$this->db->execute( 'DELETE FROM ' . $this->db->connection()->usermeta . ' WHERE user_id=%d AND meta_key=%s', array( $userId, self::META ) );
			wp_cache_delete( $userId, 'user_meta' ); return $done;
		}, 10, 2 );
	}

	/** No caller-provided account identity is accepted. Pending accounts can dismiss help. */
	public function mine(): array {
		$user = $this->accounts->requireOwner( false );
		return $this->stored( (int) $user->ID );
	}
	public function complete( string $step ): array {
		if ( ! in_array( $step, self::STEPS, true ) ) { throw new \InvalidArgumentException( 'onboarding_input' ); }
		return $this->change( $step );
	}
	public function reset(): array { return $this->change( null ); }
	private function change( ?string $step ): array {
		$user = $this->accounts->requireOwner( false ); $id = (int) $user->ID;
		return $this->locks->withUser( $id, function () use ( $id, $step ): array {
			if ( (int) $this->accounts->requireOwner( false )->ID !== $id ) { throw new \RuntimeException( 'onboarding_forbidden' ); }
			try {
				return $this->db->transaction( function () use ( $id, $step ): array {
					$before = $this->stored( $id, true );
					$completed = null === $step ? array() : array_values( array_intersect( self::STEPS, array_merge( $before['completed'], array( $step ) ) ) );
					$after = array( 'version' => 1, 'completed' => $completed );
					if ( $before === $after ) { return $after; }
					$table = $this->db->connection()->usermeta;
					if ( ! $completed ) { $this->db->execute( "DELETE FROM $table WHERE user_id=%d AND meta_key=%s", array( $id, self::META ) ); }
					else {
						$exists = $this->db->value( "SELECT umeta_id FROM $table WHERE user_id=%d AND meta_key=%s LIMIT 1", array( $id, self::META ) );
						if ( $exists ) { $this->db->execute( "UPDATE $table SET meta_value=%s WHERE user_id=%d AND meta_key=%s", array( wp_json_encode( $after ), $id, self::META ) ); }
						else { $this->db->execute( "INSERT INTO $table (user_id,meta_key,meta_value) VALUES (%d,%s,%s)", array( $id, self::META, wp_json_encode( $after ) ) ); }
					}
					return $after;
				} );
			} finally { wp_cache_delete( $id, 'user_meta' ); }
		} );
	}
	private function stored( int $userId, bool $lock = false ): array {
		if ( $userId < 1 ) { throw new \RuntimeException( 'onboarding_forbidden' ); }
		$rows = $this->db->rows( 'SELECT meta_value FROM ' . $this->db->connection()->usermeta . ' WHERE user_id=%d AND meta_key=%s LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ), array( $userId, self::META ) );
		if ( ! $rows ) { return array( 'version' => 1, 'completed' => array() ); }
		if ( 1 !== count( $rows ) || ! is_string( $rows[0]['meta_value'] ) || strlen( $rows[0]['meta_value'] ) > 256 ) { throw new \RuntimeException( 'onboarding_storage' ); }
		$data = json_decode( $rows[0]['meta_value'], true );
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'version', 'completed' ) || 1 !== $data['version'] || ! is_array( $data['completed'] ) || ! array_is_list( $data['completed'] ) || count( $data['completed'] ) > count( self::STEPS ) ) { throw new \RuntimeException( 'onboarding_storage' ); }
		foreach ( $data['completed'] as $step ) { if ( ! is_string( $step ) || ! in_array( $step, self::STEPS, true ) ) { throw new \RuntimeException( 'onboarding_storage' ); } }
		return array( 'version' => 1, 'completed' => array_values( array_intersect( self::STEPS, $data['completed'] ) ) );
	}

	public function request( array $data ): array {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { throw new \DomainException( 'onboarding_method' ); }
		if ( array_diff( array_keys( $data ), array( 'action', 'operation', 'step', '_wpnonce', '_wp_http_referer', 'locale' ) ) || ! is_string( $data['_wpnonce'] ?? null ) || ! wp_verify_nonce( $data['_wpnonce'], self::ACTION ) ) { throw new \DomainException( 'onboarding_forbidden' ); }
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ( ! is_string( $origin ) || ( ! in_array( $origin, array( '', 'null' ), true ) && ! self::sameOrigin( $origin, Router::siteUrl() ) ) ) { throw new \DomainException( 'onboarding_forbidden' ); }
		if ( ! in_array( $data['locale'] ?? '', array( 'zh_CN', 'en_US' ), true ) || ! in_array( $data['operation'] ?? null, array( 'dismiss', 'reset' ), true ) ) { throw new \InvalidArgumentException( 'onboarding_input' ); }
		if ( 'reset' === $data['operation'] ) { if ( isset( $data['step'] ) && '' !== $data['step'] ) { throw new \InvalidArgumentException( 'onboarding_input' ); } return $this->reset(); }
		if ( ! is_string( $data['step'] ?? null ) ) { throw new \InvalidArgumentException( 'onboarding_input' ); }
		return $this->complete( $data['step'] );
	}
	public function receive(): never {
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		try { $result = $this->request( wp_unslash( $_POST ) ); }
		catch ( \Throwable $error ) { wp_send_json_error( array( 'code' => 'onboarding_unavailable' ), 'onboarding_method' === $error->getMessage() ? 405 : ( $error instanceof \InvalidArgumentException ? 400 : ( $error instanceof \DomainException || $error instanceof AccountException ? 403 : 503 ) ) ); }
		wp_send_json_success( $result );
	}
	public function receiveForm(): never {
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		$locale = 'en_US' === ( $_POST['locale'] ?? '' ) ? 'en_US' : 'zh_CN';
		try { $this->request( wp_unslash( $_POST ) ); }
		catch ( \Throwable $error ) { wp_die( esc_html( 'zh_CN' === $locale ? '未能更新引导偏好，请返回后重试。' : 'Help preferences could not be updated. Go back and retry.' ), '', array( 'response' => 'onboarding_method' === $error->getMessage() ? 405 : 403 ) ); }
		wp_safe_redirect( Accounts::url( 'settings', $locale ) . '#aish-help-preferences', 303 ); exit;
	}
	private static function sameOrigin( string $left, string $right ): bool {
		$a = wp_parse_url( $left ); $b = wp_parse_url( $right );
		return is_array( $a ) && is_array( $b ) && ! isset( $a['user'] ) && ! isset( $a['pass'] ) && strtolower( (string) ( $a['host'] ?? '' ) ) === strtolower( (string) ( $b['host'] ?? '' ) ) && ( $a['scheme'] ?? '' ) === ( $b['scheme'] ?? '' ) && (int) ( $a['port'] ?? ( 'https' === ( $a['scheme'] ?? '' ) ? 443 : 80 ) ) === (int) ( $b['port'] ?? ( 'https' === ( $b['scheme'] ?? '' ) ? 443 : 80 ) );
	}

	/** Call only with resolved server contexts; signed account/community flash is authoritative. */
	public function renderContext( string $placement, string $locale, array $context = array() ): void {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || ! empty( $context['error'] ) || ( $context['status'] ?? 200 ) >= 400 || isset( $_GET['aish_home_preview'] ) || isset( $_GET['aish_preview'] ) || is_admin() ) { return; }
		$step = match ( $placement ) {
			'home' => 'welcome', 'listing' => 'search',
			'account' => match ( $context['message'] ?? '' ) { 'registration' => 'registration', 'verified' => 'verified', default => 'account' === ( $context['route'] ?? '' ) ? 'account' : '' },
			'community' => 'favorite_saved' === ( $context['message'] ?? '' ) ? 'favorite' : ( 'review' === ( $context['route'] ?? '' ) && empty( $context['head'] ) ? 'review' : '' ),
			'submissions' => 'submit' === ( $context['route'] ?? '' ) ? 'submission' : '',
			default => '',
		};
		if ( '' === $step ) { return; }
		try { $state = is_user_logged_in() ? $this->mine() : array( 'version' => 1, 'completed' => array() ); }
		catch ( \Throwable $error ) { return; }
		if ( in_array( $step, $state['completed'], true ) ) { return; }
		get_template_part( 'templates/learning/onboarding', null, array( 'step' => $step, 'locale' => $locale, 'copy' => self::copy( $step, $locale ), 'signed_in' => is_user_logged_in(), 'state' => $state ) );
	}
	public function settings( string $locale ): void {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { return; }
		try { $state = $this->mine(); } catch ( \Throwable $error ) { return; }
		get_template_part( 'templates/learning/onboarding-settings', null, array( 'locale' => $locale, 'state' => $state ) );
	}
	public static function helpUrl( string $locale ): string { return Router::siteUrl( '/' . ( 'en_US' === $locale ? 'en' : 'zh' ) . '/help/' ); }
	public static function copy( string $step, string $locale ): array {
		$zh = 'zh_CN' === $locale;
		return match ( $step ) {
			'welcome' => $zh ? array( '认识 AI Skills Hub', '从任务出发搜索 Skills，再检查来源、许可证、安装要求与风险。收藏和评价需要登录并验证邮箱。你可以随时跳过这份介绍。' ) : array( 'Start with a task', 'Search for a Skill that fits your task, then check its source, license, requirements and risks. Saving and reviewing require a verified account. You can skip this introduction.' ),
			'registration' => $zh ? array( '欢迎，接下来验证邮箱', '如果该邮箱可以注册，验证邮件会进入发送流程。请查看验证说明；邮件传输受理不代表已送达。验证后再登录，即可使用收藏、评价和投稿。' ) : array( 'Welcome — next, verify your email', 'If this address can be registered, a verification message enters the mail workflow. Follow the verification guidance; transport acceptance does not prove delivery. After verification, sign in to save, review and contribute.' ),
			'verified' => $zh ? array( '邮箱验证已完成', '现在可以登录并开始使用账户。收藏只对自己可见；评价和投稿仍需要人工审核。验证邮箱不会自动订阅任何邮件精选。' ) : array( 'Email verification complete', 'You can now sign in. Favorites stay private, and reviews and submissions still need human moderation. Verification never subscribes you to email digests.' ),
			'search' => $zh ? array( '更快找到合适的 Skill', '先输入具体任务，再组合分类、标签和平台筛选。结果只展示当前语言下可公开的资源；没有结果时，尝试缩短关键词或取消部分筛选。' ) : array( 'Find a Skill that fits', 'Start with a concrete task, then combine category, tag and platform filters. Results contain public resources in this language. Try a shorter query or fewer filters when nothing matches.' ),
			'favorite' => $zh ? array( '已保存到你的收藏', '收藏关系在中英文间共用，且始终为私有数据。你可以在用户中心管理收藏；资源下架或缺少对应翻译时，会显示不可用说明。' ) : array( 'Saved to your favorites', 'Favorites are private and shared across both languages. Manage them in your account. Withdrawn resources or missing translations are shown as unavailable.' ),
			'review' => $zh ? array( '分享实际使用体验', '请基于真实使用填写评分、体验和使用平台。首次评价待审核；修改已公开评价会建立新修订，旧批准版本继续显示。不要发布密钥、个人信息或冒充官方结论。' ) : array( 'Review your actual experience', 'Describe what you used, on which platform, and why you chose your rating. New reviews wait for moderation. Edits create a new revision while the approved version remains visible. Keep credentials and private information out.' ),
			'submission' => $zh ? array( '投稿会经过哪些步骤？', '填写仓库与具体 SKILL.md 路径，可先存草稿再提交。管理员可能要求补充资料；接受投稿仅进入后续处理，不代表资源已公开发布。请只提交你有权分享的信息。' ) : array( 'How submissions work', 'Provide the repository and exact SKILL.md path. Save a draft or submit for review. An administrator may request more information. Acceptance starts further processing; it does not publish a Skill. Share only material you may disclose.' ),
			'account' => $zh ? array( '认识你的个人中心', '在这里查看收藏、评价、投稿和通知，并管理资料、邮箱与隐私设置。帮助中心随时可用；在账户设置中可重新查看或重置这些引导。' ) : array( 'Your personal workspace', 'Manage favorites, reviews, submissions and notifications here, along with your profile, email and privacy settings. Help is always available; revisit or reset these introductions in Account Settings.' ),
			default => throw new \InvalidArgumentException( 'onboarding_input' ),
		};
	}
}
