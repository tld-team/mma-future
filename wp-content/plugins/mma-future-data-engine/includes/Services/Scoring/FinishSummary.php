<?php
namespace MMAF\DataEngine\Services\Scoring;

/** Count finish wins from the exact fights frozen with the displayed score. */
final class FinishSummary {
    public static function from_breakdown(?array $breakdown): ?array {
        $items = $breakdown['per_fight_items'] ?? null;
        $sample = $breakdown['sample_size'] ?? null;
        $points = $breakdown['finishes_points'] ?? null;
        if (!is_array($items) || !is_numeric($sample) || (int)$sample < 0 || count($items) !== (int)$sample
            || !is_numeric($points) || !is_finite((float)$points)) { return null; }

        $ko = 0; $submissions = 0; $unknown = 0;
        foreach ($items as $item) {
            $result = $item['result_for_fighter'] ?? null;
            if (!in_array($result, ['win', 'loss'], true)) { return null; }
            if ($result !== 'win') { continue; }
            switch ($item['method_category'] ?? 'unknown') {
                case 'ko_tko': ++$ko; break;
                case 'submission': ++$submissions; break;
                case 'decision': case 'dq': break;
                default: ++$unknown;
            }
        }
        // Direct formulas award one point per recent scored finish. Do not show contradictory inputs.
        if (abs((float)$points - ($ko + $submissions)) > 0.000001) { return null; }
        return [
            'count' => $ko + $submissions,
            'ko_tko' => $ko,
            'submission' => $submissions,
            'points' => (float)$points,
            'unknown_win_methods' => $unknown,
            'scoring_bouts' => (int)$sample,
        ];
    }
}
