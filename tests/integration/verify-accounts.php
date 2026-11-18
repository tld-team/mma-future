<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Services\Notifications\Auth;
use MMAF\DataEngine\Services\Notifications\AuthError;
use MMAF\DataEngine\Services\Notifications\Config;
use MMAF\DataEngine\Services\Notifications\Schema;
use MMAF\DataEngine\Services\Notifications\ServiceMailer;
use MMAF\DataEngine\Services\Notifications\Transport;

// Reader accounts: register with a confirmed email, sign in with a password, reset it. Local installs use the fake mailbox.
function assert_accounts( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

/** @return array{0:string,1:string,2:int} Exception class, field and status of what the call raised; empty class when it succeeded. */
function accounts_failure( callable $call ): array {
	try { $call(); return array( '', '', 0 ); }
	catch ( AuthError $e ) { return array( AuthError::class, $e->field, $e->status ); }
	catch ( Throwable $e ) { return array( get_class( $e ), '', 0 ); }
}

function accounts_mailbox(): array { return (array) get_option( 'mmaf_notification_fake_mailbox', array() ); }

/** The one-time token in the newest email to an address. */
function accounts_token( string $email, string $purpose ): string {
	foreach ( array_reverse( accounts_mailbox() ) as $mail ) {
		if ( $mail['to'] === $email && preg_match( '#/notification-action/\?purpose=' . $purpose . '&token=([A-Za-z0-9_-]+)#', (string) $mail['text'], $match ) ) { return $match[1]; }
	}
	return '';
}

function accounts_reset_limits( array $subjects ): void {
	foreach ( $subjects as $subject ) {
		foreach ( array( 'reg_e', 'reg_i', 'reset_e', 'reset_i', 'reset_u', 'login_e', 'login_i' ) as $bucket ) { delete_transient( 'mmaf_auth_' . $bucket . '_' . substr( hash( 'sha256', strtolower( (string) $subject ) ), 0, 24 ) ); }
	}
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
add_filter( 'send_auth_cookies', '__return_false' ); // The flows set a session; a command-line run has no browser to send it to.

$suffix      = strtolower( wp_generate_password( 8, false, false ) );
$tokens      = Schema::tables()['tokens'];
$new_email   = 'account-new-' . $suffix . '@example.test';
$ip          = '203.0.113.' . wp_rand( 1, 250 );
$old_mailbox = get_option( 'mmaf_notification_fake_mailbox', null );
$old_log     = get_option( 'mmaf_service_mail_log', null );
$user_ids    = array();
$password    = 'correct horse battery ' . $suffix;
update_option( 'mmaf_notification_fake_mailbox', array(), false );
update_option( 'mmaf_service_mail_log', array(), false );

try {
	$auth  = new Auth();
	$staff = wp_create_user( 'account_staff_' . $suffix, 'staff ' . $password, 'account-staff-' . $suffix . '@example.test' );
	if ( is_wp_error( $staff ) ) { throw new RuntimeException( $staff->get_error_message() ); }
	get_userdata( $staff )->set_role( 'editor' );
	$user_ids[]  = (int) $staff;
	$staff_email = get_userdata( $staff )->user_email;

	// Registration is refused before anything is stored or sent when the form itself is wrong.
	assert_accounts( array( AuthError::class, 'email', 400 ) === accounts_failure( static fn() => $auth->request_registration( 'not-an-email', $password, array(), $ip ) ), 'A malformed address was accepted.' );
	foreach ( array( 'short', '        ', 'password123', $new_email, 'account-new-' . $suffix, str_repeat( 'x', Auth::PASSWORD_MAX + 1 ) ) as $weak ) {
		assert_accounts( array( AuthError::class, 'password', 400 ) === accounts_failure( static fn() => $auth->request_registration( $new_email, $weak, array(), $ip ) ), 'A weak password was accepted: ' . wp_json_encode( $weak ) );
	}
	assert_accounts( '' === Auth::password_problem( str_repeat( 'ž', Auth::PASSWORD_MIN ) ) && '' === Auth::password_problem( " leading and trailing spaces " ), 'A long-enough password was refused.' );
	assert_accounts( array() === accounts_mailbox() && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tokens} WHERE email=%s", $new_email ) ), 'A refused registration still stored or sent something.' );

	// Asking: a link is emailed, the password is kept only as a hash, and no account exists yet.
	$answer = $auth->request_registration( $new_email, $password, array( 'return_to' => 'https://outside.example/steal' ), $ip );
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tokens} WHERE email=%s AND purpose='register'", $new_email ), ARRAY_A );
	$stored = json_decode( (string) $row['payload_json'], true );
	assert_accounts( str_contains( $answer['message'], $new_email ) && '' === $answer['delivery'], 'The answer to a registration request is not the neutral one.' );
	assert_accounts( $row && null === $row['user_id'] && ! str_contains( (string) $row['payload_json'], $password ) && wp_check_password( $password, (string) $stored['hash'] ) && home_url( '/account/' ) === $stored['return_to'], 'The pending registration holds the password in the clear, or an outside return address.' );
	assert_accounts( false === get_user_by( 'email', $new_email ), 'An account exists before the address was confirmed.' );
	$mail = accounts_mailbox();
	assert_accounts( 1 === count( $mail ) && $new_email === $mail[0]['to'] && str_contains( (string) $mail[0]['subject'], 'Confirm your email' ) && ! str_contains( wp_json_encode( $mail[0] ), $password ) && ! str_contains( wp_json_encode( $mail[0] ), (string) $stored['hash'] ), 'The confirmation email is missing, or carries the password.' );
	$register_token = accounts_token( $new_email, 'register' );
	assert_accounts( '' !== $register_token && ! str_contains( (string) $row['token_hash'], $register_token ), 'The emailed link has no token, or the token is stored unhashed.' );

	// Confirming creates the reader, already verified and signed in, and the link cannot be used twice.
	$done = $auth->confirm_registration( $register_token );
	$user = get_user_by( 'email', $new_email );
	assert_accounts( $user && (int) $done['user_id'] === (int) $user->ID && home_url( '/account/' ) === $done['return_to'], 'Confirming did not create the account.' );
	$user_ids[] = (int) $user->ID;
	assert_accounts( array( 'subscriber' ) === array_values( (array) $user->roles ) && Config::email_verified( (int) $user->ID ) && get_current_user_id() === (int) $user->ID, 'The new account is not a verified, signed-in reader.' );
	assert_accounts( wp_check_password( $password, $user->user_pass, $user->ID ) && ! wp_check_password( $password . 'x', $user->user_pass, $user->ID ), 'The password chosen at registration does not open the account.' );
	assert_accounts( null === $wpdb->get_var( $wpdb->prepare( "SELECT payload_json FROM {$tokens} WHERE id=%d", (int) $row['id'] ) ), 'A used registration link still holds the password hash.' );
	assert_accounts( RuntimeException::class === accounts_failure( static fn() => $auth->confirm_registration( $register_token ) )[0] && 1 === count( get_users( array( 'search' => $new_email, 'search_columns' => array( 'user_email' ) ) ) ), 'A registration link worked twice.' );
	wp_set_current_user( 0 );

	// The same answer for an address that already has an account; the owner is told by email instead.
	accounts_reset_limits( array( $new_email, $ip ) );
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tokens} WHERE purpose='register'" );
	$again  = $auth->request_registration( $new_email, 'another ' . $password, array(), $ip );
	$mail   = accounts_mailbox();
	assert_accounts( $again === $answer && $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tokens} WHERE purpose='register'" ), 'Registering a taken address answers differently or issues a second link.' );
	assert_accounts( 2 === count( $mail ) && str_contains( (string) end( $mail )['subject'], 'already have an account' ) && wp_check_password( $password, get_userdata( $user->ID )->user_pass, $user->ID ), 'The owner was not told, or the existing password changed.' );
	$auth->request_registration( $staff_email, $password, array(), $ip );
	$auth->request_reset( $staff_email, $ip );
	assert_accounts( 2 === count( accounts_mailbox() ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tokens} WHERE email=%s", $staff_email ) ), 'A staff address was sent a reader link.' );

	// Signing in.
	accounts_reset_limits( array( $new_email, $staff_email, $ip ) );
	assert_accounts( array( AuthError::class, '', 401 ) === accounts_failure( static fn() => $auth->sign_in( $new_email, 'wrong ' . $password, array(), $ip ) ), 'A wrong password was accepted, or says which part was wrong.' );
	assert_accounts( array( AuthError::class, '', 401 ) === accounts_failure( static fn() => $auth->sign_in( 'nobody-' . $suffix . '@example.test', $password, array(), $ip ) ), 'An unknown address answers differently from a wrong password.' );
	assert_accounts( array( AuthError::class, 'password', 400 ) === accounts_failure( static fn() => $auth->sign_in( $new_email, '', array(), $ip ) ), 'An empty password is not reported on its field.' );
	$signed = $auth->sign_in( strtoupper( $new_email ), $password, array( 'return_to' => home_url( '/fighters/' ) ), $ip );
	assert_accounts( (int) $signed['user_id'] === (int) $user->ID && home_url( '/fighters/' ) === $signed['return_to'] && get_current_user_id() === (int) $user->ID, 'The right password did not sign the reader in.' );
	wp_set_current_user( 0 );
	assert_accounts( array( AuthError::class, '', 403 ) === accounts_failure( static fn() => $auth->sign_in( $staff_email, 'staff ' . $password, array(), $ip ) ) && 0 === get_current_user_id(), 'A staff account could sign in through the reader form.' );
	for ( $attempt = 0; $attempt < 5; $attempt++ ) { accounts_failure( static fn() => $auth->sign_in( $new_email, 'guess ' . $attempt, array(), $ip ) ); }
	assert_accounts( array( AuthError::class, '', 429 ) === accounts_failure( static fn() => $auth->sign_in( $new_email, $password, array(), $ip ) ) && 0 === get_current_user_id(), 'Repeated wrong passwords do not lock the address for a while.' );
	accounts_reset_limits( array( $new_email, $ip ) );

	// Resetting: the same answer for any address, a link only for a reader, and a weak choice keeps the link usable.
	$unknown = $auth->request_reset( 'nobody-' . $suffix . '@example.test', $ip );
	$known   = $auth->request_reset( $new_email, $ip );
	assert_accounts( str_replace( 'nobody-' . $suffix . '@example.test', '', $unknown['message'] ) === str_replace( $new_email, '', $known['message'] ) && 3 === count( accounts_mailbox() ), 'A password request reveals whether the address has an account, or sent the wrong number of emails.' );
	$reset_token = accounts_token( $new_email, 'password_reset' );
	$sessions    = WP_Session_Tokens::get_instance( (int) $user->ID );
	$old_session = $sessions->create( time() + HOUR_IN_SECONDS );
	$new_password = 'a different passphrase ' . $suffix;
	assert_accounts( array( AuthError::class, 'password', 400 ) === accounts_failure( static fn() => $auth->reset_password( $reset_token, 'short' ) ) && wp_check_password( $password, get_userdata( $user->ID )->user_pass, $user->ID ), 'A weak new password was accepted or changed the account.' );
	$reset = $auth->reset_password( $reset_token, $new_password );
	$fresh = get_userdata( $user->ID );
	assert_accounts( (int) $reset['user_id'] === (int) $user->ID && wp_check_password( $new_password, $fresh->user_pass, $user->ID ) && ! wp_check_password( $password, $fresh->user_pass, $user->ID ), 'The reset did not replace the password, or a refused attempt spent the link.' );
	assert_accounts( ! $sessions->verify( $old_session ), 'Sessions opened before the reset still work.' );
	assert_accounts( RuntimeException::class === accounts_failure( static fn() => $auth->reset_password( $reset_token, 'yet another passphrase' ) )[0] && wp_check_password( $new_password, get_userdata( $user->ID )->user_pass, $user->ID ), 'A password link worked twice.' );
	wp_set_current_user( 0 );
	assert_accounts( (int) $auth->sign_in( $new_email, $new_password, array(), $ip )['user_id'] === (int) $user->ID, 'The new password does not sign the reader in.' );

	// A signed-in reader can ask for the same link (a Google account has no password to begin with); staff cannot.
	$count = count( accounts_mailbox() );
	$link  = $auth->request_reset_for( (int) $user->ID );
	assert_accounts( str_contains( $link['message'], $new_email ) && $count + 1 === count( accounts_mailbox() ) && '' !== accounts_token( $new_email, 'password_reset' ), 'A signed-in reader cannot get a password link.' );
	assert_accounts( array( AuthError::class, '', 403 ) === accounts_failure( static fn() => $auth->request_reset_for( (int) $staff ) ), 'Staff can request a reader password link.' );

	// Limits on requests that send email.
	accounts_reset_limits( array( $new_email, $ip ) );
	$limited = 'account-limit-' . $suffix . '@example.test';
	for ( $attempt = 0; $attempt < 3; $attempt++ ) { $auth->request_registration( $limited, $password, array(), $ip ); }
	assert_accounts( array( AuthError::class, '', 429 ) === accounts_failure( static fn() => $auth->request_registration( $limited, $password, array(), $ip ) ), 'One address can be sent confirmation emails without limit.' );

	// Every account email leaves a record an administrator can read, with the address masked; a refused send is visible.
	$log = ServiceMailer::log();
	assert_accounts( count( $log ) === count( accounts_mailbox() ) && 'register' === $log[0]['kind'] && 'accepted' === $log[0]['status'] && 'a***@example.test' === $log[0]['to'] && ! str_contains( wp_json_encode( $log ), $new_email ), 'Account emails are not recorded, or the record names the reader.' );
	$refuse = static fn() => new class() implements Transport { public function send( string $to, string $subject, string $html, string $text, array $headers = array() ): array { return array( 'status' => 'rejected', 'error' => 'staging_recipient_blocked', 'http_code' => 0 ); } };
	add_filter( 'mmaf_notifications_transport', $refuse );
	accounts_reset_limits( array( $new_email, $ip ) );
	$auth->request_reset( $new_email, $ip );
	remove_filter( 'mmaf_notifications_transport', $refuse );
	$last = ServiceMailer::log();
	$last = end( $last );
	assert_accounts( 'password_reset' === $last['kind'] && 'rejected' === $last['status'] && 'staging_recipient_blocked' === $last['error'], 'A refused account email leaves no trace of why.' );

	// REST: the forms' endpoints are for signed-out visitors; errors name the field they belong to.
	wp_set_current_user( 0 );
	accounts_reset_limits( array( $new_email, $ip, '', '127.0.0.1' ) );
	$post = static function ( string $route, array $body ): WP_REST_Response { $request = new WP_REST_Request( 'POST', '/mma-future/v1/notifications/' . $route ); $request->set_body_params( $body ); return rest_do_request( $request ); };
	$response = $post( 'auth/register', array( 'email' => 'rest-' . $new_email, 'password' => 'short' ) );
	assert_accounts( 400 === $response->get_status() && 'password' === $response->get_data()['data']['field'], 'A registration error over REST does not name its field.' );
	$response = $post( 'auth/login', array( 'email' => $new_email, 'password' => 'wrong ' . $password ) );
	assert_accounts( 401 === $response->get_status() && 0 === get_current_user_id(), 'A wrong password over REST is not a 401.' );
	$response = $post( 'auth/login', array( 'email' => $new_email, 'password' => $new_password, 'return_to' => 'https://outside.example/steal' ) );
	assert_accounts( 200 === $response->get_status() && array( 'redirect' => home_url( '/account/' ) ) === $response->get_data() && get_current_user_id() === (int) $user->ID, 'Signing in over REST does not answer with a safe place to go.' );
	foreach ( array( 'auth/register', 'auth/login', 'auth/password/forgot' ) as $route ) {
		assert_accounts( 403 === $post( $route, array( 'email' => $new_email, 'password' => $new_password ) )->get_status(), "A signed-in reader can use $route." );
	}
	accounts_reset_limits( array( (string) $user->ID ) );
	assert_accounts( 200 === $post( 'password-link', array() )->get_status(), 'A signed-in reader cannot request a password link over REST.' );
	wp_set_current_user( 0 );
	assert_accounts( 401 === $post( 'password-link', array() )->get_status(), 'A signed-out visitor can request a password link without naming an address.' );
	assert_accounts( 404 === rest_do_request( new WP_REST_Request( 'POST', '/mma-future/v1/notifications/auth/email' ) )->get_status(), 'The email-link sign-in route still exists.' );

	echo wp_json_encode( array( 'ok' => true ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	wp_set_current_user( 0 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
	$created = get_user_by( 'email', $new_email );
	if ( $created ) { $user_ids[] = (int) $created->ID; }
	foreach ( array_unique( $user_ids ) as $id ) { $wpdb->delete( $tokens, array( 'user_id' => $id ) ); wp_delete_user( $id ); }
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE email LIKE %s", '%' . $wpdb->esc_like( $suffix . '@example.test' ) ) );
	accounts_reset_limits( array( $new_email, 'account-limit-' . $suffix . '@example.test', 'account-staff-' . $suffix . '@example.test', 'nobody-' . $suffix . '@example.test', 'rest-' . $new_email, $ip, '', '127.0.0.1' ) );
	if ( null === $old_mailbox ) { delete_option( 'mmaf_notification_fake_mailbox' ); } else { update_option( 'mmaf_notification_fake_mailbox', $old_mailbox, false ); }
	if ( null === $old_log ) { delete_option( 'mmaf_service_mail_log' ); } else { update_option( 'mmaf_service_mail_log', $old_log, false ); }
}
