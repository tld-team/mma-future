<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $finishes = $r['scoring_finishes'] ?? null; ?>
<div class="rk-finishes">
<?php if ( null === $finishes ) : ?>
    <span class="rk-impact-total">—</span>
    <span class="rk-impact-note">Not available for this score</span>
<?php else : $unknown = $finishes['unknown_win_methods']; ?>
    <span class="rk-impact-total"><span class="sr-only"><?php echo $unknown ? 'Confirmed finishes, total incomplete: ' : 'Finishes: '; ?></span><?php echo esc_html( $unknown && ! $finishes['count'] ? '—' : ( $unknown ? '≥' : '' ) . $finishes['count'] ); ?></span>
    <span class="rk-finishes-parts"><span><?php echo esc_html( $finishes['ko_tko'] ); ?> KO/TKO</span><span><?php echo esc_html( $finishes['submission'] ); ?> SUB</span></span>
    <?php if ( $unknown ) : ?><span class="rk-impact-note rk-impact-note--partial"><?php echo esc_html( $unknown . ( 1 === $unknown ? ' win method unknown' : ' win methods unknown' ) ); ?></span><?php endif; ?>
<?php endif; ?>
</div>
