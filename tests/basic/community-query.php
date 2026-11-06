<?php
declare(strict_types=1);

// Isolated projection contracts with a recording SQL adapter, not a MySQL test.
namespace AISkillsHub\Core\Accounts {
	final class AccountService {
		public int $owner = 42;
		public array $calls = array();
		public function requireOwner( bool $verified ): object { $this->calls[] = $verified; return (object) array( 'ID' => $this->owner ); }
	}
}
namespace AISkillsHub\Core\Database {
	final class Migrator {
		public bool $current = true;
		public function isCurrent(): bool { return $this->current; }
	}
}
namespace AISkillsHub\Core\Frontend {
	final class PublicCatalog {
		public static array $cards = array();
		public static array $calls = array();
		public function __construct( mixed $db, mixed $migrator ) {}
		public function matchingIdsSql( string $locale, array $filters ): string {
			return "SELECT id FROM fixture_public WHERE locale='" . $locale . "' AND needle='" . str_replace( "'", "''", $filters['q'] ?? '' ) . "'";
		}
		public function cardsByIds( array $ids, string $locale ): array {
			self::$calls[] = array( $ids, $locale );
			return array_values( array_filter( self::$cards[ $locale ] ?? array(), static fn( array $card ): bool => in_array( $card['id'], $ids, true ) ) );
		}
	}
}
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['caps'] = array(); $GLOBALS['meta'] = array();
	function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['caps'][ $cap ] ); }
	function get_user_meta( int $user, string $key, bool $single ): mixed { return $GLOBALS['meta'][ $user ][ $key ] ?? ''; }
	function sanitize_text_field( string $text ): string { return trim( strip_tags( $text ) ); }
	function sanitize_textarea_field( string $text ): string { return trim( strip_tags( $text ) ); }
	final class wpdb {
		public string $prefix = 'fixture_'; public string $users = 'fixture_users'; public string $usermeta = 'fixture_usermeta'; public string $last_error = '';
		public array $queries = array(); public array $guards = array(); public array $values = array(); public array $rows = array(); private array $prepared = array();
		public function suppress_errors( bool $suppress ): bool { return false; }
		public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
		public function prepare( string $sql, array $values ): string {
			preg_match_all( '/%%|%[sdf]/', $sql, $matches );
			$placeholders = array_filter( $matches[0], static fn( string $part ): bool => '%%' !== $part );
			if ( count( $placeholders ) !== count( $values ) ) { throw new \RuntimeException( 'Fixture placeholder count mismatch.' ); }
			$key = 'prepared:' . count( $this->prepared ); $this->prepared[ $key ] = array( $sql, $values ); return $key;
		}
		private function record( string $sql ): void { $this->queries[] = $this->prepared[ $sql ] ?? array( $sql, array() ); }
		public function get_var( string $sql ): mixed {
			[ $raw, $values ] = $this->prepared[ $sql ] ?? array( $sql, array() );
			if ( str_contains( $raw, "meta_key='_aish_community_erasing'" ) ) { $this->guards[] = array( $raw, $values ); return ! empty( $GLOBALS['meta'][ $values[0] ]['_aish_community_erasing'] ) ? 1 : null; }
			$this->record( $sql ); if ( ! $this->values ) { throw new \RuntimeException( 'Unexpected scalar query.' ); } return array_shift( $this->values );
		}
		public function get_results( string $sql, string $format ): array { $this->record( $sql ); if ( ! $this->rows ) { throw new \RuntimeException( 'Unexpected row query.' ); } return array_shift( $this->rows ); }
		public function reset( array $values = array(), array $rows = array() ): void { $this->queries = array(); $this->guards = array(); $this->values = $values; $this->rows = $rows; }
	}
	$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
	foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Community/Exception.php', 'Community/Input.php', 'Community/Query.php' ) as $file ) { require $base . $file; }
	use AISkillsHub\Core\Accounts\AccountService;
	use AISkillsHub\Core\Database\Database;
	use AISkillsHub\Core\Database\Migrator;
	use AISkillsHub\Core\Frontend\PublicCatalog;
	use AISkillsHub\Core\Community\Query;
	use AISkillsHub\Core\Community\Exception;
	$count = 0;
	function check( bool $value, string $why ): void { global $count; if ( ! $value ) { throw new \RuntimeException( $why ); } ++$count; }
	function refuses( callable $operation, string $reason ): void { try { $operation(); } catch ( Exception $error ) { check( $error->reason === $reason, 'Expected safe failure: ' . $reason ); return; } throw new \RuntimeException( 'Operation did not fail closed.' ); }
	$connection = new wpdb(); $accounts = new AccountService(); $migrator = new Migrator();
	$query = new Query( new Database( $connection ), $migrator, $accounts );

	// Empty and changing aggregates are read afresh; no fabricated rating or cache.
	$connection->reset( array( 0 ), array( array( array( 'total' => 0, 'rating_sum' => null ) ) ) );
	$summary = $query->publicSummary( 7, 'zh_CN' );
	check( null === $summary['average'] && 0 === $summary['count'] && 0 === $summary['favorites'], 'No approved reviews means no rating.' );
	check( array( 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 ) === $summary['distribution'], 'Empty star distribution contains actual zeroes.' );
	$connection->reset( array( 3 ), array( array( array( 'total' => 3, 'rating_sum' => 11, 'star1' => 0, 'star2' => 0, 'star3' => 1, 'star4' => 2, 'star5' => 0 ) ) ) );
	$summary = $query->publicSummary( 7, 'en_US' );
	check( 3.67 === $summary['average'] && 3 === $summary['count'] && 3 === $summary['favorites'], 'Changed facts replace empty summary immediately.' );
	check( 2 === count( $connection->queries ), 'Summary uses two aggregate queries, independent of review count.' );
	foreach ( $connection->queries as [ $sql, $values ] ) {
		check( array( 7 ) === $values && str_contains( $sql, "locale='en_US'" ), 'Shared Skill identity and current public language are bound.' );
		check( str_contains( $sql, "'_aish_account_deletion_pending'" ) && str_contains( $sql, "'_aish_community_erasing'" ), 'Deleting identities are excluded from public aggregates.' );
		foreach ( array( '_aish_account_state', '_aish_verified_email_hash', '_aish_email_verified_at' ) as $key ) {
			check( 1 === preg_match( '/SELECT COUNT\(\*\)[^)]*meta_key=\'' . $key . '\'\)=1/', $sql ), 'Conflicting authoritative metadata must fail closed.' );
		}
	}

	// Public review SQL cannot read a pending or another review's pointer.
	$connection->reset( array( 1 ), array( array( array( 'id' => 20, 'revision_id' => 101, 'rating' => 4 ) ) ) );
	$query->publicReviews( 7, 'zh_CN', array( 'locale' => 'en_US', 'sort' => 'oldest' ) );
	[ $sql, $values ] = $connection->queries[1]; $fields = substr( $sql, 0, strpos( $sql, ' FROM ' ) );
	check( str_contains( $sql, 'v.id=r.approved_revision_id AND v.review_id=r.id' ), 'Public pointer belongs to its own review.' );
	check( str_contains( $sql, "r.state='approved' AND v.moderation_state='approved'" ) && str_contains( $sql, 'v.rating BETWEEN 1 AND 5' ), 'Public source requires both approvals and valid score.' );
	check( str_contains( $sql, 'v.moderator_id>0' ) && str_contains( $sql, 'v.moderated_at>=' ), 'A valid moderation record is required.' );
	foreach ( array( 'user_id', 'private_note', 'request_key', 'moderator_id', 'email', '*' ) as $privateField ) { check( ! str_contains( $fields, $privateField ), 'Public SELECT excludes ' . $privateField ); }
	check( array( 7, 'en_US', 12, 0 ) === $values && str_contains( $sql, 'r.first_published_at ASC' ), 'Review language, sort and pagination remain validated/bound.' );
	$connection->reset( array(), array( array() ) );
	check( null === $query->publicReview( 20, 102, 'zh_CN' ), 'An unavailable pending or stale public revision returns nothing.' );
	check( array( 20, 102 ) === $connection->queries[0][1], 'Reported review and exact revision are both constrained.' );

	// A forged owner filter never changes the authenticated account identity.
	$connection->reset( array( 1 ) ); check( $query->favoriteState( 7 ), 'Own active favorite is readable.' );
	check( array( 42, 7 ) === $connection->queries[0][1], 'Favorite relation belongs to the authenticated user.' );
	$accounts->owner = 84; $connection->reset( array( 0 ) ); check( ! $query->favoriteState( 7 ), 'Switching user cannot reuse another private result.' );
	check( array( 84, 7 ) === $connection->queries[0][1], 'Changed identity is queried afresh.' ); $accounts->owner = 42;
	PublicCatalog::$cards = array( 'zh_CN' => array( array( 'id' => 7, 'title' => '中文公开资源' ) ), 'en_US' => array( array( 'id' => 8, 'title' => 'Public English translation' ) ) ); PublicCatalog::$calls = array();
	$connection->reset( array( 3 ), array( array( array( 'skill_id' => '7', 'created_at' => '2026-01-01' ), array( 'skill_id' => '8', 'created_at' => '2026-01-02' ), array( 'skill_id' => '9', 'created_at' => '2026-01-03' ) ) ) );
	$favorites = $query->mineFavorites( 'zh_CN', array( 'user_id' => 999, 'sort' => 'title', 'q' => 'literal%s %d 50%' ) );
	check( array( 'zh_CN', 42 ) === $connection->queries[0][1], 'Client owner is ignored, including title-join parameter order.' );
	check( array( 'zh_CN', 42, 12, 0 ) === $connection->queries[1][1], 'Search percent literals cannot consume outer prepared placeholders.' );
	check( str_contains( $connection->queries[1][0], "literal%%s %%d 50%%" ), 'An already prepared subquery retains literal search percent characters.' );
	check( $favorites['items'][0]['available'] && null === $favorites['items'][0]['alternative'], 'Existing translation is used directly.' );
	check( ! $favorites['items'][1]['available'] && 'en_US' === $favorites['items'][1]['alternative']['locale'], 'Missing translation links only to an available public translation.' );
	check( null === $favorites['items'][2]['skill'] && null === $favorites['items'][2]['alternative'], 'Unavailable favorite exposes no stale title or source.' );
	check( array( array( array( 7, 8, 9 ), 'zh_CN' ), array( array( 8, 9 ), 'en_US' ) ) === PublicCatalog::$calls, 'Card lookup batches primary and missing alternate translations without N+1.' );
	check( 2 === count( $connection->queries ) && 1 === count( $connection->guards ), 'Private relation pagination has one count, one data query and one identity privacy guard.' );

	// An approved revision remains visible to its owner alongside a pending edit;
	// corrupt cross-review pointers are discarded and moderator secrets stripped.
	$head = array( 'id' => '20', 'skill_id' => '7', 'user_id' => '42', 'state' => 'approved', 'lock_version' => '3', 'approved_revision_id' => '101', 'pending_revision_id' => '102', 'latest_revision_id' => '103' );
	$revision = array( 'id' => 101, 'review_id' => 20, 'rating' => 4, 'comment' => 'Approved experience', 'private_note' => 'Moderator-only evidence', 'moderator_id' => 1, 'request_key' => str_repeat( 'a', 64 ) );
	$pending = array_replace( $revision, array( 'id' => 102, 'rating' => 1, 'comment' => 'Pending edit' ) );
	$alien = array_replace( $revision, array( 'id' => 103, 'review_id' => 21, 'comment' => 'Other user private draft' ) );
	$connection->reset( array(), array( array( $head ), array( $revision, $pending, $alien ) ) );
	$mine = $query->mineReview( 7 );
	check( 4 === $mine['approved']['rating'] && 1 === $mine['pending']['rating'], 'Owner can compare the retained approval and separate pending edit.' );
	check( null === $mine['latest'], 'Cross-review revision pointer never exposes another author draft.' );
	check( ! isset( $mine['user_id'], $mine['latest_revision_id'] ), 'Private view avoids redundant identity/internal latest pointer.' );
	foreach ( array( 'approved', 'pending' ) as $kind ) { foreach ( array( 'private_note', 'moderator_id', 'request_key' ) as $field ) { check( ! isset( $mine[ $kind ][ $field ] ), 'Owner cannot access moderator-only ' . $field ); } }
	check( array( 42, 7 ) === $connection->queries[0][1] && array( 101, 102, 103 ) === $connection->queries[1][1], 'Private head is owner-bound and revisions are fetched as one batch.' );

	// Capability, privacy fence and migration guards fail before reading content.
	$connection->reset(); refuses( fn() => $query->adminReviews(), 'forbidden' ); refuses( fn() => $query->adminReports( 'skill' ), 'forbidden' ); refuses( fn() => $query->history( 'review', 20 ), 'forbidden' );
	check( array() === $connection->queries, 'Unauthorized administrator queries never reach SQL.' );
	$GLOBALS['meta'][42]['_aish_community_erasing'] = 1; refuses( fn() => $query->favoriteState( 7 ), 'forbidden' ); unset( $GLOBALS['meta'][42] );
	$migrator->current = false; refuses( fn() => $query->mineReview( 7 ), 'storage_unavailable' ); refuses( fn() => $query->publicSummary( 7, 'en_US' ), 'storage_unavailable' ); $migrator->current = true;
	foreach ( array( array( 'page' => 10001 ), array( 'per_page' => 25 ), array( 'page' => 0 ), array( 'sort' => 'title; DROP TABLE reviews' ) ) as $badFilter ) { refuses( fn() => $query->publicReviews( 7, 'en_US', $badFilter ), 'invalid_input' ); }
	refuses( fn() => $query->liveUserSql( 'r.user_id OR 1=1' ), 'invalid_input' ); refuses( fn() => $query->publicSummary( 7, 'fr_FR' ), 'invalid_input' );
	$connection->reset( array( 1 ) ); $empty = $query->publicReviews( 7, 'en_US', array( 'page' => 2 ) );
	check( array() === $empty['items'] && 1 === count( $connection->queries ), 'Out-of-range page omits an unnecessary data query.' );
	echo "Community query: $count isolated assertions passed; recording adapters, no WordPress/MySQL integration.\n";
}
