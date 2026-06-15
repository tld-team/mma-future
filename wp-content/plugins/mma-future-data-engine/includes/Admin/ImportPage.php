<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Repositories\SourceImportItemRepository;
use MMAF\DataEngine\Repositories\SourceImportRunRepository;
use MMAF\DataEngine\Services\Import\ScraperJsonDryRunService;
use MMAF\DataEngine\Services\Import\ScraperJsonImportService;
use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentApplyService;
use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentPreviewService;
use MMAF\DataEngine\Services\Import\ScraperLatestBundleService;
use MMAF\DataEngine\Services\SystemSnapshotExportService;
use MMAF\DataEngine\Services\SystemSnapshotImportService;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImportPage {
	private const PAGE_SLUG = 'mmaf-import';
	private const MAX_FILE_SIZE = 26214400;
	private const MAX_SNAPSHOT_FILE_SIZE = 1073741824;

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'scraper_results';
		if ( ! in_array( $tab, array( 'scraper_results', 'fighter_profile_enrichment', 'system_snapshot' ), true ) ) {
			$tab = 'scraper_results';
		}

		if ( 'fighter_profile_enrichment' === $tab ) {
			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'MMA Future Import', 'mma-future-data-engine' ); ?></h1>
				<?php self::render_tabs( $tab ); ?>
				<?php FighterProfileEnrichmentPreviewPage::render(); ?>
			</div>
			<?php
			return;
		}

		if ( 'system_snapshot' === $tab ) {
			self::render_system_snapshot_tab();
			return;
		}

		$result        = null;
		$import_result = null;
		$error         = null;

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' === $request_method && isset( $_POST['mmaf_import_dry_run_submit'] ) ) {
			check_admin_referer( 'mmaf_import_dry_run', 'mmaf_import_dry_run_nonce' );

			try {
				$result = self::handle_dry_run_request();
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}

		if ( 'POST' === $request_method && isset( $_POST['mmaf_import_run_submit'] ) ) {
			check_admin_referer( 'mmaf_import_run', 'mmaf_import_run_nonce' );

			try {
				$import_result = self::handle_import_request();
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'MMA Future Import', 'mma-future-data-engine' ); ?></h1>
			<?php self::render_tabs( $tab ); ?>
			<p><?php echo esc_html__( 'Validate Tapology scraper results, preview the import plan, then run a guarded canonical import. Import updates canonical data only. Stats remain stale until rebuild, ranking drafts remain stale until recalculation, and live rankings remain unchanged until explicit activation.', 'mma-future-data-engine' ); ?></p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( $result ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Dry-run analysis completed. Canonical data was not modified.', 'mma-future-data-engine' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $import_result ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Actual import completed. Canonical data may have changed; stats, ranking drafts, and live rankings were not updated automatically.', 'mma-future-data-engine' ); ?></p></div>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data" style="max-width: 900px;">
				<?php wp_nonce_field( 'mmaf_import_dry_run', 'mmaf_import_dry_run_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="mmaf_latest_bundle_dir"><?php echo esc_html__( 'Latest bundle directory', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<input type="text" id="mmaf_latest_bundle_dir" name="mmaf_latest_bundle_dir" class="regular-text" placeholder="<?php echo esc_attr( ScraperLatestBundleService::default_latest_dir() ); ?>">
								<p class="description"><?php echo esc_html__( 'Preferred production input. Reads results.json, daily_summary.json, manual_review.json, run_manifest.json, changes.json, fighter_profiles.json, and fighter_profiles_report.json from scraper/data/latest. When provided, this takes precedence over individual JSON fields.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mmaf_scraper_json"><?php echo esc_html__( 'Upload results.json', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<input type="file" id="mmaf_scraper_json" name="mmaf_scraper_json" accept=".json,application/json">
								<p class="description"><?php echo esc_html__( 'JSON only. Maximum size: 25 MB. Uploaded files are read from PHP temp storage and are not kept publicly.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mmaf_server_json_path"><?php echo esc_html__( 'Server JSON path', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<input type="text" id="mmaf_server_json_path" name="mmaf_server_json_path" class="regular-text" placeholder="C:\Users\lukam\Local Sites\mma-future\scraper\data\latest\results.json">
								<p class="description"><?php echo esc_html__( 'Optional local development path. Upload takes precedence when both are provided.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mmaf_fighter_profiles_json"><?php echo esc_html__( 'Optional fighter_profiles.json', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<input type="file" id="mmaf_fighter_profiles_json" name="mmaf_fighter_profiles_json" accept=".json,application/json">
								<p class="description"><?php echo esc_html__( 'Optional Tapology fighter profile enrichment JSON. It is previewed with this dry-run and, on actual import, applied only to empty safe fields after the normal results import.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mmaf_fighter_profiles_server_json_path"><?php echo esc_html__( 'Optional profiles server path', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<input type="text" id="mmaf_fighter_profiles_server_json_path" name="mmaf_fighter_profiles_server_json_path" class="regular-text" placeholder="C:\Users\lukam\Local Sites\mma-future\scraper\data\latest\fighter_profiles.json">
								<p class="description"><?php echo esc_html__( 'Optional local development path. Must be under scraper/data/latest or scraper/data/runs. Upload takes precedence.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p>
					<button type="submit" name="mmaf_import_dry_run_submit" class="button button-primary" value="1"><?php echo esc_html__( 'Run Dry-Run Analysis', 'mma-future-data-engine' ); ?></button>
				</p>
			</form>

			<?php
			if ( $result ) {
				self::render_latest_bundle_summary( (array) ( $result['latest_bundle'] ?? array() ) );
				self::render_result( $result );
				self::render_profile_enrichment_preview( (array) ( $result['profile_enrichment'] ?? array() ) );
				self::render_import_form_after_dry_run( (array) $result['summary'], (array) ( $result['profile_enrichment_input'] ?? array() ), (array) ( $result['latest_bundle_input'] ?? array() ) );
			}

			if ( $import_result ) {
				self::render_import_result( $import_result );
			}

			self::render_import_runs_section();
			?>
		</div>
		<?php
	}

	private static function render_tabs( string $active_tab ): void {
		?>
		<nav class="nav-tab-wrapper" style="margin-bottom: 16px;">
			<a class="<?php echo esc_attr( 'nav-tab' . ( 'scraper_results' === $active_tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url() ); ?>"><?php echo esc_html__( 'Scraper Results Import', 'mma-future-data-engine' ); ?></a>
			<a class="<?php echo esc_attr( 'nav-tab' . ( 'fighter_profile_enrichment' === $active_tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => 'fighter_profile_enrichment' ) ) ); ?>"><?php echo esc_html__( 'Fighter Profile Enrichment Preview', 'mma-future-data-engine' ); ?></a>
			<a class="<?php echo esc_attr( 'nav-tab' . ( 'system_snapshot' === $active_tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => 'system_snapshot' ) ) ); ?>"><?php echo esc_html__( 'System Snapshot', 'mma-future-data-engine' ); ?></a>
		</nav>
		<?php
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	public static function handle_snapshot_export(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to export MMA Future data.', 'mma-future-data-engine' ) );
		}

		check_admin_referer( 'mmaf_system_snapshot_export', 'mmaf_system_snapshot_export_nonce' );
		self::extend_snapshot_runtime();

		$filename = 'mmaf-system-snapshot-' . gmdate( 'Ymd-His' ) . '.jsonl';

		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		try {
			( new SystemSnapshotExportService() )->stream_jsonl();
		} catch ( \Throwable $e ) {
			echo wp_json_encode(
				array(
					'type'    => 'error',
					'message' => $e->getMessage(),
				),
				JSON_UNESCAPED_SLASHES
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}

	private static function render_system_snapshot_tab(): void {
		$result = null;
		$error  = null;

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';
		if ( 'POST' === $request_method && isset( $_POST['mmaf_system_snapshot_import_submit'] ) ) {
			check_admin_referer( 'mmaf_system_snapshot_import', 'mmaf_system_snapshot_import_nonce' );

			try {
				$result = self::handle_snapshot_import_request();
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'MMA Future Import', 'mma-future-data-engine' ); ?></h1>
			<?php self::render_tabs( 'system_snapshot' ); ?>

			<p><?php echo esc_html__( 'Export or restore a full MMA Future plugin data snapshot. This is intended for staging, client test sites, local backup/restore, and moving the current canonical system state between sites.', 'mma-future-data-engine' ); ?></p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( $result ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'System snapshot import completed.', 'mma-future-data-engine' ); ?></p></div>
				<?php self::render_snapshot_import_result( $result ); ?>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Export Current System Snapshot', 'mma-future-data-engine' ); ?></h2>
			<p><?php echo esc_html__( 'Downloads all MMA Future plugin tables as a streaming JSONL snapshot using logical table names, so it can be imported on a site with a different WordPress database prefix without exhausting PHP memory.', 'mma-future-data-engine' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mmaf_system_snapshot_export', 'mmaf_system_snapshot_export_nonce' ); ?>
				<input type="hidden" name="action" value="mmaf_system_snapshot_export">
				<?php submit_button( __( 'Download System Snapshot JSONL', 'mma-future-data-engine' ), 'primary', 'submit', false ); ?>
			</form>

			<hr>
			<h2><?php echo esc_html__( 'Import System Snapshot', 'mma-future-data-engine' ); ?></h2>
			<div class="notice notice-warning inline">
				<p><strong><?php echo esc_html__( 'This replaces all existing MMA Future plugin data on this site with the uploaded snapshot.', 'mma-future-data-engine' ); ?></strong></p>
				<p><?php echo esc_html__( 'Use this only on a fresh target site, staging/client test site, or after taking a database backup. This is not the scraper incremental import.', 'mma-future-data-engine' ); ?></p>
			</div>
			<form method="post" enctype="multipart/form-data" style="max-width: 900px;">
				<?php wp_nonce_field( 'mmaf_system_snapshot_import', 'mmaf_system_snapshot_import_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="mmaf_system_snapshot_json"><?php echo esc_html__( 'Upload snapshot JSONL', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<input type="file" id="mmaf_system_snapshot_json" name="mmaf_system_snapshot_json" accept=".jsonl,.json,application/x-ndjson,application/json">
								<p class="description"><?php echo esc_html__( 'JSONL preferred; legacy JSON is accepted for small older snapshots. Maximum size: 1 GB.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mmaf_system_snapshot_server_path"><?php echo esc_html__( 'Server snapshot path', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<input type="text" id="mmaf_system_snapshot_server_path" name="mmaf_system_snapshot_server_path" class="regular-text" placeholder="C:\path\to\mmaf-system-snapshot.jsonl">
								<p class="description"><?php echo esc_html__( 'Optional local/dev path. Upload takes precedence when both are provided.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Portability', 'mma-future-data-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="mmaf_system_snapshot_reset_wp_posts" value="1" checked>
									<?php echo esc_html__( 'Reset fighter WordPress post links on import.', 'mma-future-data-engine' ); ?>
								</label>
								<p class="description"><?php echo esc_html__( 'Recommended for importing into another site, because old wp_post_id values usually point to posts that do not exist there. Canonical fighters, bouts, events, stats, and rankings are still imported.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Confirmation', 'mma-future-data-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="mmaf_system_snapshot_confirm_replace" value="1">
									<?php echo esc_html__( 'I understand this will replace all existing MMA Future plugin data on this site.', 'mma-future-data-engine' ); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="mmaf_system_snapshot_confirm_backup" value="1">
									<?php echo esc_html__( 'I confirm a backup exists or this is a local/staging/client test environment.', 'mma-future-data-engine' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" name="mmaf_system_snapshot_import_submit" class="button button-primary" value="1"><?php echo esc_html__( 'Import System Snapshot', 'mma-future-data-engine' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	private static function handle_snapshot_import_request(): array {
		if ( empty( $_POST['mmaf_system_snapshot_confirm_replace'] ) ) {
			throw new \RuntimeException( 'Confirm that this import should replace all existing MMA Future plugin data.' );
		}

		if ( empty( $_POST['mmaf_system_snapshot_confirm_backup'] ) ) {
			throw new \RuntimeException( 'Confirm that a backup exists or that this is a local/staging/client test environment.' );
		}

		$path = self::resolve_snapshot_input_path();
		self::validate_snapshot_json_file( $path );
		self::extend_snapshot_runtime();

		return ( new SystemSnapshotImportService() )->import_file(
			$path,
			get_current_user_id(),
			! empty( $_POST['mmaf_system_snapshot_reset_wp_posts'] )
		);
	}

	private static function resolve_snapshot_input_path(): string {
		if ( ! empty( $_FILES['mmaf_system_snapshot_json'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['mmaf_system_snapshot_json']['error'] ) {
			$file = $_FILES['mmaf_system_snapshot_json'];

			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				throw new \RuntimeException( 'Snapshot upload failed with PHP upload error code ' . (int) $file['error'] . '.' );
			}

			$tmp_name = (string) $file['tmp_name'];
			$name     = (string) $file['name'];

			if ( ! is_uploaded_file( $tmp_name ) ) {
				throw new \RuntimeException( 'Uploaded system snapshot file could not be verified.' );
			}

			if ( ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), array( 'jsonl', 'json' ), true ) ) {
				throw new \RuntimeException( 'Only .jsonl or legacy .json files are accepted for system snapshots.' );
			}

			return $tmp_name;
		}

		$server_path = isset( $_POST['mmaf_system_snapshot_server_path'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_system_snapshot_server_path'] ) ) : '';
		if ( '' === $server_path ) {
			throw new \RuntimeException( 'Choose a snapshot JSON upload or provide a server snapshot path.' );
		}

		$real_path = realpath( $server_path );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			throw new \RuntimeException( 'Server snapshot path does not exist or is not a file.' );
		}

		if ( ! in_array( strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) ), array( 'jsonl', 'json' ), true ) ) {
			throw new \RuntimeException( 'Only .jsonl or legacy .json files are accepted for system snapshots.' );
		}

		return $real_path;
	}

	private static function validate_snapshot_json_file( string $path ): void {
		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			throw new \RuntimeException( 'System snapshot JSON file is empty or cannot be sized.' );
		}

		if ( $size > self::MAX_SNAPSHOT_FILE_SIZE ) {
			throw new \RuntimeException( 'System snapshot JSON exceeds the 1 GB import limit.' );
		}

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = $finfo ? finfo_file( $finfo, $path ) : false;
			if ( $finfo ) {
				finfo_close( $finfo );
			}

			$allowed = array(
				'application/json',
				'application/jsonl',
				'application/x-jsonlines',
				'application/x-ndjason',
				'application/x-ndjson',
				'text/plain',
				'text/x-json',
				'text/x-jsonl',
				'text/x-ndjson',
				'application/octet-stream',
			);
			if ( is_string( $mime ) && '' !== $mime && ! in_array( $mime, $allowed, true ) ) {
				throw new \RuntimeException( 'File MIME type is not accepted for system snapshot import: ' . $mime );
			}
		}
	}

	private static function handle_dry_run_request(): array {
		$bundle_dir = self::resolve_optional_bundle_dir();
		if ( null !== $bundle_dir ) {
			$analysis = ( new ScraperLatestBundleService() )->analyze_bundle( $bundle_dir, get_current_user_id(), false );
			$result = (array) $analysis['results_dry_run'];
			$result['latest_bundle'] = (array) ( $analysis['summary'] ?? array() );
			$result['profile_enrichment'] = (array) ( $analysis['profile_enrichment'] ?? array() );
			$result['latest_bundle_input'] = array(
				'dir' => (string) ( $analysis['summary']['bundle_dir'] ?? $bundle_dir ),
				'ready_for_import' => ! empty( $analysis['ready_for_import'] ),
				'bundle_hash' => (string) ( $analysis['summary']['bundle_hash'] ?? '' ),
			);

			return $result;
		}

		$path = self::resolve_input_path();

		self::validate_json_file( $path );

		$service = new ScraperJsonDryRunService();

		$result = $service->analyze_file( $path, get_current_user_id(), false );
		$profile_input = self::resolve_optional_profile_input();
		if ( null !== $profile_input ) {
			$result['profile_enrichment'] = self::preview_profile_input( $profile_input, $result );
			$result['profile_enrichment_input'] = self::profile_input_public_meta( $profile_input );
		}

		return $result;
	}

	private static function handle_import_request(): array {
		if ( empty( $_POST['mmaf_import_confirm_reviewed'] ) ) {
			throw new \RuntimeException( 'Confirm that you reviewed the dry-run preview before running actual import.' );
		}

		if ( empty( $_POST['mmaf_import_confirm_backup'] ) ) {
			throw new \RuntimeException( 'Confirm that a backup exists or that this is a local/dev environment before running actual import.' );
		}

		$bundle_dir = self::resolve_optional_bundle_dir();
		if ( null !== $bundle_dir ) {
			$reviewed_bundle_hash = isset( $_POST['mmaf_import_reviewed_bundle_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_import_reviewed_bundle_hash'] ) ) : '';
			if ( '' === $reviewed_bundle_hash ) {
				throw new \RuntimeException( 'Actual import is blocked until the latest bundle hash has been reviewed in the dry-run preview form.' );
			}

			$analysis = ( new ScraperLatestBundleService() )->analyze_bundle( $bundle_dir, get_current_user_id(), false );
			$current_bundle_hash = (string) ( $analysis['summary']['bundle_hash'] ?? '' );
			if ( '' === $current_bundle_hash || ! hash_equals( $current_bundle_hash, $reviewed_bundle_hash ) ) {
				throw new \RuntimeException( 'Actual import is blocked until this exact latest bundle hash has been reviewed in the dry-run preview form.' );
			}

			$allow_not_ready = ! empty( $_POST['mmaf_import_confirm_not_ready_bundle'] );
			$not_ready_reason = isset( $_POST['mmaf_import_not_ready_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mmaf_import_not_ready_reason'] ) ) : '';
			if ( empty( $analysis['ready_for_import'] ) && $allow_not_ready && '' === trim( $not_ready_reason ) ) {
				throw new \RuntimeException( 'Explain why this not-ready latest bundle should be imported before running manual-review import.' );
			}

			return ( new ScraperLatestBundleService() )->import_bundle( $bundle_dir, get_current_user_id(), $allow_not_ready, $not_ready_reason );
		}

		$path = self::resolve_input_path();

		self::validate_json_file( $path );

		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read JSON file for import.' );
		}

		$dry_run = ( new ScraperJsonDryRunService() )->analyze_json_string( $content, get_current_user_id(), false );
		$summary = (array) ( $dry_run['summary'] ?? array() );

		if ( empty( $dry_run['is_valid'] ) || (int) ( $summary['validation_errors_count'] ?? 0 ) > 0 ) {
			throw new \RuntimeException( 'Actual import is blocked because this payload has validation errors. Run and review a valid dry-run first.' );
		}

		$payload_hash = (string) ( $summary['payload_hash'] ?? hash( 'sha256', $content ) );
		$reviewed_payload_hash = isset( $_POST['mmaf_import_reviewed_payload_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_import_reviewed_payload_hash'] ) ) : '';
		if ( '' === $reviewed_payload_hash || ! hash_equals( $payload_hash, $reviewed_payload_hash ) ) {
			throw new \RuntimeException( 'Actual import is blocked until this exact payload hash has been reviewed in the dry-run preview form.' );
		}

		if ( (int) ( $summary['conflicts_count'] ?? 0 ) > 0 && empty( $_POST['mmaf_import_confirm_conflicts'] ) ) {
			throw new \RuntimeException( 'This dry-run has conflicts. Confirm that conflicts should remain in the existing review/audit workflow before importing.' );
		}

		$profile_input = self::resolve_optional_profile_input();
		$service = new ScraperJsonImportService();
		$result = $service->import_json_string( $content, get_current_user_id() );
		$result['summary']['fighter_profile_enrichment_provided'] = null !== $profile_input;

		if ( null !== $profile_input ) {
			try {
				$enrichment = self::apply_profile_input( $profile_input );
				$result['profile_enrichment'] = $enrichment;
				$result['summary']['fighter_profile_enrichment_status'] = (string) ( $enrichment['summary']['status'] ?? 'completed' );
				$result['summary']['fighter_profile_enrichment_profiles_applied'] = (int) ( $enrichment['summary']['profiles_applied'] ?? 0 );
				$result['summary']['fighter_profile_enrichment_fields_applied'] = (int) ( $enrichment['summary']['fields_applied_total'] ?? 0 );
				$result['summary']['fighter_profile_enrichment_profiles_unmatched'] = (int) ( $enrichment['summary']['profiles_unmatched'] ?? 0 );
				$result['summary']['fighter_profile_enrichment_profiles_ambiguous'] = (int) ( $enrichment['summary']['profiles_ambiguous'] ?? 0 );
				$result['summary']['fighter_profile_enrichment_profiles_failed'] = (int) ( $enrichment['summary']['profiles_failed'] ?? 0 );
			} catch ( \Throwable $e ) {
				$result['profile_enrichment'] = array(
					'summary' => array(
						'status' => 'failed',
						'error' => $e->getMessage(),
					),
					'rows' => array(),
				);
				$result['summary']['fighter_profile_enrichment_status'] = 'failed';
				$result['summary']['fighter_profile_enrichment_error'] = $e->getMessage();
			}
		}

		return $result;
	}

	private static function resolve_optional_bundle_dir(): ?string {
		$bundle_dir = isset( $_POST['mmaf_latest_bundle_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_latest_bundle_dir'] ) ) : '';
		if ( '' === $bundle_dir ) {
			return null;
		}

		$real_path = realpath( $bundle_dir );
		if ( false === $real_path || ! is_dir( $real_path ) ) {
			throw new \RuntimeException( 'Latest bundle directory does not exist or is not a directory.' );
		}

		return $real_path;
	}

	private static function resolve_input_path(): string {
		if ( ! empty( $_FILES['mmaf_scraper_json'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['mmaf_scraper_json']['error'] ) {
			$file = $_FILES['mmaf_scraper_json'];

			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				throw new \RuntimeException( 'Upload failed with PHP upload error code ' . (int) $file['error'] . '.' );
			}

			$tmp_name = (string) $file['tmp_name'];
			$name     = (string) $file['name'];

			if ( ! is_uploaded_file( $tmp_name ) ) {
				throw new \RuntimeException( 'Uploaded file could not be verified.' );
			}

			if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				throw new \RuntimeException( 'Only .json files are accepted.' );
			}

			return $tmp_name;
		}

		$server_path = isset( $_POST['mmaf_server_json_path'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_server_json_path'] ) ) : '';
		if ( '' === $server_path ) {
			throw new \RuntimeException( 'Choose a JSON upload or provide a server JSON path.' );
		}

		$real_path = realpath( $server_path );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			throw new \RuntimeException( 'Server JSON path does not exist or is not a file.' );
		}

		if ( 'json' !== strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) ) ) {
			throw new \RuntimeException( 'Only .json files are accepted.' );
		}

		return $real_path;
	}

	private static function resolve_optional_profile_input(): ?array {
		if ( ! empty( $_FILES['mmaf_fighter_profiles_json'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['mmaf_fighter_profiles_json']['error'] ) {
			$file = $_FILES['mmaf_fighter_profiles_json'];

			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				throw new \RuntimeException( 'Fighter profile enrichment upload failed with PHP upload error code ' . (int) $file['error'] . '.' );
			}

			$tmp_name = (string) $file['tmp_name'];
			$name = (string) $file['name'];

			if ( ! is_uploaded_file( $tmp_name ) ) {
				throw new \RuntimeException( 'Uploaded fighter profile enrichment file could not be verified.' );
			}

			if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				throw new \RuntimeException( 'Only .json files are accepted for fighter profile enrichment.' );
			}

			self::validate_json_file( $tmp_name );

			return array(
				'path' => $tmp_name,
				'label' => 'uploaded:' . $name,
				'is_upload' => true,
				'server_path' => '',
			);
		}

		$server_path = isset( $_POST['mmaf_fighter_profiles_server_json_path'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_fighter_profiles_server_json_path'] ) ) : '';
		if ( '' === $server_path ) {
			return null;
		}

		$real_path = FighterProfileEnrichmentPreviewService::resolve_safe_json_path( $server_path );
		self::validate_json_file( $real_path );

		return array(
			'path' => $real_path,
			'label' => $real_path,
			'is_upload' => false,
			'server_path' => $real_path,
		);
	}

	private static function preview_profile_input( array $input, array $results_dry_run = array() ): array {
		$service = new FighterProfileEnrichmentPreviewService();
		if ( ! empty( $input['is_upload'] ) ) {
			$content = file_get_contents( (string) $input['path'] );
			if ( false === $content ) {
				throw new \RuntimeException( 'Could not read uploaded fighter profile enrichment JSON.' );
			}

			return $service->analyze_json_string( $content, (string) $input['label'], array(), '', 25, 0, $results_dry_run );
		}

		return $service->analyze_file( (string) $input['path'], array(), '', 25, 0, $results_dry_run );
	}

	private static function apply_profile_input( array $input ): array {
		$content = file_get_contents( (string) $input['path'] );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read fighter profile enrichment JSON for apply.' );
		}

		return ( new FighterProfileEnrichmentApplyService() )->apply_all_safe_json_string( $content, (string) $input['label'], get_current_user_id() );
	}

	private static function profile_input_public_meta( array $input ): array {
		return array(
			'label' => (string) ( $input['label'] ?? '' ),
			'is_upload' => ! empty( $input['is_upload'] ),
			'server_path' => (string) ( $input['server_path'] ?? '' ),
		);
	}

	private static function validate_json_file( string $path ): void {
		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			throw new \RuntimeException( 'JSON file is empty or cannot be sized.' );
		}

		if ( $size > self::MAX_FILE_SIZE ) {
			throw new \RuntimeException( 'JSON file exceeds the 25 MB dry-run limit.' );
		}

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = $finfo ? finfo_file( $finfo, $path ) : false;
			if ( $finfo ) {
				finfo_close( $finfo );
			}

			$allowed = array( 'application/json', 'text/plain', 'application/octet-stream', 'text/x-json' );
			if ( is_string( $mime ) && '' !== $mime && ! in_array( $mime, $allowed, true ) ) {
				throw new \RuntimeException( 'File MIME type is not accepted for JSON dry-run: ' . $mime );
			}
		}
	}

	private static function extend_snapshot_runtime(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
	}

	private static function render_result( array $result ): void {
		$summary = (array) $result['summary'];
		?>
		<hr>
		<h2><?php echo esc_html__( 'Pre-Import Review', 'mma-future-data-engine' ); ?></h2>
		<p><?php echo esc_html__( 'Review this dry-run before running actual import. No canonical data has been written by this dry-run.', 'mma-future-data-engine' ); ?></p>
		<h3><?php echo esc_html__( 'Summary', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_summary_table( $summary ); ?>

		<h3><?php echo esc_html__( 'Action Counts', 'mma-future-data-engine' ); ?></h3>
		<div style="display: grid; grid-template-columns: repeat(3, minmax(220px, 1fr)); gap: 16px; max-width: 1100px;">
			<?php self::render_counts_box( __( 'Events', 'mma-future-data-engine' ), self::event_action_counts( (array) ( $summary['event_actions'] ?? array() ) ) ); ?>
			<?php self::render_counts_box( __( 'Fighters', 'mma-future-data-engine' ), self::fighter_action_counts( (array) ( $summary['fighter_actions'] ?? array() ) ) ); ?>
			<?php self::render_counts_box( __( 'Bouts', 'mma-future-data-engine' ), self::bout_action_counts( (array) ( $summary['bout_actions'] ?? array() ) ) ); ?>
		</div>

		<h3><?php echo esc_html__( 'Detailed Preview', 'mma-future-data-engine' ); ?></h3>
		<?php
		self::render_events_table( __( 'Events to Create', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['events'], array( 'create_candidate' ) ) );
		self::render_events_table( __( 'Events to Update', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['events'], array( 'update_candidate' ) ) );
		self::render_fighters_table( __( 'Fighters to Create as Provisional', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['fighters'], array( 'create_provisional_candidate' ) ) );
		self::render_fighters_table( __( 'Likely Fighter Matches Skipped', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['fighters'], array( 'likely_match_review' ) ) );
		self::render_bouts_table( __( 'Bouts to Create', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['bouts'], array( 'create_candidate' ) ) );
		self::render_bouts_table( __( 'Bouts to Update', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['bouts'], array( 'update_candidate' ) ) );
		self::render_bouts_table( __( 'Bouts Skipped / Non-Scoring', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['bouts'], array( 'skipped_non_scoring', 'excluded_amateur', 'excluded_cancelled', 'excluded_overturned', 'upcoming_review' ) ) );
		self::render_events_table( __( 'Events Needing Review', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['events'], array( 'review_event_match' ) ) );
		self::render_bouts_table( __( 'Bouts Needing Review / Conflict', 'mma-future-data-engine' ), self::filter_by_actions( (array) $result['bouts'], array( 'review_bout_match' ) ) );
		self::render_diagnostics( $summary );
		?>
		<?php
	}

	private static function render_profile_enrichment_preview( array $report ): void {
		if ( empty( $report ) ) {
			return;
		}

		$summary = (array) ( $report['summary'] ?? array() );
		$rows = (array) ( $report['rows'] ?? array() );
		?>
		<h2><?php echo esc_html__( 'Optional Fighter Profile Enrichment Preview', 'mma-future-data-engine' ); ?></h2>
		<p><?php echo esc_html__( 'This preview is read-only. During actual import, enrichment runs after the normal results import and fills only empty safe fields on fighters matched by Tapology source mapping.', 'mma-future-data-engine' ); ?></p>
		<?php self::render_profile_enrichment_summary_table( $summary ); ?>
		<h3><?php echo esc_html__( 'Matched / Unmatched Profiles', 'mma-future-data-engine' ); ?></h3>
		<?php self::render_profile_enrichment_rows_table( $rows, false ); ?>
		<?php
	}

	private static function render_profile_enrichment_apply_result( array $result ): void {
		if ( empty( $result ) ) {
			return;
		}

		$summary = (array) ( $result['summary'] ?? array() );
		$rows = (array) ( $result['rows'] ?? array() );
		?>
		<h2><?php echo esc_html__( 'Fighter Profile Enrichment Apply Summary', 'mma-future-data-engine' ); ?></h2>
		<?php self::render_profile_enrichment_summary_table( $summary ); ?>
		<?php self::render_profile_enrichment_rows_table( $rows, true ); ?>
		<?php
	}

	private static function render_latest_bundle_summary( array $summary ): void {
		if ( empty( $summary ) ) {
			return;
		}

		$rows = array(
			'bundle_dir',
			'bundle_hash',
			'ready_for_import',
			'daily_ready_for_import',
			'event_run_status',
			'profile_run_status',
			'source_run_id',
			'events_total',
			'bouts_total',
			'profiles_success',
			'profiles_total',
			'manual_review_count',
			'blocking_issues_count',
			'bundle_errors_count',
			'results_validation_errors_count',
			'results_conflicts_count',
			'results_warnings_count',
			'results_unsupported_fields_count',
		);
		$changes = (array) ( $summary['changes'] ?? array() );
		?>
		<hr>
		<h2><?php echo esc_html__( 'Latest Bundle Health Gate', 'mma-future-data-engine' ); ?></h2>
		<?php if ( empty( $summary['ready_for_import'] ) ) : ?>
			<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'This latest bundle is not ready for automatic import. Actual import will be blocked unless you explicitly choose manual-review import.', 'mma-future-data-engine' ); ?></strong></p></div>
		<?php else : ?>
			<div class="notice notice-success inline"><p><strong><?php echo esc_html__( 'This latest bundle passed the import health gate.', 'mma-future-data-engine' ); ?></strong></p></div>
		<?php endif; ?>
		<table class="widefat striped" style="max-width: 1040px;">
			<tbody>
				<?php foreach ( $rows as $key ) : ?>
					<tr>
						<th scope="row"><code><?php echo esc_html( $key ); ?></code></th>
						<td><?php echo esc_html( self::format_scalar( $summary[ $key ] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><code><?php echo esc_html__( 'changes', 'mma-future-data-engine' ); ?></code></th>
					<td><?php echo esc_html( self::format_scalar( $changes ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php if ( ! empty( $summary['blocking_issues'] ) ) : ?>
			<h3><?php echo esc_html__( 'Blocking Issues', 'mma-future-data-engine' ); ?></h3>
			<ul>
				<?php foreach ( (array) $summary['blocking_issues'] as $issue ) : ?>
					<li><code><?php echo esc_html( (string) $issue ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! empty( $summary['bundle_errors'] ) ) : ?>
			<h3><?php echo esc_html__( 'Bundle Errors', 'mma-future-data-engine' ); ?></h3>
			<ul>
				<?php foreach ( (array) $summary['bundle_errors'] as $error ) : ?>
					<li><code><?php echo esc_html( (string) $error ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	private static function render_profile_enrichment_summary_table( array $summary ): void {
		$keys = array(
			'status',
			'enrichment_file',
			'schema_version',
			'source',
			'run_id',
			'profiles_total',
			'profiles_matched_exact_source',
			'profiles_matched_url',
			'profiles_matched_existing_canonical',
			'profiles_matched_planned_import',
			'profiles_matched',
			'profiles_unmatched',
			'profiles_ambiguous',
			'profiles_with_dob',
			'profiles_with_birth_year',
			'profiles_with_weight_class',
			'profiles_with_gender_inference',
			'profiles_with_height',
			'profiles_with_height_cm',
			'profiles_with_last_weigh_in',
			'profiles_gender_cannot_infer',
			'fields_canonical_empty_can_suggest',
			'profiles_applied',
			'profiles_no_safe_changes',
			'profiles_failed',
			'fields_applied_total',
			'fields_skipped_total',
			'error',
		);
		?>
		<table class="widefat striped" style="max-width: 1040px;">
			<tbody>
				<?php foreach ( $keys as $key ) : ?>
					<?php if ( ! array_key_exists( $key, $summary ) ) { continue; } ?>
					<tr>
						<th scope="row" style="width: 360px;"><code><?php echo esc_html( $key ); ?></code></th>
						<td><?php echo esc_html( self::format_scalar( $summary[ $key ] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_profile_enrichment_rows_table( array $rows, bool $apply_result ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No fighter profile enrichment rows to show.', 'mma-future-data-engine' ) . '</p>';
			return;
		}
		self::render_limited_notice( $rows );
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Profile', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Tapology', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Canonical Fighter', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Match / Status', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Safe Field Actions', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $rows, 0, 25 ) as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['profile_display_name'] ?? '' ) ); ?></td>
						<td>
							<code><?php echo esc_html( (string) ( $row['source_fighter_id'] ?? '' ) ); ?></code>
							<?php if ( ! empty( $row['source_url'] ) ) : ?>
								<br><a href="<?php echo esc_url( (string) $row['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open source', 'mma-future-data-engine' ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::profile_canonical_summary( $row ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $apply_result ? ( $row['status'] ?? '' ) : ( $row['profile_match_status'] ?? ( $row['match_type'] ?? '' ) ) ) ); ?></code></td>
						<td><?php echo esc_html( $apply_result ? self::applied_fields_summary( $row ) : self::profile_field_action_summary( $row ) ); ?></td>
						<td><?php echo esc_html( self::list_summary_for_import_page( (array) ( $row['warnings'] ?? array() ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_import_form_after_dry_run( array $summary, array $profile_input = array(), array $latest_bundle_input = array() ): void {
		$posted_path       = isset( $_POST['mmaf_server_json_path'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_server_json_path'] ) ) : '';
		$posted_profiles_path = isset( $_POST['mmaf_fighter_profiles_server_json_path'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_fighter_profiles_server_json_path'] ) ) : '';
		$posted_bundle_dir = isset( $_POST['mmaf_latest_bundle_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['mmaf_latest_bundle_dir'] ) ) : '';
		if ( '' === $posted_bundle_dir ) {
			$posted_bundle_dir = (string) ( $latest_bundle_input['dir'] ?? '' );
		}
		if ( '' === $posted_profiles_path && empty( $profile_input['is_upload'] ) ) {
			$posted_profiles_path = (string) ( $profile_input['server_path'] ?? '' );
		}
		$validation_errors = (int) ( $summary['validation_errors_count'] ?? 0 );
		$conflicts         = (int) ( $summary['conflicts_count'] ?? 0 );
		$blocked           = $validation_errors > 0;
		$is_bundle_import  = '' !== $posted_bundle_dir;
		$bundle_ready      = ! empty( $latest_bundle_input['ready_for_import'] );
		?>
		<h2><?php echo esc_html__( 'Actual Import', 'mma-future-data-engine' ); ?></h2>
		<?php if ( $blocked ) : ?>
			<div class="notice notice-error inline"><p><strong><?php echo esc_html__( 'Actual import is blocked because this dry-run has validation errors.', 'mma-future-data-engine' ); ?></strong></p></div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'This will create/update canonical entities only. Stats remain stale until rebuild, ranking drafts remain stale until recalculation, and live rankings remain unchanged until explicit activation.', 'mma-future-data-engine' ); ?></strong></p></div>
		<?php endif; ?>
		<form method="post" enctype="multipart/form-data" style="max-width: 900px;">
			<?php wp_nonce_field( 'mmaf_import_run', 'mmaf_import_run_nonce' ); ?>
			<input type="hidden" name="mmaf_import_reviewed_payload_hash" value="<?php echo esc_attr( (string) ( $summary['payload_hash'] ?? '' ) ); ?>">
			<?php if ( $is_bundle_import ) : ?>
				<input type="hidden" name="mmaf_import_reviewed_bundle_hash" value="<?php echo esc_attr( (string) ( $latest_bundle_input['bundle_hash'] ?? '' ) ); ?>">
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tbody>
					<?php if ( $is_bundle_import ) : ?>
						<tr>
							<th scope="row"><label for="mmaf_import_latest_bundle_dir"><?php echo esc_html__( 'Latest bundle directory', 'mma-future-data-engine' ); ?></label></th>
							<td>
								<input type="text" id="mmaf_import_latest_bundle_dir" name="mmaf_latest_bundle_dir" class="regular-text" value="<?php echo esc_attr( $posted_bundle_dir ); ?>">
								<p class="description"><?php echo esc_html__( 'Actual import will re-read this bundle directory, then enforce daily_summary.ready_for_import unless the override below is checked.', 'mma-future-data-engine' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="mmaf_import_scraper_json"><?php echo esc_html__( 'Upload results.json again', 'mma-future-data-engine' ); ?></label></th>
						<td>
							<input type="file" id="mmaf_import_scraper_json" name="mmaf_scraper_json" accept=".json,application/json">
							<p class="description"><?php echo esc_html__( 'Required for uploaded dry-runs because PHP temp uploads are not preserved between requests. Server path below can be reused for local development.', 'mma-future-data-engine' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mmaf_import_server_json_path"><?php echo esc_html__( 'Server JSON path', 'mma-future-data-engine' ); ?></label></th>
						<td>
							<input type="text" id="mmaf_import_server_json_path" name="mmaf_server_json_path" class="regular-text" value="<?php echo esc_attr( $posted_path ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mmaf_import_fighter_profiles_json"><?php echo esc_html__( 'Optional fighter_profiles.json again', 'mma-future-data-engine' ); ?></label></th>
						<td>
							<input type="file" id="mmaf_import_fighter_profiles_json" name="mmaf_fighter_profiles_json" accept=".json,application/json">
							<p class="description"><?php echo esc_html__( 'Required again only if the enrichment dry-run used an upload. Server path below can be reused for local development.', 'mma-future-data-engine' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mmaf_import_fighter_profiles_server_json_path"><?php echo esc_html__( 'Optional profiles server path', 'mma-future-data-engine' ); ?></label></th>
						<td>
							<input type="text" id="mmaf_import_fighter_profiles_server_json_path" name="mmaf_fighter_profiles_server_json_path" class="regular-text" value="<?php echo esc_attr( $posted_profiles_path ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Confirmation', 'mma-future-data-engine' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="mmaf_import_confirm_reviewed" value="1">
								<?php echo esc_html__( 'I reviewed this dry-run preview and understand canonical data may be written.', 'mma-future-data-engine' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="mmaf_import_confirm_backup" value="1">
								<?php echo esc_html__( 'I confirm a backup exists or this is a local/dev environment.', 'mma-future-data-engine' ); ?>
							</label>
							<?php if ( $is_bundle_import && ! $bundle_ready ) : ?>
								<br>
								<label>
									<input type="checkbox" name="mmaf_import_confirm_not_ready_bundle" value="1">
									<?php echo esc_html__( 'I understand this latest bundle is not ready_for_import and want to run a manual-review import anyway.', 'mma-future-data-engine' ); ?>
								</label>
								<p>
									<label for="mmaf_import_not_ready_reason"><strong><?php echo esc_html__( 'Manual-review import reason', 'mma-future-data-engine' ); ?></strong></label><br>
									<textarea id="mmaf_import_not_ready_reason" name="mmaf_import_not_ready_reason" class="large-text" rows="3" placeholder="<?php echo esc_attr__( 'Required when importing a latest bundle that did not pass ready_for_import.', 'mma-future-data-engine' ); ?>"></textarea>
								</p>
							<?php endif; ?>
							<?php if ( $conflicts > 0 ) : ?>
								<br>
								<label>
									<input type="checkbox" name="mmaf_import_confirm_conflicts" value="1">
									<?php echo esc_html__( 'I understand this payload has conflicts and they must remain in the existing review/audit workflow.', 'mma-future-data-engine' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php if ( ! $blocked ) : ?>
				<p>
					<button type="submit" name="mmaf_import_run_submit" class="button button-primary" value="1"><?php echo esc_html__( 'Run Actual Import', 'mma-future-data-engine' ); ?></button>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	private static function render_snapshot_import_result( array $result ): void {
		$source   = (array) ( $result['source'] ?? array() );
		$inserted = (array) ( $result['inserted'] ?? array() );
		?>
		<h2><?php echo esc_html__( 'System Snapshot Import Summary', 'mma-future-data-engine' ); ?></h2>
		<table class="widefat striped" style="max-width: 1040px;">
			<tbody>
				<tr>
					<th scope="row"><code>status</code></th>
					<td><?php echo esc_html( (string) ( $result['status'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><code>imported_at</code></th>
					<td><?php echo esc_html( (string) ( $result['imported_at'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><code>reset_wp_post_links</code></th>
					<td><?php echo esc_html( self::format_scalar( $result['reset_wp_post_links'] ?? false ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><code>snapshot_exported_at</code></th>
					<td><?php echo esc_html( (string) ( $source['exported_at'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><code>snapshot_source</code></th>
					<td><?php echo esc_html( self::format_scalar( $source['exported_from'] ?? array() ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><code>inserted_rows</code></th>
					<td><?php echo esc_html( self::format_scalar( $inserted ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-data-audit' ) ); ?>"><?php echo esc_html__( 'Next: Run Data Audit', 'mma-future-data-engine' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-stats' ) ); ?>"><?php echo esc_html__( 'Optional: Rebuild Stats', 'mma-future-data-engine' ); ?></a>
		</p>
		<?php
	}

	private static function render_import_result( array $result ): void {
		$summary = (array) $result['summary'];
		?>
		<h2><?php echo esc_html__( 'Import Summary', 'mma-future-data-engine' ); ?></h2>
		<?php self::render_summary_table( $summary ); ?>

		<table class="widefat striped" style="max-width: 980px;">
			<tbody>
				<?php
				$rows = array(
					'status',
					'events_created',
					'events_updated',
					'events_no_change',
					'events_needs_review_conflict',
					'fighters_created_provisional',
					'fighters_exact_matched',
					'fighters_likely_match_skipped',
					'bouts_created',
					'bouts_updated',
					'bouts_no_change',
					'bouts_skipped_non_scoring',
					'bouts_needs_review_conflict',
					'participants_created_updated',
					'provenance_rows_written',
					'audit_rows_written',
					'import_items_logged',
					'stats_rebuilt',
					'rankings_recalculated',
					'rankings_activated',
					'fighter_profile_enrichment_provided',
					'fighter_profile_enrichment_status',
					'fighter_profile_enrichment_profiles_applied',
					'fighter_profile_enrichment_fields_applied',
					'fighter_profile_enrichment_profiles_unmatched',
					'fighter_profile_enrichment_profiles_ambiguous',
					'fighter_profile_enrichment_profiles_failed',
					'latest_bundle_ready_for_import',
					'latest_bundle_manual_review_count',
					'manual_review_items_upserted',
				);
				foreach ( $rows as $key ) :
					?>
					<tr>
						<th scope="row"><code><?php echo esc_html( $key ); ?></code></th>
						<td><?php echo esc_html( self::format_scalar( $summary[ $key ] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php self::render_diagnostics( $summary ); ?>
		<?php self::render_profile_enrichment_apply_result( (array) ( $result['profile_enrichment'] ?? array() ) ); ?>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-stats' ) ); ?>"><?php echo esc_html__( 'Next: Rebuild Stats', 'mma-future-data-engine' ); ?></a>
		</p>
		<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Stats were not rebuilt automatically. Ranking drafts were not recalculated automatically. Live rankings were not activated or changed.', 'mma-future-data-engine' ); ?></p></div>
		<?php
	}

	private static function render_summary_table( array $summary ): void {
		$rows = array(
			'schema_version',
			'source',
			'source_run_id',
			'scraped_at',
			'payload_hash',
			'import_run_id',
			'events_total',
			'bouts_total',
			'fighter_refs_total',
			'unique_fighter_refs',
			'validation_errors_count',
			'warnings_count',
			'conflicts_count',
			'unsupported_fields_count',
			'non_scoring_bouts',
		);
		?>
		<table class="widefat striped" style="max-width: 900px;">
			<tbody>
				<?php foreach ( $rows as $key ) : ?>
					<tr>
						<th scope="row"><code><?php echo esc_html( $key ); ?></code></th>
						<td><?php echo esc_html( self::format_scalar( $summary[ $key ] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_counts_box( string $title, array $counts ): void {
		?>
		<div style="border: 1px solid #c3c4c7; background: #fff; padding: 12px;">
			<h3 style="margin-top: 0;"><?php echo esc_html( $title ); ?></h3>
			<table class="widefat striped">
				<tbody>
					<?php foreach ( $counts as $key => $count ) : ?>
						<tr>
							<th scope="row"><code><?php echo esc_html( (string) $key ); ?></code></th>
							<td><?php echo esc_html( (string) $count ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_diagnostics( array $summary ): void {
		$diagnostics = array(
			'Validation errors'  => (array) ( $summary['validation_errors'] ?? array() ),
			'Warnings'           => (array) ( $summary['warnings'] ?? array() ),
			'Conflicts'          => (array) ( $summary['conflicts'] ?? array() ),
			'Unsupported fields' => (array) ( $summary['unsupported_fields'] ?? array() ),
		);
		?>
		<h2><?php echo esc_html__( 'Diagnostics', 'mma-future-data-engine' ); ?></h2>
		<?php foreach ( $diagnostics as $label => $items ) : ?>
			<h3><?php echo esc_html( $label ); ?></h3>
			<?php if ( empty( $items ) ) : ?>
				<p><?php echo esc_html__( 'None.', 'mma-future-data-engine' ); ?></p>
			<?php else : ?>
				<ol>
					<?php foreach ( array_slice( $items, 0, 25 ) as $item ) : ?>
						<li><code><?php echo esc_html( (string) $item ); ?></code></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		<?php endforeach; ?>

		<h3><?php echo esc_html__( 'Summary JSON', 'mma-future-data-engine' ); ?></h3>
		<textarea class="large-text code" rows="10" readonly><?php echo esc_textarea( wp_json_encode( $summary, JSON_PRETTY_PRINT ) ); ?></textarea>
		<?php
	}

	private static function render_events_table( string $title, array $events ): void {
		?>
		<h4><?php echo esc_html( $title ); ?></h4>
		<?php if ( empty( $events ) ) : ?>
			<p><?php echo esc_html__( 'None.', 'mma-future-data-engine' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<?php self::render_limited_notice( $events ); ?>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Source Event ID', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Event', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Date', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Promotion', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Venue / Location', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( array_slice( $events, 0, 25 ) as $event ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) ( $event['source_event_id'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $event['event_name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $event['event_date'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $event['promotion_name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( trim( (string) ( $event['venue'] ?? '' ) . ' / ' . (string) ( $event['location'] ?? '' ), ' /' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $event['action'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $event['warning_count'] ?? 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_fighters_table( string $title, array $fighters ): void {
		?>
		<h4><?php echo esc_html( $title ); ?></h4>
		<?php if ( empty( $fighters ) ) : ?>
			<p><?php echo esc_html__( 'None.', 'mma-future-data-engine' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<?php self::render_limited_notice( $fighters ); ?>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Source Fighter ID', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Source Name', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Canonical Match', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Confidence', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Warning', 'mma-future-data-engine' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( array_slice( $fighters, 0, 25 ) as $fighter ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) ( $fighter['source_fighter_id'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $fighter['source_name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( ! empty( $fighter['matched_fighter'] ) ? (string) $fighter['matched_fighter'] . ' #' . (string) ( $fighter['matched_fighter_id'] ?? '' ) : '-' ); ?></td>
						<td><code><?php echo esc_html( (string) ( $fighter['action'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $fighter['confidence'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( '' === (string) ( $fighter['source_fighter_id'] ?? '' ) && empty( $fighter['source_url_hash'] ) ? __( 'Missing source_fighter_id and source URL identity', 'mma-future-data-engine' ) : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_bouts_table( string $title, array $bouts ): void {
		?>
		<h4><?php echo esc_html( $title ); ?></h4>
		<?php if ( empty( $bouts ) ) : ?>
			<p><?php echo esc_html__( 'None.', 'mma-future-data-engine' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<?php self::render_limited_notice( $bouts ); ?>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Source Bout ID', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Event', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Event Date', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Fighter A', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Fighter B', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Result', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Method', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Round/time', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Weight class', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( array_slice( $bouts, 0, 25 ) as $bout ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) ( $bout['source_bout_id'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $bout['event_name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $bout['event_date'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $bout['fighter_a'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $bout['fighter_b'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $bout['result_type'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( trim( (string) ( $bout['method_category'] ?? '' ) . ' ' . (string) ( $bout['method_detail'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( trim( (string) ( $bout['round'] ?? '' ) . ' / ' . (string) ( $bout['time'] ?? '' ), ' /' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $bout['weight_class'] ?? '' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $bout['action'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $bout['warning_count'] ?? 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function filter_by_actions( array $items, array $actions ): array {
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $actions ): bool {
					return is_array( $item ) && in_array( (string) ( $item['action'] ?? '' ), $actions, true );
				}
			)
		);
	}

	private static function event_action_counts( array $counts ): array {
		return array(
			'create'    => (int) ( $counts['create_candidate'] ?? 0 ),
			'update'    => (int) ( $counts['update_candidate'] ?? 0 ),
			'no_change' => (int) ( $counts['no_change_candidate'] ?? 0 ),
			'review'    => (int) ( $counts['review_event_match'] ?? 0 ),
		);
	}

	private static function fighter_action_counts( array $counts ): array {
		return array(
			'exact_match'         => (int) ( $counts['exact_source_match'] ?? 0 ),
			'likely_match_review' => (int) ( $counts['likely_match_review'] ?? 0 ),
			'create_provisional'  => (int) ( $counts['create_provisional_candidate'] ?? 0 ),
			'unresolved'          => (int) ( $counts['unresolved_fighter_ref'] ?? 0 ),
		);
	}

	private static function bout_action_counts( array $counts ): array {
		return array(
			'create'              => (int) ( $counts['create_candidate'] ?? 0 ),
			'update'              => (int) ( $counts['update_candidate'] ?? 0 ),
			'no_change'           => (int) ( $counts['no_change_candidate'] ?? 0 ),
			'skipped_non_scoring' => (int) ( $counts['skipped_non_scoring'] ?? 0 ),
			'needs_review'        => (int) ( $counts['review_bout_match'] ?? 0 ) + (int) ( $counts['upcoming_review'] ?? 0 ),
			'excluded'            => (int) ( $counts['excluded_amateur'] ?? 0 ) + (int) ( $counts['excluded_cancelled'] ?? 0 ) + (int) ( $counts['excluded_overturned'] ?? 0 ),
		);
	}

	private static function render_limited_notice( array $items ): void {
		if ( count( $items ) > 25 ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( sprintf( __( 'Showing first 25 of %d rows.', 'mma-future-data-engine' ), count( $items ) ) )
			);
		}
	}

	private static function render_import_runs_section(): void {
		$runs_repo = new SourceImportRunRepository();
		$items_repo = new SourceImportItemRepository();
		$detail_id = isset( $_GET['import_run_id'] ) ? absint( $_GET['import_run_id'] ) : 0;
		$runs      = $runs_repo->recent( 10 );
		?>
		<hr>
		<h2><?php echo esc_html__( 'Recent Import Runs', 'mma-future-data-engine' ); ?></h2>
		<?php if ( empty( $runs ) ) : ?>
			<p><?php echo esc_html__( 'No import runs found.', 'mma-future-data-engine' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Run ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Source Run ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Dry Run', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Schema', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Created / Finished', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Summary Counts', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Conflicts', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Item Failures', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $runs as $run ) : ?>
					<?php $summary = self::decode_json_object( $run['summary_json'] ?? '' ); ?>
					<tr>
						<td><?php echo esc_html( (string) $run['id'] ); ?></td>
						<td><code><?php echo esc_html( (string) $run['source_run_id'] ); ?></code></td>
						<td><?php echo esc_html( (string) $run['status'] ); ?></td>
						<td><?php echo esc_html( ! empty( $run['dry_run'] ) ? __( 'yes', 'mma-future-data-engine' ) : __( 'no', 'mma-future-data-engine' ) ); ?></td>
						<td><?php echo esc_html( (string) $run['source_schema_version'] ); ?></td>
						<td><?php echo esc_html( trim( (string) $run['created_at'] . ' / ' . (string) $run['finished_at'], ' /' ) ); ?></td>
						<td><?php echo esc_html( self::format_run_counts( $summary ) ); ?></td>
						<td><?php echo esc_html( (string) ( $summary['warnings_count'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( $summary['conflicts_count'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( $summary['item_failures'] ?? 0 ) ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'mmaf-import', 'import_run_id' => (int) $run['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'View Detail', 'mma-future-data-engine' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php echo esc_html__( 'Run detail uses existing import run and item log fields only. Item logs do not store full bout/event/fighter labels, so detail rows show source IDs, canonical IDs, statuses, actions, warnings, and errors.', 'mma-future-data-engine' ); ?></p>
		<?php
		if ( $detail_id > 0 ) {
			self::render_import_run_detail( $detail_id, $runs_repo, $items_repo );
		}
	}

	private static function render_import_run_detail( int $run_id, SourceImportRunRepository $runs_repo, SourceImportItemRepository $items_repo ): void {
		$run = $runs_repo->find( $run_id );
		if ( ! $run ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Import run not found.', 'mma-future-data-engine' ) . '</p></div>';
			return;
		}

		$summary = self::decode_json_object( $run['summary_json'] ?? '' );
		$counts  = $items_repo->counts_for_run( $run_id );
		?>
		<h3><?php echo esc_html( sprintf( __( 'Import Run #%d', 'mma-future-data-engine' ), $run_id ) ); ?></h3>
		<h4><?php echo esc_html__( 'Summary', 'mma-future-data-engine' ); ?></h4>
		<?php self::render_summary_table( $summary ); ?>

		<h4><?php echo esc_html__( 'Item Counts', 'mma-future-data-engine' ); ?></h4>
		<table class="widefat striped" style="max-width: 700px;">
			<thead><tr><th><?php echo esc_html__( 'Type', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Total', 'mma-future-data-engine' ); ?></th></tr></thead>
			<tbody>
				<?php if ( empty( $counts ) ) : ?>
					<tr><td colspan="3"><?php echo esc_html__( 'No item rows logged for this run.', 'mma-future-data-engine' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $counts as $count ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $count['item_type'] ); ?></td>
						<td><?php echo esc_html( (string) $count['status'] ); ?></td>
						<td><?php echo esc_html( (string) $count['total'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		self::render_import_items_table( __( 'Events', 'mma-future-data-engine' ), $items_repo->list_for_run( $run_id, 'event' ) );
		self::render_import_items_table( __( 'Fighters', 'mma-future-data-engine' ), $items_repo->list_for_run( $run_id, 'fighter' ) );
		self::render_import_items_table( __( 'Bouts', 'mma-future-data-engine' ), $items_repo->list_for_run( $run_id, 'bout' ) );
		self::render_import_items_table( __( 'Needs Review', 'mma-future-data-engine' ), self::filter_items_by_status( $items_repo->list_for_run( $run_id, '', 200 ), array( 'needs_review', 'conflict', 'failed' ) ) );
		self::render_diagnostic_list( __( 'Warnings', 'mma-future-data-engine' ), (array) ( $summary['warnings'] ?? array() ) );
		self::render_diagnostic_list( __( 'Conflicts', 'mma-future-data-engine' ), (array) ( $summary['conflicts'] ?? array() ) );
	}

	private static function render_import_items_table( string $title, array $items ): void {
		?>
		<h4><?php echo esc_html( $title ); ?></h4>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Item ID', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Type', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Source ID', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Canonical ID', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Action', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Warnings', 'mma-future-data-engine' ); ?></th><th><?php echo esc_html__( 'Error', 'mma-future-data-engine' ); ?></th></tr></thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="8"><?php echo esc_html__( 'None.', 'mma-future-data-engine' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $item['id'] ); ?></td>
						<td><?php echo esc_html( (string) $item['item_type'] ); ?></td>
						<td><code><?php echo esc_html( (string) $item['source_id'] ); ?></code></td>
						<td><?php self::render_canonical_link( (string) $item['item_type'], isset( $item['canonical_id'] ) ? (int) $item['canonical_id'] : 0 ); ?></td>
						<td><code><?php echo esc_html( (string) $item['action'] ); ?></code></td>
						<td><?php echo esc_html( (string) $item['status'] ); ?></td>
						<td><?php echo esc_html( self::format_warnings_json( $item['warnings_json'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) $item['error_message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_canonical_link( string $type, int $canonical_id ): void {
		if ( $canonical_id <= 0 ) {
			echo esc_html( '-' );
			return;
		}

		$page_by_type = array(
			'fighter' => 'mmaf-fighters',
			'event'   => 'mmaf-events',
			'bout'    => 'mmaf-bouts',
		);
		$id_arg_by_type = array(
			'fighter' => 'fighter_id',
			'event'   => 'event_id',
			'bout'    => 'bout_id',
		);

		if ( empty( $page_by_type[ $type ] ) ) {
			echo esc_html( (string) $canonical_id );
			return;
		}

		$url = add_query_arg(
			array(
				'page'                  => $page_by_type[ $type ],
				'action'                => 'edit',
				$id_arg_by_type[ $type ] => $canonical_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<a href="' . esc_url( $url ) . '">' . esc_html( '#' . $canonical_id ) . '</a>';
	}

	private static function filter_items_by_status( array $items, array $statuses ): array {
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $statuses ): bool {
					return is_array( $item ) && in_array( (string) ( $item['status'] ?? '' ), $statuses, true );
				}
			)
		);
	}

	private static function render_diagnostic_list( string $title, array $items ): void {
		echo '<h4>' . esc_html( $title ) . '</h4>';
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'None.', 'mma-future-data-engine' ) . '</p>';
			return;
		}

		echo '<ol>';
		foreach ( array_slice( $items, 0, 50 ) as $item ) {
			echo '<li><code>' . esc_html( (string) $item ) . '</code></li>';
		}
		echo '</ol>';
	}

	private static function decode_json_object( $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private static function format_run_counts( array $summary ): string {
		$parts = array(
			'events ' . (string) ( $summary['events_total'] ?? 0 ),
			'bouts ' . (string) ( $summary['bouts_total'] ?? 0 ),
			'fighter refs ' . (string) ( $summary['fighter_refs_total'] ?? 0 ),
		);

		if ( isset( $summary['events_created'] ) || isset( $summary['bouts_created'] ) || isset( $summary['fighters_created_provisional'] ) ) {
			$parts[] = 'created e/f/b ' . (string) ( $summary['events_created'] ?? 0 ) . '/' . (string) ( $summary['fighters_created_provisional'] ?? 0 ) . '/' . (string) ( $summary['bouts_created'] ?? 0 );
		}

		return implode( ', ', $parts );
	}

	private static function format_warnings_json( $json ): string {
		$items = self::decode_json_object( $json );
		if ( empty( $items ) ) {
			return '';
		}

		return implode( '; ', array_map( 'strval', $items ) );
	}

	private static function profile_canonical_summary( array $row ): string {
		if ( 'matched_planned_import' === (string) ( $row['match_type'] ?? '' ) ) {
			$name = (string) ( $row['matched_planned_name'] ?? $row['profile_display_name'] ?? '' );
			return trim( 'planned: ' . $name );
		}

		$fighter_id = (int) ( $row['matched_canonical_fighter_id'] ?? 0 );
		if ( $fighter_id <= 0 ) {
			return '-';
		}

		$name = (string) ( $row['matched_canonical_name'] ?? '' );
		return trim( '#' . $fighter_id . ' ' . $name );
	}

	private static function profile_field_action_summary( array $row ): string {
		if ( 'no_canonical_match' === (string) ( $row['match_type'] ?? '' ) ) {
			return 'profile=skip_unmatched_fighter';
		}

		$statuses = (array) ( $row['field_statuses'] ?? array() );
		if ( empty( $statuses ) ) {
			return '-';
		}

		$fields = array( 'nickname', 'date_of_birth', 'birth_year', 'weight_class', 'height', 'height_cm', 'last_weigh_in', 'nationality', 'gender' );
		$parts = array();
		foreach ( $fields as $field ) {
			$status = (string) ( $statuses[ $field ] ?? 'not_available' );
			$parts[] = $field . '=' . self::profile_action_from_status( $field, $status );
		}

		return implode( '; ', $parts );
	}

	private static function profile_action_from_status( string $field, string $status ): string {
		if ( 'canonical_empty_can_suggest' === $status || 'safe_empty_can_apply' === $status ) {
			return 'apply';
		}
		if ( 'not_available' === $status ) {
			return 'gender' === $field ? 'skip_cannot_infer_gender' : 'skip_missing_value';
		}
		if ( 'already_same' === $status || 'canonical_differs_source_suggestion' === $status ) {
			return 'skip_existing_value';
		}
		if ( in_array( $status, array( 'protected_by_locked_provenance', 'protected_by_manual_verified', 'protected_by_admin_override' ), true ) ) {
			return 'skip_locked_or_manual_verified';
		}
		if ( 'backend_column_missing' === $status || 'unsafe_ambiguous' === $status ) {
			return 'skip_unsupported_field';
		}

		return $status;
	}

	private static function applied_fields_summary( array $row ): string {
		$applied = array_map( 'strval', (array) ( $row['applied'] ?? array() ) );
		$skipped = (array) ( $row['skipped'] ?? array() );
		$parts = array();
		if ( ! empty( $applied ) ) {
			$parts[] = 'applied=' . implode( ',', $applied );
		}
		if ( ! empty( $skipped ) ) {
			$skip_parts = array();
			foreach ( $skipped as $field => $reason ) {
				$skip_parts[] = (string) $field . ':' . (string) $reason;
			}
			$parts[] = 'skipped=' . implode( ',', $skip_parts );
		}
		if ( ! empty( $row['error'] ) ) {
			$parts[] = 'error=' . (string) $row['error'];
		}

		return empty( $parts ) ? '-' : implode( '; ', $parts );
	}

	private static function list_summary_for_import_page( array $items ): string {
		if ( empty( $items ) ) {
			return '-';
		}

		return implode( '; ', array_slice( array_map( 'strval', $items ), 0, 8 ) );
	}

	private static function format_scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}

		return (string) $value;
	}
}
