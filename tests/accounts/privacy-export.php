<?php
declare(strict_types=1);

/** Real native ZIP generation + real HTTP downloads; transport email is explicitly mocked. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE8_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
require_once ABSPATH . 'wp-admin/includes/privacy-tools.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Accounts\PrivacyExports;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Store;

$core = Core::boot(); $db = $core->get( 'database' ); $privacy = $core->get( 'account_privacy' );
$exports = new PrivacyExports( $db, new Locks( $db ) );
$checks = 0; $run = bin2hex( random_bytes( 6 ) ); $users = array(); $request = 0; $failure = null;
$capturedMail = array();
add_filter( 'pre_wp_mail', static function ( mixed $result, array $message ) use ( &$capturedMail ): bool { $capturedMail[] = $message; return true; }, 100, 2 );
if ( ! has_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file' ) ) { add_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file', 10 ); }
function pe_check( bool $condition, string $label ): void { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $label ); } }
function pe_reject( callable $fn, string $label ): void { try { $fn(); } catch ( Throwable $error ) { pe_check( true, $label ); return; } throw new RuntimeException( $label ); }
function pe_http( string $path, int $userId = 0 ): array {
	$headers = array();
	if ( $userId ) {
		$token = WP_Session_Tokens::get_instance( $userId )->create( time() + 600 );
		$headers['Cookie'] = AUTH_COOKIE . '=' . wp_generate_auth_cookie( $userId, time() + 600, 'auth', $token ) . '; ' . LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie( $userId, time() + 600, 'logged_in', $token );
	}
	$result = wp_remote_get( 'http://127.0.0.1' . $path, array( 'headers' => $headers, 'timeout' => 15, 'redirection' => 0 ) );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Local HTTP test is unavailable.' ); }
	return $result;
}

try {
	wp_set_current_user( 1 );
	foreach ( array( 'owner', 'other' ) as $suffix ) {
		$id = wp_insert_user( array( 'user_login' => 'p8export_' . $run . $suffix, 'user_email' => 'p8export-' . $run . $suffix . '@example.invalid', 'user_pass' => bin2hex( random_bytes( 24 ) ), 'role' => 'subscriber' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Export QA account creation failed.' ); }
		$users[] = (int) $id;
		update_user_meta( $id, '_aish_account_state', 'active' ); update_user_meta( $id, '_aish_email_verified_at', gmdate( 'Y-m-d H:i:s' ) ); update_user_meta( $id, '_aish_verified_email_hash', Store::emailHash( get_userdata( $id )->user_email ) );
	}
	$owner = $users[0]; $other = $users[1];
	$request = wp_create_user_request( get_userdata( $owner )->user_email, 'export_personal_data', array(), 'confirmed' );
	pe_check( is_int( $request ) && $request > 0, 'Native export request created.' );
	update_post_meta( $request, '_export_data_grouped', array( 'aish-account' => array( 'group_label' => 'Account', 'items' => array( 'account-1' => array( array( 'name' => 'Private QA marker', 'value' => 'Private account export content ' . $run ) ) ) ) ) );
	do_action( 'wp_privacy_personal_data_export_file', $request );
	$name = get_post_meta( $request, '_aish_export_file', true );
	$path = $exports->authorizedFile( $request, $name );
	pe_check( str_starts_with( $path, PrivacyExports::directory() . '/' ) && ! str_starts_with( $path, ABSPATH ), 'Native reports and ZIP are written outside webroot.' );
	pe_check( ( fileperms( dirname( $path ) ) & 0777 ) === 0700 && ( fileperms( $path ) & 0777 ) === 0600, 'Private directory and archive permissions are enforced.' );
	pe_check( ! is_file( wp_upload_dir()['basedir'] . '/wp-personal-data-exports/' . $name ), 'No copy exists in public WordPress uploads.' );
	$zip = new ZipArchive(); pe_check( true === $zip->open( $path ), 'Native ZIP is readable.' );
	pe_check( str_contains( (string) $zip->getFromName( 'export.json' ), 'Private account export content ' . $run ), 'The ZIP contains the actual native export projection.' ); $zip->close();
	$url = wp_privacy_exports_url() . $name;
	pe_check( str_contains( $url, 'action=aish_account_export' ) && ! str_contains( $url, get_userdata( $owner )->user_email ), 'Native export links use authenticated route with opaque filename.' );
	$parts = wp_parse_url( $url ); $endpoint = $parts['path'] . '?' . $parts['query'];
	$http = pe_http( $endpoint, $owner );
	pe_check( 200 === wp_remote_retrieve_response_code( $http ) && str_starts_with( wp_remote_retrieve_body( $http ), 'PK' ), 'Owner can download through a real HTTP authenticated request.' );
	pe_check( str_contains( (string) wp_remote_retrieve_header( $http, 'cache-control' ), 'no-store' ), 'HTTP export response is never shared-cacheable.' );
	pe_check( 404 === wp_remote_retrieve_response_code( pe_http( $endpoint, $other ) ), 'Another authenticated user cannot download.' );
	pe_check( 404 === wp_remote_retrieve_response_code( pe_http( $endpoint ) ), 'Anonymous user cannot download.' );
	// Simulate a later native resend with no generation context left in this request.
	$allHooks = $GLOBALS['wp_filter']['wp_privacy_personal_data_export_file']->callbacks[1] ?? array();
	foreach ( $allHooks as $hook ) { $callable = $hook['function']; if ( is_array( $callable ) && $callable[0] instanceof PrivacyExports ) { ( new ReflectionProperty( $callable[0], 'context' ) )->setValue( $callable[0], null ); } }
	foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
		update_user_meta( $owner, '_aish_profile_locale', $locale );
		pe_check( true === wp_privacy_send_personal_data_export_email( $request ), 'Native resend reports only Mock transport acceptance.' );
		$message = end( $capturedMail );
		pe_check( str_contains( $message['message'], 'action=aish_account_export' ) && ! str_contains( $message['message'], '/wp-personal-data-exports/' ), 'Separate-context native resend always uses the authenticated URL.' );
		pe_check( str_contains( $message['message'], 'zh_CN' === $locale ? '请先登录' : 'Sign in to your own' ), 'Native export email follows the account language.' );
	}
	// Mock short-circuit does not emit wp_mail_succeeded; model request-end lock release.
	do_action( 'shutdown' );
	update_post_meta( $request, '_aish_export_expires_at', (string) ( time() - 1 ) );
	pe_check( 404 === wp_remote_retrieve_response_code( pe_http( $endpoint, $owner ) ), 'Expired export is rejected by real HTTP endpoint.' );
	update_post_meta( $request, '_aish_export_expires_at', (string) ( time() + 600 ) );
	pe_reject( fn() => $exports->authorizedFile( $request, '../wp-config.php' ), 'Request cannot choose a filesystem path.' );
	pe_reject( fn() => $exports->authorizedFile( $request, 'aish-' . str_repeat( '0', 64 ) . '.zip' ), 'Another opaque filename is rejected.' );
	// Cache priming cannot preserve download eligibility after identity changes.
	$exports->authorizedFile( $request, $name );
	wp_update_user( array( 'ID' => $owner, 'user_email' => 'p8export-' . $run . '-changed@example.invalid' ) );
	pe_reject( fn() => $exports->authorizedFile( $request, $name ), 'Email change invalidates a previously cached export identity.' );
	pe_check( ! is_file( $path ), 'Identity change removes the old private archive.' );
} catch ( Throwable $error ) { $failure = $error->getMessage(); }
finally {
	wp_set_current_user( 1 );
	if ( is_int( $request ) && $request > 0 ) { PrivacyExports::invalidate( $request ); wp_delete_post( $request, true ); }
	foreach ( $users as $id ) { if ( get_userdata( $id ) ) { wp_delete_user( $id, 1 ); } }
	$privacy->tick();
}
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'environment' => 'real WordPress/MySQL/native ZIP and HTTP; Mock mail only', 'assertions' => $checks, 'passed' => null === $failure ? 1 : 0, 'failed' => null === $failure ? 0 : 1, 'failure' => $failure ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure ? 0 : 1 );
