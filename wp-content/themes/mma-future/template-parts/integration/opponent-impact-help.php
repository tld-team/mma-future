<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<span class="rk-impact-help">
    <button type="button" class="rk-impact-info" data-rk-impact-help aria-label="About opponent quality" aria-describedby="<?php echo esc_attr( $help_id ); ?>"><span aria-hidden="true">i</span></button>
    <span class="rk-impact-tooltip" id="<?php echo esc_attr( $help_id ); ?>" role="tooltip"><strong>Points from opponent quality</strong>Extra points based on opponents’ pre-fight records, already included in Score. Win adjustment + Extra loss penalty = the value shown; open a row to see both. Losing to an opponent with more wins than losses adds no extra penalty. Missing opponent records contribute 0 and are marked as partial.</span>
</span>
