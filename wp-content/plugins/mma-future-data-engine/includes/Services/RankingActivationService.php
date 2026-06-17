<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Repositories\RankingCurrentRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Services\Formula\FormulaV13;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankingActivationService {
	private RankingRunRepository $runs;
	private RankingCurrentRepository $rankings;
	private AuditLogService $audit_log;

	public function __construct() {
		$this->runs      = new RankingRunRepository();
		$this->rankings  = new RankingCurrentRepository();
		$this->audit_log = new AuditLogService();
	}

	public function activate( int $ranking_run_id, int $actor_user_id = 0 ): array {
		global $wpdb;

		$ranking_run_id = max( 0, $ranking_run_id );
		$previous = array(
			'active_ranking_run_id' => $this->runs->get_active_ranking_run_id(),
			'current_integrity'     => $this->rankings->current_integrity(),
			'last_activation'       => $this->runs->get_last_activation_summary(),
		);

		try {
			$run       = $this->validate_run( $ranking_run_id );
			$snapshots = $this->validate_snapshots( $ranking_run_id );
		} catch ( \Throwable $error ) {
			$this->audit_log->write(
				'ranking_activation_failed',
				'ranking_run',
				$ranking_run_id,
				$previous,
				array( 'error' => $error->getMessage() ),
				'Manual ranking activation failed',
				$actor_user_id
			);
			throw $error;
		}

		$activated_at = DateTime::mysql_now();
		$boards       = array();
		foreach ( $snapshots as $snapshot ) {
			$boards[ (string) $snapshot['board_key'] ] = true;
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$rows_written = $this->rankings->replace_current_from_snapshots( $ranking_run_id );
			if ( $rows_written !== count( $snapshots ) ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: expected rows, 2: rows written. */
						__( 'Current ranking row count mismatch. Expected %1$d rows, wrote %2$d rows.', 'mma-future-data-engine' ),
						count( $snapshots ),
						$rows_written
					)
				);
			}

			$this->runs->mark_active( $ranking_run_id );
			$this->runs->set_active_ranking_run_id( $ranking_run_id );

			$summary = array(
				'activated_at'                   => $activated_at,
				'ranking_run_id'                 => $ranking_run_id,
				'formula_version'                => (string) $run['formula_version'],
				'current_rows_written'           => $rows_written,
				'boards_count'                   => count( $boards ),
				'boards'                         => array_keys( $boards ),
				'previous_active_ranking_run_id' => $previous['active_ranking_run_id'],
				'status'                         => 'activated',
				'warnings_count'                 => $this->warnings_count( $snapshots ),
				'actor_user_id'                  => $actor_user_id > 0 ? $actor_user_id : null,
			);

			$this->runs->set_last_activation_summary( $summary );

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$this->audit_log->write(
				'ranking_activation_failed',
				'ranking_run',
				$ranking_run_id,
				$previous,
				array( 'error' => $error->getMessage() ),
				'Manual ranking activation failed',
				$actor_user_id
			);
			throw $error;
		}

		$this->audit_log->write(
			'ranking_activated',
			'ranking_run',
			$ranking_run_id,
			$previous,
			$summary,
			'Manual ranking activation',
			$actor_user_id
		);

		return $summary;
	}

	private function validate_run( int $ranking_run_id ): array {
		if ( $ranking_run_id <= 0 ) {
			throw new \InvalidArgumentException( __( 'A ranking run ID is required.', 'mma-future-data-engine' ) );
		}

		$run = $this->runs->find( $ranking_run_id );
		if ( ! $run ) {
			throw new \RuntimeException( __( 'Ranking run does not exist.', 'mma-future-data-engine' ) );
		}

		if ( 'completed' !== (string) $run['status'] ) {
			throw new \RuntimeException( __( 'Only completed ranking runs can be activated.', 'mma-future-data-engine' ) );
		}

		if ( FormulaV13::VERSION !== (string) $run['formula_version'] ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: formula version. */
					__( 'Only Formula %s ranking runs can be activated in this phase.', 'mma-future-data-engine' ),
					FormulaV13::VERSION
				)
			);
		}

		if ( empty( $run['formula_config_snapshot'] ) || null === json_decode( (string) $run['formula_config_snapshot'], true ) ) {
			throw new \RuntimeException( __( 'Ranking run is missing a valid formula config snapshot.', 'mma-future-data-engine' ) );
		}

		return $run;
	}

	private function validate_snapshots( int $ranking_run_id ): array {
		$snapshots = $this->rankings->snapshots_for_run( $ranking_run_id );
		if ( empty( $snapshots ) ) {
			throw new \RuntimeException( __( 'Ranking run has no draft snapshot rows to activate.', 'mma-future-data-engine' ) );
		}

		$board_fighters = array();
		$board_ranks    = array();
		$config         = FormulaV13::config();
		$min_sample     = (int) ( $config['eligibility']['min_scoring_bouts'] ?? 3 );

		foreach ( $snapshots as $snapshot ) {
			$board_key = (string) $snapshot['board_key'];
			$fighter_id = (int) $snapshot['fighter_id'];
			$rank_position = (int) $snapshot['rank_position'];

			if ( '' === $board_key ) {
				throw new \RuntimeException( __( 'Snapshot row has an empty board key.', 'mma-future-data-engine' ) );
			}

			if ( $fighter_id <= 0 || empty( $snapshot['fighter_exists'] ) ) {
				throw new \RuntimeException( __( 'Snapshot row references a missing fighter.', 'mma-future-data-engine' ) );
			}

			if ( $rank_position <= 0 ) {
				throw new \RuntimeException( __( 'Snapshot row has an invalid rank position.', 'mma-future-data-engine' ) );
			}

			if ( ! is_numeric( $snapshot['total_score'] ) ) {
				throw new \RuntimeException( __( 'Snapshot row has a non-numeric total score.', 'mma-future-data-engine' ) );
			}

			foreach ( array( 'raw_score', 'normalized_score', 'confidence_score' ) as $score_field ) {
				if ( ! is_numeric( $snapshot[ $score_field ] ?? null ) ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: %s: score field name. */
							__( 'Snapshot row has a non-numeric %s field.', 'mma-future-data-engine' ),
							$score_field
						)
					);
				}
			}

			$total_score      = (float) $snapshot['total_score'];
			$normalized_score = (float) $snapshot['normalized_score'];
			$confidence_score = (float) $snapshot['confidence_score'];
			if ( $normalized_score < 0.0 || $normalized_score > 100.0 || $total_score < 0.0 || $total_score > 100.0 ) {
				throw new \RuntimeException( __( 'Snapshot row has a normalized score outside the 0-100 range.', 'mma-future-data-engine' ) );
			}

			if ( $confidence_score < 0.0 || $confidence_score > 100.0 ) {
				throw new \RuntimeException( __( 'Snapshot row has a confidence score outside the 0-100 range.', 'mma-future-data-engine' ) );
			}

			if ( abs( $total_score - $normalized_score ) > 0.001 ) {
				throw new \RuntimeException( __( 'Snapshot total_score must match normalized_score for Formula v1.3.', 'mma-future-data-engine' ) );
			}

			if ( (int) ( $snapshot['sample_size'] ?? 0 ) < $min_sample ) {
				throw new \RuntimeException( __( 'Snapshot row does not meet the minimum scoring sample size.', 'mma-future-data-engine' ) );
			}

			$board_fighter_key = $board_key . ':' . $fighter_id;
			if ( isset( $board_fighters[ $board_fighter_key ] ) ) {
				throw new \RuntimeException( __( 'Snapshot rows contain duplicate board/fighter pairs.', 'mma-future-data-engine' ) );
			}
			$board_fighters[ $board_fighter_key ] = true;

			if ( ! isset( $board_ranks[ $board_key ] ) ) {
				$board_ranks[ $board_key ] = array();
			}

			if ( isset( $board_ranks[ $board_key ][ $rank_position ] ) ) {
				throw new \RuntimeException( __( 'Snapshot rows contain duplicate rank positions in a board.', 'mma-future-data-engine' ) );
			}
			$board_ranks[ $board_key ][ $rank_position ] = true;

			foreach ( array( 'breakdown_json', 'eligibility_json', 'warnings_json', 'source_summary_json', 'quality_flags_json' ) as $json_field ) {
				if ( null !== $snapshot[ $json_field ] && '' !== (string) $snapshot[ $json_field ] && null === json_decode( (string) $snapshot[ $json_field ], true ) ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: %s: JSON field name. */
							__( 'Snapshot row has invalid JSON in %s.', 'mma-future-data-engine' ),
							$json_field
						)
					);
				}
			}
		}

		foreach ( $board_ranks as $board_key => $ranks ) {
			ksort( $ranks );
			$expected = 1;
			foreach ( array_keys( $ranks ) as $rank_position ) {
				if ( $rank_position !== $expected ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: %s: board key. */
							__( 'Snapshot ranks for board %s must start at 1 and be contiguous.', 'mma-future-data-engine' ),
							$board_key
						)
					);
				}
				++$expected;
			}
		}

		return $snapshots;
	}

	private function warnings_count( array $snapshots ): int {
		$count = 0;

		foreach ( $snapshots as $snapshot ) {
			$decoded = json_decode( (string) ( $snapshot['warnings_json'] ?? '' ), true );
			if ( is_array( $decoded['warnings'] ?? null ) ) {
				$count += count( $decoded['warnings'] );
			}
		}

		return $count;
	}
}
