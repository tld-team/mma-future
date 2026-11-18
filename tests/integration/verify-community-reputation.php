<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\FanLists;
use MMAF\DataEngine\Services\Community\Levels;
use MMAF\DataEngine\Services\Community\Privacy;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Ratings;
use MMAF\DataEngine\Services\Community\Reputation;
use MMAF\DataEngine\Services\Community\Schema;
use MMAF\DataEngine\Services\Community\ValidationFailed;

// Props, XP and levels against the real tables. Every variable is prefixed: other checks include templates at file scope.
function assert_rep( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

/** @return string The props error, or '' when the rating was accepted. */
function rep_rate( int $rater, string $handle, int $value ): string {
	try { ( new Ratings() )->rate( $rater, $handle, $value ); return ''; } catch ( ValidationFailed $e ) { return (string) ( $e->fields()['props'] ?? $e->getMessage() ); }
}

function rep_points( int $user_id ): int {
	Profiles::forget_cached( $user_id );
	return (int) ( ( new Profiles() )->find_by_user( $user_id )['rep_points'] ?? -1 );
}

global $wpdb;
add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
add_filter( 'mmaf_community_feature_enabled', '__return_true' );
require_once ABSPATH . 'wp-admin/includes/user.php';

$mmafr_suffix = strtolower( wp_generate_password( 8, false, false ) );
$mmafr_tables = Schema::tables();
$mmafr_areas  = get_option( 'mmaf_community_areas', null );
$mmafr_users  = array();
$mmafr_lists  = array();

try {
	assert_rep( Config::reputation_schema_ready(), 'The migration did not reach schema ' . Config::REPUTATION_SCHEMA_VERSION . '.' );
	Config::save_areas( array( 'fan' ) );
	assert_rep( ! Config::area_enabled( 'reputation' ), 'Levels are on without being switched on.' );
	assert_rep( array( 'profiles', 'fan', 'reputation' ) === Config::save_areas( array( 'fan', 'reputation' ) ) && Config::area_enabled( 'reputation' ), 'The reputation area cannot be switched on.' );

	// owner, rater, newcomer and mole are readers; staff is an editor.
	foreach ( array( 'owner' => 'subscriber', 'rater' => 'subscriber', 'newcomer' => 'subscriber', 'mole' => 'subscriber', 'staff' => 'editor' ) as $mmafr_label => $mmafr_role ) {
		$mmafr_id = wp_create_user( 'rep_' . $mmafr_label . '_' . $mmafr_suffix, wp_generate_password( 24, true, true ), 'rep-' . $mmafr_label . '-' . $mmafr_suffix . '@example.test' );
		if ( is_wp_error( $mmafr_id ) ) { throw new RuntimeException( $mmafr_id->get_error_message() ); }
		get_userdata( $mmafr_id )->set_role( $mmafr_role );
		update_user_meta( $mmafr_id, '_mmaf_email_verified', '1' );
		$mmafr_users[ $mmafr_label ] = (int) $mmafr_id;
		( new Profiles() )->save( (int) $mmafr_id, array( 'handle' => 'rep' . substr( $mmafr_label, 0, 4 ) . '_' . $mmafr_suffix ) );
	}
	$u = $mmafr_users;
	$h = static fn( string $label ): string => 'rep' . substr( $label, 0, 4 ) . '_' . $mmafr_suffix;
	$profiles = new Profiles();

	/** A full Top 10 written straight to the tables: the list rules are verify-community-fan.php's business. */
	$mmafr_list = static function ( int $user_id ) use ( $wpdb, $mmafr_tables, &$mmafr_lists ): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $mmafr_tables['fan_lists'], array( 'user_id' => $user_id, 'revision' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		$list_id = (int) $wpdb->insert_id;
		$mmafr_lists[] = $list_id;
		for ( $position = 1; $position <= FanLists::SIZE; $position++ ) { $wpdb->insert( $mmafr_tables['fan_list_items'], array( 'list_id' => $list_id, 'position' => $position, 'fighter_id' => 880000000 + $position, 'picked_at' => $now ) ); }
	};
	$mmafr_complete = static function ( int $user_id, string $name ) use ( $profiles ): void {
		$profiles->save( $user_id, array( 'display_name' => $name, 'bio' => 'Calls prospects early.' ) );
		$profiles->set_avatar( $user_id, 'rep-test-' . $user_id . '.webp' );
	};

	// Awards: each is earned once, however often the triggers fire.
	Reputation::sync( $u['owner'] );
	assert_rep( 0 === rep_points( $u['owner'] ), 'A bare profile earned points.' );
	$profiles->save( $u['owner'], array( 'display_name' => $h( 'owner' ), 'bio' => 'Bio only.' ) );
	assert_rep( 0 === rep_points( $u['owner'] ), 'A profile without a photo, or with the handle as its name, counted as complete.' );
	$mmafr_complete( $u['owner'], 'Owner Fan' );
	assert_rep( Reputation::PROFILE_POINTS === rep_points( $u['owner'] ), 'Completing the profile did not earn its award through the save hook.' );
	Reputation::sync( $u['owner'] ); Reputation::sync( $u['owner'] );
	assert_rep( Reputation::PROFILE_POINTS === rep_points( $u['owner'] ), 'The profile award was granted twice.' );
	$mmafr_list( $u['owner'] );
	do_action( 'mmafc_top10_saved', $u['owner'] );
	assert_rep( 25 === rep_points( $u['owner'] ) && 2 === Reputation::summary( $u['owner'] )['level']['level'], 'A complete profile and a full Top 10 do not reach level 2.' );
	$mmafr_breakdown = Reputation::breakdown( $u['owner'] )['breakdown'];
	assert_rep( array( 'profile' => 10, 'top10' => 15, 'props' => 0, 'comments' => 0, 'tenure' => 0 ) === $mmafr_breakdown, 'The breakdown does not add up: ' . wp_json_encode( $mmafr_breakdown ) );

	foreach ( array( 'rater' => 'Rater Fan', 'mole' => 'Mole Fan' ) as $mmafr_label => $mmafr_name ) { $mmafr_complete( $u[ $mmafr_label ], $mmafr_name ); $mmafr_list( $u[ $mmafr_label ] ); Reputation::sync( $u[ $mmafr_label ] ); }
	$profiles->save( $u['newcomer'], array( 'display_name' => 'New Fan' ) );
	$mmafr_list( $u['staff'] );

	// Who may give props.
	assert_rep( str_contains( rep_rate( $u['newcomer'], $h( 'owner' ), 3 ), 'Reach level 2' ), 'A level 1 reader could give props.' );
	assert_rep( str_contains( rep_rate( $u['owner'], $h( 'owner' ), 3 ), 'your own' ), 'A reader could give props to their own Top 10.' );
	assert_rep( str_contains( rep_rate( $u['staff'], $h( 'owner' ), 3 ), 'Staff' ), 'A staff account could give props.' );
	assert_rep( str_contains( rep_rate( $u['rater'], $h( 'staff' ), 3 ), 'can’t receive' ), 'A staff list could receive props.' );
	assert_rep( str_contains( rep_rate( $u['rater'], $h( 'newcomer' ), 3 ), 'no Top 10' ), 'An empty list could receive props.' );
	assert_rep( '' !== rep_rate( $u['rater'], $h( 'owner' ), 5 ) && '' !== rep_rate( $u['rater'], 'nobody_' . $mmafr_suffix, 2 ), 'An unknown value or profile was accepted.' );
	assert_rep( 25 === rep_points( $u['owner'] ), 'A refused rating changed the owner’s points.' );

	// Giving, changing and withdrawing props.
	assert_rep( '' === rep_rate( $u['rater'], $h( 'owner' ), 3 ) && 28 === rep_points( $u['owner'] ), 'Elite props did not add 3 XP.' );
	assert_rep( '' === rep_rate( $u['rater'], $h( 'owner' ), 1 ) && 26 === rep_points( $u['owner'] ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mmafr_tables['ratings']} WHERE owner_user_id=%d", $u['owner'] ) ), 'Changing props added a second rating instead of replacing the first.' );
	assert_rep( '' === rep_rate( $u['rater'], $h( 'owner' ), 0 ) && 25 === rep_points( $u['owner'] ) && null === ( new Ratings() )->mine( $u['rater'], $u['owner'] ), 'Withdrawn props still count.' );
	rep_rate( $u['rater'], $h( 'owner' ), 2 );
	rep_rate( $u['mole'], $h( 'owner' ), 3 );
	$mmafr_state = ( new Ratings() )->state( $u['rater'], (array) $profiles->find_by_user( $u['owner'] ) );
	assert_rep( 30 === rep_points( $u['owner'] ) && array( 1 => 0, 2 => 1, 3 => 1, 'count' => 2 ) === $mmafr_state['totals'] && 2 === $mmafr_state['mine'] && $mmafr_state['can_rate'], 'Totals or the rater’s own rating are wrong: ' . wp_json_encode( $mmafr_state ) );

	// Moderation: a shadowed reader may keep rating, unaware, but their props stop counting; a hidden list takes none.
	$profiles->set_status( $u['mole'], 'shadow' );
	assert_rep( 27 === rep_points( $u['owner'] ) && 1 === ( new Ratings() )->totals( $u['owner'] )['count'], 'A shadowed reader’s props still count.' );
	assert_rep( '' === ( new Ratings() )->problem( $u['mole'], (array) $profiles->find_by_user( $u['owner'] ) ), 'A shadowed reader is told they cannot rate, which gives the moderation away.' );
	$profiles->set_status( $u['mole'], 'active' );
	assert_rep( 30 === rep_points( $u['owner'] ), 'Props did not count again after the reader was restored.' );
	$profiles->set_status( $u['owner'], 'hidden' );
	assert_rep( str_contains( rep_rate( $u['rater'], $h( 'owner' ), 3 ), 'can’t receive' ), 'A hidden profile could receive props.' );
	$profiles->set_status( $u['owner'], 'active' );

	// Tenure is added when read and is not stored.
	$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-7 months -2 days' ) ) ), array( 'ID' => $u['owner'] ) );
	clean_user_cache( $u['owner'] );
	$mmafr_summary = Reputation::summary( $u['owner'] );
	assert_rep( 20 === $mmafr_summary['tenure'] && 50 === $mmafr_summary['xp'] && 30 === rep_points( $u['owner'] ) && Levels::for_xp( 50 ) === $mmafr_summary['level'], 'Tenure is not added on read: ' . wp_json_encode( $mmafr_summary ) );

	// REST.
	$mmafr_request = static function ( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/mma-future/v1/community/' . $route );
		foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
		return rest_do_request( $request );
	};
	wp_set_current_user( 0 );
	$mmafr_out = $mmafr_request( 'GET', 'props', array( 'handle' => $h( 'owner' ) ) );
	assert_rep( 200 === $mmafr_out->get_status() && false === $mmafr_out->get_data()['can_rate'] && null === $mmafr_out->get_data()['mine'] && 'Sign in to give props.' === $mmafr_out->get_data()['reason'] && 2 === $mmafr_out->get_data()['totals']['count'], 'Signed-out visitors do not get the totals, or are offered the controls.' );
	assert_rep( in_array( $mmafr_request( 'POST', 'props', array( 'handle' => $h( 'owner' ), 'value' => 3 ) )->get_status(), array( 401, 403 ), true ), 'A signed-out visitor could give props.' );
	wp_set_current_user( $u['newcomer'] );
	$mmafr_refused = $mmafr_request( 'POST', 'props', array( 'handle' => $h( 'owner' ), 'value' => 3 ) );
	assert_rep( 400 === $mmafr_refused->get_status() && isset( $mmafr_refused->get_data()['data']['fields']['props'] ), 'A refused rating does not answer 400 with the reason.' );
	wp_set_current_user( $u['rater'] );
	$mmafr_given = $mmafr_request( 'POST', 'props', array( 'handle' => $h( 'owner' ), 'value' => 3 ) );
	assert_rep( 200 === $mmafr_given->get_status() && 3 === $mmafr_given->get_data()['mine'] && 31 === rep_points( $u['owner'] ), 'Props over REST were not stored.' );
	assert_rep( 200 === $mmafr_request( 'DELETE', 'props', array( 'handle' => $h( 'owner' ) ) )->get_status() && 28 === rep_points( $u['owner'] ), 'Props could not be withdrawn over REST.' );
	$mmafr_own = $mmafr_request( 'GET', 'reputation' );
	assert_rep( 200 === $mmafr_own->get_status() && 25 === $mmafr_own->get_data()['points'] && str_contains( (string) $mmafr_own->get_data()['badge'], 'mmafc-badge' ), 'The reader’s own level is not served.' );
	assert_rep( 404 === $mmafr_request( 'GET', 'props', array( 'handle' => 'nobody_' . $mmafr_suffix ) )->get_status(), 'An unknown profile does not answer 404.' );
	wp_set_current_user( 0 );

	// The public profile prints the level and the totals; nothing about the viewer.
	set_query_var( 'mmaf_community_page', 'profile' );
	set_query_var( 'mmaf_community_handle', $h( 'owner' ) );
	ob_start();
	include MMAF_PLUGIN_DIR . 'templates/community-profile.php';
	$mmafr_page = (string) ob_get_clean();
	set_query_var( 'mmaf_community_page', '' );
	set_query_var( 'mmaf_community_handle', '' );
	assert_rep( str_contains( $mmafr_page, 'aria-label="Level 2, Spotter"' ) && str_contains( $mmafr_page, 'data-mmafc-props="' . $h( 'owner' ) . '"' ) && str_contains( $mmafr_page, 'Solid 0 · Sharp 0 · Elite 1' ) && ! str_contains( $mmafr_page, 'aria-pressed="true"' ), 'The public profile does not show the level and the props totals, or shows a viewer’s own rating.' );

	// Erasure removes the props a reader gave, and the lists they rated lose them.
	Privacy::erase( get_userdata( $u['mole'] )->user_email );
	assert_rep( 25 === rep_points( $u['owner'] ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mmafr_tables['ratings']} WHERE rater_user_id=%d", $u['mole'] ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mmafr_tables['rep_events']} WHERE user_id=%d", $u['mole'] ) ), 'Erasing a reader left their props or awards behind.' );

	echo wp_json_encode( array( 'ok' => true ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	while ( ob_get_level() > 1 ) { ob_end_clean(); }
	wp_set_current_user( 0 );
	foreach ( $mmafr_users as $mmafr_id ) {
		( new Profiles() )->forget( $mmafr_id );
		wp_delete_user( $mmafr_id );
		foreach ( array( 'props', 'profile' ) as $mmafr_bucket ) { delete_transient( 'mmafc_rl_' . $mmafr_bucket . '_' . $mmafr_id ); }
	}
	foreach ( $mmafr_lists as $mmafr_list_id ) { $wpdb->delete( $mmafr_tables['fan_list_items'], array( 'list_id' => $mmafr_list_id ) ); $wpdb->delete( $mmafr_tables['fan_lists'], array( 'id' => $mmafr_list_id ) ); }
	if ( null === $mmafr_areas ) { delete_option( 'mmaf_community_areas' ); } else { update_option( 'mmaf_community_areas', $mmafr_areas, false ); }
	remove_filter( 'mmaf_community_feature_enabled', '__return_true' );
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
}
