<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Learning;
use AISkillsHub\Core\Database\{Database, Migrator};
use AISkillsHub\Core\Frontend\{PublicCatalog, Editorial, Router};
use AISkillsHub\Core\Discovery\{Query, Frontend};
use AISkillsHub\Core\Submissions\Store as NativeStore;
defined( 'ABSPATH' ) || exit;

/** Private issue snapshots contain only public resource IDs. The existing outbox owns every delivery. */
final class SubscriptionCampaigns {
	public const TYPE = 'aish_mail_issue';
	private static bool $writing = false;
	private NativeStore $native;
	public function __construct( private Database $db, private Subscriptions $subscriptions, private PublicCatalog $catalog, private Editorial $editorial, private Query $discovery ) { $this->native = new NativeStore( $db ); }
	public static function writing(): bool { return self::$writing; }
	public function register(): void { add_action( 'init', array( $this, 'registerType' ) ); }
	public function registerType(): void {
		register_post_type( self::TYPE, array( 'label' => 'Newsletter issues', 'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true, 'show_ui' => false, 'can_export' => false, 'show_in_rest' => false, 'rewrite' => false, 'query_var' => false, 'has_archive' => false, 'supports' => array(), 'capabilities' => array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'do_not_allow' ), 'map_meta_cap' => false ) );
	}
	private static function selection( string $locale, string $kind ): void { if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) || ! in_array( $kind, array( 'weekly', 'new_resources' ), true ) ) { throw new \InvalidArgumentException( 'invalid_input' ); } }
	private function fresh(): array { $m = new Migrator( $this->db ); $c = new PublicCatalog( $this->db, $m ); return array( $c, new Editorial( $c, $this->db ), new Query( $this->db, $m, $c ) ); }
	private static function text( string $value, int $limit ): string { $plain = preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ); preg_match( '/^.{0,' . $limit . '}/us', trim( $plain ?? '' ), $match ); return $match[0] ?? ''; }
	private function references( string $locale, string $kind ): array {
		self::selection( $locale, $kind ); [ $catalog, $editorial, $discovery ] = $this->fresh(); $refs = array();
		$add = static function ( string $type, array $items, int $limit ) use ( &$refs ): void { foreach ( array_slice( $items, 0, $limit ) as $item ) { $refs[ $type . ':' . $item['id'] ] = array( 'type' => $type, 'id' => (int) $item['id'] ); } };
		if ( 'weekly' === $kind ) {
			// Editorial collections are curated; generic GitHub activity never selects an update here.
			$collections = $discovery->topics( 'collection', $locale ); foreach ( array_slice( $collections, 0, 1 ) as $topic ) { $detail = $discovery->detail( 'collection', $topic['slug'], $locale, array( 'per_page' => 4 ) ); $add( 'skill', $detail['skills']['items'] ?? array(), 4 ); }
			$add( 'skill', $catalog->featured( $locale, 2 ), 2 );
		}
		$recent = $discovery->list( 'recently-added', $locale, array( 'per_page' => 6 ) )['items'];
		if ( 'new_resources' === $kind ) {
			$minimum = gmdate( 'Y-m-d H:i:s', time() - 86400 ); $ids = array_column( $recent, 'id' ); $allowed = array();
			if ( $ids ) { $allowed = array_map( 'intval', array_column( $this->db->rows( 'SELECT id FROM ' . $this->db->table( 'skills' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') AND created_at>=%s', array( $minimum ) ), 'id' ) ); }
			$recent = array_values( array_filter( $recent, static fn( array $item ): bool => in_array( (int) $item['id'], $allowed, true ) ) );
		}
		$add( 'skill', $recent, 6 ); $tutorials = $editorial->tutorials( $locale, array( 'per_page' => 3 ) )['items'];
		if ( 'new_resources' === $kind ) { $tutorials = array_values( array_filter( $tutorials, static fn( array $item ): bool => ( $item['published_at'] ?? '' ) >= gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) ); }
		$add( 'tutorial', $tutorials, 2 ); if ( 'weekly' === $kind ) { $add( 'use_case', $discovery->topics( 'use_case', $locale ), 2 ); }
		return array_slice( array_values( $refs ), 0, 12 );
	}
	private function resolve( array $refs, string $locale ): array {
		[ $catalog, $editorial, $discovery ] = $this->fresh(); $groups = array( 'skill' => array(), 'tutorial' => array(), 'use_case' => array() ); foreach ( $refs as $ref ) { if ( ! is_array( $ref ) || array_keys( $ref ) !== array( 'type', 'id' ) || ! isset( $groups[ $ref['type'] ?? '' ] ) || ! is_int( $ref['id'] ?? null ) || $ref['id'] < 1 ) { throw new \RuntimeException( 'storage_unavailable' ); } $groups[ $ref['type'] ][] = $ref['id']; }
		$items = array(); $sources = array( 'skill' => $catalog->cardsByIds( $groups['skill'], $locale ), 'tutorial' => $editorial->cardsByIds( $groups['tutorial'], $locale ), 'use_case' => $discovery->topicsByIds( 'use_case', $groups['use_case'], $locale ) );
		foreach ( $sources as $type => $rows ) { foreach ( $rows as $row ) { $url = 'use_case' === $type ? Frontend::link( 'use-cases', $locale, array( 'id' => (int) $row['id'], 'slug' => $row['slug'] ) ) : Router::url( $type, array( 'id' => (int) $row['id'], 'slug' => $row['slug'] ), $locale ); $items[ $type . ':' . $row['id'] ] = array( 'type' => $type, 'id' => (int) $row['id'], 'title' => self::text( $row['title'] ?? $row['name'] ?? '', 150 ), 'summary' => self::text( $row['summary'] ?? $row['description'] ?? '', 220 ), 'url' => $url ); } }
		$result = array(); foreach ( $refs as $ref ) { if ( isset( $items[ $ref['type'] . ':' . $ref['id'] ] ) ) { $result[] = $items[ $ref['type'] . ':' . $ref['id'] ]; } } return $result;
	}
	private function render( array $refs, string $locale, string $kind ): array { return $this->renderItems( $this->resolve( $refs, $locale ), $locale, $kind ); }
	private function renderItems( array $items, string $locale, string $kind ): array {
		 $zh = 'zh_CN' === $locale; $subject = '[AI Skills Hub] ' . ( 'weekly' === $kind ? ( $zh ? '每周精选' : 'Weekly selections' ) : ( $zh ? '新资源通知' : 'New resources' ) );
		$body = $zh ? '您主动订阅了此类邮件。以下内容仅包含当前语言下已经审核并公开的资源；风险审核不代表绝对安全保证。' : 'You explicitly subscribed to these emails. These resources are reviewed and public in your selected language. Review does not guarantee safety.';
		foreach ( $items as $item ) { $body .= "\n\n" . $item['title'] . "\n" . $item['summary'] . "\n" . $item['url']; } return array( 'subject' => $subject, 'body' => $body, 'items' => $items );
	}
	public function preview( string $locale, string $kind = 'weekly' ): array { self::selection( $locale, $kind ); $preview = $this->render( $this->references( $locale, $kind ), $locale, $kind ); $preview['fingerprint'] = hash( 'sha256', wp_json_encode( array( $locale, $kind, $preview['items'] ) ) ); return $preview; }
	private function write( array $state, ?int $id = null ): int {
		$record = array( 'post_type' => self::TYPE, 'post_status' => 'private', 'post_author' => 0, 'post_title' => 'Newsletter ' . $state['period'] . ' ' . $state['locale'] . ' ' . $state['kind'], 'post_content' => wp_json_encode( $state ), 'post_excerpt' => '', 'post_name' => 'newsletter-' . hash( 'sha256', $state['locale'] . ':' . $state['kind'] . ':' . $state['period'] ), 'post_parent' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed' ); if ( $id ) { $record['ID'] = $id; } return $this->native->post( $record );
	}
	private function transaction( callable $operation ): mixed { try { self::$writing = true; return $this->native->transaction( $operation ); } finally { self::$writing = false; } }
	private function state( int $id, bool $lock = false ): ?array {
		if ( $id < 1 ) { return null; } $p = $this->db->row( 'SELECT post_content FROM ' . $this->db->connection()->posts . " WHERE ID=%d AND post_type=%s AND post_status='private'" . ( $lock ? ' FOR UPDATE' : '' ), array( $id, self::TYPE ) ); if ( ! $p ) { return null; }
		$s = json_decode( $p['post_content'], true, 8 ); if ( ! is_array( $s ) || 1 !== ( $s['version'] ?? null ) || ! in_array( $s['state'] ?? '', array( 'queued', 'complete', 'failed', 'expired' ), true ) || ! is_array( $s['refs'] ?? null ) || count( $s['refs'] ) > 12 || ! is_array( $s['approved'] ?? null ) || count( $s['approved'] ) > 12 || ! is_int( $s['approved_by'] ?? null ) || $s['approved_by'] < 1 ) { throw new \RuntimeException( 'storage_unavailable' ); }
		self::selection( $s['locale'] ?? '', $s['kind'] ?? '' ); foreach ( array( 'revision', 'cursor', 'upper_user_id', 'queued', 'skipped', 'retries' ) as $key ) { if ( ! is_int( $s[ $key ] ?? null ) || $s[ $key ] < 0 ) { throw new \RuntimeException( 'storage_unavailable' ); } } return $s;
	}
	private function create( string $locale, string $kind, string $fingerprint ): int {
		self::selection( $locale, $kind ); $period = 'weekly' === $kind ? gmdate( 'o-\WW' ) : gmdate( 'Y-m-d' ); $name = 'newsletter-' . hash( 'sha256', $locale . ':' . $kind . ':' . $period );
		$existing = $this->db->value( 'SELECT ID FROM ' . $this->db->connection()->posts . ' WHERE post_type=%s AND post_name=%s LIMIT 1', array( self::TYPE, $name ) ); if ( $existing ) { return (int) $existing; }
		$refs = $this->references( $locale, $kind ); if ( ! $refs ) { return 0; } $items = $this->resolve( $refs, $locale ); if ( ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) || ! hash_equals( hash( 'sha256', wp_json_encode( array( $locale, $kind, $items ) ) ), $fingerprint ) ) { throw new \RuntimeException( 'preview_changed' ); } $approved = array(); foreach ( $items as $item ) { $approved[ $item['type'] . ':' . $item['id'] ] = hash( 'sha256', wp_json_encode( $item ) ); }
		return $this->transaction( function () use ( $locale, $kind, $period, $refs, $approved ): int { return $this->write( array( 'version' => 1, 'revision' => 1, 'locale' => $locale, 'kind' => $kind, 'period' => $period, 'state' => 'queued', 'refs' => $refs, 'approved' => $approved, 'approved_by' => get_current_user_id(), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 'weekly' === $kind ? 604800 : 86400 ) ), 'cursor' => 0, 'upper_user_id' => (int) $this->db->value( 'SELECT COALESCE(MAX(ID),0) FROM ' . $this->db->connection()->users ), 'queued' => 0, 'skipped' => 0, 'retries' => 0, 'last_error' => '' ) ); } );
	}
	public function queueIssue( string $locale, string $kind, string $requestKey, string $fingerprint = '' ): int {
		if ( ! preg_match( '/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $requestKey ) ) { throw new \InvalidArgumentException( 'invalid_request' ); }
		$id = $this->subscriptions->withPolicy( function () use ( $locale, $kind, $fingerprint ): int { $c = $this->subscriptions->publicStatus(); if ( ! $c['enabled'] || $c['paused'] || ! $c['mail_ready'] ) { throw new \RuntimeException( 'mail_unavailable' ); } return $this->create( $locale, $kind, $fingerprint ); } ); if ( ! $id ) { throw new \RuntimeException( 'empty_issue' ); } do_action( 'aish_account_mail_wake' ); return $id;
	}
	public function message( int $id, string $locale, string $kind ): array { self::selection( $locale, $kind ); $s = $this->state( $id ); if ( ! $s || $locale !== $s['locale'] || $kind !== $s['kind'] || ! in_array( $s['state'], array( 'queued', 'complete' ), true ) || $s['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) { return array( 'subject' => '', 'body' => '', 'items' => array() ); } $current = $this->resolve( $s['refs'], $locale ); $approvedItems = array(); foreach ( $current as $item ) { $key = $item['type'] . ':' . $item['id']; if ( isset( $s['approved'][ $key ] ) && hash_equals( $s['approved'][ $key ], hash( 'sha256', wp_json_encode( $item ) ) ) ) { $approvedItems[] = $item; } } return $this->renderItems( $approvedItems, $locale, $kind ); }
	public function detail( int $id ): array { $s = $this->state( $id ); if ( ! $s ) { throw new \RuntimeException( 'not_found' ); } unset( $s['refs'], $s['approved'] ); return array( 'id' => $id ) + $s; }
	public function listing( int $page = 1 ): array { if ( $page < 1 || $page > 10000 ) { throw new \InvalidArgumentException( 'invalid_input' ); } $n = $this->db->connection()->posts; $rows = $this->db->rows( "SELECT ID FROM $n WHERE post_type=%s AND post_status='private' ORDER BY ID DESC LIMIT 20 OFFSET %d", array( self::TYPE, ( $page - 1 ) * 20 ) ); return array( 'items' => array_map( fn( array $row ): array => $this->detail( (int) $row['ID'] ), $rows ), 'total' => (int) $this->db->value( "SELECT COUNT(*) FROM $n WHERE post_type=%s AND post_status='private'", array( self::TYPE ) ), 'page' => $page, 'per_page' => 20 ); }
	public function retry( int $id ): void { $this->subscriptions->withPolicy( function () use ( $id ): void { $this->transaction( function () use ( $id ): void { $s = $this->state( $id, true ); if ( ! $s || 'failed' !== $s['state'] || $s['retries'] >= 3 || $s['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) { throw new \RuntimeException( 'retry_unavailable' ); } $s['state'] = 'queued'; ++$s['revision']; ++$s['retries']; $s['last_error'] = ''; $this->write( $s, $id ); } ); } ); do_action( 'aish_account_mail_wake' ); }
	/** Only this module's expired, private issue metadata is retained for ninety days. */
	private function cleanup(): void {
		$p = $this->db->connection()->posts; $now = gmdate( 'Y-m-d H:i:s' ); $source = "CASE WHEN JSON_VALID(post_content) THEN post_content ELSE '{}' END";
		$rows = $this->db->rows( "SELECT ID FROM $p WHERE post_type=%s AND post_status='private' AND (JSON_UNQUOTE(JSON_EXTRACT($source,'$.expires_at'))<%s OR (JSON_UNQUOTE(JSON_EXTRACT($source,'$.expires_at'))<%s AND JSON_UNQUOTE(JSON_EXTRACT($source,'$.state'))='queued')) ORDER BY ID LIMIT 5", array( self::TYPE, gmdate( 'Y-m-d H:i:s', time() - 90 * 86400 ), $now ) );
		foreach ( $rows as $row ) { $id = (int) $row['ID']; $this->transaction( function () use ( $id, $now ): void { $s = $this->state( $id, true ); if ( ! $s || $s['expires_at'] >= $now ) { return; }
			if ( $s['expires_at'] < gmdate( 'Y-m-d H:i:s', time() - 90 * 86400 ) ) { $n = $this->db->connection(); $this->db->execute( "DELETE FROM {$n->postmeta} WHERE post_id=%d", array( $id ) ); $this->db->execute( "DELETE FROM {$n->posts} WHERE ID=%d AND post_type=%s AND post_status='private'", array( $id, self::TYPE ) ); }
			elseif ( 'queued' === $s['state'] ) { $s['state'] = 'expired'; ++$s['revision']; $this->write( $s, $id ); }
		} ); clean_post_cache( $id ); }
	}
	/** Existing account Cron invokes at most one issue / five recipient cursors per tick. */
	public function tick(): void {
		$this->subscriptions->withPolicy( function (): void {
			$this->cleanup(); $c = $this->subscriptions->snapshot(); if ( ! $c['enabled'] || $c['paused'] || ! $this->subscriptions->publicStatus()['mail_ready'] ) { return; }
			// Each new issue requires an exact administrator preview; Cron only continues approved batches.
			// Leave room for security mail and prevent campaigns filling the existing transport queue.
			if ( (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'notification_outbox' ) . " WHERE template_key IN ('newsletter_weekly','newsletter_new') AND status IN ('queued','retry','claimed','sending')" ) >= 20 ) { return; }
			$p = $this->db->connection()->posts; $id = (int) $this->db->value( "SELECT ID FROM $p WHERE post_type=%s AND post_status='private' AND JSON_VALID(post_content) AND JSON_UNQUOTE(JSON_EXTRACT(post_content,'$.state'))='queued' ORDER BY ID ASC LIMIT 1", array( self::TYPE ) ); if ( ! $id ) { return; }
			try {
				$s = $this->state( $id ); if ( ! $s ) { return; } if ( $s['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) { $this->transaction( function () use ( $s, $id ): void { $s['state'] = 'expired'; ++$s['revision']; $this->write( $s, $id ); } ); return; }
				$users = $this->db->rows( 'SELECT DISTINCT user_id FROM ' . $this->db->connection()->usermeta . ' WHERE meta_key=%s AND user_id>%d AND user_id<=%d ORDER BY user_id LIMIT 5', array( $this->subscriptions->storageKey(), $s['cursor'], $s['upper_user_id'] ) );
				if ( ! $users ) { $this->transaction( function () use ( $s, $id ): void { $s['state'] = 'complete'; ++$s['revision']; $this->write( $s, $id ); } ); return; }
				foreach ( $users as $row ) { $userId = (int) $row['user_id']; $this->subscriptions->withRecipient( $userId, function ( ?array $consent, ?\WP_User $user ) use ( $id, $userId ): void { $this->transaction( function () use ( $consent, $user, $id, $userId ): void { $issue = $this->state( $id, true ); if ( ! $issue || 'queued' !== $issue['state'] || $issue['cursor'] >= $userId ) { return; } $queued = $this->subscriptions->queueRecipient( $id, $issue, $userId, $consent, $user ); $issue['cursor'] = $userId; ++$issue['revision']; ++$issue[ $queued ? 'queued' : 'skipped' ]; $this->write( $issue, $id ); } ); } ); }
			} catch ( \Throwable $error ) { $this->transaction( function () use ( $id ): void { $s = $this->state( $id, true ); if ( ! $s || 'queued' !== $s['state'] ) { return; } $s['state'] = 'failed'; $s['last_error'] = 'mail_queue_unavailable'; ++$s['revision']; $this->write( $s, $id ); } ); }
		} );
	}
}
