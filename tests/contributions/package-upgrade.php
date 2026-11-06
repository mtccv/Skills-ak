<?php
declare(strict_types=1);

/** Native ZIP upgrade against the dedicated disposable qa_package fixture only. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_PHASE10_QA' ) ) { exit( 2 ); }
$mode = getenv( 'AISH_PACKAGE_MODE' );
if ( ! in_array( $mode, array( 'upgrade', 'verify', 'reinstall' ), true ) ) { exit( 2 ); }
$archive = '/tmp/aish-phase10-package/ai-skills-hub-0.10.0.zip';
$snapshot = '/tmp/aish-phase10-package/before.json';
$expected = getenv( 'AISH_QA_ZIP_SHA256' );
if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! is_file( $archive ) || ! hash_equals( $expected, hash_file( 'sha256', $archive ) ) ) { exit( 4 ); }

// Preinitialized WordPress filters also protect theme bootstrap, before wp-load returns.
$requests = 0;
$mail = 0;
$GLOBALS['wp_filter']['pre_http_request'][1][] = array( 'function' => static function () use ( &$requests ): WP_Error { ++$requests; return new WP_Error( 'qa_external_request_forbidden', 'External requests disabled.' ); }, 'accepted_args' => 3 );
$GLOBALS['wp_filter']['pre_wp_mail'][1][] = array( 'function' => static function () use ( &$mail ): bool { ++$mail; return false; }, 'accepted_args' => 2 );
define( 'WP_CLI', true );
require '/var/www/html/wp-load.php';
if ( 'qa_package' !== DB_NAME || 'ai-skills-hub' !== get_stylesheet() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) { exit( 3 ); }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
if ( ! current_user_can( 'update_themes' ) ) { exit( 5 ); }

function contribution_package_fingerprints(): array {
	global $wpdb;
	$entities = array( 'repositories', 'skills', 'skill_translations', 'skill_versions', 'categories', 'tags', 'skill_categories', 'skill_tags', 'imports', 'jobs', 'import_logs', 'ai_jobs', 'ai_attempts', 'ai_reviews', 'email_verifications', 'user_notifications', 'notification_outbox', 'favorites', 'reviews', 'review_revisions', 'review_reports', 'skill_reports', 'workflow_events', 'metric_events', 'daily_metrics' );
	$data = array( 'schema' => get_option( 'aish_schema_version' ), 'theme' => wp_get_theme( 'ai-skills-hub' )->get( 'Version' ), 'tables' => array() );
	foreach ( $entities as $entity ) {
		$rows = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->prefix . 'aish_' . $entity . '`', ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'fixture_read_failed' ); }
		$hashes = array_map( static fn( array $row ): string => hash( 'sha256', (string) json_encode( $row ) ), $rows );
		sort( $hashes );
		$data['tables'][ $entity ] = array( 'rows' => count( $rows ), 'sha256' => hash( 'sha256', implode( '', $hashes ) ) );
	}
	$data['private_inbox_hash'] = hash( 'sha256', (string) wp_json_encode( array( $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type='aish_message' ORDER BY ID", ARRAY_A ), $wpdb->get_results( "SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='aish_message' ORDER BY m.meta_id", ARRAY_A ) ) ) );
	$data['private_messages'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='aish_message'" );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'inbox_read_failed' ); }
	return $data;
}

try {
	if ( in_array( $mode, array( 'upgrade', 'reinstall' ), true ) ) {
		$current = contribution_package_fingerprints();
		if ( 'upgrade' === $mode ) {
			if ( '0.9.0' !== $current['theme'] || '1.7.0' !== $current['schema'] || file_exists( $snapshot ) ) { throw new RuntimeException( 'original_fixture_required' ); }
			if ( false === file_put_contents( $snapshot, json_encode( $current, JSON_THROW_ON_ERROR ), LOCK_EX ) || ! chmod( $snapshot, 0600 ) ) { throw new RuntimeException( 'snapshot_failed' ); }
		} else {
			// Explicitly authorized same-version ZIP readback; never overwrite the original 0.9 snapshot.
			if ( '0.10.0' !== $current['theme'] || '1.8.0' !== $current['schema'] || ! is_file( $snapshot ) ) { throw new RuntimeException( 'upgraded_fixture_required' ); }
			$before = json_decode( (string) file_get_contents( $snapshot ), true, 512, JSON_THROW_ON_ERROR );
			if ( $before['tables'] !== $current['tables'] || $before['private_messages'] !== $current['private_messages'] ) { throw new RuntimeException( 'preservation_failed' ); }
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( 'direct' !== get_filesystem_method() ) { throw new RuntimeException( 'owning_web_user_required' ); }
		$previous = get_site_transient( 'update_themes' );
		$update = (object) array( 'last_checked' => time(), 'checked' => array( 'ai-skills-hub' => $current['theme'] ), 'response' => array( 'ai-skills-hub' => array( 'theme' => 'ai-skills-hub', 'new_version' => '0.10.0', 'url' => 'https://github.com/mtccv/Skills-ak', 'package' => $archive ) ) );
		set_site_transient( 'update_themes', $update );
		try { $result = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( 'ai-skills-hub' ); }
		finally { false === $previous ? delete_site_transient( 'update_themes' ) : set_site_transient( 'update_themes', $previous ); }
		wp_clean_themes_cache();
		if ( true !== $result || '0.10.0' !== wp_get_theme( 'ai-skills-hub' )->get( 'Version' ) || $requests || $mail ) { throw new RuntimeException( 'upgrade_failed' ); }
		echo json_encode( array( 'method' => 'Theme_Upgrader::upgrade', 'passed' => true, 'from' => $current['theme'], 'to' => '0.10.0', 'mode' => $mode, 'outbound_requests' => $requests, 'mail_calls' => $mail ), JSON_PRETTY_PRINT ) . "\n";
	} else {
		$before = json_decode( (string) file_get_contents( $snapshot ), true, 512, JSON_THROW_ON_ERROR );
		$after = contribution_package_fingerprints();
		$tables = AISkillsHub\Core\Database\Schema::COLUMNS;
		AISkillsHub\Core\Database\Schema::verify( AISkillsHub\Core\Core::boot()->get( 'database' ), '1.8.0' );
		$physical = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'aish_' ) . '%' ) );
		if ( $wpdb->last_error || 26 !== count( $physical ) || '0.9.0' !== $before['theme'] || '1.7.0' !== $before['schema'] || 25 !== count( $before['tables'] ) || '1.8.0' !== $after['schema'] || '0.10.0' !== $after['theme'] || 26 !== count( $tables ) || $before['tables'] !== $after['tables'] || $before['private_messages'] !== $after['private_messages'] || $before['private_inbox_hash'] !== $after['private_inbox_hash'] ) { throw new RuntimeException( 'preservation_failed' ); }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) ) { throw new RuntimeException( 'zip_failed' ); }
		$files = array();
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$name = $zip->getNameIndex( $i );
			if ( str_ends_with( $name, '/' ) ) { continue; }
			if ( ! str_starts_with( $name, 'ai-skills-hub/' ) || str_contains( $name, '..' ) ) { throw new RuntimeException( 'zip_path_failed' ); }
			$relative = substr( $name, strlen( 'ai-skills-hub/' ) );
			$path = get_theme_root() . '/' . $name;
			if ( ! is_file( $path ) || is_link( $path ) || ! hash_equals( hash( 'sha256', $zip->getFromIndex( $i ) ), hash_file( 'sha256', $path ) ) ) { throw new RuntimeException( 'installed_bytes_failed' ); }
			$files[] = $relative;
		}
		$zip->close();
		$installed = array();
		$root = get_stylesheet_directory();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isLink() ) { throw new RuntimeException( 'installed_symlink_failed' ); }
			if ( $file->isFile() ) { $installed[] = substr( $file->getPathname(), strlen( $root ) + 1 ); }
		}
		sort( $files );
		sort( $installed );
		if ( 144 !== count( $files ) || $files !== $installed || $requests || $mail ) { throw new RuntimeException( 'installed_manifest_failed' ); }
		echo json_encode( array( 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'database' => DB_NAME ), 'archive_sha256' => $expected, 'method' => 'Native Theme_Upgrader then fresh WordPress CLI bootstrap; no mail or external HTTP', 'previous_theme' => $before['theme'], 'installed_theme' => $after['theme'], 'schema_before' => $before['schema'], 'schema_after' => $after['schema'], 'tables_before' => count( $before['tables'] ), 'tables_after' => count( $physical ), 'original_table_fingerprints_unchanged' => true, 'original_rows' => array_sum( array_column( $before['tables'], 'rows' ) ), 'private_messages_before' => $before['private_messages'], 'private_messages_after' => $after['private_messages'], 'installed_files' => count( $files ), 'exact_archive_file_match' => true, 'outbound_requests' => $requests, 'mail_calls' => $mail, 'passed' => true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}
} catch ( Throwable $error ) {
	echo json_encode( array( 'passed' => false, 'failure' => $error instanceof RuntimeException ? $error->getMessage() : 'unexpected_error' ), JSON_PRETTY_PRINT ) . "\n";
	exit( 1 );
}
