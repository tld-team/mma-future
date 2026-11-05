<?php
namespace MMAF\DataEngine\Services\Formula;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hybrid career formula.
 *
 * Career W/L supplies the base score. Only accepted professional MMA results on
 * or after PERIOD_START receive finish and opponent-quality adjustments.
 */
final class FormulaV17 {
	public const VERSION = 'v1.7';
	public const PERIOD_START = '2026-05-09';
	public const SCOPE = 'professional_mma_hybrid_20260509_v1';

	public static function config(): array {
		return array(
			'formula_version' => self::VERSION,
			'eligibility' => array(
				'max_age' => 34,
				'inactivity_months' => 24,
				'max_pro_fights_count' => 30,
				'min_scoring_bouts' => 0,
				'min_career_win_loss_bouts' => 1,
				'required_is_rankable' => 1,
				'required_rankability' => 'rankable',
				'excluded_statuses' => array( 'deleted_soft', 'hidden', 'merged', 'retired' ),
				'loss_limit_by_wins' => array( '20' => 5, '15' => 4, '10' => 3, '5' => 2 ),
				'missing_birthdate_blocks' => true,
				'missing_last_fight_blocks' => true,
			),
			'base_record' => array( 'win' => 0.5, 'loss' => -1.5, 'draw' => 0.0, 'no_contest' => 0.0 ),
			'finishes' => array( 'ko_tko' => 1.0, 'submission' => 1.0, 'decision' => 0.0, 'dq' => 0.0, 'unknown' => 0.0 ),
			'age_adjustment' => array( 'under_25' => 1.0, '25_to_30' => 0.0, '31_plus' => -1.0 ),
			'opponent_differential_for_wins' => array(
				'od_lte_minus_10' => -2.0,
				'od_minus_9_to_minus_1' => 0.0,
				'od_0_to_10' => 1.0,
				'od_11_to_30' => 3.0,
				'od_31_to_60' => 5.0,
				'od_61_to_100' => 7.0,
				'od_gt_100' => 10.0,
				'missing' => 0.0,
			),
			'loss_quality_penalty' => array(
				'od_gt_0' => 0.0,
				'od_0_to_minus_10' => -2.0,
				'od_minus_11_to_minus_20' => -5.0,
				'od_lte_minus_21' => -10.0,
				'missing' => 0.0,
			),
			'scoring_scope' => array(
				'sport' => 'professional_mma',
				'strategy' => 'career_base_recent_quality',
				'start_date' => self::PERIOD_START,
				'end_date' => 'reference_date',
				'inclusive' => true,
				'career_eligibility' => true,
				'career_base_results' => array( 'win', 'loss' ),
				'recent_quality_results' => array( 'win', 'loss' ),
				'max_verification_age_days' => 7,
				'minimum_sample' => 'career_win_loss_bouts',
				'allow_zero_recent_win_loss_bouts' => true,
			),
			'tie_breakers' => array(
				'total_score_desc',
				'period_wins_desc',
				'period_finish_rate_desc',
				'age_asc',
				'last_professional_fight_date_desc',
				'fighter_id_asc',
			),
		);
	}

	public function historical_base_points( array $record ): float {
		$config = self::config()['base_record'];

		return round( (float) $config['win'] * (int) ( $record['wins'] ?? 0 ) + (float) $config['loss'] * (int) ( $record['losses'] ?? 0 ), 3 );
	}

	public function base_record_points( string $result ): float {
		return (float) ( self::config()['base_record'][ $result ] ?? 0.0 );
	}

	public function finish_points( string $result, ?string $method_category ): float {
		if ( 'win' !== $result ) {
			return 0.0;
		}

		return (float) ( self::config()['finishes'][ $method_category ?: 'unknown' ] ?? 0.0 );
	}

	public function age_adjustment_points( ?int $age ): float {
		if ( null === $age ) {
			return 0.0;
		}
		$config = self::config()['age_adjustment'];

		return (float) ( $age < 25 ? $config['under_25'] : ( $age <= 30 ? $config['25_to_30'] : $config['31_plus'] ) );
	}

	public function opponent_differential_points_for_win( ?int $diff ): float {
		$config = self::config()['opponent_differential_for_wins'];
		if ( null === $diff ) {
			return (float) $config['missing'];
		}
		if ( $diff <= -10 ) {
			return (float) $config['od_lte_minus_10'];
		}
		if ( $diff <= -1 ) {
			return (float) $config['od_minus_9_to_minus_1'];
		}
		if ( $diff <= 10 ) {
			return (float) $config['od_0_to_10'];
		}
		if ( $diff <= 30 ) {
			return (float) $config['od_11_to_30'];
		}
		if ( $diff <= 60 ) {
			return (float) $config['od_31_to_60'];
		}
		if ( $diff <= 100 ) {
			return (float) $config['od_61_to_100'];
		}

		return (float) $config['od_gt_100'];
	}

	public function loss_quality_penalty_points( ?int $diff ): float {
		$config = self::config()['loss_quality_penalty'];
		if ( null === $diff ) {
			return (float) $config['missing'];
		}
		if ( $diff > 0 ) {
			return (float) $config['od_gt_0'];
		}
		if ( $diff >= -10 ) {
			return (float) $config['od_0_to_minus_10'];
		}
		if ( $diff >= -20 ) {
			return (float) $config['od_minus_11_to_minus_20'];
		}

		return (float) $config['od_lte_minus_21'];
	}
}
