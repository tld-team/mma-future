<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankingCurrentRepository {
	private string $current_table;
	private string $snapshots_table;
	private string $fighters_table;

	public function __construct() {
		$tables                = Schema::table_names();
		$this->current_table   = $tables['ranking_current'];
		$this->snapshots_table = $tables['ranking_snapshots'];
		$this->fighters_table  = $tables['fighters'];
	}

	public function current_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->current_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function snapshot_count_for_run( int $ranking_run_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->snapshots_table} WHERE ranking_run_id = %d", $ranking_run_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function snapshot_count_for_run_board( int $ranking_run_id, string $board_key ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->snapshots_table} WHERE ranking_run_id = %d AND board_key = %s", $ranking_run_id, $board_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function snapshot_unique_fighter_count_for_run( int $ranking_run_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT fighter_id) FROM {$this->snapshots_table} WHERE ranking_run_id = %d", $ranking_run_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function snapshot_unique_fighter_count_for_run_board( int $ranking_run_id, string $board_key ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT fighter_id) FROM {$this->snapshots_table} WHERE ranking_run_id = %d AND board_key = %s", $ranking_run_id, $board_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function board_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT board_key) FROM {$this->current_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function snapshot_board_count_for_run( int $ranking_run_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT board_key) FROM {$this->snapshots_table} WHERE ranking_run_id = %d", $ranking_run_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function snapshot_boards_for_run( int $ranking_run_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT board_key, COUNT(*) AS rows_count, COUNT(DISTINCT fighter_id) AS unique_fighters
				FROM {$this->snapshots_table}
				WHERE ranking_run_id = %d
				GROUP BY board_key
				ORDER BY CASE WHEN board_key = 'overall' THEN 0 ELSE 1 END, board_key ASC
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ranking_run_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function snapshots_for_run( int $ranking_run_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					r.*,
					f.id AS fighter_exists
				FROM {$this->snapshots_table} r
				LEFT JOIN {$this->fighters_table} f ON f.id = r.fighter_id
				WHERE r.ranking_run_id = %d
				ORDER BY r.board_key ASC, r.rank_position ASC, r.id ASC
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ranking_run_id
			),
			ARRAY_A
		);
	}

	public function replace_current_from_snapshots( int $ranking_run_id ): int {
		global $wpdb;

		$now = DateTime::mysql_now();

		$deleted = $wpdb->query( "DELETE FROM {$this->current_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $deleted ) {
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not clear current ranking rows.', 'mma-future-data-engine' ) );
		}

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"
				INSERT INTO {$this->current_table}
					(ranking_run_id, board_key, fighter_id, rank_position, total_score, raw_score, normalized_score, confidence_score, sample_size, quality_flags_json, breakdown_json, eligibility_json, warnings_json, source_summary_json, created_at, updated_at)
				SELECT
					ranking_run_id, board_key, fighter_id, rank_position, total_score, raw_score, normalized_score, confidence_score, sample_size, quality_flags_json, breakdown_json, eligibility_json, warnings_json, source_summary_json, created_at, %s
				FROM {$this->snapshots_table}
				WHERE ranking_run_id = %d
				ORDER BY board_key ASC, rank_position ASC, id ASC
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$ranking_run_id
			)
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not write current ranking rows.', 'mma-future-data-engine' ) );
		}

		return (int) $inserted;
	}

	public function current_integrity(): array {
		global $wpdb;

		$duplicate_board_fighters = (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM (
				SELECT board_key, fighter_id, COUNT(*) AS row_count
				FROM {$this->current_table}
				GROUP BY board_key, fighter_id
				HAVING row_count > 1
			) duplicates
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$missing_fighters = (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$this->current_table} r
			LEFT JOIN {$this->fighters_table} f ON f.id = r.fighter_id
			WHERE f.id IS NULL
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array(
			'current_rows_count'       => $this->current_count(),
			'current_boards_count'     => $this->board_count(),
			'duplicate_board_fighters' => $duplicate_board_fighters,
			'missing_fighters'         => $missing_fighters,
			'is_malformed'             => $duplicate_board_fighters > 0 || $missing_fighters > 0,
		);
	}

	public function insert_draft_snapshots( int $ranking_run_id, array $rows ): void {
		global $wpdb;

		$now = DateTime::mysql_now();

		$wpdb->query( 'START TRANSACTION' );

		try {
			$deleted = $wpdb->delete( $this->snapshots_table, array( 'ranking_run_id' => $ranking_run_id ), array( '%d' ) );
			if ( false === $deleted ) {
				throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not clear draft ranking snapshots.', 'mma-future-data-engine' ) );
			}

			foreach ( $rows as $row ) {
				$row['ranking_run_id'] = $ranking_run_id;
				$row['created_at']     = $now;
				$inserted = $wpdb->insert( $this->snapshots_table, $row );
				if ( false === $inserted ) {
					throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not insert draft ranking row.', 'mma-future-data-engine' ) );
				}
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function latest_preview( int $ranking_run_id, int $limit = 25, ?string $board_key = null, int $offset = 0 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$where = 'WHERE r.ranking_run_id = %d';
		$args  = array( $ranking_run_id );
		if ( null !== $board_key && '' !== $board_key ) {
			$where .= ' AND r.board_key = %s';
			$args[] = $board_key;
		}
		$args[] = $limit;
		$args[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					r.board_key,
					r.rank_position,
					r.fighter_id,
					f.display_name,
					r.total_score,
					r.raw_score,
					r.normalized_score,
					r.confidence_score,
					r.sample_size,
					r.quality_flags_json,
					r.breakdown_json,
					r.eligibility_json,
					r.warnings_json
				FROM {$this->snapshots_table} r
				LEFT JOIN {$this->fighters_table} f ON f.id = r.fighter_id
				{$where}
				ORDER BY r.board_key ASC, r.rank_position ASC
				LIMIT %d OFFSET %d
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			),
			ARRAY_A
		);
	}

	public function snapshot_warning_diagnostics( int $ranking_run_id, int $sample_limit = 5 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					r.ranking_run_id,
					r.board_key,
					r.rank_position,
					r.fighter_id,
					f.display_name,
					r.eligibility_json,
					r.warnings_json,
					r.source_summary_json
				FROM {$this->snapshots_table} r
				LEFT JOIN {$this->fighters_table} f ON f.id = r.fighter_id
				WHERE r.ranking_run_id = %d
				ORDER BY r.board_key ASC, r.rank_position ASC, r.id ASC
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ranking_run_id
			),
			ARRAY_A
		);

		return $this->warning_diagnostics_from_rows( $rows, $sample_limit );
	}

	public function current_warning_diagnostics( int $ranking_run_id = 0, int $sample_limit = 5 ): array {
		global $wpdb;

		$where = $ranking_run_id > 0 ? $wpdb->prepare( 'WHERE r.ranking_run_id = %d', $ranking_run_id ) : '';
		$rows = $wpdb->get_results(
			"
			SELECT
				r.ranking_run_id,
				r.board_key,
				r.rank_position,
				r.fighter_id,
				f.display_name,
				r.eligibility_json,
				r.warnings_json,
				r.source_summary_json
			FROM {$this->current_table} r
			LEFT JOIN {$this->fighters_table} f ON f.id = r.fighter_id
			{$where}
			ORDER BY r.board_key ASC, r.rank_position ASC, r.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $this->warning_diagnostics_from_rows( $rows, $sample_limit );
	}

	private function warning_diagnostics_from_rows( array $rows, int $sample_limit ): array {
		$sample_limit = max( 1, min( 10, $sample_limit ) );
		$warning_counts = array();
		$warning_samples = array();
		$group_counts = array(
			'serious_readiness' => 0,
			'scoring_context'   => 0,
			'other'             => 0,
		);
		$fighters_with_warnings = array();
		$rows_with_warnings = 0;
		$eligible_rows_with_warnings = 0;
		$ineligible_rows_with_warnings = 0;

		foreach ( $rows as $row ) {
			$warnings_json = json_decode( (string) ( $row['warnings_json'] ?? '' ), true );
			$warnings = is_array( $warnings_json['warnings'] ?? null ) ? array_values( array_unique( $warnings_json['warnings'] ) ) : array();
			if ( empty( $warnings ) ) {
				continue;
			}

			++$rows_with_warnings;
			$fighters_with_warnings[ (int) $row['fighter_id'] ] = true;

			$eligibility = json_decode( (string) ( $row['eligibility_json'] ?? '' ), true );
			if ( is_array( $eligibility ) && ! empty( $eligibility['eligible'] ) ) {
				++$eligible_rows_with_warnings;
			} elseif ( is_array( $eligibility ) ) {
				++$ineligible_rows_with_warnings;
			}

			foreach ( $warnings as $warning ) {
				$warning = (string) $warning;
				if ( ! isset( $warning_counts[ $warning ] ) ) {
					$warning_counts[ $warning ] = 0;
				}
				++$warning_counts[ $warning ];

				$group = $this->warning_group( $warning );
				++$group_counts[ $group ];

				if ( ! isset( $warning_samples[ $warning ] ) ) {
					$warning_samples[ $warning ] = array();
				}
				if ( count( $warning_samples[ $warning ] ) < $sample_limit ) {
					$source_summary = json_decode( (string) ( $row['source_summary_json'] ?? '' ), true );
					$warning_samples[ $warning ][] = array(
						'fighter_id'        => (int) $row['fighter_id'],
						'display_name'      => (string) ( $row['display_name'] ?? '' ),
						'board_key'         => (string) $row['board_key'],
						'rank_position'     => (int) $row['rank_position'],
						'countable_bouts'   => is_array( $source_summary ) ? (int) ( $source_summary['countable_bouts_used'] ?? 0 ) : null,
						'prefight_missing'  => is_array( $source_summary ) ? (int) ( $source_summary['prefight_records_missing_count'] ?? 0 ) : null,
					);
				}
			}
		}

		arsort( $warning_counts );

		return array(
			'rows_checked'                    => count( $rows ),
			'rows_with_warnings'              => $rows_with_warnings,
			'unique_fighters_with_warnings'   => count( $fighters_with_warnings ),
			'eligible_rows_with_warnings'     => $eligible_rows_with_warnings,
			'ineligible_rows_with_warnings'   => $ineligible_rows_with_warnings,
			'warning_counts'                  => $warning_counts,
			'group_counts'                    => $group_counts,
			'samples'                         => $warning_samples,
			'storage_note'                    => __( 'Breakdown is based on ranked rows in ranking snapshots/current rows. Counts are row occurrences across boards, not unique fighters. Ineligible fighter warnings are only available at summary level unless they also have stored rows.', 'mma-future-data-engine' ),
		);
	}

	private function warning_group( string $warning ): string {
		if ( in_array( $warning, array( 'missing_prefight_record', 'missing_method_category', 'skipped_non_scoring_bout', 'birth_year_only_age_estimate' ), true ) ) {
			return 'scoring_context';
		}

		if ( in_array( $warning, array( 'missing_date_of_birth', 'invalid_date_of_birth', 'missing_last_fight_date', 'missing_stats_row', 'rankable_missing_gender', 'rankable_missing_weight_class', 'inconsistent_rankability_status_vs_is_rankable', 'no_countable_fights' ), true ) ) {
			return 'serious_readiness';
		}

		return 'other';
	}
}
