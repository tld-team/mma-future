<?php
/**
 * Read-only Formula v1.4 calibration and active-run comparison report.
 *
 * Usage: php tests/report-ranking-v14-calibration.php --run-id=13
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

$cli_db_host = getenv( 'MMAF_CLI_DB_HOST' );
if ( is_string( $cli_db_host ) && '' !== $cli_db_host && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', $cli_db_host );
}
$cli_db_user = getenv( 'MMAF_CLI_DB_USER' );
if ( false !== $cli_db_user && ! defined( 'DB_USER' ) ) {
	define( 'DB_USER', (string) $cli_db_user );
}
$cli_db_password = getenv( 'MMAF_CLI_DB_PASSWORD' );
if ( false !== $cli_db_password && ! defined( 'DB_PASSWORD' ) ) {
	define( 'DB_PASSWORD', '__EMPTY__' === $cli_db_password ? '' : (string) $cli_db_password );
}
$suppress_db_host_redefine = static function ( int $errno, string $errstr ): bool {
	return E_WARNING === $errno && (
		false !== strpos( $errstr, 'Constant DB_HOST already defined' )
		|| false !== strpos( $errstr, 'Constant DB_USER already defined' )
		|| false !== strpos( $errstr, 'Constant DB_PASSWORD already defined' )
	);
};
set_error_handler( $suppress_db_host_redefine );
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
restore_error_handler();

use MMAF\DataEngine\Migrations\Schema;

global $wpdb;
$tables = Schema::table_names();
$run_id = 0;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--run-id=' ) ) {
		$run_id = max( 0, (int) substr( $arg, 9 ) );
	}
}
if ( $run_id <= 0 ) {
	$run_id = (int) $wpdb->get_var( "SELECT id FROM {$tables['ranking_runs']} WHERE formula_version = 'v1.4' AND status = 'completed' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['ranking_runs']} WHERE id = %d LIMIT 1", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( ! $run || 'v1.4' !== (string) $run['formula_version'] ) {
	fwrite( STDERR, "Completed Formula v1.4 run not found.\n" );
	exit( 1 );
}

$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT r.*, f.display_name FROM {$tables['ranking_snapshots']} r LEFT JOIN {$tables['fighters']} f ON f.id = r.fighter_id WHERE r.ranking_run_id = %d AND r.board_key = 'overall' ORDER BY r.rank_position ASC",
		$run_id
	),
	ARRAY_A
); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$active_run = $wpdb->get_row( "SELECT * FROM {$tables['ranking_runs']} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$active_rows = array();
if ( $active_run ) {
	foreach ( $wpdb->get_results( "SELECT fighter_id, rank_position, total_score FROM {$tables['ranking_current']} WHERE board_key = 'overall'", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_rows[ (int) $row['fighter_id'] ] = $row;
	}
}

$adjusted = array_map( static fn( array $row ): float => (float) $row['raw_score'], $rows );
sort( $adjusted, SORT_NUMERIC );
$p01 = percentile( $adjusted, 0.01 );
$p99 = percentile( $adjusted, 0.99 );
$range = max( 1.0, $p99 - $p01 );
$raw_min = floor( $p01 - ( 0.05 * $range ) );
$raw_max = ceil( $p99 + ( 0.05 * $range ) );
$allowed_clamped = (int) floor( count( $adjusted ) * 0.01 );
while ( clamped_count( $adjusted, $raw_min, $raw_max ) > $allowed_clamped ) {
	$low = count( array_filter( $adjusted, static fn( float $value ): bool => $value <= $raw_min ) );
	$high = count( array_filter( $adjusted, static fn( float $value ): bool => $value >= $raw_max ) );
	if ( $low >= $high && $low > 0 ) {
		$raw_min -= 1.0;
	} elseif ( $high > 0 ) {
		$raw_max += 1.0;
	} else {
		break;
	}
}

$sample_distribution = array();
$changes = array();
foreach ( $rows as $row ) {
	$sample_key = (string) (int) $row['sample_size'] . '@' . number_format( (float) $row['confidence_score'], 3, '.', '' );
	$sample_distribution[ $sample_key ] = ( $sample_distribution[ $sample_key ] ?? 0 ) + 1;
	$previous = $active_rows[ (int) $row['fighter_id'] ] ?? null;
	$changes[] = array(
		'fighter_id' => (int) $row['fighter_id'],
		'display_name' => (string) $row['display_name'],
		'old_rank' => $previous ? (int) $previous['rank_position'] : null,
		'new_rank' => (int) $row['rank_position'],
		'movement' => $previous ? (int) $previous['rank_position'] - (int) $row['rank_position'] : null,
		'old_total' => $previous ? (float) $previous['total_score'] : null,
		'new_total' => (float) $row['total_score'],
	);
}
$moved = array_values( array_filter( $changes, static fn( array $row ): bool => null !== $row['movement'] ) );
usort( $moved, static fn( array $a, array $b ): int => abs( $b['movement'] ) <=> abs( $a['movement'] ) );
$notes = json_decode( (string) ( $run['notes'] ?? '' ), true );

$output = array(
	'run' => array( 'id' => (int) $run['id'], 'formula_version' => (string) $run['formula_version'], 'reference_date' => (string) $run['reference_date'] ),
	'active_run' => $active_run ? array( 'id' => (int) $active_run['id'], 'formula_version' => (string) $active_run['formula_version'] ) : null,
	'overall_fighters' => count( $rows ),
	'calibration' => array(
		'p01' => $p01,
		'p99' => $p99,
		'suggested_raw_min' => $raw_min,
		'suggested_raw_max' => $raw_max,
		'clamped_with_suggested_bounds' => clamped_count( $adjusted, $raw_min, $raw_max ),
		'allowed_clamped_count' => $allowed_clamped,
	),
	'current_bounds' => array(
		'zero_count' => count( array_filter( $rows, static fn( array $row ): bool => (float) $row['total_score'] <= 0.0 ) ),
		'hundred_count' => count( array_filter( $rows, static fn( array $row ): bool => (float) $row['total_score'] >= 100.0 ) ),
	),
	'top_20' => array_slice( $changes, 0, 20 ),
	'largest_rank_changes' => array_slice( $moved, 0, 20 ),
	'sample_confidence_distribution' => $sample_distribution,
	'eligibility_reasons' => is_array( $notes ) ? ( $notes['eligibility_reasons'] ?? array() ) : array(),
);

echo wp_json_encode( $output, JSON_PRETTY_PRINT ) . PHP_EOL;

function percentile( array $values, float $percentile ): float {
	if ( empty( $values ) ) {
		return 0.0;
	}
	$index = ( count( $values ) - 1 ) * $percentile;
	$lower = (int) floor( $index );
	$upper = (int) ceil( $index );
	if ( $lower === $upper ) {
		return (float) $values[ $lower ];
	}
	$weight = $index - $lower;
	return (float) $values[ $lower ] + ( ( (float) $values[ $upper ] - (float) $values[ $lower ] ) * $weight );
}

function clamped_count( array $values, float $min, float $max ): int {
	return count( array_filter( $values, static fn( float $value ): bool => $value <= $min || $value >= $max ) );
}
