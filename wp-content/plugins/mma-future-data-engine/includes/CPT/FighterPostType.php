<?php
namespace MMAF\DataEngine\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterPostType {
	public const POST_TYPE = 'mmaf_fighter';

	public static function register(): void {
		$labels = array(
			'name'               => __( 'Fighters', 'mma-future-data-engine' ),
			'singular_name'      => __( 'Fighter', 'mma-future-data-engine' ),
			'add_new_item'       => __( 'Add New Fighter', 'mma-future-data-engine' ),
			'edit_item'          => __( 'Edit Fighter', 'mma-future-data-engine' ),
			'new_item'           => __( 'New Fighter', 'mma-future-data-engine' ),
			'view_item'          => __( 'View Fighter', 'mma-future-data-engine' ),
			'search_items'       => __( 'Search Fighters', 'mma-future-data-engine' ),
			'not_found'          => __( 'No fighters found.', 'mma-future-data-engine' ),
			'not_found_in_trash' => __( 'No fighters found in Trash.', 'mma-future-data-engine' ),
			'all_items'          => __( 'All Fighters', 'mma-future-data-engine' ),
			'menu_name'          => __( 'Fighters', 'mma-future-data-engine' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => $labels,
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'fighters' ),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-groups',
			)
		);
	}
}
