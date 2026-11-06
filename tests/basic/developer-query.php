<?php
declare(strict_types=1);
// Explicit projection/SQL boundary tests with recording fixtures, not real database tests.
namespace AISkillsHub\Core\Accounts {
	final class AccountService { public int $owner = 42; public bool $active = true; public function requireOwner( bool $verified = true ): object { if ( ! $this->active ) { throw new \RuntimeException(); } return (object) array( 'ID' => $this->owner ); } public function verified( int $id ): bool { return $this->active; } public function state( int $id ): string { return $this->active ? 'active' : 'suspended'; } }
}
namespace AISkillsHub\Core\Database { final class Migrator { public bool $current = true; public function isCurrent(): bool { return $this->current; } } }
namespace AISkillsHub\Core\Frontend {
	final class PublicCatalog { public static array $calls = array(); public function __construct( mixed $db, mixed $migrator ) {} public function matchingIdsSql( string $locale, array $filters ): string { return "SELECT id FROM fixture_published WHERE locale='$locale' AND reviewed=1"; } public function cardsByIds( array $ids, string $locale ): array { self::$calls[] = array( $ids, $locale ); return array(); } public function detail( int $id, string $locale ): ?array { return null; } }
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); $GLOBALS['caps'] = array(); $GLOBALS['erasing'] = array(); $GLOBALS['contribution_calls'] = array();
	function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['caps'][ $cap ] ); }
	function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function is_serialized( string $value ): bool { return (bool) preg_match( '/^(?:[abisOd]:|N;)/', $value ); }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { $GLOBALS['contribution_calls'][] = array( $hook, $args ); return $value; }
	final class wpdb {
		public string $prefix = 'fixture_'; public string $users = 'fixture_users'; public string $usermeta = 'fixture_usermeta'; public string $last_error = ''; public array $queries = array(); public array $values = array(); public array $rows = array(); private array $prepared = array();
		public function suppress_errors( bool $value ): bool { return false; }
		public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
		public function prepare( string $sql, array $values ): string { preg_match_all( '/%%|%[sdf]/', $sql, $m ); $p = array_filter( $m[0], static fn( string $v ): bool => '%%' !== $v ); if ( count( $p ) !== count( $values ) ) { throw new \RuntimeException( 'Placeholder/value mismatch' ); } $key = 'prepared:' . count( $this->prepared ); $this->prepared[ $key ] = array( $sql, $values ); return $key; }
		public function get_var( string $sql ): mixed { [ $raw, $args ] = $this->prepared[ $sql ] ?? array( $sql, array() ); $this->queries[] = array( $raw, $args ); if ( str_contains( $raw, "'_aish_contributions_erasing'" ) ) { return ! empty( $GLOBALS['erasing'][ $args[0] ] ) ? 1 : null; } if ( ! $this->values ) { throw new \RuntimeException( 'Unexpected scalar SQL.' ); } return array_shift( $this->values ); }
		public function get_results( string $sql, string $format ): array { $this->queries[] = $this->prepared[ $sql ] ?? array( $sql, array() ); if ( ! $this->rows ) { throw new \RuntimeException( 'Unexpected row SQL.' ); } return array_shift( $this->rows ); }
		public function reset( array $values, array $rows ): void { $this->queries = array(); $this->values = $values; $this->rows = $rows; }
	}
	$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/'; foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Community/Exception.php', 'Community/Input.php', 'Developers/Exception.php', 'Developers/Input.php', 'Developers/Metadata.php', 'Developers/Query.php' ) as $file ) { require $base . $file; }
	use AISkillsHub\Core\Database\Database; use AISkillsHub\Core\Database\Migrator; use AISkillsHub\Core\Accounts\AccountService; use AISkillsHub\Core\Developers\Query; use AISkillsHub\Core\Developers\Exception;
	$count = 0; function check( bool $ok, string $why ): void { global $count; ++$count; if ( ! $ok ) { throw new \RuntimeException( $why ); } }
	function refuses( callable $operation ): void { try { $operation(); } catch ( Exception $e ) { check( true, 'Denied read.' ); return; } throw new \RuntimeException( 'Read bypassed capability or verification.' ); }
	function metadata( array $values, int $id = 42 ): array { $rows = array(); foreach ( $values as $key => $value ) { $rows[] = array( 'user_id' => (string) $id, 'meta_key' => $key, 'meta_value' => is_array( $value ) ? serialize( $value ) : (string) $value ); } return $rows; }
	$db = new wpdb(); $accounts = new AccountService(); $migrator = new Migrator(); $query = new Query( new Database( $db ), $migrator, $accounts );
	$snapshot = array( 'handle' => 'reviewed-alias', 'revision' => 1, 'reviewer_id' => 7, 'reviewed_at' => '2026-01-01 00:00:00', 'public_avatar' => false, 'show_contributions' => true, 'github_url' => 'https://github.com/maintainer', 'website_url' => '', 'translations' => array( 'en_US' => array( 'display_name' => 'Reviewed public name', 'bio' => 'Reviewed public biography.' ) ) );
	$meta = array( '_aish_public_profile' => '1', '_aish_profile_review_status' => 'pending', '_aish_profile_revision' => 3, '_aish_profile_candidate' => array( 'handle' => 'proposed-alias', 'show_contributions' => false, 'public_avatar' => false ), '_aish_profile_translations' => array( 'en_US' => array( 'display_name' => 'PRIVATE PENDING NAME', 'bio' => 'PRIVATE PENDING BIO' ) ), '_aish_profile_approved_snapshot' => $snapshot, '_aish_profile_private_note' => 'PRIVATE ADMIN NOTE' );
	$user = array( array( 'ID' => '42', 'user_nicename' => 'reviewed-alias' ) );
	$db->reset( array( 0 ), array( $user, metadata( $meta ) ) ); $public = $query->publicProfile( 'reviewed-alias', 'en_US' );
	check( $public['display_name'] === 'Reviewed public name', 'Pending edits retain the actual approved text.' );
	check( ! str_contains( json_encode( $public ), 'PRIVATE' ), 'Public DTO never includes private candidate or moderator notes.' );
	foreach ( array( 'user_id', 'reviewer_id', 'email', 'user_login', 'proof', 'claimant_user_id', 'private_note' ) as $key ) { check( ! array_key_exists( $key, $public ), 'No private identity field in public DTO.' ); }
	check( ! $GLOBALS['contribution_calls'], 'Restrictive current contribution consent hides old opt-in immediately.' );
	check( $public['skills']['total'] === 0 && ! $public['skills']['items'], 'No invented public claims or counts.' );
	$db->reset( array(), array( $user, metadata( $meta ) ) ); check( null === $query->publicProfile( 'reviewed-alias', 'zh_CN' ), 'Missing translation has no fabricated public page.' );
	$db->reset( array(), array( $user, metadata( $meta ) ) ); check( array( 'en_US' => 'reviewed-alias' ) === $query->alternatives( 'reviewed-alias' ), 'Hreflang alternatives include only reviewed real language.' );
	$db->reset( array(), array() ); check( null === $query->publicProfile( 'native_private_login', 'en_US' ) && ! $db->queries, 'Native default login-derived slugs never create a profile or throw on public attribution.' );
	$db->reset( array(), array( $user, metadata( array_replace( $meta, array( '_aish_public_profile' => '0' ) ) ) ) ); check( null === $query->publicProfile( 'reviewed-alias', 'en_US' ), 'Opt-out immediately removes public projection.' );
	$GLOBALS['erasing'][42] = 1; $db->reset( array(), array( $user ) ); check( null === $query->publicProfile( 'reviewed-alias', 'en_US' ), 'Privacy erase marker hides profile before cleanup.' ); unset( $GLOBALS['erasing'][42] );
	$accounts->active = false; $db->reset( array(), array( $user ) ); check( null === $query->publicProfile( 'reviewed-alias', 'en_US' ), 'Revoked verification/active state hides public data.' ); refuses( fn() => $query->mineProfile() ); $accounts->active = true;
	$db->reset( array(), array( metadata( $meta ) ) ); $own = $query->mineProfile(); check( $own['draft']['translations']['en_US']['display_name'] === 'PRIVATE PENDING NAME', 'Owner can edit own current candidate.' ); check( ! isset( $own['private_note'] ) && ! isset( $own['reviewer_id'] ) && ! isset( $own['approved']['reviewer_id'] ), 'Owner projection omits moderator identity and private notes.' );
	$db->reset( array(), array( array() ) ); check( null === $query->mineClaim( 90 ), 'Missing or foreign claim reads are indistinguishable.' ); check( str_contains( $db->queries[1][0], 'claimant_user_id=%d' ) && array( 90, 42 ) === $db->queries[1][1], 'Ownership is bound in SQL, never caller-supplied.' );
	refuses( fn() => $query->adminProfiles() ); refuses( fn() => $query->adminClaims() );
	$GLOBALS['caps']['aish_manage_developers'] = true;
	$db->reset( array( 2 ), array( array( array( 'ID' => '42' ), array( 'ID' => '43' ) ), array_merge( metadata( $meta ), metadata( $meta, 43 ) ) ) );
	$list = $query->adminProfiles( array( 'state' => 'all', 'q' => "test%' OR 1=1", 'sort' => 'updated_at', 'direction' => 'asc' ) );
	check( count( $list['items'] ) === 2 && count( $db->queries ) === 3, 'Admin page batches metadata in one query without N+1.' );
	check( ! str_contains( $db->queries[0][0], "OR 1=1" ) && str_contains( $db->queries[0][1][0], "OR 1=1" ), 'Search text is bound as literal data.' );
	refuses( fn() => $query->adminProfiles( array( 'sort' => 'id; DROP TABLE users' ) ) );
	$GLOBALS['caps']['aish_manage_author_claims'] = true; $db->reset( array( 0 ), array() ); $query->adminClaims( array( 'locale' => '', 'q' => 'known phrase', 'sort' => 'created_at', 'direction' => 'desc' ) ); check( str_contains( $db->queries[0][0], '$.application.reason' ) && ! str_contains( $db->queries[0][0], 'private_note' ), 'Search cannot be used as an oracle for private moderator text.' );
	$migrator->current = false; refuses( fn() => $query->publicProfile( 'reviewed-alias', 'en_US' ) );
	echo "Developer queries: $count isolated assertions passed; recording SQL fixtures only.\n";
}
