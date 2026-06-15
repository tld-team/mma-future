<?php
namespace MMAF\DataEngine\CLI;

use MMAF\DataEngine\Services\Import\ScraperLatestBundleService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImportLatestBundleCommand {
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'mmaf import latest-bundle', self::class );
	}

	/**
	 * Analyze or import a scraper data/latest bundle.
	 *
	 * ## OPTIONS
	 *
	 * [<path>]
	 * : Path to scraper/data/latest. Defaults to the workspace latest folder.
	 *
	 * [--dry-run]
	 * : Analyze the bundle and results import plan without canonical writes.
	 *
	 * [--allow-not-ready]
	 * : Permit actual import when daily_summary.ready_for_import is false.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$path = isset( $args[0] ) ? (string) $args[0] : ScraperLatestBundleService::default_latest_dir();
		$service = new ScraperLatestBundleService();
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			$result = $service->analyze_bundle( $path, 0, true );
			$this->print_analysis( $result );

			if ( empty( $result['is_valid'] ) ) {
				\WP_CLI::error( 'Latest bundle dry-run has validation errors.' );
			}

			if ( empty( $result['ready_for_import'] ) ) {
				\WP_CLI::warning( 'Latest bundle is not ready for automatic import.' );
			}

			\WP_CLI::success( 'Latest bundle dry-run completed without canonical writes.' );
			return;
		}

		$result = $service->import_bundle( $path, 0, isset( $assoc_args['allow-not-ready'] ) );
		$summary = (array) ( $result['summary'] ?? array() );
		$bundle = (array) ( $result['bundle_summary'] ?? array() );

		\WP_CLI::line( 'MMA Future latest bundle import' );
		\WP_CLI::line( 'Bundle: ' . (string) ( $bundle['bundle_dir'] ?? $path ) );
		\WP_CLI::line( 'Ready for import: ' . ( ! empty( $bundle['ready_for_import'] ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Import run row: ' . (int) ( $summary['import_run_id'] ?? 0 ) );
		\WP_CLI::line( 'Events created/updated/no_change/review: ' . $this->quad( $summary, 'events_created', 'events_updated', 'events_no_change', 'events_needs_review_conflict' ) );
		\WP_CLI::line( 'Bouts created/updated/no_change/review: ' . $this->quad( $summary, 'bouts_created', 'bouts_updated', 'bouts_no_change', 'bouts_needs_review_conflict' ) );
		\WP_CLI::line( 'Profile enrichment status: ' . (string) ( $summary['fighter_profile_enrichment_status'] ?? '' ) );
		\WP_CLI::line( 'Profile fields applied: ' . (int) ( $summary['fighter_profile_enrichment_fields_applied'] ?? 0 ) );
		\WP_CLI::line( 'Manual review items upserted: ' . (int) ( $summary['manual_review_items_upserted'] ?? 0 ) );
		\WP_CLI::line( 'Stats rebuilt: no' );
		\WP_CLI::line( 'Rankings recalculated: no' );
		\WP_CLI::line( 'Rankings activated: no' );

		if ( empty( $result['is_valid'] ) || 'failed' === (string) ( $summary['status'] ?? '' ) ) {
			\WP_CLI::error( 'Latest bundle import failed.' );
		}

		\WP_CLI::success( 'Latest bundle import completed.' );
	}

	private function print_analysis( array $result ): void {
		$summary = (array) ( $result['summary'] ?? array() );
		$changes = (array) ( $summary['changes'] ?? array() );

		\WP_CLI::line( 'MMA Future latest bundle dry-run' );
		\WP_CLI::line( 'Bundle: ' . (string) ( $summary['bundle_dir'] ?? '' ) );
		\WP_CLI::line( 'Bundle hash: ' . (string) ( $summary['bundle_hash'] ?? '' ) );
		\WP_CLI::line( 'Ready for import: ' . ( ! empty( $summary['ready_for_import'] ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Daily ready flag: ' . ( ! empty( $summary['daily_ready_for_import'] ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Event run status: ' . (string) ( $summary['event_run_status'] ?? '' ) );
		\WP_CLI::line( 'Profile run status: ' . (string) ( $summary['profile_run_status'] ?? '' ) );
		\WP_CLI::line( 'Run ID: ' . (string) ( $summary['source_run_id'] ?? '' ) );
		\WP_CLI::line( 'Events: ' . (int) ( $summary['events_total'] ?? 0 ) );
		\WP_CLI::line( 'Bouts: ' . (int) ( $summary['bouts_total'] ?? 0 ) );
		\WP_CLI::line( 'Profiles: ' . (int) ( $summary['profiles_success'] ?? 0 ) . '/' . (int) ( $summary['profiles_total'] ?? 0 ) );
		\WP_CLI::line( 'Manual review items: ' . (int) ( $summary['manual_review_count'] ?? 0 ) );
		\WP_CLI::line( 'Results validation errors/conflicts/warnings: ' . (int) ( $summary['results_validation_errors_count'] ?? 0 ) . '/' . (int) ( $summary['results_conflicts_count'] ?? 0 ) . '/' . (int) ( $summary['results_warnings_count'] ?? 0 ) );
		\WP_CLI::line( 'Unsupported results fields: ' . (int) ( $summary['results_unsupported_fields_count'] ?? 0 ) );
		\WP_CLI::line( 'Changes new_events/updated_events/new_bouts/updated_bouts: ' . $this->change_line( $changes ) );

		foreach ( (array) ( $summary['blocking_issues'] ?? array() ) as $issue ) {
			\WP_CLI::warning( 'Blocking issue: ' . (string) $issue );
		}

		foreach ( (array) ( $summary['bundle_errors'] ?? array() ) as $error ) {
			\WP_CLI::warning( 'Bundle error: ' . (string) $error );
		}
	}

	private function change_line( array $changes ): string {
		return sprintf(
			'%d/%d/%d/%d',
			(int) ( $changes['new_events'] ?? 0 ),
			(int) ( $changes['updated_events'] ?? 0 ),
			(int) ( $changes['new_bouts'] ?? 0 ),
			(int) ( $changes['updated_bouts'] ?? 0 )
		);
	}

	private function quad( array $summary, string $a, string $b, string $c, string $d ): string {
		return sprintf(
			'%d/%d/%d/%d',
			(int) ( $summary[ $a ] ?? 0 ),
			(int) ( $summary[ $b ] ?? 0 ),
			(int) ( $summary[ $c ] ?? 0 ),
			(int) ( $summary[ $d ] ?? 0 )
		);
	}
}
