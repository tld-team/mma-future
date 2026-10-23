<?php
namespace MMAF\DataEngine\Services\Upcoming;

use MMAF\DataEngine\Admin\CalendarPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module {
	public static function register(): void {
		CalendarPage::register();
		add_shortcode( 'mmaf_event_calendar', array( self::class, 'shortcode' ) );
	}

	public static function shortcode( array $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'title' => 'Upcoming MMA Events', 'intro' => '' ), $attributes, 'mmaf_event_calendar' );
		ob_start();
		Renderer::render( $attributes );
		return (string) ob_get_clean();
	}
}
