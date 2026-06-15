<?php
namespace MMAF\DataEngine\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImportJsonCommand {
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'mmaf import json', self::class );
	}

	/**
	 * Import or dry-run a scraper JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Path to results.json.
	 *
	 * [--dry-run]
	 * : Run the existing dry-run flow instead of canonical import.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['dry-run'] ) ) {
			( new ImportDryRunCommand() )->__invoke( $args, $assoc_args );
			return;
		}

		( new ImportRunCommand() )->__invoke( $args, $assoc_args );
	}
}
