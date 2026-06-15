<?php
namespace MMAF\DataEngine\Services\Formula;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FormulaV12 {
	public const VERSION = 'v1.2';

	public static function config(): array {
		return array(
			'formula_version' => self::VERSION,
			'eligibility'     => array(
				'max_age'                  => 34,
				'inactivity_months'        => 24,
				'max_pro_fights_count'     => 30,
				'required_is_rankable'     => 1,
				'required_rankability'     => 'rankable',
				'excluded_statuses'        => array( 'deleted_soft', 'hidden', 'merged', 'retired' ),
				'loss_limit_by_wins'       => array(
					'20' => 5,
					'15' => 4,
					'10' => 3,
					'5'  => 1,
				),
				'missing_birthdate_blocks' => true,
				'missing_last_fight_blocks'=> true,
			),
			'base_record'    => array(
				'win'        => 0.5,
				'loss'       => -1.5,
				'draw'       => 0.0,
				'no_contest' => 0.0,
			),
			'finishes'       => array(
				'ko_tko'     => 1.0,
				'submission' => 1.0,
				'decision'   => 0.0,
				'dq'         => 0.0,
				'unknown'    => 0.0,
			),
			'age_adjustment' => array(
				'under_25' => 1.0,
				'25_to_29' => 0.0,
				'30_plus'  => -1.0,
			),
			'opponent_differential_for_wins' => array(
				'od_lte_minus_10' => -2.0,
				'od_minus_9_to_minus_1' => 0.0,
				'od_0_to_10'      => 1.0,
				'od_11_to_30'     => 3.0,
				'od_31_to_60'     => 5.0,
				'od_61_to_100'    => 7.0,
				'od_gt_100'       => 10.0,
				'missing'         => 0.0,
			),
			'loss_quality_penalty' => array(
				'od_gt_0'       => 0.0,
				'od_0_to_minus_10' => -2.0,
				'od_minus_11_to_minus_20' => -5.0,
				'od_lte_minus_21' => -10.0,
				'missing'       => 0.0,
			),
			'tie_breakers'   => array(
				'wins_desc',
				'finish_rate_desc',
				'age_asc',
				'last_fight_date_desc',
				'fighter_id_asc',
			),
		);
	}

	public function base_record_points( string $result ): float {
		$config = self::config();

		return (float) ( $config['base_record'][ $result ] ?? 0.0 );
	}

	public function finish_points( string $result, ?string $method_category ): float {
		if ( 'win' !== $result ) {
			return 0.0;
		}

		$method = $method_category ? $method_category : 'unknown';
		$config = self::config();

		return (float) ( $config['finishes'][ $method ] ?? 0.0 );
	}

	public function age_adjustment_points( ?int $age ): float {
		if ( null === $age ) {
			return 0.0;
		}

		if ( $age < 25 ) {
			return 1.0;
		}

		if ( $age < 30 ) {
			return 0.0;
		}

		return -1.0;
	}

	public function opponent_differential_points_for_win( ?int $opponent_prefight_diff ): float {
		if ( null === $opponent_prefight_diff ) {
			return 0.0;
		}

		if ( $opponent_prefight_diff <= -10 ) {
			return -2.0;
		}

		if ( $opponent_prefight_diff <= -1 ) {
			return 0.0;
		}

		if ( $opponent_prefight_diff <= 10 ) {
			return 1.0;
		}

		if ( $opponent_prefight_diff <= 30 ) {
			return 3.0;
		}

		if ( $opponent_prefight_diff <= 60 ) {
			return 5.0;
		}

		if ( $opponent_prefight_diff <= 100 ) {
			return 7.0;
		}

		return 10.0;
	}

	public function loss_quality_penalty_points( ?int $opponent_prefight_diff ): float {
		if ( null === $opponent_prefight_diff ) {
			return 0.0;
		}

		if ( $opponent_prefight_diff > 0 ) {
			return 0.0;
		}

		if ( $opponent_prefight_diff >= -10 ) {
			return -2.0;
		}

		if ( $opponent_prefight_diff >= -20 ) {
			return -5.0;
		}

		return -10.0;
	}
}
