<?php
declare(strict_types=1);

/** Real WordPress/MySQL regression; only generated QA identities/attachments are changed. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE8_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }
require_once ABSPATH . 'wp-admin/includes/user.php';

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Accounts\Store;

$core = Core::boot();
$accounts = $core->get( 'accounts' ); $privacy = $core->get( 'account_privacy' ); $avatar = $core->get( 'account_avatar' ); $db = $core->get( 'database' );
$run = bin2hex( random_bytes( 6 ) ); $password = 'Privacy-QA-' . bin2hex( random_bytes( 16 ) );
$checks = 0; $cases = array(); $users = array(); $posts = array();
add_filter( 'pre_wp_mail', static fn() => true ); // Explicit Mock transport: no real mail is sent.
function pg_assert( bool $condition, string $label ): void { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $label ); } }
function pg_reject( callable $fn, string $label ): void { try { $fn(); } catch ( Throwable $error ) { pg_assert( true, $label ); return; } throw new RuntimeException( $label ); }
function pg_case( string $id, callable $fn ): void { global $cases; try { $fn(); $cases[] = array( 'id' => $id, 'status' => 'passed' ); } catch ( Throwable $error ) { $cases[] = array( 'id' => $id, 'status' => 'failed', 'reason' => $error->getMessage() ); } }
function pg_user( string $suffix ): int {
	global $run, $password, $users;
	wp_set_current_user( 1 );
	$id = wp_insert_user( array( 'user_login' => 'p8privacy_' . $run . $suffix, 'user_email' => 'p8privacy-' . $run . $suffix . '@example.invalid', 'user_pass' => $password, 'display_name' => 'Private QA account', 'role' => 'subscriber', 'user_url' => 'https://example.invalid/private-profile/' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'QA user could not be created.' ); }
	$users[] = (int) $id;
	update_user_meta( $id, '_aish_account_state', 'active' ); update_user_meta( $id, '_aish_email_verified_at', gmdate( 'Y-m-d H:i:s' ) ); update_user_meta( $id, '_aish_verified_email_hash', Store::emailHash( get_userdata( $id )->user_email ) );
	update_user_meta( $id, 'description', 'Private profile biography' );
	return (int) $id;
}

$first = pg_user( 'first' ); $other = pg_user( 'other' );

pg_case( 'PRIVACY-EMAIL-CHANGE-INVALIDATES-NATIVE-REQUEST', function () use ( $first, $privacy, $password, $run, &$posts ): void {
	wp_set_current_user( $first );
	$request = $privacy->request( 'export', $password ); $posts[] = $request;
	pg_assert( 0 === (int) get_post( $request )->post_author, 'Native request cannot be reassigned with user content.' );
	pg_assert( 'authenticated_password' === get_post_meta( $request, '_aish_request_confirmed_by', true ), 'Confirmation mechanism is explicitly recorded.' );
	wp_set_current_user( 1 );
	$result = wp_update_user( array( 'ID' => $first, 'user_email' => 'p8privacy-' . $run . '-changed@example.invalid' ) );
	pg_assert( ! is_wp_error( $result ), 'Native authorized email update succeeds.' );
	$post = get_post( $request );
	pg_assert( 'request-failed' === $post->post_status && '' === $post->post_title && '' === $post->post_content, 'Old email request is invalidated and cannot target a recycled address.' );
} );

pg_case( 'PRIVACY-RECYCLED-EMAIL-DOES-NOT-RETARGET', function () use ( $first, $other, $privacy, &$posts ): void {
	wp_set_current_user( 1 ); $email = get_userdata( $other )->user_email;
	foreach ( array( 'export_personal_data', 'remove_personal_data' ) as $action ) {
		$id = wp_insert_post( array( 'post_type' => 'user_request', 'post_status' => 'request-confirmed', 'post_name' => $action, 'post_title' => $email, 'post_author' => 0 ) ); $posts[] = $id;
		update_post_meta( $id, '_aish_request_user_id', (string) $first );
	}
	pg_reject( fn() => $privacy->exporter( $email ), 'Export rejects a different pinned account.' );
	pg_reject( fn() => $privacy->eraser( $email ), 'Erase rejects a different pinned account before deleting data.' );
	pg_assert( 'Private profile biography' === get_user_meta( $other, 'description', true ), 'Recycled email owner data is intact.' );
	foreach ( $posts as $id ) { if ( get_post( $id ) && get_post( $id )->post_title === $email ) { wp_delete_post( $id, true ); } }
} );

pg_case( 'PRIVACY-NATIVE-EXACT-REQUEST-BINDING', function () use ( $first, $other, $privacy, &$posts ): void {
	wp_set_current_user( 1 ); $email = get_userdata( $other )->user_email;
	$id = wp_create_user_request( $email, 'export_personal_data', array(), 'confirmed' );
	pg_assert( is_int( $id ) && $id > 0, 'Native privacy request creation works.' ); $posts[] = $id;
	pg_assert( (string) $other === get_post_meta( $id, '_aish_request_user_id', true ), 'Native request creation pins the original user ID.' );
	$_POST = array( 'id' => (string) $id, 'action' => 'wp-privacy-export-personal-data' );
	try {
		pg_assert( ! empty( $privacy->exporter( $email )['data'] ), 'Exact confirmed native request exports its original account.' );
		delete_post_meta( $id, '_aish_request_user_id' );
		pg_reject( fn() => $privacy->exporter( $email ), 'Historical unbound native request is rejected before account projection.' );
		update_post_meta( $id, '_aish_request_user_id', (string) $first );
		pg_reject( fn() => $privacy->exporter( $email ), 'Exact stale request cannot resolve a new owner of its email.' );
		$callbacks = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		pg_assert( is_wp_error( $callbacks['ai-skills-hub-account']['callback']( $email ) ), 'Native callback converts identity conflict to a safe WP_Error, not a fatal response.' );
		update_post_meta( $id, '_aish_request_user_id', (string) $other );
	} finally { $_POST = array(); }
} );

pg_case( 'PRIVACY-AVATAR-TRANSACTIONAL-NATIVE-TABLES', function () use ( $privacy, $avatar, $db, $run ): void {
	$native = $db->connection(); $original = $native->posts; $probe = 'p8_privacy_engine_' . $run;
	$db->execute( "CREATE TABLE `$probe` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MyISAM" );
	try {
		pg_assert( 'MyISAM' === $db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $probe ) ), 'The isolated engine probe is genuinely nontransactional.' );
		$native->posts = $probe;
		pg_reject( fn() => ( new ReflectionMethod( $avatar, 'nativeReady' ) )->invoke( $avatar ), 'Avatar rejects a nontransactional native posts table.' );
		pg_reject( fn() => ( new ReflectionMethod( $privacy, 'nativeReady' ) )->invoke( $privacy ), 'Privacy rejects a nontransactional native posts table.' );
	} finally { $native->posts = $original; $db->execute( "DROP TABLE `$probe`" ); }
} );

$attachment = 0;
pg_case( 'AVATAR-PRIVATE-REST-AND-NO-GRAVATAR', function () use ( $other, $avatar, &$attachment, &$posts ): void {
	wp_set_current_user( 1 );
	$image = imagecreatetruecolor( 2, 2 ); ob_start(); imagepng( $image ); $bytes = ob_get_clean(); imagedestroy( $image );
	$attachment = wp_insert_attachment( array( 'post_type' => 'attachment', 'post_author' => $other, 'post_status' => 'private', 'post_title' => 'Private account avatar', 'post_mime_type' => 'image/png' ), false, 0, true );
	pg_assert( is_int( $attachment ) && $attachment > 0, 'Private native attachment fixture exists.' ); $posts[] = $attachment;
	update_post_meta( $attachment, '_aish_avatar_owner', (string) $other ); update_post_meta( $attachment, '_aish_avatar_bytes', base64_encode( $bytes ) ); update_user_meta( $other, '_aish_avatar_attachment_id', $attachment );
	wp_set_current_user( $other ); pg_assert( str_contains( $avatar->url(), 'aish_account_avatar' ), 'Owner sees only the authenticated avatar endpoint.' );
	wp_set_current_user( 0 );
	pg_assert( '' === $avatar->url( $other ), 'Other session cannot obtain avatar URL.' );
	$data = get_avatar_data( get_userdata( $other )->user_email ); pg_assert( isset( $data['url'] ) && '' === $data['url'], 'Email-based native avatar calls do not produce a Gravatar URL.' );
	wp_set_current_user( 1 );
	$single = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media/' . $attachment ) ); pg_assert( 404 === $single->get_status(), 'Even administrative REST cannot expose the private bytes attachment.' );
	$list = new WP_REST_Request( 'GET', '/wp/v2/media' ); $list->set_param( 'status', 'private' ); $list->set_param( 'context', 'edit' ); $list->set_param( 'per_page', 100 );
	$response = rest_do_request( $list ); pg_assert( 200 === $response->get_status(), 'Private REST media collections do not crash.' );
	pg_assert( ! in_array( $attachment, array_column( $response->get_data(), 'id' ), true ), 'Avatar is excluded from REST media collections.' );
} );

pg_case( 'AVATAR-OWNERSHIP-CONFLICT-NOT-DELETED', function () use ( $attachment, $first, $other, $avatar ): void {
	wp_set_current_user( 1 ); wp_update_post( array( 'ID' => $attachment, 'post_author' => $first ) );
	pg_reject( fn() => $avatar->purgeOwned( $other ), 'Author/owner mismatch is not blindly deleted.' );
	pg_assert( get_post( $attachment ) instanceof WP_Post, 'Conflicting attachment remains intact.' );
	wp_update_post( array( 'ID' => $attachment, 'post_author' => $other ) );
} );

pg_case( 'PRIVACY-ERASURE-PROFILE-BOUNDARY', function () use ( $other, $privacy, $accounts ): void {
	wp_set_current_user( 1 ); $identity = get_userdata( $other );
	$result = $privacy->eraser( $identity->user_email );
	pg_assert( $result['done'] && $result['items_retained'], 'Completed product erasure honestly reports native identity retention.' );
	pg_assert( '' === get_user_meta( $other, 'description', true ) && '' === get_userdata( $other )->user_url, 'Private biography and site URL are erased.' );
	pg_assert( get_userdata( $other )->user_login === $identity->user_login && get_userdata( $other )->display_name === $identity->display_name, 'Native identity and display name are not falsely deleted.' );
	pg_assert( 'suspended' === $accounts->state( $other ), 'Erased subscriber cannot restart private mail or interactions.' );
} );

pg_case( 'PRIVACY-NATIVE-DELETE-ISOLATION-AND-RECOVERY', function () use ( $first, $privacy, $accounts, $db ): void {
	wp_set_current_user( 1 ); $user = get_userdata( $first );
	$privacy->beforeDelete( $first, 1, $user );
	pg_assert( '1' === get_user_meta( $first, '_aish_account_deletion_pending', true ), 'Native deletion durably blocks new account work before core SQL.' );
	pg_assert( 'suspended' === $accounts->state( $first ), 'Deletion marker is an immediate state guard.' );
	pg_assert( wp_delete_user( $first, 1 ), 'Native deletion still succeeds after the staged hook.' );
	$native = $db->connection();
	$request = (int) $db->value( "SELECT post_id FROM {$native->postmeta} WHERE meta_key='_aish_cleanup_target' AND meta_value=%s LIMIT 1", array( (string) $first ) );
	pg_assert( $request > 0 && 0 === (int) get_post( $request )->post_author, 'Durable cleanup survives native reassignment.' );
	$privacy->tick();
	pg_assert( 'complete' === get_post_meta( $request, '_aish_cleanup_state', true ), 'Bounded cleanup resumes to completion.' );
	pg_assert( '' === get_post( $request )->post_content && '' === get_post_meta( $request, '_aish_cleanup_target', true ), 'Completed cleanup scrubs private identity payload and pointer.' );
} );

pg_case( 'PRIVACY-LEGACY-EMPTY-EMAIL-NATIVE-DELETION', function () use ( $privacy, $db ): void {
	$id = pg_user( 'legacyempty' ); $native = $db->connection();
	// Explicit historical fixture only: ordinary account creation must continue rejecting empty email.
	$db->execute( "UPDATE {$native->users} SET user_email='' WHERE ID=%d", array( $id ) ); clean_user_cache( $id );
	wp_set_current_user( 1 ); $privacy->beforeDelete( $id, 1, get_userdata( $id ) );
	$request = (int) $db->value( "SELECT post_id FROM {$native->postmeta} WHERE meta_key='_aish_cleanup_target' AND meta_value=%s LIMIT 1", array( (string) $id ) );
	pg_assert( $request > 0 && 'user_request' === get_post_type( $request ), 'Historical empty-email identity gets a durable native cleanup record.' );
	pg_assert( '' === get_post( $request )->post_title, 'Cleanup does not invent an email recipient.' );
	$payload = json_decode( get_post( $request )->post_content, true );
	pg_assert( (int) ( $payload['aish_user_id'] ?? 0 ) === $id && 'native_user_deletion' === ( $payload['aish_scope'] ?? '' ), 'Cleanup is pinned to the real historical user ID.' );
	pg_assert( wp_delete_user( $id, 1 ), 'Native user deletion with an empty legacy email succeeds.' );
	$privacy->tick();
	pg_assert( ! get_userdata( $id ) && 'complete' === get_post_meta( $request, '_aish_cleanup_state', true ), 'The same bounded cleanup finishes after native deletion.' );
	pg_assert( '' === get_post( $request )->post_content, 'Completed empty-email cleanup scrubs its private identity payload.' );
} );

wp_set_current_user( 1 );
foreach ( $users as $id ) { if ( get_userdata( $id ) ) { wp_delete_user( $id, 1 ); } }
$privacy->tick();
foreach ( $posts as $id ) { if ( get_post( $id ) ) { wp_delete_post( $id, true ); } }
$failed = count( array_filter( $cases, static fn( array $case ): bool => 'failed' === $case['status'] ) );
echo wp_json_encode( array( 'environment' => array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => 'real isolated MySQL', 'mail' => 'Mock only' ), 'cases' => $cases, 'assertions' => $checks, 'passed' => count( $cases ) - $failed, 'failed' => $failed ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failed ? 1 : 0 );
