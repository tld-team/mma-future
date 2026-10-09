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
	/**
	 * Guarded placeholder for future audited fighter merges.
	 */
	public function merge( array $args, array $assoc_args ): void {
		\WP_CLI::error( 'Fighter merge is intentionally unavailable. Build and audit a dedicated merge service before enabling wp mmaf fighter merge <source_id> <target_id>.' );
	}
}
