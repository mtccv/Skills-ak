<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Discovery;

defined( 'ABSPATH' ) || exit;

/** Pure ranking policy: callers must supply eligible public facts, never request counters. */
final class RankingPolicy {
	public const VERSION = 'discovery-1';
	public const MIN_REVIEWS = 5;
	public const TREND_DAYS = 7;

	/** Seven completed UTC days, newest first; the current partial day is excluded. */
	public static function days( \DateTimeImmutable $now ): array {
		$today = $now->setTimezone( new \DateTimeZone( 'UTC' ) )->setTime( 0, 0 );
		$days = array();
		for ( $age = 0; $age < self::TREND_DAYS; ++$age ) {
			$days[] = $today->modify( '-' . ( $age + 1 ) . ' days' )->format( 'Y-m-d' );
		}
		return $days;
	}

	/** Day buckets contain deduplicated views, still-active first favorites and still-approved first reviews with their current rating points. */
	public static function trending( array $buckets, \DateTimeImmutable $now ): float {
		$score = 0.0;
		foreach ( self::days( $now ) as $age => $day ) {
			$bucket = $buckets[ $day ] ?? array();
			if ( ! is_array( $bucket ) ) { throw new \InvalidArgumentException( 'Invalid trend bucket.' ); }
			$counts = array();
			foreach ( array( 'views', 'favorites', 'reviews', 'rating_points' ) as $key ) {
				$value = $bucket[ $key ] ?? 0;
				if ( ! is_int( $value ) || $value < 0 ) { throw new \InvalidArgumentException( 'Invalid trend count.' ); }
				$counts[ $key ] = $value;
			}
			if ( $counts['reviews'] > intdiv( PHP_INT_MAX, 5 ) || $counts['rating_points'] < $counts['reviews'] || $counts['rating_points'] > 5 * $counts['reviews'] ) { throw new \InvalidArgumentException( 'Invalid trend rating evidence.' ); }
			$score += pow( 2, -$age / 3 ) * ( log1p( $counts['views'] ) + 3 * log1p( $counts['favorites'] ) + 2 * log1p( $counts['rating_points'] / 5 ) );
		}
		return $score;
	}

	/** No prior or synthetic votes; insufficient samples remain visible but unranked. */
	public static function ratings( array $distribution ): array {
		if ( array_diff( array_keys( $distribution ), range( 1, 5 ) ) ) { throw new \InvalidArgumentException( 'Invalid rating bucket.' ); }
		$count = 0; $sum = 0; $stars = array();
		foreach ( range( 1, 5 ) as $star ) {
			$value = $distribution[ $star ] ?? 0;
			if ( ! is_int( $value ) || $value < 0 || $value > intdiv( PHP_INT_MAX, 25 ) ) { throw new \InvalidArgumentException( 'Invalid rating count.' ); }
			$stars[ $star ] = $value; $count += $value; $sum += $star * $value;
		}
		return array( 'count' => $count, 'average' => $count ? (float) $sum / $count : null, 'distribution' => $stars, 'rankable' => $count >= self::MIN_REVIEWS );
	}
}
