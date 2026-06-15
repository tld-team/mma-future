<?php
/**
 * Shared WordPress bootstrap for Data Engine CLI tools.
 *
 * LocalWP often exposes MySQL on a non-default port. CLI tools can set
 * MMAF_CLI_DB_HOST before loading WordPress, while this helper suppresses the
 * expected duplicate DB_HOST warning from local wp-config.php.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

$cli_db_host = getenv( 'MMAF_CLI_DB_HOST' );
if ( is_string( $cli_db_host ) && '' !== $cli_db_host && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', $cli_db_host );
}

$suppress_db_host_redefine = static function ( int $errno, string $errstr ): bool {
	if ( E_WARNING !== $errno ) {
		return false;
	}

	return false !== strpos( $errstr, 'Constant DB_HOST already defined' );
};

set_error_handler( $suppress_db_host_redefine );
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
restore_error_handler();
