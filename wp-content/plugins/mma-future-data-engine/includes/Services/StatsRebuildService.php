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
        \MMAF\DataEngine\Services\RegularImport\WriteGuard::assertAllowed();
        return \MMAF\DataEngine\Services\RegularImport\Transaction::run(fn()=>$this->rebuild_summary($actor_user_id,$reason));
    }
    private function rebuild_summary(int $actor_user_id,string $reason): array {
		$previous_summary = $this->stats->get_last_rebuild_summary();
		$fighters_total = $this->stats->active_count();
		$warnings_count = 0;
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

		foreach ( $this->stats->bout_stream() as $bout ) {
			$bout_id      = (int) $bout['id'];
			$participants = $bout['_participants'];

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

				if ( 1 === (int) $participant['fighter_deleted_soft'] ) {
					$this->increment_warning( $summary_warnings, 'soft_deleted_fighter_skipped', $fighter_id, $warnings_count );
					continue;
				}

				if ( ! in_array( $result, self::COUNTABLE_RESULTS, true ) ) {
					$this->increment_warning( $summary_warnings, 'invalid_result_for_fighter', $bout_id, $warnings_count );
					continue;
				}

				if ( $this->participant_missing_prefight_record( $participant ) ) {
					$this->increment_warning( $summary_warnings, 'missing_prefight_record', $bout_id, $warnings_count );
				}


				if ( 'win' === $result && ( empty( $bout['method_category'] ) || 'unknown' === (string) $bout['method_category'] ) ) {
					$this->increment_warning( $summary_warnings, 'missing_method_category', $bout_id, $warnings_count );
				}

			}
		}

		$strategy_counts = array( 'carried' => 0, 'calculated' => 0 );
		$stats_rows = ( function() use ( &$strategy_counts ) {
			foreach ( $this->stats_rows() as $row ) {
				$strategy = (string) ( $row['_tracking_stats_strategy'] ?? 'calculated' );
				$strategy_counts[ $strategy ] = (int) ( $strategy_counts[ $strategy ] ?? 0 ) + 1;
				yield $row;
			}
		} )();
		$this->stats->replace_all( $stats_rows );
        $coverageTable=\MMAF\DataEngine\Migrations\Schema::table_names()['fighter_career_coverage']; $last=0;
        $career=new \MMAF\DataEngine\Services\RegularImport\CareerHistory();
        do {
            global $wpdb;
            $coverages=$wpdb->get_results($wpdb->prepare("SELECT * FROM $coverageTable WHERE fighter_id>%d ORDER BY fighter_id LIMIT 250",$last),ARRAY_A);
            if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
            foreach ($coverages as $coverage) { $last=(int)$coverage['fighter_id']; $career->refresh_record_coverage($coverage,$actor_user_id); }
        } while(count($coverages)===250);
		$tracking = ( new TrackingService() )->refresh_paused_candidates( current_time( 'Y-m-d' ), $actor_user_id );


		$summary = array(
			'rebuilt_at'             => DateTime::mysql_now(),
			'fighters_total'         => $fighters_total,
			'stats_rows_written'     => $fighters_total,
			'countable_bouts'        => $countable_bouts,
			'skipped_bouts'          => $skipped_bouts,
			'malformed_bouts'        => $malformed_bouts,
			'participants_processed' => $participants_processed,
			'stats_rows_without_tracking_optimization' => $fighters_total,
			'stats_rows_recalculated_after_tracking' => (int) $strategy_counts['calculated'],
			'stats_rows_carried'    => (int) $strategy_counts['carried'],
			'stats_rows_work_avoided' => (int) $strategy_counts['carried'],
			'paused_fighters_checked' => (int) $tracking['checked'],
			'paused_profile_refresh_pending' => (int) ( $tracking['profile_refresh_pending'] ?? 0 ),
			'return_candidates'     => (int) $tracking['candidates'],
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

		do_action( 'mmaf_public_data_changed', 'stats' );
		return $summary;
	}

	/** Materialize only one indexed fighter batch and its history at a time. */
    public function diagnostics_batch(iterable $bouts): array {
		$warnings_count = 0;
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
			$participants = $bout['_participants'];

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

				if ( 1 === (int) $participant['fighter_deleted_soft'] ) {
					$this->increment_warning( $summary_warnings, 'soft_deleted_fighter_skipped', $fighter_id, $warnings_count );
					continue;
				}

				if ( ! in_array( $result, self::COUNTABLE_RESULTS, true ) ) {
					$this->increment_warning( $summary_warnings, 'invalid_result_for_fighter', $bout_id, $warnings_count );
					continue;
				}

				if ( $this->participant_missing_prefight_record( $participant ) ) {
					$this->increment_warning( $summary_warnings, 'missing_prefight_record', $bout_id, $warnings_count );
				}


				if ( 'win' === $result && ( empty( $bout['method_category'] ) || 'unknown' === (string) $bout['method_category'] ) ) {
					$this->increment_warning( $summary_warnings, 'missing_method_category', $bout_id, $warnings_count );
				}

			}
		}

        return ['warnings_count'=>$warnings_count,'countable_bouts'=>$countable_bouts,'skipped_bouts'=>$skipped_bouts,'malformed_bouts'=>$malformed_bouts,'participants_processed'=>$participants_processed,'warnings'=>array_filter($summary_warnings)];
    }

	public function stats_rows(?array $fighter_ids=null): \Generator {
		foreach (null===$fighter_ids?$this->stats->fighter_batches():[$fighter_ids] as $ids) {
			$ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
			$carried=$this->carried_stats($ids);$calculate=array_values(array_diff($ids,array_keys($carried)));
			foreach($carried as $row){$row['_tracking_stats_strategy']='carried';yield $row;}
			$rows=[]; foreach ($calculate as $id) { $rows[$id]=$this->empty_fighter_stats($id); }
			foreach ($this->stats->history_for_fighters($calculate) as $bout) {
				$id=(int)$bout['fighter_id']; $result=(string)$bout['result_for_fighter'];
				if ((int)$bout['participant_count']!==2 || (int)$bout['deleted_soft']===1 || !in_array($bout['status'],self::COUNTABLE_STATUSES,true)) { continue; }
				if (!in_array($result,self::COUNTABLE_RESULTS,true)) {
					if ((int)$bout['countable_participants']>0) { $this->add_fighter_warning($rows[$id],'invalid_result_for_fighter'); }
					continue;
				}
				if ($this->participant_missing_prefight_record($bout)) { $this->add_fighter_warning($rows[$id],'missing_prefight_record'); }
				if (empty($bout['event_date'])) { $this->add_fighter_warning($rows[$id],'missing_event_date'); }
				if ($result==='win' && (empty($bout['method_category']) || $bout['method_category']==='unknown')) { $this->add_fighter_warning($rows[$id],'missing_method_category'); }
				$this->apply_participant_result($rows[$id],$bout,$result);
			}
			foreach ($rows as $row) { $this->finalize_fighter_stats($row); $database=$this->database_row($row);$database['_tracking_stats_strategy']='calculated';yield $database; }
		}
	}

	/** Reuse the last durable aggregate for permanent opponent-only identities. */
	private function carried_stats(array $ids): array {
		global $wpdb;if(!$ids){return [];}$t=\MMAF\DataEngine\Migrations\Schema::table_names();$in=implode(',',$ids);
		$rows=$wpdb->get_results("SELECT s.* FROM {$t['fighter_stats_current']} s JOIN {$t['fighters']} f ON f.id=s.fighter_id LEFT JOIN (SELECT p.fighter_id,MAX(p.bout_id) latest_bout_id,MAX(GREATEST(COALESCE(b.updated_at,b.created_at,'1000-01-01 00:00:00'),COALESCE(e.updated_at,e.created_at,'1000-01-01 00:00:00'))) relevant_updated_at FROM {$t['bout_participants']} p JOIN {$t['bouts']} b ON b.id=p.bout_id LEFT JOIN {$t['events']} e ON e.id=b.event_id WHERE p.fighter_id IN ($in) AND b.deleted_soft=0 AND b.status IN ('valid','completed') AND p.result_for_fighter IN ('win','loss','draw','no_contest') AND (e.event_date IS NULL OR e.event_date<=UTC_DATE()) GROUP BY p.fighter_id) latest ON latest.fighter_id=f.id WHERE s.fighter_id IN ($in) AND (f.tracking_mode='opponent_only' OR (f.tracking_mode='paused' AND COALESCE(latest.latest_bout_id,0)<=COALESCE(f.tracking_last_checked_bout_id,0) AND (latest.relevant_updated_at IS NULL OR latest.relevant_updated_at<=f.tracking_last_checked_at)))",ARRAY_A);
		if($wpdb->last_error){throw new \RuntimeException($wpdb->last_error);}$out=[];
		foreach($rows?:[] as $row){unset($row['id'],$row['created_at'],$row['updated_at']);$out[(int)$row['fighter_id']]=$row;}
		return $out;
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
