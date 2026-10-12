<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<section class="about-highlighted-data-section py-12 sm:py-16 lg:py-20 !bg-white">
<div class="about-highlighted-data-section__container max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
<!-- A) Header -->
<div class="about-highlighted-data-section__header">
<h2 class="about-highlighted-data-section__title text-3xl sm:text-4xl font-extrabold tracking-tight !text-slate-900"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
<p class="about-highlighted-data-section__subtitle mt-3 max-w-3xl text-base sm:text-lg !text-slate-600 leading-relaxed"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
</div>
<!-- B) Card grid -->
<div class="about-highlighted-data-section__grid mt-8 sm:mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
<!-- Card 1: Data sources -->
<div class="about-highlighted-data-section__card !rounded-2xl !border !border-slate-200/70 !bg-white p-6">
<div class="about-highlighted-data-section__icon-wrap h-11 w-11 rounded-xl !bg-slate-50 !border !border-slate-200/70 flex items-center justify-center">
<svg aria-hidden="true" class="about-highlighted-data-section__icon h-5 w-5 !text-slate-500" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"></path>
</svg>
</div>
<h3 class="about-highlighted-data-section__card-title mt-5 text-lg font-bold !text-slate-900">Data sources</h3>
<div class="about-highlighted-data-section__card-text mt-2 text-sm sm:text-base !text-slate-600 leading-relaxed"><?php echo wp_kses_post( wpautop( (string) mmaf_site_field( 'data_sources_text' ) ) ); ?></div>
</div>
<!-- Card 2: Update schedule -->
<div class="about-highlighted-data-section__card !rounded-2xl !border !border-slate-200/70 !bg-white p-6">
<div class="about-highlighted-data-section__icon-wrap h-11 w-11 rounded-xl !bg-slate-50 !border !border-slate-200/70 flex items-center justify-center">
<svg aria-hidden="true" class="about-highlighted-data-section__icon h-5 w-5 !text-slate-500" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"></path>
</svg>
</div>
<h3 class="about-highlighted-data-section__card-title mt-5 text-lg font-bold !text-slate-900">Update schedule</h3>
<div class="about-highlighted-data-section__card-text mt-2 text-sm sm:text-base !text-slate-600 leading-relaxed"><?php echo wp_kses_post( wpautop( (string) mmaf_site_field( 'update_schedule_text' ) ) ); ?></div>
</div>
<!-- Card 3: Known limitations -->
<div class="about-highlighted-data-section__card !rounded-2xl !border !border-slate-200/70 !bg-white p-6">
<div class="about-highlighted-data-section__icon-wrap h-11 w-11 rounded-xl !bg-slate-50 !border !border-slate-200/70 flex items-center justify-center">
<svg aria-hidden="true" class="about-highlighted-data-section__icon h-5 w-5 !text-slate-500" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"></path>
</svg>
</div>
<h3 class="about-highlighted-data-section__card-title mt-5 text-lg font-bold !text-slate-900">Known limitations</h3>
<div class="about-highlighted-data-section__card-text mt-2 text-sm sm:text-base !text-slate-600 leading-relaxed"><?php echo wp_kses_post( wpautop( (string) mmaf_site_field( 'limitations_text' ) ) ); ?></div>
</div>
</div>
<!-- C) Disclaimer -->
<?php if ( mmaf_site_field( 'disclaimer' ) ) : ?><div class="about-highlighted-data-section__disclaimer mt-8 !rounded-xl !border !border-slate-200/70 !bg-slate-50/40 px-5 py-4"><?php echo esc_html( mmaf_site_field( 'disclaimer' ) ); ?></div><?php endif; ?>
</div>
</section>
