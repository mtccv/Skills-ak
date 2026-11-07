<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Discovery/RankingPolicy.php';
use AISkillsHub\Core\Discovery\RankingPolicy as Policy;
$count = 0;
function check( bool $ok ): void { global $count; if ( ! $ok ) { throw new RuntimeException( 'Ranking policy regression.' ); } ++$count; }
$now = new DateTimeImmutable( '2026-11-07T01:00:00+08:00' );
$days = Policy::days( $now );
check( 7 === count( $days ) && '2026-11-05' === $days[0] && '2026-10-30' === $days[6] );
check( 0.0 === Policy::trending( array(), $now ) );
check( 0.0 === Policy::trending( array( '2026-11-06' => array( 'views' => 1000 ) ), $now ) );
$recent = Policy::trending( array( $days[0] => array( 'views' => 5, 'favorites' => 2, 'reviews' => 1, 'rating_points' => 5 ) ), $now );
$old = Policy::trending( array( $days[3] => array( 'views' => 5, 'favorites' => 2, 'reviews' => 1, 'rating_points' => 5 ) ), $now );
check( abs( $recent / 2 - $old ) < 0.000001 );
check( null === Policy::ratings( array() )['average'] );
check( false === Policy::ratings( array( 5 => 1 ) )['rankable'] );
check( true === Policy::ratings( array( 5 => 5 ) )['rankable'] );
check( 3.0 === Policy::ratings( array( 1 => 2, 5 => 2 ) )['average'] );
foreach ( array( array( 0 => 2 ), array( 6 => 2 ), array( 5 => -1 ), array( 5 => '2' ) ) as $bad ) {
	try { Policy::ratings( $bad ); throw new RuntimeException( 'Accepted invalid distribution.' ); }
	catch ( InvalidArgumentException $e ) { check( true ); }
}
foreach ( array( -1, '2', 1.5 ) as $bad ) {
	try { Policy::trending( array( $days[0] => array( 'views' => $bad ) ), $now ); throw new RuntimeException( 'Accepted invalid count.' ); }
	catch ( InvalidArgumentException $e ) { check( true ); }
}
$low = Policy::trending( array( $days[0] => array( 'reviews' => 5, 'rating_points' => 5 ) ), $now );
$high = Policy::trending( array( $days[0] => array( 'reviews' => 5, 'rating_points' => 25 ) ), $now );
check( $high > $low && abs( $high - 2 * log( 6 ) ) < 0.000001 );
foreach ( array( array( 'reviews' => 1 ), array( 'reviews' => 2, 'rating_points' => 1 ), array( 'reviews' => 1, 'rating_points' => 6 ), array( 'reviews' => 0, 'rating_points' => 1 ), array( 'reviews' => 1, 'rating_points' => '5' ), array( 'reviews' => PHP_INT_MAX, 'rating_points' => PHP_INT_MAX ) ) as $bad ) {
	try { Policy::trending( array( $days[0] => $bad ), $now ); throw new RuntimeException( 'Accepted invalid review quality evidence.' ); }
	catch ( InvalidArgumentException $e ) { check( true ); }
}
echo "Discovery ranking: $count isolated assertions passed; no WordPress/MySQL/browser integration.\n";
