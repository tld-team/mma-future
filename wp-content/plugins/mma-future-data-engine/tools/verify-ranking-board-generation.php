<?php
/**
 * Read-only smoke checks for ranking board row generation.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/verify-ranking-board-generation.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\RankingCalculatorService;

$eligible = array(
	array(
		'fighter' => array(
			'id'           => 10001,
			'gender'       => 'male',
			'weight_class' => 'lightweight',
		),
		'total_score' => 10.0,
		'breakdown' => array( 'total_score' => 10.0 ),
		'eligibility' => array( 'eligible' => true, 'age' => 24 ),
		'warnings' => array(),
		'source_summary' => array( 'generated_by' => 'board_generation_smoke' ),
	),
	array(
		'fighter' => array(
			'id'           => 10002,
			'gender'       => 'female',
			'weight_class' => 'Strawweight',
		),
		'total_score' => 9.0,
		'breakdown' => array( 'total_score' => 9.0 ),
		'eligibility' => array( 'eligible' => true, 'age' => 31 ),
		'warnings' => array(),
		'source_summary' => array( 'generated_by' => 'board_generation_smoke' ),
	),
);

$service = new RankingCalculatorService();
$method = new ReflectionMethod( RankingCalculatorService::class, 'rows_for_boards' );
$method->setAccessible( true );
$rows = $method->invoke( $service, $eligible );

$boards_by_fighter = array();
foreach ( $rows as $row ) {
	$fighter_id = (int) $row['fighter_id'];
	if ( ! isset( $boards_by_fighter[ $fighter_id ] ) ) {
		$boards_by_fighter[ $fighter_id ] = array();
	}
	$boards_by_fighter[ $fighter_id ][] = (string) $row['board_key'];
}

$checks = array(
	'male_lightweight_in_overall' => in_array( 'overall', $boards_by_fighter[10001] ?? array(), true ),
	'male_lightweight_in_male' => in_array( 'male', $boards_by_fighter[10001] ?? array(), true ),
	'male_lightweight_in_under_30' => in_array( 'under_30', $boards_by_fighter[10001] ?? array(), true ),
	'male_lightweight_in_lightweight' => in_array( 'lightweight', $boards_by_fighter[10001] ?? array(), true ),
	'male_lightweight_not_in_women_strawweight' => ! in_array( 'women_strawweight', $boards_by_fighter[10001] ?? array(), true ),
	'female_strawweight_in_overall' => in_array( 'overall', $boards_by_fighter[10002] ?? array(), true ),
	'female_strawweight_in_female' => in_array( 'female', $boards_by_fighter[10002] ?? array(), true ),
	'female_strawweight_in_over_30_to_34' => in_array( 'over_30_to_34', $boards_by_fighter[10002] ?? array(), true ),
	'female_strawweight_in_women_strawweight' => in_array( 'women_strawweight', $boards_by_fighter[10002] ?? array(), true ),
	'female_strawweight_not_in_strawweight_board' => ! in_array( 'strawweight', $boards_by_fighter[10002] ?? array(), true ),
);

$failed = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );

echo wp_json_encode(
	array(
		'ok' => empty( $failed ),
		'checks' => $checks,
		'failed' => $failed,
		'boards_by_fighter' => $boards_by_fighter,
		'rows_count' => count( $rows ),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( empty( $failed ) ? 0 : 1 );
