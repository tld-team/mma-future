<?php
/**
 * Read-only integrity report for canonical data, imports, stats, and rankings.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/verify-data-pipeline-integrity.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\Import\FighterIdentityPreviewService;
use MMAF\DataEngine\Services\Import\ScraperJsonImportService;
use MMAF\DataEngine\Support\TapologyFighterUrl;

global $wpdb;

$tables = Schema::table_names();
$critical_failures = array();
$warnings = array();
$info = array(
	'read_only' => true,
	'generated_at' => current_time( 'mysql' ),
);

function mmaf_vdpi_count( string $sql ): int {
	global $wpdb;

	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

function mmaf_vdpi_rows( string $sql ): array {
	global $wpdb;

	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return is_array( $rows ) ? $rows : array();
}

function mmaf_vdpi_prepared_rows( string $sql, array $args ): array {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return is_array( $rows ) ? $rows : array();
}

function mmaf_vdpi_prepared_var( string $sql, array $args ): string {
	global $wpdb;

	$value = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return null === $value ? '' : (string) $value;
}

function mmaf_vdpi_json( ?string $json ): array {
	$decoded = json_decode( (string) $json, true );
	return is_array( $decoded ) ? $decoded : array();
}

function mmaf_vdpi_age( array $row, string $reference_date ): ?int {
	if ( ! empty( $row['date_of_birth'] ) ) {
		$birth = strtotime( (string) $row['date_of_birth'] );
		$ref = strtotime( $reference_date );
		if ( false !== $birth && false !== $ref ) {
			return (int) gmdate( 'Y', $ref ) - (int) gmdate( 'Y', $birth ) - ( gmdate( 'md', $ref ) < gmdate( 'md', $birth ) ? 1 : 0 );
		}
	}

	if ( ! empty( $row['birth_year'] ) && preg_match( '/^\d{4}$/', (string) $row['birth_year'] ) ) {
		return max( 0, (int) substr( $reference_date, 0, 4 ) - (int) $row['birth_year'] );
	}

	return null;
}

function mmaf_vdpi_tie_decider( array $ordered_rows, string $reference_date ): string {
	if ( count( $ordered_rows ) < 2 ) {
		return 'none';
	}

	$fields = array( 'wins', 'finish_rate', 'age', 'last_fight_date', 'fighter_id' );
	foreach ( $fields as $field ) {
		$values = array();
		foreach ( $ordered_rows as $row ) {
			if ( 'age' === $field ) {
				$values[] = mmaf_vdpi_age( $row, $reference_date );
			} else {
				$values[] = $row[ $field ] ?? null;
			}
		}

		if ( count( array_unique( array_map( static fn( $value ): string => (string) $value, $values ) ) ) > 1 ) {
			return $field;
		}
	}

	return 'all_tie_fields_equal';
}

function mmaf_vdpi_summary_from_run( ?array $run ): array {
	if ( ! $run ) {
		return array();
	}

	return mmaf_vdpi_json( $run['notes'] ?? '' );
}

function mmaf_vdpi_status_label( ?array $run ): ?array {
	if ( ! $run ) {
		return null;
	}

	return array(
		'id' => (int) $run['id'],
		'status' => (string) $run['status'],
		'is_active' => (int) $run['is_active'],
		'reference_date' => (string) $run['reference_date'],
		'calculated_at' => (string) $run['calculated_at'],
	);
}

$canonical = array(
	'fighters' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['fighters']} WHERE deleted_soft = 0" ),
	'tapology_mapped_fighters' => mmaf_vdpi_count( "SELECT COUNT(DISTINCT fighter_id) FROM {$tables['fighter_sources']} WHERE source_type = 'tapology' AND fighter_id IS NOT NULL" ),
	'events' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['events']} WHERE deleted_soft = 0" ),
	'bouts' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['bouts']} WHERE deleted_soft = 0" ),
	'bout_participants' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['bout_participants']}" ),
	'malformed_bouts' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM {$tables['bouts']} b
		LEFT JOIN (
			SELECT bout_id, COUNT(*) AS participant_count
			FROM {$tables['bout_participants']}
			GROUP BY bout_id
		) p ON p.bout_id = b.id
		WHERE COALESCE(p.participant_count, 0) <> 2
		"
	),
	'same_fighter_bouts' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT bout_id, COUNT(DISTINCT fighter_id) AS fighter_count, COUNT(*) AS row_count
			FROM {$tables['bout_participants']}
			WHERE fighter_id IS NOT NULL
			GROUP BY bout_id
			HAVING row_count = 2 AND fighter_count < 2
		) same_fighter
		"
	),
	'bouts_with_participant_count_not_2' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT b.id, COUNT(p.id) AS participant_count
			FROM {$tables['bouts']} b
			LEFT JOIN {$tables['bout_participants']} p ON p.bout_id = b.id
			GROUP BY b.id
			HAVING participant_count <> 2
		) malformed
		"
	),
	'participants_missing_fighter_id' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE fighter_id IS NULL OR fighter_id = 0" ),
	'participants_missing_opponent_fighter_id' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE opponent_fighter_id IS NULL OR opponent_fighter_id = 0" ),
);

$historical = array(
	'countable_bouts' => mmaf_vdpi_count(
		"
		SELECT COUNT(DISTINCT b.id)
		FROM {$tables['bouts']} b
		INNER JOIN {$tables['events']} e ON e.id = b.event_id AND e.deleted_soft = 0
		INNER JOIN {$tables['bout_participants']} p ON p.bout_id = b.id
		WHERE b.deleted_soft = 0
			AND b.status IN ('valid', 'completed')
			AND p.result_for_fighter IN ('win', 'loss', 'draw', 'no_contest')
		"
	),
	'scoring_participant_rows' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM {$tables['bout_participants']} p
		INNER JOIN {$tables['bouts']} b ON b.id = p.bout_id
		INNER JOIN {$tables['events']} e ON e.id = b.event_id AND e.deleted_soft = 0
		WHERE b.deleted_soft = 0
			AND b.status IN ('valid', 'completed')
			AND b.is_scoring_candidate = 1
			AND b.result_type = 'win_loss'
			AND p.result_for_fighter IN ('win', 'loss')
		"
	),
	'rows_with_complete_fighter_prefight_record' => mmaf_vdpi_count(
		"SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE prefight_wins IS NOT NULL AND prefight_losses IS NOT NULL AND prefight_draws IS NOT NULL AND prefight_nc IS NOT NULL"
	),
	'rows_with_complete_opponent_prefight_record' => mmaf_vdpi_count(
		"SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE opponent_prefight_wins IS NOT NULL AND opponent_prefight_losses IS NOT NULL AND opponent_prefight_draws IS NOT NULL AND opponent_prefight_nc IS NOT NULL"
	),
	'rows_missing_prefight_data' => mmaf_vdpi_count(
		"SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE prefight_wins IS NULL OR prefight_losses IS NULL OR opponent_prefight_wins IS NULL OR opponent_prefight_losses IS NULL"
	),
	'rows_with_unknown_method' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM {$tables['bout_participants']} p
		INNER JOIN {$tables['bouts']} b ON b.id = p.bout_id
		WHERE b.method_category IS NULL OR b.method_category = '' OR b.method_category = 'unknown'
		"
	),
	'rows_excluded_by_status_result_scoring_flags' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM {$tables['bout_participants']} p
		INNER JOIN {$tables['bouts']} b ON b.id = p.bout_id
		LEFT JOIN {$tables['events']} e ON e.id = b.event_id AND e.deleted_soft = 0
		WHERE e.id IS NULL
			OR b.deleted_soft = 1
			OR b.status NOT IN ('valid', 'completed')
			OR b.is_scoring_candidate <> 1
			OR b.result_type <> 'win_loss'
			OR p.result_for_fighter NOT IN ('win', 'loss')
		"
	),
	'stored_opponent_prefight_diff_rows' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE opponent_prefight_diff IS NOT NULL" ),
);

$latest_run = $wpdb->get_row( "SELECT * FROM {$tables['ranking_runs']} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$active_run = $wpdb->get_row( "SELECT * FROM {$tables['ranking_runs']} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$latest_run_id = $latest_run ? (int) $latest_run['id'] : 0;
$active_run_id = $active_run ? (int) $active_run['id'] : 0;
$latest_summary = mmaf_vdpi_summary_from_run( $latest_run );

$latest_rows = array();
if ( $latest_run_id > 0 ) {
	$latest_rows = mmaf_vdpi_prepared_rows(
		"
		SELECT fighter_id, board_key, source_summary_json, warnings_json
		FROM {$tables['ranking_snapshots']}
		WHERE ranking_run_id = %d
		",
		array( $latest_run_id )
	);
}

$ranked_missing_rows = 0;
$ranked_missing_fighters = array();
foreach ( $latest_rows as $row ) {
	$source_summary = mmaf_vdpi_json( $row['source_summary_json'] ?? '' );
	if ( (int) ( $source_summary['prefight_records_missing_count'] ?? 0 ) > 0 ) {
		++$ranked_missing_rows;
		$ranked_missing_fighters[ (int) $row['fighter_id'] ] = true;
	}
}

$historical['latest_ranked_rows_with_missing_prefight_records'] = $ranked_missing_rows;
$historical['latest_ranked_fighters_with_missing_prefight_records'] = count( $ranked_missing_fighters );

$import = array(
	'import_runs' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['source_import_runs']}" ),
	'latest_actual_import_run' => $wpdb->get_row( "SELECT id, status, mode, dry_run, started_at, finished_at, source_run_id FROM {$tables['source_import_runs']} WHERE dry_run = 0 ORDER BY id DESC LIMIT 1", ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	'latest_dry_run' => $wpdb->get_row( "SELECT id, status, mode, dry_run, started_at, finished_at, source_run_id FROM {$tables['source_import_runs']} WHERE dry_run = 1 ORDER BY id DESC LIMIT 1", ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	'source_import_items' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['source_import_items']}" ),
	'duplicate_fighter_source_identities' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT source_type, source_fighter_id, COUNT(*) AS row_count
			FROM {$tables['fighter_sources']}
			WHERE source_fighter_id IS NOT NULL AND source_fighter_id <> ''
			GROUP BY source_type, source_fighter_id
			HAVING row_count > 1
		) duplicates
		"
	),
	'duplicate_event_source_identities' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT source_type, source_event_id, COUNT(*) AS row_count
			FROM {$tables['event_sources']}
			GROUP BY source_type, source_event_id
			HAVING row_count > 1
		) duplicates
		"
	),
	'duplicate_bout_source_identities' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT source_type, source_bout_id, COUNT(*) AS row_count
			FROM {$tables['bout_sources']}
			GROUP BY source_type, source_bout_id
			HAVING row_count > 1
		) duplicates
		"
	),
	'same_tapology_fighter_url_mapped_to_multiple_fighters' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT identity_hash, COUNT(DISTINCT fighter_id) AS fighter_count
			FROM {$tables['fighter_sources']}
			WHERE source_type = 'tapology' AND identity_hash IS NOT NULL AND identity_hash <> '' AND fighter_id IS NOT NULL
			GROUP BY identity_hash
			HAVING fighter_count > 1
		) duplicates
		"
	),
	'same_source_fighter_id_mapped_to_multiple_fighters' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT source_type, source_fighter_id, COUNT(DISTINCT fighter_id) AS fighter_count
			FROM {$tables['fighter_sources']}
			WHERE source_fighter_id IS NOT NULL AND source_fighter_id <> '' AND fighter_id IS NOT NULL
			GROUP BY source_type, source_fighter_id
			HAVING fighter_count > 1
		) duplicates
		"
	),
	'same_bout_identity_mapped_to_multiple_bouts' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT source_type, identity_hash, COUNT(DISTINCT bout_id) AS bout_count
			FROM {$tables['bout_sources']}
			WHERE identity_hash IS NOT NULL AND identity_hash <> '' AND bout_id IS NOT NULL
			GROUP BY source_type, identity_hash
			HAVING bout_count > 1
		) duplicates
		"
	),
	'content_hash_changes_detected' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT item_type, identity_hash, COUNT(DISTINCT content_hash) AS content_hashes
			FROM {$tables['source_import_items']}
			WHERE identity_hash IS NOT NULL AND identity_hash <> '' AND content_hash IS NOT NULL AND content_hash <> ''
			GROUP BY item_type, identity_hash
			HAVING content_hashes > 1
		) changed
		"
	),
	'identity_hash_collisions' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM (
			SELECT 'event' AS item_type, source_type, identity_hash, COUNT(DISTINCT event_id) AS canonical_count
			FROM {$tables['event_sources']}
			WHERE identity_hash IS NOT NULL AND identity_hash <> '' AND event_id IS NOT NULL
			GROUP BY source_type, identity_hash
			HAVING canonical_count > 1
			UNION ALL
			SELECT 'bout' AS item_type, source_type, identity_hash, COUNT(DISTINCT bout_id) AS canonical_count
			FROM {$tables['bout_sources']}
			WHERE identity_hash IS NOT NULL AND identity_hash <> '' AND bout_id IS NOT NULL
			GROUP BY source_type, identity_hash
			HAVING canonical_count > 1
		) collisions
		"
	),
	'event_sources_total' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['event_sources']}" ),
	'event_sources_with_raw_payload' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['event_sources']} WHERE raw_payload IS NOT NULL AND raw_payload <> ''" ),
	'event_sources_missing_raw_payload' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['event_sources']} WHERE raw_payload IS NULL OR raw_payload = ''" ),
	'bout_sources_total' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['bout_sources']}" ),
	'bout_sources_with_raw_payload' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['bout_sources']} WHERE raw_payload IS NOT NULL AND raw_payload <> ''" ),
	'bout_sources_missing_raw_payload' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['bout_sources']} WHERE raw_payload IS NULL OR raw_payload = ''" ),
);

$ranking = array(
	'fighters_with_stats' => mmaf_vdpi_count( "SELECT COUNT(DISTINCT fighter_id) FROM {$tables['fighter_stats_current']}" ),
	'fighters_missing_stats' => mmaf_vdpi_count(
		"
		SELECT COUNT(*)
		FROM {$tables['fighters']} f
		LEFT JOIN {$tables['fighter_stats_current']} s ON s.fighter_id = f.id
		WHERE f.deleted_soft = 0 AND s.fighter_id IS NULL
		"
	),
	'latest_ranking_run' => mmaf_vdpi_status_label( $latest_run ),
	'active_ranking_run' => mmaf_vdpi_status_label( $active_run ),
	'latest_run_newer_than_active_run' => $latest_run_id > 0 && $active_run_id > 0 ? $latest_run_id > $active_run_id : null,
	'latest_draft_rows' => $latest_run_id > 0 ? mmaf_vdpi_prepared_var( "SELECT COUNT(*) FROM {$tables['ranking_snapshots']} WHERE ranking_run_id = %d", array( $latest_run_id ) ) : 0,
	'active_current_rows' => mmaf_vdpi_count( "SELECT COUNT(*) FROM {$tables['ranking_current']}" ),
	'eligible_count_from_latest_run' => (int) ( $latest_summary['eligible_fighters'] ?? 0 ),
	'ineligible_count_from_latest_run' => (int) ( $latest_summary['ineligible_fighters'] ?? 0 ),
	'warnings_count_from_latest_run' => (int) ( $latest_summary['warnings_count'] ?? 0 ),
	'warning_groups_latest_ranked_rows' => array(
		'serious_readiness' => 0,
		'scoring_context' => 0,
		'other' => 0,
	),
	'rank_position_duplicates_latest_run' => $latest_run_id > 0 ? mmaf_vdpi_count(
		$wpdb->prepare(
			"
			SELECT COUNT(*)
			FROM (
				SELECT board_key, rank_position, COUNT(*) AS row_count
				FROM {$tables['ranking_snapshots']}
				WHERE ranking_run_id = %d
				GROUP BY board_key, rank_position
				HAVING row_count > 1
			) duplicates
			",
			$latest_run_id
		)
	) : 0,
);

foreach ( $latest_rows as $row ) {
	$warnings_json = mmaf_vdpi_json( $row['warnings_json'] ?? '' );
	foreach ( (array) ( $warnings_json['warnings'] ?? array() ) as $warning ) {
		if ( in_array( $warning, array( 'missing_prefight_record', 'missing_method_category', 'skipped_non_scoring_bout', 'birth_year_only_age_estimate' ), true ) ) {
			++$ranking['warning_groups_latest_ranked_rows']['scoring_context'];
		} elseif ( in_array( $warning, array( 'missing_date_of_birth', 'invalid_date_of_birth', 'missing_last_fight_date', 'missing_stats_row', 'rankable_missing_gender', 'rankable_missing_weight_class', 'inconsistent_rankability_status_vs_is_rankable', 'no_countable_fights' ), true ) ) {
			++$ranking['warning_groups_latest_ranked_rows']['serious_readiness'];
		} else {
			++$ranking['warning_groups_latest_ranked_rows']['other'];
		}
	}
}

$tie_diagnostics = array(
	'contract' => array(
		'higher_total_score',
		'more_wins',
		'higher_finish_rate',
		'younger_age',
		'more_recent_last_fight',
		'lower_fighter_id_fallback',
	),
	'latest_run_id' => $latest_run_id,
	'tied_groups' => array(),
);

if ( $latest_run_id > 0 ) {
	$tied_groups = mmaf_vdpi_prepared_rows(
		"
		SELECT board_key, total_score, COUNT(*) AS row_count
		FROM {$tables['ranking_snapshots']}
		WHERE ranking_run_id = %d
		GROUP BY board_key, total_score
		HAVING row_count > 1
		ORDER BY row_count DESC, board_key ASC, total_score DESC
		LIMIT 10
		",
		array( $latest_run_id )
	);

	foreach ( $tied_groups as $group ) {
		$rows = mmaf_vdpi_prepared_rows(
			"
			SELECT r.board_key, r.rank_position, r.fighter_id, f.display_name, r.total_score, s.wins, s.finish_rate, s.last_fight_date, f.date_of_birth, f.birth_year
			FROM {$tables['ranking_snapshots']} r
			LEFT JOIN {$tables['fighters']} f ON f.id = r.fighter_id
			LEFT JOIN {$tables['fighter_stats_current']} s ON s.fighter_id = r.fighter_id
			WHERE r.ranking_run_id = %d AND r.board_key = %s AND r.total_score = %s
			ORDER BY r.rank_position ASC, r.id ASC
			",
			array( $latest_run_id, $group['board_key'], $group['total_score'] )
		);
		$tie_diagnostics['tied_groups'][] = array(
			'board_key' => (string) $group['board_key'],
			'total_score' => (string) $group['total_score'],
			'row_count' => (int) $group['row_count'],
			'deciding_field' => mmaf_vdpi_tie_decider( $rows, (string) $latest_run['reference_date'] ),
			'ordered_rows' => array_slice( $rows, 0, 8 ),
		);
	}
}

$od_traces = array();
if ( $latest_run_id > 0 ) {
	$sample_rows = mmaf_vdpi_prepared_rows(
		"
		SELECT r.rank_position, r.fighter_id, f.display_name, r.total_score, r.breakdown_json
		FROM {$tables['ranking_snapshots']} r
		INNER JOIN {$tables['fighters']} f ON f.id = r.fighter_id
		WHERE r.ranking_run_id = %d AND r.board_key = 'overall'
		ORDER BY r.rank_position ASC
		LIMIT 10
		",
		array( $latest_run_id )
	);

	foreach ( $sample_rows as $row ) {
		if ( count( $od_traces ) >= 3 ) {
			break;
		}

		$breakdown = mmaf_vdpi_json( $row['breakdown_json'] ?? '' );
		$item = (array) ( $breakdown['per_fight_items'][0] ?? array() );
		if ( empty( $item['bout_id'] ) || ! in_array( (string) ( $item['result_for_fighter'] ?? '' ), array( 'win', 'loss' ), true ) ) {
			continue;
		}

		$participant = $wpdb->get_row(
			$wpdb->prepare(
				"
				SELECT
					p.id AS participant_id,
					p.bout_id,
					p.fighter_id,
					p.opponent_fighter_id,
					p.result_for_fighter,
					p.opponent_prefight_wins,
					p.opponent_prefight_losses,
					p.opponent_prefight_draws,
					p.opponent_prefight_nc,
					p.opponent_prefight_record_raw,
					p.opponent_prefight_diff,
					b.event_id,
					b.method_category,
					e.event_date
				FROM {$tables['bout_participants']} p
				INNER JOIN {$tables['bouts']} b ON b.id = p.bout_id
				INNER JOIN {$tables['events']} e ON e.id = b.event_id
				WHERE p.bout_id = %d AND p.fighter_id = %d
				LIMIT 1
				",
				(int) $item['bout_id'],
				(int) $row['fighter_id']
			),
			ARRAY_A
		);

		$od_traces[] = array(
			'fighter_id' => (int) $row['fighter_id'],
			'display_name' => (string) $row['display_name'],
			'rank_position' => (int) $row['rank_position'],
			'breakdown_item' => array(
				'bout_id' => (int) $item['bout_id'],
				'opponent_fighter_id' => isset( $item['opponent_fighter_id'] ) ? (int) $item['opponent_fighter_id'] : null,
				'result_for_fighter' => (string) ( $item['result_for_fighter'] ?? '' ),
				'opponent_prefight_diff' => isset( $item['opponent_prefight_diff'] ) ? (int) $item['opponent_prefight_diff'] : null,
				'opponent_diff_points' => isset( $item['opponent_diff_points'] ) ? (float) $item['opponent_diff_points'] : null,
			),
			'canonical_participant' => $participant,
			'od_matches_stored_prefight_record' => $participant ? (int) $participant['opponent_prefight_diff'] === (int) ( $item['opponent_prefight_diff'] ?? 0 ) : false,
			'stored_prefight_calculation' => $participant ? (int) $participant['opponent_prefight_wins'] - (int) $participant['opponent_prefight_losses'] : null,
		);
	}
}

$existing_path = array(
	'checked' => false,
	'resolved_to_existing_canonical_fighter_id' => false,
	'simulated_participant_row_links_existing_fighter_id' => false,
	'stats_rebuild_positive_path' => 'StatsRebuildService reads bout_participants.fighter_id and applies countable results to fighter_stats_current during rebuild.',
);
$source_rows = mmaf_vdpi_rows(
	"
	SELECT fs.*, f.display_name
	FROM {$tables['fighter_sources']} fs
	INNER JOIN {$tables['fighters']} f ON f.id = fs.fighter_id
	WHERE fs.source_type = 'tapology'
		AND fs.fighter_id IS NOT NULL
		AND fs.source_url IS NOT NULL
		AND fs.source_url <> ''
	ORDER BY fs.id ASC
	LIMIT 2
	"
);
if ( count( $source_rows ) >= 2 ) {
	$existing = $source_rows[0];
	$opponent = $source_rows[1];
	$events = array(
		array(
			'event_name' => 'Read Only Existing Fighter Path Check',
			'event_date' => current_time( 'Y-m-d' ),
			'source_event_id' => 'read_only_existing_path_event',
			'bouts' => array(
				array(
					'source_bout_id' => 'read_only_existing_path_bout',
					'result_type' => 'win_loss',
					'winner' => 'fighter_a',
					'flags' => array( 'is_scoring_candidate' => true ),
					'result' => array( 'method_category' => 'decision' ),
					'fighter_a' => array(
						'name' => (string) $existing['display_name'],
						'source_fighter_id' => (string) $existing['source_fighter_id'],
						'source_fighter_numeric_id' => (string) $existing['source_numeric_id'],
						'url' => (string) $existing['source_url'],
						'prefight_record' => array( 'wins' => 1, 'losses' => 0, 'draws' => 0, 'nc' => 0, 'raw' => '1-0-0' ),
					),
					'fighter_b' => array(
						'name' => (string) $opponent['display_name'],
						'source_fighter_id' => (string) $opponent['source_fighter_id'],
						'source_fighter_numeric_id' => (string) $opponent['source_numeric_id'],
						'url' => (string) $opponent['source_url'],
						'prefight_record' => array( 'wins' => 2, 'losses' => 1, 'draws' => 0, 'nc' => 0, 'raw' => '2-1-0' ),
					),
				),
			),
		),
	);
	$preview_warnings = array();
	$preview_conflicts = array();
	$preview = ( new FighterIdentityPreviewService() )->preview_fighters( $events, $preview_warnings, $preview_conflicts );
	$existing_key = ! empty( $existing['source_fighter_id'] )
		? 'source:' . (string) $existing['source_fighter_id']
		: 'url_hash:' . (string) TapologyFighterUrl::source_url_hash( (string) $existing['source_url'] );
	$existing_preview = (array) ( $preview['by_ref_key'][ $existing_key ] ?? array() );

	$participant_rows_method = new ReflectionMethod( ScraperJsonImportService::class, 'participant_rows' );
	$participant_rows_method->setAccessible( true );
	$simulated_rows = $participant_rows_method->invoke(
		new ScraperJsonImportService(),
		$events[0]['bouts'][0],
		(int) $existing['fighter_id'],
		(int) $opponent['fighter_id']
	);

	$existing_path = array(
		'checked' => true,
		'existing_source_row_id' => (int) $existing['id'],
		'existing_fighter_id' => (int) $existing['fighter_id'],
		'existing_display_name' => (string) $existing['display_name'],
		'existing_source_fighter_id' => (string) $existing['source_fighter_id'],
		'existing_source_url' => (string) $existing['source_url'],
		'preview_action' => (string) ( $existing_preview['action'] ?? '' ),
		'preview_matched_fighter_id' => isset( $existing_preview['matched_fighter_id'] ) ? (int) $existing_preview['matched_fighter_id'] : null,
		'resolved_to_existing_canonical_fighter_id' => isset( $existing_preview['matched_fighter_id'] ) && (int) $existing_preview['matched_fighter_id'] === (int) $existing['fighter_id'],
		'simulated_participant_row' => $simulated_rows[0] ?? null,
		'simulated_participant_row_links_existing_fighter_id' => isset( $simulated_rows[0]['fighter_id'] ) && (int) $simulated_rows[0]['fighter_id'] === (int) $existing['fighter_id'],
		'stats_rebuild_positive_path' => 'A subsequent rebuild will count this imported participant row because StatsRebuildService iterates bout_participants by fighter_id and applies win/loss/draw/no_contest results.',
		'warnings' => $preview_warnings,
		'conflicts' => $preview_conflicts,
	);
}

foreach ( array( 'malformed_bouts', 'same_fighter_bouts', 'participants_missing_fighter_id', 'participants_missing_opponent_fighter_id' ) as $key ) {
	if ( (int) $canonical[ $key ] > 0 ) {
		$critical_failures[] = $key . '=' . (string) $canonical[ $key ];
	}
}

if ( (int) $historical['scoring_participant_rows'] > 0 && (int) $historical['rows_missing_prefight_data'] > 0 ) {
	$critical_failures[] = 'historical_prefight_data_missing=' . (string) $historical['rows_missing_prefight_data'];
}

foreach ( array( 'same_tapology_fighter_url_mapped_to_multiple_fighters', 'same_source_fighter_id_mapped_to_multiple_fighters', 'same_bout_identity_mapped_to_multiple_bouts', 'identity_hash_collisions' ) as $key ) {
	if ( (int) $import[ $key ] > 0 ) {
		$critical_failures[] = $key . '=' . (string) $import[ $key ];
	}
}

if ( ! $existing_path['checked'] ) {
	$warnings[] = 'existing_fighter_update_path_not_checked_no_two_tapology_sources';
} elseif ( empty( $existing_path['resolved_to_existing_canonical_fighter_id'] ) || empty( $existing_path['simulated_participant_row_links_existing_fighter_id'] ) ) {
	$critical_failures[] = 'existing_fighter_update_path_did_not_resolve_to_existing_fighter';
}

if ( empty( $tie_diagnostics['tied_groups'] ) ) {
	$info['tie_breaker_note'] = 'No tied total_score examples exist in the latest run; contract documented only.';
}

if ( 0 === (int) $import['event_sources_with_raw_payload'] && 0 === (int) $import['bout_sources_with_raw_payload'] ) {
	$warnings[] = 'event_and_bout_source_raw_payload_columns_exist_but_current_rows_do_not_store_raw_payload';
}

$report = array(
	'ok' => empty( $critical_failures ),
	'critical_failures' => $critical_failures,
	'warnings' => $warnings,
	'info' => $info,
	'canonical_data' => $canonical,
	'historical_scoring_data' => $historical,
	'import_idempotency_signals' => $import,
	'existing_fighter_update_path' => $existing_path,
	'stats_ranking_consistency' => $ranking,
	'ranking_tie_diagnostics' => $tie_diagnostics,
	'od_traces' => $od_traces,
);

echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . PHP_EOL;

exit( empty( $critical_failures ) ? 0 : 1 );
