<?php
declare(strict_types=1);
require __DIR__ . '/ai-common.php';

use AISkillsHub\Core\AI\Settings;

$db = $core->get( 'database' ); $settings = $core->get( 'ai_settings' ); $store = $core->get( 'ai_store' ); $scheduler = $core->get( 'ai_scheduler' );
$profile = array_replace( Settings::defaults( 'qa' ), array( 'provider' => 'final_mock', 'model' => 'qa-synthetic', 'enabled' => true, 'input_price' => 1.0, 'output_price' => 2.0 ) );
$settings->save( 'qa', $profile ); $settings->setPaused( false ); $qa_cases = array(); $qa_assertions = 0; $qa_jobs = array();

function aishqa_race( array $tasks ): array {
	$start = microtime( true ) + 2; $children = array();
	foreach ( $tasks as $task ) {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/ai-race-worker.php', $task[0], (string) $task[1], $task[2] ?? '', (string) $start ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start QA child process.' ); }
		fclose( $pipes[0] ); $children[] = array( $process, $pipes );
	}
	$results = array();
	foreach ( $children as list( $process, $pipes ) ) {
		$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		if ( 0 !== $exit || '' !== trim( $err ) ) { throw new RuntimeException( 'QA child failed before controlled result; inspect isolated container output.' ); }
		$results[] = json_decode( trim( $out ), true, 16, JSON_THROW_ON_ERROR );
	}
	return $results;
}

foreach ( array( 'same', 'different' ) as $keys ) {
	aishqa_case( 'AI-CONCURRENT-ENQUEUE-' . $keys, function () use ( $core, $db, $keys ): void {
		$skill = aishqa_source( 'concurrent-' . $keys ); $key = bin2hex( random_bytes( 16 ) );
		$result = aishqa_race( array( array( 'enqueue', $skill, $key ), array( 'enqueue', $skill, 'same' === $keys ? $key : bin2hex( random_bytes( 16 ) ) ) ) );
		$rows = $db->rows( 'SELECT id,status FROM ' . $db->table( 'ai_jobs' ) . ' WHERE skill_id=%d', array( $skill ) );
		foreach ( $rows as $row ) { $GLOBALS['qa_jobs'][] = (int) $row['id']; }
		aishqa_assert( 1 === count( $rows ) && 'queued' === $rows[0]['status'], 'Two real PHP/MySQL sessions create one active Skill generation.' );
		aishqa_assert( count( array_filter( $result, static fn( array $r ): bool => 'ok' === $r['status'] ) ) >= 1, 'At least one concurrent enqueue succeeds.' );
		if ( 'same' === $keys ) { aishqa_assert( (int) $rows[0]['id'] === $core->get( 'ai_generation' )->enqueue( $skill, 'qa', 'both', $key ), 'Settled same-key retry returns the durable original ID.' ); }
		else { aishqa_assert( 1 === count( array_filter( $result, static fn( array $r ): bool => 'rejected' === $r['status'] ) ), 'Distinct concurrent request is explicitly rejected.' ); }
	} );
}

aishqa_case( 'AI-CONCURRENT-GLOBAL-WORKER', function () use ( $scheduler ): void {
	$first = aishqa_enqueue( aishqa_source( 'tick-one' ) ); $second = aishqa_enqueue( aishqa_source( 'tick-two' ) );
	$result = aishqa_race( array( array( 'tick', 0 ), array( 'tick', 0 ) ) );
	aishqa_assert( 1 === array_sum( array_column( $result, 'provider_calls' ) ), 'Two overlapping scheduler processes deliver only one Mock call while global lock is held.' );
	aishqa_assert( 'needs_review' === aishqa_row( 'ai_jobs', $first )['status'] && 'queued' === aishqa_row( 'ai_jobs', $second )['status'], 'Second task remains queued after busy global tick.' );
	$scheduler->tick(); aishqa_assert( 'needs_review' === aishqa_row( 'ai_jobs', $second )['status'], 'Next independent tick resumes remaining work.' );
} );

aishqa_case( 'AI-CONCURRENT-ADOPTION', function () use ( $store, $scheduler, $db, $core ): void {
	$skill = aishqa_source( 'double-adopt' ); $id = aishqa_enqueue( $skill ); $scheduler->tick();
	$result = aishqa_race( array( array( 'accept', $id ), array( 'accept', $id ) ) );
	aishqa_assert( 1 === count( array_filter( $result, static fn( array $r ): bool => 'ok' === $r['status'] ) ), 'Exactly one of two simultaneous human acceptance submissions succeeds.' );
	$job = $store->job( $id ); aishqa_assert( 'accepted' === $job['review_status'] && 1 === $job['reviews_total'], 'One accepted revision/event persists without duplicate history.' );
	aishqa_assert( 2 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skill_translations' ) . ' WHERE skill_id=%d', array( $skill ) ) && 'pending' === $core->catalog()->getSkill( $skill )['status'], 'Both language rows share original ID and remain pending.' );
} );

aishqa_case( 'AI-CONCURRENT-EDITOR-ADOPTION', function () use ( $scheduler, $core ): void {
	$skill = aishqa_source( 'editor-adopt' ); $id = aishqa_enqueue( $skill ); $scheduler->tick();
	$result = aishqa_race( array( array( 'accept', $id ), array( 'edit', $skill ) ) );
	aishqa_assert( 'ok' === $result[1]['status'], 'Human editor request completes during adoption race.' );
	$translation = $core->catalog()->getTranslation( $skill, 'zh_CN' );
	aishqa_assert( '并发人工编辑标题' === $translation['title'] && 'qa-ai-race-' . $skill === $translation['slug'], 'AI acceptance never overwrites the concurrent human edit or slug.' );
	aishqa_assert( in_array( $core->catalog()->getSkill( $skill )['status'], array( 'draft', 'pending' ), true ), 'Concurrent workflows cannot publish the fixture; an editor save may return it to draft.' );
} );

aishqa_case( 'AI-REAL-CONNECTION-LOSS-RECOVERY', function () use ( $db, $store, $scheduler, $mock ): void {
	$id = aishqa_enqueue( aishqa_source( 'connection-loss' ) );
	$result = aishqa_race( array( array( 'disconnect', $id ) ) ); $job = $store->job( $id );
	aishqa_assert( 'ok' === $result[0]['status'] && 1 === $result[0]['provider_calls'], 'Real database fault occurred inside one Mock Provider attempt.' );
	aishqa_assert( 'running' === $job['status'] && 'inflight' === $job['attempts_history'][0]['status'] && array() === $job['output'], 'Killed session cannot reconnect and falsely persist success or definitive failure.' );
	$db->updateById( 'ai_jobs', $id, array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
	aishqa_assert( null === $store->claim(), 'Fresh session recovers expired inflight without issuing a new request.' );
	$job = $store->job( $id );
	aishqa_assert( 'failed' === $job['status'] && 'uncertain' === $job['attempts_history'][0]['status'] && null === $job['cost_estimate'], 'Durable uncertain result and unknown costs survive actual connection loss.' );
	$before = count( $mock->calls ); $scheduler->tick(); aishqa_assert( $before === count( $mock->calls ), 'Uncertain recovered request is not automatically resent.' );
} );

$settings->setPaused( true );
echo json_encode( array( 'suite' => 'ai-concurrency', 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ), 'provider' => 'Mock Provider; real parallel PHP/MySQL sessions', 'assertions' => $qa_assertions, 'cases' => $qa_cases ), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), "\n";
exit( array_filter( $qa_cases, static fn( array $case ): bool => 'passed' !== $case['status'] ) ? 1 : 0 );
