<?php
if (function_exists('acf_register_block_type')) {
	/**
	 * ==============================
	 * Single Fighter Detail Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-detail',
		'title' => 'Single Fighter Detail',
		'description' => 'Single Fighter Detail',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-detail/single-fighter-detail.php',
	));

	/**
	 * ==============================
	 * Single Fighter Hero Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-hero-section',
		'title' => 'Single Fighter Hero Section',
		'description' => 'Single Fighter Hero Section',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-hero-section/single-fighter-hero-section.php',
	));

	/**
	 * ==============================
	 * Single Fighter Last Fights Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-last-fights',
		'title' => 'Single Fighter Last Fights',
		'description' => 'Single Fighter Last Fights',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-last-fights/single-fighter-last-fights.php',
	));

	/**
	 * ==============================
	 * HOME: Hero Section Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'hp-hero-section',
		'title' => 'Hero Section Block',
		'description' => 'Hero Section Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/hp-hero-section/hp-hero-section.php',
	));

	/**
	 * ==============================
	 * HOME: Highlights Section Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'highlights-section',
		'title' => 'Highlights Section Block',
		'description' => 'Highlights Section Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/highlights-section/highlights-section.php',
	));

	/**
	 * ==============================
	 * HOME: Short Ranking Overview Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'short-ranking-overview',
		'title' => 'Short Ranking Overview Block',
		'description' => 'Short Ranking Overview Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/short-ranking-overview/short-ranking-overview.php',
	));

	/**
	 * ==============================
	 * HOME: Newsletter Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'newsletter-block',
		'title' => 'Newsletter Block',
		'description' => 'Newsletter Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/newsletter-block/newsletter-block.php',
	));



	/**
	 * ==============================
	 * Single Fighter Page: single-fighter-latest-fights-section Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-latest-fights-section-block',
		'title' => 'Single Fighter Latest Fights Section Block',
		'description' => 'Single Fighter Latest Fights Section Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-latest-fights-section/single-fighter-latest-fights-section.php',
	));

	/**
	 * ==============================
	 * Single Fighter Page: single-fighter-quick-stats-section Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-quick-stats-section-block',
		'title' => 'Single Fighter Quick Stats Section Block',
		'description' => 'Single Fighter Quick Stats Section Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-quick-stats-section/single-fighter-quick-stats-section.php',
	));

	/**
	 * ==============================
	 * Single Fighter Page: single-fighter-quick-stats-section Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-hero-section-block',
		'title' => 'Single Fighter Hero Section Block',
		'description' => 'Single Fighter Hero Section Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-hero-section/single-fighter-hero-section.php',
	));

	
	/**
	 * ==============================
	 * Single Fighter Page: single-fighter-quick-stats-section Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-hero-section-block',
		'title' => 'Single Fighter Hero Section Block',
		'description' => 'Single Fighter Hero Section Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-hero-section/single-fighter-hero-section.php',
	));

	/**
	 * ==============================
	 * Single Fighter Page: single-fighter-full-fight-history-section Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-full-fight-history-section-block',
		'title' => 'Single Fighter Full Fight History Section Block',
		'description' => 'Single Fighter Full Fight History Section Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-full-fight-history-section/single-fighter-full-fight-history-section.php',
	));
	/**
	 * ==============================
	 * Single Fighter Page: single-fighter-bio-section Block
	 * ==============================
	 */
	acf_register_block_type(array(
		'name' => 'single-fighter-bio-section-block',
		'title' => 'Single Fighter Bio Section Block',
		'description' => 'Single Fighter Bio Section Block',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-bio-section/single-fighter-bio-section.php',
	));

}


