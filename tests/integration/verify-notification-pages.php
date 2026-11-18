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
	foreach ( array( 'login', 'register', 'forgot', 'account', 'notification-action', 'confirm-registration', 'reset-password', 'reset-password-retry' ) as $mmaf_test_key ) {
		$mmaf_test_view = array( 'forgot' => 'login', 'confirm-registration' => 'notification-action', 'reset-password' => 'notification-action', 'reset-password-retry' => 'notification-action' )[ $mmaf_test_key ] ?? $mmaf_test_key;
		$_GET = 'forgot' === $mmaf_test_key ? array( 'forgot' => '1' ) : array();
		wp_set_current_user( 'account' === $mmaf_test_view ? (int) $mmaf_test_user : 0 );
		if ( 'notification-action' === $mmaf_test_key ) {
			$_GET = array( 'purpose' => 'unsubscribe', 'token' => ( new Tokens() )->issue( 'unsubscribe', (int) $mmaf_test_user, null, array(), 60 ) );
		} elseif ( 'confirm-registration' === $mmaf_test_key ) {
			$_GET = array( 'purpose' => 'register', 'token' => ( new Tokens() )->issue( 'register', null, 'pages-new-' . $mmaf_test_suffix . '@example.test', array( 'hash' => wp_hash_password( 'fixture-only-passphrase' ) ), 600 ) );
		} elseif ( in_array( $mmaf_test_key, array( 'reset-password', 'reset-password-retry' ), true ) ) {
			$_GET = array( 'purpose' => 'password_reset', 'token' => ( new Tokens() )->issue( 'password_reset', (int) $mmaf_test_user, get_userdata( $mmaf_test_user )->user_email, array(), 600 ) );
			if ( 'reset-password-retry' === $mmaf_test_key ) { $_GET['mmaf_error'] = 'Use at least 8 characters.'; }
		}
		set_query_var( 'mmaf_notification_page', $mmaf_test_view );
		ob_start();
		include $mmaf_test_template;
		$mmaf_test_output[ $mmaf_test_key ] = (string) ob_get_clean();
		set_query_var( 'mmaf_notification_page', $mmaf_test_view );
		$mmaf_test_titles[ $mmaf_test_key ] = Module::document_title( 'Blog' );
		$mmaf_test_robots[ $mmaf_test_key ] = Module::robots( array( 'max-image-preview' => 'large' ) );
	}
	set_query_var( 'mmaf_notification_page', '' );

	$source = (string) file_get_contents( $mmaf_test_template );
	assert_notification_pages( ! preg_match( '/\$(page|pages|s|post|posts|id|more|numpages|multipage|authordata|wp_query)\b\s*=(?!=)/', $source ), 'The page template assigns a WordPress global that get_header() resets.' );
	foreach ( array( 'login' => 'Sign in', 'register' => 'Create your account', 'forgot' => 'Reset your password' ) as $view => $heading ) {
		assert_notification_pages( str_contains( $mmaf_test_output[ $view ], '<h1>' . $heading . '</h1>' ), "The $view page lost its heading." );
		assert_notification_pages( str_contains( $mmaf_test_output[ $view ], 'data-mmaf-auth="' . $view . '"' ) && str_contains( $mmaf_test_output[ $view ], 'type="email"' ), "The $view page rendered without its form." );
		assert_notification_pages( ( 'forgot' === $view ) !== str_contains( $mmaf_test_output[ $view ], 'type="password"' ), "The $view page has the wrong password field: only sign-in and registration ask for one." );
		assert_notification_pages( ( 'forgot' === $view ) !== str_contains( $mmaf_test_output[ $view ], 'mmaf-google-login' ), "Google sign-in is offered on the wrong pages: $view." );
	}
	assert_notification_pages( str_contains( $mmaf_test_output['login'], 'autocomplete="current-password"' ) && str_contains( $mmaf_test_output['login'], '?forgot=1' ) && str_contains( $mmaf_test_output['register'], 'autocomplete="new-password"' ) && ! str_contains( $mmaf_test_output['register'], '?forgot=1' ), 'The password fields are not labelled for password managers, or the forgot link is misplaced.' );
	assert_notification_pages( ! str_contains( $mmaf_test_output['login'] . $mmaf_test_output['register'], 'sign-in link' ) && ! str_contains( $mmaf_test_output['login'] . $mmaf_test_output['register'], 'data-mmaf-email-login' ), 'The email-link sign-in is still offered.' );
	assert_notification_pages( str_contains( $mmaf_test_output['confirm-registration'], 'value="mmafc_register_confirm"' ) && str_contains( $mmaf_test_output['confirm-registration'], 'pages-new-' . $mmaf_test_suffix . '@example.test' ) && ! str_contains( $mmaf_test_output['confirm-registration'], '$wp$' ) && ! str_contains( $mmaf_test_output['confirm-registration'], '$P$' ), 'The registration link has no confirmation form, or prints what it carries.' );
	assert_notification_pages( str_contains( $mmaf_test_output['reset-password'], 'value="mmafc_password_reset"' ) && str_contains( $mmaf_test_output['reset-password'], 'autocomplete="new-password"' ) && ! str_contains( $mmaf_test_output['reset-password'], 'mmaf-alert--error' ), 'The password link has no new-password form.' );
	assert_notification_pages( str_contains( $mmaf_test_output['reset-password-retry'], 'Use at least 8 characters.' ) && str_contains( $mmaf_test_output['reset-password-retry'], 'value="mmafc_password_reset"' ), 'A refused password does not bring the reader back to the form with the reason.' );
	assert_notification_pages( str_starts_with( $mmaf_test_titles['forgot'], 'Reset your password - ' ) && str_starts_with( $mmaf_test_titles['confirm-registration'], 'Confirm your email - ' ) && str_starts_with( $mmaf_test_titles['reset-password'], 'Set a new password - ' ), 'The account pages do not name themselves in the title.' );
	assert_notification_pages( str_contains( $mmaf_test_output['account'], 'data-mmaf-password-link' ), 'The account page has no way to set or change a password.' );
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
	$wpdb->delete( NotificationSchema::tables()['tokens'], array( 'email' => 'pages-new-' . $mmaf_test_suffix . '@example.test' ) );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $mmaf_test_user );
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
}
