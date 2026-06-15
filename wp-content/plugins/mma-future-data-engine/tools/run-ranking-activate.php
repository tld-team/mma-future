<?php
/**
 * Activate a completed draft ranking run into current rankings.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-ranking-activate.php --run-id=123
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\RankingActivationService;

$run_id = 0;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--run-id=' ) ) {
		$run_id = absint( substr( $arg, 9 ) );
	}
}

if ( $run_id <= 0 ) {
	fwrite( STDERR, "A positive --run-id is required.\n" );
	exit( 1 );
}

$summary = ( new RankingActivationService() )->activate( $run_id, 0 );

echo wp_json_encode( $summary, JSON_PRETTY_PRINT ) . PHP_EOL;
