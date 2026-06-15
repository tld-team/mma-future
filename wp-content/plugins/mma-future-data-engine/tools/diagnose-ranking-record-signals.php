<?php
/**
 * Read-only diagnostics for ranking record/prefight signals.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/diagnose-ranking-record-signals.php [--board=overall]
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;

$board = 'overall';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--board=' ) ) {
		$board = sanitize_key( substr( $arg, 8 ) );
	}
}

global $wpdb;
$tables = Schema::table_names();

$active_run_id = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'active_ranking_run_id'
	)
);

if ( $active_run_id <= 0 ) {
	fwrite( STDERR, "No active ranking run.\n" );
	exit( 1 );
}

$score_distribution = $wpdb->get_results(
	$wpdb->prepare(
		"
		SELECT total_score, COUNT(*) AS rows_count, COUNT(DISTINCT fighter_id) AS fighters_count
		FROM {$tables['ranking_current']}
		WHERE ranking_run_id = %d AND board_key = %s
		GROUP BY total_score
		ORDER BY CAST(total_score AS DECIMAL(10,3)) DESC
		", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_run_id,
		$board
	),
	ARRAY_A
);

$fight_count_distribution = $wpdb->get_results(
	$wpdb->prepare(
		"
		SELECT s.pro_fights_count, COUNT(*) AS fighters_count
		FROM {$tables['ranking_current']} r
		JOIN {$tables['fighter_stats_current']} s ON s.fighter_id = r.fighter_id
		WHERE r.ranking_run_id = %d AND r.board_key = %s
		GROUP BY s.pro_fights_count
		ORDER BY s.pro_fights_count ASC
		", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_run_id,
		$board
	),
	ARRAY_A
);

$record_signal_summary = $wpdb->get_row(
	$wpdb->prepare(
		"
		SELECT
			COUNT(*) AS scoring_rows,
			SUM(CASE WHEN p.prefight_record_raw IS NOT NULL AND p.prefight_record_raw <> '' THEN 1 ELSE 0 END) AS rows_with_fighter_prefight,
			SUM(CASE WHEN p.opponent_prefight_record_raw IS NOT NULL AND p.opponent_prefight_record_raw <> '' THEN 1 ELSE 0 END) AS rows_with_opponent_prefight,
			SUM(CASE WHEN p.opponent_prefight_diff IS NOT NULL THEN 1 ELSE 0 END) AS rows_with_opponent_diff,
			MIN(p.opponent_prefight_diff) AS min_opponent_diff,
			MAX(p.opponent_prefight_diff) AS max_opponent_diff,
			AVG(p.opponent_prefight_diff) AS avg_opponent_diff
		FROM {$tables['ranking_current']} r
		JOIN {$tables['bout_participants']} p ON p.fighter_id = r.fighter_id
		JOIN {$tables['bouts']} b ON b.id = p.bout_id
		WHERE r.ranking_run_id = %d
			AND r.board_key = %s
			AND b.deleted_soft = 0
			AND b.is_scoring_candidate = 1
			AND b.result_type = 'win_loss'
			AND p.result_for_fighter IN ('win', 'loss')
		", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_run_id,
		$board
	),
	ARRAY_A
);

$opponent_diff_distribution = $wpdb->get_results(
	$wpdb->prepare(
		"
		SELECT
			CASE
				WHEN p.opponent_prefight_diff IS NULL THEN 'missing'
				WHEN p.opponent_prefight_diff <= -10 THEN '<=-10'
				WHEN p.opponent_prefight_diff < 0 THEN '-9_to_-1'
				WHEN p.opponent_prefight_diff = 0 THEN '0'
				WHEN p.opponent_prefight_diff <= 5 THEN '1_to_5'
				WHEN p.opponent_prefight_diff <= 10 THEN '6_to_10'
				WHEN p.opponent_prefight_diff <= 30 THEN '11_to_30'
				WHEN p.opponent_prefight_diff <= 60 THEN '31_to_60'
				WHEN p.opponent_prefight_diff <= 100 THEN '61_to_100'
				ELSE '>100'
			END AS bucket,
			COUNT(*) AS rows_count,
			MIN(p.opponent_prefight_diff) AS min_diff,
			MAX(p.opponent_prefight_diff) AS max_diff
		FROM {$tables['ranking_current']} r
		JOIN {$tables['bout_participants']} p ON p.fighter_id = r.fighter_id
		JOIN {$tables['bouts']} b ON b.id = p.bout_id
		WHERE r.ranking_run_id = %d
			AND r.board_key = %s
			AND b.deleted_soft = 0
			AND b.is_scoring_candidate = 1
			AND b.result_type = 'win_loss'
			AND p.result_for_fighter IN ('win', 'loss')
		GROUP BY bucket
		ORDER BY MIN(p.opponent_prefight_diff) ASC
		", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_run_id,
		$board
	),
	ARRAY_A
);

$largest_ties = $wpdb->get_results(
	$wpdb->prepare(
		"
		SELECT
			r.total_score,
			COUNT(*) AS fighters_count,
			GROUP_CONCAT(CONCAT(r.rank_position, ':', r.fighter_id, ':', f.display_name) ORDER BY r.rank_position ASC SEPARATOR ' | ') AS fighters
		FROM {$tables['ranking_current']} r
		JOIN {$tables['fighters']} f ON f.id = r.fighter_id
		WHERE r.ranking_run_id = %d AND r.board_key = %s
		GROUP BY r.total_score
		HAVING fighters_count > 1
		ORDER BY fighters_count DESC, CAST(r.total_score AS DECIMAL(10,3)) DESC
		LIMIT 10
		", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_run_id,
		$board
	),
	ARRAY_A
);

$out = array(
	'active_ranking_run_id'       => $active_run_id,
	'board'                       => $board,
	'score_distribution'          => $score_distribution,
	'fight_count_distribution'    => $fight_count_distribution,
	'record_signal_summary'       => $record_signal_summary,
	'opponent_diff_distribution'  => $opponent_diff_distribution,
	'largest_ties'                => $largest_ties,
	'diagnosis'                   => array(
		'current_formula_uses_opponent_prefight_diff' => true,
		'current_formula_uses_profile_aggregate_record' => false,
		'main_reason_for_many_ties' => 'Ranked fighters currently have shallow canonical history; Formula v1.2 uses bucketed opponent-diff scoring, so one-fight records naturally tie more often than deeper records.',
	),
);

echo wp_json_encode( $out, JSON_PRETTY_PRINT ) . PHP_EOL;
