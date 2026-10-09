<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $location = has_block( 'acf/all-rankings-table-section', get_queried_object_id() ) ? 'rankings' : 'about'; $items = array_filter( (array) mmaf_site_setting( 'faq_items' ), static fn( $row ) => ! empty( $row['question'] ) && in_array( $row['show_on'] ?? 'both', array( 'both', $location ), true ) ); ?>

<section class="about-faq-section py-12 sm:py-16 lg:py-20">
<div class="about-faq-section__container max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="about-faq-section__header">
<h2 class="about-faq-section__title text-3xl sm:text-4xl font-extrabold tracking-tight !text-slate-900 text-center"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
<p class="about-faq-section__subtitle mt-3 text-base sm:text-lg !text-slate-600 text-center"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
</div>
<div class="about-faq-section__list mt-10 border-t border-slate-200/70"><?php foreach ( $items as $index => $row ) : ?><details class="about-faq-section__item group border-b border-slate-200/70" <?php echo 0 === $index ? 'open' : ''; ?>>
<summary class="about-faq-section__summary !flex items-center justify-between gap-4 py-5 cursor-pointer select-none list-none focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
<span class="about-faq-section__question text-base sm:text-lg font-semibold !text-slate-900 leading-snug"><?php echo esc_html( $row['question'] ); ?></span>
<svg aria-hidden="true" class="about-faq-section__chevron w-5 h-5 !text-slate-400 transition-transform duration-200 group-open:rotate-180 flex-shrink-0" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
</summary>
<div class="about-faq-section__panel pb-6 pr-8">
<div class="about-faq-section__answer text-sm sm:text-base !text-slate-600 leading-relaxed"><?php echo wp_kses_post( $row['answer'] ); ?></div>
</div>
</details><?php endforeach; ?></div>
</div>
</section>
