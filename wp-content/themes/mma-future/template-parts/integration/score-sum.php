<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $components = mmaf_score_components( $r ); if ( ! $components ) { return; } $parts = mmaf_score_parts( $r ); $first = true; ?>
<div class="rk-breakdown">
<p class="rk-breakdown-title">Score breakdown <span>points</span></p>
<div class="rk-breakdown-grid">
<?php foreach ( $parts as $part ) : ?>
<?php if ( ! $first ) : ?><span class="rk-breakdown-op" aria-hidden="true">+</span><?php endif; $first = false; ?>
<div class="rk-tile"><span class="rk-tile-label"><?php echo esc_html( $part['label'] ); ?></span><span class="rk-tile-value<?php echo esc_attr( mmaf_negative_class( $part['value'] ) ); ?>"><?php echo esc_html( mmaf_display_points( $part['value'] ) ); ?></span><?php foreach ( $part['lines'] as $line ) : ?><span class="rk-tile-note"><?php echo esc_html( $line ); ?></span><?php endforeach; ?><?php if ( '' !== $part['warn'] ) : ?><span class="rk-tile-note rk-impact-note--partial"><?php echo esc_html( $part['warn'] ); ?></span><?php endif; ?></div>
<?php endforeach; ?>
<span class="rk-breakdown-op" aria-hidden="true">=</span>
<div class="rk-tile rk-tile--total"><span class="rk-tile-label">Score</span><span class="rk-tile-value"><?php echo esc_html( mmaf_display_number( $components['total'], 2 ) ); ?></span><?php if ( ! empty( $r['rank'] ) ) : ?><span class="rk-tile-note"><?php echo esc_html( 'Rank #' . $r['rank'] ); ?></span><?php endif; ?></div>
</div>
</div>
