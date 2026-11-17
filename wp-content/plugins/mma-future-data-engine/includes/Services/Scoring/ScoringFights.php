<?php
namespace MMAF\DataEngine\Services\Scoring;

/** List the frozen fights behind a score with the points each one contributed, without recalculating them. */
final class ScoringFights {
    /** Fighter ids named in a breakdown, so their display names can be loaded in one query. */
    public static function opponent_ids(?array $breakdown): array {
        $ids = [];
        foreach ((array)($breakdown['per_fight_items'] ?? []) as $item) {
            if (is_array($item) && !empty($item['opponent_fighter_id'])) { $ids[] = (int)$item['opponent_fighter_id']; }
        }
        return $ids;
    }

    public static function from_breakdown(?array $breakdown, array $names = []): ?array {
        $items = $breakdown['per_fight_items'] ?? null;
        $sample = $breakdown['sample_size'] ?? null;
        if (!is_array($items) || !is_numeric($sample) || count($items) !== (int)$sample) { return null; }
        $fights = []; $sums = ['record_points' => 0.0, 'finish_points' => 0.0, 'opponent_points' => 0.0];
        foreach ($items as $item) {
            $result = $item['result_for_fighter'] ?? null;
            if (!in_array($result, ['win', 'loss'], true)) { return null; }
            foreach (['base_points', 'finish_points', 'opponent_diff_points', 'loss_quality_penalty'] as $key) {
                if (!isset($item[$key]) || !is_numeric($item[$key]) || !is_finite((float)$item[$key])) { return null; }
            }
            $record = (float)$item['base_points']; $finish = (float)$item['finish_points'];
            $opponent = round((float)$item['opponent_diff_points'] + (float)$item['loss_quality_penalty'], 3);
            $id = isset($item['opponent_fighter_id']) ? (int)$item['opponent_fighter_id'] : 0;
            $raw = $item['opponent_prefight_record']['raw'] ?? null;
            $fights[] = [
                'event_date' => isset($item['event_date']) ? (string)$item['event_date'] : null,
                'opponent_name' => $names[$id] ?? null,
                'opponent_record' => is_numeric($item['opponent_prefight_diff'] ?? null) && is_string($raw) && '' !== $raw ? $raw : null,
                'result' => $result,
                'method_category' => isset($item['method_category']) ? (string)$item['method_category'] : null,
                'record_points' => $record,
                'finish_points' => $finish,
                'opponent_points' => $opponent,
                'points' => round($record + $finish + $opponent, 3),
            ];
            $sums['record_points'] += $record; $sums['finish_points'] += $finish; $sums['opponent_points'] += $opponent;
        }
        // The list must explain the saved totals exactly; a list that disagrees with them is not shown.
        $saved = [
            'record_points' => $breakdown['base_record_points'] ?? null,
            'finish_points' => $breakdown['finishes_points'] ?? null,
            'opponent_points' => is_numeric($breakdown['opponent_differential_points'] ?? null) && is_numeric($breakdown['loss_quality_penalty_points'] ?? null) ? $breakdown['opponent_differential_points'] + $breakdown['loss_quality_penalty_points'] : null,
        ];
        foreach ($sums as $key => $sum) {
            if (!is_numeric($saved[$key]) || abs($sum - (float)$saved[$key]) > 0.0005) { return null; }
        }
        usort($fights, static fn($a, $b) => strcmp((string)$b['event_date'], (string)$a['event_date']));
        return $fights;
    }
}
