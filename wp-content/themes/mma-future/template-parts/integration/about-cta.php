<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="about-cta-section py-12 sm:py-16 bg-white">
 <div class="max-w-6xl mx-auto px-4 text-center">
  <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
  <p class="mt-4 max-w-2xl mx-auto text-slate-600 leading-relaxed"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
  <div class="mt-6 flex flex-wrap justify-center gap-3">
   <?php mmaf_presentation_link( mmaf_site_field( 'primary_link' ), 'about-hero__btn about-hero__btn--primary inline-flex items-center gap-2 rounded-xl px-5 py-3 bg-[#0047A8] !text-white text-sm font-semibold' ); ?>
   <?php mmaf_presentation_link( mmaf_site_field( 'secondary_link' ), 'about-hero__btn about-hero__btn--secondary inline-flex items-center gap-2 rounded-xl px-5 py-3 border border-slate-200 text-slate-700 text-sm font-semibold' ); ?>
  </div>
 </div>
</section>
