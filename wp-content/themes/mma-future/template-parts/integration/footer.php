<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $titles = (array) mmaf_site_setting( 'footer_menu_titles' ); ?>

<footer class="py-12" style="background-color: #ffffff;">
<div class="container mx-auto pl-2 pr-4 md:px-4">
<div class="grid grid-cols-1 md:grid-cols-4 gap-8">
<div><?php mmaf_brand_link( 'footer' ); ?>
<p class="text-base leading-relaxed font-normal" style="color:#647488"><?php echo esc_html( mmaf_site_setting( 'footer_description' ) ); ?></p></div>
<div class="ml-3 md:ml-0">
<h4 class="text-lg font-heading font-bold mb-4" style="color: #0B1220;"><?php echo esc_html( $titles['explore'] ?? '' ); ?></h4>
<?php wp_nav_menu( array( 'theme_location' => 'footer-explore', 'container' => false, 'fallback_cb' => false, 'depth' => 1, 'menu_class' => 'space-y-1 list-none ml-0 mmaf-footer-menu' ) ); ?>
</div>
<div class="ml-3 md:ml-0">
<h4 class="text-lg font-heading font-bold mb-4" style="color: #0B1220;"><?php echo esc_html( $titles['resources'] ?? '' ); ?></h4>
<?php wp_nav_menu( array( 'theme_location' => 'footer-resources', 'container' => false, 'fallback_cb' => false, 'depth' => 1, 'menu_class' => 'space-y-1 list-none ml-0 mmaf-footer-menu' ) ); ?>
</div>
<div>
<h4 class="text-lg font-heading font-bold mb-4" style="color: #0B1220;"><?php echo esc_html( $titles['contact'] ?? '' ); ?></h4>
<ul class="space-y-2 list-none mmaf-footer-contact"><?php mmaf_render_contact_details( 'hover:text-primary transition-colors no-underline font-normal', true ); ?></ul>
<div class="flex space-x-3 mt-4"><?php mmaf_social_links( (array) mmaf_site_setting( 'social_links' ), 'social-link w-10 h-10 rounded-full flex items-center justify-center no-underline transition-all duration-300 hover:scale-105 hover:shadow-md hover:-translate-y-0.5' ); ?></div>
</div>
</div>
<div class="section-divider my-8"></div>
<div class="text-center" style="color: #647488; font-weight: 400;">
<p> <em>© <?php echo esc_html( wp_date( 'Y' ) . ' ' . mmaf_site_setting( 'footer_copyright' ) ); ?></em> · Web by <a href="https://tldteam.com" target="_blank" rel="noopener noreferrer" class="hover:opacity-80 transition-opacity" style="color:#0047A8">TLD Team</a></p>
</div>
</div>
</footer>
