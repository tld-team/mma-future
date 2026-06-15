<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RestReadRepository {
	private string $fighters_table;
	private string $fighter_aliases_table;
	private string $events_table;
	private string $bouts_table;
	private string $participants_table;
	private string $stats_table;
	private string $ranking_runs_table;
	private string $ranking_current_table;
	private string $system_state_table;
	private string $fighter_sources_table;

	public function __construct() {
		$tables                      = Schema::table_names();
		$this->fighters_table        = $tables['fighters'];
		$this->fighter_aliases_table = $tables['fighter_aliases'];
		$this->events_table          = $tables['events'];
		$this->bouts_table           = $tables['bouts'];
		$this->participants_table    = $tables['bout_participants'];
		$this->stats_table           = $tables['fighter_stats_current'];
		$this->ranking_runs_table    = $tables['ranking_runs'];
		$this->ranking_current_table = $tables['ranking_current'];
		$this->system_state_table    = $tables['system_state'];
		$this->fighter_sources_table = $tables['fighter_sources'];
	}

	public function active_ranking_run(): ?array {
		global $wpdb;

		$run_id = $this->active_ranking_run_id();
		if ( null === $run_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->ranking_runs_table} WHERE id = %d LIMIT 1", $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function active_ranking_run_id(): ?int {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT state_value FROM {$this->system_state_table} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'active_ranking_run_id'
			)
		);

		return null === $value || '' === $value ? null : (int) $value;
	}

	public function ranking_rows( string $board, int $page, int $per_page, bool $include_non_public ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;
		$visibility_where = $include_non_public ? '' : ' AND f.is_public = 1';
		$tapology_identity_where = $include_non_public ? '' : "
					AND EXISTS (
						SELECT 1
						FROM {$this->fighter_sources_table} fs
						WHERE fs.fighter_id = f.id
							AND fs.source_type = 'tapology'
							AND fs.source_url IS NOT NULL
							AND fs.source_url <> ''
							AND fs.identity_hash IS NOT NULL
							AND fs.identity_hash <> ''
						LIMIT 1
					)";
		$total  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COUNT(*)
				FROM {$this->ranking_current_table} r
				INNER JOIN {$this->fighters_table} f ON f.id = r.fighter_id
				WHERE r.board_key = %s
					AND f.deleted_soft = 0
					{$visibility_where}
					{$tapology_identity_where}
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$board
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					r.*,
					f.display_name,
					f.nickname,
					f.gender,
					f.date_of_birth,
					f.birth_year,
					f.nationality,
					f.weight_class,
					f.wp_post_id,
					s.wins,
					s.losses,
					s.draws,
					s.nc,
					s.pro_fights_count,
					s.finish_wins,
					s.finish_rate,
					s.last_fight_date,
					s.streak,
					s.recent_form,
					s.activity_status
				FROM {$this->ranking_current_table} r
				INNER JOIN {$this->fighters_table} f ON f.id = r.fighter_id
				LEFT JOIN {$this->stats_table} s ON s.fighter_id = f.id
				WHERE r.board_key = %s
					AND f.deleted_soft = 0
					{$visibility_where}
					{$tapology_identity_where}
				ORDER BY r.rank_position ASC
				LIMIT %d OFFSET %d
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$board,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'total' => $total,
			'rows'  => $rows,
		);
	}

	public function fighter( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->fighters_table} WHERE id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function fighter_by_wp_post_id( int $wp_post_id ): ?array {
		global $wpdb;

		if ( $wp_post_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->fighters_table} WHERE wp_post_id = %d LIMIT 1", $wp_post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function fighter_stats( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->stats_table} WHERE fighter_id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function fighter_current_rankings( int $fighter_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT board_key, rank_position, total_score, ranking_run_id
				FROM {$this->ranking_current_table}
				WHERE fighter_id = %d
				ORDER BY board_key ASC, rank_position ASC
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$fighter_id
			),
			ARRAY_A
		);
	}

	public function recent_fights( int $fighter_id, int $limit = 10 ): array {
		global $wpdb;

		$limit = max( 1, min( 25, $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					p.bout_id,
					p.opponent_fighter_id,
					p.result_for_fighter,
					b.event_id,
					b.weight_class,
					b.method_category,
					b.method_detail,
					b.round_number,
					b.time_in_round,
					e.event_name,
					e.event_date,
					opp.display_name AS opponent_display_name
				FROM {$this->participants_table} p
				INNER JOIN {$this->bouts_table} b ON b.id = p.bout_id
				LEFT JOIN {$this->events_table} e ON e.id = b.event_id
				LEFT JOIN {$this->fighters_table} opp ON opp.id = p.opponent_fighter_id
				WHERE p.fighter_id = %d
					AND b.deleted_soft = 0
					AND b.status IN ('valid', 'completed')
				ORDER BY e.event_date DESC, b.id DESC
				LIMIT %d
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$fighter_id,
				$limit
			),
			ARRAY_A
		);
	}

	public function search_fighters( string $query, int $limit, bool $include_non_public ): array {
		global $wpdb;

		$like  = '%' . $wpdb->esc_like( $query ) . '%';
		$where = 'f.deleted_soft = 0';

		if ( ! $include_non_public ) {
			$where .= ' AND f.is_public = 1';
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT DISTINCT f.*
				FROM {$this->fighters_table} f
				LEFT JOIN {$this->fighter_aliases_table} a ON a.fighter_id = f.id
				WHERE {$where}
					AND (
						f.display_name LIKE %s
						OR f.normalized_name LIKE %s
						OR f.nickname LIKE %s
						OR a.alias LIKE %s
						OR a.normalized_alias LIKE %s
					)
				ORDER BY f.display_name ASC
				LIMIT %d
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like,
				$like,
				$like,
				$like,
				$like,
				$limit
			),
			ARRAY_A
		);
	}

	public function health_summary(): array {
		global $wpdb;

		$stats_summary       = $this->system_state_json( 'last_stats_rebuild_summary' );
		$ranking_summary     = $this->system_state_json( 'last_ranking_calculation_summary' );
		$activation_summary  = $this->system_state_json( 'last_ranking_activation_summary' );
		$active_ranking_run  = $this->active_ranking_run();

		return array(
			'plugin_version'                 => defined( 'MMAF_PLUGIN_VERSION' ) ? MMAF_PLUGIN_VERSION : '',
			'db_version'                     => (string) get_option( 'mmaf_db_version', '' ),
			'active_ranking_run_id'          => $this->active_ranking_run_id(),
			'active_ranking_formula_version' => $active_ranking_run['formula_version'] ?? null,
			'current_ranking_rows_count'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->ranking_current_table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'fighters_count'                 => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->fighters_table} WHERE deleted_soft = 0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'events_count'                   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->events_table} WHERE deleted_soft = 0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'bouts_count'                    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->bouts_table} WHERE deleted_soft = 0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'stats_rows_count'               => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->stats_table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'latest_stats_rebuild_time'      => $stats_summary['rebuilt_at'] ?? null,
			'latest_ranking_calculation_time'=> $ranking_summary['calculated_at'] ?? null,
			'latest_ranking_activation_time' => $activation_summary['activated_at'] ?? null,
		);
	}

	public function public_health_summary(): array {
		global $wpdb;

		$activation_summary = $this->system_state_json( 'last_ranking_activation_summary' );
		$active_ranking_run = $this->active_ranking_run();
		$current_ranking_rows_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->ranking_current_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rankings_available = null !== $active_ranking_run && $current_ranking_rows_count > 0;

		return array(
			'status' => $rankings_available ? 'ok' : 'degraded',
			'rankings_available' => $rankings_available,
			'latest_public_ranking_update' => $activation_summary['activated_at'] ?? ( $active_ranking_run['calculated_at'] ?? null ),
			'checked_at' => current_time( 'mysql' ),
		);
	}

	private function system_state_json( string $key ): ?array {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT state_value FROM {$this->system_state_table} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			)
		);

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
