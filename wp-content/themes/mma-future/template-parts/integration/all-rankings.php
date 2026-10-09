<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $labels = \MMAF\DataEngine\Services\PublicReadService::board_labels(); $choices = array_intersect_key( $labels, array_flip( $data['available_boards'] ) ); if ( ! $choices ) { $choices = $labels; } ?>

<section class="mmaf-rankings-section" data-breakdown="<?php echo esc_attr( (int) $options['include_breakdown'] ); ?>" data-endpoint="rankings" data-mmaf-browser="rankings" data-per-page="<?php echo esc_attr( $options['per_page'] ); ?>" data-prefix="<?php echo esc_attr( $prefix ); ?>">
<div class="container mx-auto px-4 py-12">
<!-- Header -->
<div class="flex flex-wrap items-end justify-between gap-3 mb-7">
<div>
<h1 class="text-4xl font-black" style="font-family:'Sora',sans-serif;letter-spacing:-0.025em;line-height:1;color:#0f172a"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h1>
<?php if ( mmaf_site_field( 'section_description' ) ) { echo '<p class="text-sm text-slate-500 mt-3">' . esc_html( mmaf_site_field( 'section_description' ) ) . '</p>'; } mmaf_render_dates( $data ); ?>
</div>
</div>
<!-- Controls toolbar -->
<form class="mt-6 space-y-3 mb-5 rk-filters" method="get">
<!-- Row 1: Gender + Top 15 | Search -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
<!-- Left group -->
<div class="flex flex-wrap items-center gap-3">
<!-- Men / Women segmented control -->
<div class="inline-flex items-center rounded-xl bg-slate-100 py-1.5 px-2 gap-2">
<a role="button" href="<?php echo esc_url( add_query_arg( array( $prefix . '_board' => 'male', $prefix . '_page' => 1 ) ) ); ?>" aria-pressed="<?php echo ( 'male' === $options['board'] || in_array( $options['board'], array( 'flyweight', 'bantamweight', 'featherweight', 'lightweight', 'welterweight', 'middleweight', 'light_heavyweight', 'heavyweight' ), true ) ) ? 'true' : 'false'; ?>" class="rk-g-btn h-8 px-4 py-0 rounded-lg text-sm font-semibold transition-colors focus:outline-none focus-visible:outline-none" data-board="male" data-gender="male">Men</a>
<a role="button" href="<?php echo esc_url( add_query_arg( array( $prefix . '_board' => 'female', $prefix . '_page' => 1 ) ) ); ?>" aria-pressed="<?php echo ( 'female' === $options['board'] || str_starts_with( $options['board'], 'women_' ) ) ? 'true' : 'false'; ?>" class="rk-g-btn h-8 px-4 py-0 rounded-lg text-sm font-semibold transition-colors focus:outline-none focus-visible:outline-none" data-board="female" data-gender="female">Women</a>
</div>
<!-- Top 15 toggle -->
<a role="button" href="<?php echo esc_url( add_query_arg( array( $prefix . '_top15' => empty( $options['top15'] ) ? '1' : '0', $prefix . '_page' => 1 ) ) ); ?>" aria-label="Show top 15 only" aria-pressed="<?php echo empty( $options['top15'] ) ? 'false' : 'true'; ?>" class="inline-flex items-center gap-3 h-10 px-4 rounded-xl border border-slate-200 bg-white text-sm font-medium text-slate-700 transition-all duration-150 focus:outline-none focus-visible:outline-none" data-top15="">
<span class="rk-top15-track inline-flex items-center w-8 h-[1.15rem] rounded-full px-0.5 shrink-0 transition-all duration-200">
<span class="rk-top15-knob block w-[0.85rem] h-[0.85rem] rounded-full shrink-0 transition-all duration-200"></span>
</span>
            Top 15
          </a>
<!-- Sort select (mobile only) -->
<label class="rk-mobile-sort sm:hidden text-xs text-slate-500"><span class="sr-only">Sort rankings</span><select data-rk-mobile-sort class="h-10 rounded-xl"><option value="rank|asc">Rank ↑</option><option value="rank|desc">Rank ↓</option><option value="score|desc">Score ↓</option><option value="score|asc">Score ↑</option></select></label><?php mmaf_filter_select( $prefix . '_sort', 'Sort by', array( 'rank' => 'Rank', 'score' => 'Score' ), $options['sort'] ?? 'rank' ); ?>
</div>
<!-- Right group: Search -->
<div class="relative w-full lg:w-[360px]">
<svg aria-hidden="true" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none text-slate-400" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"></path>
</svg>
<input aria-label="Search fighters" class="w-full h-10 pr-4 text-sm rounded-xl border border-slate-200 bg-white text-slate-700 placeholder:text-slate-400 transition-colors focus:outline-none focus-visible:outline-none" id="<?php echo esc_attr( $prefix . '_q' ); ?>" name="<?php echo esc_attr( $prefix . '_q' ); ?>" placeholder="Search fighter…" type="search" value="<?php echo esc_attr( $options['q'] ?? '' ); ?>"/>
</div>
</div>
<!-- Row 2: Division filter pills (desktop) -->
<div class="hidden sm:flex flex-wrap items-center gap-2"><?php foreach ( $choices as $key => $label ) : ?><a role="button" href="<?php echo esc_url( add_query_arg( array( $prefix . '_board' => $key, $prefix . '_page' => 1 ) ) ); ?>" aria-pressed="<?php echo $options['board'] === $key ? 'true' : 'false'; ?>" class="rk-chip inline-flex items-center h-9 px-4 rounded-xl text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:outline-none" data-board="<?php echo esc_attr( $key ); ?>" data-board-gender="<?php echo ( 'female' === $key || str_starts_with( $key, 'women_' ) ) ? 'female' : ( in_array( $key, array( 'male', 'flyweight', 'bantamweight', 'featherweight', 'lightweight', 'welterweight', 'middleweight', 'light_heavyweight', 'heavyweight' ), true ) ? 'male' : 'both' ); ?>"><?php echo esc_html( in_array( $key, array( 'male', 'female' ), true ) ? 'All divisions' : $label ); ?></a><?php endforeach; ?></div>
<!-- Weight class select (mobile) -->
<?php mmaf_filter_select( $prefix . '_board', 'Ranking board', $choices, $options['board'] ); mmaf_filter_select( $prefix . '_order', 'Order', array( 'asc' => 'Ascending', 'desc' => 'Descending' ), $options['order'] ?? 'asc' ); ?>
<?php if ( ! empty( $options['top15'] ) ) : ?><input type="hidden" name="<?php echo esc_attr( $prefix . '_top15' ); ?>" value="1" data-top15-value><?php else : ?><input type="hidden" name="<?php echo esc_attr( $prefix . '_top15' ); ?>" value="0" data-top15-value><?php endif; ?><button class="mmaf-button rk-apply" type="submit">Apply filters</button></form>
<p aria-live="polite" class="mmaf-browser-status"></p><div class="mmaf-browser-results"><?php mmaf_render_rankings_table( $data, $options['include_breakdown'], $prefix ); mmaf_pagination( $data, $prefix ); ?></div>

</div>
</section>
