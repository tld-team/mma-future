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

	/**
	 * Guarded placeholder for future audited fighter merges.
	 */
	public function merge( array $args, array $assoc_args ): void {
		\WP_CLI::error( 'Fighter merge is intentionally unavailable. Build and audit a dedicated merge service before enabling wp mmaf fighter merge <source_id> <target_id>.' );
	}
}
