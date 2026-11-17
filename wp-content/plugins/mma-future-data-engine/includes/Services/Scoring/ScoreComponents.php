<?php
namespace MMAF\DataEngine\Services\Scoring;

/** Split the frozen score into the point parts that add up to it, without recalculating fights. */
final class ScoreComponents {
    private const PARTS = [
        'record_points' => 'base_record_points',
        'finish_points' => 'finishes_points',
        'opponent_win_points' => 'opponent_differential_points',
        'opponent_loss_points' => 'loss_quality_penalty_points',
        'age_points' => 'age_adjustment_points',
    ];

    public static function from_breakdown(?array $breakdown, $score): ?array {
        if (!$breakdown || !is_numeric($score) || !is_finite((float)$score)) { return null; }
        $out = [];
        foreach (self::PARTS as $name => $key) {
            if (!isset($breakdown[$key]) || !is_numeric($breakdown[$key]) || !is_finite((float)$breakdown[$key])) { return null; }
            $out[$name] = (float)$breakdown[$key];
        }
        $out['opponent_points'] = round($out['opponent_win_points'] + $out['opponent_loss_points'], 3);
        $sum = $out['record_points'] + $out['finish_points'] + $out['opponent_points'] + $out['age_points'];
        // Parts that do not add up to the displayed score would mislead; show none instead.
        if (abs($sum - (float)$score) > 0.0005) { return null; }

        $wins = null; $losses = null;
        $items = $breakdown['per_fight_items'] ?? null;
        if (is_array($items) && isset($breakdown['sample_size']) && count($items) === (int)$breakdown['sample_size']) {
            $wins = 0; $losses = 0;
            foreach ($items as $item) {
                $result = $item['result_for_fighter'] ?? null;
                if ('win' === $result) { ++$wins; } elseif ('loss' === $result) { ++$losses; }
            }
        }
        return [
            'record_points' => $out['record_points'],
            'finish_points' => $out['finish_points'],
            'opponent_points' => $out['opponent_points'],
            'age_points' => $out['age_points'],
            'total' => (float)$score,
            'scoring_wins' => $wins,
            'scoring_losses' => $losses,
        ];
    }
}
