<?php
namespace MMAF\DataEngine\Services\System;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\REST\RestServiceProvider;
use MMAF\DataEngine\Services\Formula\FormulaV13;
use MMAF\DataEngine\Services\Formula\FormulaV14;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SystemCheckService {
	public const STATE_KEY = 'last_backend_system_check_summary';

	private array $checks = array();
	private int $warnings = 0;
	private int $critical_failures = 0;
	private int $integrity_checks = 0;
	private int $tables_checked = 0;

	public function run(): array {
		$this->checks            = array();
		$this->warnings          = 0;
		$this->critical_failures = 0;
		$this->integrity_checks  = 0;
		$this->tables_checked    = 0;

		$this->check_database_basics();
		$this->check_expected_unique_indexes();
		$this->check_core_counts();
		$this->check_bout_integrity();
		$this->check_stats_integrity();
		$this->check_ranking_integrity();
		$this->check_import_review_integrity();
		$this->check_rest_integrity();

		$result = array(
			'status'     => $this->critical_failures > 0 ? 'fail' : ( $this->warnings > 0 ? 'warning' : 'pass' ),
			'checked_at' => DateTime::mysql_now(),
			'summary'    => array(
				'critical_failures' => $this->critical_failures,
				'warnings'          => $this->warnings,
				'tables_checked'    => $this->tables_checked,
				'integrity_checks'  => $this->integrity_checks,
			),
			'checks'     => $this->checks,
		);

		$this->store_latest( $result );

		return $result;
	}

	public function latest(): ?array {
		$value = $this->system_state_value( self::STATE_KEY );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	private function check_database_basics(): void {
		$tables = Schema::table_names();

		$this->add_check(
			'plugin_version',
			'pass',
			'Plugin version',
			defined( 'MMAF_PLUGIN_VERSION' ) ? MMAF_PLUGIN_VERSION : '',
			'defined'
		);

		$db_version = (string) get_option( 'mmaf_db_version', '' );
		$this->add_check(
			'db_version_current',
			$db_version === MMAF_DB_VERSION ? 'pass' : 'fail',
			'DB version option',
			$db_version,
			MMAF_DB_VERSION
		);

		foreach ( $tables as $key => $table ) {
			$exists = $this->table_exists( $table );
			++$this->tables_checked;
			$this->add_check(
				'table_' . $key,
				$exists ? 'pass' : 'fail',
				'Required table: ' . $key,
				$exists ? 'exists' : 'missing',
				$table
			);
		}

		$required_columns = array(
			array( 'fighters', 'nickname', 'mmaf_fighters.nickname' ),
			array( 'fighters', 'height', 'mmaf_fighters.height' ),
			array( 'fighters', 'height_cm', 'mmaf_fighters.height_cm' ),
			array( 'fighters', 'last_weigh_in', 'mmaf_fighters.last_weigh_in' ),
			array( 'event_sources', 'source_promotion_url', 'mmaf_event_sources.source_promotion_url' ),
			array( 'review_items', 'id', 'mmaf_review_items table' ),
		);

		foreach ( $required_columns as $required ) {
			$table_key = $required[0];
			$column    = $required[1];
			$label     = $required[2];
			$exists    = isset( $tables[ $table_key ] ) && $this->table_exists( $tables[ $table_key ] ) && $this->column_exists( $tables[ $table_key ], $column );

			$this->add_check(
				'required_column_' . $table_key . '_' . $column,
				$exists ? 'pass' : 'fail',
				'Required schema: ' . $label,
				$exists ? 'exists' : 'missing',
				'exists'
			);
		}

		foreach ( $tables as $key => $table ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}

			$this->add_check(
				'row_count_' . $key,
				'pass',
				'Table rows: ' . $key,
				$this->count( $table ),
				'>= 0'
			);
		}
	}

	private function check_core_counts(): void {
		$tables = Schema::table_names();

		$this->add_count_check( 'fighters_count', 'Fighters', $tables['fighters'], 'deleted_soft = 0' );
		$this->add_count_check( 'events_count', 'Events', $tables['events'], 'deleted_soft = 0' );
		$this->add_count_check( 'bouts_count', 'Bouts', $tables['bouts'], 'deleted_soft = 0' );
		$this->add_count_check( 'participant_rows_count', 'Participant rows', $tables['bout_participants'] );
		$this->add_count_check( 'stats_rows_count', 'Stats rows', $tables['fighter_stats_current'] );
		$this->add_count_check( 'ranking_current_rows_count', 'Current ranking rows', $tables['ranking_current'] );

		$active_run_id = $this->active_ranking_run_id();
		$this->add_check( 'active_ranking_run_id', 'pass', 'Active ranking run ID', null === $active_run_id ? 'none' : $active_run_id, 'set only after activation' );
		$this->add_count_check( 'source_import_runs_count', 'Source import runs', $tables['source_import_runs'] );
		$this->add_count_check( 'source_import_items_count', 'Source import items', $tables['source_import_items'] );
		$this->add_count_check( 'review_items_count', 'Review items', $tables['review_items'] );
	}

	private function check_expected_unique_indexes(): void {
		$tables = Schema::table_names();
		$indexes = array(
			array( 'fighter_sources', 'source_type_identity_hash', 'mmaf_fighter_sources source_type + identity_hash' ),
			array( 'fighter_stats_current', 'fighter_id_unique', 'mmaf_fighter_stats_current fighter_id' ),
			array( 'fighter_stats_overrides', 'fighter_id_unique', 'mmaf_fighter_stats_overrides fighter_id' ),
			array( 'ranking_current', 'board_fighter', 'mmaf_ranking_current board_key + fighter_id' ),
			array( 'ranking_current', 'board_rank_position', 'mmaf_ranking_current board_key + rank_position' ),
			array( 'ranking_snapshots', 'run_board_fighter', 'mmaf_ranking_snapshots run + board + fighter' ),
			array( 'ranking_snapshots', 'run_board_rank_position', 'mmaf_ranking_snapshots run + board + rank' ),
			array( 'bout_participants', 'bout_role', 'mmaf_bout_participants bout_id + participant_role' ),
		);

		foreach ( $indexes as $index ) {
			$table_key = $index[0];
			$index_name = $index[1];
			$label = $index[2];
			$exists = isset( $tables[ $table_key ] ) && $this->table_exists( $tables[ $table_key ] ) && $this->index_exists( $tables[ $table_key ], $index_name );

			$this->add_check(
				'unique_index_' . $table_key . '_' . $index_name,
				$exists ? 'pass' : 'warning',
				'Expected unique index: ' . $label,
				$exists ? 'exists' : 'missing',
				'exists when current data has no duplicates'
			);
		}
	}

	private function check_bout_integrity(): void {
		global $wpdb;

		$tables = Schema::table_names();

		$this->add_zero_check(
			'malformed_bouts',
			'Malformed bouts',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$tables['bouts']} b
				LEFT JOIN (
					SELECT bout_id, COUNT(*) AS participant_count
					FROM {$tables['bout_participants']}
					GROUP BY bout_id
				) p ON p.bout_id = b.id
				WHERE COALESCE(p.participant_count, 0) <> 2
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'same_fighter_bouts',
			'Same-fighter bouts',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(DISTINCT bout_id)
				FROM {$tables['bout_participants']}
				WHERE fighter_id IS NOT NULL
					AND opponent_fighter_id IS NOT NULL
					AND fighter_id = opponent_fighter_id
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check( 'participant_missing_fighter_id', 'Participant rows missing fighter_id', $this->count_where( $tables['bout_participants'], 'fighter_id IS NULL OR fighter_id = 0' ) );
		$this->add_zero_check( 'participant_missing_opponent_fighter_id', 'Participant rows missing opponent_fighter_id', $this->count_where( $tables['bout_participants'], 'opponent_fighter_id IS NULL OR opponent_fighter_id = 0' ) );

		$this->add_zero_check(
			'bouts_missing_events',
			'Bouts pointing to missing events',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$tables['bouts']} b
				LEFT JOIN {$tables['events']} e ON e.id = b.event_id
				WHERE e.id IS NULL
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'participants_missing_fighters',
			'Participant rows pointing to missing fighters',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$tables['bout_participants']} p
				LEFT JOIN {$tables['fighters']} f ON f.id = p.fighter_id
				WHERE p.fighter_id IS NOT NULL AND f.id IS NULL
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'duplicate_participant_roles_per_bout',
			'Duplicate participant roles per bout',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT bout_id, participant_role, COUNT(*) AS role_count
					FROM {$tables['bout_participants']}
					GROUP BY bout_id, participant_role
					HAVING role_count > 1
				) duplicate_roles
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'bouts_participant_count_not_two',
			'Bouts with participant count other than 2',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$tables['bouts']} b
				LEFT JOIN (
					SELECT bout_id, COUNT(*) AS participant_count
					FROM {$tables['bout_participants']}
					GROUP BY bout_id
				) p ON p.bout_id = b.id
				WHERE COALESCE(p.participant_count, 0) <> 2
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check( 'scoring_candidates_non_win_loss', 'Scoring candidates with non-win_loss result', $this->count_where( $tables['bouts'], "is_scoring_candidate = 1 AND (result_type IS NULL OR result_type <> 'win_loss')" ) );
		$this->add_zero_check( 'scoring_candidates_unknown_method', 'Scoring candidates with unknown method', $this->count_where( $tables['bouts'], "is_scoring_candidate = 1 AND (method_category IS NULL OR method_category = '' OR method_category = 'unknown')" ) );
		$this->add_zero_check( 'scoring_candidates_excluded_status', 'Scoring candidates with deleted/hidden/excluded status', $this->count_where( $tables['bouts'], "is_scoring_candidate = 1 AND (deleted_soft = 1 OR status IN ('deleted', 'hidden', 'excluded', 'cancelled', 'amateur', 'overturned') OR status LIKE '%cancel%' OR status LIKE '%amateur%' OR status LIKE '%overturn%')" ) );
	}

	private function check_stats_integrity(): void {
		global $wpdb;

		$tables = Schema::table_names();

		$stats_rows = $this->count( $tables['fighter_stats_current'] );
		$fighters   = $this->count_where( $tables['fighters'], 'deleted_soft = 0' );
		$this->add_check( 'stats_rows_vs_non_deleted_fighters', $stats_rows === $fighters ? 'pass' : 'fail', 'Stats rows vs non-deleted fighters', $stats_rows . ' / ' . $fighters, 'equal' );

		$this->add_zero_check(
			'fighters_missing_stats',
			'Fighters missing stats',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$tables['fighters']} f
				LEFT JOIN {$tables['fighter_stats_current']} s ON s.fighter_id = f.id
				WHERE f.deleted_soft = 0 AND s.fighter_id IS NULL
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'stats_missing_fighters',
			'Stats rows pointing to missing fighters',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$tables['fighter_stats_current']} s
				LEFT JOIN {$tables['fighters']} f ON f.id = s.fighter_id
				WHERE f.id IS NULL
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'duplicate_stats_rows_per_fighter',
			'Duplicate stats rows per fighter',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT fighter_id, COUNT(*) AS row_count
					FROM {$tables['fighter_stats_current']}
					GROUP BY fighter_id
					HAVING row_count > 1
				) duplicates
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$summary = $this->system_state_json( 'last_stats_rebuild_summary' );
		$this->add_check( 'last_stats_rebuild_summary', null === $summary ? 'warning' : 'pass', 'Last stats rebuild summary', null === $summary ? 'missing' : (string) ( $summary['rebuilt_at'] ?? 'stored' ), 'stored summary' );
		if ( is_array( $summary ) ) {
			$this->add_check( 'last_stats_rebuild_warning_count', (int) ( $summary['warnings_count'] ?? 0 ) > 0 ? 'warning' : 'pass', 'Last stats rebuild warning count', (int) ( $summary['warnings_count'] ?? 0 ), '0 preferred' );
		}
	}

	private function check_ranking_integrity(): void {
		global $wpdb;

		$tables        = Schema::table_names();
		$active_run_id = $this->active_ranking_run_id();
		$current_rows  = $this->count( $tables['ranking_current'] );

		if ( null === $active_run_id ) {
			$this->add_check( 'active_ranking_run_set', 'warning', 'Active ranking run exists if set', 'not set', 'set when rankings are live' );
			$this->add_check( 'current_rows_without_active_run', 0 === $current_rows ? 'pass' : 'fail', 'Current ranking rows require an active run', $current_rows, '0 when no active ranking run is set' );
		} else {
			$active_run = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, is_active, status, formula_version FROM {$tables['ranking_runs']} WHERE id = %d LIMIT 1", $active_run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);

			$this->add_check( 'active_ranking_run_exists', $active_run ? 'pass' : 'fail', 'Active ranking run exists', $active_run ? $active_run_id : 'missing', 'existing ranking run' );
			if ( $active_run ) {
				$this->add_check( 'active_ranking_run_marked_active', 1 === (int) $active_run['is_active'] ? 'pass' : 'fail', 'Active ranking run is marked active', (int) $active_run['is_active'], '1' );
				$this->add_check( 'active_ranking_formula_current', FormulaV14::VERSION === (string) $active_run['formula_version'] ? 'pass' : 'warning', 'Active ranking formula version', (string) $active_run['formula_version'], FormulaV14::VERSION );
			}
		}

		if ( null !== $active_run_id ) {
			$this->add_zero_check( 'current_rows_not_active_run', 'Current ranking rows not on active run', $this->count_where( $tables['ranking_current'], $wpdb->prepare( 'ranking_run_id <> %d', $active_run_id ) ) );
		}

		$this->add_zero_check(
			'current_ranking_missing_fighters',
			'Current ranking rows pointing to missing fighters',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$tables['ranking_current']} r
				LEFT JOIN {$tables['fighters']} f ON f.id = r.fighter_id
				WHERE f.id IS NULL
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'duplicate_board_fighter',
			'Duplicate board_key + fighter_id',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT board_key, fighter_id, COUNT(*) AS duplicate_count
					FROM {$tables['ranking_current']}
					GROUP BY board_key, fighter_id
					HAVING duplicate_count > 1
				) duplicates
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'duplicate_rank_position_per_board',
			'Duplicate rank_position per board',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT board_key, rank_position, COUNT(*) AS duplicate_count
					FROM {$tables['ranking_current']}
					GROUP BY board_key, rank_position
					HAVING duplicate_count > 1
				) duplicates
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'duplicate_snapshot_run_board_fighter',
			'Duplicate snapshot run/board/fighter rows',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT ranking_run_id, board_key, fighter_id, COUNT(*) AS duplicate_count
					FROM {$tables['ranking_snapshots']}
					GROUP BY ranking_run_id, board_key, fighter_id
					HAVING duplicate_count > 1
				) duplicates
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'duplicate_snapshot_run_board_rank',
			'Duplicate snapshot run/board/rank rows',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT ranking_run_id, board_key, rank_position, COUNT(*) AS duplicate_count
					FROM {$tables['ranking_snapshots']}
					GROUP BY ranking_run_id, board_key, rank_position
					HAVING duplicate_count > 1
				) duplicates
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_zero_check(
			'current_ranking_invalid_json_fields',
			'Invalid JSON in current ranking JSON fields',
			$this->invalid_json_rows_count( $tables['ranking_current'], array( 'breakdown_json', 'eligibility_json', 'warnings_json', 'source_summary_json', 'quality_flags_json' ) )
		);

		$this->add_zero_check(
			'ranking_snapshot_invalid_json_fields',
			'Invalid JSON in snapshot JSON fields',
			$this->invalid_json_rows_count( $tables['ranking_snapshots'], array( 'breakdown_json', 'eligibility_json', 'warnings_json', 'source_summary_json', 'quality_flags_json' ) )
		);

		$this->add_zero_check( 'invalid_total_score', 'Invalid total_score', $this->count_where( $tables['ranking_current'], 'total_score IS NULL' ) );
		$active_formula_version = null === $active_run_id ? null : $wpdb->get_var( $wpdb->prepare( "SELECT formula_version FROM {$tables['ranking_runs']} WHERE id = %d LIMIT 1", $active_run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_normalized_formula = in_array( (string) $active_formula_version, array( FormulaV13::VERSION, FormulaV14::VERSION ), true );
		$formula_config = FormulaV14::config();
		$min_scoring_bouts = (int) ( $formula_config['eligibility']['min_scoring_bouts'] ?? 1 );

		if ( $active_normalized_formula && $this->column_exists( $tables['ranking_current'], 'normalized_score' ) ) {
			$this->add_zero_check( 'invalid_normalized_score', 'Invalid normalized_score', $this->count_where( $tables['ranking_current'], 'normalized_score IS NULL OR normalized_score < 0 OR normalized_score > 100 OR ABS(total_score - normalized_score) > 0.001' ) );
		}
		if ( $active_normalized_formula && $this->column_exists( $tables['ranking_current'], 'raw_score' ) ) {
			$this->add_zero_check( 'invalid_raw_score', 'Invalid raw_score', $this->count_where( $tables['ranking_current'], 'raw_score IS NULL' ) );
		}
		if ( $active_normalized_formula && $this->column_exists( $tables['ranking_current'], 'confidence_score' ) ) {
			$this->add_zero_check( 'invalid_confidence_score', 'Invalid confidence_score', $this->count_where( $tables['ranking_current'], 'confidence_score IS NULL OR confidence_score < 0 OR confidence_score > 100' ) );
		}
		if ( $active_normalized_formula && $this->column_exists( $tables['ranking_current'], 'sample_size' ) ) {
			$this->add_zero_check( 'invalid_sample_size', 'Invalid trusted ranking sample size', $this->count_where( $tables['ranking_current'], 'sample_size < ' . $min_scoring_bouts ) );
		}

		$calculation = $this->system_state_json( 'last_ranking_calculation_summary' );
		$activation  = $this->system_state_json( 'last_ranking_activation_summary' );
		$this->add_check( 'latest_ranking_calculation_summary', null === $calculation ? 'warning' : 'pass', 'Latest ranking calculation summary', null === $calculation ? 'missing' : (string) ( $calculation['calculated_at'] ?? 'stored' ), 'stored summary' );
		$this->add_check( 'latest_ranking_activation_summary', null === $activation ? 'warning' : 'pass', 'Latest ranking activation summary', null === $activation ? 'missing' : (string) ( $activation['activated_at'] ?? 'stored' ), 'stored summary' );

		$this->add_check( 'active_ranking_test_data_note', 2 === $current_rows ? 'warning' : 'pass', 'Active ranking test-data note', $current_rows, 'not production-ready until enough fighters are reviewed/public/rankable' );
	}

	private function check_import_review_integrity(): void {
		global $wpdb;

		$tables = Schema::table_names();

		$this->add_zero_check(
			'duplicate_fighter_source_identity_hash',
			'Duplicate fighter source identity_hash per source_type',
			(int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT source_type, identity_hash, COUNT(*) AS row_count
					FROM {$tables['fighter_sources']}
					WHERE identity_hash IS NOT NULL
					GROUP BY source_type, identity_hash
					HAVING row_count > 1
				) duplicates
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->add_latest_import_run_check( 'latest_dry_run_summary', 'Latest dry-run summary', true );
		$this->add_latest_import_run_check( 'latest_actual_import_summary', 'Latest actual import summary', false );

		$this->add_check( 'conflict_item_count', $this->count_where( $tables['source_import_items'], "status = 'conflict'" ) > 0 ? 'warning' : 'pass', 'Conflict item count', $this->count_where( $tables['source_import_items'], "status = 'conflict'" ), '0 after manual review' );
		$this->add_check( 'needs_review_count', $this->count_where( $tables['source_import_items'], "status = 'needs_review'" ) > 0 ? 'warning' : 'pass', 'Needs-review import item count', $this->count_where( $tables['source_import_items'], "status = 'needs_review'" ), '0 after manual review' );
		$this->add_zero_check( 'failed_import_item_count', 'Failed import item count', $this->count_where( $tables['source_import_items'], "status = 'failed'" ) );

		$known_conflict = $this->count_where( $tables['source_import_items'], "source_id = 'tapology_bout_1116772' AND status = 'conflict'" );
		$this->add_check( 'known_conflict_tapology_bout_1116772', $known_conflict > 0 ? 'pass' : 'warning', 'Known conflict tapology_bout_1116772 remains tracked', $known_conflict, '>= 1' );

		$review_counts = $this->group_counts( $tables['review_items'], 'status' );
		$this->add_check( 'review_item_counts_by_status', 'pass', 'Review item counts by status', wp_json_encode( $review_counts ), 'tracked' );

		$this->add_check( 'source_link_action_count', 'pass', 'Source-link action count', $this->count_where( $tables['audit_log'], "action = 'review_source_linked_to_existing_fighter'" ), 'auditable' );
		$this->add_check( 'participant_remap_action_count', 'pass', 'Participant-remap action count', $this->count_where( $tables['audit_log'], "action = 'review_participants_remapped'" ), 'auditable' );

		$provisional = $this->provisional_tapology_fighters_count();
		$this->add_check( 'unresolved_provisional_tapology_fighters', $provisional > 0 ? 'warning' : 'pass', 'Unresolved provisional Tapology fighters', $provisional, '0 after full manual review' );
	}

	private function check_rest_integrity(): void {
		$routes = $this->rest_routes();

		$this->add_check( 'rest_route_rankings_registered', $this->has_rest_route( $routes, '/mma-future/v1/rankings' ) ? 'pass' : 'fail', 'REST route registered: rankings', $this->has_rest_route( $routes, '/mma-future/v1/rankings' ) ? 'yes' : 'no', 'yes' );
		$this->add_check( 'rest_route_fighter_registered', $this->has_rest_route_pattern( $routes, '#^/mma-future/v1/fighters/\(\?P<id>.*#' ) ? 'pass' : 'fail', 'REST route registered: fighters/{id}', $this->has_rest_route_pattern( $routes, '#^/mma-future/v1/fighters/\(\?P<id>.*#' ) ? 'yes' : 'no', 'yes' );
		$this->add_check( 'rest_route_fighter_slug_registered', $this->has_rest_route_pattern( $routes, '#^/mma-future/v1/fighters/slug/\(\?P<slug>.*#' ) ? 'pass' : 'fail', 'REST route registered: fighters/slug/{slug}', $this->has_rest_route_pattern( $routes, '#^/mma-future/v1/fighters/slug/\(\?P<slug>.*#' ) ? 'yes' : 'no', 'yes' );
		$this->add_check( 'rest_route_fighter_search_registered', $this->has_rest_route( $routes, '/mma-future/v1/fighters/search' ) ? 'pass' : 'fail', 'REST route registered: fighters/search', $this->has_rest_route( $routes, '/mma-future/v1/fighters/search' ) ? 'yes' : 'no', 'yes' );
		$this->add_check( 'rest_route_health_registered', $this->has_rest_route( $routes, '/mma-future/v1/health' ) ? 'pass' : 'fail', 'REST route registered: health', $this->has_rest_route( $routes, '/mma-future/v1/health' ) ? 'yes' : 'no', 'yes' );

		$health_response = $this->dispatch_rest_request( '/mma-future/v1/health' );
		$this->add_check( 'rest_health_internal_status', 200 === $health_response['status'] && is_array( $health_response['data'] ) ? 'pass' : 'fail', 'REST health internal check', $health_response['status'], '200' );

		$rankings_response = $this->dispatch_rest_request( '/mma-future/v1/rankings' );
		$this->add_check( 'rest_rankings_internal_status', 200 === $rankings_response['status'] && is_array( $rankings_response['data'] ) ? 'pass' : 'fail', 'REST rankings internal check', $rankings_response['status'], '200' );

		$write_routes = $this->write_routes( $routes );
		$this->add_check( 'rest_no_write_endpoints', empty( $write_routes ) ? 'pass' : 'fail', 'No REST write endpoints under mma-future/v1', empty( $write_routes ) ? 'none' : implode( ', ', $write_routes ), 'none' );
	}

	private function add_latest_import_run_check( string $key, string $label, bool $dry_run ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, finished_at FROM {$tables['source_import_runs']} WHERE dry_run = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dry_run ? 1 : 0
			),
			ARRAY_A
		);

		$this->add_check( $key, $row ? 'pass' : 'warning', $label, $row ? sprintf( 'run=%d status=%s finished_at=%s', (int) $row['id'], (string) $row['status'], (string) $row['finished_at'] ) : 'missing', 'latest run stored' );
	}

	private function add_count_check( string $key, string $label, string $table, ?string $where = null ): void {
		$value = null === $where ? $this->count( $table ) : $this->count_where( $table, $where );
		$this->add_check( $key, 'pass', $label, $value, 'tracked' );
	}

	private function add_zero_check( string $key, string $label, int $value ): void {
		$this->add_check( $key, 0 === $value ? 'pass' : 'fail', $label, $value, '0' );
	}

	private function add_check( string $key, string $status, string $label, $value, string $expected ): void {
		++$this->integrity_checks;

		if ( 'fail' === $status ) {
			++$this->critical_failures;
		} elseif ( 'warning' === $status ) {
			++$this->warnings;
		}

		$this->checks[] = array(
			'key'      => $key,
			'status'   => $status,
			'label'    => $label,
			'value'    => is_scalar( $value ) || null === $value ? $value : wp_json_encode( $value ),
			'expected' => $expected,
		);
	}

	private function store_latest( array $result ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->query(
			$wpdb->prepare(
				"
				INSERT INTO {$tables['system_state']} (state_key, state_value, autoload, updated_at)
				VALUES (%s, %s, 'no', %s)
				ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = VALUES(updated_at)
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATE_KEY,
				wp_json_encode( $result ),
				DateTime::mysql_now()
			)
		);
	}

	private function active_ranking_run_id(): ?int {
		$value = $this->system_state_value( 'active_ranking_run_id' );

		return null === $value || '' === $value ? null : (int) $value;
	}

	private function system_state_json( string $key ): ?array {
		$value = $this->system_state_value( $key );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	private function system_state_value( string $key ): ?string {
		global $wpdb;

		$tables = Schema::table_names();

		return $wpdb->get_var(
			$wpdb->prepare( "SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", $key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function column_exists( string $table, string $column ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', $column ) ) === $column; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function index_exists( string $table, string $index ): bool {
		global $wpdb;

		return null !== $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', $index ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	private function count( string $table ): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function count_where( string $table, string $where ): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function invalid_json_rows_count( string $table, array $fields ): int {
		global $wpdb;

		$fields = array_values(
			array_filter(
				$fields,
				function ( string $field ) use ( $table ): bool {
					return $this->column_exists( $table, $field );
				}
			)
		);

		if ( empty( $fields ) ) {
			return 0;
		}

		$select = implode( ', ', array_map( 'strval', $fields ) );
		$rows = $wpdb->get_results(
			"SELECT id, {$select} FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$invalid = 0;
		foreach ( (array) $rows as $row ) {
			foreach ( $fields as $field ) {
				if ( null === $row[ $field ] || '' === (string) $row[ $field ] ) {
					continue;
				}

				json_decode( (string) $row[ $field ], true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					++$invalid;
					break;
				}
			}
		}

		return $invalid;
	}

	private function group_counts( string $table, string $field ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT {$field} AS item_key, COUNT(*) AS item_count FROM {$table} GROUP BY {$field} ORDER BY item_count DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['item_key'] ] = (int) $row['item_count'];
		}

		return $counts;
	}

	private function provisional_tapology_fighters_count(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT f.id)
			FROM {$tables['fighters']} f
			INNER JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id AND fs.source_type = 'tapology'
			WHERE f.deleted_soft = 0 AND f.status = 'provisional'
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function rest_routes(): array {
		if ( 0 === did_action( 'rest_api_init' ) ) {
			do_action( 'rest_api_init' );
		}

		return rest_get_server()->get_routes();
	}

	private function has_rest_route( array $routes, string $route ): bool {
		return array_key_exists( $route, $routes );
	}

	private function has_rest_route_pattern( array $routes, string $pattern ): bool {
		foreach ( array_keys( $routes ) as $route ) {
			if ( 1 === preg_match( $pattern, $route ) ) {
				return true;
			}
		}

		return false;
	}

	private function dispatch_rest_request( string $route ): array {
		$request  = new \WP_REST_Request( 'GET', $route );
		$response = rest_get_server()->dispatch( $request );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 500,
				'data'   => $response->get_error_message(),
			);
		}

		return array(
			'status' => (int) $response->get_status(),
			'data'   => $response->get_data(),
		);
	}

	private function write_routes( array $routes ): array {
		$write_routes = array();

		foreach ( $routes as $route => $handlers ) {
			if ( ! str_starts_with( (string) $route, '/' . RestServiceProvider::NAMESPACE ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || empty( $handler['methods'] ) || ! is_array( $handler['methods'] ) ) {
					continue;
				}

				foreach ( array_keys( $handler['methods'] ) as $method ) {
					if ( ! in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD' ), true ) ) {
						$write_routes[] = $method . ' ' . $route;
					}
				}
			}
		}

		return array_values( array_unique( $write_routes ) );
	}
}
