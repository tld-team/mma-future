<?php
namespace MMAF\DataEngine\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a source weight class label into a storable canonical weight class.
 *
 * The source label alone does not say whether a division is a men's or a women's one
 * (Tapology lists men at Strawweight too), so the fighter's gender decides.
 */
final class WeightClassResolver {
	private const MALE_CLASSES = array(
		'flyweight',
		'bantamweight',
		'featherweight',
		'lightweight',
		'welterweight',
		'middleweight',
		'light_heavyweight',
		'heavyweight',
	);

	private const FEMALE_BASE_CLASSES = array(
		'strawweight',
		'flyweight',
		'bantamweight',
		'featherweight',
	);

	private const LABEL_ALIASES = array(
		'straw'       => 'strawweight',
		'fly'         => 'flyweight',
		'bantam'      => 'bantamweight',
		'feather'     => 'featherweight',
		'light'       => 'lightweight',
		'welter'      => 'welterweight',
		'middle'      => 'middleweight',
		'light_heavy' => 'light_heavyweight',
		'heavy'       => 'heavyweight',
	);

	/** Lowercase snake_case label; "Women's Flyweight" becomes "women_flyweight". */
	public static function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = str_replace( array( "women's", 'women’s', 'womens' ), 'women', $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		$value = trim( (string) $value, '_' );

		$is_women = 0 === strpos( $value, 'women_' );
		$base     = $is_women ? substr( $value, 6 ) : $value;
		if ( isset( self::LABEL_ALIASES[ $base ] ) ) {
			$base = self::LABEL_ALIASES[ $base ];
		}

		return $is_women ? 'women_' . $base : $base;
	}

	/**
	 * @param string|null $raw    Source label, or an already canonical value.
	 * @param string|null $gender 'male', 'female', or anything else for unknown.
	 * @return string|null Canonical storable weight class, or null when it cannot be decided.
	 */
	public static function canonical( ?string $raw, ?string $gender ): ?string {
		$normalized = self::normalize( (string) $raw );
		if ( '' === $normalized || 'unknown' === $normalized ) {
			return null;
		}

		$labelled_women = 0 === strpos( $normalized, 'women_' );
		$base           = $labelled_women ? substr( $normalized, 6 ) : $normalized;

		if ( 'female' === $gender ) {
			return in_array( $base, self::FEMALE_BASE_CLASSES, true ) ? 'women_' . $base : null;
		}

		if ( 'male' === $gender ) {
			if ( $labelled_women ) {
				return null;
			}

			// Men listed at Strawweight are ranked with the flyweights; there is no men's strawweight board.
			if ( 'strawweight' === $base ) {
				return 'flyweight';
			}

			return in_array( $base, self::MALE_CLASSES, true ) ? $base : null;
		}

		if ( $labelled_women ) {
			return in_array( $base, self::FEMALE_BASE_CLASSES, true ) ? 'women_' . $base : null;
		}

		// A bare Strawweight is a women's division in most promotions but not all, so it stays undecided.
		return in_array( $base, self::MALE_CLASSES, true ) ? $base : null;
	}

	/** The division a stored weight class becomes once the fighter's gender is known; null when there is none. */
	public static function realign( string $canonical, ?string $gender ): ?string {
		$normalized = self::normalize( $canonical );
		$base       = 0 === strpos( $normalized, 'women_' ) ? substr( $normalized, 6 ) : $normalized;

		return self::canonical( $base, $gender );
	}

	public static function is_compatible( ?string $gender, string $canonical ): bool {
		$canonical = self::normalize( $canonical );
		if ( '' === $canonical || 'unknown' === $canonical ) {
			return true;
		}

		$is_women = 0 === strpos( $canonical, 'women_' );
		if ( 'female' === $gender ) {
			return $is_women;
		}
		if ( 'male' === $gender ) {
			return ! $is_women;
		}

		return true;
	}
}
