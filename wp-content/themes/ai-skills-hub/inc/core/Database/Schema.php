<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const VERSION = '1.8.0';

	public const COLUMNS = array(
		'repositories' => array( 'id' => '%d', 'owner' => '%s', 'name' => '%s', 'created_at' => '%s', 'is_archived' => '%d', 'github_id' => '%d', 'default_branch' => '%s', 'sync_enabled' => '%d', 'sync_interval' => '%d', 'last_sync_at' => '%s', 'last_sync_commit' => '%s', 'last_scan_at' => '%s', 'sync_status' => '%s', 'license_spdx' => '%s' ),
		'skills' => array( 'id' => '%d', 'repository_id' => '%d', 'source_path' => '%s', 'status' => '%s', 'revision' => '%d', 'review_status' => '%s', 'risk_level' => '%s', 'reviewer_id' => '%d', 'reviewed_at' => '%s', 'reviewed_revision' => '%d', 'review_notes' => '%s', 'created_at' => '%s', 'updated_at' => '%s' ),
		'skill_translations' => array( 'id' => '%d', 'skill_id' => '%d', 'locale' => '%s', 'title' => '%s', 'slug' => '%s', 'summary' => '%s', 'content' => '%s', 'seo_title' => '%s', 'seo_description' => '%s', 'created_at' => '%s', 'updated_at' => '%s' ),
		'skill_versions' => array( 'id' => '%d', 'skill_id' => '%d', 'commit_sha' => '%s', 'content_hash' => '%s', 'source_content' => '%s', 'created_at' => '%s', 'version_label' => '%s', 'file_sha' => '%s', 'repository_id' => '%d', 'source_path' => '%s' ),
		'categories' => array( 'id' => '%d', 'name' => '%s', 'slug' => '%s', 'parent_id' => '%d', 'created_at' => '%s', 'is_archived' => '%d' ),
		'tags' => array( 'id' => '%d', 'name' => '%s', 'slug' => '%s', 'created_at' => '%s', 'is_archived' => '%d' ),
		'skill_categories' => array( 'skill_id' => '%d', 'category_id' => '%d' ),
		'skill_tags' => array( 'skill_id' => '%d', 'tag_id' => '%d' ),
		'imports' => array( 'id' => '%d', 'skill_id' => '%d', 'repository_id' => '%d', 'source_path' => '%s', 'file_sha' => '%s', 'commit_sha' => '%s', 'source_status' => '%s', 'change_status' => '%s', 'metadata_json' => '%s', 'findings_json' => '%s', 'last_seen_job' => '%d', 'created_at' => '%s', 'updated_at' => '%s' ),
		'jobs' => array( 'id' => '%d', 'repository_id' => '%d', 'kind' => '%s', 'status' => '%s', 'active_key' => '%d', 'state_json' => '%s', 'attempts' => '%d', 'available_at' => '%s', 'lease_token' => '%s', 'locked_until' => '%s', 'deadline_at' => '%s', 'created_at' => '%s', 'updated_at' => '%s', 'completed_at' => '%s', 'last_error' => '%s' ),
		'import_logs' => array( 'id' => '%d', 'job_id' => '%d', 'repository_id' => '%d', 'skill_id' => '%d', 'source_path' => '%s', 'level' => '%s', 'code' => '%s', 'message' => '%s', 'created_at' => '%s' ),
		'ai_jobs' => array( 'id' => '%d', 'skill_id' => '%d', 'mode' => '%s', 'request_key' => '%s', 'active_key' => '%s', 'profile_json' => '%s', 'profile_hash' => '%s', 'provider' => '%s', 'model' => '%s', 'prompt_version' => '%s', 'source_json' => '%s', 'source_commit' => '%s', 'file_sha' => '%s', 'status' => '%s', 'review_status' => '%s', 'revision' => '%d', 'output_json' => '%s', 'edited_json' => '%s', 'attempts' => '%d', 'input_tokens' => '%d', 'output_tokens' => '%d', 'cost_estimate' => '%s', 'elapsed_ms' => '%d', 'requester_id' => '%d', 'lease_token' => '%s', 'locked_until' => '%s', 'available_at' => '%s', 'deadline_at' => '%s', 'created_at' => '%s', 'updated_at' => '%s', 'completed_at' => '%s', 'last_error' => '%s' ),
		'ai_attempts' => array( 'id' => '%d', 'job_id' => '%d', 'attempt_number' => '%d', 'request_key' => '%s', 'status' => '%s', 'input_tokens' => '%d', 'output_tokens' => '%d', 'cost_estimate' => '%s', 'elapsed_ms' => '%d', 'error_code' => '%s', 'started_at' => '%s', 'completed_at' => '%s' ),
		'ai_reviews' => array( 'id' => '%d', 'job_id' => '%d', 'revision' => '%d', 'action' => '%s', 'actor_id' => '%d', 'snapshot_json' => '%s', 'created_at' => '%s' ),
		'email_verifications' => array( 'id' => '%d', 'user_id' => '%d', 'purpose' => '%s', 'target_email' => '%s', 'email_hash' => '%s', 'token_hash' => '%s', 'active_key' => '%s', 'expires_at' => '%s', 'attempt_count' => '%d', 'consumed_at' => '%s', 'created_at' => '%s', 'updated_at' => '%s' ),
		'user_notifications' => array( 'id' => '%d', 'user_id' => '%d', 'event_key' => '%s', 'type' => '%s', 'subject_type' => '%s', 'subject_id' => '%d', 'template_key' => '%s', 'params_json' => '%s', 'locale' => '%s', 'read_at' => '%s', 'created_at' => '%s', 'expires_at' => '%s' ),
		'notification_outbox' => array( 'id' => '%d', 'recipient_user_id' => '%d', 'notification_id' => '%d', 'event_key' => '%s', 'channel' => '%s', 'template_key' => '%s', 'locale' => '%s', 'destination_hash' => '%s', 'payload_ciphertext' => '%s', 'key_version' => '%s', 'status' => '%s', 'attempts' => '%d', 'available_at' => '%s', 'lease_token' => '%s', 'locked_until' => '%s', 'last_error' => '%s', 'provider_message_id' => '%s', 'created_at' => '%s', 'updated_at' => '%s', 'accepted_at' => '%s', 'expires_at' => '%s' ),
		'favorites' => array( 'user_id' => '%d', 'skill_id' => '%d', 'is_active' => '%d', 'created_at' => '%s', 'updated_at' => '%s', 'cancelled_at' => '%s' ),
		'reviews' => array( 'id' => '%d', 'user_id' => '%d', 'skill_id' => '%d', 'state' => '%s', 'approved_revision_id' => '%d', 'pending_revision_id' => '%d', 'lock_version' => '%d', 'first_published_at' => '%s', 'created_at' => '%s', 'updated_at' => '%s', 'withdrawn_at' => '%s' ),
		'review_revisions' => array( 'id' => '%d', 'review_id' => '%d', 'revision' => '%d', 'locale' => '%s', 'rating' => '%d', 'title' => '%s', 'comment' => '%s', 'experience' => '%s', 'pros' => '%s', 'cons' => '%s', 'platform' => '%s', 'version_label' => '%s', 'moderation_state' => '%s', 'moderator_id' => '%d', 'moderated_at' => '%s', 'moderation_code' => '%s', 'private_note' => '%s', 'request_key' => '%s', 'created_at' => '%s' ),
		'review_reports' => array( 'id' => '%d', 'review_id' => '%d', 'revision_id' => '%d', 'reporter_user_id' => '%d', 'locale' => '%s', 'reason_code' => '%s', 'details' => '%s', 'state' => '%s', 'active_key' => '%s', 'request_key' => '%s', 'handler_id' => '%d', 'resolution_code' => '%s', 'private_note' => '%s', 'created_at' => '%s', 'resolved_at' => '%s' ),
		'skill_reports' => array( 'id' => '%d', 'skill_id' => '%d', 'skill_revision' => '%d', 'reporter_user_id' => '%d', 'locale' => '%s', 'kind' => '%s', 'details' => '%s', 'state' => '%s', 'active_key' => '%s', 'request_key' => '%s', 'handler_id' => '%d', 'resolution_code' => '%s', 'private_note' => '%s', 'created_at' => '%s', 'resolved_at' => '%s' ),
		'workflow_events' => array( 'id' => '%d', 'event_key' => '%s', 'subject_type' => '%s', 'subject_id' => '%d', 'subject_revision' => '%d', 'action' => '%s', 'actor_user_id' => '%d', 'from_state' => '%s', 'to_state' => '%s', 'metadata_json' => '%s', 'created_at' => '%s', 'retain_until' => '%s' ),
		'metric_events' => array( 'id' => '%d', 'metric_name' => '%s', 'dimension_type' => '%s', 'dimension_key' => '%s', 'locale' => '%s', 'event_day' => '%s', 'dedupe_key' => '%s', 'policy_version' => '%s', 'occurred_at' => '%s', 'expires_at' => '%s' ),
		'daily_metrics' => array( 'stat_day' => '%s', 'metric_name' => '%s', 'dimension_type' => '%s', 'dimension_key' => '%s', 'locale' => '%s', 'metric_count' => '%d', 'policy_version' => '%s', 'updated_at' => '%s' ),
		'author_claims' => array( 'id' => '%d', 'skill_id' => '%d', 'claimant_user_id' => '%d', 'claim_role' => '%s', 'repository_id' => '%d', 'source_revision' => '%d', 'state' => '%s', 'proof_kind' => '%s', 'proof_json' => '%s', 'request_key' => '%s', 'active_request_key' => '%s', 'approved_skill_key' => '%d', 'reviewer_id' => '%d', 'reviewed_at' => '%s', 'expires_at' => '%s', 'created_at' => '%s', 'updated_at' => '%s' ),
	);

	public const UNIQUE_KEYS = array(
		'repositories' => array( 'PRIMARY' => array( 'id' ), 'repository_identity' => array( 'owner', 'name' ), 'github_identity' => array( 'github_id' ) ),
		'skills' => array( 'PRIMARY' => array( 'id' ), 'skill_source' => array( 'repository_id', 'source_path' ) ),
		'skill_translations' => array( 'PRIMARY' => array( 'id' ), 'skill_locale' => array( 'skill_id', 'locale' ), 'locale_slug' => array( 'locale', 'slug' ) ),
		'skill_versions' => array( 'PRIMARY' => array( 'id' ), 'skill_commit' => array( 'skill_id', 'commit_sha' ) ),
		'categories' => array( 'PRIMARY' => array( 'id' ), 'slug' => array( 'slug' ) ),
		'tags' => array( 'PRIMARY' => array( 'id' ), 'slug' => array( 'slug' ) ),
		'skill_categories' => array( 'PRIMARY' => array( 'skill_id', 'category_id' ) ),
		'skill_tags' => array( 'PRIMARY' => array( 'skill_id', 'tag_id' ) ),
		'imports' => array( 'PRIMARY' => array( 'id' ), 'import_skill' => array( 'skill_id' ), 'import_source' => array( 'repository_id', 'source_path' ) ),
		'jobs' => array( 'PRIMARY' => array( 'id' ), 'active_repository' => array( 'active_key' ) ),
		'import_logs' => array( 'PRIMARY' => array( 'id' ) ),
		'ai_jobs' => array( 'PRIMARY' => array( 'id' ), 'request_identity' => array( 'request_key' ), 'active_generation' => array( 'active_key' ) ),
		'ai_attempts' => array( 'PRIMARY' => array( 'id' ), 'job_attempt' => array( 'job_id', 'attempt_number' ), 'attempt_request' => array( 'request_key' ) ),
		'ai_reviews' => array( 'PRIMARY' => array( 'id' ), 'job_revision' => array( 'job_id', 'revision' ) ),
		'email_verifications' => array( 'PRIMARY' => array( 'id' ), 'token_identity' => array( 'token_hash' ), 'active_verification' => array( 'active_key' ) ),
		'user_notifications' => array( 'PRIMARY' => array( 'id' ), 'user_event' => array( 'user_id', 'event_key' ) ),
		'notification_outbox' => array( 'PRIMARY' => array( 'id' ), 'event_channel' => array( 'event_key', 'channel' ) ),
		'favorites' => array( 'PRIMARY' => array( 'user_id', 'skill_id' ) ),
		'reviews' => array( 'PRIMARY' => array( 'id' ), 'user_skill' => array( 'user_id', 'skill_id' ) ),
		'review_revisions' => array( 'PRIMARY' => array( 'id' ), 'review_revision' => array( 'review_id', 'revision' ), 'request_identity' => array( 'request_key' ) ),
		'review_reports' => array( 'PRIMARY' => array( 'id' ), 'request_identity' => array( 'request_key' ), 'active_report' => array( 'active_key' ) ),
		'skill_reports' => array( 'PRIMARY' => array( 'id' ), 'request_identity' => array( 'request_key' ), 'active_report' => array( 'active_key' ) ),
		'workflow_events' => array( 'PRIMARY' => array( 'id' ), 'event_identity' => array( 'event_key' ) ),
		'metric_events' => array( 'PRIMARY' => array( 'id' ), 'daily_event' => array( 'event_day', 'metric_name', 'dedupe_key' ) ),
		'daily_metrics' => array( 'PRIMARY' => array( 'stat_day', 'metric_name', 'dimension_type', 'dimension_key', 'locale' ) ),
		'author_claims' => array( 'PRIMARY' => array( 'id' ), 'request_identity' => array( 'request_key' ), 'active_request' => array( 'active_request_key' ), 'approved_skill' => array( 'approved_skill_key' ) ),
	);

	/** Columns introduced with the importer, masked when verifying historical migrations. */
	private const IMPORT_COLUMNS = array(
		'repositories' => array( 'github_id', 'default_branch', 'sync_enabled', 'sync_interval', 'last_sync_at', 'last_sync_commit', 'last_scan_at', 'sync_status', 'license_spdx' ),
		'skill_versions' => array( 'file_sha', 'repository_id', 'source_path' ),
	);
	private const IMPORT_TABLES = array( 'imports', 'jobs', 'import_logs' );
	private const AI_TABLES = array( 'ai_jobs', 'ai_attempts', 'ai_reviews' );
	private const ACCOUNT_TABLES = array( 'email_verifications', 'user_notifications', 'notification_outbox' );
	private const COMMUNITY_TABLES = array( 'favorites', 'reviews', 'review_revisions', 'review_reports', 'skill_reports', 'workflow_events', 'metric_events', 'daily_metrics' );
	private const DEVELOPER_TABLES = array( 'author_claims' );

	/** Phase 4 non-unique access paths; existing identity constraints stay unchanged. */
	public const SEARCH_KEYS = array(
		'skills' => array( 'public_updated' => array( 'status', 'review_status', 'updated_at', 'id' ) ),
		'skill_translations' => array( 'locale_title' => array( 'locale', 'title', 'skill_id' ) ),
		'email_verifications' => array( 'user_purpose' => array( 'user_id', 'purpose', 'created_at' ), 'expiry' => array( 'expires_at', 'id' ) ),
		'user_notifications' => array( 'user_unread' => array( 'user_id', 'read_at', 'id' ), 'expiry' => array( 'expires_at', 'id' ) ),
		'notification_outbox' => array( 'due' => array( 'status', 'available_at', 'id' ), 'lease' => array( 'status', 'locked_until' ), 'recipient_history' => array( 'recipient_user_id', 'created_at' ), 'expiry' => array( 'expires_at', 'id' ) ),
		'favorites' => array( 'user_active' => array( 'user_id', 'is_active', 'created_at', 'skill_id' ), 'skill_active' => array( 'skill_id', 'is_active', 'created_at', 'user_id' ) ),
		'reviews' => array( 'skill_state' => array( 'skill_id', 'state', 'id' ), 'user_updated' => array( 'user_id', 'updated_at', 'id' ) ),
		'review_revisions' => array( 'moderation_queue' => array( 'moderation_state', 'created_at', 'id' ), 'review_history' => array( 'review_id', 'id' ) ),
		'review_reports' => array( 'report_queue' => array( 'state', 'created_at', 'id' ), 'review_report' => array( 'review_id', 'revision_id', 'id' ), 'reporter_history' => array( 'reporter_user_id', 'id' ) ),
		'skill_reports' => array( 'report_queue' => array( 'state', 'kind', 'created_at', 'id' ), 'skill_report' => array( 'skill_id', 'id' ), 'reporter_history' => array( 'reporter_user_id', 'id' ) ),
		'workflow_events' => array( 'subject_history' => array( 'subject_type', 'subject_id', 'id' ), 'subject_action' => array( 'subject_type', 'action', 'created_at', 'subject_id' ), 'actor_history' => array( 'actor_user_id', 'created_at', 'id' ), 'retention' => array( 'retain_until', 'id' ) ),
		'metric_events' => array( 'dimension_day' => array( 'dimension_type', 'dimension_key', 'event_day' ), 'expiry' => array( 'expires_at', 'id' ) ),
		'daily_metrics' => array( 'metric_window' => array( 'metric_name', 'dimension_type', 'stat_day', 'dimension_key' ) ),
		'author_claims' => array( 'skill_state' => array( 'skill_id', 'state', 'id' ), 'claimant_state' => array( 'claimant_user_id', 'state', 'id' ), 'claim_queue' => array( 'state', 'created_at', 'id' ) ),
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
		if ( version_compare( $version, '1.2.0', '>=' ) ) {
			$bodies['skills'] = str_replace( array( 'repository_id bigint(20) unsigned NOT NULL,', 'source_path varbinary(768) NOT NULL,' ), array( 'repository_id bigint(20) unsigned DEFAULT NULL,', 'source_path varbinary(768) DEFAULT NULL,' ), $bodies['skills'] );
			$bodies['skills'] = str_replace( "created_at datetime NOT NULL,", "revision bigint(20) unsigned NOT NULL DEFAULT 1,\nreview_status varchar(20) NOT NULL DEFAULT 'unreviewed',\nrisk_level varchar(10) NOT NULL DEFAULT 'unknown',\nreviewer_id bigint(20) unsigned DEFAULT NULL,\nreviewed_at datetime DEFAULT NULL,\nreviewed_revision bigint(20) unsigned DEFAULT NULL,\nreview_notes text,\ncreated_at datetime NOT NULL,", $bodies['skills'] );
			$bodies['skill_translations'] = str_replace( 'created_at datetime NOT NULL,', "seo_title varchar(191) NOT NULL DEFAULT '',\nseo_description varchar(320) NOT NULL DEFAULT '',\ncreated_at datetime NOT NULL,", $bodies['skill_translations'] );
			$bodies['skill_translations'] = str_replace( 'slug varchar(191) NOT NULL,', 'slug varchar(191) DEFAULT NULL,', $bodies['skill_translations'] );
			foreach ( array( 'repositories', 'categories', 'tags' ) as $entity ) {
				$bodies[ $entity ] = str_replace( 'created_at datetime NOT NULL,', "is_archived tinyint(1) unsigned NOT NULL DEFAULT 0,\ncreated_at datetime NOT NULL,", $bodies[ $entity ] );
			}
		}
		if ( version_compare( $version, '1.3.0', '>=' ) ) {
			$bodies['skills'] .= ",\nKEY public_updated (status,review_status,updated_at,id)";
			$bodies['skill_translations'] .= ",\nKEY locale_title (locale,title,skill_id)";
		}

		if ( version_compare( $version, '1.4.0', '>=' ) ) {
			$bodies['repositories'] = str_replace( 'created_at datetime NOT NULL,', "github_id bigint(20) unsigned DEFAULT NULL,
default_branch varchar(255) NOT NULL DEFAULT '',
sync_enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
sync_interval int(10) unsigned NOT NULL DEFAULT 86400,
last_sync_at datetime DEFAULT NULL,
last_sync_commit varchar(64) NOT NULL DEFAULT '',
last_scan_at datetime DEFAULT NULL,
sync_status varchar(20) NOT NULL DEFAULT 'idle',
license_spdx varchar(100) NOT NULL DEFAULT '',
created_at datetime NOT NULL,", $bodies['repositories'] );
			$bodies['repositories'] .= ",
UNIQUE KEY github_identity (github_id)";
			$bodies['skill_versions'] = str_replace( 'created_at datetime NOT NULL,', "file_sha varchar(64) NOT NULL DEFAULT '',
repository_id bigint(20) unsigned DEFAULT NULL,
source_path varbinary(768) DEFAULT NULL,
created_at datetime NOT NULL,", $bodies['skill_versions'] );
			$bodies['imports'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
skill_id bigint(20) unsigned NOT NULL,
repository_id bigint(20) unsigned NOT NULL,
source_path varbinary(768) NOT NULL,
file_sha varchar(64) NOT NULL DEFAULT '',
commit_sha varchar(64) NOT NULL DEFAULT '',
source_status varchar(20) NOT NULL DEFAULT 'present',
change_status varchar(20) NOT NULL DEFAULT 'new',
metadata_json mediumtext,
findings_json mediumtext,
last_seen_job bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY import_skill (skill_id),
UNIQUE KEY import_source (repository_id,source_path),
KEY repository_status (repository_id,source_status)";
			$bodies['jobs'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
repository_id bigint(20) unsigned NOT NULL,
kind varchar(10) NOT NULL,
status varchar(20) NOT NULL DEFAULT 'queued',
active_key bigint(20) unsigned DEFAULT NULL,
state_json mediumtext,
attempts int(10) unsigned NOT NULL DEFAULT 0,
available_at datetime NOT NULL,
lease_token varchar(64) NOT NULL DEFAULT '',
locked_until datetime DEFAULT NULL,
deadline_at datetime NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
completed_at datetime DEFAULT NULL,
last_error varchar(64) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
UNIQUE KEY active_repository (active_key),
KEY due (status,available_at),
KEY lease (status,locked_until)";
			$bodies['import_logs'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_id bigint(20) unsigned NOT NULL,
repository_id bigint(20) unsigned NOT NULL,
skill_id bigint(20) unsigned DEFAULT NULL,
source_path varbinary(768) DEFAULT NULL,
level varchar(10) NOT NULL,
code varchar(64) NOT NULL,
message varchar(500) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY job_log (job_id,id)";
		}

		if ( version_compare( $version, '1.5.0', '>=' ) ) {
			$bodies['ai_jobs'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
skill_id bigint(20) unsigned DEFAULT NULL,
mode varchar(20) NOT NULL,
request_key char(64) NOT NULL,
active_key char(64) DEFAULT NULL,
profile_json mediumtext NOT NULL,
profile_hash char(64) NOT NULL,
provider varchar(64) NOT NULL,
model varchar(191) NOT NULL,
prompt_version varchar(64) NOT NULL,
source_json mediumtext NOT NULL,
source_commit varchar(64) NOT NULL DEFAULT '',
file_sha varchar(64) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'queued',
review_status varchar(20) NOT NULL DEFAULT 'pending',
revision bigint(20) unsigned NOT NULL DEFAULT 1,
output_json mediumtext,
edited_json mediumtext,
attempts int(10) unsigned NOT NULL DEFAULT 0,
input_tokens bigint(20) unsigned DEFAULT NULL,
output_tokens bigint(20) unsigned DEFAULT NULL,
cost_estimate decimal(20,8) DEFAULT NULL,
elapsed_ms bigint(20) unsigned NOT NULL DEFAULT 0,
requester_id bigint(20) unsigned NOT NULL,
lease_token char(64) NOT NULL DEFAULT '',
locked_until datetime DEFAULT NULL,
available_at datetime NOT NULL,
deadline_at datetime NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
completed_at datetime DEFAULT NULL,
last_error varchar(64) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
UNIQUE KEY request_identity (request_key),
UNIQUE KEY active_generation (active_key),
KEY due (status,available_at),
KEY skill_history (skill_id,id),
KEY lease (status,locked_until)";
			$bodies['ai_attempts'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_id bigint(20) unsigned NOT NULL,
attempt_number int(10) unsigned NOT NULL,
request_key char(64) NOT NULL,
status varchar(20) NOT NULL DEFAULT 'inflight',
input_tokens bigint(20) unsigned DEFAULT NULL,
output_tokens bigint(20) unsigned DEFAULT NULL,
cost_estimate decimal(20,8) DEFAULT NULL,
elapsed_ms bigint(20) unsigned NOT NULL DEFAULT 0,
error_code varchar(64) NOT NULL DEFAULT '',
started_at datetime NOT NULL,
completed_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY job_attempt (job_id,attempt_number),
UNIQUE KEY attempt_request (request_key)";
			$bodies['ai_reviews'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_id bigint(20) unsigned NOT NULL,
revision bigint(20) unsigned NOT NULL,
action varchar(20) NOT NULL,
actor_id bigint(20) unsigned NOT NULL,
snapshot_json mediumtext NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY job_revision (job_id,revision)";
		}

		if ( version_compare( $version, '1.6.0', '>=' ) ) {
			$bodies['email_verifications'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
purpose varchar(32) NOT NULL,
target_email varchar(254) NOT NULL,
email_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
token_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
active_key char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
expires_at datetime NOT NULL,
attempt_count int(10) unsigned NOT NULL DEFAULT 0,
consumed_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY token_identity (token_hash),
UNIQUE KEY active_verification (active_key),
KEY user_purpose (user_id,purpose,created_at),
KEY expiry (expires_at,id)";
			$bodies['user_notifications'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
event_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
type varchar(32) NOT NULL,
subject_type varchar(32) NOT NULL,
subject_id bigint(20) unsigned DEFAULT NULL,
template_key varchar(64) NOT NULL,
params_json text NOT NULL,
locale varchar(10) NOT NULL,
read_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
expires_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY user_event (user_id,event_key),
KEY user_unread (user_id,read_at,id),
KEY expiry (expires_at,id)";
			$bodies['notification_outbox'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
recipient_user_id bigint(20) unsigned DEFAULT NULL,
notification_id bigint(20) unsigned DEFAULT NULL,
event_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
channel varchar(16) NOT NULL DEFAULT 'email',
template_key varchar(64) NOT NULL,
locale varchar(10) NOT NULL,
destination_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
payload_ciphertext mediumtext,
key_version varchar(32) NOT NULL,
status varchar(20) NOT NULL DEFAULT 'queued',
attempts int(10) unsigned NOT NULL DEFAULT 0,
available_at datetime NOT NULL,
lease_token char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
locked_until datetime DEFAULT NULL,
last_error varchar(64) NOT NULL DEFAULT '',
provider_message_id varchar(191) DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
accepted_at datetime DEFAULT NULL,
expires_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY event_channel (event_key,channel),
KEY due (status,available_at,id),
KEY lease (status,locked_until),
KEY recipient_history (recipient_user_id,created_at),
KEY expiry (expires_at,id)";
		}

		if ( version_compare( $version, '1.7.0', '>=' ) ) {
			$bodies['favorites'] = "user_id bigint(20) unsigned NOT NULL,
skill_id bigint(20) unsigned NOT NULL,
is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
cancelled_at datetime DEFAULT NULL,
PRIMARY KEY  (user_id,skill_id),
KEY user_active (user_id,is_active,created_at,skill_id),
KEY skill_active (skill_id,is_active,created_at,user_id)";
			$bodies['reviews'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned DEFAULT NULL,
skill_id bigint(20) unsigned NOT NULL,
state varchar(20) NOT NULL DEFAULT 'pending',
approved_revision_id bigint(20) unsigned DEFAULT NULL,
pending_revision_id bigint(20) unsigned DEFAULT NULL,
lock_version bigint(20) unsigned NOT NULL DEFAULT 1,
first_published_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
withdrawn_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY user_skill (user_id,skill_id),
KEY skill_state (skill_id,state,id),
KEY user_updated (user_id,updated_at,id)";
			$bodies['review_revisions'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
review_id bigint(20) unsigned NOT NULL,
revision int(10) unsigned NOT NULL,
locale varchar(10) NOT NULL,
rating tinyint(1) unsigned NOT NULL,
title varchar(191) NOT NULL,
comment text NOT NULL,
experience text NOT NULL,
pros text NOT NULL,
cons text NOT NULL,
platform varchar(100) NOT NULL DEFAULT '',
version_label varchar(191) NOT NULL DEFAULT '',
moderation_state varchar(20) NOT NULL DEFAULT 'pending',
moderator_id bigint(20) unsigned DEFAULT NULL,
moderated_at datetime DEFAULT NULL,
moderation_code varchar(64) DEFAULT NULL,
private_note text DEFAULT NULL,
request_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY review_revision (review_id,revision),
UNIQUE KEY request_identity (request_key),
KEY moderation_queue (moderation_state,created_at,id),
KEY review_history (review_id,id)";
			$bodies['review_reports'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
review_id bigint(20) unsigned NOT NULL,
revision_id bigint(20) unsigned NOT NULL,
reporter_user_id bigint(20) unsigned DEFAULT NULL,
locale varchar(10) NOT NULL,
reason_code varchar(32) NOT NULL,
details text NOT NULL,
state varchar(20) NOT NULL DEFAULT 'pending',
active_key char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
request_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
handler_id bigint(20) unsigned DEFAULT NULL,
resolution_code varchar(64) DEFAULT NULL,
private_note text DEFAULT NULL,
created_at datetime NOT NULL,
resolved_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY request_identity (request_key),
UNIQUE KEY active_report (active_key),
KEY report_queue (state,created_at,id),
KEY review_report (review_id,revision_id,id),
KEY reporter_history (reporter_user_id,id)";
			$bodies['skill_reports'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
skill_id bigint(20) unsigned NOT NULL,
skill_revision bigint(20) unsigned NOT NULL,
reporter_user_id bigint(20) unsigned DEFAULT NULL,
locale varchar(10) NOT NULL,
kind varchar(32) NOT NULL,
details text NOT NULL,
state varchar(20) NOT NULL DEFAULT 'pending',
active_key char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
request_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
handler_id bigint(20) unsigned DEFAULT NULL,
resolution_code varchar(64) DEFAULT NULL,
private_note text DEFAULT NULL,
created_at datetime NOT NULL,
resolved_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY request_identity (request_key),
UNIQUE KEY active_report (active_key),
KEY report_queue (state,kind,created_at,id),
KEY skill_report (skill_id,id),
KEY reporter_history (reporter_user_id,id)";
			$bodies['workflow_events'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
event_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
subject_type varchar(32) NOT NULL,
subject_id bigint(20) unsigned NOT NULL,
subject_revision bigint(20) unsigned DEFAULT NULL,
action varchar(32) NOT NULL,
actor_user_id bigint(20) unsigned DEFAULT NULL,
from_state varchar(20) DEFAULT NULL,
to_state varchar(20) DEFAULT NULL,
metadata_json text NOT NULL,
created_at datetime NOT NULL,
retain_until datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY event_identity (event_key),
KEY subject_history (subject_type,subject_id,id),
KEY subject_action (subject_type,action,created_at,subject_id),
KEY actor_history (actor_user_id,created_at,id),
KEY retention (retain_until,id)";
			$bodies['metric_events'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
metric_name varchar(32) NOT NULL,
dimension_type varchar(16) NOT NULL,
dimension_key varchar(191) NOT NULL,
locale varchar(10) NOT NULL DEFAULT '',
event_day date NOT NULL,
dedupe_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
policy_version varchar(16) NOT NULL,
occurred_at datetime NOT NULL,
expires_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY daily_event (event_day,metric_name,dedupe_key),
KEY dimension_day (dimension_type,dimension_key,event_day),
KEY expiry (expires_at,id)";
			$bodies['daily_metrics'] = "stat_day date NOT NULL,
metric_name varchar(32) NOT NULL,
dimension_type varchar(16) NOT NULL,
dimension_key varchar(191) NOT NULL,
locale varchar(10) NOT NULL DEFAULT '',
metric_count bigint(20) unsigned NOT NULL DEFAULT 0,
policy_version varchar(16) NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (stat_day,metric_name,dimension_type,dimension_key,locale),
KEY metric_window (metric_name,dimension_type,stat_day,dimension_key)";
		}

		if ( version_compare( $version, '1.8.0', '>=' ) ) {
			$bodies['author_claims'] = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
skill_id bigint(20) unsigned NOT NULL,
claimant_user_id bigint(20) unsigned DEFAULT NULL,
claim_role varchar(16) NOT NULL,
repository_id bigint(20) unsigned DEFAULT NULL,
source_revision bigint(20) unsigned NOT NULL,
state varchar(20) NOT NULL DEFAULT 'pending',
proof_kind varchar(32) NOT NULL,
proof_json text NOT NULL,
request_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
active_request_key char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
approved_skill_key bigint(20) unsigned DEFAULT NULL,
reviewer_id bigint(20) unsigned DEFAULT NULL,
reviewed_at datetime DEFAULT NULL,
expires_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY request_identity (request_key),
UNIQUE KEY active_request (active_request_key),
UNIQUE KEY approved_skill (approved_skill_key),
KEY skill_state (skill_id,state,id),
KEY claimant_state (claimant_user_id,state,id),
KEY claim_queue (state,created_at,id)";
		}

		$definitions = array();
		foreach ( $bodies as $entity => $body ) {
			$definitions[ $entity ] = 'CREATE TABLE ' . trim( $db->table( $entity ), '`' ) . " (\n$body\n)$suffix";
		}
		return $definitions;
	}

	/** Verify physical schema before advancing the version; dbDelta's messages alone are insufficient. */
	public static function verify( Database $db, string $version ): void {
		foreach ( self::COLUMNS as $entity => $columns ) {
			if ( version_compare( $version, '1.8.0', '<' ) && in_array( $entity, self::DEVELOPER_TABLES, true ) ) { continue; }
			if ( version_compare( $version, '1.7.0', '<' ) && in_array( $entity, self::COMMUNITY_TABLES, true ) ) { continue; }
			if ( version_compare( $version, '1.6.0', '<' ) && in_array( $entity, self::ACCOUNT_TABLES, true ) ) { continue; }
			if ( version_compare( $version, '1.5.0', '<' ) && in_array( $entity, self::AI_TABLES, true ) ) { continue; }
			if ( version_compare( $version, '1.4.0', '<' ) ) {
				if ( in_array( $entity, self::IMPORT_TABLES, true ) ) { continue; }
				foreach ( self::IMPORT_COLUMNS[ $entity ] ?? array() as $column ) { unset( $columns[ $column ] ); }
			}
			if ( 'skill_versions' === $entity && version_compare( $version, '1.1.0', '<' ) ) {
				unset( $columns['version_label'] );
			}
			if ( version_compare( $version, '1.2.0', '<' ) ) {
				foreach ( array( 'is_archived', 'revision', 'review_status', 'risk_level', 'reviewer_id', 'reviewed_at', 'reviewed_revision', 'review_notes', 'seo_title', 'seo_description' ) as $column ) { unset( $columns[ $column ] ); }
			}
			$table  = $db->table( $entity );
			$fields = $db->rows( "SHOW COLUMNS FROM $table" );
			if ( in_array( $entity, self::ACCOUNT_TABLES, true ) || in_array( $entity, self::COMMUNITY_TABLES, true ) || in_array( $entity, self::DEVELOPER_TABLES, true ) ) { self::verifyTypedFields( $db, $entity, $version ); }
			$actual = array_column( $fields, 'Field' );
			if ( array_diff( array_keys( $columns ), $actual ) ) {
				throw new \RuntimeException( 'Core schema columns are incomplete.' );
			}
			foreach ( $fields as $field ) {
				if ( 'id' === $field['Field'] && ( ! str_contains( $field['Type'], 'bigint' ) || ! str_contains( $field['Type'], 'unsigned' ) || ! str_contains( $field['Extra'], 'auto_increment' ) ) ) {
					throw new \RuntimeException( 'Core identity columns are invalid.' );
				}
				if ( 'skills' === $entity && version_compare( $version, '1.2.0', '>=' ) && in_array( $field['Field'], array( 'repository_id', 'source_path' ), true ) && 'YES' !== $field['Null'] ) {
					throw new \RuntimeException( 'Manual Skill source columns must allow NULL.' );
				}
				if ( 'skill_translations' === $entity && version_compare( $version, '1.2.0', '>=' ) && 'slug' === $field['Field'] && 'YES' !== $field['Null'] ) {
					throw new \RuntimeException( 'Draft translation slugs must allow NULL.' );
				}
				if ( version_compare( $version, '1.4.0', '>=' ) && ( ( 'repositories' === $entity && 'github_id' === $field['Field'] ) || ( 'jobs' === $entity && 'active_key' === $field['Field'] ) || ( 'skill_versions' === $entity && in_array( $field['Field'], array( 'repository_id', 'source_path' ), true ) ) ) && 'YES' !== $field['Null'] ) {
					throw new \RuntimeException( 'Importer optional identity columns must allow NULL.' );
				}
				if ( version_compare( $version, '1.5.0', '>=' ) && 'ai_jobs' === $entity && in_array( $field['Field'], array( 'skill_id', 'active_key' ), true ) && 'YES' !== $field['Null'] ) {
					throw new \RuntimeException( 'AI optional identity columns must allow NULL.' );
				}
				if ( 'source_path' === $field['Field'] && 'varbinary(768)' !== strtolower( $field['Type'] ) ) {
					throw new \RuntimeException( 'Source paths require exact byte comparison.' );
				}
			}
			$indexes = array();
			$searchIndexes = array();
			foreach ( $db->rows( "SHOW INDEX FROM $table" ) as $index ) {
				if ( version_compare( $version, '1.3.0', '>=' ) && isset( self::SEARCH_KEYS[ $entity ][ $index['Key_name'] ] ) && 1 === (int) $index['Non_unique'] && null === $index['Sub_part'] ) {
					$searchIndexes[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				}
				if ( 0 === (int) $index['Non_unique'] ) {
					if ( null !== $index['Sub_part'] ) {
						throw new \RuntimeException( 'Core unique keys must not truncate identity columns.' );
					}
					$indexes[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				}
			}
			foreach ( self::UNIQUE_KEYS[ $entity ] as $name => $expected ) {
				if ( 'github_identity' === $name && version_compare( $version, '1.4.0', '<' ) ) { continue; }
				$parts = $indexes[ $name ] ?? array();
				ksort( $parts );
				if ( array_values( $parts ) !== $expected ) {
					throw new \RuntimeException( 'Core schema unique indexes are incomplete.' );
				}
			}
			if ( version_compare( $version, '1.3.0', '>=' ) ) {
				foreach ( self::SEARCH_KEYS[ $entity ] ?? array() as $name => $expected ) {
					$parts = $searchIndexes[ $name ] ?? array();
					ksort( $parts );
					if ( array_values( $parts ) !== $expected ) { throw new \RuntimeException( 'Core search indexes are incomplete.' ); }
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

	/** New account/community tables verify types, nullability and binary hashes, not only names. */
	private static function verifyTypedFields( Database $db, string $entity, string $version ): void {
		$definition = self::definitions( $db, $version )[ $entity ];
		$expected = array();
		foreach ( explode( "\n", $definition ) as $line ) {
			if ( preg_match( '/^([a-z_]+) ((?:bigint|int|tinyint)\(\d+\) unsigned|char\(\d+\)|varchar\(\d+\)|datetime|date|mediumtext|text)(.*)$/D', rtrim( $line, ',' ), $match ) ) {
				$expected[ $match[1] ] = array( preg_replace( '/\b(bigint|int|tinyint)\(\d+\)/', '$1', $match[2] ), str_contains( $match[3], 'NOT NULL' ) ? 'NO' : 'YES', str_contains( $match[3], 'ascii_bin' ) );
			}
		}
		foreach ( $db->rows( 'SHOW FULL COLUMNS FROM ' . $db->table( $entity ) ) as $field ) {
			if ( ! isset( $expected[ $field['Field'] ] ) ) { continue; }
			[ $type, $nullable, $binary ] = $expected[ $field['Field'] ];
			if ( preg_replace( '/\b(bigint|int|tinyint)\(\d+\)/', '$1', strtolower( $field['Type'] ) ) !== $type || $nullable !== $field['Null'] || ( $binary && 'ascii_bin' !== $field['Collation'] ) ) {
				throw new \RuntimeException( 'Operational schema column contract is invalid.' );
			}
			unset( $expected[ $field['Field'] ] );
		}
		if ( $expected ) { throw new \RuntimeException( 'Operational schema columns are incomplete.' ); }
	}
}
