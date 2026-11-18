<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Services\Community\Conflict;
use MMAF\DataEngine\Services\Community\FanBoard;
use MMAF\DataEngine\Services\Community\FanLists;
use MMAF\DataEngine\Services\Community\FanRanking;
use MMAF\DataEngine\Services\Community\Module;
use MMAF\DataEngine\Services\Community\Privacy;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Routes;
use MMAF\DataEngine\Services\Community\Schema;
use MMAF\DataEngine\Services\Community\ValidationFailed;

// Fan lists commit their own transactions, so this fixture writes real rows and removes them in the finally block.
// Templates are included at file scope on purpose (see verify-notification-pages.php); every variable is prefixed.
function assert_fan( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

/** @return string The class of the exception a save raised, or '' when it succeeded. */
function fan_save( int $user_id, $ids, int $revision ): string {
	try { ( new FanLists() )->save( $user_id, $ids, $revision ); return ''; } catch ( ValidationFailed | Conflict $e ) { return get_class( $e ); }
}

/** fighter id => [points, picks, firsts], in fan-rank order. */
function fan_table(): array {
	$out = array();
	foreach ( ( new FanRanking() )->standings()['rows'] as $row ) { $out[ $row['fighter_id'] ] = array( $row['points'], $row['picks'], $row['firsts'] ); }
	return $out;
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
add_filter( 'mmaf_community_feature_enabled', '__return_true' );
add_filter( 'mmaf_community_area_enabled', '__return_true' );

$mmafc_f_suffix   = strtolower( wp_generate_password( 8, false, false ) );
$mmafc_f_canon    = CanonicalSchema::table_names();
$mmafc_f_tables   = Schema::tables();
$mmafc_f_runs     = new RankingRunRepository();
$mmafc_f_original = $mmafc_f_runs->get_active_ranking_run_id();
$mmafc_f_revision = get_option( 'mmaf_fan_revision', null );
$mmafc_f_get      = $_GET;
$mmafc_f_users    = array();
$mmafc_f_posts    = array();
$mmafc_f_ids      = array();
$mmafc_f_run      = 0;
$mmafc_f_done     = false;

try {
	// Twelve ranked fighters; the first five are also on the men's board.
	for ( $mmafc_f_i = 1; $mmafc_f_i <= 12; $mmafc_f_i++ ) {
		$mmafc_f_name    = sprintf( 'Fan Fixture %02d %s', $mmafc_f_i, $mmafc_f_suffix );
		$mmafc_f_post    = (int) wp_insert_post( array( 'post_type' => 'mmaf_fighter', 'post_status' => 'publish', 'post_title' => $mmafc_f_name ) );
		$mmafc_f_posts[] = $mmafc_f_post;
		$mmafc_f_ids[]   = ( new FighterRepository() )->insert( array( 'wp_post_id' => $mmafc_f_post, 'display_name' => $mmafc_f_name, 'normalized_name' => strtolower( $mmafc_f_name ), 'weight_class' => 'lightweight', 'is_public' => 1, 'is_rankable' => 1, 'rankability_status' => 'rankable' ) );
	}
	$mmafc_f_run = $mmafc_f_runs->create( 'v1.7', \MMAF\DataEngine\Services\Formula\FormulaV17::config(), gmdate( 'Y-m-d' ), gmdate( 'Y-m-d H:i:s' ), 'completed', 'fan ranking fixture' );
	foreach ( $mmafc_f_ids as $mmafc_f_index => $mmafc_f_id ) {
		foreach ( $mmafc_f_index < 5 ? array( 'overall', 'male' ) : array( 'overall' ) as $mmafc_f_board ) {
			if ( false === $wpdb->insert( $mmafc_f_canon['ranking_snapshots'], array( 'ranking_run_id' => $mmafc_f_run, 'board_key' => $mmafc_f_board, 'fighter_id' => $mmafc_f_id, 'rank_position' => $mmafc_f_index + 1, 'total_score' => 50 - $mmafc_f_index, 'raw_score' => 50 - $mmafc_f_index, 'sample_size' => 2, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ) ) { throw new RuntimeException( $wpdb->last_error ); }
		}
	}
	$mmafc_f_runs->set_active_ranking_run_id( $mmafc_f_run );
	$f = $mmafc_f_ids; // $f[0] is the official number one.

	// Readers a, b and c are established accounts; "fresh" registered a moment ago; "staff" is an administrator.
	foreach ( array( 'a' => 'subscriber', 'b' => 'subscriber', 'c' => 'subscriber', 'fresh' => 'subscriber', 'staff' => 'administrator' ) as $mmafc_f_label => $mmafc_f_role ) {
		$mmafc_f_user = wp_create_user( 'fan_' . $mmafc_f_label . '_' . $mmafc_f_suffix, wp_generate_password( 24, true, true ), 'fan-' . $mmafc_f_label . '-' . $mmafc_f_suffix . '@example.test' );
		if ( is_wp_error( $mmafc_f_user ) ) { throw new RuntimeException( $mmafc_f_user->get_error_message() ); }
		get_userdata( $mmafc_f_user )->set_role( $mmafc_f_role );
		update_user_meta( $mmafc_f_user, '_mmaf_email_verified', '1' );
		if ( 'fresh' !== $mmafc_f_label ) { $wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'ID' => $mmafc_f_user ) ); clean_user_cache( $mmafc_f_user ); }
		$mmafc_f_users[ $mmafc_f_label ] = (int) $mmafc_f_user;
	}
	$u     = $mmafc_f_users;
	$lists = new FanLists();
	$base  = count( fan_table() ); // Rows from other fixtures, if any, are not ours to judge.

	// A first list, and what it is worth.
	assert_fan( array( 'revision' => 0, 'items' => array(), 'counts' => true ) === array_intersect_key( $lists->editable( $u['a'] ), array( 'revision' => 1, 'items' => 1, 'counts' => 1 ) ), 'A reader without a list does not start from an empty revision 0.' );
	assert_fan( '' === fan_save( $u['a'], array_slice( $f, 0, 10 ), 0 ), 'A first Top 10 could not be saved.' );
	$mmafc_f_view = $lists->editable( $u['a'] );
	assert_fan( 1 === $mmafc_f_view['revision'] && array_slice( $f, 0, 10 ) === array_column( $mmafc_f_view['items'], 'fighter_id' ) && array( 25, 18, 15, 12, 10, 8, 6, 4, 2, 1 ) === array_column( $mmafc_f_view['items'], 'points' ), 'The saved list does not come back in order with its points.' );
	assert_fan( $mmafc_f_view['items'][0]['display_name'] === 'Fan Fixture 01 ' . $mmafc_f_suffix && 1 === $mmafc_f_view['items'][0]['official_rank'] && '' !== $mmafc_f_view['items'][0]['permalink'], 'A pick is shown without the fighter’s public card data.' );
	$mmafc_f_table = fan_table();
	assert_fan( array( 25, 1, 1 ) === $mmafc_f_table[ $f[0] ] && array( 1, 1, 0 ) === $mmafc_f_table[ $f[9] ] && ! isset( $mmafc_f_table[ $f[10] ] ) && count( $mmafc_f_table ) === $base + 10, 'One list does not produce the expected standings.' );

	// A second reader: points add up, and a tie falls to the better official rank.
	assert_fan( '' === fan_save( $u['b'], array( $f[1], $f[0], $f[10] ), 0 ), 'A shorter list could not be saved.' );
	$mmafc_f_table = fan_table();
	assert_fan( array( 43, 2, 1 ) === $mmafc_f_table[ $f[0] ] && array( 43, 2, 1 ) === $mmafc_f_table[ $f[1] ] && array( 15, 1, 0 ) === $mmafc_f_table[ $f[10] ], 'Points from two lists were not added together.' );
	assert_fan( array_search( $f[0], array_keys( $mmafc_f_table ), true ) < array_search( $f[1], array_keys( $mmafc_f_table ), true ), 'A tie on points, first places and lists is not settled by the official rank.' );
	$mmafc_f_scores = ( new FanRanking() )->scores( array( $f[0], $f[11] ) );
	assert_fan( isset( $mmafc_f_scores[ $f[0] ]['rank'] ) && 43 === $mmafc_f_scores[ $f[0] ]['points'] && ! isset( $mmafc_f_scores[ $f[11] ] ), 'Scores for a fighter page are wrong, or invent a score for an unpicked fighter.' );

	// What a save refuses.
	assert_fan( Conflict::class === fan_save( $u['a'], array( $f[5] ), 0 ), 'A save from a stale copy was accepted.' );
	assert_fan( Conflict::class === fan_save( $u['c'], array( $f[5] ), 4 ), 'A first save claimed a revision that never existed.' );
	assert_fan( array_slice( $f, 0, 10 ) === $lists->fighter_ids( $u['a'] ) && array() === $lists->fighter_ids( $u['c'] ), 'A refused save still changed a list.' );
	foreach ( array( 'a repeated fighter' => array( $f[0], $f[1], $f[0] ), 'eleven fighters' => array_slice( $f, 0, 11 ), 'an unknown fighter' => array( $f[0], 999999999 ), 'a zero' => array( $f[0], 0 ), 'a nested value' => array( $f[0], array( $f[1] ) ), 'not a list' => 'all' ) as $mmafc_f_what => $mmafc_f_bad ) {
		assert_fan( ValidationFailed::class === fan_save( $u['a'], $mmafc_f_bad, 1 ), "A list with $mmafc_f_what was accepted." );
	}
	assert_fan( 1 === $lists->editable( $u['a'] )['revision'], 'A rejected list advanced the revision.' );

	// Reordering keeps the date a fighter was first picked; a fighter nobody picks any more leaves the board.
	$mmafc_f_items  = $mmafc_f_tables['fan_list_items'];
	$mmafc_f_list   = (int) $lists->find( $u['a'] )['id'];
	$wpdb->query( $wpdb->prepare( "UPDATE {$mmafc_f_items} SET picked_at='2026-01-02 03:04:05' WHERE list_id=%d AND fighter_id=%d", $mmafc_f_list, $f[9] ) );
	assert_fan( '' === fan_save( $u['a'], array_merge( array( $f[9] ), array_slice( $f, 0, 8 ), array( $f[11] ) ), 1 ), 'A reordered list could not be saved.' );
	assert_fan( '2026-01-02 03:04:05' === $wpdb->get_var( $wpdb->prepare( "SELECT picked_at FROM {$mmafc_f_items} WHERE list_id=%d AND fighter_id=%d", $mmafc_f_list, $f[9] ) ), 'Reordering reset the date a fighter was picked.' );
	assert_fan( array( 1, 10 ) === array_map( 'intval', (array) $wpdb->get_row( $wpdb->prepare( "SELECT MIN(position),COUNT(*) FROM {$mmafc_f_items} WHERE list_id=%d", $mmafc_f_list ), ARRAY_N ) ) && 2 === $lists->editable( $u['a'] )['revision'], 'The replaced list is not ten positions at revision 2.' );
	$mmafc_f_table = fan_table();
	assert_fan( ! isset( $mmafc_f_table[ $f[8] ] ) && array( 25, 1, 1 ) === $mmafc_f_table[ $f[9] ] && array( 1, 1, 0 ) === $mmafc_f_table[ $f[11] ], 'A fighter dropped from every list is still on the board, or the new order is not scored.' );

	// Lists that do not count: an account registered a moment ago, staff, and a reader a moderator has excluded.
	assert_fan( '' === fan_save( $u['fresh'], array( $f[11] ), 0 ) && '' === fan_save( $u['staff'], array( $f[11] ), 0 ), 'A new account or a staff account cannot keep a list.' );
	assert_fan( 'unlisted' === $lists->find( $u['staff'] )['status'] && false === $lists->editable( $u['staff'] )['counts'] && true === $lists->editable( $u['fresh'] )['counts'], 'A staff list is not marked as uncounted.' );
	assert_fan( null === $lists->editable( $u['a'] )['counts_from'] && strtotime( (string) $lists->editable( $u['fresh'] )['counts_from'] ) > time() + 23 * HOUR_IN_SECONDS, 'A fresh account is not told when its picks start to count, or an established one is.' );
	assert_fan( array( 1, 1, 0 ) === fan_table()[ $f[11] ], 'A brand-new account or a staff account moved the fan ranking.' );
	add_filter( 'mmaf_community_fan_min_account_hours', '__return_zero' );
	assert_fan( array( 26, 2, 1 ) === fan_table()[ $f[11] ], 'The account-age rule cannot be changed, or still blocks an account that is old enough.' );
	remove_filter( 'mmaf_community_fan_min_account_hours', '__return_zero' );
	( new Profiles() )->save( $u['b'], array( 'handle' => 'fanb_' . $mmafc_f_suffix ) );
	$wpdb->update( $mmafc_f_tables['profiles'], array( 'status' => 'shadow' ), array( 'user_id' => $u['b'] ) );
	FanRanking::changed();
	assert_fan( ! isset( fan_table()[ $f[10] ] ) && 18 === fan_table()[ $f[0] ][0], 'An excluded reader’s picks still count.' );
	$wpdb->update( $mmafc_f_tables['profiles'], array( 'status' => 'hidden' ), array( 'user_id' => $u['b'] ) );
	FanRanking::changed();
	assert_fan( ! isset( fan_table()[ $f[10] ] ) && 18 === fan_table()[ $f[0] ][0], 'A hidden reader’s picks still count.' );
	$wpdb->update( $mmafc_f_tables['profiles'], array( 'status' => 'active' ), array( 'user_id' => $u['b'] ) );
	FanRanking::changed();
	assert_fan( isset( fan_table()[ $f[10] ] ), 'A reader shown again does not count again.' );

	// Boards: a fighter has one fan score; a division board is the same standings narrowed and renumbered.
	$mmafc_f_overall = ( new FanRanking() )->board( 'overall', 1, 100 );
	$mmafc_f_male    = ( new FanRanking() )->board( 'male', 1, 100 );
	$mmafc_f_ours    = array_values( array_filter( $mmafc_f_male['items'], static fn( array $row ): bool => in_array( $row['fighter_id'], $f, true ) ) );
	assert_fan( array( $f[1], $f[0], $f[2], $f[3], $f[4] ) === array_column( $mmafc_f_ours, 'fighter_id' ) && count( $mmafc_f_male['items'] ) === $mmafc_f_male['total'], 'The men’s board is not the overall standings narrowed to its own fighters.' );
	assert_fan( $mmafc_f_male['total'] < $mmafc_f_overall['total'] && $mmafc_f_male['lists'] === $mmafc_f_overall['lists'], 'A division board has its own list count or is not smaller than the overall board.' );
	foreach ( $mmafc_f_male['items'] as $mmafc_f_index => $mmafc_f_row ) {
		assert_fan( $mmafc_f_index + 1 === $mmafc_f_row['fan_rank'] && $mmafc_f_row['board_rank'] - $mmafc_f_row['fan_rank'] === $mmafc_f_row['difference'], 'Fan ranks on a board are not numbered from one, or the difference from the official rank is wrong.' );
	}
	assert_fan( 'overall' === ( new FanRanking() )->board( 'no_such_board' )['board'], 'An unknown board is not treated as the overall board.' );
	$mmafc_f_paged = ( new FanRanking() )->board( 'overall', 2, 3 );
	assert_fan( 2 === $mmafc_f_paged['page'] && 4 === $mmafc_f_paged['items'][0]['fan_rank'] && 3 === count( $mmafc_f_paged['items'] ), 'The second page does not continue the numbering.' );

	// A fighter who signs with the UFC keeps their place on the reader's list but leaves the ranking.
	$wpdb->update( $mmafc_f_canon['fighters'], array( 'in_ufc' => 1 ), array( 'id' => $f[1] ) );
	FanRanking::changed();
	assert_fan( ! isset( fan_table()[ $f[1] ] ), 'A fighter signed to the UFC is still on the fan board.' );
	$mmafc_f_signed = array_values( array_filter( $lists->editable( $u['a'] )['items'], static fn( array $item ): bool => $item['fighter_id'] === $f[1] ) )[0];
	assert_fan( false === $mmafc_f_signed['eligible'] && 0 === $mmafc_f_signed['points'] && 'Signed with the UFC' === $mmafc_f_signed['note'], 'A signed fighter still scores on the list, or the reader is not told why.' );
	assert_fan( '' === fan_save( $u['a'], $lists->fighter_ids( $u['a'] ), 2 ), 'A list holding a signed fighter can no longer be saved.' );
	assert_fan( ValidationFailed::class === fan_save( $u['c'], array( $f[1] ), 0 ), 'A signed fighter can be newly added to a list.' );
	$wpdb->update( $mmafc_f_canon['fighters'], array( 'in_ufc' => 0 ), array( 'id' => $f[1] ) );
	FanRanking::changed();

	// REST.
	wp_set_current_user( 0 );
	assert_fan( 401 === rest_do_request( new WP_REST_Request( 'GET', '/mma-future/v1/community/top-10' ) )->get_status(), 'A signed-out visitor can read a Top 10.' );
	$mmafc_f_request = new WP_REST_Request( 'GET', '/mma-future/v1/community/fan-scores' );
	$mmafc_f_request->set_query_params( array( 'fighter_ids' => $f[0] . ',' . $f[8] . ',abc' ) );
	$mmafc_f_response = rest_do_request( $mmafc_f_request );
	$mmafc_f_scores   = (array) $mmafc_f_response->get_data()['scores'];
	assert_fan( 200 === $mmafc_f_response->get_status() && isset( $mmafc_f_scores[ $f[0] ]['rank'] ) && ! isset( $mmafc_f_scores[ $f[8] ] ) && $mmafc_f_response->get_data()['lists'] >= 2, 'The public fan-score route is closed or returns the wrong fighters.' );
	wp_set_current_user( $u['c'] );
	$mmafc_f_request = new WP_REST_Request( 'POST', '/mma-future/v1/community/top-10' );
	$mmafc_f_request->set_body_params( array( 'fighter_ids' => array( $f[4], $f[3] ), 'revision' => 0 ) );
	$mmafc_f_response = rest_do_request( $mmafc_f_request );
	assert_fan( 200 === $mmafc_f_response->get_status() && 1 === $mmafc_f_response->get_data()['revision'] && array( $f[4], $f[3] ) === array_column( $mmafc_f_response->get_data()['items'], 'fighter_id' ), 'A Top 10 cannot be saved over REST.' );
	assert_fan( 409 === rest_do_request( $mmafc_f_request )->get_status(), 'Sending the same save twice did not report a conflict.' );
	$mmafc_f_request = new WP_REST_Request( 'POST', '/mma-future/v1/community/top-10' );
	$mmafc_f_request->set_body_params( array( 'fighter_ids' => array( $f[4], $f[4] ), 'revision' => 1 ) );
	$mmafc_f_response = rest_do_request( $mmafc_f_request );
	assert_fan( 400 === $mmafc_f_response->get_status() && isset( $mmafc_f_response->get_data()['data']['fields']['fighters'] ), 'An invalid list over REST does not say what is wrong.' );

	// Pages.
	$_GET = array();
	assert_fan( false === FanBoard::requested() && str_contains( FanBoard::source_toggle( 'official' ), 'aria-current="page">Official</a>' ), 'The official view is treated as the fan view, or has no switch.' );
	assert_fan( str_contains( FanBoard::chip( $f[0] ), 'data-mmafc-fan-chip="' . $f[0] . '"' ) && str_contains( FanBoard::chip( $f[0] ), ' hidden' ) && '' === FanBoard::chip( 0 ), 'The fighter page has no hidden fan-rank placeholder.' );
	$_GET = array( FanBoard::QUERY_VAR => 'fans', FanBoard::BOARD_VAR => 'male' );
	assert_fan( true === FanBoard::requested(), 'The fan view is not recognised.' );
	ob_start();
	FanBoard::render();
	$mmafc_f_board = (string) ob_get_clean();
	assert_fan( str_contains( $mmafc_f_board, 'Fan Fixture 01 ' . $mmafc_f_suffix ) && ! str_contains( $mmafc_f_board, 'Fan Fixture 10 ' . $mmafc_f_suffix ) && str_contains( $mmafc_f_board, 'aria-current="page">Fans</a>' ), 'The fan board does not show the chosen division, or its switch.' );
	assert_fan( strpos( $mmafc_f_board, 'Fan Fixture 01 ' ) < strpos( $mmafc_f_board, 'Fan Fixture 03 ' ) && str_contains( $mmafc_f_board, 'fan Top 10' ), 'The fan board is out of order or does not say how many lists it rests on.' );
	$_GET = array();

	wp_set_current_user( $u['a'] );
	assert_fan( ! preg_match( '/\$(page|pages|s|post|posts|id|more|numpages|multipage|authordata|wp_query)\b\s*=(?!=)/', (string) file_get_contents( MMAF_PLUGIN_DIR . 'templates/community-top10.php' ) ), 'The Top 10 template assigns a WordPress global that get_header() resets.' );
	set_query_var( 'mmaf_community_page', 'account-top-10' );
	ob_start();
	include MMAF_PLUGIN_DIR . 'templates/community-top10.php';
	$mmafc_f_editor = (string) ob_get_clean();
	assert_fan( str_contains( $mmafc_f_editor, 'data-mmafc-editor' ) && str_contains( $mmafc_f_editor, 'aria-current="page">My Top 10</a>' ) && MMAF_PLUGIN_DIR . 'templates/community-top10.php' === Routes::template( 'theme.php' ) && str_starts_with( Routes::document_title( 'Blog' ), 'My Top 10 - ' ), 'The Top 10 page is not routed, titled or rendered.' );

	( new Profiles() )->save( $u['a'], array( 'handle' => 'fana_' . $mmafc_f_suffix ) );
	wp_set_current_user( 0 );
	set_query_var( 'mmaf_community_page', 'profile' );
	set_query_var( 'mmaf_community_handle', 'fana_' . $mmafc_f_suffix );
	ob_start();
	include MMAF_PLUGIN_DIR . 'templates/community-profile.php';
	$mmafc_f_profile = (string) ob_get_clean();
	set_query_var( 'mmaf_community_page', '' );
	set_query_var( 'mmaf_community_handle', '' );
	assert_fan( str_contains( $mmafc_f_profile, 'id="mmafc-top10-title"' ) && strpos( $mmafc_f_profile, 'Fan Fixture 10 ' ) < strpos( $mmafc_f_profile, 'Fan Fixture 01 ' ) && str_contains( $mmafc_f_profile, 'Official #10' ), 'The public profile does not show the reader’s Top 10 in their order.' );
	ob_start(); Module::account_nav( 'top-10' ); $mmafc_f_nav = (string) ob_get_clean();
	assert_fan( 3 === substr_count( $mmafc_f_nav, 'mmafc-tabs__link' ), 'The account tabs do not include My Top 10.' );

	// Leaving: the export names the picks, and a removed account takes its points with it.
	assert_fan( str_contains( wp_json_encode( Privacy::export( get_userdata( $u['c'] )->user_email ) ), '1. Fan Fixture 05 ' . $mmafc_f_suffix ), 'The privacy export omits a Top 10 kept without a profile.' );
	$mmafc_f_before = fan_table()[ $f[0] ];
	wp_delete_user( $u['b'] );
	assert_fan( $mmafc_f_before[0] - 18 === fan_table()[ $f[0] ][0] && $mmafc_f_before[1] - 1 === fan_table()[ $f[0] ][1] && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mmafc_f_tables['fan_lists']} WHERE user_id=%d", $u['b'] ) ), 'A deleted account still counts in the fan ranking.' );

	$mmafc_f_done = true;
	echo wp_json_encode( array( 'ok' => true, 'fighters' => count( $f ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	while ( ! $mmafc_f_done && ob_get_level() > 0 ) { ob_end_clean(); }
	$_GET = $mmafc_f_get;
	foreach ( array( 'mmaf_community_page', 'mmaf_community_handle' ) as $mmafc_f_var ) { set_query_var( $mmafc_f_var, '' ); }
	wp_set_current_user( 0 );
	foreach ( $mmafc_f_users as $mmafc_f_user ) { ( new Profiles() )->forget( $mmafc_f_user ); wp_delete_user( $mmafc_f_user ); delete_transient( 'mmafc_rl_top10_' . $mmafc_f_user ); delete_transient( 'mmafc_rl_profile_' . $mmafc_f_user ); }
	if ( null === $mmafc_f_original ) { $wpdb->delete( $mmafc_f_canon['system_state'], array( 'state_key' => 'active_ranking_run_id' ) ); } else { $mmafc_f_runs->set_active_ranking_run_id( (int) $mmafc_f_original ); }
	if ( $mmafc_f_run ) { $wpdb->delete( $mmafc_f_canon['ranking_snapshots'], array( 'ranking_run_id' => $mmafc_f_run ) ); $wpdb->delete( $mmafc_f_canon['ranking_runs'], array( 'id' => $mmafc_f_run ) ); }
	foreach ( $mmafc_f_ids as $mmafc_f_id ) { $wpdb->delete( $mmafc_f_canon['fighters'], array( 'id' => $mmafc_f_id ) ); }
	foreach ( $mmafc_f_posts as $mmafc_f_post ) { wp_delete_post( $mmafc_f_post, true ); }
	if ( null === $mmafc_f_revision ) { delete_option( 'mmaf_fan_revision' ); } else { update_option( 'mmaf_fan_revision', $mmafc_f_revision, false ); }
	remove_filter( 'mmaf_community_fan_min_account_hours', '__return_zero' );
	remove_filter( 'mmaf_community_area_enabled', '__return_true' );
	remove_filter( 'mmaf_community_feature_enabled', '__return_true' );
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
	wp_cache_flush();
}
