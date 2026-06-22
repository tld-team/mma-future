<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Services\Formula\FormulaRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EligibilityService {
	public function evaluate( array $fighter, ?array $stats, string $reference_date ): array {
		$reasons  = array();
		$warnings = array();
		$gates    = array();
		$config   = FormulaRegistry::current_config();
		$rules    = $config['eligibility'];

		$this->gate(
			$gates,
			'deleted_soft',
			0 === (int) ( $fighter['deleted_soft'] ?? 0 ),
			'deleted_soft',
			$reasons
		);

		$this->gate(
			$gates,
			'is_rankable',
			1 === (int) ( $fighter['is_rankable'] ?? 0 ),
			'not_rankable_flag',
			$reasons
		);

		$status = (string) ( $fighter['status'] ?? '' );
		$this->gate(
			$gates,
			'status',
			! in_array( $status, $rules['excluded_statuses'], true ),
			'ineligible_status_' . ( $status ? $status : 'missing' ),
			$reasons,
			array( 'value' => $status )
		);

		$rankability_status = (string) ( $fighter['rankability_status'] ?? '' );
		$this->gate(
			$gates,
			'rankability_status',
			'rankable' === $rankability_status,
			'rankability_status_not_rankable',
			$reasons,
			array( 'value' => $rankability_status )
		);

		if ( 1 === (int) ( $fighter['is_rankable'] ?? 0 ) && 'rankable' !== $rankability_status ) {
			$warnings[] = 'inconsistent_rankability_status_vs_is_rankable';
		}

		$marked_rankable = 1 === (int) ( $fighter['is_rankable'] ?? 0 ) || 'rankable' === $rankability_status;
		if ( $marked_rankable && empty( $fighter['gender'] ) ) {
			$warnings[] = 'rankable_missing_gender';
		}

		if ( $marked_rankable && ( empty( $fighter['weight_class'] ) || 'unknown' === (string) $fighter['weight_class'] ) ) {
			$warnings[] = 'rankable_missing_weight_class';
		}

		$has_valid_tapology_source = 1 === (int) ( $fighter['has_valid_tapology_source'] ?? 0 );
		$tapology_source_count = (int) ( $fighter['tapology_source_count'] ?? 0 );
		$malformed_tapology_source_count = (int) ( $fighter['malformed_tapology_source_count'] ?? 0 );
		if ( $marked_rankable && ! $has_valid_tapology_source ) {
			$warnings[] = $tapology_source_count > 0 ? 'invalid_tapology_identity_mapping' : 'missing_tapology_identity_mapping';
		}

		$this->gate(
			$gates,
			'tapology_identity',
			$has_valid_tapology_source,
			$tapology_source_count > 0 ? 'invalid_tapology_identity_mapping' : 'missing_tapology_identity_mapping',
			$reasons,
			array(
				'has_valid_tapology_source'        => $has_valid_tapology_source,
				'tapology_source_count'            => $tapology_source_count,
				'malformed_tapology_source_count'  => $malformed_tapology_source_count,
				'override_supported'               => false,
			)
		);

		$age_info = $this->age( $fighter, $reference_date );
		$warnings = array_merge( $warnings, $age_info['warnings'] );
		$this->gate(
			$gates,
			'age',
			null !== $age_info['age'] && $age_info['age'] <= (int) $rules['max_age'],
			null === $age_info['age'] ? 'insufficient_data_missing_birthdate' : 'ineligible_age_35_plus',
			$reasons,
			array(
				'age'        => $age_info['age'],
				'method'     => $age_info['method'],
				'max_age'    => (int) $rules['max_age'],
			)
		);

		$last_fight_date    = is_array( $stats ) ? ( $stats['last_fight_date'] ?? null ) : null;
		$inactivity_months = null;
		if ( ! $last_fight_date ) {
			$warnings[] = 'missing_last_fight_date';
		} else {
			$inactivity_months = $this->months_between( (string) $last_fight_date, $reference_date );
		}

		$is_inactive = $last_fight_date ? $this->is_inactive( (string) $last_fight_date, $reference_date, (int) $rules['inactivity_months'] ) : null;

		$this->gate(
			$gates,
			'inactivity',
			null !== $is_inactive && ! $is_inactive,
			null === $inactivity_months ? 'insufficient_data_missing_last_fight_date' : 'ineligible_inactive_24_months',
			$reasons,
			array(
				'last_fight_date'    => $last_fight_date,
				'inactivity_months'  => $inactivity_months,
				'max_months'         => (int) $rules['inactivity_months'],
			)
		);

		$in_ufc = (int) ( $fighter['in_ufc'] ?? 0 );
		$this->gate(
			$gates,
			'ufc',
			0 === $in_ufc,
			'ineligible_ufc',
			$reasons,
			array( 'in_ufc' => $in_ufc )
		);

		$wins             = is_array( $stats ) ? (int) ( $stats['wins'] ?? 0 ) : 0;
		$losses           = is_array( $stats ) ? (int) ( $stats['losses'] ?? 0 ) : 0;
		$pro_fights_count = is_array( $stats ) ? (int) ( $stats['pro_fights_count'] ?? 0 ) : 0;
		$scoring_bouts_count = $wins + $losses;

		if ( ! is_array( $stats ) ) {
			$warnings[] = 'missing_stats_row';
		}

		$this->gate(
			$gates,
			'max_pro_fights',
			$pro_fights_count <= (int) $rules['max_pro_fights_count'],
			'ineligible_too_many_fights',
			$reasons,
			array(
				'pro_fights_count' => $pro_fights_count,
				'max'              => (int) $rules['max_pro_fights_count'],
			)
		);

		$this->gate(
			$gates,
			'min_scoring_bouts',
			$scoring_bouts_count >= (int) $rules['min_scoring_bouts'],
			'insufficient_sample_size',
			$reasons,
			array(
				'scoring_bouts_count' => $scoring_bouts_count,
				'min'                 => (int) $rules['min_scoring_bouts'],
			)
		);

		$loss_limit = $this->loss_limit( $wins );
		$passes_loss_limit = null === $loss_limit || $losses <= $loss_limit;
		$this->gate(
			$gates,
			'loss_limit',
			$passes_loss_limit,
			'ineligible_loss_limit',
			$reasons,
			array(
				'wins'           => $wins,
				'losses'         => $losses,
				'allowed_losses' => $loss_limit,
			)
		);

		$eligible_for_current_ranking = empty( $reasons );
		$calculation_blocking_reasons = array_values( array_diff( $reasons, array( 'insufficient_sample_size' ) ) );
		$eligible_for_calculation     = empty( $calculation_blocking_reasons );

		return array(
			'eligible'          => $eligible_for_current_ranking,
			'eligible_for_calculation' => $eligible_for_calculation,
			'eligible_for_current_ranking' => $eligible_for_current_ranking,
			'reasons'           => array_values( array_unique( $reasons ) ),
			'calculation_blocking_reasons' => array_values( array_unique( $calculation_blocking_reasons ) ),
			'gates'             => $gates,
			'reference_date'    => $reference_date,
			'age'               => $age_info['age'],
			'age_source'        => $age_info['method'],
			'inactivity_months' => $inactivity_months,
			'loss_limit'        => array(
				'wins'           => $wins,
				'losses'         => $losses,
				'allowed_losses' => $loss_limit,
				'passes'         => $passes_loss_limit,
			),
			'pro_fights_count'  => $pro_fights_count,
			'scoring_bouts_count' => $scoring_bouts_count,
			'in_ufc'            => $in_ufc,
			'source_flags'      => array(
				'is_rankable'        => (int) ( $fighter['is_rankable'] ?? 0 ),
				'deleted_soft'       => (int) ( $fighter['deleted_soft'] ?? 0 ),
				'status'             => $status,
				'rankability_status' => $rankability_status,
				'has_valid_tapology_source' => $has_valid_tapology_source ? 1 : 0,
				'tapology_source_count' => $tapology_source_count,
				'malformed_tapology_source_count' => $malformed_tapology_source_count,
			),
			'warnings'          => array_values( array_unique( $warnings ) ),
		);
	}

	private function gate( array &$gates, string $key, bool $passes, string $reason, array &$reasons, array $extra = array() ): void {
		$gates[ $key ] = array_merge(
			array(
				'passes' => $passes,
				'reason' => $passes ? null : $reason,
			),
			$extra
		);

		if ( ! $passes ) {
			$reasons[] = $reason;
		}
	}

	private function age( array $fighter, string $reference_date ): array {
		$warnings = array();
		$dob      = (string) ( $fighter['date_of_birth'] ?? '' );

		if ( '' !== $dob && '0000-00-00' !== $dob ) {
			try {
				$birth = new \DateTimeImmutable( $dob );
				$ref   = new \DateTimeImmutable( $reference_date );

				return array(
					'age'      => (int) $birth->diff( $ref )->y,
					'method'   => 'date_of_birth',
					'warnings' => $warnings,
				);
			} catch ( \Throwable $error ) {
				$warnings[] = 'invalid_date_of_birth';
			}
		}

		$birth_year = (int) ( $fighter['birth_year'] ?? 0 );
		if ( $birth_year > 0 ) {
			$warnings[] = 'birth_year_only_age_estimate';
			$ref_year   = (int) substr( $reference_date, 0, 4 );

			return array(
				'age'      => max( 0, $ref_year - $birth_year ),
				'method'   => 'birth_year_estimate',
				'warnings' => $warnings,
			);
		}

		$warnings[] = 'missing_date_of_birth';

		return array(
			'age'      => null,
			'method'   => null,
			'warnings' => $warnings,
		);
	}

	private function months_between( string $from, string $to ): ?int {
		try {
			$start = new \DateTimeImmutable( $from );
			$end   = new \DateTimeImmutable( $to );
			$diff  = $start->diff( $end );

			return (int) $diff->y * 12 + (int) $diff->m + ( $diff->d > 0 ? 1 : 0 );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	private function is_inactive( string $last_fight_date, string $reference_date, int $threshold_months ): ?bool {
		try {
			$last_fight = new \DateTimeImmutable( $last_fight_date );
			$reference  = new \DateTimeImmutable( $reference_date );
			$cutoff     = $last_fight->modify( '+' . $threshold_months . ' months' );

			return $reference >= $cutoff;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	private function loss_limit( int $wins ): ?int {
		if ( $wins >= 20 ) {
			return 5;
		}

		if ( $wins >= 15 ) {
			return 4;
		}

		if ( $wins >= 10 ) {
			return 3;
		}

		if ( $wins >= 5 ) {
			return 2;
		}

		return null;
	}
}
