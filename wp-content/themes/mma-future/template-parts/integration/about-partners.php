<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $partners = array_filter( (array) mmaf_site_setting( 'partners' ), static fn( $row ) => ! empty( $row['partner_name'] ) && ! empty( $row['partner_logo'] ) ); if ( ! $partners ) { return; } ?>

<section class="about-partners-section <?php echo count( $partners ) === 1 ? 'about-partners-section--static' : ''; ?> py-12 sm:py-14">
<div class="about-partners-section__container max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="about-partners-section__header text-center">
<span class="about-partners-section__eyebrow text-xs uppercase tracking-widest !text-slate-400 font-medium"><?php echo esc_html( mmaf_site_field( 'eyebrow' ) ); ?></span>
<h2 class="about-partners-section__title mt-2 text-lg sm:text-xl font-semibold !text-slate-800"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
<p class="about-partners-section__subtitle mt-1.5 text-sm !text-slate-500"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
</div>
<div class="about-partners-section__marquee relative mt-7 overflow-hidden">
<div class="about-partners-section__fade-left absolute left-0 top-0 bottom-0 w-12 sm:w-16 z-10 pointer-events-none" style="background:linear-gradient(to right,white,transparent)"></div>
<div class="about-partners-section__fade-right absolute right-0 top-0 bottom-0 w-12 sm:w-16 z-10 pointer-events-none" style="background:linear-gradient(to left,white,transparent)"></div>
<div class="about-partners-section__track flex items-center whitespace-nowrap">
<div class="about-partners-section__set flex items-center gap-10 sm:gap-14"><?php foreach ( $partners as $row ) : ?><div class="about-partners-section__item flex-shrink-0 flex items-center justify-center"><?php $logo = mmaf_site_image( $row['partner_logo'], 'medium', array( 'alt' => $row['partner_name'], 'class' => 'max-h-12 w-auto object-contain' ) ); $url = $row['partner_link']['url'] ?? ''; if ( $url ) { echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $logo . '</a>'; } else { echo $logo; } ?></div><?php endforeach; ?></div>
<div aria-hidden="true" class="about-partners-section__set flex items-center gap-10 sm:gap-14" inert=""><?php foreach ( $partners as $row ) : ?><div class="about-partners-section__item flex-shrink-0 flex items-center justify-center"><?php $logo = mmaf_site_image( $row['partner_logo'], 'medium', array( 'alt' => $row['partner_name'], 'class' => 'max-h-12 w-auto object-contain' ) ); $url = $row['partner_link']['url'] ?? ''; if ( $url ) { echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $logo . '</a>'; } else { echo $logo; } ?></div><?php endforeach; ?></div>
</div>
</div>
<p class="about-partners-section__disclaimer mt-5 text-center text-xs !text-slate-400"><?php echo esc_html( mmaf_site_field( 'disclaimer' ) ); ?></p>
</div>
</section>
