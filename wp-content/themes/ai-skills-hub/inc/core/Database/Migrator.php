<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Database;

defined( 'ABSPATH' ) || exit;

/** Additive migrations only. MySQL DDL is not transactionally reversible. */
final class Migrator {
	public function __construct( private Database $db, private string $option = 'aish_schema_version' ) {}

	public function version(): string {
		return (string) get_option( $this->option, '0' );
	}

	public function isCurrent(): bool {
		return Schema::VERSION === $this->version();
	}

	public function lockName(): string {
		return 'aish:' . substr( hash( 'sha256', DB_NAME . ':' . $this->db->prefix() ), 0, 50 );
	}

	public function migrate( string $target = Schema::VERSION ): true|\WP_Error {
		$versions = array( '0', '1.0.0', '1.1.0', '1.2.0' );
		if ( ! in_array( $target, array_slice( $versions, 1 ), true ) ) {
			return new \WP_Error( 'aish_schema_target', 'Unsupported migration target.' );
		}
		$locked = false;
		try {
			if ( 1 !== (int) $this->db->value( 'SELECT GET_LOCK(%s, 0)', array( $this->lockName() ) ) ) {
				return new \WP_Error( 'aish_schema_busy', 'Another Core migration is running. Retry later.' );
			}
			$locked = true;
			// Another worker may have completed a migration while this request cached options.
			wp_cache_delete( $this->option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$current = $this->version();
			if ( ! in_array( $current, $versions, true ) || version_compare( $current, $target, '>' ) ) {
				return new \WP_Error( 'aish_schema_version', 'Unknown or newer schema; automatic downgrade refused.' );
			}
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( array_slice( $versions, 1 ) as $version ) {
				if ( version_compare( $version, $current, '<=' ) || version_compare( $version, $target, '>' ) ) {
					continue;
				}
				// dbDelta uses WordPress's global connection. Do not allow a different connection silently.
				if ( $this->db->connection() !== $GLOBALS['wpdb'] ) {
					throw new \RuntimeException( 'Migration must use the active WordPress connection.' );
				}
				$previous = $this->db->connection()->suppress_errors( true );
				try {
					foreach ( Schema::definitions( $this->db, $version ) as $definition ) {
						dbDelta( $definition );
						if ( '' !== $this->db->connection()->last_error ) {
							throw new \RuntimeException( 'Schema DDL failed.' );
						}
					}
				} finally {
					$this->db->connection()->suppress_errors( $previous );
				}
				if ( '1.2.0' === $version ) {
					// dbDelta does not reliably compare nullability when the SQL type is unchanged.
					$table = $this->db->table( 'skills' );
					$this->db->execute( "ALTER TABLE $table MODIFY repository_id bigint(20) unsigned NULL DEFAULT NULL, MODIFY source_path varbinary(768) NULL DEFAULT NULL" );
					$translations = $this->db->table( 'skill_translations' );
					$this->db->execute( "ALTER TABLE $translations MODIFY slug varchar(191) NULL DEFAULT NULL" );
					$this->db->execute( "UPDATE $table SET status='pending', review_status='pending', revision=revision+1 WHERE status='published' AND (review_status<>'approved' OR reviewer_id IS NULL OR reviewer_id=0 OR reviewed_at IS NULL OR reviewed_revision IS NULL OR reviewed_revision<>revision)" );
					$this->db->execute( "UPDATE $table SET review_status='pending' WHERE status='pending' AND review_status='unreviewed'" );
				}
				Schema::verify( $this->db, $version );
				update_option( $this->option, $version, false );
				if ( $version !== $this->version() ) {
					throw new \RuntimeException( 'Schema version could not be saved.' );
				}
				$current = $version;
			}
			return true;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'aish_schema_failed', 'Core migration failed; version retained. Check database availability and retry.' );
		} finally {
			if ( $locked ) {
				try {
					$this->db->value( 'SELECT RELEASE_LOCK(%s)', array( $this->lockName() ) );
				} catch ( \Throwable $error ) {
					// A disconnected MySQL session releases its advisory locks automatically.
				}
			}
		}
	}
}
