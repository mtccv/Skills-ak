<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Accounts\Store;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/** Business eligibility for native users; never writes roles, verification or content. */
final class Users {
	public const CAPABILITY = 'aish_manage_accounts';
	public const META = '_aish_operations_account';
	private const KEYS = array( '_aish_account_state', '_aish_account_deletion_pending', '_aish_email_verified_at', '_aish_verified_email_hash', '_aish_community_erasing', '_aish_contributions_erasing', self::META );
	public function __construct( private Database $db, private Migrator $migrator, private AccountService $accounts, private Locks $locks ) {}
	private function authorize(): void {
		if ( ! current_user_can( self::CAPABILITY ) || get_current_user_id() < 1 ) { throw new \RuntimeException( 'users_forbidden' ); }
		if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'users_unavailable' ); }
		try { $this->accounts->requireOwner( false ); } catch ( \Throwable $error ) { throw new \RuntimeException( 'users_forbidden' ); }
		$m = $this->metadata( array( get_current_user_id() ) )[ get_current_user_id() ] ?? array();
		foreach ( array( '_aish_account_deletion_pending', '_aish_community_erasing', '_aish_contributions_erasing' ) as $key ) { if ( ! empty( $m[ $key ] ) ) { throw new \RuntimeException( 'users_forbidden' ); } }
	}
	private static function page( int $page ): int { if ( $page < 1 || $page > 10000 ) { throw new \InvalidArgumentException( 'users_input' ); } return $page; }
	private static function text( string $text, int $max, bool $required = true ): string {
		if ( ! preg_match( '//u', $text ) || strlen( $text ) > $max * 4 || preg_match_all( '/./us', $text ) > $max || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text ) || strip_tags( $text ) !== $text ) { throw new \InvalidArgumentException( 'users_input' ); }
		$text = trim( $text );
		if ( $required && '' === $text ) { throw new \InvalidArgumentException( 'users_input' ); }
		// Operators use concise internal explanations, never credentials or token URLs.
		if ( preg_match( '~(?:Bearer\s+|(?:api[_ -]?key|token|password|secret)\s*[:=]|(?:sk-|gh[pousr]_|github_pat_)[A-Za-z0-9_-]{12,}|https?://[^\s]+[?&](?:token|key|password)=)~i', $text ) ) { throw new \InvalidArgumentException( 'users_sensitive_note' ); }
		return $text;
	}
	private function metadata( array $ids, bool $lock = false ): array {
		if ( ! $ids ) { return array(); }
		$m = $this->db->connection()->usermeta;
		$sql = "SELECT user_id,meta_key,meta_value FROM $m WHERE user_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( self::KEYS ), '%s' ) ) . ') ORDER BY umeta_id' . ( $lock ? ' FOR UPDATE' : '' );
		$result = array();
		foreach ( $this->db->rows( $sql, array_merge( $ids, self::KEYS ) ) as $row ) {
			if ( isset( $result[ $row['user_id'] ][ $row['meta_key'] ] ) ) { throw new \RuntimeException( 'users_ambiguous_meta' ); }
			$result[ $row['user_id'] ][ $row['meta_key'] ] = $row['meta_value'];
		}
		return $result;
	}
	private static function control( array $meta ): array {
		if ( ! isset( $meta[ self::META ] ) ) { return array( 'revision' => 0, 'paused' => false, 'prior_state' => '' ); }
		try { $value = json_decode( $meta[ self::META ], true, 4, JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { throw new \RuntimeException( 'users_ambiguous_meta' ); }
		if ( ! is_array( $value ) || array_keys( $value ) !== array( 'revision', 'paused', 'prior_state' ) || ! is_int( $value['revision'] ) || $value['revision'] < 1 || ! is_bool( $value['paused'] ) || ! in_array( $value['prior_state'], array( 'active', 'pending_email', 'legacy' ), true ) ) { throw new \RuntimeException( 'users_ambiguous_meta' ); }
		return $value;
	}
	private static function state( array $meta ): string {
		if ( ! empty( $meta['_aish_account_deletion_pending'] ) ) { return 'suspended'; }
		$state = $meta['_aish_account_state'] ?? '';
		return '' === $state ? 'legacy' : ( in_array( $state, array( 'active', 'pending_email', 'suspended' ), true ) ? $state : 'suspended' );
	}
	private static function emailEvidence( array $meta, string $email ): bool { return ! empty( $meta['_aish_email_verified_at'] ) && ! empty( $meta['_aish_verified_email_hash'] ) && hash_equals( Store::emailHash( $email ), $meta['_aish_verified_email_hash'] ); }
	/** Staff accounts require native administrator governance, not a community operator. */
	public static function protectedUser( \WP_User $user ): bool {
		if ( ( is_multisite() && is_super_admin( (int) $user->ID ) ) || array_intersect( $user->roles, array( 'administrator', 'editor', 'author', 'contributor' ) ) ) { return true; }
		foreach ( $user->allcaps as $cap => $allowed ) {
			if ( $allowed && ( str_starts_with( (string) $cap, 'aish_' ) || in_array( $cap, array( 'manage_options', 'edit_users', 'promote_users', 'delete_users', 'create_users', 'edit_posts', 'install_plugins', 'activate_plugins', 'edit_theme_options' ), true ) ) ) { return true; }
		}
		return false;
	}
	/** Batches summaries: no per-user review/favorite/submission queries or raw private content. */
	public function summaries( array $ids ): array {
		$this->authorize();
		if ( count( $ids ) > 100 ) { throw new \InvalidArgumentException( 'users_input' ); }
		foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 1 ) { throw new \InvalidArgumentException( 'users_input' ); } }
		$ids = array_values( array_unique( $ids ) ); if ( ! $ids ) { return array(); }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) ); $c = $this->db->connection();
		$rows = $this->db->rows( "SELECT ID,display_name,user_email,user_registered FROM {$c->users} WHERE ID IN ($placeholders)", $ids );
		$meta = $this->metadata( $ids ); $result = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['ID']; $m = $meta[ $id ] ?? array(); $control = self::control( $m );
			$verified = self::emailEvidence( $m, $row['user_email'] );
			$erasing = ! empty( $m['_aish_account_deletion_pending'] ) || ! empty( $m['_aish_community_erasing'] ) || ! empty( $m['_aish_contributions_erasing'] );
			$result[ $id ] = array( 'id' => $id, 'display_name' => $row['display_name'], 'registered_at' => $row['user_registered'], 'state' => self::state( $m ), 'verified' => $verified, 'erasing' => $erasing, 'revision' => $control['revision'], 'operations_paused' => $control['paused'], 'prior_state' => $control['prior_state'], 'favorites' => 0, 'reviews' => 0, 'submissions' => 0 );
		}
		foreach ( array( 'favorites' => "SELECT user_id,COUNT(*) AS total FROM {$this->db->table('favorites')} WHERE user_id IN ($placeholders) AND is_active=1 GROUP BY user_id", 'reviews' => "SELECT user_id,COUNT(*) AS total FROM {$this->db->table('reviews')} WHERE user_id IN ($placeholders) GROUP BY user_id", 'submissions' => "SELECT p.post_author AS user_id,COUNT(*) AS total FROM {$c->posts} p WHERE p.post_author IN ($placeholders) AND p.post_type='aish_message' AND p.post_status='private' AND (SELECT COUNT(*) FROM {$c->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_aish_submission_version')=1 AND EXISTS (SELECT 1 FROM {$c->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_aish_submission_version' AND m.meta_value='1') AND EXISTS (SELECT 1 FROM {$c->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_aish_message_kind' AND m.meta_value='submit') GROUP BY p.post_author" ) as $kind => $sql ) {
			foreach ( $this->db->rows( $sql, $ids ) as $row ) { if ( isset( $result[ $row['user_id'] ] ) ) { $result[ $row['user_id'] ][ $kind ] = (int) $row['total']; } }
		}
		return $result;
	}
	public function listing( array $filters = array() ): array {
		$this->authorize(); $page = self::page( (int) ( $filters['page'] ?? 1 ) ); $state = $filters['state'] ?? 'all';
		if ( ! in_array( $state, array( 'all', 'active', 'pending_email', 'suspended', 'legacy' ), true ) || ! is_string( $filters['q'] ?? '' ) ) { throw new \InvalidArgumentException( 'users_input' ); }
		$q = self::text( $filters['q'] ?? '', 100, false ); $c = $this->db->connection(); $where = '1=1'; $values = array();
		if ( 'all' !== $state ) {
			if ( 'legacy' === $state ) { $where .= " AND NOT EXISTS (SELECT 1 FROM {$c->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='_aish_account_state' AND m.meta_value<>'') AND NOT EXISTS (SELECT 1 FROM {$c->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='_aish_account_deletion_pending' AND m.meta_value='1')"; }
			elseif ( 'suspended' === $state ) { $where .= " AND EXISTS (SELECT 1 FROM {$c->usermeta} m WHERE m.user_id=u.ID AND ((m.meta_key='_aish_account_state' AND m.meta_value='suspended') OR (m.meta_key='_aish_account_deletion_pending' AND m.meta_value='1')))"; }
			else { $where .= " AND EXISTS (SELECT 1 FROM {$c->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='_aish_account_state' AND m.meta_value=%s) AND NOT EXISTS (SELECT 1 FROM {$c->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='_aish_account_deletion_pending' AND m.meta_value='1')"; $values[] = $state; }
		}
		if ( '' !== $q ) { $where .= ' AND (u.display_name LIKE %s OR u.ID=%d)'; array_push( $values, '%' . $c->esc_like( $q ) . '%', ctype_digit( $q ) ? (int) $q : 0 ); }
		$total = (int) $this->db->value( "SELECT COUNT(*) FROM {$c->users} u WHERE $where", $values );
		$rows = $this->db->rows( "SELECT u.ID FROM {$c->users} u WHERE $where ORDER BY u.user_registered DESC,u.ID DESC LIMIT 20 OFFSET %d", array_merge( $values, array( ( $page - 1 ) * 20 ) ) );
		return array( 'items' => array_values( $this->summaries( array_map( 'intval', array_column( $rows, 'ID' ) ) ) ), 'total' => $total, 'page' => $page, 'per_page' => 20 );
	}
	/** Account stock, not activity or new registrations during a reporting window. */
	public function summary(): array {
		$this->authorize(); $c = $this->db->connection(); $zh = str_starts_with( (string) get_user_locale(), 'zh' );
		$definitions = array(
			'registered' => array( $zh ? '原生账户总数' : 'Native accounts', '1=1', 'all' ),
			'pending_email' => array( $zh ? '待验证账户' : 'Accounts awaiting verification', "EXISTS (SELECT 1 FROM {$c->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='_aish_account_state' AND m.meta_value='pending_email')", 'pending_email' ),
			'suspended' => array( $zh ? '暂停 / 删除隔离账户' : 'Suspended / erasure-isolated accounts', "EXISTS (SELECT 1 FROM {$c->usermeta} m WHERE m.user_id=u.ID AND ((m.meta_key='_aish_account_state' AND m.meta_value='suspended') OR (m.meta_key='_aish_account_deletion_pending' AND m.meta_value='1')))", 'suspended' ),
			'verification_record' => array( $zh ? '有验证时间记录的账户' : 'Accounts with verification-time evidence', "EXISTS (SELECT 1 FROM {$c->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='_aish_email_verified_at' AND m.meta_value<>'')", 'all' ),
		); $rows = array();
		foreach ( $definitions as $key => [ $label, $where, $state ] ) {
			$rows[] = array( 'key' => 'users_' . $key, 'label' => $label, 'unit' => $zh ? '用户' : 'users', 'scope' => 'verification_record' === $key ? ( $zh ? '当前原生账户的验证时间记录；不等于当前邮箱仍核验，绑定状态须逐户核对。' : 'Current native accounts with a verification-time marker; current email binding must be checked per account.' ) : ( $zh ? '当前数据库账户存量；不是新增或活跃用户。' : 'Current database account stock; not new or active users.' ), 'value' => (int) $this->db->value( "SELECT COUNT(*) FROM {$c->users} u WHERE $where" ), 'status' => 'available', 'url' => add_query_arg( array( 'page' => 'aish-users', 'state' => $state ), admin_url( 'admin.php' ) ) );
		}
		return $rows;
	}
	public function detail( int $id, int $historyPage = 1 ): array {
		$this->authorize(); self::page( $historyPage ); $row = $this->summaries( array( $id ) )[ $id ] ?? null;
		if ( ! $row ) { throw new \RuntimeException( 'users_unavailable' ); }
		clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); $user = get_userdata( $id );
		$row['protected'] = $row['erasing'] || ! $user instanceof \WP_User || self::protectedUser( $user ) || $id === get_current_user_id();
		$events = $this->db->table( 'workflow_events' );
		$row['history'] = $this->db->rows( "SELECT id,subject_revision,action,actor_user_id,from_state,to_state,metadata_json,created_at FROM $events WHERE subject_type='user' AND subject_id=%d AND action IN ('user_suspended','user_restored') ORDER BY id DESC LIMIT 20 OFFSET %d", array( $id, ( $historyPage - 1 ) * 20 ) );
		$row['history_page'] = $historyPage; return $row;
	}
	/** Idempotent audited transition, sharing the account lock with community/native writes. */
	public function setState( int $id, string $action, int $revision, string $reason, string $recoveryCondition, string $requestKey ): void {
		$this->authorize();
		if ( $id < 1 || $revision < 0 || ! in_array( $action, array( 'suspend', 'resume' ), true ) || ! preg_match( '/^[a-f0-9]{32}$/D', $requestKey ) ) { throw new \InvalidArgumentException( 'users_input' ); }
		$reason = self::text( $reason, 500 ); $recoveryCondition = self::text( $recoveryCondition, 500, 'suspend' === $action ); $actor = get_current_user_id();
		$eventKey = hash( 'sha256', 'user-operation:' . $actor . ':' . $id . ':' . $requestKey );
		$payloadHash = hash( 'sha256', wp_json_encode( array( $id, $action, $revision, $reason, $recoveryCondition ) ) );
		$c = $this->db->connection();
		foreach ( array( $c->users, $c->usermeta ) as $table ) { $engine = $this->db->row( 'SHOW TABLE STATUS WHERE Name=%s', array( $table ) ); if ( ! $engine || 'innodb' !== strtolower( (string) $engine['Engine'] ) ) { throw new \RuntimeException( 'users_unavailable' ); } }
		$this->withAccounts( $actor, $id, function () use ( $id, $action, $revision, $reason, $recoveryCondition, $actor, $eventKey, $payloadHash, $c ): void {
			$cache = wp_suspend_cache_addition(); wp_suspend_cache_addition( true );
			try { $this->db->transaction( function () use ( $id, $action, $revision, $reason, $recoveryCondition, $actor, $eventKey, $payloadHash, $c ): void {
				$this->authorize();
				clean_user_cache( $actor ); wp_cache_delete( $actor, 'user_meta' ); $operator = get_userdata( $actor );
				if ( ! $operator instanceof \WP_User || ! user_can( $operator, self::CAPABILITY ) ) { throw new \RuntimeException( 'users_forbidden' ); }
				$identities = array_values( array_unique( array( $actor, $id ) ) ); sort( $identities, SORT_NUMERIC );
				foreach ( $identities as $identity ) { if ( ! $this->db->row( "SELECT ID FROM {$c->users} WHERE ID=%d FOR UPDATE", array( $identity ) ) ) { throw new \RuntimeException( 'users_unavailable' ); } }
				clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); $user = get_userdata( $id );
				if ( ! $user instanceof \WP_User || $actor === $id || self::protectedUser( $user ) ) { throw new \RuntimeException( 'users_protected' ); }
				$m = $this->metadata( array( $id ), true )[ $id ] ?? array();
				foreach ( array( '_aish_account_deletion_pending', '_aish_community_erasing', '_aish_contributions_erasing' ) as $key ) { if ( ! empty( $m[ $key ] ) ) { throw new \RuntimeException( 'users_erasing' ); } }
				$events = $this->db->table( 'workflow_events' ); $existing = $this->db->row( "SELECT metadata_json FROM $events WHERE event_key=%s", array( $eventKey ) );
				if ( $existing ) { $payload = json_decode( $existing['metadata_json'], true ); if ( ! is_array( $payload ) || ! hash_equals( (string) ( $payload['payload_hash'] ?? '' ), $payloadHash ) ) { throw new \RuntimeException( 'users_conflict' ); } return; }
				$control = self::control( $m ); $from = self::state( $m );
				if ( $revision !== $control['revision'] || $revision >= PHP_INT_MAX ) { throw new \RuntimeException( 'users_conflict' ); }
				if ( 'suspend' === $action ) {
					if ( ! in_array( $from, array( 'active', 'pending_email', 'legacy' ), true ) || $control['paused'] ) { throw new \RuntimeException( 'users_conflict' ); }
					$to = 'suspended'; $prior = $from;
				} else {
					if ( 'suspended' !== $from || ! $control['paused'] ) { throw new \RuntimeException( 'users_conflict' ); }
					$to = $control['prior_state'];
					// An administrator may correct the native email while paused. Its revoked
					// evidence must be renewed, never restored by a community operator.
					if ( 'active' === $to && ! self::emailEvidence( $m, $user->user_email ) ) { $to = 'pending_email'; }
					$prior = $to;
				}
				$this->db->execute( "DELETE FROM {$c->usermeta} WHERE user_id=%d AND meta_key IN ('_aish_account_state','_aish_operations_account')", array( $id ) );
				if ( 'legacy' !== $to ) { $this->db->execute( "INSERT INTO {$c->usermeta} (user_id,meta_key,meta_value) VALUES (%d,'_aish_account_state',%s)", array( $id, $to ) ); }
				$this->db->execute( "INSERT INTO {$c->usermeta} (user_id,meta_key,meta_value) VALUES (%d,'_aish_operations_account',%s)", array( $id, wp_json_encode( array( 'revision' => $revision + 1, 'paused' => 'suspend' === $action, 'prior_state' => $prior ) ) ) );
				$this->db->insert( 'workflow_events', array( 'event_key' => $eventKey, 'subject_type' => 'user', 'subject_id' => $id, 'subject_revision' => $revision + 1, 'action' => 'suspend' === $action ? 'user_suspended' : 'user_restored', 'actor_user_id' => $actor, 'from_state' => $from, 'to_state' => $to, 'metadata_json' => wp_json_encode( array( 'payload_hash' => $payloadHash, 'reason' => $reason, 'recovery_condition' => $recoveryCondition ) ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'retain_until' => gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS ) ) );
			} ); }
			finally { clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); wp_suspend_cache_addition( $cache ); }
		} );
	}
	private function withAccounts( int $actor, int $target, callable $operation ): void {
		$ids = array_values( array_unique( array( $actor, $target ) ) ); sort( $ids, SORT_NUMERIC ); $release = array();
		try { foreach ( $ids as $id ) { $release[] = $this->locks->acquire( array(), $id ); } $operation(); }
		finally { foreach ( array_reverse( $release ) as $unlock ) { $unlock(); } }
	}

	/** Native identity-pinned erasure removes private explanations; no new export endpoint. */
	public function erase( bool $done, int $id ): bool {
		if ( $id < 1 || ! $this->migrator->isCurrent() ) { return $done; }
		return $this->locks->withUser( $id, function () use ( $done, $id ): bool {
			if ( ! \AISkillsHub\Core\Accounts\Privacy::isErasingAccount( $id ) ) { throw new \RuntimeException( 'users_forbidden' ); }
			$t = $this->db->table( 'workflow_events' );
			$this->db->execute( "UPDATE $t SET subject_id=0,actor_user_id=NULL,metadata_json='{}' WHERE subject_type='user' AND subject_id=%d ORDER BY id LIMIT 50", array( $id ) );
			return null === $this->db->value( "SELECT id FROM $t WHERE subject_type='user' AND subject_id=%d LIMIT 1", array( $id ) ) && $done;
		} );
	}
}
