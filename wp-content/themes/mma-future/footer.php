
    <footer class="py-12" style="background-color: #ffffff;">
        <div class="container mx-auto pl-2 pr-4 md:px-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <?php
                    // Footer Logo - same pattern as header
                    if (has_custom_logo()) {
                        $custom_logo_id = get_theme_mod('custom_logo');
                        $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
                        
                        if ($logo) : ?>
                            <a href="<?php echo esc_url(home_url('/')); ?>" class="block w-full max-w-[180px] mb-4 no-underline transition-opacity duration-300 hover:opacity-80" rel="home">
                                <span class="sr-only"><?php bloginfo('name'); ?></span>
                                <img 
                                    src="<?php echo esc_url($logo[0]); ?>" 
                                    alt="<?php echo esc_attr(get_bloginfo('name')); ?>" 
                                    class="w-full h-auto object-contain"
                                />
                            </a>
                        <?php endif;
                    } else {
                        // Fallback: Site Title with icon
                        ?>
                        <a href="<?php echo esc_url(home_url('/')); ?>" class="no-underline" rel="home">
                            <h3 class="text-xl font-heading font-bold mb-4 flex items-center hover:text-primary transition-colors" style="color: #0B1220;">
                                <i class="fas fa-fist-raised text-primary mr-2"></i> <?php echo esc_html(get_bloginfo('name')); ?>
                            </h3>
                        </a>
                    <?php } ?>
                    <p class="text-base leading-relaxed font-normal" style="color: #647488;">
                        Tvoj prozor u svet kaveza.
                    </p>
                </div>
                
                <div class="ml-3 md:ml-0">
                    <h4 class="text-lg font-heading font-bold mb-4" style="color: #0B1220;">Brzi linkovi</h4>
                    <ul class="space-y-1 list-none ml-0">
                        <li><a href="#" class="footer-link hover:text-primary no-underline transition-colors duration-200" style="color: #647488;">Početna</a></li>
                        <li><a href="#" class="footer-link hover:text-primary no-underline transition-colors duration-200" style="color: #647488;">Borci</a></li>
                        <li><a href="#" class="footer-link hover:text-primary no-underline transition-colors duration-200" style="color: #647488;">Turniri</a></li>
                        <li><a href="#" class="footer-link hover:text-primary no-underline transition-colors duration-200" style="color: #647488;">Vesti</a></li>
                    </ul>
                </div>
                
                <div class="ml-3 md:ml-0">
                    <h4 class="text-lg font-heading font-bold mb-4" style="color: #0B1220;">Organizacije</h4>
                    <ul class="space-y-1 list-none ml-0">
                        <li><a href="#" class="footer-link hover:text-primary no-underline transition-colors duration-200" style="color: #647488;">UFC</a></li>
                        <li><a href="#" class="footer-link hover:text-primary no-underline transition-colors duration-200" style="color: #647488;">Bellator</a></li>
                        <li><a href="#" class="footer-link hover:text-primary no-underline transition-colors duration-200" style="color: #647488;">ONE Championship</a></li>
                        <li><a href="#" class="footer-link hover:text-primary no-underline transition-colors duration-200" style="color: #647488;">PFL</a></li>
                    </ul>
                </div>
                
                <div>
                    <h4 class="text-lg font-heading font-bold mb-4" style="color: #0B1220;">Kontakt</h4>
                    <ul class="space-y-2 list-none">
                        <li class="flex items-center" style="color: #647488;">
                            <i class="fas fa-envelope mr-2"></i>
                            <a href="mailto:info@mmaborci.rs" class="hover:text-primary transition-colors no-underline font-normal" style="color: #647488;">info@mmaborci.rs</a>
                        </li>
                        <li class="flex items-center" style="color: #647488;">
                            <i class="fas fa-phone mr-2"></i>
                            <a href="tel:+38111123456" class="hover:text-primary transition-colors no-underline font-normal" style="color: #647488;">+381 11 123 456</a>
                        </li>
                        <li class="flex items-center" style="color: #647488;">
                            <i class="fas fa-map-marker-alt mr-2"></i>
                            <a href="https://maps.google.com/?q=Beograd,Srbija" target="_blank" rel="noopener noreferrer" class="hover:text-primary transition-colors no-underline font-normal" style="color: #647488;">Beograd, Srbija</a>
                        </li>
                    </ul>
                    
                    <div class="flex space-x-3 mt-4">
                        <a href="#" class="social-link w-10 h-10 rounded-full flex items-center justify-center no-underline transition-all duration-300 hover:scale-105 hover:shadow-md hover:-translate-y-0.5" style="background-color: #E5E7EB; color: #647488;" aria-label="Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="social-link w-10 h-10 rounded-full flex items-center justify-center no-underline transition-all duration-300 hover:scale-105 hover:shadow-md hover:-translate-y-0.5" style="background-color: #E5E7EB; color: #647488;" aria-label="Twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="social-link w-10 h-10 rounded-full flex items-center justify-center no-underline transition-all duration-300 hover:scale-105 hover:shadow-md hover:-translate-y-0.5" style="background-color: #E5E7EB; color: #647488;" aria-label="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="social-link w-10 h-10 rounded-full flex items-center justify-center no-underline transition-all duration-300 hover:scale-105 hover:shadow-md hover:-translate-y-0.5" style="background-color: #E5E7EB; color: #647488;" aria-label="YouTube">
                            <i class="fab fa-youtube"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="section-divider my-8"></div>
            
            <div class="text-center" style="color: #647488; font-weight: 400;">
                <p><em>&copy; <?php echo esc_html(date('Y')); ?> MMA Borci. Sva prava zadržana.</em> · Web by <a href="https://tldteam.com" target="_blank" rel="noopener noreferrer" class="hover:opacity-80 transition-opacity" style="color: #0047A8;">TLD Team</a></p>
            </div>
        </div>
    </footer>
<!-- </div>#page -->

<?php wp_footer(); ?>

</body>
</html>
