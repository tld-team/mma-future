<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $finish_points = isset( $finish_points ) && is_numeric( $finish_points ) ? (float) $finish_points : null; ?>
<span class="rk-impact-help">
    <button type="button" class="rk-impact-info" data-rk-impact-help aria-label="About finishes" aria-describedby="<?php echo esc_attr( $help_id ); ?>"><span aria-hidden="true">i</span></button>
    <span class="rk-impact-tooltip" id="<?php echo esc_attr( $help_id ); ?>" role="tooltip"><strong>Finish wins behind this score</strong>Wins by KO/TKO or submission (SUB), from the same scoring fights as the displayed Score. <?php echo esc_html( null === $finish_points ? 'Confirmed finishes are already included in Score.' : 'Each confirmed finish adds ' . mmaf_points_text( $finish_points ) . ', already included in Score.' ); ?> Decision and disqualification wins add no finish bonus. Unknown win methods are marked; ≥ means at least that many finishes are confirmed.</span>
</span>
