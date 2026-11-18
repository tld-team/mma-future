<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Scout levels: plain arithmetic over a reader's XP, with no database access, so the same rules hold on every page.
 * XP = the points a reader has earned (never below zero) + tenure. Tenure helps loyal readers but cannot buy the top.
 */
final class Levels {
	/** Level => XP needed to reach it. */
	public const THRESHOLDS = array( 1 => 0, 2 => 25, 3 => 100, 4 => 300, 5 => 750, 6 => 2000, 7 => 5000 );
	public const NAMES      = array( 1 => 'Spectator', 2 => 'Spotter', 3 => 'Scout', 4 => 'Senior Scout', 5 => 'Head Scout', 6 => 'Matchmaker', 7 => 'Oracle' );

	public const TENURE_STEP   = 10;
	public const TENURE_MONTHS = 3;
	public const TENURE_MAX    = 80;

	/**
	 * Where this XP stands: the level, the next one, and how far along the way it is.
	 *
	 * @return array{level:int,name:string,xp:int,floor:int,next:?int,next_name:?string,to_next:int,progress:float,eighths:int}
	 */
	public static function for_xp( int $xp ): array {
		$xp    = max( 0, $xp );
		$level = 1;
		foreach ( self::THRESHOLDS as $candidate => $needed ) { if ( $xp >= $needed ) { $level = $candidate; } }
		$floor = self::THRESHOLDS[ $level ];
		$next  = self::THRESHOLDS[ $level + 1 ] ?? null;
		$share = null === $next ? 1.0 : ( $xp - $floor ) / ( $next - $floor );
		return array(
			'level'     => $level,
			'name'      => self::NAMES[ $level ],
			'xp'        => $xp,
			'floor'     => $floor,
			'next'      => $next,
			'next_name' => null === $next ? null : self::NAMES[ $level + 1 ],
			'to_next'   => null === $next ? 0 : $next - $xp,
			'progress'  => round( min( 1.0, max( 0.0, $share ) ), 4 ),
			// The badge's eight sides: each one is an eighth of the way to the next level; the top level is complete.
			'eighths'   => null === $next ? 8 : min( 7, (int) floor( $share * 8 ) ),
		);
	}

	/** +10 for every full three months since registration, at most 80. */
	public static function tenure( int $registered_at, int $now ): int {
		if ( $registered_at <= 0 || $now <= $registered_at ) { return 0; }
		$diff   = ( new \DateTimeImmutable( '@' . $registered_at ) )->diff( new \DateTimeImmutable( '@' . $now ) );
		$months = $diff->y * 12 + $diff->m;
		return min( self::TENURE_MAX, intdiv( $months, self::TENURE_MONTHS ) * self::TENURE_STEP );
	}

	/** steel for the first steps, the brand blue for the scouting ranks, gold for the last. */
	public static function tier( int $level ): string {
		if ( $level >= 7 ) { return 'gold'; }
		return $level >= 3 ? 'blue' : 'steel';
	}
}
