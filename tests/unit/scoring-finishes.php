<?php
/** Finish counts must explain the frozen score, including incomplete methods. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
$includes = ABSPATH . 'wp-content/plugins/mma-future-data-engine/includes/';
spl_autoload_register(static function ($class) use ($includes) {
    $prefix = 'MMAF\\DataEngine\\';
    if (str_starts_with($class, $prefix)) {
        $file = $includes . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
$wpdb = (object)['prefix' => 'test_'];
function add_action(...$args) {}
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function number_format_i18n($value, $decimals = 0) { return number_format($value, $decimals, '.', ','); }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
require ABSPATH . 'wp-content/themes/mma-future/inc/public-render.php';
function finishes_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}
function finishes_html(?array $summary): string {
    $r = ['scoring_finishes' => $summary];
    ob_start();
    require ABSPATH . 'wp-content/themes/mma-future/template-parts/integration/scoring-finishes.php';
    return ob_get_clean();
}
function parts_html(string $view, array $r): string {
    ob_start();
    require ABSPATH . 'wp-content/themes/mma-future/template-parts/integration/' . $view . '.php';
    return ob_get_clean();
}
use MMAF\DataEngine\Services\Scoring\FinishSummary;
use MMAF\DataEngine\Services\Scoring\ScoreComponents;
use MMAF\DataEngine\Services\Scoring\ScoringFights;
use MMAF\DataEngine\Services\PublicReadService;

$bout = static fn($result, $method) => ['result_for_fighter' => $result, 'method_category' => $method];
$breakdown = ['sample_size' => 4, 'finishes_points' => 2, 'per_fight_items' => [
    $bout('win', 'ko_tko'), $bout('win', 'submission'), $bout('win', 'decision'), $bout('loss', 'ko_tko'),
]];
$summary = FinishSummary::from_breakdown($breakdown);
finishes_check($summary['count'] === 2 && $summary['ko_tko'] === 1 && $summary['submission'] === 1, 'only KO/TKO and submission victories count as finishes');
finishes_check($summary['points'] === 2.0 && $summary['unknown_win_methods'] === 0, 'the finish count agrees with the saved score contribution');
$zero = FinishSummary::from_breakdown(['sample_size' => 2, 'finishes_points' => 0, 'per_fight_items' => [$bout('win', 'decision'), $bout('loss', 'submission')]]);
finishes_check($zero['count'] === 0 && !str_contains(finishes_html($zero), 'Not available'), 'a verified zero remains a real finish count');
$partial = FinishSummary::from_breakdown(['sample_size' => 3, 'finishes_points' => 1, 'per_fight_items' => [$bout('win', 'ko_tko'), $bout('win', 'unknown'), $bout('loss', 'unknown')]]);
finishes_check($partial['count'] === 1 && $partial['unknown_win_methods'] === 1, 'only unknown winning methods make the finish total incomplete');
finishes_check(str_contains(finishes_html($partial), '≥1 finish<') && str_contains(finishes_html($partial), '1 win method unknown'), 'partly known finish totals are explicitly shown as a lower bound');
$unknown = FinishSummary::from_breakdown(['sample_size' => 2, 'finishes_points' => 0, 'per_fight_items' => [$bout('win', 'unknown'), $bout('win', null)]]);
finishes_check($unknown['unknown_win_methods'] === 2 && str_contains(finishes_html($unknown), 'Finishes unknown') && !str_contains(finishes_html($unknown), '0 finishes'), 'unknown win methods do not produce a misleading exact zero');
$onlyLoss = FinishSummary::from_breakdown(['sample_size' => 1, 'finishes_points' => 0, 'per_fight_items' => [$bout('loss', 'unknown')]]);
finishes_check($onlyLoss['count'] === 0 && $onlyLoss['unknown_win_methods'] === 0, 'a loss with an unknown method cannot hide a finish victory');
$dq = FinishSummary::from_breakdown(['sample_size' => 1, 'finishes_points' => 0, 'per_fight_items' => [$bout('win', 'dq')]]);
finishes_check($dq['count'] === 0 && $dq['unknown_win_methods'] === 0, 'a known DQ victory does not receive a finish bonus');
finishes_check(FinishSummary::from_breakdown(null) === null && FinishSummary::from_breakdown(['sample_size' => 2, 'finishes_points' => 2]) === null, 'aggregate points alone are not substituted for missing fight evidence');
finishes_check(FinishSummary::from_breakdown(array_merge($breakdown, ['sample_size' => 5])) === null, 'truncated fight evidence is unavailable rather than silently undercounted');
finishes_check(FinishSummary::from_breakdown(array_merge($breakdown, ['finishes_points' => 3])) === null, 'contradictory finish methods and saved bonus do not become a public number');
finishes_check(FinishSummary::from_breakdown(array_merge($breakdown, ['finishes_points' => INF])) === null, 'non-finite saved points cannot become a public count');
finishes_check(str_contains(finishes_html(null), 'Not available for this score'), 'unavailable data has an explicit explanation');

$service = new PublicReadService();
$ranking = new ReflectionMethod($service, 'ranking');
$ranking->setAccessible(true);
$row = ['board_key' => 'overall', 'rank_position' => 1, 'total_score' => '9.500', 'raw_score' => '9.500', 'sample_size' => 4, 'ranking_run_id' => 123,
    'breakdown_json' => json_encode($breakdown), 'quality_flags_json' => '[]', 'warnings_json' => '[]', 'finish_wins' => 99];
foreach (['v1.5', 'v1.6', 'v1.7', 'v1.8'] as $version) {
    $dto = $ranking->invoke($service, $row, ['formula_version' => $version]);
    unset($dto['breakdown']);
    finishes_check($dto['scoring_finishes'] === $summary && $dto['score'] === 9.5 && $dto['active_ranking_run_id'] === 123, "$version exposes frozen finish counts independently of newer statistics and optional full details");
}
$quarterRow = array_merge($row, ['total_score' => '8.000', 'raw_score' => '8.000', 'breakdown_json' => json_encode(array_merge($breakdown, ['finishes_points' => 0.5]))]);
$quarter = $ranking->invoke($service, $quarterRow, ['formula_version' => 'v1.9']);
finishes_check($quarter['scoring_finishes']['count'] === 2 && $quarter['scoring_finishes']['points'] === 0.5 && $quarter['scoring_finishes']['points_per_finish'] === 0.25, 'v1.9 explains its finish points at 0.25 per finish');
$stale = $ranking->invoke($service, $row, ['formula_version' => 'v1.9']);
finishes_check($stale['scoring_finishes'] === null, 'v1.9 does not present a one-point finish total as its own');
$legacy = $ranking->invoke($service, $row, ['formula_version' => 'v1.4']);
finishes_check($legacy['scoring_finishes'] === null, 'historical normalized scores do not imply a direct one-point finish bonus');

// The cell leads with points, so it cannot be mistaken for a count that adds up to Score.
$quarterHtml = finishes_html($quarter['scoring_finishes']);
finishes_check(str_contains($quarterHtml, 'Finish points: </span>+0.5<') && str_contains($quarterHtml, '>2 finishes<') && !str_contains($quarterHtml, 'KO/TKO'), 'the finishes cell shows points first, the finish count as one labelled line, and no further detail');
finishes_check(str_contains(finishes_html($zero), 'Finish points: </span>0<') && str_contains(finishes_html($zero), '>0 finishes<') && str_contains(finishes_html($zero), 'is-zero'), 'zero finishes read as zero points and a labelled zero count');
finishes_check(mmaf_display_points(2.25) === '+2.25' && mmaf_display_points(2.0) === '+2' && mmaf_display_points(-1.5) === '−1.5' && mmaf_display_points(0) === '0' && mmaf_display_points(null) === '—', 'points are signed and never padded into a different-looking number');

$career = ['sample_size' => 4, 'base_record_points' => 0.0, 'finishes_points' => 0.5, 'opponent_differential_points' => 4.0, 'loss_quality_penalty_points' => -2.0, 'age_adjustment_points' => 1.0, 'per_fight_items' => $breakdown['per_fight_items']];
$parts = ScoreComponents::from_breakdown($career, 3.5);
finishes_check($parts === ['record_points' => 0.0, 'finish_points' => 0.5, 'opponent_points' => 2.0, 'age_points' => 1.0, 'total' => 3.5, 'scoring_wins' => 3, 'scoring_losses' => 1], 'score parts are read from the frozen breakdown and add up to the score');
finishes_check(abs($parts['record_points'] + $parts['finish_points'] + $parts['opponent_points'] + $parts['age_points'] - $parts['total']) < 0.0005, 'the visible parts sum to the displayed score');
finishes_check(ScoreComponents::from_breakdown($career, 9.5) === null, 'parts that do not add up to the displayed score are not shown');
finishes_check(ScoreComponents::from_breakdown(array_diff_key($career, ['age_adjustment_points' => 1]), 2.5) === null && ScoreComponents::from_breakdown(null, 3.5) === null, 'a missing part is not replaced by a guess');
$noFights = ScoreComponents::from_breakdown(array_diff_key($career, ['per_fight_items' => 1]), 3.5);
finishes_check($noFights['total'] === 3.5 && $noFights['scoring_wins'] === null, 'win and loss counts are left out when the fight list is unavailable');
$careerRow = array_merge($row, ['total_score' => '3.500', 'raw_score' => '3.500', 'breakdown_json' => json_encode($career)]);
$careerDto = $ranking->invoke($service, $careerRow, ['formula_version' => 'v1.9']);
finishes_check($careerDto['score_components'] === $parts && $stale['score_components'] === null && $legacy['score_components'] === null, 'v1.9 exposes score parts; incomplete or normalized scores expose none');
$careerDto['age'] = 24;
$sum = parts_html('score-sum', $careerDto);
finishes_check(str_contains($sum, '3 wins · 1 loss') && str_contains($sum, '2 finishes × 0.25') && str_contains($sum, '1 KO/TKO · 1 SUB') && str_contains($sum, 'Win adjustment +4') && str_contains($sum, 'Extra loss penalty −2') && str_contains($sum, 'Age 24'), 'the breakdown panel explains how each part is counted');
finishes_check(str_contains($sum, 'rk-tile-value">0<') && str_contains($sum, 'rk-tile-value">+0.5<') && str_contains($sum, 'rk-tile-value">+2<') && str_contains($sum, 'rk-tile-value">+1<') && str_contains($sum, 'rk-tile-value">3.50<'), 'the breakdown panel lists every part and the score they add up to');
$cells = mmaf_score_parts($careerDto);
finishes_check(array_keys($cells) === ['record', 'finishes', 'opponents', 'age'] && abs(array_sum(array_column($cells, 'value')) - $careerDto['score']) < 0.0005, 'the four row columns are the parts of the score and add up to it');
finishes_check(parts_html('score-sum', $stale) === '' && mmaf_score_parts($stale)['record']['value'] === null, 'rows without known parts show no sum and no invented values');

// Fight by fight: every row is read from the frozen score and the rows add up to the parts above.
$fight = static fn($id, $date, $result, $method, $base, $finish, $diff, $od, $lq) => ['opponent_fighter_id' => $id, 'event_date' => $date, 'result_for_fighter' => $result, 'method_category' => $method,
    'base_points' => $base, 'finish_points' => $finish, 'opponent_prefight_diff' => $diff, 'opponent_prefight_record' => null === $diff ? null : ['raw' => '5-' . (5 - $diff)], 'opponent_diff_points' => $od, 'loss_quality_penalty' => $lq];
$fightBreakdown = ['sample_size' => 3, 'base_record_points' => -0.5, 'finishes_points' => 0.25, 'opponent_differential_points' => 1.0, 'loss_quality_penalty_points' => -2.0, 'age_adjustment_points' => 1.0, 'per_fight_items' => [
    $fight(11, '2025-01-10', 'win', 'ko_tko', 0.5, 0.25, 2, 1.0, 0.0), $fight(12, '2026-03-01', 'loss', 'decision', -1.5, 0.0, -1, 0.0, -2.0), $fight(13, '2024-06-01', 'win', 'decision', 0.5, 0.0, null, 0.0, 0.0)]];
$fights = ScoringFights::from_breakdown($fightBreakdown, [11 => 'Ana Ko', 12 => 'Bo Dec']);
finishes_check(ScoringFights::opponent_ids($fightBreakdown) === [11, 12, 13] && array_column($fights, 'event_date') === ['2026-03-01', '2025-01-10', '2024-06-01'], 'scoring fights are listed newest first and name the opponents to look up');
finishes_check($fights[0]['points'] === -3.5 && $fights[0]['opponent_points'] === -2.0 && $fights[1]['points'] === 1.75 && $fights[1]['opponent_name'] === 'Ana Ko' && $fights[1]['opponent_record'] === '5-3', 'each fight carries its own record, finish and opponent points');
finishes_check($fights[2]['opponent_name'] === null && $fights[2]['opponent_record'] === null, 'an unknown opponent name or record stays unknown');
finishes_check(abs(array_sum(array_column($fights, 'points')) + 1.0 - -0.25) < 0.0005, 'fight points plus the age adjustment equal the score');
finishes_check(ScoringFights::from_breakdown(array_merge($fightBreakdown, ['finishes_points' => 1.0])) === null && ScoringFights::from_breakdown(array_merge($fightBreakdown, ['sample_size' => 4])) === null && ScoringFights::from_breakdown(null) === null, 'a fight list that does not explain the saved totals is not shown');
$fightRow = ['scoring_fights' => $fights, 'age' => 24, 'score_components' => ScoreComponents::from_breakdown($fightBreakdown, -0.25), 'scoring_finishes' => null, 'opponent_impact' => null];
$fightHtml = parts_html('score-fights', $fightRow);
finishes_check(str_contains($fightHtml, '3 scoring bouts') && str_contains($fightHtml, 'Ana Ko') && str_contains($fightHtml, 'KO/TKO · opponent was 5-3') && str_contains($fightHtml, 'Unknown opponent') && str_contains($fightHtml, 'opponent record unknown'), 'the fight list names opponents and says what is unknown');
finishes_check(str_contains($fightHtml, 'rk-neg">−3.5<') && str_contains($fightHtml, '>+1.75<') && str_contains($fightHtml, '>−1.25<') && str_contains($fightHtml, '>+1<') && str_contains($fightHtml, '>-0.25<'), 'the fight list ends with the fights total, the age adjustment and the score');
finishes_check(parts_html('score-fights', ['scoring_fights' => null] + $fightRow) === '' && parts_html('score-fights', ['score_components' => null] + $fightRow) === '', 'no fight list is shown without verified fights and score parts');
echo "Scoring finishes checks completed.\n";
