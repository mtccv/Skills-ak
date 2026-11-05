<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Admin;

use AISkillsHub\Core\Content\Catalog;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Security\Input;
use AISkillsHub\Core\Sources\ImportStore;

defined( 'ABSPATH' ) || exit;

/** Administrative workflows over the existing Core tables; no HTTP parsing belongs here. */
final class Management {
	public const STATUSES = array( 'draft', 'pending', 'published', 'rejected', 'archived' );
	public const RISKS = array( 'unknown', 'low', 'medium', 'high' );

	public function __construct( private Database $db, private Migrator $migrator, private Catalog $catalog ) {}

	private function ready( array $capabilities = array( 'aish_manage_skills', 'aish_review_skills' ) ): void {
		$allowed = current_user_can( 'manage_options' );
		foreach ( $capabilities as $capability ) { $allowed = $allowed || current_user_can( $capability ); }
		if ( ! $allowed ) { throw new \DomainException( '没有执行此操作的权限。' ); }
		if ( ! $this->migrator->isCurrent() ) { throw new \DomainException( '数据库升级尚未完成，请联系管理员。' ); }
	}

	private function row( string $entity, int $id, bool $lock = false ): array {
		$id = Input::id( $id );
		$table = $this->db->table( $entity );
		$row = $this->db->row( "SELECT * FROM $table WHERE id=%d" . ( $lock ? ' FOR UPDATE' : '' ), array( $id ) );
		if ( ! $row ) { throw new \DomainException( '记录不存在。' ); }
		return $row;
	}

	private function revision( array $row, int $revision ): void {
		if ( $revision < 1 || (int) $row['revision'] !== $revision ) {
			throw new \DomainException( '记录已被其他操作更新，请刷新后重试。' );
		}
	}

	private function page( array $filters ): array {
		$page = filter_var( $filters['page'] ?? 1, FILTER_VALIDATE_INT );
		$limit = filter_var( $filters['per_page'] ?? 20, FILTER_VALIDATE_INT );
		if ( false === $page || false === $limit || $page < 1 || $page > 10000 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( '分页参数无效。' );
		}
		return array( $limit, ( $page - 1 ) * $limit );
	}

	private function ids( mixed $values ): array {
		if ( ! is_array( $values ) || count( $values ) > 100 ) { throw new \InvalidArgumentException( '关联数量无效。' ); }
		$ids = array();
		foreach ( $values as $value ) {
			$id = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false === $id || $id < 1 ) { throw new \InvalidArgumentException( '关联 ID 无效。' ); }
			$ids[] = $id;
		}
		return array_values( array_unique( $ids ) );
	}

	public function dashboard(): array {
		$this->ready( array( 'aish_manage_skills', 'aish_review_skills', 'aish_manage_taxonomies', 'aish_manage_repositories', 'aish_manage_settings' ) );
		$table = $this->db->table( 'skills' );
		$statuses = array_fill_keys( self::STATUSES, 0 );
		foreach ( $this->db->rows( "SELECT status,COUNT(*) AS total FROM $table GROUP BY status" ) as $row ) {
			if ( isset( $statuses[ $row['status'] ] ) ) { $statuses[ $row['status'] ] = (int) $row['total']; }
		}
		$result = array( 'statuses' => $statuses, 'total' => array_sum( $statuses ), 'pending' => $statuses['pending'] );
		foreach ( array( 'repositories', 'categories', 'tags' ) as $entity ) {
			$result[ $entity ] = (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( $entity ) . ' WHERE is_archived=0' );
		}
		return $result;
	}

	public function skills( array $filters = array() ): array {
		$this->ready();
		list( $limit, $offset ) = $this->page( $filters );
		$locale = $filters['locale'] ?? 'zh_CN';
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( '语言无效。' ); }
		$skills = $this->db->table( 'skills' );
		$translations = $this->db->table( 'skill_translations' );
		$repos = $this->db->table( 'repositories' );
		$where = array( '1=1' );
		$values = array();
		$q = trim( Input::utf8( $filters['q'] ?? '', 768 ) );
		if ( '' !== $q ) {
			$like = '%' . $this->db->connection()->esc_like( $q ) . '%';
			$where[] = "(s.source_path LIKE %s OR EXISTS (SELECT 1 FROM $translations st WHERE st.skill_id=s.id AND (st.title LIKE %s OR st.slug LIKE %s)) OR CONCAT(r.owner,'/',r.name) LIKE %s)";
			array_push( $values, $like, $like, $like, $like );
		}
		foreach ( array( 'status' => self::STATUSES, 'risk' => self::RISKS ) as $filter => $allowed ) {
			$value = $filters[ $filter ] ?? '';
			if ( '' !== $value ) {
				if ( ! in_array( $value, $allowed, true ) ) { throw new \InvalidArgumentException( '筛选条件无效。' ); }
				$where[] = 's.' . ( 'risk' === $filter ? 'risk_level' : 'status' ) . '=%s';
				$values[] = $value;
			}
		}
		foreach ( array( 'repository_id', 'category_id', 'tag_id' ) as $filter ) {
			$value = $filters[ $filter ] ?? '';
			if ( '' === $value || null === $value || '0' === $value || 0 === $value ) { continue; }
			$id = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false === $id || $id < 1 ) { throw new \InvalidArgumentException( '筛选 ID 无效。' ); }
			if ( 'repository_id' === $filter ) { $where[] = 's.repository_id=%d'; }
			else {
				$link = $this->db->table( 'category_id' === $filter ? 'skill_categories' : 'skill_tags' );
				$where[] = "EXISTS (SELECT 1 FROM $link sl WHERE sl.skill_id=s.id AND sl.$filter=%d)";
			}
			$values[] = $id;
		}
		$sorts = array( 'id' => 's.id', 'title' => 'tr.title', 'status' => 's.status', 'risk_level' => 's.risk_level', 'updated_at' => 's.updated_at', 'created_at' => 's.created_at' );
		$sort = $filters['sort'] ?? 'updated_at';
		$direction = strtoupper( (string) ( $filters['direction'] ?? 'DESC' ) );
		if ( ! isset( $sorts[ $sort ] ) || ! in_array( $direction, array( 'ASC', 'DESC' ), true ) ) { throw new \InvalidArgumentException( '排序条件无效。' ); }
		$from = "$skills s LEFT JOIN $repos r ON r.id=s.repository_id";
		$clause = implode( ' AND ', $where );
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $from WHERE $clause", $values );
		$sql = "SELECT s.*,tr.title AS display_title,CONCAT(r.owner,'/',r.name) AS repository_label FROM $from LEFT JOIN $translations tr ON tr.skill_id=s.id AND tr.locale=%s WHERE $clause ORDER BY {$sorts[$sort]} $direction,s.id $direction LIMIT %d OFFSET %d";
		$items = $this->db->rows( $sql, array_merge( array( $locale ), $values, array( $limit, $offset ) ) );
		$completeness = array();
		// Small batches bound memory even when each bilingual document approaches the size limit.
		foreach ( array_chunk( array_map( 'intval', array_column( $items, 'id' ) ), 10 ) as $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			foreach ( $this->db->rows( "SELECT skill_id,locale,title,slug,summary,content FROM $translations WHERE skill_id IN ($placeholders)", $ids ) as $translation ) {
				$completeness[ (int) $translation['skill_id'] ][ $translation['locale'] ] = self::translationComplete( $translation );
			}
		}
		foreach ( $items as &$item ) {
			$item['zh_complete'] = $completeness[ (int) $item['id'] ]['zh_CN'] ?? false;
			$item['en_complete'] = $completeness[ (int) $item['id'] ]['en_US'] ?? false;
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}

	/** Shared by editing, lists and approval; SEO is optional and never synthesized. */
	public static function translationComplete( ?array $translation ): bool {
		if ( null === $translation ) { return false; }
		foreach ( array( 'title', 'slug', 'summary', 'content' ) as $field ) {
			$text = html_entity_decode( wp_strip_all_tags( (string) ( $translation[ $field ] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = preg_replace( '/[\s\p{Z}]+/u', '', $text );
			if ( null === $text || '' === $text ) { return false; }
		}
		return true;
	}

	public function skill( int $id ): array {
		$this->ready();
		$skill = $this->row( 'skills', $id );
		$translations = array( 'zh_CN' => null, 'en_US' => null );
		foreach ( array_keys( $translations ) as $locale ) {
			$translations[ $locale ] = $this->catalog->getTranslation( $id, $locale );
		}
		$result = array( 'skill' => $skill, 'translations' => $translations );
		foreach ( array( 'categories' => 'category_id', 'tags' => 'tag_id' ) as $kind => $column ) {
			$table = $this->db->table( 'skill_' . $kind );
			$result[ $kind ] = array_map( 'intval', array_column( $this->db->rows( "SELECT $column FROM $table WHERE skill_id=%d", array( $id ) ), $column ) );
		}
		return $result;
	}

	private function translation( array $input ): array {
		$slug = Input::utf8( $input['slug'] ?? '', 191 );
		return array(
			'title' => Input::plain( $input['title'] ?? '', 191, true ),
			'slug' => '' === $slug ? null : Input::slug( $slug ),
			'summary' => sanitize_textarea_field( Input::utf8( $input['summary'] ?? '', 16000 ) ),
			'content' => wp_kses_post( Input::utf8( $input['content'] ?? '', 524288 ) ),
			'seo_title' => Input::plain( $input['seo_title'] ?? '', 191, true ),
			'seo_description' => Input::plain( $input['seo_description'] ?? '', 320, true ),
		);
	}

	private function relation( int $skill_id, string $kind, array $ids ): void {
		$column = 'categories' === $kind ? 'category_id' : 'tag_id';
		$link = $this->db->table( 'skill_' . $kind );
		$existing = array_map( 'intval', array_column( $this->db->rows( "SELECT $column FROM $link WHERE skill_id=%d", array( $skill_id ) ), $column ) );
		foreach ( $ids as $id ) {
			$row = $this->row( $kind, $id, true );
			if ( (int) $row['is_archived'] && ! in_array( $id, $existing, true ) ) { throw new \DomainException( '不能新增已归档的分类或标签关联。' ); }
		}
		// Only relationship rows are replaced; canonical Skill/category/tag records remain intact.
		$this->db->execute( "DELETE FROM $link WHERE skill_id=%d", array( $skill_id ) );
		foreach ( $ids as $id ) { $this->db->insert( 'skill_' . $kind, array( 'skill_id' => $skill_id, $column => $id ) ); }
	}

	public function saveSkill( ?int $id, array $input ): int {
		$this->ready( array( 'aish_manage_skills' ) );
		$mode = $input['save_mode'] ?? 'draft';
		if ( ! in_array( $mode, array( 'draft', 'pending' ), true ) ) { throw new \InvalidArgumentException( '保存状态无效。' ); }
		$repo = $input['repository_id'] ?? null;
		$repo = null === $repo || '' === $repo || '0' === $repo || 0 === $repo ? null : filter_var( $repo, FILTER_VALIDATE_INT );
		if ( false === $repo || ( null !== $repo && $repo < 1 ) ) { throw new \InvalidArgumentException( '仓库 ID 无效。' ); }
		$path = null === $repo ? null : Input::sourcePath( $input['source_path'] ?? '' );
		if ( null === $repo && null !== ( $input['source_path'] ?? null ) && '' !== $input['source_path'] ) { throw new \InvalidArgumentException( '人工录入资源不应填写仓库路径。' ); }
		$translations = $input['translations'] ?? array();
		if ( ! is_array( $translations ) || array_diff( array_keys( $translations ), array( 'zh_CN', 'en_US' ) ) ) { throw new \InvalidArgumentException( '翻译数据无效。' ); }
		$clean = array();
		foreach ( $translations as $locale => $translation ) {
			if ( ! is_array( $translation ) ) { throw new \InvalidArgumentException( '翻译数据无效。' ); }
			$clean[ $locale ] = $this->translation( $translation );
		}
		$categories = $this->ids( $input['category_ids'] ?? array() );
		$tags = $this->ids( $input['tag_ids'] ?? array() );
		return $this->db->transaction( function () use ( $id, $input, $mode, $repo, $path, $clean, $categories, $tags ) {
			$old = null;
			if ( null !== $id ) {
				$old = $this->row( 'skills', $id, true );
				$revision = filter_var( $input['revision'] ?? null, FILTER_VALIDATE_INT );
				$this->revision( $old, false === $revision ? 0 : $revision );
			}
			if ( null !== $repo ) {
				$repository = $this->row( 'repositories', $repo, true );
				if ( (int) $repository['is_archived'] && ( ! $old || (int) $old['repository_id'] !== $repo || $old['source_path'] !== $path ) ) { throw new \DomainException( '不能向已归档仓库添加或更改来源关联。' ); }
				$table = $this->db->table( 'skills' );
				$duplicate = $this->db->value( "SELECT id FROM $table WHERE repository_id=%d AND source_path=%s", array( $repo, $path ) );
				if ( null !== $duplicate && (int) $duplicate !== $id ) { throw new \DomainException( '该仓库路径已关联其他 Skill，请编辑已有记录。' ); }
			}
			$now = gmdate( 'Y-m-d H:i:s' );
			$data = array( 'repository_id' => $repo, 'source_path' => $path, 'status' => $mode, 'review_status' => 'pending' === $mode ? 'pending' : 'unreviewed', 'updated_at' => $now, 'revision' => $old ? (int) $old['revision'] + 1 : 1 );
			if ( $old ) { $this->db->updateById( 'skills', $id, $data ); }
			else { $data['created_at'] = $now; $id = $this->db->insert( 'skills', $data ); }
			$table = $this->db->table( 'skill_translations' );
			foreach ( $clean as $locale => $translation ) {
				$existing = $this->db->row( "SELECT * FROM $table WHERE skill_id=%d AND locale=%s", array( $id, $locale ) );
				if ( ! $existing && ! array_filter( $translation, static fn( $value ) => null !== $value && '' !== $value ) ) { continue; }
				if ( null !== $translation['slug'] ) {
					$duplicate = $this->db->value( "SELECT skill_id FROM $table WHERE locale=%s AND slug=%s", array( $locale, $translation['slug'] ) );
					if ( null !== $duplicate && (int) $duplicate !== $id ) { throw new \DomainException( '该语言的 Slug 已被其他 Skill 使用。' ); }
				}
				$translation['updated_at'] = $now;
				if ( $existing ) { $this->db->updateById( 'skill_translations', (int) $existing['id'], $translation ); }
				else {
					$translation['skill_id'] = $id; $translation['locale'] = $locale; $translation['created_at'] = $now;
					$this->db->insert( 'skill_translations', $translation );
				}
			}
			$this->relation( $id, 'categories', $categories );
			$this->relation( $id, 'tags', $tags );
			return $id;
		} );
	}

	public function review( int $id, string $decision, string $risk, string $notes, int $revision ): void {
		$this->ready( array( 'aish_review_skills' ) );
		if ( ! in_array( $decision, array( 'approved', 'rejected' ), true ) || ! in_array( $risk, self::RISKS, true ) ) { throw new \InvalidArgumentException( '审核结果或风险等级无效。' ); }
		$notes = sanitize_textarea_field( Input::utf8( $notes, 16000 ) );
		if ( 'rejected' === $decision && '' === trim( $notes ) ) { throw new \InvalidArgumentException( '拒绝时请填写审核备注。' ); }
		$this->db->transaction( function () use ( $id, $decision, $risk, $notes, $revision ) {
			$skill = $this->row( 'skills', $id, true );
			$this->revision( $skill, $revision );
			if ( 'pending' !== $skill['status'] || 'pending' !== $skill['review_status'] ) { throw new \DomainException( '仅可审核当前待审核版本。' ); }
			if ( 'approved' === $decision ) {
				$table = $this->db->table( 'skill_translations' );
				$rows = $this->db->rows( "SELECT * FROM $table WHERE skill_id=%d", array( $id ) );
				$complete = false;
				foreach ( $rows as $row ) {
					$complete = $complete || self::translationComplete( $row );
				}
				if ( ! $complete ) { throw new \DomainException( '至少需要一种语言的标题、Slug、简介和正文完整后才能通过审核。' ); }
			}
			$this->db->updateById( 'skills', $id, array( 'status' => 'approved' === $decision ? 'published' : 'rejected', 'review_status' => $decision, 'risk_level' => $risk, 'review_notes' => $notes, 'reviewer_id' => get_current_user_id(), 'reviewed_at' => gmdate( 'Y-m-d H:i:s' ), 'reviewed_revision' => $revision + 1, 'revision' => $revision + 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		} );
	}

	public function archiveSkill( int $id, int $revision ): void {
		$this->ready( array( 'aish_manage_skills' ) );
		$this->db->transaction( function () use ( $id, $revision ) {
			$skill = $this->row( 'skills', $id, true ); $this->revision( $skill, $revision );
			$this->db->updateById( 'skills', $id, array( 'status' => 'archived', 'revision' => $revision + 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		} );
	}

	public function changeStatus( int $id, string $status, int $revision ): void {
		$this->ready( array( 'aish_manage_skills' ) );
		if ( ! in_array( $status, array( 'draft', 'pending' ), true ) ) { throw new \InvalidArgumentException( '状态操作无效。' ); }
		$this->db->transaction( function () use ( $id, $status, $revision ) {
			$skill = $this->row( 'skills', $id, true ); $this->revision( $skill, $revision );
			$this->db->updateById( 'skills', $id, array( 'status' => $status, 'review_status' => 'pending' === $status ? 'pending' : 'unreviewed', 'revision' => $revision + 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		} );
	}

	private function termEntity( string $kind ): string {
		return match ( $kind ) { 'category' => 'categories', 'tag' => 'tags', default => throw new \InvalidArgumentException( '分类类型无效。' ) };
	}

	private function referenceList( string $entity, array $filters ): array {
		list( $limit, $offset ) = $this->page( $filters );
		$table = $this->db->table( $entity );
		$archived = (string) ( $filters['archived'] ?? '0' );
		if ( ! in_array( $archived, array( 'all', '0', '1' ), true ) ) { throw new \InvalidArgumentException( '归档筛选无效。' ); }
		$where = array( '1=1' ); $values = array();
		if ( 'all' !== $archived ) { $where[] = 't.is_archived=%d'; $values[] = (int) $archived; }
		$q = trim( Input::utf8( $filters['q'] ?? '', 768 ) );
		if ( '' !== $q ) {
			$like = '%' . $this->db->connection()->esc_like( $q ) . '%';
			$where[] = 'repositories' === $entity ? "CONCAT(t.owner,'/',t.name) LIKE %s" : '(t.name LIKE %s OR t.slug LIKE %s)';
			$values[] = $like; if ( 'repositories' !== $entity ) { $values[] = $like; }
		}
		$sort = $filters['sort'] ?? 'id';
		$allowed = 'repositories' === $entity ? array( 'id', 'owner', 'name', 'created_at' ) : array( 'id', 'name', 'slug', 'created_at' );
		$direction = strtoupper( (string) ( $filters['direction'] ?? 'DESC' ) );
		if ( ! in_array( $sort, $allowed, true ) || ! in_array( $direction, array( 'ASC', 'DESC' ), true ) ) { throw new \InvalidArgumentException( '排序无效。' ); }
		$clause = implode( ' AND ', $where );
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM $table t WHERE $clause", $values );
		if ( 'repositories' === $entity ) { $links = $this->db->table( 'skills' ); $count = "(SELECT COUNT(*) FROM $links l WHERE l.repository_id=t.id)"; }
		else { $links = $this->db->table( 'skill_' . $entity ); $column = 'categories' === $entity ? 'category_id' : 'tag_id'; $count = "(SELECT COUNT(*) FROM $links l WHERE l.$column=t.id)"; }
		return array( 'items' => $this->db->rows( "SELECT t.*,$count AS skill_count FROM $table t WHERE $clause ORDER BY t.$sort $direction,t.id $direction LIMIT %d OFFSET %d", array_merge( $values, array( $limit, $offset ) ) ), 'total' => $total );
	}

	public function listTerms( string $kind, array $filters = array() ): array {
		$this->ready( array( 'aish_manage_taxonomies', 'aish_manage_skills', 'aish_review_skills' ) );
		return $this->referenceList( $this->termEntity( $kind ), $filters );
	}

	public function term( string $kind, int $id ): array {
		$this->ready( array( 'aish_manage_taxonomies', 'aish_manage_skills', 'aish_review_skills' ) );
		return $this->row( $this->termEntity( $kind ), $id );
	}

	public function saveTerm( string $kind, ?int $id, array $data ): int {
		$this->ready( array( 'aish_manage_taxonomies' ) );
		$entity = $this->termEntity( $kind );
		$clean = array( 'name' => Input::plain( $data['name'] ?? '' ), 'slug' => Input::slug( $data['slug'] ?? '' ) );
		$parent = null;
		if ( 'category' === $kind ) {
			$value = $data['parent_id'] ?? null;
			if ( null !== $value && '' !== $value && 0 !== $value && '0' !== $value ) {
				$parent = filter_var( $value, FILTER_VALIDATE_INT );
				if ( false === $parent || $parent < 1 ) { throw new \InvalidArgumentException( '父分类无效。' ); }
			}
			$clean['parent_id'] = $parent;
		}
		return $this->db->transaction( function () use ( $entity, $id, $clean, $parent ) {
			// Serialize hierarchy edits so two concurrent changes cannot create a cycle.
			if ( 'categories' === $entity ) { $this->db->rows( 'SELECT id FROM ' . $this->db->table( $entity ) . ' ORDER BY id FOR UPDATE' ); }
			$old = null === $id ? null : $this->row( $entity, $id, true );
			$table = $this->db->table( $entity );
			$duplicate = $this->db->value( "SELECT id FROM $table WHERE slug=%s", array( $clean['slug'] ) );
			if ( null !== $duplicate && (int) $duplicate !== $id ) { throw new \DomainException( '该 Slug 已被其他分类或标签使用。' ); }
			$seen = array(); $cursor = $parent;
			while ( null !== $cursor ) {
				if ( $cursor === $id || isset( $seen[ $cursor ] ) ) { throw new \DomainException( '分类不能形成循环。' ); }
				$seen[ $cursor ] = true;
				$row = $this->row( 'categories', $cursor, true );
				if ( (int) $row['is_archived'] && $cursor === $parent && ( ! $old || (int) $old['parent_id'] !== $parent ) ) { throw new \DomainException( '不能新增已归档的父分类关联。' ); }
				$cursor = null === $row['parent_id'] ? null : (int) $row['parent_id'];
			}
			if ( null === $id ) { return $this->db->insert( $entity, $clean + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); }
			$this->db->updateById( $entity, $id, $clean );
			return $id;
		} );
	}

	public function archiveTerm( string $kind, int $id ): void {
		$this->ready( array( 'aish_manage_taxonomies' ) );
		$entity = $this->termEntity( $kind );
		$this->db->transaction( function () use ( $entity, $id ) {
			$this->row( $entity, $id, true );
			$this->db->updateById( $entity, $id, array( 'is_archived' => 1 ) );
		} );
	}

	public function listRepositories( array $filters = array() ): array {
		$this->ready( array( 'aish_manage_repositories', 'aish_manage_skills', 'aish_review_skills' ) );
		return $this->referenceList( 'repositories', $filters );
	}

	public function repository( int $id ): array {
		$this->ready( array( 'aish_manage_repositories', 'aish_manage_skills', 'aish_review_skills' ) );
		return $this->row( 'repositories', $id );
	}

	public function saveRepository( ?int $id, string $owner, string $name ): int {
		$this->ready( array( 'aish_manage_repositories' ) );
		list( $owner, $name ) = Input::repository( $owner, $name );
		return $this->db->transaction( function () use ( $id, $owner, $name ) {
			$table = $this->db->table( 'repositories' );
			// Lock the identity before the first consistent read. Under REPEATABLE READ,
			// an earlier snapshot could hide bindings committed while this lock waited.
			$old = null === $id ? null : $this->row( 'repositories', $id, true );
			$duplicate = $this->db->value( "SELECT id FROM $table WHERE owner=%s AND name=%s", array( $owner, $name ) );
			if ( null !== $duplicate && (int) $duplicate !== $id ) { throw new \DomainException( '该 GitHub 仓库已存在，请编辑已有记录。' ); }
			if ( null === $id ) { return $this->db->insert( 'repositories', array( 'owner' => $owner, 'name' => $name, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); }
			$skills = $this->db->table( 'skills' );
			$jobs = $this->db->table( 'jobs' );
			if ( ( $old['owner'] !== $owner || $old['name'] !== $name ) && ( ! empty( $old['github_id'] ) || (int) $this->db->value( "SELECT COUNT(*) FROM $skills WHERE repository_id=%d", array( $id ) ) > 0 || (int) $this->db->value( "SELECT COUNT(*) FROM $jobs WHERE repository_id=%d", array( $id ) ) > 0 ) ) { throw new \DomainException( '仓库已绑定来源或采集记录，不能更换身份；请新建仓库。' ); }
			$this->db->updateById( 'repositories', $id, array( 'owner' => $owner, 'name' => $name ) );
			return $id;
		} );
	}

	public function archiveRepository( int $id ): void {
		$this->ready( array( 'aish_manage_repositories' ) );
		$this->row( 'repositories', $id );
		// Both administrative menus must fence leases and cancel the same queue.
		( new ImportStore( $this->db, $this->migrator ) )->archiveRepository( $id );
	}
}
