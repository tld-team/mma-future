<?php

if ( ! function_exists( 'mma_future_register_acf_blocks' ) ) {
	function mma_future_register_acf_blocks() {
		if ( ! function_exists( 'acf_register_block_type' ) ) {
			return;
		}

		$blocks = array(
			array(
				'name' => 'single-fighter-detail',
				'title' => 'Single Fighter Detail',
				'description' => 'Single Fighter Detail',
				'render_template' => 'blocks/single-fighter-detail/single-fighter-detail.php',
			),
			array(
				'name' => 'single-fighter-hero-section',
				'title' => 'Single Fighter Hero Section',
				'description' => 'Single Fighter Hero Section',
				'render_template' => 'blocks/single-fighter-hero-section/single-fighter-hero-section.php',
			),
			array(
				'name' => 'single-fighter-last-fights',
				'title' => 'Single Fighter Last Fights',
				'description' => 'Single Fighter Last Fights',
				'render_template' => 'blocks/single-fighter-last-fights/single-fighter-last-fights.php',
			),
			array(
				'name' => 'hp-hero-section',
				'title' => 'Hero Section Block',
				'description' => 'Hero Section Block',
				'render_template' => 'blocks/hp-hero-section/hp-hero-section.php',
			),
			array(
				'name' => 'highlights-section',
				'title' => 'Highlights Section Block',
				'description' => 'Highlights Section Block',
				'render_template' => 'blocks/highlights-section/highlights-section.php',
			),
			array(
				'name' => 'short-ranking-overview',
				'title' => 'Short Ranking Overview Block',
				'description' => 'Short Ranking Overview Block',
				'render_template' => 'blocks/short-ranking-overview/short-ranking-overview.php',
			),
			array(
				'name' => 'newsletter-block',
				'title' => 'Newsletter Block',
				'description' => 'Newsletter Block',
				'render_template' => 'blocks/newsletter-block/newsletter-block.php',
			),
			array(
				'name' => 'single-fighter-latest-fights-section-block',
				'title' => 'Single Fighter Latest Fights Section Block',
				'description' => 'Single Fighter Latest Fights Section Block',
				'render_template' => 'blocks/single-fighter-latest-fights-section/single-fighter-latest-fights-section.php',
			),
			array(
				'name' => 'single-fighter-quick-stats-section-block',
				'title' => 'Single Fighter Quick Stats Section Block',
				'description' => 'Single Fighter Quick Stats Section Block',
				'render_template' => 'blocks/single-fighter-quick-stats-section/single-fighter-quick-stats-section.php',
			),
			array(
				'name' => 'single-fighter-hero-section-block',
				'title' => 'Single Fighter Hero Section Block',
				'description' => 'Single Fighter Hero Section Block',
				'render_template' => 'blocks/single-fighter-hero-section/single-fighter-hero-section.php',
			),
			array(
				'name' => 'single-fighter-full-fight-history-section-block',
				'title' => 'Single Fighter Full Fight History Section Block',
				'description' => 'Single Fighter Full Fight History Section Block',
				'render_template' => 'blocks/single-fighter-full-fight-history-section/single-fighter-full-fight-history-section.php',
			),
			array(
				'name' => 'single-fighter-bio-section-block',
				'title' => 'Single Fighter Bio Section Block',
				'description' => 'Single Fighter Bio Section Block',
				'render_template' => 'blocks/single-fighter-bio-section/single-fighter-bio-section.php',
			),
			array(
				'name' => 'all-rankings-table-section',
				'title' => 'All Rankings Table Section',
				'description' => 'All Rankings Table Section',
				'render_template' => 'blocks/all-rankings-table-section/all-rankings-table-section.php',
			),
			array(
				'name' => 'about-hero-section',
				'title' => 'About Hero Section',
				'description' => 'About Hero Section',
				'render_template' => 'blocks/about-hero-section/about-hero-section.php',
			),
			array(
				'name' => 'about-featured-section',
				'title' => 'About Featured Section',
				'description' => 'About Featured Section',
				'render_template' => 'blocks/about-featured-section/about-featured-section.php',
			),
			array(
				'name' => 'about-how-it-works-section',
				'title' => 'About How It Works Section',
				'description' => 'About How It Works Section',
				'render_template' => 'blocks/about-how-it-works-section/about-how-it-works-section.php',
			),
			array(
				'name' => 'about-highlighted-data-section',
				'title' => 'About Highlighted Data Section',
				'description' => 'About Highlighted Data Section',
				'render_template' => 'blocks/about-highlighted-data-section/about-highlighted-data-section.php',
			),
			array(
				'name' => 'about-faq-section',
				'title' => 'About FAQ Section',
				'description' => 'About FAQ Section',
				'render_template' => 'blocks/about-faq-section/about-faq-section.php',
			),
			array(
				'name' => 'about-founder-bio-section',
				'title' => 'About Founder Bio Section',
				'description' => 'About Founder Bio Section',
				'render_template' => 'blocks/about-founder-bio-section/about-founder-bio-section.php',
			),
			array(
				'name' => 'about-cta-section',
				'title' => 'About CTA Section',
				'description' => 'About CTA Section',
				'render_template' => 'blocks/about-cta-section/about-cta-section.php',
			),
			array(
				'name' => 'about-partners-logos-section',
				'title' => 'About Partners Logos Section',
				'description' => 'About Partners Logos Section',
				'render_template' => 'blocks/about-partners-logos-section/about-partners-logos-section.php',
			),
			array(
				'name' => 'about-our-team-section',
				'title' => 'About Our Team Section',
				'description' => 'About Our Team Section',
				'render_template' => 'blocks/about-our-team-section/about-our-team-section.php',
			),
			array(
				'name' => 'contact-main-section',
				'title' => 'Contact Main Section',
				'description' => 'Contact Main Section',
				'render_template' => 'blocks/contact-main-section/contact-main-section.php',
			),
			array(
				'name' => 'blog-latest-posts-section',
				'title' => 'Blog Latest Posts Section',
				'description' => 'Blog Latest Posts Section',
				'render_template' => 'blocks/blog-latest-posts-section/blog-latest-posts-section.php',
			),
		);

		$registered_names = array();
		$registry = class_exists( 'WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance() : null;

		foreach ( $blocks as $block ) {
			$name = (string) $block['name'];
			if ( isset( $registered_names[ $name ] ) ) {
				continue;
			}

			$registered_names[ $name ] = true;
			$registry_name = 'acf/' . $name;
			if ( $registry && $registry->is_registered( $registry_name ) ) {
				continue;
			}

			acf_register_block_type(
				array(
					'name'            => $name,
					'title'           => $block['title'],
					'description'     => $block['description'],
					'category'        => 'custom_theme',
					'mode'            => 'preview',
					'supports'        => array(
						'align'  => true,
						'mode'   => true,
						'jsx'    => true,
						'anchor' => true,
					),
					'render_template' => $block['render_template'],
				)
			);
		}
	}
}

add_action( 'acf/init', 'mma_future_register_acf_blocks' );
