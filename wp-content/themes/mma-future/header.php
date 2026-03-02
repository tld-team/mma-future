<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php wp_head(); ?>
	<style>
		/* Topbar marquee (scoped) – light cool gray */
		.site-topbar {
			background-color: #F8FAFC;
			border-bottom: 1px solid rgba(15, 23, 42, 0.06);
			--site-topbar-bg: 248 250 252; /* #F8FAFC for edge fades */
		}
		.site-topbar__label-text {
			color: #1E3A5F;
		}
		.site-topbar__item {
			color: #64748B;
		}
		.site-topbar__separator {
			color: #CBD5E1;
		}
		.site-topbar__marquee {
			position: relative;
			overflow: hidden;
		}
		.site-topbar__fade-left,
		.site-topbar__fade-right {
			position: absolute;
			top: 0;
			bottom: 0;
			width: 28px;
			pointer-events: none;
			z-index: 2;
		}
		.site-topbar__fade-left {
			left: 0;
			background: linear-gradient(
				to right,
				rgb(var(--site-topbar-bg)),
				rgb(var(--site-topbar-bg) / 0)
			);
		}
		.site-topbar__fade-right {
			right: 0;
			background: linear-gradient(
				to left,
				rgb(var(--site-topbar-bg)),
				rgb(var(--site-topbar-bg) / 0)
			);
		}
		.site-topbar__track {
			display: flex;
			width: max-content;
			will-change: transform;
			animation: mmaFutureTopbarMarquee 38s linear infinite;
		}
		.site-topbar:hover .site-topbar__track {
			animation-play-state: paused;
		}
		@keyframes mmaFutureTopbarMarquee {
			from { transform: translateX(0); }
			to { transform: translateX(-50%); }
		}
		@media (prefers-reduced-motion: reduce) {
			.site-topbar__marquee {
				overflow-x: auto;
			}
			.site-topbar__fade-left,
			.site-topbar__fade-right {
				display: none;
			}
			.site-topbar__track {
				animation: none;
			}
			.site-topbar__track > [aria-hidden="true"] {
				display: none;
			}
		}
		#primary-menu a {
			color: #64748B;
		}
	</style>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

	<!-- Skip to Content Link (Accessibility) -->
	<a class="skip-link screen-reader-text" href="#primary">
		<?php esc_html_e('Skip to content', 'mma-future'); ?>
	</a>

	<!-- ============================================================
	     SITE TOPBAR (Marquee)
	     ============================================================ -->
	<div class="site-topbar">
		<div class="site-topbar__inner mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 min-h-10 py-2 flex items-center gap-4">
			<div class="site-topbar__label flex items-center gap-2 flex-none pr-4 border-r border-[#CBD5E1]">
				<span class="site-topbar__label-dot inline-block w-1.5 h-1.5 rounded-full bg-[#1D4E89]"></span>
				<span class="site-topbar__label-text text-[11px] sm:text-[12px] font-heading font-semibold uppercase tracking-wider">Latest updates</span>
			</div>

			<div class="site-topbar__marquee flex-1 min-w-0 pl-1" aria-label="<?php esc_attr_e('Site updates', 'mma-future'); ?>">
				<div class="site-topbar__fade-left" aria-hidden="true"></div>
				<div class="site-topbar__fade-right" aria-hidden="true"></div>

				<div class="site-topbar__track">
					<div class="flex items-center whitespace-nowrap pr-10">
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">UFC 300 breakdown now live</span>
						<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">Updated heavyweight rankings available</span>
						<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">New fight analysis added to the blog</span>
						<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">Explore scoring methodology on the About page</span>
						<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">Follow MMA Future for updates</span>
					</div>
					<div class="flex items-center whitespace-nowrap pr-10" aria-hidden="true">
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">UFC 300 breakdown now live</span>
						<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">Updated heavyweight rankings available</span>
						<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">New fight analysis added to the blog</span>
						<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">Explore scoring methodology on the About page</span>
						<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>
						<span class="site-topbar__item text-xs font-heading font-medium uppercase tracking-wide leading-none">Follow MMA Future for updates</span>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================================================
	     SITE HEADER
	     ============================================================ -->
	<header id="masthead" class="site-header sticky top-0 z-40 bg-white transition-all duration-300">
		
		<!-- Main Navigation Bar -->
		<nav aria-label="<?php esc_attr_e('Main Navigation', 'mma-future'); ?>" class="mx-auto max-w-7xl px-4 py-2 sm:px-6 lg:px-8">
			<div class="flex items-center justify-between transition-all duration-300 header-content">
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
						<svg class="burger-icon h-7 w-7 text-[#64748B] transition-all duration-300 ease-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
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
						href="/wp-admin" 
						class="header-login-btn inline-flex items-center gap-1.5 h-10 px-4 text-base font-heading font-semibold no-underline rounded-[12px] border border-[#E2E8F0] bg-white text-[#0F172A]"
						style="text-decoration: none !important;"
					>
						<svg class="header-login-btn-icon h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
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
						href="/wp-admin" 
						class="header-login-btn flex w-full items-center justify-center gap-2 h-12 px-5 rounded-xl text-base font-heading font-semibold no-underline ring-1 ring-black/10 shadow-sm visited:text-[--brand]"
						style="text-decoration: none !important;"
					>
						<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
							<path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
						</svg>
						<?php esc_html_e('Prijava', 'mma-future'); ?>
					</a>
				</div>

			</div><!-- Mobile Menu Content -->
		</div><!-- Mobile Menu Panel -->
	</div><!-- #mobile-menu -->
