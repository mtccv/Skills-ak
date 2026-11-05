<?php
declare(strict_types=1);

// Trusted CLI fixture/probe helper. Never install this file as a web endpoint.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( 'qa_security' !== DB_NAME || ! in_array( parse_url( (string) get_option( 'home' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'This suite requires the dedicated local qa_security database.' );
}

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Frontend\Forms;
use AISkillsHub\Core\Frontend\Editorial;
use AISkillsHub\Core\Frontend\PublicCatalog;
use AISkillsHub\Core\Security\Input;
use AISkillsHub\Core\Sources\GitHubClient;
use AISkillsHub\Core\AI\Settings;

$input = json_decode( stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
$mode = $input['mode'] ?? 'seed';
$core = Core::boot(); $db = $core->get( 'database' );
$result = array();
if ( 'nonce' === $mode ) {
	wp_set_current_user( (int) $input['user'] );
	// PHP decodes Set-Cookie values when building $_COOKIE; CookieJar retains
	// the wire encoding, so reproduce that decoding for the real HTTP session.
	$_COOKIE[ LOGGED_IN_COOKIE ] = rawurldecode( (string) $input['cookie'] );
	if ( (int) $input['user'] !== wp_validate_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ], 'logged_in' ) ) { throw new RuntimeException( 'The QA HTTP session is not valid.' ); }
	foreach ( $input['actions'] as $action ) { $result[ $action ] = wp_create_nonce( $action ); }
} elseif ( 'state' === $mode ) {
	foreach ( array( 'skills', 'skill_translations', 'repositories', 'categories', 'tags', 'skill_versions', 'jobs', 'ai_jobs' ) as $entity ) {
		$rows = $db->rows( 'SELECT * FROM ' . $db->table( $entity ) . ' ORDER BY id' );
		$result[ $entity ] = array( 'rows' => count( $rows ), 'digest' => hash( 'sha256', serialize( $rows ) ) );
	}
	foreach ( array( 'aish_admin_settings', 'aish_ai_profiles', 'aish_ai_paused', 'aish_seo_settings' ) as $option ) {
		$result['options'][ $option ] = hash( 'sha256', serialize( get_option( $option ) ) );
	}
	$result['admin_settings'] = get_option( 'aish_admin_settings' );
	$result['inbox'] = $wpdb->get_results( "SELECT ID,post_status,post_content FROM {$wpdb->posts} WHERE post_type='aish_message' ORDER BY ID", ARRAY_A );
} elseif ( 'archive' === $mode ) {
	wp_set_current_user( (int) $input['user'] );
	$row = $core->catalog()->getSkill( (int) $input['id'] );
	$core->management()->archiveSkill( (int) $input['id'], (int) $row['revision'] );
	$result = array( 'archived' => true, 'retained' => null !== $core->catalog()->getSkill( (int) $input['id'] ) );
} elseif ( 'probes' === $mode ) {
	$checks = array();
	$reject = static function ( string $label, callable $operation ) use ( &$checks ): void {
		try { $operation(); $checks[] = array( 'name' => $label, 'pass' => false ); }
		catch ( Throwable $error ) { $checks[] = array( 'name' => $label, 'pass' => $error instanceof InvalidArgumentException ); }
	};
	foreach ( array( '../SKILL.md', '/SKILL.md', 'a/../SKILL.md', 'a//SKILL.md', 'a\\SKILL.md', "a\0b", str_repeat( 'x', 769 ) ) as $index => $path ) {
		$reject( 'source_path_' . $index, static fn() => Input::sourcePath( $path ) );
	}
	foreach ( array( 'http://github.com/o/r', 'https://github.com.evil.example/o/r', 'https://user@github.com/o/r', 'https://github.com:443/o/r', 'https://github.com/o/r?url=http://127.0.0.1', 'https://127.0.0.1/o/r', 'file:///etc/passwd', 'https://github.com/o/../r' ) as $index => $url ) {
		$reject( 'repository_url_' . $index, static fn() => GitHubClient::repositoryIdentity( $url ) );
	}
	foreach ( array( 'http://api.openai.com/v1', 'https://127.0.0.1/v1', 'https://localhost/v1', 'https://user@api.openai.com/v1', 'https://api.openai.com:444/v1', 'https://api.openai.com/v1?url=private', 'https://untrusted.example/v1' ) as $index => $url ) {
		$reject( 'ai_base_url_' . $index, static fn() => Settings::validateBaseUrl( $url ) );
	}
	// Real WordPress HTTP URL validation. Only the controlled loopback is supplied;
	// no metadata host, private remote network, live provider or repository is requested.
	// Use a loopback host different from this QA site's own URL: WordPress
	// intentionally permits its configured home host in safe URL validation.
	$response = wp_safe_remote_get( 'http://127.0.0.2/aish-qa-must-not-be-requested', array( 'timeout' => 2, 'redirection' => 0 ) );
	$checks[] = array( 'name' => 'wordpress_safe_http_rejects_loopback', 'pass' => is_wp_error( $response ) && 'http_request_failed' === $response->get_error_code(), 'error_code' => is_wp_error( $response ) ? $response->get_error_code() : null );
	$public = new PublicCatalog( $db, $core->migrator() );
	foreach ( array( array( 'sort' => 'id;DROP TABLE users' ), array( 'category' => "x' OR 1=1 --" ), array( 'page' => '1 OR 1=1' ), array( 'per_page' => 100000 ), array( 'platform' => 'unprefixed' ) ) as $index => $filters ) {
		$reject( 'public_query_validation_' . $index, static fn() => $public->skills( 'en_US', $filters ) );
	}
	// A harmless synthetic secret-shaped value makes an actual duplicate-key
	// MySQL error. Verify both the DAL exception and this site's debug log;
	// never supply a real API credential or print the raw driver error.
	$canary = 'qa-secret-canary-' . bin2hex( random_bytes( 12 ) );
	$row = array( 'name' => 'Synthetic SQL error/log QA fixture', 'slug' => $canary, 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
	$db->insert( 'tags', $row );
	$log = WP_CONTENT_DIR . '/debug.log'; clearstatcache( true, $log ); $offset = is_file( $log ) ? (int) filesize( $log ) : 0;
	try { $db->insert( 'tags', $row ); $checks[] = array( 'name' => 'mysql_error_is_non_disclosing', 'pass' => false ); }
	catch ( Throwable $error ) { $checks[] = array( 'name' => 'mysql_error_is_non_disclosing', 'pass' => $error instanceof RuntimeException && ! str_contains( $error->getMessage(), $canary ) && null === $error->getPrevious() ); }
	$written = is_file( $log ) ? file_get_contents( $log, false, null, $offset, 65536 ) : '';
	$checks[] = array( 'name' => 'mysql_error_does_not_log_synthetic_secret', 'pass' => is_string( $written ) && ! str_contains( $written, $canary ) );
	$result = array( 'kind' => 'real_wordpress_validation_and_mysql_reads', 'checks' => $checks, 'passed' => count( array_filter( $checks, static fn( array $check ): bool => $check['pass'] ) ), 'total' => count( $checks ) );
} elseif ( 'seed' === $mode ) {
	$password = $input['password'] ?? '';
	if ( ! is_string( $password ) || strlen( $password ) < 24 ) { throw new RuntimeException( 'Supply an ephemeral QA password over stdin.' ); }
	$users = array();
	foreach ( array( 'administrator', 'subscriber', 'contributor', 'author', 'editor' ) as $role ) {
		$login = 'aish-security-' . $role; $user = get_user_by( 'login', $login );
		$id = $user ? $user->ID : wp_create_user( $login, $password, $login . '@example.invalid' );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'QA user creation failed.' ); }
		wp_set_password( $password, (int) $id ); ( new WP_User( $id ) )->set_role( $role );
		$users[ $role ] = array( 'id' => (int) $id, 'login' => $login );
	}
	wp_set_current_user( $users['administrator']['id'] ); do_action( 'admin_init' );
	if ( ! $core->migrator()->isCurrent() ) { throw new RuntimeException( 'Core migration is not current.' ); }
	$run = 'qa-' . bin2hex( random_bytes( 4 ) );
	$ids = array(); $markers = array();
	foreach ( array( 'published', 'draft', 'pending', 'rejected', 'archived', 'stale', 'unapproved', 'no_reviewer', 'no_review_time', 'partial', 'xss', 'withdrawal' ) as $kind ) {
		$marker = strtoupper( str_replace( '-', '_', $run ) . '_' . $kind ); $markers[ $kind ] = $marker;
		$content = '<p>' . $marker . ' real fixture instructions.</p>';
		if ( 'xss' === $kind ) { $content .= '<script>alert("aish-qa-xss")</script><img src="x" onerror="alert(1)"><svg onload="alert(1)"></svg><a href="javascript:alert(1)">dangerous</a>'; }
		$translation = array( 'title' => $marker, 'slug' => $run . '-' . str_replace( '_', '-', $kind ), 'summary' => $marker . ' summary', 'content' => $content, 'seo_title' => '', 'seo_description' => '' );
		$translations = array( 'en_US' => $translation, 'zh_CN' => array_replace( $translation, array( 'title' => '中文 ' . $marker, 'summary' => '中文简介 ' . $marker ) ) );
		if ( 'partial' === $kind ) { $translations['en_US']['content'] = '<p>&nbsp; &#x2003;</p>'; }
		$id = $core->management()->saveSkill( null, array( 'save_mode' => 'draft' === $kind ? 'draft' : 'pending', 'translations' => $translations ) );
		if ( ! in_array( $kind, array( 'draft', 'pending' ), true ) ) {
			$core->management()->review( $id, 'rejected' === $kind ? 'rejected' : 'approved', 'unknown', 'Explicit synthetic Final QA fixture; not a safety certification.', 1 );
		}
		if ( 'archived' === $kind ) { $core->management()->archiveSkill( $id, 2 ); }
		// Deliberate inconsistent historical rows in this isolated QA database test
		// fail-closed public reads. They are never application mutation examples.
		if ( 'stale' === $kind ) { $db->updateById( 'skills', $id, array( 'revision' => 3 ) ); }
		if ( 'unapproved' === $kind ) { $db->updateById( 'skills', $id, array( 'review_status' => 'pending' ) ); }
		if ( 'no_reviewer' === $kind ) { $db->updateById( 'skills', $id, array( 'reviewer_id' => 0 ) ); }
		if ( 'no_review_time' === $kind ) { $db->updateById( 'skills', $id, array( 'reviewed_at' => null ) ); }
		$ids[ $kind ] = $id;
	}
	$tutorials = array();
	foreach ( array( 'publish', 'draft', 'private', 'password' ) as $status ) {
		$id = wp_insert_post( array( 'post_type' => Editorial::TUTORIAL, 'post_status' => 'password' === $status ? 'publish' : $status, 'post_password' => 'password' === $status ? 'qa-password' : '', 'post_title' => $run . ' tutorial ' . $status, 'post_content' => '<p>' . $run . ' TUTORIAL_' . $status . '</p>', 'post_excerpt' => $run . ' excerpt', 'meta_input' => array( '_aish_locale' => 'en_US' ) ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Tutorial fixture failed.' ); }
		$tutorials[ $status ] = (int) $id;
	}
	$inbox = wp_insert_post( array( 'post_type' => Forms::POST_TYPE, 'post_status' => 'publish', 'post_title' => $run . ' inbox', 'post_content' => $run . '_PRIVATE_INBOX' ), true );
	if ( is_wp_error( $inbox ) ) { throw new RuntimeException( 'Inbox fixture failed.' ); }
	$result = array( 'run' => $run, 'users' => $users, 'skills' => $ids, 'markers' => $markers, 'tutorials' => $tutorials, 'inbox' => $inbox, 'site' => get_option( 'home' ), 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ) );
	update_option( 'aish_final_qa_security', $result, false );
} else { throw new RuntimeException( 'Unknown QA helper mode.' ); }
echo json_encode( $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";
