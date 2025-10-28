<?php
/** ==============================
 * About eBook
 * ============================== */
acf_register_block_type( array(
	'name'            => 'about-ebook',
	'title'           => 'About ebook',
	'description'     => 'About ebook',
	'category'        => 'custom_theme',
	'mode'            => 'preview',
	'supports'        => array(
		'align' => true,
		'mode'  => false,
		'jsx'   => true,
	),
	'render_template' => 'blocks/block-1/block-1.php',
) );