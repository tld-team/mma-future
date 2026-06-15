<?php
/**
 * Run SystemCheckService::run from CLI.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-system-check.php
 *
 * Writes the run result into wp_mmaf_system_state under last_backend_system_check_summary.
 * Exits 0 on `critical_failures = 0`, 1 otherwise.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\System\SystemCheckService;

$result = ( new SystemCheckService() )->run();

$summary    = (array) ( $result['summary'] ?? array() );
$checks     = (array) ( $result['checks'] ?? array() );
$by_status  = array();
$critical_rows = array();

foreach ( $checks as $check ) {
	$status = (string) ( $check['status'] ?? '' );
	$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + 1;
	if ( 'fail' === $status ) {
		$critical_rows[] = $check;
	}
}

echo wp_json_encode(
	array(
		'status'    => (string) ( $result['status'] ?? '' ),
		'checked_at'=> (string) ( $result['checked_at'] ?? '' ),
		'summary'   => $summary,
		'check_counts_by_status' => $by_status,
		'critical_failures' => $critical_rows,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( ( (int) ( $summary['critical_failures'] ?? 0 ) ) > 0 ? 1 : 0 );
