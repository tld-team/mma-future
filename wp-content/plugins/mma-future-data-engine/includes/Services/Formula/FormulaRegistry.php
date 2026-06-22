<?php
namespace MMAF\DataEngine\Services\Formula;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FormulaRegistry {
	public static function current_version(): string {
		return FormulaV15::VERSION;
	}

	public static function current_config(): array {
		return FormulaV15::config();
	}

	public static function config_for_version( string $formula_version ): array {
		switch ( $formula_version ) {
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
		);
	}

	public static function uses_normalized_scores( string $formula_version ): bool {
		return in_array( $formula_version, array( FormulaV13::VERSION, FormulaV14::VERSION ), true );
	}

	public static function uses_direct_scores( string $formula_version ): bool {
		return FormulaV15::VERSION === $formula_version;
	}
}
