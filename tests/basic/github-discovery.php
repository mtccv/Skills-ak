<?php
declare(strict_types=1);

// Pure state-machine fixtures: no GitHub requests, WordPress bootstrap, filesystem scans or MySQL.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
require $root . 'Security/Input.php';
require $root . 'Sources/Discovery.php';

use AISkillsHub\Core\Sources\Discovery;

$assertions = 0;
function check( bool $condition, string $message ): void { global $assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$assertions; }
function rejects( callable $call, string $expected ): void {
	try { $call(); }
	catch ( InvalidArgumentException | RuntimeException $error ) { check( $error->getMessage() === $expected, 'Unexpected failure: ' . $error->getMessage() . ' instead of ' . $expected ); return; }
	throw new RuntimeException( 'Expected failure: ' . $expected );
}
function sha( string $character ): string { return str_repeat( $character, 40 ); }
function entry( string $path, string $type = 'blob', string $mode = '100644', string $sha = '', ?int $size = 20 ): array {
	return array( 'path' => $path, 'type' => $type, 'mode' => $mode, 'sha' => $sha ?: sha( 'b' ), 'size' => $size );
}
function tree( array $entries, string $sha = '', bool $truncated = false ): array { return array( 'sha' => $sha ?: sha( 'a' ), 'truncated' => $truncated, 'tree' => $entries ); }

$initial = Discovery::begin( sha( 'a' ) );
check( ! Discovery::complete( $initial ) && array() === Discovery::candidates( $initial ), 'Starting state is not complete.' );
$request = Discovery::request( $initial );
check( true === $request['recursive'] && '' === $request['path'] && sha( 'a' ) === $request['sha'], 'First request pins recursive root tree.' );
$response = tree( array( entry( 'z', 'tree', '040000', sha( 'c' ), null ), entry( 'z/SKILL.md' ), entry( 'SKILL.md', 'blob', '100755' ), entry( 'skill.md' ), entry( 'z/readme.md' ) ) );
$complete = Discovery::accept( $initial, $response, $request );
check( Discovery::complete( $complete ) && null === Discovery::request( $complete ), 'Nontruncated full tree completes.' );
check( array( 'SKILL.md', 'z/SKILL.md' ) === array_column( Discovery::candidates( $complete ), 'path' ), 'Exact uppercase filename discovers multiple stable paths including root.' );
check( '100755' === Discovery::candidates( $complete )[0]['mode'], 'Executable regular file is metadata only; nothing is executed.' );
check( count( Discovery::entries( $complete ) ) === 5, 'Full inventory retains nearby metadata for risk checks.' );
check( $initial['entries'] === array() && ! Discovery::complete( $initial ), 'accept does not mutate the prior checkpoint.' );
check( Discovery::accept( $complete, $response, $request ) === $complete, 'Exact captured-response replay is idempotent after completion.' );
check( Discovery::complete( json_decode( json_encode( $complete, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR ) ), 'Checkpoint survives JSON round trip.' );
$conflicting = $response; $conflicting['tree'][1]['sha'] = sha( 'd' );
rejects( fn() => Discovery::accept( $complete, $conflicting, $request ), 'discovery_conflicting_response' );

// Even unsafe-looking partial paths are discarded, never treated as an authoritative inventory.
$fallback = Discovery::accept( $initial, tree( array( entry( '../discarded/SKILL.md' ) ), '', true ), $request );
check( ! Discovery::complete( $fallback ) && array() === Discovery::entries( $fallback ), 'Truncated recursive tree discards its partial inventory.' );
check( array( 'recursive_tree_truncated_fallback' ) === $fallback['warnings'], 'Fallback retains an honest truncated warning.' );
$directRoot = Discovery::request( $fallback );
check( false === $directRoot['recursive'] && '' === $directRoot['path'] && sha( 'a' ) === $directRoot['sha'], 'Fallback starts direct pinned root.' );
$directResponse = tree( array( entry( 'first', 'tree', '040000', sha( 'c' ), null ), entry( 'second', 'tree', '040000', sha( 'c' ), null ), entry( 'external', 'commit', '160000', sha( 'd' ), null ), entry( 'link', 'blob', '120000' ) ) );
$walking = Discovery::accept( $fallback, $directResponse, $directRoot );
check( ! Discovery::complete( $walking ) && count( $walking['pending'] ) === 2, 'Only real directories are queued, excluding symlinks and submodules.' );
check( in_array( 'symlink_not_followed', $walking['warnings'], true ) && in_array( 'submodule_not_followed', $walking['warnings'], true ), 'Unsupported dependencies are represented as warning metadata.' );
$firstRequest = Discovery::request( $walking );
$child = tree( array( entry( 'SKILL.md' ) ), sha( 'c' ) );
$walking = Discovery::accept( $walking, $child, $firstRequest );
check( 'second' === Discovery::request( $walking )['path'], 'Identical subtree SHA at another path stays in the queue.' );
check( Discovery::accept( $walking, $child, $firstRequest ) === $walking, 'Replayed first path does not consume same-SHA second path.' );
$walking = Discovery::accept( $walking, $child, Discovery::request( $walking ) );
check( Discovery::complete( $walking ), 'Fallback completes only after all direct trees.' );
check( array( 'first/SKILL.md', 'second/SKILL.md' ) === array_column( Discovery::candidates( $walking ), 'path' ), 'Same content and name in separate source paths remains separate.' );
check( count( $walking['accepted'] ) === 4, 'All checkpointed requests remain identifiable.' );
rejects( fn() => Discovery::accept( $fallback, tree( array(), '', true ), $directRoot ), 'discovery_truncated_direct_tree' );
check( ! Discovery::complete( $fallback ), 'A truncated direct tree cannot mutate checkpoint into completion.' );
$sized = Discovery::fallback( $initial );
check( ! Discovery::request( $sized )['recursive'] && $sized['warnings'] === array( 'recursive_response_size_limit' ), 'Oversized recursive response has explicit reliable traversal fallback.' );
rejects( fn() => Discovery::fallback( $sized ), 'discovery_fallback_not_available' );
rejects( fn() => Discovery::fallback( $complete ), 'discovery_fallback_not_available' );

foreach ( array( '/absolute/SKILL.md', '../SKILL.md', 'a/../SKILL.md', 'a//SKILL.md', 'a\\SKILL.md', "a\0/SKILL.md", "a\n/SKILL.md", ' a/SKILL.md', str_repeat( 'x', 769 ) ) as $badPath ) {
	rejects( fn() => Discovery::accept( $initial, tree( array( entry( $badPath ) ) ) ), 'discovery_invalid_path' );
}
rejects( fn() => Discovery::accept( $fallback, tree( array( entry( 'nested/SKILL.md' ) ) ) ), 'discovery_nonlocal_entry' );
rejects( fn() => Discovery::accept( $initial, tree( array( entry( 'orphan/SKILL.md' ) ) ) ), 'discovery_incomplete_hierarchy' );
rejects( fn() => Discovery::accept( $initial, tree( array( entry( 'parent', 'blob', '120000' ), entry( 'parent/SKILL.md' ) ) ) ), 'discovery_incomplete_hierarchy' );
foreach ( array( entry( 'SKILL.md', 'blob', '120000' ), entry( 'SKILL.md', 'tree', '040000', sha( 'c' ), null ), entry( 'SKILL.md', 'commit', '160000', sha( 'c' ), null ) ) as $unsafe ) {
	rejects( fn() => Discovery::accept( $initial, tree( array( $unsafe ) ) ), 'discovery_unsafe_skill_entry' );
}
rejects( fn() => Discovery::accept( $initial, tree( array( entry( 'SKILL.md', 'blob', '040000' ) ) ) ), 'discovery_invalid_mode' );
rejects( fn() => Discovery::accept( $initial, tree( array( entry( 'SKILL.md', 'blob', '100644', '', -1 ) ) ) ), 'discovery_invalid_size' );
rejects( fn() => Discovery::accept( $initial, tree( array( entry( 'SKILL.md' ), entry( 'SKILL.md' ) ) ) ), 'discovery_duplicate_path' );
rejects( fn() => Discovery::accept( $initial, tree( array( entry( 'loop', 'tree', '040000', sha( 'a' ), null ) ) ) ), 'discovery_tree_cycle' );
rejects( fn() => Discovery::accept( $fallback, tree( array( entry( 'loop', 'tree', '040000', sha( 'a' ), null ) ) ) ), 'discovery_tree_cycle' );
rejects( fn() => Discovery::accept( $initial, tree( array( entry( str_repeat( 'dir/', 32 ) . 'SKILL.md' ) ) ) ), 'discovery_depth_limit' );
rejects( fn() => Discovery::accept( $initial, tree( array_fill( 0, Discovery::MAX_ENTRIES + 1, entry( 'x' ) ) ) ), 'discovery_entry_limit' );
$manySkills = array();
for ( $i = 0; $i <= Discovery::MAX_SKILLS; ++$i ) { $manySkills[] = entry( 's' . $i, 'tree', '040000', sha( 'c' ), null ); $manySkills[] = entry( 's' . $i . '/SKILL.md' ); }
rejects( fn() => Discovery::accept( $initial, tree( $manySkills ) ), 'discovery_skill_limit' );
$largeState = $initial; $largeState['padding'] = str_repeat( 'a', Discovery::MAX_STATE_BYTES );
rejects( fn() => Discovery::request( $largeState ), 'discovery_state_size_limit' );
$queued = $initial; $queued['pending'] = array_fill( 0, Discovery::MAX_REQUESTS + 1, $initial['pending'][0] );
rejects( fn() => Discovery::request( $queued ), 'discovery_state_limit' );
$falseComplete = $initial; $falseComplete['phase'] = 'complete';
rejects( fn() => Discovery::complete( $falseComplete ), 'discovery_invalid_state' );
$badResponse = tree( array() ); $badResponse['truncated'] = 'false';
rejects( fn() => Discovery::accept( $initial, $badResponse ), 'discovery_invalid_response' );
rejects( fn() => Discovery::accept( $initial, tree( array(), sha( 'f' ) ) ), 'discovery_invalid_response' );
rejects( fn() => Discovery::begin( str_repeat( 'A', 40 ) ), 'discovery_invalid_sha' );
rejects( fn() => Discovery::begin( 'main' ), 'discovery_invalid_sha' );
$wrongRequest = $request; $wrongRequest['path'] = 'changed';
rejects( fn() => Discovery::accept( $initial, tree( array() ), $wrongRequest ), 'discovery_invalid_request' );
rejects( fn() => Discovery::accept( $sized, tree( array() ), $request ), 'discovery_stale_response' );

$largeFile = Discovery::accept( $initial, tree( array( entry( 'SKILL.md', 'blob', '100644', '', Discovery::MAX_SKILL_BYTES + 1 ) ) ) );
check( Discovery::complete( $largeFile ) && in_array( 'oversized_skill', $largeFile['warnings'], true ), 'Inventory completion records oversized candidate without fetching; worker must fail file processing.' );
check( Discovery::candidates( $largeFile )[0]['size'] === Discovery::MAX_SKILL_BYTES + 1, 'Worker receives exact byte size to prevent blob request.' );
$numeric = Discovery::accept( $fallback, tree( array( entry( '123', 'tree', '040000', sha( 'c' ), null ) ) ) );
check( '123' === Discovery::request( $numeric )['path'], 'Numeric directory names keep string path identities.' );
$numeric = Discovery::accept( $numeric, tree( array( entry( 'SKILL.md' ) ), sha( 'c' ) ) );
check( array( '123/SKILL.md' ) === array_column( Discovery::candidates( $numeric ), 'path' ), 'Numeric directory traverses correctly.' );
$unicode = Discovery::accept( $initial, tree( array( entry( '中文', 'tree', '040000', sha( 'c' ), null ), entry( '中文/SKILL.md' ) ) ) );
check( '中文/SKILL.md' === Discovery::candidates( $unicode )[0]['path'], 'UTF-8 relative path is preserved exactly.' );
check( Discovery::complete( Discovery::accept( $initial, tree( array() ) ) ), 'A genuinely empty, nontruncated tree is complete.' );

echo 'GitHub discovery contracts: ' . $assertions . " assertions passed (pure fixtures; no GitHub/WordPress/MySQL integration).\n";
