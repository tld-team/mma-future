<?php
/**
 * Deterministic Formula v1.5, eligibility, serializer, and activation-contract checks.
 *
 * Usage: php tests/verify-formula-v15.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR );
}

global $wpdb;
if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
	$wpdb = (object) array( 'prefix' => 'wp_' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ): string {
		return (string) json_encode( $data );
	}
}

require_once dirname( __DIR__ ) . '/includes/Support/DateTime.php';
require_once dirname( __DIR__ ) . '/includes/Migrations/Schema.php';
require_once dirname( __DIR__ ) . '/includes/Repositories/RankingCurrentRepository.php';
require_once dirname( __DIR__ ) . '/includes/Repositories/RankingRunRepository.php';
require_once dirname( __DIR__ ) . '/includes/Services/AuditLogService.php';
require_once dirname( __DIR__ ) . '/includes/Services/Formula/FormulaV13.php';
require_once dirname( __DIR__ ) . '/includes/Services/Formula/FormulaV14.php';
require_once dirname( __DIR__ ) . '/includes/Services/Formula/FormulaV15.php';
require_once dirname( __DIR__ ) . '/includes/Services/Formula/FormulaRegistry.php';
require_once dirname( __DIR__ ) . '/includes/Services/EligibilityService.php';
require_once dirname( __DIR__ ) . '/includes/Services/RankingCalculatorService.php';
require_once dirname( __DIR__ ) . '/includes/Services/RankingActivationService.php';
require_once dirname( __DIR__ ) . '/includes/REST/AbstractRestController.php';

use MMAF\DataEngine\REST\AbstractRestController;
use MMAF\DataEngine\Services\EligibilityService;
use MMAF\DataEngine\Services\Formula\FormulaRegistry;
use MMAF\DataEngine\Services\Formula\FormulaV15;
use MMAF\DataEngine\Services\RankingActivationService;
use MMAF\DataEngine\Services\RankingCalculatorService;

final class VerifyFormulaV15RestHarness extends AbstractRestController {
	public function payload( array $row, ?string $fallback_formula_version = null ): array {
		return array(
			'formula_version' => $this->ranking_formula_version( $row, $fallback_formula_version ),
			'score' => $this->ranking_public_score( $row, $fallback_formula_version ),
			'raw_score' => $this->ranking_raw_public_score( $row ),
			'performance_raw_score' => $this->ranking_performance_raw_public_score( $row, $fallback_formula_version ),
			'confidence_score' => $this->ranking_confidence_public_score( $row, $fallback_formula_version ),
		);
	}
}

$formula = new FormulaV15();
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

$assertTrue = static function ( string $name, bool $condition, $actual = null ) use ( &$failed, &$results ): void {
	$results[ $name ] = array( 'expected' => true, 'actual' => null === $actual ? $condition : $actual, 'passed' => $condition );
	if ( ! $condition ) {
		$failed[] = $name;
	}
};

$assertThrows = static function ( string $name, callable $callback ) use ( &$failed, &$results ): void {
	try {
		$callback();
		$results[ $name ] = array( 'expected' => 'exception', 'actual' => 'none', 'passed' => false );
		$failed[] = $name;
	} catch ( \Throwable $error ) {
		$results[ $name ] = array( 'expected' => 'exception', 'actual' => get_class( $error ), 'passed' => true );
	}
};

$assert( 'registry_current_version', FormulaV15::VERSION, FormulaRegistry::current_version() );
$assertTrue( 'registry_direct_scores', FormulaRegistry::uses_direct_scores( FormulaV15::VERSION ) );
$assertTrue( 'registry_legacy_normalized_v14', FormulaRegistry::uses_normalized_scores( 'v1.4' ) );

$assert( 'base_win', 0.5, $formula->base_record_points( 'win' ) );
$assert( 'base_loss', -1.5, $formula->base_record_points( 'loss' ) );
$assert( 'base_draw', 0.0, $formula->base_record_points( 'draw' ) );
$assert( 'base_nc', 0.0, $formula->base_record_points( 'no_contest' ) );
$assert( 'finish_ko', 1.0, $formula->finish_points( 'win', 'ko_tko' ) );
$assert( 'finish_submission', 1.0, $formula->finish_points( 'win', 'submission' ) );
$assert( 'finish_decision', 0.0, $formula->finish_points( 'win', 'decision' ) );
$assert( 'finish_dq', 0.0, $formula->finish_points( 'win', 'dq' ) );
$assert( 'finish_unknown', 0.0, $formula->finish_points( 'win', 'unknown' ) );
$assert( 'finish_non_win_na', 0.0, $formula->finish_points( 'loss', 'unknown' ) );
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
$stats = array( 'wins' => 5, 'losses' => 2, 'pro_fights_count' => 7, 'last_fight_date' => '2025-12-22' );

$evaluation_6 = $eligibility->evaluate( $fighter, $stats, '2026-06-22' );
$assert( 'inactivity_6_months', 6, $evaluation_6['inactivity_months'] );
$assert( 'inactivity_6_eligible', true, $evaluation_6['eligible'] );

$stats['last_fight_date'] = '2025-06-22';
$evaluation_12 = $eligibility->evaluate( $fighter, $stats, '2026-06-22' );
$assert( 'inactivity_12_months', 12, $evaluation_12['inactivity_months'] );
$assert( 'inactivity_12_eligible', true, $evaluation_12['eligible'] );

$stats['last_fight_date'] = '2024-12-22';
$evaluation_18 = $eligibility->evaluate( $fighter, $stats, '2026-06-22' );
$assert( 'inactivity_18_months', 18, $evaluation_18['inactivity_months'] );
$assert( 'inactivity_18_eligible', true, $evaluation_18['eligible'] );

$stats['last_fight_date'] = '2024-06-23';
$evaluation_day_before_cutoff = $eligibility->evaluate( $fighter, $stats, '2026-06-22' );
$assert( 'day_before_24_month_cutoff_eligible', true, $evaluation_day_before_cutoff['eligible'] );

$stats['last_fight_date'] = '2024-06-22';
$evaluation_exact_cutoff = $eligibility->evaluate( $fighter, $stats, '2026-06-22' );
$assert( 'exact_24_month_cutoff_ineligible', false, $evaluation_exact_cutoff['eligible'] );
$assertTrue( 'exact_24_month_reason', in_array( 'ineligible_inactive_24_months', $evaluation_exact_cutoff['reasons'], true ), $evaluation_exact_cutoff['reasons'] );

$stats['last_fight_date'] = '2024-06-21';
$evaluation_after_cutoff = $eligibility->evaluate( $fighter, $stats, '2026-06-22' );
$assert( 'after_24_month_cutoff_ineligible', false, $evaluation_after_cutoff['eligible'] );

$stats['last_fight_date'] = '2023-01-01';
$evaluation_scheduled_ignored = $eligibility->evaluate( $fighter, $stats, '2026-06-22' );
$assert( 'scheduled_bout_ignored_old_last_fight_stays_ineligible', false, $evaluation_scheduled_ignored['eligible'] );

$stats['last_fight_date'] = '2026-06-01';
$evaluation_returned_after_new_fight = $eligibility->evaluate( $fighter, $stats, '2026-06-22' );
$assert( 'new_held_bout_returns_fighter_to_eligibility', true, $evaluation_returned_after_new_fight['eligible'] );

$calculator = new RankingCalculatorService();
$score_fighter = new ReflectionMethod( RankingCalculatorService::class, 'score_fighter' );
$score_fighter->setAccessible( true );
$sort_ranked = new ReflectionMethod( RankingCalculatorService::class, 'sort_ranked' );
$sort_ranked->setAccessible( true );

$base_fighter = array( 'id' => 101 );
$base_stats = array(
	'id' => 1,
	'wins' => 1,
	'losses' => 0,
	'draws' => 0,
	'nc' => 0,
	'pro_fights_count' => 1,
	'finish_rate' => 0.0,
	'last_fight_date' => '2026-06-01',
	'calculated_at' => '2026-06-22 00:00:00',
);
$base_eligibility = $eligibility->evaluate(
	array_merge( $fighter, array( 'date_of_birth' => '2000-06-22', 'birth_year' => 2000 ) ),
	array( 'wins' => 1, 'losses' => 0, 'pro_fights_count' => 1, 'last_fight_date' => '2026-06-01' ),
	'2026-06-22'
);

$missing_prefight_result = $score_fighter->invoke(
	$calculator,
	$base_fighter,
	$base_stats,
	$base_eligibility,
	array(
		array(
			'bout_id' => 11,
			'event_id' => 21,
			'event_date' => '2026-06-01',
			'opponent_fighter_id' => 202,
			'result_for_fighter' => 'win',
			'method_category' => 'decision',
			'prefight_wins' => 1,
			'prefight_losses' => 0,
			'prefight_draws' => 0,
			'prefight_nc' => 0,
			'prefight_record_raw' => '1-0-0',
			'opponent_prefight_wins' => null,
			'opponent_prefight_losses' => null,
			'opponent_prefight_draws' => null,
			'opponent_prefight_nc' => null,
			'opponent_prefight_record_raw' => null,
			'opponent_prefight_diff' => null,
		),
	),
	array( 'tapology' ),
	'2026-06-22 00:00:00'
);

$assert( 'missing_prefight_direct_score_no_correction', 0.5, $missing_prefight_result['breakdown']['total_score'] );
$assert( 'missing_prefight_raw_equals_total', 0.5, $missing_prefight_result['breakdown']['raw_score'] );
$assert( 'missing_prefight_sample_size', 1, $missing_prefight_result['breakdown']['sample_size'] );
$assertTrue( 'missing_prefight_warning_present', in_array( 'missing_prefight_record', $missing_prefight_result['warnings'], true ), $missing_prefight_result['warnings'] );
$assertTrue( 'missing_prefight_quality_flag_present', in_array( 'missing_prefight_records', $missing_prefight_result['quality_flags'], true ), $missing_prefight_result['quality_flags'] );
$assert( 'missing_prefight_source_summary_count', 1, $missing_prefight_result['source_summary']['prefight_records_missing_count'] );
$assert( 'missing_method_count_zero', 0, $missing_prefight_result['source_summary']['method_category_missing_count'] );
$assertTrue( 'breakdown_has_no_confidence_score', ! array_key_exists( 'confidence_score', $missing_prefight_result['breakdown'] ), array_keys( $missing_prefight_result['breakdown'] ) );
$assertTrue( 'breakdown_has_no_normalized_score', ! array_key_exists( 'normalized_score', $missing_prefight_result['breakdown'] ), array_keys( $missing_prefight_result['breakdown'] ) );
$assertTrue( 'breakdown_has_no_performance_raw_score', ! array_key_exists( 'performance_raw_score', $missing_prefight_result['breakdown'] ), array_keys( $missing_prefight_result['breakdown'] ) );
$assertTrue( 'breakdown_has_no_raw_before_confidence', ! array_key_exists( 'raw_score_before_confidence', $missing_prefight_result['breakdown'] ), array_keys( $missing_prefight_result['breakdown'] ) );
$assertTrue( 'source_summary_has_no_confidence_score', ! array_key_exists( 'confidence_score', $missing_prefight_result['source_summary'] ), array_keys( $missing_prefight_result['source_summary'] ) );
$assertTrue( 'source_summary_has_no_raw_score', ! array_key_exists( 'raw_score', $missing_prefight_result['source_summary'] ), array_keys( $missing_prefight_result['source_summary'] ) );
$assertTrue( 'source_summary_has_no_normalized_score', ! array_key_exists( 'normalized_score', $missing_prefight_result['source_summary'] ), array_keys( $missing_prefight_result['source_summary'] ) );
$assertTrue( 'source_summary_has_no_performance_raw', ! array_key_exists( 'performance_raw_score', $missing_prefight_result['source_summary'] ), array_keys( $missing_prefight_result['source_summary'] ) );

$same_score_single = $missing_prefight_result['breakdown'];
$same_score_multi = $score_fighter->invoke(
	$calculator,
	array( 'id' => 102 ),
	array_merge( $base_stats, array( 'id' => 2, 'wins' => 3, 'pro_fights_count' => 3 ) ),
	$eligibility->evaluate(
		array_merge( $fighter, array( 'date_of_birth' => '1995-06-22', 'birth_year' => 1995 ) ),
		array( 'wins' => 3, 'losses' => 0, 'pro_fights_count' => 3, 'last_fight_date' => '2026-06-01' ),
		'2026-06-22'
	),
	array(
		array(
			'bout_id' => 12,
			'event_id' => 22,
			'event_date' => '2026-01-01',
			'opponent_fighter_id' => 301,
			'result_for_fighter' => 'win',
			'method_category' => 'decision',
			'prefight_wins' => 1,
			'prefight_losses' => 0,
			'prefight_draws' => 0,
			'prefight_nc' => 0,
			'prefight_record_raw' => '1-0-0',
			'opponent_prefight_wins' => 2,
			'opponent_prefight_losses' => 3,
			'opponent_prefight_draws' => 0,
			'opponent_prefight_nc' => 0,
			'opponent_prefight_record_raw' => '2-3-0',
			'opponent_prefight_diff' => -1,
		),
		array(
			'bout_id' => 13,
			'event_id' => 23,
			'event_date' => '2026-02-01',
			'opponent_fighter_id' => 302,
			'result_for_fighter' => 'win',
			'method_category' => 'decision',
			'prefight_wins' => 2,
			'prefight_losses' => 0,
			'prefight_draws' => 0,
			'prefight_nc' => 0,
			'prefight_record_raw' => '2-0-0',
			'opponent_prefight_wins' => 4,
			'opponent_prefight_losses' => 5,
			'opponent_prefight_draws' => 0,
			'opponent_prefight_nc' => 0,
			'opponent_prefight_record_raw' => '4-5-0',
			'opponent_prefight_diff' => -1,
		),
		array(
			'bout_id' => 14,
			'event_id' => 24,
			'event_date' => '2026-03-01',
			'opponent_fighter_id' => 303,
			'result_for_fighter' => 'win',
			'method_category' => 'decision',
			'prefight_wins' => 3,
			'prefight_losses' => 0,
			'prefight_draws' => 0,
			'prefight_nc' => 0,
			'prefight_record_raw' => '3-0-0',
			'opponent_prefight_wins' => 1,
			'opponent_prefight_losses' => 2,
			'opponent_prefight_draws' => 0,
			'opponent_prefight_nc' => 0,
			'opponent_prefight_record_raw' => '1-2-0',
			'opponent_prefight_diff' => -1,
		),
	),
	array( 'tapology' ),
	'2026-06-22 00:00:00'
);
$assert( 'same_score_different_sample_score', $same_score_single['total_score'], $same_score_multi['breakdown']['total_score'] );
$assert( 'same_score_different_sample_sample_size', 3, $same_score_multi['breakdown']['sample_size'] );

$negative_score = $score_fighter->invoke(
	$calculator,
	array( 'id' => 103 ),
	array_merge( $base_stats, array( 'id' => 3, 'wins' => 0, 'losses' => 1, 'pro_fights_count' => 1 ) ),
	$eligibility->evaluate(
		array_merge( $fighter, array( 'date_of_birth' => '1992-06-22', 'birth_year' => 1992 ) ),
		array( 'wins' => 0, 'losses' => 1, 'pro_fights_count' => 1, 'last_fight_date' => '2026-06-01' ),
		'2026-06-22'
	),
	array(
		array(
			'bout_id' => 15,
			'event_id' => 25,
			'event_date' => '2026-06-01',
			'opponent_fighter_id' => 304,
			'result_for_fighter' => 'loss',
			'method_category' => 'dq',
			'prefight_wins' => 0,
			'prefight_losses' => 0,
			'prefight_draws' => 0,
			'prefight_nc' => 0,
			'prefight_record_raw' => '0-0-0',
			'opponent_prefight_wins' => 0,
			'opponent_prefight_losses' => 21,
			'opponent_prefight_draws' => 0,
			'opponent_prefight_nc' => 0,
			'opponent_prefight_record_raw' => '0-21-0',
			'opponent_prefight_diff' => -21,
		),
	),
	array( 'tapology' ),
	'2026-06-22 00:00:00'
);
$assert( 'negative_direct_score_supported', -12.5, $negative_score['breakdown']['total_score'] );

$positive_items = array();
for ( $i = 0; $i < 9; $i++ ) {
	$positive_items[] = array(
		'bout_id' => 100 + $i,
		'event_id' => 200 + $i,
		'event_date' => '2025-0' . ( ( $i % 9 ) + 1 ) . '-01',
		'opponent_fighter_id' => 400 + $i,
		'result_for_fighter' => 'win',
		'method_category' => 'ko_tko',
		'prefight_wins' => $i,
		'prefight_losses' => 0,
		'prefight_draws' => 0,
		'prefight_nc' => 0,
		'prefight_record_raw' => $i . '-0-0',
		'opponent_prefight_wins' => 150 + $i,
		'opponent_prefight_losses' => 1,
		'opponent_prefight_draws' => 0,
		'opponent_prefight_nc' => 0,
		'opponent_prefight_record_raw' => ( 150 + $i ) . '-1-0',
		'opponent_prefight_diff' => 149 + $i,
	);
}
$over_100_score = $score_fighter->invoke(
	$calculator,
	array( 'id' => 104 ),
	array_merge( $base_stats, array( 'id' => 4, 'wins' => 9, 'losses' => 0, 'pro_fights_count' => 9 ) ),
	$eligibility->evaluate(
		array_merge( $fighter, array( 'date_of_birth' => '2003-06-22', 'birth_year' => 2003 ) ),
		array( 'wins' => 9, 'losses' => 0, 'pro_fights_count' => 9, 'last_fight_date' => '2026-06-01' ),
		'2026-06-22'
	),
	$positive_items,
	array( 'tapology' ),
	'2026-06-22 00:00:00'
);
$assertTrue( 'over_100_direct_score_supported', $over_100_score['breakdown']['total_score'] > 100.0, $over_100_score['breakdown']['total_score'] );

$ranked = array(
	array(
		'fighter' => array( 'id' => 10 ),
		'stats' => array( 'wins' => 5, 'finish_rate' => 0.9, 'last_fight_date' => '2026-06-01' ),
		'eligibility' => array( 'age' => 18 ),
		'total_score' => 20.0,
		'raw_score' => 999.0,
		'confidence_score' => 999.0,
	),
	array(
		'fighter' => array( 'id' => 20 ),
		'stats' => array( 'wins' => 6, 'finish_rate' => 0.1, 'last_fight_date' => '2026-03-01' ),
		'eligibility' => array( 'age' => 34 ),
		'total_score' => 20.0,
		'raw_score' => -999.0,
		'confidence_score' => 1.0,
	),
	array(
		'fighter' => array( 'id' => 30 ),
		'stats' => array( 'wins' => 6, 'finish_rate' => 0.8, 'last_fight_date' => '2026-04-01' ),
		'eligibility' => array( 'age' => 34 ),
		'total_score' => 20.0,
		'raw_score' => -500.0,
		'confidence_score' => 2.0,
	),
	array(
		'fighter' => array( 'id' => 40 ),
		'stats' => array( 'wins' => 6, 'finish_rate' => 0.8, 'last_fight_date' => '2025-01-01' ),
		'eligibility' => array( 'age' => 25 ),
		'total_score' => 20.0,
		'raw_score' => 123.0,
		'confidence_score' => 3.0,
	),
	array(
		'fighter' => array( 'id' => 50 ),
		'stats' => array( 'wins' => 6, 'finish_rate' => 0.8, 'last_fight_date' => '2026-02-01' ),
		'eligibility' => array( 'age' => 25 ),
		'total_score' => 20.0,
		'raw_score' => 124.0,
		'confidence_score' => 4.0,
	),
	array(
		'fighter' => array( 'id' => 5 ),
		'stats' => array( 'wins' => 6, 'finish_rate' => 0.8, 'last_fight_date' => '2026-02-01' ),
		'eligibility' => array( 'age' => 25 ),
		'total_score' => 20.0,
		'raw_score' => 125.0,
		'confidence_score' => 5.0,
	),
	array(
		'fighter' => array( 'id' => 60 ),
		'stats' => array( 'wins' => 1, 'finish_rate' => 0.0, 'last_fight_date' => '2020-01-01' ),
		'eligibility' => array( 'age' => 99 ),
		'total_score' => 21.0,
		'raw_score' => -1.0,
		'confidence_score' => 0.0,
	),
);
$sort_ranked->invokeArgs( $calculator, array( &$ranked ) );
$assert( 'tie_break_order', '60,5,50,40,30,20,10', implode( ',', array_map( static fn( array $item ): int => (int) $item['fighter']['id'], $ranked ) ) );

$rest = new VerifyFormulaV15RestHarness();
$legacy_payload = $rest->payload(
	array(
		'formula_version' => 'v1.4',
		'total_score' => 75.0,
		'raw_score' => 2.0,
		'normalized_score' => 75.0,
		'confidence_score' => 80.0,
		'breakdown_json' => wp_json_encode( array( 'performance_raw_score' => 3.0 ) ),
	),
	'v1.4'
);
$assert( 'legacy_payload_score', 75.0, $legacy_payload['score'] );
$assert( 'legacy_payload_raw', 2.0, $legacy_payload['raw_score'] );
$assert( 'legacy_payload_performance', 3.0, $legacy_payload['performance_raw_score'] );
$assert( 'legacy_payload_confidence', 80.0, $legacy_payload['confidence_score'] );

$v15_payload = $rest->payload(
	array(
		'formula_version' => FormulaV15::VERSION,
		'total_score' => -12.5,
		'raw_score' => -12.5,
		'normalized_score' => null,
		'confidence_score' => null,
		'breakdown_json' => wp_json_encode( array( 'total_score' => -12.5 ) ),
	),
	FormulaV15::VERSION
);
$assert( 'v15_payload_score', -12.5, $v15_payload['score'] );
$assert( 'v15_payload_raw_alias', -12.5, $v15_payload['raw_score'] );
$assert( 'v15_payload_performance_absent', null, $v15_payload['performance_raw_score'] );
$assert( 'v15_payload_confidence_absent', null, $v15_payload['confidence_score'] );

$legacy_fallback_payload = $rest->payload(
	array(
		'formula_version' => 'v1.3',
		'total_score' => 5.0,
		'raw_score' => 5.0,
		'normalized_score' => null,
		'confidence_score' => 60.0,
		'breakdown_json' => wp_json_encode( array( 'raw_score_before_confidence' => 5.5 ) ),
	),
	'v1.3'
);
$assertTrue( 'legacy_fallback_normalized_score_generated', is_float( $legacy_fallback_payload['score'] ) && $legacy_fallback_payload['score'] > 0.0, $legacy_fallback_payload['score'] );

$activation_reflection = new ReflectionClass( RankingActivationService::class );
$activation_service = $activation_reflection->newInstanceWithoutConstructor();
$validate_contract = new ReflectionMethod( RankingActivationService::class, 'validate_snapshot_score_contract' );
$validate_contract->setAccessible( true );
$validate_contract->invoke(
	$activation_service,
	array(
		'total_score' => '-12.500',
		'raw_score' => '-12.500',
		'normalized_score' => null,
		'confidence_score' => null,
	),
	FormulaV15::VERSION
);
$results['activation_v15_contract_accepts_negative_direct_score'] = array( 'expected' => true, 'actual' => true, 'passed' => true );

$assertThrows(
	'activation_v15_rejects_raw_total_mismatch',
	static function () use ( $validate_contract, $activation_service ): void {
		$validate_contract->invoke(
			$activation_service,
			array(
				'total_score' => '10.000',
				'raw_score' => '9.998',
				'normalized_score' => null,
				'confidence_score' => null,
			),
			FormulaV15::VERSION
		);
	}
);

$assertThrows(
	'activation_v15_rejects_legacy_confidence_fields',
	static function () use ( $validate_contract, $activation_service ): void {
		$validate_contract->invoke(
			$activation_service,
			array(
				'total_score' => '10.000',
				'raw_score' => '10.000',
				'normalized_score' => '10.000',
				'confidence_score' => null,
			),
			FormulaV15::VERSION
		);
	}
);

echo json_encode( array( 'ok' => empty( $failed ), 'results' => $results, 'failed' => $failed ), JSON_PRETTY_PRINT ) . PHP_EOL;
exit( empty( $failed ) ? 0 : 1 );
