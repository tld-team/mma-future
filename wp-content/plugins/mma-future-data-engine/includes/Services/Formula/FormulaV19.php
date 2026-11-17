<?php
namespace MMAF\DataEngine\Services\Formula;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whole professional MMA career formula, v1.9.
 *
 * Same evidence, scope and scoring structure as v1.8, with two changes:
 * a known KO/TKO or submission win adds 0.25 points, and a fighter with
 * fewer than five career wins may have at most one career loss.
 */
final class FormulaV19 extends FormulaV18 {
	public const VERSION = 'v1.9';
	public const FINISH_POINTS = 0.25;

	public static function config(): array {
		$config = parent::config();
		$config['formula_version'] = self::VERSION;
		$config['finishes']['ko_tko'] = self::FINISH_POINTS;
		$config['finishes']['submission'] = self::FINISH_POINTS;
		$config['eligibility']['loss_limit_by_wins'] = array( '20' => 5, '15' => 4, '10' => 3, '5' => 2, '0' => 1 );

		return $config;
	}
}
