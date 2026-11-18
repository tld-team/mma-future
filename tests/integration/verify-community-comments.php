<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Services\Community\Comments;
use MMAF\DataEngine\Services\Community\CommentVotes;
use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\FanLists;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Schema;
use MMAF\DataEngine\Services\Community\ValidationFailed;

// Comments, replies, votes, reports and their XP against a real article. Every variable is prefixed (see other checks).
function assert_cm( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

/** @return string The field error the call raised, or '' when it went through. */
function cm_error( callable $call ): string {
	try { $call(); return ''; } catch ( ValidationFailed $e ) { $fields = $e->fields(); return (string) reset( $fields ); }
}

function cm_points( int $user_id ): int {
	Profiles::forget_cached( $user_id );
	return (int) ( ( new Profiles() )->find_by_user( $user_id )['rep_points'] ?? -1 );
}

global $wpdb;
add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
add_filter( 'mmaf_community_feature_enabled', '__return_true' );
add_filter( 'wp_is_comment_flood', '__return_false', 99 );
require_once ABSPATH . 'wp-admin/includes/user.php';

$mmafm_suffix = strtolower( wp_generate_password( 8, false, false ) );
$mmafm_tables = Schema::tables();
$mmafm_areas  = get_option( 'mmaf_community_areas', null );
$mmafm_users  = array();
$mmafm_lists  = array();
$mmafm_post   = 0;

try {
	$mmafm_post = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Comments fixture ' . $mmafm_suffix, 'post_content' => 'Body', 'comment_status' => 'open' ) );
	Config::save_areas( array( 'fan', 'reputation' ) );
	assert_cm( '' === Comments::shell( $mmafm_post ) && ! Config::area_enabled( 'comments' ), 'The discussion shows while the comments area is off.' );
	Config::save_areas( array( 'fan', 'reputation', 'comments' ) );
	$mmafm_shell = Comments::shell( $mmafm_post );
	assert_cm( str_contains( $mmafm_shell, 'data-mmafc-comments="' . $mmafm_post . '"' ) && str_contains( $mmafm_shell, 'id="comments"' ) && ! str_contains( $mmafm_shell, 'nonce' ), 'The article does not get an empty discussion section, or it carries per-reader data.' );
	$mmafm_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Comments fixture page ' . $mmafm_suffix ) );
	assert_cm( '' === Comments::shell( $mmafm_page ), 'A page that is not an article got a discussion.' );
	wp_delete_post( $mmafm_page, true );

	// author and voter reach level 2; newcomer has a bare profile; ghost has none; editor is staff; boss moderates.
	foreach ( array( 'author' => 'subscriber', 'voter' => 'subscriber', 'newcomer' => 'subscriber', 'ghost' => 'subscriber', 'editor' => 'editor', 'boss' => 'administrator' ) as $mmafm_label => $mmafm_role ) {
		$mmafm_id = wp_create_user( 'cm_' . $mmafm_label . '_' . $mmafm_suffix, wp_generate_password( 24, true, true ), 'cm-' . $mmafm_label . '-' . $mmafm_suffix . '@example.test' );
		if ( is_wp_error( $mmafm_id ) ) { throw new RuntimeException( $mmafm_id->get_error_message() ); }
		get_userdata( $mmafm_id )->set_role( $mmafm_role );
		update_user_meta( $mmafm_id, '_mmaf_email_verified', '1' );
		$mmafm_users[ $mmafm_label ] = (int) $mmafm_id;
	}
	$u        = $mmafm_users;
	$profiles = new Profiles();
	foreach ( array( 'author', 'voter' ) as $mmafm_label ) {
		$profiles->save( $u[ $mmafm_label ], array( 'handle' => 'cm' . substr( $mmafm_label, 0, 3 ) . '_' . $mmafm_suffix, 'display_name' => ucfirst( $mmafm_label ) . ' Fan', 'bio' => 'Bio.' ) );
		$profiles->set_avatar( $u[ $mmafm_label ], 'cm-' . $u[ $mmafm_label ] . '.webp' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $mmafm_tables['fan_lists'], array( 'user_id' => $u[ $mmafm_label ], 'revision' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		$mmafm_list_id = (int) $wpdb->insert_id;
		$mmafm_lists[] = $mmafm_list_id;
		for ( $p = 1; $p <= FanLists::SIZE; $p++ ) { $wpdb->insert( $mmafm_tables['fan_list_items'], array( 'list_id' => $mmafm_list_id, 'position' => $p, 'fighter_id' => 870000000 + $p, 'picked_at' => $now ) ); }
		do_action( 'mmafc_top10_saved', $u[ $mmafm_label ] );
	}
	$profiles->save( $u['newcomer'], array( 'handle' => 'cmnew_' . $mmafm_suffix ) );
	assert_cm( 25 === cm_points( $u['author'] ) && 25 === cm_points( $u['voter'] ), 'The fixture readers did not reach level 2.' );

	$comments = new Comments();
	assert_cm( str_starts_with( $comments->problem( 0, $mmafm_post ), 'Sign in' ) && str_starts_with( $comments->problem( $u['ghost'], $mmafm_post ), 'Set up your profile' ) && '' === $comments->problem( $u['newcomer'], $mmafm_post ) && '' === $comments->problem( $u['editor'], $mmafm_post ), 'Who may comment is wrong.' );
	assert_cm( str_contains( cm_error( static fn() => $comments->post( $u['ghost'], $mmafm_post, 'Hello' ) ), 'profile' ) && '' !== cm_error( static fn() => $comments->post( $u['author'], $mmafm_post, '   ' ) ) && '' !== cm_error( static fn() => $comments->post( $u['author'], $mmafm_post, str_repeat( 'x', Comments::MAX_LENGTH + 1 ) ) ), 'An empty, oversized or profile-less comment was accepted.' );

	// Posting: visible at once, plain text, +1 XP for at most three a day.
	$mmafm_c = array();
	foreach ( array( 'First <b>take</b>', 'Second take', 'Third take', 'Fourth take' ) as $mmafm_text ) { $mmafm_c[] = $comments->post( $u['author'], $mmafm_post, $mmafm_text )['id']; }
	assert_cm( 'approved' === wp_get_comment_status( $mmafm_c[0] ) && 'First take' === get_comment( $mmafm_c[0] )->comment_content && 4 === Comments::count( $mmafm_post ), 'A comment is not visible at once, or kept markup.' );
	assert_cm( 28 === cm_points( $u['author'] ), 'Commenting did not earn +1 XP for at most three comments a day: ' . cm_points( $u['author'] ) );

	// Replies nest two levels; an answer to the deepest level joins that conversation.
	$mmafm_r1 = $comments->post( $u['voter'], $mmafm_post, 'Reply one', $mmafm_c[0] )['id'];
	$mmafm_r2 = $comments->post( $u['newcomer'], $mmafm_post, 'Reply two', $mmafm_r1 )['id'];
	$mmafm_r3 = $comments->post( $u['author'], $mmafm_post, 'Reply three', $mmafm_r2 )['id'];
	assert_cm( $mmafm_r1 === (int) get_comment( $mmafm_r2 )->comment_parent && $mmafm_r1 === (int) get_comment( $mmafm_r3 )->comment_parent, 'Replies nest deeper than two levels.' );
	$mmafm_view = $comments->thread( $mmafm_post, 'new', $u['voter'] );
	$mmafm_by   = array_column( $mmafm_view['comments'], null, 'id' );
	assert_cm( 7 === $mmafm_view['count'] && 4 === count( $mmafm_view['order'] ) && 1 === $mmafm_by[ $mmafm_r1 ]['depth'] && 2 === $mmafm_by[ $mmafm_r2 ]['depth'] && $mmafm_by[ $mmafm_r1 ]['own'] && ! $mmafm_by[ $mmafm_c[0] ]['own'], 'The thread does not carry depth, order or ownership: ' . wp_json_encode( $mmafm_view['order'] ) );
	assert_cm( $mmafm_view['can_down'] && ! $comments->thread( $mmafm_post, 'new', $u['newcomer'] )['can_down'] && ! $comments->thread( $mmafm_post, 'new', 0 )['can_vote'], 'Vote permissions in the thread are wrong.' );
	assert_cm( 'Author Fan' === $mmafm_by[ $mmafm_c[0] ]['author']['name'] && str_contains( $mmafm_by[ $mmafm_c[0] ]['author']['badge'], 'mmafc-badge--sm' ) && ! str_contains( wp_json_encode( $mmafm_view ), get_userdata( $u['author'] )->user_email ), 'Authors are not shown by profile, or the thread exposes an email.' );

	// Votes.
	$votes = new CommentVotes();
	assert_cm( str_contains( cm_error( static fn() => $votes->vote( $u['author'], $mmafm_c[0], 1 ) ), 'own' ) && str_contains( cm_error( static fn() => $votes->vote( $u['ghost'], $mmafm_c[0], 1 ) ), 'profile' ) && str_contains( cm_error( static fn() => $votes->vote( $u['newcomer'], $mmafm_c[0], -1 ) ), 'level 2' ), 'A vote that should be refused went through.' );
	assert_cm( 1 === $votes->vote( $u['voter'], $mmafm_c[0], 1 )['score'] && 2 === $votes->vote( $u['newcomer'], $mmafm_c[0], 1 )['score'] && 30 === cm_points( $u['author'] ), 'Up votes do not add to the score and to the author’s XP.' );
	assert_cm( 0 === $votes->vote( $u['voter'], $mmafm_c[0], -1 )['score'] && 28 === cm_points( $u['author'] ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mmafm_tables['comment_votes']} WHERE comment_id=%d AND user_id=%d", $mmafm_c[0], $u['voter'] ) ), 'Changing a vote added a second one instead of replacing it.' );
	assert_cm( 1 === $votes->vote( $u['voter'], $mmafm_c[0], 0 )['score'] && 29 === cm_points( $u['author'] ), 'A vote could not be taken back.' );
	$profiles->set_status( $u['newcomer'], 'shadow' );
	assert_cm( 0 === (int) get_comment_meta( $mmafm_c[0], '_mmafc_score', true ) && 28 === cm_points( $u['author'] ), 'A shadowed reader’s vote still counts.' );
	$profiles->set_status( $u['newcomer'], 'active' );
	assert_cm( 1 === (int) get_comment_meta( $mmafm_c[0], '_mmafc_score', true ), 'A restored reader’s vote does not count again.' );
	$mmafm_breakdown = \MMAF\DataEngine\Services\Community\Reputation::breakdown( $u['author'] )['breakdown'];
	assert_cm( 4 === $mmafm_breakdown['comments'] && 0 === $mmafm_breakdown['props'], 'The breakdown does not separate comments from props: ' . wp_json_encode( $mmafm_breakdown ) );

	// Reports: three accounts hold a comment, and its XP leaves with it.
	assert_cm( str_contains( cm_error( static fn() => $comments->report( $u['author'], $mmafm_c[1] ) ), 'own' ), 'A reader could report their own comment.' );
	assert_cm( false === $comments->report( $u['voter'], $mmafm_c[1] ) && false === $comments->report( $u['voter'], $mmafm_c[1] ) && false === $comments->report( $u['newcomer'], $mmafm_c[1] ), 'Reports from one account were counted twice, or two reports held a comment.' );
	assert_cm( true === $comments->report( $u['editor'], $mmafm_c[1] ) && 'unapproved' === wp_get_comment_status( $mmafm_c[1] ) && 6 === Comments::count( $mmafm_post ) && 28 === cm_points( $u['author'] ), 'Three reports did not hold the comment and take back its XP.' );

	// The core comment form and route are closed to everyone but moderators while the area is on.
	wp_set_current_user( $u['voter'] );
	$mmafm_core_request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$mmafm_core_request->set_body_params( array( 'post' => $mmafm_post, 'content' => 'Through core' ) );
	$mmafm_core = rest_do_request( $mmafm_core_request );
	assert_cm( 403 === $mmafm_core->get_status() && 'mmafc_comments_route' === ( $mmafm_core->get_data()['code'] ?? '' ), 'A reader can still post through /wp/v2/comments.' );
	wp_set_current_user( $u['boss'] );
	assert_cm( null === Comments::refuse_core_rest( null, rest_get_server(), $mmafm_core_request ), 'A moderator was refused the core comments route.' );

	// REST.
	$mmafm_rest = static function ( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/mma-future/v1/community/' . $route );
		foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
		return rest_do_request( $request );
	};
	wp_set_current_user( 0 );
	$mmafm_public = $mmafm_rest( 'GET', 'comments', array( 'post_id' => $mmafm_post ) );
	assert_cm( 200 === $mmafm_public->get_status() && 6 === $mmafm_public->get_data()['count'] && false === $mmafm_public->get_data()['can_post'], 'Signed-out visitors cannot read the discussion, or are offered the form.' );
	assert_cm( in_array( $mmafm_rest( 'POST', 'comments', array( 'post_id' => $mmafm_post, 'content' => 'Hi' ) )->get_status(), array( 401, 403 ), true ), 'A signed-out visitor could post.' );
	wp_set_current_user( $u['ghost'] );
	$mmafm_refused = $mmafm_rest( 'POST', 'comments', array( 'post_id' => $mmafm_post, 'content' => 'Hi' ) );
	assert_cm( 400 === $mmafm_refused->get_status() && isset( $mmafm_refused->get_data()['data']['fields']['content'] ), 'A refused comment does not answer 400 with the reason.' );
	wp_set_current_user( $u['voter'] );
	$mmafm_posted = $mmafm_rest( 'POST', 'comments', array( 'post_id' => $mmafm_post, 'content' => 'Posted over REST', 'parent' => $mmafm_c[2] ) );
	assert_cm( 200 === $mmafm_posted->get_status() && 7 === $mmafm_posted->get_data()['count'], 'A comment over REST was not posted.' );
	assert_cm( 200 === $mmafm_rest( 'POST', 'comments/' . $mmafm_c[2] . '/vote', array( 'value' => 1 ) )->get_status() && 404 === $mmafm_rest( 'GET', 'comments', array( 'post_id' => 999999999 ) )->get_status(), 'Voting over REST failed, or an unknown article answered.' );
	wp_set_current_user( 0 );

	echo wp_json_encode( array( 'ok' => true ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	wp_set_current_user( 0 );
	remove_filter( 'wp_is_comment_flood', '__return_false', 99 );
	if ( $mmafm_post ) {
		$mmafm_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID=%d", $mmafm_post ) ) );
		if ( $mmafm_ids ) { $wpdb->query( "DELETE FROM {$mmafm_tables['comment_votes']} WHERE comment_id IN (" . implode( ',', $mmafm_ids ) . ')' ); }
		wp_delete_post( $mmafm_post, true );
	}
	foreach ( $mmafm_users as $mmafm_id ) {
		( new Profiles() )->forget( $mmafm_id );
		wp_delete_user( $mmafm_id );
		foreach ( array( 'comment', 'comment_day', 'vote', 'report', 'profile' ) as $mmafm_bucket ) { delete_transient( 'mmafc_rl_' . $mmafm_bucket . '_' . $mmafm_id ); }
	}
	foreach ( $mmafm_lists as $mmafm_list_id ) { $wpdb->delete( $mmafm_tables['fan_list_items'], array( 'list_id' => $mmafm_list_id ) ); $wpdb->delete( $mmafm_tables['fan_lists'], array( 'id' => $mmafm_list_id ) ); }
	if ( null === $mmafm_areas ) { delete_option( 'mmaf_community_areas' ); } else { update_option( 'mmaf_community_areas', $mmafm_areas, false ); }
	remove_filter( 'mmaf_community_feature_enabled', '__return_true' );
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
}
