<?php
declare(strict_types=1);

/** Isolated native UI boundaries; WP, queries and mutation services are substitutes. */
namespace AISkillsHub\Core\Submissions {
	final class Service {
		public array $calls = array();
		public function moderate( int $id, int $revision, string $decision, string $feedback, string $privateNote, array $skillIds, string $key ): array { $this->calls[] = func_get_args(); if ( 8 === $id ) { throw new \RuntimeException( 'private database diagnostic' ); } return array(); }
	}
	final class Query {
		public int $calls = 0;
		public ?array $row = null;
		public array $filters = array();
		public function adminDetail( string $kind, int $id, int $page = 1 ): ?array { ++$this->calls; return $this->row; }
		public function adminList( string $kind, array $filters = array() ): array { ++$this->calls; $this->filters = $filters; return array( 'items' => $this->row ? array( $this->row ) : array(), 'total' => $this->row ? 1 : 0, 'page' => $filters['page'], 'per_page' => 20 ); }
	}
}
namespace AISkillsHub\Core\Developers {
	final class Service {
		public array $calls = array();
		public function moderateProfile( int $id, int $revision, string $decision, string $feedback, string $note, string $key ): array { $this->calls[] = func_get_args(); return array(); }
		public function moderateClaim( int $id, string $state, string $decision, string $feedback, string $note, string $key, array $verification = array() ): array { $this->calls[] = func_get_args(); if ( 8 === $id ) { throw new \RuntimeException( 'private claim diagnostic' ); } return array(); }
	}
	final class Query {
		public int $calls = 0;
		public ?array $profile = null;
		public ?array $claim = null;
		public function adminProfile( int $id, int $page = 1 ): ?array { ++$this->calls; return $this->profile; }
		public function adminClaim( int $id, int $page = 1 ): ?array { ++$this->calls; return $this->claim; }
		public function adminProfiles( array $filters = array() ): array { ++$this->calls; return array( 'items' => $this->profile ? array( $this->profile ) : array(), 'total' => $this->profile ? 1 : 0, 'page' => $filters['page'], 'per_page' => 20 ); }
		public function adminClaims( array $filters = array() ): array { ++$this->calls; return array( 'items' => $this->claim ? array( $this->claim ) : array(), 'total' => $this->claim ? 1 : 0, 'page' => $filters['page'], 'per_page' => 20 ); }
	}
}
namespace {
	define( 'ABSPATH', '/isolated-wordpress/' );
	$GLOBALS['caps'] = array(); $GLOBALS['locale'] = 'en_US'; $GLOBALS['hooks'] = array(); $GLOBALS['menus'] = array();
	function get_user_locale(): string { return $GLOBALS['locale']; }
	function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['caps'][ $cap ] ); }
	function get_current_user_id(): int { return 19; }
	function wp_die( string $message, string $title = '', array $args = array() ): never { throw new RuntimeException( 'http_' . ( $args['response'] ?? 500 ) ); }
	function wp_unslash( mixed $value ): mixed { return is_string( $value ) ? stripslashes( $value ) : $value; }
	function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_attr( string $value ): string { return esc_html( $value ); }
	function esc_url( string $value ): string { return esc_attr( $value ); }
	function selected( string $value, string $selected, bool $echo = true ): string { return $value === $selected ? ' selected="selected"' : ''; }
	function admin_url( string $path ): string { return 'https://admin.example/wp-admin/' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
	function wp_verify_nonce( string $nonce, string $action ): bool { return 'fixture' === $nonce; }
	function get_transient( string $key ): bool { return false; }
	function delete_transient( string $key ): bool { return true; }
	function set_transient( string $key, mixed $value, int $expiry ): bool { $GLOBALS['notice'] = $value; return true; }
	final class RedirectReached extends RuntimeException { public function __construct( public readonly string $url, public readonly int $status ) { parent::__construct( 'redirect' ); } }
	function wp_safe_redirect( string $url, int $status = 302 ): never { throw new RedirectReached( $url, $status ); }
	function add_action( string $name, callable $callback ): void { $GLOBALS['hooks'][ $name ][] = $callback; }
	function add_submenu_page( string $parent, string $title, string $menu, string $cap, string $slug, callable $callback ): string { $GLOBALS['menus'][] = compact( 'parent', 'title', 'cap', 'slug' ); return $parent . '_page_' . $slug; }
	$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	require $root . 'Admin/ContributionUi.php'; require $root . 'Submissions/Admin.php'; require $root . 'Developers/Admin.php';
	use AISkillsHub\Core\Admin\ContributionUi as Ui;
	use AISkillsHub\Core\Submissions\Admin as Submissions;
	use AISkillsHub\Core\Developers\Admin as Developers;
	$assertions = 0;
	function check( bool $condition, string $message ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
	function rejected( callable $callback, string $message ): void { try { $callback(); } catch ( Throwable $error ) { check( true, $message ); return; } check( false, $message ); }
	function render( callable $callback ): string { ob_start(); $callback(); return (string) ob_get_clean(); }
	function dispatch( object $admin, array $post ): RedirectReached { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post + array( '_wpnonce' => 'fixture' ); try { $admin->handle(); } catch ( RedirectReached $redirect ) { return $redirect; } throw new RuntimeException( 'Expected redirect' ); }
	$sq = new AISkillsHub\Core\Submissions\Query(); $ss = new AISkillsHub\Core\Submissions\Service(); $submissions = new Submissions( $ss, $sq );
	$dq = new AISkillsHub\Core\Developers\Query(); $ds = new AISkillsHub\Core\Developers\Service(); $developers = new Developers( $ds, $dq );
	$sc = new ReflectionMethod( $submissions, 'commands' ); $dc = new ReflectionMethod( $developers, 'commands' );
	$key = str_repeat( 'a', 32 );
	$input = array( 'kind' => 'submit', 'id' => '7', 'revision' => '3', 'decision' => 'accept', 'feedback' => 'Accepted for editing.', 'private_note' => 'Internal only', 'result_skill_ids' => '4, 6', 'request_key' => $key, 'confirmed' => '1' );
	foreach ( array( array(), array( 'manage_options' => true, 'aish_review_skills' => true, 'aish_moderate_community' => true ) ) as $caps ) { $GLOBALS['caps'] = $caps; rejected( fn() => $sc->invoke( $submissions, $input ), 'unrelated capabilities cannot moderate submissions' ); }
	$GLOBALS['caps'] = array( Submissions::SUBMISSIONS => true ); $plan = $sc->invoke( $submissions, $input );
	check( $plan['commands'][0] === array( 7, 3, 'accept', 'Accepted for editing.', 'Internal only', array( 4, 6 ), $key ), 'acceptance binds exact revision, feedback, note and associations' );
	check( $sc->invoke( $submissions, $input + array( 'name' => 'forged name', 'user_id' => '99', 'post_author' => '99', 'status' => 'published' ) ) === $plan, 'moderator cannot rewrite original proposal or publish via extra fields' );
	$link = array_replace( $input, array( 'decision' => 'link_result', 'feedback' => '', 'result_skill_ids' => '' ) ); check( $sc->invoke( $submissions, $link )['commands'][0] === array( 7, 3, 'link_result', '', 'Internal only', array(), $key ), 'accepted associations can explicitly be replaced or cleared without rewriting content' );
	foreach ( array( array( 'id' => '7e0' ), array( 'id' => '-1' ), array( 'id' => '99999999999999999999' ), array( 'id' => array( '7' ) ), array( 'revision' => '0' ), array( 'revision' => '1.5' ), array( 'kind' => 'contact' ), array( 'decision' => 'publish' ), array( 'confirmed' => '0' ), array( 'request_key' => 'short' ), array( 'request_key' => str_repeat( 'x', 32 ) ), array( 'feedback' => ' ' ), array( 'private_note' => str_repeat( '中', 2001 ) ), array( 'private_note' => "bad\0note" ), array( 'private_note' => "\xFF" ), array( 'result_skill_ids' => '1,1' ), array( 'result_skill_ids' => '0' ), array( 'result_skill_ids' => '4);DROP TABLE' ), array( 'result_skill_ids' => implode( ',', range( 1, 21 ) ) ) ) as $change ) { rejected( fn() => $sc->invoke( $submissions, array_replace( $input, $change ) ), 'reject malformed submission command before dispatch' ); }
	$update = array_replace( $input, array( 'kind' => 'update_suggestion' ) ); rejected( fn() => $sc->invoke( $submissions, $update ), 'submission capability cannot handle update suggestions' );
	$GLOBALS['caps'] = array( Submissions::UPDATES => true ); check( $sc->invoke( $submissions, $update )['kind'] === 'update_suggestion', 'update suggestions use dedicated capability' ); rejected( fn() => $sc->invoke( $submissions, $input ), 'update capability cannot handle submissions' );
	$GLOBALS['caps'] = array( Submissions::SUBMISSIONS => true );
	$bulk = array_replace( $input, array( 'bulk' => '1', 'decision' => 'reject', 'result_skill_ids' => '', 'ids' => array( '7', '8' ), 'snapshots' => array( 7 => array( 'revision' => '3' ), 8 => array( 'revision' => '4' ) ) ) );
	$batch = $sc->invoke( $submissions, $bulk ); check( count( $batch['commands'] ) === 2 && $batch['commands'][1][1] === 4, 'batch captures separate revisions' );
	check( $batch['commands'][0][6] === $key . '_7' && $batch['commands'][1][6] === $key . '_8' && $sc->invoke( $submissions, $bulk ) === $batch, 'bounded batch has stable per-record retry identities' );
	foreach ( array( array( 'decision' => 'accept' ), array( 'ids' => array() ), array( 'ids' => array( '7', '7' ) ), array( 'ids' => array_fill( 0, 21, '7' ) ), array( 'snapshots' => array() ), array( 'result_skill_ids' => '1' ), array( 'bulk' => 'true' ) ) as $change ) { rejected( fn() => $sc->invoke( $submissions, array_replace( $bulk, $change ) ), 'bulk rejects acceptance, unbounded IDs and missing revisions' ); }
	rejected( fn() => $sc->invoke( $submissions, array_replace( $bulk, array( 'decision' => 'link_result' ) ) ), 'result associations require individual inspection, not bulk linking' );
	$profile = array( 'kind' => 'profile', 'id' => '7', 'revision' => '4', 'decision' => 'approve', 'feedback' => 'Verified original profile.', 'private_note' => 'Private', 'confirmed' => '1', 'request_key' => $key );
	$GLOBALS['caps'] = array( Developers::CLAIMS => true ); rejected( fn() => $dc->invoke( $developers, $profile ), 'claim capability cannot approve public profiles' );
	$GLOBALS['caps'] = array( Developers::PROFILES => true ); $profilePlan = $dc->invoke( $developers, $profile ); check( $profilePlan['commands'][0] === array( 7, 4, 'approve', 'Verified original profile.', 'Private', $key ), 'profile decision binds owner and revision' );
	check( $dc->invoke( $developers, $profile + array( 'bio' => 'forged bio', 'role' => 'administrator', 'handle' => 'forged' ) ) === $profilePlan, 'profile moderation cannot edit owner text or grant roles' );
	foreach ( array( array( 'id' => '0' ), array( 'id' => '7.0' ), array( 'id' => array( '7' ) ), array( 'revision' => '0' ), array( 'revision' => '99999999999999999999' ), array( 'decision' => 'grant_admin' ), array( 'feedback' => '短' ), array( 'feedback' => "\xFF" ), array( 'private_note' => str_repeat( 'x', 2001 ) ), array( 'confirmed' => '0' ), array( 'request_key' => '../path' ) ) as $change ) { rejected( fn() => $dc->invoke( $developers, array_replace( $profile, $change ) ), 'developer commands reject malformed identity, stale token shapes and unsafe action' ); }
	$claim = array_replace( $profile, array( 'kind' => 'claim', 'expected_state' => 'pending', 'verification' => array( 'evidence_checked' => '1', 'role_checked' => '1', 'source_checked' => '1' ) ) );
	rejected( fn() => $dc->invoke( $developers, $claim ), 'profile capability cannot approve claims' );
	$GLOBALS['caps'] = array( Developers::CLAIMS => true ); $claimPlan = $dc->invoke( $developers, $claim );
	check( $claimPlan['commands'][0] === array( 7, 'pending', 'approve', 'Verified original profile.', 'Private', $key, array( 'evidence_checked' => true, 'role_checked' => true, 'source_checked' => true ) ), 'claim approval binds state and all manual checks' );
	foreach ( array( array( 'verification' => array() ), array( 'verification' => '1' ), array( 'verification' => array( 'evidence_checked' => '1', 'role_checked' => '0', 'source_checked' => '1' ) ), array( 'expected_state' => 'published' ), array( 'feedback' => '' ), array( 'decision' => 'needs_information' ), array( 'revision' => '0', 'expected_state' => '' ) ) as $change ) { rejected( fn() => $dc->invoke( $developers, array_replace( $claim, $change ) ), 'invalid or unchecked claim decisions rejected' ); }
	$claimBulk = array_replace( $claim, array( 'bulk' => '1', 'decision' => 'dispute', 'ids' => array( '7', '8' ), 'snapshots' => array( 7 => array( 'expected_state' => 'approved' ), 8 => array( 'expected_state' => 'approved' ) ) ) );
	check( $dc->invoke( $developers, $claimBulk )['commands'][1][1] === 'approved', 'dispute batch forwards captured claim state' ); rejected( fn() => $dc->invoke( $developers, array_replace( $claimBulk, array( 'decision' => 'approve' ) ) ), 'claims cannot be batch approved' );
	foreach ( array( array( 'snapshots' => 'malformed' ), array( 'ids' => array( '7', '7' ) ), array( 'ids' => array_fill( 0, 21, '7' ) ), array( 'snapshots' => array( 7 => array( 'expected_state' => 'pending' ), 8 => array( 'expected_state' => 'approved' ) ) ) ) as $change ) { rejected( fn() => $dc->invoke( $developers, array_replace( $claimBulk, $change ) ), 'malformed or state-incompatible claim batch rejected before execution' ); }
	$GLOBALS['caps'] = array( Developers::PROFILES => true );
	$profileBulk = array_replace( $profile, array( 'bulk' => '1', 'ids' => array( '7' ), 'snapshots' => array( 7 => array( 'revision' => '4' ) ) ) );
	rejected( fn() => $dc->invoke( $developers, $profileBulk ), 'profiles cannot be batch approved' );
	$submissions->register(); $developers->register(); foreach ( $GLOBALS['hooks']['admin_menu'] as $callback ) { $callback(); }
	check( array_column( $GLOBALS['menus'], 'slug' ) === array( 'aish-submissions', 'aish-update-suggestions', 'aish-developers', 'aish-author-claims' ), 'four independent native menus registered' );
	check( array_column( $GLOBALS['menus'], 'cap' ) === array( Submissions::SUBMISSIONS, Submissions::UPDATES, Developers::PROFILES, Developers::CLAIMS ), 'menus retain distinct capabilities' );
	check( ! isset( $GLOBALS['hooks']['admin_post_nopriv_aish_submission_admin'], $GLOBALS['hooks']['admin_post_nopriv_aish_developer_admin'] ), 'no anonymous mutation hook' );
	foreach ( array( $submissions, $developers ) as $admin ) {
		$_SERVER['REQUEST_METHOD'] = 'GET'; rejected( fn() => $admin->handle(), 'GET cannot mutate' );
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( '_wpnonce' => 'bad' ); rejected( fn() => $admin->handle(), 'bad nonce rejected' );
		$_POST = array( '_wpnonce' => array( 'fixture' ) ); rejected( fn() => $admin->handle(), 'array nonce rejected' );
	}
	$GLOBALS['caps'] = array(); $_GET = array(); rejected( fn() => $submissions->submissionsScreen(), 'unauthorized submissions screen rejected' ); rejected( fn() => $developers->profilesScreen(), 'unauthorized profiles screen rejected' ); rejected( fn() => $developers->claimsScreen(), 'unauthorized claims screen rejected' ); check( $sq->calls === 0 && $dq->calls === 0, 'private records not queried before authorization' );
	$history = array( 'items' => array( array( 'id' => 1, 'action' => '<script>event</script>', 'subject_revision' => 3, 'actor_user_id' => 9, 'from_state' => 'submitted', 'to_state' => 'accepted', 'created_at' => '2026-11-06 00:00:00', 'metadata_json' => 'private opaque payload' ) ), 'page' => 1, 'per_page' => 10, 'total' => 1 );
	$sq->row = array( 'id' => 7, 'kind' => 'submit', 'user_id' => 20, 'revision' => 3, 'state' => 'submitted', 'name' => '<script>name</script>', 'summary' => 'summary', 'notes' => '<img src=x onerror=alert(1)>', 'locale' => 'en_US', 'payload' => array( 'name' => '<script>name</script>', 'source_path' => 'skills/A/SKILL.md', 'evidence' => '<img src=x onerror=alert(1)>' ), 'source' => array( 'canonical_repository' => 'owner/repo', 'source_path' => 'skills/A/SKILL.md', 'path_status' => 'known', 'existing_skill_ids' => array( 4 ) ), 'feedback' => '<script>feedback</script>', 'private_note' => '<script>private</script>', 'result_skill_ids' => array(), 'history' => $history, 'updated_at' => '2026-11-06 00:00:00' );
	$GLOBALS['caps'] = array( Submissions::SUBMISSIONS => true ); $_GET = array( 'id' => '7' ); $html = render( fn() => $submissions->submissionsScreen() );
	check( str_contains( $html, '&lt;script&gt;name&lt;/script&gt;' ) && ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img src=x' ), 'submission originals, feedback, history and notes are escaped' );
	check( ! str_contains( $html, 'name="name"' ) && ! str_contains( $html, 'name="summary"' ) && ! str_contains( $html, 'name="notes"' ), 'no moderator original-content editing controls' );
	check( str_contains( $html, 'name="revision" value="3"' ) && str_contains( $html, 'name="_wpnonce"' ), 'submission form captures revision and nonce' );
	check( str_contains( $html, 'does not mean a Skill is published' ) && ! str_contains( $html, 'private opaque payload' ), 'acceptance distinction explicit and opaque event JSON not dumped' );
	$sq->row['state'] = 'accepted'; $sq->row['result_skill_ids'] = array( 4 ); $html = render( fn() => $submissions->submissionsScreen() ); check( str_contains( $html, 'value="link_result"' ) && ! str_contains( $html, 'value="accept"' ) && str_contains( $html, 'The proposal remains accepted' ) && str_contains( $html, 'value="4"' ), 'accepted proposal exposes only bounded association maintenance' ); $sq->row['state'] = 'submitted';
	$sq->row['snapshots'] = array( 'items' => array( array( 'id' => 30, 'revision' => 1, 'kind' => 'content', 'created_at' => '2026-11-06 00:00:00', 'payload' => array( 'name' => '<script>old draft</script>' ) ), array( 'id' => 31, 'revision' => 2, 'kind' => 'moderation', 'created_at' => '2026-11-06 00:00:01', 'payload' => array( 'decision' => 'needs_information', 'feedback' => '<script>old feedback</script>', 'private_note' => '<script>old note</script>', 'handler_id' => 9, 'revision' => 2 ) ) ), 'total' => 2, 'page' => 1, 'per_page' => 10 );
	$html = render( fn() => $submissions->submissionsScreen() ); check( str_contains( $html, 'Original snapshot #30' ) && str_contains( $html, 'Decision snapshot #31' ) && str_contains( $html, '&lt;script&gt;old draft&lt;/script&gt;' ) && str_contains( $html, '&lt;script&gt;old feedback&lt;/script&gt;' ) && ! str_contains( $html, '<script>' ), 'immutable original and moderation snapshots remain distinct and escaped' );
	$redirect = dispatch( $submissions, $bulk ); check( $redirect->status === 303 && str_contains( $redirect->url, 'page=aish-submissions' ), 'submission result uses fixed internal redirect' ); check( $GLOBALS['notice'] === array( 'success' => 1, 'failed' => 1 ), 'partial batch failure reports actual outcome' );
	$sq->row['kind'] = 'update_suggestion'; $calls = count( $ss->calls ); dispatch( $submissions, $input ); check( count( $ss->calls ) === $calls && $GLOBALS['notice']['failed'] === 1, 'forged queue kind cannot dispatch other workflow record' ); $sq->row['kind'] = 'submit';
	$dq->profile = array( 'user_id' => 19, 'revision' => 4, 'state' => 'pending', 'opt_in' => true, 'draft' => array( 'handle' => 'public-person', 'github_url' => 'https://github.com/example', 'translations' => array( 'zh_CN' => array( 'display_name' => '<script>中文</script>', 'bio' => '真实简介' ) ) ), 'approved' => array( 'handle' => 'public-person', 'translations' => array( 'en_US' => array( 'display_name' => 'Old approved name', 'bio' => '<img src=x onerror=alert(1)>' ) ) ), 'feedback' => 'Feedback', 'private_note' => '<script>private</script>', 'history' => $history, 'updated_at' => '2026-11-06 00:00:00' );
	$GLOBALS['caps'] = array( Developers::PROFILES => true ); $_GET = array( 'id' => '19' ); $html = render( fn() => $developers->profilesScreen() );
	check( str_contains( $html, 'Old approved name' ) && str_contains( $html, '&lt;script&gt;中文&lt;/script&gt;' ), 'approved and pending profile versions displayed separately' );
	check( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img src=x' ), 'profile text and moderator notes escaped' );
	check( str_contains( $html, 'This language has not been provided' ) && ! str_contains( $html, 'name="bio"' ) && ! str_contains( $html, 'value="approve"' ), 'missing translation honest; moderators cannot edit original or self approve' );
	$dq->profile['decisions'] = array( 'items' => array( array( 'revision' => 2, 'decision' => 'approve', 'reason' => '<script>prior reason</script>', 'private_note' => '<script>prior note</script>', 'reviewer_id' => 9, 'at' => '2026-11-06 00:00:00', 'snapshot' => array( 'handle' => 'old-handle', 'translations' => array( 'en_US' => array( 'display_name' => 'Immutable inspected name', 'bio' => 'Original biography' ) ) ) ) ), 'total' => 1, 'page' => 1, 'per_page' => 20 );
	$dq->profile['avatar_preview'] = 'javascript:alert(1)'; $dq->profile['approved_avatar_preview'] = 'data:image/png;base64,aGVsbG8=';
	$html = render( fn() => $developers->profilesScreen() ); check( str_contains( $html, 'Immutable inspected name' ) && str_contains( $html, '&lt;script&gt;prior reason&lt;/script&gt;' ) && ! str_contains( $html, '<script>' ), 'prior profile decisions retain immutable inspected snapshot and escaped reasoning' );
	check( str_contains( $html, 'data:image/png;base64,aGVsbG8=' ) && ! str_contains( $html, 'javascript:alert' ), 'admin avatar review accepts only bounded internal PNG data projections' );
	$dq->claim = array( 'id' => 7, 'skill_id' => 4, 'claimant_user_id' => 20, 'claim_role' => 'maintainer', 'source_revision' => 5, 'repository_id' => 2, 'state' => 'pending', 'proof_kind' => 'repository_control', 'proof' => array( 'reason' => '<script>proof</script>', 'evidence_urls' => array( 'https://example.test/path?x=<script>' ), 'locale' => 'en_US' ), 'feedback' => 'Feedback', 'private_note' => '<script>private</script>', 'history' => $history, 'updated_at' => '2026-11-06 00:00:00' );
	$GLOBALS['caps'] = array( Developers::CLAIMS => true ); $_GET = array( 'id' => '7' ); $html = render( fn() => $developers->claimsScreen() );
	check( str_contains( $html, 'Repository Maintainer' ) && str_contains( $html, 'Original authorship' ) === false && str_contains( $html, 'original authorship requires' ), 'maintainer role and stronger author evidence explained' );
	check( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;proof&lt;/script&gt;' ), 'claim evidence treated as escaped inert content' );
	check( str_contains( $html, 'name="expected_state" value="pending"' ) && str_contains( $html, 'name="verification[evidence_checked]"' ) && str_contains( $html, 'name="verification[role_checked]"' ) && str_contains( $html, 'name="verification[source_checked]"' ), 'claim form contains state snapshot and three verification controls' );
	check( str_contains( $html, 'minlength="10"' ), 'claim reviewer reason requirement matches domain minimum' );
	check( ! str_contains( $html, 'name="claim_role"' ) && ! str_contains( $html, 'name="reason"' ) && ! str_contains( $html, 'name="evidence_urls"' ), 'moderator cannot author original claim proof or change role' );
	$dq->claim['state'] = 'revoked'; $html = render( fn() => $developers->claimsScreen() ); check( ! str_contains( $html, 'name="decision"' ) && ! str_contains( $html, 'value="approve"' ), 'terminal claim history has no mutation or reapproval controls' );
	$dq->claim['state'] = 'approved'; $html = render( fn() => $developers->claimsScreen() ); check( str_contains( $html, 'value="revoke"' ) && str_contains( $html, 'value="dispute"' ) && ! str_contains( $html, 'value="approve"' ), 'approved claim only offers revocation and dispute' ); $dq->claim['state'] = 'pending';
	$redirect = dispatch( $developers, $claimBulk ); check( str_contains( $redirect->url, 'page=aish-author-claims' ) && $GLOBALS['notice'] === array( 'success' => 1, 'failed' => 1 ), 'claim batch failures remain partial with fixed redirect' );
	$GLOBALS['locale'] = 'zh_CN'; $html = render( fn() => $developers->claimsScreen() ); check( str_contains( $html, '作者认领' ) && str_contains( $html, '确认处理' ), 'Chinese administrator labels supported' );
	$GLOBALS['caps'] = array( Submissions::SUBMISSIONS => true ); $_GET = array( 'state' => 'all', 's' => 'source', 'orderby' => 'created_at', 'order' => 'asc' ); $html = render( fn() => $submissions->submissionsScreen() ); check( $sq->filters['state'] === 'all' && $sq->filters['q'] === 'source' && $sq->filters['direction'] === 'asc', 'search, state, sort contracts forwarded' ); check( ! str_contains( $html, 'value="accept"' ), 'list contains no bulk acceptance' );
	$sq->row = null; $_GET = array( 'id' => '999' ); check( str_contains( render( fn() => $submissions->submissionsScreen() ), 'notice-error' ), 'missing submission handled without null dereference' );
	$dq->profile = null; $GLOBALS['caps'] = array( Developers::PROFILES => true ); check( str_contains( render( fn() => $developers->profilesScreen() ), 'notice-error' ), 'missing developer handled safely' );
	$dq->claim = null; $GLOBALS['caps'] = array( Developers::CLAIMS => true ); check( str_contains( render( fn() => $developers->claimsScreen() ), 'notice-error' ), 'missing claim handled safely' );
	echo "Contribution admin: {$assertions} isolated assertions passed (WP/query/service substitutes; no real WordPress/MySQL or external requests).\n";
}
