<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reader profiles, and the base the fan ranking, reputation and comments build on.
 * Everything is additive and off until MMAF_COMMUNITY_ENABLED is set and the schema has been migrated.
 */
final class Module {
	public static function register(): void {
		add_action( 'init', array( Routes::class, 'rewrites' ), 9 );
		add_action( 'init', array( Routes::class, 'maybe_flush' ), 11 );
		add_filter( 'query_vars', array( Routes::class, 'query_vars' ) );
		add_action( 'template_redirect', array( Routes::class, 'guard' ), 0 );
		add_filter( 'template_include', array( Routes::class, 'template' ), 99 );
		add_filter( 'document_title_parts', array( Routes::class, 'title' ) );
		// Yoast SEO answers before document_title_parts and describes these routes as the blog; they have no post of their own.
		foreach ( array( 'pre_get_document_title', 'wpseo_title', 'wpseo_opengraph_title' ) as $title_filter ) { add_filter( $title_filter, array( Routes::class, 'document_title' ), 99 ); }
		add_filter( 'wp_robots', array( Routes::class, 'robots' ) );
		add_filter( 'wpseo_robots', static fn( $robots ) => '' !== Routes::view() ? 'noindex, nofollow' : $robots, 99 );
		add_filter( 'wpseo_canonical', static fn( $canonical ) => '' !== Routes::view() ? false : $canonical, 99 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		// Read over GET on purpose: admin-ajax POSTs whose action starts with "mmaf_" pass through the canonical write guard.
		add_action( 'wp_ajax_mmafc_session', array( self::class, 'session' ) );
		add_action( 'wp_ajax_nopriv_mmafc_session', array( self::class, 'session' ) );
		add_action( 'mmaf_account_nav', array( self::class, 'account_nav' ) );
		add_filter( 'pre_get_avatar_data', array( Avatars::class, 'avatar_data' ), 10, 2 );
		add_action( 'deleted_user', array( Privacy::class, 'forget_deleted_user' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( Privacy::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Privacy::class, 'erasers' ) );
	}

	public static function assets(): void {
		if ( is_admin() || ! Config::area_enabled( 'profiles' ) ) { return; }
		$view       = Routes::view();
		$on_account = 'account' === (string) get_query_var( 'mmaf_notification_page' );
		if ( '' === $view && ! $on_account ) { return; }
		$assets = dirname( MMAF_PLUGIN_FILE ) . '/assets/';
		wp_enqueue_style( 'mmaf-community', plugins_url( 'assets/community.css', MMAF_PLUGIN_FILE ), array(), MMAF_PLUGIN_VERSION . '.' . filemtime( $assets . 'community.css' ) );
		if ( '' === $view ) { return; }
		wp_enqueue_script( 'mmaf-community', plugins_url( 'assets/community.js', MMAF_PLUGIN_FILE ), array(), MMAF_PLUGIN_VERSION . '.' . filemtime( $assets . 'community.js' ), true );
		// No nonce here: the script asks for one at runtime, so nothing per-reader is printed into HTML a cache might keep.
		wp_localize_script( 'mmaf-community', 'mmafCommunity', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'restUrl' => rest_url( 'mma-future/v1/community/' ), 'maxAvatarBytes' => Config::avatar_max_bytes() ) );
	}

	public static function session(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0' );
		$user_id = get_current_user_id();
		$profile = $user_id && Config::area_enabled( 'profiles' ) ? ( new Profiles() )->find_by_user( $user_id ) : null;
		wp_send_json_success( array( 'loggedIn' => $user_id > 0, 'nonce' => $user_id ? wp_create_nonce( 'wp_rest' ) : null, 'handle' => $profile ? (string) $profile['handle'] : null ) );
	}

	/**
	 * The tabs shared by every account page. Prints nothing while profiles are off, so the existing account page is unchanged.
	 *
	 * @param string $current The tab being viewed.
	 */
	public static function account_nav( $current = '' ): void {
		if ( ! Config::area_enabled( 'profiles' ) ) { return; }
		$tabs = array(
			'profile'       => array( 'Profile', home_url( '/account/profile/' ) ),
			'notifications' => array( 'Notifications', home_url( '/account/' ) ),
		);
		echo '<nav class="mmafc-tabs" aria-label="Account">';
		foreach ( $tabs as $key => $tab ) {
			$active = $key === $current;
			echo '<a class="mmafc-tabs__link' . ( $active ? ' is-current' : '' ) . '" href="' . esc_url( $tab[1] ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab[0] ) . '</a>';
		}
		echo '</nav>';
	}
}
