<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<span class="rk-impact-help">
    <button type="button" class="rk-impact-info" data-rk-impact-help aria-label="About opponent impact" aria-describedby="<?php echo esc_attr( $help_id ); ?>"><span aria-hidden="true">i</span></button>
    <span class="rk-impact-tooltip" id="<?php echo esc_attr( $help_id ); ?>" role="tooltip"><strong>How opponents affect the score</strong>Points based on each opponent’s record before the fight. Wins and Losses show point adjustments, not fight counts. Their sum is already included in Score and covers the same scoring fights. Missing records add 0 and are marked as partial data.</span>
</span>
