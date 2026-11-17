<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<span class="rk-impact-help">
    <button type="button" class="rk-impact-info" data-rk-impact-help aria-label="<?php echo esc_attr( 'About ' . $title ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>"><span aria-hidden="true">i</span></button>
    <span class="rk-impact-tooltip" id="<?php echo esc_attr( $help_id ); ?>" role="tooltip"><strong><?php echo esc_html( $title ); ?></strong><?php echo esc_html( $text ); ?></span>
</span>
