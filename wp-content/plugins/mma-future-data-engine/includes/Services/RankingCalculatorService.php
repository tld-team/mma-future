<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\RankingCurrentRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Services\Formula\FormulaRegistry;
use MMAF\DataEngine\Services\Formula\FormulaV15;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankingCalculatorService {
	private const VALID_BOUT_STATUSES = array( 'valid', 'completed' );
	private const BOARD_KEYS = array(
		'overall',
		'male',
		'female',
		'under_30',
		'over_30_to_34',
		'flyweight',
		'bantamweight',
		'featherweight',
		'lightweight',
		'welterweight',
		'middleweight',
		'light_heavyweight',
		'heavyweight',
		'women_strawweight',
		'women_flyweight',
		'women_bantamweight',
		'women_featherweight',
	);
	private const WEIGHT_BOARD_KEYS = array(
		'flyweight',
		'bantamweight',
		'featherweight',
		'lightweight',
		'welterweight',
		'middleweight',
		'light_heavyweight',
		'heavyweight',
		'women_strawweight',
		'women_flyweight',
		'women_bantamweight',
		'women_featherweight',
	);

	private RankingRunRepository $runs;
	private RankingCurrentRepository $rankings;
	private EligibilityService $eligibility;
	private FormulaV15 $formula;
	private AuditLogService $audit_log;

	public function __construct() {
		$this->runs       = new RankingRunRepository();
		$this->rankings   = new RankingCurrentRepository();
		$this->eligibility = new EligibilityService();
		$this->formula    = new FormulaV15();
		$this->audit_log  = new AuditLogService();
	}

	public function calculate_draft( int $actor_user_id = 0, ?string $reference_date = null ): array {
		$reference_date = $reference_date ? $reference_date : current_time( 'Y-m-d' );
		$calculated_at  = DateTime::mysql_now();
		$config         = FormulaRegistry::current_config();
		$previous       = $this->runs->get_last_calculation_summary();
		$run_id         = $this->runs->create(
			FormulaRegistry::current_version(),
			$config,
			$reference_date,
			$calculated_at,
			'draft',
			'Manual draft ranking calculation'
		);

		try {
			$result = $this->build_result( $run_id, $reference_date, $calculated_at );
			$this->rankings->insert_draft_snapshots( $run_id, $result['rows'] );

			$summary = $result['summary'];
			$summary['ranked_rows'] = count( $result['rows'] );
			$summary['status']      = 'completed';

			$this->runs->update(
				$run_id,
				array(
					'status' => 'completed',
					'notes'  => wp_json_encode( $summary ),
				)
			);
			$this->runs->set_last_calculation_summary( $summary );
			$this->audit_log->write(
				'ranking_calculated',
				'system',
				$run_id,
				$previous,
				$summary,
				'Manual ranking calculation',
				$actor_user_id
			);

			return $summary;
		} catch ( \Throwable $error ) {
			$summary = array(
				'ranking_run_id'      => $run_id,
				'calculated_at'       => $calculated_at,
				'formula_version'     => FormulaRegistry::current_version(),
				'reference_date'      => $reference_date,
				'status'              => 'failed',
				'eligible_fighters'   => 0,
				'ineligible_fighters' => 0,
				'ranked_rows'         => 0,
				'boards_generated'    => array(),
				'warnings_count'      => 0,
				'failed_reason'       => $error->getMessage(),
				'storage_strategy'    => $this->storage_strategy(),
			);

			$this->runs->update(
				$run_id,
				array(
					'status' => 'failed',
					'notes'  => wp_json_encode( $summary ),
				)
			);
			$this->runs->set_last_calculation_summary( $summary );

			throw $error;
		}
	}

	private function build_result( int $run_id, string $reference_date, string $calculated_at ): array {
		$fighters             = $this->runs->list_fighters_with_stats();
		$source_types         = $this->runs->source_types_by_fighter();
		$scoring_items        = $this->scoring_items_by_fighter();
		$ranked              = array();
		$eligible_count      = 0;
		$ineligible_count    = 0;
		$calculated_count    = 0;
		$excluded_insufficient_sample = 0;
		$min_scoring_bouts   = (int) ( FormulaRegistry::current_config()['eligibility']['min_scoring_bouts'] ?? 1 );
		$total_missing_prefight = 0;
		$total_warnings      = 0;
		$structural_warnings = array();
		$eligibility_reasons = array();

		foreach ( $fighters as $fighter ) {
			$fighter_id = (int) $fighter['id'];
			$stats      = $this->stats_from_row( $fighter );
			$evaluation = $this->eligibility->evaluate( $fighter, $stats, $reference_date );
			$warnings   = $evaluation['warnings'];

			foreach ( $evaluation['reasons'] as $reason ) {
				if ( ! isset( $eligibility_reasons[ $reason ] ) ) {
					$eligibility_reasons[ $reason ] = 0;
				}
				++$eligibility_reasons[ $reason ];
			}

			if ( empty( $evaluation['eligible_for_calculation'] ) ) {
				++$ineligible_count;
				$total_warnings += count( $warnings );
				continue;
			}

			$calculation = $this->score_fighter( $fighter, $stats, $evaluation, $scoring_items[ $fighter_id ] ?? array(), $source_types[ $fighter_id ] ?? array(), $calculated_at );
			$warnings    = array_values( array_unique( array_merge( $warnings, $calculation['warnings'] ) ) );
			$total_warnings += count( $warnings );
			++$calculated_count;
			$total_missing_prefight += (int) $calculation['source_summary']['prefight_records_missing_count'];
			$actual_sample_size = (int) $calculation['breakdown']['sample_size'];

			if ( $actual_sample_size < $min_scoring_bouts ) {
				++$ineligible_count;
				++$excluded_insufficient_sample;
				if ( ! isset( $eligibility_reasons['insufficient_sample_size'] ) ) {
					$eligibility_reasons['insufficient_sample_size'] = 0;
				}
				++$eligibility_reasons['insufficient_sample_size'];
				continue;
			}

			if ( empty( $evaluation['eligible_for_current_ranking'] ) ) {
				++$ineligible_count;
				if ( in_array( 'insufficient_sample_size', (array) $evaluation['reasons'], true ) ) {
					++$excluded_insufficient_sample;
				}
				continue;
			}

			++$eligible_count;

			$ranked[] = array(
				'fighter'      => $fighter,
				'stats'        => $stats,
				'total_score'  => $calculation['breakdown']['total_score'],
				'raw_score'    => $calculation['breakdown']['raw_score'],
				'normalized_score' => null,
				'confidence_score' => null,
				'sample_size'  => $calculation['breakdown']['sample_size'],
				'quality_flags'=> $calculation['quality_flags'],
				'breakdown'    => $calculation['breakdown'],
				'eligibility'  => $evaluation,
				'warnings'     => $warnings,
				'source_summary' => $calculation['source_summary'],
			);
		}

		$this->sort_ranked( $ranked );

		$rows = $this->rows_for_boards( $ranked );

		$summary = array(
			'ranking_run_id'      => $run_id,
			'calculated_at'       => $calculated_at,
			'formula_version'     => FormulaRegistry::current_version(),
			'reference_date'      => $reference_date,
			'status'              => 'draft',
			'is_active'           => 0,
			'eligible_fighters'   => $eligible_count,
			'ineligible_fighters' => $ineligible_count,
			'calculated_fighters' => $calculated_count,
			'trusted_ranked_fighters' => $eligible_count,
			'excluded_insufficient_sample' => $excluded_insufficient_sample,
			'missing_prefight_count' => $total_missing_prefight,
			'ranked_rows'         => 0,
			'boards_generated'    => self::BOARD_KEYS,
			'warnings_count'      => $total_warnings,
			'eligibility_reasons' => $eligibility_reasons,
			'structural_warnings' => array_filter( $structural_warnings ),
			'storage_strategy'    => $this->storage_strategy(),
		);

		return array(
			'rows'    => $rows,
			'summary' => $summary,
		);
	}

	private function rows_for_boards( array $ranked ): array {
		$rows = array();

		foreach ( self::BOARD_KEYS as $board_key ) {
			$position = 1;
			foreach ( $ranked as $item ) {
				if ( ! $this->item_matches_board( $item, $board_key ) ) {
					continue;
				}

				$rows[] = $this->snapshot_row_for_item( $item, $board_key, $position );
				++$position;
			}
		}

		return $rows;
	}

	private function snapshot_row_for_item( array $item, string $board_key, int $position ): array {
		return array(
			'board_key'              => $board_key,
			'fighter_id'             => (int) $item['fighter']['id'],
			'rank_position'          => $position,
			'total_score'            => number_format( (float) $item['total_score'], 3, '.', '' ),
			'raw_score'              => number_format( (float) $item['raw_score'], 3, '.', '' ),
			'normalized_score'       => null,
			'confidence_score'       => null,
			'sample_size'            => (int) $item['sample_size'],
			'quality_flags_json'     => wp_json_encode( $item['quality_flags'] ),
			'previous_rank_position' => null,
			'previous_total_score'   => null,
			'movement'               => null,
			'breakdown_json'         => wp_json_encode( $item['breakdown'] ),
			'eligibility_json'       => wp_json_encode( $item['eligibility'] ),
			'warnings_json'          => wp_json_encode( array( 'warnings' => $item['warnings'] ) ),
			'source_summary_json'    => wp_json_encode( $item['source_summary'] ),
		);
	}

	private function item_matches_board( array $item, string $board_key ): bool {
		if ( 'overall' === $board_key ) {
			return true;
		}

		$gender = $this->normalize_gender( $item['fighter']['gender'] ?? null );
		$age    = isset( $item['eligibility']['age'] ) && null !== $item['eligibility']['age'] ? (int) $item['eligibility']['age'] : null;

		if ( 'male' === $board_key || 'female' === $board_key ) {
			return $gender === $board_key;
		}

		if ( 'under_30' === $board_key ) {
			return null !== $age && $age < 30;
		}

		if ( 'over_30_to_34' === $board_key ) {
			return null !== $age && $age >= 30 && $age <= 34;
		}

		if ( in_array( $board_key, self::WEIGHT_BOARD_KEYS, true ) ) {
			return $this->weight_class_board_key( $item['fighter']['weight_class'] ?? null, $gender ) === $board_key;
		}

		return false;
	}

	private function normalize_gender( $gender ): string {
		$normalized = strtolower( trim( (string) $gender ) );
		if ( in_array( $normalized, array( 'm', 'man', 'men' ), true ) ) {
			return 'male';
		}
		if ( in_array( $normalized, array( 'f', 'woman', 'women' ), true ) ) {
			return 'female';
		}

		return $normalized;
	}

	private function weight_class_board_key( $weight_class, string $gender ): string {
		$normalized = strtolower( trim( (string) $weight_class ) );
		$normalized = str_replace( array( "women's", 'womens', 'women ' ), 'women ', $normalized );
		$normalized = preg_replace( '/[^a-z0-9]+/', '_', $normalized );
		$normalized = trim( (string) $normalized, '_' );

		if ( '' === $normalized || 'unknown' === $normalized ) {
			return '';
		}

		if ( 0 === strpos( $normalized, 'women_' ) ) {
			return $normalized;
		}

		if ( 'female' === $gender && in_array( $normalized, array( 'strawweight', 'flyweight', 'bantamweight', 'featherweight' ), true ) ) {
			return 'women_' . $normalized;
		}

		return $normalized;
	}

	private function score_fighter( array $fighter, ?array $stats, array $eligibility, array $items, array $source_types, string $calculated_at ): array {
		$warnings = array();
		$breakdown = array(
			'total_score'                  => 0.0,
			'raw_score'                    => 0.0,
			'sample_size'                  => 0,
			'base_record_points'           => 0.0,
			'wins_points'                  => 0.0,
			'losses_points'                => 0.0,
			'finishes_points'              => 0.0,
			'age_adjustment_points'        => $this->formula->age_adjustment_points( $eligibility['age'] ),
			'opponent_differential_points' => 0.0,
			'loss_quality_penalty_points'  => 0.0,
			'per_fight_items'              => array(),
		);
		$countable_bouts_used = 0;
		$prefight_missing     = 0;
		$method_missing       = 0;

		foreach ( $items as $item ) {
			$item_warnings = array();
			$result        = (string) ( $item['result_for_fighter'] ?? '' );

			if ( ! in_array( $result, array( 'win', 'loss' ), true ) ) {
				$item_warnings[] = 'skipped_non_scoring_bout';
				$warnings[]      = 'skipped_non_scoring_bout';
				continue;
			}

			++$countable_bouts_used;
			$method_category = $item['method_category'] ? (string) $item['method_category'] : 'unknown';
			if ( 'unknown' === $method_category || '' === $method_category ) {
				++$method_missing;
				$item_warnings[] = 'missing_method_category';
				$warnings[]      = 'missing_method_category';
			}

			$base_points   = $this->formula->base_record_points( $result );
			$finish_points = $this->formula->finish_points( $result, $method_category );
			$opponent_diff = $this->opponent_prefight_diff( $item );
			if ( null === $opponent_diff ) {
				++$prefight_missing;
				$item_warnings[] = 'missing_prefight_record';
				$warnings[]      = 'missing_prefight_record';
			}

			$od_points = 'win' === $result ? $this->formula->opponent_differential_points_for_win( $opponent_diff ) : 0.0;
			$loss_quality = 'loss' === $result ? $this->formula->loss_quality_penalty_points( $opponent_diff ) : 0.0;

			$breakdown['base_record_points'] += $base_points;
			if ( 'win' === $result ) {
				$breakdown['wins_points'] += $base_points;
			} elseif ( 'loss' === $result ) {
				$breakdown['losses_points'] += $base_points;
			}
			$breakdown['finishes_points'] += $finish_points;
			$breakdown['opponent_differential_points'] += $od_points;
			$breakdown['loss_quality_penalty_points'] += $loss_quality;

			$breakdown['per_fight_items'][] = array(
				'bout_id'                 => (int) $item['bout_id'],
				'event_id'                => (int) $item['event_id'],
				'event_date'              => $item['event_date'],
				'opponent_fighter_id'     => null === $item['opponent_fighter_id'] ? null : (int) $item['opponent_fighter_id'],
				'result_for_fighter'      => $result,
				'method_category'         => $method_category,
				'fighter_prefight_record' => $this->record_summary_from_item( $item, 'prefight' ),
				'opponent_prefight_record' => $this->record_summary_from_item( $item, 'opponent_prefight' ),
				'finish_points'           => $finish_points,
				'base_points'             => $base_points,
				'opponent_prefight_diff'  => $opponent_diff,
				'opponent_diff_points'    => $od_points,
				'loss_quality_penalty'    => $loss_quality,
				'warnings'                => array_values( array_unique( $item_warnings ) ),
			);
		}

		if ( 0 === $countable_bouts_used ) {
			$warnings[] = 'no_countable_fights';
		}

		$performance_raw_score =
			$breakdown['base_record_points']
			+ $breakdown['finishes_points']
			+ $breakdown['age_adjustment_points']
			+ $breakdown['opponent_differential_points']
			+ $breakdown['loss_quality_penalty_points'];
		$direct_score = round( $performance_raw_score, 3 );

		$breakdown['raw_score']   = $direct_score;
		$breakdown['total_score'] = $direct_score;
		$breakdown['sample_size']                  = $countable_bouts_used;
		$breakdown['tie_breaker'] = $this->tie_breaker_values( $fighter, $stats, $eligibility );
		$quality_flags = $this->quality_flags( $countable_bouts_used, $prefight_missing, $method_missing, $eligibility );

		$source_summary = array(
			'stats_calculated_at'              => $stats['calculated_at'] ?? null,
			'stats_row_id'                     => $stats['id'] ?? null,
			'countable_bouts_used'             => $countable_bouts_used,
			'scoring_bouts_used'               => $countable_bouts_used,
			'prefight_records_missing_count'   => $prefight_missing,
			'method_category_missing_count'    => $method_missing,
			'quality_flags'                    => $quality_flags,
			'source_types_linked_to_fighter'   => $source_types,
			'generated_by'                     => 'ranking_engine',
			'formula_version'                  => FormulaRegistry::current_version(),
			'generated_at'                     => $calculated_at,
			'tie_breaker_order'                => (array) ( FormulaRegistry::current_config()['tie_breakers'] ?? array() ),
		);

		return array(
			'breakdown'      => $breakdown,
			'warnings'       => array_values( array_unique( $warnings ) ),
			'source_summary' => $source_summary,
			'quality_flags'  => $quality_flags,
		);
	}

	private function quality_flags( int $sample_size, int $prefight_missing, int $method_missing, array $eligibility ): array {
		$config = FormulaRegistry::current_config();
		$flags  = array();

		if ( $sample_size < (int) $config['eligibility']['min_scoring_bouts'] ) {
			$flags[] = 'insufficient_sample_size';
		}

		if ( $sample_size < 3 ) {
			$flags[] = 'provisional_sample_size';
		}

		if ( $prefight_missing > 0 ) {
			$flags[] = 'missing_prefight_records';
		}

		if ( $method_missing > 0 ) {
			$flags[] = 'missing_method_category';
		}

		foreach ( (array) ( $eligibility['warnings'] ?? array() ) as $warning ) {
			if ( in_array( $warning, array( 'birth_year_only_age_estimate', 'missing_tapology_identity_mapping', 'invalid_tapology_identity_mapping' ), true ) ) {
				$flags[] = $warning;
			}
		}

		return array_values( array_unique( $flags ) );
	}

	private function scoring_items_by_fighter(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			"
			SELECT
				p.id AS participant_id,
				p.bout_id,
				p.fighter_id,
				p.opponent_fighter_id,
				p.result_for_fighter,
				p.prefight_wins,
				p.prefight_losses,
				p.prefight_draws,
				p.prefight_nc,
				p.prefight_record_raw,
				p.opponent_prefight_wins,
				p.opponent_prefight_losses,
				p.opponent_prefight_draws,
				p.opponent_prefight_nc,
				p.opponent_prefight_record_raw,
				p.opponent_prefight_diff,
				b.event_id,
				b.status AS bout_status,
				b.deleted_soft AS bout_deleted_soft,
				b.result_type,
				b.method_category,
				b.is_scoring_candidate,
				e.event_date,
				e.id AS event_exists,
				f.id AS fighter_exists
			FROM {$tables['bout_participants']} p
			LEFT JOIN {$tables['bouts']} b ON b.id = p.bout_id
			LEFT JOIN {$tables['events']} e ON e.id = b.event_id AND e.deleted_soft = 0
			LEFT JOIN {$tables['fighters']} f ON f.id = p.fighter_id
			ORDER BY b.event_id ASC, p.bout_id ASC, p.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$participants_by_bout = array();
		foreach ( $rows as $row ) {
			$bout_id = (int) $row['bout_id'];
			if ( ! isset( $participants_by_bout[ $bout_id ] ) ) {
				$participants_by_bout[ $bout_id ] = array();
			}
			$participants_by_bout[ $bout_id ][] = $row;
		}

		$grouped = array();
		foreach ( $participants_by_bout as $bout_id => $participants ) {
			if ( 2 !== count( $participants ) ) {
				continue;
			}

			foreach ( $participants as $participant ) {
				if ( empty( $participant['fighter_id'] ) || empty( $participant['fighter_exists'] ) ) {
					continue;
				}

				if ( empty( $participant['event_exists'] ) ) {
					continue;
				}

				if ( 1 === (int) $participant['bout_deleted_soft'] ) {
					continue;
				}

				if ( ! in_array( (string) $participant['bout_status'], self::VALID_BOUT_STATUSES, true ) ) {
					continue;
				}

				if ( 1 !== (int) $participant['is_scoring_candidate'] ) {
					continue;
				}

				if ( 'win_loss' !== (string) $participant['result_type'] ) {
					continue;
				}

				$fighter_id = (int) $participant['fighter_id'];
				if ( ! isset( $grouped[ $fighter_id ] ) ) {
					$grouped[ $fighter_id ] = array();
				}
				$grouped[ $fighter_id ][] = $participant;
			}
		}

		return $grouped;
	}

	private function tie_breaker_values( array $fighter, ?array $stats, array $eligibility ): array {
		return array(
			'wins'            => is_array( $stats ) ? (int) ( $stats['wins'] ?? 0 ) : 0,
			'finish_rate'     => is_array( $stats ) && null !== ( $stats['finish_rate'] ?? null ) ? (float) $stats['finish_rate'] : 0.0,
			'age'             => null === ( $eligibility['age'] ?? null ) ? null : (int) $eligibility['age'],
			'last_fight_date' => is_array( $stats ) ? ( $stats['last_fight_date'] ?? null ) : null,
			'fighter_id'      => (int) ( $fighter['id'] ?? 0 ),
			'order'           => (array) ( FormulaRegistry::current_config()['tie_breakers'] ?? array() ),
		);
	}

	private function opponent_prefight_diff( array $item ): ?int {
		if ( null !== $item['opponent_prefight_diff'] ) {
			return (int) $item['opponent_prefight_diff'];
		}

		if ( null === $item['opponent_prefight_wins'] || null === $item['opponent_prefight_losses'] ) {
			return null;
		}

		return (int) $item['opponent_prefight_wins'] - (int) $item['opponent_prefight_losses'];
	}

	private function record_summary_from_item( array $item, string $prefix ): array {
		$wins   = $this->nullable_int_value( $item[ "{$prefix}_wins" ] ?? null );
		$losses = $this->nullable_int_value( $item[ "{$prefix}_losses" ] ?? null );
		$draws  = $this->nullable_int_value( $item[ "{$prefix}_draws" ] ?? null );
		$nc     = $this->nullable_int_value( $item[ "{$prefix}_nc" ] ?? null );
		$raw    = $item[ "{$prefix}_record_raw" ] ?? null;

		return array(
			'wins'   => $wins,
			'losses' => $losses,
			'draws'  => $draws,
			'nc'     => $nc,
			'raw'    => null === $raw || '' === $raw ? null : (string) $raw,
			'diff'   => null === $wins || null === $losses ? null : $wins - $losses,
		);
	}

	private function nullable_int_value( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}

	private function stats_from_row( array $row ): ?array {
		if ( null === $row['stats_row_id'] ) {
			return null;
		}

		return array(
			'id'                => (int) $row['stats_row_id'],
			'wins'              => (int) $row['wins'],
			'losses'            => (int) $row['losses'],
			'draws'             => (int) $row['draws'],
			'nc'                => (int) $row['nc'],
			'pro_fights_count'  => (int) $row['pro_fights_count'],
			'finish_rate'       => null === $row['finish_rate'] ? null : (float) $row['finish_rate'],
			'last_fight_date'   => $row['last_fight_date'],
			'calculated_at'     => $row['stats_calculated_at'],
		);
	}

	private function sort_ranked( array &$ranked ): void {
		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				$a_total = (float) ( $a['total_score'] ?? 0 );
				$b_total = (float) ( $b['total_score'] ?? 0 );
				if ( $a_total !== $b_total ) {
					return $b_total <=> $a_total;
				}

				$a_stats = is_array( $a['stats'] ?? null ) ? $a['stats'] : array();
				$b_stats = is_array( $b['stats'] ?? null ) ? $b['stats'] : array();

				if ( (int) ( $a_stats['wins'] ?? 0 ) !== (int) ( $b_stats['wins'] ?? 0 ) ) {
					return (int) ( $b_stats['wins'] ?? 0 ) <=> (int) ( $a_stats['wins'] ?? 0 );
				}

				$a_finish_rate = null === ( $a_stats['finish_rate'] ?? null ) ? 0.0 : (float) $a_stats['finish_rate'];
				$b_finish_rate = null === ( $b_stats['finish_rate'] ?? null ) ? 0.0 : (float) $b_stats['finish_rate'];
				if ( $a_finish_rate !== $b_finish_rate ) {
					return $b_finish_rate <=> $a_finish_rate;
				}

				$a_age = null === ( $a['eligibility']['age'] ?? null ) ? 999 : (int) $a['eligibility']['age'];
				$b_age = null === ( $b['eligibility']['age'] ?? null ) ? 999 : (int) $b['eligibility']['age'];
				if ( $a_age !== $b_age ) {
					return $a_age <=> $b_age;
				}

				$a_last = (string) ( $a_stats['last_fight_date'] ?? '' );
				$b_last = (string) ( $b_stats['last_fight_date'] ?? '' );
				if ( $a_last !== $b_last ) {
					return strcmp( $b_last, $a_last );
				}

				return (int) $a['fighter']['id'] <=> (int) $b['fighter']['id'];
			}
		);
	}

	private function storage_strategy(): string {
		return 'draft rows are stored in mmaf_ranking_snapshots by ranking_run_id; mmaf_ranking_current is live-only; Formula v1.5 stores direct component totals in total_score and raw_score, while normalized_score and confidence_score stay NULL until a manual activation chooses a completed draft.';
	}
}
