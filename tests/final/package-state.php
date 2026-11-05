<?php
declare(strict_types=1);

// Disposable, installed ZIP acceptance only. Never enable this for a production database.
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_FINAL_QA' ) ) { exit( 2 ); }
define( 'WP_CLI', true );
require '/var/www/html/wp-load.php';
if ( 'qa_package' !== DB_NAME || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) { throw new RuntimeException( 'Dedicated package QA database with automatic Cron disabled is required.' ); }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'QA administrator is unavailable.' ); }
$entities = array( 'repositories', 'skills', 'skill_translations', 'skill_versions', 'categories', 'tags', 'skill_categories', 'skill_tags', 'imports', 'jobs', 'import_logs', 'ai_jobs', 'ai_attempts', 'ai_reviews' );
$mode = $argv[1] ?? 'snapshot';
if ( ! in_array( $mode, array( 'seed', 'snapshot', 'hooks' ), true ) ) { throw new RuntimeException( 'Unknown package QA operation.' ); }
$requests = 0;
add_filter( 'pre_http_request', static function () use ( &$requests ): WP_Error { ++$requests; return new WP_Error( 'qa_external_request_forbidden', 'Package data checks must not call external services.' ); }, 1 );
$result = array( 'kind' => 'real_installed_zip_wordpress_mysql', 'mode' => $mode, 'time' => gmdate( 'c' ), 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'database' => DB_NAME, 'theme' => get_stylesheet(), 'theme_version' => wp_get_theme( 'ai-skills-hub' )->get( 'Version' ), 'core_loaded' => class_exists( '\\AISkillsHub\\Core\\Core', false ) );
$result['core_version'] = defined( 'AISkillsHub\\Core\\VERSION' ) ? constant( 'AISkillsHub\\Core\\VERSION' ) : null;
$theme_directory = get_theme_root( 'ai-skills-hub' ) . '/ai-skills-hub';
$result['theme_files'] = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( $file->isLink() ) { throw new RuntimeException( 'Installed QA theme unexpectedly contains a symlink.' ); }
	if ( $file->isFile() ) { $result['theme_files'][ substr( $file->getPathname(), strlen( $theme_directory ) + 1 ) ] = hash_file( 'sha256', $file->getPathname() ); }
}
ksort( $result['theme_files'] );
if ( 'seed' === $mode ) {
	if ( get_option( '_aish_qa_package_fixture' ) ) { throw new RuntimeException( 'Package fixture already exists; use snapshot to retain it.' ); }
	$core = \AISkillsHub\Core\Core::boot(); $db = $core->get( 'database' ); $catalog = $core->catalog(); $management = $core->management(); $imports = $core->get( 'imports' );
	$repo = $imports->saveRepository( null, 'https://github.com/qa-package/skills-retention', false );
	$jobId = $imports->enqueue( $repo, 'scan' ); $job = $imports->claim();
	if ( (int) $job['id'] !== $jobId ) { throw new RuntimeException( 'Unexpected import queue state.' ); }
	$raw = "---\nname: package-retention\ndescription: ZIP upgrade retention source\n---\nRead documented input. This is a database fixture, not an executed Skill.";
	$sha = sha1( 'blob ' . strlen( $raw ) . "\0" . $raw );
	$record = $imports->record( $jobId, $job['lease_token'], 'retention/SKILL.md', $sha, str_repeat( 'd', 40 ), $raw, array( 'name' => 'package-retention', 'description' => 'ZIP upgrade retention source', 'metadata' => array(), 'warnings' => array() ), array( 'risk_level' => 'unknown' ), true );
	$imports->finish( $jobId, $job['lease_token'], array( 'stage' => 'qa_fixture', 'errors' => 1 ), 'incomplete' );
	$skill = (int) $record['skill_id'];
	$category = $catalog->createCategory( 'Package retained category', 'package-retained' ); $tag = $catalog->createTag( 'Package retained tag', 'package-retained' );
	$translation = static fn( string $title, string $summary, string $body ): array => array( 'title' => $title, 'slug' => 'package-retained', 'summary' => $summary, 'content' => '<p>' . $body . '</p>', 'seo_title' => $title . ' SEO', 'seo_description' => $summary . ' SEO' );
	$management->saveSkill( $skill, array( 'revision' => (int) $catalog->getSkill( $skill )['revision'], 'save_mode' => 'pending', 'repository_id' => $repo, 'source_path' => 'retention/SKILL.md', 'category_ids' => array( $category ), 'tag_ids' => array( $tag ), 'translations' => array( 'zh_CN' => $translation( 'ZIP 人工中文保留', '管理员中文简介不可覆盖', '管理员中文正文 🚀' ), 'en_US' => $translation( 'ZIP retained human English', 'Human summary must remain unchanged', 'Human-authored English body' ) ) ) );
	$management->review( $skill, 'approved', 'low', 'Manual review retained across ZIP replacement; not a safety certification.', (int) $catalog->getSkill( $skill )['revision'] );
	$draft = $management->saveSkill( null, array( 'translations' => array( 'en_US' => array( 'title' => 'ZIP private draft marker', 'slug' => 'package-private-draft', 'summary' => 'Private retained summary', 'content' => '<p>ZIP_PRIVATE_DRAFT_SENTINEL</p>' ) ) ) );
	$ai = $core->get( 'ai_store' ); $source = ( new \AISkillsHub\Core\AI\SourceContext( $db, $core->migrator() ) )->snapshot( $skill );
	$profile = array( 'id' => 'qa_package', 'enabled' => true, 'provider' => 'openai', 'model' => 'fixture-no-api', 'base_url' => 'https://api.openai.com/v1', 'timeout' => 15, 'temperature' => null, 'max_output_tokens' => 256, 'token_parameter' => 'max_tokens', 'input_price' => null, 'output_price' => null, 'max_cost' => 0.0 );
	$generation = $ai->enqueue( $source, $profile, 'both', hash( 'sha256', 'package-retention' ), 'qa-no-api' ); $claimed = $ai->claim();
	if ( (int) $claimed['id'] !== $generation ) { throw new RuntimeException( 'Unexpected AI queue state.' ); }
	$attempt = $ai->beginAttempt( $generation, $claimed['lease_token'] );
	$ai->completeAttempt( $generation, $claimed['lease_token'], $attempt['id'], array( 'fixture' => 'database-only; no provider called' ), array( 'en_US' => array( 'summary' => 'Private QA generation draft' ) ), array() );
	$ai->saveReview( $generation, 1, array( 'en_US' => array( 'summary' => 'Private QA human-edited generation draft' ) ) );
	update_option( '_aish_qa_package_fixture', array( 'skill_id' => $skill, 'manual_draft_id' => $draft, 'repository_id' => $repo, 'version_id' => (int) $catalog->versionsForSkill( $skill )[0]['id'], 'import_job_id' => $jobId, 'generation_id' => $generation ), false );
}
if ( 'hooks' === $mode ) {
	$core = \AISkillsHub\Core\Core::boot();
	$core->get( 'import_scheduler' )->ensure(); $core->get( 'ai_scheduler' )->ensure();
	// The seeded repository is disabled and both histories are terminal/review-only.
	// Running the registered Cron actions must neither mutate them nor perform HTTP.
	do_action( 'aish_import_tick' ); do_action( 'aish_ai_tick' );
	$result['modules'] = $core->modules();
}
$result['fixture'] = get_option( '_aish_qa_package_fixture', array() );
$result['tables'] = array();
foreach ( $entities as $entity ) {
	$table = $wpdb->prefix . 'aish_' . $entity;
	$rows = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_A );
	if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Package QA fingerprint query failed.' ); }
	$canonical = array();
	foreach ( $rows as $row ) { ksort( $row ); $canonical[] = json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); }
	sort( $canonical, SORT_STRING );
	$result['tables'][ $entity ] = array( 'rows' => count( $rows ), 'sha256' => hash( 'sha256', implode( "\n", $canonical ) ) );
}
$result['schema_version'] = get_option( 'aish_schema_version' );
$result['hooks'] = array( 'import_registered' => false !== has_action( 'aish_import_tick' ), 'ai_registered' => false !== has_action( 'aish_ai_tick' ), 'import_scheduled' => false !== wp_next_scheduled( 'aish_import_tick' ), 'ai_scheduled' => false !== wp_next_scheduled( 'aish_ai_tick' ) );
$result['outbound_http_requests'] = $requests;
if ( $requests > 0 ) { throw new RuntimeException( 'Unexpected HTTP request attempted during package data validation.' ); }
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
