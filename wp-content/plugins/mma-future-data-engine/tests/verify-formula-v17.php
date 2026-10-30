<?php
/** Deterministic Formula v1.7 hybrid-score contract checks. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit( 'CLI only.' ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR ); }
global $wpdb;
if ( ! isset( $wpdb ) ) { $wpdb = (object) array( 'prefix' => 'wp_' ); }
if ( ! function_exists( '__' ) ) { function __( string $text, string $domain = '' ): string { return $text; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data ): string { return (string) json_encode( $data ); } }

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'Support/DateTime.php';
require_once $base . 'Migrations/Schema.php';
require_once $base . 'Repositories/RankingCurrentRepository.php';
require_once $base . 'Repositories/RankingRunRepository.php';
require_once $base . 'Services/AuditLogService.php';
foreach ( array( 'FormulaV13', 'FormulaV14', 'FormulaV15', 'FormulaV16', 'FormulaV17', 'FormulaRegistry' ) as $class ) {
	require_once $base . 'Services/Formula/' . $class . '.php';
}
require_once $base . 'Services/TrackingService.php';
require_once $base . 'Services/EligibilityService.php';
require_once $base . 'Services/RankingCalculatorService.php';
require_once $base . 'Services/Scoring/FinishSummary.php';
require_once $base . 'Services/Scoring/OpponentImpact.php';

use MMAF\DataEngine\Services\EligibilityService;
use MMAF\DataEngine\Services\Formula\FormulaRegistry;
use MMAF\DataEngine\Services\Formula\FormulaV17;
use MMAF\DataEngine\Services\RankingCalculatorService;
use MMAF\DataEngine\Services\Scoring\FinishSummary;
use MMAF\DataEngine\Services\Scoring\OpponentImpact;

$failed = array();
$results = array();
$assert = static function( string $name, $expected, $actual, float $epsilon = 0.000001 ) use ( &$failed, &$results ): void {
	$ok = is_numeric( $expected ) && is_numeric( $actual ) ? abs( (float) $expected - (float) $actual ) <= $epsilon : $expected === $actual;
	$results[ $name ] = array( 'expected' => $expected, 'actual' => $actual, 'passed' => $ok );
	if ( ! $ok ) { $failed[] = $name; }
};

$formula = new FormulaV17();
$assert( 'current_version', 'v1.7', FormulaRegistry::current_version() );
$assert( 'scope', 'professional_mma_hybrid_20260509_v1', FormulaV17::SCOPE );
$assert( 'period_start', '2026-05-09', FormulaV17::PERIOD_START );
$assert( 'frozen_win_weight', 0.5, FormulaV17::config()['base_record']['win'] );
$assert( 'frozen_loss_weight', -1.5, FormulaV17::config()['base_record']['loss'] );
$assert( 'historical_8_1', 2.5, $formula->historical_base_points( array( 'wins' => 8, 'losses' => 1 ) ) );
$assert( 'historical_10_0', 5.0, $formula->historical_base_points( array( 'wins' => 10, 'losses' => 0 ) ) );
$assert( 'zero_recent_allowed', 0, FormulaV17::config()['eligibility']['min_scoring_bouts'] );

$fighter = array(
	'id' => 7, 'deleted_soft' => 0, 'is_rankable' => 1, 'status' => 'active', 'rankability_status' => 'rankable',
	'tracking_mode' => 'normal', 'has_valid_tapology_source' => 1, 'tapology_source_count' => 1,
	'malformed_tapology_source_count' => 0, 'gender' => 'male', 'weight_class' => 'lightweight',
	'date_of_birth' => '1998-01-01', 'birth_year' => 1998, 'in_ufc' => 0,
);
$career = array( 'wins' => 10, 'losses' => 0, 'draws' => 0, 'nc' => 0, 'pro_fights_count' => 10 );
$period_stats = array( 'wins' => 0, 'losses' => 0, 'draws' => 0, 'nc' => 0, 'pro_fights_count' => 0, 'finish_rate' => null, 'last_fight_date' => '2025-01-01' );
$eligibility = ( new EligibilityService( FormulaV17::VERSION ) )->evaluate( $fighter, $period_stats, '2026-10-30', $career, true );
$assert( 'historical_only_eligible', true, $eligibility['eligible'] );
$assert( 'historical_only_recent_sample', 0, $eligibility['scoring_bouts_count'] );
$period_stats['last_fight_date'] = '2024-10-30';
$inactive = ( new EligibilityService( FormulaV17::VERSION ) )->evaluate( $fighter, $period_stats, '2026-10-30', $career, true );
$assert( 'exactly_24_months_inactive', true, in_array( 'ineligible_inactive_24_months', $inactive['reasons'], true ) );

$calculator = new RankingCalculatorService( FormulaV17::VERSION );
$score = new ReflectionMethod( RankingCalculatorService::class, 'score_fighter' );
$score->setAccessible( true );
$items = array(
	array( 'bout_id'=>1, 'event_id'=>1, 'event_date'=>'2026-05-09', 'opponent_fighter_id'=>11, 'result_for_fighter'=>'win', 'method_category'=>'ko_tko', 'opponent_prefight_diff'=>11, 'prefight_wins'=>8, 'prefight_losses'=>1, 'prefight_draws'=>0, 'prefight_nc'=>0, 'prefight_record_raw'=>'8-1-0', 'opponent_prefight_wins'=>12, 'opponent_prefight_losses'=>1, 'opponent_prefight_draws'=>0, 'opponent_prefight_nc'=>0, 'opponent_prefight_record_raw'=>'12-1-0' ),
	array( 'bout_id'=>2, 'event_id'=>2, 'event_date'=>'2026-06-01', 'opponent_fighter_id'=>12, 'result_for_fighter'=>'win', 'method_category'=>'decision', 'opponent_prefight_diff'=>0, 'prefight_wins'=>9, 'prefight_losses'=>1, 'prefight_draws'=>0, 'prefight_nc'=>0, 'prefight_record_raw'=>'9-1-0', 'opponent_prefight_wins'=>5, 'opponent_prefight_losses'=>5, 'opponent_prefight_draws'=>0, 'opponent_prefight_nc'=>0, 'opponent_prefight_record_raw'=>'5-5-0' ),
	array( 'bout_id'=>3, 'event_id'=>3, 'event_date'=>'2026-07-01', 'opponent_fighter_id'=>13, 'result_for_fighter'=>'loss', 'method_category'=>'unknown', 'opponent_prefight_diff'=>1, 'prefight_wins'=>10, 'prefight_losses'=>1, 'prefight_draws'=>0, 'prefight_nc'=>0, 'prefight_record_raw'=>'10-1-0', 'opponent_prefight_wins'=>6, 'opponent_prefight_losses'=>5, 'opponent_prefight_draws'=>0, 'opponent_prefight_nc'=>0, 'opponent_prefight_record_raw'=>'6-5-0' ),
);
$recent = $score->invoke( $calculator, $fighter, array( 'wins'=>2, 'losses'=>1, 'finish_rate'=>0.5, 'last_fight_date'=>'2026-07-01' ), array( 'age'=>28, 'warnings'=>array() ), $items, array( 'tapology' ), '2026-10-30 12:00:00' );
$add = new ReflectionMethod( RankingCalculatorService::class, 'add_historical_base' );
$add->setAccessible( true );
$hybrid = $add->invoke( $calculator, $recent, array( 'historical_record'=>array( 'wins'=>8, 'losses'=>1, 'draws'=>0, 'nc'=>0, 'pro_fights_count'=>9 ), 'period_record'=>array( 'wins'=>2, 'losses'=>1, 'draws'=>0, 'nc'=>0, 'pro_fights_count'=>3 ), 'career_record_used'=>array( 'wins'=>10, 'losses'=>2, 'draws'=>0, 'nc'=>0, 'pro_fights_count'=>12 ) ) );
$breakdown = $hybrid['breakdown'];
$assert( 'example_period_base', -0.5, $breakdown['period_base_points'] );
$assert( 'example_historical_base', 2.5, $breakdown['historical_base_points'] );
$assert( 'example_finish', 1.0, $breakdown['finishes_points'] );
$assert( 'example_opponent_quality', 4.0, $breakdown['opponent_differential_points'] );
$assert( 'example_total', 7.0, $breakdown['total_score'] );
$assert( 'unknown_loss_method_does_not_change_score', 7.0, $breakdown['known_points'] );
$diff_only = $items[0];
unset( $diff_only['opponent_prefight_wins'], $diff_only['opponent_prefight_losses'] );
$unverified_diff = $score->invoke( $calculator, $fighter, array( 'wins'=>1, 'losses'=>0, 'finish_rate'=>1.0, 'last_fight_date'=>'2026-05-09' ), array( 'age'=>28, 'warnings'=>array() ), array( $diff_only ), array( 'tapology' ), '2026-10-30 12:00:00' );
$assert( 'diff_without_prefight_record_scores_no_quality', 0.0, $unverified_diff['breakdown']['opponent_differential_points'] );
$assert( 'diff_without_prefight_record_is_missing', 1, $unverified_diff['source_summary']['prefight_records_missing_count'] );

$zero = FinishSummary::from_breakdown( array( 'per_fight_items'=>array(), 'sample_size'=>0, 'finishes_points'=>0 ) );
$assert( 'zero_recent_finish_count', 0, $zero['count'] ?? null );
$impact = OpponentImpact::from_breakdown( array( 'per_fight_items'=>array(), 'sample_size'=>0, 'opponent_differential_points'=>0, 'loss_quality_penalty_points'=>0 ) );
$assert( 'zero_recent_opponent_known', 0, $impact['known_record_bouts'] ?? null );

echo json_encode( array( 'ok' => empty( $failed ), 'results' => $results, 'failed' => $failed ), JSON_PRETTY_PRINT ) . PHP_EOL;
exit( empty( $failed ) ? 0 : 1 );
