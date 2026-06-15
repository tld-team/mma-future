<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Repositories\FighterStatsRepository;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StatsRebuildService {
	private const COUNTABLE_RESULTS = array( 'win', 'loss', 'draw', 'no_contest' );
	private const COUNTABLE_STATUSES = array( 'valid', 'completed' );
	private const RESULT_CODES = array(
		'win'        => 'W',
		'loss'       => 'L',
		'draw'       => 'D',
		'no_contest' => 'NC',
	);

	private FighterStatsRepository $stats;
	private AuditLogService $audit_log;

	public function __construct() {
		$this->stats     = new FighterStatsRepository();
		$this->audit_log = new AuditLogService();
	}

	public function rebuild_all( int $actor_user_id = 0, string $reason = 'Manual stats rebuild' ): array {
		$previous_summary = $this->stats->get_last_rebuild_summary();
		$fighters         = $this->stats->list_active_fighters();
		$fighter_ids      = array();
		$rows_by_fighter  = array();
		$warnings_count   = 0;

		foreach ( $fighters as $fighter ) {
			$fighter_id                 = (int) $fighter['id'];
			$fighter_ids[ $fighter_id ] = true;
			$rows_by_fighter[ $fighter_id ] = $this->empty_fighter_stats( $fighter_id );
		}

		$participants_by_bout = $this->participants_by_bout( $this->stats->list_participants_with_fighters() );
		$bouts                = $this->stats->list_bouts_with_events();
		$countable_bouts      = 0;
		$skipped_bouts        = 0;
		$malformed_bouts      = 0;
		$participants_processed = 0;
		$summary_warnings     = array(
			'malformed_bout_skipped'       => 0,
			'missing_fighter_id'           => 0,
			'missing_fighter'              => 0,
			'soft_deleted_fighter_skipped' => 0,
			'invalid_result_for_fighter'   => 0,
			'non_counted_result'           => 0,
			'non_counted_bout_status'      => 0,
			'bout_deleted_soft'            => 0,
			'bout_missing_event'           => 0,
			'missing_event_date'           => 0,
			'missing_method_category'      => 0,
			'missing_prefight_record'      => 0,
		);

		foreach ( $bouts as $bout ) {
			$bout_id      = (int) $bout['id'];
			$participants = $participants_by_bout[ $bout_id ] ?? array();

			if ( 2 !== count( $participants ) ) {
				++$skipped_bouts;
				++$malformed_bouts;
				$this->increment_warning( $summary_warnings, 'malformed_bout_skipped', count( $participants ), $warnings_count );
				continue;
			}

			if ( 1 === (int) $bout['deleted_soft'] || 'deleted_soft' === (string) $bout['status'] ) {
				++$skipped_bouts;
				$this->increment_warning( $summary_warnings, 'bout_deleted_soft', $bout_id, $warnings_count );
				continue;
			}

			if ( ! in_array( (string) $bout['status'], self::COUNTABLE_STATUSES, true ) ) {
				++$skipped_bouts;
				$this->increment_warning( $summary_warnings, 'non_counted_bout_status', $bout_id, $warnings_count );
				continue;
			}

			$bout_is_countable = false;
			foreach ( $participants as $participant ) {
				if ( in_array( (string) $participant['result_for_fighter'], self::COUNTABLE_RESULTS, true ) ) {
					$bout_is_countable = true;
					break;
				}
			}

			if ( ! $bout_is_countable ) {
				++$skipped_bouts;
				$this->increment_warning( $summary_warnings, 'non_counted_result', $bout_id, $warnings_count );
				continue;
			}

			if ( empty( $bout['event_exists'] ) ) {
				$this->increment_warning( $summary_warnings, 'bout_missing_event', $bout_id, $warnings_count );
			}

			if ( empty( $bout['event_date'] ) ) {
				$this->increment_warning( $summary_warnings, 'missing_event_date', $bout_id, $warnings_count );
			}

			++$countable_bouts;

			foreach ( $participants as $participant ) {
				++$participants_processed;
				$fighter_id = (int) ( $participant['fighter_id'] ?? 0 );
				$result     = (string) ( $participant['result_for_fighter'] ?? '' );

				if ( $fighter_id <= 0 ) {
					$this->increment_warning( $summary_warnings, 'missing_fighter_id', $bout_id, $warnings_count );
					continue;
				}

				if ( empty( $participant['fighter_exists'] ) ) {
					$this->increment_warning( $summary_warnings, 'missing_fighter', $fighter_id, $warnings_count );
					continue;
				}

				if ( 1 === (int) $participant['fighter_deleted_soft'] || ! isset( $fighter_ids[ $fighter_id ] ) ) {
					$this->increment_warning( $summary_warnings, 'soft_deleted_fighter_skipped', $fighter_id, $warnings_count );
					continue;
				}

				if ( ! in_array( $result, self::COUNTABLE_RESULTS, true ) ) {
					$this->add_fighter_warning( $rows_by_fighter[ $fighter_id ], 'invalid_result_for_fighter' );
					$this->increment_warning( $summary_warnings, 'invalid_result_for_fighter', $bout_id, $warnings_count );
					continue;
				}

				if ( $this->participant_missing_prefight_record( $participant ) ) {
					$this->add_fighter_warning( $rows_by_fighter[ $fighter_id ], 'missing_prefight_record' );
					$this->increment_warning( $summary_warnings, 'missing_prefight_record', $bout_id, $warnings_count );
				}

				if ( empty( $bout['event_date'] ) ) {
					$this->add_fighter_warning( $rows_by_fighter[ $fighter_id ], 'missing_event_date' );
				}

				if ( 'win' === $result && ( empty( $bout['method_category'] ) || 'unknown' === (string) $bout['method_category'] ) ) {
					$this->add_fighter_warning( $rows_by_fighter[ $fighter_id ], 'missing_method_category' );
					$this->increment_warning( $summary_warnings, 'missing_method_category', $bout_id, $warnings_count );
				}

				$this->apply_participant_result( $rows_by_fighter[ $fighter_id ], $bout, $result );
			}
		}

		$stats_rows = array();
		foreach ( $rows_by_fighter as $fighter_id => $stats_row ) {
			$this->finalize_fighter_stats( $stats_row );
			$stats_rows[] = $this->database_row( $stats_row );
		}

		$this->stats->replace_all( $stats_rows );

		$summary = array(
			'rebuilt_at'             => DateTime::mysql_now(),
			'fighters_total'         => count( $fighters ),
			'stats_rows_written'     => count( $stats_rows ),
			'countable_bouts'        => $countable_bouts,
			'skipped_bouts'          => $skipped_bouts,
			'malformed_bouts'        => $malformed_bouts,
			'participants_processed' => $participants_processed,
			'warnings_count'         => $warnings_count,
			'warnings'               => array_filter( $summary_warnings ),
		);

		$this->stats->set_last_rebuild_summary( $summary );
		$this->audit_log->write(
			'stats_rebuilt',
			'system',
			0,
			$previous_summary,
			$summary,
			$reason,
			$actor_user_id
		);

		return $summary;
	}

	private function participants_by_bout( array $participants ): array {
		$grouped = array();

		foreach ( $participants as $participant ) {
			$bout_id = (int) $participant['bout_id'];
			if ( ! isset( $grouped[ $bout_id ] ) ) {
				$grouped[ $bout_id ] = array();
			}
			$grouped[ $bout_id ][] = $participant;
		}

		return $grouped;
	}

	private function empty_fighter_stats( int $fighter_id ): array {
		return array(
			'fighter_id'       => $fighter_id,
			'wins'             => 0,
			'losses'           => 0,
			'draws'            => 0,
			'nc'               => 0,
			'pro_fights_count' => 0,
			'ko_tko_wins'      => 0,
			'submission_wins'  => 0,
			'decision_wins'    => 0,
			'finish_wins'      => 0,
			'finish_rate'      => '0.000',
			'last_fight_date'  => null,
			'streak'           => null,
			'recent_form'      => null,
			'activity_status'  => 'no_fights',
			'warnings'         => array(),
			'fights'           => array(),
		);
	}

	private function apply_participant_result( array &$stats_row, array $bout, string $result ): void {
		if ( 'win' === $result ) {
			++$stats_row['wins'];
			if ( 'ko_tko' === (string) $bout['method_category'] ) {
				++$stats_row['ko_tko_wins'];
			} elseif ( 'submission' === (string) $bout['method_category'] ) {
				++$stats_row['submission_wins'];
			} elseif ( 'decision' === (string) $bout['method_category'] ) {
				++$stats_row['decision_wins'];
			}
		} elseif ( 'loss' === $result ) {
			++$stats_row['losses'];
		} elseif ( 'draw' === $result ) {
			++$stats_row['draws'];
		} elseif ( 'no_contest' === $result ) {
			++$stats_row['nc'];
		}

		$stats_row['pro_fights_count'] = $stats_row['wins'] + $stats_row['losses'] + $stats_row['draws'] + $stats_row['nc'];
		$stats_row['finish_wins']      = $stats_row['ko_tko_wins'] + $stats_row['submission_wins'];

		if ( ! empty( $bout['event_date'] ) && ( null === $stats_row['last_fight_date'] || $bout['event_date'] > $stats_row['last_fight_date'] ) ) {
			$stats_row['last_fight_date'] = $bout['event_date'];
		}

		$stats_row['fights'][] = array(
			'result'     => $result,
			'event_date' => $bout['event_date'] ?? null,
			'bout_order' => null === $bout['bout_order'] ? 0 : (int) $bout['bout_order'],
			'bout_id'    => (int) $bout['id'],
		);
	}

	private function finalize_fighter_stats( array &$stats_row ): void {
		if ( $stats_row['wins'] > 0 ) {
			$stats_row['finish_rate'] = number_format( $stats_row['finish_wins'] / $stats_row['wins'], 3, '.', '' );
		}

		if ( 0 === $stats_row['pro_fights_count'] ) {
			$this->add_fighter_warning( $stats_row, 'no_countable_fights' );
		}

		$this->sort_fights( $stats_row['fights'] );
		$recent = array_slice( $stats_row['fights'], 0, 5 );
		$codes  = array();

		foreach ( $recent as $fight ) {
			$codes[] = self::RESULT_CODES[ $fight['result'] ] ?? strtoupper( $fight['result'] );
		}

		$stats_row['recent_form'] = empty( $codes ) ? null : implode( '-', $codes );
		$stats_row['streak']      = $this->current_streak( $stats_row['fights'] );
		$stats_row['activity_status'] = $this->activity_status( $stats_row );
	}

	private function current_streak( array $fights ): ?string {
		if ( empty( $fights ) ) {
			return null;
		}

		$first_result = (string) $fights[0]['result'];
		$count        = 0;

		foreach ( $fights as $fight ) {
			if ( $first_result !== (string) $fight['result'] ) {
				break;
			}
			++$count;
		}

		return ( self::RESULT_CODES[ $first_result ] ?? strtoupper( $first_result ) ) . $count;
	}

	private function activity_status( array $stats_row ): string {
		if ( 0 === (int) $stats_row['pro_fights_count'] ) {
			return 'no_fights';
		}

		if ( empty( $stats_row['last_fight_date'] ) ) {
			return 'unknown';
		}

		$cutoff = gmdate( 'Y-m-d', strtotime( '-24 months', current_time( 'timestamp', true ) ) );

		return $stats_row['last_fight_date'] >= $cutoff ? 'active' : 'inactive';
	}

	private function database_row( array $stats_row ): array {
		$warnings = array(
			'warnings' => array_values( array_unique( $stats_row['warnings'] ) ),
		);

		return array(
			'fighter_id'       => $stats_row['fighter_id'],
			'wins'             => $stats_row['wins'],
			'losses'           => $stats_row['losses'],
			'draws'            => $stats_row['draws'],
			'nc'               => $stats_row['nc'],
			'pro_fights_count' => $stats_row['pro_fights_count'],
			'ko_tko_wins'      => $stats_row['ko_tko_wins'],
			'submission_wins'  => $stats_row['submission_wins'],
			'decision_wins'    => $stats_row['decision_wins'],
			'finish_wins'      => $stats_row['finish_wins'],
			'finish_rate'      => $stats_row['finish_rate'],
			'last_fight_date'  => $stats_row['last_fight_date'],
			'streak'           => $stats_row['streak'],
			'recent_form'      => $stats_row['recent_form'],
			'activity_status'  => $stats_row['activity_status'],
			'warnings_json'    => wp_json_encode( $warnings ),
			'calculated_at'    => DateTime::mysql_now(),
		);
	}

	private function sort_fights( array &$fights ): void {
		usort(
			$fights,
			static function ( array $a, array $b ): int {
				$a_has_date = empty( $a['event_date'] ) ? 0 : 1;
				$b_has_date = empty( $b['event_date'] ) ? 0 : 1;

				if ( $a_has_date !== $b_has_date ) {
					return $b_has_date <=> $a_has_date;
				}

				if ( (string) $a['event_date'] !== (string) $b['event_date'] ) {
					return strcmp( (string) $b['event_date'], (string) $a['event_date'] );
				}

				if ( (int) $a['bout_order'] !== (int) $b['bout_order'] ) {
					return (int) $b['bout_order'] <=> (int) $a['bout_order'];
				}

				return (int) $b['bout_id'] <=> (int) $a['bout_id'];
			}
		);
	}

	private function participant_missing_prefight_record( array $participant ): bool {
		return null === $participant['prefight_wins']
			|| null === $participant['prefight_losses']
			|| null === $participant['prefight_draws']
			|| null === $participant['prefight_nc'];
	}

	private function add_fighter_warning( array &$stats_row, string $warning ): void {
		$stats_row['warnings'][] = $warning;
	}

	private function increment_warning( array &$summary_warnings, string $key, int $unused_id, int &$warnings_count ): void {
		if ( ! isset( $summary_warnings[ $key ] ) ) {
			$summary_warnings[ $key ] = 0;
		}

		++$summary_warnings[ $key ];
		++$warnings_count;
	}
}
