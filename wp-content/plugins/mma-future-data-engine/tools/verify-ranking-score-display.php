<?php
/**
 * Read-only ranking score/admin display audit.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/verify-ranking-score-display.php [--run-id=7] [--board=overall]
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;

$args = array();
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--' ) && false !== strpos( $arg, '=' ) ) {
		list( $key, $value ) = explode( '=', substr( $arg, 2 ), 2 );
		$args[ $key ] = $value;
	}
}

global $wpdb;

$tables = Schema::table_names();
$run_id = isset( $args['run-id'] ) ? max( 0, (int) $args['run-id'] ) : 0;
$board  = isset( $args['board'] ) ? sanitize_key( (string) $args['board'] ) : 'overall';

if ( $run_id <= 0 ) {
	$run_id = (int) $wpdb->get_var( "SELECT id FROM {$tables['ranking_runs']} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$latest_run = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$tables['ranking_runs']} WHERE id = %d LIMIT 1", $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ARRAY_A
);

if ( ! $latest_run ) {
	fwrite( STDERR, "Ranking run not found.\n" );
	exit( 1 );
}

$active_run = $wpdb->get_row(
	"SELECT * FROM {$tables['ranking_runs']} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ARRAY_A
);

$boards = $wpdb->get_results(
	$wpdb->prepare(
		"
		SELECT board_key, COUNT(*) AS rows_count, COUNT(DISTINCT fighter_id) AS unique_fighters
		FROM {$tables['ranking_snapshots']}
		WHERE ranking_run_id = %d
		GROUP BY board_key
		ORDER BY CASE WHEN board_key = 'overall' THEN 0 ELSE 1 END, board_key ASC
		", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$run_id
	),
	ARRAY_A
);

$board_keys = array_map( static fn( array $row ): string => (string) $row['board_key'], $boards );
if ( ! in_array( $board, $board_keys, true ) ) {
	$board = in_array( 'overall', $board_keys, true ) ? 'overall' : (string) ( $board_keys[0] ?? '' );
}

$board_rows = array();
if ( '' !== $board ) {
	$board_rows = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT r.*, f.display_name, f.date_of_birth, f.birth_year, f.gender, f.weight_class
			FROM {$tables['ranking_snapshots']} r
			LEFT JOIN {$tables['fighters']} f ON f.id = r.fighter_id
			WHERE r.ranking_run_id = %d AND r.board_key = %s
			ORDER BY r.rank_position ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$run_id,
			$board
		),
		ARRAY_A
	);
}

$sample_rows = select_sample_rows( $board_rows, $run_id, $tables );
$sample_audits = array();
$critical = array();

foreach ( $sample_rows as $row ) {
	$audit = audit_row( $row, $run_id, $tables );
	$sample_audits[] = $audit;
	if ( ! $audit['matches']['db_total_equals_breakdown_total'] || ! $audit['matches']['db_total_equals_breakdown_sum'] || ! $audit['matches']['db_total_equals_canonical_expected'] ) {
		$critical[] = $audit['fighter_id'] . ':' . $audit['board_key'];
	}
}

$integrity = integrity_report( $run_id, $tables );
if ( $integrity['duplicate_rank_positions_count'] > 0 || $integrity['non_contiguous_boards_count'] > 0 ) {
	$critical[] = 'rank_integrity';
}

$out = array(
	'ok' => empty( $critical ),
	'source_table' => 'wp_mmaf_ranking_snapshots',
	'run' => array(
		'id' => (int) $latest_run['id'],
		'status' => (string) $latest_run['status'],
		'is_active' => (int) $latest_run['is_active'],
		'formula_version' => (string) $latest_run['formula_version'],
		'reference_date' => (string) $latest_run['reference_date'],
		'calculated_at' => (string) $latest_run['calculated_at'],
	),
	'active_run_id' => $active_run ? (int) $active_run['id'] : null,
	'selected_board' => $board,
	'board_rows_count' => count( $board_rows ),
	'board_unique_fighters' => count( array_unique( array_map( static fn( array $row ): int => (int) $row['fighter_id'], $board_rows ) ) ),
	'all_boards' => $boards,
	'integrity' => $integrity,
	'tied_score_examples' => tied_score_examples( $run_id, $tables ),
	'samples' => $sample_audits,
	'critical' => $critical,
);

echo wp_json_encode( $out, JSON_PRETTY_PRINT ) . PHP_EOL;

exit( empty( $critical ) ? 0 : 1 );

function select_sample_rows( array $board_rows, int $run_id, array $tables ): array {
	global $wpdb;

	if ( empty( $board_rows ) ) {
		return array();
	}

	$selected = array();
	$add = static function ( array $row ) use ( &$selected ): void {
		$key = (int) $row['fighter_id'] . ':' . (string) $row['board_key'];
		$selected[ $key ] = $row;
	};

	$add( $board_rows[0] );
	$add( $board_rows[(int) floor( ( count( $board_rows ) - 1 ) / 2 )] );
	$add( $board_rows[count( $board_rows ) - 1] );

	$tied = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT r.*
			FROM {$tables['ranking_snapshots']} r
			INNER JOIN (
				SELECT board_key, total_score
				FROM {$tables['ranking_snapshots']}
				WHERE ranking_run_id = %d AND board_key = %s
				GROUP BY board_key, total_score
				HAVING COUNT(*) > 1
				ORDER BY total_score DESC
				LIMIT 1
			) t ON t.board_key = r.board_key AND t.total_score = r.total_score
			WHERE r.ranking_run_id = %d
			ORDER BY r.rank_position ASC
			LIMIT 2
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$run_id,
			(string) $board_rows[0]['board_key'],
			$run_id
		),
		ARRAY_A
	);
	foreach ( $tied as $row ) {
		$add( hydrate_sample_row( $row, $tables ) );
	}

	$multi = $wpdb->get_row(
		$wpdb->prepare(
			"
			SELECT r.*
			FROM {$tables['ranking_snapshots']} r
			INNER JOIN (
				SELECT fighter_id
				FROM {$tables['ranking_snapshots']}
				WHERE ranking_run_id = %d
				GROUP BY fighter_id
				HAVING COUNT(DISTINCT board_key) > 1
				ORDER BY COUNT(DISTINCT board_key) DESC, fighter_id ASC
				LIMIT 1
			) m ON m.fighter_id = r.fighter_id
			WHERE r.ranking_run_id = %d AND r.board_key = %s
			LIMIT 1
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$run_id,
			$run_id,
			(string) $board_rows[0]['board_key']
		),
		ARRAY_A
	);
	if ( $multi ) {
		$add( hydrate_sample_row( $multi, $tables ) );
	}

	foreach ( $board_rows as $row ) {
		if ( count( $selected ) >= 5 ) {
			break;
		}
		$add( $row );
	}

	return array_values( array_slice( $selected, 0, 5 ) );
}

function hydrate_sample_row( array $row, array $tables ): array {
	global $wpdb;

	$extra = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT display_name, date_of_birth, birth_year, gender, weight_class FROM {$tables['fighters']} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $row['fighter_id']
		),
		ARRAY_A
	);

	return array_merge( $row, is_array( $extra ) ? $extra : array() );
}

function audit_row( array $row, int $run_id, array $tables ): array {
	$breakdown = json_decode( (string) ( $row['breakdown_json'] ?? '' ), true );
	$source_summary = json_decode( (string) ( $row['source_summary_json'] ?? '' ), true );
	$db_total = (float) $row['total_score'];
	$breakdown_total = is_array( $breakdown ) ? (float) ( $breakdown['total_score'] ?? 0.0 ) : null;
	$breakdown_sum = is_array( $breakdown ) ? score_round(
		(float) ( $breakdown['base_record_points'] ?? 0.0 )
		+ (float) ( $breakdown['finishes_points'] ?? 0.0 )
		+ (float) ( $breakdown['age_adjustment_points'] ?? 0.0 )
		+ (float) ( $breakdown['opponent_differential_points'] ?? 0.0 )
		+ (float) ( $breakdown['loss_quality_penalty_points'] ?? 0.0 )
	) : null;
	$canonical = canonical_score( (int) $row['fighter_id'], (string) $row['date_of_birth'], $row['birth_year'] ?? null, (string) source_reference_date( $run_id, $tables ), $tables );
	$boards = boards_for_fighter( (int) $row['fighter_id'], $run_id, $tables );

	return array(
		'board_key' => (string) $row['board_key'],
		'rank_position' => (int) $row['rank_position'],
		'fighter_id' => (int) $row['fighter_id'],
		'display_name' => (string) ( $row['display_name'] ?? '' ),
		'db_total_score' => score_round( $db_total ),
		'breakdown_total_score' => null === $breakdown_total ? null : score_round( $breakdown_total ),
		'breakdown_component_sum' => $breakdown_sum,
		'canonical_expected_score' => $canonical['total_score'],
		'stats_row_used_in_snapshot' => is_array( $source_summary ) ? ( $source_summary['stats_row_id'] ?? null ) : null,
		'current_stats_row' => $canonical['stats'],
		'boards_for_fighter' => $boards,
		'canonical_components' => $canonical['components'],
		'canonical_bouts' => $canonical['bouts'],
		'matches' => array(
			'db_total_equals_breakdown_total' => null !== $breakdown_total && scores_equal( $db_total, $breakdown_total ),
			'db_total_equals_breakdown_sum' => null !== $breakdown_sum && scores_equal( $db_total, $breakdown_sum ),
			'db_total_equals_canonical_expected' => scores_equal( $db_total, $canonical['total_score'] ),
		),
	);
}

function canonical_score( int $fighter_id, string $date_of_birth, $birth_year, string $reference_date, array $tables ): array {
	global $wpdb;

	$stats = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$tables['fighter_stats_current']} WHERE fighter_id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT
				p.id AS participant_id,
				p.bout_id,
				p.result_for_fighter,
				p.opponent_fighter_id,
				p.opponent_prefight_wins,
				p.opponent_prefight_losses,
				p.opponent_prefight_diff,
				b.event_id,
				b.status AS bout_status,
				b.deleted_soft AS bout_deleted_soft,
				b.result_type,
				b.method_category,
				b.is_scoring_candidate,
				e.event_date,
				e.deleted_soft AS event_deleted_soft,
				(SELECT COUNT(*) FROM {$tables['bout_participants']} bp WHERE bp.bout_id = p.bout_id) AS participant_count
			FROM {$tables['bout_participants']} p
			LEFT JOIN {$tables['bouts']} b ON b.id = p.bout_id
			LEFT JOIN {$tables['events']} e ON e.id = b.event_id
			WHERE p.fighter_id = %d
			ORDER BY e.event_date ASC, p.bout_id ASC, p.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$fighter_id
		),
		ARRAY_A
	);

	$components = array(
		'base_record_points' => 0.0,
		'wins_points' => 0.0,
		'losses_points' => 0.0,
		'finishes_points' => 0.0,
		'age_adjustment_points' => age_adjustment_points( fighter_age( $date_of_birth, $birth_year, $reference_date ) ),
		'opponent_differential_points' => 0.0,
		'loss_quality_penalty_points' => 0.0,
	);
	$bouts = array();

	foreach ( $rows as $item ) {
		if ( 2 !== (int) $item['participant_count'] ) {
			continue;
		}
		if ( 1 === (int) $item['bout_deleted_soft'] || 1 === (int) $item['event_deleted_soft'] ) {
			continue;
		}
		if ( ! in_array( (string) $item['bout_status'], array( 'valid', 'completed' ), true ) ) {
			continue;
		}
		if ( 1 !== (int) $item['is_scoring_candidate'] || 'win_loss' !== (string) $item['result_type'] ) {
			continue;
		}

		$result = (string) $item['result_for_fighter'];
		if ( ! in_array( $result, array( 'win', 'loss' ), true ) ) {
			continue;
		}

		$method = (string) ( $item['method_category'] ?: 'unknown' );
		$base = 'win' === $result ? 0.5 : -1.5;
		$finish = 'win' === $result && in_array( $method, array( 'ko_tko', 'submission' ), true ) ? 1.0 : 0.0;
		$od = opponent_prefight_diff( $item );
		$od_points = 'win' === $result ? od_points_for_win( $od ) : 0.0;
		$loss_quality = 'loss' === $result ? loss_quality_points( $od ) : 0.0;

		$components['base_record_points'] += $base;
		$components['wins_points'] += 'win' === $result ? $base : 0.0;
		$components['losses_points'] += 'loss' === $result ? $base : 0.0;
		$components['finishes_points'] += $finish;
		$components['opponent_differential_points'] += $od_points;
		$components['loss_quality_penalty_points'] += $loss_quality;

		$bouts[] = array(
			'bout_id' => (int) $item['bout_id'],
			'event_id' => (int) $item['event_id'],
			'event_date' => (string) $item['event_date'],
			'opponent_fighter_id' => null === $item['opponent_fighter_id'] ? null : (int) $item['opponent_fighter_id'],
			'result_for_fighter' => $result,
			'method_category' => $method,
			'opponent_prefight_diff' => $od,
			'base_points' => $base,
			'finish_points' => $finish,
			'opponent_diff_points' => $od_points,
			'loss_quality_penalty' => $loss_quality,
		);
	}

	$total = $components['base_record_points']
		+ $components['finishes_points']
		+ $components['age_adjustment_points']
		+ $components['opponent_differential_points']
		+ $components['loss_quality_penalty_points'];

	return array(
		'total_score' => score_round( $total ),
		'components' => array_map( 'score_round', $components ),
		'bouts' => $bouts,
		'stats' => $stats ? array(
			'id' => (int) $stats['id'],
			'wins' => (int) $stats['wins'],
			'losses' => (int) $stats['losses'],
			'finish_rate' => null === $stats['finish_rate'] ? null : (float) $stats['finish_rate'],
			'last_fight_date' => $stats['last_fight_date'],
			'calculated_at' => $stats['calculated_at'],
		) : null,
	);
}

function fighter_age( string $date_of_birth, $birth_year, string $reference_date ): ?int {
	if ( '' !== $date_of_birth && '0000-00-00' !== $date_of_birth ) {
		try {
			$dob = new DateTimeImmutable( $date_of_birth );
			$ref = new DateTimeImmutable( $reference_date );
			return (int) $dob->diff( $ref )->y;
		} catch ( Throwable $error ) {
			return null;
		}
	}

	return is_numeric( $birth_year ) ? ( (int) substr( $reference_date, 0, 4 ) - (int) $birth_year ) : null;
}

function age_adjustment_points( ?int $age ): float {
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

function opponent_prefight_diff( array $item ): ?int {
	if ( null !== $item['opponent_prefight_diff'] ) {
		return (int) $item['opponent_prefight_diff'];
	}
	if ( null === $item['opponent_prefight_wins'] || null === $item['opponent_prefight_losses'] ) {
		return null;
	}
	return (int) $item['opponent_prefight_wins'] - (int) $item['opponent_prefight_losses'];
}

function od_points_for_win( ?int $od ): float {
	if ( null === $od ) {
		return 0.0;
	}

	if ( $od <= -10 ) {
		return -2.0;
	}
	if ( $od <= -1 ) {
		return 0.0;
	}
	if ( $od <= 10 ) {
		return 1.0;
	}
	if ( $od <= 30 ) {
		return 3.0;
	}
	if ( $od <= 60 ) {
		return 5.0;
	}
	if ( $od <= 100 ) {
		return 7.0;
	}

	return 10.0;
}

function loss_quality_points( ?int $od ): float {
	if ( null === $od ) {
		return 0.0;
	}

	if ( $od > 0 ) {
		return 0.0;
	}

	if ( $od >= -10 ) {
		return -2.0;
	}

	if ( $od >= -20 ) {
		return -5.0;
	}

	return -10.0;
}

function integrity_report( int $run_id, array $tables ): array {
	global $wpdb;

	$duplicate_ranks = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT board_key, rank_position, COUNT(*) AS row_count
			FROM {$tables['ranking_snapshots']}
			WHERE ranking_run_id = %d
			GROUP BY board_key, rank_position
			HAVING row_count > 1
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$run_id
		),
		ARRAY_A
	);

	$boards = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT board_key, COUNT(*) AS rows_count, MIN(rank_position) AS min_rank, MAX(rank_position) AS max_rank
			FROM {$tables['ranking_snapshots']}
			WHERE ranking_run_id = %d
			GROUP BY board_key
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$run_id
		),
		ARRAY_A
	);

	$non_contiguous = array();
	foreach ( $boards as $board ) {
		if ( 1 !== (int) $board['min_rank'] || (int) $board['rows_count'] !== (int) $board['max_rank'] ) {
			$non_contiguous[] = $board;
		}
	}

	return array(
		'duplicate_rank_positions_count' => count( $duplicate_ranks ),
		'duplicate_rank_positions' => $duplicate_ranks,
		'non_contiguous_boards_count' => count( $non_contiguous ),
		'non_contiguous_boards' => $non_contiguous,
	);
}

function tied_score_examples( int $run_id, array $tables ): array {
	global $wpdb;

	return $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT board_key, total_score, COUNT(*) AS row_count, GROUP_CONCAT(CONCAT(rank_position, ':', fighter_id) ORDER BY rank_position SEPARATOR ',') AS rows_list
			FROM {$tables['ranking_snapshots']}
			WHERE ranking_run_id = %d
			GROUP BY board_key, total_score
			HAVING row_count > 1
			ORDER BY row_count DESC, board_key ASC, total_score DESC
			LIMIT 10
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$run_id
		),
		ARRAY_A
	);
}

function source_reference_date( int $run_id, array $tables ): string {
	global $wpdb;

	return (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT reference_date FROM {$tables['ranking_runs']} WHERE id = %d LIMIT 1", $run_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

function boards_for_fighter( int $fighter_id, int $run_id, array $tables ): array {
	global $wpdb;

	$rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT board_key FROM {$tables['ranking_snapshots']} WHERE ranking_run_id = %d AND fighter_id = %d ORDER BY board_key ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$run_id,
			$fighter_id
		)
	);

	return array_values( array_map( 'strval', is_array( $rows ) ? $rows : array() ) );
}

function score_round( $value ): float {
	return round( (float) $value, 3 );
}

function scores_equal( $a, $b ): bool {
	return abs( score_round( $a ) - score_round( $b ) ) < 0.0005;
}
