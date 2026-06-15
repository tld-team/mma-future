<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentPreviewService;
use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentApplyService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterProfileEnrichmentPreviewPage {
	private const PAGE_SLUG = 'mmaf-import';
	private const TAB = 'fighter_profile_enrichment';
	private const NONCE_ACTION = 'mmaf_fighter_profile_enrichment_preview';
	private const APPLY_NONCE_ACTION = 'mmaf_fighter_profile_enrichment_apply';

	public static function render(): void {
		$path = isset( $_GET['enrichment_path'] ) ? sanitize_text_field( wp_unslash( $_GET['enrichment_path'] ) ) : FighterProfileEnrichmentPreviewService::default_path();
		$profile_action = isset( $_GET['profile_action'] ) ? sanitize_key( wp_unslash( $_GET['profile_action'] ) ) : '';
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' === $request_method && isset( $_POST['mmaf_profile_enrichment_apply_submit'] ) ) {
			self::handle_apply_post();
			return;
		}

		if ( 'review_apply' === $profile_action ) {
			self::render_apply_detail( $path );
			return;
		}

		$has_preview_request = isset( $_GET['mmaf_profile_enrichment_preview_submit'] ) || isset( $_GET['profile_filter'] ) || isset( $_GET['paged'] ) || isset( $_GET['s'] );
		$error = null;
		$report = null;
		$total = 0;
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = self::current_per_page();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filters = isset( $_GET['profile_filter'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['profile_filter'] ) ) : array();
		$filters = array_values( array_intersect( $filters, FighterProfileEnrichmentPreviewService::filters() ) );

		if ( $has_preview_request ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
				$error = __( 'Preview request failed security validation. Reload the page and run the preview again.', 'mma-future-data-engine' );
			} else {
				try {
					$offset = ( $paged - 1 ) * $per_page;
					$service = new FighterProfileEnrichmentPreviewService();
					$report = $service->analyze_file( $path, $filters, $search, $per_page, $offset );
					$total = (int) $report['total'];
					$total_pages = max( 1, (int) ceil( $total / $per_page ) );
					if ( $paged > $total_pages ) {
						$paged = $total_pages;
						$offset = ( $paged - 1 ) * $per_page;
						$report = $service->analyze_file( $path, $filters, $search, $per_page, $offset );
						$total = (int) $report['total'];
					}
				} catch ( \Throwable $e ) {
					$error = $e->getMessage();
				}
			}
		}

		?>
		<h2><?php echo esc_html__( 'Fighter Profile Enrichment Preview', 'mma-future-data-engine' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Read-only dry-run review for scraper-side Tapology fighter profile enrichment JSON. This preview does not write canonical data, import media, rebuild stats, or change rankings.', 'mma-future-data-engine' ); ?></p>

		<?php if ( $error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<?php self::render_form( $path, $search, $filters, $per_page ); ?>

		<?php if ( $report ) : ?>
			<div class="notice notice-success inline"><p><?php echo esc_html__( 'Dry-run preview completed. No canonical data was modified.', 'mma-future-data-engine' ); ?></p></div>
			<h3><?php echo esc_html__( 'Summary Counts', 'mma-future-data-engine' ); ?></h3>
			<?php self::render_summary_table( (array) $report['summary'] ); ?>

			<h3><?php echo esc_html__( 'Final Assessment', 'mma-future-data-engine' ); ?></h3>
			<table class="widefat striped" style="max-width: 1040px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Matching quality good enough for later safe enrichment import phase?', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $report['summary']['matching_quality_good_enough_for_later_import'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Why', 'mma-future-data-engine' ); ?></th>
						<td><?php echo esc_html( (string) $report['summary']['matching_quality_reason'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Detailed Preview', 'mma-future-data-engine' ); ?></h3>
			<?php self::render_pagination( $total, $paged, $per_page, $path, $search, $filters ); ?>
			<?php self::render_preview_table( (array) $report['rows'], $path ); ?>
			<?php self::render_pagination( $total, $paged, $per_page, $path, $search, $filters ); ?>
		<?php endif; ?>
		<?php
	}

	private static function handle_apply_post(): void {
		$path = isset( $_POST['enrichment_path'] ) ? sanitize_text_field( wp_unslash( $_POST['enrichment_path'] ) ) : '';
		$source_fighter_id = isset( $_POST['source_fighter_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_fighter_id'] ) ) : '';
		$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
		$notice = array(
			'type' => 'error',
			'message' => __( 'Could not apply fighter profile enrichment suggestions.', 'mma-future-data-engine' ),
			'applied' => array(),
			'skipped' => array(),
		);

		try {
			if ( ! isset( $_POST['mmaf_profile_enrichment_apply_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmaf_profile_enrichment_apply_nonce'] ) ), self::APPLY_NONCE_ACTION ) ) {
				throw new \RuntimeException( 'Invalid nonce.' );
			}

			if ( empty( $_POST['mmaf_profile_enrichment_confirm_reviewed'] ) ) {
				throw new \RuntimeException( 'Confirm that you reviewed these source suggestions before applying selected fields.' );
			}

			$selected = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : array();
			$result = ( new FighterProfileEnrichmentApplyService() )->apply( $path, $source_fighter_id, $source_url, $selected, get_current_user_id() );
			$applied = array_map( 'strval', (array) ( $result['applied'] ?? array() ) );
			$skipped = (array) ( $result['skipped'] ?? array() );
			$notice = array(
				'type' => empty( $applied ) ? 'warning' : ( empty( $skipped ) ? 'success' : 'warning' ),
				'message' => empty( $applied )
					? __( 'No fields were applied. Selected fields were no longer safe.', 'mma-future-data-engine' )
					: __( 'Selected fighter profile enrichment suggestions were applied.', 'mma-future-data-engine' ),
				'applied' => $applied,
				'skipped' => $skipped,
			);
		} catch ( \Throwable $error ) {
			$notice['message'] = $error->getMessage();
		}

		$token = self::store_apply_notice( $notice );
		wp_safe_redirect(
			self::apply_detail_url(
				array(
					'enrichment_path' => $path,
					'source_fighter_id' => $source_fighter_id,
					'source_url' => $source_url,
					'apply_notice' => $token,
				)
			)
		);
		exit;
	}

	private static function render_apply_detail( string $path ): void {
		$source_fighter_id = isset( $_GET['source_fighter_id'] ) ? sanitize_text_field( wp_unslash( $_GET['source_fighter_id'] ) ) : '';
		$source_url = isset( $_GET['source_url'] ) ? esc_url_raw( wp_unslash( $_GET['source_url'] ) ) : '';
		$error = null;
		$detail = null;
		$notice = self::consume_apply_notice();

		try {
			$detail = ( new FighterProfileEnrichmentApplyService() )->load_detail( $path, $source_fighter_id, $source_url );
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}

		?>
		<h2><?php echo esc_html__( 'Review / Apply Fighter Profile Suggestions', 'mma-future-data-engine' ); ?></h2>
		<p><a href="<?php echo esc_url( self::page_url( array( 'tab' => self::TAB, 'enrichment_path' => $path, 'mmaf_profile_enrichment_preview_submit' => 1, '_wpnonce' => wp_create_nonce( self::NONCE_ACTION ) ) ) ); ?>">&larr; <?php echo esc_html__( 'Back to Fighter Profile Enrichment Preview', 'mma-future-data-engine' ); ?></a></p>
		<p class="description"><?php echo esc_html__( 'This screen can only fill missing safe canonical fields for one matched fighter. It does not import images, fight history, records, ranking flags, source mappings, stats, or rankings.', 'mma-future-data-engine' ); ?></p>

		<?php self::render_apply_notice( $notice ); ?>

		<?php if ( $error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( ! $detail ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html__( 'Profile detail could not be loaded.', 'mma-future-data-engine' ); ?></p></div>
			<?php return; ?>
		<?php endif; ?>

		<?php self::render_profile_source_table( $detail ); ?>
		<?php self::render_apply_form( $detail ); ?>
		<?php self::render_read_only_comparison( $detail ); ?>
		<?php
	}

	private static function render_profile_source_table( array $detail ): void {
		$rows = array(
			'source_fighter_id' => (string) ( $detail['source_fighter_id'] ?? '' ),
			'source_url' => (string) ( $detail['source_url'] ?? '' ),
			'profile_display_name' => (string) ( $detail['profile_display_name'] ?? '' ),
			'profile_completeness_score' => (string) ( $detail['completeness_score'] ?? '' ),
			'matched_canonical_fighter_id' => (string) ( $detail['matched_canonical_fighter_id'] ?? '' ),
			'matched_canonical_name' => (string) ( $detail['matched_canonical_name'] ?? '' ),
			'match_type' => (string) ( $detail['match_type'] ?? '' ),
			'enrichment_file' => (string) ( $detail['enrichment_file'] ?? '' ),
		);
		?>
		<h3><?php echo esc_html__( 'Profile / Source Match', 'mma-future-data-engine' ); ?></h3>
		<table class="widefat striped" style="max-width: 1040px;">
			<tbody>
				<?php foreach ( $rows as $key => $value ) : ?>
					<tr>
						<th scope="row" style="width: 280px;"><code><?php echo esc_html( $key ); ?></code></th>
						<td>
							<?php if ( 'source_url' === $key && '' !== $value ) : ?>
								<a href="<?php echo esc_url( $value ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $value ); ?></a>
							<?php else : ?>
								<?php echo esc_html( self::empty_marker( $value ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_apply_form( array $detail ): void {
		$reviews = (array) ( $detail['safe_field_reviews'] ?? array() );
		$is_matched = in_array( (string) ( $detail['match_type'] ?? '' ), array( 'exact_source_match', 'source_url_match' ), true );
		?>
		<h3><?php echo esc_html__( 'Safe Writable Fields', 'mma-future-data-engine' ); ?></h3>
		<form method="post" style="max-width: 1200px;">
			<?php wp_nonce_field( self::APPLY_NONCE_ACTION, 'mmaf_profile_enrichment_apply_nonce' ); ?>
			<input type="hidden" name="enrichment_path" value="<?php echo esc_attr( (string) ( $detail['enrichment_file'] ?? '' ) ); ?>">
			<input type="hidden" name="source_fighter_id" value="<?php echo esc_attr( (string) ( $detail['source_fighter_id'] ?? '' ) ); ?>">
			<input type="hidden" name="source_url" value="<?php echo esc_attr( (string) ( $detail['source_url'] ?? '' ) ); ?>">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Apply', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Field', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Current canonical value', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Source suggestion', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Admin note', 'mma-future-data-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $reviews as $field => $review ) : ?>
						<?php $can_apply = ! empty( $review['can_apply'] ) && $is_matched; ?>
						<tr>
							<td>
								<input class="mmaf-safe-profile-field" type="checkbox" name="fields[]" value="<?php echo esc_attr( (string) $field ); ?>" <?php disabled( ! $can_apply ); ?>>
							</td>
							<td><code><?php echo esc_html( (string) $field ); ?></code><br><?php echo esc_html( (string) ( $review['label'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $review['current'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $review['suggested'] ?? '' ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $review['status'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( self::field_admin_note( (string) ( $review['status'] ?? '' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button" id="mmaf-select-all-safe-profile-suggestions"><?php echo esc_html__( 'Select all safe suggestions', 'mma-future-data-engine' ); ?></button>
			</p>
			<p>
				<label>
					<input type="checkbox" name="mmaf_profile_enrichment_confirm_reviewed" value="1">
					<?php echo esc_html__( 'I reviewed these source suggestions and want to apply selected fields to this canonical fighter.', 'mma-future-data-engine' ); ?>
				</label>
				<br>
				<label>
					<input type="checkbox" name="mmaf_profile_enrichment_confirm_no_rankable" value="1">
					<?php echo esc_html__( 'I understand this does not make the fighter public or rankable.', 'mma-future-data-engine' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" name="mmaf_profile_enrichment_apply_submit" class="button button-primary" value="1" <?php disabled( ! $is_matched ); ?>><?php echo esc_html__( 'Apply selected suggestions', 'mma-future-data-engine' ); ?></button>
			</p>
		</form>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var button = document.getElementById('mmaf-select-all-safe-profile-suggestions');
			if (!button) {
				return;
			}
			button.addEventListener('click', function () {
				document.querySelectorAll('.mmaf-safe-profile-field:not(:disabled)').forEach(function (field) {
					field.checked = true;
				});
			});
		});
		</script>
		<?php
	}

	private static function render_read_only_comparison( array $detail ): void {
		$statuses = (array) ( $detail['field_statuses'] ?? array() );
		$rows = array(
			'tapology_source_url' => array( (string) ( $detail['current_tapology_source_url'] ?? '' ), (string) ( $detail['source_url'] ?? '' ), (string) ( $statuses['tapology_source_url'] ?? 'not_available' ) ),
			'enriched_pro_record_vs_canonical_stats' => array( (string) ( $detail['canonical_stats_record'] ?? '' ), (string) ( $detail['enriched_pro_record'] ?? '' ), 'read_only_not_imported' ),
			'fight_history_row_count' => array( '', (string) (int) ( $detail['fight_history_rows'] ?? 0 ), 'read_only_not_imported' ),
			'record_gap_vs_canonical_stats' => array( '', (string) ( $detail['record_gap_indicator'] ?? '' ), '' === (string) ( $detail['record_gap_indicator'] ?? '' ) ? 'not_available' : 'read_only_record_gap' ),
			'reach' => array( '', (string) ( $detail['suggested_reach'] ?? '' ), (string) ( $statuses['reach'] ?? 'not_available' ) ),
			'born_location' => array( '', (string) ( $detail['born_location'] ?? '' ), (string) ( $statuses['born_location'] ?? 'not_available' ) ),
			'fighting_out_of' => array( '', (string) ( $detail['fighting_out_of'] ?? '' ), (string) ( $statuses['fighting_out_of'] ?? 'not_available' ) ),
		);
		?>
		<h3><?php echo esc_html__( 'Read-Only Source Context', 'mma-future-data-engine' ); ?></h3>
		<table class="widefat striped" style="max-width: 1200px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Field / Context', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Current canonical value', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Source value', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $label => $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $label ); ?></code></td>
						<td><?php echo esc_html( self::empty_marker( (string) $row[0] ) ); ?></td>
						<td><?php echo esc_html( self::empty_marker( (string) $row[1] ) ); ?></td>
						<td><code><?php echo esc_html( (string) $row[2] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_form( string $path, string $search, array $selected_filters, int $per_page ): void {
		$options = self::filter_options();
		?>
		<form method="get" style="margin: 16px 0; max-width: 1200px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mmaf-enrichment-path"><?php echo esc_html__( 'Enrichment JSON path', 'mma-future-data-engine' ); ?></label></th>
						<td>
							<input id="mmaf-enrichment-path" type="text" name="enrichment_path" class="large-text code" value="<?php echo esc_attr( $path ); ?>">
							<p class="description"><?php echo esc_html__( 'Allowed locations: scraper/data/latest and scraper/data/runs. JSON only.', 'mma-future-data-engine' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mmaf-profile-preview-search"><?php echo esc_html__( 'Search', 'mma-future-data-engine' ); ?></label></th>
						<td>
							<input id="mmaf-profile-preview-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Profile name, source fighter ID, or canonical fighter name', 'mma-future-data-engine' ); ?>">
							<?php self::render_per_page_select( $per_page ); ?>
						</td>
					</tr>
				</tbody>
			</table>
			<fieldset style="margin: 12px 0 8px;">
				<legend class="screen-reader-text"><?php echo esc_html__( 'Profile preview filters', 'mma-future-data-engine' ); ?></legend>
				<?php foreach ( $options as $key => $label ) : ?>
					<label style="display:inline-block; margin: 0 14px 8px 0;">
						<input type="checkbox" name="profile_filter[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_filters, true ) ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<p>
				<button type="submit" name="mmaf_profile_enrichment_preview_submit" class="button button-primary" value="1"><?php echo esc_html__( 'Run Enrichment Preview', 'mma-future-data-engine' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::page_url( array( 'tab' => self::TAB ) ) ); ?>"><?php echo esc_html__( 'Reset', 'mma-future-data-engine' ); ?></a>
			</p>
		</form>
		<?php
	}

	private static function render_summary_table( array $summary ): void {
		$keys = array(
			'enrichment_file',
			'schema_version',
			'source',
			'run_id',
			'scraped_at',
			'profiles_total',
			'profiles_matched_exact_source',
			'profiles_matched_url',
			'profiles_unmatched',
			'profiles_ambiguous',
			'profiles_missing_source_fighter_id',
			'profiles_missing_source_url',
			'profiles_with_dob',
			'profiles_with_birth_year',
			'profiles_with_gender_inference',
			'profiles_gender_cannot_infer',
			'profiles_with_weight_class',
			'profiles_with_height',
			'profiles_with_height_cm',
			'profiles_with_last_weigh_in',
			'profiles_with_image',
			'profiles_with_pro_record',
			'profiles_with_fight_history',
			'profiles_with_record_gap_vs_canonical_stats',
			'fields_canonical_empty_can_suggest',
			'fields_differs_source_suggestion',
			'backend_column_missing_count',
			'unsafe_or_ambiguous_fields',
			'profiles_not_safe_for_auto_write',
		);
		?>
		<table class="widefat striped" style="max-width: 1040px;">
			<tbody>
				<?php foreach ( $keys as $key ) : ?>
					<tr>
						<th scope="row" style="width: 360px;"><code><?php echo esc_html( $key ); ?></code></th>
						<td><?php echo esc_html( self::format_value( $summary[ $key ] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_preview_table( array $rows, string $path ): void {
		?>
		<div style="overflow-x:auto;">
			<table class="widefat striped" style="min-width: 3150px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Source Fighter ID', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Source URL', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Profile Display Name', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Matched Canonical Fighter', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Match Type', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Current DOB / Birth Year', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Suggested DOB / Birth Year', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Current Gender', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Suggested Gender', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Current Weight Class', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Suggested Weight Class', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Current Height', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Suggested Height', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Current Last Weigh-in', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Suggested Last Weigh-in', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Current Nationality', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Suggested Country / Location', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Canonical Stats Record', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Enriched Pro Record', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Record Gap', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'History Rows', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'History URL Coverage', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Prefight Record Rows', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Image', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Completeness', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Field Statuses', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Apply Status', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Suggested Admin Action', 'mma-future-data-engine' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="30"><?php echo esc_html__( 'No profiles matched the selected filters.', 'mma-future-data-engine' ); ?></td></tr>
				<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $row['source_fighter_id'] ); ?></code></td>
							<td><?php self::render_source_url( (string) $row['source_url'] ); ?></td>
							<td><strong><?php echo esc_html( (string) $row['profile_display_name'] ); ?></strong></td>
							<td><?php echo esc_html( self::canonical_summary( $row ) ); ?></td>
							<td><code><?php echo esc_html( (string) $row['match_type'] ); ?></code></td>
							<td><?php echo esc_html( self::join_values( (string) $row['current_dob'], (string) $row['current_birth_year'] ) ); ?></td>
							<td><?php echo esc_html( self::join_values( (string) $row['suggested_dob'], (string) $row['suggested_birth_year'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) ( $row['current_gender'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( self::suggested_gender_summary( $row ) ); ?></td>
						<td><?php echo esc_html( self::empty_marker( (string) $row['current_weight_class'] ) ); ?></td>
						<td><?php echo esc_html( self::empty_marker( (string) $row['suggested_weight_class'] ) ); ?></td>
						<td><?php echo esc_html( self::join_values( (string) ( $row['current_height'] ?? '' ), (string) ( $row['current_height_cm'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( self::join_values( (string) ( $row['suggested_height'] ?? '' ), (string) ( $row['suggested_height_cm'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( self::empty_marker( (string) ( $row['current_last_weigh_in'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( self::empty_marker( (string) ( $row['suggested_last_weigh_in'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( self::empty_marker( (string) $row['current_nationality'] ) ); ?></td>
							<td><?php echo esc_html( self::suggested_location_summary( $row ) ); ?></td>
							<td><?php echo esc_html( (string) $row['canonical_stats_record'] ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['enriched_pro_record'] ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['record_gap_indicator'] ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['fight_history_rows'] ); ?></td>
							<td><?php echo esc_html( self::history_url_summary( $row ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['fight_history_prefight_record_rows'] ); ?></td>
							<td><?php echo esc_html( ! empty( $row['image_available'] ) ? __( 'Yes', 'mma-future-data-engine' ) : __( 'No', 'mma-future-data-engine' ) ); ?></td>
							<td><?php echo esc_html( self::empty_marker( (string) $row['completeness_score'] ) ); ?></td>
							<td><?php echo esc_html( self::field_status_summary( (array) $row['field_statuses'] ) ); ?></td>
							<td><?php echo esc_html( self::list_summary( (array) $row['warnings'] ) ); ?></td>
							<td><?php echo esc_html( self::apply_status_label( $row ) ); ?></td>
							<td><?php echo esc_html( (string) $row['suggested_admin_action'] ); ?></td>
							<td><?php self::render_review_apply_link( $row, $path ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_pagination( int $total, int $paged, int $per_page, string $path, string $search, array $filters ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args = array(
			'page' => self::PAGE_SLUG,
			'tab' => self::TAB,
			'enrichment_path' => $path,
			'per_page' => $per_page,
			'mmaf_profile_enrichment_preview_submit' => 1,
			'_wpnonce' => wp_create_nonce( self::NONCE_ACTION ),
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( ! empty( $filters ) ) {
			$args['profile_filter'] = $filters;
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s profile', '%s profiles', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';
		if ( $total_pages > 1 ) {
			$base = add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
			echo '<div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base' => $base,
						'format' => '',
						'current' => $paged,
						'total' => $total_pages,
						'prev_text' => __( '&laquo;', 'mma-future-data-engine' ),
						'next_text' => __( '&raquo;', 'mma-future-data-engine' ),
					)
				)
			) . '</div>';
		}
		echo '<br class="clear"></div>';
	}

	private static function filter_options(): array {
		return array(
			'matched' => __( 'Matched', 'mma-future-data-engine' ),
			'unmatched' => __( 'Unmatched', 'mma-future-data-engine' ),
			'ambiguous' => __( 'Ambiguous', 'mma-future-data-engine' ),
			'has_dob_suggestion' => __( 'Has DOB suggestion', 'mma-future-data-engine' ),
			'has_weight_class_suggestion' => __( 'Has weight class suggestion', 'mma-future-data-engine' ),
			'has_pro_record' => __( 'Has pro record', 'mma-future-data-engine' ),
			'has_fight_history' => __( 'Has fight history', 'mma-future-data-engine' ),
			'has_record_gap' => __( 'Has record gap', 'mma-future-data-engine' ),
			'backend_column_missing' => __( 'Backend column missing', 'mma-future-data-engine' ),
			'unsafe_ambiguous' => __( 'Unsafe / ambiguous', 'mma-future-data-engine' ),
			'low_completeness' => __( 'Low completeness', 'mma-future-data-engine' ),
		);
	}

	private static function current_per_page(): int {
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;
		return in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 50;
	}

	private static function render_per_page_select( int $per_page ): void {
		?>
		<label for="mmaf-profile-preview-per-page" style="margin-left: 8px;"><?php echo esc_html__( 'Per page', 'mma-future-data-engine' ); ?></label>
		<select id="mmaf-profile-preview-per-page" name="per_page">
			<?php foreach ( array( 25, 50, 100 ) as $option ) : ?>
				<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( (string) $option ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function apply_detail_url( array $args ): string {
		return self::page_url(
			array_merge(
				array(
					'tab' => self::TAB,
					'profile_action' => 'review_apply',
				),
				$args
			)
		);
	}

	private static function render_review_apply_link( array $row, string $path ): void {
		if ( ! in_array( (string) ( $row['match_type'] ?? '' ), array( 'exact_source_match', 'source_url_match' ), true ) ) {
			echo esc_html( '-' );
			return;
		}

		$url = self::apply_detail_url(
			array(
				'enrichment_path' => $path,
				'source_fighter_id' => (string) ( $row['source_fighter_id'] ?? '' ),
				'source_url' => (string) ( $row['source_url'] ?? '' ),
			)
		);

		echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Review / Apply', 'mma-future-data-engine' ) . '</a>';
	}

	private static function apply_status_label( array $row ): string {
		$match_type = (string) ( $row['match_type'] ?? '' );
		if ( 'no_canonical_match' === $match_type ) {
			return __( 'Unmatched', 'mma-future-data-engine' );
		}
		if ( 'ambiguous_match' === $match_type ) {
			return __( 'Ambiguous', 'mma-future-data-engine' );
		}

		$safe_count = self::safe_applyable_count_from_row( $row );
		if ( $safe_count > 0 ) {
			return sprintf(
				_n( '%d safe suggestion', '%d safe suggestions', $safe_count, 'mma-future-data-engine' ),
				$safe_count
			);
		}

		if ( ! empty( $row['image_available'] ) || ! empty( $row['has_pro_record'] ) || (int) ( $row['fight_history_rows'] ?? 0 ) > 0 || '' !== (string) ( $row['record_gap_indicator'] ?? '' ) ) {
			return __( 'Review only', 'mma-future-data-engine' );
		}

		return __( 'No safe suggestions', 'mma-future-data-engine' );
	}

	private static function safe_applyable_count_from_row( array $row ): int {
		if ( ! in_array( (string) ( $row['match_type'] ?? '' ), array( 'exact_source_match', 'source_url_match' ), true ) ) {
			return 0;
		}

		$count = 0;
		$statuses = (array) ( $row['field_statuses'] ?? array() );
		foreach ( array_keys( FighterProfileEnrichmentApplyService::writable_fields() ) as $field ) {
			if ( 'canonical_empty_can_suggest' === (string) ( $statuses[ $field ] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}

	private static function store_apply_notice( array $notice ): string {
		$token = wp_generate_uuid4();
		set_transient( 'mmaf_profile_apply_notice_' . get_current_user_id() . '_' . $token, $notice, 300 );

		return $token;
	}

	private static function consume_apply_notice(): ?array {
		if ( empty( $_GET['apply_notice'] ) ) {
			return null;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['apply_notice'] ) );
		$key = 'mmaf_profile_apply_notice_' . get_current_user_id() . '_' . $token;
		$notice = get_transient( $key );
		delete_transient( $key );

		return is_array( $notice ) ? $notice : null;
	}

	private static function render_apply_notice( ?array $notice ): void {
		if ( ! $notice ) {
			return;
		}

		$type = in_array( (string) ( $notice['type'] ?? '' ), array( 'success', 'warning', 'error' ), true ) ? (string) $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p>';

		$applied = array_map( 'strval', (array) ( $notice['applied'] ?? array() ) );
		$skipped = (array) ( $notice['skipped'] ?? array() );
		if ( ! empty( $applied ) ) {
			echo '<p><strong>' . esc_html__( 'Fields applied:', 'mma-future-data-engine' ) . '</strong> <code>' . esc_html( implode( ', ', $applied ) ) . '</code></p>';
		}
		if ( ! empty( $skipped ) ) {
			$parts = array();
			foreach ( $skipped as $field => $reason ) {
				$parts[] = (string) $field . '=' . (string) $reason;
			}
			echo '<p><strong>' . esc_html__( 'Fields skipped:', 'mma-future-data-engine' ) . '</strong> <code>' . esc_html( implode( ', ', $parts ) ) . '</code></p>';
		}
		echo '</div>';
	}

	private static function field_admin_note( string $status ): string {
		if ( 'safe_empty_can_apply' === $status ) {
			return __( 'Empty or unknown canonical value; admin may apply this source suggestion.', 'mma-future-data-engine' );
		}
		if ( 'already_same' === $status ) {
			return __( 'Canonical value already matches the source suggestion.', 'mma-future-data-engine' );
		}
		if ( 'canonical_differs_source_suggestion' === $status ) {
			return __( 'Canonical value differs; use the normal Fighter edit screen for deliberate corrections.', 'mma-future-data-engine' );
		}
		if ( 'backend_column_missing' === $status ) {
			return __( 'No safe canonical backend column exists for this field.', 'mma-future-data-engine' );
		}
		if ( 'unsafe_ambiguous' === $status ) {
			return __( 'Suggestion is unsafe or ambiguous for this phase.', 'mma-future-data-engine' );
		}
		if ( 'protected_by_locked_provenance' === $status ) {
			return __( 'Field provenance is locked; enrichment cannot populate or overwrite this field.', 'mma-future-data-engine' );
		}
		if ( 'protected_by_manual_verified' === $status ) {
			return __( 'Field has manually verified provenance; use the normal Fighter edit screen for deliberate corrections.', 'mma-future-data-engine' );
		}
		if ( 'protected_by_admin_override' === $status ) {
			return __( 'Field has admin-protected provenance; use the normal Fighter edit screen for deliberate corrections.', 'mma-future-data-engine' );
		}

		return __( 'No explicit safe source suggestion is available.', 'mma-future-data-engine' );
	}

	private static function render_source_url( string $url ): void {
		if ( '' === $url ) {
			echo esc_html( '-' );
			return;
		}

		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open source', 'mma-future-data-engine' ) . '</a>';
	}

	private static function canonical_summary( array $row ): string {
		$fighter_id = (int) ( $row['matched_canonical_fighter_id'] ?? 0 );
		if ( $fighter_id <= 0 ) {
			return '-';
		}

		return '#' . $fighter_id . ' ' . (string) ( $row['matched_canonical_name'] ?? '' );
	}

	private static function suggested_location_summary( array $row ): string {
		$parts = array_filter(
			array(
				(string) ( $row['suggested_country'] ?? '' ),
				(string) ( $row['born_location'] ?? '' ),
				(string) ( $row['fighting_out_of'] ?? '' ),
			),
			static fn( string $value ): bool => '' !== $value
		);

		return empty( $parts ) ? '-' : implode( ' / ', $parts );
	}

	private static function suggested_gender_summary( array $row ): string {
		$gender = (string) ( $row['suggested_gender'] ?? '' );
		if ( '' === $gender ) {
			return '-';
		}

		$source = (string) ( $row['suggested_gender_source'] ?? '' );
		$confidence = (string) ( $row['suggested_gender_confidence'] ?? '' );
		$meta = trim( $source . ( '' !== $source && '' !== $confidence ? ' / ' : '' ) . $confidence );

		return '' === $meta ? $gender : $gender . ' (' . $meta . ')';
	}

	private static function history_url_summary( array $row ): string {
		return sprintf(
			'opponent=%d event=%d bout=%d',
			(int) $row['fight_history_opponent_url_rows'],
			(int) $row['fight_history_event_url_rows'],
			(int) $row['fight_history_bout_url_rows']
		);
	}

	private static function field_status_summary( array $statuses ): string {
		$parts = array();
		foreach ( $statuses as $field => $status ) {
			if ( in_array( $status, array( 'canonical_empty_can_suggest', 'canonical_differs_source_suggestion', 'backend_column_missing', 'unsafe_ambiguous', 'protected_by_locked_provenance', 'protected_by_manual_verified', 'protected_by_admin_override' ), true ) ) {
				$parts[] = $field . '=' . $status;
			}
		}

		return empty( $parts ) ? 'no actionable field suggestions' : implode( '; ', $parts );
	}

	private static function list_summary( array $items ): string {
		if ( empty( $items ) ) {
			return '-';
		}

		return implode( '; ', array_slice( array_map( 'strval', $items ), 0, 8 ) );
	}

	private static function join_values( string $first, string $second ): string {
		$value = trim( $first . ( '' !== $first && '' !== $second ? ' / ' : '' ) . $second );
		return '' === $value ? '-' : $value;
	}

	private static function empty_marker( string $value ): string {
		return '' === $value ? '-' : $value;
	}

	private static function format_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}

		return (string) $value;
	}
}
