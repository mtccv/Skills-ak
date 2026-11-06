<?php
declare(strict_types=1);
// Isolated validation and protected-metadata decoding, not WordPress/MySQL integration.
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_parse_url( string $value ): mixed { return parse_url( $value ); }
function esc_url_raw( string $value, array $protocols = array() ): string { return $value; }
function is_serialized( string $value ): bool { return (bool) preg_match( '/^(?:[abisOd]:|N;)/', $value ); }
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Community/Exception.php', 'Community/Input.php', 'Developers/Exception.php', 'Developers/Input.php', 'Developers/Metadata.php' ) as $file ) { require $base . $file; }
use AISkillsHub\Core\Developers\Input;
use AISkillsHub\Core\Developers\Exception;
use AISkillsHub\Core\Developers\Metadata;
$count = 0;
function check( bool $ok, string $why ): void { global $count; ++$count; if ( ! $ok ) { throw new RuntimeException( $why ); } }
function refuses( callable $operation, string $why ): void { try { $operation(); } catch ( Exception $e ) { check( true, $why ); return; } throw new RuntimeException( 'Unexpected acceptance: ' . $why ); }
$profile = array( 'handle' => 'resource-maintainer', 'github_url' => 'https://github.com/example', 'website_url' => 'https://example.com/about', 'opt_in' => true, 'public_avatar' => false, 'show_contributions' => true, 'translations' => array( 'en_US' => array( 'display_name' => 'Resource Maintainer', 'bio' => 'I maintain developer resources.', 'seo_title' => 'Developer profile' ) ) );
$clean = Input::profile( $profile );
check( $clean['translations']['en_US']['display_name'] === 'Resource Maintainer', 'Real English snapshot input is preserved.' );
check( ! isset( $clean['translations']['zh_CN'] ), 'No fabricated missing Chinese translation.' );
$zh = Input::profile( array_replace( $profile, array( 'translations' => array( 'zh_CN' => array( 'display_name' => '资源维护者', 'bio' => '维护真实开发者资源。' ) ) ) ) );
check( ! isset( $zh['translations']['en_US'] ) && $zh['translations']['zh_CN']['display_name'] === '资源维护者', 'Chinese input remains independent.' );
foreach ( array( 'user_id', 'reviewer_id', 'approved', 'revision', 'private_note', 'roles', 'avatar', 'user_login', 'user_email' ) as $key ) { refuses( fn() => Input::profile( $profile + array( $key => 'forged' ) ), 'Protected identity input rejected.' ); }
foreach ( array( '', 'aa', 'Admin', 'admin', 'administrator', 'a--b', 'a_b', 'a@b', '../etc/passwd', '-alice', 'alice-', str_repeat( 'a', 51 ), array( 'evil' ), 123 ) as $handle ) { refuses( fn() => Input::profile( array_replace( $profile, array( 'handle' => $handle ) ) ), 'Handle remains an independent bounded slug.' ); }
foreach ( array( 'javascript:alert(1)', 'http://example.com', 'https://localhost/', 'https://127.0.0.1/', 'https://[::1]/', 'https://host.internal/', 'https://host.local/', 'https://user:pass@example.com/', 'https://example.com:8443/', "https://example.com/\nprivate", 'https://example.com/?access_token=secret', 'https://example.com/?API_KEY=private' ) as $url ) { refuses( fn() => Input::url( $url ), 'Unsafe or credential URL rejected without fetching.' ); }
foreach ( array( 'https://github.com/example/repo', 'https://github.com/example?tab=repositories', 'https://github.com.evil.test/example', 'https://github.com/example#token' ) as $url ) { refuses( fn() => Input::url( $url, true ), 'GitHub profile URL must be exact public owner root.' ); }
check( Input::url( 'https://example.com/evidence?line=10#section' ) === 'https://example.com/evidence?line=10#section', 'Evidence locations allow non-secret query and fragment.' );
refuses( fn() => Input::profile( array_replace( $profile, array( 'translations' => array() ) ) ), 'Opt-in without any real complete language is refused.' );
check( ! Input::profile( array_replace( $profile, array( 'opt_in' => false, 'translations' => array() ) ) )['opt_in'], 'A private draft can omit translations.' );
refuses( fn() => Input::profile( array_replace( $profile, array( 'translations' => array( 'fr_FR' => array( 'display_name' => 'Nom', 'bio' => 'Texte' ) ) ) ) ), 'Unknown locale rejected.' );
refuses( fn() => Input::profile( array_replace( $profile, array( 'public_avatar' => 'yes' ) ) ), 'Boolean controls are strict.' );
refuses( fn() => Input::text( '-----BEGIN PRIVATE KEY-----abc', 100 ), 'Private key must not be stored as public evidence.' );
refuses( fn() => Input::text( 'ghp_' . str_repeat( 'A', 36 ), 100 ), 'Recognizable token must not enter metadata.' );
refuses( fn() => Input::text( "Valid name\0private", 100 ), 'Control characters rejected.' );
$claim = array( 'claim_role' => 'author', 'locale' => 'en_US', 'source_revision' => 7, 'proof_kind' => 'license_credit', 'evidence_urls' => array( 'https://github.com/example/repo/blob/main/LICENSE' ), 'reason' => 'The linked copyright record credits my original work.' );
check( Input::claim( $claim )['source_revision'] === 7, 'Stable Skill source revision remains explicit.' );
check( Input::claim( array_replace( $claim, array( 'claim_role' => 'maintainer', 'proof_kind' => 'repository_control' ) ) )['claim_role'] === 'maintainer', 'Repository control is a maintainer claim.' );
refuses( fn() => Input::claim( array_replace( $claim, array( 'proof_kind' => 'repository_control' ) ) ), 'Repository owner is not automatically original author.' );
foreach ( array( 'submitter', 'administrator', 'owner' ) as $role ) { refuses( fn() => Input::claim( array_replace( $claim, array( 'claim_role' => $role ) ) ), 'Unrelated roles cannot be promoted by a claim.' ); }
foreach ( array( 'claimant_user_id', 'approved_skill_key', 'reviewer_id', 'state', 'proof_json', 'request_key' ) as $key ) { refuses( fn() => Input::claim( $claim + array( $key => 1 ) ), 'Ownership and moderation cannot be submitted.' ); }
foreach ( array( array(), array_fill( 0, 6, 'https://example.com/evidence' ), array( '' ), array( 1 ), array( 'javascript:alert(1)' ) ) as $urls ) { refuses( fn() => Input::claim( array_replace( $claim, array( 'evidence_urls' => $urls ) ) ), 'Bounded explicit evidence is required.' ); }
foreach ( array( 0, true, '1 OR 1=1', 1.1 ) as $revision ) { refuses( fn() => Input::claim( array_replace( $claim, array( 'source_revision' => $revision ) ) ), 'Source version requires positive integer.' ); }
check( count( Input::claim( array_replace( $claim, array( 'evidence_urls' => array_merge( $claim['evidence_urls'], $claim['evidence_urls'] ) ) ) )['evidence_urls'] ) === 1, 'Duplicate evidence links are normalized.' );
check( Input::text( '<script>fake()</script> actual evidence', 100 ) === 'fake() actual evidence', 'Untrusted markup is stored only as plain text.' );
check( Input::request( str_repeat( 'a', 32 ) ) === str_repeat( 'a', 32 ), 'Opaque request token accepted.' );
refuses( fn() => Input::request( '../wrong' ), 'Arbitrary request identifiers rejected.' );
check( Metadata::decode( serialize( array( 'translations' => $zh['translations'] ) ) )['translations'] === $zh['translations'], 'Native arrays decode without executing objects.' );
check( Metadata::decode( 'b:0;' ) === false && Metadata::decode( '' ) === '', 'Legitimate false and empty scalar remain explicit.' );
refuses( fn() => Metadata::decode( str_repeat( 'x', 131073 ) ), 'Oversized metadata fails closed.' );
refuses( fn() => Metadata::decode( 'a:1:{invalid' ), 'Invalid serialization fails closed.' );
class ForbiddenWakeup { public function __wakeup(): void { throw new RuntimeException( 'Object execution is forbidden.' ); } }
refuses( fn() => Metadata::decode( serialize( new ForbiddenWakeup() ) ), 'Top-level object never executes.' );
refuses( fn() => Metadata::decode( serialize( array( 'nested' => new ForbiddenWakeup() ) ) ), 'Nested object never executes.' );
$recursive = array(); $recursive['self'] =& $recursive;
refuses( fn() => Metadata::decode( serialize( $recursive ) ), 'Recursive metadata fails closed.' );
check( Input::proofDocument( array( 'reason' => '真实证据' ) ) === '{"reason":"真实证据"}', 'Unicode proof remains valid compact JSON within TEXT byte limits.' );
refuses( fn() => Input::proofDocument( array( 'reason' => str_repeat( '字', 20000 ) ) ), 'Oversized accumulated decision history fails before TEXT truncation.' );
refuses( fn() => Input::proofDocument( array( 'invalid' => "\xFF" ) ), 'Invalid JSON encoding fails before storage mutation.' );
echo "Developer input/metadata: $count isolated assertions passed; no WordPress, MySQL, or external requests.\n";
