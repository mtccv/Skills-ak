<?php
declare(strict_types=1);

// Real WordPress/MySQL acceptance; opt-in and dedicated database only.
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_FINAL_QA' ) ) { exit( 2 ); }
define( 'WP_CLI', true );
require getenv( 'AISH_QA_WP_LOAD' ) ?: '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_data', 'qa_php84', 'qa_wp68' ), true ) ) { throw new RuntimeException( 'Dedicated Final QA data database required.' ); }

use AISkillsHub\Core\Core;
use AISkillsHub\Core\Content\Catalog;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Database\Schema;
use AISkillsHub\Core\Language\BuiltinLanguageProvider;
use AISkillsHub\Core\Admin\Management;
use AISkillsHub\Core\Sources\ImportStore;
use AISkillsHub\Core\AI\GenerationStore;
use AISkillsHub\Core\AI\SourceContext;

$qa_directory = getenv( 'AISH_QA_RESULT_DIR' ) ?: '/tmp/aish-final-qa';
if ( ! is_dir( $qa_directory ) ) { mkdir( $qa_directory, 0755, true ); }
if ( ! is_dir( '/tmp/aish-final-qa' ) ) { mkdir( '/tmp/aish-final-qa', 0700, true ); }
$qa_report = array( 'suite' => 'data', 'kind' => 'real_wordpress_mysql', 'started_at' => gmdate( 'c' ), 'cases' => array(), 'assertions' => 0, 'limitations' => array( 'No live GitHub or AI Provider calls.', 'Unknown COMMIT response after server durability cannot be conclusively undone or guaranteed exactly once.', 'Case modes 1/2 require a separately configured MySQL service; this run records the actual mode only.' ) );
$qa_fixtures = array();
function qa_check( bool $condition, string $label ): void { global $qa_report; ++$qa_report['assertions']; if ( ! $condition ) { throw new RuntimeException( $label ); } }
function qa_case( string $id, callable $work ): void {
	global $qa_report; $start = microtime( true );
	try { $evidence = $work(); $row = array( 'id' => $id, 'status' => 'passed', 'evidence' => $evidence ?? array() ); }
	catch ( Throwable $error ) { $row = array( 'id' => $id, 'status' => 'failed', 'triage' => 'required', 'error_class' => get_class( $error ), 'message' => $error->getMessage() ); }
	$row['elapsed_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 ); $qa_report['cases'][] = $row;
	echo $id . ': ' . $row['status'] . ( isset( $row['message'] ) ? ' — ' . $row['message'] : '' ) . "\n";
}
function qa_fixture( string $target = Schema::VERSION ): array {
	global $wpdb, $qa_fixtures;
	$prefix = 'aish_qd_' . bin2hex( random_bytes( 6 ) ) . '_'; $option = '_aish_qa_schema_' . $prefix;
	$db = new Database( $wpdb, $prefix ); $migrator = new Migrator( $db, $option );
	$qa_fixtures[] = array( $prefix, $option );
	if ( '0' !== $target ) { qa_check( true === $migrator->migrate( $target ), 'Fixture migration failed at ' . $target ); }
	$catalog = new Catalog( $db, $migrator, new BuiltinLanguageProvider() );
	return array( $db, $migrator, $catalog, new Management( $db, $migrator, $catalog ), $option );
}
function qa_counts( Database $db ): array { $counts = array(); foreach ( array_keys( Schema::COLUMNS ) as $entity ) { $counts[ $entity ] = (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( $entity ) ); } return $counts; }
function qa_rejects( callable $work, string $expected = Throwable::class ): Throwable { try { $work(); } catch ( Throwable $error ) { qa_check( $error instanceof $expected, 'Unexpected failure class.' ); return $error; } throw new RuntimeException( 'Expected rejection was accepted.' ); }
function qa_connection(): wpdb { global $wpdb; $connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $connection->set_prefix( $wpdb->prefix ); $connection->suppress_errors( true ); return $connection; }
function qa_processes( array $commands, ?string $barrier = null ): array {
	$children = array();
	foreach ( $commands as $arguments ) {
		$process = proc_open( array_merge( array( PHP_BINARY, __DIR__ . '/data-worker.php' ), $arguments ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start QA subprocess.' ); }
		fclose( $pipes[0] ); $children[] = array( $process, $pipes );
	}
	if ( null !== $barrier ) {
		$deadline = microtime( true ) + 20;
		while ( count( glob( $barrier . '.ready.*' ) ) < count( $children ) ) {
			if ( microtime( true ) > $deadline ) { foreach ( $children as [ $process ] ) { proc_terminate( $process ); } throw new RuntimeException( 'QA workers failed to reach the concurrent barrier.' ); }
			usleep( 20000 );
		}
		file_put_contents( $barrier, 'go' );
	}
	$output = array();
	foreach ( $children as [ $process, $pipes ] ) {
		$stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		$row = json_decode( trim( $stdout ), true );
		qa_check( is_array( $row ) && 0 === $exit && '' === trim( $stderr ), 'QA child failed or emitted a PHP warning.' ); $output[] = $row;
	}
	if ( null !== $barrier ) { unlink( $barrier ); foreach ( glob( $barrier . '.ready.*' ) as $ready ) { unlink( $ready ); } }
	return $output;
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
$original_theme = get_stylesheet();
$qa_report['environment'] = array( 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'isolation' => $wpdb->get_var( 'SELECT @@transaction_isolation' ), 'lower_case_table_names' => (int) $wpdb->get_var( 'SELECT @@lower_case_table_names' ), 'database' => DB_NAME, 'theme' => $original_theme );
try {
	qa_case( 'D01-fresh-fourteen-table-install', function (): array {
		[ $db, $migrator ] = qa_fixture(); Schema::verify( $db, Schema::VERSION );
		qa_check( 14 === count( Schema::COLUMNS ) && Schema::VERSION === $migrator->version(), 'Fourteen-table schema/version mismatch.' );
		$counts = qa_counts( $db ); qa_check( 0 === array_sum( $counts ), 'A fresh schema must be empty.' );
		foreach ( array_keys( Schema::COLUMNS ) as $entity ) {
			$row = $db->row( 'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array( trim( $db->table( $entity ), '`' ) ) );
			qa_check( 'InnoDB' === $row['ENGINE'] && str_starts_with( $row['TABLE_COLLATION'], 'utf8mb4' ), 'Unexpected physical storage engine or collation.' );
		}
		return array( 'tables' => array_keys( $counts ), 'schema' => $migrator->version() );
	} );
	qa_case( 'D02-historical-stepwise-upgrades-and-reinstall', function (): array {
		[ $db, $migrator, , , $option ] = qa_fixture( '1.0.0' ); $now = gmdate( 'Y-m-d H:i:s' );
		$repo = $db->insert( 'repositories', array( 'owner' => 'qa-legacy', 'name' => 'skills', 'created_at' => $now ) );
		$skill = $db->insert( 'skills', array( 'repository_id' => $repo, 'source_path' => 'legacy/SKILL.md', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
		foreach ( array( 'zh_CN' => '历史中文 🚀', 'en_US' => 'Historical English' ) as $locale => $title ) { $db->insert( 'skill_translations', array( 'skill_id' => $skill, 'locale' => $locale, 'title' => $title, 'slug' => 'legacy', 'summary' => 'Summary', 'content' => '<p>Body</p>', 'created_at' => $now, 'updated_at' => $now ) ); }
		$version = $db->insert( 'skill_versions', array( 'skill_id' => $skill, 'commit_sha' => str_repeat( 'a', 40 ), 'content_hash' => hash( 'sha256', 'old raw' ), 'source_content' => 'old raw', 'created_at' => $now ) );
		$steps = array();
		foreach ( array( '1.1.0', '1.2.0', '1.3.0', '1.4.0', '1.5.0' ) as $target ) {
			qa_check( true === $migrator->migrate( $target ), 'Historical upgrade failed: ' . $target ); Schema::verify( $db, $target );
			qa_check( true === $migrator->migrate( $target ), 'Repeated historical upgrade failed.' );
			qa_check( $skill === (int) $db->value( 'SELECT id FROM ' . $db->table( 'skills' ) ) && 2 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skill_translations' ) ), 'Upgrade changed stable IDs or bilingual rows.' );
			qa_check( $version === (int) $db->value( 'SELECT id FROM ' . $db->table( 'skill_versions' ) ) && 'old raw' === $db->value( 'SELECT source_content FROM ' . $db->table( 'skill_versions' ) ), 'Upgrade changed immutable history.' );
			$steps[] = $target;
		}
		qa_check( 'pending' === $db->value( 'SELECT status FROM ' . $db->table( 'skills' ) ), 'Legacy unreviewed publication was not gated.' );
		$before = qa_counts( $db ); $ddl = 0; $watch = static function ( string $sql ) use ( &$ddl ): string { if ( preg_match( '/^\s*(?:CREATE|ALTER|DROP)\s/i', $sql ) ) { ++$ddl; } return $sql; };
		add_filter( 'query', $watch ); try { qa_check( true === $migrator->migrate(), 'Repeat current schema failed.' ); } finally { remove_filter( 'query', $watch ); }
		qa_check( 0 === $ddl && $before === qa_counts( $db ), 'Repeated current install altered tables/data.' );
		qa_check( is_wp_error( $migrator->migrate( '1.0.0' ) ), 'Downgrade was accepted.' ); update_option( $option, '99.0.0', false );
		qa_check( is_wp_error( $migrator->migrate() ) && '99.0.0' === $migrator->version(), 'Unknown schema was overwritten.' ); update_option( $option, Schema::VERSION, false );
		return array( 'steps' => $steps, 'retained_counts' => $before, 'repeat_ddl' => $ddl );
	} );
	qa_case( 'D03-failed-upgrade-retains-version-and-recovers', function (): array {
		[ $db, $migrator ] = qa_fixture( '1.4.0' ); $view = $db->table( 'ai_jobs' );
		$db->execute( "CREATE VIEW $view AS SELECT 1 AS id" );
		try { $error = $migrator->migrate(); qa_check( is_wp_error( $error ) && '1.4.0' === $migrator->version(), 'Failed upgrade falsely advanced its version.' ); }
		finally { $db->execute( "DROP VIEW $view" ); }
		qa_check( true === $migrator->migrate(), 'Additive migration did not recover.' ); Schema::verify( $db, Schema::VERSION );
		return array( 'failed_version' => '1.4.0', 'recovered_version' => $migrator->version() );
	} );
	qa_case( 'D04-identities-bilingual-unique-keys-and-history', function (): array {
		[ $db, , $catalog, $management ] = qa_fixture(); $repo = $catalog->createRepository( 'QA-Owner', 'Skills' );
		qa_check( $repo === $catalog->createRepository( 'qa-owner', 'skills' ), 'Repository identity was duplicated.' );
		$a = $catalog->createSkill( $repo, 'one/SKILL.md' ); $b = $catalog->createSkill( $repo, 'two/SKILL.md' );
		qa_check( $a !== $b && $a === $catalog->createSkill( $repo, 'one/SKILL.md', 'pending' ), 'Source identity is unstable.' );
		qa_check( $a !== $catalog->createSkill( $repo, 'One/SKILL.md' ), 'Binary path key collapsed case.' );
		$zh = $catalog->saveTranslation( $a, 'zh', '真实中文 🚀', 'stable', '中文简介', '<p>正文</p>' ); $en = $catalog->saveTranslation( $a, 'en', 'English', 'stable', 'Summary', '<p>Body</p>' );
		qa_check( $zh !== $en && (int) $catalog->getTranslation( $a, 'zh_CN' )['skill_id'] === (int) $catalog->getTranslation( $a, 'en_US' )['skill_id'], 'Bilingual relation changed.' );
		qa_rejects( fn() => $catalog->saveTranslation( $b, 'en', 'Collision', 'stable' ), RuntimeException::class );
		qa_check( 'English' === $catalog->getTranslation( $a, 'en' )['title'], 'Collision overwrote another translation.' );
		$id = $catalog->addVersion( $a, str_repeat( 'b', 40 ), 'Private raw source', 'original' );
		qa_check( $id === $catalog->addVersion( $a, str_repeat( 'b', 40 ), 'Private raw source', 'renamed' ), 'Same snapshot was duplicated.' );
		qa_rejects( fn() => $catalog->addVersion( $a, str_repeat( 'b', 40 ), 'Conflict' ), DomainException::class );
		qa_check( 'original' === $catalog->versionsForSkill( $a )[0]['version_label'], 'Snapshot label was overwritten.' );
		$manual1 = $management->saveSkill( null, array( 'translations' => array( 'zh_CN' => array( 'title' => '草稿一' ) ) ) );
		$manual2 = $management->saveSkill( null, array( 'translations' => array( 'zh_CN' => array( 'title' => '草稿二' ) ) ) );
		qa_check( $manual1 !== $manual2 && null === $catalog->getSkill( $manual1 )['repository_id'] && null === $catalog->getTranslation( $manual2, 'zh' )['slug'], 'Nullable manual sources/slugs are not independent.' );
		$tag = $catalog->createTag( 'QA', 'qa' ); $catalog->assignTerm( $a, 'tag', $tag ); $catalog->assignTerm( $a, 'tag', $tag );
		qa_check( 1 === count( $catalog->termsForSkill( $a, 'tag' ) ), 'Repeated relation assignment created duplicates.' );
		return array( 'stable_skill_id' => $a, 'translation_ids' => array( $zh, $en ), 'version_id' => $id );
	} );
	qa_case( 'D05-real-migration-advisory-lock', function (): array {
		[ $db, $migrator ] = qa_fixture( '1.4.0' ); $second = qa_connection();
		try { qa_check( 1 === (int) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s,0)', $migrator->lockName() ) ), 'Cannot acquire competing migration lock.' ); $error = $migrator->migrate(); qa_check( is_wp_error( $error ) && 'aish_schema_busy' === $error->get_error_code() && '1.4.0' === $migrator->version(), 'Competing migration was not fenced.' ); }
		finally { $second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $migrator->lockName() ) ); $second->close(); }
		qa_check( true === $migrator->migrate(), 'Migration failed after lock release.' ); return array( 'sessions' => 2 );
	} );
	qa_case( 'D06-real-concurrent-source-deduplication', function (): array {
		[ $db, , $catalog ] = qa_fixture(); $repo = $catalog->createRepository( 'qa-concurrent', 'skills' );
		$barrier = '/tmp/aish-final-qa/data-barrier-' . bin2hex( random_bytes( 8 ) ); $commands = array_fill( 0, 8, array( 'source', $db->prefix(), (string) $repo, 'same/SKILL.md', $barrier ) );
		$results = qa_processes( $commands, $barrier ); $ids = array_column( $results, 'id' );
		qa_check( count( $ids ) === 8 && 1 === count( array_unique( $ids ) ), 'Concurrent source writers did not converge on one stable ID.' );
		qa_check( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skills' ) ), 'Concurrent source writers created duplicates.' );
		return array( 'workers' => 8, 'unique_ids' => array_values( array_unique( $ids ) ) );
	} );
	qa_case( 'D07-real-concurrent-editor-revision-fence', function (): array {
		[ $db, , $catalog, $management ] = qa_fixture(); $id = $management->saveSkill( null, array() );
		$barrier = '/tmp/aish-final-qa/data-barrier-' . bin2hex( random_bytes( 8 ) ); $commands = array_fill( 0, 2, array( 'revision', $db->prefix(), (string) $id, '1', $barrier ) );
		$results = qa_processes( $commands, $barrier ); $statuses = array_column( $results, 'status' ); sort( $statuses );
		qa_check( array( 'conflict', 'passed' ) === $statuses && 2 === (int) $catalog->getSkill( $id )['revision'], 'Concurrent stale editor was not rejected.' );
		return array( 'workers' => 2, 'outcomes' => $statuses, 'revision' => 2 );
	} );
	qa_case( 'D08-transaction-rollback-and-real-lock-timeout', function (): array {
		[ $db, , $catalog ] = qa_fixture(); $tag = $catalog->createTag( 'Locked', 'locked' ); $table = $db->table( 'tags' ); $second = qa_connection();
		$second->query( 'START TRANSACTION' ); $second->query( "SELECT id FROM $table WHERE id=$tag FOR UPDATE" );
		$db->execute( 'SET SESSION innodb_lock_wait_timeout=1' );
		try { qa_rejects( fn() => $db->transaction( function () use ( $db, $tag ) { $db->insert( 'tags', array( 'name' => 'Must rollback', 'slug' => 'rollback', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); $db->updateById( 'tags', $tag, array( 'name' => 'Blocked write' ) ); } ), RuntimeException::class ); }
		finally { $second->query( 'ROLLBACK' ); $second->close(); $db->execute( 'SET SESSION innodb_lock_wait_timeout=50' ); }
		qa_check( 1 === (int) $db->value( "SELECT COUNT(*) FROM $table" ) && 'Locked' === $db->value( "SELECT name FROM $table WHERE id=%d", array( $tag ) ), 'Lock timeout partially committed a transaction.' );
		$db->transaction( fn() => $db->updateById( 'tags', $tag, array( 'name' => 'Recovered' ) ) ); qa_check( 'Recovered' === $db->value( "SELECT name FROM $table WHERE id=%d", array( $tag ) ), 'Healthy rollback did not permit recovery.' );
		return array( 'lock_wait_timeout_seconds' => 1, 'rollback_confirmed' => true );
	} );
	qa_case( 'D09-real-disconnect-no-transaction-replay', function (): array {
		[ $db ] = qa_fixture(); $victim = qa_connection(); $killer = qa_connection(); $guarded = new Database( $victim, $db->prefix() ); $id = (int) $victim->get_var( 'SELECT CONNECTION_ID()' );
		$mode = ( new mysqli_driver() )->report_mode; mysqli_report( MYSQLI_REPORT_OFF );
		try {
			$error = qa_rejects( fn() => $guarded->transaction( function () use ( $guarded, $killer, $id ) {
				$guarded->insert( 'tags', array( 'name' => 'First', 'slug' => 'disconnect-first', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				$killer->query( 'KILL CONNECTION ' . $id );
				$guarded->insert( 'tags', array( 'name' => 'Second', 'slug' => 'disconnect-second', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			} ), RuntimeException::class );
			qa_check( 'Core database operation failed.' === $error->getMessage() && null === $error->getPrevious(), 'Native driver error escaped the safe boundary.' );
			qa_check( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'tags' ) ), 'Lost transaction was partially replayed.' );
			qa_rejects( fn() => $guarded->transaction( static fn() => null ), RuntimeException::class );
			qa_check( ! $victim->ready && MYSQLI_REPORT_OFF === ( new mysqli_driver() )->report_mode, 'Failed connection was not quarantined or driver mode leaked.' );
		} finally { $killer->close(); mysqli_report( $mode ); }
		return array( 'killed_connection' => $id, 'retained_rows' => 0, 'reused_connection' => false );
	} );
	qa_case( 'D10-real-ddl-disconnect-and-additive-recovery', function (): array {
		global $wpdb; [ $originalDb, , , , $option ] = qa_fixture( '0' ); $original = $wpdb; $victim = qa_connection(); $killer = qa_connection(); $id = (int) $victim->get_var( 'SELECT CONNECTION_ID()' );
		$db = new Database( $victim, $originalDb->prefix() ); $migrator = new Migrator( $db, $option ); $triggered = false;
		$watch = static function ( string $sql ) use ( $killer, $id, $db, &$triggered ): string { if ( ! $triggered && str_starts_with( $sql, 'CREATE TABLE ' . trim( $db->table( 'categories' ), '`' ) . ' ' ) ) { $triggered = true; $killer->query( 'KILL CONNECTION ' . $id ); } return $sql; };
		$wpdb = $victim; add_filter( 'query', $watch );
		try { $error = $migrator->migrate(); }
		finally { remove_filter( 'query', $watch ); $wpdb = $original; $killer->close(); $victim->close(); }
		wp_cache_delete( $option, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
		qa_check( $triggered && is_wp_error( $error ) && '0' === (string) get_option( $option, '0' ), 'Interrupted first DDL falsely advanced version.' );
		$partial = (int) $originalDb->value( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', array( $wpdb->esc_like( $originalDb->prefix() . 'aish_' ) . '%' ) );
		qa_check( $partial > 0 && $partial < 14, 'Interrupted DDL did not retain a partial additive state.' );
		$retry = new Migrator( $originalDb, $option ); qa_check( true === $retry->migrate(), 'Disconnected migration did not recover on fresh connection.' ); Schema::verify( $originalDb, Schema::VERSION );
		return array( 'partial_tables_retained' => $partial, 'recovered_tables' => 14 );
	} );
	qa_case( 'D11-theme-switch-fresh-request-data-retention', function (): array {
		$core = Core::boot(); $db = $core->get( 'database' ); $migrator = $core->migrator(); $catalog = $core->catalog();
		qa_check( $migrator->isCurrent(), 'Site schema is not current.' );
		$imports = new ImportStore( $db, $migrator ); $suffix = bin2hex( random_bytes( 4 ) ); $repo = $imports->saveRepository( null, 'https://github.com/qa-retention/skills-' . $suffix, false );
		$jobId = $imports->enqueue( $repo, 'scan' ); $job = $imports->claim(); qa_check( (int) $job['id'] === $jobId, 'Dedicated QA site has unexpected import jobs.' );
		$raw = "---\nname: retention\ndescription: QA retention source\n---\nRead documented input."; $sha = sha1( 'blob ' . strlen( $raw ) . "\0" . $raw );
		$record = $imports->record( $jobId, $job['lease_token'], 'retention/SKILL.md', $sha, str_repeat( 'c', 40 ), $raw, array( 'name' => 'retention', 'description' => 'QA retention source', 'metadata' => array(), 'warnings' => array() ), array( 'risk_level' => 'unknown' ), true );
		$imports->finish( $jobId, $job['lease_token'], array( 'stage' => 'qa_fixture', 'errors' => 1 ), 'incomplete' );
		$skill = $record['skill_id']; $catalog->saveTranslation( $skill, 'zh', '保留中文', 'retention-' . $suffix, '简介', '<p>正文</p>' ); $catalog->saveTranslation( $skill, 'en', 'Retained English', 'retention-' . $suffix, 'Summary', '<p>Body</p>' );
		$category = $catalog->createCategory( 'Retention', 'retention-' . $suffix ); $tag = $catalog->createTag( 'Retention', 'retention-' . $suffix ); $catalog->assignTerm( $skill, 'category', $category ); $catalog->assignTerm( $skill, 'tag', $tag );
		$ai = new GenerationStore( $db, $migrator ); $source = ( new SourceContext( $db, $migrator ) )->snapshot( $skill );
		$profile = array( 'id' => 'qa_retention', 'enabled' => true, 'provider' => 'openai', 'model' => 'fixture-no-api', 'base_url' => 'https://api.openai.com/v1', 'timeout' => 15, 'temperature' => null, 'max_output_tokens' => 256, 'token_parameter' => 'max_tokens', 'input_price' => null, 'output_price' => null, 'max_cost' => 0.0 );
		$generation = $ai->enqueue( $source, $profile, 'both', hash( 'sha256', 'retention-' . $suffix ), 'qa-no-api' ); $claimed = $ai->claim(); qa_check( (int) $claimed['id'] === $generation, 'Dedicated QA site has unexpected AI jobs.' );
		$attempt = $ai->beginAttempt( $generation, $claimed['lease_token'] ); $ai->completeAttempt( $generation, $claimed['lease_token'], $attempt['id'], array( 'fixture' => 'database-only; no provider called' ), array( 'en_US' => array( 'summary' => 'Private QA draft' ) ), array() ); $ai->saveReview( $generation, 1, array( 'en_US' => array( 'summary' => 'Private edited QA draft' ) ) );
		$before = qa_counts( $db ); qa_check( count( array_filter( $before, static fn( int $count ): bool => $count > 0 ) ) === 14, 'Retention fixtures did not populate all fourteen tables.' );
		$themes = array_filter( wp_get_themes(), static fn( $theme ) => 'ai-skills-hub' !== $theme->get_stylesheet() && ! $theme->errors() ); qa_check( ! empty( $themes ), 'Alternative theme unavailable.' ); $alternative = reset( $themes )->get_stylesheet();
		qa_processes( array( array( 'theme', $alternative ) ) );
		try { $off = qa_processes( array( array( 'inspect', implode( ',', array_keys( Schema::COLUMNS ) ) ) ) )[0]; qa_check( ! $off['core_loaded'] && $off['counts'] === $before, 'Theme deactivation deleted data or still loaded Core.' ); }
		finally { qa_processes( array( array( 'theme', 'ai-skills-hub' ) ) ); }
		$on = qa_processes( array( array( 'inspect', implode( ',', array_keys( Schema::COLUMNS ) ) ) ) )[0]; qa_check( $on['core_loaded'] && $on['counts'] === $before, 'Theme reactivation changed retained data.' );
		qa_check( true === $migrator->migrate() && '保留中文' === $catalog->getTranslation( $skill, 'zh' )['title'] && 'Retained English' === $catalog->getTranslation( $skill, 'en' )['title'], 'Reinstall changed stable bilingual content.' );
		return array( 'before_counts' => $before, 'after_counts' => $on['counts'], 'skill_id' => $skill, 'alternative_theme' => $alternative, 'api_calls' => 0, 'seeded_records_retained_in_disposable_site' => true );
	} );
} finally {
	if ( get_stylesheet() !== $original_theme ) { switch_theme( $original_theme ); }
	foreach ( $qa_fixtures as [ $prefix, $option ] ) {
		if ( ! preg_match( '/^aish_qd_[a-f0-9]{12}_$/D', $prefix ) ) { continue; }
		$db = new Database( $wpdb, $prefix );
		foreach ( array_reverse( array_keys( Schema::COLUMNS ) ) as $entity ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $db->table( $entity ) ); }
		delete_option( $option );
	}
	$qa_report['finished_at'] = gmdate( 'c' ); $qa_report['passed'] = count( array_filter( $qa_report['cases'], static fn( array $row ): bool => 'passed' === $row['status'] ) ); $qa_report['failed'] = count( $qa_report['cases'] ) - $qa_report['passed'];
	file_put_contents( $qa_directory . '/data-results.json', json_encode( $qa_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n" );
}
exit( $qa_report['failed'] ? 1 : 0 );
