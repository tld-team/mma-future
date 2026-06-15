<?php
namespace MMAF\DataEngine\CLI;

use MMAF\DataEngine\Services\Import\ScraperJsonImportService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImportRunCommand {
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'mmaf import run', self::class );
	}

	public function __invoke( array $args, array $assoc_args ): void {
		$path = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $path ) {
			\WP_CLI::error( 'Usage: wp mmaf import run /path/to/results.json' );
		}

		if ( ! is_file( $path ) ) {
			\WP_CLI::error( 'JSON file not found: ' . $path );
		}

		$service = new ScraperJsonImportService();
		$result  = $service->import_file( $path, 0 );
		$summary = (array) $result['summary'];

		\WP_CLI::line( 'MMA Future scraper JSON import' );
		\WP_CLI::line( 'Operational flow: import updates canonical data only.' );
		\WP_CLI::line( 'Stats remain stale until wp mmaf stats rebuild.' );
		\WP_CLI::line( 'Ranking drafts remain stale until wp mmaf rankings calculate or wp mmaf rankings recalculate.' );
		\WP_CLI::line( 'Live rankings remain unchanged until explicit wp mmaf rankings activate <run_id> or wp mmaf ranking activate <run_id>.' );
		\WP_CLI::line( 'Status: ' . (string) ( $summary['status'] ?? '' ) );
		\WP_CLI::line( 'Run ID: ' . (string) ( $summary['source_run_id'] ?? '' ) );
		\WP_CLI::line( 'Payload hash: ' . (string) ( $summary['payload_hash'] ?? '' ) );
		\WP_CLI::line( 'Import run row: ' . (string) ( $summary['import_run_id'] ?? 0 ) );
		\WP_CLI::line( 'Events created/updated/no_change/review: ' . $this->quad( $summary, 'events_created', 'events_updated', 'events_no_change', 'events_needs_review_conflict' ) );
		\WP_CLI::line( 'Fighters provisional/exact/likely_skipped: ' . $this->triple( $summary, 'fighters_created_provisional', 'fighters_exact_matched', 'fighters_likely_match_skipped' ) );
		\WP_CLI::line( 'Bouts created/updated/no_change/review: ' . $this->quad( $summary, 'bouts_created', 'bouts_updated', 'bouts_no_change', 'bouts_needs_review_conflict' ) );
		\WP_CLI::line( 'Participants created/updated: ' . (int) ( $summary['participants_created_updated'] ?? 0 ) );
		\WP_CLI::line( 'Warnings: ' . (int) ( $summary['warnings_count'] ?? 0 ) );
		\WP_CLI::line( 'Conflicts: ' . (int) ( $summary['conflicts_count'] ?? 0 ) );
		\WP_CLI::line( 'Stats rebuilt: no' );
		\WP_CLI::line( 'Rankings recalculated: no' );
		\WP_CLI::line( 'Rankings activated: no' );

		if ( empty( $result['is_valid'] ) || 'failed' === (string) ( $summary['status'] ?? '' ) ) {
			\WP_CLI::error( 'Import failed.' );
		}

		\WP_CLI::success( 'Import completed.' );
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

	private function triple( array $summary, string $a, string $b, string $c ): string {
		return sprintf(
			'%d/%d/%d',
			(int) ( $summary[ $a ] ?? 0 ),
			(int) ( $summary[ $b ] ?? 0 ),
			(int) ( $summary[ $c ] ?? 0 )
		);
	}
}
