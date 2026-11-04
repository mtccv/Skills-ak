<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const VERSION = '1.1.0';

	public const COLUMNS = array(
		'repositories' => array( 'id' => '%d', 'owner' => '%s', 'name' => '%s', 'created_at' => '%s' ),
		'skills' => array( 'id' => '%d', 'repository_id' => '%d', 'source_path' => '%s', 'status' => '%s', 'created_at' => '%s', 'updated_at' => '%s' ),
		'skill_translations' => array( 'id' => '%d', 'skill_id' => '%d', 'locale' => '%s', 'title' => '%s', 'slug' => '%s', 'summary' => '%s', 'content' => '%s', 'created_at' => '%s', 'updated_at' => '%s' ),
		'skill_versions' => array( 'id' => '%d', 'skill_id' => '%d', 'commit_sha' => '%s', 'content_hash' => '%s', 'source_content' => '%s', 'created_at' => '%s', 'version_label' => '%s' ),
		'categories' => array( 'id' => '%d', 'name' => '%s', 'slug' => '%s', 'parent_id' => '%d', 'created_at' => '%s' ),
		'tags' => array( 'id' => '%d', 'name' => '%s', 'slug' => '%s', 'created_at' => '%s' ),
		'skill_categories' => array( 'skill_id' => '%d', 'category_id' => '%d' ),
		'skill_tags' => array( 'skill_id' => '%d', 'tag_id' => '%d' ),
	);

	public const UNIQUE_KEYS = array(
		'repositories' => array( 'PRIMARY' => array( 'id' ), 'repository_identity' => array( 'owner', 'name' ) ),
		'skills' => array( 'PRIMARY' => array( 'id' ), 'skill_source' => array( 'repository_id', 'source_path' ) ),
		'skill_translations' => array( 'PRIMARY' => array( 'id' ), 'skill_locale' => array( 'skill_id', 'locale' ), 'locale_slug' => array( 'locale', 'slug' ) ),
		'skill_versions' => array( 'PRIMARY' => array( 'id' ), 'skill_commit' => array( 'skill_id', 'commit_sha' ) ),
		'categories' => array( 'PRIMARY' => array( 'id' ), 'slug' => array( 'slug' ) ),
		'tags' => array( 'PRIMARY' => array( 'id' ), 'slug' => array( 'slug' ) ),
		'skill_categories' => array( 'PRIMARY' => array( 'skill_id', 'category_id' ) ),
		'skill_tags' => array( 'PRIMARY' => array( 'skill_id', 'tag_id' ) ),
	);

	public static function definitions( Database $db, string $version ): array {
		$collation = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		$suffix    = " ENGINE=InnoDB $collation;";
		$label     = version_compare( $version, '1.1.0', '>=' ) ? "\nversion_label varchar(191) NOT NULL DEFAULT ''," : '';
		$bodies    = array(
			'repositories' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
owner varchar(39) NOT NULL,
name varchar(100) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY repository_identity (owner,name)",
			'skills' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
repository_id bigint(20) unsigned NOT NULL,
source_path varbinary(768) NOT NULL,
status varchar(20) NOT NULL DEFAULT 'draft',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY skill_source (repository_id,source_path),
KEY status_created (status,created_at)",
			'skill_translations' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
skill_id bigint(20) unsigned NOT NULL,
locale varchar(10) NOT NULL,
title varchar(191) NOT NULL,
slug varchar(191) NOT NULL,
summary text NOT NULL,
content longtext NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY skill_locale (skill_id,locale),
UNIQUE KEY locale_slug (locale,slug)",
			'skill_versions' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
skill_id bigint(20) unsigned NOT NULL,
commit_sha varchar(64) NOT NULL,
content_hash char(64) NOT NULL,
source_content mediumtext NOT NULL,$label
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY skill_commit (skill_id,commit_sha)",
			'categories' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
name varchar(191) NOT NULL,
slug varchar(191) NOT NULL,
parent_id bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY slug (slug),
KEY parent_id (parent_id)",
			'tags' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
name varchar(191) NOT NULL,
slug varchar(191) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY slug (slug)",
			'skill_categories' => "skill_id bigint(20) unsigned NOT NULL,
category_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (skill_id,category_id),
KEY category_id (category_id)",
			'skill_tags' => "skill_id bigint(20) unsigned NOT NULL,
tag_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (skill_id,tag_id),
KEY tag_id (tag_id)",
		);
		$definitions = array();
		foreach ( $bodies as $entity => $body ) {
			$definitions[ $entity ] = 'CREATE TABLE ' . trim( $db->table( $entity ), '`' ) . " (\n$body\n)$suffix";
		}
		return $definitions;
	}

	/** Verify physical schema before advancing the version; dbDelta's messages alone are insufficient. */
	public static function verify( Database $db, string $version ): void {
		foreach ( self::COLUMNS as $entity => $columns ) {
			if ( 'skill_versions' === $entity && version_compare( $version, '1.1.0', '<' ) ) {
				unset( $columns['version_label'] );
			}
			$table  = $db->table( $entity );
			$fields = $db->rows( "SHOW COLUMNS FROM $table" );
			$actual = array_column( $fields, 'Field' );
			if ( array_diff( array_keys( $columns ), $actual ) ) {
				throw new \RuntimeException( 'Core schema columns are incomplete.' );
			}
			foreach ( $fields as $field ) {
				if ( 'id' === $field['Field'] && ( ! str_contains( $field['Type'], 'bigint' ) || ! str_contains( $field['Type'], 'unsigned' ) || ! str_contains( $field['Extra'], 'auto_increment' ) ) ) {
					throw new \RuntimeException( 'Core identity columns are invalid.' );
				}
				if ( 'source_path' === $field['Field'] && 'varbinary(768)' !== strtolower( $field['Type'] ) ) {
					throw new \RuntimeException( 'Source paths require exact byte comparison.' );
				}
			}
			$indexes = array();
			foreach ( $db->rows( "SHOW INDEX FROM $table" ) as $index ) {
				if ( 0 === (int) $index['Non_unique'] ) {
					if ( null !== $index['Sub_part'] ) {
						throw new \RuntimeException( 'Core unique keys must not truncate identity columns.' );
					}
					$indexes[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				}
			}
			foreach ( self::UNIQUE_KEYS[ $entity ] as $name => $expected ) {
				$parts = $indexes[ $name ] ?? array();
				ksort( $parts );
				if ( array_values( $parts ) !== $expected ) {
					throw new \RuntimeException( 'Core schema unique indexes are incomplete.' );
				}
			}
			$engine = $db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( trim( $table, '`' ) ) );
			if ( 'InnoDB' !== $engine ) {
				throw new \RuntimeException( 'Core tables require InnoDB.' );
			}
			$collation = $db->value( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( trim( $table, '`' ) ) );
			if ( ! is_string( $collation ) || ! str_starts_with( $collation, 'utf8mb4_' ) ) {
				throw new \RuntimeException( 'Core tables require utf8mb4.' );
			}
		}
	}
}
