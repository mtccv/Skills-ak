<?php
declare(strict_types=1);
/** Isolated real notification service/DAL with modeled native identity and SQL; no MySQL claim. */
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['owner'] = 7; $GLOBALS['preferences'] = array();
	class WP_User { public function __construct( public int $ID ) {} }
	function get_user_meta( int $id, string $key, bool $single = true ): mixed { return $GLOBALS['preferences'][ $id ] ?? array(); }
	function get_current_user_id(): int { return $GLOBALS['owner']; }
	function wp_json_encode( mixed $v ): string { return json_encode( $v, JSON_THROW_ON_ERROR ); }
	final class wpdb {
		public string $prefix = 'wp_'; public string $users = 'wp_users'; public string $usermeta = 'wp_usermeta'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta'; public string $last_error = '';
		public array $notices = array(); public array $queries = array(); public array $targetIds = array(); private array $before = array(); public bool $erasing = false;
		public function suppress_errors( bool $v ): bool { return false; }
		public function prepare( string $sql, array $args ): string { return json_encode( array( $sql, $args ), JSON_THROW_ON_ERROR ); }
		private function unpack( string $value ): array { $r = str_starts_with( $value, '[' ) ? json_decode( $value, true, 32, JSON_THROW_ON_ERROR ) : array( $value, array() ); $this->queries[] = $r; return $r; }
		public function get_var( string $sql ): mixed {
			[ $q, $v ] = $this->unpack( $sql );
			if ( str_contains( $q, 'COUNT(*)' ) && str_contains( $q, 'user_notifications' ) ) { return count( array_filter( $this->notices, fn( $r ) => $r['user_id'] === $v[0] && null === $r['read_at'] && $r['expires_at'] > $v[1] ) ); }
			if ( str_contains( $q, 'wp_usermeta' ) ) { return $this->erasing ? 1 : null; }
			throw new \RuntimeException( 'Unexpected fixture query.' );
		}
		public function get_results( string $sql, string $format ): array {
			[ $q, $v ] = $this->unpack( $sql );
			if ( str_contains( $q, 'user_notifications' ) ) {
				$rows = array_values( array_filter( $this->notices, fn( $r ) => $r['user_id'] === $v[0] && $r['expires_at'] > $v[1] ) );
				if ( str_contains( $q, 'id IN (' ) ) { return array_map( fn( $r ) => array( 'id' => $r['id'] ), array_values( array_filter( $rows, fn( $r ) => in_array( $r['id'], array_slice( $v, 2 ), true ) ) ) ); }
				if ( str_contains( $q, 'AND type=%s' ) ) { $rows = array_values( array_filter( $rows, fn( $r ) => $r['type'] === $v[2] ) ); }
				usort( $rows, fn( $a, $b ) => $b['id'] <=> $a['id'] );
				$rows = array_slice( $rows, $v[ count( $v ) - 1 ], $v[ count( $v ) - 2 ] );
				return array_map( fn( $r ) => array_intersect_key( $r, array_flip( array( 'id', 'type', 'subject_type', 'subject_id', 'template_key', 'params_json', 'locale', 'read_at', 'created_at' ) ) ), $rows );
			}
			if ( str_contains( $q, 'wp_aish_reviews' ) || str_contains( $q, 'wp_aish_author_claims' ) ) {
				if ( 7 !== $v[0] || ! str_contains( $q, "state IN ('pending','approved','rejected')" ) ) { throw new \RuntimeException( 'Target lacks owner/state gate.' ); }
				return array_map( fn( $id ) => array( 'id' => $id ), array_values( array_intersect( array_slice( $v, 1 ), $this->targetIds ) ) );
			}
			if ( str_contains( $q, 'wp_posts' ) ) {
				if ( 7 !== $v[0] || ! str_contains( $q, "p.post_status='private'" ) || ! str_contains( $q, "'_aish_submission_version'" ) || ! str_contains( $q, "'needs_changes','accepted','rejected'" ) ) { throw new \RuntimeException( 'Submission lacks ownership/marker/state gate.' ); }
				return array_map( fn( $id ) => array( 'ID' => $id ), array_values( array_intersect( array_slice( $v, 1, -1 ), $this->targetIds ) ) );
			}
			if ( str_contains( $q, 'wp_usermeta' ) ) { return array(); }
			throw new \RuntimeException( 'Unexpected fixture rows query.' );
		}
		public function query( string $sql ): int {
			[ $q, $v ] = $this->unpack( $sql );
			if ( 'START TRANSACTION' === $q ) { $this->before = $this->notices; return 0; }
			if ( 'ROLLBACK' === $q ) { $this->notices = $this->before; return 0; }
			if ( 'COMMIT' === $q ) { return 0; }
			if ( str_starts_with( $q, 'UPDATE' ) && str_contains( $q, 'read_at=' ) ) { $n = 0; foreach ( $this->notices as &$r ) { if ( $r['user_id'] === $v[1] && $r['expires_at'] > $v[2] && in_array( $r['id'], array_slice( $v, 3 ), true ) && null === $r['read_at'] ) { $r['read_at'] = $v[0]; ++$n; } } return $n; }
			throw new \RuntimeException( 'Unexpected mutation.' );
		}
	}
}
namespace AISkillsHub\Core\Accounts {
	final class AccountService { public function requireOwner(): \WP_User { if ( \get_current_user_id() < 1 ) { throw new \RuntimeException( 'forbidden' ); } return new \WP_User( \get_current_user_id() ); } }
	final class Frontend { public static function url( string $route, string $locale ): string { return '/private/' . $locale . '/' . $route; } }
}
namespace AISkillsHub\Core\Community { final class Frontend { public static function url( string $route, string $locale ): string { return '/private/' . $locale . '/' . $route; } } }
namespace AISkillsHub\Core\Developers { final class Frontend { public static function url( string $route, string $locale, array $args = array() ): string { return '/private/' . $locale . '/' . $route . '?' . http_build_query( $args ); } } }
namespace AISkillsHub\Core\Submissions { final class Frontend { public static function url( string $route, string $locale, array $args = array() ): string { return '/private/' . $locale . '/' . $route . '?' . http_build_query( $args ); } } }
namespace {
	$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Accounts/Store.php', 'Operations/Notifications.php' ) as $f ) { require $base . $f; }
	use AISkillsHub\Core\Accounts\{Store,AccountService}; use AISkillsHub\Core\Operations\Notifications; use AISkillsHub\Core\Database\Database;
	$db = new wpdb(); $store = new Store( new Database( $db ) ); $service = new Notifications( $store, new AccountService() ); $checks = 0;
	function check( bool $ok, string $label ): void { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $label ); } }
	function reject( callable $f ): void { try { $f(); } catch ( Throwable $e ) { check( true, 'Rejected untrusted input.' ); return; } throw new RuntimeException( 'Unexpected acceptance.' ); }
	for ( $i = 1; $i <= 25; ++$i ) { $db->notices[ $i ] = array( 'id' => $i, 'user_id' => 7, 'type' => 'account', 'subject_type' => 'account', 'subject_id' => 7, 'template_key' => 'password_changed', 'params_json' => '{"unsafe":"never project"}', 'locale' => 'en_US', 'read_at' => null, 'created_at' => '2026-01-01 00:00:00', 'expires_at' => '2099-01-01 00:00:00' ); }
	$db->notices[26] = array_replace( $db->notices[1], array( 'id' => 26, 'user_id' => 8, 'subject_id' => 8 ) );
	$db->notices[27] = array_replace( $db->notices[1], array( 'id' => 27, 'expires_at' => '2000-01-01 00:00:00' ) );
	$p = $service->mine( 'en_US' ); check( 21 === count( $p['notifications'] ) && 25 === $p['unread_count'], 'Sentinel pagination and unread count exclude another owner and expired notices.' );
	check( 5 === count( $service->mine( 'en_US', 2 )['notifications'] ), 'Second page uses bounded offset.' );
	check( ! str_contains( json_encode( $p ), 'never project' ) && ! isset( $p['notifications'][0]['subject_id'] ), 'Private projection strips snapshots and target IDs.' );
	$service->read( array( 1, 2, 2 ) ); check( 23 === $store->unreadCount( 7 ), 'Duplicate selected IDs are idempotent.' ); $service->read( array( 1, 2 ) ); check( 23 === $store->unreadCount( 7 ), 'Repeated read operation is idempotent.' );
	reject( fn() => $service->read( array( 3, 26 ) ) ); check( null === $db->notices[3]['read_at'], 'Mixed-owner batch fails atomically.' );
	foreach ( array( array(), array( 27 ), array( '3' ), range( 1, 51 ), array( 0 ), array( 'one' => 1 ) ) as $ids ) { reject( fn() => $service->read( $ids ) ); }
	foreach ( array( 'sql injection', array(), null ) as $type ) { reject( fn() => Notifications::type( $type ) ); }
	reject( fn() => $service->mine( 'fr_FR' ) ); reject( fn() => $service->mine( 'zh_CN', 1001 ) );
	$db->notices[3] = array_replace( $db->notices[3], array( 'type' => 'community', 'subject_type' => 'review', 'subject_id' => 101, 'template_key' => 'review_approved' ) );
	$db->targetIds = array( 101 ); $p = $service->mine( 'zh_CN', 1, 'community' ); check( 1 === count( $p['notifications'] ) && null !== $p['notifications'][0]['target_url'], 'Type filter plus freshly accessible review target.' );
	$db->targetIds = array(); check( null === $service->mine( 'zh_CN', 1, 'community' )['notifications'][0]['target_url'], 'Withdrawn/deleted target produces no stale link.' );
	$db->notices[4] = array_replace( $db->notices[4], array( 'type' => 'contribution', 'subject_type' => 'submission', 'subject_id' => 201 ) ); $db->targetIds = array( 201 );
	check( str_contains( $service->mine( 'en_US', 1, 'contribution' )['notifications'][0]['target_url'], 'id=201' ), 'Only marker-owned accessible submission gets private target.' );
	$db->erasing = true; check( null === $service->mine( 'en_US', 1, 'contribution' )['notifications'][0]['target_url'], 'Erasure fence removes target links immediately.' ); $db->erasing = false;
	$db->notices[5] = array_replace( $db->notices[5], array( 'type' => 'community', 'subject_type' => 'review_report', 'subject_id' => 301, 'template_key' => 'report_resolved' ) );
	check( null === $service->mine( 'en_US', 1, 'community' )['notifications'][0]['target_url'], 'Reports never link to someone else\'s review/report detail.' );
	$GLOBALS['preferences'][7] = array( 'community_notifications' => false, 'contribution_notifications' => false, 'account_notifications' => false );
	check( 0 === $store->notifyCommunity( 7, str_repeat( 'a', 64 ), 'review_approved', 'en_US', 'review', 101 ), 'Optional community preference suppresses future notice.' );
	check( 0 === $store->notifyContribution( 7, str_repeat( 'b', 64 ), 'contribution_submitted', 'en_US', 'submission', 201 ), 'Optional contribution preference suppresses future notice.' );
	check( 0 === $store->notify( 7, str_repeat( 'c', 64 ), 'profile_updated', 'en_US' ), 'Optional account preference is applied.' );
	$GLOBALS['owner'] = 0; reject( fn() => $service->mine( 'en_US' ) ); reject( fn() => $service->read( array( 1 ) ) );
	echo "Operations notifications: $checks isolated assertions passed (modeled native identity/SQL; no real WordPress/MySQL).\n";
}
