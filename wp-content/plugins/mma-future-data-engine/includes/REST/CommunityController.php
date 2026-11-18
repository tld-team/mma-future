<?php
namespace MMAF\DataEngine\REST;

use MMAF\DataEngine\Services\Community\Avatars;
use MMAF\DataEngine\Services\Community\Badge;
use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\Conflict;
use MMAF\DataEngine\Services\Community\FanLists;
use MMAF\DataEngine\Services\Community\FanRanking;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\RateLimit;
use MMAF\DataEngine\Services\Community\Ratings;
use MMAF\DataEngine\Services\Community\Reputation;
use MMAF\DataEngine\Services\Community\ValidationFailed;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The signed-in reader's own profile and Top 10, plus the public fan scores. No route takes a user id. */
final class CommunityController extends \WP_REST_Controller {
	public function __construct() { $this->namespace = RestServiceProvider::NAMESPACE; $this->rest_base = 'community'; }

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/profile', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'profile' ), 'permission_callback' => array( $this, 'profile_permission' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_profile' ), 'permission_callback' => array( $this, 'profile_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/profile/handle', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'check_handle' ), 'permission_callback' => array( $this, 'profile_permission' ) ) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/profile/avatar', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'upload_avatar' ), 'permission_callback' => array( $this, 'profile_permission' ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'remove_avatar' ), 'permission_callback' => array( $this, 'profile_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/top-10', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'top_10' ), 'permission_callback' => array( $this, 'fan_permission' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_top_10' ), 'permission_callback' => array( $this, 'fan_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/reputation', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'reputation' ), 'permission_callback' => static fn(): bool => is_user_logged_in() && Config::area_enabled( 'reputation' ) ) );
		// Props need both areas: they are given to a Top 10.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/props', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'props' ), 'permission_callback' => array( $this, 'props_permission' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'give_props' ), 'permission_callback' => array( $this, 'props_write_permission' ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'withdraw_props' ), 'permission_callback' => array( $this, 'props_write_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/fan-scores', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'fan_scores' ), 'permission_callback' => static fn(): bool => Config::area_enabled( 'fan' ) ) );
	}

	public function profile_permission(): bool { return is_user_logged_in() && Config::area_enabled( 'profiles' ); }

	public function profile(): \WP_REST_Response { return $this->response( ( new Profiles() )->editable( get_current_user_id() ) ); }

	public function save_profile( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! RateLimit::allow( 'profile', $user_id, 20, 10 * MINUTE_IN_SECONDS ) ) { return $this->slow_down(); }
		$input = array();
		foreach ( array( 'handle', 'display_name', 'bio', 'socials' ) as $field ) { if ( $request->has_param( $field ) ) { $input[ $field ] = $request->get_param( $field ); } }
		try { ( new Profiles() )->save( $user_id, $input ); }
		catch ( ValidationFailed $e ) { return $this->invalid( $e ); }
		catch ( \Throwable $e ) { return new \WP_Error( 'mmafc_profile_failed', 'Your profile couldn’t be saved. Try again.', array( 'status' => 500 ) ); }
		return $this->profile();
	}

	public function check_handle( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! RateLimit::allow( 'handle', $user_id, 40, MINUTE_IN_SECONDS ) ) { return $this->slow_down(); }
		$problem = ( new Profiles() )->handle_problem( (string) $request->get_param( 'value' ), $user_id );
		return $this->response( array( 'available' => '' === $problem, 'message' => $problem ) );
	}

	public function upload_avatar( \WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$profiles = new Profiles();
		if ( ! RateLimit::allow( 'avatar', $user_id, 10, HOUR_IN_SECONDS ) ) { return $this->slow_down(); }
		$file = $request->get_file_params()['avatar'] ?? array();
		try {
			if ( ! $profiles->find_by_user( $user_id ) ) { throw new ValidationFailed( array( 'handle' => 'Choose a handle and save your profile before adding a photo.' ) ); }
			$stored   = ( new Avatars() )->store_upload( is_array( $file ) ? $file : array() );
			$previous = $profiles->set_avatar( $user_id, $stored );
			Avatars::delete( $previous );
		}
		catch ( ValidationFailed $e ) { return $this->invalid( $e ); }
		catch ( \Throwable $e ) { return new \WP_Error( 'mmafc_avatar_failed', 'The photo couldn’t be stored. Try again.', array( 'status' => 500 ) ); }
		return $this->profile();
	}

	public function remove_avatar() {
		$user_id  = get_current_user_id();
		$profiles = new Profiles();
		if ( $profiles->find_by_user( $user_id ) ) { Avatars::delete( $profiles->set_avatar( $user_id, '' ) ); }
		return $this->profile();
	}

	public function fan_permission(): bool { return is_user_logged_in() && Config::area_enabled( 'fan' ); }

	public function top_10(): \WP_REST_Response { return $this->response( ( new FanLists() )->editable( get_current_user_id() ) ); }

	public function save_top_10( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! RateLimit::allow( 'top10', $user_id, 30, 10 * MINUTE_IN_SECONDS ) ) { return $this->slow_down(); }
		try { ( new FanLists() )->save( $user_id, $request->get_param( 'fighter_ids' ), absint( $request->get_param( 'revision' ) ) ); }
		catch ( ValidationFailed $e ) { return $this->invalid( $e ); }
		catch ( Conflict $e ) { return new \WP_Error( 'mmafc_conflict', 'Your Top 10 was changed in another tab or on another device. Reload to see the latest version.', array( 'status' => 409 ) ); }
		catch ( \Throwable $e ) { return new \WP_Error( 'mmafc_top10_failed', 'Your Top 10 couldn’t be saved. Try again.', array( 'status' => 500 ) ); }
		return $this->top_10();
	}

	/** The reader's own level and where their XP came from. Grants any award they earned before levels existed. */
	public function reputation(): \WP_REST_Response {
		$user_id = get_current_user_id();
		Reputation::sync( $user_id );
		$data = Reputation::breakdown( $user_id );
		return $this->response( $data + array( 'badge' => Badge::svg( $data['level'], 'lg' ) ) );
	}

	public function props_permission(): bool { return Config::area_enabled( 'reputation' ) && Config::area_enabled( 'fan' ); }
	public function props_write_permission(): bool { return is_user_logged_in() && $this->props_permission(); }

	/** Totals for anyone; the viewer's own rating and whether they may rate when signed in. */
	public function props( \WP_REST_Request $request ) {
		$owner = ( new Profiles() )->find_by_handle( (string) $request->get_param( 'handle' ) );
		if ( ! $owner || 'hidden' === (string) $owner['status'] ) { return new \WP_Error( 'mmafc_not_found', 'That profile doesn’t exist.', array( 'status' => 404 ) ); }
		return $this->response( ( new Ratings() )->state( get_current_user_id(), $owner ) );
	}

	public function give_props( \WP_REST_Request $request ) {
		return $this->rate( (string) $request->get_param( 'handle' ), absint( $request->get_param( 'value' ) ) ?: -1 );
	}

	public function withdraw_props( \WP_REST_Request $request ) {
		return $this->rate( (string) $request->get_param( 'handle' ), 0 );
	}

	private function rate( string $handle, int $value ) {
		$user_id = get_current_user_id();
		if ( ! RateLimit::allow( 'props', $user_id, 30, HOUR_IN_SECONDS ) ) { return $this->slow_down(); }
		try { return $this->response( ( new Ratings() )->rate( $user_id, $handle, $value ) ); }
		catch ( ValidationFailed $e ) { return $this->invalid( $e ); }
		catch ( \Throwable $e ) { return new \WP_Error( 'mmafc_props_failed', 'Your props couldn’t be saved. Try again.', array( 'status' => 500 ) ); }
	}

	/** Public and the same for everyone, so it may be cached briefly; fighter pages call it once per view. */
	public function fan_scores( \WP_REST_Request $request ): \WP_REST_Response {
		$ids      = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'fighter_ids' ) ) ) ) ) ), 0, 100 );
		$ranking  = new FanRanking();
		$response = new \WP_REST_Response( array( 'lists' => $ranking->standings()['lists'], 'scores' => (object) $ranking->scores( $ids ) ), 200 );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		return $response;
	}

	private function invalid( ValidationFailed $e ): \WP_Error {
		return new \WP_Error( 'mmafc_invalid', $e->getMessage(), array( 'status' => 400, 'fields' => $e->fields() ) );
	}

	private function slow_down(): \WP_Error {
		return new \WP_Error( 'mmafc_rate_limited', 'That’s a lot of changes in a short time. Try again in a few minutes.', array( 'status' => 429 ) );
	}

	private function response( $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}
}
