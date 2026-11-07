<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;

use AISkillsHub\Core\Database\{Database,Migrator};
use AISkillsHub\Core\Discovery\Settings;
use AISkillsHub\Core\Frontend\Forms;
use AISkillsHub\Core\Submissions\Store;
use AISkillsHub\Core\Community\Events;

defined( 'ABSPATH' ) || exit;

/** Enterprise inquiries are one protected subtype of the existing private Inbox. */
final class Enterprise {
	public const CAPABILITY = 'aish_manage_enterprise';
	public const OPTION = 'aish_enterprise_settings';
	public const MARKER = '_aish_enterprise_version';
	public const STATES = array( 'pending', 'reviewing', 'closed', 'archived' );
	private static bool $writing = false;
	private array $touched = array();
	public function __construct( private Database $db, private Migrator $migrator ) {}
	public function register(): void {
		( new EnterpriseGuards( $this->db ) )->register();
		add_filter( 'wp_privacy_personal_data_exporters', function ( array $items ): array { $items['aish-enterprise'] = array( 'exporter_friendly_name' => 'AI Skills Hub enterprise inquiries', 'callback' => array( $this, 'exportPrivacy' ) ); return $items; } );
		add_filter( 'wp_privacy_personal_data_erasers', function ( array $items ): array { $items['aish-enterprise'] = array( 'eraser_friendly_name' => 'AI Skills Hub enterprise inquiries', 'callback' => array( $this, 'erasePrivacy' ) ); return $items; } );
	}
	public static function writing(): bool { return self::$writing; }
	public static function defaults(): array { return array( 'revision' => 0, 'enabled' => false, 'translations' => array( 'zh_CN' => array( 'title' => '', 'description' => '' ), 'en_US' => array( 'title' => '', 'description' => '' ) ) ); }
	public static function validate( array $data ): array {
		if ( array_diff( array_keys( $data ), array( 'enabled', 'translations' ) ) || ! is_bool( $data['enabled'] ?? null ) || ! is_array( $data['translations'] ?? null ) || array_diff( array_keys( $data['translations'] ), array( 'zh_CN', 'en_US' ) ) ) { throw new \InvalidArgumentException( 'invalid_settings' ); }
		$out = array( 'enabled' => $data['enabled'], 'translations' => array() ); $complete = false;
		foreach ( array( 'zh_CN', 'en_US' ) as $locale ) {
			$row = $data['translations'][ $locale ] ?? array(); if ( ! is_array( $row ) || array_diff( array_keys( $row ), array( 'title', 'description' ) ) ) { throw new \InvalidArgumentException( 'invalid_translation' ); }
			$out['translations'][ $locale ] = array( 'title' => Settings::text( $row['title'] ?? '', 120 ), 'description' => Settings::text( $row['description'] ?? '', 2000 ) );
			$complete = $complete || ( '' !== $out['translations'][ $locale ]['title'] && '' !== $out['translations'][ $locale ]['description'] );
		}
		if ( $data['enabled'] && ! $complete ) { throw new \InvalidArgumentException( 'real_service_copy_required' ); }
		return $out;
	}
	private static function decode( mixed $raw ): array {
		if ( false === $raw || null === $raw ) { return self::defaults(); }
		if ( ! is_string( $raw ) || strlen( $raw ) > 24000 ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$data = json_decode( $raw, true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || ! is_int( $data['revision'] ?? null ) || $data['revision'] < 0 ) { throw new \RuntimeException( 'storage_unavailable' ); }
		return array( 'revision' => $data['revision'] ) + self::validate( array_intersect_key( $data, array_flip( array( 'enabled', 'translations' ) ) ) );
	}
	public static function snapshot(): array { return self::decode( get_option( self::OPTION, false ) ); }
	public static function publicCopy( string $locale ): ?array {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { return null; }
		try { $state = self::snapshot(); $copy = $state['translations'][ $locale ]; return $state['enabled'] && '' !== $copy['title'] && '' !== $copy['description'] ? $copy : null; } catch ( \Throwable $error ) { return null; }
	}
	private function requireManager(): void { if ( ! current_user_can( self::CAPABILITY ) || ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'forbidden' ); } }
	private function transaction( callable $operation ): mixed {
		$this->touched = array();
		try { return ( new Store( $this->db ) )->transaction( function ( Store $store ) use ( $operation ): mixed { self::$writing = true; return $operation( $store ); } ); }
		finally { self::$writing = false; foreach ( array_keys( $this->touched ) as $id ) { clean_post_cache( $id ); wp_cache_delete( $id, 'post_meta' ); } wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' ); }
	}
	private function set( int $id, string $key, string $value ): void {
		if ( ! self::$writing || ! in_array( $key, array( self::MARKER, '_aish_message_kind', '_aish_message_locale', '_aish_sender_name', '_aish_sender_email', '_aish_message_state', '_aish_message_notes', '_aish_enterprise_revision', '_aish_enterprise_request', '_aish_enterprise_payload', '_aish_enterprise_erased' ), true ) ) { throw new \LogicException( 'Invalid private inbox write.' ); }
		$this->touched[ $id ] = true; $m = $this->db->connection()->postmeta;
		$this->db->execute( "DELETE FROM $m WHERE post_id=%d AND meta_key=%s", array( $id, $key ) );
		$this->db->execute( "INSERT INTO $m (post_id,meta_key,meta_value) VALUES (%d,%s,%s)", array( $id, $key, $value ) );
	}
	public function saveConfig( array $data, int $revision ): array {
		$this->requireManager(); Settings::integer( $revision, 0, PHP_INT_MAX - 1 ); $data = self::validate( $data );
		return $this->transaction( function () use ( $data, $revision ): array {
			$this->requireManager(); $o = $this->db->connection()->options;
			if ( 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( $o ) ) ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
			$old = self::decode( $this->db->value( "SELECT option_value FROM $o WHERE option_name=%s FOR UPDATE", array( self::OPTION ) ) );
			if ( $old['revision'] !== $revision ) { throw new \RuntimeException( 'conflict' ); }
			$new = array( 'revision' => $revision + 1 ) + $data; $json = wp_json_encode( $new );
			$this->db->execute( "INSERT INTO $o (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", array( self::OPTION, $json ) );
			( new Events( $this->db ) )->record( hash( 'sha256', 'enterprise_config:' . $new['revision'] ), 'enterprise_configuration', 1, $new['revision'], 'enterprise_configured', get_current_user_id(), $old['enabled'] ? 'enabled' : 'disabled', $data['enabled'] ? 'enabled' : 'disabled', array( 'payload_hash' => hash( 'sha256', $json ) ) ); return $new;
		} );
	}
	/** Input is validated again; no caller can turn an inquiry into a user-owned submission. */
	public function receive( array $input, string $locale, string $key ): int {
		if ( ! $this->migrator->isCurrent() || ! self::publicCopy( $locale ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) { throw new \RuntimeException( 'unavailable' ); }
		$data = Forms::validateContact( $input ); if ( '' !== $data['github_url'] ) { throw new \InvalidArgumentException( 'invalid_request' ); }
		$hash = hash_hmac( 'sha256', wp_json_encode( array( $locale, $data ) ), wp_salt( 'auth' ) );
		return $this->transaction( function ( Store $store ) use ( $data, $locale, $key, $hash ): int {
			$n = $this->db->connection();
			$config = self::decode( $this->db->value( "SELECT option_value FROM {$n->options} WHERE option_name=%s FOR UPDATE", array( self::OPTION ) ) );
			$copy = $config['translations'][ $locale ]; if ( ! $config['enabled'] || '' === $copy['title'] || '' === $copy['description'] ) { throw new \RuntimeException( 'unavailable' ); }
			$existing = $this->db->row( "SELECT p.ID,h.meta_value AS payload FROM {$n->posts} p INNER JOIN {$n->postmeta} k ON k.post_id=p.ID AND k.meta_key='_aish_enterprise_request' INNER JOIN {$n->postmeta} h ON h.post_id=p.ID AND h.meta_key='_aish_enterprise_payload' WHERE p.post_type='aish_message' AND k.meta_value=%s LIMIT 1 FOR UPDATE", array( $key ) );
			if ( $existing ) { if ( ! hash_equals( $hash, $existing['payload'] ) ) { throw new \RuntimeException( 'conflict' ); } return (int) $existing['ID']; }
			$id = $store->post( array( 'post_type' => Forms::POST_TYPE, 'post_status' => 'private', 'post_author' => 0, 'post_title' => '[Enterprise] ' . $data['name'], 'post_content' => $data['message'], 'comment_status' => 'closed', 'ping_status' => 'closed' ) );
			foreach ( array( self::MARKER => '1', '_aish_message_kind' => 'enterprise', '_aish_message_locale' => $locale, '_aish_sender_name' => $data['name'], '_aish_sender_email' => $data['email'], '_aish_message_state' => 'pending', '_aish_message_notes' => '', '_aish_enterprise_revision' => '1', '_aish_enterprise_request' => $key, '_aish_enterprise_payload' => $hash ) as $field => $value ) { $this->set( $id, $field, $value ); }
			( new Events( $this->db ) )->record( hash( 'sha256', 'enterprise_received:' . $key ), 'enterprise_inquiry', $id, 1, 'enterprise_received', null, null, 'pending', array( 'payload_hash' => $hash ) ); return $id;
		} );
	}
	private function where(): string { $m = $this->db->connection()->postmeta; return "p.post_type='aish_message' AND p.post_status='private' AND EXISTS (SELECT 1 FROM $m em WHERE em.post_id=p.ID AND em.meta_key='_aish_enterprise_version' AND em.meta_value='1') AND EXISTS (SELECT 1 FROM $m ek WHERE ek.post_id=p.ID AND ek.meta_key='_aish_message_kind' AND ek.meta_value='enterprise')"; }
	public function listing( int $page = 1, string $state = '' ): array {
		$this->requireManager(); Settings::integer( $page, 1, 100000 ); if ( '' !== $state && ! in_array( $state, self::STATES, true ) ) { throw new \InvalidArgumentException( 'invalid_state' ); }
		$n = $this->db->connection(); $where = $this->where(); $v = array();
		if ( '' !== $state ) { $where .= " AND EXISTS (SELECT 1 FROM {$n->postmeta} es WHERE es.post_id=p.ID AND es.meta_key='_aish_message_state' AND es.meta_value=%s)"; $v[] = $state; }
		return $this->db->rows( "SELECT p.ID,p.post_title,p.post_date_gmt FROM {$n->posts} p WHERE $where ORDER BY p.ID DESC LIMIT 21 OFFSET %d", array_merge( $v, array( ( $page - 1 ) * 20 ) ) );
	}
	private function raw( int $id, bool $lock = false ): array {
		Settings::integer( $id, 1, PHP_INT_MAX ); $n = $this->db->connection();
		$post = $this->db->row( "SELECT p.ID,p.post_content,p.post_date_gmt FROM {$n->posts} p WHERE " . $this->where() . ' AND p.ID=%d' . ( $lock ? ' FOR UPDATE' : '' ), array( $id ) ); if ( ! $post ) { throw new \RuntimeException( 'not_found' ); }
		$meta = array(); foreach ( $this->db->rows( "SELECT meta_key,meta_value FROM {$n->postmeta} WHERE post_id=%d", array( $id ) ) as $row ) { if ( isset( $meta[ $row['meta_key'] ] ) ) { throw new \RuntimeException( 'storage_unavailable' ); } $meta[ $row['meta_key'] ] = $row['meta_value']; }
		return $post + array( 'meta' => $meta );
	}
	public function detail( int $id ): array { $this->requireManager(); return $this->raw( $id ); }
	public function moderate( int $id, int $revision, string $state, string $notes ): void {
		$this->requireManager(); Settings::integer( $revision, 1, PHP_INT_MAX - 1 ); if ( ! in_array( $state, self::STATES, true ) ) { throw new \InvalidArgumentException( 'invalid_state' ); } $notes = Settings::text( $notes, 2000 );
		$this->transaction( function () use ( $id, $revision, $state, $notes ): void {
			$this->requireManager(); $row = $this->raw( $id, true ); $m = $row['meta']; if ( (int) ( $m['_aish_enterprise_revision'] ?? 0 ) !== $revision ) { throw new \RuntimeException( 'conflict' ); }
			if ( isset( $m['_aish_enterprise_erased'] ) ) { throw new \RuntimeException( 'erased' ); }
			if ( $state === $m['_aish_message_state'] && $notes === $m['_aish_message_notes'] ) { return; }
			$this->set( $id, '_aish_message_state', $state ); $this->set( $id, '_aish_message_notes', $notes ); $this->set( $id, '_aish_enterprise_revision', (string) ( $revision + 1 ) );
			( new Events( $this->db ) )->record( hash( 'sha256', 'enterprise_handled:' . $id . ':' . ( $revision + 1 ) ), 'enterprise_inquiry', $id, $revision + 1, 'enterprise_handled', get_current_user_id(), $m['_aish_message_state'], $state, array( 'payload_hash' => hash_hmac( 'sha256', $notes, wp_salt( 'auth' ) ) ) );
		} );
	}
	public function history( int $id = 0 ): array {
		$this->requireManager(); return $this->db->rows( 'SELECT subject_revision,action,actor_user_id,from_state,to_state,created_at FROM ' . $this->db->table( 'workflow_events' ) . ' WHERE subject_type=%s AND subject_id=%d ORDER BY id DESC LIMIT 20', array( $id ? 'enterprise_inquiry' : 'enterprise_configuration', $id ?: 1 ) );
	}
	public function countReceived( string $startUtc, string $endUtc ): int {
		if ( ! current_user_can( 'aish_view_commercial_metrics' ) || ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'forbidden' ); }
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $startUtc, new \DateTimeZone( 'UTC' ) ); $end = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $endUtc, new \DateTimeZone( 'UTC' ) );
		if ( ! $start || ! $end || $start->format( 'Y-m-d H:i:s' ) !== $startUtc || $end->format( 'Y-m-d H:i:s' ) !== $endUtc || $start >= $end || $end->getTimestamp() - $start->getTimestamp() > 366 * 86400 ) { throw new \InvalidArgumentException( 'invalid_window' ); }
		return (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->connection()->posts . ' p WHERE ' . $this->where() . ' AND p.post_date_gmt>=%s AND p.post_date_gmt<%s', array( $startUtc, $endUtc ) );
	}

	/** Native confirmed email privacy requests extend the existing WordPress processing flow. */
	private function privacyAllowed( string $email, bool $erase ): void {
		if ( ! current_user_can( $erase ? 'erase_others_personal_data' : 'export_others_personal_data' ) || ! is_email( $email ) || ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'privacy_forbidden' ); }
		$n = $this->db->connection();
		$confirmed = $this->db->value( "SELECT ID FROM {$n->posts} WHERE post_type='user_request' AND post_name=%s AND post_title=%s AND post_status IN ('request-confirmed','request-completed') LIMIT 1", array( $erase ? 'remove_personal_data' : 'export_personal_data', $email ) );
		if ( ! $confirmed ) { throw new \RuntimeException( 'privacy_confirmation_required' ); }
	}
	private function privacyRows( string $email, int $offset = 0 ): array {
		$n = $this->db->connection(); return $this->db->rows( "SELECT p.ID,p.post_content,p.post_date_gmt FROM {$n->posts} p WHERE " . $this->where() . " AND EXISTS (SELECT 1 FROM {$n->postmeta} pe WHERE pe.post_id=p.ID AND pe.meta_key='_aish_sender_email' AND LOWER(pe.meta_value)=LOWER(%s)) ORDER BY p.ID LIMIT 50 OFFSET %d", array( $email, $offset ) );
	}
	public function exportPrivacy( string $email, int $page = 1 ): array|\WP_Error {
		try {
			$this->privacyAllowed( $email, false ); Settings::integer( $page, 1, 100000 ); $rows = $this->privacyRows( $email, ( $page - 1 ) * 50 ); $data = array();
			foreach ( $rows as $row ) { $r = $this->raw( (int) $row['ID'] ); $m = $r['meta']; $values = array( 'Received UTC' => $row['post_date_gmt'], 'Name' => $m['_aish_sender_name'] ?? '', 'Email' => $m['_aish_sender_email'] ?? '', 'Language' => $m['_aish_message_locale'] ?? '', 'Message' => $row['post_content'], 'State' => $m['_aish_message_state'] ?? '' ); $fields = array(); foreach ( $values as $name => $value ) { $fields[] = array( 'name' => $name, 'value' => $value ); } $data[] = array( 'group_id' => 'aish-enterprise', 'group_label' => 'AI Skills Hub enterprise inquiries', 'item_id' => 'aish-enterprise-' . $row['ID'], 'data' => $fields ); }
			return array( 'data' => $data, 'done' => count( $rows ) < 50 );
		} catch ( \Throwable $error ) { return new \WP_Error( 'aish_privacy_unavailable', 'The confirmed privacy request could not be processed.' ); }
	}
	public function erasePrivacy( string $email, int $page = 1 ): array|\WP_Error {
		try {
			$this->privacyAllowed( $email, true ); Settings::integer( $page, 1, 100000 );
			$count = $this->transaction( function () use ( $email ): int {
				$this->privacyAllowed( $email, true ); $rows = $this->privacyRows( $email ); $n = $this->db->connection();
				foreach ( $rows as $row ) { $id = (int) $row['ID']; $this->touched[ $id ] = true; $this->db->execute( "UPDATE {$n->posts} SET post_title='',post_content='',post_excerpt='',post_name=%s,post_author=0 WHERE ID=%d", array( 'aish-private-erased-' . $id, $id ) ); foreach ( array( '_aish_sender_name', '_aish_sender_email', '_aish_message_notes', '_aish_enterprise_payload' ) as $field ) { $this->set( $id, $field, '' ); } $this->set( $id, '_aish_enterprise_erased', '1' ); $this->set( $id, '_aish_message_state', 'archived' ); }
				return count( $rows );
			} );
			return array( 'items_removed' => $count > 0, 'items_retained' => false, 'messages' => array(), 'done' => $count < 50 );
		} catch ( \Throwable $error ) { return new \WP_Error( 'aish_privacy_unavailable', 'The confirmed privacy request could not be processed.' ); }
	}
}
