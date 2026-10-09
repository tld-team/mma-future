<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<section class="about-hero py-12 sm:py-16 lg:py-20 bg-white">
<div class="about-hero__container max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
<h1 class="about-hero__title font-extrabold tracking-tight text-slate-900"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h1>
<p class="about-hero__subtitle mt-4 text-base sm:text-lg text-slate-600 leading-relaxed max-w-2xl mx-auto"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
<div class="about-hero__chips mt-6 flex flex-wrap justify-center gap-2"><?php foreach ( (array) mmaf_site_field( 'badges' ) as $row ) : ?><span class="about-hero__chip inline-flex items-center gap-2 h-8 px-3 rounded-full bg-slate-50 border border-slate-200/70 text-sm font-medium text-slate-700 transition hover:bg-slate-100 hover:border-slate-300"><?php echo esc_html( $row['badge_text'] ); ?></span><?php endforeach; ?></div>
<div class="about-hero__actions mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
<?php mmaf_presentation_link( mmaf_site_field( 'primary_link' ), 'about-hero__btn about-hero__btn--primary group inline-flex items-center justify-center gap-2 h-11 w-full sm:w-auto px-5 rounded-xl bg-white border border-slate-200/70 text-[#0047A8] text-sm sm:text-base font-semibold no-underline transition-colors duration-200 ease-out hover:!bg-[#0047A8] hover:!border-[#0047A8] hover:!text-white active:translate-y-[1px] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0' ); ?>
<?php mmaf_presentation_link( mmaf_site_field( 'secondary_link' ), 'about-hero__btn about-hero__btn--secondary inline-flex items-center justify-center gap-2 h-11 w-full sm:w-auto px-5 rounded-xl bg-white text-[#0047A8] border border-[#0047A8]/30 text-sm sm:text-base font-semibold no-underline transition-all duration-200 ease-out hover:bg-slate-50 hover:border-[#0047A8]/55 hover:-translate-y-0.5 active:translate-y-0 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0' ); ?>
</div>
</div>
</section>
