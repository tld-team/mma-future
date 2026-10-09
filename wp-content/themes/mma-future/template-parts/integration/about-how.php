<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $steps = (array) mmaf_site_field( 'steps' ); $example = null; $example_ranking = null; $id = (int) mmaf_site_field( 'example_fighter_id' ); if ( $id && mmaf_public_service() ) { $example = mmaf_public_service()->fighter( $id ); if ( ! is_wp_error( $example ) ) { foreach ( $example['rankings'] as $ranking ) { if ( 'overall' === $ranking['board'] ) { $example_ranking = $ranking; break; } } } } ?>

<section class="how-it-works-section py-12 sm:py-16 lg:py-20 !bg-white">
<div class="how-it-works-section__container max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
<!-- A) Header -->
<div class="how-it-works-section__header">
<h2 class="how-it-works-section__title text-3xl sm:text-4xl font-extrabold tracking-tight !text-slate-900"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
<p class="how-it-works-section__intro mt-3 max-w-3xl text-base sm:text-lg !text-slate-600 leading-relaxed"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
</div>
<!-- B) Flow diagram -->
<?php if ( $steps ) : ?>
<div class="how-it-works-section__flow mt-10 grid grid-cols-1 gap-4 items-stretch" style="--mmaf-flow-columns:<?php echo esc_attr( implode( ' auto ', array_fill( 0, count( $steps ), 'minmax(0,1fr)' ) ) ); ?>">
 <?php foreach ( $steps as $index => $step ) : $last = $index === count( $steps ) - 1; ?>
 <div class="how-it-works-section__flow-card !rounded-xl p-5 <?php echo $last ? '!border-2 !border-[#0047A8]/70' : '!border !border-slate-200/70 !bg-white'; ?>" <?php if ( $last ) { echo 'style="background:rgba(0,71,168,0.06)"'; } ?>>
  <h3 class="how-it-works-section__flow-card-title text-xs font-semibold uppercase tracking-wider !text-slate-400"><?php echo esc_html( $step['step_title'] ); ?></h3>
  <p class="mt-3 text-sm !text-slate-600 leading-relaxed"><?php echo esc_html( $step['step_description'] ); ?></p>
 </div>
 <?php if ( ! $last ) : ?>
 <div class="how-it-works-section__flow-arrow flex items-center justify-center !text-slate-400"><svg class="w-6 h-6 rotate-90 lg:rotate-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M13 7l5 5m0 0l-5 5m5-5H6" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></svg></div>
 <?php endif; endforeach; ?>
</div>
<?php endif; ?>
<!-- C) Key Principles -->
<div class="how-it-works-section__principles mt-8 mb-10 !rounded-xl !border !border-[#0047A8]/25 p-6" style="background: rgba(0,71,168,0.06);">
<h3 class="how-it-works-section__principles-title text-xs font-semibold uppercase tracking-wider !text-slate-500 mb-2">Key Principles</h3>
<ul class="how-it-works-section__principles-list mt-4 grid grid-cols-1 md:grid-cols-2 gap-3"><?php foreach ( (array) mmaf_site_field( 'principles' ) as $row ) : ?><li class="how-it-works-section__principle flex items-start gap-3 text-sm !text-slate-700"><svg aria-hidden="true" class="how-it-works-section__principle-icon mt-0.5 h-5 w-5 !text-[#0047A8] flex-shrink-0" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
</svg><?php echo esc_html( $row['principle_text'] ); ?></li><?php endforeach; ?></ul>
</div>
<!-- D) Example Score Breakdown -->
<div class="how-it-works-section__breakdown mt-10"><?php if ( $example_ranking ) : ?><h3 class="text-xl font-bold !text-slate-900"><?php echo esc_html( $example['fighter']['display_name'] ); ?></h3><?php mmaf_score_details( $example_ranking ); endif; ?></div>
</div>
</section>
