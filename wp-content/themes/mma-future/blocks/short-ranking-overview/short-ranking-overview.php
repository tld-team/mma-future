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
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg'
    ],
    [
        'id' => 2,
        'name' => 'Milan Petrović',
        'change' => -8,
        'direction' => 'down',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg'
    ],
    [
        'id' => 3,
        'name' => 'Srki Đorđević',
        'change' => 5,
        'direction' => 'up',
        'image' => 'http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aaron-Beltran.png'
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
        'score' => '95.2',
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
        'score' => '94.0',
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
        'score' => '92.5',
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
        'score' => '89.5',
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
        'score' => '87.4',
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
            <div class="flex justify-between items-center mb-8">
                <h2 class="text-3xl sm:text-4xl font-bold text-slate-900">Top Movers</h2>
                <a href="#rankings"
                    class="group text-slate-700 hover:text-[#0047A8] visited:text-slate-700 visited:hover:text-[#0047A8] font-medium transition-colors duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0047A8] no-underline inline-flex items-center gap-1">
                    <span class="relative">
                        View All Rankings
                        <span
                            class="absolute bottom-0 left-0 w-0 h-[1px] bg-[#0047A8] transition-all duration-300 group-hover:w-full"></span>
                    </span>
                    <svg class="w-5 h-5 transition-transform duration-300 group-hover:translate-x-1" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>
            </div>

            <!-- Top Movers Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($top_movers as $mover): ?>
                    <article class="bg-white rounded-xl ring-1 ring-black/10 shadow-sm p-4 flex items-start gap-3">
                        <?php
                        // Determine colors and background based on direction and change magnitude
                        if ($mover['direction'] === 'up'):
                            $abs_change = abs($mover['change']);
                            // Use green for all up movements
                            $icon_color = '#16A34A'; // Green
                            $bg_color = 'rgba(22, 163, 74, 0.1)'; // Light green background
                        ?>
                            <!-- Up Arrow Icon (Diagonal Up-Right) - Professional Design -->
                            <div class="flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center"
                                style="background: <?php echo esc_attr($bg_color); ?>;">
                                <svg class="w-6 h-6" style="color: <?php echo esc_attr($icon_color); ?>;" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 19L19 5m0 0H9m10 0v10"/>
                                </svg>
                            </div>
                        <?php else:
                            $abs_change = abs($mover['change']);
                            // Use red for all down movements
                            $icon_color = '#DC2626'; // Red
                            $bg_color = 'rgba(220, 38, 38, 0.1)'; // Light red background
                        ?>
                            <!-- Down Arrow Icon (Diagonal Down-Right) - Professional Design -->
                            <div class="flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center"
                                style="background: <?php echo esc_attr($bg_color); ?>;">
                                <svg class="w-6 h-6" style="color: <?php echo esc_attr($icon_color); ?>;" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 5L5 19m0 0h10M5 19V9"/>
                                </svg>
                            </div>
                        <?php endif; ?>

                        <div class="flex-grow">
                            <h3 class="text-2xl font-medium text-slate-900 mb-1">
                                <?php echo esc_html($mover['name']); ?>
                            </h3>
                            <p
                                class="text-base font-normal <?php echo $mover['direction'] === 'up' ? 'text-slate-700' : 'text-slate-600'; ?>">
                                <?php
                                $sign = $mover['change'] > 0 ? '+' : '';
                                echo esc_html($sign . $mover['change'] . ' points this week');
                                ?>
                            </p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Current Top 5 Section -->
        <div>
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mb-6">Current Top 5</h2>

            <!-- Top 5 List -->
            <div class="flex flex-col gap-4">
                <?php foreach ($top_five as $fighter): ?>
                    <article class="bg-white rounded-2xl ring-1 ring-black/10 shadow-sm transition-shadow duration-200 hover:shadow-md">
                        <a href="<?php echo esc_url($fighter['url']); ?>" 
                           class="block p-4 sm:p-5 no-underline group">
                            <div class="flex flex-col sm:flex-row gap-4 sm:gap-6 sm:items-center">
                                <!-- Left Cluster: Rank + Avatar + Info -->
                                <div class="flex items-center gap-4 flex-grow">
                                    <!-- Rank Badge -->
                                    <div class="flex-shrink-0 w-12 h-12 flex items-center justify-center">
                                        <span
                                            class="text-2xl font-bold text-slate-400">#<?php echo esc_html($fighter['rank']); ?></span>
                                    </div>

                                    <!-- Avatar -->
                                    <div class="flex-shrink-0 w-12 h-12 sm:w-14 sm:h-14">
                                        <img src="<?php echo esc_url($fighter['image']); ?>" 
                                             alt="<?php echo esc_attr($fighter['name']); ?>"
                                             class="w-full h-full object-cover rounded-lg shadow-sm ring-1 ring-black/5">
                                    </div>

                                    <!-- Fighter Info -->
                                    <div class="flex-grow min-w-0">
                                        <h3 class="font-medium text-slate-900 text-lg mb-1 truncate group-hover:text-[#0047A8] transition-colors duration-200">
                                            <?php echo esc_html($fighter['name']); ?>
                                        </h3>
                                        <div class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-slate-600 mb-1">
                                            <span class="font-normal"><?php echo esc_html($fighter['record']); ?></span>
                                            <span class="font-normal capitalize"><?php echo esc_html($fighter['weight_class']); ?></span>
                                            <span class="font-normal"><?php echo esc_html($fighter['country']); ?></span>
                                        </div>
                                        <div class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-slate-500">
                                            <span class="font-normal"><?php echo esc_html($fighter['finish_rate']); ?> finish rate</span>
                                            <span class="font-normal">Last bout: <?php echo esc_html($fighter['last_bout']); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right Cluster: Score -->
                                <div
                                    class="flex sm:flex-col items-center sm:items-end gap-2 sm:gap-1 sm:flex-shrink-0 sm:min-w-[100px] self-start sm:self-center">
                                    <span class="text-xs font-semibold text-slate-500 tracking-wider uppercase">Score</span>
                                    <span
                                        class="text-3xl sm:text-4xl font-bold text-slate-900"><?php echo esc_html($fighter['score']); ?></span>
                                </div>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</section>