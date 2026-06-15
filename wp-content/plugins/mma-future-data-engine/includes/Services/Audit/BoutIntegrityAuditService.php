<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BoutIntegrityAuditService {
	public function audit(): array {
		global $wpdb;

		$tables = Schema::table_names();

		$malformed_bouts = (int) $wpdb->get_var(
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
		);

		$missing_fighter_bouts = (int) $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT p.bout_id)
			FROM {$tables['bout_participants']} p
			LEFT JOIN {$tables['fighters']} f ON f.id = p.fighter_id
			WHERE p.fighter_id IS NULL OR f.id IS NULL
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$missing_prefight_bouts = (int) $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT bout_id)
			FROM {$tables['bout_participants']}
			WHERE prefight_record_raw IS NULL
				OR prefight_record_raw = ''
				OR prefight_wins IS NULL
				OR prefight_losses IS NULL
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array(
			'total_bouts'                              => $this->count( $tables['bouts'] ),
			'imported_tapology_bouts'                 => $this->distinct_count( $tables['bout_sources'], 'bout_id', "source_type = 'tapology' AND bout_id IS NOT NULL" ),
			'scoring_candidate_count'                 => $this->count_where( $tables['bouts'], 'is_scoring_candidate = 1 AND deleted_soft = 0' ),
			'non_scoring_count'                       => $this->count_where( $tables['bouts'], 'is_scoring_candidate = 0 AND deleted_soft = 0' ),
			'excluded_amateur_count'                  => $this->count_like_status( $tables['bouts'], 'amateur' ),
			'excluded_cancelled_count'                => $this->count_like_status( $tables['bouts'], 'cancel' ),
			'excluded_overturned_count'               => $this->count_like_status( $tables['bouts'], 'overturn' ),
			'no_contest_draw_count'                   => $this->count_where( $tables['bouts'], "result_type IN ('draw', 'no_contest') OR result_type LIKE '%draw%' OR result_type LIKE '%contest%'" ),
			'unknown_result_count'                    => $this->count_where( $tables['bouts'], "result_type IS NULL OR result_type = '' OR result_type = 'unknown'" ),
			'missing_method_count'                    => $this->count_where( $tables['bouts'], "method_category IS NULL OR method_category = '' OR method_category = 'unknown'" ),
			'missing_prefight_record_count'           => $missing_prefight_bouts,
			'malformed_bouts_count'                   => $malformed_bouts,
			'missing_event_count'                     => $this->missing_event_count(),
			'missing_fighter_bouts_count'             => $missing_fighter_bouts,
			'scoring_candidate_unknown_method_count'  => $this->count_where( $tables['bouts'], "is_scoring_candidate = 1 AND (method_category IS NULL OR method_category = '' OR method_category = 'unknown')" ),
			'scoring_candidate_non_win_loss_count'    => $this->count_where( $tables['bouts'], "is_scoring_candidate = 1 AND (result_type IS NULL OR result_type <> 'win_loss')" ),
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

	private function distinct_count( string $table, string $field, string $where ): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT {$field}) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function count_like_status( string $table, string $needle ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function missing_event_count(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['bouts']} b
			LEFT JOIN {$tables['events']} e ON e.id = b.event_id
			WHERE e.id IS NULL
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
