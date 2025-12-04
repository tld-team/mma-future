
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?> style="background-color: red;">
<?php wp_body_open(); ?>
<!-- <div id="page" class="site"> -->
	<a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e('Skip to content', 'mma-future'); ?></a>

	<header id="masthead" class="site-header">
		<div class="site-branding">
			<?php
			the_custom_logo();
			if (is_front_page() && is_home()):
				?>
				<h1 class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></h1>
				<?php
			else:
				?>
				<p class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></p>
				<?php
			endif;
			$mma_future_description = get_bloginfo('description', 'display');
			if ($mma_future_description || is_customize_preview()):
				?>
				<p class="site-description"><?php echo $mma_future_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php endif; ?>
		</div><!-- .site-branding -->
		<!-- Navigacija -->
		<nav class="bg-gray-800 py-4 sticky top-0 z-50">
			<button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false">
				<?php esc_html_e('Primary Menu', 'mma-future'); ?>
			</button>
			<div class="container mx-auto px-4 flex justify-between items-center">
				<div class="flex items-center">
					
				<?php
				the_custom_logo();
				if (is_front_page() && is_home()):
					?>
				<h1 class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></h1>
				<?php
				else:
				?>
				<p class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></p>
				<?php
				endif;
				$mma_future_description = get_bloginfo('description', 'display');
				if ($mma_future_description || is_customize_preview()):
				?>
				<p class="site-description"><?php echo $mma_future_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php endif; ?>

				</div>
				
				<div class="hidden md:flex space-x-6">
					<a href="#" class="hover:text-red-500 transition">Početna</a>
					<a href="#" class="hover:text-red-500 transition">Borci</a>
					<a href="#" class="hover:text-red-500 transition">Turniri</a>
					<a href="#" class="hover:text-red-500 transition">Vesti</a>
					<a href="#" class="hover:text-red-500 transition">Statistike</a>
				</div>
				
				<div class="flex items-center space-x-4">
					<button class="bg-red-600 hover:bg-red-700 px-4 py-2 rounded transition">Prijava</button>
					<button class="md:hidden">
						<i class="fas fa-bars text-xl"></i>
					</button>
				</div>
			</div>
		</nav>
	</header><!-- #masthead -->
