<?php
namespace MMAF\DataEngine\Services\Formula;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FormulaRegistry {
	public static function current_version(): string {
		return FormulaV19::VERSION;
	}

	public static function current_config(): array {
		return FormulaV19::config();
	}

	public static function config_for_version( string $formula_version ): array {
		switch ( $formula_version ) {
			case FormulaV19::VERSION:
				return FormulaV19::config();
			case FormulaV18::VERSION:
				return FormulaV18::config();
			case FormulaV17::VERSION:
				return FormulaV17::config();
			case FormulaV16::VERSION:
				return FormulaV16::config();
			case FormulaV13::VERSION:
				return FormulaV13::config();
			case FormulaV14::VERSION:
				return FormulaV14::config();
			case FormulaV15::VERSION:
				return FormulaV15::config();
			default:
				return self::current_config();
		}
	}

	public static function activation_supported_versions(): array {
		return array(
			FormulaV13::VERSION,
			FormulaV14::VERSION,
			FormulaV15::VERSION,
			FormulaV16::VERSION,
			FormulaV17::VERSION,
			FormulaV18::VERSION,
			FormulaV19::VERSION,
		);
	}

	public static function uses_normalized_scores( string $formula_version ): bool {
		return in_array( $formula_version, array( FormulaV13::VERSION, FormulaV14::VERSION ), true );
	}

	public static function uses_direct_scores( string $formula_version ): bool {
		return in_array($formula_version,[FormulaV15::VERSION,FormulaV16::VERSION,FormulaV17::VERSION,FormulaV18::VERSION,FormulaV19::VERSION],true);
	}

	/** Whole-career formulas share scope, evidence contract and frozen assessment replay. */
	public static function career_versions(): array {
		return array( FormulaV18::VERSION, FormulaV19::VERSION );
	}

	public static function is_career_version( ?string $formula_version ): bool {
		return in_array( (string) $formula_version, self::career_versions(), true );
	}

	/** Formulas whose public score is a frozen assessment with provisional known points (v1.7+). */
	public static function uses_known_points( ?string $formula_version ): bool {
		return FormulaV17::VERSION === $formula_version || self::is_career_version( $formula_version );
	}

	/** Formulas with frozen per-fighter assessments and a provisional view (v1.6+). */
	public static function has_assessments( ?string $formula_version ): bool {
		return FormulaV16::VERSION === $formula_version || self::uses_known_points( $formula_version );
	}

	public static function career_formula( string $formula_version ): FormulaV18 {
		if ( FormulaV19::VERSION === $formula_version ) {
			return new FormulaV19();
		}
		if ( FormulaV18::VERSION === $formula_version ) {
			return new FormulaV18();
		}
		throw new \InvalidArgumentException( 'Not a whole-career formula version: ' . $formula_version );
	}

	/** Points for one known KO/TKO or submission win; null when the version has no direct finish points. */
	public static function points_per_finish( ?string $formula_version ): ?float {
		if ( ! $formula_version || ! self::uses_direct_scores( $formula_version ) ) {
			return null;
		}
		$value = self::config_for_version( $formula_version )['finishes']['ko_tko'] ?? null;

		return is_numeric( $value ) ? (float) $value : null;
	}
}
