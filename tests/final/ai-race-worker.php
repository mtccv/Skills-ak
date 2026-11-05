<?php
declare(strict_types=1);
require __DIR__ . '/ai-common.php';

$action = $argv[1] ?? ''; $id = (int) ( $argv[2] ?? 0 ); $key = $argv[3] ?? ''; $start = (float) ( $argv[4] ?? 0 );
$killer = null; $connectionId = 0;
if ( 'disconnect' === $action ) {
	// Dedicated QA process only: prepare its own connection killer before the scoped lock.
	$connectionId = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	$killer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
}
if ( $start > microtime( true ) + 5 || $start < microtime( true ) - 30 ) { throw new RuntimeException( 'Bounded QA start barrier required.' ); }
while ( microtime( true ) < $start ) { usleep( 10000 ); }
$result = array( 'action' => $action, 'status' => 'ok' );
try {
	if ( 'enqueue' === $action ) { $result['job_id'] = $core->get( 'ai_generation' )->enqueue( $id, 'qa', 'both', $key ); }
	elseif ( 'tick' === $action ) {
		$mock->before = static fn() => usleep( 1500000 );
		$core->get( 'ai_scheduler' )->tick(); $result['provider_calls'] = count( $mock->calls );
	} elseif ( 'accept' === $action ) { $core->get( 'ai_store' )->accept( $id, 1, array( 'zh_CN.title', 'en_US.title' ) ); }
	elseif ( 'edit' === $action ) { $core->catalog()->saveTranslation( $id, 'zh_CN', '并发人工编辑标题', 'qa-ai-race-' . $id, '人工摘要', '<p>人工内容</p>' ); }
	elseif ( 'disconnect' === $action ) {
		$mock->before = static function () use ( $killer, $connectionId ): void {
			if ( $connectionId < 1 || false === $killer->query( 'KILL CONNECTION ' . $connectionId ) ) { throw new RuntimeException( 'QA connection fault could not be injected.' ); }
			get_option( 'qa_uncached_fault_' . bin2hex( random_bytes( 8 ) ) );
		};
		$core->get( 'ai_scheduler' )->tick(); $result['provider_calls'] = count( $mock->calls );
		// Do not reuse the disconnected WordPress/DAL session to inspect its outcome.
	}
	else { throw new RuntimeException( 'Unknown QA worker action.' ); }
} catch ( Throwable $error ) {
	$result['status'] = 'rejected'; $result['class'] = get_class( $error );
	$result['code'] = preg_match( '/^ai_[a-z_]+$/D', $error->getMessage() ) ? $error->getMessage() : 'controlled_failure';
}
echo json_encode( $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ), "\n";
