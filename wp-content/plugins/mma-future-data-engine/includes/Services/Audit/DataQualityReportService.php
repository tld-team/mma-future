<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\FighterStatsRepository;
use MMAF\DataEngine\Repositories\RankingCurrentRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Repositories\SourceImportRunRepository;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DataQualityReportService {
	public function build_report(): array {
		$duplicates = ( new FighterDuplicateAuditService() )->audit( 50 );
		$bouts      = ( new BoutIntegrityAuditService() )->audit();

		return array(
			'audited_at'       => DateTime::mysql_now(),
			'canonical'        => array(
				'fighters'     => $this->fighter_summary( $duplicates ),
				'events'       => $this->event_summary(),
				'bouts'        => $this->bout_summary( $bouts ),
				'participants' => $this->participant_summary(),
			),
			'import'           => $this->import_summary(),
			'stats'            => $this->stats_summary(),
			'ranking'          => $this->ranking_summary(),
			'duplicates'       => $duplicates,
			'import_items'     => $this->import_item_report(),
			'scoring'          => $bouts,
			'system_summary'   => $this->system_summary( $duplicates, $bouts ),
		);
	}

	public function latest_stored_summary(): ?array {
		$value = $this->system_state_value( 'last_post_import_audit_summary' );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	private function fighter_summary( array $duplicates ): array {
		global $wpdb;

		$tables = Schema::table_names();

		return array(
			'total'                                        => $this->count_where( $tables['fighters'], 'deleted_soft = 0' ),
			'public'                                       => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND is_public = 1' ),
			'non_public'                                   => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND is_public = 0' ),
			'rankable'                                     => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND is_rankable = 1' ),
			'provisional'                                  => $this->count_where( $tables['fighters'], "deleted_soft = 0 AND status = 'provisional'" ),
			'scraped_provisional_tapology'                 => $this->scraped_provisional_count(),
			'with_tapology_source_mappings'                => $this->distinct_count( $tables['fighter_sources'], 'fighter_id', "source_type = 'tapology' AND fighter_id IS NOT NULL" ),
			'without_source_mappings'                      => $this->count_fighters_without_sources(),
			'with_linked_wp_posts'                         => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND wp_post_id IS NOT NULL AND wp_post_id > 0' ),
			'missing_linked_wp_posts'                      => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND (wp_post_id IS NULL OR wp_post_id = 0)' ),
			'unknown_or_null_gender'                       => $this->count_where( $tables['fighters'], "deleted_soft = 0 AND (gender IS NULL OR gender = '' OR gender = 'unknown')" ),
			'unknown_or_null_weight_class'                 => $this->count_where( $tables['fighters'], "deleted_soft = 0 AND (weight_class IS NULL OR weight_class = '' OR weight_class = 'unknown')" ),
			'missing_dob_or_birth_year'                    => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND date_of_birth IS NULL AND birth_year IS NULL' ),
			'duplicate_normalized_name_groups'             => (int) $duplicates['exact_normalized_name_groups_count'],
			'created_from_scraper_import'                  => $this->distinct_count( $tables['fighter_sources'], 'fighter_id', "source_type = 'tapology' AND fighter_id IS NOT NULL" ),
			'created_from_legacy_import'                   => $this->distinct_count( $tables['fighter_sources'], 'fighter_id', "source_type = 'legacy_wp_export' AND fighter_id IS NOT NULL" ),
			'provisional_with_tapology_source'             => $this->scraped_provisional_count(),
			'scraper_provisional_public_or_rankable_count' => (int) $wpdb->get_var(
				"
				SELECT COUNT(DISTINCT f.id)
				FROM {$tables['fighters']} f
				INNER JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id AND fs.source_type = 'tapology'
				WHERE f.deleted_soft = 0
					AND f.status = 'provisional'
					AND (f.is_public = 1 OR f.is_rankable = 1)
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
		);
	}

	private function event_summary(): array {
		$tables = Schema::table_names();

		return array(
			'total'                         => $this->count_where( $tables['events'], 'deleted_soft = 0' ),
			'with_tapology_source_mappings' => $this->distinct_count( $tables['event_sources'], 'event_id', "source_type = 'tapology' AND event_id IS NOT NULL" ),
			'without_source_mappings'       => $this->count_events_without_sources(),
			'missing_event_date'            => $this->count_where( $tables['events'], 'deleted_soft = 0 AND event_date IS NULL' ),
			'missing_promotion_name'        => $this->count_where( $tables['events'], "deleted_soft = 0 AND (promotion_name IS NULL OR promotion_name = '')" ),
			'status_counts'                 => $this->group_counts( $tables['events'], 'status', 'deleted_soft = 0' ),
			'imported_tapology_events'      => $this->distinct_count( $tables['event_sources'], 'event_id', "source_type = 'tapology' AND event_id IS NOT NULL" ),
		);
	}

	private function bout_summary( array $integrity ): array {
		$tables = Schema::table_names();

		return array_merge(
			array(
				'total'                         => $this->count_where( $tables['bouts'], 'deleted_soft = 0' ),
				'with_tapology_source_mappings' => $this->distinct_count( $tables['bout_sources'], 'bout_id', "source_type = 'tapology' AND bout_id IS NOT NULL" ),
				'without_source_mappings'       => $this->count_bouts_without_sources(),
				'unknown_weight_class'          => $this->count_where( $tables['bouts'], "deleted_soft = 0 AND (weight_class IS NULL OR weight_class = '' OR weight_class = 'unknown')" ),
				'missing_round_or_time'         => $this->count_where( $tables['bouts'], "deleted_soft = 0 AND (round_number IS NULL OR time_in_round IS NULL OR time_in_round = '')" ),
				'source_conflict_import_items'  => $this->count_import_items_by_status_type( 'conflict', 'bout' ),
				'status_counts'                 => $this->group_counts( $tables['bouts'], 'status', 'deleted_soft = 0' ),
			),
			$integrity
		);
	}

	private function participant_summary(): array {
		$tables     = Schema::table_names();
		$bout_count = $this->count_where( $tables['bouts'], 'deleted_soft = 0' );

		return array(
			'total_rows'                    => $this->count( $tables['bout_participants'] ),
			'expected_rows'                 => $bout_count * 2,
			'missing_fighter_id'            => $this->count_where( $tables['bout_participants'], 'fighter_id IS NULL OR fighter_id = 0' ),
			'missing_opponent_fighter_id'   => $this->count_where( $tables['bout_participants'], 'opponent_fighter_id IS NULL OR opponent_fighter_id = 0' ),
			'missing_prefight_record_raw'   => $this->count_where( $tables['bout_participants'], "prefight_record_raw IS NULL OR prefight_record_raw = ''" ),
			'missing_prefight_wins_losses'  => $this->count_where( $tables['bout_participants'], 'prefight_wins IS NULL OR prefight_losses IS NULL' ),
			'invalid_result_for_fighter'    => $this->count_where( $tables['bout_participants'], "result_for_fighter IS NULL OR result_for_fighter NOT IN ('win', 'loss', 'draw', 'no_contest')" ),
		);
	}

	private function import_summary(): array {
		$tables = Schema::table_names();
		$runs   = new SourceImportRunRepository();

		return array(
			'latest_dry_run'       => $this->decode_import_run( $runs->latest_dry_run() ),
			'latest_actual_import' => $this->decode_import_run( $runs->latest_import() ),
			'import_runs_count'    => $this->count( $tables['source_import_runs'] ),
			'import_items_count'   => $this->count( $tables['source_import_items'] ),
			'needs_review_count'   => $this->count_import_items_by_status( 'needs_review' ),
			'conflict_count'       => $this->count_import_items_by_status( 'conflict' ),
			'failed_count'         => $this->count_import_items_by_status( 'failed' ),
			'known_conflict_found' => $this->source_import_item_exists( 'tapology_bout_1116772', 'conflict' ),
		);
	}

	private function stats_summary(): array {
		global $wpdb;

		$tables      = Schema::table_names();
		$stats_repo  = new FighterStatsRepository();
		$fighter_cnt = $this->count_where( $tables['fighters'], 'deleted_soft = 0' );

		return array(
			'current_stats_rows'                 => $this->count( $tables['fighter_stats_current'] ),
			'total_non_deleted_canonical_fighters'=> $fighter_cnt,
			'stats_rows_missing_for_fighters'   => (int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$tables['fighters']} f
				LEFT JOIN {$tables['fighter_stats_current']} s ON s.fighter_id = f.id
				WHERE f.deleted_soft = 0 AND s.fighter_id IS NULL
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
			'stats_rows_with_no_fights'          => $this->count_where( $tables['fighter_stats_current'], 'pro_fights_count = 0' ),
			'stats_rows_with_countable_fights'   => $this->count_where( $tables['fighter_stats_current'], 'pro_fights_count > 0' ),
			'last_stats_rebuild_summary'         => $stats_repo->get_last_rebuild_summary(),
		);
	}

	private function ranking_summary(): array {
		$runs             = new RankingRunRepository();
		$current          = new RankingCurrentRepository();
		$latest           = $runs->latest();
		$latest_run_id    = $latest ? (int) $latest['id'] : 0;
		$current_rows     = $current->current_count();
		$rankable_fighters = $this->count_where( Schema::table_names()['fighters'], 'deleted_soft = 0 AND is_rankable = 1' );

		return array(
			'ranking_runs_count'                     => $runs->count(),
			'active_ranking_run_id'                  => $runs->get_active_ranking_run_id(),
			'current_ranking_rows_count'             => $current_rows,
			'latest_ranking_draft_rows'              => $latest_run_id > 0 ? $current->snapshot_count_for_run( $latest_run_id ) : 0,
			'latest_ranking_calculation_summary'     => $runs->get_last_calculation_summary(),
			'active_ranking_still_looks_like_test_data'=> 2 === $current_rows && 0 === $rankable_fighters,
			'rankable_fighters_count'                => $rankable_fighters,
		);
	}

	private function import_item_report(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$items  = $wpdb->get_results(
			"
			SELECT import_run_id, item_type, source_id, action, status, warnings_json, error_message, canonical_id, created_at
			FROM {$tables['source_import_items']}
			WHERE status IN ('conflict', 'needs_review', 'failed')
			ORDER BY FIELD(status, 'conflict', 'needs_review', 'failed'), id DESC
			LIMIT 100
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'conflict_count'        => $this->count_import_items_by_status( 'conflict' ),
			'needs_review_count'    => $this->count_import_items_by_status( 'needs_review' ),
			'failed_count'          => $this->count_import_items_by_status( 'failed' ),
			'known_conflict_source' => 'tapology_bout_1116772',
			'known_conflict_found'  => $this->source_import_item_exists( 'tapology_bout_1116772', 'conflict' ),
			'items'                 => $items,
		);
	}

	private function system_summary( array $duplicates, array $bouts ): array {
		$tables       = Schema::table_names();
		$stats        = $this->stats_summary();
		$import_items = $this->import_item_report();
		$warnings     = (int) $bouts['missing_prefight_record_count']
			+ (int) $bouts['malformed_bouts_count']
			+ (int) $import_items['conflict_count']
			+ (int) $import_items['needs_review_count']
			+ (int) $duplicates['likely_duplicates_count'];

		return array(
			'audited_at'                 => DateTime::mysql_now(),
			'fighters_total'             => $this->count_where( $tables['fighters'], 'deleted_soft = 0' ),
			'events_total'               => $this->count_where( $tables['events'], 'deleted_soft = 0' ),
			'bouts_total'                => $this->count_where( $tables['bouts'], 'deleted_soft = 0' ),
			'participants_total'         => $this->count( $tables['bout_participants'] ),
			'malformed_bouts'            => (int) $bouts['malformed_bouts_count'],
			'stats_rows'                 => (int) $stats['current_stats_rows'],
			'stats_missing_count'        => (int) $stats['stats_rows_missing_for_fighters'],
			'duplicate_candidates_count' => (int) $duplicates['likely_duplicates_count'],
			'import_conflicts_count'     => (int) $import_items['conflict_count'],
			'import_needs_review_count'  => (int) $import_items['needs_review_count'],
			'scoring_candidates_count'   => (int) $bouts['scoring_candidate_count'],
			'non_scoring_bouts_count'    => (int) $bouts['non_scoring_count'],
			'warnings_count'             => $warnings,
			'missing_prefight_warnings_count'=> (int) $bouts['missing_prefight_record_count'],
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

	private function group_counts( string $table, string $field, string $where ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT {$field} AS item_key, COUNT(*) AS item_count FROM {$table} WHERE {$where} GROUP BY {$field} ORDER BY item_count DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['item_key'] ] = (int) $row['item_count'];
		}

		return $counts;
	}

	private function scraped_provisional_count(): int {
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

	private function count_fighters_without_sources(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['fighters']} f
			LEFT JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id
			WHERE f.deleted_soft = 0 AND fs.id IS NULL
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_events_without_sources(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['events']} e
			LEFT JOIN {$tables['event_sources']} es ON es.event_id = e.id
			WHERE e.deleted_soft = 0 AND es.id IS NULL
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_bouts_without_sources(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['bouts']} b
			LEFT JOIN {$tables['bout_sources']} bs ON bs.bout_id = b.id
			WHERE b.deleted_soft = 0 AND bs.id IS NULL
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_import_items_by_status( string $status ): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_import_items_by_status_type( string $status, string $item_type ): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE status = %s AND item_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status,
				$item_type
			)
		);
	}

	private function source_import_item_exists( string $source_id, string $status ): bool {
		global $wpdb;

		$tables = Schema::table_names();

		return 0 < (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE source_id = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$source_id,
				$status
			)
		);
	}

	private function decode_import_run( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}

		$summary = array();
		if ( is_string( $row['summary_json'] ?? null ) && '' !== $row['summary_json'] ) {
			$decoded = json_decode( $row['summary_json'], true );
			$summary = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'id'                    => (int) $row['id'],
			'source_type'           => (string) $row['source_type'],
			'source_schema_version' => (string) $row['source_schema_version'],
			'status'                => (string) $row['status'],
			'mode'                  => (string) $row['mode'],
			'dry_run'               => 1 === (int) $row['dry_run'],
			'started_at'            => (string) $row['started_at'],
			'finished_at'           => (string) $row['finished_at'],
			'summary'               => $summary,
		);
	}

	private function system_state_value( string $key ): ?string {
		global $wpdb;

		$tables = Schema::table_names();

		return $wpdb->get_var(
			$wpdb->prepare( "SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", $key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
