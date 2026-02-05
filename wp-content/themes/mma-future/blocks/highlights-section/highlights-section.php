<?php
/**
 * Highlights Section Block Template
 * Block: acf/highlights-section
 */

// Read ACF field values
$section_title = get_field('title');
$section_subtitle = get_field('subtitle');
$cta = get_field('cta_button');
$rows = get_field('highlights_list');
$count = is_array($rows) ? count($rows) : 0;

// Check if we have header content
$has_header_content = !empty($section_title) || !empty($section_subtitle);

// Fallback values
if (empty($section_title)) {
    $section_title = 'Transparent Ranking System';
}
if (empty($section_subtitle)) {
    $section_subtitle = 'Our formula evaluates fighters across multiple dimensions for fair, data-driven rankings.';
}

// CTA values with fallbacks
$has_cta = !empty($cta) && is_array($cta) && !empty($cta['url']);
$cta_url = '#methodology';
$cta_title = 'Read Full Methodology';
$cta_target = '';
if ($has_cta) {
    $cta_url = $cta['url'];
    if (!empty($cta['title'])) {
        $cta_title = $cta['title'];
    }
    if (!empty($cta['target'])) {
        $cta_target = $cta['target'];
    }
}

// Dynamic desktop layout class
$lg_basis_class = ($count >= 4) ? 'lg:basis-[calc(25%-18px)]' : 'lg:basis-[calc(33.333%-16px)]';
?>
<section id="highlights-section" class="highlights-section py-20 mt-20 mb-10 bg-white">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Section Header -->
        <?php if ($has_header_content) : ?>
        <div class="text-center mb-16">
            <h2 class="text-xl md:text-2xl font-extrabold text-slate-900 mb-4">
                <?php echo esc_html($section_title); ?>
            </h2>
            <p class="text-xl text-slate-600 max-w-2xl mx-auto">
                <?php echo esc_html($section_subtitle); ?>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Highlight Cards Grid -->
        <div class="flex flex-wrap gap-6 justify-center mb-12">
            
            <?php if (have_rows('highlights_list')) : ?>
                <?php while (have_rows('highlights_list')) : the_row(); ?>
                    <?php
                    $icon = get_sub_field('icon');
                    $card_title = get_sub_field('title');
                    $card_desc = get_sub_field('description');
                    
                    // Icon wrapper style - soft brand tint with subtle ring (no hard border)
                    $icon_wrapper_style = '';
                    $svg_style = 'color: var(--brand);';
                    
                    if (!empty($icon) && is_array($icon) && !empty($icon['url'])) {
                        $icon_url = esc_url($icon['url']);
                        $icon_wrapper_style = 'background-image: url(' . $icon_url . '); background-repeat: no-repeat; background-position: center; background-size: 24px 24px;';
                        $svg_style .= ' display: none;';
                    }
                    ?>
            <article class="w-full sm:basis-[calc(50%-12px)] <?php echo esc_attr($lg_basis_class); ?> bg-white rounded-2xl ring-1 ring-black/5 shadow-sm p-6 flex flex-col h-full hover:shadow-md hover:ring-black/10 transition duration-200">
                <div class="mb-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-[rgba(var(--brand-rgb),0.08)] ring-1 ring-[rgba(var(--brand-rgb),0.18)]" style="<?php echo esc_attr($icon_wrapper_style); ?>">
                        <svg class="w-6 h-6" style="<?php echo esc_attr($svg_style); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                </div>
                <h3 class="text-base md:text-lg font-semibold text-slate-900 mb-3">
                    <?php echo esc_html($card_title); ?>
                </h3>
                <p class="text-slate-600 leading-relaxed flex-grow">
                    <?php echo esc_html($card_desc); ?>
                </p>
            </article>
                <?php endwhile; ?>
            <?php endif; ?>
            
        </div>
        
        <!-- CTA Button -->
        <?php if ($has_cta) : ?>
        <div class="text-center">
            <a href="<?php echo esc_url($cta_url); ?>"<?php if ($cta_target === '_blank') : ?> target="_blank" rel="noopener noreferrer"<?php elseif (!empty($cta_target)) : ?> target="<?php echo esc_attr($cta_target); ?>"<?php endif; ?> class="inline-flex items-center justify-center gap-2 h-12 px-8 rounded-xl bg-slate-700 hover:bg-slate-800 text-white font-semibold text-lg ring-1 ring-slate-600/20 shadow-sm transition-all duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700 no-underline">
                <span><?php echo esc_html($cta_title); ?></span>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                </svg>
            </a>
        </div>
        <?php endif; ?>
        
    </div>
</section>