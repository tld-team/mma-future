<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<section class="about-featured-section py-12 sm:py-16 lg:py-20 bg-white">
<div class="about-featured-section__container max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="about-featured-section__header">
<h2 class="about-featured-section__title text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
<p class="about-featured-section__subtitle mt-3 max-w-2xl text-base sm:text-lg text-slate-600 leading-relaxed"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
</div>
<div class="about-featured-section__grid mt-8 sm:mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"><?php foreach ( (array) mmaf_site_field( 'features' ) as $index => $row ) : ?><div class="about-featured-section__card rounded-2xl border border-slate-200/70 bg-white p-6 hover:border-slate-300 transition-colors">
<div class="about-featured-section__icon h-10 w-10 rounded-xl bg-slate-50 border border-slate-200/70 flex items-center justify-center"><?php if ( ! empty( $row['feature_icon'] ) ) { echo mmaf_site_image( $row['feature_icon'], 'thumbnail', array( 'class' => 'w-5 h-5 object-contain' ) ); } else { ?><?php if ( $index % 3 === 0 ) : ?><svg aria-hidden="true" class="h-5 w-5 text-[#0047A8]" fill="none" stroke="currentColor" viewbox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
<path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
<path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg><?php elseif ( $index % 3 === 1 ) : ?><svg aria-hidden="true" class="h-5 w-5 text-[#0047A8]" fill="none" stroke="currentColor" viewbox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
<path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg><?php elseif ( $index % 3 === 2 ) : ?><svg aria-hidden="true" class="h-5 w-5 text-[#0047A8]" fill="none" stroke="currentColor" viewbox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
<path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg><?php endif; ?><?php } ?></div>
<h3 class="about-featured-section__card-title mt-4 text-lg font-bold text-slate-900"><?php echo esc_html( $row['feature_title'] ); ?></h3>
<p class="about-featured-section__card-text mt-2 text-sm sm:text-base text-slate-600 leading-relaxed"><?php echo esc_html( $row['feature_description'] ); ?></p>
</div><?php endforeach; ?></div>
</div>
</section>
