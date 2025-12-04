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
		'name' => 'single-fighter-hero',
		'title' => 'Single Fighter Hero',
		'description' => 'Single Fighter Hero',
		'category' => 'custom_theme',
		'mode' => 'preview',
		'supports' => array(
			'align' => true,
			'mode' => true,
			'jsx' => true,
			'anchor' => true,
		),
		'render_template' => 'blocks/single-fighter-hero/single-fighter-hero.php',
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
}
