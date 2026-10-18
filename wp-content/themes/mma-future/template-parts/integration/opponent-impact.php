<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $impact = $r['opponent_impact'] ?? null; ?>
<div class="rk-impact">
<?php if ( null === $impact ) : ?>
    <span class="rk-impact-total">—</span>
    <span class="rk-impact-note">Not available for this score</span>
<?php else : ?>
    <span class="rk-impact-total"><span class="sr-only">Net opponent quality adjustment: </span><?php echo esc_html( mmaf_display_signed_points( $impact['total_points'] ) ); ?><span class="sr-only"> points</span></span>
    <span class="rk-impact-parts"><span>Win adjustment <strong><?php echo esc_html( mmaf_display_signed_points( $impact['win_points'] ) ); ?></strong></span><span>Extra loss penalty <strong><?php echo esc_html( mmaf_display_signed_points( $impact['loss_points'] ) ); ?></strong></span></span>
    <?php if ( null === $impact['missing_record_bouts'] ) : ?>
        <span class="rk-impact-note">Opponent record coverage unavailable</span>
    <?php elseif ( $impact['missing_record_bouts'] > 0 ) : ?>
        <span class="rk-impact-note rk-impact-note--partial">Partial · <?php echo esc_html( $impact['known_record_bouts'] . '/' . $impact['scoring_bouts'] ); ?> opponent records known</span>
    <?php endif; ?>
<?php endif; ?>
</div>
