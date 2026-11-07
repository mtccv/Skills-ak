<?php
declare(strict_types=1);

// CLI-only Final QA suite. Do not treat a syntax check as an executed integration run.
// All destructive cleanup is confined to random test tables.
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Module;
use AISkillsHub\Core\ModuleLoader;
use AISkillsHub\Core\Content\Catalog;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Database\Schema;
use AISkillsHub\Core\Language\BuiltinLanguageProvider;
use AISkillsHub\Core\Language\LanguageProvider;
use AISkillsHub\Core\Modules\LanguageModule;
use AISkillsHub\Core\Security\Input;
use AISkillsHub\Core\Security\Output;

$assertions = 0;
$fixtures   = array();
$views      = array();
$test_user  = null;
$original_theme = get_stylesheet();
$status = 0;

function check( bool $condition, string $label ): void {
	global $assertions;
	if ( ! $condition ) {
		throw new RuntimeException( 'Assertion failed: ' . $label );
	}
	++$assertions;
	echo "PASS: $label\n";
}

function rejects( callable $operation, string $class, string $label ): void {
	try {
		$operation();
	} catch ( Throwable $error ) {
		check( $error instanceof $class, $label );
		return;
	}
	check( false, $label );
}

function fixture(): array {
	global $wpdb, $fixtures;
	$prefix = 'aish_t_' . bin2hex( random_bytes( 6 ) ) . '_';
	$option = '_aish_test_schema_' . $prefix;
	$db     = new Database( $wpdb, $prefix );
	$migrator = new Migrator( $db, $option );
	$fixtures[] = array( $db, $option );
	return array( $db, $migrator, new Catalog( $db, $migrator, new BuiltinLanguageProvider() ), $option );
}

function count_rows( Database $db, string $entity ): int {
	return (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( $entity ) );
}

function module_fixture( string $name, array $dependencies = array() ): Module {
	return new class( $name, $dependencies ) implements Module {
		public function __construct( private string $id, private array $needs ) {}
		public function name(): string { return $this->id; }
		public function dependencies(): array { return $this->needs; }
		public function register( Core $core ): void { $core->set( $this->id, new stdClass() ); }
	};
}

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 100, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
	// A quarantined historical QA administrator can retain its role while all
	// capabilities are intentionally denied by the account deletion guard.
	$admins = array_values( array_filter( $admins, static fn( $id ) => user_can( (int) $id, 'manage_options' ) ) );
	check( ! empty( $admins ), 'capability-bearing development administrator exists' );
	$admin = (int) $admins[0];
	wp_set_current_user( $admin );
	do_action( 'admin_init' );
	$core = Core::boot();
	check( $core->migrator()->isCurrent(), 'authorized admin initializes active theme database' );
	check( $core->modules() === array( 'database', 'language', 'catalog', 'admin', 'frontend', 'sources', 'ai', 'accounts', 'community', 'contributions', 'discovery', 'operations' ), 'Core modules loaded in dependency order' );
	check( Core::boot() === $core, 'Core boot is idempotent' );

	list( $db, $migrator, $catalog, $option ) = fixture();
	check( true === $migrator->migrate(), 'fresh database installation' );
	Schema::verify( $db, Schema::VERSION );
	check( Schema::VERSION === get_option( $option ), 'physical schema verified before version stored' );
	check( 33 === count( Schema::COLUMNS ), '33 Core tables including seven discovery tables defined' );
	foreach ( array_keys( Schema::COLUMNS ) as $entity ) {
		check( 0 === count_rows( $db, $entity ), 'fresh table empty: ' . $entity );
	}
	$repo = $catalog->createRepository( 'OpenAI', 'Skills' );
	check( $repo === $catalog->createRepository( 'openai', 'skills' ), 'repository identity normalized and deduplicated' );
	$skill = $catalog->createSkill( $repo, 'skills/one/SKILL.md' );
	$other = $catalog->createSkill( $repo, 'skills/two/SKILL.md' );
	check( $skill !== $other, 'one repository contains multiple Skills' );
	check( $skill === $catalog->createSkill( $repo, 'skills/one/SKILL.md', 'pending' ), 'source duplicate retains stable internal ID' );
	check( 'draft' === $catalog->getSkill( $skill )['status'], 'source deduplication does not overwrite existing status' );
	rejects( fn() => $catalog->createSkill( $repo, 'unreviewed/SKILL.md', 'published' ), InvalidArgumentException::class, 'Catalog cannot bypass human review by directly publishing' );
	$case = $catalog->createSkill( $repo, 'skills/One/SKILL.md' );
	check( $case !== $skill, 'source path case preserved with byte-exact unique key' );
	$repo2 = $catalog->createRepository( 'openai', 'other' );
	check( $skill !== $catalog->createSkill( $repo2, 'skills/one/SKILL.md' ), 'source uniqueness scoped to repository_id' );
	$original = $catalog->getSkill( $skill );
	unset( $original['id'] );
	rejects( fn() => $db->insert( 'skills', $original ), RuntimeException::class, 'database itself enforces full repository/path uniqueness' );

	$zh = $catalog->saveTranslation( $skill, 'zh_CN', '技能中文 🚀', 'skill-one', '中文摘要', '<p>安全说明</p>' );
	$en = $catalog->saveTranslation( $skill, 'en_US', 'English Skill', 'skill-one', 'English summary', '<p>Instructions</p>' );
	check( $zh !== $en && (int) $catalog->getTranslation( $skill, 'zh' )['skill_id'] === $skill && (int) $catalog->getTranslation( $skill, 'en' )['skill_id'] === $skill, 'Chinese and English share the same stable Skill ID' );
	check( '技能中文 🚀' === $catalog->getTranslation( $skill, 'zh_CN' )['title'], 'utf8mb4 Chinese and emoji round trip' );
	check( $zh === $catalog->saveTranslation( $skill, 'zh-cn', '更新中文', 'skill-one' ), 'translation update retains row ID and locale aliases deduplicate' );
	check( 'English Skill' === $catalog->getTranslation( $skill, 'en_US' )['title'], 'updating Chinese does not overwrite English' );
	rejects( fn() => $catalog->saveTranslation( $other, 'en_US', 'Conflict', 'skill-one' ), RuntimeException::class, 'locale/slug collision rejected without updating unrelated translation' );
	check( 'English Skill' === $catalog->getTranslation( $skill, 'en_US' )['title'], 'slug conflict preserves original translation' );
	check( null === $catalog->getTranslation( $other, 'zh_CN' ), 'missing translation returns null without fabricated fallback' );
	rejects( fn() => $catalog->saveTranslation( $skill, 'fr_FR', 'Unsupported', 'unsupported' ), InvalidArgumentException::class, 'unsupported content language rejected' );

	$sha = str_repeat( 'a', 40 );
	$version = $catalog->addVersion( $skill, $sha, "# Original\nSource body", 'v1.0' );
	check( $version === $catalog->addVersion( $skill, $sha, "# Original\nSource body", 'renamed' ), 'version snapshot deduplicates without mutating history' );
	rejects( fn() => $catalog->addVersion( $skill, $sha, 'Different body' ), DomainException::class, 'same commit conflicting snapshot rejected' );
	$versions = $catalog->versionsForSkill( $skill );
	check( 1 === count( $versions ) && 'v1.0' === $versions[0]['version_label'] && hash( 'sha256', "# Original\nSource body" ) === $versions[0]['content_hash'], 'immutable version stores computed source hash and label' );
	check( $version !== $catalog->addVersion( $other, $sha, 'Other Skill body' ), 'same repository commit may version multiple Skills' );

	$category = $catalog->createCategory( 'Development', 'development' );
	$child    = $catalog->createCategory( 'Testing', 'testing', $category );
	$tag      = $catalog->createTag( 'PHP', 'php' );
	$catalog->assignTerm( $skill, 'category', $category );
	$catalog->assignTerm( $skill, 'category', $child );
	$catalog->assignTerm( $skill, 'tag', $tag );
	$catalog->assignTerm( $skill, 'tag', $tag );
	check( 2 === count( $catalog->termsForSkill( $skill, 'category' ) ) && 1 === count( $catalog->termsForSkill( $skill, 'tag' ) ), 'hierarchical categories and idempotent many-to-many tags' );
	check( (int) $catalog->termsForSkill( $skill, 'category' )[1]['parent_id'] === $category, 'category parent association' );
	rejects( fn() => $catalog->assignTerm( $skill, 'evil;DROP', $tag ), InvalidArgumentException::class, 'term identifiers use allowlist' );
	rejects( fn() => $catalog->createCategory( 'Orphan', 'orphan', PHP_INT_MAX ), DomainException::class, 'unknown category parent rejected' );

	$counts = array();
	foreach ( array_keys( Schema::COLUMNS ) as $entity ) { $counts[ $entity ] = count_rows( $db, $entity ); }
	$ddl = 0;
	$watch = static function ( $query ) use ( &$ddl ) {
		if ( preg_match( '/^\s*(CREATE|ALTER|DROP)\s/i', $query ) ) { ++$ddl; }
		return $query;
	};
	add_filter( 'query', $watch );
	check( true === $migrator->migrate() && true === $migrator->migrate(), 'repeated install succeeds' );
	remove_filter( 'query', $watch );
	check( 0 === $ddl, 'current schema repeats issue no DDL' );
	foreach ( $counts as $entity => $count ) { check( $count === count_rows( $db, $entity ), 'reinstall retains rows: ' . $entity ); }
	check( (int) $catalog->getTranslation( $skill, 'zh_CN' )['skill_id'] === $skill, 'reinstall retains bilingual stable identity' );

	// Real historical schema: preserve Phase 1 fixtures while upgrading through the current version.
	list( $old_db, $old_migrator, $old_catalog, $old_option ) = fixture();
	check( true === $old_migrator->migrate( '1.0.0' ), 'historical v1.0.0 schema installation' );
	check( ! in_array( 'version_label', array_column( $old_db->rows( 'SHOW COLUMNS FROM ' . $old_db->table( 'skill_versions' ) ), 'Field' ), true ), 'historical fixture physically lacks new column' );
	$now = gmdate( 'Y-m-d H:i:s' );
	$old_repo = $old_db->insert( 'repositories', array( 'owner' => 'legacy', 'name' => 'skills', 'created_at' => $now ) );
	$old_skill = $old_db->insert( 'skills', array( 'repository_id' => $old_repo, 'source_path' => 'SKILL.md', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now ) );
	$legacy_published = $old_db->insert( 'skills', array( 'repository_id' => $old_repo, 'source_path' => 'published/SKILL.md', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$old_translation = $old_db->insert( 'skill_translations', array( 'skill_id' => $old_skill, 'locale' => 'zh_CN', 'title' => '升级前内容 🚀', 'slug' => 'legacy', 'summary' => '', 'content' => '<p>旧内容</p>', 'created_at' => $now, 'updated_at' => $now ) );
	$old_version = $old_db->insert( 'skill_versions', array( 'skill_id' => $old_skill, 'commit_sha' => $sha, 'content_hash' => hash( 'sha256', 'legacy' ), 'source_content' => 'legacy', 'created_at' => $now ) );
	$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	try {
		check( 1 === (int) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $old_migrator->lockName() ) ), 'second MySQL session holds migration lock' );
		$busy = $old_migrator->migrate();
		check( is_wp_error( $busy ) && 'aish_schema_busy' === $busy->get_error_code() && '1.0.0' === get_option( $old_option ), 'concurrent migration refused without changing version' );
	} finally {
		$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $old_migrator->lockName() ) );
		$second->close();
	}
	check( true === $old_migrator->migrate(), 'upgrade v1.0.0 through current schema after lock released' );
	Schema::verify( $old_db, Schema::VERSION );
	$legacy_review = $old_catalog->getSkill( $legacy_published );
	check( (int) $legacy_review['id'] === $legacy_published && 'pending' === $legacy_review['status'] && 'pending' === $legacy_review['review_status'], 'legacy publication without review evidence retains ID and requires review' );
	check( $old_skill === (int) $old_catalog->getSkill( $old_skill )['id'] && $old_translation === (int) $old_catalog->getTranslation( $old_skill, 'zh_CN' )['id'] && '升级前内容 🚀' === $old_catalog->getTranslation( $old_skill, 'zh_CN' )['title'], 'upgrade preserves stable IDs and Unicode content' );
	check( $old_version === (int) $old_catalog->versionsForSkill( $old_skill )[0]['id'] && '' === $old_catalog->versionsForSkill( $old_skill )[0]['version_label'], 'upgrade preserves historical version and initializes new field' );
	check( is_wp_error( $old_migrator->migrate( '1.0.0' ) ), 'automatic downgrade refused' );
	update_option( $old_option, '99.0.0', false );
	check( is_wp_error( $old_migrator->migrate() ) && '99.0.0' === get_option( $old_option ), 'future schema version retained and refused' );
	rejects( fn() => $old_catalog->createTag( 'Blocked', 'blocked' ), DomainException::class, 'writes fail closed against unsupported schema' );
	update_option( $old_option, Schema::VERSION, false );

	// Simulate DDL failure with a view occupying a required table name, then recover.
	list( $failed_db, $failed_migrator ) = fixture();
	$view = $failed_db->table( 'repositories' );
	$failed_db->execute( "CREATE VIEW $view AS SELECT 1 AS id" );
	$views[] = $view;
	$failure = $failed_migrator->migrate();
	check( is_wp_error( $failure ) && '0' === $failed_migrator->version(), 'failed DDL does not advance schema version' );
	check( ! str_contains( $failure->get_error_message(), DB_PASSWORD ), 'migration errors do not disclose credentials' );
	$failed_db->execute( "DROP VIEW $view" );
	$views = array_diff( $views, array( $view ) );
	check( true === $failed_migrator->migrate(), 'partial DDL installation can safely retry after cause repaired' );

	foreach ( array( '/SKILL.md', '../SKILL.md', 'a/../SKILL.md', 'a//SKILL.md', 'a\\SKILL.md', "a\0b", str_repeat( 'x', 769 ) ) as $bad_path ) {
		rejects( fn() => $catalog->createSkill( $repo, $bad_path ), InvalidArgumentException::class, 'unsafe or oversized source path rejected' );
	}
	rejects( fn() => $catalog->createRepository( "x' OR 1=1", 'repo' ), InvalidArgumentException::class, 'repository identity injection rejected' );
	rejects( fn() => Input::utf8( "\xff", 10 ), InvalidArgumentException::class, 'invalid UTF-8 rejected' );
	rejects( fn() => Input::id( '1 OR 1=1' ), InvalidArgumentException::class, 'IDs reject SQL fragments' );
	rejects( fn() => $db->table( 'skills; DROP TABLE users' ), InvalidArgumentException::class, 'DAL table names allowlisted' );
	rejects( fn() => $db->updateById( 'skills', $skill, array( 'id' => 99 ) ), InvalidArgumentException::class, 'stable ID cannot be updated' );
	rejects( fn() => $db->insert( 'skills', array( 'evil_column' => 1 ) ), InvalidArgumentException::class, 'DAL columns allowlisted' );
	rejects( fn() => $catalog->listSkills( 20, 0, 'id; DROP TABLE users' ), InvalidArgumentException::class, 'SQL sort injection rejected' );
	rejects( fn() => $catalog->listSkills( 101 ), InvalidArgumentException::class, 'unbounded pagination rejected' );
	rejects( fn() => $catalog->versionsForSkill( $skill, 101 ), InvalidArgumentException::class, 'unbounded version history rejected' );
	$quoted_path = "quote' OR '1'='1/SKILL.md";
	$quoted_id = $catalog->createSkill( $repo, $quoted_path );
	check( $quoted_id === (int) $catalog->findSkillBySource( $repo, $quoted_path )['id'] && null === $catalog->findSkillBySource( $repo, 'not-found' ), 'bound SQL treats quotes and SQL fragments as literal path data' );
	$catalog->createSkill( $repo, 'literal%_/SKILL.md' );
	check( 1 === count( $catalog->listSkills( 20, 0, 'id', 'ASC', '%_' ) ), 'LIKE search escapes wildcard input' );

	foreach ( array(
		fn() => $catalog->createSkill( PHP_INT_MAX, 'orphan/SKILL.md' ),
		fn() => $catalog->saveTranslation( PHP_INT_MAX, 'en', 'Orphan', 'orphan' ),
		fn() => $catalog->addVersion( PHP_INT_MAX, $sha, 'orphan' ),
		fn() => $catalog->assignTerm( $skill, 'tag', PHP_INT_MAX ),
	) as $operation ) { rejects( $operation, DomainException::class, 'missing reference cannot create orphan through Catalog' ); }
	$before = count_rows( $db, 'tags' );
	rejects( fn() => $db->transaction( function () use ( $db, $now ) {
		$db->insert( 'tags', array( 'name' => 'Rollback', 'slug' => 'rollback', 'created_at' => $now ) );
		throw new RuntimeException( 'Expected test rollback.' );
	} ), RuntimeException::class, 'transaction reports failure' );
	check( $before === count_rows( $db, 'tags' ), 'transaction failure rolls back inserted rows' );

	$malicious = '<script>alert(1)</script><p onclick="alert(1)">Safe</p><a href="javascript:alert(1)">Link</a>';
	$catalog->saveTranslation( $other, 'zh', '<b>标题</b>', 'safe-title', '', $malicious );
	$safe = $catalog->getTranslation( $other, 'zh_CN' );
	check( '标题' === $safe['title'] && ! str_contains( $safe['content'], '<script' ) && ! str_contains( $safe['content'], 'onclick' ) && ! str_contains( $safe['content'], 'javascript:' ), 'translation content strips executable markup' );
	check( ! str_contains( Output::text( '<img src=x onerror=alert(1)>' ), '<img' ), 'HTML output escaped' );
	check( ! str_contains( Output::attribute( '" onmouseover="x' ), '"' ), 'attribute output escaped' );
	check( '' === Output::url( 'javascript:alert(1)' ), 'unsafe output URL rejected' );
	check( ! str_contains( Output::rich( $malicious ), '<script' ) && ! str_contains( Output::json( '</script><script>alert(1)</script>' ), '<' ), 'rich HTML and embedded JSON output safe' );

	wp_set_current_user( 0 );
	foreach ( array(
		fn() => $catalog->createRepository( 'denied', 'repo' ),
		fn() => $catalog->createSkill( $repo, 'denied/SKILL.md' ),
		fn() => $catalog->saveTranslation( $skill, 'en', 'Denied', 'denied' ),
		fn() => $catalog->addVersion( $skill, str_repeat( 'b', 40 ), 'denied' ),
		fn() => $catalog->createCategory( 'Denied', 'denied' ),
		fn() => $catalog->createTag( 'Denied', 'denied' ),
		fn() => $catalog->assignTerm( $skill, 'tag', $tag ),
	) as $operation ) { rejects( $operation, DomainException::class, 'anonymous Core mutation denied' ); }
	wp_set_current_user( $admin );
	$test_identity = bin2hex( random_bytes( 6 ) );
	$test_user = wp_insert_user( array( 'user_login' => 'aish_test_' . $test_identity, 'user_email' => 'aish-test-' . $test_identity . '@example.invalid', 'user_pass' => wp_generate_password( 48, true, true ), 'role' => 'subscriber' ) );
	check( ! is_wp_error( $test_user ), 'low privilege test user created' );
	wp_set_current_user( (int) $test_user );
	rejects( fn() => $catalog->createTag( 'Denied', 'denied' ), DomainException::class, 'subscriber mutation denied' );
	wp_set_current_user( $admin );

	$provider = new class implements LanguageProvider {
		public function supportedLocales(): array { return array( 'zh_CN', 'en_US' ); }
		public function normalize( string $locale ): string { return ( new BuiltinLanguageProvider() )->normalize( $locale ); }
		public function externalObjectId( int $skill_id, string $locale ): ?int { return 999999; }
	};
	$adapter_catalog = new Catalog( $db, $migrator, $provider );
	check( (int) $adapter_catalog->getTranslation( $skill, 'en' )['skill_id'] === $skill, 'external language object ID cannot replace canonical Skill ID' );
	$filter = static fn() => $provider;
	add_filter( 'aish_core_language_provider', $filter );
	$probe = new Core();
	( new LanguageModule() )->register( $probe );
	remove_filter( 'aish_core_language_provider', $filter );
	check( $probe->languages() === $provider, 'language provider extension hook accepts interface adapter' );
	$loader = new ModuleLoader();
	check( array( 'dependency', 'consumer' ) === $loader->load( array( module_fixture( 'consumer', array( 'dependency' ) ), module_fixture( 'dependency' ) ), new Core() ), 'module loader resolves declared dependencies' );
	rejects( fn() => $loader->load( array( module_fixture( 'same' ), module_fixture( 'same' ) ), new Core() ), LogicException::class, 'duplicate modules rejected' );
	rejects( fn() => $loader->load( array( module_fixture( 'a', array( 'missing' ) ) ), new Core() ), LogicException::class, 'missing module dependency rejected' );
	rejects( fn() => $loader->load( array( module_fixture( 'a', array( 'b' ) ), module_fixture( 'b', array( 'a' ) ) ), new Core() ), LogicException::class, 'cyclic module dependency rejected' );

	$alternatives = array_filter( wp_get_themes(), static fn( $theme ) => $theme->get_stylesheet() !== $original_theme && ! $theme->errors() );
	check( ! empty( $alternatives ), 'another installed theme available for retention test' );
	switch_theme( reset( $alternatives )->get_stylesheet() );
	check( '更新中文' === $catalog->getTranslation( $skill, 'zh' )['title'] && 1 === count( $catalog->versionsForSkill( $skill ) ), 'theme deactivation retains bilingual data and versions' );
	switch_theme( $original_theme );
	check( true === $migrator->migrate() && (int) $catalog->getSkill( $skill )['id'] === $skill, 'theme reactivation retains stable Skill ID' );
	Schema::verify( $core->get( 'database' ), Schema::VERSION );
	check( true, 'active site Core tables remain intact after theme switching' );
	echo "Integration complete: $assertions assertions passed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" );
	$status = 1;
} finally {
	if ( get_stylesheet() !== $original_theme ) { switch_theme( $original_theme ); }
	wp_set_current_user( $admin ?? 0 );
	if ( is_int( $test_user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $test_user );
	}
	foreach ( $views as $view ) { $wpdb->query( "DROP VIEW IF EXISTS $view" ); }
	foreach ( $fixtures as list( $fixture_db, $fixture_option ) ) {
		if ( ! preg_match( '/^aish_t_[a-f0-9]{12}_$/D', $fixture_db->prefix() ) ) { continue; }
		foreach ( array_reverse( array_keys( Schema::COLUMNS ) ) as $entity ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $fixture_db->table( $entity ) );
		}
		delete_option( $fixture_option );
	}
}
exit( $status );
