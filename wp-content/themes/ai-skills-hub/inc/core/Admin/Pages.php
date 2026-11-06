<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class Pages {
	public const CAPABILITIES = array( 'aish_manage_skills', 'aish_review_skills', 'aish_manage_taxonomies', 'aish_manage_repositories', 'aish_manage_settings' );
	public static function statuses(): array { return array( 'draft' => __( '草稿', 'ai-skills-hub' ), 'pending' => __( '待审核', 'ai-skills-hub' ), 'published' => __( '已发布', 'ai-skills-hub' ), 'rejected' => __( '拒绝', 'ai-skills-hub' ), 'archived' => __( '归档', 'ai-skills-hub' ) ); }
	public function __construct( private Management $management, private Actions $actions ) {}
	public static function can( string $cap ): bool { return current_user_can( $cap ) || current_user_can( 'manage_options' ); }
	public static function url( string $page, array $args = array() ): string { return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ); }
	public static function confirmUrl( string $kind, int $id, int $revision = 0 ): string {
		return self::url( 'aish-confirm', array( 'kind' => $kind, 'id' => $id, 'revision' => $revision, '_wpnonce' => wp_create_nonce( "aish_confirm_{$kind}_{$id}_{$revision}" ) ) );
	}
	private static function requireCap( string $cap ): void {
		if ( ! self::can( $cap ) ) { wp_die( esc_html( __( '你没有管理此内容的权限。', 'ai-skills-hub' ) ), '', array( 'response' => 403 ) ); }
	}
	private static function query( string $key, string $default = '' ): string {
		if ( ! isset( $_GET[ $key ] ) ) { return $default; }
		if ( ! is_string( $_GET[ $key ] ) ) { throw new \InvalidArgumentException( 'Invalid query.' ); }
		return wp_unslash( $_GET[ $key ] );
	}
	private static function id( string $key = 'id', int $default = 0 ): int {
		$value = self::query( $key, (string) $default );
		if ( ! preg_match( '/^[0-9]{1,18}$/D', $value ) ) { throw new \InvalidArgumentException( 'Invalid ID.' ); }
		return (int) $value;
	}
	private function screen( string $cap, string $title, callable $body ): void {
		self::requireCap( $cap );
		echo '<div class="wrap aish-admin"><h1>' . esc_html( $title ) . '</h1>';
		$this->notices();
		try { $body(); } catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html( __( '无法显示内容。请检查请求参数、数据库升级状态及你的权限后重试。', 'ai-skills-hub' ) ) . '</p></div>'; }
		echo '</div>';
	}
	private function notices(): void {
		$messages = array( 'saved' => __( '已保存。', 'ai-skills-hub' ), 'reviewed' => __( '审核结果已保存。', 'ai-skills-hub' ), 'archived' => __( '已归档，原始记录保留。', 'ai-skills-hub' ), 'bulk' => __( '批量操作已完成；请检查各记录的状态。', 'ai-skills-hub' ), 'failed' => __( '操作未完成。请检查内容和权限，或重新载入最新版本后重试。', 'ai-skills-hub' ), 'error' => __( '操作未完成。请检查内容和权限，或重新载入最新版本后重试。', 'ai-skills-hub' ) );
		$code = isset( $_GET['aish_notice'] ) && is_string( $_GET['aish_notice'] ) ? $_GET['aish_notice'] : '';
		if ( isset( $messages[ $code ] ) ) { echo '<div class="notice ' . ( in_array( $code, array( 'failed', 'error' ), true ) ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $messages[ $code ] ) . '</p></div>'; }
		if ( $message = Actions::failureMessage() ) { echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>'; }
		foreach ( array( 'successcount' => __( '成功', 'ai-skills-hub' ), 'errorcount' => __( '未完成', 'ai-skills-hub' ) ) as $key => $label ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) && ctype_digit( $_GET[ $key ] ) ) { echo '<p>' . esc_html( $label . '：' . min( 50, (int) $_GET[ $key ] ) ) . '</p>'; }
		}
	}
	public function menu(): void {
		$editorial = false;
		foreach ( self::CAPABILITIES as $cap ) { $editorial = $editorial || self::can( $cap ); }
		if ( ! $editorial ) {
			foreach ( array( 'aish_moderate_community', 'aish_manage_community_reports' ) as $cap ) {
				if ( current_user_can( $cap ) ) {
					add_menu_page( 'AI Skills Hub', 'AI Skills Hub', $cap, 'aish', static function (): void { do_action( 'aish_community_admin_landing' ); }, 'dashicons-lightbulb', 25 );
					return;
				}
			}
		}
		$top = 'aish_manage_skills';
		foreach ( self::CAPABILITIES as $cap ) { if ( self::can( $cap ) ) { $top = current_user_can( $cap ) ? $cap : 'manage_options'; break; } }
		add_menu_page( 'AI Skills Hub', 'AI Skills Hub', $top, 'aish', array( $this, 'dashboard' ), 'dashicons-lightbulb', 25 );
		$items = array(
			array( __( 'Dashboard', 'ai-skills-hub' ), 'aish', $top, 'dashboard' ),
			array( __( 'Skills', 'ai-skills-hub' ), 'aish-skills', 'aish_manage_skills', 'skills' ),
			array( __( 'Add New Skill', 'ai-skills-hub' ), 'aish-skill-edit', 'aish_manage_skills', 'editSkill' ),
			array( __( 'Categories', 'ai-skills-hub' ), 'aish-categories', 'aish_manage_taxonomies', 'categories' ),
			array( __( 'Tags', 'ai-skills-hub' ), 'aish-tags', 'aish_manage_taxonomies', 'tags' ),
			array( __( 'Repositories', 'ai-skills-hub' ), 'aish-repositories', 'aish_manage_repositories', 'repositories' ),
			array( __( 'Reviews', 'ai-skills-hub' ), 'aish-reviews', 'aish_review_skills', 'reviews' ),
			array( __( 'Settings', 'ai-skills-hub' ), 'aish-settings', 'aish_manage_settings', 'settings' ),
		);
		foreach ( $items as list( $label, $slug, $cap, $method ) ) { add_submenu_page( 'aish', $label, $label, current_user_can( 'manage_options' ) ? 'manage_options' : $cap, $slug, array( $this, $method ) ); }
		add_submenu_page( '', __( '确认归档', 'ai-skills-hub' ), __( '确认归档', 'ai-skills-hub' ), 'read', 'aish-confirm', array( $this, 'confirmation' ) );
	}
	public function dashboard(): void {
		$cap = 'aish_manage_skills';
		foreach ( self::CAPABILITIES as $candidate ) { if ( self::can( $candidate ) ) { $cap = $candidate; break; } }
		$this->screen( $cap, __( 'AI Skills Hub · Dashboard', 'ai-skills-hub' ), function (): void {
			$stats = $this->management->dashboard();
			echo '<p>' . esc_html__( '管理双语资源、来源和人工审核。', 'ai-skills-hub' ) . '</p><div class="aish-stats">';
			foreach ( array( 'total' => __( 'Skills 总数', 'ai-skills-hub' ), 'pending' => __( '待审核', 'ai-skills-hub' ), 'repositories' => __( 'Repositories', 'ai-skills-hub' ), 'categories' => __( 'Categories', 'ai-skills-hub' ), 'tags' => __( 'Tags', 'ai-skills-hub' ) ) as $key => $label ) { echo '<div class="aish-stat">' . esc_html( $label ) . '<strong>' . (int) ( $stats[ $key ] ?? 0 ) . '</strong></div>'; }
			echo '</div><h2>' . esc_html__( '资源状态', 'ai-skills-hub' ) . '</h2><ul>';
			foreach ( self::statuses() as $status => $label ) { echo '<li>' . esc_html( $label ) . '：' . (int) ( $stats['statuses'][ $status ] ?? 0 ) . '</li>'; }
			echo '</ul>';
			if ( self::can( 'aish_manage_skills' ) ) { echo '<p><a class="button button-primary" href="' . esc_url( self::url( 'aish-skill-edit' ) ) . '">' . esc_html__( '添加 Skill', 'ai-skills-hub' ) . '</a></p>'; }
		} );
	}
	private function filters( bool $reviews = false ): array {
		$settings = get_option( 'aish_admin_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		return array( 'q' => self::query( 's' ), 'page' => max( 1, self::id( 'paged', 1 ) ), 'per_page' => max( 1, min( 100, (int) ( $settings['per_page'] ?? 20 ) ) ), 'sort' => self::query( 'orderby', 'id' ), 'direction' => strtoupper( self::query( 'order', 'DESC' ) ), 'locale' => self::query( 'locale', (string) ( $settings['default_locale'] ?? 'zh_CN' ) ), 'status' => self::query( 'status', $reviews ? 'pending' : '' ), 'risk' => self::query( 'risk' ), 'repository_id' => self::id( 'repository_id' ), 'category_id' => self::id( 'category_id' ), 'tag_id' => self::id( 'tag_id' ), 'archived' => self::query( 'archived', '0' ) );
	}
	private static function select( string $name, array $options, string $selected, string $label ): void {
		echo '<label class="screen-reader-text" for="aish-filter-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><select id="aish-filter-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $text ) { echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $selected, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select>';
	}
	private function table( string $kind, string $page, array $result, array $filters, bool $reviews = false ): void {
		$table = new ListTable( $kind, $result, $filters, $reviews );
		$table->prepare_items();
		echo '<form method="get" id="aish-filters" class="aish-filters"><input type="hidden" name="page" value="' . esc_attr( $page ) . '" />';
		self::hidden( 'orderby', $filters['sort'] ); self::hidden( 'order', $filters['direction'] );
		echo '<label class="screen-reader-text" for="aish-search">' . esc_html__( '搜索', 'ai-skills-hub' ) . '</label><input type="search" id="aish-search" name="s" value="' . esc_attr( $filters['q'] ) . '" placeholder="' . esc_attr__( '搜索名称、标题或来源', 'ai-skills-hub' ) . '" />';
		if ( 'skill' === $kind ) {
			self::select( 'status', array( '' => __( '所有状态', 'ai-skills-hub' ) ) + self::statuses(), $filters['status'], __( '状态', 'ai-skills-hub' ) );
			self::select( 'risk', array( '' => __( '所有风险', 'ai-skills-hub' ), 'unknown' => __( 'Unknown', 'ai-skills-hub' ), 'low' => __( 'Low', 'ai-skills-hub' ), 'medium' => __( 'Medium', 'ai-skills-hub' ), 'high' => __( 'High', 'ai-skills-hub' ) ), $filters['risk'], __( '风险', 'ai-skills-hub' ) );
			self::select( 'locale', array( 'zh_CN' => __( '简体中文', 'ai-skills-hub' ), 'en_US' => __( 'English', 'ai-skills-hub' ) ), $filters['locale'], __( '语言', 'ai-skills-hub' ) );
			foreach ( array( 'repository_id' => __( 'Repository ID', 'ai-skills-hub' ), 'category_id' => __( 'Category ID', 'ai-skills-hub' ), 'tag_id' => __( 'Tag ID', 'ai-skills-hub' ) ) as $key => $label ) {
				echo '<label>' . esc_html( $label ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $key ) . '" value="' . (int) $filters[ $key ] . '" /></label>';
			}
		} else { self::select( 'archived', array( '0' => __( '可用记录', 'ai-skills-hub' ), '1' => __( '已归档', 'ai-skills-hub' ), 'all' => __( '全部记录', 'ai-skills-hub' ) ), $filters['archived'], __( '归档状态', 'ai-skills-hub' ) ); }
		submit_button( __( '筛选', 'ai-skills-hub' ), 'secondary', 'filter', false );
		echo ' <a href="' . esc_url( self::url( $page ) ) . '">' . esc_html__( '重置', 'ai-skills-hub' ) . '</a></form>';
		if ( 'skill' === $kind && ! $reviews ) { echo '<p class="description">' . esc_html__( '批量操作每次最多 50 项。归档会先显示确认页；提交审核与转草稿按各记录的编辑版本执行。', 'ai-skills-hub' ) . '</p>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aish_bulk" />';
		wp_nonce_field( 'aish_bulk' );
		$table->display();
		echo '</form>';
	}
	public function skills(): void {
		$this->screen( 'aish_manage_skills', __( 'Skills', 'ai-skills-hub' ), function (): void {
			echo '<p><a class="page-title-action" href="' . esc_url( self::url( 'aish-skill-edit' ) ) . '">' . esc_html__( 'Add New Skill', 'ai-skills-hub' ) . '</a></p>';
			$filters = $this->filters();
			$this->table( 'skill', 'aish-skills', $this->management->skills( $filters ), $filters );
		} );
	}
	private static function hidden( string $name, string|int $value ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />'; }
	private static function input( string $name, string $label, string $value, int $length = 191 ): void {
		$id = 'aish-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );
		echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br /><input class="large-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . $length . '" value="' . esc_attr( $value ) . '" /></p>';
	}
	private static function textarea( string $name, string $label, string $value, int $max = 16000 ): void {
		$id = 'aish-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );
		echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br /><textarea class="large-text" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . $max . '">' . esc_textarea( $value ) . '</textarea></p>';
	}
	public function editSkill(): void {
		$this->screen( 'aish_manage_skills', __( 'Skill 编辑', 'ai-skills-hub' ), function (): void {
			$id = self::id();
			$detail = $id ? $this->management->skill( $id ) : array( 'skill' => array( 'id' => 0, 'revision' => 0, 'status' => 'draft', 'repository_id' => null, 'source_path' => '' ), 'translations' => array(), 'categories' => array(), 'tags' => array() );
			$skill = $detail['skill'];
			$draft = Actions::failedDraft( $id ?: null );
			if ( $draft ) {
				$detail['translations'] = $draft['translations'] ?? $detail['translations'];
				$detail['categories'] = $draft['category_ids'] ?? $detail['categories'];
				$detail['tags'] = $draft['tag_ids'] ?? $detail['tags'];
				$skill['repository_id'] = $draft['repository_id'] ?? null;
				$skill['source_path'] = $draft['source_path'] ?? '';
				$skill['revision'] = (int) ( $draft['revision'] ?? $skill['revision'] );
				echo '<div class="notice notice-warning"><p>' . esc_html__( '下方保留了上次未保存的输入及原始编辑版本。如果其他人已经更新记录，请先复制需要保留的内容，再重新载入最新记录合并修改。', 'ai-skills-hub' ) . '</p><p><a href="' . esc_url( self::url( 'aish-skill-edit', array( 'id' => $id ) ) ) . '">' . esc_html__( '放弃未保存输入并载入最新记录', 'ai-skills-hub' ) . '</a></p></div>';
			}
			echo '<p class="aish-meta">' . esc_html( $id ? 'Skill #' . $id . __( ' · 当前状态：', 'ai-skills-hub' ) . ( self::statuses()[ $skill['status'] ] ?? $skill['status'] ) : __( '创建新的人工或 GitHub 来源 Skill', 'ai-skills-hub' ) ) . '</p>';
			if ( $id ) { $this->previewLinks( $id ); }
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aish-fields">';
			self::hidden( 'action', 'aish_save_skill' ); self::hidden( 'id', $id ); self::hidden( 'revision', (int) $skill['revision'] ); wp_nonce_field( 'aish_save_skill' );
			echo '<div class="aish-panel"><h2>' . esc_html__( '来源', 'ai-skills-hub' ) . '</h2><label for="aish-repository-id">' . esc_html__( 'Repository ID（留空为人工录入）', 'ai-skills-hub' ) . '</label><br /><input type="number" id="aish-repository-id" name="repository_id" list="aish-repository-options" min="1" value="' . esc_attr( (string) ( $skill['repository_id'] ?? '' ) ) . '" />';
			$repositories = $this->management->listRepositories( array( 'per_page' => 100, 'sort' => 'name', 'direction' => 'ASC', 'archived' => '0' ) );
			$options = array_column( $repositories['items'], null, 'id' );
			if ( ! empty( $skill['repository_id'] ) && ! isset( $options[ $skill['repository_id'] ] ) ) {
				try { $options[ $skill['repository_id'] ] = $this->management->repository( (int) $skill['repository_id'] ); }
				catch ( \Throwable $error ) { echo '<p class="description">' . esc_html__( '所填仓库不存在或不可用，请更正来源。其他输入保留在下方。', 'ai-skills-hub' ) . '</p>'; }
			}
			echo '<datalist id="aish-repository-options">';
			foreach ( $options as $repository ) { echo '<option value="' . (int) $repository['id'] . '" label="' . esc_attr( $repository['owner'] . '/' . $repository['name'] . ( (int) $repository['is_archived'] ? __( '（已归档）', 'ai-skills-hub' ) : '' ) ) . '"></option>'; }
			echo '</datalist>';
			self::input( 'source_path', __( '仓库内文件路径（人工录入留空）', 'ai-skills-hub' ), (string) ( $skill['source_path'] ?? '' ), 768 );
			echo '<p class="description">' . esc_html__( '一个仓库可以关联多个 Skills。仓库 ID 可在 Repositories 页面查找；来源路径必须是相对路径，如 tools/review/SKILL.md。', 'ai-skills-hub' ) . '</p></div>';
			$settings = get_option( 'aish_admin_settings', array() );
			$default = is_array( $settings ) ? ( $settings['default_locale'] ?? 'zh_CN' ) : 'zh_CN';
			echo '<div data-aish-language-tabs><div class="nav-tab-wrapper" role="tablist" aria-label="' . esc_attr__( '编辑语言', 'ai-skills-hub' ) . '">';
			foreach ( array( 'zh_CN' => __( '简体中文', 'ai-skills-hub' ), 'en_US' => __( 'English', 'ai-skills-hub' ) ) as $locale => $label ) { echo '<button type="button" role="tab" class="nav-tab" id="aish-tab-' . esc_attr( $locale ) . '" aria-controls="aish-panel-' . esc_attr( $locale ) . '" aria-selected="true" data-default="' . ( $default === $locale ? '1' : '0' ) . '">' . esc_html( $label ) . '</button>'; }
			echo '</div>';
			foreach ( array( 'zh_CN' => __( '简体中文', 'ai-skills-hub' ), 'en_US' => __( 'English', 'ai-skills-hub' ) ) as $locale => $label ) {
				$row = $detail['translations'][ $locale ] ?? array();
				$row = is_array( $row ) ? $row : array();
				$complete = Management::translationComplete( $row );
				echo '<section class="aish-panel" role="tabpanel" id="aish-panel-' . esc_attr( $locale ) . '" aria-labelledby="aish-tab-' . esc_attr( $locale ) . '"><h2>' . esc_html( $label ) . '</h2><p>' . esc_html( $complete ? __( '内容完整。', 'ai-skills-hub' ) : __( '翻译未完整：标题、Slug、简介和正文均需填写，才能作为完整译文审核。未填写的语言不会生成内容。', 'ai-skills-hub' ) ) . '</p>';
				$prefix = 'translations[' . $locale . ']';
				self::input( $prefix . '[title]', __( '标题 / Title', 'ai-skills-hub' ), (string) ( $row['title'] ?? '' ) );
				self::input( $prefix . '[slug]', __( 'Slug（小写英文、数字与连字符）', 'ai-skills-hub' ), (string) ( $row['slug'] ?? '' ) );
				self::textarea( $prefix . '[summary]', __( '简介 / Summary', 'ai-skills-hub' ), (string) ( $row['summary'] ?? '' ) );
				echo '<label for="aish_' . esc_attr( $locale ) . '_content"><strong>' . esc_html__( '正文 / Content', 'ai-skills-hub' ) . '</strong></label>';
				wp_editor( (string) ( $row['content'] ?? '' ), 'aish_' . $locale . '_content', array( 'textarea_name' => $prefix . '[content]', 'textarea_rows' => 14, 'media_buttons' => false ) );
				self::input( $prefix . '[seo_title]', __( 'SEO 标题（可选）', 'ai-skills-hub' ), (string) ( $row['seo_title'] ?? '' ) );
				self::textarea( $prefix . '[seo_description]', __( 'SEO 描述（可选）', 'ai-skills-hub' ), (string) ( $row['seo_description'] ?? '' ), 320 );
				echo '</section>';
			}
			echo '</div><div class="aish-panel"><h2>' . esc_html__( '分类与标签', 'ai-skills-hub' ) . '</h2><div class="aish-relations">';
			foreach ( array( 'category' => __( '分类', 'ai-skills-hub' ), 'tag' => __( '标签', 'ai-skills-hub' ) ) as $kind => $label ) {
				$key = 'category' === $kind ? 'categories' : 'tags';
				$name = 'category' === $kind ? 'category_ids' : 'tag_ids';
				$selected = array_map( 'intval', $detail[ $key ] );
				$terms = $this->management->listTerms( $kind, array( 'per_page' => 100, 'sort' => 'name', 'direction' => 'ASC', 'archived' => '0' ) );
				$options = array_column( $terms['items'], null, 'id' );
				foreach ( $selected as $term_id ) {
					if ( isset( $options[ $term_id ] ) ) { continue; }
					try { $options[ $term_id ] = $this->management->term( $kind, $term_id ); }
					catch ( \Throwable $error ) { $options[ $term_id ] = array( 'id' => $term_id, 'name' => __( '记录不存在或不可用，请取消选择', 'ai-skills-hub' ), 'is_archived' => 0 ); }
				}
				echo '<div><label for="aish-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><select multiple id="aish-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '[]">';
				foreach ( $options as $term ) { echo '<option value="' . (int) $term['id'] . '"' . selected( in_array( (int) $term['id'], $selected, true ), true, false ) . '>' . esc_html( $term['name'] . ' (#' . $term['id'] . ')' . ( (int) $term['is_archived'] ? __( '（已归档）', 'ai-skills-hub' ) : '' ) ) . '</option>'; }
				echo '</select>';
				if ( $terms['total'] > 100 ) { self::input( $kind . '_extra_ids', __( '其他记录 ID（逗号分隔）', 'ai-skills-hub' ), '', 2000 ); }
				echo '</div>';
			}
			echo '</div><p class="description">' . esc_html__( '按住 Ctrl / Command 可多选或取消选择。显示前 100 条可用记录及所有已有选择；超过范围时可填写其他记录 ID。取消选择会解除关联，分类和标签记录仍保留。', 'ai-skills-hub' ) . '</p></div><p>' . esc_html__( '保存修改会使已有审核失效。只有审核人员通过当前版本后才能公开发布。', 'ai-skills-hub' ) . '</p>';
			echo '<p><button class="button button-primary" name="save_mode" value="draft" type="submit">' . esc_html__( '保存草稿', 'ai-skills-hub' ) . '</button> <button class="button" name="save_mode" value="pending" type="submit">' . esc_html__( '保存并提交审核', 'ai-skills-hub' ) . '</button></p></form>';
			if ( $id && 'archived' !== $skill['status'] ) { echo '<p><a href="' . esc_url( self::confirmUrl( 'skill', $id, (int) $skill['revision'] ) ) . '">' . esc_html__( '归档此 Skill（保留记录）', 'ai-skills-hub' ) . '</a></p>'; }
		} );
	}
	private function previewLinks( int $id ): void {
		echo '<p>' . esc_html__( '已保存内容预览：', 'ai-skills-hub' );
		foreach ( array( 'zh_CN' => __( '简体中文', 'ai-skills-hub' ), 'en_US' => __( 'English', 'ai-skills-hub' ) ) as $locale => $label ) {
			$url = add_query_arg( array( 'action' => 'aish_preview', 'id' => $id, 'locale' => $locale, '_wpnonce' => wp_create_nonce( "aish_preview_{$id}_{$locale}" ) ), admin_url( 'admin-post.php' ) );
			echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '</p>';
	}
	public function categories(): void { $this->terms( 'category' ); }
	public function tags(): void { $this->terms( 'tag' ); }
	private function terms( string $kind ): void {
		$page = 'category' === $kind ? 'aish-categories' : 'aish-tags';
		$this->screen( 'aish_manage_taxonomies', 'category' === $kind ? __( 'Categories', 'ai-skills-hub' ) : __( 'Tags', 'ai-skills-hub' ), function () use ( $kind, $page ): void {
			$id = self::id();
			$row = $id ? $this->management->term( $kind, $id ) : array();
			echo '<div class="aish-panel"><h2>' . esc_html( $id ? __( '编辑 #', 'ai-skills-hub' ) . $id : __( '添加新记录', 'ai-skills-hub' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::hidden( 'action', 'aish_save_term' ); self::hidden( 'kind', $kind ); self::hidden( 'id', $id ); wp_nonce_field( 'aish_save_term' );
			self::input( 'name', __( '名称', 'ai-skills-hub' ), (string) ( $row['name'] ?? '' ) ); self::input( 'slug', __( 'Slug', 'ai-skills-hub' ), (string) ( $row['slug'] ?? '' ) );
			if ( 'category' === $kind ) { echo '<p><label for="aish-parent-id">' . esc_html__( '父分类 ID（留空为顶级）', 'ai-skills-hub' ) . '</label><br /><input type="number" id="aish-parent-id" name="parent_id" min="1" value="' . esc_attr( (string) ( $row['parent_id'] ?? '' ) ) . '" /></p>'; }
			submit_button( __( '保存', 'ai-skills-hub' ) ); echo '</form></div>';
			if ( $id ) { echo '<p><a href="' . esc_url( self::url( $page ) ) . '">' . esc_html__( '添加另一条记录', 'ai-skills-hub' ) . '</a></p>'; }
			$filters = $this->filters(); $this->table( $kind, $page, $this->management->listTerms( $kind, $filters ), $filters );
		} );
	}
	public function repositories(): void {
		$this->screen( 'aish_manage_repositories', __( 'GitHub Repositories', 'ai-skills-hub' ), function (): void {
			$id = self::id(); $row = $id ? $this->management->repository( $id ) : array();
			echo '<div class="aish-panel"><h2>' . esc_html( $id ? __( '编辑 Repository #', 'ai-skills-hub' ) . $id : __( '手动添加 Repository', 'ai-skills-hub' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::hidden( 'action', 'aish_save_repository' ); self::hidden( 'id', $id ); wp_nonce_field( 'aish_save_repository' );
			self::input( 'owner', __( 'GitHub Owner / Organization', 'ai-skills-hub' ), (string) ( $row['owner'] ?? '' ), 39 ); self::input( 'name', __( 'Repository Name', 'ai-skills-hub' ), (string) ( $row['name'] ?? '' ), 100 );
			echo '<p class="description">' . esc_html__( '只维护仓库记录。已有 Skills 关联的仓库不允许修改身份，以免改变这些 Skills 的来源。', 'ai-skills-hub' ) . '</p>';
			submit_button( __( '保存仓库', 'ai-skills-hub' ) ); echo '</form></div>';
			if ( $id ) { echo '<p><a href="' . esc_url( self::url( 'aish-repositories' ) ) . '">' . esc_html__( '添加另一个仓库', 'ai-skills-hub' ) . '</a></p>'; }
			$filters = $this->filters(); $this->table( 'repository', 'aish-repositories', $this->management->listRepositories( $filters ), $filters );
		} );
	}
	public function reviews(): void {
		$this->screen( 'aish_review_skills', __( 'Reviews · 人工审核', 'ai-skills-hub' ), function (): void {
			$id = self::id();
			if ( ! $id ) { $filters = $this->filters( true ); $this->table( 'skill', 'aish-reviews', $this->management->skills( $filters ), $filters, true ); return; }
			$detail = $this->management->skill( $id ); $skill = $detail['skill'];
			echo '<p>' . esc_html( 'Skill #' . $id . __( ' · 版本 ', 'ai-skills-hub' ) . $skill['revision'] . ' · ' . ( self::statuses()[ $skill['status'] ] ?? $skill['status'] ) ) . '</p>';
			if ( ! empty( $skill['repository_id'] ) ) { $repository = $this->management->repository( (int) $skill['repository_id'] ); echo '<p>' . esc_html( $repository['owner'] . '/' . $repository['name'] . ' · ' . $skill['source_path'] ) . '</p>'; }
			else { echo '<p>' . esc_html__( '人工录入，无 GitHub 来源。', 'ai-skills-hub' ) . '</p>'; }
			$this->previewLinks( $id );
			foreach ( array( 'zh_CN' => __( '简体中文', 'ai-skills-hub' ), 'en_US' => __( 'English', 'ai-skills-hub' ) ) as $locale => $label ) {
				$row = $detail['translations'][ $locale ] ?? null;
				echo '<section class="aish-panel"><h2>' . esc_html( $label ) . '</h2>';
				if ( ! $row ) { echo '<p>' . esc_html__( '尚未提供此语言的翻译。', 'ai-skills-hub' ) . '</p>'; } else {
					echo '<h3>' . esc_html( (string) $row['title'] ) . '</h3><p>' . esc_html( (string) $row['summary'] ) . '</p><div class="aish-preview-body">' . wp_kses_post( (string) $row['content'] ) . '</div><p class="description">' . esc_html( 'Slug: ' . ( $row['slug'] ?? '' ) . ' · SEO: ' . ( $row['seo_title'] ?? '' ) ) . '</p><p>' . esc_html( (string) ( $row['seo_description'] ?? '' ) ) . '</p>';
				}
				echo '</section>';
			}
			$reviewer = ! empty( $skill['reviewer_id'] ) ? get_userdata( (int) $skill['reviewer_id'] ) : false;
			echo '<p>' . esc_html( __( '最近审核人：', 'ai-skills-hub' ) . ( $reviewer ? $reviewer->display_name : ( $skill['reviewer_id'] ?? '—' ) ) . __( ' · 审核时间（UTC）：', 'ai-skills-hub' ) . ( $skill['reviewed_at'] ?? '—' ) ) . '</p>';
			if ( 'pending' !== $skill['status'] ) { echo '<p>' . esc_html__( '当前记录不在待审核状态。请先由编辑提交审核。', 'ai-skills-hub' ) . '</p><p>' . esc_html( (string) ( $skill['review_notes'] ?? '' ) ) . '</p>'; return; }
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aish-panel">';
			self::hidden( 'action', 'aish_review' ); self::hidden( 'id', $id ); self::hidden( 'revision', (int) $skill['revision'] ); wp_nonce_field( 'aish_review' );
			echo '<p><label for="aish-filter-risk">' . esc_html__( '风险等级', 'ai-skills-hub' ) . '</label></p>'; self::select( 'risk', array( 'unknown' => __( 'Unknown', 'ai-skills-hub' ), 'low' => __( 'Low', 'ai-skills-hub' ), 'medium' => __( 'Medium', 'ai-skills-hub' ), 'high' => __( 'High', 'ai-skills-hub' ) ), (string) ( $skill['risk_level'] ?? 'unknown' ), __( '风险等级', 'ai-skills-hub' ) );
			self::textarea( 'notes', __( '审核备注（拒绝时请说明原因）', 'ai-skills-hub' ), (string) ( $skill['review_notes'] ?? '' ), 16000 );
			echo '<p>' . esc_html__( '通过后发布当前版本；至少需要一种完整语言。未审核或拒绝的资源不会公开发布。', 'ai-skills-hub' ) . '</p><button class="button button-primary" type="submit" name="decision" value="approved">' . esc_html__( '通过并发布', 'ai-skills-hub' ) . '</button> <button class="button" type="submit" name="decision" value="rejected">' . esc_html__( '拒绝', 'ai-skills-hub' ) . '</button></form>';
		} );
	}
	public function settings(): void {
		$this->screen( 'aish_manage_settings', __( 'Settings', 'ai-skills-hub' ), function (): void {
			$settings = get_option( 'aish_admin_settings', array() ); $settings = is_array( $settings ) ? $settings : array();
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><div class="aish-panel">'; self::hidden( 'action', 'aish_settings' ); wp_nonce_field( 'aish_settings' );
			echo '<p><label for="aish-per-page">' . esc_html__( '每页记录数（1–100）', 'ai-skills-hub' ) . '</label><br /><input type="number" id="aish-per-page" name="per_page" min="1" max="100" value="' . (int) ( $settings['per_page'] ?? 20 ) . '" /></p><p>' . esc_html__( '默认编辑语言', 'ai-skills-hub' ) . '</p>';
			self::select( 'default_locale', array( 'zh_CN' => __( '简体中文', 'ai-skills-hub' ), 'en_US' => __( 'English', 'ai-skills-hub' ) ), (string) ( $settings['default_locale'] ?? 'zh_CN' ), __( '默认编辑语言', 'ai-skills-hub' ) ); submit_button( __( '保存设置', 'ai-skills-hub' ) ); echo '</div></form><h2>' . esc_html__( '管理权限', 'ai-skills-hub' ) . '</h2><p>' . esc_html__( '默认仅授予 Administrator。其他角色需由站点管理员明确分配以下 Capability；审核权限与编辑权限分开。', 'ai-skills-hub' ) . '</p><ul>';
			foreach ( self::CAPABILITIES as $cap ) { echo '<li><code>' . esc_html( $cap ) . '</code></li>'; } echo '</ul>';
		} );
	}
	public function confirmation(): void {
		try {
			$token = self::query( 'token' );
			if ( '' === $token ) {
				$kind = self::query( 'kind' ); $id = self::id(); $revision = self::id( 'revision' );
				check_admin_referer( "aish_confirm_{$kind}_{$id}_{$revision}" );
				$url = $this->actions->prepareConfirmation( $kind, array( $id ), array( $id => $revision ) );
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $parts ); $token = (string) ( $parts['token'] ?? '' );
			}
			$data = $this->actions->confirmation( $token );
			echo '<div class="wrap aish-admin"><h1>' . esc_html__( '确认归档', 'ai-skills-hub' ) . '</h1><div class="aish-panel"><p>' . esc_html__( '归档后原始记录及关联会保留。请核对以下记录，再确认操作。', 'ai-skills-hub' ) . '</p><ul>';
			foreach ( $data['ids'] as $id ) {
				$label = $data['kind'] . ' #' . $id;
				if ( 'skill' === $data['kind'] ) { $detail = $this->management->skill( (int) $id ); $label .= ' — ' . ( $detail['translations']['zh_CN']['title'] ?? $detail['translations']['en_US']['title'] ?? __( '未填写标题', 'ai-skills-hub' ) ); }
				elseif ( 'repository' === $data['kind'] ) { $row = $this->management->repository( (int) $id ); $label .= ' — ' . $row['owner'] . '/' . $row['name']; }
				else { $row = $this->management->term( $data['kind'], (int) $id ); $label .= ' — ' . $row['name']; }
				echo '<li>' . esc_html( $label ) . '</li>';
			}
			echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; self::hidden( 'action', 'aish_archive' ); self::hidden( 'token', $token ); self::hidden( 'confirmed', 1 ); wp_nonce_field( 'aish_archive' );
			submit_button( __( '确认归档并保留记录', 'ai-skills-hub' ), 'primary', 'confirm', false ); echo ' <a class="button" href="' . esc_url( self::url( 'aish' ) ) . '">' . esc_html__( '取消', 'ai-skills-hub' ) . '</a></form></div></div>';
		} catch ( \Throwable $error ) { wp_die( esc_html( __( '确认请求无效、已过期，或你没有权限。请返回列表重新选择。', 'ai-skills-hub' ) ), '', array( 'response' => 400 ) ); }
	}
}
