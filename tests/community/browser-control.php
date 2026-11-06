<?php
declare(strict_types=1);

// Private stdin/stdout fixture bridge. Never expose this file through an HTTP route.
require __DIR__ . '/bootstrap.php';
if ( DB_NAME !== 'qa_browser' ) { exit( 2 ); }
$input = json_decode( (string) stream_get_contents( STDIN ), true );
if ( ! is_array( $input ) ) { exit( 3 ); }
$mode = $input['mode'] ?? '';
if ( 'seed' === $mode ) {
	$users = array();
	foreach ( array( 'author', 'reporter', 'moderator' ) as $role ) {
		$id = c9_user( 'browser-' . $role ); $password = 'Browser-QA-' . bin2hex( random_bytes( 24 ) ); wp_set_password( $password, $id );
		$user = get_userdata( $id );
		if ( 'moderator' === $role ) { $user->add_cap( 'aish_moderate_community' ); $user->add_cap( 'aish_manage_community_reports' ); }
		$users[ $role ] = array( 'id' => $id, 'email' => $user->user_email, 'login' => $user->user_login, 'password' => $password );
	}
	$skill = c9_skill( 'browser-' . ( $input['lang'] === 'zh' ? 'zh' : 'en' ) );
	$slug = $db->value( 'SELECT slug FROM ' . $db->table( 'skill_translations' ) . ' WHERE skill_id=%d AND locale=%s', array( $skill, 'en_US' ) );
	echo json_encode( array( 'skill' => $skill, 'slug' => $slug, 'users' => $users ) ); exit;
}
$skill = (int) ( $input['skill'] ?? 0 ); $user = (int) ( $input['user'] ?? 0 );
$identity = get_userdata( $user ); $slug = $db->value( 'SELECT slug FROM ' . $db->table( 'skill_translations' ) . ' WHERE skill_id=%d AND locale=%s', array( $skill, 'en_US' ) );
if ( ! $identity || ! preg_match( '/^p9_[a-f0-9]{16}_browser-(author|reporter|moderator)$/D', $identity->user_login ) || ! is_string( $slug ) || ! preg_match( '/^community-[a-f0-9]{16}-browser-(en|zh)$/D', $slug ) ) { exit( 4 ); }
if ( 'archive' === $mode ) { wp_set_current_user( $admin ); $management->archiveSkill( $skill, (int) $db->value( 'SELECT revision FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) ) ); }
$head = c9_head( $user, $skill );
echo json_encode( array( 'head' => $head ? array_intersect_key( $head, array_flip( array( 'id', 'lock_version', 'state', 'approved_revision_id', 'pending_revision_id' ) ) ) : null, 'favorites' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'favorites' ) . ' WHERE user_id=%d AND skill_id=%d AND is_active=1', array( $user, $skill ) ), 'reports' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'review_reports' ) . ' WHERE reporter_user_id=%d', array( $user ) ), 'corrections' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skill_reports' ) . ' WHERE reporter_user_id=%d AND skill_id=%d', array( $user, $skill ) ) ) );
