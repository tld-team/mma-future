<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $fights = $r['scoring_fights'] ?? null; $components = mmaf_score_components( $r ); if ( ! is_array( $fights ) || ! $fights || ! $components ) { return; }
$methods = array( 'ko_tko' => 'KO/TKO', 'submission' => 'Submission', 'decision' => 'Decision', 'dq' => 'Disqualification' );
$cell = static function ( $value, string $class = '' ): string { return '<td class="rk-fights-num' . ( $class ? ' ' . $class : '' ) . ( is_numeric( $value ) && 0.0 === (float) $value ? ' is-zero' : '' ) . mmaf_negative_class( $value ) . '">' . esc_html( mmaf_display_points( $value ) ) . '</td>'; };
$fight_total = $components['record_points'] + $components['finish_points'] + $components['opponent_points'];
?>
<details class="rk-fights">
<summary>Fight by fight <span><?php echo esc_html( count( $fights ) . ( 1 === count( $fights ) ? ' scoring bout' : ' scoring bouts' ) ); ?></span></summary>
<table>
<thead><tr><th scope="col" class="rk-fights-date">Date</th><th scope="col" class="rk-fights-opp">Opponent</th><th scope="col" class="rk-fights-num rk-fights-part">Record</th><th scope="col" class="rk-fights-num rk-fights-part">Finishes</th><th scope="col" class="rk-fights-num rk-fights-part">Opponents</th><th scope="col" class="rk-fights-num rk-fights-points">Points</th></tr></thead>
<tbody>
<?php foreach ( $fights as $fight ) : $won = 'win' === $fight['result']; ?>
<tr>
<td class="rk-fights-date"><?php echo esc_html( mmaf_display_date( $fight['event_date'] ) ); ?></td>
<td class="rk-fights-opp"><span class="rk-streak" data-streak="<?php echo $won ? 'W' : 'L'; ?>"><span class="sr-only"><?php echo $won ? 'Win' : 'Loss'; ?> </span><span aria-hidden="true"><?php echo $won ? 'W' : 'L'; ?></span></span> <span class="rk-fights-name"><?php echo esc_html( $fight['opponent_name'] ?: 'Unknown opponent' ); ?></span><span class="rk-fights-sub"><?php echo esc_html( ( $methods[ $fight['method_category'] ] ?? 'Method unknown' ) . ' · ' . ( null === $fight['opponent_record'] ? 'opponent record unknown' : 'opponent was ' . $fight['opponent_record'] ) ); ?></span></td>
<?php echo $cell( $fight['record_points'], 'rk-fights-part' ) . $cell( $fight['finish_points'], 'rk-fights-part' ) . $cell( $fight['opponent_points'], 'rk-fights-part' ) . $cell( $fight['points'], 'rk-fights-points' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr><th scope="row" colspan="2" class="rk-fights-label">All fights</th><?php echo $cell( $components['record_points'], 'rk-fights-part' ) . $cell( $components['finish_points'], 'rk-fights-part' ) . $cell( $components['opponent_points'], 'rk-fights-part' ) . $cell( $fight_total, 'rk-fights-points' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></tr>
<tr><th scope="row" colspan="2" class="rk-fights-label">Age<?php echo isset( $r['age'] ) ? ' ' . (int) $r['age'] : ''; ?></th><td class="rk-fights-part" colspan="3"></td><?php echo $cell( $components['age_points'], 'rk-fights-points' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></tr>
<tr class="rk-fights-score"><th scope="row" colspan="2" class="rk-fights-label">Score</th><td class="rk-fights-part" colspan="3"></td><td class="rk-fights-num rk-fights-points"><?php echo esc_html( mmaf_display_number( $components['total'], 2 ) ); ?></td></tr>
</tfoot>
</table>
</details>
