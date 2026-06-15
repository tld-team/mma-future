<?php
/**
 * Synthetic MVP ranking contract checks. Performs no DB writes.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/verify-ranking-mvp-contract.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\EligibilityService;
use MMAF\DataEngine\Services\RankingCalculatorService;

$reference_date = '2026-06-14';
$eligibility = new EligibilityService();
$calculator = new RankingCalculatorService();
$results = array();

$record = static function ( string $name, bool $pass, string $detail = '' ) use ( &$results ): void {
	$results[ $name ] = array(
		'pass' => $pass,
		'detail' => $detail,
	);
};

$base_fighter = array(
	'id' => 10001,
	'display_name' => 'Synthetic Fighter',
	'status' => 'verified',
	'deleted_soft' => 0,
	'is_rankable' => 1,
	'rankability_status' => 'rankable',
	'in_ufc' => 0,
	'gender' => 'male',
	'weight_class' => 'lightweight',
	'date_of_birth' => '1996-06-14',
	'birth_year' => 1996,
	'has_valid_tapology_source' => 1,
	'tapology_source_count' => 1,
	'malformed_tapology_source_count' => 0,
);
$base_stats = array(
	'id' => 1,
	'wins' => 9,
	'losses' => 1,
	'draws' => 0,
	'nc' => 0,
	'pro_fights_count' => 10,
	'finish_rate' => 0.500,
	'last_fight_date' => '2026-01-15',
	'calculated_at' => '2026-01-16 00:00:00',
);

$evaluate = static function ( array $fighter_overrides = array(), array $stats_overrides = array() ) use ( $eligibility, $base_fighter, $base_stats, $reference_date ): array {
	$fighter = array_merge( $base_fighter, $fighter_overrides );
	$stats = array_merge( $base_stats, $stats_overrides );

	return $eligibility->evaluate( $fighter, $stats, $reference_date );
};

$record( 'eligibility_age_35_excluded', ! $evaluate( array( 'date_of_birth' => '1991-06-13', 'birth_year' => 1991 ) )['eligible'] );
$record( 'eligibility_inactivity_gt_24_months_excluded', ! $evaluate( array(), array( 'last_fight_date' => '2024-06-13' ) )['eligible'] );
$record( 'eligibility_in_ufc_excluded', ! $evaluate( array( 'in_ufc' => 1 ) )['eligible'] );
$record( 'eligibility_pro_fights_gt_30_excluded', ! $evaluate( array(), array( 'pro_fights_count' => 31 ) )['eligible'] );
$record( 'eligibility_5w_2l_excluded', ! $evaluate( array(), array( 'wins' => 5, 'losses' => 2, 'pro_fights_count' => 7 ) )['eligible'] );
$record( 'eligibility_10w_4l_excluded', ! $evaluate( array(), array( 'wins' => 10, 'losses' => 4, 'pro_fights_count' => 14 ) )['eligible'] );
$record( 'eligibility_15w_5l_excluded', ! $evaluate( array(), array( 'wins' => 15, 'losses' => 5, 'pro_fights_count' => 20 ) )['eligible'] );
$record( 'eligibility_20w_6l_excluded', ! $evaluate( array(), array( 'wins' => 20, 'losses' => 6, 'pro_fights_count' => 26 ) )['eligible'] );
$record( 'eligibility_missing_tapology_excluded', ! $evaluate( array( 'has_valid_tapology_source' => 0, 'tapology_source_count' => 0 ) )['eligible'] );
$record(
	'eligibility_provisional_without_tapology_may_exist_but_not_rank',
	! $evaluate(
		array(
			'status' => 'provisional',
			'is_rankable' => 0,
			'rankability_status' => 'pending_review',
			'has_valid_tapology_source' => 0,
			'tapology_source_count' => 0,
		)
	)['eligible']
);

$score_method = new ReflectionMethod( RankingCalculatorService::class, 'score_fighter' );
$score_method->setAccessible( true );

$scoring_item = static function ( array $overrides = array() ): array {
	return array_merge(
		array(
			'bout_id' => 20001,
			'event_id' => 30001,
			'event_date' => '2026-01-15',
			'opponent_fighter_id' => 10002,
			'result_for_fighter' => 'win',
			'method_category' => 'unknown',
			'prefight_wins' => 1,
			'prefight_losses' => 0,
			'prefight_draws' => 0,
			'prefight_nc' => 0,
			'prefight_record_raw' => '1-0',
			'opponent_prefight_wins' => 8,
			'opponent_prefight_losses' => 8,
			'opponent_prefight_draws' => 0,
			'opponent_prefight_nc' => 0,
			'opponent_prefight_record_raw' => '8-8',
			'opponent_prefight_diff' => 0,
		),
		$overrides
	);
};

$eligible_eval = $evaluate();
$unknown_method = $score_method->invoke( $calculator, $base_fighter, $base_stats, $eligible_eval, array( $scoring_item() ), array( 'tapology' ), '2026-06-14 00:00:00' );
$unknown_item = $unknown_method['breakdown']['per_fight_items'][0] ?? array();
$record( 'scoring_unknown_method_keeps_base_points', 0.5 === (float) ( $unknown_item['base_points'] ?? 0 ) );
$record( 'scoring_unknown_method_no_finish_bonus', 0.0 === (float) ( $unknown_item['finish_points'] ?? -1 ) );
$record( 'scoring_unknown_method_warning', in_array( 'missing_method_category', $unknown_item['warnings'] ?? array(), true ) );

$missing_prefight = $score_method->invoke(
	$calculator,
	$base_fighter,
	$base_stats,
	$eligible_eval,
	array(
		$scoring_item(
			array(
				'opponent_prefight_wins' => null,
				'opponent_prefight_losses' => null,
				'opponent_prefight_record_raw' => null,
				'opponent_prefight_diff' => null,
			)
		),
	),
	array( 'tapology' ),
	'2026-06-14 00:00:00'
);
$missing_item = $missing_prefight['breakdown']['per_fight_items'][0] ?? array();
$record( 'scoring_missing_prefight_does_not_crash_or_remove_base', 0.5 === (float) ( $missing_item['base_points'] ?? 0 ) );
$record( 'scoring_missing_prefight_zero_od', 0.0 === (float) ( $missing_item['opponent_diff_points'] ?? -1 ) );
$record( 'scoring_missing_prefight_warning', in_array( 'missing_prefight_record', $missing_item['warnings'] ?? array(), true ) );

$loss_strong = $score_method->invoke(
	$calculator,
	$base_fighter,
	$base_stats,
	$eligible_eval,
	array( $scoring_item( array( 'result_for_fighter' => 'loss', 'opponent_prefight_diff' => 20 ) ) ),
	array( 'tapology' ),
	'2026-06-14 00:00:00'
);
$record( 'scoring_loss_to_strong_opponent_no_positive_credit', 0.0 === (float) $loss_strong['breakdown']['loss_quality_penalty_points'] );

$sort_method = new ReflectionMethod( RankingCalculatorService::class, 'sort_ranked' );
$sort_method->setAccessible( true );
$ranked = array(
	array(
		'fighter' => array_merge( $base_fighter, array( 'id' => 3 ) ),
		'stats' => array_merge( $base_stats, array( 'wins' => 5, 'finish_rate' => 0.8, 'last_fight_date' => '2026-02-01' ) ),
		'eligibility' => array( 'age' => 24 ),
		'total_score' => 10.0,
	),
	array(
		'fighter' => array_merge( $base_fighter, array( 'id' => 2 ) ),
		'stats' => array_merge( $base_stats, array( 'wins' => 6, 'finish_rate' => 0.1, 'last_fight_date' => '2026-01-01' ) ),
		'eligibility' => array( 'age' => 25 ),
		'total_score' => 10.0,
	),
);
$sort_method->invokeArgs( $calculator, array( &$ranked ) );
$record( 'tie_breaker_higher_wins_first_without_score_change', 2 === (int) $ranked[0]['fighter']['id'] && 10.0 === (float) $ranked[0]['total_score'] );

$rows_method = new ReflectionMethod( RankingCalculatorService::class, 'rows_for_boards' );
$rows_method->setAccessible( true );
$board_rows = $rows_method->invoke(
	$calculator,
	array(
		array(
			'fighter' => array_merge( $base_fighter, array( 'id' => 10, 'gender' => 'female', 'weight_class' => 'women_flyweight' ) ),
			'stats' => $base_stats,
			'total_score' => 9.0,
			'breakdown' => array( 'total_score' => 9.0 ),
			'eligibility' => array( 'eligible' => true, 'age' => 29 ),
			'warnings' => array(),
			'source_summary' => array(),
		),
	)
);
$boards = array_map( static fn( array $row ): string => (string) $row['board_key'], $board_rows );
$positions = array();
foreach ( $board_rows as $row ) {
	$positions[ (string) $row['board_key'] ][] = (int) $row['rank_position'];
}
$contiguous = true;
foreach ( $positions as $board_positions ) {
	sort( $board_positions );
	$contiguous = $contiguous && range( 1, count( $board_positions ) ) === $board_positions;
}
$record( 'board_eligible_fighter_in_relevant_boards', in_array( 'overall', $boards, true ) && in_array( 'female', $boards, true ) && in_array( 'under_30', $boards, true ) && in_array( 'women_flyweight', $boards, true ) );
$record( 'board_rank_positions_contiguous', $contiguous );

$failed = array();
foreach ( $results as $name => $row ) {
	if ( empty( $row['pass'] ) ) {
		$failed[] = $name;
	}
}

echo wp_json_encode(
	array(
		'ok' => empty( $failed ),
		'results' => $results,
		'failed' => $failed,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( empty( $failed ) ? 0 : 1 );
