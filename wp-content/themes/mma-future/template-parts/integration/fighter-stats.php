<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<section class="mf-quick-stats bg-gradient-to-br from-slate-50 to-slate-100 py-8 lg:py-12">
<div class="mf-quick-stats__container container mx-auto px-4">
<!-- Section Header -->
<div class="mf-quick-stats__header mb-6 lg:mb-8">
<h2 class="mf-quick-stats__title font-heading font-bold text-xl md:text-2xl text-heading uppercase tracking-wide">Quick stats</h2>
<p class="mt-3 text-sm text-muted">Record: <?php echo esc_html( mmaf_record( $stats ) ); ?> · Draws: <?php echo esc_html( mmaf_display_number( $stats['draws'] ?? null ) ); ?> · No contests: <?php echo esc_html( mmaf_display_number( $stats['nc'] ?? null ) ); ?></p><p class="mt-2 text-sm text-muted">KO / TKO wins: <?php echo esc_html( mmaf_display_number( $stats['ko_tko_wins'] ?? null ) ); ?> · Submission wins: <?php echo esc_html( mmaf_display_number( $stats['submission_wins'] ?? null ) ); ?> · Decision wins: <?php echo esc_html( mmaf_display_number( $stats['decision_wins'] ?? null ) ); ?></p></div>
<!-- Stats Cards Grid -->
<div class="mf-quick-stats__cards grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 lg:gap-4 mb-6 lg:mb-8">
<!-- Card 1: Wins -->
<article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
<div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-green-50 flex items-center justify-center mb-3">
<svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
</svg>
</div>
<div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1"><?php echo esc_html( mmaf_display_number( $stats['wins'] ?? null ) ); ?></div>
<div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">Wins</div>
</article>
<!-- Card 2: Losses -->
<article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
<div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-red-50 flex items-center justify-center mb-3">
<svg class="w-5 h-5 lg:w-6 lg:h-6 text-red-600" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
</svg>
</div>
<div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1"><?php echo esc_html( mmaf_display_number( $stats['losses'] ?? null ) ); ?></div>
<div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">Losses</div>
</article>
<!-- Card 3: Finishes -->
<article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
<div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-amber-50 flex items-center justify-center mb-3">
<svg class="w-5 h-5 lg:w-6 lg:h-6 text-amber-600" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M13 10V3L4 14h7v7l9-11h-7z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
</svg>
</div>
<div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1"><?php echo esc_html( mmaf_display_number( $stats['finish_wins'] ?? null ) ); ?></div>
<div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">Finishes</div>
</article>
<!-- Card 4: MMA Future Score -->
<article class="mf-quick-stats__card mf-quick-stats__card--active bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
<div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-blue-50 flex items-center justify-center mb-3">
<svg class="w-5 h-5 lg:w-6 lg:h-6 text-blue-600" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
</svg>
</div>
<div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1"><?php echo esc_html( mmaf_display_number( $overall['score'] ?? null, 2 ) ); ?></div>
<div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">MMA Future Score</div>
</article>
<!-- Card 5: Win Percentage -->
<article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
<div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-indigo-50 flex items-center justify-center mb-3">
<svg class="w-5 h-5 lg:w-6 lg:h-6 text-indigo-600" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
</svg>
</div>
<div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1"><?php echo esc_html( mmaf_display_percent( $stats['win_rate'] ?? null ) ); ?></div>
<div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">Win rate</div>
</article>
<!-- Card 6: Finish Percentage -->
<article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
<div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-purple-50 flex items-center justify-center mb-3">
<svg class="w-5 h-5 lg:w-6 lg:h-6 text-purple-600" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
</svg>
</div>
<div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1"><?php echo esc_html( mmaf_display_percent( $stats['finish_rate'] ?? null ) ); ?></div>
<div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">Finish rate</div>
</article>
</div>
<!-- Latest 5 Fights -->
<div class="mf-quick-stats__recent flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4">
<h3 class="mf-quick-stats__recent-title font-heading font-semibold text-sm uppercase tracking-wide text-heading">Recent form</h3>
<div class="mf-quick-stats__chips flex flex-wrap items-center gap-2"><?php foreach ( preg_split( '/[^A-Z]+/', strtoupper( (string) ( $stats['recent_form'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) as $result ) : $variant = array( 'W' => 'win', 'L' => 'loss', 'D' => 'draw', 'NC' => 'nc' )[$result] ?? 'nc'; ?><span class="mf-quick-stats__chip mf-quick-stats__chip--<?php echo esc_attr( $variant ); ?>"><?php echo esc_html( $result ); ?></span><?php endforeach; ?></div>
</div>
</div>
</section>
