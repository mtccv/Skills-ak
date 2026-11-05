<?php
declare(strict_types=1);

// Real WordPress/MySQL Final QA subprocess. Never run against a persistent site.
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'AISH_FINAL_QA' ) ) { exit( 2 ); }
define( 'WP_CLI', true );
require getenv( 'AISH_QA_WP_LOAD' ) ?: '/var/www/html/wp-load.php';
if ( ! in_array( DB_NAME, array( 'qa_data', 'qa_php84', 'qa_wp68' ), true ) ) { throw new RuntimeException( 'Dedicated Final QA data database required.' ); }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
$mode = $argv[1] ?? '';
$result = array( 'mode' => $mode, 'pid' => getmypid(), 'status' => 'failed' );
try {
	if ( 'theme' === $mode ) {
		$theme = $argv[2] ?? '';
		if ( ! in_array( $theme, array_keys( wp_get_themes() ), true ) ) { throw new RuntimeException( 'Unknown QA theme.' ); }
		switch_theme( $theme ); $result['theme'] = get_stylesheet();
	} elseif ( 'inspect' === $mode ) {
		$entities = explode( ',', $argv[2] ?? '' ); $result['counts'] = array();
		foreach ( $entities as $entity ) {
			if ( ! preg_match( '/^[a-z_]+$/D', $entity ) ) { throw new RuntimeException( 'Invalid QA entity.' ); }
			$table = $wpdb->prefix . 'aish_' . $entity;
			$result['counts'][ $entity ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'QA table query failed.' ); }
		}
		$result['theme'] = get_stylesheet();
		$result['core_loaded'] = class_exists( '\\AISkillsHub\\Core\\Core', false );
	} else {
		$prefix = $argv[2] ?? '';
		if ( ! preg_match( '/^aish_qd_[a-f0-9]{12}_$/D', $prefix ) ) { throw new RuntimeException( 'Unsafe QA prefix.' ); }
		$db = new \AISkillsHub\Core\Database\Database( $wpdb, $prefix );
		$migrator = new \AISkillsHub\Core\Database\Migrator( $db, '_aish_qa_schema_' . $prefix );
		$catalog = new \AISkillsHub\Core\Content\Catalog( $db, $migrator, new \AISkillsHub\Core\Language\BuiltinLanguageProvider() );
		$management = new \AISkillsHub\Core\Admin\Management( $db, $migrator, $catalog );
		$barrier = $argv[5] ?? '';
		if ( ! preg_match( '#^/tmp/aish-final-qa/data-barrier-[a-f0-9]{16}$#D', $barrier ) ) { throw new RuntimeException( 'Unsafe QA barrier.' ); }
		file_put_contents( $barrier . '.ready.' . getmypid(), 'ready' );
		$deadline = microtime( true ) + 20;
		while ( ! is_file( $barrier ) ) { if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'QA barrier timeout.' ); } usleep( 20000 ); }
		if ( 'source' === $mode ) {
			$result['id'] = $catalog->createSkill( (int) $argv[3], $argv[4] );
		} elseif ( 'revision' === $mode ) {
			$result['id'] = $management->saveSkill( (int) $argv[3], array( 'revision' => (int) $argv[4], 'save_mode' => 'draft', 'translations' => array( 'en_US' => array( 'title' => 'Concurrent ' . getmypid(), 'slug' => 'concurrent', 'summary' => 'QA summary', 'content' => '<p>QA content</p>' ) ) ) );
		} else { throw new RuntimeException( 'Unknown QA worker.' ); }
	}
	$result['status'] = 'passed';
} catch ( DomainException $error ) { $result['status'] = 'conflict'; $result['error_class'] = get_class( $error ); }
catch ( Throwable $error ) { $result['error_class'] = get_class( $error ); $result['error'] = 'QA worker failed; inspect case evidence.'; }
echo json_encode( $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 'failed' === $result['status'] ? 1 : 0 );
