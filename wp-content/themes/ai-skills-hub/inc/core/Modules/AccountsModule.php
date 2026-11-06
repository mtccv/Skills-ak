<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Modules;

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Avatar;
use AISkillsHub\Core\Accounts\Frontend;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Mail;
use AISkillsHub\Core\Accounts\MailWorker;
use AISkillsHub\Core\Accounts\Privacy;
use AISkillsHub\Core\Accounts\Scheduler;
use AISkillsHub\Core\Accounts\Store;

defined( 'ABSPATH' ) || exit;

/** Native WP identity with a separate, account-only verification and notification workflow. */
final class AccountsModule implements Module {
	public function name(): string { return 'accounts'; }
	public function dependencies(): array { return array( 'database', 'language', 'frontend' ); }

	public function register( Core $core ): void {
		$db = $core->get( 'database' );
		$store = new Store( $db );
		$locks = new Locks( $db );
		$mail = new Mail( $store );
		$accounts = new AccountService( $db, $store, $mail, $locks );
		$avatar = new Avatar( $db, $locks, $accounts );
		$privacy = new Privacy( $db, $store, $locks, $accounts, $avatar );
		$worker = new MailWorker( $store, $mail, $locks );
		$scheduler = new Scheduler( $db, $core->migrator(), $worker, $store );
		$frontend = new Frontend( $accounts, $store, $privacy, $avatar );
		foreach ( array(
			'accounts' => $accounts, 'account_store' => $store, 'account_locks' => $locks,
			'account_mail' => $mail, 'account_mail_worker' => $worker,
			'account_scheduler' => $scheduler, 'account_avatar' => $avatar,
			'account_privacy' => $privacy, 'account_frontend' => $frontend,
		) as $name => $service ) { $core->set( $name, $service ); }
		$accounts->hooks();
		$avatar->register();
		$privacy->register();
		$scheduler->register();
		$frontend->register();
		// The legacy anonymous Inbox remains until Phase 10. A restricted signed-in
		// account may not use it as an alternate community-write endpoint.
		add_action( 'admin_post_aish_public_message', static function () use ( $accounts ): void {
			if ( 'submit' !== ( $_POST['kind'] ?? null ) || ! is_user_logged_in() ) { return; }
			try { $allowed = ! $accounts->restricted( wp_get_current_user() ); }
			catch ( \Throwable $error ) { $allowed = false; }
			if ( ! $allowed ) {
				nocache_headers();
				header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
				wp_die( esc_html( 'en_US' === ( $_POST['locale'] ?? '' ) ? 'Verify your account before submitting a Skill.' : '请先完成账户验证，再提交 Skill。' ), '', array( 'response' => 403 ) );
			}
		}, 1 );
		add_action( 'admin_notices', static function () use ( $mail ): void {
			if ( ! current_user_can( 'manage_options' ) || $mail->ready() ) { return; }
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'AI Skills Hub: account registration email is not configured. Configure the dedicated encryption key and mail transport before enabling registration. Existing WordPress administrator login remains available.', 'ai-skills-hub' ) . '</p></div>';
		} );
	}
}
