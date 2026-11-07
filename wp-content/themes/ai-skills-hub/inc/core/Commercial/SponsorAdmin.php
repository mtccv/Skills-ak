<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;

use AISkillsHub\Core\Admin\ContributionUi as Ui;
use AISkillsHub\Core\Discovery\Settings;

defined( 'ABSPATH' ) || exit;

/** Native private configuration and preview; no anonymous preview or public sponsor archive. */
final class SponsorAdmin {
	public const SLUG = 'aish-sponsors';
	private const ACTION = 'aish_sponsor_action';
	private const NOTICE = 'aish_sponsor_notice_';
	public function __construct( private Sponsors $sponsors ) {}
	public function register(): void {
		add_action( 'admin_menu', function (): void { Ui::menu( Ui::t( '赞助展示（默认关闭）', 'Sponsored placements (off by default)' ), Sponsors::CAPABILITY, self::SLUG, array( $this, 'screen' ) ); } );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}
	private static function field( string $name, string $label, string $value, int $max, bool $area = false ): void {
		echo '<p><label>' . esc_html( $label ) . '<br>';
		if ( $area ) { echo '<textarea class="large-text" name="' . esc_attr( $name ) . '" rows="3" maxlength="' . $max . '">' . esc_textarea( $value ) . '</textarea>'; }
		else { echo '<input class="regular-text" name="' . esc_attr( $name ) . '" maxlength="' . $max . '" value="' . esc_attr( $value ) . '">'; }
		echo '</label></p>';
	}
	private static function stateLabel( string $state ): string { return match ( $state ) { 'enabled' => Ui::t( '启用', 'Enabled' ), 'draft' => Ui::t( '草稿', 'Draft' ), 'paused' => Ui::t( '暂停', 'Paused' ), 'scheduled' => Ui::t( '未到生效时间', 'Scheduled' ), 'expired' => Ui::t( '已到期', 'Expired' ), default => Ui::t( '不可用', 'Unavailable' ) }; }
	public function screen(): void {
		Ui::screen( Sponsors::CAPABILITY, Ui::t( '赞助展示', 'Sponsored placements' ), self::NOTICE, function (): void {
			$state = $this->sponsors->snapshot();
			echo '<p>' . esc_html( Ui::t( '先免费运营。赞助默认关闭；此配置不涉及收费，不改变精选、自然排行、评分或发布审核。最多20条人工记录，每个位置最多展示前2条有效记录（按创建顺序）。没有配置时不生成公开页面。', 'Free operation comes first. Sponsorship is off by default; configuration does not charge users or change editorial picks, rankings, ratings or publication review. Maximum 20 manual records; each placement displays the first two eligible records in creation order. No public sponsor pages are created.' ) ) . '</p><p><strong>' . esc_html( $state['enabled'] ? Ui::t( '全局展示：已开启', 'Global display: on' ) : Ui::t( '全局展示：已关闭', 'Global display: off' ) ) . '</strong> · r' . (int) $state['revision'] . '</p>';
			Ui::form( self::ACTION, self::ACTION, 'global' ); Ui::hidden( 'revision', $state['revision'] ); Ui::hidden( 'enabled', $state['enabled'] ? '0' : '1' );
			echo '<p>' . esc_html( $state['enabled'] ? Ui::t( '确认后暂停全部赞助展示，保留记录。', 'Confirm to turn off all sponsored display and retain records.' ) : Ui::t( '确认后仅展示已单独审核启用、在有效期内的完整内容。', 'Confirm to display only individually reviewed, enabled, complete entries within their valid periods.' ) ) . '</p>'; Ui::confirm(); echo '</form>';
			echo '<h2>' . esc_html( Ui::t( '现有展示记录', 'Configured placements' ) ) . '</h2><div style="overflow-x:auto"><table class="widefat striped"><thead><tr>'; foreach ( array( 'ID', Ui::t( '赞助名称', 'Sponsor' ), Ui::t( '位置', 'Placement' ), Ui::t( '当前状态', 'Current state' ), Ui::t( '审核', 'Review' ) ) as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; } echo '</tr></thead><tbody>';
			foreach ( $state['entries'] as $entry ) { echo '<tr><td><a href="' . esc_url( Ui::url( self::SLUG, array( 'id' => $entry['id'] ) ) ) . '">#' . (int) $entry['id'] . '</a></td><td>' . esc_html( $entry['name'] ) . '</td><td>' . esc_html( $entry['placement'] ) . '</td><td>' . esc_html( self::stateLabel( Sponsors::displayState( $entry ) ) ) . ( ! $state['enabled'] ? ' · ' . esc_html( Ui::t( '全局关闭', 'Global off' ) ) : '' ) . '</td><td>' . esc_html( $entry['reviewed_at'] ? '#' . $entry['reviewed_by'] . ' · ' . $entry['reviewed_at'] . ' UTC' : Ui::t( '未审核启用', 'Not reviewed for activation' ) ) . '</td></tr>'; }
			if ( ! $state['entries'] ) { echo '<tr><td colspan="5">' . esc_html( Ui::t( '暂无记录。不会自动生成赞助或演示内容。', 'No records. Sponsors and sample content are never created automatically.' ) ) . '</td></tr>'; } echo '</tbody></table></div><p><a class="button" href="' . esc_url( Ui::url( self::SLUG ) ) . '">' . esc_html( Ui::t( '新增草稿', 'New draft' ) ) . '</a></p>';
			$id = Ui::id( $_GET, 'id' ); $entry = Sponsors::blank(); if ( $id ) { $found = array_values( array_filter( $state['entries'], static fn( array $r ): bool => $r['id'] === $id ) ); if ( ! $found ) { throw new \InvalidArgumentException( 'sponsor_missing' ); } $entry = $found[0]; }
			$this->editor( $entry, $id, $state['revision'] );
			if ( $id ) { echo '<h2>' . esc_html( Ui::t( '已保存内容的私有预览', 'Private preview of saved content' ) ) . '</h2><p>' . esc_html( Ui::t( '仅此授权后台页面可见；不记录展示或点击。预览忽略赞助启停时间，但关联 Skill 仍须当前公开；缺译文不回填。', 'Only visible on this authorized admin page; no impression or click measurement. Preview ignores sponsorship activation dates, but linked Skills must still be public. Missing translations are never filled automatically.' ) ) . '</p>'; foreach ( Sponsors::LOCALES as $locale ) { echo '<h3>' . esc_html( $locale ) . '</h3>'; try { $preview = $this->sponsors->preview( $id, $locale ); if ( $preview ) { SponsorFrontend::card( $preview, true ); } else { echo '<p>' . esc_html( Ui::t( '内容不完整、缺译文或关联资源不可用。', 'Incomplete content, missing translation or unavailable linked resource.' ) ) . '</p>'; } } catch ( \Throwable $error ) { echo '<p>' . esc_html( Ui::t( '素材或配置暂不可用。', 'Artwork or configuration is unavailable.' ) ) . '</p>'; } } }
			$this->history();
		} );
	}
	private function editor( array $entry, int $id, int $revision ): void {
		echo '<h2>' . esc_html( $id ? Ui::t( '编辑记录', 'Edit entry' ) . ' #' . $id : Ui::t( '新增草稿', 'New draft' ) ) . '</h2>';
		Ui::form( self::ACTION, self::ACTION, 'entry' ); Ui::hidden( 'id', $id ); Ui::hidden( 'revision', $revision );
		Ui::select( 'status', array( 'draft' => self::stateLabel( 'draft' ), 'enabled' => self::stateLabel( 'enabled' ), 'paused' => self::stateLabel( 'paused' ) ), $entry['status'], Ui::t( '状态', 'State' ) );
		Ui::select( 'placement', array( 'home_after_search' => Ui::t( '首页搜索区域之后', 'After homepage search' ), 'skill_detail_footer' => Ui::t( 'Skill详情页末尾', 'End of Skill detail' ) ), $entry['placement'], Ui::t( '位置', 'Placement' ) );
		self::field( 'name', Ui::t( '赞助名称', 'Sponsor name' ), $entry['name'], 120 );
		self::field( 'url', Ui::t( '外部目标 HTTPS URL（不抓取；站内资源必须填写 Skill ID）', 'External HTTPS target (never fetched; internal resources require a Skill ID)' ), $entry['url'], 2048 );
		echo '<p><label>Skill ID <input type="number" min="0" name="skill_id" value="' . (int) $entry['skill_id'] . '"></label> <span class="description">' . esc_html( Ui::t( '0 为无关联；每次展示重新检查当前语言公开状态。', '0 for none; each display rechecks current-language public availability.' ) ) . '</span></p><p><label>' . esc_html( Ui::t( '本地媒体图片 ID（可选）', 'Local media image ID (optional)' ) ) . ' <input type="number" min="0" name="image_id" value="' . (int) $entry['image_id'] . '"></label></p><p class="description">' . esc_html( Ui::t( '只允许本站 uploads 内 PNG、JPEG、WebP 或 AVIF，最大1MiB、4096px；不接受外部图片、SVG、跟踪代码或执行模板。', 'Only local uploads PNG, JPEG, WebP or AVIF; at most 1 MiB and 4096 px. No remote images, SVG, tracking code or executable templates.' ) ) . '</p>';
		self::field( 'image_evidence', Ui::t( '素材使用权依据（内部；有图片时启用必填）', 'Artwork usage-rights evidence (internal; required to enable an image)' ), $entry['image_evidence'], 1000, true );
		foreach ( Sponsors::LOCALES as $locale ) { echo '<h3>' . esc_html( $locale ) . '</h3>'; self::field( 'translations[' . $locale . '][title]', Ui::t( '公开标题', 'Public title' ), $entry['translations'][ $locale ]['title'], 160 ); self::field( 'translations[' . $locale . '][description]', Ui::t( '简短说明', 'Short description' ), $entry['translations'][ $locale ]['description'], 400, true ); }
		self::field( 'starts_at', Ui::t( '开始 UTC（YYYY-MM-DD HH:MM:SS；空为立即）', 'Start UTC (YYYY-MM-DD HH:MM:SS; blank means now)' ), $entry['starts_at'], 19 );
		self::field( 'ends_at', Ui::t( '结束 UTC（启用时必填；到点立即隐藏）', 'End UTC (required for activation; hidden at expiry)' ), $entry['ends_at'], 19 );
		self::field( 'review_note', Ui::t( '内部审核记录（启用时必填；不公开）', 'Internal review note (required for activation; never public)' ), $entry['review_note'], 1000, true );
		echo '<p><label><input type="checkbox" name="review_confirmed" value="1"> ' . esc_html( Ui::t( '启用前已人工核对内容、链接、素材权利和有效期；赞助不代表作者或安全认证。', 'Before activation I reviewed the copy, destination, artwork rights and dates; sponsorship does not certify authorship or safety.' ) ) . '</label></p>'; Ui::confirm(); Ui::hidden( 'form_complete', '1' ); echo '</form>';
	}
	private function history(): void {
		echo '<h2>' . esc_html( Ui::t( '最近40条操作记录', 'Latest 40 actions' ) ) . '</h2><p>' . esc_html( Ui::t( '审计与配置同一事务，记录操作者、状态、修订和内容指纹；保留365天。内部备注只显示在授权编辑界面。', 'Audit and configuration share a transaction. Actor, state, revision and payload fingerprint are retained for 365 days. Internal notes are visible only in the authorized editor.' ) ) . '</p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr>'; foreach ( array( 'ID', Ui::t( '修订', 'Revision' ), Ui::t( '操作', 'Action' ), Ui::t( '状态', 'State' ), Ui::t( '操作人', 'Actor' ), 'UTC' ) as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( $this->sponsors->history() as $event ) { echo '<tr><td>' . (int) $event['subject_id'] . '</td><td>' . (int) $event['subject_revision'] . '</td><td>' . esc_html( $event['action'] ) . '</td><td>' . esc_html( ( $event['from_state'] ?? '' ) . ' → ' . ( $event['to_state'] ?? '' ) ) . '</td><td>#' . (int) $event['actor_user_id'] . '</td><td>' . esc_html( $event['created_at'] ) . '</td></tr>'; } echo '</tbody></table></div>';
	}
	public static function payload( array $post ): array {
		if ( '1' !== ( $post['form_complete'] ?? '' ) || ! is_array( $post['translations'] ?? null ) ) { throw new \InvalidArgumentException( 'incomplete_form' ); }
		$data = Sponsors::blank(); foreach ( array( 'status', 'placement', 'name', 'url', 'image_evidence', 'starts_at', 'ends_at', 'review_note' ) as $field ) { $data[ $field ] = Ui::input( $post, $field, '', 2048 ); }
		foreach ( array( 'skill_id', 'image_id' ) as $field ) { $data[ $field ] = Ui::id( $post, $field ); }
		foreach ( Sponsors::LOCALES as $locale ) { if ( ! is_array( $post['translations'][ $locale ] ?? null ) || ! array_key_exists( 'title', $post['translations'][ $locale ] ) || ! array_key_exists( 'description', $post['translations'][ $locale ] ) ) { throw new \InvalidArgumentException( 'incomplete_form' ); } foreach ( array( 'title', 'description' ) as $field ) { $data['translations'][ $locale ][ $field ] = Ui::input( $post['translations'][ $locale ], $field, '', 400 ); } }
		return Sponsors::validate( $data );
	}
	public function handle(): void {
		Ui::authorize( Sponsors::CAPABILITY ); Ui::post( self::ACTION ); $id = 0;
		try {
			Ui::request( $_POST ); $kind = Ui::choice( $_POST, 'kind', array( 'global', 'entry' ) ); $revision = Ui::id( $_POST, 'revision' );
			if ( 'global' === $kind ) { $this->sponsors->setEnabled( '1' === Ui::choice( $_POST, 'enabled', array( '0', '1' ) ), $revision ); }
			else { $id = Ui::id( $_POST, 'id' ); $state = $this->sponsors->saveEntry( $id, $revision, self::payload( $_POST ), '1' === Ui::input( $_POST, 'review_confirmed', '0', 1 ) ); if ( ! $id ) { $id = $state['next_id'] - 1; } }
			set_transient( self::NOTICE . get_current_user_id(), array( 'success' => 1, 'failed' => 0 ), 120 );
		} catch ( \Throwable $error ) { set_transient( self::NOTICE . get_current_user_id(), array( 'success' => 0, 'failed' => 1 ), 120 ); }
		wp_safe_redirect( Ui::url( self::SLUG, $id ? array( 'id' => $id ) : array() ), 303 ); exit;
	}
}
