<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Admin;

defined( 'ABSPATH' ) || exit;

/** Authenticated HTTP boundary. Every mutation is POST + capability + action nonce. */
final class Actions {
	private const CAPS = array(
		'skill' => 'aish_manage_skills', 'category' => 'aish_manage_taxonomies',
		'tag' => 'aish_manage_taxonomies', 'repository' => 'aish_manage_repositories',
	);

	public function __construct( private Management $management ) {}

	public function register(): void {
		foreach ( array( 'aish_save_skill', 'aish_review', 'aish_save_term', 'aish_save_repository', 'aish_archive', 'aish_bulk', 'aish_settings', 'aish_preview' ) as $action ) {
			add_action( 'admin_post_' . $action, fn() => $this->dispatch( $action ) );
		}
	}

	public static function can( string $capability ): bool {
		return current_user_can( $capability ) || current_user_can( 'manage_options' );
	}

	private static function requireCapability( string $capability ): void {
		if ( ! self::can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this operation.', 'ai-skills-hub' ), '', array( 'response' => 403 ) );
		}
	}

	private static function text( array $data, string $key, int $limit = 1000, string $default = '' ): string {
		$value = $data[ $key ] ?? $default;
		if ( ! is_string( $value ) || strlen( $value ) > $limit || str_contains( $value, "\0" ) ) {
			throw new \InvalidArgumentException( 'Invalid form field.' );
		}
		return $value;
	}

	private static function number( mixed $value, bool $zero = false ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^[0-9]+$/D', $value ) ) ) {
			throw new \InvalidArgumentException( 'Invalid integer field.' );
		}
		$number = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => $zero ? 0 : 1 ) ) );
		if ( false === $number ) {
			throw new \InvalidArgumentException( 'Invalid integer field.' );
		}
		return $number;
	}

	private static function ids( mixed $values, int $limit = 50 ): array {
		if ( ! is_array( $values ) || count( $values ) > $limit ) {
			throw new \InvalidArgumentException( 'Invalid selection.' );
		}
		return array_values( array_unique( array_map( static fn( $value ) => self::number( $value ), $values ) ) );
	}

	private static function optionalId( array $data ): ?int {
		$value = $data['id'] ?? '';
		return '' === $value || '0' === $value || 0 === $value ? null : self::number( $value );
	}

	private static function revisions( array $ids, mixed $values ): array {
		if ( ! is_array( $values ) ) {
			throw new \InvalidArgumentException( 'Missing edit versions.' );
		}
		$revisions = array();
		foreach ( $ids as $id ) {
			$revisions[ $id ] = self::number( $values[ $id ] ?? null );
		}
		return $revisions;
	}

	private static function destination( string $kind ): string {
		return match ( $kind ) {
			'skill' => 'aish-skills', 'category' => 'aish-categories',
			'tag' => 'aish-tags', 'repository' => 'aish-repositories',
			default => throw new \InvalidArgumentException( 'Unknown record kind.' ),
		};
	}

	private static function redirect( string $page, array $args = array() ): never {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ), 303 );
		exit;
	}

	/** Token captures the exact confirmed records and their edit revisions, scoped to this user. */
	public function prepareConfirmation( string $kind, array $ids, array $revisions = array() ): string {
		if ( ! isset( self::CAPS[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'Unknown record kind.' );
		}
		self::requireCapability( self::CAPS[ $kind ] );
		$ids = self::ids( $ids );
		if ( ! $ids ) {
			throw new \InvalidArgumentException( 'Select at least one record.' );
		}
		if ( 'skill' === $kind ) {
			$revisions = self::revisions( $ids, $revisions );
		}
		$token = wp_generate_password( 40, false, false );
		$stored = set_transient( self::tokenKey( $token ), array( 'kind' => $kind, 'ids' => $ids, 'revisions' => $revisions, 'user_id' => get_current_user_id() ), 15 * MINUTE_IN_SECONDS );
		if ( ! $stored ) {
			throw new \RuntimeException( 'Unable to prepare confirmation.' );
		}
		return add_query_arg( array( 'page' => 'aish-confirm', 'token' => $token ), admin_url( 'admin.php' ) );
	}

	private static function tokenKey( string $token ): string {
		if ( ! preg_match( '/^[A-Za-z0-9]{40}$/D', $token ) ) {
			throw new \InvalidArgumentException( 'Invalid confirmation token.' );
		}
		return 'aish_confirm_' . hash( 'sha256', $token );
	}

	public function confirmation( string $token ): array {
		$payload = get_transient( self::tokenKey( $token ) );
		if ( ! is_array( $payload ) || (int) ( $payload['user_id'] ?? 0 ) !== get_current_user_id() || ! isset( self::CAPS[ $payload['kind'] ?? '' ] ) ) {
			throw new \DomainException( 'Confirmation expired or unavailable.' );
		}
		self::requireCapability( self::CAPS[ $payload['kind'] ] );
		return $payload;
	}

	private static function draftKey( ?int $id ): string {
		return 'aish_failed_draft_' . get_current_user_id() . '_' . ( $id ?? 0 );
	}

	public static function failedDraft( ?int $id ): ?array {
		self::requireCapability( 'aish_manage_skills' );
		$key = self::draftKey( $id );
		$value = get_transient( $key );
		delete_transient( $key );
		return is_array( $value ) ? $value : null;
	}

	/** Return a one-time, user-scoped message; callers must escape for their output context. */
	public static function failureMessage(): ?string {
		$key = 'aish_failure_' . get_current_user_id();
		$message = get_transient( $key );
		delete_transient( $key );
		return is_string( $message ) ? $message : null;
	}

	private static function rememberFailure( \Throwable $error ): void {
		$message = __( 'The operation failed. Reload the page and check your input before trying again.', 'ai-skills-hub' );
		// Never display arbitrary exception messages: DAL/runtime errors can contain internal details.
		if ( $error instanceof \InvalidArgumentException || $error instanceof \DomainException ) {
			$message = match ( $error->getMessage() ) {
				'记录已被其他操作更新，请刷新后重试。' => __( 'This record changed after you opened it. Copy any unsaved edits, reload the latest record, and review the changes before saving again.', 'ai-skills-hub' ),
				'数据库升级尚未完成，请联系管理员。' => __( 'The database upgrade is incomplete. Ask an administrator to finish the upgrade.', 'ai-skills-hub' ),
				'没有执行此操作的权限。' => __( 'You do not have permission to perform this operation.', 'ai-skills-hub' ),
				'记录不存在。' => __( 'The selected record no longer exists. Return to the list and select an existing record.', 'ai-skills-hub' ),
				'该仓库路径已关联其他 Skill，请编辑已有记录。' => __( 'This repository and source path already belong to another Skill. Edit that Skill or use a different source path.', 'ai-skills-hub' ),
				'该语言的 Slug 已被其他 Skill 使用。' => __( 'Another Skill already uses this slug in the same language. Choose a unique slug.', 'ai-skills-hub' ),
				'该 Slug 已被其他分类或标签使用。' => __( 'This taxonomy slug already exists. Choose a unique slug.', 'ai-skills-hub' ),
				'该 GitHub 仓库已存在，请编辑已有记录。' => __( 'This GitHub repository already exists. Edit its existing record.', 'ai-skills-hub' ),
				'仓库已关联 Skills，不能更换其来源身份；请新建仓库。' => __( 'This repository has linked Skills. Create a new repository instead of changing its owner or name.', 'ai-skills-hub' ),
				'不能新增已归档的分类或标签关联。', '不能新增已归档的父分类关联。' => __( 'An archived taxonomy cannot be added as a new relationship. Select an active category or tag.', 'ai-skills-hub' ),
				'不能向已归档仓库添加或更改来源关联。' => __( 'An archived repository cannot receive new or changed source links. Select an active repository.', 'ai-skills-hub' ),
				'分类不能形成循环。' => __( 'This parent category would create a cycle. Choose a different parent or no parent.', 'ai-skills-hub' ),
				'拒绝时请填写审核备注。' => __( 'Enter a review note explaining why the Skill is rejected.', 'ai-skills-hub' ),
				'仅可审核当前待审核版本。' => __( 'Only the current pending revision can be reviewed. Reload the review queue.', 'ai-skills-hub' ),
				'至少需要一种语言的标题、Slug、简介和正文完整后才能通过审核。' => __( 'Approval requires a title, slug, summary, and body in at least one language. Complete the content and submit it for review again.', 'ai-skills-hub' ),
				'人工录入资源不应填写仓库路径。' => __( 'Clear the source path for a manually entered Skill, or select its repository.', 'ai-skills-hub' ),
				'Expected a canonical ASCII slug.' => __( 'Use a lowercase ASCII slug containing letters, numbers, and hyphens.', 'ai-skills-hub' ),
				'Invalid relative source path.', 'Source path must be canonical and relative.' => __( 'Enter a relative repository file path without leading slashes or parent-directory segments.', 'ai-skills-hub' ),
				'Invalid GitHub repository identity.' => __( 'Enter a valid GitHub owner and repository name, without a URL.', 'ai-skills-hub' ),
				'Invalid text encoding or size.', 'Invalid text length.', 'Invalid form field.' => __( 'A text field is empty, too long, or invalid. Check the field limits and required values.', 'ai-skills-hub' ),
				'Invalid selection.', '关联数量无效。' => __( 'The selection is invalid or too large. Select at most 50 records per bulk action or 100 taxonomy IDs per Skill.', 'ai-skills-hub' ),
				'Invalid integer field.', 'Expected a positive internal ID.', '关联 ID 无效。', '仓库 ID 无效。', '父分类无效。', 'Invalid taxonomy ID list.' => __( 'Use positive integer record IDs. Separate category or tag IDs with commas.', 'ai-skills-hub' ),
				'Missing edit versions.' => __( 'The edit revision is missing. Reload the record before trying again.', 'ai-skills-hub' ),
				'Confirmation expired or unavailable.', 'Invalid confirmation token.' => __( 'This confirmation expired. Return to the list and start the archive operation again.', 'ai-skills-hub' ),
				'Select at least one record.', 'Empty selection.' => __( 'Select at least one record before applying a bulk action.', 'ai-skills-hub' ),
				'Invalid settings.' => __( 'Choose 1 to 100 rows per page and Simplified Chinese or English as the default language.', 'ai-skills-hub' ),
				default => $message,
			};
		}
		set_transient( 'aish_failure_' . get_current_user_id(), $message, 10 * MINUTE_IN_SECONDS );
	}

	private static function taxonomyIds( mixed $value ): array {
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 2100 ) { throw new \InvalidArgumentException( 'Invalid taxonomy ID list.' ); }
			$value = '' === trim( $value ) ? array() : array_map( 'trim', explode( ',', $value ) );
		}
		return self::ids( $value, 100 );
	}

	private static function selectedTaxonomyIds( array $data, string $kind ): array {
		$chosen = self::taxonomyIds( $data[ $kind . '_ids' ] ?? array() );
		$extra = self::taxonomyIds( $data[ $kind . '_extra_ids' ] ?? '' );
		return self::ids( array_values( array_unique( array_merge( $chosen, $extra ) ) ), 100 );
	}

	private function skillInput( array $data, ?int $id ): array {
		$translations = $data['translations'] ?? array();
		if ( ! is_array( $translations ) || array_diff( array_keys( $translations ), array( 'zh_CN', 'en_US' ) ) ) {
			throw new \InvalidArgumentException( 'Invalid translations.' );
		}
		$clean = array();
		foreach ( $translations as $locale => $translation ) {
			if ( ! is_array( $translation ) ) {
				throw new \InvalidArgumentException( 'Invalid translation form.' );
			}
			foreach ( array( 'title' => 764, 'slug' => 191, 'summary' => 16000, 'content' => 524288, 'seo_title' => 764, 'seo_description' => 1280 ) as $field => $length ) {
				$clean[ $locale ][ $field ] = self::text( $translation, $field, $length );
			}
		}
		$repository = self::text( $data, 'repository_id', 20 );
		$path = self::text( $data, 'source_path', 768 );
		return array(
			'revision' => $id ? self::number( $data['revision'] ?? null ) : 0,
			'repository_id' => '' === $repository || '0' === $repository ? null : self::number( $repository ),
			'source_path' => '' === $path ? null : $path,
			'translations' => $clean,
			'category_ids' => self::selectedTaxonomyIds( $data, 'category' ),
			'tag_ids' => self::selectedTaxonomyIds( $data, 'tag' ),
			'save_mode' => self::text( $data, 'save_mode', 20, 'draft' ),
		);
	}

	/** A false Options API result may mean unchanged; verify durable storage, not its cache. */
	private static function saveSettings( int $perPage, string $locale ): void {
		global $wpdb;
		$settings = array( 'per_page' => $perPage, 'default_locale' => $locale );
		$previous = $wpdb->suppress_errors( true );
		try {
			$wpdb->last_error = '';
			update_option( 'aish_admin_settings', $settings, false );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Settings could not be stored.' ); }
			$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'aish_admin_settings' ) );
			if ( '' !== $wpdb->last_error || $stored !== maybe_serialize( $settings ) ) { throw new \RuntimeException( 'Settings could not be verified.' ); }
		} catch ( \Throwable $error ) {
			// Do not leave an optimistic/corrupt option cache masking the persisted value.
			foreach ( array( 'aish_admin_settings', 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
			throw $error;
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	private function dispatch( string $action ): never {
		if ( 'aish_preview' === $action ) {
			$this->preview();
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'This operation requires a submitted form.', 'ai-skills-hub' ), '', array( 'response' => 405 ) );
		}
		$nonce = $_POST['_wpnonce'] ?? '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), $action ) ) {
			wp_die( esc_html__( 'The security check failed. Reload the page and try again.', 'ai-skills-hub' ), '', array( 'response' => 403 ) );
		}
		$data = wp_unslash( $_POST );
		delete_transient( 'aish_failure_' . get_current_user_id() );
		$page = 'aish';
		$id = null;
		$input = null;
		try {
			switch ( $action ) {
				case 'aish_save_skill':
					self::requireCapability( 'aish_manage_skills' );
					$page = 'aish-skill-edit';
					$id = self::optionalId( $data );
					$input = $this->skillInput( $data, $id );
					$saved = $this->management->saveSkill( $id, $input );
					delete_transient( self::draftKey( $id ) );
					self::redirect( $page, array( 'id' => $saved, 'aish_notice' => 'saved' ) );
				case 'aish_review':
					self::requireCapability( 'aish_review_skills' );
					$page = 'aish-reviews';
					$this->management->review( self::number( $data['id'] ?? null ), self::text( $data, 'decision', 20 ), self::text( $data, 'risk', 10 ), self::text( $data, 'notes', 16000 ), self::number( $data['revision'] ?? null ) );
					break;
				case 'aish_save_term':
					self::requireCapability( 'aish_manage_taxonomies' );
					$kind = self::text( $data, 'kind', 10 );
					if ( ! in_array( $kind, array( 'category', 'tag' ), true ) ) { throw new \InvalidArgumentException( 'Unknown taxonomy.' ); }
					$page = self::destination( $kind );
					$parent = self::text( $data, 'parent_id', 20 );
					$this->management->saveTerm( $kind, self::optionalId( $data ), array( 'name' => self::text( $data, 'name', 764 ), 'slug' => self::text( $data, 'slug', 191 ), 'parent_id' => '' === $parent || '0' === $parent ? null : self::number( $parent ) ) );
					break;
				case 'aish_save_repository':
					self::requireCapability( 'aish_manage_repositories' );
					$page = 'aish-repositories';
					$this->management->saveRepository( self::optionalId( $data ), self::text( $data, 'owner', 39 ), self::text( $data, 'name', 100 ) );
					break;
				case 'aish_archive':
					$this->archive( $data );
				case 'aish_bulk':
					self::requireCapability( 'aish_manage_skills' );
					$page = 'aish-skills';
					$ids = self::ids( $data['ids'] ?? array() );
					if ( ! $ids ) { throw new \InvalidArgumentException( 'Empty selection.' ); }
					$revisions = self::revisions( $ids, $data['revisions'] ?? null );
					$operation = self::text( $data, 'bulk_action', 20 );
					if ( '' === $operation || '-1' === $operation ) { $operation = self::text( $data, 'bulk_action2', 20 ); }
					if ( 'archive' === $operation ) {
						wp_safe_redirect( $this->prepareConfirmation( 'skill', $ids, $revisions ), 303 );
						exit;
					}
					if ( ! in_array( $operation, array( 'draft', 'pending' ), true ) ) { throw new \InvalidArgumentException( 'Invalid bulk operation.' ); }
					$success = $errors = 0;
					foreach ( $ids as $selected ) {
						try {
							self::requireCapability( 'aish_manage_skills' );
							$this->management->changeStatus( $selected, $operation, $revisions[ $selected ] );
							++$success;
						} catch ( \Throwable $error ) {
							if ( 0 === $errors ) { self::rememberFailure( $error ); }
							++$errors;
						}
					}
					self::redirect( $page, array( 'aish_notice' => 'bulk', 'successcount' => $success, 'errorcount' => $errors ) );
				case 'aish_settings':
					self::requireCapability( 'aish_manage_settings' );
					$page = 'aish-settings';
					$per_page = self::number( $data['per_page'] ?? null );
					$locale = self::text( $data, 'default_locale', 10 );
					if ( $per_page > 100 || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'Invalid settings.' ); }
					self::saveSettings( $per_page, $locale );
					break;
			}
			self::redirect( $page, array( 'aish_notice' => 'saved' ) );
		} catch ( \Throwable $error ) {
			self::rememberFailure( $error );
			if ( 'aish_save_skill' === $action && is_array( $input ) ) {
				set_transient( self::draftKey( $id ), $input, 10 * MINUTE_IN_SECONDS );
			}
			self::redirect( $page, array_filter( array( 'aish_notice' => 'failed', 'id' => $id ), static fn( $value ) => null !== $value ) );
		}
	}

	private function archive( array $data ): never {
		if ( '1' !== self::text( $data, 'confirmed', 1 ) ) {
			$kind = self::text( $data, 'kind', 20 );
			$id = self::number( $data['id'] ?? null );
			$revisions = 'skill' === $kind ? array( $id => self::number( $data['revision'] ?? null ) ) : array();
			wp_safe_redirect( $this->prepareConfirmation( $kind, array( $id ), $revisions ), 303 );
			exit;
		}
		$token = self::text( $data, 'token', 40 );
		$payload = $this->confirmation( $token );
		delete_transient( self::tokenKey( $token ) );
		$success = $errors = 0;
		foreach ( $payload['ids'] as $id ) {
			try {
				self::requireCapability( self::CAPS[ $payload['kind'] ] );
				match ( $payload['kind'] ) {
					'skill' => $this->management->archiveSkill( $id, $payload['revisions'][ $id ] ),
					'repository' => $this->management->archiveRepository( $id ),
					default => $this->management->archiveTerm( $payload['kind'], $id ),
				};
				++$success;
			} catch ( \Throwable $error ) {
				if ( 0 === $errors ) { self::rememberFailure( $error ); }
				++$errors;
			}
		}
		self::redirect( self::destination( $payload['kind'] ), array( 'aish_notice' => 'archived', 'successcount' => $success, 'errorcount' => $errors ) );
	}

	private function preview(): never {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( ! self::can( 'aish_manage_skills' ) && ! self::can( 'aish_review_skills' ) ) ) {
			wp_die( esc_html__( 'Preview is not available.', 'ai-skills-hub' ), '', array( 'response' => 403 ) );
		}
		try {
			$data = wp_unslash( $_GET );
			$id = self::number( $data['id'] ?? null );
			$locale = self::text( $data, 'locale', 10 );
			if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || ! wp_verify_nonce( self::text( $data, '_wpnonce', 100 ), 'aish_preview_' . $id . '_' . $locale ) ) {
				throw new \DomainException( 'Invalid preview request.' );
			}
			$detail = $this->management->skill( $id );
			$translation = $detail['translations'][ $locale ] ?? null;
			nocache_headers();
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'" );
			echo '<!doctype html><html lang="' . esc_attr( 'zh_CN' === $locale ? 'zh-CN' : 'en-US' ) . '"><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $translation['title'] ?? __( 'Skill preview', 'ai-skills-hub' ) ) . '</title><style>body{font:18px/1.7 system-ui,sans-serif;max-width:860px;margin:40px auto;padding:0 24px;color:#1d2327}aside{padding:12px 20px;background:#f0f0f1;border-left:4px solid #2271b1}img{max-width:100%;height:auto}pre{overflow:auto}a{color:#2271b1}</style></head><body><aside>';
			echo esc_html__( 'Private preview of saved content. This is not a public page.', 'ai-skills-hub' );
			echo '</aside>';
			if ( ! $translation ) {
				echo '<h1>' . esc_html__( 'Translation not available', 'ai-skills-hub' ) . '</h1><p>' . esc_html__( 'No content has been supplied for this language.', 'ai-skills-hub' ) . '</p>';
			} else {
				echo '<main><h1>' . esc_html( $translation['title'] ) . '</h1><p>' . nl2br( esc_html( $translation['summary'] ) ) . '</p><article>' . wp_kses_post( $translation['content'] ) . '</article></main>';
			}
			echo '</body></html>';
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Preview is unavailable. Reload the Skill and try again.', 'ai-skills-hub' ), '', array( 'response' => 403 ) );
		}
	}
}
