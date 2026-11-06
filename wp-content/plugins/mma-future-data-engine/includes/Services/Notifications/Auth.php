<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Auth {
	private Tokens $tokens;
	public function __construct() { $this->tokens = new Tokens(); }

	public function request_link( string $email, array $intent = array(), string $ip = '' ): void {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) { return; }
		$email_key = 'mmaf_login_e_' . substr( hash( 'sha256', strtolower( $email ) ), 0, 24 );
		$ip_key = 'mmaf_login_i_' . substr( hash( 'sha256', $ip ), 0, 24 );
		if ( (int) get_transient( $email_key ) >= 3 || (int) get_transient( $ip_key ) >= 12 ) { return; }
		set_transient( $email_key, (int) get_transient( $email_key ) + 1, 15 * MINUTE_IN_SECONDS );
		set_transient( $ip_key, (int) get_transient( $ip_key ) + 1, 15 * MINUTE_IN_SECONDS );

		$user = get_user_by( 'email', $email );
		if ( $user && ( ! in_array( 'subscriber', (array) $user->roles, true ) || user_can( $user, 'edit_posts' ) ) ) { return; }
		$payload = $this->sanitize_intent( $intent );
		$token = $this->tokens->issue( 'magic_login', $user ? (int) $user->ID : null, $email, $payload, 15 * MINUTE_IN_SECONDS );
		$link = $this->tokens->action_url( 'magic_login', $token );
		$subject = sprintf( '[%s] Your secure sign-in link', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body = "Open this link to review and confirm your sign-in request. The link expires in 15 minutes.\n\n" . $link . "\n\nIf you did not request this, you can ignore this message.";
		ServiceMailer::send( $email, $subject, nl2br( esc_html( $body ) ), $body );
	}

	public function confirm( string $token ): array {
		$row = $this->tokens->consume( 'magic_login', $token );
		if ( ! $row ) { throw new \RuntimeException( 'This sign-in link is invalid, expired, or already used.' ); }
		$user = ! empty( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : get_user_by( 'email', (string) $row['email'] );
		if ( $user && ( ! in_array( 'subscriber', (array) $user->roles, true ) || user_can( $user, 'edit_posts' ) ) ) { throw new \RuntimeException( 'This sign-in method is not available for that account.' ); }
		if ( ! $user ) {
			$base = sanitize_user( strtok( (string) $row['email'], '@' ), true ) ?: 'subscriber';
			$login = $base; $suffix = 1;
			while ( username_exists( $login ) ) { $login = $base . $suffix++; }
			$user_id = wp_create_user( $login, wp_generate_password( 32, true, true ), (string) $row['email'] );
			if ( is_wp_error( $user_id ) ) { throw new \RuntimeException( $user_id->get_error_message() ); }
			$user = get_userdata( (int) $user_id );
			$user->set_role( 'subscriber' );
		}
		update_user_meta( (int) $user->ID, '_mmaf_email_verified', '1' );
		if ( '' === get_user_meta( (int) $user->ID, '_mmaf_notification_timezone', true ) ) { update_user_meta( (int) $user->ID, '_mmaf_notification_timezone', 'Europe/Belgrade' ); }
		wp_set_current_user( (int) $user->ID ); wp_set_auth_cookie( (int) $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		$payload = $row['payload'];
		if ( ! empty( $payload['fighter_id'] ) ) { ( new Subscriptions() )->follow( (int) $user->ID, (int) $payload['fighter_id'] ); }
		return array( 'user_id' => (int) $user->ID, 'return_to' => $this->safe_return( (string) ( $payload['return_to'] ?? '' ) ) );
	}

	public function sanitize_intent( array $intent ): array {
		return array( 'fighter_id' => absint( $intent['fighter_id'] ?? 0 ), 'return_to' => $this->safe_return( (string) ( $intent['return_to'] ?? '' ) ) );
	}

	public function safe_return( string $url ): string {
		$fallback = home_url( '/account/' );
		$url = wp_validate_redirect( $url, $fallback );
		return 0 === strpos( $url, home_url() ) ? $url : $fallback;
	}
}
