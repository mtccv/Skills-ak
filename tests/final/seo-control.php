<?php
declare(strict_types=1);

// Test-only mutations on the disposable SEO QA database, never a deployment utility.
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_FINAL_QA' ) ) { exit( 1 ); }
require ( getenv( 'AISH_QA_WP_ROOT' ) ?: '/var/www/html' ) . '/wp-load.php';
if ( 'qa_seo' !== DB_NAME || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Refusing a non-QA site.' ); }
wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
$action = $argv[1] ?? ''; $result = array( 'action' => $action, 'ok' => true );
if ( str_starts_with( $action, 'plugins-' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugins = array( 'yoast' => 'wordpress-seo/wp-seo.php', 'rankmath' => 'seo-by-rank-math/rank-math.php' );
	$mode = substr( $action, 8 );
	if ( ! in_array( $mode, array( 'none', 'yoast', 'rankmath', 'both' ), true ) ) { throw new InvalidArgumentException( 'Invalid QA plugin mode.' ); }
	deactivate_plugins( array_values( $plugins ) );
	if ( in_array( $mode, array( 'rankmath', 'both' ), true ) ) {
		// The free plugin's public Skip wizard action sets this same option.
		update_option( 'rank_math_registration_skip', true );
		update_option( 'rank_math_modules', array( 'sitemap', 'schema' ) );
	}
	$result['plugins'] = array();
	foreach ( $plugins as $key => $plugin ) {
		if ( $key !== $mode && 'both' !== $mode ) { continue; }
		$activated = activate_plugin( $plugin );
		if ( is_wp_error( $activated ) ) { $result['ok'] = false; $result['plugins'][ $key ] = array( 'error' => $activated->get_error_code(), 'message' => $activated->get_error_message() ); }
		else { $result['plugins'][ $key ] = array( 'version' => get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false )['Version'], 'active' => is_plugin_active( $plugin ) ); }
	}
} elseif ( in_array( $action, array( 'site-private', 'site-public' ), true ) ) {
	update_option( 'blog_public', 'site-public' === $action ? '1' : '0' );
} elseif ( in_array( $action, array( 'default-en', 'default-zh' ), true ) ) {
	$settings = get_option( 'aish_admin_settings', array() ); $settings['default_locale'] = 'default-en' === $action ? 'en_US' : 'zh_CN'; update_option( 'aish_admin_settings', $settings, false );
} elseif ( in_array( $action, array( 'pair-archive', 'pair-restore', 'pair-edit-withdraw' ), true ) ) {
	$fixture = get_option( '_aish_final_seo_fixture' ); $id = (int) $fixture['skills']['pair']['id'];
	$management = \AISkillsHub\Core\Core::boot()->management(); $bundle = $management->skill( $id ); $revision = (int) $bundle['skill']['revision'];
	if ( 'pair-edit-withdraw' === $action ) {
		$translations = $bundle['translations'];
		$translations['en_US']['content'] .= '<p>QA_SEO_UNREVIEWED_EDIT</p>';
		$management->saveSkill( $id, array( 'revision' => $revision, 'save_mode' => 'draft', 'repository_id' => $bundle['skill']['repository_id'], 'source_path' => $bundle['skill']['source_path'], 'translations' => $translations, 'category_ids' => $bundle['categories'], 'tag_ids' => $bundle['tags'] ) );
	} elseif ( 'pair-archive' === $action ) { $management->archiveSkill( $id, $revision ); }
	else { $management->changeStatus( $id, 'pending', $revision ); $management->review( $id, 'approved', 'low', 'QA restore after publication invalidation check.', $revision + 1 ); }
} else { throw new InvalidArgumentException( 'Unknown QA action.' ); }
if ( str_starts_with( $action, 'plugins-' ) ) { flush_rewrite_rules( true ); }
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
