<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FightHistoryCompletenessAuditService {
	private const COUNTABLE_RESULTS = array( 'win', 'loss', 'draw', 'no_contest' );
	private const FILTERS = array(
		'no_fights',
		'single_known_fight',
		'multiple_canonical_fights',
		'current_record_derivable',
		'full_history_needed',
		'missing_prefight_data',
		'has_tapology_source',
		'missing_profile_fields',
		'record_difference',
	);

	public static function filters(): array {
		return self::FILTERS;
	}

	public function build_report( array $filters, string $search, int $per_page, int $offset ): array {
		$filters = array_values( array_intersect( $filters, self::FILTERS ) );

		return array(
			'summary'        => $this->summary(),
			'recommendation' => $this->recommendation(),
			'total'          => $this->count_rows( $filters, $search ),
			'rows'           => $this->rows( $filters, $search, $per_page, $offset ),
		);
	}

	public function summary(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$facts  = $this->facts_from_sql( $tables );
		$sql    = "
			SELECT
				COUNT(*) AS total_fighters,
				SUM(CASE WHEN countable_fights = 0 THEN 1 ELSE 0 END) AS fighters_zero_canonical_countable_fights,
				SUM(CASE WHEN countable_fights = 1 THEN 1 ELSE 0 END) AS fighters_exactly_one_countable_fight,
				SUM(CASE WHEN countable_fights > 1 THEN 1 ELSE 0 END) AS fighters_multiple_countable_fights,
				SUM(CASE WHEN tapology_source_count > 0 THEN 1 ELSE 0 END) AS fighters_with_tapology_source_mapping,
				SUM(CASE WHEN latest_bout_id IS NOT NULL THEN 1 ELSE 0 END) AS fighters_with_latest_canonical_bout,
				SUM(CASE WHEN latest_bout_id IS NOT NULL AND has_latest_prefight_wl = 1 THEN 1 ELSE 0 END) AS fighters_with_participant_prefight_wl_data,
				SUM(CASE WHEN latest_bout_id IS NOT NULL AND has_latest_prefight_wl = 0 THEN 1 ELSE 0 END) AS fighters_missing_participant_prefight_wl_data,
				SUM(CASE WHEN latest_bout_id IS NOT NULL AND derived_record_available = 1 THEN 1 ELSE 0 END) AS fighters_current_aggregate_record_derivable,
				SUM(CASE WHEN latest_bout_id IS NULL OR derived_record_available = 0 THEN 1 ELSE 0 END) AS fighters_current_aggregate_record_cannot_be_derived,
				SUM(CASE WHEN canonical_finish_wins > 0 THEN 1 ELSE 0 END) AS fighters_with_finish_wins_known_from_canonical_log,
				SUM(CASE WHEN derived_record_available = 1 AND countable_fights <= 1 AND derived_total_fights > countable_fights THEN 1 ELSE 0 END) AS fighters_finish_breakdown_incomplete_latest_only
			FROM (
				{$facts}
			) facts
		";
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_merge(
			array_map( 'intval', is_array( $row ) ? $row : array() ),
			array(
				'bouts_with_missing_prefight_wins_losses' => $this->count_bouts_with_missing_prefight( $tables ),
				'bouts_with_unknown_missing_method'       => $this->count_where( $tables['bouts'], "deleted_soft = 0 AND (method_category IS NULL OR method_category = '' OR method_category = 'unknown')" ),
				'scoring_candidate_bouts'                 => $this->count_where( $tables['bouts'], 'deleted_soft = 0 AND is_scoring_candidate = 1' ),
				'non_scoring_bouts'                       => $this->count_where( $tables['bouts'], 'deleted_soft = 0 AND is_scoring_candidate = 0' ),
				'read_only_note'                          => __( 'This audit reads existing fighter, source, bout, participant, and current stats rows only. It does not write canonical data, rebuild stats, calculate rankings, or activate rankings.', 'mma-future-data-engine' ),
			)
		);
	}

	public function recommendation(): array {
		$summary = $this->summary();
		$total_with_latest = max( 1, (int) ( $summary['fighters_with_latest_canonical_bout'] ?? 0 ) );
		$derivable_ratio = (int) ( $summary['fighters_current_aggregate_record_derivable'] ?? 0 ) / $total_with_latest;
		$missing_prefight_ratio = (int) ( $summary['fighters_missing_participant_prefight_wl_data'] ?? 0 ) / $total_with_latest;
		$finish_incomplete_ratio = (int) ( $summary['fighters_finish_breakdown_incomplete_latest_only'] ?? 0 ) / max( 1, (int) ( $summary['fighters_exactly_one_countable_fight'] ?? 0 ) );

		$recommendations = array();
		if ( $derivable_ratio >= 0.60 ) {
			$recommendations[] = __( 'Many fighters with a latest canonical bout also have prefight W/L data. Consider supporting a clearly labeled interim derived aggregate record from latest prefight record plus latest result.', 'mma-future-data-engine' );
		}
		if ( $missing_prefight_ratio >= 0.30 ) {
			$recommendations[] = __( 'A large share of latest canonical bouts lack prefight W/L data. Full fighter history scraping or profile enrichment should happen before ranking decisions depend on aggregate records.', 'mma-future-data-engine' );
		}
		if ( $finish_incomplete_ratio >= 0.30 ) {
			$recommendations[] = __( 'Finish breakdown is incomplete for many fighters whose latest bout implies prior career history. Use fighter profile enrichment or full fight history enrichment before treating finish rates as complete.', 'mma-future-data-engine' );
		}
		if ( empty( $recommendations ) ) {
			$recommendations[] = __( 'Completeness signals are mixed. Review the high-priority rows before deciding whether interim derived records are acceptable.', 'mma-future-data-engine' );
		}

		return array(
			'latest_bout_prefight_derivable_ratio' => number_format( $derivable_ratio * 100, 1 ) . '%',
			'latest_bout_missing_prefight_ratio'   => number_format( $missing_prefight_ratio * 100, 1 ) . '%',
			'latest_only_finish_gap_ratio'         => number_format( $finish_incomplete_ratio * 100, 1 ) . '%',
			'recommendation'                       => implode( ' ', $recommendations ),
		);
	}

	private function count_rows( array $filters, string $search ): int {
		global $wpdb;

		$base = $this->base_sql( $filters, $search );
		$sql  = 'SELECT COUNT(*) ' . $base['from'] . ' WHERE ' . implode( ' AND ', $base['where'] );

		if ( ! empty( $base['args'] ) ) {
			$sql = $wpdb->prepare( $sql, $base['args'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function rows( array $filters, string $search, int $per_page, int $offset ): array {
		global $wpdb;

		$base = $this->base_sql( $filters, $search );
		$sql  = "
			SELECT *
			" . $base['from'] . '
			WHERE ' . implode( ' AND ', $base['where'] ) . '
			ORDER BY record_completeness_priority DESC, countable_fights ASC, latest_fight_date DESC, display_name ASC, id ASC
			LIMIT %d OFFSET %d';
		$args = array_merge( $base['args'], array( $per_page, $offset ) );
		$sql  = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'prepare_row' ), $rows );
	}

	private function base_sql( array $filters, string $search ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$where  = array( 'facts.deleted_soft = 0' );
		$args   = array();

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(facts.display_name LIKE %s OR facts.normalized_name LIKE %s OR facts.tapology_source_url LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		foreach ( $filters as $filter ) {
			$condition = $this->filter_condition( $filter );
			if ( '' !== $condition ) {
				$where[] = $condition;
			}
		}

		$from = 'FROM (' . $this->facts_from_sql( $tables ) . ') facts';

		return array(
			'from'  => $from,
			'where' => $where,
			'args'  => $args,
		);
	}

	private function facts_from_sql( array $tables ): string {
		$countable_results = "'" . implode( "', '", self::COUNTABLE_RESULTS ) . "'";

		return "
			SELECT
				f.id,
				f.display_name,
				f.normalized_name,
				f.status,
				f.rankability_status,
				f.is_public,
				f.is_rankable,
				f.deleted_soft,
				f.date_of_birth,
				f.birth_year,
				f.gender,
				f.weight_class,
				COALESCE(src.source_mapping_count, 0) AS source_mapping_count,
				COALESCE(src.tapology_source_count, 0) AS tapology_source_count,
				src.tapology_source_url,
				COALESCE(ca.countable_fights, 0) AS countable_fights,
				COALESCE(ca.canonical_finish_wins, 0) AS canonical_finish_wins,
				latest.bout_id AS latest_bout_id,
				latest.event_date AS latest_fight_date,
				latest.result_for_fighter AS latest_bout_result,
				latest.opponent_name AS latest_bout_opponent,
				latest.prefight_wins,
				latest.prefight_losses,
				latest.prefight_draws,
				latest.prefight_nc,
				latest.prefight_record_raw,
				CASE WHEN latest.prefight_wins IS NOT NULL AND latest.prefight_losses IS NOT NULL THEN 1 ELSE 0 END AS has_latest_prefight_wl,
				CASE WHEN latest.prefight_wins IS NOT NULL AND latest.prefight_losses IS NOT NULL AND latest.prefight_draws IS NOT NULL AND latest.prefight_nc IS NOT NULL AND latest.result_for_fighter IN ({$countable_results}) THEN 1 ELSE 0 END AS derived_record_available,
				CASE
					WHEN latest.prefight_wins IS NOT NULL AND latest.prefight_losses IS NOT NULL AND latest.prefight_draws IS NOT NULL AND latest.prefight_nc IS NOT NULL AND latest.result_for_fighter IN ({$countable_results})
					THEN latest.prefight_wins + CASE WHEN latest.result_for_fighter = 'win' THEN 1 ELSE 0 END
					ELSE NULL
				END AS derived_wins,
				CASE
					WHEN latest.prefight_wins IS NOT NULL AND latest.prefight_losses IS NOT NULL AND latest.prefight_draws IS NOT NULL AND latest.prefight_nc IS NOT NULL AND latest.result_for_fighter IN ({$countable_results})
					THEN latest.prefight_losses + CASE WHEN latest.result_for_fighter = 'loss' THEN 1 ELSE 0 END
					ELSE NULL
				END AS derived_losses,
				CASE
					WHEN latest.prefight_wins IS NOT NULL AND latest.prefight_losses IS NOT NULL AND latest.prefight_draws IS NOT NULL AND latest.prefight_nc IS NOT NULL AND latest.result_for_fighter IN ({$countable_results})
					THEN latest.prefight_draws + CASE WHEN latest.result_for_fighter = 'draw' THEN 1 ELSE 0 END
					ELSE NULL
				END AS derived_draws,
				CASE
					WHEN latest.prefight_wins IS NOT NULL AND latest.prefight_losses IS NOT NULL AND latest.prefight_draws IS NOT NULL AND latest.prefight_nc IS NOT NULL AND latest.result_for_fighter IN ({$countable_results})
					THEN latest.prefight_nc + CASE WHEN latest.result_for_fighter = 'no_contest' THEN 1 ELSE 0 END
					ELSE NULL
				END AS derived_nc,
				CASE
					WHEN latest.prefight_wins IS NOT NULL AND latest.prefight_losses IS NOT NULL AND latest.prefight_draws IS NOT NULL AND latest.prefight_nc IS NOT NULL AND latest.result_for_fighter IN ({$countable_results})
					THEN latest.prefight_wins + latest.prefight_losses + latest.prefight_draws + latest.prefight_nc + 1
					ELSE NULL
				END AS derived_total_fights,
				COALESCE(st.wins, 0) AS stats_wins,
				COALESCE(st.losses, 0) AS stats_losses,
				COALESCE(st.draws, 0) AS stats_draws,
				COALESCE(st.nc, 0) AS stats_nc,
				COALESCE(st.pro_fights_count, 0) AS stats_pro_fights_count,
				COALESCE(st.finish_wins, 0) AS stats_finish_wins,
				(
					CASE WHEN COALESCE(ca.countable_fights, 0) = 1 THEN 35 ELSE 0 END
					+ CASE WHEN latest.prefight_wins IS NULL OR latest.prefight_losses IS NULL THEN 30 ELSE 0 END
					+ CASE WHEN latest.prefight_wins IS NOT NULL AND latest.prefight_losses IS NOT NULL AND latest.prefight_draws IS NOT NULL AND latest.prefight_nc IS NOT NULL AND latest.result_for_fighter IN ({$countable_results}) AND latest.prefight_wins + latest.prefight_losses + latest.prefight_draws + latest.prefight_nc + 1 > COALESCE(ca.countable_fights, 0) THEN 30 ELSE 0 END
					+ CASE WHEN COALESCE(src.tapology_source_count, 0) > 0 THEN 10 ELSE 0 END
					+ CASE WHEN f.is_public = 1 OR f.is_rankable = 1 THEN 10 ELSE 0 END
					+ CASE WHEN f.date_of_birth IS NULL AND f.birth_year IS NULL THEN 4 ELSE 0 END
					+ CASE WHEN f.gender IS NULL OR f.gender = '' OR f.gender = 'unknown' THEN 4 ELSE 0 END
					+ CASE WHEN f.weight_class IS NULL OR f.weight_class = '' OR f.weight_class = 'unknown' THEN 4 ELSE 0 END
					- CASE WHEN COALESCE(ca.countable_fights, 0) = 0 THEN 40 ELSE 0 END
				) AS record_completeness_priority
			FROM {$tables['fighters']} f
			LEFT JOIN {$tables['fighter_stats_current']} st ON st.fighter_id = f.id
			LEFT JOIN (
				SELECT
					fighter_id,
					COUNT(*) AS source_mapping_count,
					SUM(CASE WHEN source_type = 'tapology' THEN 1 ELSE 0 END) AS tapology_source_count,
					MIN(CASE WHEN source_type = 'tapology' THEN source_url ELSE NULL END) AS tapology_source_url
				FROM {$tables['fighter_sources']}
				WHERE fighter_id IS NOT NULL
				GROUP BY fighter_id
			) src ON src.fighter_id = f.id
			LEFT JOIN (
				SELECT
					bp.fighter_id,
					COUNT(*) AS countable_fights,
					SUM(CASE WHEN bp.result_for_fighter = 'win' AND b.method_category IN ('ko_tko', 'submission') THEN 1 ELSE 0 END) AS canonical_finish_wins
				FROM {$tables['bout_participants']} bp
				INNER JOIN {$tables['bouts']} b ON b.id = bp.bout_id
				WHERE bp.fighter_id IS NOT NULL
					AND b.deleted_soft = 0
					AND b.status IN ('valid', 'completed')
					AND bp.result_for_fighter IN ({$countable_results})
				GROUP BY bp.fighter_id
			) ca ON ca.fighter_id = f.id
			LEFT JOIN (
				SELECT latest_rows.*
				FROM (
					SELECT
						lp.fighter_id,
						SUBSTRING_INDEX(GROUP_CONCAT(lp.participant_id ORDER BY lp.event_date_sort DESC, lp.bout_order_sort DESC, lp.bout_id DESC, lp.participant_id DESC SEPARATOR ','), ',', 1) AS latest_participant_id
					FROM (
						SELECT
							bp.id AS participant_id,
							bp.fighter_id,
							bp.bout_id,
							COALESCE(e.event_date, '0000-00-00') AS event_date_sort,
							COALESCE(b.bout_order, 0) AS bout_order_sort
						FROM {$tables['bout_participants']} bp
						INNER JOIN {$tables['bouts']} b ON b.id = bp.bout_id
						LEFT JOIN {$tables['events']} e ON e.id = b.event_id
						WHERE bp.fighter_id IS NOT NULL
							AND b.deleted_soft = 0
							AND b.status IN ('valid', 'completed')
							AND bp.result_for_fighter IN ({$countable_results})
					) lp
					GROUP BY lp.fighter_id
				) latest_ids
				INNER JOIN (
					SELECT
						bp.id AS participant_id,
						bp.fighter_id,
						bp.bout_id,
						e.event_date,
						bp.result_for_fighter,
						bp.prefight_wins,
						bp.prefight_losses,
						bp.prefight_draws,
						bp.prefight_nc,
						bp.prefight_record_raw,
						COALESCE(op.display_name, '') AS opponent_name
					FROM {$tables['bout_participants']} bp
					INNER JOIN {$tables['bouts']} b ON b.id = bp.bout_id
					LEFT JOIN {$tables['events']} e ON e.id = b.event_id
					LEFT JOIN {$tables['fighters']} op ON op.id = bp.opponent_fighter_id
				) latest_rows ON latest_rows.participant_id = latest_ids.latest_participant_id
			) latest ON latest.fighter_id = f.id
		";
	}

	private function filter_condition( string $filter ): string {
		switch ( $filter ) {
			case 'no_fights':
				return 'facts.countable_fights = 0';
			case 'single_known_fight':
				return 'facts.countable_fights = 1';
			case 'multiple_canonical_fights':
				return 'facts.countable_fights > 1';
			case 'current_record_derivable':
				return 'facts.derived_record_available = 1';
			case 'full_history_needed':
				return 'facts.derived_record_available = 1 AND facts.derived_total_fights > facts.countable_fights';
			case 'missing_prefight_data':
				return 'facts.latest_bout_id IS NOT NULL AND facts.has_latest_prefight_wl = 0';
			case 'has_tapology_source':
				return 'facts.tapology_source_count > 0';
			case 'missing_profile_fields':
				return "(facts.date_of_birth IS NULL AND facts.birth_year IS NULL) OR (facts.gender IS NULL OR facts.gender = '' OR facts.gender = 'unknown') OR (facts.weight_class IS NULL OR facts.weight_class = '' OR facts.weight_class = 'unknown')";
			case 'record_difference':
				return 'facts.derived_record_available = 1 AND (facts.derived_wins <> facts.stats_wins OR facts.derived_losses <> facts.stats_losses OR facts.derived_draws <> facts.stats_draws OR facts.derived_nc <> facts.stats_nc)';
		}

		return '';
	}

	private function prepare_row( array $row ): array {
		$row['derived_record'] = $this->record_string( $row['derived_wins'], $row['derived_losses'], $row['derived_draws'], $row['derived_nc'] );
		$row['stats_record']   = $this->record_string( $row['stats_wins'], $row['stats_losses'], $row['stats_draws'], $row['stats_nc'] );
		$row['record_difference'] = $this->record_difference( $row );
		$row['missing_profile_flags'] = $this->missing_profile_flags( $row );
		$row['record_completeness_status'] = $this->record_completeness_status( $row );
		$row['suggested_next_action'] = $this->suggested_next_action( $row );

		return $row;
	}

	private function record_string( $wins, $losses, $draws, $nc ): string {
		if ( null === $wins || null === $losses || null === $draws || null === $nc || '' === (string) $wins || '' === (string) $losses || '' === (string) $draws || '' === (string) $nc ) {
			return '-';
		}

		return sprintf( '%d-%d-%d-%d', (int) $wins, (int) $losses, (int) $draws, (int) $nc );
	}

	private function record_difference( array $row ): string {
		if ( 1 !== (int) $row['derived_record_available'] ) {
			return '-';
		}

		$diffs = array();
		foreach ( array( 'wins', 'losses', 'draws', 'nc' ) as $field ) {
			$diff = (int) $row[ 'derived_' . $field ] - (int) $row[ 'stats_' . $field ];
			if ( 0 !== $diff ) {
				$diffs[] = strtoupper( 'nc' === $field ? 'NC' : substr( $field, 0, 1 ) ) . ( $diff > 0 ? '+' : '' ) . $diff;
			}
		}

		return empty( $diffs ) ? __( 'none detected', 'mma-future-data-engine' ) : implode( ', ', $diffs );
	}

	private function missing_profile_flags( array $row ): string {
		$flags = array();
		if ( empty( $row['date_of_birth'] ) && empty( $row['birth_year'] ) ) {
			$flags[] = __( 'DOB', 'mma-future-data-engine' );
		}
		if ( empty( $row['gender'] ) || 'unknown' === (string) $row['gender'] ) {
			$flags[] = __( 'gender', 'mma-future-data-engine' );
		}
		if ( empty( $row['weight_class'] ) || 'unknown' === (string) $row['weight_class'] ) {
			$flags[] = __( 'weight', 'mma-future-data-engine' );
		}

		return empty( $flags ) ? '-' : implode( ', ', $flags );
	}

	private function record_completeness_status( array $row ): string {
		$countable = (int) $row['countable_fights'];

		if ( 0 === $countable ) {
			return __( 'no fights', 'mma-future-data-engine' );
		}
		if ( 1 !== (int) $row['has_latest_prefight_wl'] ) {
			return __( 'missing prefight data', 'mma-future-data-engine' );
		}
		if ( 1 === (int) $row['derived_record_available'] && (int) $row['derived_total_fights'] > $countable ) {
			return __( 'full history needed', 'mma-future-data-engine' );
		}
		if ( 1 === (int) $row['derived_record_available'] ) {
			return __( 'current record derivable from latest prefight', 'mma-future-data-engine' );
		}
		if ( 1 === $countable ) {
			return __( 'single known fight only', 'mma-future-data-engine' );
		}

		return __( 'multiple canonical fights', 'mma-future-data-engine' );
	}

	private function suggested_next_action( array $row ): string {
		$countable = (int) $row['countable_fights'];

		if ( 0 === $countable ) {
			return __( 'low priority/no fights', 'mma-future-data-engine' );
		}
		if ( 0 === (int) $row['tapology_source_count'] ) {
			return __( 'scrape fighter profile', 'mma-future-data-engine' );
		}
		if ( 1 === (int) $row['derived_record_available'] && (int) $row['derived_total_fights'] > $countable ) {
			return __( 'scrape full fight history', 'mma-future-data-engine' );
		}
		if ( 1 !== (int) $row['has_latest_prefight_wl'] ) {
			return __( 'needs manual review', 'mma-future-data-engine' );
		}
		if ( 1 === (int) $row['derived_record_available'] ) {
			return __( 'derive current record from prefight + latest result', 'mma-future-data-engine' );
		}

		return __( 'needs manual review', 'mma-future-data-engine' );
	}

	private function count_bouts_with_missing_prefight( array $tables ): int {
		global $wpdb;

		$countable_results = "'" . implode( "', '", self::COUNTABLE_RESULTS ) . "'";

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT b.id)
			FROM {$tables['bouts']} b
			INNER JOIN {$tables['bout_participants']} bp ON bp.bout_id = b.id
			WHERE b.deleted_soft = 0
				AND b.status IN ('valid', 'completed')
				AND bp.result_for_fighter IN ({$countable_results})
				AND (bp.prefight_wins IS NULL OR bp.prefight_losses IS NULL)
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_where( string $table, string $where ): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
