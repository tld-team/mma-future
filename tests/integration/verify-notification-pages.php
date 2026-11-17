<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Services\Notifications\Module;
use MMAF\DataEngine\Services\Notifications\Schema as NotificationSchema;
use MMAF\DataEngine\Services\Notifications\Tokens;

// The template is included at file scope on purpose: WordPress loads templates in the global scope, where
// get_header() resets names such as $page. Rendering inside a function would hide exactly that failure.
function assert_notification_pages( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
$mmaf_test_template = MMAF_PLUGIN_DIR . 'templates/notifications-page.php';
$mmaf_test_suffix   = strtolower( wp_generate_password( 8, false, false ) );
$mmaf_test_user     = wp_create_user( 'notification_pages_' . $mmaf_test_suffix, wp_generate_password( 24, true, true ), 'notification-pages-' . $mmaf_test_suffix . '@example.test' );
if ( is_wp_error( $mmaf_test_user ) ) { throw new RuntimeException( $mmaf_test_user->get_error_message() ); }
get_userdata( $mmaf_test_user )->set_role( 'subscriber' );
$mmaf_test_get    = $_GET;
$mmaf_test_output = array();
$mmaf_test_titles = array();
$mmaf_test_robots = array();

try {
	foreach ( array( 'login', 'register', 'account', 'notification-action' ) as $mmaf_test_view ) {
		$_GET = array();
		wp_set_current_user( 'account' === $mmaf_test_view ? (int) $mmaf_test_user : 0 );
		if ( 'notification-action' === $mmaf_test_view ) {
			$_GET = array( 'purpose' => 'unsubscribe', 'token' => ( new Tokens() )->issue( 'unsubscribe', (int) $mmaf_test_user, null, array(), 60 ) );
		}
		set_query_var( 'mmaf_notification_page', $mmaf_test_view );
		ob_start();
		include $mmaf_test_template;
		$mmaf_test_output[ $mmaf_test_view ] = (string) ob_get_clean();
		set_query_var( 'mmaf_notification_page', $mmaf_test_view );
		$mmaf_test_titles[ $mmaf_test_view ] = Module::document_title( 'Blog' );
		$mmaf_test_robots[ $mmaf_test_view ] = Module::robots( array( 'max-image-preview' => 'large' ) );
	}
	set_query_var( 'mmaf_notification_page', '' );

	$source = (string) file_get_contents( $mmaf_test_template );
	assert_notification_pages( ! preg_match( '/\$(page|pages|s|post|posts|id|more|numpages|multipage|authordata|wp_query)\b\s*=(?!=)/', $source ), 'The page template assigns a WordPress global that get_header() resets.' );
	foreach ( array( 'login' => 'Sign in', 'register' => 'Create your account' ) as $view => $heading ) {
		assert_notification_pages( str_contains( $mmaf_test_output[ $view ], '<h1>' . $heading . '</h1>' ), "The $view page lost its heading." );
		assert_notification_pages( str_contains( $mmaf_test_output[ $view ], 'data-mmaf-email-login' ) && str_contains( $mmaf_test_output[ $view ], 'type="email"' ), "The $view page rendered without the sign-in form." );
	}
	assert_notification_pages( str_contains( $mmaf_test_output['account'], 'Followed fighters' ) && str_contains( $mmaf_test_output['account'], 'data-mmaf-settings' ), 'The account page rendered without its sections.' );
	assert_notification_pages( str_contains( $mmaf_test_output['account'], 'action=logout' ) && str_contains( $mmaf_test_output['account'], 'Sign out' ), 'The account page has no sign-out link.' );
	assert_notification_pages( str_contains( $mmaf_test_output['notification-action'], 'value="mmaf_token_action"' ), 'A valid secure link rendered without its confirmation form.' );
	assert_notification_pages( str_starts_with( $mmaf_test_titles['login'], 'Sign in - ' ) && 'Blog' === Module::document_title( 'Blog' ), 'The sign-in page does not own its document title.' );
	assert_notification_pages( ! empty( $mmaf_test_robots['account']['noindex'] ) && ! isset( Module::robots( array() )['noindex'] ), 'Account pages are not marked noindex.' );
	echo wp_json_encode( array( 'ok' => true, 'views' => array_keys( $mmaf_test_output ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	if ( ob_get_level() > 0 && ! isset( $mmaf_test_output['notification-action'] ) ) { ob_end_clean(); }
	$_GET = $mmaf_test_get;
	set_query_var( 'mmaf_notification_page', '' );
	wp_set_current_user( 0 );
	global $wpdb;
	$wpdb->delete( NotificationSchema::tables()['tokens'], array( 'user_id' => (int) $mmaf_test_user ) );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $mmaf_test_user );
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
}
