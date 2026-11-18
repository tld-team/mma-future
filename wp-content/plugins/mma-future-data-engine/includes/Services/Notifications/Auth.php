<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reader accounts: register with email and password, sign in, and reset a password.
 *
 * An account is created only after the address has been confirmed from the email, so there is never an account whose
 * mailbox nobody has proved they own. Requests answer the same way whether or not an account exists for the address.
 * Staff accounts are refused here and keep using the WordPress login.
 */
final class Auth {
	public const PASSWORD_MIN = 8;
	public const PASSWORD_MAX = 128;

	private const REGISTER_TTL = DAY_IN_SECONDS;
	private const RESET_TTL    = HOUR_IN_SECONDS;
	private const WINDOW       = 15 * MINUTE_IN_SECONDS;
	/** Passwords so common that length alone does not make them a password. */
	private const TRIVIAL = array( 'password', 'password1', 'password123', '12345678', '123456789', '1234567890', 'qwertyui', 'qwertyuiop', 'iloveyou', '11111111', 'mmafuture' );

	private Tokens $tokens;
	public function __construct() { $this->tokens = new Tokens(); }

	/** Why this password cannot be used, or '' when it can. */
	public static function password_problem( string $password, string $email = '' ): string {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $password, 'UTF-8' ) : strlen( $password );
		if ( $length < self::PASSWORD_MIN || '' === trim( $password ) ) { return 'Use at least ' . self::PASSWORD_MIN . ' characters.'; }
		if ( $length > self::PASSWORD_MAX ) { return 'Use ' . self::PASSWORD_MAX . ' characters or fewer.'; }
		$lower = strtolower( $password );
		$email = strtolower( trim( $email ) );
		if ( in_array( $lower, self::TRIVIAL, true ) || ( '' !== $email && ( $lower === $email || $lower === strtok( $email, '@' ) ) ) ) { return 'Choose a password that is harder to guess.'; }
		return '';
	}

	/**
	 * Step one of registration: remember the password (hashed) against a one-time link and email that link.
	 * Nothing is created yet. An address that already has an account gets a different email and the same answer.
	 *
	 * @return array{message:string}
	 * @throws AuthError
	 */
	public function request_registration( string $email, string $password, array $intent = array(), string $ip = '' ): array {
		$email = $this->valid_email( $email );
		$issue = self::password_problem( $password, $email );
		if ( '' !== $issue ) { throw new AuthError( $issue, 'password' ); }
		$this->throttle( 'reg', $email, $ip, 3, 12 );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$user = get_user_by( 'email', $email );
		if ( $user && self::is_reader( $user ) ) {
			$body = "Someone tried to create a $site account with this email address, but you already have one.\n\nSign in here:\n" . home_url( '/login/' ) . "\n\nIf you do not remember your password, choose “Forgot password?” on that page.\n\nIf this was not you, you can ignore this message. Your account has not changed.";
			$this->mail( 'account_exists', $email, sprintf( '[%s] You already have an account', $site ), $body );
		} elseif ( ! $user ) {
			$token = $this->tokens->issue( 'register', null, $email, array( 'hash' => wp_hash_password( $password ) ) + $this->sanitize_intent( $intent ), self::REGISTER_TTL );
			$body  = "Confirm your email address to finish creating your $site account. The link works for 24 hours.\n\n" . $this->tokens->action_url( 'register', $token ) . "\n\nIf you did not ask for an account, you can ignore this message and nothing will be created.";
			$this->mail( 'register', $email, sprintf( '[%s] Confirm your email to create your account', $site ), $body );
		}
		return array( 'message' => 'Check your inbox. We sent a link to ' . $email . ' to finish creating your account.' );
	}

	/**
	 * Step two: the link from the email, confirmed with a button. Creates the reader and signs them in.
	 *
	 * @return array{user_id:int,return_to:string}
	 */
	public function confirm_registration( string $token ): array {
		$row = $this->tokens->consume( 'register', $token );
		if ( ! $row || empty( $row['email'] ) ) { throw new \RuntimeException( 'This link is invalid, expired, or already used.' ); }
		$this->tokens->forget_payload( (int) $row['id'] );
		if ( get_user_by( 'email', (string) $row['email'] ) ) { throw new \RuntimeException( 'An account with this email already exists. Sign in instead.' ); }
		$user = $this->create_reader( (string) $row['email'], (string) ( $row['payload']['hash'] ?? '' ) );
		return $this->complete( $user, $row['payload'] );
	}

	/**
	 * @return array{user_id:int,return_to:string}
	 * @throws AuthError
	 */
	public function sign_in( string $email, string $password, array $intent = array(), string $ip = '' ): array {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) { throw new AuthError( 'Enter the email address you signed up with.', 'email' ); }
		if ( '' === $password ) { throw new AuthError( 'Enter your password.', 'password' ); }
		if ( $this->count( 'login_e', $email ) >= 5 || $this->count( 'login_i', $ip ) >= 30 ) { throw new AuthError( 'Too many attempts. Try again in 15 minutes, or reset your password.', '', 429 ); }
		$user = wp_authenticate( $email, $password );
		if ( is_wp_error( $user ) || ! $user instanceof \WP_User ) {
			$this->hit( 'login_e', $email );
			$this->hit( 'login_i', $ip );
			throw new AuthError( 'That email and password don’t match.', '', 401 );
		}
		if ( ! self::is_reader( $user ) ) { throw new AuthError( 'Staff accounts sign in through the WordPress login.', '', 403 ); }
		delete_transient( self::key( 'login_e', $email ) );
		return $this->complete( $user, $this->sanitize_intent( $intent ) );
	}

	/**
	 * Email a link to set a new password. Answers the same way whether or not the address has an account.
	 *
	 * @return array{message:string}
	 * @throws AuthError
	 */
	public function request_reset( string $email, string $ip = '' ): array {
		$email = $this->valid_email( $email );
		$this->throttle( 'reset', $email, $ip, 3, 12 );
		$user = get_user_by( 'email', $email );
		if ( $user && self::is_reader( $user ) ) { $this->send_reset_link( $user ); }
		return array( 'message' => 'If an account exists for ' . $email . ', a link to set a new password is on its way.' );
	}

	/**
	 * The same link for a signed-in reader, who may have signed up with Google and never had a password.
	 *
	 * @return array{message:string}
	 * @throws AuthError
	 */
	public function request_reset_for( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::is_reader( $user ) ) { throw new AuthError( 'Staff accounts change their password in WordPress.', '', 403 ); }
		if ( $this->count( 'reset_u', (string) $user_id ) >= 3 ) { throw new AuthError( 'You asked for several links already. Try again in 15 minutes.', '', 429 ); }
		$this->hit( 'reset_u', (string) $user_id );
		$this->send_reset_link( $user );
		return array( 'message' => 'We sent a link to ' . $user->user_email . '. It works for one hour.' );
	}

	/**
	 * @return array{user_id:int,return_to:string}
	 * @throws AuthError When the new password is not acceptable; the link stays usable.
	 */
	public function reset_password( string $token, string $password ): array {
		$row  = $this->tokens->inspect( 'password_reset', $token );
		$user = $row ? get_userdata( (int) $row['user_id'] ) : false;
		if ( ! $user || ! self::is_reader( $user ) ) { throw new \RuntimeException( 'This link is invalid, expired, or already used.' ); }
		$issue = self::password_problem( $password, (string) $user->user_email );
		if ( '' !== $issue ) { throw new AuthError( $issue, 'password' ); }
		if ( ! $this->tokens->consume( 'password_reset', $token ) ) { throw new \RuntimeException( 'This link is invalid, expired, or already used.' ); }
		wp_set_password( $password, (int) $user->ID );
		// Whoever held a session before the reset is signed out; the reader who has the mailbox continues below.
		\WP_Session_Tokens::get_instance( (int) $user->ID )->destroy_all();
		delete_transient( self::key( 'login_e', (string) $user->user_email ) );
		return $this->complete( get_userdata( (int) $user->ID ), array() );
	}

	public function sanitize_intent( array $intent ): array {
		return array( 'fighter_id' => absint( $intent['fighter_id'] ?? 0 ), 'return_to' => $this->safe_return( (string) ( $intent['return_to'] ?? '' ) ) );
	}

	public function safe_return( string $url ): string {
		$fallback = home_url( '/account/' );
		$url = wp_validate_redirect( $url, $fallback );
		return 0 === strpos( $url, home_url() ) ? $url : $fallback;
	}

	public static function is_reader( \WP_User $user ): bool {
		return in_array( 'subscriber', (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' );
	}

	private function send_reset_link( \WP_User $user ): void {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$token = $this->tokens->issue( 'password_reset', (int) $user->ID, (string) $user->user_email, array(), self::RESET_TTL );
		$body  = "Use this link to set a new password for your $site account. It works for one hour and can be used once.\n\n" . $this->tokens->action_url( 'password_reset', $token ) . "\n\nIf you did not ask for this, you can ignore this message. Your password has not changed.";
		$this->mail( 'password_reset', (string) $user->user_email, sprintf( '[%s] Set a new password', $site ), $body );
	}

	/** The sign-in name is derived from the address, as it always has been; it is never shown publicly. */
	private function create_reader( string $email, string $password_hash ): \WP_User {
		global $wpdb;
		$base  = sanitize_user( strtok( $email, '@' ), true ) ?: 'subscriber';
		$login = $base;
		$suffix = 1;
		while ( username_exists( $login ) ) { $login = $base . $suffix++; }
		$user_id = wp_create_user( $login, wp_generate_password( 32, true, true ), $email );
		if ( is_wp_error( $user_id ) ) { throw new \RuntimeException( $user_id->get_error_message() ); }
		// The password was hashed when the reader chose it, so the hash is stored as it is rather than hashed again.
		if ( '' !== $password_hash && '$' === $password_hash[0] && strlen( $password_hash ) <= 255 ) {
			$wpdb->update( $wpdb->users, array( 'user_pass' => $password_hash ), array( 'ID' => (int) $user_id ) );
			clean_user_cache( (int) $user_id );
		}
		$user = get_userdata( (int) $user_id );
		$user->set_role( 'subscriber' );
		return $user;
	}

	/** The reader has proved the mailbox or the password: mark the address confirmed, sign in, carry out the follow they came for. */
	private function complete( \WP_User $user, array $payload ): array {
		update_user_meta( (int) $user->ID, '_mmaf_email_verified', '1' );
		if ( '' === get_user_meta( (int) $user->ID, '_mmaf_notification_timezone', true ) ) { update_user_meta( (int) $user->ID, '_mmaf_notification_timezone', 'Europe/Belgrade' ); }
		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		if ( ! empty( $payload['fighter_id'] ) ) { try { ( new Subscriptions() )->follow( (int) $user->ID, (int) $payload['fighter_id'] ); } catch ( \Throwable $e ) { /* The sign-in itself succeeded. */ } }
		return array( 'user_id' => (int) $user->ID, 'return_to' => $this->safe_return( (string) ( $payload['return_to'] ?? '' ) ) );
	}

	private function mail( string $kind, string $to, string $subject, string $body ): void {
		ServiceMailer::send( $to, $subject, nl2br( esc_html( $body ) ), $body, $kind );
	}

	private function valid_email( string $email ): string {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) { throw new AuthError( 'Enter a valid email address.', 'email' ); }
		return $email;
	}

	/** Requests that send email are limited per address and per network, so the form cannot be used to flood a mailbox. */
	private function throttle( string $bucket, string $email, string $ip, int $per_email, int $per_ip ): void {
		if ( $this->count( $bucket . '_e', $email ) >= $per_email || $this->count( $bucket . '_i', $ip ) >= $per_ip ) { throw new AuthError( 'Too many requests. Try again in 15 minutes.', '', 429 ); }
		$this->hit( $bucket . '_e', $email );
		$this->hit( $bucket . '_i', $ip );
	}

	private static function key( string $bucket, string $subject ): string {
		return 'mmaf_auth_' . $bucket . '_' . substr( hash( 'sha256', strtolower( $subject ) ), 0, 24 );
	}
	private function count( string $bucket, string $subject ): int { return (int) get_transient( self::key( $bucket, $subject ) ); }
	private function hit( string $bucket, string $subject ): void { set_transient( self::key( $bucket, $subject ), $this->count( $bucket, $subject ) + 1, self::WINDOW ); }
}
