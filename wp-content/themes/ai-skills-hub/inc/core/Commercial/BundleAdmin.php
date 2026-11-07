<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Commercial;

use AISkillsHub\Core\Admin\ContributionUi as Ui;

defined( 'ABSPATH' ) || exit;

/** Native curation controls over existing collections; no commerce permissions or price fields. */
final class BundleAdmin {
	private const SLUG = 'aish-resource-bundles';
	private const NOTICE = 'aish_bundle_notice_';
	public function __construct( private Bundles $bundles ) {}
	public function register(): void {
		add_action( 'admin_menu', function (): void { Ui::menu( Ui::t( '资源套装', 'Resource bundles' ), Bundles::CAPABILITY, self::SLUG, array( $this, 'screen' ) ); } );
		add_action( 'admin_post_aish_bundle_save', array( $this, 'handle' ) );
	}
	public function handle(): never {
		Ui::authorize( Bundles::CAPABILITY ); Ui::post( 'aish_bundle_save' );
		try {
			$raw = trim( Ui::input( $_POST, 'collection_ids', '', 300 ) );
			$ids = '' === $raw ? array() : array_map( 'trim', explode( ',', $raw ) );
			$this->bundles->save( array( 'state' => Ui::choice( $_POST, 'state', array( 'draft', 'enabled', 'paused' ) ), 'collection_ids' => $ids, 'reason' => Ui::input( $_POST, 'reason', '', 500 ) ), Ui::id( $_POST, 'revision' ), '1' === Ui::input( $_POST, 'confirmed', '', 1 ) );
			$result = array( 'success' => 1, 'failed' => 0 );
		} catch ( \Throwable $error ) { $result = array( 'success' => 0, 'failed' => 1 ); }
		set_transient( self::NOTICE . get_current_user_id(), $result, 120 );
		wp_safe_redirect( Ui::url( self::SLUG ), 303 ); exit;
	}
	public function screen(): void {
		Ui::screen( Bundles::CAPABILITY, Ui::t( '资源套装', 'Resource bundles' ), self::NOTICE, function (): void {
			$r = $this->bundles->configuration();
			echo '<p>' . esc_html( Ui::t( '把已有编辑集合整理为免费学习套装。默认关闭；只有当前语言下已发布、未到期且含公开 Skill 的集合会展示。', 'Organize existing editorial collections as free learning bundles. Disabled by default; only published, unexpired collections with public Skills in the current language appear.' ) ) . '</p>';
			echo '<p>' . esc_html( Ui::t( '本入口不会创建 Skill、修改自然排行榜或授予权利。原创作者信息沿用经过证据审核的作者认领；许可证和来源不明确时继续待核实，不自动标注原创或允许商业再分发。', 'This entry does not create Skills, change natural rankings or grant rights. Original-author information follows the existing evidence-reviewed claims; unclear sources or licenses remain unverified, without automatic originality or commercial redistribution labels.' ) ) . '</p>';
			echo '<p><a href="' . esc_url( Ui::url( 'aish-collections' ) ) . '">' . esc_html( Ui::t( '维护已有编辑集合', 'Manage existing editorial collections' ) ) . '</a></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			Ui::hidden( 'action', 'aish_bundle_save' ); Ui::hidden( 'revision', $r['revision'] ); wp_nonce_field( 'aish_bundle_save' );
			echo '<p>'; Ui::select( 'state', array( 'draft' => Ui::t( '草稿（关闭）', 'Draft (off)' ), 'enabled' => Ui::t( '启用', 'Enabled' ), 'paused' => Ui::t( '暂停', 'Paused' ) ), $r['state'], Ui::t( '显示状态', 'Display state' ) ); echo '</p>';
			echo '<p><label>' . esc_html( Ui::t( '集合 ID，按显示顺序用逗号分隔，最多 12 个', 'Collection IDs in display order, comma-separated, up to 12' ) ) . '<br><input class="large-text" name="collection_ids" maxlength="300" value="' . esc_attr( implode( ',', $r['collection_ids'] ) ) . '"></label></p>';
			echo '<p><label>' . esc_html( Ui::t( '内部变更原因（不公开）', 'Internal change reason (private)' ) ) . '<br><textarea class="large-text" name="reason" rows="3" maxlength="500" required>' . esc_textarea( $r['reason'] ) . '</textarea></label></p>';
			Ui::confirm(); echo '</form><h2>' . esc_html( Ui::t( '当前有效展示', 'Currently visible' ) ) . '</h2>';
			foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $locale => $caption ) {
				$rows = $this->bundles->publicEntries( $locale );
				echo '<p><strong>' . esc_html( $caption ) . '</strong>: ' . esc_html( $rows ? implode( ', ', array_map( static fn( array $row ): string => '#' . $row['id'] . ' ' . $row['title'], $rows ) ) : Ui::t( '无有效套装，前台入口隐藏。', 'No eligible bundles; the public entry is hidden.' ) ) . '</p>';
			}
			echo '<h2>' . esc_html( Ui::t( '最近 20 次变更', 'Latest 20 changes' ) ) . '</h2><div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
			foreach ( array( Ui::t( '修订', 'Revision' ), Ui::t( '操作者', 'Actor' ), Ui::t( '状态', 'State' ), 'UTC' ) as $title ) { echo '<th>' . esc_html( $title ) . '</th>'; } echo '</tr></thead><tbody>';
			$history = $this->bundles->history();
			foreach ( $history as $row ) { echo '<tr><td>' . (int) $row['subject_revision'] . '</td><td>' . (int) $row['actor_user_id'] . '</td><td>' . esc_html( $row['from_state'] . ' → ' . $row['to_state'] ) . '</td><td>' . esc_html( $row['created_at'] ) . '</td></tr>'; }
			if ( ! $history ) { echo '<tr><td colspan="4">' . esc_html( Ui::t( '暂无操作记录。', 'No changes recorded.' ) ) . '</td></tr>'; }
			echo '</tbody></table></div>';
		} );
	}
}
