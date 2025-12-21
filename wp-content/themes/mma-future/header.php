<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

	<!-- Skip to Content Link (Accessibility) -->
	<a class="skip-link screen-reader-text" href="#primary">
		<?php esc_html_e('Skip to content', 'mma-future'); ?>
	</a>

	<!-- ============================================================
	     SITE HEADER
	     ============================================================ -->
	<header id="masthead" class="site-header sticky top-0 z-40 bg-white transition-all duration-300">
		
		<!-- Main Navigation Bar -->
		<nav aria-label="<?php esc_attr_e('Main Navigation', 'mma-future'); ?>" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
			<div class="flex h-20 items-center justify-between transition-all duration-300 header-content">
				
				<!-- ============================================================
				     LOGO / BRANDING (Left Side)
				     ============================================================ -->
				<div class="flex items-center lg:flex-1 overflow-hidden">
					<?php
					// Custom Logo or Site Title
					if (has_custom_logo()) {
						$custom_logo_id = get_theme_mod('custom_logo');
						$logo = wp_get_attachment_image_src($custom_logo_id, 'full');
						
						if ($logo) : ?>
							<a href="<?php echo esc_url(home_url('/')); ?>" class="block w-full max-w-[150px] h-20 flex items-center overflow-hidden no-underline transition-all duration-300 hover:opacity-80 header-logo" rel="home">
								<span class="sr-only"><?php bloginfo('name'); ?></span>
								<img 
									src="<?php echo esc_url($logo[0]); ?>" 
									alt="<?php echo esc_attr(get_bloginfo('name')); ?>" 
									class="w-full max-h-20 object-contain transition-all duration-300 header-logo-img"
								/>
							</a>
						<?php endif;
					} else {
						// Fallback: Site Title
						$site_title = get_bloginfo('name');
						
						if (is_front_page() && is_home()) : ?>
							<a href="<?php echo esc_url(home_url('/')); ?>" class="no-underline" rel="home">
								<h1 class="text-2xl font-heading font-bold text-heading hover:text-primary transition-colors">
									<?php echo esc_html($site_title); ?>
								</h1>
							</a>
						<?php else : ?>
							<a href="<?php echo esc_url(home_url('/')); ?>" class="no-underline" rel="home">
								<span class="text-2xl font-heading font-bold text-heading hover:text-primary transition-colors">
									<?php echo esc_html($site_title); ?>
								</span>
							</a>
						<?php endif;
					}
					?>
				</div>

				<!-- ============================================================
				     MOBILE MENU TOGGLE (Center on Mobile)
				     ============================================================ -->
				<div class="flex lg:hidden">
					<button 
						type="button" 
						id="mobile-menu-toggle" 
						class="mobile-menu-toggle relative inline-flex items-center justify-center w-10 h-10 text-body hover:text-heading focus:outline-none transition-colors duration-200 ease-out"
						aria-expanded="false" 
						aria-controls="mobile-menu"
						aria-label="<?php esc_attr_e('Toggle menu', 'mma-future'); ?>"
					>
						<span class="sr-only"><?php esc_html_e('Open main menu', 'mma-future'); ?></span>
						<svg class="burger-icon h-7 w-7 transition-all duration-300 ease-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
							<path class="burger-line-top" d="M3.75 6.75h16.5" />
							<path class="burger-line-middle" d="M3.75 12h16.5" />
							<path class="burger-line-bottom" d="M3.75 17.25h16.5" />
						</svg>
					</button>
				</div>

				<!-- ============================================================
				     DESKTOP NAVIGATION MENU (Center)
				     ============================================================ -->
				<?php
				if (has_nav_menu('menu-1')) :
					wp_nav_menu(array(
						'theme_location'  => 'menu-1',
						'menu_id'         => 'primary-menu',
						'container'       => false,
						'menu_class'      => '',
						'items_wrap'      => '<ul id="%1$s" class="hidden lg:flex lg:items-center lg:gap-x-4 list-none">%3$s</ul>',
						'walker'          => new MMA_Desktop_Nav_Walker(),
						'fallback_cb'     => false,
					));
				endif;
				?>

				<!-- ============================================================
				     LOGIN / CTA BUTTON (Right Side)
				     ============================================================ -->
				<div class="hidden lg:flex lg:flex-1 lg:items-center lg:justify-end">
					<a 
						href="#" 
						class="inline-flex items-center gap-x-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-heading font-semibold text-button-text no-underline shadow-sm hover:bg-primary-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all duration-200"
					>
						<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
							<path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
						</svg>
						<?php esc_html_e('Prijava', 'mma-future'); ?>
					</a>
				</div>

			</div><!-- .flex -->
		</nav><!-- Main Navigation Bar -->

	</header><!-- #masthead -->

	<!-- ============================================================
	     MOBILE MENU (Off-Canvas)
	     ============================================================ -->
	<div 
		id="mobile-menu" 
		class="hidden lg:hidden fixed inset-0 z-50"
		aria-modal="true"
		role="dialog"
	>
		<!-- Backdrop / Overlay -->
		<div class="fixed inset-0 bg-secondary-900/50 backdrop-blur-sm transition-opacity" aria-hidden="true"></div>
		
	<!-- Mobile Menu Panel -->
	<div class="fixed inset-y-0 right-0 z-50 w-full max-w-sm bg-white shadow-xl transform transition-transform duration-300 ease-out translate-x-full">
		
		<!-- Mobile Menu Header -->
		<div class="flex h-16 items-center justify-between px-6 overflow-hidden">
				<!-- Logo in Mobile Menu -->
				<?php
				if (has_custom_logo()) {
					$custom_logo_id = get_theme_mod('custom_logo');
					$logo = wp_get_attachment_image_src($custom_logo_id, 'full');
					
					if ($logo) : ?>
						<a href="<?php echo esc_url(home_url('/')); ?>" class="block w-full max-w-[120px] h-16 flex items-center overflow-hidden no-underline" rel="home">
							<span class="sr-only"><?php bloginfo('name'); ?></span>
							<img 
								src="<?php echo esc_url($logo[0]); ?>" 
								alt="<?php echo esc_attr(get_bloginfo('name')); ?>" 
								class="w-full max-h-16 object-contain"
							/>
						</a>
					<?php endif;
				} else {
					$site_title = get_bloginfo('name');
					?>
					<a href="<?php echo esc_url(home_url('/')); ?>" class="no-underline" rel="home">
						<span class="text-xl font-heading font-bold text-heading">
							<?php echo esc_html($site_title); ?>
						</span>
					</a>
				<?php } ?>

			<!-- Close Button -->
			<button 
				type="button" 
				id="mobile-menu-close" 
				class="mobile-menu-close relative inline-flex items-center justify-center w-10 h-10 text-body hover:text-heading focus:outline-none transition-colors duration-200 ease-out"
				aria-label="<?php esc_attr_e('Close menu', 'mma-future'); ?>"
			>
				<span class="sr-only"><?php esc_html_e('Close menu', 'mma-future'); ?></span>
				<svg class="h-7 w-7 transition-all duration-200 ease-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
			</div>

			<!-- Mobile Menu Content -->
			<div class="flex flex-col h-[calc(100%-4rem)] overflow-y-auto">
				
				<!-- Mobile Navigation -->
				<div class="flex-1 px-6 py-4">
					<?php
					if (has_nav_menu('menu-1')) :
						wp_nav_menu(array(
							'theme_location'  => 'menu-1',
							'menu_id'         => 'mobile-primary-menu',
							'container'       => false,
							'menu_class'      => '',
							'items_wrap'      => '<ul id="%1$s" class="space-y-0.5 list-none">%3$s</ul>',
							'walker'          => new MMA_Mobile_Nav_Walker(),
							'fallback_cb'     => false,
						));
					endif;
					?>
				</div>

			<!-- Mobile Menu Footer (Login Button) -->
			<div class="px-6 py-6">
					<a 
						href="#" 
						class="flex w-full items-center justify-center gap-x-2 rounded-lg bg-primary px-5 py-3 text-base font-heading font-semibold text-button-text no-underline shadow-sm hover:bg-primary-600 transition-colors"
					>
						<svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
							<path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
						</svg>
						<?php esc_html_e('Prijava', 'mma-future'); ?>
					</a>
				</div>

			</div><!-- Mobile Menu Content -->
		</div><!-- Mobile Menu Panel -->
	</div><!-- #mobile-menu -->
