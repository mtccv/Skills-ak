<?php
declare(strict_types=1);

/** Shared real integration harness, never an HTTP entry point. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE9_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) || '1.7.0' !== get_option( 'aish_schema_version' ) ) { exit( 2 ); }
require_once ABSPATH . 'wp-admin/includes/user.php';

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Accounts\Store;

$core = Core::boot(); $db = $core->get( 'database' ); $accounts = $core->get( 'accounts' );
$service = $core->get( 'community' ); $query = $core->get( 'community_query' );
$management = $core->get( 'management' );
$adminIds = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
$admin = (int) ( $adminIds[0] ?? 0 ); if ( $admin < 1 ) { exit( 3 ); }
wp_set_current_user( $admin ); do_action( 'admin_init' );
wp_set_current_user( 0 ); // Reload role capabilities after the normal administrator initialization.
$run = bin2hex( random_bytes( 8 ) ); $assertions = 0; $cases = array();
$qaUsers = array(); $qaSkills = array();
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 1, 250 );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'qa_external_disabled', 'External HTTP is disabled in this test.' ) );
add_filter( 'pre_wp_mail', static fn() => true ); // Mock acceptance only, no SMTP or delivery assertion.

function c9_assert( bool $condition, string $message ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function c9_reject( callable $fn, string $message ): void { try { $fn(); } catch ( Throwable $error ) { c9_assert( true, $message ); return; } throw new RuntimeException( $message ); }
function c9_case( string $id, callable $fn ): void {
	global $cases;
	try { $fn(); $cases[] = array( 'id' => $id, 'status' => 'passed' ); }
	catch ( Throwable $error ) { $cases[] = array( 'id' => $id, 'status' => 'failed', 'reason' => $error instanceof AISkillsHub\Core\Community\Exception ? $error->reason : ( $error instanceof RuntimeException ? $error->getMessage() : get_class( $error ) ) ); }
}
function c9_key(): string { return bin2hex( random_bytes( 24 ) ); }
function c9_user( string $suffix, bool $verified = true ): int {
	global $run, $admin, $accounts, $core, $qaUsers;
	wp_set_current_user( $admin );
	$password = 'Community-QA-' . bin2hex( random_bytes( 20 ) );
	$id = wp_insert_user( array( 'user_login' => 'p9_' . $run . '_' . $suffix, 'user_email' => 'p9-' . $run . '-' . $suffix . '@example.invalid', 'user_pass' => $password, 'display_name' => 'Community QA ' . $suffix, 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Generated QA identity unavailable.' ); }
	$qaUsers[] = (int) $id;
	update_user_meta( $id, '_aish_account_state', 'pending_email' );
	if ( $verified ) {
		$token = bin2hex( random_bytes( 32 ) ); $store = $core->get( 'account_store' );
		$store->transaction( fn() => $store->issueVerification( (int) $id, 'verify_email', get_userdata( $id )->user_email, $token ) );
		wp_set_current_user( 0 ); $accounts->verify( $token );
	}
	return (int) $id;
}
function c9_skill( string $suffix, bool $bilingual = true, bool $published = true ): int {
	global $run, $admin, $management, $db, $qaSkills;
	wp_set_current_user( $admin );
	$translations = array();
	foreach ( $bilingual ? array( 'en_US', 'zh_CN' ) : array( 'en_US' ) as $locale ) {
		$translations[ $locale ] = array( 'title' => 'Community QA ' . $suffix . ' ' . $locale, 'slug' => 'community-' . $run . '-' . $suffix, 'summary' => 'Synthetic community test resource.', 'content' => '<p>Isolated test fixture; no installation commands or external execution.</p>' );
	}
	$id = $management->saveSkill( null, array( 'save_mode' => $published ? 'pending' : 'draft', 'translations' => $translations ) ); $qaSkills[] = $id;
	if ( $published ) { $management->review( $id, 'approved', 'unknown', 'Isolated QA fixture.', (int) $db->value( 'SELECT revision FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $id ) ) ); }
	return $id;
}
function c9_head( int $user, int $skill ): array { global $db; return $db->row( 'SELECT * FROM ' . $db->table( 'reviews' ) . ' WHERE user_id=%d AND skill_id=%d', array( $user, $skill ) ) ?? array(); }
function c9_revision( int $id ): array { global $db; return $db->row( 'SELECT * FROM ' . $db->table( 'review_revisions' ) . ' WHERE id=%d', array( $id ) ) ?? array(); }
function c9_payload( int|string $rating = 4, string $locale = 'en_US', string $marker = 'Original' ): array { return array( 'rating' => $rating, 'locale' => $locale, 'title' => $marker . ' community review', 'comment' => $marker . ' tested comment in the isolated environment.', 'experience' => 'Used for a synthetic QA scenario.', 'pros' => 'Explicit review boundaries.', 'cons' => 'Fixture only.', 'platform' => 'Claude Code', 'version_label' => 'QA v1' ); }
function c9_approve( array $head, string $decision = 'approve', string $note = '' ): array {
	global $admin, $service, $db;
	wp_set_current_user( $admin );
	$revision = (int) ( $head['pending_revision_id'] ?: $head['approved_revision_id'] );
	$service->moderateReview( (int) $head['id'], $revision, (int) $head['lock_version'], $decision, $decision === 'approve' ? 'approved' : 'other', $note, c9_key() );
	return $db->row( 'SELECT * FROM ' . $db->table( 'reviews' ) . ' WHERE id=%d', array( $head['id'] ) );
}
function c9_report( string $kind = 'Real WordPress/MySQL community integration; mail transport mocked, no external API' ): never {
	global $cases, $assertions, $wpdb;
	$failed = count( array_filter( $cases, static fn( array $c ): bool => $c['status'] !== 'passed' ) );
	echo json_encode( array( 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ) ), 'kind' => $kind, 'cases' => $cases, 'assertions' => $assertions, 'passed' => count( $cases ) - $failed, 'failed' => $failed ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( $failed ? 1 : 0 );
}
