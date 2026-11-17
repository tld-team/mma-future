<?php
/** Deterministic Formula v1.9 checks: 0.25 finish points and the loss limit below five wins. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR ); }
if ( ! function_exists( '__' ) ) { function __( string $text, string $domain = '' ): string { return $text; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value ): string { return json_encode( $value ); } }

$base = dirname( __DIR__ ) . '/includes/';
foreach ( array( 'FormulaV13', 'FormulaV14', 'FormulaV15', 'FormulaV16', 'FormulaV17', 'FormulaV18', 'FormulaV19', 'FormulaRegistry' ) as $class ) {
	require_once $base . 'Services/Formula/' . $class . '.php';
}
require_once $base . 'Services/TrackingService.php';
require_once $base . 'Services/EligibilityService.php';
require_once $base . 'Services/Scoring/FinishSummary.php';

use MMAF\DataEngine\Services\EligibilityService;
use MMAF\DataEngine\Services\Formula\FormulaRegistry;
use MMAF\DataEngine\Services\Formula\FormulaV18;
use MMAF\DataEngine\Services\Formula\FormulaV19;
use MMAF\DataEngine\Services\Scoring\FinishSummary;

$failed = array(); $count = 0;
$check = static function ( string $name, $expected, $actual ) use ( &$failed, &$count ): void {
	++$count;
	$ok = is_float( $expected ) || is_float( $actual ) ? null !== $actual && null !== $expected && abs( (float) $expected - (float) $actual ) < 0.000001 : $expected === $actual;
	if ( ! $ok ) { $failed[ $name ] = array( 'expected' => $expected, 'actual' => $actual ); }
};

// Identity: same whole-career scope and evidence window as v1.8.
$check( 'version', 'v1.9', FormulaV19::VERSION );
$check( 'config_version', 'v1.9', FormulaV19::config()['formula_version'] );
$check( 'scope_same_as_v18', FormulaV18::SCOPE, FormulaV19::SCOPE );
$check( 'verification_window_same_as_v18', 30, FormulaV19::config()['scoring_scope']['max_verification_age_days'] );

// Only finishes, the loss table and the version label differ from v1.8.
$v18 = FormulaV18::config(); $v19 = FormulaV19::config();
foreach ( array( 'formula_version', 'finishes', 'eligibility' ) as $key ) { unset( $v18[ $key ], $v19[ $key ] ); }
$check( 'other_components_unchanged', $v18, $v19 );
$e18 = FormulaV18::config()['eligibility']; $e19 = FormulaV19::config()['eligibility'];
unset( $e18['loss_limit_by_wins'], $e19['loss_limit_by_wins'] );
$check( 'other_eligibility_rules_unchanged', $e18, $e19 );
$check( 'loss_table', array( 20 => 5, 15 => 4, 10 => 3, 5 => 2, 0 => 1 ), FormulaV19::config()['eligibility']['loss_limit_by_wins'] );

// Points.
$v19f = new FormulaV19(); $v18f = new FormulaV18();
$check( 'ko_tko_win', 0.25, $v19f->finish_points( 'win', 'ko_tko' ) );
$check( 'submission_win', 0.25, $v19f->finish_points( 'win', 'submission' ) );
$check( 'decision_win', 0.0, $v19f->finish_points( 'win', 'decision' ) );
$check( 'dq_win', 0.0, $v19f->finish_points( 'win', 'dq' ) );
$check( 'unknown_win', 0.0, $v19f->finish_points( 'win', null ) );
$check( 'finish_loss', 0.0, $v19f->finish_points( 'loss', 'ko_tko' ) );
$check( 'base_win', 0.5, $v19f->base_record_points( 'win' ) );
$check( 'base_loss', -1.5, $v19f->base_record_points( 'loss' ) );
$check( 'opponent_quality_unchanged', $v18f->opponent_differential_points_for_win( 14 ), $v19f->opponent_differential_points_for_win( 14 ) );
$check( 'loss_quality_unchanged', $v18f->loss_quality_penalty_points( -12 ), $v19f->loss_quality_penalty_points( -12 ) );
$check( 'age_unchanged', $v18f->age_adjustment_points( 31 ), $v19f->age_adjustment_points( 31 ) );
// A 20-3 career with 13 finishes, +21 opponent quality and age 31 (run 41 leader) moves from 38.5 to 28.75.
$career = static function ( FormulaV18 $f ): float {
	return 20 * $f->base_record_points( 'win' ) + 3 * $f->base_record_points( 'loss' ) + 7 * $f->finish_points( 'win', 'ko_tko' ) + 6 * $f->finish_points( 'win', 'submission' ) + 21 + $f->age_adjustment_points( 31 );
};
$check( 'v18_example_total', 38.5, $career( $v18f ) );
$check( 'v19_example_total', 28.75, $career( $v19f ) );

// v1.8 stays reproducible for its completed and active runs.
$check( 'v18_finish_still_one', 1.0, $v18f->finish_points( 'win', 'ko_tko' ) );
$check( 'v18_finish_config_still_one', 1.0, FormulaV18::config()['finishes']['submission'] );
$check( 'v18_loss_table_unchanged', array( 20 => 5, 15 => 4, 10 => 3, 5 => 2 ), FormulaV18::config()['eligibility']['loss_limit_by_wins'] );

// Registry.
$check( 'current_version', 'v1.9', FormulaRegistry::current_version() );
$check( 'current_config', FormulaV19::config(), FormulaRegistry::current_config() );
$check( 'config_for_v19', FormulaV19::config(), FormulaRegistry::config_for_version( 'v1.9' ) );
$check( 'config_for_v18', FormulaV18::config(), FormulaRegistry::config_for_version( 'v1.8' ) );
$check( 'activation_supported', true, in_array( 'v1.9', FormulaRegistry::activation_supported_versions(), true ) );
$check( 'direct_scores', true, FormulaRegistry::uses_direct_scores( 'v1.9' ) );
$check( 'career_versions', array( 'v1.8', 'v1.9' ), FormulaRegistry::career_versions() );
$check( 'career_v17', false, FormulaRegistry::is_career_version( 'v1.7' ) );
$check( 'career_null', false, FormulaRegistry::is_career_version( null ) );
$check( 'known_points_v17', true, FormulaRegistry::uses_known_points( 'v1.7' ) );
$check( 'known_points_v19', true, FormulaRegistry::uses_known_points( 'v1.9' ) );
$check( 'known_points_v16', false, FormulaRegistry::uses_known_points( 'v1.6' ) );
$check( 'assessments_v16', true, FormulaRegistry::has_assessments( 'v1.6' ) );
$check( 'assessments_v15', false, FormulaRegistry::has_assessments( 'v1.5' ) );
$check( 'career_formula_v19', FormulaV19::class, get_class( FormulaRegistry::career_formula( 'v1.9' ) ) );
$check( 'career_formula_v18', FormulaV18::class, get_class( FormulaRegistry::career_formula( 'v1.8' ) ) );
try { FormulaRegistry::career_formula( 'v1.7' ); $check( 'career_formula_rejects_v17', true, false ); } catch ( \InvalidArgumentException $e ) { $check( 'career_formula_rejects_v17', true, true ); }
$check( 'per_finish_v19', 0.25, FormulaRegistry::points_per_finish( 'v1.9' ) );
$check( 'per_finish_v18', 1.0, FormulaRegistry::points_per_finish( 'v1.8' ) );
$check( 'per_finish_v15', 1.0, FormulaRegistry::points_per_finish( 'v1.5' ) );
$check( 'per_finish_normalized', null, FormulaRegistry::points_per_finish( 'v1.4' ) );

// Loss limit. Career scope: wins/losses come from the accepted career record.
$fighter = array(
	'deleted_soft' => 0, 'is_rankable' => 1, 'status' => 'active', 'rankability_status' => 'rankable',
	'has_valid_tapology_source' => 1, 'tapology_source_count' => 1, 'malformed_tapology_source_count' => 0,
	'gender' => 'male', 'weight_class' => 'lightweight', 'date_of_birth' => '2000-01-01', 'in_ufc' => 0,
);
$limit = static function ( string $version, int $wins, int $losses ) use ( $fighter ): array {
	$record = array( 'wins' => $wins, 'losses' => $losses, 'draws' => 0, 'nc' => 0, 'pro_fights_count' => $wins + $losses );
	$stats = $record + array( 'last_fight_date' => '2026-10-01' );
	$evaluation = ( new EligibilityService( $version ) )->evaluate( $fighter, $stats, '2026-11-17', $record, true, $version );
	return array( 'passes' => $evaluation['loss_limit']['passes'], 'allowed' => $evaluation['loss_limit']['allowed_losses'], 'blocked' => in_array( 'ineligible_loss_limit', $evaluation['reasons'], true ) );
};
foreach ( array( array( 0, 1, true ), array( 1, 1, true ), array( 4, 1, true ), array( 0, 2, false ), array( 3, 2, false ), array( 4, 2, false ), array( 2, 10, false ), array( 5, 2, true ), array( 5, 3, false ), array( 9, 3, false ), array( 10, 3, true ), array( 20, 5, true ) ) as list( $wins, $losses, $passes ) ) {
	$result = $limit( 'v1.9', $wins, $losses );
	$check( "v19_{$wins}_{$losses}_passes", $passes, $result['passes'] );
	$check( "v19_{$wins}_{$losses}_blocked", ! $passes, $result['blocked'] );
}
$check( 'v19_under_five_allowed', 1, $limit( 'v1.9', 3, 0 )['allowed'] );
$check( 'v18_under_five_has_no_limit', null, $limit( 'v1.8', 2, 10 )['allowed'] );
$check( 'v18_under_five_still_passes', true, $limit( 'v1.8', 2, 10 )['passes'] );

// Finish summary uses the run's value per finish.
$bout = static fn( $result, $method ) => array( 'result_for_fighter' => $result, 'method_category' => $method );
$items = array( $bout( 'win', 'ko_tko' ), $bout( 'win', 'submission' ), $bout( 'win', 'decision' ), $bout( 'loss', 'ko_tko' ) );
$quarter = FinishSummary::from_breakdown( array( 'sample_size' => 4, 'finishes_points' => 0.5, 'per_fight_items' => $items ), 0.25 );
$check( 'summary_quarter_count', 2, $quarter['count'] ?? null );
$check( 'summary_quarter_points', 0.5, $quarter['points'] ?? null );
$check( 'summary_quarter_per_finish', 0.25, $quarter['points_per_finish'] ?? null );
$check( 'summary_rejects_one_point_total_for_v19', null, FinishSummary::from_breakdown( array( 'sample_size' => 4, 'finishes_points' => 2, 'per_fight_items' => $items ), 0.25 ) );
$check( 'summary_default_one_point', 2, FinishSummary::from_breakdown( array( 'sample_size' => 4, 'finishes_points' => 2, 'per_fight_items' => $items ) )['count'] ?? null );
$check( 'summary_rejects_zero_value', null, FinishSummary::from_breakdown( array( 'sample_size' => 4, 'finishes_points' => 0, 'per_fight_items' => $items ), 0.0 ) );

echo json_encode( array( 'ok' => ! $failed, 'checks' => $count, 'failed' => $failed ), JSON_PRETTY_PRINT ) . "\n";
exit( $failed ? 1 : 0 );
