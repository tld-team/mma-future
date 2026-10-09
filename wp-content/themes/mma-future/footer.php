<footer class="mmaf-footer"><div class="mmaf-container"><div class="mmaf-footer-grid">
<div><?php if ( has_custom_logo() ) { echo '<div class="mmaf-footer-logo">' . get_custom_logo() . '</div>'; } else { echo '<h3>' . esc_html( get_bloginfo( 'name' ) ) . '</h3>'; } ?>
<p><?php echo esc_html( mmaf_site_setting( 'footer_description' ) ); ?></p></div>
<?php $titles = (array) mmaf_site_setting( 'footer_menu_titles' ); foreach ( array( 'explore', 'resources' ) as $column ) : ?>
<div><h4><?php echo esc_html( $titles[$column] ?? '' ); ?></h4><?php wp_nav_menu( array( 'theme_location' => 'footer-' . $column, 'container' => false, 'fallback_cb' => false, 'depth' => 1 ) ); ?></div>
<?php endforeach; ?>
<div><h4><?php echo esc_html( $titles['contact'] ?? '' ); ?></h4><ul>
<?php if ( $email = mmaf_site_setting( 'contact_email' ) ) : ?><li><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></li><?php endif; ?>
<?php if ( $phone = mmaf_site_setting( 'contact_phone' ) ) : ?><li><a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></li><?php endif; ?>
<?php if ( $location = mmaf_site_setting( 'contact_location' ) ) : ?><li><?php if ( $map = mmaf_site_setting( 'contact_map_link' ) ) : ?><a href="<?php echo esc_url( $map ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $location ); ?></a><?php else : echo esc_html( $location ); endif; ?></li><?php endif; ?>
</ul><div class="mmaf-actions"><?php foreach ( (array) mmaf_site_setting( 'social_links' ) as $social ) { if ( ! empty( $social['profile_link'] ) ) { echo '<a href="' . esc_url( $social['profile_link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( ucfirst( $social['platform'] ) ) . '</a>'; } } ?></div></div>
</div><div class="mmaf-copyright">&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . mmaf_site_setting( 'footer_copyright' ) ); ?> · Web by <a href="https://tldteam.com" target="_blank" rel="noopener noreferrer">TLD Team</a></div></div></footer>
<?php wp_footer(); ?></body></html>
