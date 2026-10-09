<?php
/**
 * The template for displaying all single posts
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package mma-future
 */

get_header();

?>

	<!-- <main id="primary" class="site-main"> -->

		<?php
		while ( have_posts() ) :
			the_post();

			if ( ! trim( get_the_content() ) ) { echo '<main id="primary" class="mmaf-section"><div class="mmaf-container"><h1>' . esc_html( get_the_title() ) . '</h1><p>This legacy profile is awaiting verified linkage to its canonical fighter data.</p></div></main>'; }
			get_template_part( 'template-parts/content', 'fighter' );

		endwhile; // End of the loop.
		?>

	<!-- </main> #main -->

<?php
get_footer();
