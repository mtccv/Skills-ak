<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;
defined( 'ABSPATH' ) || exit;

/** Starter library is reviewed in the existing native tutorial editor, not a second CMS. */
final class HelpAdmin {
	public function __construct( private Help $help ) {}
	public function register(): void {
		add_action( 'admin_menu', function (): void {
			$hook = add_submenu_page( 'aish', '帮助中心 / Help center', '帮助中心 / Help center', Help::CAPABILITY, 'aish-help', array( $this, 'screen' ) );
			if ( is_string( $hook ) && '' !== $hook ) { add_action( 'load-' . $hook, array( $this, 'privateHeaders' ) ); }
		} );
		add_action( 'admin_post_aish_help_import', array( $this, 'import' ) );
	}
	/** WordPress fires load-$hook before its admin header has started HTML output. */
	public function privateHeaders(): void {
		if ( ! current_user_can( Help::CAPABILITY ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
	}
	public function import(): never {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POST required.', '', array( 'response' => 405 ) ); }
		if ( ! current_user_can( Help::CAPABILITY ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'aish_help_import' );
		try {
			if ( '1' !== ( $_POST['confirm_drafts'] ?? '' ) ) { throw new \InvalidArgumentException( 'confirmation_required' ); }
			$result = $this->help->importDrafts();
			set_transient( 'aish_help_import_' . get_current_user_id(), array( 'created' => count( $result['created'] ), 'existing' => count( $result['existing'] ) ), 120 );
		} catch ( \Throwable $error ) { set_transient( 'aish_help_import_' . get_current_user_id(), 'failed', 120 ); }
		wp_safe_redirect( admin_url( 'admin.php?page=aish-help' ), 303 ); exit;
	}
	public function screen(): void {
		if ( ! current_user_can( Help::CAPABILITY ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
		$notice = get_transient( 'aish_help_import_' . get_current_user_id() ); delete_transient( 'aish_help_import_' . get_current_user_id() );
		echo '<div class="wrap"><h1>帮助中心 / Help center</h1>';
		if ( is_array( $notice ) ) { echo '<div class="notice notice-success"><p>' . esc_html( sprintf( '已创建 %d 篇草稿，保留 %d 篇已有内容。请逐篇审核后发布。 / Created %d drafts; kept %d existing articles. Review before publishing.', $notice['created'], $notice['existing'], $notice['created'], $notice['existing'] ) ) . '</p></div>'; }
		elseif ( 'failed' === $notice ) { echo '<div class="notice notice-error"><p>' . esc_html__( '未能确认导入完成。已有内容不会被覆盖；检查权限和数据库状态后重试。 / Import could not be confirmed. Existing content is not overwritten; check permissions and storage before retrying.', 'ai-skills-hub' ) . '</p></div>'; }
		echo '<p>' . esc_html__( '复用现有教程编辑器。内容库包含 12 篇学习指南和 9 篇社区说明，各有独立中文与英文正文。导入仅创建草稿，不自动发布，也不覆盖已有编辑。 / This library uses the existing tutorial editor: 12 learning guides and 9 community explanations in independently maintained Chinese and English. Import creates drafts only and never overwrites existing edits.', 'ai-skills-hub' ) . '</p>';
		echo '<p>' . esc_html__( '发布前核对平台文档、站点实际配置、隐私与服务说明及适用法律要求。来源链接不是安全或兼容认证。 / Before publishing, review platform documentation, actual site settings, privacy and service statements, and applicable legal requirements. Source links are not safety or compatibility certification.', 'ai-skills-hub' ) . '</p>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="aish_help_import">'; wp_nonce_field( 'aish_help_import' );
		echo '<p><label><input type="checkbox" name="confirm_drafts" value="1" required> ' . esc_html__( '确认创建缺少的教程草稿；已有记录（包括回收站）保持不变。 / Create missing tutorial drafts; keep existing records, including trash.', 'ai-skills-hub' ) . '</label></p>'; submit_button( '导入帮助草稿 / Import help drafts', 'secondary' ); echo '</form>';
		try { $rows = $this->help->inventory(); } catch ( \Throwable $error ) { $rows = null; }
		echo '<h2>已导入教程 / Imported tutorials</h2>';
		if ( null === $rows ) { echo '<p>' . esc_html__( '暂时无法读取。 / Temporarily unavailable.', 'ai-skills-hub' ) . '</p>'; }
		elseif ( ! $rows ) { echo '<p>' . esc_html__( '尚未导入；没有创建公开示例文章。 / Nothing imported; no public sample articles were created.', 'ai-skills-hub' ) . '</p>'; }
		else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">ID</th><th scope="col">标题 / Title</th><th scope="col">状态 / Status</th><th scope="col">操作 / Action</th></tr></thead><tbody>';
			foreach ( $rows as $row ) { echo '<tr><td>' . (int) $row['ID'] . '</td><td>' . esc_html( $row['post_title'] ) . '</td><td>' . esc_html( $row['post_status'] ) . '</td><td><a href="' . esc_url( get_edit_post_link( (int) $row['ID'], 'raw' ) ?? '' ) . '">' . esc_html__( '编辑教程 / Edit tutorial', 'ai-skills-hub' ) . '</a></td></tr>'; }
			echo '</tbody></table>';
		}
		echo '<h2>导入前阅读内容 / Read the library before import</h2><p>' . esc_html__( '以下是内容库预览，只有经原生编辑器发布的相应语言文章才会进入公开帮助中心。 / These are library previews. Only the corresponding language article published through the native editor appears in public Help.', 'ai-skills-hub' ) . '</p>';
		$articles = HelpContent::articles(); $topic = isset( $_GET['topic'] ) && is_string( $_GET['topic'] ) ? wp_unslash( $_GET['topic'] ) : 'what-are-agent-skills'; if ( ! isset( $articles[ $topic ] ) ) { $topic = 'what-are-agent-skills'; }
		$locale = 'en_US' === ( $_GET['help_locale'] ?? '' ) ? 'en_US' : 'zh_CN';
		echo '<form method="get"><input type="hidden" name="page" value="aish-help"><label for="help-library-topic">主题 / Topic </label><select id="help-library-topic" name="topic">';
		foreach ( $articles as $key => $article ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $topic, $key, false ) . '>' . esc_html( $article[ $locale ]['title'] ) . '</option>'; }
		echo '</select> <label for="help-library-locale">语言 / Language </label><select id="help-library-locale" name="help_locale"><option value="zh_CN"' . selected( $locale, 'zh_CN', false ) . '>简体中文</option><option value="en_US"' . selected( $locale, 'en_US', false ) . '>English</option></select> '; submit_button( '阅读 / Read', 'secondary', '', false ); echo '</form><div class="card" style="max-width:900px"><h2>' . esc_html( $articles[ $topic ][ $locale ]['title'] ) . '</h2>' . HelpContent::body( $topic, $locale ) . '</div></div>';
	}
}
