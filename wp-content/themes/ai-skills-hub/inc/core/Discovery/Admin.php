<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Discovery;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Admin\ContributionUi as Ui;

defined( 'ABSPATH' ) || exit;

/** Native editorial controls, independent from Skill approval and community moderation. */
final class Admin {
	public const CAPABILITY = Settings::CAPABILITY;
	private const NONCE = 'aish_discovery_admin';
	private const SLUGS = array( 'collection' => 'aish-collections', 'use_case' => 'aish-use-cases', 'terms' => 'aish-term-translations', 'settings' => 'aish-discovery-settings' );
	public function __construct( private Management $management, private Settings $settings, private Database $db ) {}
	private static function t( string $zh, string $en ): string { return Ui::t( $zh, $en ); }
	public function register(): void {
		add_action( 'admin_menu', function (): void { foreach ( array( 'collection' => self::t( '编辑精选', "Editor's Picks" ), 'use_case' => self::t( '使用场景', 'Use Cases' ), 'terms' => self::t( '分类与标签翻译', 'Category / Tag Translations' ), 'settings' => self::t( '发现与首页配置', 'Discovery / Home Settings' ) ) as $kind => $label ) { add_submenu_page( 'aish', $label, $label, self::CAPABILITY, self::SLUGS[ $kind ], fn() => $this->screen( $kind ) ); } } );
		add_action( 'admin_post_aish_discovery_admin', array( $this, 'handle' ) );
	}
	private static function authorize(): void { if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html( self::t( '没有管理发现内容的权限。', 'Discovery management permission is required.' ) ), '', array( 'response' => 403 ) ); } }
	private static function input( array $data, string $key, string $default = '', int $max = 20000 ): string { $v = $data[ $key ] ?? $default; if ( ! is_string( $v ) || strlen( $v ) > $max ) { throw new \InvalidArgumentException( 'invalid_input' ); } return wp_unslash( $v ); }
	private static function id( mixed $value, bool $zero = false ): int { return Settings::integer( $value, $zero ? 0 : 1, PHP_INT_MAX - 1 ); }
	private static function url( string $kind, array $args = array() ): string { return add_query_arg( array_merge( array( 'page' => self::SLUGS[ $kind ] ), $args ), admin_url( 'admin.php' ) ); }
	private static function hidden( string $name, mixed $value ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">'; }
	private static function field( string $name, string $label, mixed $value, int $max = 191, bool $area = false ): void {
		echo '<p><label>' . esc_html( $label ) . '<br>';
		if ( $area ) { echo '<textarea class="large-text" rows="4" maxlength="' . (int) $max . '" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>'; }
		else { echo '<input class="regular-text" type="text" maxlength="' . (int) $max . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">'; }
		echo '</label></p>';
	}
	private static function form( string $kind, string $operation, int $revision = 0, int $id = 0 ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( self::NONCE ); foreach ( array( 'action' => 'aish_discovery_admin', 'kind' => $kind, 'operation' => $operation, 'revision' => $revision, 'id' => $id ) as $key => $value ) { self::hidden( $key, $value ); }
	}
	private static function confirm(): void { echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html( self::t( '确认保存本次明确修改；不会批准或发布任何 Skill。', 'Confirm these changes; they do not approve or publish any Skill.' ) ) . '</label></p>'; }
	public function screen( string $kind = 'collection' ): void {
		self::authorize();
		if ( ! isset( self::SLUGS[ $kind ] ) ) { return; }
		echo '<div class="wrap"><h1>' . esc_html( self::t( 'AI Skills Hub · 资源发现', 'AI Skills Hub · Discovery' ) ) . '</h1>';
		$key = 'aish_discovery_notice_' . get_current_user_id(); $notice = get_transient( $key ); if ( $notice ) { delete_transient( $key ); echo '<div class="notice ' . ( 'saved' === $notice ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( 'saved' === $notice ? self::t( '已保存。', 'Saved.' ) : self::t( '未保存。请检查字段和当前修订；并发修改需刷新后重新确认。', 'Not saved. Check the fields and current revision; refresh and review concurrent changes.' ) ) . '</p></div>'; }
		try {
			if ( 'settings' === $kind ) { $this->settingsScreen(); }
			elseif ( 'terms' === $kind ) { $this->termsScreen(); }
			elseif ( isset( $_GET['id'] ) ) { $this->editor( $kind, self::id( $_GET['id'], true ) ); }
			else { $this->listing( $kind ); }
		} catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html( self::t( '内容暂不可用。请检查权限、输入和数据库迁移状态。', 'Content is unavailable. Check permission, input and schema readiness.' ) ) . '</p></div>'; }
		echo '</div>';
	}
	private function listing( string $kind ): void {
		$page = Settings::integer( $_GET['paged'] ?? '1', 1, 1000 ); $state = self::input( $_GET, 'status' ); $q = Settings::text( self::input( $_GET, 'q', '', 400 ), 100 );
		$result = $this->management->list( $kind, array( 'page' => $page, 'per_page' => 20, 'status' => $state, 'q' => $q ) );
		echo '<p><a class="page-title-action" href="' . esc_url( self::url( $kind, array( 'id' => 0 ) ) ) . '">' . esc_html( self::t( '新增', 'Add new' ) ) . '</a></p><form method="get">'; self::hidden( 'page', self::SLUGS[ $kind ] );
		echo '<label>' . esc_html( self::t( '搜索', 'Search' ) ) . ' <input type="search" name="q" value="' . esc_attr( $q ) . '"></label> <select name="status"><option value="">' . esc_html( self::t( '全部状态', 'All states' ) ) . '</option>';
		foreach ( array( 'draft', 'published', 'archived' ) as $value ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $state, $value, false ) . '>' . esc_html( $value ) . '</option>'; }
		echo '</select> <button class="button">' . esc_html( self::t( '筛选', 'Filter' ) ) . '</button></form><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html( self::t( '标题', 'Title' ) ) . '</th><th>' . esc_html( self::t( '状态 / 修订', 'State / revision' ) ) . '</th><th>' . esc_html( self::t( '排序', 'Order' ) ) . '</th></tr></thead><tbody>';
		foreach ( $result['rows'] ?? array() as $row ) { $label = $row['title'] ?? $row['name'] ?? $row['translations']['zh_CN']['title'] ?? $row['translations']['zh_CN']['name'] ?? $row['translations']['en_US']['title'] ?? $row['translations']['en_US']['name'] ?? ( '#' . $row['id'] ); echo '<tr><td>' . (int) $row['id'] . '</td><td><a href="' . esc_url( self::url( $kind, array( 'id' => $row['id'] ) ) ) . '">' . esc_html( $label ) . '</a></td><td>' . esc_html( $row['status'] . ' / r' . $row['revision'] ) . '</td><td>' . (int) $row['sort_order'] . '</td></tr>'; }
		if ( empty( $result['rows'] ) ) { echo '<tr><td colspan="4">' . esc_html( self::t( '暂无内容。', 'No items.' ) ) . '</td></tr>'; } echo '</tbody></table>';
		$total = (int) ( $result['total'] ?? 0 ); echo '<p>' . esc_html( (string) $total ) . ' ' . esc_html( self::t( '项', 'items' ) ) . '</p>';
		foreach ( array( -1 => self::t( '上一页', 'Previous' ), 1 => self::t( '下一页', 'Next' ) ) as $offset => $label ) { $target = $page + $offset; if ( $target > 0 && ( $target - 1 ) * 20 < $total ) { echo '<a class="button" href="' . esc_url( self::url( $kind, array( 'paged' => $target, 'status' => $state, 'q' => $q ) ) ) . '">' . esc_html( $label ) . '</a> '; } }
	}
	private function editor( string $kind, int $id ): void {
		$row = $id ? $this->management->detail( $kind, $id ) : array( 'revision' => 0, 'status' => 'draft', 'sort_order' => 0, 'skills' => array(), 'translations' => array() ); if ( ! $row ) { throw new \InvalidArgumentException( 'unavailable' ); }
		echo '<p><a href="' . esc_url( self::url( $kind ) ) . '">' . esc_html( self::t( '返回列表', 'Back to list' ) ) . '</a></p><p>' . esc_html( self::t( '发布专题不会发布其中的 Skill。缺少翻译、未审核、归档或失效的资源不会公开。Slug 首次保存后保持稳定。时间统一填写 UTC。', 'Publishing a topic never publishes its Skills. Missing translations, unapproved, archived or invalid resources stay private. Slugs stay stable after first save. Enter times in UTC.' ) ) . '</p>';
		self::form( $kind, 'save', (int) $row['revision'], $id );
		echo '<p><label>' . esc_html( self::t( '状态', 'State' ) ) . ' <select name="status">'; foreach ( array( 'draft', 'published', 'archived' ) as $state ) { echo '<option value="' . esc_attr( $state ) . '" ' . selected( $state, $row['status'], false ) . '>' . esc_html( $state ) . '</option>'; } echo '</select></label></p>';
		self::field( 'sort_order', self::t( '显示顺序（0–1000000）', 'Display order (0–1000000)' ), $row['sort_order'], 7 );
		foreach ( Settings::LOCALES as $locale ) { $translation = $row['translations'][ $locale ] ?? array(); echo '<fieldset><legend><h2>' . esc_html( 'zh_CN' === $locale ? '简体中文' : 'English' ) . '</h2></legend>'; foreach ( array( 'collection' === $kind ? 'title' : 'name' => self::t( '标题', 'Title' ), 'slug' => 'Slug', 'description' => self::t( '介绍', 'Description' ), 'seo_title' => 'SEO Title', 'seo_description' => 'Meta Description' ) as $field => $label ) { self::field( 'translations[' . $locale . '][' . $field . ']', $label, $translation[ $field ] ?? '', 'description' === $field ? 12000 : ( 'seo_description' === $field ? 320 : 191 ), 'description' === $field ); } echo '</fieldset>'; }
		self::field( 'skills', self::t( 'Skill ID（逗号分隔，按输入顺序展示）', 'Skill IDs (comma-separated, in display order)' ), implode( ',', $row['skills'] ), 4000, true );
		self::field( 'tutorial_ids', self::t( '相关教程文章 ID（逗号分隔）', 'Related tutorial post IDs (comma-separated)' ), implode( ',', $row['tutorial_ids'] ?? array() ), 1000 );
		if ( 'collection' === $kind ) { self::field( 'published_at', self::t( '发布时间 UTC（YYYY-MM-DD HH:MM:SS，空为立即）', 'Publication UTC (YYYY-MM-DD HH:MM:SS; blank means now)' ), $row['published_at'] ?? '', 19 ); }
		self::field( 'ends_at', self::t( '结束时间 UTC（空为无期限）', 'End time UTC (blank means no expiry)' ), $row['ends_at'] ?? '', 19 );
		self::confirm(); echo '<button class="button button-primary">' . esc_html( self::t( '保存当前修订', 'Save this revision' ) ) . '</button></form>';
		if ( $id && 'archived' !== $row['status'] ) { echo '<hr>'; self::form( $kind, 'archive', (int) $row['revision'], $id ); self::confirm(); echo '<button class="button">' . esc_html( self::t( '归档（保留记录）', 'Archive (retain record)' ) ) . '</button></form>'; }
	}
	private function termsScreen(): void {
		$kind = self::input( $_GET, 'term_type', 'category', 16 ); if ( ! in_array( $kind, array( 'category', 'tag' ), true ) ) { throw new \InvalidArgumentException( 'invalid_term' ); }
		$id = isset( $_GET['term_id'] ) ? self::id( $_GET['term_id'] ) : 0;
		echo '<p>' . esc_html( self::t( '保留分类/标签原 ID、slug 和层级。留空的语言保持缺译，不复制另一语言。', 'Keep original term IDs, slugs and category hierarchy. Blank languages remain untranslated.' ) ) . '</p><form method="get">'; self::hidden( 'page', self::SLUGS['terms'] ); echo '<select name="term_type">'; foreach ( array( 'category', 'tag' ) as $type ) { echo '<option ' . selected( $kind, $type, false ) . '>' . esc_html( $type ) . '</option>'; } echo '</select> <label>ID <input type="number" name="term_id" min="1" required value="' . esc_attr( $id ? (string) $id : '' ) . '"></label> <button class="button">' . esc_html( self::t( '编辑翻译', 'Edit translations' ) ) . '</button></form>';
		if ( ! $id ) { return; } $translations = $this->management->termTranslations( $kind, $id ); self::form( 'terms', 'save', 0, $id ); self::hidden( 'term_type', $kind ); self::hidden( 'digest', Management::termDigest( $translations ) );
		foreach ( Settings::LOCALES as $locale ) { echo '<h2>' . esc_html( $locale ) . '</h2>'; foreach ( array( 'name', 'description' ) as $field ) { self::field( 'translations[' . $locale . '][' . $field . ']', $field, $translations[ $locale ][ $field ] ?? '', 'name' === $field ? 191 : 12000, 'description' === $field ); } }
		self::confirm(); echo '<button class="button button-primary">' . esc_html( self::t( '保存翻译', 'Save translations' ) ) . '</button></form>';
	}
	private function settingsScreen(): void {
		$snapshot = Settings::snapshot(); $data = $snapshot['draft'];
		echo '<p>' . esc_html( self::t( '保存草稿不影响首页；勾选确认并发布后生效。此处不能编辑任何浏览量、收藏或评分。', 'Saving a draft does not change the live home page. Confirm and publish to apply it. Views, favorites and ratings cannot be edited here.' ) ) . '</p><p>' . esc_html( 'Draft r' . $snapshot['revision'] . ' / Published r' . $snapshot['published_revision'] ) . '</p>';
		$selected = isset( $_GET['platform_tag'] ) ? self::id( $_GET['platform_tag'] ) : 0;
		$ids = array_map( 'intval', array_keys( $data['platforms'] ) ); if ( $selected && ! in_array( $selected, $ids, true ) ) { $ids[] = $selected; } if ( count( $ids ) > 50 ) { throw new \InvalidArgumentException( 'too_many_platforms' ); }
		$tags = array(); if ( $ids ) { $holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) ); $tags = $this->db->rows( 'SELECT id,name,slug,is_archived FROM ' . $this->db->table( 'tags' ) . " WHERE id IN ($holders) ORDER BY id", $ids ); if ( count( $tags ) !== count( $ids ) ) { throw new \RuntimeException( 'platform_unavailable' ); } foreach ( $tags as $tag ) { if ( ! str_starts_with( $tag['slug'], 'platform-' ) ) { throw new \InvalidArgumentException( 'invalid_platform' ); } } }
		echo '<form method="get">'; self::hidden( 'page', self::SLUGS['settings'] ); echo '<label>' . esc_html( self::t( '添加现有 platform-* 标签 ID（最多 50 个）', 'Add an existing platform-* tag ID (maximum 50)' ) ) . ' <input name="platform_tag" type="number" min="1" required></label> <button class="button">' . esc_html( self::t( '载入配置', 'Load configuration' ) ) . '</button></form>';
		self::form( 'settings', 'save', (int) $snapshot['revision'] );
		self::hidden( 'platform_ids', implode( ',', $ids ) );
		echo '<p><label><input type="checkbox" name="tracking" value="1" ' . checked( $data['tracking'], true, false ) . '> ' . esc_html( self::t( '启用经访客同意的有效浏览统计（默认关闭）', 'Enable consent-based effective-view measurement (off by default)' ) ) . '</label></p>';
		foreach ( Settings::LOCALES as $locale ) { echo '<h2>' . esc_html( $locale ) . '</h2><table class="widefat striped"><thead><tr><th>Module</th><th>' . esc_html( self::t( '显示 / 顺序 / 数量', 'Visible / Order / Limit' ) ) . '</th><th>' . esc_html( self::t( '标题 / 说明', 'Title / Description' ) ) . '</th></tr></thead><tbody>'; foreach ( $data['home'][ $locale ] as $order => $row ) { $prefix = 'home[' . $locale . '][' . $row['key'] . ']'; echo '<tr><td>' . esc_html( $row['key'] ) . '</td><td><label><input type="checkbox" name="' . esc_attr( $prefix . '[enabled]' ) . '" value="1" ' . checked( $row['enabled'], true, false ) . '> ' . esc_html( self::t( '显示', 'Visible' ) ) . '</label><p><input aria-label="Order" type="number" name="' . esc_attr( $prefix . '[order]' ) . '" min="0" max="99" value="' . (int) $order . '"> <input aria-label="Limit" type="number" name="' . esc_attr( $prefix . '[limit]' ) . '" min="1" max="12" value="' . (int) $row['limit'] . '"></p></td><td>'; self::field( $prefix . '[title]', self::t( '标题', 'Title' ), $row['title'] ); self::field( $prefix . '[description]', self::t( '说明', 'Description' ), $row['description'], 500, true ); echo '</td></tr>'; } echo '</tbody></table>'; }
		echo '<h2>' . esc_html( self::t( '平台专题', 'Platform topics' ) ) . '</h2><p>' . esc_html( self::t( '只能使用已有 platform-* 标签。先人工核对证据，专题不会为任何 Skill 新增兼容性。', 'Use existing platform-* tags only. Review evidence manually; a topic does not grant compatibility to any Skill.' ) ) . '</p>';
		foreach ( $tags as $tag ) { $id = (int) $tag['id']; $row = $data['platforms'][ $id ] ?? array(); $prefix = 'platforms[' . $id . ']'; echo '<details><summary>' . esc_html( $tag['name'] . ' / ' . $tag['slug'] . ' #' . $id . ( (int) $tag['is_archived'] ? ' (archived)' : '' ) ) . '</summary><p><label><input type="checkbox" name="' . esc_attr( $prefix . '[enabled]' ) . '" value="1" ' . checked( $row['enabled'] ?? false, true, false ) . '> ' . esc_html( self::t( '启用专题', 'Enable topic' ) ) . '</label> <label><input type="checkbox" name="' . esc_attr( $prefix . '[verified]' ) . '" value="1" ' . checked( $row['verified'] ?? false, true, false ) . '> ' . esc_html( self::t( '已人工核对平台介绍证据', 'I reviewed the platform description evidence' ) ) . '</label></p>'; self::field( $prefix . '[evidence_url]', self::t( '证据 HTTPS URL（不会自动访问）', 'Evidence HTTPS URL (never fetched automatically)' ), $row['evidence_url'] ?? '', 2048 ); foreach ( Settings::LOCALES as $locale ) { echo '<h3>' . esc_html( $locale ) . '</h3>'; foreach ( array( 'title' => 191, 'description' => 4000, 'seo_title' => 191, 'seo_description' => 320 ) as $field => $max ) { self::field( $prefix . '[translations][' . $locale . '][' . $field . ']', $field, $row['translations'][ $locale ][ $field ] ?? '', $max, 'description' === $field ); } } echo '</details>'; }
		self::confirm(); echo '<p><button class="button" name="mode" value="draft">' . esc_html( self::t( '保存草稿', 'Save draft' ) ) . '</button> <button class="button button-primary" name="mode" value="publish">' . esc_html( self::t( '确认发布配置', 'Publish configuration' ) ) . '</button></p></form>';
	}
	private static function ids( string $value ): array { if ( '' === trim( $value ) ) { return array(); } $parts = preg_split( '/[\s,]+/', trim( $value ) ); if ( count( $parts ) > 100 ) { throw new \InvalidArgumentException( 'too_many_ids' ); } return array_map( static fn( $v ): int => self::id( $v ), $parts ); }
	/** Parse a bounded form without accepting query expressions or count overrides. */
	public static function settingsPayload( array $post ): array {
		$home = $post['home'] ?? null; if ( ! is_array( $home ) || ! is_array( $post['platforms'] ?? array() ) ) { throw new \InvalidArgumentException( 'invalid_settings' ); }
		$result = array( 'tracking' => $post['tracking'] ?? '0', 'home' => array(), 'platforms' => wp_unslash( $post['platforms'] ?? array() ) );
		// Detect truncated PHP max_input_vars submissions rather than silently losing saved platforms.
		$expected = self::ids( self::input( $post, 'platform_ids', '', 1200 ) ); $actual = array_map( static fn( $v ): int => self::id( $v ), array_keys( $result['platforms'] ) ); sort( $expected, SORT_NUMERIC ); sort( $actual, SORT_NUMERIC ); if ( $expected !== $actual ) { throw new \InvalidArgumentException( 'incomplete_platform_form' ); }
		foreach ( $result['platforms'] as $row ) { if ( ! is_array( $row ) || ! array_key_exists( 'evidence_url', $row ) || ! is_array( $row['translations'] ?? null ) ) { throw new \InvalidArgumentException( 'incomplete_platform_form' ); } foreach ( Settings::LOCALES as $locale ) { if ( ! is_array( $row['translations'][ $locale ] ?? null ) || array_diff( array( 'title', 'description', 'seo_title', 'seo_description' ), array_keys( $row['translations'][ $locale ] ) ) ) { throw new \InvalidArgumentException( 'incomplete_platform_form' ); } } }
		foreach ( Settings::LOCALES as $locale ) { $rows = $home[ $locale ] ?? null; if ( ! is_array( $rows ) || array_diff( array_keys( $rows ), Settings::MODULES ) || count( $rows ) !== count( Settings::MODULES ) ) { throw new \InvalidArgumentException( 'invalid_modules' ); } $ordered = array(); foreach ( $rows as $key => $r ) { if ( ! is_array( $r ) || array_diff( array( 'order', 'limit', 'title', 'description' ), array_keys( $r ) ) ) { throw new \InvalidArgumentException( 'invalid_module' ); } $ordered[] = array( 'order' => Settings::integer( $r['order'] ?? 0, 0, 99 ), 'row' => array( 'key' => $key, 'enabled' => $r['enabled'] ?? '0', 'limit' => $r['limit'] ?? 6, 'title' => self::input( $r, 'title', '', 764 ), 'description' => self::input( $r, 'description', '', 2000 ) ) ); } usort( $ordered, static fn( $a, $b ) => ( $a['order'] <=> $b['order'] ) ?: ( array_search( $a['row']['key'], Settings::MODULES, true ) <=> array_search( $b['row']['key'], Settings::MODULES, true ) ) ); $result['home'][ $locale ] = array_column( $ordered, 'row' ); }
		return Settings::validate( $result );
	}
	public function handle(): void {
		self::authorize(); if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Method not allowed', '', array( 'response' => 405 ) ); } if ( ! is_string( $_POST['_wpnonce'] ?? null ) || strlen( $_POST['_wpnonce'] ) > 128 ) { wp_die( 'Invalid nonce', '', array( 'response' => 403 ) ); } check_admin_referer( self::NONCE ); $kind = 'collection'; $id = 0;
		try {
			$kind = self::input( $_POST, 'kind', '', 20 ); if ( ! isset( self::SLUGS[ $kind ] ) || '1' !== self::input( $_POST, 'confirm', '', 1 ) ) { throw new \InvalidArgumentException( 'invalid_action' ); }
			$id = self::id( $_POST['id'] ?? '0', true ); $revision = self::id( $_POST['revision'] ?? '0', true ); $operation = self::input( $_POST, 'operation', '', 20 );
			if ( 'settings' === $kind && 'save' === $operation ) { $this->settings->save( self::settingsPayload( $_POST ), $revision, self::input( $_POST, 'mode', '', 10 ) ); }
			elseif ( 'terms' === $kind && 'save' === $operation ) { if ( ! is_array( $_POST['translations'] ?? null ) ) { throw new \InvalidArgumentException( 'invalid_translation' ); } $this->management->saveTerm( self::input( $_POST, 'term_type', '', 16 ), $id, wp_unslash( $_POST['translations'] ), self::input( $_POST, 'digest', '', 64 ) ); }
			elseif ( in_array( $kind, array( 'collection', 'use_case' ), true ) && 'archive' === $operation ) { $this->management->archive( $kind, $id, $revision ); }
			elseif ( in_array( $kind, array( 'collection', 'use_case' ), true ) && 'save' === $operation ) { if ( ! is_array( $_POST['translations'] ?? null ) ) { throw new \InvalidArgumentException( 'invalid_translation' ); } $payload = array( 'status' => self::input( $_POST, 'status', '', 20 ), 'sort_order' => self::input( $_POST, 'sort_order', '0', 7 ), 'translations' => wp_unslash( $_POST['translations'] ), 'skills' => self::ids( self::input( $_POST, 'skills', '', 4000 ) ), 'tutorial_ids' => self::ids( self::input( $_POST, 'tutorial_ids', '', 1000 ) ), 'ends_at' => self::input( $_POST, 'ends_at', '', 19 ) ); if ( 'collection' === $kind ) { $payload['published_at'] = self::input( $_POST, 'published_at', '', 19 ); } $row = $this->management->save( $kind, $id, $payload, $revision ); $id = (int) $row['id']; }
			else { throw new \InvalidArgumentException( 'invalid_operation' ); }
			set_transient( 'aish_discovery_notice_' . get_current_user_id(), 'saved', 120 );
		} catch ( \Throwable $error ) { set_transient( 'aish_discovery_notice_' . get_current_user_id(), 'failed', 120 ); }
		if ( ! isset( self::SLUGS[ $kind ] ) ) { $kind = 'collection'; } wp_safe_redirect( self::url( $kind, $id && in_array( $kind, array( 'collection', 'use_case' ), true ) ? array( 'id' => $id ) : array() ), 303 ); exit;
	}
}
