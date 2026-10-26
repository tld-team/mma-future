<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<section class="mf-hero fighter-hero-section relative min-h-[75vh] flex items-center py-12 lg:py-20 overflow-hidden">
<!-- Parallax Background -->
<div class="absolute inset-0 w-full h-full z-0" style="background-image:url('<?php echo esc_url( get_template_directory_uri() . '/assets/dist/images/fighter-background.png' ); ?>');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed;">
</div>
<!-- Dark Overlay -->
<div class="absolute inset-0 bg-gradient-to-br from-black/70 via-black/60 to-black/70 z-0"></div>
<!-- Content Container -->
<div class="container mx-auto px-4 xl:px-6 relative z-10">
<div class="grid grid-cols-1 lg:grid-cols-[auto_1fr_auto] gap-8 lg:gap-8 xl:gap-12 2xl:gap-16 items-start lg:items-center">
<!-- Left Column: Fighter Portrait -->
<div class="flex justify-center lg:justify-start lg:w-56 xl:w-72 2xl:w-80">
<div class="group relative w-full max-w-xs lg:max-w-none aspect-square cursor-pointer">
<!-- Multi-layer border frame -->
<div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-primary-500/30 to-accent/30 group-hover:from-primary-400/50 group-hover:to-accent/50 p-1 transition-all duration-500 group-hover:shadow-lg group-hover:shadow-primary-500/30">
<div class="w-full h-full rounded-2xl bg-gradient-to-br from-secondary-900/90 to-secondary-800/90 p-2">
<div class="w-full h-full rounded-xl overflow-hidden shadow-fighter group-hover:shadow-xl transition-shadow duration-500">
<?php mmaf_fighter_image( $c, 'w-full h-full object-cover transition-all duration-500 group-hover:scale-110 group-hover:brightness-110' ); ?>
</div>
</div>
</div>
</div>
</div>
<!-- Right Column: Fighter Info -->
<div class="space-y-6 lg:space-y-5 xl:space-y-6 2xl:space-y-8 text-center lg:text-left">
<!-- Fighter Name -->
<h1 class="font-heading font-black text-3xl xs:text-4xl md:text-5xl lg:text-4xl xl:text-5xl 2xl:text-6xl text-white leading-tight tracking-tight" style="font-weight: 950;"><?php echo esc_html( $f['display_name'] ); ?></h1><?php if ( ! empty( $f['nickname'] ) ) : ?><p class="text-white/80 font-heading"><?php echo esc_html( $f['nickname'] ); ?></p><?php endif; ?>
<?php if ( ! empty( $c['ufc_signing']['confirmed'] ) ) : ?><div class="rounded-xl border border-primary-400/40 bg-primary-500/20 px-5 py-4 text-left"><strong class="block text-white text-lg">Signed with the UFC</strong><span class="text-white/80">This fighter is no longer ranked by MMA Future.</span></div><?php endif; ?>
<!-- Meta Information Row -->
<div class="flex flex-wrap items-center gap-3 xs:gap-4 md:gap-6 lg:gap-4 xl:gap-6 justify-center lg:justify-start text-white/90">
<!-- Nationality -->
<?php if ( ! empty( $facts['Nationality'] ) ) : ?><div class="flex items-center gap-1.5 xs:gap-2">
<svg aria-hidden="true" class="w-4 xs:w-5 h-4 xs:h-5 text-primary-400" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
</path>
</svg>
<span class="text-xs xs:text-sm md:text-base lg:text-sm xl:text-base font-medium"><?php echo esc_html( $facts['Nationality'] ); ?></span>
</div><?php endif; ?>
<!-- Height -->
<?php if ( ! empty( $facts['Height'] ) ) : ?><div class="flex items-center gap-1.5 xs:gap-2">
<svg aria-hidden="true" class="w-4 xs:w-5 h-4 xs:h-5 text-primary-400" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
</path>
</svg>
<span class="text-xs xs:text-sm md:text-base lg:text-sm xl:text-base font-medium"><?php echo esc_html( $facts['Height'] ); ?></span>
</div><?php endif; ?>
<!-- Date of Birth -->
<?php if ( ! empty( $facts['Date of birth'] ) ) : ?><div class="flex items-center gap-1.5 xs:gap-2">
<svg aria-hidden="true" class="w-4 xs:w-5 h-4 xs:h-5 text-primary-400" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
</path>
</svg>
<span class="text-xs xs:text-sm md:text-base lg:text-sm xl:text-base font-medium"><?php echo esc_html( $facts['Date of birth'] ); ?></span>
</div><?php endif; ?>
</div>
<!-- Category Tags -->
<div class="flex flex-wrap gap-2 justify-center lg:justify-start">
<span class="inline-flex items-center px-3 xs:px-4 py-1.5 xs:py-2 rounded-full bg-primary-500/20 border border-primary-400/30 text-primary-300 text-xs xs:text-sm font-semibold uppercase tracking-wide backdrop-blur-sm"><?php echo esc_html( $facts['Division'] ?? 'Unknown division' ); ?></span>
<?php if ( empty( $c['ufc_signing']['confirmed'] ) ) : ?><span class="inline-flex items-center px-3 xs:px-4 py-1.5 xs:py-2 rounded-full bg-accent/20 border border-accent/30 text-white text-xs xs:text-sm font-semibold uppercase tracking-wide backdrop-blur-sm"><?php echo esc_html( $overall ? '#' . $overall['rank'] . ' Overall' : 'Unranked' ); ?></span><?php endif; ?>
<?php if ( mmaf_fighter_has_fight_data( $c ) && ! empty( $stats['activity_status'] ) ) : ?><span class="inline-flex items-center px-3 xs:px-4 py-1.5 xs:py-2 rounded-full bg-white/10 border border-white/20 text-white/90 text-xs xs:text-sm font-semibold uppercase tracking-wide backdrop-blur-sm"><?php echo esc_html( ucwords( str_replace( '_', ' ', $stats['activity_status'] ) ) ); ?></span><?php endif; ?>
</div>
<!-- Stats Row -->
<div class="grid grid-cols-[1.8fr_1.3fr_1.4fr] gap-6 sm:gap-8 md:gap-10 lg:gap-6 xl:gap-8 2xl:gap-12 max-w-full 2xl:max-w-[85%]">
<!-- Record (W-L) - Largest -->
<div class="text-center lg:text-left">
<div class="text-[0.65rem] xs:text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-2 xs:mb-3 flex items-center gap-1 xs:gap-1.5 justify-center lg:justify-start">
                            Record
                            <!-- Tooltip -->
<div class="group relative inline-flex">
<div aria-label="Record information" tabindex="0" class="tooltip-trigger inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/5 hover:bg-white/10 border border-white/20 transition-all duration-200 cursor-pointer" role="img">
<svg aria-hidden="true" class="w-3.5 h-3.5 text-white/60" fill="currentColor" height="16" viewbox="0 0 20 20" width="16">
<path clip-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" fill-rule="evenodd"></path>
</svg>
</div>
<div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-black/50 text-white/90 text-xs rounded-lg shadow-2xl border border-white/10 whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible group-focus:opacity-100 group-focus:visible transition-all duration-200 pointer-events-none z-50" style="backdrop-filter: blur(12px);">Recorded professional wins, losses, draws and no contests<div class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 w-2 h-2 bg-black/50 border-r border-b border-white/10 rotate-45" style="backdrop-filter: blur(12px);"></div>
</div>
</div>
</div>
<div class="text-3xl xs:text-4xl md:text-5xl lg:text-3xl xl:text-4xl 2xl:text-6xl font-heading font-black text-white tracking-tight leading-none mt-3"><?php echo esc_html( mmaf_fighter_record_available( $c ) ? mmaf_record( mmaf_display_record($c) ) : '—' ); ?></div>
</div>
<!-- Finishes - Medium -->
<div class="text-center lg:text-left">
<div class="text-[0.65rem] xs:text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-2 xs:mb-3 flex items-center gap-1 xs:gap-1.5 justify-center lg:justify-start">
                            Finishes
                            <!-- Tooltip -->
<div class="group relative inline-flex">
<div aria-label="Finishes information" tabindex="0" class="tooltip-trigger inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/5 hover:bg-white/10 border border-white/20 transition-all duration-200 cursor-pointer" role="img">
<svg aria-hidden="true" class="w-3.5 h-3.5 text-white/60" fill="currentColor" height="16" viewbox="0 0 20 20" width="16">
<path clip-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" fill-rule="evenodd"></path>
</svg>
</div>
<div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-black/50 text-white/90 text-xs rounded-lg shadow-2xl border border-white/10 whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible group-focus:opacity-100 group-focus:visible transition-all duration-200 pointer-events-none z-50" style="backdrop-filter: blur(12px);">Wins by KO, TKO or submission<div class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 w-2 h-2 bg-black/50 border-r border-b border-white/10 rotate-45" style="backdrop-filter: blur(12px);"></div>
</div>
</div>
</div>
<div class="text-2xl xs:text-3xl md:text-4xl lg:text-2xl xl:text-3xl 2xl:text-5xl font-heading font-bold text-white tracking-tight leading-none mt-3"><?php echo esc_html( mmaf_display_number( mmaf_fighter_record_available( $c ) ? ( $stats['finish_wins'] ?? null ) : null ) ); ?></div>
</div>
<?php if ( empty( $c['ufc_signing']['confirmed'] ) ) : ?><!-- Opponent Strength Score - Highlighted -->
<div class="text-center lg:text-left">
<div class="text-[0.65rem] xs:text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-2 xs:mb-3 flex items-center gap-1 xs:gap-1.5 justify-center lg:justify-start">MMA Future Score<!-- Tooltip -->
<div class="group relative inline-flex">
<div aria-label="MMA Future Score information" tabindex="0" class="tooltip-trigger inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/5 hover:bg-white/10 border border-white/20 transition-all duration-200 cursor-pointer" role="img">
<svg aria-hidden="true" class="w-3.5 h-3.5 text-white/60" fill="currentColor" height="16" viewbox="0 0 20 20" width="16">
<path clip-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" fill-rule="evenodd"></path>
</svg>
</div>
<div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-black/50 text-white/90 text-xs rounded-lg shadow-2xl border border-white/10 whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible group-focus:opacity-100 group-focus:visible transition-all duration-200 pointer-events-none z-50" style="backdrop-filter: blur(12px);">Direct total from the active formula v1.5 ranking<div class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 w-2 h-2 bg-black/50 border-r border-b border-white/10 rotate-45" style="backdrop-filter: blur(12px);"></div>
</div>
</div>
</div>
<div class="text-2xl xs:text-3xl md:text-4xl lg:text-2xl xl:text-3xl 2xl:text-5xl font-heading font-bold tracking-tight leading-none mt-4">
<span class="opp-score-badge inline-block px-3 xs:px-4 py-1.5 rounded-lg text-white/90 cursor-pointer transition-all duration-300 text-[0.9em]"><?php echo esc_html( mmaf_display_number( $overall['score'] ?? null, 2 ) ); ?></span>
</div>
</div>
<?php endif; ?>
</div>
<!-- Social Icons Row -->
<div class="space-y-3 mt-8 sm:mt-10 lg:mt-12 xl:mt-14"><?php mmaf_social_links( (array) $c['editorial']['fighter_social_links'], 'inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/5 border border-white/10 text-white hover:bg-white/15', 'link' ); ?></div>
<p class="text-white/70 text-sm"><?php echo esc_html( mmaf_fighter_record_available( $c ) ? mmaf_record_label($c) : 'Professional record not available yet' ); ?></p><?php mmaf_render_dates( $c['dates'] ); if(empty($c['ufc_signing']['confirmed'])){mmaf_render_scoring_assessment($c);} ?></div>
<!-- Right Column: Methodology Bubble -->
<aside class="mf-hero-bubble w-full lg:w-auto">
<div class="mf-hero-bubble__card rounded-2xl p-5 lg:p-6 space-y-3 lg:space-y-4">
<!-- Header -->
<div class="mf-hero-bubble__header space-y-3">
<span class="mf-hero-bubble__badge">MMA Future</span>
<h3 class="mf-hero-bubble__title"><?php echo esc_html( 'How is the MMA Future Score calculated?' ); ?></h3>
</div>
<!-- Description -->
<p class="mf-hero-bubble__text"><?php echo esc_html(($c['dates']['formula_version']??'')==='v1.6'?'MMA Future bodovi od 9. maja 2026. Career records determine eligibility; accepted period results, finishes, age and pre-fight opponent records determine points.':'The activated formula adds the saved contributions from professional fight results, finishes, age and pre-fight opponent records.'); ?></p>
<!-- Expandable More Info -->
<div class="mf-hero-bubble__more" data-expandable="" id="<?php echo esc_attr( $prefix . '_more' ); ?>">
<p class="mf-hero-bubble__text mf-hero-bubble__text--muted">The score is a direct total, not a percentage. Only the reviewed, activated ranking appears here. Statistics can have a newer update date.</p>
</div>
<!-- Actions -->
<div class="mf-hero-bubble__actions flex items-center gap-3">
<a class="mf-hero-bubble__cta" href="<?php echo esc_url( home_url( '/about/' ) ); ?>">
                            Read more
                            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M17 8l4 4m0 0l-4 4m4-4H3" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
</a>
<button aria-controls="<?php echo esc_attr( $prefix . '_more' ); ?>" aria-expanded="false" class="mf-hero-bubble__toggle" data-mmaf-toggle=".mf-hero-bubble__more" type="button">
                            Learn more
                            <svg aria-hidden="true" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
</button>
</div>
</div>
</aside>
</div>
</div>
</section>
