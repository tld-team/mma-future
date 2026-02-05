<?php
/**
 * Short Ranking Overview Block Template
 * Block: acf/short-ranking-overview
 * Static HTML snapshot with placeholder data
 */

// Placeholder data for Top Movers
$top_movers = [
    [
        'id' => 1,
        'name' => 'Pera Perić',
        'change' => 12,
        'direction' => 'up',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg',
        'url' => '#'
    ],
    [
        'id' => 2,
        'name' => 'Milan Petrović',
        'change' => -8,
        'direction' => 'down',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg',
        'url' => '#'
    ],
    [
        'id' => 3,
        'name' => 'Srki Đorđević',
        'change' => 5,
        'direction' => 'up',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aaron-Beltran.png',
        'url' => '#'
    ]
];

// Placeholder data for Top 5 Rankings
$top_five = [
    [
        'id' => 1,
        'rank' => 1,
        'name' => 'Pera Perić',
        'record' => '15-02-00',
        'weight_class' => 'lightweight',
        'country' => 'USA',
        'finish_rate' => '72%',
        'last_bout' => 'Dec 2024',
        'streak' => 'W3',
        'score' => '95.2',
        'score_delta' => '+1.4',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg',
        'url' => '#'
    ],
    [
        'id' => 2,
        'rank' => 2,
        'name' => 'Pera Perić',
        'record' => '15-02-00',
        'weight_class' => 'lightweight',
        'country' => 'USA',
        'finish_rate' => '72%',
        'last_bout' => 'Dec 2024',
        'streak' => 'W2',
        'score' => '94.0',
        'score_delta' => '+0.8',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg',
        'url' => '#'
    ],
    [
        'id' => 3,
        'rank' => 3,
        'name' => 'Pera Perić',
        'record' => '15-02-00',
        'weight_class' => 'lightweight',
        'country' => 'USA',
        'finish_rate' => '72%',
        'last_bout' => 'Dec 2024',
        'streak' => 'W1',
        'score' => '92.5',
        'score_delta' => '+0.3',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg',
        'url' => '#'
    ],
    [
        'id' => 4,
        'rank' => 4,
        'name' => 'Pera Perić',
        'record' => '15-03-00',
        'weight_class' => 'lightweight',
        'country' => 'USA',
        'finish_rate' => '72%',
        'last_bout' => 'Dec 2024',
        'streak' => 'L1',
        'score' => '89.5',
        'score_delta' => '-0.5',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abdullo_Khodzhaev-hero-1200x1165-1_cropped.jpg',
        'url' => '#'
    ],
    [
        'id' => 5,
        'rank' => 5,
        'name' => 'Pera Perić',
        'record' => '15-02-00',
        'weight_class' => 'lightweight',
        'country' => 'USA',
        'finish_rate' => '72%',
        'last_bout' => 'Dec 2024',
        'streak' => 'W2',
        'score' => '87.4',
        'score_delta' => '+0.2',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/abdulaziz-datsilaev_500x500.jpg',
        'url' => '#'
    ]
];
?>

<section id="rankings-snapshot" class="py-16 bg-white">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Top Movers Section -->
        <div class="mb-20">
            <!-- Header Row -->
            <div class="flex justify-between items-center mb-10">
                <h2 class="text-xl md:text-2xl font-extrabold text-slate-900 leading-tight">Top Movers</h2>
                <a href="#rankings"
                    class="group inline-flex items-center gap-2 h-10 px-4 rounded-xl bg-white ring-1 ring-black/10 shadow-sm text-slate-700 font-semibold text-sm transition-all duration-200 hover:bg-slate-50 hover:ring-black/15 hover:shadow-md hover:text-[#0B3AA4] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]/35 focus-visible:ring-offset-2 visited:text-slate-700 no-underline">
                    <span>View All Rankings</span>
                    <svg class="w-4 h-4 transition-transform duration-200 group-hover:translate-x-0.5" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>
            </div>

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
                                    <span class="block text-[10px] uppercase tracking-wider text-slate-500 mb-1">Weekly Change</span>
                                    
                                    <!-- Delta as plain text -->
                                    <span class="text-sm font-medium <?php echo esc_attr($delta_text_classes); ?>">
                                        <?php echo esc_html($sign . $mover['change']); ?> points this week
                                    </span>
                                </div>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Subtle divider that fades at edges -->
        <div class="h-px w-full mb-8" style="background: linear-gradient(to right, transparent, rgba(0,0,0,0.08) 20%, rgba(0,0,0,0.08) 80%, transparent);"></div>

        <!-- Current Top 5 Section -->
        <div class="mt-12">
            <h2 class="text-xl md:text-2xl font-extrabold text-slate-900 leading-tight mb-6">Current Top 5</h2>

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
                    
                    // Calculate score percentage for meter (assuming max 100)
                    $score_num = floatval($fighter['score']);
                    $score_pct = min(100, $score_num);
                    
                    // Score delta display
                    $score_delta = isset($fighter['score_delta']) ? $fighter['score_delta'] : '+0.0';
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
                                        <img src="<?php echo esc_url($fighter['image']); ?>" 
                                             alt="<?php echo esc_attr($fighter['name']); ?>"
                                             style="width: 100%; height: 100%; object-fit: cover;">
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
                                            Streak: <?php echo esc_html(isset($fighter['streak']) ? $fighter['streak'] : 'W1'); ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Right Cluster: Score -->
                                <div class="flex sm:flex-col items-center sm:items-end gap-1 sm:flex-shrink-0 self-center min-w-[90px]">
                                    <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest">Score</span>
                                    <div class="flex items-baseline gap-0.5">
                                        <span class="text-3xl font-extrabold text-slate-900 tabular-nums leading-none"><?php echo esc_html($fighter['score']); ?></span>
                                    </div>
                                    <!-- Score meter -->
                                    <div class="hidden sm:block w-full h-1.5 bg-slate-100 rounded-full mt-1">
                                        <div class="h-full bg-[var(--brand)] rounded-full" style="width: <?php echo esc_attr($score_pct); ?>%;"></div>
                                    </div>
                                    <!-- Score delta detail -->
                                    <span class="hidden sm:block text-xs text-slate-500 mt-0.5">
                                        Δ <?php echo esc_html($score_delta); ?> this week
                                    </span>
                                </div>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</section>
