<?php
/**
 * Read-only REST smoke check for public rankings endpoint.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/verify-rest-rankings.php [--board=overall] [--per-page=5]
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

$board    = 'overall';
$per_page = 5;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--board=' ) ) {
		$board = sanitize_key( substr( $arg, 8 ) );
	}
	if ( 0 === strpos( $arg, '--per-page=' ) ) {
		$per_page = max( 1, min( 100, absint( substr( $arg, 11 ) ) ) );
	}
}

do_action( 'rest_api_init' );

$request = new \WP_REST_Request( 'GET', '/mma-future/v1/rankings' );
$request->set_param( 'board', $board );
$request->set_param( 'page', 1 );
$request->set_param( 'per_page', $per_page );
$request->set_param( 'include_breakdown', true );

$response = rest_do_request( $request );
$status   = $response->get_status();
$data     = $response->get_data();
$items    = is_array( $data['items'] ?? null ) ? $data['items'] : array();

$out = array(
	'ok'                    => 200 === $status && ! empty( $data['active_ranking_run_id'] ) && count( $items ) > 0,
	'status'                => $status,
	'board'                 => $data['board'] ?? $board,
	'active_ranking_run_id' => $data['active_ranking_run_id'] ?? null,
	'formula_version'       => $data['formula_version'] ?? null,
	'total'                 => $data['total'] ?? 0,
	'total_pages'           => $data['total_pages'] ?? 0,
	'items_count'           => count( $items ),
	'first_item'            => $items[0] ?? null,
);

echo wp_json_encode( $out, JSON_PRETTY_PRINT ) . PHP_EOL;
exit( ! empty( $out['ok'] ) ? 0 : 1 );
