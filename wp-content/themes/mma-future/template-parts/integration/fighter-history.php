<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! mmaf_fighter_has_fight_data( $c ) ) {
 echo '<section class="mmaf-section mmaf-history-empty"><div class="mmaf-container">';
 mmaf_view( 'empty-state', array( 'heading_level' => 2, 'title' => mmaf_fighter_record_available( $c ) ? 'No professional fights recorded' : 'Fight history not available yet', 'description' => 'We do not have confirmed professional MMA results for ' . $f['display_name'] . ' yet. Fight history, statistics and recent form will appear here when results are available.', 'show_links' => true ) );
 echo '</div></section>'; return;
}  ?>

<section class="mf-fight-history bg-gradient-to-br from-slate-50 to-slate-100 py-12 lg:py-16">
<div class="container mx-auto px-4" data-endpoint="fighters/<?php echo (int) $f['id']; ?>/fights" data-mmaf-browser="history" data-per-page="10" data-prefix="<?php echo esc_attr( $prefix ); ?>">
<!-- Section Title -->
<h2 class="mf-fight-history__title font-heading font-bold text-xl md:text-2xl text-heading uppercase tracking-wide mb-6">Fight history</h2>
<!-- Filters Bar -->
<div class="mf-fight-history__filters bg-white rounded-xl border border-slate-200 p-4 lg:p-5 mb-6 shadow-sm">
<!-- Filters Label Row -->
<div class="flex items-center justify-between gap-4 mb-4">
<div class="mf-fight-history__filters-label flex items-center gap-2 text-sm font-semibold text-muted uppercase tracking-wide">
<svg class="w-4 h-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
<span>Filters:</span>
</div>
</div>
<!-- Filter Controls -->
<form class="mf-fight-history__controls flex flex-wrap items-center gap-3 mmaf-filters" method="get"><?php mmaf_filter_select( $prefix . '_result', 'Result', array( '' => 'All results', 'win' => 'Wins', 'loss' => 'Losses', 'draw' => 'Draws', 'no_contest' => 'No contests' ), $options['result'] ?? '' ); mmaf_filter_select( $prefix . '_method', 'Method', array( '' => 'All methods', 'ko_tko' => 'KO / TKO', 'submission' => 'Submission', 'decision' => 'Decision', 'other' => 'Other', 'unknown' => 'Unknown' ), $options['method'] ?? '' ); mmaf_filter_select( $prefix . '_sort', 'Order', array( 'latest' => 'Newest first', 'oldest' => 'Oldest first' ), $options['sort'] ?? 'latest' ); ?><button class="mmaf-button">Apply</button></form>
</div>
<!-- Summary Line -->
<div aria-live="polite" class="mf-fight-history__summary text-sm text-muted mb-4 px-1 mmaf-browser-status"><?php echo esc_html( $c['career_coverage']['label'] ); ?><?php if ( 'complete' !== $c['career_coverage']['status'] ) { echo ' · ' . esc_html( $c['career_coverage']['reason'] ); } ?></div>
<!-- Fight List -->
<div class="mmaf-browser-results"><?php mmaf_render_fights( $history ); mmaf_pagination( $history, $prefix ); ?></div>
</div>
</section>
