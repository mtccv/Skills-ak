<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Database {
	final class Database {
		public array $calls = array(); public bool $fail = false; public bool $remaining = false;
		public array $fixtures = array();
		public function table( string $name ): string { return '`fixture_aish_' . $name . '`'; }
		public function transaction( callable $fn ): mixed { if ( $this->fail ) { throw new \RuntimeException( 'modeled rollback' ); } return $fn(); }
		public function execute( string $sql, array $values = array() ): int { $this->calls[] = array( $sql, $values ); return 1; }
		public function value( string $sql, array $values = array() ): mixed { $this->calls[] = array( $sql, $values ); return $this->remaining && str_contains( $sql, 'favorites' ) ? 1 : null; }
		public function row( string $sql, array $values = array() ): ?array { $rows = $this->rows( $sql, $values ); return $rows[0] ?? null; }
		public function rows( string $sql, array $values = array() ): array {
			$this->calls[] = array( $sql, $values );
			if ( ! str_contains( $sql, 'OFFSET' ) ) { return array(); }
			foreach ( $this->fixtures as $entity => $rows ) {
				if ( ! str_contains( $sql, '`fixture_aish_' . $entity . '`' ) ) { continue; }
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => $row['owner'] === $values[0] ) );
				$rows = array_slice( $rows, (int) end( $values ), 50 );
				preg_match( '/^SELECT (.*?) FROM /', $sql, $match ); $fields = array();
				foreach ( explode( ',', $match[1] ) as $field ) { $field = preg_replace( '/^[rv]\./', '', $field ); $fields[] = preg_replace( '/^.* AS /', '', $field ); }
				return array_map( static fn( array $row ): array => in_array( '*', $fields, true ) ? $row : array_intersect_key( $row, array_flip( $fields ) ), $rows );
			}
			return array();
		}
	}
}
namespace AISkillsHub\Core\Accounts {
	final class Locks { public array $users = array(); public function withUser( int $id, callable $fn ): mixed { if ( $id < 1 ) { throw new \RuntimeException(); } $this->users[] = $id; return $fn(); } }
}
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
	class WP_User { public function __construct( public int $ID, public string $user_email ) {} }
	$schema = '1.7.0'; $cap = true; $failMeta = false; $users = array( 4 => new WP_User( 4, 'owner@example.invalid' ), 5 => new WP_User( 5, 'other@example.invalid' ) ); $meta = array(); $hooks = array();
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['schema']; }
	function current_user_can( string $cap ): bool { return $GLOBALS['cap']; }
	function get_userdata( int $id ): WP_User|false { return $GLOBALS['users'][ $id ] ?? false; }
	function clean_user_cache( int $id ): void {}
	function wp_cache_delete( mixed $id, string $group ): void {}
	function delete_user_meta( int $id, string $key ): void { unset( $GLOBALS['meta'][ $id ][ $key ] ); }
	function add_user_meta( int $id, string $key, mixed $value, bool $unique ): bool { if ( $GLOBALS['failMeta'] ) { return false; } $GLOBALS['meta'][ $id ][ $key ] = array( $value ); return true; }
	function get_user_meta( int $id, string $key, bool $single = false ): mixed { $rows = $GLOBALS['meta'][ $id ][ $key ] ?? array(); return $single ? ( $rows[0] ?? '' ) : $rows; }
	function add_filter( string $name, callable $callback, int $priority = 10, int $args = 1 ): void { $GLOBALS['hooks'][ $name ] = $args; }
	function add_action( string $name, callable $callback, int $priority = 10, int $args = 1 ): void { add_filter( $name, $callback, $priority, $args ); }
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Community/Privacy.php';
	use AISkillsHub\Core\Community\Privacy;
	use AISkillsHub\Core\Database\Database;
	use AISkillsHub\Core\Accounts\Locks;
	$count = 0;
	function check( bool $condition, string $message ): void { global $count; if ( ! $condition ) { throw new \RuntimeException( $message ); } ++$count; }
	function rejects( callable $fn, string $message ): void { try { $fn(); } catch ( \Throwable $error ) { check( true, $message ); return; } throw new \RuntimeException( $message ); }
	$db = new Database(); $locks = new Locks(); $privacy = new Privacy( $db, $locks ); $privacy->register();
	check( 3 === $hooks['aish_account_privacy_export'] && 2 === $hooks['aish_account_privacy_erase_batch'], 'Native identity and completion extensions use explicit contracts.' );
	$db->fixtures = array(
		'favorites' => array( array( 'owner' => 4, 'skill_id' => 7, 'is_active' => 1, 'created_at' => '2026-01-01', 'private_note' => 'SECRET' ), array( 'owner' => 5, 'skill_id' => 99 ) ),
		'reviews' => array( array( 'owner' => 4, 'id' => 10, 'review_id' => 3, 'skill_id' => 7, 'title' => 'Owned review', 'comment' => 'Owned draft', 'private_note' => 'SECRET', 'moderator_id' => 99, 'request_key' => 'SECRET', 'user_email' => 'SECRET' ), array( 'owner' => 5, 'id' => 11, 'comment' => 'FOREIGN' ) ),
		'review_reports' => array( array( 'owner' => 4, 'id' => 12, 'details' => 'Owned report', 'private_note' => 'SECRET', 'reporter_user_id' => 4, 'handler_id' => 99 ) ),
		'skill_reports' => array( array( 'owner' => 4, 'id' => 13, 'details' => 'Owned correction', 'private_note' => 'SECRET', 'handler_id' => 99 ) ),
	);
	$result = $privacy->export( array( 'data' => array(), 'done' => true ), clone $users[4], 1 );
	check( 4 === count( $result['data'] ), 'Own favorites, revisions and two report types are exported.' );
	check( ! str_contains( json_encode( $result ), 'SECRET' ) && ! str_contains( json_encode( $result ), 'FOREIGN' ), 'Foreign drafts, internal notes, credentials and identities are excluded.' );
	check( str_contains( json_encode( $result ), 'Owned draft' ), 'Own unpublished draft remains exportable to its authorized owner.' );
	foreach ( $db->calls as [ $sql, $values ] ) { check( 4 === $values[0] && str_contains( $sql, 'LIMIT 50 OFFSET %d' ), 'Every export query binds the pinned owner and finite page.' ); }
	check( $result['done'], 'A short final page completes.' );
	$db->fixtures['favorites'] = array_fill( 0, 51, array( 'owner' => 4, 'skill_id' => 7 ) );
	check( ! $privacy->export( array( 'data' => array(), 'done' => true ), clone $users[4], 1 )['done'], 'Full community page keeps native export pending.' );
	check( $privacy->export( array( 'data' => array(), 'done' => true ), clone $users[4], 2 )['done'], 'The subsequent page completes with remaining own records.' );
	$cap = false; rejects( fn() => $privacy->export( array(), clone $users[4], 1 ), 'Missing privacy capability is rejected.' ); $cap = true;
	rejects( fn() => $privacy->export( array(), clone $users[4], 0 ), 'Invalid page is rejected.' );
	rejects( fn() => $privacy->export( array(), clone $users[4], 100001 ), 'Unbounded offset is rejected.' );
	$stale = clone $users[4]; $users[4]->user_email = 'changed@example.invalid';
	rejects( fn() => $privacy->export( array(), $stale, 1 ), 'An email change during export cannot retarget another account.' );
	$privacy->isolate( 4 ); check( '1' === get_user_meta( 4, Privacy::ERASING, true ), 'Erasing marker hides all contributions before a bounded batch runs.' );
	$meta[4]['_aish_account_deletion_pending'] = array( '1' );
	rejects( fn() => $privacy->export( array(), clone $users[4], 1 ), 'Pending native deletion blocks export.' ); unset( $meta[4]['_aish_account_deletion_pending'] );
	$db->fail = true; rejects( fn() => $privacy->erase( true, 4 ), 'Failed transaction does not report erasure complete.' );
	check( '1' === get_user_meta( 4, Privacy::ERASING, true ), 'Failure keeps public quarantine in place.' ); $db->fail = false;
	$db->remaining = true; check( ! $privacy->erase( true, 4 ), 'Remaining private rows keep durable account cleanup pending.' );
	check( '1' === get_user_meta( 4, Privacy::ERASING, true ), 'Partial batch does not reopen community writes.' ); $db->remaining = false;
	check( ! $privacy->erase( false, 4 ), 'Previous account module pending state is preserved.' );
	check( $privacy->erase( true, 4 ), 'All module batches complete together.' );
	check( '' === get_user_meta( 4, Privacy::ERASING, true ), 'Completed cleanup clears its protected transient marker.' );
	rejects( fn() => $privacy->erase( true, 5 ), 'A live account cannot be erased before isolation.' );
	$failMeta = true; rejects( fn() => $privacy->isolate( 5 ), 'Failed quarantine persistence fails closed.' ); $failMeta = false;
	$db->calls = array(); $privacy->retention(); check( 3 === count( $db->calls ), 'Retention is limited to two report types and workflow evidence.' );
	foreach ( $db->calls as [ $sql, $values ] ) { check( str_contains( $sql, 'LIMIT 50' ) && ! str_contains( $sql, 'DELETE ' ), 'Retention is bounded and preserves dedupe tombstones.' ); }
	check( ! str_contains( implode( '\n', array_column( $db->calls, 0 ) ), 'request_key=' ) && ! str_contains( implode( '\n', array_column( $db->calls, 0 ) ), 'event_key=' ), 'Retention cannot clear replay-prevention identities.' );
	$db->calls = array(); $schema = '1.6.0';
	$privacy->isolate( 4 ); $privacy->retention(); check( $privacy->erase( true, 4 ) && ! $db->calls, 'Historical schema performs no Phase 9 table access.' );
	echo 'Community privacy: ' . $count . " isolated assertions passed (modeled WordPress/SQL; no real integration).\n";
}
