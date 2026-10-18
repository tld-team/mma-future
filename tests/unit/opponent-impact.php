<?php
/** Frozen opponent contributions and their public presentation, without a database. */
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
require ABSPATH . 'wp-content/themes/mma-future/inc/public-render.php';
function impact_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}
function impact_html(?array $impact): string {
    $r = ['opponent_impact' => $impact];
    ob_start();
    require ABSPATH . 'wp-content/themes/mma-future/template-parts/integration/opponent-impact.php';
    return ob_get_clean();
}
use MMAF\DataEngine\Services\Scoring\OpponentImpact;
use MMAF\DataEngine\Services\PublicReadService;

$breakdown = ['total_score' => 9.5, 'sample_size' => 4, 'opponent_differential_points' => 4, 'loss_quality_penalty_points' => -2,
    'per_fight_items' => [['opponent_prefight_diff' => 11], ['opponent_prefight_diff' => 1], ['opponent_prefight_diff' => 0], ['opponent_prefight_diff' => null]]];
$impact = OpponentImpact::from_breakdown($breakdown);
impact_check($impact['total_points'] === 2.0 && $impact['win_points'] === 4.0 && $impact['loss_points'] === -2.0, 'net impact retains the separate win and loss point contributions');
impact_check($impact['known_record_bouts'] === 3 && $impact['missing_record_bouts'] === 1, 'a known zero differential is distinct from a missing opponent record');
impact_check(OpponentImpact::from_breakdown(null) === null && OpponentImpact::from_breakdown(['opponent_differential_points' => 4]) === null, 'missing contributions never become a fabricated zero');
$zero = OpponentImpact::from_breakdown(['sample_size' => 1, 'opponent_differential_points' => 0, 'loss_quality_penalty_points' => 0, 'per_fight_items' => [['opponent_prefight_diff' => 0]]]);
impact_check($zero['total_points'] === 0.0 && $zero['known_record_bouts'] === 1, 'a genuine zero remains available');
$unknown = OpponentImpact::from_breakdown(['sample_size' => 2, 'opponent_differential_points' => 0, 'loss_quality_penalty_points' => 0, 'per_fight_items' => [['opponent_prefight_diff' => null], ['opponent_prefight_diff' => null]]]);
impact_check($unknown['total_points'] === 0.0 && $unknown['missing_record_bouts'] === 2 && $unknown['known_record_bouts'] === 0, 'neutral points with no known records retain their incomplete coverage');
$summary_only = $breakdown; unset($summary_only['per_fight_items']);
impact_check(OpponentImpact::from_breakdown($summary_only)['known_record_bouts'] === null, 'absent coverage is not presented as complete');
impact_check(OpponentImpact::from_breakdown($summary_only, ['prefight_records_missing_count' => 1])['known_record_bouts'] === 3, 'frozen source summary supplies coverage when per-fight rows are absent');
impact_check(OpponentImpact::from_breakdown($summary_only, ['prefight_records_missing_count' => 5])['known_record_bouts'] === null, 'inconsistent coverage counts are not displayed');
$negative = OpponentImpact::from_breakdown(['opponent_differential_points' => -2, 'loss_quality_penalty_points' => 0]);
impact_check($negative['total_points'] === -2.0 && str_contains(impact_html($negative), 'Wins <strong>−2</strong>'), 'penalties for weak-opponent wins retain their negative sign');
impact_check(str_contains(impact_html($impact), '3/4 records known') && str_contains(impact_html($unknown), '0/2 records known'), 'partial data remains visible beside the point contributions');
impact_check(str_contains(impact_html(null), 'Not available for this score') && !str_contains(impact_html(null), 'Wins <strong>0'), 'unavailable scores show an explanation rather than invented contributions');
impact_check(mmaf_display_signed_points(4) === '+4' && mmaf_display_signed_points(-2) === '−2' && mmaf_display_signed_points(0) === '0', 'positive, negative and zero point labels are unambiguous');

$service = new PublicReadService();
$ranking = new ReflectionMethod($service, 'ranking');
$ranking->setAccessible(true);
$row = ['board_key' => 'overall', 'rank_position' => 1, 'total_score' => '9.500', 'raw_score' => '9.500', 'sample_size' => 4, 'ranking_run_id' => 123,
    'breakdown_json' => json_encode($breakdown), 'quality_flags_json' => '[]', 'warnings_json' => '[]', 'source_summary_json' => '{}'];
foreach (['v1.5', 'v1.6'] as $version) {
    $dto = $ranking->invoke($service, $row, ['formula_version' => $version]);
    unset($dto['breakdown']); // The rankings endpoint can omit the full breakdown.
    impact_check($dto['opponent_impact'] === $impact && $dto['score'] === 9.5 && $dto['active_ranking_run_id'] === 123, "$version exposes the same frozen contributions independently of full score details");
}
$legacy = $ranking->invoke($service, $row, ['formula_version' => 'v1.4']);
impact_check($legacy['opponent_impact'] === null, 'historical normalized scores do not mislabel raw contributions as displayed score points');
echo "Opponent impact checks completed.\n";
