<?php
declare(strict_types=1);

/** Bounded read-only service measurements on retained local QA data; no fixtures or admin_init. */
if ( PHP_SAPI !== 'cli' || getenv( 'AISH_PHASE14_QA' ) !== '1' ) { exit( 1 ); }
$scope = $argv[1] ?? ''; $locale = $argv[2] ?? '';
$scopes = array( 'help_list', 'help_detail', 'home_catalog_preamble', 'skill_detail', 'account_subscription', 'account_onboarding', 'guard_post_metadata' );
if ( ! in_array( $scope, $scopes, true ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { exit( 2 ); }
$_SERVER['HTTP_HOST'] = PHP_VERSION_ID >= 80400 ? '127.0.0.1:18184' : '127.0.0.1:18183';
require '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_discovery', 'qa_operations84' ), true ) || '1.9.0' !== get_option( 'aish_schema_version' ) ) { exit( 3 ); }

$blockedSql = 0; $network = 0; $mail = 0;
add_filter( 'query', static function ( string $sql ) use ( &$blockedSql ): string {
	// PublicCatalog reads category parents through a SELECT-only recursive CTE.
	$selectCte = preg_match( '/^\s*WITH\s+RECURSIVE\s+category_ancestors\s+AS\s*\(/i', $sql ) && ! preg_match( '/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|CALL|SET|INTO)\b/i', $sql );
	if ( ( ! preg_match( '/^\s*(?:SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) && ! $selectCte ) || preg_match( '/\b(?:GET_LOCK|RELEASE_LOCK|INTO\s+(?:OUTFILE|DUMPFILE))\s*\(?/i', $sql ) ) { ++$blockedSql; throw new RuntimeException( 'qa_read_only_guard' ); }
	return $sql;
}, PHP_INT_MAX );
add_filter( 'pre_http_request', static function () use ( &$network ): WP_Error { ++$network; return new WP_Error( 'qa_external_disabled', 'External requests disabled.' ); }, PHP_INT_MAX );
add_filter( 'pre_wp_mail', static function () use ( &$mail ): bool { ++$mail; return false; }, PHP_INT_MAX );

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Frontend\{Editorial, PublicCatalog};
use AISkillsHub\Core\Learning\Help;
$core = Core::boot(); $db = $core->get( 'database' ); $migrator = $core->migrator();
$catalog = new PublicCatalog( $db, $migrator ); $editorial = new Editorial( $catalog, $db ); $help = new Help( $db, $migrator, $editorial );
$native = $db->connection(); $schemaTables = $db->rows( 'SHOW TABLES LIKE %s', array( $native->esc_like( $native->prefix . 'aish_' ) . '%' ) );
$scale = array(
	'core_tables' => count( $schemaTables ),
	'skills_all_states' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skills' ) ),
	'skill_translations' => (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skill_translations' ) ),
	'native_users' => (int) $db->value( "SELECT COUNT(*) FROM {$native->users}" ),
	'native_tutorials_all_states' => (int) $db->value( "SELECT COUNT(*) FROM {$native->posts} WHERE post_type='aish_tutorial'" ),
	'published_help_locale' => (int) $db->value( "SELECT COUNT(DISTINCT p.ID) FROM {$native->posts} p INNER JOIN {$native->postmeta} l ON l.post_id=p.ID AND l.meta_key='_aish_locale' INNER JOIN {$native->postmeta} h ON h.post_id=p.ID AND h.meta_key='_aish_help_category' WHERE p.post_type='aish_tutorial' AND p.post_status='publish' AND p.post_password='' AND l.meta_value=%s AND h.meta_value<>''", array( $locale ) ),
);
$tutorial = (int) $db->value( "SELECT p.ID FROM {$native->posts} p INNER JOIN {$native->postmeta} l ON l.post_id=p.ID AND l.meta_key='_aish_locale' INNER JOIN {$native->postmeta} h ON h.post_id=p.ID AND h.meta_key='_aish_help_category' WHERE p.post_type='aish_tutorial' AND p.post_status='publish' AND p.post_password='' AND l.meta_value=%s AND h.meta_value<>'' ORDER BY p.ID DESC LIMIT 1", array( $locale ) );
$skill = (int) $db->value( 'SELECT s.id FROM ' . $db->table( 'skills' ) . ' s INNER JOIN ' . $db->table( 'skill_translations' ) . " t ON t.skill_id=s.id WHERE s.status='published' AND s.review_status='approved' AND s.reviewed_revision=s.revision AND s.reviewer_id>0 AND s.reviewed_at IS NOT NULL AND t.locale=%s AND t.title<>'' AND t.summary<>'' AND t.content<>'' ORDER BY s.id DESC LIMIT 1", array( $locale ) );
$preferenceJoin = 'account_subscription' === $scope ? " INNER JOIN {$native->usermeta} pref ON pref.user_id=u.ID AND pref.meta_key=%s" : '';
$userArgs = 'account_subscription' === $scope ? array( $core->get( 'learning_subscriptions' )->storageKey(), $native->esc_like( 'p14_' ) . '%' ) : array( $native->esc_like( 'p14_' ) . '%' );
$user = (int) $db->value( "SELECT u.ID FROM {$native->users} u INNER JOIN {$native->usermeta} a ON a.user_id=u.ID AND a.meta_key='_aish_account_state' AND a.meta_value='active' INNER JOIN {$native->usermeta} v ON v.user_id=u.ID AND v.meta_key='_aish_verified_email_hash' AND v.meta_value<>'' $preferenceJoin WHERE u.user_login LIKE %s ORDER BY u.ID DESC LIMIT 1", $userArgs );
if ( in_array( $scope, array( 'account_subscription', 'account_onboarding' ), true ) ) {
	if ( ! $user ) { throw new RuntimeException( 'qa_fixture_unavailable' ); }
	wp_set_current_user( $user ); $core->get( 'accounts' )->requireOwner();
} else { wp_set_current_user( 0 ); }
if ( ( in_array( $scope, array( 'help_detail', 'guard_post_metadata' ), true ) && ! $tutorial ) || ( 'skill_detail' === $scope && ! $skill ) ) { throw new RuntimeException( 'qa_fixture_unavailable' ); }

$probe = match ( $scope ) {
	'help_list' => static function () use ( $help, $locale ): array { $r = $help->listing( $locale, array( 'page' => 1, 'per_page' => 12 ) ); return array( 'returned_items' => count( $r['items'] ), 'matched_items' => $r['total'] ); },
	'help_detail' => static function () use ( $help, $editorial, $tutorial, $locale ): array { $r = $editorial->tutorial( $tutorial, $locale ); if ( ! $r ) { throw new RuntimeException( 'qa_fixture_unavailable' ); } $reading = $help->reading( $r, $locale ); return array( 'found' => true, 'toc_items' => count( $reading['toc'] ), 'related_items' => count( $reading['related'] ) ); },
	'home_catalog_preamble' => static function () use ( $catalog, $locale ): array { return array( 'featured_items' => count( $catalog->featured( $locale, 1 ) ), 'latest_items' => count( $catalog->latest( $locale, 1 ) ), 'categories' => count( $catalog->terms( 'category', $locale ) ) ); },
	'skill_detail' => static function () use ( $catalog, $editorial, $skill, $locale ): array { $r = $catalog->detail( $skill, $locale ); if ( ! $r ) { throw new RuntimeException( 'qa_fixture_unavailable' ); } return array( 'found' => true, 'language_alternatives' => count( $catalog->alternatives( $skill ) ), 'related_tutorials' => count( $editorial->relatedTutorials( $skill, $locale, 3 ) ) ); },
	'account_subscription' => static function () use ( $core ): array { $r = $core->get( 'learning_subscriptions' )->accountPreferences(); return array( 'existing_preference' => null !== $r ); },
	'account_onboarding' => static function () use ( $core ): array { $r = $core->get( 'learning_onboarding' )->mine(); return array( 'completed_count' => count( $r['completed'] ) ); },
	'guard_post_metadata' => static function () use ( $tutorial ): array { get_post( $tutorial ); foreach ( array( '_aish_locale', '_aish_help_topic', '_aish_help_category', '_aish_seo_title', '_aish_seo_description', '_aish_translation_id', '_aish_help_seed', '_aish_related_skill' ) as $key ) { get_post_meta( $tutorial, $key, true ); } return array( 'ordinary_post_metadata_reads' => 8 ); },
};

$samples = array();
foreach ( array( 'first_call_fresh_php_process', 'same_request_repeat' ) as $kind ) {
	$before = $wpdb->num_queries; $start = hrtime( true ); $result = $probe();
	$samples[] = array( 'kind' => $kind, 'sql_queries' => $wpdb->num_queries - $before, 'elapsed_ms' => round( ( hrtime( true ) - $start ) / 1000000, 3 ), 'result_shape' => $result );
}
$files = array( 'Learning/Help.php', 'Learning/Onboarding.php', 'Learning/Subscriptions.php', 'Learning/SubscriptionGuards.php', 'Frontend/Editorial.php', 'Frontend/PublicCatalog.php' ); $fingerprints = array();
foreach ( $files as $file ) { $fingerprints[ $file ] = hash_file( 'sha256', get_template_directory() . '/inc/core/' . $file ); }
$result = array(
	'kind' => 'Limited real WordPress/MySQL read-only service measurement; not load testing or Final QA 2.0',
	'captured_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'scope' => $scope, 'locale' => $locale,
	'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'mysql' => $db->value( 'SELECT VERSION()' ), 'schema' => '1.9.0', 'persistent_object_cache' => (bool) wp_using_ext_object_cache() ),
	'scale' => $scale, 'samples' => $samples, 'source_sha256' => $fingerprints,
	'blocked_sql_mutations' => $blockedSql, 'external_http_attempts' => $network, 'mail_attempts' => $mail,
	'limitations' => array( 'Existing retained synthetic QA data only; no new fixtures.', 'First call uses a separate PHP process; database/OS caches are not flushed.', 'WordPress bootstrap, fixture selection, identity preparation and scale counts are excluded from timings.', 'These are service calls, not full HTTP/render, browser, production throughput or latency SLO measurements.', 'Homepage scope measures the actual public catalog preamble only; modular discovery rendering is excluded.', 'One first call and one repeat per scope/language; no statistical latency claim.' ),
);
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $blockedSql || $network || $mail ? 4 : 0 );
