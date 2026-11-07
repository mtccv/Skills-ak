<?php
declare(strict_types=1);

// Pure editorial input and optimistic-revision contract; no WordPress/MySQL.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( string $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $value ) ) ); }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
$core = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
require $core . 'Security/Input.php'; require $core . 'Discovery/Input.php'; require $core . 'Discovery/Management.php';
use AISkillsHub\Core\Discovery\Input;
use AISkillsHub\Core\Discovery\Management;
$count = 0;
function check( bool $value, string $label ): void { global $count; if ( ! $value ) { throw new RuntimeException( $label ); } ++$count; }
function rejects( callable $callback, string $label ): void { try { $callback(); } catch ( InvalidArgumentException $e ) { check( true, $label ); return; } throw new RuntimeException( $label ); }
check( 12 === Input::integer( '12' ) && 0 === Input::integer( 0 ), 'Native form integer strings are supported exactly.' );
foreach ( array( '01', '-1', '+1', '1e3', ' 1', 1.0, true, null, array(), '9223372036854775808' ) as $value ) { rejects( fn() => Input::integer( $value ), 'Malformed, overflowing or lossy identity is rejected.' ); }
check( array( 9, 4 ) === Input::ids( array( '9', 4 ) ), 'Editor-selected Skill order is retained.' );
foreach ( array( array( 1, '1' ), array( -1 ), array( 0 ), array( 'x' => 1 ), range( 1, 201 ) ) as $value ) { rejects( fn() => Input::ids( $value ), 'Membership is bounded, unique and strictly typed.' ); }
check( null === Input::date( '' ) && '2028-02-29 12:34:56' === Input::date( '2028-02-29 12:34:56' ), 'Nullable UTC schedule and leap day are accepted.' );
foreach ( array( '2026-02-29 12:00:00', '2026-01-01T12:00:00', '2026-01-01 12:00:00+08:00', '2026-01-01 24:00:00', '1999-12-31 00:00:00', '2101-01-01 00:00:00', array() ) as $value ) { rejects( fn() => Input::date( $value ), 'Invalid or ambiguous schedule rejected.' ); }
$payload = array( 'status' => 'published', 'sort_order' => '4', 'skills' => array( 18, 2 ), 'translations' => array( 'zh_CN' => array( 'title' => '开发精选', 'slug' => 'development', 'description' => '真实编辑说明' ), 'en_US' => array( 'title' => 'Development', 'slug' => 'development', 'description' => 'Editorial description.' ) ), 'published_at' => '2026-11-07 12:00:00', 'ends_at' => '2026-11-08 12:00:00' );
$clean = Input::entity( 'collection', $payload );
check( $clean['translations']['zh_CN']['title'] === '开发精选' && count( $clean['translations'] ) === 2, 'Actual bilingual copy shares one collection payload.' );
check( 4 === $clean['sort_order'] && array( 18, 2 ) === $clean['skills'], 'Ordering and stable Skill identities survive validation.' );
$single = $payload; unset( $single['translations']['zh_CN'] );
check( array( 'en_US' ) === array_keys( Input::entity( 'collection', $single )['translations'] ), 'Missing Chinese remains absent.' );
$single['translations']['zh_CN'] = array( 'title' => '', 'slug' => '', 'description' => '' );
check( array( 'en_US' ) === array_keys( Input::entity( 'collection', $single )['translations'] ), 'Blank language forms do not create synthetic translations.' );
$incomplete = $payload; $incomplete['translations']['en_US']['description'] = '';
check( Input::entity( 'collection', $incomplete )['translations']['en_US']['description'] === '', 'Complete Chinese may publish while the actual English draft stays incomplete.' );
$incomplete['translations']['en_US']['slug'] = null;
check( null === Input::entity( 'collection', $incomplete )['translations']['en_US']['slug'], 'An unfinished secondary-language URL is retained as missing, never generated.' );
foreach ( array(
	array_replace( $payload, array( 'status' => 'approved' ) ),
	array_replace( $payload, array( 'sort_order' => '0 OR 1=1' ) ),
	array_replace( $payload, array( 'owner_user_id' => 1 ) ),
	array_replace( $payload, array( 'translations' => array() ) ),
	array_replace( $payload, array( 'ends_at' => $payload['published_at'] ) ),
	array_replace( $payload, array( 'tutorial_ids' => range( 1, 21 ) ) ),
	array_replace( $payload, array( 'translations' => array( 'fr_FR' => array( 'title' => 'Wrong locale' ) ) ) ),
	array_replace( $payload, array( 'translations' => array( 'en_US' => array( 'title' => 'Name without slug' ) ) ) ),
	array_replace( $payload, array( 'translations' => array( 'en_US' => array( 'title' => 'Name', 'slug' => 'empty-description', 'description' => '  ' ) ) ) ),
	array_replace( $payload, array( 'translations' => array( 'en_US' => array( 'title' => 'Name', 'slug' => 'unicode-spaces', 'description' => "\u{3000}\u{00a0}" ) ) ) ),
	array_replace( $payload, array( 'translations' => array( 'en_US' => array( 'title' => "\u{3000}", 'slug' => 'empty-title', 'description' => 'Description' ) ) ) ),
	array_replace( $payload, array( 'translations' => array( 'en_US' => array( 'title' => 'Name', 'slug' => '../escape' ) ) ) ),
	array_replace( $payload, array( 'translations' => array( 'en_US' => array( 'title' => 'Name', 'slug' => 'good', 'id' => 4 ) ) ) ),
) as $bad ) { rejects( fn() => Input::entity( 'collection', $bad ), 'Invalid editorial mutation fails before persistence.' ); }
rejects( fn() => Input::entity( 'use_case', $payload ), 'A use case cannot silently acquire an unplanned schedule column.' );
rejects( fn() => Input::text( "hello\0world", 100 ), 'Embedded NUL rejected.' );
rejects( fn() => Input::text( str_repeat( '中', 101 ), 100 ), 'Unicode character limit is enforced.' );
rejects( fn() => Input::text( "\xff", 100 ), 'Invalid UTF-8 rejected.' );
rejects( fn() => Input::termKind( 'platform' ), 'No second platform taxonomy is created.' );
rejects( fn() => Input::kind( 'submissions' ), 'No arbitrary table selection.' );
$plain = Input::translations( array( 'en_US' => array( 'name' => '<b>Category</b>', 'description' => '<img src=x onerror=bad>Plain description.' ) ), 'category' );
check( $plain['en_US'] === array( 'name' => 'Category', 'description' => 'Plain description.' ), 'Editorial term content is plain text.' );
$before = array( 'zh_CN' => array( 'id' => 10, 'name' => '中文', 'description' => '说明', 'updated_at' => 'old' ), 'en_US' => array( 'id' => 11, 'name' => 'English', 'description' => '' ) );
$reordered = array( 'en_US' => $before['en_US'], 'zh_CN' => $before['zh_CN'] ); $reordered['en_US']['updated_at'] = 'new';
check( Management::termDigest( $before ) === Management::termDigest( $reordered ), 'Translation revision ignores row order and irrelevant database metadata.' );
$reordered['zh_CN']['description'] = '另一位编辑修改了内容';
check( Management::termDigest( $before ) !== Management::termDigest( $reordered ), 'Meaningful concurrent edits change the expected revision.' );
check( Management::termDigest( array() ) !== Management::termDigest( $before ), 'First translation insertion conflicts with an obsolete empty form.' );
echo 'Discovery input: ' . $count . " isolated assertions passed (sanitizer stubs; no real WordPress/MySQL).\n";
