<?php
namespace MMAF\DataEngine\CLI;

use MMAF\DataEngine\Services\StatsRebuildService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StatsCommand {
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'mmaf stats', self::class );
	}

	/**
	 * Rebuild all current fighter stats from canonical bouts and participants.
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		try {
			$service = new StatsRebuildService();
			$summary = $service->rebuild_all( 0, 'Manual stats rebuild from WP-CLI' );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
			return;
		}

		\WP_CLI::line( 'MMA Future stats rebuild complete' );
		\WP_CLI::line( 'Rebuilt at: ' . $summary['rebuilt_at'] );
		\WP_CLI::line( 'Fighters total: ' . $summary['fighters_total'] );
		\WP_CLI::line( 'Stats rows written: ' . $summary['stats_rows_written'] );
		\WP_CLI::line( 'Countable bouts: ' . $summary['countable_bouts'] );
		\WP_CLI::line( 'Skipped bouts: ' . $summary['skipped_bouts'] );
		\WP_CLI::line( 'Malformed bouts: ' . $summary['malformed_bouts'] );
		\WP_CLI::line( 'Participants processed: ' . $summary['participants_processed'] );
		\WP_CLI::line( 'Warnings: ' . $summary['warnings_count'] );

		\WP_CLI::success( 'Stats rebuilt.' );
	}
}
