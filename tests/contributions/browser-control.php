<?php
declare(strict_types=1);
// Private stdin bridge. CLI-only and isolated QA database; no HTTP entry point.
require __DIR__ . '/bootstrap.php';
if ( DB_NAME !== 'qa_browser' ) { exit( 2 ); }
$input = json_decode( (string) stream_get_contents( STDIN ), true ); if ( ! is_array( $input ) ) { exit( 3 ); }
if ( ( $input['mode'] ?? '' ) === 'seed' ) {
	$users = array(); foreach ( array( 'author', 'other', 'moderator', 'unverified' ) as $role ) {
		$id = p10_user( 'browser-' . $role, $role !== 'unverified' ); $password = 'Browser-P10-' . bin2hex( random_bytes( 24 ) ); wp_set_password( $password, $id ); $user = get_userdata( $id );
		if ( $role === 'moderator' ) { foreach ( array( 'aish_manage_submissions', 'aish_manage_update_suggestions', 'aish_manage_developers', 'aish_manage_author_claims' ) as $cap ) { $user->add_cap( $cap ); } }
		$users[ $role ] = array( 'id' => $id, 'email' => $user->user_email, 'login' => $user->user_login, 'password' => $password );
	}
	$skill = p10_skill( 'browser-' . ( ( $input['lang'] ?? 'en' ) === 'zh' ? 'zh' : 'en' ) );
	$slug = $db->value( 'SELECT slug FROM ' . $db->table( 'skill_translations' ) . ' WHERE skill_id=%d AND locale=%s', array( $skill, 'en_US' ) );
	echo json_encode( array( 'skill' => $skill, 'slug' => $slug, 'users' => $users, 'handle' => 'qa-' . $run . '-browser', 'repository' => 'https://github.com/qa-' . $run . '/browser-skills' ) ); exit;
}
$user = (int) ( $input['user'] ?? 0 ); $identity = get_userdata( $user );
if ( ! $identity || ! preg_match( '/^p10_[a-f0-9]{16}_browser-(author|other|moderator|unverified)$/D', $identity->user_login ) ) { exit( 4 ); }
wp_set_current_user( $user );
if ( ( $input['mode'] ?? '' ) === 'state' ) {
	$list = $submissionQuery->mine( 'en_US' ); $claims = $developerQuery->mineClaims(); $profile = $developerQuery->mineProfile();
	echo json_encode( array( 'submissions' => array_map( static fn( array $r ): array => array_intersect_key( $r, array_flip( array( 'id', 'kind', 'revision', 'state', 'name', 'feedback' ) ) ), $list['items'] ), 'claims' => array_map( static fn( array $r ): array => array_intersect_key( $r, array_flip( array( 'id', 'skill_id', 'state', 'claim_role' ) ) ), $claims['items'] ), 'profile' => array_intersect_key( $profile, array_flip( array( 'revision', 'state', 'opt_in' ) ) ), 'notifications' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'user_notifications' ) . " WHERE user_id=%d AND type='contribution'", array( $user ) ) ) ); exit;
}
exit( 5 );
