<?php
/**
 * Read-only current Formula boundary checks.
 *
 * Usage: php tools/verify-formula-v12-boundaries.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR );
}

require_once dirname( __DIR__ ) . '/includes/Services/Formula/FormulaV12.php';

use MMAF\DataEngine\Services\Formula\FormulaV12;

$formula = new FormulaV12();
$cases   = array(
	'base_win'         => array( 'method' => 'base_record_points', 'input' => 'win', 'expected' => 0.5 ),
	'base_loss'        => array( 'method' => 'base_record_points', 'input' => 'loss', 'expected' => -1.5 ),
	'base_draw'        => array( 'method' => 'base_record_points', 'input' => 'draw', 'expected' => 0.0 ),
	'base_nc'          => array( 'method' => 'base_record_points', 'input' => 'no_contest', 'expected' => 0.0 ),
	'finish_ko_tko'    => array( 'method' => 'finish_points', 'input' => array( 'win', 'ko_tko' ), 'expected' => 1.0 ),
	'finish_submission'=> array( 'method' => 'finish_points', 'input' => array( 'win', 'submission' ), 'expected' => 1.0 ),
	'finish_decision'  => array( 'method' => 'finish_points', 'input' => array( 'win', 'decision' ), 'expected' => 0.0 ),
	'finish_unknown'   => array( 'method' => 'finish_points', 'input' => array( 'win', 'unknown' ), 'expected' => 0.0 ),
	'finish_missing'   => array( 'method' => 'finish_points', 'input' => array( 'win', null ), 'expected' => 0.0 ),
	'age_24'           => array( 'method' => 'age_adjustment_points', 'input' => 24, 'expected' => 1.0 ),
	'age_25'           => array( 'method' => 'age_adjustment_points', 'input' => 25, 'expected' => 0.0 ),
	'age_29'           => array( 'method' => 'age_adjustment_points', 'input' => 29, 'expected' => 0.0 ),
	'age_30'           => array( 'method' => 'age_adjustment_points', 'input' => 30, 'expected' => -1.0 ),
	'age_34'           => array( 'method' => 'age_adjustment_points', 'input' => 34, 'expected' => -1.0 ),
	'win_missing'      => array( 'method' => 'opponent_differential_points_for_win', 'input' => null, 'expected' => 0.0 ),
	'win_minus_11'     => array( 'method' => 'opponent_differential_points_for_win', 'input' => -11, 'expected' => -2.0 ),
	'win_minus_8'      => array( 'method' => 'opponent_differential_points_for_win', 'input' => -8, 'expected' => 0.0 ),
	'win_zero'         => array( 'method' => 'opponent_differential_points_for_win', 'input' => 0, 'expected' => 1.0 ),
	'win_10'           => array( 'method' => 'opponent_differential_points_for_win', 'input' => 10, 'expected' => 1.0 ),
	'win_11'           => array( 'method' => 'opponent_differential_points_for_win', 'input' => 11, 'expected' => 3.0 ),
	'win_30'           => array( 'method' => 'opponent_differential_points_for_win', 'input' => 30, 'expected' => 3.0 ),
	'win_31'           => array( 'method' => 'opponent_differential_points_for_win', 'input' => 31, 'expected' => 5.0 ),
	'win_60'           => array( 'method' => 'opponent_differential_points_for_win', 'input' => 60, 'expected' => 5.0 ),
	'win_61'           => array( 'method' => 'opponent_differential_points_for_win', 'input' => 61, 'expected' => 7.0 ),
	'win_100'          => array( 'method' => 'opponent_differential_points_for_win', 'input' => 100, 'expected' => 7.0 ),
	'win_101'          => array( 'method' => 'opponent_differential_points_for_win', 'input' => 101, 'expected' => 10.0 ),
	'loss_missing'     => array( 'method' => 'loss_quality_penalty_points', 'input' => null, 'expected' => 0.0 ),
	'loss_positive_20' => array( 'method' => 'loss_quality_penalty_points', 'input' => 20, 'expected' => 0.0 ),
	'loss_positive_1'  => array( 'method' => 'loss_quality_penalty_points', 'input' => 1, 'expected' => 0.0 ),
	'loss_zero'        => array( 'method' => 'loss_quality_penalty_points', 'input' => 0, 'expected' => -2.0 ),
	'loss_minus_10'    => array( 'method' => 'loss_quality_penalty_points', 'input' => -10, 'expected' => -2.0 ),
	'loss_minus_11'    => array( 'method' => 'loss_quality_penalty_points', 'input' => -11, 'expected' => -5.0 ),
	'loss_minus_20'    => array( 'method' => 'loss_quality_penalty_points', 'input' => -20, 'expected' => -5.0 ),
	'loss_minus_21'    => array( 'method' => 'loss_quality_penalty_points', 'input' => -21, 'expected' => -10.0 ),
);

$results = array();
$failed  = array();

foreach ( $cases as $name => $case ) {
	$method = (string) $case['method'];
	$input  = $case['input'];
	$actual = is_array( $input ) ? $formula->$method( ...$input ) : $formula->$method( $input );
	$passed = (float) $case['expected'] === (float) $actual;
	$results[ $name ] = array(
		'input'    => $case['input'],
		'expected' => (float) $case['expected'],
		'actual'   => $actual,
		'passed'   => $passed,
	);

	if ( ! $passed ) {
		$failed[] = $name;
	}
}

echo json_encode(
	array(
		'ok'      => empty( $failed ),
		'results' => $results,
		'failed'  => $failed,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( empty( $failed ) ? 0 : 1 );
