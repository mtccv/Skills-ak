<?php
declare(strict_types=1);

/** Configuration rejection test: none of these shared directories may be chmod'ed. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE8_QA' ) !== '1' ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) ) { exit( 2 ); }

use AISkillsHub\Core\Accounts\PrivacyExports;

$case = getenv( 'AISH_EXPORT_DIRECTORY_CASE' );
$targets = array( 'filesystem_root' => '/', 'system_temporary_root' => (string) realpath( sys_get_temp_dir() ), 'web_root' => (string) realpath( ABSPATH ), 'web_root_ancestor' => dirname( (string) realpath( ABSPATH ) ) );
if ( ! is_string( $case ) || ! isset( $targets[ $case ] ) || defined( 'AISH_ACCOUNT_EXPORT_DIR' ) ) { exit( 3 ); }
$target = $targets[ $case ]; $before = fileperms( $target );
define( 'AISH_ACCOUNT_EXPORT_DIR', $target );
$rejected = false;
try { PrivacyExports::directory(); } catch ( RuntimeException $error ) { $rejected = true; }
clearstatcache( true, $target ); $unchanged = fileperms( $target ) === $before;
echo wp_json_encode( array( 'php' => PHP_VERSION, 'case' => $case, 'assertions' => 2, 'rejected_before_chmod' => $rejected, 'permissions_unchanged' => $unchanged, 'passed' => $rejected && $unchanged ? 1 : 0, 'failed' => $rejected && $unchanged ? 0 : 1 ), JSON_UNESCAPED_SLASHES ) . "\n";
exit( $rejected && $unchanged ? 0 : 1 );
