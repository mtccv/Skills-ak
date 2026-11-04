<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Admin;

defined( 'ABSPATH' ) || exit;
if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/** Native presentation only; querying and authorization belong to Management. */
final class ListTable extends \WP_List_Table {
	public function __construct( private string $kind, private array $result, private array $filters, private bool $review = false ) {
		parent::__construct( array( 'singular' => 'aish-record', 'plural' => 'aish-records', 'ajax' => false ) );
	}

	public function get_columns(): array {
		if ( 'skill' === $this->kind ) {
			$columns = array( 'id' => __( 'ID', 'ai-skills-hub' ), 'display_title' => __( 'Skill', 'ai-skills-hub' ), 'status' => __( '状态', 'ai-skills-hub' ), 'languages' => __( '翻译完整性', 'ai-skills-hub' ), 'repository_label' => __( '来源', 'ai-skills-hub' ), 'risk_level' => __( '风险', 'ai-skills-hub' ), 'updated_at' => __( '更新时间（UTC）', 'ai-skills-hub' ) );
			if ( ! $this->review && Pages::can( 'aish_manage_skills' ) ) { $columns = array( 'cb' => '<input type="checkbox" />' ) + $columns; }
			return $columns;
		}
		$columns = 'repository' === $this->kind
			? array( 'id' => __( 'ID', 'ai-skills-hub' ), 'name' => __( 'GitHub Repository', 'ai-skills-hub' ), 'skill_count' => __( 'Skills', 'ai-skills-hub' ), 'is_archived' => __( '状态', 'ai-skills-hub' ) )
			: array( 'id' => __( 'ID', 'ai-skills-hub' ), 'name' => __( '名称', 'ai-skills-hub' ), 'slug' => __( 'Slug', 'ai-skills-hub' ), 'parent_id' => __( '父分类 ID', 'ai-skills-hub' ), 'is_archived' => __( '状态', 'ai-skills-hub' ) );
		if ( 'tag' === $this->kind ) { unset( $columns['parent_id'] ); }
		return $columns;
	}

	protected function get_sortable_columns(): array {
		return 'skill' === $this->kind ? array( 'id' => array( 'id', false ), 'updated_at' => array( 'updated_at', true ) ) : array( 'id' => array( 'id', false ), 'name' => array( 'name', false ) );
	}

	protected function get_bulk_actions(): array {
		return 'skill' === $this->kind && ! $this->review && Pages::can( 'aish_manage_skills' ) ? array( 'pending' => __( '提交审核', 'ai-skills-hub' ), 'draft' => __( '转为草稿 / 从归档恢复', 'ai-skills-hub' ), 'archive' => __( '归档（下一步确认）', 'ai-skills-hub' ) ) : array();
	}

	protected function bulk_actions( $which = '' ): void {
		$options = $this->get_bulk_actions();
		if ( ! $options ) { return; }
		$name = 'bottom' === $which ? 'bulk_action2' : 'bulk_action';
		echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html__( '批量操作', 'ai-skills-hub' ) . '</label><select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '"><option value="">' . esc_html__( '批量操作', 'ai-skills-hub' ) . '</option>';
		foreach ( $options as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>'; }
		echo '</select> ';
		submit_button( __( '应用', 'ai-skills-hub' ), 'secondary action', 'apply', false );
	}

	/** The enclosing form owns its nonce; WordPress's default bulk nonce uses another action. */
	protected function display_tablenav( $which ): void {
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		if ( $this->has_items() ) {
			echo '<div class="alignleft actions bulkactions">';
			$this->bulk_actions( $which );
			echo '</div>';
		}
		$this->extra_tablenav( $which );
		$this->pagination( $which );
		echo '<br class="clear" /></div>';
	}

	/** Native page input submits the read-only filter form, never the bulk mutation form. */
	protected function pagination( $which ): void {
		ob_start();
		parent::pagination( $which );
		$html = (string) ob_get_clean();
		echo str_replace( 'name="paged"', 'name="paged" form="aish-filters"', $html );
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'skill' === $this->kind ? 'display_title' : 'name' );
		$this->items = $this->result['items'];
		$this->set_pagination_args( array( 'total_items' => (int) $this->result['total'], 'per_page' => (int) $this->filters['per_page'], 'total_pages' => (int) ceil( (int) $this->result['total'] / (int) $this->filters['per_page'] ) ) );
	}

	public function no_items(): void { echo esc_html( __( '没有匹配的记录。', 'ai-skills-hub' ) ); }

	protected function column_cb( $item ): string {
		$id = (int) $item['id'];
		return '<label class="screen-reader-text" for="aish-select-' . $id . '">' . esc_html( __( '选择 Skill ', 'ai-skills-hub' ) . $id ) . '</label><input id="aish-select-' . $id . '" type="checkbox" name="ids[]" value="' . $id . '" /><input type="hidden" name="revisions[' . $id . ']" value="' . (int) $item['revision'] . '" />';
	}

	protected function column_display_title( $item ): string {
		$id = (int) $item['id'];
		$page = $this->review ? 'aish-reviews' : 'aish-skill-edit';
		$title = (string) ( $item['display_title'] ?? '' );
		$label = '' !== $title ? $title : __( '未填写标题 · Skill #', 'ai-skills-hub' ) . $id;
		$actions = array( 'edit' => '<a href="' . esc_url( Pages::url( $page, array( 'id' => $id ) ) ) . '">' . esc_html( $this->review ? __( '审核', 'ai-skills-hub' ) : __( '编辑', 'ai-skills-hub' ) ) . '</a>' );
		if ( Pages::can( 'aish_manage_skills' ) && 'archived' !== $item['status'] ) {
			$actions['archive'] = '<a href="' . esc_url( Pages::confirmUrl( 'skill', $id, (int) $item['revision'] ) ) . '">' . esc_html__( '归档', 'ai-skills-hub' ) . '</a>';
		}
		return '<strong><a href="' . esc_url( Pages::url( $page, array( 'id' => $id ) ) ) . '">' . esc_html( $label ) . '</a></strong>' . $this->row_actions( $actions );
	}

	protected function column_name( $item ): string {
		$id = (int) $item['id'];
		$page = match ( $this->kind ) { 'repository' => 'aish-repositories', 'category' => 'aish-categories', default => 'aish-tags' };
		$name = 'repository' === $this->kind ? $item['owner'] . '/' . $item['name'] : $item['name'];
		$actions = array( 'edit' => '<a href="' . esc_url( Pages::url( $page, array( 'id' => $id ) ) ) . '">' . esc_html__( '编辑', 'ai-skills-hub' ) . '</a>' );
		if ( ! (int) ( $item['is_archived'] ?? 0 ) ) { $actions['archive'] = '<a href="' . esc_url( Pages::confirmUrl( $this->kind, $id ) ) . '">' . esc_html__( '归档', 'ai-skills-hub' ) . '</a>'; }
		return '<strong><a href="' . esc_url( Pages::url( $page, array( 'id' => $id ) ) ) . '">' . esc_html( $name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	protected function column_languages( $item ): string {
		return esc_html( __( '中文：', 'ai-skills-hub' ) . ( ! empty( $item['zh_complete'] ) ? __( '完整', 'ai-skills-hub' ) : __( '缺少内容', 'ai-skills-hub' ) ) . ' / English: ' . ( ! empty( $item['en_complete'] ) ? __( 'Complete', 'ai-skills-hub' ) : __( 'Incomplete', 'ai-skills-hub' ) ) );
	}

	protected function column_default( $item, $column_name ): string {
		return esc_html( match ( $column_name ) {
			'status' => Pages::statuses()[ $item['status'] ] ?? $item['status'],
			'is_archived' => ! empty( $item['is_archived'] ) ? __( '已归档', 'ai-skills-hub' ) : __( '可用', 'ai-skills-hub' ),
			'repository_label' => $item['repository_label'] ?? __( '人工录入', 'ai-skills-hub' ),
			'risk_level' => ucfirst( (string) ( $item['risk_level'] ?? 'unknown' ) ),
			default => (string) ( $item[ $column_name ] ?? '—' ),
		} );
	}
}
