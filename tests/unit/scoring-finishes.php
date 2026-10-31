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
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
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
use MMAF\DataEngine\Services\Scoring\FinishSummary;
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
finishes_check(str_contains(finishes_html($partial), '≥1') && str_contains(finishes_html($partial), '1 win method unknown'), 'partly known finish totals are explicitly shown as a lower bound');
$unknown = FinishSummary::from_breakdown(['sample_size' => 2, 'finishes_points' => 0, 'per_fight_items' => [$bout('win', 'unknown'), $bout('win', null)]]);
finishes_check($unknown['unknown_win_methods'] === 2 && str_contains(finishes_html($unknown), '</span>—</span>'), 'unknown win methods do not produce a misleading exact zero');
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
foreach (['v1.5', 'v1.6', 'v1.7'] as $version) {
    $dto = $ranking->invoke($service, $row, ['formula_version' => $version]);
    unset($dto['breakdown']);
    finishes_check($dto['scoring_finishes'] === $summary && $dto['score'] === 9.5 && $dto['active_ranking_run_id'] === 123, "$version exposes frozen finish counts independently of newer statistics and optional full details");
}
$legacy = $ranking->invoke($service, $row, ['formula_version' => 'v1.4']);
finishes_check($legacy['scoring_finishes'] === null, 'historical normalized scores do not imply a direct one-point finish bonus');
echo "Scoring finishes checks completed.\n";
