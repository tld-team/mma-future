<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $variant = array( 'win' => 'win', 'loss' => 'loss', 'draw' => 'draw', 'no_contest' => 'nc' )[$fight['result']]; $result_label = array( 'win' => 'W', 'loss' => 'L', 'draw' => 'D', 'no_contest' => 'NC' )[$fight['result']]; ?>

<article class="mf-fight-card bg-white rounded-xl border border-slate-200 p-5 lg:p-6 <?php echo esc_attr( 'mf-fight-card--' . $variant ); ?>" data-fight-id="<?php echo (int) $fight['bout_id']; ?>">
<!-- Header: Badge + Opponent Info -->
<div class="mf-fight-card__header flex items-start justify-between gap-4 mb-4">
<!-- Outcome Badge -->
<div class="mf-fight-card__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide"><?php echo esc_html( $result_label ); ?></div>
<!-- Opponent Block -->
<div class="mf-fight-card__opponent flex items-center gap-3 flex-1 min-w-0">
<!-- Avatar -->
<span class="mf-fight-card__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200"><?php mmaf_opponent_image( $fight['opponent'] ); ?></span>
<!-- Name + Record -->
<div class="flex-1 min-w-0">
<?php mmaf_opponent_name( $fight['opponent'], 'mf-fight-card__name block font-heading font-semibold text-base text-heading truncate hover:text-primary-500 transition-colors' ); ?>
<div class="mf-fight-card__record text-xs text-muted mt-0.5"><?php echo esc_html( $fight['event_name'] ); ?></div>
</div>
</div>
</div>
<!-- Method Row -->
<div class="mf-fight-card__method font-heading font-bold text-2xl text-heading mb-4"><?php echo esc_html( mmaf_display_method( $fight ) ); ?> <span class="text-muted">·</span> <?php echo esc_html( isset( $fight['round'] ) ? 'R' . $fight['round'] : '—' ); ?> <small><?php echo esc_html( $fight['time'] ?? '' ); ?></small></div>
<!-- Meta Row -->
<div class="mf-fight-card__meta flex flex-wrap items-center gap-3 mb-5">
<!-- Date -->
<div class="mf-fight-card__meta-item flex items-center gap-1.5 text-sm text-body"><svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg><?php echo esc_html( mmaf_display_date( $fight['event_date'] ) ); ?></div>
<!-- Organization Tag -->
<?php if ( ! empty( $fight['promotion_name'] ) ) : ?><div class="mf-fight-card__meta-item">
<span class="mf-fight-card__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200"><?php echo esc_html( $fight['promotion_name'] ?? '' ); ?></span>
</div><?php endif; ?>
<!-- Location -->
<?php if ( ! empty( $fight['event_location'] ) ) : ?><div class="mf-fight-card__meta-item flex items-center gap-1.5 text-sm text-body"><svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
<path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg><?php echo esc_html( $fight['event_location'] ?? '' ); ?></div><?php endif; ?>
</div>
<!-- Divider -->

<!-- Footer: CTA Button -->

<?php if ( ! empty( $fight['record_note'] ) ) : ?><p class="text-xs text-muted mt-3"><?php echo esc_html( $fight['record_note'] ); ?></p><?php endif; ?></article>
