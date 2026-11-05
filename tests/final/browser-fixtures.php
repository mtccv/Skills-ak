<?php
declare(strict_types=1);

/** Explicit fixtures, only for the disposable Cloud browser QA database. Run with WP-CLI. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'DB_NAME' ) || 'qa_browser' !== DB_NAME || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Requires the disposable loopback browser QA site.' ); }
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $administrators ) { throw new RuntimeException( 'QA administrator missing.' ); }
wp_set_current_user( $administrators[0]->ID );
$credentials = array( 'admin' => json_decode( file_get_contents( '/qa/browser-credentials.json' ), true, 16, JSON_THROW_ON_ERROR ) );
foreach ( array( 'subscriber' => null, 'ai_generate' => 'aish_generate_content', 'ai_settings' => 'aish_manage_ai_settings' ) as $role => $capability ) {
	$username = 'qa_browser_' . $role;
	$password = wp_generate_password( 40, true, true );
	$user = get_user_by( 'login', $username );
	if ( ! $user ) { $id = wp_insert_user( array( 'user_login' => $username, 'user_pass' => $password, 'user_email' => $username . '@example.invalid', 'role' => 'subscriber' ) ); if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Could not create isolated QA role.' ); } $user = get_user_by( 'id', $id ); }
	else { wp_set_password( $password, $user->ID ); }
	if ( $capability ) { $user->add_cap( $capability ); }
	$credentials[ $role ] = array( 'username' => $username, 'password' => $password );
}
file_put_contents( '/qa/browser-test-credentials.json', wp_json_encode( $credentials ), LOCK_EX ); chmod( '/qa/browser-test-credentials.json', 0600 ); unset( $credentials, $password );
// Reset only QA rate counters so a deliberate repeat of the browser suite is reproducible.
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_aish_public_rate_' ) . '%' ) );
$existing = get_option( 'aish_browser_qa_fixtures' );
if ( is_array( $existing ) ) { echo wp_json_encode( $existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), "\n"; return; }
$management = AISkillsHub\Core\Core::boot()->management();
$category = $management->saveTerm( 'category', null, array( 'name' => 'QA 浏览器分类 / Browser category', 'slug' => 'qa-browser-category', 'parent_id' => null ) );
$tags = array();
foreach ( array( 'qa-browser-tag' => 'QA Browser tag', 'platform-qa-browser' => 'QA fixture platform (not a compatibility claim)', 'featured' => 'QA featured fixture' ) as $slug => $name ) { $tags[ $slug ] = $management->saveTerm( 'tag', null, array( 'name' => $name, 'slug' => $slug, 'parent_id' => null ) ); }
$manifest = array( 'fixture_notice' => 'Isolated QA content, never production resources or statistics.', 'category_id' => $category, 'tags' => $tags, 'published' => array(), 'hidden' => array(), 'tutorials' => array(), 'pages' => array() );
foreach ( array_merge( range( 1, 14 ), array( 'zh-only', 'draft', 'pending', 'rejected', 'archived' ) ) as $key ) {
	$slug = 'qa-browser-' . $key;
	$translations = array();
	foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
		if ( 'zh-only' === $key && 'en_US' === $locale ) { continue; }
		$zh = 'zh_CN' === $locale;
		$translations[ $locale ] = array( 'title' => ( $zh ? 'QA 浏览器技能 ' : 'QA Browser Skill ' ) . $key, 'slug' => $slug, 'summary' => $zh ? '仅用于隔离浏览器验收的真实数据库测试记录。' : 'A real database fixture used only by isolated browser acceptance tests.', 'content' => $zh ? '<p>明确标记的 QA 测试内容。</p><h2>使用场景</h2><p>验证页面阅读和语言切换。</p><h2>安装说明</h2><p>测试记录无需安装任何命令。</p><h2>依赖要求</h2><p>仅用于 Cloud QA。</p>' : '<p>Explicit QA fixture content.</p><h2>Use cases</h2><p>Verify reading and language navigation.</p><h2>Installation</h2><p>No installation commands for this fixture.</p><h2>Requirements</h2><p>Cloud QA only.</p>', 'seo_title' => '', 'seo_description' => '' );
	}
	$id = $management->saveSkill( null, array( 'save_mode' => 'draft' === $key ? 'draft' : 'pending', 'translations' => $translations, 'category_ids' => array( $category ), 'tag_ids' => array_values( $tags ) ) );
	if ( is_int( $key ) || in_array( $key, array( 'zh-only', 'archived' ), true ) ) { $management->review( $id, 'approved', 'unknown', 'QA_INTERNAL_REVIEW_NOTE_NOT_PUBLIC', 1 ); }
	elseif ( 'rejected' === $key ) { $management->review( $id, 'rejected', 'high', 'QA rejection fixture', 1 ); }
	if ( 'archived' === $key ) { $management->archiveSkill( $id, 2 ); }
	$item = array( 'id' => $id, 'slug' => $slug );
	if ( is_int( $key ) ) { $manifest['published'][] = $item; }
	elseif ( 'zh-only' === $key ) { $manifest['zh_only'] = $item; }
	else { $manifest['hidden'][ $key ] = $item; }
}
foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
	$zh = 'zh_CN' === $locale;
	$id = wp_insert_post( array( 'post_type' => 'aish_tutorial', 'post_status' => 'publish', 'post_author' => get_current_user_id(), 'post_title' => $zh ? 'QA 浏览器教程' : 'QA Browser Tutorial', 'post_content' => $zh ? '<p>浏览器验收专用教程。</p>' : '<p>Browser acceptance fixture tutorial.</p>', 'post_excerpt' => $zh ? '隔离测试教程摘要。' : 'Isolated fixture tutorial summary.' ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Could not create tutorial fixture.' ); }
	update_post_meta( $id, '_aish_locale', $locale ); update_post_meta( $id, '_aish_related_skill', $manifest['published'][0]['id'] ); $manifest['tutorials'][ $locale ] = $id;
	foreach ( array( 'about', 'contact', 'submit' ) as $kind ) {
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA ' . $kind . ( $zh ? ' 中文说明' : ' English information' ), 'post_content' => '<p>Explicit isolated QA fixture. 此页面只用于验收。</p>' ), true );
		if ( is_wp_error( $page ) ) { throw new RuntimeException( 'Could not create page fixture.' ); }
		update_post_meta( $page, '_aish_locale', $locale ); update_post_meta( $page, '_aish_page_kind', $kind ); $manifest['pages'][ $locale ][ $kind ] = $page;
	}
}
update_post_meta( $manifest['tutorials']['zh_CN'], '_aish_translation_id', $manifest['tutorials']['en_US'] );
update_post_meta( $manifest['tutorials']['en_US'], '_aish_translation_id', $manifest['tutorials']['zh_CN'] );
update_option( 'aish_browser_qa_fixtures', $manifest, false );
echo wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), "\n";
