<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Module {
	public static function register(): void {
		add_action( 'init', array( self::class, 'rewrites' ) );
		add_filter( 'query_vars', static function( array $vars ): array { $vars[] = 'mmaf_notification_page'; return $vars; } );
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_action( 'template_redirect', array( self::class, 'no_cache' ), 0 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'wp_ajax_mmaf_notification_session', array( self::class, 'session' ) );
		add_action( 'wp_ajax_nopriv_mmaf_notification_session', array( self::class, 'session' ) );
		add_action( 'admin_post_nopriv_mmaf_magic_confirm', array( self::class, 'confirm_magic' ) );
		add_action( 'admin_post_mmaf_magic_confirm', array( self::class, 'confirm_magic' ) );
		add_action( 'admin_post_nopriv_mmaf_token_action', array( self::class, 'confirm_token_action' ) );
		add_action( 'admin_post_mmaf_token_action', array( self::class, 'confirm_token_action' ) );
		add_action( 'wp_login', array( self::class, 'continue_intent' ), 20, 2 );
		add_action( 'mmaf_notifications_run', static function(): void { try { ( new Worker() )->run( 25 ); } catch ( \Throwable $e ) { error_log( 'MMAF notifications worker: ' . $e->getMessage() ); } } );
		add_filter( 'nsl_register_roles', static fn(): array => array( 'subscriber' ) );
		add_filter( 'nsl_google_auto_link_allowed', '__return_false' );
		add_action( 'nsl_google_register_new_user', static function( int $user_id ): void { $user = get_userdata( $user_id ); if ( $user ) { $user->set_role( 'subscriber' ); update_user_meta( $user_id, '_mmaf_email_verified', '1' ); } } );
		add_filter( 'nsl_google_is_login_allowed', static function( bool $allowed, $provider, int $user_id ): bool { $user = get_userdata( $user_id ); return $allowed && $user && in_array( 'subscriber', (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' ); }, 10, 3 );
		add_action( 'nsl_google_login', static function( int $user_id ): void { $user = get_userdata( $user_id ); if ( $user && in_array( 'subscriber', (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' ) ) { update_user_meta( $user_id, '_mmaf_email_verified', '1' ); } }, 10, 1 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) { \WP_CLI::add_command( 'mmaf notifications work', static function( array $args, array $assoc ): void { \WP_CLI::log( wp_json_encode( ( new Worker() )->run( absint( $assoc['limit'] ?? 25 ) ) ) ); } ); }
	}

	public static function rewrites(): void {
		foreach ( array( 'login','register','account','notification-action' ) as $page ) { add_rewrite_rule( '^' . preg_quote( $page, '/' ) . '/?$', 'index.php?mmaf_notification_page=' . $page, 'top' ); }
		if ( get_option( 'mmaf_notifications_rewrite_version' ) !== MMAF_DB_VERSION ) { flush_rewrite_rules( false ); update_option( 'mmaf_notifications_rewrite_version', MMAF_DB_VERSION, false ); }
	}

	public static function template( string $template ): string {
		return get_query_var( 'mmaf_notification_page' ) ? MMAF_PLUGIN_DIR . 'templates/notifications-page.php' : $template;
	}

	public static function no_cache(): void {
		if ( ! get_query_var( 'mmaf_notification_page' ) ) { return; }
		nocache_headers(); header( 'Cache-Control: no-store, private, max-age=0' );
		if ( 'notification-action' === get_query_var( 'mmaf_notification_page' ) && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'One-Click' === ( $_POST['List-Unsubscribe'] ?? '' ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); $row = ( new Tokens() )->consume( 'unsubscribe', $token );
			if ( $row ) { self::unsubscribe_user( (int) $row['user_id'] ); status_header( 200 ); echo 'Unsubscribed'; } else { status_header( 400 ); echo 'Invalid or expired token'; }
			exit;
		}
		if ( 'account' === get_query_var( 'mmaf_notification_page' ) && ! is_user_logged_in() ) { wp_safe_redirect( self::login_url( array( 'return_to' => home_url( '/account/' ) ) ) ); exit; }
		if ( in_array( get_query_var( 'mmaf_notification_page' ), array( 'login','register' ), true ) ) { self::remember_intent_from_request(); }
	}

	public static function assets(): void {
		if ( is_admin() || ( ! Config::feature_enabled() && ! get_query_var( 'mmaf_notification_page' ) ) ) { return; }
		wp_enqueue_style( 'mmaf-notifications', plugins_url( 'assets/notifications.css', MMAF_PLUGIN_FILE ), array(), MMAF_PLUGIN_VERSION );
		wp_enqueue_script( 'mmaf-notifications', plugins_url( 'assets/notifications.js', MMAF_PLUGIN_FILE ), array(), MMAF_PLUGIN_VERSION, true );
		wp_localize_script( 'mmaf-notifications', 'mmafNotifications', array( 'ajaxUrl'=>admin_url( 'admin-ajax.php' ), 'restUrl'=>rest_url( 'mma-future/v1/notifications/' ), 'loginUrl'=>home_url( '/login/' ), 'accountUrl'=>home_url( '/account/' ), 'featureEnabled'=>Config::feature_enabled() ) );
	}

	public static function session(): void {
		nocache_headers();
		wp_send_json_success( array( 'loggedIn'=>is_user_logged_in(), 'nonce'=>is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : null, 'userId'=>get_current_user_id() ) );
	}

	public static function button( int $fighter_id, string $label = 'Follow fighter' ): string {
		if ( $fighter_id < 1 || ! Config::feature_enabled() ) { return ''; }
		return '<button type="button" class="mmaf-follow" data-mmaf-follow="' . esc_attr( (string) $fighter_id ) . '" aria-pressed="false"><span class="mmaf-follow__bell" aria-hidden="true">&#128276;</span><span data-mmaf-follow-label>' . esc_html( $label ) . '</span></button>';
	}

	public static function login_url( array $intent = array() ): string {
		$args = array_filter( array( 'fighter_id'=>absint( $intent['fighter_id'] ?? 0 ), 'return_to'=>( new Auth() )->safe_return( (string) ( $intent['return_to'] ?? home_url( '/account/' ) ) ) ) );
		return add_query_arg( $args, home_url( '/login/' ) );
	}

	public static function confirm_magic(): void {
		check_admin_referer( 'mmaf_magic_confirm' );
		try { $result = ( new Auth() )->confirm( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) ); wp_safe_redirect( $result['return_to'] ); }
		catch ( \Throwable $e ) { wp_safe_redirect( add_query_arg( 'mmaf_error', rawurlencode( $e->getMessage() ), home_url( '/login/' ) ) ); }
		exit;
	}

	public static function confirm_token_action(): void {
		check_admin_referer( 'mmaf_token_action' );
		$purpose = sanitize_key( $_POST['purpose'] ?? '' ); $raw = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$row = ( new Tokens() )->consume( $purpose, $raw );
		if ( ! $row ) { wp_safe_redirect( add_query_arg( 'mmaf_error', 'This secure action link is invalid or expired.', home_url( '/notification-action/' ) ) ); exit; }
		try {
			if ( 'reminder' === $purpose ) { ( new Reminders() )->enable( (int) $row['user_id'], (int) $row['payload']['bout_id'] ); $message = 'Reminder enabled.'; }
			elseif ( 'unsubscribe' === $purpose ) { self::unsubscribe_user( (int) $row['user_id'] ); $message = 'You have been unsubscribed from sports notifications.'; }
			else { throw new \RuntimeException( 'Unknown secure action.' ); }
			wp_safe_redirect( add_query_arg( 'mmaf_notice', rawurlencode( $message ), home_url( '/notification-action/' ) ) );
		} catch ( \Throwable $e ) { wp_safe_redirect( add_query_arg( 'mmaf_error', rawurlencode( $e->getMessage() ), home_url( '/notification-action/' ) ) ); }
		exit;
	}

	public static function continue_intent( string $login, \WP_User $user ): void {
		$intent = self::read_intent_cookie(); if ( ! $intent || ! in_array( 'subscriber', (array) $user->roles, true ) || user_can( $user, 'edit_posts' ) ) { return; }
		if ( ! empty( $intent['fighter_id'] ) ) { try { ( new Subscriptions() )->follow( (int) $user->ID, (int) $intent['fighter_id'] ); } catch ( \Throwable $e ) {} }
		setcookie( 'mmaf_follow_intent', '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	private static function remember_intent_from_request(): void {
		$fighter_id = absint( $_GET['fighter_id'] ?? 0 ); if ( ! $fighter_id ) { return; }
		$payload = array( 'fighter_id'=>$fighter_id, 'return_to'=>( new Auth() )->safe_return( (string) ( $_GET['return_to'] ?? '' ) ), 'expires'=>time()+HOUR_IN_SECONDS );
		$json = wp_json_encode( $payload ); $value = base64_encode( $json ) . '.' . hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
		setcookie( 'mmaf_follow_intent', $value, time()+HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	}
	public static function read_intent_cookie(): array {
		$value = (string) ( $_COOKIE['mmaf_follow_intent'] ?? '' ); $parts = explode( '.', $value, 2 ); if ( 2 !== count( $parts ) ) { return array(); }
		$json = base64_decode( $parts[0], true ); if ( ! $json || ! hash_equals( hash_hmac( 'sha256', $json, wp_salt( 'auth' ) ), $parts[1] ) ) { return array(); }
		$data = json_decode( $json, true ) ?: array(); return (int) ( $data['expires'] ?? 0 ) >= time() ? $data : array();
	}

	public static function cancel_queued( int $user_id ): void { global $wpdb; $t=Schema::tables(); $wpdb->query( $wpdb->prepare( "UPDATE {$t['items']} SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND status IN ('pending','reserved')", $user_id ) ); }
	public static function unsubscribe_user( int $user_id ): void { global $wpdb; $t=Schema::tables(); update_user_meta( $user_id, '_mmaf_notifications_unsubscribed_at', gmdate( 'c' ) ); update_user_meta( $user_id, '_mmaf_notifications_enabled', '0' ); self::cancel_queued( $user_id ); $wpdb->query( $wpdb->prepare( "UPDATE {$t['reminders']} SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND status IN ('active','waiting_date')", $user_id ) ); }
	public static function exporters( array $exporters ): array { $exporters['mmaf-notifications']=array( 'exporter_friendly_name'=>'MMA Future notifications','callback'=>array( Privacy::class,'export' ) ); return $exporters; }
	public static function erasers( array $erasers ): array { $erasers['mmaf-notifications']=array( 'eraser_friendly_name'=>'MMA Future notifications','callback'=>array( Privacy::class,'erase' ) ); return $erasers; }
}
