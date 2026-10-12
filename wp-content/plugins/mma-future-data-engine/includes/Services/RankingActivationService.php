<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Repositories\RankingCurrentRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Services\Formula\FormulaV13;
use MMAF\DataEngine\Services\Formula\FormulaV14;
use MMAF\DataEngine\Services\Formula\FormulaRegistry;
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
        \MMAF\DataEngine\Services\RegularImport\WriteGuard::assertAllowed();
		global $wpdb;

		$ranking_run_id = max( 0, $ranking_run_id );
		$previous = array(
			'active_ranking_run_id' => $this->runs->get_active_ranking_run_id(),
			'current_integrity'     => $this->rankings->current_integrity(),
			'last_activation'       => $this->runs->get_last_activation_summary(),
		);

		try {
			$run       = $this->validate_run( $ranking_run_id );
			$validation = $this->validate_snapshots( $ranking_run_id, (string) $run['formula_version'] );
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
		$boards = $validation['boards'];
		\MMAF\DataEngine\Services\RegularImport\Transaction::begin();

		try {
			$rows_written = $this->rankings->replace_current_from_snapshots( $ranking_run_id );
			if ( $rows_written !== $validation['total'] ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: expected rows, 2: rows written. */
						__( 'Current ranking row count mismatch. Expected %1$d rows, wrote %2$d rows.', 'mma-future-data-engine' ),
						$validation['total'],
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
				'boards'                         => $boards,
				'previous_active_ranking_run_id' => $previous['active_ranking_run_id'],
				'status'                         => 'activated',
				'warnings_count'                 => $validation['warnings'],
				'actor_user_id'                  => $actor_user_id > 0 ? $actor_user_id : null,
			);

			$this->runs->set_last_activation_summary( $summary );

			$audit_id=$this->audit_log->write('ranking_activated','ranking_run',$ranking_run_id,$previous,$summary,'Manual ranking activation',$actor_user_id);
			\MMAF\DataEngine\Services\Operations\Activations::record($ranking_run_id,$boards,$activated_at,$actor_user_id,$audit_id);
			\MMAF\DataEngine\Services\RegularImport\Transaction::commit();
		} catch ( \Throwable $error ) {
			\MMAF\DataEngine\Services\RegularImport\Transaction::rollback();
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

		do_action( 'mmaf_public_data_changed', 'activation' );
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

		if ( ! in_array( (string) $run['formula_version'], FormulaRegistry::activation_supported_versions(), true ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: formula versions. */
					__( 'Only Formula %s ranking runs can be activated in this phase.', 'mma-future-data-engine' ),
					implode( ', ', FormulaRegistry::activation_supported_versions() )
				)
			);
		}

		if ( empty( $run['formula_config_snapshot'] ) || null === json_decode( (string) $run['formula_config_snapshot'], true ) ) {
			throw new \RuntimeException( __( 'Ranking run is missing a valid formula config snapshot.', 'mma-future-data-engine' ) );
		}

		return $run;
	}

    private function validate_snapshots(int $run,string $formula): array {
        global $wpdb;$t=\MMAF\DataEngine\Migrations\Schema::table_names();
        $groups=$wpdb->get_results($wpdb->prepare("SELECT board_key,COUNT(*) n,COUNT(DISTINCT fighter_id) fighters,COUNT(DISTINCT rank_position) ranks,MIN(rank_position) first_rank,MAX(rank_position) last_rank FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d GROUP BY board_key",$run),ARRAY_A);
        if(!$groups){throw new \RuntimeException('Ranking run has no draft snapshot rows to activate.');}
        $out=['total'=>0,'boards'=>[],'warnings'=>0];
        foreach($groups as $g){
            if(!$g['board_key']){throw new \RuntimeException('Snapshot row has an empty board key.');}
            if($g['n']!==$g['fighters']){throw new \RuntimeException('Snapshot rows contain duplicate board/fighter pairs.');}
            if($g['n']!==$g['ranks']){throw new \RuntimeException('Snapshot rows contain duplicate rank positions in a board.');}
            if((int)$g['first_rank']!==1 || (int)$g['last_rank']!==(int)$g['n']){throw new \RuntimeException('Snapshot ranks for board '.$g['board_key'].' must start at 1 and be contiguous.');}
            $out['total']+=(int)$g['n'];$out['boards'][]=$g['board_key'];
        }
        $min=(int)(FormulaRegistry::config_for_version($formula)['eligibility']['min_scoring_bouts']??3);$cursor=0;
        do{
            $rows=$wpdb->get_results($wpdb->prepare("SELECT r.*,f.id fighter_exists FROM {$t['ranking_snapshots']} r LEFT JOIN {$t['fighters']} f ON f.id=r.fighter_id WHERE r.ranking_run_id=%d AND r.id>%d ORDER BY r.id LIMIT 250",$run,$cursor),ARRAY_A);
            foreach($rows as $row){
                $cursor=(int)$row['id'];
                if(!$row['fighter_exists'] || (int)$row['fighter_id']<1){throw new \RuntimeException('Snapshot row references a missing fighter.');}
                if(!is_numeric($row['total_score'])){throw new \RuntimeException('Snapshot row has a non-numeric total score.');}
                $this->validate_snapshot_score_contract($row,$formula);
                if((int)$row['sample_size']<$min){throw new \RuntimeException('Snapshot row does not meet the minimum scoring sample size.');}
                foreach(['breakdown_json','eligibility_json','warnings_json','source_summary_json','quality_flags_json'] as $key){if($row[$key]!==null && $row[$key]!=='' && json_decode($row[$key],true)===null){throw new \RuntimeException('Snapshot row has invalid JSON in '.$key.'.');}}
                $out['warnings']+=$this->warnings_count([$row]);
            }
        }while(count($rows)===250);
        return $out;
    }

	private function validate_snapshot_score_contract( array $snapshot, string $formula_version ): void {
		if ( FormulaRegistry::uses_normalized_scores( $formula_version ) ) {
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
				throw new \RuntimeException( __( 'Snapshot total_score must match normalized_score for normalized ranking formulas.', 'mma-future-data-engine' ) );
			}

			return;
		}

		if ( FormulaRegistry::uses_direct_scores( $formula_version ) ) {
			if ( ! is_numeric( $snapshot['raw_score'] ?? null ) ) {
				throw new \RuntimeException( __( 'Snapshot row has a non-numeric raw_score field.', 'mma-future-data-engine' ) );
			}

			if ( null !== ( $snapshot['normalized_score'] ?? null ) && '' !== (string) $snapshot['normalized_score'] ) {
				throw new \RuntimeException( __( 'Snapshot normalized_score must stay NULL for direct-score ranking formulas.', 'mma-future-data-engine' ) );
			}

			if ( null !== ( $snapshot['confidence_score'] ?? null ) && '' !== (string) $snapshot['confidence_score'] ) {
				throw new \RuntimeException( __( 'Snapshot confidence_score must stay NULL for direct-score ranking formulas.', 'mma-future-data-engine' ) );
			}

			if ( abs( (float) $snapshot['total_score'] - (float) $snapshot['raw_score'] ) > 0.001 ) {
				throw new \RuntimeException( __( 'Snapshot total_score must match raw_score for direct-score ranking formulas.', 'mma-future-data-engine' ) );
			}

			return;
		}

		throw new \RuntimeException( __( 'Snapshot row uses an unsupported ranking formula version.', 'mma-future-data-engine' ) );
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
