<?php
/**
 * Read-only fighter readiness/promotion report.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-fighter-readiness-report.php [--limit=25]
 *
 * Writes no fighter state, stats, ranking, or audit rows.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\FighterReadinessService;

$limit = 25;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--limit=' ) ) {
		$limit = max( 1, min( 100, absint( substr( $arg, 8 ) ) ) );
	}
}

$report = ( new FighterReadinessService() )->promotion_report( null, $limit );

echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . PHP_EOL;
