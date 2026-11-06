<?php
declare(strict_types=1);

/** Native API bypass contracts with isolated WP/database substitutes. */
namespace AISkillsHub\Core\Submissions { final class Store { public static bool $write = false; public static function writing(): bool { return self::$write; } } }
namespace AISkillsHub\Core\Developers { final class Service { public static bool $write = false; public static function writing(): bool { return self::$write; } } }
namespace AISkillsHub\Core\Contributions { final class Privacy { public static bool $write = false; public static function writing(): bool { return self::$write; } } }
namespace AISkillsHub\Core\Database {
	final class Database {
		public bool $fail = false;
		public array $managed = array( 10 => true, 11 => true );
		public array $postMeta = array( 100 => array( 'post_id' => 10, 'meta_key' => '_aish_message_notes' ), 101 => array( 'post_id' => 20, 'meta_key' => 'legacy_key' ) );
		public array $userMeta = array( 200 => array( 'user_id' => 30, 'meta_key' => '_aish_profile_approved_snapshot' ), 201 => array( 'user_id' => 30, 'meta_key' => 'description' ), 202 => array( 'user_id' => 30, 'meta_key' => '_aish_public_profile' ) );
		public function connection(): object { return (object) array( 'posts' => 'wp_posts', 'postmeta' => 'wp_postmeta', 'users' => 'wp_users', 'usermeta' => 'wp_usermeta' ); }
		public function value( string $sql, array $values = array() ): mixed {
			if ( $this->fail ) { throw new \RuntimeException( 'private database diagnostic' ); }
			if ( str_contains( $sql, 'SELECT p.ID FROM wp_posts' ) ) { return ! empty( $this->managed[ $values[0] ] ) ? $values[0] : null; }
			if ( str_contains( $sql, "meta_key='_aish_profile_approved_snapshot'" ) ) { return 30 === $values[0] ? 1 : null; }
			if ( str_contains( $sql, 'SELECT user_nicename' ) ) { return 'approved-public-handle'; }
			throw new \RuntimeException( 'Unexpected fixture query' );
		}
		public function row( string $sql, array $values = array() ): ?array {
			if ( $this->fail ) { throw new \RuntimeException( 'private database diagnostic' ); }
			if ( str_contains( $sql, 'FROM wp_postmeta' ) ) { return $this->postMeta[ $values[0] ] ?? null; }
			if ( str_contains( $sql, 'FROM wp_usermeta' ) ) { return $this->userMeta[ $values[0] ] ?? null; }
			throw new \RuntimeException( 'Unexpected fixture query' );
		}
	}
}
namespace {
	define( 'ABSPATH', '/isolated-wordpress/' );
	class WP_Post { public function __construct( public int $ID ) {} }
	class WP_Query { public function __construct( private bool $main = true ) {} public function is_main_query(): bool { return $this->main; } }
	$GLOBALS['hooks'] = array();
	function add_filter( string $name, callable $callback, int $priority = 10, int $arguments = 1 ): void { $GLOBALS['hooks'][ $name ] = array( $callback, $priority, $arguments ); }
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Contributions/Guards.php';
	use AISkillsHub\Core\Contributions\Guards;
	use AISkillsHub\Core\Submissions\Store;
	use AISkillsHub\Core\Developers\Service;
	use AISkillsHub\Core\Contributions\Privacy;
	$db = new AISkillsHub\Core\Database\Database(); $guard = new Guards( $db ); $assertions = 0;
	function check( bool $condition, string $message ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
	$guard->register();
	foreach ( array( 'wp_insert_post_empty_content', 'map_meta_cap', 'pre_delete_post', 'pre_trash_post', 'pre_untrash_post', 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata', 'update_post_metadata_by_mid', 'delete_post_metadata_by_mid', 'add_user_metadata', 'update_user_metadata', 'delete_user_metadata', 'update_user_metadata_by_mid', 'delete_user_metadata_by_mid', 'wp_pre_insert_user_data', 'posts_where' ) as $hook ) { check( isset( $GLOBALS['hooks'][ $hook ] ) && $GLOBALS['hooks'][ $hook ][1] === PHP_INT_MAX, 'native mutation surface is guarded: ' . $hook ); }
	check( ! $guard->managedPost( 0 ) && ! $guard->managedPost( 20 ) && $guard->managedPost( 10 ) && $guard->managedPost( 11 ), 'only marker-protected record/revision is managed; anonymous legacy remains distinct' );
	foreach ( array( 'read_post', 'edit_post', 'delete_post' ) as $cap ) { check( $guard->capabilities( array( 'manage_options' ), $cap, 1, array( 10 ) ) === array( 'do_not_allow' ), 'native capability cannot bypass controlled record: ' . $cap ); check( $guard->capabilities( array( 'manage_options' ), $cap, 1, array( 20 ) ) === array( 'manage_options' ), 'legacy Inbox capability preserved: ' . $cap ); }
	check( $guard->capabilities( array( 'read' ), 'read', 1, array() ) === array( 'read' ), 'unrelated capabilities unchanged' );
	check( $guard->capabilities( array( 'aish_manage_settings' ), 'aish_manage_settings', 1, array( 10 ) ) === array( 'do_not_allow' ), 'CPT mapped primitive with object ID cannot bypass managed-record protection' );
	check( $guard->capabilities( array( 'aish_manage_settings' ), 'aish_manage_settings', 1, array() ) === array( 'aish_manage_settings' ), 'global settings capability without object remains usable' );
	check( $guard->capabilities( array( 'aish_manage_settings' ), 'aish_manage_settings', 1, array( 20 ) ) === array( 'aish_manage_settings' ), 'legacy Inbox object retains its mapped settings capability' );
	check( $guard->rejectNativeWrite( false, array( 'ID' => 10, 'post_content' => 'replacement' ) ), 'native edit/restore cannot rewrite controlled proposal' );
	check( $guard->rejectNativeWrite( false, array( 'post_type' => 'revision', 'post_parent' => 10, 'post_content' => 'replacement' ) ), 'native autosave/revision cannot create a replacement controlled snapshot' );
	check( ! $guard->rejectNativeWrite( false, array( 'ID' => 20, 'post_content' => 'legacy update' ) ), 'legacy Inbox native editor preserved' );
	foreach ( array( '_aish_submission_version', '_aish_submission_revision', '_aish_target_skill_id', '_aish_result_skill_ids', '_aish_user_visible_feedback' ) as $key ) { check( $guard->rejectNativeWrite( false, array( 'post_type' => 'aish_message', 'meta_input' => array( $key => 'forged' ) ) ), 'native creation cannot forge controlled metadata ' . $key ); check( false === $guard->postMeta( null, 20, $key, 'forged' ), 'metadata API cannot promote legacy record ' . $key ); }
	check( true === $guard->rejectNativeWrite( true, array() ), 'pre-existing empty rejection preserved' );
	check( false === $guard->deletePost( null, new WP_Post( 10 ) ) && false === $guard->deletePost( null, new WP_Post( 11 ) ), 'native delete/trash/untrash cannot remove controlled originals or revisions' );
	check( null === $guard->deletePost( null, new WP_Post( 20 ) ), 'legacy delete decision remains with existing WordPress policy' );
	foreach ( array( '_aish_message_notes', '_aish_message_kind', '_aish_github_url', 'arbitrary_key' ) as $key ) { check( false === $guard->postMeta( null, 10, $key, 'forged' ), 'all managed-post metadata blocked outside service: ' . $key ); }
	check( null === $guard->postMeta( null, 20, 'legacy_key', 'value' ), 'ordinary legacy metadata preserved' );
	check( false === $guard->deletePostMeta( null, 0, '_aish_message_notes', null, true ), 'bulk metadata delete cannot erase private notes of managed records' );
	check( false === $guard->deletePostMeta( null, 0, '_aish_submission_version', null, true ), 'bulk delete cannot remove ownership marker' );
	check( false === $guard->postMetaById( null, 100, 'replacement' ), 'update by meta ID obeys managed-record guard' );
	check( false === $guard->postMetaById( null, 100 ), 'delete by meta ID obeys managed-record guard' );
	check( false === $guard->postMetaById( null, 101, 'forged', '_aish_submission_version' ), 'renaming legacy meta cannot forge ownership marker' );
	check( null === $guard->postMetaById( null, 101, 'normal update' ) && null === $guard->postMetaById( null, 999, 'normal update' ), 'ordinary and absent native meta retain original outcome' );
	foreach ( array( '_aish_profile_revision', '_aish_profile_approved_snapshot', '_aish_profile_translations', '_aish_profile_candidate', '_aish_profile_feedback', '_aish_profile_private_note', '_aish_profile_decision', '_aish_profile_updated_at' ) as $key ) { check( false === $guard->userMeta( null, 30, $key, 'forged' ), 'protected profile key rejects native mutation: ' . $key ); check( false === $guard->deleteUserMeta( null, 30, $key ), 'protected profile key rejects native deletion: ' . $key ); }
	check( null === $guard->userMeta( null, 30, 'description', 'private biography' ), 'Phase 8 private profile remains editable' );
	foreach ( array( 1, '1', true ) as $value ) { check( false === $guard->userMeta( null, 30, '_aish_public_profile', $value ), 'native preferences cannot create public-profile consent' ); }
	foreach ( array( 0, '0', false, '' ) as $value ) { check( null === $guard->userMeta( null, 30, '_aish_public_profile', $value ), 'native privacy path can remove public consent' ); }
	check( null === $guard->deleteUserMeta( null, 30, '_aish_public_profile' ), 'deleting public consent can only hide profile' );
	check( false === $guard->userMetaById( null, 200, 'replacement' ) && false === $guard->deleteUserMetaById( null, 200 ), 'profile approval cannot be altered via metadata ID' );
	check( false === $guard->userMetaById( null, 201, 'forged', '_aish_profile_approved_snapshot' ) && false === $guard->userMetaById( null, 201, '1', '_aish_public_profile' ), 'renaming unprotected usermeta cannot forge approval or consent' );
	check( null === $guard->userMetaById( null, 201, 'private text' ) && null === $guard->deleteUserMetaById( null, 202 ), 'normal native profile text and consent withdrawal still work' );
	check( $guard->nativeUser( array( 'user_nicename' => 'hijacked' ), true, 30 )['user_nicename'] === 'approved-public-handle', 'native user update cannot mutate approved public handle' );
	check( $guard->nativeUser( array( 'user_nicename' => 'normal' ), true, 40 )['user_nicename'] === 'normal', 'unapproved native users not forced into public identity' );
	check( $guard->nativeUser( array( 'user_nicename' => 'normal' ), false, null )['user_nicename'] === 'normal', 'native user creation preserved' );
	$clause = $guard->nativeLists( ' WHERE 1=1', new WP_Query() ); check( str_contains( $clause, '_aish_submission_version' ) && str_contains( $clause, "post_type='revision'" ), 'native main queries exclude controlled records and child revisions' );
	check( ' WHERE 1=1' === $guard->nativeLists( ' WHERE 1=1', new WP_Query( false ) ), 'non-main domain queries retain explicit authorization responsibility' );
	Store::$write = true; check( ! $guard->rejectNativeWrite( false, array( 'ID' => 10 ) ) && null === $guard->postMeta( null, 10, '_aish_submission_revision', '2' ) && null === $guard->deletePost( null, new WP_Post( 10 ) ), 'active controlled submission transaction can write its native records' ); check( ' WHERE 1=1' === $guard->nativeLists( ' WHERE 1=1', new WP_Query() ), 'controlled writer can query private snapshots' ); Store::$write = false;
	Service::$write = true; check( null === $guard->userMeta( null, 30, '_aish_profile_approved_snapshot', array() ) && $guard->nativeUser( array( 'user_nicename' => 'approved-change' ), true, 30 )['user_nicename'] === 'approved-change', 'developer service alone can publish a verified profile snapshot and handle' ); Service::$write = false;
	Privacy::$write = true; check( null === $guard->deletePost( null, new WP_Post( 10 ) ) && null === $guard->deleteUserMeta( null, 30, '_aish_profile_decision' ), 'controlled privacy cleanup can erase managed data' ); Privacy::$write = false;
	$db->fail = true;
	check( $guard->rejectNativeWrite( false, array( 'ID' => 10 ) ) && false === $guard->deletePost( null, new WP_Post( 10 ) ) && false === $guard->postMeta( null, 10, 'notes', 'forged' ), 'database uncertainty fails closed for native record mutations' );
	check( $guard->capabilities( array( 'manage_options' ), 'read_post', 1, array( 10 ) ) === array( 'do_not_allow' ), 'database uncertainty fails closed for native record reads' );
	check( false === $guard->postMetaById( null, 100 ) && false === $guard->userMetaById( null, 200 ) && false === $guard->deleteUserMetaById( null, 200 ), 'database uncertainty fails closed for metadata ID bypasses' );
	echo "Contribution guards: {$assertions} isolated assertions passed (WP/database substitutes; no real WordPress/MySQL).\n";
}
