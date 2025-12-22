<?php
$blocks_id = $block['id'];
$blocks_class = isset($block['class']) ? $block['class'] : '';
$anchor = isset($block['anchor']) ? $block['anchor'] : $blocks_id;

// ACF Fields
$background_image = get_field('background_image');
$bg_image_url = !empty($background_image['url']) ? $background_image['url'] : '';
$title = get_field('title');
$subtitle = get_field('subtitle');
$primary_cta_button = get_field('primary_cta_button');
$secondary_cta_button = get_field('secondary_cta_button');
?>

<section id="<?php echo esc_attr($anchor); ?>" class="hp-hero-section relative min-h-screen flex items-center justify-center <?php echo esc_attr($blocks_class); ?>" <?php if (!empty($bg_image_url)) : ?>style="background-image: url('<?php echo esc_url($bg_image_url); ?>'); background-size: auto; background-position: center; background-repeat: no-repeat;"<?php endif; ?>>
    
    <!-- Dark Overlay -->
    <div class="absolute inset-0 bg-black/50 z-0"></div>
    
    <!-- Content (Above Overlay) -->
    <div class="container mx-auto px-4 relative z-10">
        <div class="text-center max-w-5xl mx-auto">
            <!-- Main Headline -->
            <?php if (!empty($title)) : ?>
            <h1 class="font-heading font-black mb-4 text-white uppercase tracking-tighter leading-none" style="font-size: clamp(3rem, 8vw, 6rem); font-weight: 900;">
                <?php echo esc_html($title); ?>
            </h1>
            <?php endif; ?>
            
            <!-- Eyebrow Copy Text / Subtitle -->
            <?php if (!empty($subtitle)) : ?>
            <p class="text-lg md:text-2xl text-white/95 mb-12 max-w-3xl mx-auto font-normal leading-tight md:leading-snug">
                <?php echo esc_html($subtitle); ?>
            </p>
            <?php endif; ?>
            
            <!-- Call-to-Action Buttons -->
            <?php if (!empty($primary_cta_button['url']) || !empty($secondary_cta_button['url'])) : ?>
            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <!-- Primary CTA Button - View Rankings -->
                <?php if (!empty($primary_cta_button['url'])) : ?>
                <a href="<?php echo esc_url($primary_cta_button['url']); ?>" <?php echo !empty($primary_cta_button['target']) ? 'target="' . esc_attr($primary_cta_button['target']) . '"' : ''; ?> class="hero-cta-primary w-full sm:w-auto inline-flex items-center justify-center gap-2 h-14 px-8 rounded-2xl font-heading font-semibold text-base uppercase no-underline ring-1 ring-white/25 visited:text-white" style="text-decoration: none !important;">
                    <span><?php echo esc_html(!empty($primary_cta_button['title']) ? $primary_cta_button['title'] : 'View Rankings'); ?></span>
                    <svg class="arrow-icon w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                    </svg>
                </a>
                <?php endif; ?>
                
                <!-- Secondary CTA Button - How it works -->
                <?php if (!empty($secondary_cta_button['url'])) : ?>
                <a href="<?php echo esc_url($secondary_cta_button['url']); ?>" <?php echo !empty($secondary_cta_button['target']) ? 'target="' . esc_attr($secondary_cta_button['target']) . '"' : ''; ?> class="hero-cta-secondary w-full sm:w-auto inline-flex items-center justify-center gap-2 h-14 px-8 rounded-2xl font-heading font-semibold text-base no-underline ring-1 ring-white/30 visited:text-white" style="text-decoration: none !important;">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M8 5v14l11-7z"></path>
                    </svg>
                    <span><?php echo esc_html(!empty($secondary_cta_button['title']) ? $secondary_cta_button['title'] : 'How it works?'); ?></span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>