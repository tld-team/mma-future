<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\SourceImportRunRepository;
use MMAF\DataEngine\Services\Audit\DataQualityReportService;
use MMAF\DataEngine\Services\System\SystemCheckService;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HealthPage {
	public static function collect(): array {
		global $wpdb;

		$tables        = Schema::table_names();
		$table_status  = array();
		$missing       = array();

		foreach ( $tables as $key => $table ) {
			$exists    = self::table_exists( $table );
			$row_count = null;

			if ( $exists ) {
				$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$missing[] = $table;
			}

			$table_status[] = array(
				'key'       => $key,
				'name'      => $table,
				'exists'    => $exists,
				'row_count' => $row_count,
			);
		}

		return array(
			'plugin_version'     => defined( 'MMAF_PLUGIN_VERSION' ) ? MMAF_PLUGIN_VERSION : '',
			'db_version'         => (string) get_option( 'mmaf_db_version', '' ),
			'tables'             => $table_status,
			'missing_tables'     => $missing,
			'active_ranking_run' => self::get_active_ranking_run( $tables ),
			'latest_import_run'  => self::get_latest_import_run( $tables ),
			'phase_2'            => self::get_phase_2_summary( $tables ),
			'phase_3'            => self::get_phase_3_summary( $tables ),
			'phase_4'            => self::get_phase_4_summary( $tables ),
			'phase_5'            => self::get_phase_5_summary( $tables ),
			'phase_6'            => self::get_phase_6_summary( $tables ),
			'phase_7'            => self::get_phase_7_summary( $tables ),
			'phase_8'            => self::get_phase_8_summary(),
			'phase_9'            => self::get_phase_9_summary(),
			'phase_10'           => self::get_phase_10_summary(),
			'phase_10_1'         => self::get_phase_10_1_summary(),
			'phase_11'           => self::get_phase_11_summary(),
			'system_check'       => ( new SystemCheckService() )->latest(),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$health = self::collect();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'MMA Future Health', 'mma-future-data-engine' ); ?></h1>

			<h2><?php echo esc_html__( 'Versions', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Plugin version', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['plugin_version'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'DB version option', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['db_version'] ? $health['db_version'] : __( 'Not set', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active ranking run', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_value( $health['active_ranking_run'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest import run', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_value( $health['latest_import_run'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Backend System Check', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest check time', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::system_check_value( $health['system_check'], 'checked_at' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( strtoupper( self::system_check_value( $health['system_check'], 'status' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Critical failures', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::system_check_summary_value( $health['system_check'], 'critical_failures' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::system_check_summary_value( $health['system_check'], 'warnings' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'System Check page', 'mma-future-data-engine' ); ?></th>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-system-check' ) ); ?>"><?php echo esc_html__( 'Open System Check', 'mma-future-data-engine' ); ?></a></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 2 Fighter Management', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Canonical fighters', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_2']['canonical_fighters_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Linked fighter posts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_2']['linked_fighter_posts_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Fighter source mappings', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_2']['fighter_source_mappings_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Fighter aliases', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_2']['fighter_aliases_count'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 3 Event Management', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Canonical events', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_3']['canonical_events_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Event source mappings', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_3']['event_source_mappings_count'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 4 Bout Management', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Canonical bouts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_4']['canonical_bouts_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Bout source mappings', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_4']['bout_source_mappings_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Bout participant rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_4']['bout_participant_rows_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Malformed bouts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_4']['malformed_bouts_count'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 5 Stats Rebuild', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Fighter stats rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_5']['fighter_stats_rows_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last stats rebuild time', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_5']['last_stats_rebuild_time'] ? $health['phase_5']['last_stats_rebuild_time'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last stats rebuild summary', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_stats_summary( $health['phase_5']['last_stats_rebuild_summary'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 6 Ranking Formula', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Ranking runs', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_6']['ranking_runs_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current live ranking rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_6']['ranking_current_rows_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest draft ranking rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_6']['latest_draft_rows_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active ranking run ID', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( null === $health['phase_6']['active_ranking_run_id'] ? __( 'None found', 'mma-future-data-engine' ) : (string) $health['phase_6']['active_ranking_run_id'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest ranking calculation time', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_6']['latest_calculation_time'] ? $health['phase_6']['latest_calculation_time'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest ranking summary', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_ranking_summary( $health['phase_6']['latest_summary'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 7 Ranking Activation', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active ranking run ID', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( null === $health['phase_7']['active_ranking_run_id'] ? __( 'None found', 'mma-future-data-engine' ) : (string) $health['phase_7']['active_ranking_run_id'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active ranking run status', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_7']['active_ranking_run_status'] ? $health['phase_7']['active_ranking_run_status'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active ranking calculated at', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_7']['active_ranking_calculated_at'] ? $health['phase_7']['active_ranking_calculated_at'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current ranking rows', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_7']['current_ranking_rows_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current ranking boards', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_7']['current_ranking_boards_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Latest activation summary', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_activation_summary( $health['phase_7']['latest_activation_summary'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current ranking integrity', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_current_integrity( $health['phase_7']['current_integrity'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 8 REST Read API', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'REST namespace', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_8']['namespace'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Rankings endpoint', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_8']['routes']['rankings'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Fighter endpoint', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_8']['routes']['fighter'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Fighter search endpoint', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_8']['routes']['fighter_search'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'API health endpoint', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_8']['routes']['health'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 9 Scraper JSON Dry-Run Import', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last dry-run source type', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_9']['source_type'] ? $health['phase_9']['source_type'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last dry-run schema', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_9']['source_schema_version'] ? $health['phase_9']['source_schema_version'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last dry-run time', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_9']['finished_at'] ? $health['phase_9']['finished_at'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last dry-run totals', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_import_dry_run_totals( $health['phase_9'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last dry-run diagnostics', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_import_dry_run_diagnostics( $health['phase_9'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 10 Actual Scraper JSON Import', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last import status', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_10']['status'] ? $health['phase_10']['status'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last import time', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_10']['finished_at'] ? $health['phase_10']['finished_at'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last import entity counts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_import_run_counts( $health['phase_10'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last import diagnostics', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_import_run_diagnostics( $health['phase_10'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 10.1 Post-Import Audit', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last audit time', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_10_1']['audited_at'] ? $health['phase_10_1']['audited_at'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Duplicate candidates', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_10_1']['duplicate_candidates_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Import conflicts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_10_1']['import_conflicts_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Import needs review', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_10_1']['import_needs_review_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Stats rows vs fighters', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( self::format_phase_10_1_stats( $health['phase_10_1'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Malformed bouts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_10_1']['malformed_bouts'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Scoring candidates', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_10_1']['scoring_candidates_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Missing prefight warnings', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_10_1']['missing_prefight_warnings_count'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Phase 11 Review Queue / Resolution Tools', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Open review items', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['open_review_items'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Resolved review items', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['resolved_review_items'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Dismissed review items', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['dismissed_review_items'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Needs research items', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['needs_research_items'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Likely match items', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['likely_match_items'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Conflict items', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['import_conflicts'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Provisional Tapology fighters', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['provisional_tapology_fighters'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Duplicate candidates', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['duplicate_candidates'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Source-link actions', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['source_link_actions'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Participant-remap actions', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['participant_remap_actions'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last review action time', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( $health['phase_11']['last_review_action_time'] ? (string) $health['phase_11']['last_review_action_time'] : __( 'None found', 'mma-future-data-engine' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Malformed bouts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['malformed_bouts_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Same-fighter bouts', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $health['phase_11']['same_fighter_bouts_count'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Required Tables', 'mma-future-data-engine' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Table', 'mma-future-data-engine' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Rows', 'mma-future-data-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $health['tables'] as $table ) : ?>
						<tr>
							<td><code><?php echo esc_html( $table['name'] ); ?></code></td>
							<td><?php echo esc_html( $table['exists'] ? __( 'Exists', 'mma-future-data-engine' ) : __( 'Missing', 'mma-future-data-engine' ) ); ?></td>
							<td><?php echo esc_html( null === $table['row_count'] ? '-' : (string) $table['row_count'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	private static function get_active_ranking_run( array $tables ): ?string {
		global $wpdb;

		if ( self::table_exists( $tables['system_state'] ) ) {
			$state_value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'active_ranking_run_id'
				)
			);

			if ( null !== $state_value && '' !== $state_value ) {
				return 'system_state active_ranking_run_id=' . $state_value;
			}
		}

		if ( self::table_exists( $tables['ranking_runs'] ) ) {
			$row = $wpdb->get_row(
				"SELECT id, formula_version, reference_date, status FROM {$tables['ranking_runs']} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);

			if ( $row ) {
				return sprintf(
					'id=%s formula=%s reference_date=%s status=%s',
					$row['id'],
					$row['formula_version'],
					$row['reference_date'],
					$row['status']
				);
			}
		}

		return null;
	}

	private static function get_latest_import_run( array $tables ): ?string {
		global $wpdb;

		if ( ! self::table_exists( $tables['source_import_runs'] ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			"SELECT id, source_type, status, created_at FROM {$tables['source_import_runs']} ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return sprintf(
			'id=%s source=%s status=%s created_at=%s',
			$row['id'],
			$row['source_type'],
			$row['status'],
			$row['created_at']
		);
	}

	private static function get_phase_2_summary( array $tables ): array {
		global $wpdb;

		$summary = array(
			'canonical_fighters_count'       => 0,
			'linked_fighter_posts_count'     => 0,
			'fighter_source_mappings_count'  => 0,
			'fighter_aliases_count'          => 0,
		);

		if ( self::table_exists( $tables['fighters'] ) ) {
			$summary['canonical_fighters_count']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighters']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$summary['linked_fighter_posts_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighters']} WHERE wp_post_id IS NOT NULL AND wp_post_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['fighter_sources'] ) ) {
			$summary['fighter_source_mappings_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighter_sources']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['fighter_aliases'] ) ) {
			$summary['fighter_aliases_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighter_aliases']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $summary;
	}

	private static function get_phase_3_summary( array $tables ): array {
		global $wpdb;

		$summary = array(
			'canonical_events_count'       => 0,
			'event_source_mappings_count' => 0,
		);

		if ( self::table_exists( $tables['events'] ) ) {
			$summary['canonical_events_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['events']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['event_sources'] ) ) {
			$summary['event_source_mappings_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['event_sources']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $summary;
	}

	private static function get_phase_4_summary( array $tables ): array {
		global $wpdb;

		$summary = array(
			'canonical_bouts_count'        => 0,
			'bout_source_mappings_count'  => 0,
			'bout_participant_rows_count' => 0,
			'malformed_bouts_count'       => 0,
		);

		if ( self::table_exists( $tables['bouts'] ) ) {
			$summary['canonical_bouts_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['bouts']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['bout_sources'] ) ) {
			$summary['bout_source_mappings_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['bout_sources']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['bout_participants'] ) ) {
			$summary['bout_participant_rows_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['bout_participants']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['bouts'] ) && self::table_exists( $tables['bout_participants'] ) ) {
			$summary['malformed_bouts_count'] = (int) $wpdb->get_var(
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
		}

		return $summary;
	}

	private static function get_phase_5_summary( array $tables ): array {
		global $wpdb;

		$summary = array(
			'fighter_stats_rows_count'   => 0,
			'last_stats_rebuild_time'    => null,
			'last_stats_rebuild_summary' => null,
		);

		if ( self::table_exists( $tables['fighter_stats_current'] ) ) {
			$summary['fighter_stats_rows_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighter_stats_current']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['system_state'] ) ) {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'last_stats_rebuild_summary'
				)
			);

			if ( is_string( $value ) && '' !== $value ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$summary['last_stats_rebuild_summary'] = $decoded;
					$summary['last_stats_rebuild_time']    = (string) ( $decoded['rebuilt_at'] ?? '' );
				}
			}
		}

		return $summary;
	}

	private static function get_phase_6_summary( array $tables ): array {
		global $wpdb;

		$summary = array(
			'ranking_runs_count'         => 0,
			'ranking_current_rows_count' => 0,
			'latest_draft_rows_count'    => 0,
			'active_ranking_run_id'      => null,
			'latest_calculation_time'    => null,
			'latest_summary'             => null,
		);

		if ( self::table_exists( $tables['ranking_runs'] ) ) {
			$summary['ranking_runs_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ranking_runs']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['ranking_current'] ) ) {
			$summary['ranking_current_rows_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ranking_current']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::table_exists( $tables['ranking_snapshots'] ) && self::table_exists( $tables['ranking_runs'] ) ) {
			$latest_run_id = (int) $wpdb->get_var( "SELECT id FROM {$tables['ranking_runs']} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $latest_run_id > 0 ) {
				$summary['latest_draft_rows_count'] = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['ranking_snapshots']} WHERE ranking_run_id = %d", $latest_run_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		}

		if ( self::table_exists( $tables['system_state'] ) ) {
			$active_run_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'active_ranking_run_id'
				)
			);

			$summary['active_ranking_run_id'] = null === $active_run_id || '' === $active_run_id ? null : (int) $active_run_id;

			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'last_ranking_calculation_summary'
				)
			);

			if ( is_string( $value ) && '' !== $value ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$summary['latest_summary']          = $decoded;
					$summary['latest_calculation_time'] = (string) ( $decoded['calculated_at'] ?? '' );
				}
			}
		}

		return $summary;
	}

	private static function get_phase_7_summary( array $tables ): array {
		global $wpdb;

		$summary = array(
			'active_ranking_run_id'          => null,
			'active_ranking_run_status'      => null,
			'active_ranking_calculated_at'   => null,
			'current_ranking_rows_count'     => 0,
			'current_ranking_boards_count'   => 0,
			'latest_activation_summary'      => null,
			'current_integrity'              => array(
				'duplicate_board_fighters' => 0,
				'missing_fighters'         => 0,
				'invalid_active_run_id'    => 0,
				'is_malformed'             => false,
			),
		);

		if ( self::table_exists( $tables['ranking_current'] ) ) {
			$summary['current_ranking_rows_count']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ranking_current']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$summary['current_ranking_boards_count'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT board_key) FROM {$tables['ranking_current']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$summary['current_integrity']['duplicate_board_fighters'] = (int) $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM (
					SELECT board_key, fighter_id, COUNT(*) AS row_count
					FROM {$tables['ranking_current']}
					GROUP BY board_key, fighter_id
					HAVING row_count > 1
				) duplicates
				" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			if ( self::table_exists( $tables['fighters'] ) ) {
				$summary['current_integrity']['missing_fighters'] = (int) $wpdb->get_var(
					"
					SELECT COUNT(*)
					FROM {$tables['ranking_current']} r
					LEFT JOIN {$tables['fighters']} f ON f.id = r.fighter_id
					WHERE f.id IS NULL
					" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		}

		if ( self::table_exists( $tables['system_state'] ) ) {
			$active_run_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'active_ranking_run_id'
				)
			);

			$summary['active_ranking_run_id'] = null === $active_run_id || '' === $active_run_id ? null : (int) $active_run_id;

			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'last_ranking_activation_summary'
				)
			);

			if ( is_string( $value ) && '' !== $value ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$summary['latest_activation_summary'] = $decoded;
				}
			}
		}

		if ( null !== $summary['active_ranking_run_id'] && self::table_exists( $tables['ranking_runs'] ) ) {
			$active_run = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, status, calculated_at FROM {$tables['ranking_runs']} WHERE id = %d LIMIT 1", $summary['active_ranking_run_id'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);

			if ( $active_run ) {
				$summary['active_ranking_run_status']    = (string) $active_run['status'];
				$summary['active_ranking_calculated_at'] = (string) $active_run['calculated_at'];
			} else {
				$summary['current_integrity']['invalid_active_run_id'] = 1;
			}
		}

		$summary['current_integrity']['is_malformed'] =
			$summary['current_integrity']['duplicate_board_fighters'] > 0
			|| $summary['current_integrity']['missing_fighters'] > 0
			|| $summary['current_integrity']['invalid_active_run_id'] > 0;

		return $summary;
	}

	private static function get_phase_8_summary(): array {
		return array(
			'namespace' => 'mma-future/v1',
			'routes'    => array(
				'rankings'       => '/wp-json/mma-future/v1/rankings',
				'fighter'        => '/wp-json/mma-future/v1/fighters/{id}',
				'fighter_search' => '/wp-json/mma-future/v1/fighters/search?q=...',
				'health'         => '/wp-json/mma-future/v1/health',
			),
		);
	}

	private static function get_phase_9_summary(): array {
		$summary = array(
			'source_type'           => null,
			'source_schema_version' => null,
			'finished_at'           => null,
			'events_total'          => 0,
			'bouts_total'           => 0,
			'fighter_refs_total'    => 0,
			'warnings_count'        => 0,
			'conflicts_count'       => 0,
			'status'                => null,
		);

		$repo = new SourceImportRunRepository();
		$row  = $repo->latest_dry_run();
		if ( ! $row ) {
			return $summary;
		}

		$decoded = array();
		if ( is_string( $row['summary_json'] ?? null ) && '' !== $row['summary_json'] ) {
			$json = json_decode( $row['summary_json'], true );
			if ( is_array( $json ) ) {
				$decoded = $json;
			}
		}

		$summary['source_type']           = (string) ( $row['source_type'] ?? '' );
		$summary['source_schema_version'] = (string) ( $row['source_schema_version'] ?? '' );
		$summary['finished_at']           = (string) ( $row['finished_at'] ?? '' );
		$summary['events_total']          = (int) ( $decoded['events_total'] ?? 0 );
		$summary['bouts_total']           = (int) ( $decoded['bouts_total'] ?? 0 );
		$summary['fighter_refs_total']    = (int) ( $decoded['fighter_refs_total'] ?? 0 );
		$summary['warnings_count']        = (int) ( $decoded['warnings_count'] ?? 0 );
		$summary['conflicts_count']       = (int) ( $decoded['conflicts_count'] ?? 0 );
		$summary['status']                = (string) ( $row['status'] ?? '' );

		return $summary;
	}

	private static function get_phase_10_summary(): array {
		$summary = array(
			'status'                       => null,
			'finished_at'                  => null,
			'events_created'               => 0,
			'events_updated'               => 0,
			'events_no_change'             => 0,
			'fighters_created_provisional' => 0,
			'fighters_exact_matched'       => 0,
			'bouts_created'                => 0,
			'bouts_updated'                => 0,
			'bouts_no_change'              => 0,
			'participants_created_updated' => 0,
			'warnings_count'               => 0,
			'conflicts_count'              => 0,
			'needs_review_count'           => 0,
		);

		$repo = new SourceImportRunRepository();
		$row  = $repo->latest_import();
		if ( ! $row ) {
			return $summary;
		}

		$decoded = array();
		if ( is_string( $row['summary_json'] ?? null ) && '' !== $row['summary_json'] ) {
			$json = json_decode( $row['summary_json'], true );
			if ( is_array( $json ) ) {
				$decoded = $json;
			}
		}

		$summary['status']                       = (string) ( $row['status'] ?? '' );
		$summary['finished_at']                  = (string) ( $row['finished_at'] ?? '' );
		$summary['events_created']               = (int) ( $decoded['events_created'] ?? 0 );
		$summary['events_updated']               = (int) ( $decoded['events_updated'] ?? 0 );
		$summary['events_no_change']             = (int) ( $decoded['events_no_change'] ?? 0 );
		$summary['fighters_created_provisional'] = (int) ( $decoded['fighters_created_provisional'] ?? 0 );
		$summary['fighters_exact_matched']       = (int) ( $decoded['fighters_exact_matched'] ?? 0 );
		$summary['bouts_created']                = (int) ( $decoded['bouts_created'] ?? 0 );
		$summary['bouts_updated']                = (int) ( $decoded['bouts_updated'] ?? 0 );
		$summary['bouts_no_change']              = (int) ( $decoded['bouts_no_change'] ?? 0 );
		$summary['participants_created_updated'] = (int) ( $decoded['participants_created_updated'] ?? 0 );
		$summary['warnings_count']               = (int) ( $decoded['warnings_count'] ?? 0 );
		$summary['conflicts_count']              = (int) ( $decoded['conflicts_count'] ?? 0 );
		$summary['needs_review_count']           = (int) ( $decoded['needs_review_count'] ?? 0 );

		return $summary;
	}

	private static function get_phase_10_1_summary(): array {
		$summary = array(
			'audited_at'                     => null,
			'fighters_total'                 => 0,
			'events_total'                   => 0,
			'bouts_total'                    => 0,
			'participants_total'             => 0,
			'malformed_bouts'                => 0,
			'stats_rows'                     => 0,
			'stats_missing_count'            => 0,
			'duplicate_candidates_count'     => 0,
			'import_conflicts_count'         => 0,
			'import_needs_review_count'      => 0,
			'scoring_candidates_count'       => 0,
			'non_scoring_bouts_count'        => 0,
			'warnings_count'                 => 0,
			'missing_prefight_warnings_count'=> 0,
		);

		$stored = ( new DataQualityReportService() )->latest_stored_summary();
		if ( ! is_array( $stored ) ) {
			return $summary;
		}

		foreach ( $summary as $key => $default ) {
			if ( array_key_exists( $key, $stored ) ) {
				$summary[ $key ] = $stored[ $key ];
			}
		}

		return $summary;
	}

	private static function get_phase_11_summary(): array {
		if ( class_exists( ReviewPage::class ) ) {
			return ReviewPage::summary();
		}

		return array(
			'likely_match_items'            => 0,
			'duplicate_candidates'          => 0,
			'import_conflicts'              => 0,
			'needs_review_import_items'     => 0,
			'provisional_tapology_fighters' => 0,
			'unresolved_source_mappings'    => 0,
			'open_review_items'             => 0,
			'resolved_review_items'         => 0,
			'dismissed_review_items'        => 0,
			'needs_research_items'          => 0,
			'source_link_actions'           => 0,
			'participant_remap_actions'     => 0,
			'last_review_action_time'       => null,
			'malformed_bouts_count'         => 0,
			'same_fighter_bouts_count'      => 0,
		);
	}

	private static function system_check_value( ?array $system_check, string $key ): string {
		if ( ! is_array( $system_check ) || ! array_key_exists( $key, $system_check ) || '' === (string) $system_check[ $key ] ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return (string) $system_check[ $key ];
	}

	private static function system_check_summary_value( ?array $system_check, string $key ): string {
		if ( ! is_array( $system_check ) || ! is_array( $system_check['summary'] ?? null ) || ! array_key_exists( $key, $system_check['summary'] ) ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return (string) $system_check['summary'][ $key ];
	}

	private static function format_value( ?string $value ): string {
		return $value ? $value : __( 'None found', 'mma-future-data-engine' );
	}

	private static function format_stats_summary( ?array $summary ): string {
		if ( null === $summary ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'rows=%d fighters=%d countable_bouts=%d skipped_bouts=%d malformed_bouts=%d participants=%d warnings=%d',
			(int) ( $summary['stats_rows_written'] ?? 0 ),
			(int) ( $summary['fighters_total'] ?? 0 ),
			(int) ( $summary['countable_bouts'] ?? 0 ),
			(int) ( $summary['skipped_bouts'] ?? 0 ),
			(int) ( $summary['malformed_bouts'] ?? 0 ),
			(int) ( $summary['participants_processed'] ?? 0 ),
			(int) ( $summary['warnings_count'] ?? 0 )
		);
	}

	private static function format_ranking_summary( ?array $summary ): string {
		if ( null === $summary ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'run=%d formula=%s reference_date=%s status=%s eligible=%d ineligible=%d rows=%d boards=%s warnings=%d',
			(int) ( $summary['ranking_run_id'] ?? 0 ),
			(string) ( $summary['formula_version'] ?? '' ),
			(string) ( $summary['reference_date'] ?? '' ),
			(string) ( $summary['status'] ?? '' ),
			(int) ( $summary['eligible_fighters'] ?? 0 ),
			(int) ( $summary['ineligible_fighters'] ?? 0 ),
			(int) ( $summary['ranked_rows'] ?? 0 ),
			implode( ',', (array) ( $summary['boards_generated'] ?? array() ) ),
			(int) ( $summary['warnings_count'] ?? 0 )
		);
	}

	private static function format_activation_summary( ?array $summary ): string {
		if ( null === $summary ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'run=%d activated_at=%s rows=%d boards=%d previous_active=%s status=%s warnings=%d',
			(int) ( $summary['ranking_run_id'] ?? 0 ),
			(string) ( $summary['activated_at'] ?? '' ),
			(int) ( $summary['current_rows_written'] ?? 0 ),
			(int) ( $summary['boards_count'] ?? 0 ),
			null === ( $summary['previous_active_ranking_run_id'] ?? null ) ? 'none' : (string) $summary['previous_active_ranking_run_id'],
			(string) ( $summary['status'] ?? '' ),
			(int) ( $summary['warnings_count'] ?? 0 )
		);
	}

	private static function format_current_integrity( array $integrity ): string {
		return sprintf(
			'duplicate_board_fighters=%d missing_fighters=%d invalid_active_run_id=%d malformed=%s',
			(int) ( $integrity['duplicate_board_fighters'] ?? 0 ),
			(int) ( $integrity['missing_fighters'] ?? 0 ),
			(int) ( $integrity['invalid_active_run_id'] ?? 0 ),
			! empty( $integrity['is_malformed'] ) ? 'yes' : 'no'
		);
	}

	private static function format_import_dry_run_totals( array $summary ): string {
		if ( empty( $summary['source_type'] ) ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'events=%d bouts=%d fighter_refs=%d status=%s',
			(int) ( $summary['events_total'] ?? 0 ),
			(int) ( $summary['bouts_total'] ?? 0 ),
			(int) ( $summary['fighter_refs_total'] ?? 0 ),
			(string) ( $summary['status'] ?? '' )
		);
	}

	private static function format_import_dry_run_diagnostics( array $summary ): string {
		if ( empty( $summary['source_type'] ) ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'warnings=%d conflicts=%d',
			(int) ( $summary['warnings_count'] ?? 0 ),
			(int) ( $summary['conflicts_count'] ?? 0 )
		);
	}

	private static function format_import_run_counts( array $summary ): string {
		if ( empty( $summary['status'] ) ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'events=%d/%d/%d fighters=%d provisional,%d exact bouts=%d/%d/%d participants=%d',
			(int) ( $summary['events_created'] ?? 0 ),
			(int) ( $summary['events_updated'] ?? 0 ),
			(int) ( $summary['events_no_change'] ?? 0 ),
			(int) ( $summary['fighters_created_provisional'] ?? 0 ),
			(int) ( $summary['fighters_exact_matched'] ?? 0 ),
			(int) ( $summary['bouts_created'] ?? 0 ),
			(int) ( $summary['bouts_updated'] ?? 0 ),
			(int) ( $summary['bouts_no_change'] ?? 0 ),
			(int) ( $summary['participants_created_updated'] ?? 0 )
		);
	}

	private static function format_import_run_diagnostics( array $summary ): string {
		if ( empty( $summary['status'] ) ) {
			return __( 'None found', 'mma-future-data-engine' );
		}

		return sprintf(
			'warnings=%d conflicts=%d needs_review=%d',
			(int) ( $summary['warnings_count'] ?? 0 ),
			(int) ( $summary['conflicts_count'] ?? 0 ),
			(int) ( $summary['needs_review_count'] ?? 0 )
		);
	}

	private static function format_phase_10_1_stats( array $summary ): string {
		return sprintf(
			'stats_rows=%d fighters=%d missing=%d',
			(int) ( $summary['stats_rows'] ?? 0 ),
			(int) ( $summary['fighters_total'] ?? 0 ),
			(int) ( $summary['stats_missing_count'] ?? 0 )
		);
	}
}
