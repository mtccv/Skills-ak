<?php
declare(strict_types=1);

/** Real isolated WP/MySQL harness. No HTTP entry point or external API allowance. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE13_QA' ) !== '1' ) { exit( 1 ); }
$_SERVER['HTTP_HOST'] = PHP_VERSION_ID >= 80400 ? '127.0.0.1:18184' : '127.0.0.1:18183';
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_discovery', 'qa_operations84' ), true ) || '1.9.0' !== get_option( 'aish_schema_version' ) ) { exit( 2 ); }
require_once ABSPATH . 'wp-admin/includes/user.php';

use AISkillsHub\Core\Core;
$core = Core::boot(); $db = $core->get( 'database' ); $accounts = $core->get( 'accounts' );
$submissions = $core->get( 'submissions' ); $submissionQuery = $core->get( 'submission_query' );
$developers = $core->get( 'developers' ); $developerQuery = $core->get( 'developer_query' );
$management = $core->get( 'management' );
$admin = 0;
foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 100 ) ) as $id ) {
	if ( user_can( (int) $id, 'manage_options' ) ) { $admin = (int) $id; break; }
}
if ( $admin < 1 ) { exit( 3 ); }
wp_set_current_user( $admin ); do_action( 'admin_init' ); wp_set_current_user( 0 );
$run = bin2hex( random_bytes( 8 ) ); $assertions = 0; $cases = array(); $externalRequests = 0; $mockMail = 0;
$qaUsers = array(); $qaSkills = array(); $qaPosts = array();
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 1, 250 );
add_filter( 'pre_http_request', static function () use ( &$externalRequests ): WP_Error { ++$externalRequests; return new WP_Error( 'qa_external_disabled', 'External HTTP is disabled in this test.' ); }, 1 );
add_filter( 'pre_wp_mail', static function () use ( &$mockMail ): bool { ++$mockMail; return true; }, 1 );
function p13_assert( bool $condition, string $message ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function p13_reject( callable $fn, string $message, ?array $reasons = null ): void {
	try { $fn(); } catch ( Throwable $error ) {
		if ( null !== $reasons ) { $reason = property_exists( $error, 'reason' ) ? $error->reason : ( method_exists( $error, 'reason' ) ? $error->reason() : $error->getMessage() ); p13_assert( in_array( $reason, $reasons, true ), $message . ' has a bounded expected reason.' ); }
		p13_assert( true, $message ); return;
	}
	throw new RuntimeException( $message );
}
function p13_case( string $id, callable $fn ): void {
	global $cases;
	try { $fn(); $cases[] = array( 'id' => $id, 'status' => 'passed' ); }
	catch ( Throwable $error ) { $cases[] = array( 'id' => $id, 'status' => 'failed', 'reason' => property_exists( $error, 'reason' ) ? $error->reason : ( $error instanceof RuntimeException ? $error->getMessage() : get_class( $error ) . '@' . basename($error->getFile()) . ':' . $error->getLine() ) ); }
}
function p13_key(): string { return bin2hex( random_bytes( 24 ) ); }
function p13_user( string $suffix, bool $verified = true ): int {
	global $run, $admin, $accounts, $core, $qaUsers;
	wp_set_current_user( $admin );
	$id = wp_insert_user( array( 'user_login' => 'p13_' . $run . '_' . $suffix, 'user_email' => 'p10-' . $run . '-' . $suffix . '@example.invalid', 'user_pass' => 'P13-QA-' . bin2hex( random_bytes( 20 ) ), 'display_name' => 'Commercial QA ' . $suffix, 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) || ! is_int( $id ) ) { throw new RuntimeException( 'Generated QA identity unavailable.' ); }
	$qaUsers[] = $id; update_user_meta( $id, '_aish_account_state', 'pending_email' );
	if ( $verified ) {
		$token = bin2hex( random_bytes( 32 ) ); $store = $core->get( 'account_store' );
		$store->transaction( fn() => $store->issueVerification( $id, 'verify_email', get_userdata( $id )->user_email, $token ) );
		wp_set_current_user( 0 ); $accounts->verify( $token );
	}
	return $id;
}
function p13_skill( string $suffix, bool $published = true, bool $bilingual = true, ?string $sourcePath = null ): int {
	global $run, $admin, $management, $db, $qaSkills;
	wp_set_current_user( $admin ); $translations = array();
	foreach ( $bilingual ? array( 'en_US', 'zh_CN' ) : array( 'en_US' ) as $locale ) {
		$translations[ $locale ] = array( 'title' => 'Commercial QA ' . $suffix . ' ' . $locale, 'slug' => 'discovery-' . $run . '-' . $suffix, 'summary' => 'Synthetic contribution fixture.', 'content' => '<p>Isolated QA resource; no external execution.</p>' );
	}
	$input = array( 'save_mode' => $published ? 'pending' : 'draft', 'translations' => $translations );
	if ( null !== $sourcePath ) {
		$repo = $db->value( 'SELECT id FROM ' . $db->table( 'repositories' ) . ' WHERE owner=%s AND name=%s', array( 'qa-' . $run, 'phase13-skills' ) );
		$input['repository_id'] = $repo ? (int) $repo : $management->saveRepository( null, 'qa-' . $run, 'phase13-skills' ); $input['source_path'] = $sourcePath;
	}
	$id = $management->saveSkill( null, $input ); $qaSkills[] = $id;
	if ( $published ) { $management->review( $id, 'approved', 'unknown', 'Isolated Phase 13 QA fixture.', p13_skill_revision( $id ) ); }
	return $id;
}
function p13_skill_revision( int $id ): int { global $db; return (int) $db->value( 'SELECT revision FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $id ) ); }
function p13_skill_fingerprint( int $id ): string {
	global $db; $rows = array();
	foreach ( array( 'skills','skill_translations','skill_versions' ) as $entity ) { $rows[ $entity ] = $db->rows( 'SELECT * FROM ' . $db->table( $entity ) . ' WHERE ' . ( 'skills' === $entity ? 'id' : 'skill_id' ) . '=%d ORDER BY id', array( $id ) ); }
	return hash( 'sha256', (string) wp_json_encode( $rows ) );
}
function p13_report( string $kind ): never {
	global $cases, $assertions, $wpdb, $externalRequests, $mockMail;
	$failed = count( array_filter( $cases, static fn( array $c ): bool => $c['status'] !== 'passed' ) );
	echo json_encode( array( 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ) ), 'kind' => $kind, 'cases' => $cases, 'assertions' => $assertions, 'external_http_attempts' => $externalRequests, 'mock_mail_calls' => $mockMail, 'passed' => count( $cases ) - $failed, 'failed' => $failed ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( $failed ? 1 : 0 );
}

$query = new AISkillsHub\Core\Discovery\Query( $db, $core->migrator(), $core->get( 'public_catalog' ) );
$discovery = new AISkillsHub\Core\Discovery\Management( $db, $core->migrator() );
$settings = new AISkillsHub\Core\Discovery\Settings( $db, $core->migrator() );
$community = $core->get( 'community' );
function p13_ids( array $rows ): array { return array_map( 'intval', array_column( $rows, 'id' ) ); }
function p13_find( array $rows, int $id ): ?array { foreach ( $rows as $row ) { if ( (int) $row['id'] === $id ) { return $row; } } return null; }
function p13_review( int $user, int $skill, int $rating, bool $approve = true ): array {
 global $community, $db, $admin;
 wp_set_current_user($user);
 $community->submitReview($skill, ['locale'=>'en_US','rating'=>$rating,'title'=>'Synthetic QA review','comment'=>'Review fixture for isolated discovery verification.'],0,p13_key());
 $row=$db->row('SELECT * FROM '.$db->table('reviews').' WHERE skill_id=%d AND user_id=%d',[$skill,$user]);
 if($approve) { wp_set_current_user($admin); $community->moderateReview((int)$row['id'],(int)$row['pending_revision_id'],(int)$row['lock_version'],'approve','approved','Isolated QA approval',p13_key()); }
 return $db->row('SELECT * FROM '.$db->table('reviews').' WHERE id=%d',[(int)$row['id']]);
}
