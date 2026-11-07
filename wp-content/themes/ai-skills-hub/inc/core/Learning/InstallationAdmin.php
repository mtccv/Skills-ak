<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;

use AISkillsHub\Core\Admin\ContributionUi as Ui;

defined( 'ABSPATH' ) || exit;

/** Explicit evidence review over a currently approved Skill; no automatic source or AI writes. */
final class InstallationAdmin {
	public const SLUG = 'aish-installation-evidence';
	private const NOTICE = 'aish_installation_notice_';
	public function __construct( private SkillExperience $service ) {}
	public function register(): void {
		add_action( 'admin_menu', function (): void { Ui::menu( Ui::t( '安装命令核实', 'Installation evidence' ), SkillExperience::CAPABILITY, self::SLUG, array( $this, 'screen' ) ); } );
		add_action( 'admin_post_aish_installation_save', array( $this, 'handle' ) );
	}
	public function handle(): never {
		Ui::authorize( SkillExperience::CAPABILITY ); Ui::post( 'aish_installation_save' ); $id = 0; $locale = 'zh_CN';
		try {
			$state = Ui::choice( $_POST, 'state', array( 'verified', 'disabled' ) ); $id = Ui::id( $_POST, 'skill_id' ); $locale = Ui::choice( $_POST, 'locale', array( 'zh_CN', 'en_US' ) ); $rows = array();
			for ( $i = 0; $i < SkillExperience::MAX_COMMANDS; ++$i ) { $command = Ui::input( $_POST, 'command_' . $i, '', 2000 ); $platform = Ui::input( $_POST, 'platform_' . $i, '', 191 ); if ( '' !== trim( $command ) ) { $rows[] = array( 'command' => $command, 'platform' => $platform ); } elseif ( '' !== $platform ) { throw new \InvalidArgumentException( 'installation_missing_command' ); } }
			$this->service->save( $id, $locale, array( 'state' => $state, 'version_id' => Ui::id( $_POST, 'version_id' ), 'commands' => $rows, 'rights_note' => Ui::input( $_POST, 'rights_note', '', 800 ) ), Ui::id( $_POST, 'revision' ), Ui::id( $_POST, 'skill_revision' ), '1' === Ui::input( $_POST, 'confirmed', '', 1 ) && ( 'disabled' === $state || '1' === Ui::input( $_POST, 'license_confirmed', '', 1 ) ) );
			$result = array( 'success' => 1, 'failed' => 0 );
		} catch ( \Throwable $error ) { $result = array( 'success' => 0, 'failed' => 1 ); }
		set_transient( self::NOTICE . get_current_user_id(), $result, 120 ); wp_safe_redirect( Ui::url( self::SLUG, array( 'skill_id' => $id, 'locale' => $locale ) ), 303 ); exit;
	}
	public function screen(): void {
		Ui::screen( SkillExperience::CAPABILITY, Ui::t( '安装命令核实', 'Installation evidence' ), self::NOTICE, function (): void {
			$id = Ui::id( $_GET, 'skill_id' ); $locale = Ui::choice( $_GET, 'locale', array( 'zh_CN', 'en_US' ), 'zh_CN' );
			echo '<p>' . esc_html( Ui::t( '仅为已经发布并通过当前修订审核的资源启用复制。命令必须与当前语言已发布正文中的完整代码块一致；GitHub 来源还须匹配当前采集记录所绑定的不可变原文。不会执行命令、抓取 URL 或修改 Skill。', 'Enable copying only for a published, currently approved Skill. Each command must exactly match a complete code block in its published translation; GitHub commands must also match the immutable source bound to the current import. Nothing is executed, fetched or written to the Skill.' ) ) . '</p>';
			echo '<form method="get"><p>'; Ui::hidden( 'page', self::SLUG ); echo '<label>Skill ID <input type="number" name="skill_id" min="1" value="' . $id . '" required></label> '; Ui::select( 'locale', array( 'zh_CN' => '简体中文', 'en_US' => 'English' ), $locale, Ui::t( '语言', 'Language' ) ); echo '<button class="button" type="submit">' . esc_html( Ui::t( '打开记录', 'Open record' ) ) . '</button></p></form>';
			if ( ! $id ) { echo '<p>' . esc_html( Ui::t( '先输入已有 Skill ID。未核实的命令不会出现复制按钮。', 'Enter an existing Skill ID. Commands without verified evidence have no copy button.' ) ) . '</p>'; return; }
			$data = $this->service->editor( $id, $locale ); $skill = $data['skill']; $r = $data['configuration'];
			echo '<h2>' . esc_html( $skill['title'] ) . '</h2><p>' . esc_html( Ui::t( 'Skill 修订：', 'Skill revision: ' ) . $skill['revision'] . ' · ' . Ui::t( '配置修订：', 'Evidence revision: ' ) . $r['revision'] ) . '</p>';
			$available = $this->service->installation( $id, $locale ); echo '<p><strong>' . esc_html( 'verified' === $available['state'] ? Ui::t( '当前证据有效。', 'Current evidence is valid.' ) : Ui::t( '尚未核实、已停用或来源/正文已变化。前台不会提供命令复制。', 'Not verified, disabled, or source/content has changed. No command copying is offered publicly.' ) ) . '</strong></p>';
			echo '<details><summary>' . esc_html( Ui::t( '查看当前已发布代码块（不是执行指令）', 'Inspect current published code blocks (never executed)' ) ) . '</summary>';
			foreach ( $data['blocks'] as $block ) { echo '<pre style="overflow:auto;max-width:100%;white-space:pre;background:#f6f7f7;padding:12px" tabindex="0"><code>' . esc_html( $block ) . '</code></pre>'; }
			if ( ! $data['blocks'] ) { echo '<p>' . esc_html( Ui::t( '当前译文没有可核实的完整代码块。请先通过原内容编辑和审核流程完善说明。', 'This translation has no eligible complete code blocks. Use the existing content editing and review process first.' ) ) . '</p>'; } echo '</details>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; foreach ( array( 'action' => 'aish_installation_save', 'skill_id' => $id, 'locale' => $locale, 'revision' => $r['revision'], 'skill_revision' => (int) $skill['revision'] ) as $key => $value ) { Ui::hidden( $key, $value ); } wp_nonce_field( 'aish_installation_save' );
			echo '<p>'; Ui::select( 'state', array( 'disabled' => Ui::t( '停用复制', 'Disable copying' ), 'verified' => Ui::t( '核实并启用复制', 'Verify and enable copying' ) ), 'verified' === $r['state'] ? 'verified' : 'disabled', Ui::t( '状态', 'State' ) ); echo '</p>';
			$versions = array( '0' => Ui::t( '人工来源：仅引用当前已审核正文', 'Manual source: current approved text only' ) ); foreach ( $data['versions'] as $version ) { $versions[ (string) $version['id'] ] = '#' . $version['id'] . ' · ' . $version['commit_sha']; }
			echo '<p>'; Ui::select( 'version_id', $versions, (string) $r['version_id'], Ui::t( '原文版本（GitHub 必须选择当前绑定版本）', 'Source version (GitHub requires its current bound version)' ) ); echo '</p><p>' . esc_html( Ui::t( '旧历史 SHA 不代表已核实来源。来源有新采集结果、路径变化、风险/元数据变化、正文修订或许可/平台变化时，复制资格失效，须重新核实。', 'A historical SHA is not verified evidence. New source results, path/risk/metadata changes, content revisions or license/platform changes invalidate copying until reviewed again.' ) ) . '</p>';
			$platforms = array( '' => Ui::t( '平台待核实', 'Platform not verified' ) ); foreach ( $data['terms']['platforms'] as $platform ) { $platforms[ $platform['slug'] ] = $platform['name']; }
			for ( $i = 0; $i < SkillExperience::MAX_COMMANDS; ++$i ) { $row = $r['commands'][ $i ] ?? array( 'command' => '', 'platform' => '' ); echo '<fieldset style="margin:20px 0;padding:16px;border:1px solid #dcdcde"><legend>' . esc_html( Ui::t( '命令', 'Command' ) . ' ' . ( $i + 1 ) ) . '</legend><label>' . esc_html( Ui::t( '完整可见文本（最多 2000 字节、16 行；可留空）', 'Complete visible text (up to 2000 bytes / 16 lines; optional)' ) ) . '<textarea class="large-text code" name="command_' . $i . '" rows="3" maxlength="2000" spellcheck="false">' . esc_textarea( $row['command'] ) . '</textarea></label><p>'; Ui::select( 'platform_' . $i, $platforms, $row['platform'], Ui::t( '适用平台', 'Platform' ) ); echo '</p></fieldset>'; }
			echo '<p><label>' . esc_html( Ui::t( '许可依据与核实记录（仅后台，至少 15 字；不得填写密码、Token、API Key 或敏感个人资料）', 'License evidence and review note (admin only, at least 15 characters; no passwords, tokens, API keys or sensitive personal information)' ) ) . '<textarea class="large-text" name="rights_note" rows="3" minlength="15" maxlength="800" required>' . esc_textarea( $r['rights_note'] ) . '</textarea></label></p><p><label><input type="checkbox" name="license_confirmed" value="1"> ' . esc_html( Ui::t( '已核实公开许可允许此命令引用和相关使用，完整内容、来源、平台与风险说明准确；不把核实描述为官方认证或安全保证。', 'I verified that the published license permits this quotation and relevant use, and checked the complete command, source, platform and risk information. This is not official endorsement or a safety guarantee.' ) ) . '</label></p>';
			Ui::confirm(); echo '</form>';
		} );
	}
}
