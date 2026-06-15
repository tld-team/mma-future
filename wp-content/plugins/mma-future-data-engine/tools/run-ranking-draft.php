<?php
/**
 * Calculate a draft ranking run from canonical data.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-ranking-draft.php [--reference-date=YYYY-MM-DD]
 *
 * Writes a completed draft ranking run and draft snapshot rows. Does not
 * activate current rankings.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\RankingCalculatorService;

$reference_date = null;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--reference-date=' ) ) {
		$reference_date = sanitize_text_field( substr( $arg, 17 ) );
	}
}

$summary = ( new RankingCalculatorService() )->calculate_draft( 0, $reference_date );

echo wp_json_encode( $summary, JSON_PRETTY_PRINT ) . PHP_EOL;
