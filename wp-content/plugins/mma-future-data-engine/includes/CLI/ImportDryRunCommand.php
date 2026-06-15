<?php
namespace MMAF\DataEngine\CLI;

use MMAF\DataEngine\Services\Import\ScraperJsonDryRunService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImportDryRunCommand {
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'mmaf import dry-run', self::class );
	}

	public function __invoke( array $args, array $assoc_args ): void {
		$path = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $path ) {
			\WP_CLI::error( 'Usage: wp mmaf import dry-run /path/to/results.json' );
		}

		if ( ! is_file( $path ) ) {
			\WP_CLI::error( 'JSON file not found: ' . $path );
		}

		$service = new ScraperJsonDryRunService();
		$result  = $service->analyze_file( $path, 0, true );
		$summary = (array) $result['summary'];

		\WP_CLI::line( 'MMA Future scraper JSON dry-run' );
		\WP_CLI::line( 'Operational flow: dry-run writes import diagnostics only; canonical data, stats, ranking drafts, and live rankings remain unchanged.' );
		\WP_CLI::line( 'Schema: ' . (string) ( $summary['schema_version'] ?? '' ) );
		\WP_CLI::line( 'Source: ' . (string) ( $summary['source'] ?? '' ) );
		\WP_CLI::line( 'Run ID: ' . (string) ( $summary['source_run_id'] ?? '' ) );
		\WP_CLI::line( 'Payload hash: ' . (string) ( $summary['payload_hash'] ?? '' ) );
		\WP_CLI::line( 'Import run row: ' . (string) ( $summary['import_run_id'] ?? 0 ) );
		\WP_CLI::line( 'Events: ' . (int) ( $summary['events_total'] ?? 0 ) );
		\WP_CLI::line( 'Bouts: ' . (int) ( $summary['bouts_total'] ?? 0 ) );
		\WP_CLI::line( 'Fighter refs: ' . (int) ( $summary['fighter_refs_total'] ?? 0 ) . ' total, ' . (int) ( $summary['unique_fighter_refs'] ?? 0 ) . ' unique' );
		\WP_CLI::line( 'Warnings: ' . (int) ( $summary['warnings_count'] ?? 0 ) );
		\WP_CLI::line( 'Conflicts: ' . (int) ( $summary['conflicts_count'] ?? 0 ) );
		\WP_CLI::line( 'Non-scoring bouts: ' . (int) ( $summary['non_scoring_bouts'] ?? 0 ) );

		$this->line_counts( 'Event actions', (array) ( $summary['event_actions'] ?? array() ) );
		$this->line_counts( 'Fighter actions', (array) ( $summary['fighter_actions'] ?? array() ) );
		$this->line_counts( 'Bout actions', (array) ( $summary['bout_actions'] ?? array() ) );

		if ( ! $result['is_valid'] ) {
			foreach ( (array) ( $summary['validation_errors'] ?? array() ) as $error ) {
				\WP_CLI::warning( $error );
			}
			\WP_CLI::error( 'Schema validation failed.' );
		}

		\WP_CLI::success( 'Dry-run completed without canonical writes.' );
	}

	private function line_counts( string $label, array $counts ): void {
		\WP_CLI::line( $label . ':' );

		foreach ( $counts as $action => $count ) {
			\WP_CLI::line( sprintf( '- %s: %d', (string) $action, (int) $count ) );
		}
	}
}
