<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankingRunRepository {
	private string $runs_table;
	private string $fighters_table;
	private string $stats_table;
	private string $system_state_table;
	private string $fighter_sources_table;

	public function __construct() {
		$tables                      = Schema::table_names();
		$this->runs_table            = $tables['ranking_runs'];
		$this->fighters_table        = $tables['fighters'];
		$this->stats_table           = $tables['fighter_stats_current'];
		$this->system_state_table    = $tables['system_state'];
		$this->fighter_sources_table = $tables['fighter_sources'];
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->runs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function create( string $formula_version, array $formula_config_snapshot, string $reference_date, string $calculated_at, string $status, string $notes ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->runs_table,
			array(
				'formula_version'          => $formula_version,
				'formula_config_snapshot'  => wp_json_encode( $formula_config_snapshot ),
				'reference_date'           => $reference_date,
				'calculated_at'            => $calculated_at,
				'status'                   => $status,
				'is_active'                => 0,
				'notes'                    => $notes,
				'created_at'               => DateTime::mysql_now(),
				'updated_at'               => null,
			)
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not create ranking run.', 'mma-future-data-engine' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public function update( int $run_id, array $data ): void {
		global $wpdb;

		$data['updated_at'] = DateTime::mysql_now();

		$updated = $wpdb->update(
			$this->runs_table,
			$data,
			array( 'id' => $run_id ),
			null,
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not update ranking run.', 'mma-future-data-engine' ) );
		}
	}

	public function latest(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->runs_table} ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function find( int $ranking_run_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->runs_table} WHERE id = %d LIMIT 1", $ranking_run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function recent( int $limit = 10 ): array {
		global $wpdb;

		$limit = max( 1, min( 50, $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->runs_table} ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function active(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->runs_table} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function list_fighters_with_stats(): array {
		global $wpdb;

		return $wpdb->get_results(
			"
			SELECT
				f.*,
				s.id AS stats_row_id,
				s.wins,
				s.losses,
				s.draws,
				s.nc,
				s.pro_fights_count,
				s.ko_tko_wins,
				s.submission_wins,
				s.decision_wins,
				s.finish_wins,
				s.finish_rate,
				s.last_fight_date,
				s.calculated_at AS stats_calculated_at,
				s.warnings_json AS stats_warnings_json,
				EXISTS (
					SELECT 1
					FROM {$this->fighter_sources_table} tfs
					WHERE tfs.fighter_id = f.id
						AND tfs.source_type = 'tapology'
						AND tfs.source_url IS NOT NULL
						AND tfs.source_url <> ''
						AND tfs.identity_hash IS NOT NULL
						AND tfs.identity_hash <> ''
					LIMIT 1
				) AS has_valid_tapology_source,
				(
					SELECT COUNT(*)
					FROM {$this->fighter_sources_table} tfs_count
					WHERE tfs_count.fighter_id = f.id
						AND tfs_count.source_type = 'tapology'
				) AS tapology_source_count,
				(
					SELECT COUNT(*)
					FROM {$this->fighter_sources_table} tfs_bad
					WHERE tfs_bad.fighter_id = f.id
						AND tfs_bad.source_type = 'tapology'
						AND (
							tfs_bad.source_url IS NULL
							OR tfs_bad.source_url = ''
							OR tfs_bad.identity_hash IS NULL
							OR tfs_bad.identity_hash = ''
						)
				) AS malformed_tapology_source_count
			FROM {$this->fighters_table} f
			LEFT JOIN {$this->stats_table} s ON s.fighter_id = f.id
			ORDER BY f.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function source_types_by_fighter(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT fighter_id, source_type FROM {$this->fighter_sources_table} WHERE fighter_id IS NOT NULL ORDER BY fighter_id ASC, source_type ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$grouped = array();
		foreach ( $rows as $row ) {
			$fighter_id = (int) $row['fighter_id'];
			if ( ! isset( $grouped[ $fighter_id ] ) ) {
				$grouped[ $fighter_id ] = array();
			}
			$grouped[ $fighter_id ][] = (string) $row['source_type'];
		}

		foreach ( $grouped as $fighter_id => $source_types ) {
			$grouped[ $fighter_id ] = array_values( array_unique( $source_types ) );
		}

		return $grouped;
	}

	public function get_last_calculation_summary(): ?array {
		return $this->get_system_state_json( 'last_ranking_calculation_summary' );
	}

	public function set_last_calculation_summary( array $summary ): void {
		$this->set_system_state_json( 'last_ranking_calculation_summary', $summary );
	}

	public function get_last_activation_summary(): ?array {
		return $this->get_system_state_json( 'last_ranking_activation_summary' );
	}

	public function set_last_activation_summary( array $summary ): void {
		$this->set_system_state_json( 'last_ranking_activation_summary', $summary );
	}

	public function get_active_ranking_run_id(): ?int {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT state_value FROM {$this->system_state_table} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'active_ranking_run_id'
			)
		);

		return null === $value || '' === $value ? null : (int) $value;
	}

	public function set_active_ranking_run_id( int $ranking_run_id ): void {
		global $wpdb;

		$updated_at = DateTime::mysql_now();

		$wpdb->query(
			$wpdb->prepare(
				"
				INSERT INTO {$this->system_state_table} (state_key, state_value, autoload, updated_at)
				VALUES (%s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), autoload = VALUES(autoload), updated_at = VALUES(updated_at)
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'active_ranking_run_id',
				(string) $ranking_run_id,
				'no',
				$updated_at
			)
		);
	}

	public function mark_active( int $ranking_run_id ): void {
		global $wpdb;

		$updated_at = DateTime::mysql_now();

		$wpdb->query( $wpdb->prepare( "UPDATE {$this->runs_table} SET is_active = 0, updated_at = %s", $updated_at ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->update(
			$this->runs_table,
			array(
				'is_active'  => 1,
				'updated_at' => $updated_at,
			),
			array( 'id' => $ranking_run_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not mark ranking run active.', 'mma-future-data-engine' ) );
		}
	}

	private function get_system_state_json( string $key ): ?array {
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

	private function set_system_state_json( string $key, array $value ): void {
		global $wpdb;

		$updated_at = DateTime::mysql_now();
		$state_json = wp_json_encode( $value );

		$wpdb->query(
			$wpdb->prepare(
				"
				INSERT INTO {$this->system_state_table} (state_key, state_value, autoload, updated_at)
				VALUES (%s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), autoload = VALUES(autoload), updated_at = VALUES(updated_at)
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$state_json,
				'no',
				$updated_at
			)
		);
	}
}
