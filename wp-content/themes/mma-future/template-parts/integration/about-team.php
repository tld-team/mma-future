<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<section class="about-team-section py-12 sm:py-16 lg:py-20">
<div class="about-team-section__container max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
<!-- Header -->
<div class="about-team-section__header text-center">
<span class="about-team-section__eyebrow text-xs uppercase tracking-widest !text-slate-400 font-medium"><?php echo esc_html( mmaf_site_field( 'eyebrow' ) ); ?></span>
<h2 class="about-team-section__title mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight !text-slate-900"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
<p class="about-team-section__subtitle mt-3 text-base sm:text-lg !text-slate-600 max-w-3xl mx-auto"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
</div>
<!-- Block A: Core team -->
<?php if ( mmaf_site_field( 'team_members' ) ) : ?><div class="about-team-section__block mt-10">
<h3 class="about-team-section__block-title text-sm font-semibold uppercase tracking-wider !text-slate-500 text-center">
                Core team
            </h3>
<div class="about-team-section__grid mt-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5"><?php foreach ( (array) mmaf_site_field( 'team_members' ) as $row ) : ?><div class="about-team-section__card group !rounded-2xl !border !border-slate-200/70 !bg-white p-6 hover:!border-slate-300 hover:-translate-y-1 hover:!shadow-lg hover:!shadow-slate-200/50 transition-all duration-300 ease-out">
<div class="flex items-start gap-4">
<div class="about-team-section__avatar w-12 h-12 flex-shrink-0 !rounded-xl !bg-slate-100 flex items-center justify-center group-hover:!bg-[rgba(0,71,168,0.08)] group-hover:scale-110 transition-all duration-300"><?php echo mmaf_site_image( $row['member_photo'] ?? null, 'thumbnail', array( 'class' => 'w-full h-full rounded-xl object-cover' ) ); ?></div>
<div class="min-w-0">
<p class="about-team-section__name text-base font-bold !text-slate-900 truncate"><?php echo esc_html( $row['member_name'] ); ?></p>
<span class="about-team-section__pill mt-1.5 inline-flex items-center rounded-full !bg-[rgba(0,71,168,0.10)] px-3 py-1 text-xs font-semibold !text-[#0047A8]"><?php echo esc_html( $row['member_role'] ); ?></span>
</div>
</div>
<p class="about-team-section__bio mt-4 text-sm !text-slate-600 leading-relaxed line-clamp-2"><?php echo esc_html( $row['member_description'] ); ?></p>
<div class="about-team-section__meta mt-3 flex items-center gap-1.5 text-xs !text-slate-400"><?php echo esc_html( $row['member_focus'] ); ?></div>
<div class="about-team-section__socials mt-4 flex items-center gap-2"><?php mmaf_site_link( $row['member_link'] ?? null, 'about-team-section__social-link text-sm' ); ?></div>
</div><?php endforeach; ?></div>
</div><?php endif; ?>
<!-- Block B: Collaborators -->

<!-- CTA -->
<div class="about-team-section__cta mt-10 text-center"><?php mmaf_site_link( mmaf_site_field( 'primary_link' ), 'about-team-section__cta-link' ); mmaf_site_link( mmaf_site_field( 'secondary_link' ), 'about-team-section__cta-link ml-4' ); ?></div>
</div>
</section>
