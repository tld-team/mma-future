<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterStatsRepository {
	private string $table;
	private string $fighters_table;
	private string $events_table;
	private string $bouts_table;
	private string $participants_table;
	private string $system_state_table;

	public function __construct() {
		$tables                    = Schema::table_names();
		$this->table               = $tables['fighter_stats_current'];
		$this->fighters_table      = $tables['fighters'];
		$this->events_table        = $tables['events'];
		$this->bouts_table         = $tables['bouts'];
		$this->participants_table  = $tables['bout_participants'];
		$this->system_state_table  = $tables['system_state'];
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function list_active_fighters(): array {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT id, display_name, deleted_soft FROM {$this->fighters_table} WHERE deleted_soft = 0 ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function list_bouts_with_events(): array {
		global $wpdb;

		return $wpdb->get_results(
			"
			SELECT
				b.*,
				e.id AS event_exists,
				e.event_date
			FROM {$this->bouts_table} b
			LEFT JOIN {$this->events_table} e ON e.id = b.event_id AND e.deleted_soft = 0
			ORDER BY b.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function list_participants_with_fighters(): array {
		global $wpdb;

		return $wpdb->get_results(
			"
			SELECT
				p.*,
				f.id AS fighter_exists,
				f.deleted_soft AS fighter_deleted_soft
			FROM {$this->participants_table} p
			LEFT JOIN {$this->fighters_table} f ON f.id = p.fighter_id
			ORDER BY p.bout_id ASC, p.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function replace_all( array $rows ): void {
		global $wpdb;

		$now = DateTime::mysql_now();

		$wpdb->query( 'START TRANSACTION' );

		try {
			$deleted = $wpdb->query( "DELETE FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $deleted ) {
				throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not clear fighter stats table.', 'mma-future-data-engine' ) );
			}

			foreach ( $rows as $row ) {
				$row['created_at'] = $now;
				$row['updated_at'] = $now;

				$inserted = $wpdb->insert( $this->table, $row );
				if ( false === $inserted ) {
					throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not insert fighter stats row.', 'mma-future-data-engine' ) );
				}
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function get_last_rebuild_summary(): ?array {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT state_value FROM {$this->system_state_table} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'last_stats_rebuild_summary'
			)
		);

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	public function set_last_rebuild_summary( array $summary ): void {
		global $wpdb;

		$state_value = wp_json_encode( $summary );
		$updated_at  = DateTime::mysql_now();

		$wpdb->query(
			$wpdb->prepare(
				"
				INSERT INTO {$this->system_state_table} (state_key, state_value, autoload, updated_at)
				VALUES (%s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), autoload = VALUES(autoload), updated_at = VALUES(updated_at)
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'last_stats_rebuild_summary',
				$state_value,
				'no',
				$updated_at
			)
		);
	}

	public function find_stat_by_fighter( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE fighter_id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}
}
