<?php
declare(strict_types=1);

// Real WordPress/MySQL fixture. Only the explicitly named disposable Final QA site may be written.
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_FINAL_QA' ) ) { exit( 1 ); }
require ( getenv( 'AISH_QA_WP_ROOT' ) ?: '/var/www/html' ) . '/wp-load.php';
if ( 'qa_seo' !== DB_NAME || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Refusing to seed a non-QA site.' ); }

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Frontend\Editorial;
use AISkillsHub\Core\Frontend\Router;

$prior = get_option( '_aish_final_seo_fixture' );
if ( is_array( $prior ) ) { echo wp_json_encode( $prior, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n"; exit; }
$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
wp_set_current_user( $admin ); do_action( 'admin_init' );
$core = Core::boot(); $catalog = $core->catalog(); $management = $core->management(); $db = $core->get( 'database' );
if ( ! $core->migrator()->isCurrent() ) { throw new RuntimeException( 'Current schema required.' ); }
update_option( 'blog_public', '1' ); update_option( 'aish_admin_settings', array( 'per_page' => 20, 'default_locale' => 'zh_CN' ), false );
$category = $catalog->createCategory( 'QA Development 开发', 'qa-development' );
$tags = array( $catalog->createTag( 'QA CLI', 'qa-cli' ), $catalog->createTag( 'QA Codex', 'platform-qa-codex' ), $catalog->createTag( 'MIT', 'license-mit' ), $catalog->createTag( 'Featured', 'featured' ) );
$repo = $catalog->createRepository( 'qa-fixture', 'seo-skills' );
$fixture = array( 'base' => rtrim( home_url(), '/' ), 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'category_id' => $category, 'tag_id' => $tags[0], 'skills' => array(), 'tutorials' => array(), 'pages' => array(), 'hidden_markers' => array() );

$makeSkill = static function ( string $name, array $locales, string $state = 'published' ) use ( $management, $repo, $category, $tags, &$fixture ): int {
	$translations = array();
	foreach ( $locales as $locale ) {
		$zh = 'zh_CN' === $locale; $prefix = $zh ? 'zh' : 'en';
		$translations[ $locale ] = array( 'title' => ( $zh ? '中文技能 ' : 'English skill ' ) . $name, 'slug' => 'qa-' . $name . '-' . $prefix, 'summary' => ( $zh ? '中文实际摘要 ' : 'Actual English summary ' ) . $name, 'content' => '<p>QA_SEO_BODY_' . $name . '</p><h2>' . ( $zh ? '使用场景' : 'Use cases' ) . '</h2><p>' . ( $zh ? '帮助维护代码评审。' : 'Assist with documented code review.' ) . '</p>', 'seo_title' => ( $zh ? '中文 SEO ' : 'English SEO ' ) . $name, 'seo_description' => ( $zh ? '中文 SEO 描述 ' : 'English SEO description ' ) . $name );
	}
	$id = $management->saveSkill( null, array( 'save_mode' => 'draft' === $state ? 'draft' : 'pending', 'repository_id' => $repo, 'source_path' => $name . '/SKILL.md', 'translations' => $translations, 'category_ids' => array( $category ), 'tag_ids' => $tags ) );
	if ( in_array( $state, array( 'published', 'archived' ), true ) ) { $management->review( $id, 'approved', 'low', 'QA_SEO_PRIVATE_REVIEW_' . $name, 1 ); }
	if ( 'archived' === $state ) { $management->archiveSkill( $id, 2 ); }
	if ( 'rejected' === $state ) { $management->review( $id, 'rejected', 'high', 'QA_SEO_PRIVATE_REVIEW_' . $name, 1 ); }
	$fixture['skills'][ $name ] = array( 'id' => $id, 'state' => $state, 'urls' => array() );
	foreach ( $translations as $locale => $translation ) { $fixture['skills'][ $name ]['urls'][ $locale ] = Router::url( 'skill', array( 'id' => $id, 'slug' => $translation['slug'] ), $locale ); }
	if ( 'published' !== $state ) { $fixture['hidden_markers'][] = 'QA_SEO_BODY_' . $name; }
	return $id;
};
$pair = $makeSkill( 'pair', array( 'zh_CN', 'en_US' ) );
$makeSkill( 'english-only', array( 'en_US' ) );
$makeSkill( 'noindex', array( 'zh_CN', 'en_US' ) );
foreach ( array( 'draft', 'pending', 'rejected', 'archived' ) as $state ) { $makeSkill( $state, array( 'zh_CN', 'en_US' ), $state ); }
$stale = $makeSkill( 'stale-review', array( 'en_US' ) );
$db->updateById( 'skills', $stale, array( 'reviewed_revision' => 1 ) ); $fixture['hidden_markers'][] = 'QA_SEO_BODY_stale-review';
// Exercise XML's actual 200-item boundary and normal 12-item HTML pagination.
for ( $i = 1; $i <= 200; ++$i ) { $makeSkill( 'page-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ), array( 'en_US' ) ); }

$makePost = static function ( string $type, string $key, string $locale, string $status = 'publish', string $kind = '', string $password = '' ) use ( $admin, $pair ): int {
	$zh = 'zh_CN' === $locale;
	$id = wp_insert_post( wp_slash( array( 'post_type' => $type, 'post_status' => $status, 'post_author' => $admin, 'post_title' => ( $zh ? '中文教程/说明 ' : 'English tutorial/page ' ) . $key, 'post_name' => 'qa-native-' . $key, 'post_excerpt' => ( $zh ? '中文教程摘要 ' : 'English tutorial summary ' ) . $key, 'post_content' => '<p>QA_SEO_POST_' . $key . '</p><h2>' . ( $zh ? '实际步骤' : 'Actual steps' ) . '</h2><p>Documented fixture instructions.</p>', 'post_password' => $password ) ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Could not create QA editorial content.' ); }
	update_post_meta( $id, '_aish_locale', $locale );
	if ( $kind ) { update_post_meta( $id, '_aish_page_kind', $kind ); }
	if ( Editorial::TUTORIAL === $type ) { add_post_meta( $id, '_aish_related_skill', $pair ); }
	update_post_meta( $id, '_aish_seo_title', ( $zh ? '中文原生 SEO ' : 'English native SEO ' ) . $key );
	update_post_meta( $id, '_aish_seo_description', ( $zh ? '中文原生描述 ' : 'English native description ' ) . $key );
	return (int) $id;
};
foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
	$id = $makePost( Editorial::TUTORIAL, 'tutorial-' . $locale, $locale );
	$fixture['tutorials']['pair'][ $locale ] = array( 'id' => $id, 'url' => Router::url( 'tutorial', array( 'id' => $id ), $locale ) );
	$id = $makePost( 'page', 'about-' . $locale, $locale, 'publish', 'about' );
	$fixture['pages']['about'][ $locale ] = array( 'id' => $id, 'native_url' => get_permalink( $id ), 'url' => Router::url( 'about', array(), $locale ) );
}
update_post_meta( $fixture['tutorials']['pair']['zh_CN']['id'], '_aish_translation_id', $fixture['tutorials']['pair']['en_US']['id'] );
update_post_meta( $fixture['tutorials']['pair']['en_US']['id'], '_aish_translation_id', $fixture['tutorials']['pair']['zh_CN']['id'] );
foreach ( array( 'draft' => 'draft', 'private' => 'private', 'password' => 'publish', 'noindex' => 'publish', 'english-only' => 'publish' ) as $key => $state ) {
	$id = $makePost( Editorial::TUTORIAL, $key, 'en_US', $state, '', 'password' === $key ? 'fixture-password' : '' );
	$fixture['tutorials'][ $key ] = array( 'id' => $id, 'url' => Router::url( 'tutorial', array( 'id' => $id ), 'en_US' ) );
	if ( 'noindex' === $key ) { update_post_meta( $id, '_aish_seo_noindex', '1' ); }
	if ( in_array( $key, array( 'draft', 'private', 'password' ), true ) ) { $fixture['hidden_markers'][] = 'QA_SEO_POST_' . $key; }
}
$id = $makePost( 'page', 'contact-en_US', 'en_US', 'publish', 'contact' );
$fixture['pages']['contact']['en_US'] = array( 'id' => $id, 'native_url' => get_permalink( $id ), 'url' => Router::url( 'contact', array(), 'en_US' ) );
$seo = array();
foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
	$zh = 'zh_CN' === $locale;
	foreach ( array( 'home', 'skills', 'tutorials' ) as $route ) { $seo[ "$locale:$route:0" ] = array( 'title' => ( $zh ? '中文页面 SEO ' : 'English page SEO ' ) . $route, 'description' => ( $zh ? '中文页面描述 ' : 'English page description ' ) . $route, 'image' => '', 'noindex' => false ); }
	$seo[ "$locale:skill:" . $fixture['skills']['noindex']['id'] ] = array( 'noindex' => true );
}
$seo[ 'en_US:skill:' . $pair ] = array( 'image' => 'https://example.test/qa-share.png' );
update_option( 'aish_seo_settings', $seo, false ); flush_rewrite_rules( true );
update_option( '_aish_final_seo_fixture', $fixture, false );
echo wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
