<?php
namespace MMAF\DataEngine\CLI;


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
	 * Queue a resumable statistics generation. Use --wait for up to 50 seconds,
	 * or --operation-id=<id> to inspect/resume the same saved calculation.
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		try {
			$job = CalculationCommand::run('stats', $assoc_args);
			if ($job['status'] !== 'completed') { return; }
			$summary = $job['summary'];
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
