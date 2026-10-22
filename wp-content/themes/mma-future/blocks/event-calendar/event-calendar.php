<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$title = function_exists( 'get_field' ) ? get_field( 'calendar_title' ) : '';
$intro = function_exists( 'get_field' ) ? get_field( 'calendar_intro' ) : '';

if ( class_exists( '\MMAF\DataEngine\Services\Upcoming\Renderer' ) ) {
	\MMAF\DataEngine\Services\Upcoming\Renderer::render(
		array(
			'title' => $title ?: 'Kalendar MMA događaja',
			'intro' => $intro ?: '',
		)
	);
} elseif ( current_user_can( 'edit_posts' ) ) {
	echo '<p>MMA Future Data Engine mora biti aktivan za prikaz kalendara.</p>';
}
