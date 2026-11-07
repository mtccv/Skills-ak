<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Learning;

use AISkillsHub\Core\Admin\Management;
use AISkillsHub\Core\Community\Events;
use AISkillsHub\Core\Database\{Database,Migrator};
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Public sharing and explicitly reviewed, revision-bound installation quotations. Never executes commands. */
final class SkillExperience {
	public const CAPABILITY = 'aish_review_skills';
	public const MAX_COMMANDS = 6;
	private const MAX_CONFIGURATION_BYTES = 65536;
	public function __construct( private Database $db, private Migrator $migrator ) {}
	public function register(): void {
		add_action( 'aish_skill_share', array( $this, 'renderShare' ), 10, 2 );
		add_action( 'aish_skill_installation', array( $this, 'renderInstallation' ), 10, 2 );
		// Native options editors must not bypass the review capability or CAS/audit.
		add_filter( 'pre_update_option', static fn( mixed $value, string $option, mixed $old ): mixed => self::protectedOption( $option ) ? $old : $value, PHP_INT_MAX, 3 );
		foreach ( array( 'add_option', 'delete_option' ) as $hook ) { add_action( $hook, static function ( string $option ): void { if ( self::protectedOption( $option ) ) { throw new \RuntimeException( 'installation_native_option' ); } }, PHP_INT_MAX, 1 ); }
	}
	private static function protectedOption( string $name ): bool { return str_starts_with( strtolower( $name ), 'aish_installation_' ); }
	private function authorize(): int {
		if ( ! current_user_can( self::CAPABILITY ) || get_current_user_id() < 1 || ! $this->migrator->isCurrent() ) { throw new \DomainException( 'installation_forbidden' ); }
		return get_current_user_id();
	}
	private static function locale( string $locale ): string {
		if ( ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'installation_locale' ); }
		return $locale;
	}
	private static function option( int $id, string $locale ): string { return 'aish_installation_' . Input::id( $id ) . '_' . self::locale( $locale ); }
	private function optionsTable(): string {
		$name = $this->db->connection()->options;
		if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $name ) ) { throw new \RuntimeException( 'installation_storage' ); }
		return '`' . $name . '`';
	}
	public static function defaults(): array {
		return array( 'revision' => 0, 'state' => 'unverified', 'skill_revision' => 0, 'binding' => '', 'version_id' => 0, 'commands' => array(), 'rights_note' => '', 'actor_id' => 0, 'verified_at' => '' );
	}
	private static function decode( mixed $value ): array {
		if ( null === $value || false === $value ) { return self::defaults(); }
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_CONFIGURATION_BYTES ) { throw new \RuntimeException( 'installation_configuration' ); }
		$row = json_decode( $value, true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $row ) || array_diff( array_keys( $row ), array_keys( self::defaults() ) ) || count( $row ) !== count( self::defaults() ) || ! in_array( $row['state'], array( 'unverified', 'verified', 'disabled' ), true ) ) { throw new \RuntimeException( 'installation_configuration' ); }
		foreach ( array( 'revision', 'skill_revision', 'version_id', 'actor_id' ) as $key ) { if ( ! is_int( $row[ $key ] ) || $row[ $key ] < 0 ) { throw new \RuntimeException( 'installation_configuration' ); } }
		if ( ! is_string( $row['binding'] ) || ( '' !== $row['binding'] && ! preg_match( '/^[a-f0-9]{64}$/D', $row['binding'] ) ) || ! is_string( $row['verified_at'] ) || ! is_array( $row['commands'] ) ) { throw new \RuntimeException( 'installation_configuration' ); }
		$row['commands'] = self::commands( $row['commands'] ); $row['rights_note'] = Input::plain( $row['rights_note'], 800, true );
		return $row;
	}
	private function configuration( int $id, string $locale ): array {
		return self::decode( $this->db->value( 'SELECT option_value FROM ' . $this->optionsTable() . ' WHERE option_name=%s', array( self::option( $id, $locale ) ) ) );
	}
	/** Clipboard text has no hidden controls/bidi, and is shown in full before any user click. */
	public static function command( mixed $raw ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", Input::utf8( $raw, 2000 ) );
		$value = trim( $value, "\n" );
		if ( '' === trim( $value ) || substr_count( $value, "\n" ) > 15 || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f\p{Cf}]/u', $value ) ) { throw new \InvalidArgumentException( 'installation_command' ); }
		return $value;
	}
	public static function commands( mixed $rows ): array {
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > self::MAX_COMMANDS ) { throw new \InvalidArgumentException( 'installation_commands' ); }
		$result = array(); $seen = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_keys( $row ) !== array( 'command', 'platform' ) ) { throw new \InvalidArgumentException( 'installation_command_fields' ); }
			$command = self::command( $row['command'] ); $platform = Input::utf8( $row['platform'], 191 );
			if ( '' !== $platform && ( ! str_starts_with( $platform, 'platform-' ) || Input::slug( $platform ) !== $platform ) ) { throw new \InvalidArgumentException( 'installation_platform' ); }
			$key = hash( 'sha256', $command . "\0" . $platform ); if ( isset( $seen[ $key ] ) ) { throw new \InvalidArgumentException( 'installation_duplicate' ); } $seen[ $key ] = true;
			$result[] = array( 'command' => $command, 'platform' => $platform );
		}
		return $result;
	}
	/** Extract only already-public code blocks; no Markdown command guessing or DOM/script execution. */
	public static function codeBlocks( string $html ): array {
		Input::utf8( $html, 524288 );
		if ( ! class_exists( '\DOMDocument' ) ) { return array(); }
		$before = libxml_use_internal_errors( true );
		try {
			$dom = new \DOMDocument();
			if ( ! $dom->loadHTML( '<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) { return array(); }
			$result = array();
			foreach ( $dom->getElementsByTagName( 'pre' ) as $pre ) {
				// Reject hidden/active markup instead of copying text that was not plainly visible.
				$valid = true;
				foreach ( array_merge( array( $pre ), iterator_to_array( $pre->getElementsByTagName( '*' ) ) ) as $node ) {
					if ( ! in_array( strtolower( $node->nodeName ), array( 'pre', 'code' ), true ) ) { $valid = false; break; }
					foreach ( $node->attributes as $attribute ) { if ( ! in_array( strtolower( $attribute->name ), array( 'class', 'id' ), true ) ) { $valid = false; break 2; } }
					foreach ( preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY ) as $class ) { if ( ! preg_match( '/^(?:language-[a-z0-9_-]+|wp-block-code|wp-block-preformatted)$/iD', $class ) ) { $valid = false; break 2; } }
				}
				for ( $parent = $pre->parentNode; $parent instanceof \DOMElement && ! in_array( strtolower( $parent->nodeName ), array( 'body', 'html' ), true ); $parent = $parent->parentNode ) { if ( $parent->hasAttribute( 'hidden' ) || $parent->hasAttribute( 'style' ) || $parent->hasAttribute( 'aria-hidden' ) || $parent->hasAttribute( 'class' ) ) { $valid = false; break; } }
				if ( ! $valid ) { continue; }
				try { $result[] = self::command( (string) $pre->textContent ); } catch ( \InvalidArgumentException $error ) {}
				if ( count( $result ) >= 24 ) { break; }
			}
			return array_values( array_unique( $result ) );
		} finally { libxml_clear_errors(); libxml_use_internal_errors( $before ); }
	}
	/** Exact complete-line quotation, not a substring inside another command. */
	public static function sourceContains( string $source, string $command ): bool {
		Input::utf8( $source, 524288 ); $source = str_replace( array( "\r\n", "\r" ), "\n", $source );
		return str_contains( "\n" . $source . "\n", "\n" . self::command( $command ) . "\n" );
	}
	private function current( int $id, string $locale, bool $lock = false ): array {
		Input::id( $id ); self::locale( $locale );
		if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'installation_storage' ); }
		$s = $this->db->table( 'skills' ); $t = $this->db->table( 'skill_translations' ); $r = $this->db->table( 'repositories' );
		$row = $this->db->row( "SELECT s.id,s.repository_id,s.source_path,s.revision,s.reviewed_revision,s.reviewed_at,s.reviewer_id,s.status,s.review_status,tr.locale,tr.title,tr.slug,tr.summary,tr.content,r.owner,r.name FROM $s s INNER JOIN $t tr ON tr.skill_id=s.id LEFT JOIN $r r ON r.id=s.repository_id WHERE s.id=%d AND tr.locale=%s" . ( $lock ? ' FOR UPDATE' : '' ), array( $id, $locale ) );
		if ( ! $row || 'published' !== $row['status'] || 'approved' !== $row['review_status'] || (int) $row['revision'] < 1 || (int) $row['revision'] !== (int) $row['reviewed_revision'] || (int) $row['reviewer_id'] < 1 || ! is_string( $row['reviewed_at'] ) || $row['reviewed_at'] < '1970-01-01 00:00:00' || ! Management::translationComplete( $row ) ) { throw new \DomainException( 'installation_not_public' ); }
		return $row;
	}
	private function terms( int $id ): array {
		$t = $this->db->table( 'tags' ); $l = $this->db->table( 'skill_tags' );
		$rows = $this->db->rows( "SELECT t.id,t.slug,t.name FROM $t t INNER JOIN $l l ON l.tag_id=t.id WHERE l.skill_id=%d AND t.is_archived=0 AND (t.slug LIKE 'license-%%' OR t.slug LIKE 'platform-%%') ORDER BY t.id LIMIT 100", array( $id ) );
		$result = array( 'platforms' => array(), 'licenses' => array() );
		foreach ( $rows as $row ) { $key = str_starts_with( $row['slug'], 'platform-' ) ? 'platforms' : 'licenses'; $result[ $key ][] = array( 'id' => (int) $row['id'], 'slug' => $row['slug'], 'name' => $row['name'] ); }
		return $result;
	}
	private function source( array $skill, int $versionId, bool $includeContent ): ?array {
		if ( null === $skill['repository_id'] || 0 === (int) $skill['repository_id'] ) { if ( $versionId ) { throw new \InvalidArgumentException( 'installation_manual_version' ); } return null; }
		if ( $versionId < 1 ) { throw new \InvalidArgumentException( 'installation_version_required' ); }
		$v = $this->db->table( 'skill_versions' ); $i = $this->db->table( 'imports' );
		$fields = 'v.id,v.repository_id,v.source_path,v.commit_sha,v.file_sha,v.content_hash,i.commit_sha AS import_commit,i.file_sha AS import_file,i.source_status,i.change_status,i.findings_json,i.metadata_json';
		if ( $includeContent ) { $fields .= ',v.source_content'; }
		$row = $this->db->row( "SELECT $fields FROM $v v INNER JOIN $i i ON i.skill_id=v.skill_id AND i.repository_id=v.repository_id AND i.source_path=v.source_path WHERE v.id=%d AND v.skill_id=%d", array( $versionId, (int) $skill['id'] ) );
		if ( ! $row || (int) $row['repository_id'] !== (int) $skill['repository_id'] || $row['source_path'] !== $skill['source_path'] || 'present' !== $row['source_status'] || ! hash_equals( (string) $row['commit_sha'], (string) $row['import_commit'] ) || ! hash_equals( (string) $row['file_sha'], (string) $row['import_file'] ) ) { throw new \DomainException( 'installation_source_changed' ); }
		foreach ( array( 'commit_sha', 'file_sha' ) as $key ) { if ( ! preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', (string) $row[ $key ] ) ) { throw new \DomainException( 'installation_source_unbound' ); } }
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', (string) $row['content_hash'] ) || ( $includeContent && ! hash_equals( $row['content_hash'], hash( 'sha256', Input::utf8( $row['source_content'], 524288 ) ) ) ) ) { throw new \DomainException( 'installation_source_unbound' ); }
		return $row;
	}
	private static function binding( array $skill, array $terms, ?array $source ): string {
		$proof = array_intersect_key( $skill, array_flip( array( 'id', 'repository_id', 'source_path', 'revision', 'reviewed_revision', 'reviewed_at', 'reviewer_id', 'locale', 'title', 'slug', 'summary', 'content', 'owner', 'name' ) ) );
		if ( $source ) { unset( $source['source_content'] ); }
		return hash( 'sha256', wp_json_encode( array( 'skill' => $proof, 'terms' => $terms, 'source' => $source ) ) );
	}
	private static function validateEvidence( array $commands, array $skill, array $terms, ?array $source ): void {
		if ( ! $commands || ! $terms['licenses'] ) { throw new \InvalidArgumentException( 'installation_license_required' ); }
		foreach ( $terms['licenses'] as $license ) { if ( preg_match( '/(?:unknown|unverified|pending|未知|待核实)/iu', $license['slug'] . ' ' . $license['name'] ) ) { throw new \InvalidArgumentException( 'installation_license_unknown' ); } }
		$blocks = self::codeBlocks( $skill['content'] ); $platforms = array_column( $terms['platforms'], 'slug' );
		foreach ( $commands as $row ) {
			if ( ! in_array( $row['command'], $blocks, true ) || ( '' !== $row['platform'] && ! in_array( $row['platform'], $platforms, true ) ) ) { throw new \InvalidArgumentException( 'installation_public_evidence' ); }
			if ( $source && ! self::sourceContains( $source['source_content'], $row['command'] ) ) { throw new \InvalidArgumentException( 'installation_source_evidence' ); }
		}
	}
	public function editor( int $id, string $locale ): array {
		$this->authorize(); $skill = $this->current( $id, $locale ); $terms = $this->terms( $id ); $r = $this->configuration( $id, $locale );
		$versions = $this->db->rows( 'SELECT id,commit_sha,file_sha,repository_id,source_path FROM ' . $this->db->table( 'skill_versions' ) . ' WHERE skill_id=%d ORDER BY id DESC LIMIT 20', array( $id ) );
		return array( 'skill' => $skill, 'configuration' => $r, 'terms' => $terms, 'versions' => $versions, 'blocks' => self::codeBlocks( $skill['content'] ) );
	}
	public function save( int $id, string $locale, array $input, int $expected, int $skillRevision, bool $confirmed ): array {
		$actor = $this->authorize(); Input::id( $id ); self::locale( $locale );
		if ( $expected < 0 || $expected > PHP_INT_MAX - 2 || $skillRevision < 1 || ! $confirmed || array_diff( array_keys( $input ), array( 'state', 'version_id', 'commands', 'rights_note' ) ) || ! in_array( $input['state'] ?? '', array( 'verified', 'disabled' ), true ) || ! is_int( $input['version_id'] ?? null ) || $input['version_id'] < 0 ) { throw new \InvalidArgumentException( 'installation_input' ); }
		$data = array( 'state' => $input['state'], 'version_id' => $input['version_id'], 'commands' => self::commands( $input['commands'] ?? array() ), 'rights_note' => Input::plain( $input['rights_note'] ?? '', 800 ) );
		if ( 'verified' === $data['state'] && preg_match_all( '/./us', $data['rights_note'] ) < 15 ) { throw new \InvalidArgumentException( 'installation_rights_evidence' ); }
		$table = $this->optionsTable(); $key = self::option( $id, $locale );
		if ( 'innodb' !== strtolower( (string) $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( trim( $table, '`' ) ) ) ) ) { throw new \RuntimeException( 'installation_storage' ); }
		try {
			return $this->db->transaction( function () use ( $id, $locale, $data, $expected, $skillRevision, $actor, $table, $key ): array {
				$this->authorize(); $skill = $this->current( $id, $locale, true );
				if ( (int) $skill['revision'] !== $skillRevision ) { throw new \RuntimeException( 'installation_skill_changed' ); }
				$this->db->execute( "INSERT INTO $table (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)", array( $key, wp_json_encode( self::defaults() ) ) );
				$old = self::decode( $this->db->value( "SELECT option_value FROM $table WHERE option_name=%s FOR UPDATE", array( $key ) ) );
				$terms = $this->terms( $id ); $source = 'verified' === $data['state'] ? $this->source( $skill, $data['version_id'], true ) : null;
				if ( 'verified' === $data['state'] ) { self::validateEvidence( $data['commands'], $skill, $terms, $source ); }
				$binding = self::binding( $skill, $terms, $source );
				$next = $data + array( 'skill_revision' => $skillRevision, 'binding' => $binding, 'revision' => $expected + 1, 'actor_id' => $actor, 'verified_at' => gmdate( 'Y-m-d H:i:s' ) );
				$intent = static fn( array $r ): string => hash( 'sha256', wp_json_encode( array_intersect_key( $r, array_flip( array( 'state', 'version_id', 'commands', 'rights_note', 'skill_revision', 'binding', 'actor_id' ) ) ) ) );
				if ( $old['revision'] === $expected + 1 && hash_equals( $intent( $old ), $intent( $next ) ) ) { return $old; }
				if ( $old['revision'] !== $expected ) { throw new \RuntimeException( 'installation_revision_conflict' ); }
				$json = wp_json_encode( $next );
				if ( ! is_string( $json ) || strlen( $json ) > self::MAX_CONFIGURATION_BYTES ) { throw new \InvalidArgumentException( 'installation_configuration_size' ); }
				$this->db->execute( "UPDATE $table SET option_value=%s,autoload='off' WHERE option_name=%s", array( $json, $key ) );
				( new Events( $this->db ) )->record( hash( 'sha256', 'installation:' . $id . ':' . $locale . ':' . $next['revision'] ), 'skill', $id, $skillRevision, 'verified' === $next['state'] ? 'installation_verify' : 'installation_disable', $actor, $old['state'], $next['state'], array( 'payload_hash' => $intent( $next ), 'kind' => 'installation' ) );
				return $next;
			} );
		} finally { foreach ( array( $key, 'alloptions', 'notoptions' ) as $cache ) { wp_cache_delete( $cache, 'options' ); } }
	}
	/** The only public projection: no note, source body, actor identity or internal finding is returned. */
	public function installation( int $id, string $locale ): array {
		$empty = array( 'state' => 'unverified', 'commands' => array() );
		try {
			$skill = $this->current( $id, $locale ); $record = $this->configuration( $id, $locale );
			if ( 'verified' !== $record['state'] || ! $record['commands'] ) { return $empty; }
			$terms = $this->terms( $id ); $source = $this->source( $skill, $record['version_id'], false );
			if ( $record['skill_revision'] !== (int) $skill['revision'] || ! hash_equals( $record['binding'], self::binding( $skill, $terms, $source ) ) || ! $record['actor_id'] || ! $record['verified_at'] ) { return $empty; }
			$blocks = self::codeBlocks( $skill['content'] );
			foreach ( $record['commands'] as $command ) { if ( ! in_array( $command['command'], $blocks, true ) ) { return $empty; } }
			$sourceUrl = $source ? self::repositoryUrl( $skill ) . '/blob/' . $source['commit_sha'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', Input::sourcePath( $skill['source_path'] ) ) ) ) : Router::url( 'skill', array( 'id' => $id, 'slug' => $skill['slug'] ), $locale ) . '#skill-overview';
			if ( $source && ! self::repositoryUrl( $skill ) ) { return $empty; }
			return array( 'state' => 'verified', 'commands' => $record['commands'], 'kind' => $source ? 'repository' : 'editorial', 'source_url' => $sourceUrl, 'commit_sha' => $source['commit_sha'] ?? '', 'file_sha' => $source['file_sha'] ?? '', 'reviewed_at' => $record['verified_at'], 'platforms' => $terms['platforms'], 'licenses' => $terms['licenses'] );
		} catch ( \Throwable $error ) { return $empty; }
	}
	private static function repositoryUrl( array $skill ): string {
		if ( ! $skill['repository_id'] ) { return ''; }
		try { [ $owner, $name ] = Input::repository( $skill['owner'], $skill['name'] ); return 'https://github.com/' . rawurlencode( $owner ) . '/' . rawurlencode( $name ); } catch ( \Throwable $error ) { return ''; }
	}
	public function share( int $id, string $locale ): array {
		try {
			$skill = $this->current( $id, $locale ); $url = Router::url( 'skill', array( 'id' => $id, 'slug' => $skill['slug'] ), $locale );
			$parts = wp_parse_url( $url ); if ( ! is_array( $parts ) || ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) { return array(); }
			$repo = self::repositoryUrl( $skill ); $source = $repo && ! empty( $skill['source_path'] ) ? $repo . '/blob/HEAD/' . implode( '/', array_map( 'rawurlencode', explode( '/', Input::sourcePath( $skill['source_path'] ) ) ) ) : '';
			return array( 'url' => $url, 'source_url' => $source, 'title' => $skill['title'], 'social' => array( 'X' => 'https://twitter.com/intent/tweet?' . http_build_query( array( 'url' => $url, 'text' => $skill['title'] ), '', '&', PHP_QUERY_RFC3986 ), 'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?' . http_build_query( array( 'url' => $url ), '', '&', PHP_QUERY_RFC3986 ), 'Reddit' => 'https://www.reddit.com/submit?' . http_build_query( array( 'url' => $url, 'title' => $skill['title'] ), '', '&', PHP_QUERY_RFC3986 ) ) );
		} catch ( \Throwable $error ) { return array(); }
	}
	private static function preview(): bool { return is_admin() || isset( $_GET['aish_preview'] ) || isset( $_GET['aish_home_preview'] ) || isset( $_GET['preview'] ); }
	public function renderShare( array $skill, string $locale ): void {
		if ( self::preview() ) { return; }
		$data = $this->share( (int) ( $skill['id'] ?? 0 ), $locale );
		if ( $data ) { get_template_part( 'templates/learning/skill-experience-share', null, array( 'share' => $data, 'locale' => $locale ) ); }
	}
	public function renderInstallation( array $skill, string $locale ): void {
		if ( self::preview() ) { return; }
		get_template_part( 'templates/learning/skill-experience-installation', null, array( 'installation' => $this->installation( (int) ( $skill['id'] ?? 0 ), $locale ), 'locale' => $locale ) );
	}
}
