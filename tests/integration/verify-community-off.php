<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Services\Community\Avatars;
use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\FanBoard;
use MMAF\DataEngine\Services\Community\Module;
use MMAF\DataEngine\Services\Community\Routes;

// With the switch off, the site must behave exactly as it did before the community code existed.
// The account template is included at file scope on purpose, like verify-notification-pages.php.
function assert_community_off( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
$mmafc_off_suffix = strtolower( wp_generate_password( 8, false, false ) );
$mmafc_off_user   = wp_create_user( 'community_off_' . $mmafc_off_suffix, wp_generate_password( 24, true, true ), 'community-off-' . $mmafc_off_suffix . '@example.test' );
if ( is_wp_error( $mmafc_off_user ) ) { throw new RuntimeException( $mmafc_off_user->get_error_message() ); }
get_userdata( $mmafc_off_user )->set_role( 'subscriber' );
$mmafc_off_get = $_GET;

try {
	assert_community_off( ! defined( 'MMAF_COMMUNITY_ENABLED' ), 'This check needs an install where the community switch is not set.' );
	assert_community_off( ! Config::feature_enabled() && ! Config::area_enabled( 'profiles' ), 'Community is on without its switch.' );
	assert_community_off( in_array( 'constant_missing', Config::disabled_reasons(), true ), 'The admin page cannot say that the switch is missing.' );

	global $wp_rewrite;
	$rules = array_keys( (array) $wp_rewrite->extra_rules_top );
	foreach ( array( '^login/?$', '^register/?$', '^account/?$', '^notification\-action/?$', '^account/profile/?$', '^account/top-10/?$' ) as $rule ) {
		assert_community_off( in_array( $rule, $rules, true ), "The rewrite rule $rule is not registered." );
	}

	wp_set_current_user( (int) $mmafc_off_user );
	ob_start(); Module::account_nav( 'notifications' ); do_action( 'mmaf_account_nav', 'notifications' ); $mmafc_off_nav = (string) ob_get_clean();
	assert_community_off( '' === $mmafc_off_nav, 'The account tabs print while profiles are off.' );
	assert_community_off( home_url( '/account/' ) === apply_filters( 'mmaf_account_registered_return_to', home_url( '/account/' ), (int) $mmafc_off_user ), 'A new reader is sent to the profile form while profiles are off.' );

	$_GET = array();
	set_query_var( 'mmaf_notification_page', 'account' );
	ob_start();
	include MMAF_PLUGIN_DIR . 'templates/notifications-page.php';
	$mmafc_off_account = (string) ob_get_clean();
	assert_community_off( str_contains( $mmafc_off_account, '<h1>Your notifications</h1>' ) && str_contains( $mmafc_off_account, 'data-mmaf-settings' ), 'The account page lost its content.' );
	assert_community_off( ! str_contains( $mmafc_off_account, 'mmafc' ), 'The account page carries community markup while profiles are off.' );
	Module::assets();
	assert_community_off( ! wp_style_is( 'mmaf-community', 'enqueued' ) && ! wp_script_is( 'mmaf-community', 'enqueued' ), 'Community assets load while profiles are off.' );
	set_query_var( 'mmaf_notification_page', '' );

	assert_community_off( 'theme-template.php' === Routes::template( 'theme-template.php' ), 'An ordinary request is routed to a community template.' );
	assert_community_off( array( 'x' => 1 ) === Routes::title( array( 'x' => 1 ) ) && 'Blog' === Routes::document_title( 'Blog' ) && array() === Routes::robots( array() ), 'An ordinary request has its title or robots changed.' );
	assert_community_off( array( 'size' => 96 ) === Avatars::avatar_data( array( 'size' => 96 ), (int) $mmafc_off_user ), 'Avatars are replaced while profiles are off.' );

	// The theme calls these three behind class_exists(); while the fan area is off they must add nothing to its pages.
	$_GET = array( FanBoard::QUERY_VAR => 'fans' );
	assert_community_off( false === FanBoard::requested() && '' === FanBoard::source_toggle( 'official' ) && '' === FanBoard::chip( 1 ), 'The fan ranking shows on public pages while it is off.' );
	$_GET = array();
	assert_community_off( 401 === rest_do_request( new WP_REST_Request( 'GET', '/mma-future/v1/community/fan-scores' ) )->get_status() || 403 === rest_do_request( new WP_REST_Request( 'GET', '/mma-future/v1/community/fan-scores' ) )->get_status(), 'Fan scores are public while the fan ranking is off.' );

	foreach ( array( array( 'GET', '/mma-future/v1/community/top-10' ), array( 'POST', '/mma-future/v1/community/top-10' ), array( 'GET', '/mma-future/v1/community/profile' ), array( 'POST', '/mma-future/v1/community/profile' ), array( 'POST', '/mma-future/v1/community/profile/avatar' ), array( 'GET', '/mma-future/v1/community/reputation' ), array( 'GET', '/mma-future/v1/community/props' ), array( 'POST', '/mma-future/v1/community/props' ), array( 'DELETE', '/mma-future/v1/community/props' ), array( 'GET', '/mma-future/v1/community/comments' ), array( 'POST', '/mma-future/v1/community/comments' ), array( 'POST', '/mma-future/v1/community/comments/1/vote' ), array( 'POST', '/mma-future/v1/community/comments/1/report' ), array( 'DELETE', '/mma-future/v1/community/profile/avatar' ) ) as $route ) {
		$status = rest_do_request( new WP_REST_Request( $route[0], $route[1] ) )->get_status();
		assert_community_off( 403 === $status, "{$route[0]} {$route[1]} answered $status while profiles are off." );
	}

	// One area can stay off while the feature as a whole is on.
	add_filter( 'mmaf_community_feature_enabled', '__return_true' );
	add_filter( 'mmaf_community_area_enabled', '__return_false' );
	assert_community_off( Config::feature_enabled() && ! Config::area_enabled( 'profiles' ), 'An area cannot be switched off on its own.' );
	assert_community_off( array() === Config::disabled_reasons(), 'A reason is reported while the feature is on.' );
	remove_filter( 'mmaf_community_area_enabled', '__return_false' );
	assert_community_off( Config::area_enabled( 'profiles' ) && ! Config::area_enabled( 'unknown' ), 'A known area does not follow the feature switch, or an unknown one is on.' );
	assert_community_off( ! Config::area_enabled( 'fan' ), 'The fan ranking is on by default once profiles are; it must be switched on separately.' );
	assert_community_off( ! Config::area_enabled( 'reputation' ), 'Levels and props are on by default once profiles are; they must be switched on separately.' );
	assert_community_off( ! Config::area_enabled( 'comments' ) && '' === \MMAF\DataEngine\Services\Community\Comments::shell( 1 ), 'The discussion shows by default once profiles are; it must be switched on separately.' );

	echo wp_json_encode( array( 'ok' => true ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	if ( ob_get_level() > 0 && ! isset( $mmafc_off_account ) ) { ob_end_clean(); }
	$_GET = $mmafc_off_get;
	remove_filter( 'mmaf_community_feature_enabled', '__return_true' );
	remove_filter( 'mmaf_community_area_enabled', '__return_false' );
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
	set_query_var( 'mmaf_notification_page', '' );
	wp_set_current_user( 0 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $mmafc_off_user );
}
