<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! mmaf_site_field( 'founder_name' ) ) { return; }

// Empty ACF repeaters return false; only render populated rows.
$experience_items = mmaf_site_field( 'experience_items' );
$experience_items = array_filter( is_array( $experience_items ) ? $experience_items : array(), static function( $row ) {
	return is_array( $row ) && is_string( $row['experience_text'] ?? null ) && '' !== trim( $row['experience_text'] );
} );
$founder_links = mmaf_site_field( 'founder_links' );
$founder_links = array_filter( is_array( $founder_links ) ? $founder_links : array(), static function( $row ) {
	$link = is_array( $row ) ? ( $row['link'] ?? null ) : null;
	return is_array( $link ) && is_string( $link['url'] ?? null ) && '' !== trim( $link['url'] );
} );
$network_names = array(
	'facebook.com' => 'Facebook', 'fb.com' => 'Facebook', 'instagram.com' => 'Instagram',
	'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'linkedin.com' => 'LinkedIn',
	'twitter.com' => 'X', 'x.com' => 'X', 'tiktok.com' => 'TikTok',
);
foreach ( $founder_links as $index => $row ) {
	if ( is_string( $row['link']['title'] ?? null ) && '' !== trim( $row['link']['title'] ) ) { continue; }
	$host = strtolower( (string) wp_parse_url( $row['link']['url'], PHP_URL_HOST ) );
	$host = preg_replace( '/^(www|m|mobile)\./', '', $host );
	$founder_links[$index]['link']['title'] = $network_names[$host] ?? ( $host ?: 'Profile' );
}
?>

<section class="about-founder-bio-section py-12 sm:py-16 lg:py-20">
<div class="about-founder-bio-section__container max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
<h2 class="about-founder-bio-section__title text-3xl sm:text-4xl font-extrabold tracking-tight !text-slate-900"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h2>
<div class="about-founder-bio-section__card mt-8 !rounded-2xl !border !border-slate-200/70 !bg-white p-6 sm:p-8 transition-shadow duration-300 hover:shadow-card">
<div class="about-founder-bio-section__layout grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-6 lg:gap-8 items-start">
<!-- Left column: Avatar + Socials -->
<div class="about-founder-bio-section__left flex flex-col items-center lg:items-start">
<div class="about-founder-bio-section__avatar w-full aspect-square rounded-2xl !bg-slate-100 flex items-center justify-center"><?php echo mmaf_site_image( mmaf_site_field( 'founder_photo' ), 'large', array( 'class' => 'w-full h-full object-cover rounded-2xl' ) ); ?></div>
<?php if ( $founder_links ) : ?>
<div class="about-founder-bio-section__socials mt-3 flex items-center gap-2"><?php foreach ( $founder_links as $row ) { mmaf_site_link( $row['link'], 'about-founder-bio-section__social-link inline-flex items-center justify-center p-2 rounded-full border border-slate-200 text-sm' ); } ?></div>
<?php endif; ?>
</div>
<!-- Right column: Content -->
<div class="about-founder-bio-section__content">
<h3 class="about-founder-bio-section__name text-2xl sm:text-3xl font-extrabold tracking-tight !text-slate-900"><?php echo esc_html( mmaf_site_field( 'founder_name' ) ); ?></h3>
<span class="about-founder-bio-section__role mt-2 inline-flex items-center rounded-full !bg-[rgba(0,71,168,0.10)] px-3 py-1 text-xs font-semibold !text-[#0047A8]"><?php echo esc_html( mmaf_site_field( 'founder_role' ) ); ?></span>
<div class="about-founder-bio-section__bio mt-4 text-sm sm:text-base !text-slate-600 leading-relaxed max-w-3xl"><?php echo wp_kses_post( mmaf_site_field( 'founder_biography' ) ); ?></div>
<!-- Facts grid -->
<?php if ( $experience_items ) : ?>
<div class="about-founder-bio-section__bullets mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3"><?php foreach ( $experience_items as $row ) : ?><div class="about-founder-bio-section__bullet flex items-start gap-3 !rounded-xl !border !border-slate-200/70 !bg-slate-50/40 px-4 py-3 transition-all duration-200 hover:!border-[#0047A8]/30 hover:!bg-white hover:shadow-sm hover:-translate-y-0.5">
<span aria-hidden="true" class="mt-1.5 h-2 w-2 rounded-full !bg-[#0047A8] flex-shrink-0"></span>
<span class="about-founder-bio-section__bullet-text text-sm !text-slate-700 leading-snug"><?php echo esc_html( $row['experience_text'] ); ?></span>
</div><?php endforeach; ?></div>
<?php endif; ?>
<!-- Quote callout -->
<?php if ( mmaf_site_field( 'founder_quote' ) ) : ?><div class="about-founder-bio-section__quote mt-6 !rounded-xl !border !border-[#0047A8]/20 p-5 transition-all duration-200 hover:!border-[#0047A8]/40 hover:shadow-sm" style="background: rgba(0,71,168,0.06);">
<svg aria-hidden="true" class="about-founder-bio-section__quote-mark w-6 h-6 !text-slate-300" fill="currentColor" viewbox="0 0 24 24">
<path d="M4.583 17.321C3.553 16.227 3 15 3 13.011c0-3.5 2.457-6.637 6.03-8.188l.893 1.378c-3.335 1.804-3.987 4.145-4.247 5.621.537-.278 1.24-.375 1.929-.311 1.804.167 3.226 1.648 3.226 3.489a3.5 3.5 0 01-3.5 3.5c-1.073 0-2.099-.49-2.748-1.179zm10 0C13.553 16.227 13 15 13 13.011c0-3.5 2.457-6.637 6.03-8.188l.893 1.378c-3.335 1.804-3.987 4.145-4.247 5.621.537-.278 1.24-.375 1.929-.311 1.804.167 3.226 1.648 3.226 3.489a3.5 3.5 0 01-3.5 3.5c-1.073 0-2.099-.49-2.748-1.179z"></path>
</svg>
<p class="about-founder-bio-section__quote-text mt-2 italic text-sm sm:text-base !text-slate-700 leading-relaxed"><?php echo esc_html( mmaf_site_field( 'founder_quote' ) ); ?></p>
<p class="about-founder-bio-section__quote-author mt-3 text-xs font-semibold uppercase tracking-wider !text-slate-500"><?php echo esc_html( mmaf_site_field( 'founder_name' ) ); ?></p>
</div><?php endif; ?>
<?php echo mmaf_site_image( mmaf_site_field( 'signature_image' ), 'medium', array( 'class' => 'max-w-40 mt-5' ) ); ?></div>
</div>
</div>
</div>
</section>
