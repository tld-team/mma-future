<?php
namespace MMAF\DataEngine\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterCommand {
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'mmaf fighter', self::class );
	}

	/** Reconcile public WordPress posts with existing canonical decisions. */
	public function sync_public( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['apply'] ) && ! in_array( wp_get_environment_type(), array( 'local','development','staging' ), true ) ) { \WP_CLI::error( 'Publication reconciliation is limited to local/dev/staging.' ); }
		\WP_CLI::line( wp_json_encode( ( new \MMAF\DataEngine\Services\FighterPublicationService() )->reconcile( isset( $assoc_args['apply'] ), get_current_user_id() ), JSON_PRETTY_PRINT ) );
	}

	/** Export private scraper decisions without changing canonical data. */
	public function tracking_export( array $args, array $assoc_args ): void {
		$document=(new \MMAF\DataEngine\Services\TrackingService())->export_manifest();
		$json=wp_json_encode($document,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		if(!empty($assoc_args['output'])){
			$path=(string)$assoc_args['output'];
			if(false===file_put_contents($path,$json."\n")){\WP_CLI::error('Could not write tracking decisions export.');}
			\WP_CLI::success('Tracking decisions written to '.$path);return;
		}
		\WP_CLI::line($json);
	}

	/** Read-only archive and return-candidate report. */
	public function tracking_review( array $args, array $assoc_args ): void {
		\WP_CLI::line(wp_json_encode((new \MMAF\DataEngine\Services\TrackingService())->review_rows((int)($assoc_args['limit']??500)),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Inspect or continue the resumable existing-fighter ranking disposition pass.
	 * Use --apply only after reviewing the preceding dry run; --reset deliberately
	 * starts a completed pass again.
	 */
	public function ranking_disposition( array $args, array $assoc_args ): void {
		$apply = isset( $assoc_args['apply'] );
		if ( $apply && ! in_array( wp_get_environment_type(), array( 'local','development','staging' ), true ) ) {
			\WP_CLI::error( 'Disposition backfill is limited to local/dev/staging.' );
		}
		$service = new \MMAF\DataEngine\Services\RankingDispositionService();
		$limit = (int) ( $assoc_args['limit'] ?? 100 );
		$result = $service->backfill_step( get_current_user_id(), $limit, $apply, isset( $assoc_args['reset'] ) );
		if ( $apply && isset( $assoc_args['all'] ) ) {
			while ( empty( $result['completed'] ) ) { $result = $service->backfill_step( get_current_user_id(), $limit, true, false ); }
		}
		\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		if ( $apply && ! empty( $result['completed'] ) ) { \WP_CLI::success( 'Ranking disposition backfill completed.' ); }
	}
	/**
	 * Reconcile fighter genders with what Tapology profiles state.
	 *
	 * Without --apply this is a read-only report. With --apply it, in order: re-applies stored
	 * profiles that state a gender to fighters whose gender is empty or only inferred; gives
	 * fighters without a gender the gender of their confirmed opponents when at least two agree;
	 * clears the "women_strawweight" label on bouts with a confirmed male participant.
	 * A manually set gender is never changed.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write the changes. Omit for a dry run.
	 *
	 * @subcommand gender-reconcile
	 */
	public function gender_reconcile( array $args, array $assoc_args ): void {
		global $wpdb;
		$apply   = isset( $assoc_args['apply'] );
		$actor   = get_current_user_id();
		$service = new \MMAF\DataEngine\Services\Import\OpponentGenderConsensusService();
		$tables  = \MMAF\DataEngine\Migrations\Schema::table_names();
		$count   = static function ( string $where ) use ( $wpdb, $tables ): int {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighters']} f WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		};
		$by_source = static function () use ( $count, $tables ): array {
			$out = array( 'no_gender' => $count( "(f.gender IS NULL OR f.gender IN ('','unknown'))" ) );
			foreach ( array_keys( \MMAF\DataEngine\Services\GenderSource::FILTERS ) as $filter ) {
				$out[ $filter ] = $count( (string) \MMAF\DataEngine\Services\GenderSource::filter_sql( $filter, $tables['field_provenance'] ) );
			}
			return $out;
		};

		$report = array( 'mode' => $apply ? 'apply' : 'dry_run', 'before' => $by_source() );
		try {
			$report['stored_profiles']       = $service->apply_stored_profiles( $actor, $apply );
			$report['opponent_consensus']    = $service->apply( $actor, $apply );
			$report['strawweight_bouts']     = $service->fix_strawweight_bout_labels( $apply );
			$report['opponent_mismatches']   = $service->mismatches();
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		if ( $apply ) {
			$report['after'] = $by_source();
		}

		\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		if ( $apply ) {
			\WP_CLI::success( 'Gender reconcile applied.' );
		}
	}

	/**
	 * Guarded placeholder for future audited fighter merges.
	 */
	public function merge( array $args, array $assoc_args ): void {
		\WP_CLI::error( 'Fighter merge is intentionally unavailable. Build and audit a dedicated merge service before enabling wp mmaf fighter merge <source_id> <target_id>.' );
	}
}
