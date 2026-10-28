<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$rankings_link = mmaf_site_field( 'primary_link' );
if ( ! is_array( $rankings_link ) || empty( $rankings_link['url'] ) || empty( $rankings_link['title'] ) ) {
    $rankings_link = array( 'url' => mmaf_rankings_url(), 'title' => 'See the full rankings' );
}
$rankings_link_class = 'group inline-flex items-center gap-2 h-10 px-4 rounded-xl bg-white ring-1 ring-black/10 shadow-sm text-slate-700 font-semibold text-sm transition-all duration-200 hover:bg-slate-50 hover:ring-black/15 hover:shadow-md hover:text-[#0B3AA4] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]/35 focus-visible:ring-offset-2 visited:text-slate-700 no-underline';
$top_movers = array_map( static fn( $m ) => $m + array( 'direction' => $m['rank_change'] > 0 ? 'up' : 'down', 'change' => $m['rank_change'], 'name' => $m['display_name'], 'url' => $m['permalink'] ), $movers['items'] ?? array() );
$top_five = array_map( static fn( $r ) => array( 'rank' => $r['rank'], 'name' => $r['display_name'], 'url' => $r['permalink'], 'record' => mmaf_record( mmaf_display_record($r) ), 'weight_class' => ucwords( str_replace( '_', ' ', $r['weight_class'] ) ), 'country' => $r['nationality'] ?: '—', 'finish_rate' => mmaf_display_percent( ($r['scoring_assessment']['period_stats']['finish_rate']??$r['stats']['finish_rate']) ), 'last_bout' => mmaf_display_date( $r['stats']['last_fight_date'] ), 'streak' => $r['stats']['streak'], 'score' => mmaf_display_number( $r['score'], 2 ), 'image_data' => $r['image'] ), $data['items'] ); ?>
<section id="<?php echo esc_attr( $prefix ); ?>" class="py-16 bg-white">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Top Movers Section -->
        <?php if ( mmaf_site_field( 'show_top_movers' ) ) : ?><div class="mb-20">
            <!-- Header Row -->
            <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
                <h2 class="text-xl md:text-2xl font-extrabold text-slate-900 leading-tight"><?php echo esc_html( mmaf_site_field( 'top_movers_title' ) ); ?></h2>
<?php mmaf_presentation_link( $rankings_link, $rankings_link_class ); ?>
            </div>

<?php if ( in_array( $movers['status'] ?? '', array( 'available', 'unchanged' ), true ) ) : ?><p class="text-xs text-slate-500 mb-6">Biggest rises and falls among currently listed fighters. Ranking updates: <?php echo esc_html( mmaf_display_date( $movers['previous_activated_at'] ) ); ?> → <?php echo esc_html( mmaf_display_date( $movers['current_activated_at'] ) ); ?></p><?php endif; ?>
<?php if ( ! $top_movers ) : ?><p class="text-sm text-slate-500"><?php echo esc_html( $movers['message'] ); ?></p><?php endif; ?>
            <!-- Top Movers Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($top_movers as $mover): ?>
                    <?php
                    // Semantic colors: Green #16A34A for gains, Red #DC2626 for losses
                    $is_positive = $mover['direction'] === 'up';
                    $icon_bg_style = $is_positive
                        ? 'background-color: rgba(22, 163, 74, 0.12);'
                        : 'background-color: rgba(220, 38, 38, 0.10);';
                    $icon_color = $is_positive ? '#16A34A' : '#DC2626';
                    $delta_text_classes = $is_positive 
                        ? 'text-[#16A34A]' 
                        : 'text-[#DC2626]';
                    $sign = $mover['change'] > 0 ? '+' : '';
                    ?>
                    <article class="bg-white rounded-xl ring-1 ring-black/10 shadow-sm p-5 transition-all duration-200 ease-out hover:shadow-md hover:-translate-y-0.5">
                        <a href="<?php echo esc_url($mover['url']); ?>" 
                           class="block no-underline group visited:text-slate-700">
                            <div class="flex items-start gap-4">
                                <!-- Icon -->
                                <div class="flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center" style="<?php echo esc_attr($icon_bg_style); ?>">
                                    <?php if ($is_positive): ?>
                                        <svg class="w-5 h-5" style="color: <?php echo esc_attr($icon_color); ?>;" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 19L19 5m0 0H9m10 0v10"/>
                                        </svg>
                                    <?php else: ?>
                                        <svg class="w-5 h-5" style="color: <?php echo esc_attr($icon_color); ?>;" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 5L5 19m0 0h10M5 19V9"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>

                                <!-- Content -->
                                <div class="flex-grow min-w-0">
                                    <h3 class="text-base md:text-lg font-semibold text-slate-900 mb-1.5 truncate group-hover:text-[var(--brand)] transition-colors duration-200">
                                        <?php echo esc_html($mover['name']); ?>
                                    </h3>
                                    
                                    <!-- Weekly change label -->
                                    <span class="block text-[10px] uppercase tracking-wider text-slate-500 mb-1">Rank change</span>
                                    
                                    <!-- Delta as plain text -->
                                    <span class="text-sm font-medium <?php echo esc_attr($delta_text_classes); ?>">
                                        <?php echo esc_html($sign . $mover['change']); ?> places · #<?php echo (int) $mover['previous_rank']; ?> → #<?php echo (int) $mover['current_rank']; ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ( ! empty( $movers['new_entries'] ) ) : ?><p class="text-xs text-slate-500 mt-6">New entries: <?php foreach ( $movers['new_entries'] as $index => $entry ) : ?><?php if ( $index ) { echo ' · '; } ?><a class="underline underline-offset-2" href="<?php echo esc_url( $entry['permalink'] ); ?>"><?php echo esc_html( $entry['display_name'] ); ?></a> <span class="font-semibold">New · #<?php echo (int) $entry['current_rank']; ?></span><?php endforeach; ?></p><?php endif; ?>
        </div>

<?php endif; ?>
        <!-- Subtle divider that fades at edges -->
        <div class="h-px w-full mb-8" style="background: linear-gradient(to right, transparent, rgba(0,0,0,0.08) 20%, rgba(0,0,0,0.08) 80%, transparent);"></div>

        <!-- Current Top 5 Section -->
        <div class="mt-12">
            <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
                <h2 class="text-xl md:text-2xl font-extrabold text-slate-900 leading-tight"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
                <?php mmaf_presentation_link( $rankings_link, $rankings_link_class ); ?>
            </div>
            <?php if ( mmaf_site_field( 'section_description' ) ) : ?><p class="text-sm text-slate-500 mb-6"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p><?php endif; ?><?php mmaf_render_dates( $data ); ?><?php if ( ! $top_five ) : ?><p>No active rankings are available.</p><?php endif; ?>

            <!-- Top 5 List -->
            <div class="flex flex-col gap-4">
                <?php foreach ($top_five as $fighter): 
                    $rank = $fighter['rank'];
                    
                    // Top 3 get brand accent, #4-5 are neutral
                    $is_top_three = $rank <= 3;
                    $border_classes = $is_top_three 
                        ? 'border-l-4 border-[var(--brand)]' 
                        : 'border-l-4 border-transparent';
                    
                    // Rank badge styling
                    // Top 3: brand tint bg + brand text + brand ring
                    // #4-5: neutral slate styling
                    $rank_base_classes = 'w-12 shrink-0 text-center rounded-lg py-2 font-semibold ring-1';
                    $rank_extra_classes = $is_top_three
                        ? 'bg-[rgba(var(--brand-rgb),0.06)] text-[var(--brand)] ring-[rgba(var(--brand-rgb),0.30)]'
                        : 'bg-slate-50 text-slate-500 ring-black/5';
                    
                ?>
                    <article class="bg-white rounded-xl ring-1 ring-black/10 shadow-sm transition-all duration-200 hover:bg-slate-50/60 hover:ring-black/15 hover:shadow-md cursor-pointer <?php echo esc_attr($border_classes); ?>">
                        <a href="<?php echo esc_url($fighter['url']); ?>" 
                           class="block p-4 sm:p-5 no-underline group focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]/40 focus-visible:ring-offset-2 rounded-xl visited:text-slate-700">
                            <div class="flex flex-col sm:flex-row gap-4 sm:gap-5 sm:items-center">
                                <!-- Left Cluster: Rank + Avatar + Info -->
                                <div class="flex items-center gap-4 flex-grow">
                                    <!-- Rank Badge -->
                                    <div class="<?php echo esc_attr($rank_base_classes . ' ' . $rank_extra_classes); ?>">
                                        <span class="text-lg">#<?php echo esc_html($fighter['rank']); ?></span>
                                    </div>

                                    <!-- Avatar -->
                                    <div class="shrink-0 rounded-xl ring-1 ring-black/10 overflow-hidden" style="width: 56px; height: 56px; min-width: 56px; min-height: 56px;">
                                        <?php mmaf_opponent_image( array( 'image' => $fighter['image_data'] ) ); ?>
                                    </div>

                                    <!-- Fighter Info -->
                                    <div class="flex-grow min-w-0 flex flex-col justify-center gap-1.5">
                                        <h3 class="font-semibold text-slate-900 text-base md:text-lg leading-tight truncate group-hover:text-[var(--brand)] transition-colors duration-200">
                                            <?php echo esc_html($fighter['name']); ?>
                                        </h3>
                                        
                                        <!-- Meta Pills Row -->
                                        <div class="inline-flex gap-2 flex-wrap">
                                            <span class="text-xs px-2.5 py-1 rounded-full bg-slate-50 ring-1 ring-black/5 text-slate-600">
                                                <?php echo esc_html($fighter['record']); ?>
                                            </span>
                                            <span class="text-xs px-2.5 py-1 rounded-full bg-slate-50 ring-1 ring-black/5 text-slate-600 capitalize">
                                                <?php echo esc_html($fighter['weight_class']); ?>
                                            </span>
                                            <span class="text-xs px-2.5 py-1 rounded-full bg-slate-50 ring-1 ring-black/5 text-slate-600">
                                                <?php echo esc_html($fighter['country']); ?>
                                            </span>
                                            <span class="text-xs px-2.5 py-1 rounded-full bg-slate-50 ring-1 ring-black/5 text-slate-600">
                                                <?php echo esc_html($fighter['finish_rate']); ?> finish
                                            </span>
                                        </div>
                                        
                                        <!-- Secondary Meta Line -->
                                        <p class="text-xs text-slate-500 leading-tight truncate mb-0">
                                            Last bout: <?php echo esc_html($fighter['last_bout']); ?>
                                            <span class="mx-1 text-slate-300">•</span>
                                            Streak: <?php echo esc_html($fighter['streak'] ?: '—'); ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Right Cluster: Score -->
                                <div class="flex sm:flex-col items-center sm:items-end gap-1 sm:flex-shrink-0 self-center min-w-[90px]">
                                    <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest">Score</span>
                                    <div class="flex items-baseline gap-0.5">
                                        <span class="text-3xl font-extrabold text-slate-900 tabular-nums leading-none"><?php echo esc_html($fighter['score']); ?></span>
                                    </div>

                                </div>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</section>
