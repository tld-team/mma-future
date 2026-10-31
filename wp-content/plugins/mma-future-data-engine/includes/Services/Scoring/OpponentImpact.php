<?php
namespace MMAF\DataEngine\Services\Scoring;

/** Read point contributions from the frozen score, without recalculating fights. */
final class OpponentImpact {
    public static function from_breakdown(?array $breakdown, ?array $source_summary = null): ?array {
        if (!$breakdown) { return null; }
        foreach (['opponent_differential_points', 'loss_quality_penalty_points'] as $key) {
            if (!isset($breakdown[$key]) || !is_numeric($breakdown[$key]) || !is_finite((float)$breakdown[$key])) { return null; }
        }
        $wins = (float)$breakdown['opponent_differential_points'];
        $losses = (float)$breakdown['loss_quality_penalty_points'];
        $sample = isset($breakdown['sample_size']) ? (int)$breakdown['sample_size'] : null;
        $missing = null;
        $items = $breakdown['per_fight_items'] ?? null;
        if ($sample === 0 && is_array($items) && count($items) === 0) {
            $missing = 0;
        } elseif ($sample > 0 && is_array($items) && count($items) === $sample) {
            $missing = 0;
            foreach ($items as $item) {
                if (!is_numeric($item['opponent_prefight_diff'] ?? null)) { ++$missing; }
            }
        } elseif ($sample > 0 && isset($source_summary['prefight_records_missing_count'])) {
            $count = (int)$source_summary['prefight_records_missing_count'];
            if ($count >= 0 && $count <= $sample) { $missing = $count; }
        }
        return [
            'total_points' => round($wins + $losses, 3),
            'win_points' => $wins,
            'loss_points' => $losses,
            'scoring_bouts' => $sample,
            'known_record_bouts' => $missing === null ? null : $sample - $missing,
            'missing_record_bouts' => $missing,
        ];
    }
}
