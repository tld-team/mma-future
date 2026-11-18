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
		add_action( 'template_redirect', array( FanBoard::class, 'no_cache' ), 0 );
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
		add_filter( 'mmaf_account_registered_return_to', array( self::class, 'registered_return_to' ), 10, 2 );
		add_action( 'mmafc_profile_changed', array( Reputation::class, 'sync' ) );
		add_action( 'mmafc_top10_saved', array( Reputation::class, 'sync' ) );
		add_action( 'mmafc_profile_status_changed', array( Reputation::class, 'rater_changed' ) );
		add_filter( 'pre_get_avatar_data', array( Avatars::class, 'avatar_data' ), 10, 2 );
		add_action( 'deleted_user', array( Privacy::class, 'forget_deleted_user' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( Privacy::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Privacy::class, 'erasers' ) );
	}

	public static function assets(): void {
		if ( is_admin() || ! Config::feature_enabled() ) { return; }
		$assets  = dirname( MMAF_PLUGIN_FILE ) . '/assets/';
		$version = static fn( string $file ): string => MMAF_PLUGIN_VERSION . '.' . filemtime( $assets . $file );
		wp_register_style( 'mmaf-community', plugins_url( 'assets/community.css', MMAF_PLUGIN_FILE ), array(), $version( 'community.css' ) );
		wp_register_script( 'mmaf-community', plugins_url( 'assets/community.js', MMAF_PLUGIN_FILE ), array(), $version( 'community.js' ), true );
		// No nonce here: the script asks for one at runtime, so nothing per-reader is printed into HTML a cache might keep.
		wp_localize_script( 'mmaf-community', 'mmafCommunity', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'restUrl' => rest_url( 'mma-future/v1/community/' ), 'rankingsUrl' => rest_url( 'mma-future/v1/rankings' ), 'maxAvatarBytes' => Config::avatar_max_bytes() ) );
		$view = Routes::view();
		$fan  = Config::area_enabled( 'fan' );
		if ( $fan && is_singular( 'mmaf_fighter' ) ) {
			// Only the small script: the fan tag on a fighter page reuses the hero's own tag styles.
			wp_enqueue_script( 'mmaf-community-fan', plugins_url( 'assets/community-fan.js', MMAF_PLUGIN_FILE ), array(), $version( 'community-fan.js' ), true );
			wp_localize_script( 'mmaf-community-fan', 'mmafCommunityFan', array( 'scoresUrl' => rest_url( 'mma-future/v1/community/fan-scores' ) ) );
		}
		$rankings_page = $fan && ( FanBoard::requested() || ( is_singular() && has_block( 'acf/all-rankings-table-section' ) ) );
		if ( '' === $view && ! $rankings_page && ! ( Config::area_enabled( 'profiles' ) && 'account' === (string) get_query_var( 'mmaf_notification_page' ) ) ) { return; }
		wp_enqueue_style( 'mmaf-community' );
		if ( '' === $view ) { return; }
		wp_enqueue_script( 'mmaf-community' );
		if ( 'account-top-10' === $view ) { wp_enqueue_script( 'mmaf-community-editor', plugins_url( 'assets/community-editor.js', MMAF_PLUGIN_FILE ), array( 'mmaf-community' ), $version( 'community-editor.js' ), true ); }
	}

	public static function session(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0' );
		$user_id = get_current_user_id();
		$profile = $user_id && Config::area_enabled( 'profiles' ) ? ( new Profiles() )->find_by_user( $user_id ) : null;
		// `key` names this reader's browser storage (an unsaved Top 10 draft) without revealing the account id.
		wp_send_json_success( array( 'loggedIn' => $user_id > 0, 'nonce' => $user_id ? wp_create_nonce( 'wp_rest' ) : null, 'handle' => $profile ? (string) $profile['handle'] : null, 'key' => $user_id ? substr( wp_hash( 'mmafc-reader-' . $user_id ), 0, 16 ) : null ) );
	}

	/**
	 * The tabs shared by every account page. Prints nothing while profiles are off, so the existing account page is unchanged.
	 *
	 * @param string $current The tab being viewed.
	 */
	public static function account_nav( $current = '' ): void {
		if ( ! Config::area_enabled( 'profiles' ) ) { return; }
		$tabs = array( 'profile' => array( 'Profile', home_url( '/account/profile/' ) ) );
		if ( Config::area_enabled( 'fan' ) ) { $tabs['top-10'] = array( 'My Top 10', FanBoard::editor_url() ); }
		$tabs['notifications'] = array( 'Notifications', home_url( '/account/' ) );
		echo '<nav class="mmafc-tabs" aria-label="Account">';
		foreach ( $tabs as $key => $tab ) {
			$active = $key === $current;
			echo '<a class="mmafc-tabs__link' . ( $active ? ' is-current' : '' ) . '" href="' . esc_url( $tab[1] ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab[0] ) . '</a>';
		}
		echo '</nav>';
		// The notifications page is where every sign-in lands by default, including Google's, so it carries the invitation.
		if ( 'notifications' === $current && ! ( new Profiles() )->find_by_user( get_current_user_id() ) ) {
			echo '<div class="mmafc-callout"><p class="mmafc-callout__text"><strong>Set up your profile.</strong> Pick a handle and a photo' . ( Config::area_enabled( 'fan' ) ? ', then rank your Top 10 prospects for the fan ranking' : ' for your public fan page' ) . '.</p><a class="mmafc-callout__button" href="' . esc_url( home_url( '/account/profile/' ) ) . '">Set up profile</a></div>';
		}
	}

	/**
	 * A reader who registered without a destination of their own would land on the notification settings, which say
	 * nothing about the profile. Send them to set one up instead.
	 *
	 * @param mixed $return_to Where the reader is about to be sent.
	 * @param mixed $user_id   The new reader.
	 */
	public static function registered_return_to( $return_to, $user_id ): string {
		$return_to = (string) $return_to;
		if ( ! Config::area_enabled( 'profiles' ) || untrailingslashit( $return_to ) !== untrailingslashit( home_url( '/account/' ) ) ) { return $return_to; }
		return ( new Profiles() )->find_by_user( (int) $user_id ) ? $return_to : home_url( '/account/profile/' );
	}
}
