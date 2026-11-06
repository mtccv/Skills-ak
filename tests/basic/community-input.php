<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( string $s ): string { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $s ) ) ); }
function sanitize_textarea_field( string $s ): string { return strip_tags( $s ); }
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Community/Exception.php';
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Community/Input.php';
use AISkillsHub\Core\Community\Input;
use AISkillsHub\Core\Community\Exception;

$assertions = 0;
function check( bool $value ): void { global $assertions; ++$assertions; if ( ! $value ) { throw new RuntimeException( 'Community input assertion failed.' ); } }
function refuses( callable $fn ): void { try { $fn(); } catch ( Exception $error ) { check( 'invalid_input' === $error->reason ); return; } throw new RuntimeException( 'Unsafe input was accepted.' ); }
$payload = array( 'locale' => 'en_US', 'rating' => 4, 'title' => 'A real experience', 'comment' => 'This is my actual review.' );
foreach ( range( 1, 5 ) as $rating ) { check( Input::review( array_replace( $payload, array( 'rating' => $rating ) ) )['rating'] === $rating ); check( Input::review( array_replace( $payload, array( 'rating' => (string) $rating ) ) )['rating'] === $rating ); }
foreach ( array( 0, 6, -1, 1.0, true, false, null, '01', '2.5', '1 OR 1=1', array( 5 ) ) as $rating ) { refuses( fn() => Input::review( array_replace( $payload, array( 'rating' => $rating ) ) ) ); }
foreach ( array( 'user_id', 'approved_revision_id', 'state', 'moderator_id', 'private_note', 'skill_id', 'request_key' ) as $key ) { refuses( fn() => Input::review( $payload + array( $key => 1 ) ) ); }
refuses( fn() => Input::review( array_replace( $payload, array( 'locale' => 'fr_FR' ) ) ) );
refuses( fn() => Input::review( array_replace( $payload, array( 'title' => '' ) ) ) );
refuses( fn() => Input::review( array_replace( $payload, array( 'title' => str_repeat( 'a', 192 ) ) ) ) );
refuses( fn() => Input::review( array_replace( $payload, array( 'comment' => 'four' ) ) ) );
refuses( fn() => Input::review( array_replace( $payload, array( 'comment' => "valid text\0secret" ) ) ) );
refuses( fn() => Input::review( array_replace( $payload, array( 'platform' => str_repeat( 'a', 101 ) ) ) ) );
refuses( fn() => Input::review( array_replace( $payload, array( 'version_label' => str_repeat( 'a', 192 ) ) ) ) );
refuses( fn() => Input::review( array_replace( $payload, array( 'pros' => str_repeat( '好', 2001 ) ) ) ) );
refuses( fn() => Input::review( array_replace( $payload, array( 'comment' => str_repeat( 'https://spam.invalid ', 6 ) ) ) ) );
$zh = Input::review( array_replace( $payload, array( 'locale' => 'zh_CN', 'title' => '真实使用体验', 'comment' => '这是实际使用中的体验。', 'experience' => "第一步\n第二步", 'pros' => '说明清楚', 'cons' => '仍有限制' ) ) );
check( $zh['locale'] === 'zh_CN' && str_contains( $zh['experience'], "\n" ) );
check( Input::review( array_replace( $payload, array( 'comment' => '<script>alert(1)</script> real review' ) ) )['comment'] === 'alert(1) real review' );
check( Input::review( $payload )['platform'] === '' );
foreach ( Input::REVIEW_REASONS as $reason ) { check( Input::report( array( 'locale' => 'en_US', 'reason_code' => $reason, 'details' => 'Actual report details' ), 'review' )['reason_code'] === $reason ); }
foreach ( Input::SKILL_REASONS as $reason ) { check( Input::report( array( 'locale' => 'zh_CN', 'kind' => $reason, 'details' => '发现实际内容问题。', 'skill_revision' => 3 ), 'skill' )['skill_revision'] === 3 ); }
refuses( fn() => Input::report( array( 'locale' => 'en_US', 'reason_code' => 'delete_immediately', 'details' => 'Actual report details' ), 'review' ) );
refuses( fn() => Input::report( array( 'locale' => 'en_US', 'kind' => 'security', 'details' => 'Actual report details', 'skill_revision' => 0 ), 'skill' ) );
refuses( fn() => Input::report( array( 'locale' => 'en_US', 'reason_code' => 'spam', 'details' => 'x' ), 'review' ) );
refuses( fn() => Input::report( array( 'locale' => 'en_US', 'reason_code' => 'spam', 'details' => 'Actual report details', 'reporter_user_id' => 99 ), 'review' ) );
foreach ( array( '', 'short', str_repeat( 'x', 129 ), str_repeat( 'x', 16 ) . '!', '../etc/passwd' ) as $key ) { refuses( fn() => Input::request( $key ) ); }
check( Input::request( str_repeat( 'a', 32 ) ) === str_repeat( 'a', 32 ) );
check( Input::id( '9223372036854775807' ) === PHP_INT_MAX );
refuses( fn() => Input::id( '9223372036854775808' ) );
check( Input::id( 0, true ) === 0 );
echo "Community input: $assertions isolated assertions passed; no WordPress/MySQL integration.\n";
