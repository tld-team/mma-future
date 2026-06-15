<?php
namespace MMAF\DataEngine\CLI;

use MMAF\DataEngine\Admin\HealthPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HealthCommand {
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'mmaf health', self::class );
	}

	public function __invoke( array $args, array $assoc_args ): void {
		$health = HealthPage::collect();

		\WP_CLI::line( 'MMA Future Data Engine Health' );
		\WP_CLI::line( 'Plugin version: ' . $health['plugin_version'] );
		\WP_CLI::line( 'DB version: ' . ( $health['db_version'] ? $health['db_version'] : 'Not set' ) );
		\WP_CLI::line( 'Active ranking run: ' . ( $health['active_ranking_run'] ? $health['active_ranking_run'] : 'None found' ) );
		\WP_CLI::line( 'Latest import run: ' . ( $health['latest_import_run'] ? $health['latest_import_run'] : 'None found' ) );
		\WP_CLI::line( 'Canonical fighters: ' . $health['phase_2']['canonical_fighters_count'] );
		\WP_CLI::line( 'Linked fighter posts: ' . $health['phase_2']['linked_fighter_posts_count'] );
		\WP_CLI::line( 'Fighter source mappings: ' . $health['phase_2']['fighter_source_mappings_count'] );
		\WP_CLI::line( 'Fighter aliases: ' . $health['phase_2']['fighter_aliases_count'] );
		\WP_CLI::line( 'Canonical events: ' . $health['phase_3']['canonical_events_count'] );
		\WP_CLI::line( 'Event source mappings: ' . $health['phase_3']['event_source_mappings_count'] );
		\WP_CLI::line( 'Canonical bouts: ' . $health['phase_4']['canonical_bouts_count'] );
		\WP_CLI::line( 'Bout source mappings: ' . $health['phase_4']['bout_source_mappings_count'] );
		\WP_CLI::line( 'Bout participant rows: ' . $health['phase_4']['bout_participant_rows_count'] );
		\WP_CLI::line( 'Malformed bouts: ' . $health['phase_4']['malformed_bouts_count'] );
		\WP_CLI::line( 'Fighter stats rows: ' . $health['phase_5']['fighter_stats_rows_count'] );
		\WP_CLI::line( 'Last stats rebuild: ' . ( $health['phase_5']['last_stats_rebuild_time'] ? $health['phase_5']['last_stats_rebuild_time'] : 'None found' ) );
		\WP_CLI::line( 'Ranking runs: ' . $health['phase_6']['ranking_runs_count'] );
		\WP_CLI::line( 'Current live ranking rows: ' . $health['phase_6']['ranking_current_rows_count'] );
		\WP_CLI::line( 'Latest draft ranking rows: ' . $health['phase_6']['latest_draft_rows_count'] );
		\WP_CLI::line( 'Active ranking run ID: ' . ( null === $health['phase_6']['active_ranking_run_id'] ? 'None found' : $health['phase_6']['active_ranking_run_id'] ) );
		\WP_CLI::line( 'Latest ranking calculation: ' . ( $health['phase_6']['latest_calculation_time'] ? $health['phase_6']['latest_calculation_time'] : 'None found' ) );
		\WP_CLI::line( 'Active ranking run status: ' . ( $health['phase_7']['active_ranking_run_status'] ? $health['phase_7']['active_ranking_run_status'] : 'None found' ) );
		\WP_CLI::line( 'Active ranking calculated at: ' . ( $health['phase_7']['active_ranking_calculated_at'] ? $health['phase_7']['active_ranking_calculated_at'] : 'None found' ) );
		\WP_CLI::line( 'Current ranking boards: ' . $health['phase_7']['current_ranking_boards_count'] );
		\WP_CLI::line( 'Current ranking malformed: ' . ( ! empty( $health['phase_7']['current_integrity']['is_malformed'] ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'REST namespace: ' . $health['phase_8']['namespace'] );
		\WP_CLI::line( 'Last import dry-run source: ' . ( $health['phase_9']['source_type'] ? $health['phase_9']['source_type'] : 'None found' ) );
		\WP_CLI::line( 'Last import dry-run schema: ' . ( $health['phase_9']['source_schema_version'] ? $health['phase_9']['source_schema_version'] : 'None found' ) );
		\WP_CLI::line( 'Last import dry-run time: ' . ( $health['phase_9']['finished_at'] ? $health['phase_9']['finished_at'] : 'None found' ) );
		\WP_CLI::line( 'Last import dry-run totals: events=' . $health['phase_9']['events_total'] . ' bouts=' . $health['phase_9']['bouts_total'] . ' fighter_refs=' . $health['phase_9']['fighter_refs_total'] );
		\WP_CLI::line( 'Last import dry-run diagnostics: warnings=' . $health['phase_9']['warnings_count'] . ' conflicts=' . $health['phase_9']['conflicts_count'] );
		\WP_CLI::line( 'Last post-import audit: ' . ( $health['phase_10_1']['audited_at'] ? $health['phase_10_1']['audited_at'] : 'None found' ) );
		\WP_CLI::line( 'Post-import duplicate candidates: ' . $health['phase_10_1']['duplicate_candidates_count'] );
		\WP_CLI::line( 'Post-import conflicts: ' . $health['phase_10_1']['import_conflicts_count'] );
		\WP_CLI::line( 'Post-import needs review: ' . $health['phase_10_1']['import_needs_review_count'] );
		\WP_CLI::line( 'Post-import stats rows vs fighters: stats_rows=' . $health['phase_10_1']['stats_rows'] . ' fighters=' . $health['phase_10_1']['fighters_total'] . ' missing=' . $health['phase_10_1']['stats_missing_count'] );
		\WP_CLI::line( 'Post-import malformed bouts: ' . $health['phase_10_1']['malformed_bouts'] );
		\WP_CLI::line( 'Post-import scoring candidates: ' . $health['phase_10_1']['scoring_candidates_count'] );
		\WP_CLI::line( 'Post-import missing prefight warnings: ' . $health['phase_10_1']['missing_prefight_warnings_count'] );
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Required tables:' );

		foreach ( $health['tables'] as $table ) {
			$status = $table['exists'] ? 'exists' : 'missing';
			$rows   = null === $table['row_count'] ? '-' : (string) $table['row_count'];

			\WP_CLI::line( sprintf( '- %s: %s, rows=%s', $table['name'], $status, $rows ) );
		}

		if ( ! empty( $health['missing_tables'] ) ) {
			\WP_CLI::warning( 'Missing tables: ' . implode( ', ', $health['missing_tables'] ) );
			return;
		}

		\WP_CLI::success( 'All required tables exist.' );
	}
}
