<?php
declare(strict_types=1);

// Private CLI bridge for browser assertions; never exposed as an HTTP route.
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE8_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( DB_NAME !== 'qa_browser' ) { exit( 2 ); }
$input = json_decode( (string) stream_get_contents( STDIN ), true );
if ( ! is_array( $input ) || ! is_string( $input['email'] ?? null ) || ! preg_match( '/^p8-browser-[a-f0-9]+-(zh|en)@example\.invalid$/D', $input['email'] ) ) { exit( 3 ); }
$user = get_user_by( 'email', $input['email'] );
if ( ! $user instanceof WP_User ) { echo json_encode( array( 'exists' => false ) ); exit; }
$core = AISkillsHub\Core\Core::boot(); $store = $core->get( 'account_store' ); $db = $core->get( 'database' );
$output = array( 'exists' => true, 'id' => (int) $user->ID, 'display_name' => $user->display_name, 'roles' => $user->roles, 'state' => $core->get( 'accounts' )->state( (int) $user->ID ), 'verified' => $core->get( 'accounts' )->verified( (int) $user->ID ), 'user_nicename' => $user->user_nicename, 'avatar' => (int) get_user_meta( $user->ID, '_aish_avatar_attachment_id', true ) );
if ( in_array( $input['mode'] ?? '', array( 'verification', 'reset' ), true ) ) {
	$template = 'reset' === $input['mode'] ? 'password_reset' : 'verify_email';
	$row = $db->row( 'SELECT * FROM ' . $db->table( 'notification_outbox' ) . ' WHERE recipient_user_id=%d AND template_key=%s ORDER BY id DESC LIMIT 1', array( $user->ID, $template ) );
	if ( $row && $row['payload_ciphertext'] ) { $output['payload'] = $store->decryptPayload( $row ); }
}
echo json_encode( $output );
