<?php
declare(strict_types=1);
/** Isolated parsing tests. No WordPress runtime, database or remote service is used. */
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( string $s ): string { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $s ) ) ); }
function sanitize_textarea_field( string $s ): string { return strip_tags( $s ); }
$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Community/Exception.php', 'Community/Input.php', 'Security/Input.php', 'Sources/GitHubClient.php', 'Submissions/Exception.php', 'Submissions/Input.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Submissions\Input;
use AISkillsHub\Core\Submissions\Exception;
$assertions = 0;
function check( bool $condition ): void { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( 'Submission input assertion failed.' ); } }
function refuses( callable $operation, string $reason = 'invalid_input' ): void { try { $operation(); } catch ( Exception $e ) { check( $e->reason === $reason ); return; } throw new RuntimeException( 'Unsafe contribution input was accepted.' ); }
$base = array( 'kind' => 'submit', 'locale' => 'en_US', 'source_kind' => 'github', 'github_url' => 'https://github.com/Example/Skills.git', 'source_path' => 'skills/alpha/SKILL.md', 'name' => 'Original resource', 'summary' => 'Original factual summary.', 'notes' => 'Manual editorial review requested.' );
$normalized = Input::payload( $base, true );
check( $normalized['github_url'] === 'https://github.com/example/skills' );
check( $normalized['source_path'] === 'skills/alpha/SKILL.md' );
check( $normalized['target_skill_id'] === 0 && $normalized['target_skill_revision'] === 0 );
foreach ( array( null, '' ) as $unknown ) { check( Input::payload( array_replace( $base, array( 'source_path' => $unknown ) ), true )['source_path'] === null ); }
foreach ( array( 'skills/beta/SKILL.md', 'skills/Alpha/SKILL.md', 'SKILL.md', '技能/说明/SKILL.md', 'literal%2Fdirectory/SKILL.md' ) as $path ) { check( Input::payload( array_replace( $base, array( 'source_path' => $path ) ), true )['source_path'] === $path ); }
foreach ( array( '../SKILL.md', '/SKILL.md', './SKILL.md', 'x//SKILL.md', 'x/../SKILL.md', 'x/./SKILL.md', 'x\\SKILL.md', 'C:/SKILL.md', 'c:SKILL.md', "x/\0SKILL.md", 'x /SKILL.md', 'x/skill.md', 'x/README.md', str_repeat( 'a', 765 ) . '/SKILL.md' ) as $path ) { refuses( fn() => Input::payload( array_replace( $base, array( 'source_path' => $path ) ), true ), 'invalid_source' ); }
foreach ( array( 'http://github.com/example/skills', 'https://evil.invalid/example/skills', 'https://github.com.evil.invalid/example/skills', 'https://github.com@example.invalid/example/skills', 'https://token@github.com/example/skills', 'https://github.com:443/example/skills', 'https://github.com/example/skills/tree/main', 'https://github.com/example/skills?token=secret', 'https://github.com/example/skills#readme', 'file:///etc/passwd', 'https://127.0.0.1/example/skills', "https://github.com/example/skills\nInjected", str_repeat( 'x', 2049 ) ) as $url ) { refuses( fn() => Input::payload( array_replace( $base, array( 'github_url' => $url ) ), true ), 'invalid_source' ); }
foreach ( array( 'user_id', 'post_author', 'role', 'state', 'result_skill_ids', 'handler_id', 'private_note', 'verified', 'accepted', 'source_revision' ) as $field ) { refuses( fn() => Input::payload( $base + array( $field => 1 ), true ) ); }
foreach ( array( array( 'kind' => 'contact' ), array( 'locale' => 'fr_FR' ), array( 'source_kind' => 'url' ), array( 'name' => '' ), array( 'summary' => 'four' ), array( 'notes' => 'four' ), array( 'name' => str_repeat( '中', 192 ) ), array( 'notes' => "Valid\0text" ), array( 'summary' => "Invalid\xffUTF8" ), array( 'categories' => array( 'unsafe' ) ), array( 'target_skill_id' => 2 ), array( 'commit_sha' => str_repeat( 'a', 40 ) ) ) as $fields ) { refuses( fn() => Input::payload( array_replace( $base, $fields ), true ) ); }
$partial = array( 'kind' => 'submit', 'locale' => 'zh_CN', 'source_kind' => 'github' );
check( Input::payload( $partial, false )['name'] === '' );
refuses( fn() => Input::payload( $partial, true ) );
$manual = array_replace( $base, array( 'source_kind' => 'manual', 'github_url' => '', 'source_path' => null ) );
check( Input::payload( $manual, true )['source_kind'] === 'manual' );
refuses( fn() => Input::payload( array_replace( $manual, array( 'github_url' => $base['github_url'] ) ), true ), 'invalid_source' );
refuses( fn() => Input::payload( array_replace( $manual, array( 'source_path' => 'SKILL.md' ) ), true ), 'invalid_source' );
$zh = Input::payload( array_replace( $manual, array( 'locale' => 'zh_CN', 'name' => '原始中文资源', 'summary' => '根据实际资料提供的介绍。', 'notes' => "第一行说明\n第二行说明" ) ), true );
check( $zh['name'] === '原始中文资源' && str_contains( $zh['notes'], "\n" ) );
check( Input::payload( array_replace( $manual, array( 'notes' => '<script>do not execute</script> original notes' ) ), true )['notes'] === 'do not execute original notes' );
$update = array_replace( $base, array( 'kind' => 'update_suggestion', 'target_skill_id' => '12', 'target_skill_revision' => '3', 'commit_sha' => str_repeat( 'a', 40 ), 'evidence' => 'Verifiable source evidence.' ) );
check( Input::payload( $update, true )['target_skill_id'] === 12 );
check( Input::payload( array_replace( $update, array( 'commit_sha' => str_repeat( 'b', 64 ) ) ), true )['commit_sha'] === str_repeat( 'b', 64 ) );
foreach ( array( array( 'target_skill_id' => 0 ), array( 'target_skill_revision' => 0 ), array( 'target_skill_id' => '1 OR 1=1' ), array( 'target_skill_id' => '01' ), array( 'target_skill_revision' => true ), array( 'commit_sha' => '' ), array( 'commit_sha' => str_repeat( 'z', 40 ) ), array( 'commit_sha' => str_repeat( 'a', 39 ) ), array( 'evidence' => '' ) ) as $fields ) { refuses( fn() => Input::payload( array_replace( $update, $fields ), true ) ); }
check( Input::payload( array_replace( $update, array( 'commit_sha' => '', 'evidence' => '' ) ), false )['commit_sha'] === '' );
check( Input::cap( 'submit' ) !== Input::cap( 'update_suggestion' ) );
foreach ( array( '', 'short', '../etc/passwd', str_repeat( 'x', 129 ), str_repeat( 'x', 16 ) . '!' ) as $key ) { refuses( fn() => Input::request( $key ) ); }
Input::request( str_repeat( 'a', 32 ) ); check( true );
echo "Submission input: $assertions isolated assertions passed; no WordPress/MySQL or remote API integration.\n";
