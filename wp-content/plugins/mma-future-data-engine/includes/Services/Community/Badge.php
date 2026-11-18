<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The level badge: an octagon whose eight sides light up, one per eighth of the way to the next level, around the
 * level number. Inline SVG, coloured by CSS custom properties so it follows the light and dark themes.
 */
final class Badge {
	private const CENTER = 24;
	private const RADIUS = 20;
	private const PLATE  = 16.5;

	/**
	 * @param array{level:int,name:string,eighths:int} $level From Levels::for_xp().
	 * @param string $size 'lg' on a profile, 'sm' beside a name.
	 */
	public static function svg( array $level, string $size = 'lg' ): string {
		$size   = 'sm' === $size ? 'sm' : 'lg';
		$pixels = 'sm' === $size ? 20 : 64;
		$number = max( 1, (int) $level['level'] );
		$lit    = max( 0, min( 8, (int) $level['eighths'] ) );
		$label  = 'Level ' . $number . ', ' . (string) $level['name'];
		$svg    = '<svg class="mmafc-badge mmafc-badge--' . $size . ' mmafc-badge--' . Levels::tier( $number ) . '" viewBox="0 0 48 48" width="' . $pixels . '" height="' . $pixels . '" role="img" aria-label="' . esc_attr( $label ) . '" focusable="false">';
		$svg   .= '<polygon class="mmafc-badge__plate" points="' . self::points( self::PLATE ) . '"/>';
		$corner = self::corners( self::RADIUS );
		for ( $side = 0; $side < 8; $side++ ) {
			list( $x1, $y1 ) = $corner[ $side ];
			list( $x2, $y2 ) = $corner[ ( $side + 1 ) % 8 ];
			$svg .= '<line class="mmafc-badge__side' . ( $side < $lit ? ' is-lit' : '' ) . '" x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '"/>';
		}
		$svg .= '<text class="mmafc-badge__number" x="24" y="24" text-anchor="middle" dominant-baseline="central">' . $number . '</text></svg>';
		return $svg;
	}

	/** Corners of a flat-topped octagon, clockwise from the top-left corner, so the first side lit is the top one. */
	private static function corners( float $radius ): array {
		$corners = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$angle     = deg2rad( -112.5 + 45 * $i );
			$corners[] = array( round( self::CENTER + $radius * cos( $angle ), 2 ), round( self::CENTER + $radius * sin( $angle ), 2 ) );
		}
		return $corners;
	}

	private static function points( float $radius ): string {
		return implode( ' ', array_map( static fn( array $corner ): string => $corner[0] . ',' . $corner[1], self::corners( $radius ) ) );
	}
}
