<?php
declare(strict_types=1);

/** Real isolated WP/MySQL harness. No HTTP entry point or external API allowance. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE10_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) || '1.8.0' !== get_option( 'aish_schema_version' ) ) { exit( 2 ); }
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
function p10_assert( bool $condition, string $message ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function p10_reject( callable $fn, string $message, ?array $reasons = null ): void {
	try { $fn(); } catch ( Throwable $error ) {
		if ( null !== $reasons ) { $reason = property_exists( $error, 'reason' ) ? $error->reason : ( method_exists( $error, 'reason' ) ? $error->reason() : $error->getMessage() ); p10_assert( in_array( $reason, $reasons, true ), $message . ' has a bounded expected reason.' ); }
		p10_assert( true, $message ); return;
	}
	throw new RuntimeException( $message );
}
function p10_case( string $id, callable $fn ): void {
	global $cases;
	try { $fn(); $cases[] = array( 'id' => $id, 'status' => 'passed' ); }
	catch ( Throwable $error ) { $cases[] = array( 'id' => $id, 'status' => 'failed', 'reason' => property_exists( $error, 'reason' ) ? $error->reason : ( $error instanceof RuntimeException ? $error->getMessage() : get_class( $error ) ) ); }
}
function p10_key(): string { return bin2hex( random_bytes( 24 ) ); }
function p10_user( string $suffix, bool $verified = true ): int {
	global $run, $admin, $accounts, $core, $qaUsers;
	wp_set_current_user( $admin );
	$id = wp_insert_user( array( 'user_login' => 'p10_' . $run . '_' . $suffix, 'user_email' => 'p10-' . $run . '-' . $suffix . '@example.invalid', 'user_pass' => 'P10-QA-' . bin2hex( random_bytes( 20 ) ), 'display_name' => 'Contributions QA ' . $suffix, 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) || ! is_int( $id ) ) { throw new RuntimeException( 'Generated QA identity unavailable.' ); }
	$qaUsers[] = $id; update_user_meta( $id, '_aish_account_state', 'pending_email' );
	if ( $verified ) {
		$token = bin2hex( random_bytes( 32 ) ); $store = $core->get( 'account_store' );
		$store->transaction( fn() => $store->issueVerification( $id, 'verify_email', get_userdata( $id )->user_email, $token ) );
		wp_set_current_user( 0 ); $accounts->verify( $token );
	}
	return $id;
}
function p10_skill( string $suffix, bool $published = true, bool $bilingual = true, ?string $sourcePath = null ): int {
	global $run, $admin, $management, $db, $qaSkills;
	wp_set_current_user( $admin ); $translations = array();
	foreach ( $bilingual ? array( 'en_US', 'zh_CN' ) : array( 'en_US' ) as $locale ) {
		$translations[ $locale ] = array( 'title' => 'Contributions QA ' . $suffix . ' ' . $locale, 'slug' => 'contributions-' . $run . '-' . $suffix, 'summary' => 'Synthetic contribution fixture.', 'content' => '<p>Isolated QA resource; no external execution.</p>' );
	}
	$input = array( 'save_mode' => $published ? 'pending' : 'draft', 'translations' => $translations );
	if ( null !== $sourcePath ) {
		$repo = $db->value( 'SELECT id FROM ' . $db->table( 'repositories' ) . ' WHERE owner=%s AND name=%s', array( 'qa-' . $run, 'phase10-skills' ) );
		$input['repository_id'] = $repo ? (int) $repo : $management->saveRepository( null, 'qa-' . $run, 'phase10-skills' ); $input['source_path'] = $sourcePath;
	}
	$id = $management->saveSkill( null, $input ); $qaSkills[] = $id;
	if ( $published ) { $management->review( $id, 'approved', 'unknown', 'Isolated Phase 10 QA fixture.', p10_skill_revision( $id ) ); }
	return $id;
}
function p10_skill_revision( int $id ): int { global $db; return (int) $db->value( 'SELECT revision FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $id ) ); }
function p10_skill_fingerprint( int $id ): string {
	global $db; $rows = array();
	foreach ( array( 'skills','skill_translations','skill_versions' ) as $entity ) { $rows[ $entity ] = $db->rows( 'SELECT * FROM ' . $db->table( $entity ) . ' WHERE ' . ( 'skills' === $entity ? 'id' : 'skill_id' ) . '=%d ORDER BY id', array( $id ) ); }
	return hash( 'sha256', (string) wp_json_encode( $rows ) );
}
function p10_payload( string $suffix = 'skill', ?string $path = 'SKILL.md' ): array {
	global $run;
	return array( 'kind' => 'submit', 'locale' => 'en_US', 'source_kind' => 'github', 'github_url' => 'https://github.com/qa-' . $run . '/skills-' . $suffix, 'source_path' => $path, 'name' => 'QA proposed skill ' . $suffix, 'summary' => 'Synthetic proposed resource for Phase 10 checks.', 'categories' => '', 'platforms' => '', 'license' => 'MIT', 'notes' => 'Original submitter notes for isolated QA.' );
}
function p10_report( string $kind ): never {
	global $cases, $assertions, $wpdb, $externalRequests, $mockMail;
	$failed = count( array_filter( $cases, static fn( array $c ): bool => $c['status'] !== 'passed' ) );
	echo json_encode( array( 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ) ), 'kind' => $kind, 'cases' => $cases, 'assertions' => $assertions, 'external_http_attempts' => $externalRequests, 'mock_mail_calls' => $mockMail, 'passed' => count( $cases ) - $failed, 'failed' => $failed ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( $failed ? 1 : 0 );
}
function p10_profile_payload( string $suffix, bool $bilingual = true ): array {
	global $run; $translations = array( 'en_US' => array( 'display_name' => 'Reviewed developer ' . $suffix, 'bio' => 'Original developer biography in English.', 'seo_title' => '', 'seo_description' => '' ) );
	if ( $bilingual ) { $translations['zh_CN'] = array( 'display_name' => '开发者 ' . $suffix, 'bio' => '开发者提供的独立中文介绍。', 'seo_title' => '', 'seo_description' => '' ); }
	return array( 'handle' => 'qa-' . $run . '-' . $suffix, 'github_url' => 'https://github.com/example', 'website_url' => 'https://example.org/', 'public_avatar' => false, 'show_contributions' => true, 'opt_in' => true, 'translations' => $translations );
}
function p10_claim_payload( int $skill, string $role = 'author' ): array {
	return array( 'claim_role' => $role, 'locale' => 'en_US', 'source_revision' => p10_skill_revision( $skill ), 'proof_kind' => 'author' === $role ? 'authored_source' : 'repository_control', 'evidence_urls' => array( 'https://github.com/example/repository/blob/main/LICENSE' ), 'reason' => 'Synthetic evidence location for manual authorship verification; never fetched.' );
}
function p10_verification(): array { return array( 'evidence_checked' => true, 'role_checked' => true, 'source_checked' => true ); }
