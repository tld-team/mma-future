<?php
namespace MMAF\DataEngine\CLI;

use MMAF\DataEngine\Services\RankingActivationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankingsCommand {
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'mmaf rankings', self::class );
		\WP_CLI::add_command( 'mmaf ranking', self::class );
	}

	/**
	 * Queue a resumable draft. Use --wait for up to 50 seconds or
	 * --operation-id=<id> to continue the same draft; activation stays manual.
	 */
	public function calculate( array $args, array $assoc_args ): void {
		try {
			$job = CalculationCommand::run('ranking', $assoc_args);
			if ($job['status'] !== 'completed') { return; }
			$summary = $job['summary'];
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
			return;
		}

		\WP_CLI::line( 'MMA Future ranking draft calculated' );
		\WP_CLI::line( 'Run ID: ' . $summary['ranking_run_id'] );
		\WP_CLI::line( 'Formula: ' . $summary['formula_version'] );
		\WP_CLI::line( 'Reference date: ' . $summary['reference_date'] );
		\WP_CLI::line( 'Status: ' . $summary['status'] );
		\WP_CLI::line( 'Eligible fighters: ' . $summary['eligible_fighters'] );
		\WP_CLI::line( 'Ineligible fighters: ' . $summary['ineligible_fighters'] );
		\WP_CLI::line( 'Ranked rows: ' . $summary['ranked_rows'] );
		\WP_CLI::line( 'Boards: ' . implode( ',', (array) ($summary['boards_generated'] ?? []) ) );
		\WP_CLI::line( 'Warnings: ' . $summary['warnings_count'] );
		\WP_CLI::line( 'Storage: ' . ($summary['storage_strategy'] ?? 'validated inactive snapshots') );
		\WP_CLI::line( 'Activation: draft calculation does not change live rankings; activation stays manual.' );

		\WP_CLI::success( 'Ranking draft calculated.' );
	}

	/**
	 * Alias for calculating a draft ranking run.
	 */
	public function recalculate( array $args, array $assoc_args ): void {
		$this->calculate( $args, $assoc_args );
	}

	/**
	 * Activate a completed ranking run into current live rankings.
	 */
	public function activate( array $args, array $assoc_args ): void {
		$ranking_run_id = isset( $args[0] ) ? (int) $args[0] : 0;

		try {
			$service = new RankingActivationService();
			$summary = $service->activate( $ranking_run_id, 0 );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
			return;
		}

		\WP_CLI::line( 'MMA Future ranking activated' );
		\WP_CLI::line( 'Run ID: ' . $summary['ranking_run_id'] );
		\WP_CLI::line( 'Formula: ' . $summary['formula_version'] );
		\WP_CLI::line( 'Activated at: ' . $summary['activated_at'] );
		\WP_CLI::line( 'Current rows written: ' . $summary['current_rows_written'] );
		\WP_CLI::line( 'Boards count: ' . $summary['boards_count'] );
		\WP_CLI::line( 'Previous active run: ' . ( null === $summary['previous_active_ranking_run_id'] ? 'None found' : $summary['previous_active_ranking_run_id'] ) );
		\WP_CLI::line( 'Warnings: ' . $summary['warnings_count'] );

		\WP_CLI::success( 'Ranking activated.' );
	}
}
