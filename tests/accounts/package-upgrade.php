<?php
declare(strict_types=1);

// Run only against the existing disposable, writable ZIP fixture; never a business site.
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_PHASE8_QA' ) ) { exit( 2 ); }
define( 'WP_CLI', true );
require '/var/www/html/wp-load.php';
if ( 'qa_package' !== DB_NAME || 'ai-skills-hub' !== get_stylesheet() ) { exit( 3 ); }
$archive = '/tmp/aish-phase8-package/ai-skills-hub-0.8.0.zip';
$snapshot = '/tmp/aish-phase8-package/before.json';
$expected = getenv( 'AISH_QA_ZIP_SHA256' );
if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! is_file( $archive ) || ! hash_equals( $expected, hash_file( 'sha256', $archive ) ) ) { exit( 4 ); }
$requests = 0; $mail = 0;
add_filter( 'pre_http_request', static function () use ( &$requests ): WP_Error { ++$requests; return new WP_Error( 'qa_external_request_forbidden', 'External requests disabled.' ); }, 1 );
add_filter( 'pre_wp_mail', static function () use ( &$mail ): bool { ++$mail; return false; }, 1 );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
if ( ! current_user_can( 'update_themes' ) ) { exit( 5 ); }

function package_fingerprints(): array {
	global $wpdb;
	$entities = array( 'repositories', 'skills', 'skill_translations', 'skill_versions', 'categories', 'tags', 'skill_categories', 'skill_tags', 'imports', 'jobs', 'import_logs', 'ai_jobs', 'ai_attempts', 'ai_reviews' );
	$data = array( 'schema' => get_option( 'aish_schema_version' ), 'theme' => wp_get_theme( 'ai-skills-hub' )->get( 'Version' ), 'tables' => array() );
	foreach ( $entities as $entity ) {
		$rows = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->prefix . 'aish_' . $entity . '`', ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'fixture_read_failed' ); }
		$hashes = array_map( static fn( array $row ): string => hash( 'sha256', (string) json_encode( $row ) ), $rows ); sort( $hashes );
		$data['tables'][ $entity ] = array( 'rows' => count( $rows ), 'sha256' => hash( 'sha256', implode( '', $hashes ) ) );
	}
	$data['private_messages'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='aish_message'" );
	return $data;
}

try {
	if ( 'upgrade' === getenv( 'AISH_PACKAGE_MODE' ) ) {
		$before = package_fingerprints();
		if ( '0.7.1' !== $before['theme'] || '1.5.0' !== $before['schema'] || file_exists( $snapshot ) ) { throw new RuntimeException( 'original_fixture_required' ); }
		if ( false === file_put_contents( $snapshot, json_encode( $before, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'snapshot_failed' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( 'direct' !== get_filesystem_method() ) { throw new RuntimeException( 'owning_web_user_required' ); }
		$previous = get_site_transient( 'update_themes' );
		$update = (object) array( 'last_checked' => time(), 'checked' => array( 'ai-skills-hub' => $before['theme'] ), 'response' => array( 'ai-skills-hub' => array( 'theme' => 'ai-skills-hub', 'new_version' => '0.8.0', 'url' => 'https://github.com/mtccv/Skills-ak', 'package' => $archive ) ) );
		set_site_transient( 'update_themes', $update );
		try { $result = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( 'ai-skills-hub' ); }
		finally { false === $previous ? delete_site_transient( 'update_themes' ) : set_site_transient( 'update_themes', $previous ); }
		wp_clean_themes_cache();
		if ( true !== $result || '0.8.0' !== wp_get_theme( 'ai-skills-hub' )->get( 'Version' ) || $requests || $mail ) { throw new RuntimeException( 'upgrade_failed' ); }
		echo json_encode( array( 'method' => 'Theme_Upgrader::upgrade', 'passed' => true, 'from' => '0.7.1', 'to' => '0.8.0', 'outbound_requests' => $requests, 'mail_calls' => $mail ), JSON_PRETTY_PRINT ) . "\n";
	} elseif ( 'verify' === getenv( 'AISH_PACKAGE_MODE' ) ) {
		$before = json_decode( (string) file_get_contents( $snapshot ), true, 512, JSON_THROW_ON_ERROR );
		$after = package_fingerprints();
		$tables = AISkillsHub\Core\Database\Schema::COLUMNS;
		AISkillsHub\Core\Database\Schema::verify( AISkillsHub\Core\Core::boot()->get( 'database' ), '1.6.0' );
		if ( '1.6.0' !== $after['schema'] || '0.8.0' !== $after['theme'] || 17 !== count( $tables ) || $before['tables'] !== $after['tables'] || $before['private_messages'] !== $after['private_messages'] ) { throw new RuntimeException( 'preservation_failed' ); }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) ) { throw new RuntimeException( 'zip_failed' ); }
		$files = array();
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$name = $zip->getNameIndex( $i ); if ( str_ends_with( $name, '/' ) ) { continue; }
			if ( ! str_starts_with( $name, 'ai-skills-hub/' ) || str_contains( $name, '..' ) ) { throw new RuntimeException( 'zip_path_failed' ); }
			$relative = substr( $name, strlen( 'ai-skills-hub/' ) );
			$path = get_theme_root() . '/' . $name;
			if ( ! is_file( $path ) || is_link( $path ) || ! hash_equals( hash( 'sha256', $zip->getFromIndex( $i ) ), hash_file( 'sha256', $path ) ) ) { throw new RuntimeException( 'installed_bytes_failed' ); }
			$files[] = $relative;
		}
		$zip->close(); $installed = array();
		$root = get_stylesheet_directory();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) { if ( $file->isFile() ) { $installed[] = substr( $file->getPathname(), strlen( $root ) + 1 ); } }
		sort( $files ); sort( $installed );
		if ( $files !== $installed || $requests || $mail ) { throw new RuntimeException( 'installed_manifest_failed' ); }
		echo json_encode( array( 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'database' => DB_NAME ), 'archive_sha256' => $expected, 'method' => 'Native Theme_Upgrader then fresh WordPress request', 'previous_theme' => $before['theme'], 'installed_theme' => $after['theme'], 'schema_before' => $before['schema'], 'schema_after' => $after['schema'], 'tables_after' => count( $tables ), 'original_table_fingerprints_unchanged' => true, 'original_rows' => array_sum( array_column( $before['tables'], 'rows' ) ), 'private_messages_before' => $before['private_messages'], 'private_messages_after' => $after['private_messages'], 'installed_files' => count( $files ), 'exact_archive_file_match' => true, 'outbound_requests' => $requests, 'mail_calls' => $mail, 'passed' => true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	} else { throw new RuntimeException( 'mode_required' ); }
} catch ( Throwable $error ) {
	echo json_encode( array( 'passed' => false, 'failure' => $error instanceof RuntimeException ? $error->getMessage() : 'unexpected_error' ), JSON_PRETTY_PRINT ) . "\n";
	exit( 1 );
}
