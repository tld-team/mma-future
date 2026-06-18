<?php
/**
 * Deterministic Formula v1.4 and eligibility checks.
 *
 * Usage: php tests/verify-formula-v14.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR );
}

require_once dirname( __DIR__ ) . '/includes/Services/Formula/FormulaV14.php';
require_once dirname( __DIR__ ) . '/includes/Services/EligibilityService.php';

use MMAF\DataEngine\Services\EligibilityService;
use MMAF\DataEngine\Services\Formula\FormulaV14;

$formula = new FormulaV14();
$failed  = array();
$results = array();

$assert = static function ( string $name, $expected, $actual, float $epsilon = 0.000001 ) use ( &$failed, &$results ): void {
	$passed = is_numeric( $expected ) && is_numeric( $actual )
		? abs( (float) $expected - (float) $actual ) <= $epsilon
		: $expected === $actual;
	$results[ $name ] = array( 'expected' => $expected, 'actual' => $actual, 'passed' => $passed );
	if ( ! $passed ) {
		$failed[] = $name;
	}
};

$assert( 'base_win', 0.5, $formula->base_record_points( 'win' ) );
$assert( 'base_loss', -1.5, $formula->base_record_points( 'loss' ) );
$assert( 'finish_ko', 1.0, $formula->finish_points( 'win', 'ko_tko' ) );
$assert( 'finish_submission', 1.0, $formula->finish_points( 'win', 'submission' ) );
$assert( 'finish_na', 0.0, $formula->finish_points( 'win', 'unknown' ) );
$assert( 'age_24', 1.0, $formula->age_adjustment_points( 24 ) );
$assert( 'age_25', 0.0, $formula->age_adjustment_points( 25 ) );
$assert( 'age_30', 0.0, $formula->age_adjustment_points( 30 ) );
$assert( 'age_31', -1.0, $formula->age_adjustment_points( 31 ) );

foreach ( array( -11 => -2.0, -10 => -2.0, -9 => 0.0, -1 => 0.0, 0 => 1.0, 10 => 1.0, 11 => 3.0, 30 => 3.0, 31 => 5.0, 60 => 5.0, 61 => 7.0, 100 => 7.0, 101 => 10.0 ) as $input => $expected ) {
	$assert( 'win_od_' . str_replace( '-', 'minus_', (string) $input ), $expected, $formula->opponent_differential_points_for_win( $input ) );
}

foreach ( array( 1 => 0.0, 0 => -2.0, -10 => -2.0, -11 => -5.0, -20 => -5.0, -21 => -10.0 ) as $input => $expected ) {
	$assert( 'loss_quality_' . str_replace( '-', 'minus_', (string) $input ), $expected, $formula->loss_quality_penalty_points( $input ) );
}

foreach ( array( 1 => 40.0, 2 => 60.0, 3 => 72.0, 4 => 80.0, 5 => 600.0 / 7.0, 9 => 1080.0 / 11.0, 10 => 100.0, 15 => 100.0 ) as $sample => $expected ) {
	$assert( 'confidence_sample_' . $sample, $expected, $formula->confidence_score( $sample, 0, 0 ) );
}
$assert( 'confidence_missing_prefight', 50.0, $formula->confidence_score( 2, 1, 0 ) );
$assert( 'confidence_na_method_no_penalty', 60.0, $formula->confidence_score( 2, 0, 2 ) );
$assert( 'adjusted_positive', 4.0, $formula->adjusted_raw_score( 10.0, 40.0 ) );
$assert( 'adjusted_negative', -4.0, $formula->adjusted_raw_score( -10.0, 40.0 ) );
$assert( 'normalized_floor', 0.0, $formula->normalized_score( -100.0 ) );
$assert( 'normalized_ceiling', 100.0, $formula->normalized_score( 100.0 ) );
$assert( 'normalized_monotonic', true, $formula->normalized_score( 1.0 ) > $formula->normalized_score( 0.0 ) );

$eligibility = new EligibilityService();
$fighter = array(
	'deleted_soft' => 0,
	'is_rankable' => 1,
	'status' => 'active',
	'rankability_status' => 'rankable',
	'has_valid_tapology_source' => 1,
	'tapology_source_count' => 1,
	'malformed_tapology_source_count' => 0,
	'gender' => 'male',
	'weight_class' => 'lightweight',
	'date_of_birth' => '2000-01-01',
	'birth_year' => 2000,
	'in_ufc' => 0,
);
$stats = array( 'wins' => 5, 'losses' => 2, 'pro_fights_count' => 7, 'last_fight_date' => '2024-06-19' );
$reference_date = '2026-06-18';
$evaluation = $eligibility->evaluate( $fighter, $stats, $reference_date );
$assert( 'five_wins_two_losses_eligible', true, $evaluation['eligible'] );

$stats['losses'] = 3;
$evaluation = $eligibility->evaluate( $fighter, $stats, $reference_date );
$assert( 'five_wins_three_losses_ineligible', false, $evaluation['eligible'] );
$assert( 'loss_limit_reason', true, in_array( 'ineligible_loss_limit', $evaluation['reasons'], true ) );

$stats['losses'] = 2;
$stats['last_fight_date'] = '2024-06-18';
$evaluation = $eligibility->evaluate( $fighter, $stats, $reference_date );
$assert( 'exactly_24_months_ineligible', false, $evaluation['eligible'] );
$assert( 'inactivity_reason', true, in_array( 'ineligible_inactive_24_months', $evaluation['reasons'], true ) );

$fighter['date_of_birth'] = '1991-06-18';
$fighter['birth_year'] = 1991;
$stats['last_fight_date'] = '2026-01-01';
$evaluation = $eligibility->evaluate( $fighter, $stats, $reference_date );
$assert( 'age_35_ineligible', false, $evaluation['eligible'] );
$assert( 'age_reason', true, in_array( 'ineligible_age_35_plus', $evaluation['reasons'], true ) );

echo json_encode( array( 'ok' => empty( $failed ), 'results' => $results, 'failed' => $failed ), JSON_PRETTY_PRINT ) . PHP_EOL;
exit( empty( $failed ) ? 0 : 1 );
