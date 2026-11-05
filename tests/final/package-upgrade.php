<?php
declare(strict_types=1);

// Exercise WordPress's real update path, not a source-directory copy.
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_FINAL_QA' ) ) { exit( 2 ); }
define( 'WP_CLI', true );
require '/var/www/html/wp-load.php';
if ( 'qa_package' !== DB_NAME || 'ai-skills-hub' !== get_stylesheet() ) { throw new RuntimeException( 'Active disposable package QA theme required.' ); }
$archive = '/tmp/aish-package/ai-skills-hub-0.7.1.zip';
if ( ! is_file( $archive ) ) { throw new RuntimeException( 'Final 0.7.1 release ZIP is not ready.' ); }
$expected = getenv( 'AISH_QA_ZIP_SHA256' );
if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! hash_equals( $expected, hash_file( 'sha256', $archive ) ) ) { throw new RuntimeException( 'Explicit frozen ZIP checksum required.' ); }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
if ( ! current_user_can( 'update_themes' ) ) { throw new RuntimeException( 'QA administrator unavailable.' ); }
$requests = 0;
add_filter( 'pre_http_request', static function () use ( &$requests ): WP_Error { ++$requests; return new WP_Error( 'qa_external_request_forbidden', 'ZIP upgrade must not call external services.' ); }, 1 );
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
if ( 'direct' !== get_filesystem_method() ) { throw new RuntimeException( 'Run the disposable-site upgrader as its owning web user.' ); }
$previous = get_site_transient( 'update_themes' );
$update = new stdClass();
$update->last_checked = time();
$update->checked = array( 'ai-skills-hub' => wp_get_theme( 'ai-skills-hub' )->get( 'Version' ) );
$update->response = array( 'ai-skills-hub' => array( 'theme' => 'ai-skills-hub', 'new_version' => '0.7.1', 'url' => 'https://github.com/mtccv/Skills-ak', 'package' => $archive ) );
set_site_transient( 'update_themes', $update );
$skin = new Automatic_Upgrader_Skin();
$upgrader = new Theme_Upgrader( $skin );
try { $result = $upgrader->upgrade( 'ai-skills-hub' ); }
finally { if ( false === $previous ) { delete_site_transient( 'update_themes' ); } else { set_site_transient( 'update_themes', $previous ); } }
if ( true !== $result ) { throw new RuntimeException( 'Native Theme_Upgrader did not complete.' ); }
wp_clean_themes_cache();
$version = wp_get_theme( 'ai-skills-hub' )->get( 'Version' );
if ( '0.7.1' !== $version || $requests > 0 || ! hash_equals( $expected, hash_file( 'sha256', $archive ) ) ) { throw new RuntimeException( 'Unexpected version, changed ZIP, or outbound HTTP during package upgrade.' ); }
echo json_encode( array( 'method' => 'Theme_Upgrader::upgrade', 'status' => 'passed', 'time' => gmdate( 'c' ), 'database' => DB_NAME, 'previous_version' => $update->checked['ai-skills-hub'], 'installed_version' => $version, 'archive_sha256' => hash_file( 'sha256', $archive ), 'outbound_http_requests' => $requests ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
