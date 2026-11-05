<?php
namespace MMAF\DataEngine\Services\Scoring;

use MMAF\DataEngine\Services\Formula\FormulaV17;

/**
 * Builds the immutable v1.7 career/recent split from one accepted career
 * aggregate and the source-verified recent period.
 */
final class HybridAssessment {
	private PeriodAssessment $period;

	public function __construct() {
		$this->period = new PeriodAssessment();
	}

	public function evaluate( int $id, array $history, ?array $career, array $scoring_items, string $end, string $calculated_at ): array {
		$assessment = $this->period->evaluate( $id, $history, $career, $end, $calculated_at );
		$assessment['reasons'] = array_values( array_diff( (array) $assessment['reasons'], array( 'insufficient_sample_size' ) ) );
		$assessment['warnings'] = array_values( array_diff( (array) $assessment['warnings'], array( 'small_scoring_sample' ) ) );
		$assessment['methodology'] = FormulaV17::SCOPE;

		$period_record = $assessment['period_stats'] ?? PeriodAssessment::stats( $history, $end );
		$period_record = $this->record( $period_record );
		$career_record = $career && ( $career['status'] ?? '' ) === 'accepted' ? CareerRecords::counts( (array) ( $career['record'] ?? array() ) ) : null;
		$historical_record = null;

		if ( $career_record ) {
			$historical_record = array();
			foreach ( array( 'wins', 'losses', 'draws', 'nc' ) as $key ) {
				$historical_record[ $key ] = (int) $career_record[ $key ] - (int) $period_record[ $key ];
				if ( $historical_record[ $key ] < 0 ) {
					$assessment['reasons'][] = 'career_period_record_conflict';
				}
			}
			$historical_record['pro_fights_count'] = array_sum( array_intersect_key( $historical_record, array_flip( array( 'wins', 'losses', 'draws', 'nc' ) ) ) );
		}

		if ( ! empty( $career['observed_at'] ) && substr( (string) $career['observed_at'], 0, 10 ) > $end ) {
			$assessment['reasons'][] = 'career_snapshot_after_reference_date';
		}
		if ( ! empty( $assessment['verified_at'] ) && substr( (string) $assessment['verified_at'], 0, 10 ) > $end ) {
			$assessment['reasons'][] = 'period_snapshot_after_reference_date';
		}

		$source_inputs = $assessment['_source_inputs'] ?? array();
		$career_date = substr( (string) ( $career['observed_at'] ?? '' ), 0, 10 );
		$period_date = substr( (string) ( $assessment['verified_at'] ?? '' ), 0, 10 );
		$career_hash = (string) ( $career['profile_hash'] ?? '' );
		$period_hash = (string) ( $source_inputs['content_hash'] ?? '' );
		if ( $career_record && self::valid_date( $career_date ) && self::valid_date( $period_date ) ) {
			$automatic_hashes_match = 'tapology' === ( $career['source_type'] ?? '' ) && $career_hash && $period_hash && hash_equals( $career_hash, $period_hash );
			if ( ! $automatic_hashes_match && $career_date !== $period_date ) {
				$assessment['reasons'][] = 'career_period_snapshot_mismatch';
			} elseif ( 'tapology' === ( $career['source_type'] ?? '' ) && $career_hash && $period_hash && ! hash_equals( $career_hash, $period_hash ) ) {
				$assessment['reasons'][] = 'career_period_snapshot_mismatch';
			}
		}

		$known_historical = array( 'wins' => 0, 'losses' => 0, 'draws' => 0, 'nc' => 0 );
		foreach ( $history as $row ) {
			$date = $row['event_date'] ?? null;
			if ( ! PeriodAssessment::accepted( $row ) || ! PeriodAssessment::valid_date( $date ) || $date >= FormulaV17::PERIOD_START || $date > $end ) {
				continue;
			}
			$key = array( 'win' => 'wins', 'loss' => 'losses', 'draw' => 'draws', 'no_contest' => 'nc' )[ $row['result_for_fighter'] ];
			++$known_historical[ $key ];
		}
		$known_historical['pro_fights_count'] = array_sum( $known_historical );
		if ( is_array( $historical_record ) ) {
			foreach ( array( 'wins', 'losses', 'draws', 'nc' ) as $key ) {
				if ( $known_historical[ $key ] > $historical_record[ $key ] ) {
					$assessment['reasons'][] = 'historical_record_conflict';
				}
			}
		}

		$accepted_recent = array();
		foreach ( $history as $row ) {
			$date = $row['event_date'] ?? null;
			if ( ! PeriodAssessment::accepted( $row ) || ! PeriodAssessment::valid_date( $date ) || $date < FormulaV17::PERIOD_START || $date > $end ) {
				continue;
			}
			if ( in_array( $row['result_for_fighter'] ?? '', array( 'win', 'loss' ), true ) ) {
				$accepted_recent[ (int) $row['bout_id'] ] = (string) $row['result_for_fighter'];
			}
		}

		$scoring_by_bout = array();
		foreach ( $scoring_items as $item ) {
			$date = $item['event_date'] ?? null;
			if ( ! PeriodAssessment::valid_date( $date ) || $date < FormulaV17::PERIOD_START || $date > $end ) {
				continue;
			}
			$bout_id = (int) ( $item['bout_id'] ?? 0 );
			if ( ! $bout_id || isset( $scoring_by_bout[ $bout_id ] ) ) {
				$assessment['reasons'][] = 'period_scoring_mismatch';
				continue;
			}
			$scoring_by_bout[ $bout_id ] = $item;
		}

		$accepted_bout_ids = array_keys( $accepted_recent );
		$scoring_bout_ids = array_keys( $scoring_by_bout );
		sort( $accepted_bout_ids, SORT_NUMERIC );
		sort( $scoring_bout_ids, SORT_NUMERIC );
		if ( count( $accepted_recent ) !== count( $scoring_by_bout ) || $accepted_bout_ids !== $scoring_bout_ids ) {
			$assessment['reasons'][] = 'period_scoring_mismatch';
		}
		$source_methods = array();
		foreach ( (array) ( $source_inputs['period_source_rows'] ?? array() ) as $source_row ) {
			if ( ! empty( $source_row['source_bout_id'] ) ) {
				$source_methods[ (string) $source_row['source_bout_id'] ] = (string) ( $source_row['method_category'] ?? '' );
			}
		}

		foreach ( $accepted_recent as $bout_id => $result ) {
			$item = $scoring_by_bout[ $bout_id ] ?? null;
			if ( ! $item || ( $item['result_for_fighter'] ?? '' ) !== $result ) {
				$assessment['reasons'][] = 'period_scoring_mismatch';
				continue;
			}
			$opponent_diff = $this->opponent_diff( $item );
			if ( null === $opponent_diff ) {
				$assessment['reasons'][] = 'missing_recent_prefight_record';
			} elseif ( null !== ( $item['opponent_prefight_diff'] ?? null ) && (int) $item['opponent_prefight_diff'] !== $opponent_diff ) {
				$assessment['reasons'][] = 'recent_prefight_record_conflict';
			}
			$method = (string) ( $item['method_category'] ?? '' );
			if ( 'win' === $result && ( '' === $method || 'unknown' === $method ) ) {
				$assessment['reasons'][] = 'missing_recent_win_method';
			}
			$source_bout_id = (string) ( $item['source_bout_id'] ?? '' );
			if ( 'win' === $result && $source_bout_id && array_key_exists( $source_bout_id, $source_methods ) ) {
				$source_method = $source_methods[ $source_bout_id ];
				if ( '' === $source_method || 'unknown' === $source_method ) {
					$assessment['reasons'][] = 'missing_recent_win_method';
				} elseif ( '' !== $method && 'unknown' !== $method && $source_method !== $method ) {
					$assessment['reasons'][] = 'period_method_conflict';
				}
			}
		}

		$last_professional_fight_date = $period_record['last_fight_date'] ?? null;
		$source_last_professional_fight_date = $source_inputs['latest_confirmed_professional_fight_date'] ?? null;
		if ( PeriodAssessment::valid_date( $source_last_professional_fight_date ) && ( ! $last_professional_fight_date || $source_last_professional_fight_date > $last_professional_fight_date ) ) {
			$last_professional_fight_date = $source_last_professional_fight_date;
		}
		$boundary_date = $source_inputs['boundary_row']['fight_date'] ?? null;
		if ( PeriodAssessment::valid_date( $boundary_date ) && ( ! $last_professional_fight_date || $boundary_date > $last_professional_fight_date ) ) {
			$last_professional_fight_date = $boundary_date;
		}

		$assessment['period'] = array( 'start' => FormulaV17::PERIOD_START, 'end' => $end, 'inclusive' => true );
		$assessment['career_record_used'] = $career_record;
		$assessment['historical_record'] = $historical_record;
		$assessment['known_historical_record'] = $known_historical;
		$assessment['period_record'] = $period_record;
		$assessment['sample_size'] = count( $scoring_by_bout );
		$assessment['last_professional_fight_date'] = $last_professional_fight_date;
		$assessment['no_recent_fights'] = 0 === (int) $period_record['pro_fights_count'];
		$assessment['reasons'] = array_values( array_unique( $assessment['reasons'] ) );
		$assessment['warnings'] = array_values( array_unique( $assessment['warnings'] ) );
		$assessment['status'] = $assessment['reasons'] ? 'provisional' : 'main';
		$assessment['total_score'] = null;
		$assessment['known_points'] = null;
		$assessment['input_hash'] = hash(
			'sha256',
			wp_json_encode(
				array(
					'formula' => FormulaV17::VERSION,
					'formula_config' => FormulaV17::config(),
					'reference_date' => $end,
					'career_observation_id' => (int) ( $career['observation_id'] ?? 0 ),
					'career_record' => $career_record,
					'career_profile_hash' => $career_hash ?: null,
					'period_profile_hash' => $period_hash ?: null,
					'period_record' => $period_record,
					'scoring_items' => array_values( $scoring_by_bout ),
				)
			)
		);

		return $assessment;
	}

	public function save( int $run, int $id, array $assessment, array $inputs, string $created_at ): void {
		$this->period->save( $run, $id, $assessment, $inputs, $created_at );
	}

	private function record( array $stats ): array {
		$out = array();
		foreach ( array( 'wins', 'losses', 'draws', 'nc' ) as $key ) {
			$out[ $key ] = (int) ( $stats[ $key ] ?? 0 );
		}
		$out['pro_fights_count'] = array_sum( $out );
		$out['finish_wins'] = (int) ( $stats['finish_wins'] ?? 0 );
		$out['finish_rate'] = $out['wins'] > 0 ? $out['finish_wins'] / $out['wins'] : null;
		$out['last_fight_date'] = $stats['last_fight_date'] ?? null;

		return $out;
	}

	private function opponent_diff( array $item ): ?int {
		if ( null === ( $item['opponent_prefight_wins'] ?? null ) || null === ( $item['opponent_prefight_losses'] ?? null ) ) {
			return null;
		}

		return (int) $item['opponent_prefight_wins'] - (int) $item['opponent_prefight_losses'];
	}

	private static function valid_date( string $date ): bool {
		return PeriodAssessment::valid_date( $date );
	}
}
