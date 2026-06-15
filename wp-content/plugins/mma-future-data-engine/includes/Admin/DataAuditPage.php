<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Repositories\RankingCurrentRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Services\Audit\DataQualityReportService;
use MMAF\DataEngine\Services\Audit\FighterEnrichmentAuditService;
use MMAF\DataEngine\Services\Audit\FighterIdentityAuditService;
use MMAF\DataEngine\Services\Audit\FightHistoryCompletenessAuditService;
use MMAF\DataEngine\Services\Audit\FightHistoryStagingReportService;
use MMAF\DataEngine\Services\Audit\PostImportAuditService;
use MMAF\DataEngine\Services\Audit\ProfileRecordComparisonService;
use MMAF\DataEngine\Services\RankingCalculatorService;
use MMAF\DataEngine\Services\StatsRebuildService;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DataAuditPage {
	private const PAGE_SLUG = 'mmaf-data-audit';

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$notice = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$notice = self::handle_post();
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		if ( ! in_array( $tab, array( 'overview', 'identity_safeguards', 'record_comparison', 'fighter_enrichment', 'fight_history_completeness', 'fight_history_staging' ), true ) ) {
			$tab = 'overview';
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Data Audit', 'mma-future-data-engine' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="<?php echo esc_attr( 'error' === $notice['type'] ? 'notice notice-error' : 'notice notice-success' ); ?>">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" style="margin-bottom: 16px;">
				<a class="<?php echo esc_attr( 'nav-tab' . ( 'overview' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url() ); ?>"><?php echo esc_html__( 'Post-Import Audit', 'mma-future-data-engine' ); ?></a>
				<a class="<?php echo esc_attr( 'nav-tab' . ( 'identity_safeguards' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => 'identity_safeguards' ) ) ); ?>"><?php echo esc_html__( 'Identity Safeguards', 'mma-future-data-engine' ); ?></a>
				<a class="<?php echo esc_attr( 'nav-tab' . ( 'record_comparison' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => 'record_comparison' ) ) ); ?>"><?php echo esc_html__( 'Record Comparison', 'mma-future-data-engine' ); ?></a>
				<a class="<?php echo esc_attr( 'nav-tab' . ( 'fighter_enrichment' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => 'fighter_enrichment' ) ) ); ?>"><?php echo esc_html__( 'Fighter Enrichment Audit', 'mma-future-data-engine' ); ?></a>
				<a class="<?php echo esc_attr( 'nav-tab' . ( 'fight_history_completeness' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => 'fight_history_completeness' ) ) ); ?>"><?php echo esc_html__( 'Fight History Completeness', 'mma-future-data-engine' ); ?></a>
				<a class="<?php echo esc_attr( 'nav-tab' . ( 'fight_history_staging' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => 'fight_history_staging' ) ) ); ?>"><?php echo esc_html__( 'Fight History Staging', 'mma-future-data-engine' ); ?></a>
			</nav>

			<?php
			if ( 'identity_safeguards' === $tab ) {
				self::render_identity_safeguards_audit();
				echo '</div>';
				return;
			}
			if ( 'record_comparison' === $tab ) {
				self::render_record_comparison_audit();
				echo '</div>';
				return;
			}
			if ( 'fighter_enrichment' === $tab ) {
				self::render_fighter_enrichment_audit();
				echo '</div>';
				return;
			}
			if ( 'fight_history_completeness' === $tab ) {
				self::render_fight_history_completeness_audit();
				echo '</div>';
				return;
			}
			if ( 'fight_history_staging' === $tab ) {
				self::render_fight_history_staging_audit();
				echo '</div>';
				return;
			}

		$service       = new DataQualityReportService();
		$report        = $service->build_report();
		$stored_summary = $service->latest_stored_summary();
		?>
			<h2><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></h2>
			<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin: 12px 0 22px;">
				<form method="post">
					<?php wp_nonce_field( 'mmaf_run_data_audit', 'mmaf_data_audit_nonce' ); ?>
					<input type="hidden" name="mmaf_action" value="run_data_audit">
					<?php submit_button( __( 'Run Data Audit', 'mma-future-data-engine' ), 'primary', 'submit', false ); ?>
				</form>
				<form method="post">
					<?php wp_nonce_field( 'mmaf_data_audit_rebuild_stats', 'mmaf_data_audit_nonce' ); ?>
					<input type="hidden" name="mmaf_action" value="rebuild_stats">
					<?php submit_button( __( 'Rebuild Stats Now', 'mma-future-data-engine' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post">
					<?php wp_nonce_field( 'mmaf_data_audit_calculate_ranking_draft', 'mmaf_data_audit_nonce' ); ?>
					<input type="hidden" name="mmaf_action" value="calculate_ranking_draft">
					<?php submit_button( __( 'Calculate Ranking Draft', 'mma-future-data-engine' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<h2><?php echo esc_html__( 'Latest Stored Audit Summary', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $stored_summary ? $stored_summary : array( 'status' => __( 'No stored post-import audit summary found. Run Data Audit to store one.', 'mma-future-data-engine' ) ) ); ?>

			<h2><?php echo esc_html__( 'Current Post-Import Integrity Summary', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['system_summary'] ); ?>

			<h2><?php echo esc_html__( 'Fighters', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['canonical']['fighters'] ); ?>

			<h2><?php echo esc_html__( 'Events', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['canonical']['events'] ); ?>

			<h2><?php echo esc_html__( 'Bouts', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['canonical']['bouts'] ); ?>

			<h2><?php echo esc_html__( 'Participants', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['canonical']['participants'] ); ?>

			<h2><?php echo esc_html__( 'Stats Rebuild Status', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['stats'] ); ?>

			<h2><?php echo esc_html__( 'Ranking Draft / Current Status', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['ranking'] ); ?>

			<h2><?php echo esc_html__( 'Latest Import Summary', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['import'] ); ?>

			<h2><?php echo esc_html__( 'Scoring Candidate Health', 'mma-future-data-engine' ); ?></h2>
			<?php self::render_key_value_table( $report['scoring'] ); ?>

			<h2><?php echo esc_html__( 'Duplicate / Matching Review Summary', 'mma-future-data-engine' ); ?></h2>
			<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-review&tab=duplicates&status=open&source_type=tapology' ) ); ?>"><?php echo esc_html__( 'Open Review: Duplicate Fighters', 'mma-future-data-engine' ); ?></a> <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-review&tab=likely_matches&status=open&source_type=tapology' ) ); ?>"><?php echo esc_html__( 'Open Review: Likely Matches', 'mma-future-data-engine' ); ?></a></p>
			<?php self::render_key_value_table(
				array(
					'exact_normalized_name_groups_count' => $report['duplicates']['exact_normalized_name_groups_count'],
					'likely_duplicates_count'            => $report['duplicates']['likely_duplicates_count'],
				)
			); ?>
			<?php self::render_duplicate_candidates_table( $report['duplicates']['review_candidates'] ); ?>

			<h2><?php echo esc_html__( 'Import Conflicts / Needs Review', 'mma-future-data-engine' ); ?></h2>
			<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-review&tab=conflicts&status=open&source_type=tapology' ) ); ?>"><?php echo esc_html__( 'Open Review: Import Conflicts', 'mma-future-data-engine' ); ?></a> <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-review&tab=needs_review&status=needs_review&source_type=tapology' ) ); ?>"><?php echo esc_html__( 'Open Review: Needs Review', 'mma-future-data-engine' ); ?></a> <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-review&tab=provisional&status=open&source_type=tapology' ) ); ?>"><?php echo esc_html__( 'Open Review: Provisional Fighters', 'mma-future-data-engine' ); ?></a></p>
			<?php self::render_key_value_table(
				array(
					'conflict_count'        => $report['import_items']['conflict_count'],
					'needs_review_count'    => $report['import_items']['needs_review_count'],
					'failed_count'          => $report['import_items']['failed_count'],
					'known_conflict_source' => $report['import_items']['known_conflict_source'],
					'known_conflict_found'  => $report['import_items']['known_conflict_found'] ? 'yes' : 'no',
				)
			); ?>
			<?php self::render_import_items_table( $report['import_items']['items'] ); ?>
		</div>
		<?php
	}

	private static function render_identity_safeguards_audit(): void {
		$report = ( new FighterIdentityAuditService() )->build_report( 100 );
		?>
		<h2><?php echo esc_html__( 'Manual Fighter Identity Safeguards', 'mma-future-data-engine' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Read-only identity report. This page never links, merges, promotes, rebuilds stats, or recalculates rankings.', 'mma-future-data-engine' ); ?></p>

		<h3><?php echo esc_html__( 'Summary', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_key_value_table( (array) $report['summary'] ); ?>

		<h3><?php echo esc_html__( 'Manual / Legacy Fighters Without Tapology Mapping', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_identity_rows_table( (array) $report['manual_legacy_without_tapology'] ); ?>

		<h3><?php echo esc_html__( 'Duplicate Source Fighter IDs', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_identity_rows_table( (array) $report['duplicate_source_fighter_ids'] ); ?>

		<h3><?php echo esc_html__( 'Duplicate Normalized Source URLs', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_identity_rows_table( (array) $report['duplicate_normalized_source_urls'] ); ?>

		<h3><?php echo esc_html__( 'Public / Legacy Fighters With Possible Tapology Counterpart', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_identity_rows_table( (array) $report['possible_legacy_tapology_pairs'] ); ?>

		<h3><?php echo esc_html__( 'Provisional Tapology Fighters With Possible Manual / Legacy Counterpart', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_identity_rows_table( (array) $report['possible_legacy_tapology_pairs'] ); ?>

		<h3><?php echo esc_html__( 'Recently Created / Edited Without Tapology Mapping', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_identity_rows_table( (array) $report['recent_without_tapology_source'] ); ?>
		<?php
	}

	private static function handle_post(): array {
		$action = isset( $_POST['mmaf_action'] ) ? sanitize_key( wp_unslash( $_POST['mmaf_action'] ) ) : '';

		if ( 'run_data_audit' === $action ) {
			check_admin_referer( 'mmaf_run_data_audit', 'mmaf_data_audit_nonce' );

			$report = ( new PostImportAuditService() )->run();
			$summary = $report['system_summary'];

			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: fighters, 2: events, 3: bouts, 4: duplicate candidates, 5: conflicts. */
					__( 'Data audit stored. Fighters: %1$d. Events: %2$d. Bouts: %3$d. Duplicate candidates: %4$d. Conflicts: %5$d.', 'mma-future-data-engine' ),
					(int) $summary['fighters_total'],
					(int) $summary['events_total'],
					(int) $summary['bouts_total'],
					(int) $summary['duplicate_candidates_count'],
					(int) $summary['import_conflicts_count']
				),
			);
		}

		if ( 'rebuild_stats' === $action ) {
			check_admin_referer( 'mmaf_data_audit_rebuild_stats', 'mmaf_data_audit_nonce' );

			$ranking_runs = new RankingRunRepository();
			$current      = new RankingCurrentRepository();
			$active_before = $ranking_runs->get_active_ranking_run_id();
			$current_before = $current->current_count();

			$summary = ( new StatsRebuildService() )->rebuild_all( get_current_user_id(), 'Phase 10.1 post-import stats rebuild from Data Audit' );
			( new PostImportAuditService() )->run();

			$active_after = $ranking_runs->get_active_ranking_run_id();
			$current_after = $current->current_count();

			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: fighters, 2: stats rows, 3: countable bouts, 4: skipped bouts, 5: malformed bouts, 6: participants, 7: warnings, 8: active before, 9: active after, 10: current rows before, 11: current rows after. */
					__( 'Stats rebuilt. Fighters: %1$d. Rows: %2$d. Countable bouts: %3$d. Skipped: %4$d. Malformed: %5$d. Participants: %6$d. Warnings: %7$d. Active ranking run stayed %8$s -> %9$s. Current ranking rows stayed %10$d -> %11$d.', 'mma-future-data-engine' ),
					(int) $summary['fighters_total'],
					(int) $summary['stats_rows_written'],
					(int) $summary['countable_bouts'],
					(int) $summary['skipped_bouts'],
					(int) $summary['malformed_bouts'],
					(int) $summary['participants_processed'],
					(int) $summary['warnings_count'],
					null === $active_before ? 'none' : (string) $active_before,
					null === $active_after ? 'none' : (string) $active_after,
					$current_before,
					$current_after
				),
			);
		}

		if ( 'calculate_ranking_draft' === $action ) {
			check_admin_referer( 'mmaf_data_audit_calculate_ranking_draft', 'mmaf_data_audit_nonce' );

			$ranking_runs = new RankingRunRepository();
			$current      = new RankingCurrentRepository();
			$active_before = $ranking_runs->get_active_ranking_run_id();
			$current_before = $current->current_count();

			$summary = ( new RankingCalculatorService() )->calculate_draft( get_current_user_id() );
			( new PostImportAuditService() )->run();

			$active_after = $ranking_runs->get_active_ranking_run_id();
			$current_after = $current->current_count();

			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: run ID, 2: eligible, 3: ineligible, 4: rows, 5: warnings, 6: boards, 7: active before, 8: active after, 9: current before, 10: current after. */
					__( 'Ranking draft calculated. Run: %1$d. Eligible: %2$d. Ineligible: %3$d. Draft rows: %4$d. Warnings: %5$d. Boards: %6$s. Active ranking run stayed %7$s -> %8$s. Current ranking rows stayed %9$d -> %10$d.', 'mma-future-data-engine' ),
					(int) $summary['ranking_run_id'],
					(int) $summary['eligible_fighters'],
					(int) $summary['ineligible_fighters'],
					(int) $summary['ranked_rows'],
					(int) $summary['warnings_count'],
					implode( ',', (array) $summary['boards_generated'] ),
					null === $active_before ? 'none' : (string) $active_before,
					null === $active_after ? 'none' : (string) $active_after,
					$current_before,
					$current_after
				),
			);
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Invalid data audit action.', 'mma-future-data-engine' ),
		);
	}

	private static function render_record_comparison_audit(): void {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filters = isset( $_GET['record_filter'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['record_filter'] ) ) : array();
		$filters = array_values( array_intersect( $filters, ProfileRecordComparisonService::FILTERS ) );
		$path = isset( $_GET['profile_path'] ) ? sanitize_text_field( wp_unslash( $_GET['profile_path'] ) ) : ProfileRecordComparisonService::default_path();
		$per_page = self::current_per_page();
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset = ( $paged - 1 ) * $per_page;
		$service = new ProfileRecordComparisonService();

		try {
			$report = $service->build_report( $path, $filters, $search, $per_page, $offset );
			$total = (int) $report['total'];
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
			if ( $paged > $total_pages ) {
				$paged = $total_pages;
				$offset = ( $paged - 1 ) * $per_page;
				$report = $service->build_report( $path, $filters, $search, $per_page, $offset );
			}
			$error = '';
		} catch ( \Throwable $exception ) {
			$report = array(
				'summary' => array(
					'enrichment_file' => $path,
					'status' => 'error',
					'message' => $exception->getMessage(),
				),
				'rows' => array(),
				'total' => 0,
				'path' => $path,
			);
			$total = 0;
			$error = $exception->getMessage();
		}

		?>
		<h2><?php echo esc_html__( 'Record Comparison', 'mma-future-data-engine' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Read-only comparison of Tapology profile aggregate records and fight-history coverage against canonical stats. This report never imports records, stages history, rebuilds stats, or changes ranking data.', 'mma-future-data-engine' ); ?></p>
		<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Ranking warning: profile aggregate record is display/audit suggestion only. Canonical stats remain the only ranking-grade source. Do not activate ranking based on this report.', 'mma-future-data-engine' ); ?></p></div>

		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<h3><?php echo esc_html__( 'Summary', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_key_value_table( (array) $report['summary'] ); ?>

		<h3><?php echo esc_html__( 'Per-Profile Comparison', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_record_comparison_filters( $search, $filters, $per_page, (string) $report['path'] ); ?>
		<?php self::render_record_comparison_pagination( $total, $paged, $per_page, $search, $filters, (string) $report['path'] ); ?>
		<?php self::render_record_comparison_table( (array) $report['rows'] ); ?>
		<?php self::render_record_comparison_pagination( $total, $paged, $per_page, $search, $filters, (string) $report['path'] ); ?>
		<?php
	}

	private static function render_record_comparison_filters( string $search, array $selected_filters, int $per_page, string $path ): void {
		$options = self::record_comparison_filter_options();
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="record_comparison">
			<label for="mmaf-record-comparison-search"><?php echo esc_html__( 'Search', 'mma-future-data-engine' ); ?></label>
			<input id="mmaf-record-comparison-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Profile name, canonical name, or source fighter ID', 'mma-future-data-engine' ); ?>" style="min-width: 320px;">
			<?php self::render_per_page_select( $per_page ); ?>
			<p style="margin: 10px 0 0;">
				<label for="mmaf-profile-path"><?php echo esc_html__( 'Profile JSON', 'mma-future-data-engine' ); ?></label>
				<input id="mmaf-profile-path" type="text" name="profile_path" value="<?php echo esc_attr( $path ); ?>" style="min-width: 640px; max-width: 100%;">
			</p>
			<fieldset style="margin: 12px 0 8px;">
				<legend class="screen-reader-text"><?php echo esc_html__( 'Record comparison filters', 'mma-future-data-engine' ); ?></legend>
				<?php foreach ( $options as $key => $label ) : ?>
					<label style="display:inline-block; margin: 0 14px 8px 0;">
						<input type="checkbox" name="record_filter[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_filters, true ) ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button( __( 'Apply Filters', 'mma-future-data-engine' ), 'secondary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( self::page_url( array( 'tab' => 'record_comparison' ) ) ); ?>"><?php echo esc_html__( 'Reset', 'mma-future-data-engine' ); ?></a>
		</form>
		<?php
	}

	private static function render_record_comparison_table( array $rows ): void {
		?>
		<div style="overflow-x:auto;">
			<table class="widefat striped" style="min-width: 2500px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Source fighter ID', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Source URL', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Profile display name', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical fighter', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Match type', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical W-L-D-NC', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical countable fights', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical finish wins', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical last fight', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Stats warnings', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Profile pro record', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Parsed profile W-L-D-NC', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'History rows', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Completeness', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Record gap type', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Record gap summary', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Last fight gap', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'History coverage', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Date/result/method', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Event/bout/opponent URLs', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Opponent records', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Staging candidates', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Suggested action', 'mma-future-data-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="24"><?php echo esc_html__( 'No profiles matched the selected record comparison filters.', 'mma-future-data-engine' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( self::empty_marker( (string) $row['source_fighter_id'] ) ); ?></td>
							<td><?php self::render_source_url( (string) $row['source_url'] ); ?></td>
							<td><strong><?php echo esc_html( self::empty_marker( (string) $row['profile_display_name'] ) ); ?></strong></td>
							<td><?php echo esc_html( self::canonical_fighter_summary( $row ) ); ?></td>
							<td><?php echo esc_html( (string) $row['match_type'] ); ?></td>
							<td><?php echo esc_html( self::shorten( (string) $row['warnings'] ) ); ?></td>
							<td><?php echo esc_html( self::record_summary_from_parts( $row, 'canonical' ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['canonical_countable_fights'] ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['canonical_finish_wins'] ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['canonical_last_fight_date'] ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['canonical_stats_warning_count'] ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['pro_record_raw'] ) ); ?></td>
							<td><?php echo esc_html( self::record_summary_from_parts( $row, 'profile' ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['profile_fight_history_row_count'] ); ?></td>
							<td><?php echo esc_html( null === $row['profile_completeness_score'] ? '-' : (string) $row['profile_completeness_score'] ); ?></td>
							<td><?php echo esc_html( (string) $row['record_gap_type'] ); ?></td>
							<td><?php echo esc_html( (string) $row['record_gap_summary'] ); ?></td>
							<td><?php echo esc_html( (string) $row['last_fight_gap'] ); ?></td>
							<td><?php echo esc_html( (string) $row['fight_history_coverage_summary'] ); ?></td>
							<td><?php echo esc_html( sprintf( '%d/%d/%d', (int) $row['rows_with_date'], (int) $row['rows_with_result'], (int) $row['rows_with_method'] ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%d/%d/%d', (int) $row['rows_with_event_url'], (int) $row['rows_with_bout_url'], (int) $row['rows_with_opponent_url'] ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['rows_with_opponent_record'] ); ?></td>
							<td><?php echo esc_html( sprintf( '%d useful / %d missing core', (int) $row['rows_staging_candidate_count'], (int) $row['rows_missing_core_fields'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['suggested_action'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_record_comparison_pagination( int $total, int $paged, int $per_page, string $search, array $filters, string $path ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args = array(
			'page'         => self::PAGE_SLUG,
			'tab'          => 'record_comparison',
			'per_page'     => $per_page,
			'profile_path' => $path,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( ! empty( $filters ) ) {
			$args['record_filter'] = $filters;
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s profile', '%s profiles', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';

		if ( $total_pages > 1 ) {
			$base = add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
			echo '<div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => __( '&laquo;', 'mma-future-data-engine' ),
						'next_text' => __( '&raquo;', 'mma-future-data-engine' ),
					)
				)
			) . '</div>';
		}

		echo '<br class="clear"></div>';
	}

	private static function record_comparison_filter_options(): array {
		return array(
			'matched' => __( 'Matched', 'mma-future-data-engine' ),
			'unmatched' => __( 'Unmatched', 'mma-future-data-engine' ),
			'ambiguous' => __( 'Ambiguous', 'mma-future-data-engine' ),
			'has_profile_pro_record' => __( 'Has profile pro record', 'mma-future-data-engine' ),
			'has_profile_fight_history' => __( 'Has profile fight history', 'mma-future-data-engine' ),
			'canonical_zero_profile_has_record' => __( 'Canonical zero, profile has record', 'mma-future-data-engine' ),
			'canonical_lower_than_profile' => __( 'Canonical lower than profile', 'mma-future-data-engine' ),
			'possible_complete_canonical' => __( 'Possible complete canonical', 'mma-future-data-engine' ),
			'staging_candidates_available' => __( 'Staging candidates available', 'mma-future-data-engine' ),
			'missing_useful_history' => __( 'Missing useful history', 'mma-future-data-engine' ),
			'needs_identity_review' => __( 'Needs identity review', 'mma-future-data-engine' ),
		);
	}

	private static function render_fighter_enrichment_audit(): void {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filters = isset( $_GET['readiness_filter'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['readiness_filter'] ) ) : array();
		$filters = array_values( array_intersect( $filters, FighterEnrichmentAuditService::FILTERS ) );
		$per_page = self::current_per_page();
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset = ( $paged - 1 ) * $per_page;
		$service = new FighterEnrichmentAuditService();
		$report = $service->build_report( $filters, $search, $per_page, $offset );
		$total = (int) $report['total'];
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $paged > $total_pages ) {
			$paged = $total_pages;
			$offset = ( $paged - 1 ) * $per_page;
			$report = $service->build_report( $filters, $search, $per_page, $offset );
		}

		?>
		<h2><?php echo esc_html__( 'Fighter Profile Enrichment Audit', 'mma-future-data-engine' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Read-only workflow report for manual fighter profile review. Review Priority is an admin sorting score, not a ranking score.', 'mma-future-data-engine' ); ?></p>

		<h3><?php echo esc_html__( 'Summary', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_key_value_table( $report['summary'] ); ?>

		<h3><?php echo esc_html__( 'Fighter Readiness', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_fighter_enrichment_filters( $search, $filters, $per_page ); ?>
		<?php self::render_fighter_enrichment_pagination( $total, $paged, $per_page, $search, $filters ); ?>
		<?php self::render_fighter_enrichment_table( $report['rows'] ); ?>
		<?php self::render_fighter_enrichment_pagination( $total, $paged, $per_page, $search, $filters ); ?>
		<?php
	}

	private static function render_fighter_enrichment_filters( string $search, array $selected_filters, int $per_page ): void {
		$options = self::fighter_enrichment_filter_options();
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="fighter_enrichment">
			<label for="mmaf-fighter-readiness-search"><?php echo esc_html__( 'Search', 'mma-future-data-engine' ); ?></label>
			<input id="mmaf-fighter-readiness-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Fighter name', 'mma-future-data-engine' ); ?>">
			<?php self::render_per_page_select( $per_page ); ?>
			<fieldset style="margin: 12px 0 8px;">
				<legend class="screen-reader-text"><?php echo esc_html__( 'Readiness filters', 'mma-future-data-engine' ); ?></legend>
				<?php foreach ( $options as $key => $label ) : ?>
					<label style="display:inline-block; margin: 0 14px 8px 0;">
						<input type="checkbox" name="readiness_filter[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_filters, true ) ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button( __( 'Apply Filters', 'mma-future-data-engine' ), 'secondary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( self::page_url( array( 'tab' => 'fighter_enrichment' ) ) ); ?>"><?php echo esc_html__( 'Reset', 'mma-future-data-engine' ); ?></a>
		</form>
		<?php
	}

	private static function render_fighter_enrichment_table( array $rows ): void {
		?>
		<div style="overflow-x:auto;">
			<table class="widefat striped" style="min-width: 1900px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Review Priority', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Fighter ID', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Display name', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Rankability status', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Public', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Rankable', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Source type / source fighter ID', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Tapology source URL', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'WP post ID', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'DOB / birth year', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Gender', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical weight class', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Nationality', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Record', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Countable fights', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Finish wins / rate', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Last fight date', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Latest bout weight suggestion', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Source mappings', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Warnings / reasons', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Suggested next action', 'mma-future-data-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="22"><?php echo esc_html__( 'No fighters matched the selected readiness filters.', 'mma-future-data-engine' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) (int) $row['review_priority'] ); ?></strong></td>
							<td><?php echo esc_html( (string) (int) $row['id'] ); ?></td>
							<td><strong><?php echo esc_html( (string) $row['display_name'] ); ?></strong></td>
							<td><?php echo esc_html( (string) $row['status'] ); ?></td>
							<td><?php echo esc_html( (string) $row['rankability_status'] ); ?></td>
							<td><?php echo esc_html( self::yes_no( $row['is_public'] ) ); ?></td>
							<td><?php echo esc_html( self::yes_no( $row['is_rankable'] ) ); ?></td>
							<td><?php echo esc_html( self::source_identity_summary( $row ) ); ?></td>
							<td><?php self::render_source_url( (string) ( $row['tapology_source_url'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( ! empty( $row['wp_post_id'] ) ? (string) (int) $row['wp_post_id'] : '-' ); ?></td>
							<td><?php echo esc_html( self::dob_birth_year_summary( $row ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['gender'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['weight_class'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['nationality'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%d-%d-%d-%d', (int) $row['wins'], (int) $row['losses'], (int) $row['draws'], (int) $row['nc'] ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['countable_fights'] ); ?></td>
							<td><?php echo esc_html( self::finish_summary( $row ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['last_fight_date'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::weight_suggestion_summary( $row ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['source_mapping_count'] ); ?></td>
							<td><?php echo esc_html( self::shorten( (string) $row['warnings_reasons'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['suggested_next_action'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_fighter_enrichment_pagination( int $total, int $paged, int $per_page, string $search, array $filters ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args = array(
			'page'     => self::PAGE_SLUG,
			'tab'      => 'fighter_enrichment',
			'per_page' => $per_page,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( ! empty( $filters ) ) {
			$args['readiness_filter'] = $filters;
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s fighter', '%s fighters', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';

		if ( $total_pages > 1 ) {
			$base = add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
			echo '<div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => __( '&laquo;', 'mma-future-data-engine' ),
						'next_text' => __( '&raquo;', 'mma-future-data-engine' ),
					)
				)
			) . '</div>';
		}

		echo '<br class="clear"></div>';
	}

	private static function fighter_enrichment_filter_options(): array {
		return array(
			'missing_dob_birth_year'   => __( 'Missing DOB/birth year', 'mma-future-data-engine' ),
			'missing_gender'           => __( 'Missing gender', 'mma-future-data-engine' ),
			'missing_weight_class'     => __( 'Missing weight class', 'mma-future-data-engine' ),
			'missing_nationality'      => __( 'Missing nationality', 'mma-future-data-engine' ),
			'has_tapology_source'      => __( 'Has Tapology source', 'mma-future-data-engine' ),
			'no_tapology_source'       => __( 'No Tapology source', 'mma-future-data-engine' ),
			'has_fights'               => __( 'Has fights', 'mma-future-data-engine' ),
			'no_fights'                => __( 'No fights', 'mma-future-data-engine' ),
			'public_not_rankable'      => __( 'Public but not rankable', 'mma-future-data-engine' ),
			'scraped_provisional'      => __( 'Scraped provisional', 'mma-future-data-engine' ),
			'legacy_public'            => __( 'Legacy/public', 'mma-future-data-engine' ),
			'possible_ranking_candidate'=> __( 'Possible ranking candidate', 'mma-future-data-engine' ),
			'duplicate_conflict_signal'=> __( 'Duplicate/conflict signal', 'mma-future-data-engine' ),
		);
	}

	private static function render_fight_history_staging_audit(): void {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filters = isset( $_GET['staging_filter'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['staging_filter'] ) ) : array();
		$filters = array_values( array_intersect( $filters, FightHistoryStagingReportService::FILTERS ) );
		$per_page = self::current_per_page();
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset = ( $paged - 1 ) * $per_page;
		$service = new FightHistoryStagingReportService();

		try {
			$report = $service->build_report( '', $filters, $search, $per_page, $offset );
			$total = (int) $report['total'];
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
			if ( $paged > $total_pages ) {
				$paged = $total_pages;
				$offset = ( $paged - 1 ) * $per_page;
				$report = $service->build_report( '', $filters, $search, $per_page, $offset );
			}
			$error = '';
		} catch ( \Throwable $exception ) {
			$report = array(
				'summary' => array(
					'input_file' => FightHistoryStagingReportService::default_path(),
					'status' => 'error',
					'message' => $exception->getMessage(),
				),
				'rows' => array(),
				'total' => 0,
				'path' => FightHistoryStagingReportService::default_path(),
			);
			$total = 0;
			$error = $exception->getMessage();
		}

		?>
		<h2><?php echo esc_html__( 'Fight History Staging', 'mma-future-data-engine' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Read-only review of Tapology profile fight-history staging candidates from the scraper dry-run report.', 'mma-future-data-engine' ); ?></p>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'Fight history staging is review/audit only. Nothing here is canonical yet.', 'mma-future-data-engine' ); ?></strong></p></div>

		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<h3><?php echo esc_html__( 'Summary', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_key_value_table( (array) $report['summary'] ); ?>

		<h3><?php echo esc_html__( 'Per-Row Review', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_fight_history_staging_filters( $search, $filters, $per_page ); ?>
		<?php self::render_fight_history_staging_pagination( $total, $paged, $per_page, $search, $filters ); ?>
		<?php self::render_fight_history_staging_table( (array) $report['rows'] ); ?>
		<?php self::render_fight_history_staging_pagination( $total, $paged, $per_page, $search, $filters ); ?>
		<?php
	}

	private static function render_fight_history_staging_filters( string $search, array $selected_filters, int $per_page ): void {
		$options = self::fight_history_staging_filter_options();
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="fight_history_staging">
			<label for="mmaf-fight-history-staging-search"><?php echo esc_html__( 'Search', 'mma-future-data-engine' ); ?></label>
			<input id="mmaf-fight-history-staging-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Fighter, opponent, or event name', 'mma-future-data-engine' ); ?>" style="min-width: 320px;">
			<?php self::render_per_page_select( $per_page ); ?>
			<fieldset style="margin: 12px 0 8px;">
				<legend class="screen-reader-text"><?php echo esc_html__( 'Fight history staging filters', 'mma-future-data-engine' ); ?></legend>
				<?php foreach ( $options as $key => $label ) : ?>
					<label style="display:inline-block; margin: 0 14px 8px 0;">
						<input type="checkbox" name="staging_filter[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_filters, true ) ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button( __( 'Apply Filters', 'mma-future-data-engine' ), 'secondary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( self::page_url( array( 'tab' => 'fight_history_staging' ) ) ); ?>"><?php echo esc_html__( 'Reset', 'mma-future-data-engine' ); ?></a>
		</form>
		<?php
	}

	private static function render_fight_history_staging_table( array $rows ): void {
		?>
		<div style="overflow-x:auto;">
			<table class="widefat striped" style="min-width: 3000px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Fighter / profile name', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Matched canonical fighter', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Date', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Opponent name', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Result', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Method', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Round / time', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Event name', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Event URL', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Bout URL', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Opponent URL', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Fighter prefight record', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Opponent prefight record', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Sport / type', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Confidence', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical match status', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Recommended action', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="18"><?php echo esc_html__( 'No fight-history staging rows matched the selected filters.', 'mma-future-data-engine' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( self::empty_marker( (string) $row['fighter_profile_name'] ) ); ?></strong></td>
							<td><?php echo esc_html( self::canonical_fighter_summary( $row ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['fight_date'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['opponent_name'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['result'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['method'] ) ); ?></td>
							<td><?php echo esc_html( self::round_time_summary( $row ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['event_name'] ) ); ?></td>
							<td><?php self::render_source_url( (string) $row['event_url'] ); ?></td>
							<td><?php self::render_source_url( (string) $row['bout_url'] ); ?></td>
							<td><?php self::render_source_url( (string) $row['opponent_url'] ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['fighter_prefight_record'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['opponent_prefight_record'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['sport_type'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['staging_confidence'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['canonical_match_status'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['recommended_action'] ) ); ?></td>
							<td title="<?php echo esc_attr( (string) $row['canonical_match_evidence'] ); ?>"><?php echo esc_html( self::shorten( (string) $row['warnings'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_fight_history_staging_pagination( int $total, int $paged, int $per_page, string $search, array $filters ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args = array(
			'page'     => self::PAGE_SLUG,
			'tab'      => 'fight_history_staging',
			'per_page' => $per_page,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( ! empty( $filters ) ) {
			$args['staging_filter'] = $filters;
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s staged row', '%s staged rows', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';

		if ( $total_pages > 1 ) {
			$base = add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
			echo '<div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => __( '&laquo;', 'mma-future-data-engine' ),
						'next_text' => __( '&raquo;', 'mma-future-data-engine' ),
					)
				)
			) . '</div>';
		}

		echo '<br class="clear"></div>';
	}

	private static function fight_history_staging_filter_options(): array {
		return array(
			'high_confidence' => __( 'High confidence', 'mma-future-data-engine' ),
			'medium_confidence' => __( 'Medium confidence', 'mma-future-data-engine' ),
			'low_confidence' => __( 'Low confidence', 'mma-future-data-engine' ),
			'already_canonical' => __( 'Already canonical', 'mma-future-data-engine' ),
			'candidate_new_historical_bout' => __( 'Candidate new historical bout', 'mma-future-data-engine' ),
			'possible_weak_duplicate' => __( 'Possible weak duplicate', 'mma-future-data-engine' ),
			'missing_opponent_prefight_record' => __( 'Missing opponent prefight record', 'mma-future-data-engine' ),
			'non_mma_filtered' => __( 'Non-MMA filtered', 'mma-future-data-engine' ),
			'cancelled_amateur_overturned' => __( 'Cancelled/amateur/overturned', 'mma-future-data-engine' ),
			'needs_bout_detail_fetch' => __( 'Needs bout detail fetch', 'mma-future-data-engine' ),
			'stage_for_review' => __( 'Stage for review', 'mma-future-data-engine' ),
		);
	}

	private static function render_fight_history_completeness_audit(): void {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filters = isset( $_GET['history_filter'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['history_filter'] ) ) : array();
		$filters = array_values( array_intersect( $filters, FightHistoryCompletenessAuditService::filters() ) );
		$per_page = self::current_per_page();
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset = ( $paged - 1 ) * $per_page;
		$service = new FightHistoryCompletenessAuditService();
		$report = $service->build_report( $filters, $search, $per_page, $offset );
		$total = (int) $report['total'];
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $paged > $total_pages ) {
			$paged = $total_pages;
			$offset = ( $paged - 1 ) * $per_page;
			$report = $service->build_report( $filters, $search, $per_page, $offset );
		}

		?>
		<h2><?php echo esc_html__( 'Fight History Completeness Audit', 'mma-future-data-engine' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Read-only workflow report for deciding whether canonical fight logs are complete enough for public ranking readiness. Record Completeness Priority is an admin sorting score, not a ranking score.', 'mma-future-data-engine' ); ?></p>

		<h3><?php echo esc_html__( 'Important Notes', 'mma-future-data-engine' ); ?></h3>
		<ul style="list-style: disc; padding-left: 22px; max-width: 1040px;">
			<li><?php echo esc_html__( 'Current canonical stats are derived only from canonical bout rows.', 'mma-future-data-engine' ); ?></li>
			<li><?php echo esc_html__( 'If most fighters have only one canonical bout, stats W-L-D may not equal real career W-L-D.', 'mma-future-data-engine' ); ?></li>
			<li><?php echo esc_html__( 'Prefight record from the latest bout may allow deriving a current aggregate record, but it does not replace full fight history for finish breakdown, streak, and complete transparency.', 'mma-future-data-engine' ); ?></li>
			<li><?php echo esc_html__( 'Do not approve fighters for public ranking until the record completeness strategy is decided.', 'mma-future-data-engine' ); ?></li>
		</ul>

		<h3><?php echo esc_html__( 'Summary', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_key_value_table( $report['summary'] ); ?>

		<h3><?php echo esc_html__( 'Strategy Recommendation', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_key_value_table( $report['recommendation'] ); ?>

		<h3><?php echo esc_html__( 'Per-Fighter Completeness', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_fight_history_filters( $search, $filters, $per_page ); ?>
		<?php self::render_fight_history_pagination( $total, $paged, $per_page, $search, $filters ); ?>
		<?php self::render_fight_history_table( $report['rows'] ); ?>
		<?php self::render_fight_history_pagination( $total, $paged, $per_page, $search, $filters ); ?>
		<?php
	}

	private static function render_fight_history_filters( string $search, array $selected_filters, int $per_page ): void {
		$options = self::fight_history_filter_options();
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="fight_history_completeness">
			<label for="mmaf-fight-history-search"><?php echo esc_html__( 'Search', 'mma-future-data-engine' ); ?></label>
			<input id="mmaf-fight-history-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Fighter name or source URL', 'mma-future-data-engine' ); ?>">
			<?php self::render_per_page_select( $per_page ); ?>
			<fieldset style="margin: 12px 0 8px;">
				<legend class="screen-reader-text"><?php echo esc_html__( 'Fight history filters', 'mma-future-data-engine' ); ?></legend>
				<?php foreach ( $options as $key => $label ) : ?>
					<label style="display:inline-block; margin: 0 14px 8px 0;">
						<input type="checkbox" name="history_filter[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_filters, true ) ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button( __( 'Apply Filters', 'mma-future-data-engine' ), 'secondary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( self::page_url( array( 'tab' => 'fight_history_completeness' ) ) ); ?>"><?php echo esc_html__( 'Reset', 'mma-future-data-engine' ); ?></a>
		</form>
		<?php
	}

	private static function render_fight_history_table( array $rows ): void {
		?>
		<div style="overflow-x:auto;">
			<table class="widefat striped" style="min-width: 2400px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Record Completeness Priority', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Fighter ID', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Display name', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Rankability status', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Public', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Rankable', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Source mappings', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Tapology source URL', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical countable fights', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Latest fight date', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Latest result', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Latest opponent', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Latest prefight record', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Derived current W-L-D-NC', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical stats W-L-D-NC', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Derived vs stats difference', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Finish wins from canonical log', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Missing profile flags', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Record completeness status', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Suggested next action', 'mma-future-data-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="21"><?php echo esc_html__( 'No fighters matched the selected fight history filters.', 'mma-future-data-engine' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) (int) $row['record_completeness_priority'] ); ?></strong></td>
							<td><?php echo esc_html( (string) (int) $row['id'] ); ?></td>
							<td><strong><?php echo esc_html( (string) $row['display_name'] ); ?></strong></td>
							<td><?php echo esc_html( (string) $row['status'] ); ?></td>
							<td><?php echo esc_html( (string) $row['rankability_status'] ); ?></td>
							<td><?php echo esc_html( self::yes_no( $row['is_public'] ) ); ?></td>
							<td><?php echo esc_html( self::yes_no( $row['is_rankable'] ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['source_mapping_count'] ); ?></td>
							<td><?php self::render_source_url( (string) ( $row['tapology_source_url'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['countable_fights'] ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['latest_fight_date'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['latest_bout_result'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['latest_bout_opponent'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::prefight_record_summary( $row ) ); ?></td>
							<td><?php echo esc_html( (string) $row['derived_record'] ); ?></td>
							<td><?php echo esc_html( (string) $row['stats_record'] ); ?></td>
							<td><?php echo esc_html( (string) $row['record_difference'] ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['canonical_finish_wins'] ); ?></td>
							<td><?php echo esc_html( (string) $row['missing_profile_flags'] ); ?></td>
							<td><?php echo esc_html( (string) $row['record_completeness_status'] ); ?></td>
							<td><?php echo esc_html( (string) $row['suggested_next_action'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_fight_history_pagination( int $total, int $paged, int $per_page, string $search, array $filters ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args = array(
			'page'     => self::PAGE_SLUG,
			'tab'      => 'fight_history_completeness',
			'per_page' => $per_page,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( ! empty( $filters ) ) {
			$args['history_filter'] = $filters;
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s fighter', '%s fighters', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';

		if ( $total_pages > 1 ) {
			$base = add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
			echo '<div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => __( '&laquo;', 'mma-future-data-engine' ),
						'next_text' => __( '&raquo;', 'mma-future-data-engine' ),
					)
				)
			) . '</div>';
		}

		echo '<br class="clear"></div>';
	}

	private static function fight_history_filter_options(): array {
		return array(
			'no_fights'                 => __( 'No fights', 'mma-future-data-engine' ),
			'single_known_fight'        => __( 'Single known fight', 'mma-future-data-engine' ),
			'multiple_canonical_fights' => __( 'Multiple canonical fights', 'mma-future-data-engine' ),
			'current_record_derivable'  => __( 'Current record derivable', 'mma-future-data-engine' ),
			'full_history_needed'       => __( 'Full history needed', 'mma-future-data-engine' ),
			'missing_prefight_data'     => __( 'Missing prefight data', 'mma-future-data-engine' ),
			'has_tapology_source'       => __( 'Has Tapology source', 'mma-future-data-engine' ),
			'missing_profile_fields'    => __( 'Missing DOB/gender/weight', 'mma-future-data-engine' ),
			'record_difference'         => __( 'Derived/stats difference', 'mma-future-data-engine' ),
		);
	}

	private static function prefight_record_summary( array $row ): string {
		$raw = (string) ( $row['prefight_record_raw'] ?? '' );
		if ( '' !== $raw ) {
			return $raw;
		}

		foreach ( array( 'prefight_wins', 'prefight_losses', 'prefight_draws', 'prefight_nc' ) as $field ) {
			if ( null === $row[ $field ] || '' === (string) $row[ $field ] ) {
				return '-';
			}
		}

		return sprintf( '%d-%d-%d-%d', (int) $row['prefight_wins'], (int) $row['prefight_losses'], (int) $row['prefight_draws'], (int) $row['prefight_nc'] );
	}

	private static function canonical_fighter_summary( array $row ): string {
		$fighter_id = (int) ( $row['matched_canonical_fighter_id'] ?? 0 );
		if ( $fighter_id <= 0 ) {
			return '-';
		}

		return '#' . $fighter_id . ' ' . (string) ( $row['matched_canonical_name'] ?? '' );
	}

	private static function record_summary_from_parts( array $row, string $prefix ): string {
		$fields = array(
			$prefix . '_wins',
			$prefix . '_losses',
			$prefix . '_draws',
			$prefix . '_no_contests',
		);

		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $row ) || null === $row[ $field ] || '' === (string) $row[ $field ] ) {
				return '-';
			}
		}

		return sprintf(
			'%d-%d-%d-%d',
			(int) $row[ $fields[0] ],
			(int) $row[ $fields[1] ],
			(int) $row[ $fields[2] ],
			(int) $row[ $fields[3] ]
		);
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	private static function current_per_page(): int {
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;

		return in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 50;
	}

	private static function render_per_page_select( int $per_page ): void {
		?>
		<label for="mmaf-per-page" style="margin-left: 8px;"><?php echo esc_html__( 'Per page', 'mma-future-data-engine' ); ?></label>
		<select id="mmaf-per-page" name="per_page">
			<?php foreach ( array( 25, 50, 100 ) as $option ) : ?>
				<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( (string) $option ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function yes_no( $value ): string {
		return (int) $value ? __( 'Yes', 'mma-future-data-engine' ) : __( 'No', 'mma-future-data-engine' );
	}

	private static function source_identity_summary( array $row ): string {
		$source_types = (string) ( $row['source_types'] ?? '' );
		$tapology_id = (string) ( $row['tapology_source_fighter_id'] ?? '' );

		if ( '' === $source_types && '' === $tapology_id ) {
			return '-';
		}

		return trim( $source_types . ( '' !== $tapology_id ? ' / tapology:' . $tapology_id : '' ), ' /' );
	}

	private static function render_source_url( string $url ): void {
		if ( '' === $url ) {
			echo esc_html( '-' );
			return;
		}

		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open source', 'mma-future-data-engine' ) . '</a>';
	}

	private static function dob_birth_year_summary( array $row ): string {
		$dob = (string) ( $row['date_of_birth'] ?? '' );
		$birth_year = (string) ( $row['birth_year'] ?? '' );
		$value = trim( $dob . ( '' !== $dob && '' !== $birth_year ? ' / ' : '' ) . $birth_year );

		return '' === $value ? '-' : $value;
	}

	private static function finish_summary( array $row ): string {
		$finish_rate = null === $row['finish_rate'] || '' === (string) $row['finish_rate'] ? '-' : rtrim( rtrim( number_format_i18n( (float) $row['finish_rate'], 3 ), '0' ), '.' );

		return (string) (int) $row['finish_wins'] . ' / ' . $finish_rate;
	}

	private static function weight_suggestion_summary( array $row ): string {
		$suggestion = (string) ( $row['latest_bout_weight_suggestion'] ?? '' );
		if ( '' === $suggestion ) {
			return '-';
		}

		$distribution = (string) ( $row['bout_weight_distribution'] ?? '' );

		return '' === $distribution ? $suggestion : $suggestion . ' [' . $distribution . ']';
	}

	private static function round_time_summary( array $row ): string {
		$round = trim( (string) ( $row['round'] ?? '' ) );
		$time = trim( (string) ( $row['time'] ?? '' ) );
		$parts = array();

		if ( '' !== $round ) {
			$parts[] = 'R' . $round;
		}
		if ( '' !== $time ) {
			$parts[] = $time;
		}

		return empty( $parts ) ? '-' : implode( ' / ', $parts );
	}

	private static function empty_marker( string $value ): string {
		return '' === $value ? '-' : $value;
	}

	private static function render_key_value_table( array $data ): void {
		?>
		<table class="widefat striped" style="max-width: 1040px;">
			<tbody>
				<?php foreach ( $data as $key => $value ) : ?>
					<?php if ( is_array( $value ) && self::is_table_rows( $value ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<tr>
						<th scope="row" style="width: 300px;"><?php echo esc_html( self::label( (string) $key ) ); ?></th>
						<td><?php echo esc_html( self::format_value( $value ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_identity_rows_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No rows found.', 'mma-future-data-engine' ) . '</p>';
			return;
		}

		$columns = array_keys( (array) $rows[0] );
		?>
		<div style="overflow-x:auto;">
			<table class="widefat striped" style="min-width: 1400px;">
				<thead>
					<tr>
						<?php foreach ( $columns as $column ) : ?>
							<th><?php echo esc_html( self::label( (string) $column ) ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<?php foreach ( $columns as $column ) : ?>
								<td><?php echo esc_html( self::format_value( $row[ $column ] ?? '' ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_duplicate_candidates_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No duplicate review candidates found.', 'mma-future-data-engine' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Scraped fighter ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Scraped display name', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Scraped source fighter ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Possible existing fighter ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Existing display name', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Reason', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Confidence', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['scraped_fighter_id'] ); ?></td>
						<td><?php echo esc_html( (string) $row['scraped_display_name'] ); ?></td>
						<td><?php echo esc_html( (string) $row['scraped_source_fighter_id'] ); ?></td>
						<td><?php echo esc_html( (string) $row['existing_fighter_id'] ); ?></td>
						<td><?php echo esc_html( (string) $row['existing_display_name'] ); ?></td>
						<td><?php echo esc_html( (string) $row['reason'] ); ?></td>
						<td><?php echo esc_html( (string) $row['confidence'] ); ?></td>
						<td><?php echo esc_html( (string) $row['action'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_import_items_table( array $items ): void {
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No conflict, needs-review, or failed import items found.', 'mma-future-data-engine' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Run ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Type', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Source ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Canonical ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Error', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Created', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<tr<?php echo 'tapology_bout_1116772' === (string) $item['source_id'] ? ' style="font-weight:600;"' : ''; ?>>
						<td><?php echo esc_html( (string) $item['import_run_id'] ); ?></td>
						<td><?php echo esc_html( (string) $item['item_type'] ); ?></td>
						<td><?php echo esc_html( (string) $item['source_id'] ); ?></td>
						<td><?php echo esc_html( (string) $item['status'] ); ?></td>
						<td><?php echo esc_html( (string) $item['action'] ); ?></td>
						<td><?php echo esc_html( (string) $item['canonical_id'] ); ?></td>
						<td><?php echo esc_html( self::shorten( (string) $item['warnings_json'] ) ); ?></td>
						<td><?php echo esc_html( self::shorten( (string) $item['error_message'] ) ); ?></td>
						<td><?php echo esc_html( (string) $item['created_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function format_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( null === $value ) {
			return 'none';
		}

		if ( is_array( $value ) ) {
			return self::shorten( wp_json_encode( $value ) );
		}

		return (string) $value;
	}

	private static function label( string $key ): string {
		return ucwords( str_replace( '_', ' ', $key ) );
	}

	private static function shorten( string $value ): string {
		if ( strlen( $value ) <= 220 ) {
			return $value;
		}

		return substr( $value, 0, 217 ) . '...';
	}

	private static function is_table_rows( array $value ): bool {
		$first = reset( $value );

		return is_array( $first );
	}
}
