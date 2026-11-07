<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Frontend;
use AISkillsHub\Core\Accounts\Store;

defined( 'ABSPATH' ) || exit;

/** Private current-user projection. No notification snapshots become content or URLs. */
final class Notifications {
	public const TYPES = array( 'all', 'account', 'community', 'contribution' );
	public function __construct( private Store $store, private AccountService $accounts ) {}
	public static function type( mixed $type ): string {
		if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) { throw new \RuntimeException( 'invalid_request' ); }
		return $type;
	}
	public function mine( string $locale, int $page = 1, string $type = 'all', bool $overview = false ): array {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || $page < 1 || $page > 1000 ) { throw new \RuntimeException( 'invalid_request' ); }
		$owner = (int) $this->accounts->requireOwner()->ID; self::type( $type );
		$rows = $this->store->notifications( $owner, $overview ? 5 : 21, $overview ? 0 : ( $page - 1 ) * 20, $type );
		$targets = $this->targets( $owner, $rows, $locale );
		foreach ( $rows as &$row ) {
			$row['target_url'] = $targets[ (int) $row['id'] ] ?? null;
			// Legacy/invalid event data is never used as a title, body or link.
			unset( $row['params_json'], $row['subject_id'], $row['subject_type'] );
		} unset( $row );
		return array( 'notifications' => $rows, 'unread_count' => $this->store->unreadCount( $owner ), 'notification_type' => $type, 'page' => $page );
	}
	public function read( array $ids ): void {
		$owner = (int) $this->accounts->requireOwner()->ID;
		$this->store->markReadBatch( $owner, $ids );
	}
	/** Batched target checks, bounded by one page. Destination handlers check ownership again. */
	private function targets( int $owner, array $rows, string $locale ): array {
		$db = $this->store->database(); $native = $db->connection(); $groups = array(); $safe = array();
		foreach ( $rows as $row ) {
			$kind = (string) ( $row['subject_type'] ?? '' ); $id = (int) ( $row['subject_id'] ?? 0 );
			if ( $id > 0 ) { $groups[ $kind ][ $id ] = $id; }
		}
		// Account notices contain fixed event codes only; essential security remains reachable.
		if ( isset( $groups['account'][ $owner ] ) ) { $safe['account'][ $owner ] = Frontend::url( 'security', $locale ); }
		$erasing = $db->value( "SELECT 1 FROM {$native->usermeta} WHERE user_id=%d AND meta_key IN ('_aish_community_erasing','_aish_contributions_erasing','_aish_account_deletion_pending') AND meta_value<>'' AND meta_value<>'0' LIMIT 1", array( $owner ) );
		if ( ! $erasing ) {
			foreach ( array( 'review' => array( 'reviews', 'user_id' ), 'claim' => array( 'author_claims', 'claimant_user_id' ) ) as $kind => [ $entity, $column ] ) {
				$ids = array_values( $groups[ $kind ] ?? array() ); if ( ! $ids ) { continue; }
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$states = "('pending','approved','rejected')";
				$found = $db->rows( 'SELECT id FROM ' . $db->table( $entity ) . " WHERE $column=%d AND id IN ($placeholders) AND state IN $states", array_merge( array( $owner ), $ids ) );
				foreach ( $found as $target ) {
					$id = (int) $target['id'];
					$safe[ $kind ][ $id ] = 'review' === $kind ? \AISkillsHub\Core\Community\Frontend::url( 'reviews', $locale ) : \AISkillsHub\Core\Developers\Frontend::url( 'claim', $locale, array( 'id' => $id ) );
				}
			}
			foreach ( array( 'submission' => 'submit', 'update_suggestion' => 'update_suggestion' ) as $kind => $nativeKind ) {
				$ids = array_values( $groups[ $kind ] ?? array() ); if ( ! $ids ) { continue; }
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) ); $m = $native->postmeta;
				$sql = "SELECT p.ID FROM {$native->posts} p WHERE p.post_type='aish_message' AND p.post_status='private' AND p.post_author=%d AND p.ID IN ($placeholders)";
				foreach ( array( '_aish_submission_version', '_aish_message_kind', '_aish_submission_state' ) as $key ) { $sql .= " AND (SELECT COUNT(*) FROM $m m WHERE m.post_id=p.ID AND m.meta_key='$key')=1"; }
				$sql .= " AND EXISTS (SELECT 1 FROM $m m WHERE m.post_id=p.ID AND m.meta_key='_aish_submission_version' AND m.meta_value='1') AND EXISTS (SELECT 1 FROM $m m WHERE m.post_id=p.ID AND m.meta_key='_aish_message_kind' AND m.meta_value=%s) AND EXISTS (SELECT 1 FROM $m m WHERE m.post_id=p.ID AND m.meta_key='_aish_submission_state' AND m.meta_value IN ('draft','submitted','in_review','needs_changes','accepted','rejected'))";
				foreach ( $db->rows( $sql, array_merge( array( $owner ), $ids, array( $nativeKind ) ) ) as $target ) { $id = (int) $target['ID']; $safe[ $kind ][ $id ] = \AISkillsHub\Core\Submissions\Frontend::url( 'submission', $locale, array( 'id' => $id ) ); }
			}
			if ( isset( $groups['developer'][ $owner ] ) ) {
				$states = $db->rows( "SELECT meta_value FROM {$native->usermeta} WHERE user_id=%d AND meta_key='_aish_profile_review_status'", array( $owner ) );
				if ( 1 === count( $states ) && in_array( $states[0]['meta_value'], array( 'pending', 'approved', 'rejected' ), true ) ) { $safe['developer'][ $owner ] = \AISkillsHub\Core\Developers\Frontend::url( 'developer', $locale ); }
			}
		}
		$result = array();
		foreach ( $rows as $row ) { $result[ (int) $row['id'] ] = $safe[ $row['subject_type'] ?? '' ][ (int) ( $row['subject_id'] ?? 0 ) ] ?? null; }
		// Report notifications deliberately expose no target: there is no private report-detail route.
		return $result;
	}
}
