<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Admin\FollowersPage;
use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
use MMAF\DataEngine\Services\Notifications\FollowersReport;
use MMAF\DataEngine\Services\Notifications\Schema as NotificationSchema;
use MMAF\DataEngine\Services\Notifications\Subscriptions;

function assert_followers( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

global $wpdb;
add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
$n = NotificationSchema::tables(); $canonical = CanonicalSchema::table_names();
$suffix = strtolower( wp_generate_password( 8, false, false ) );
$user_ids = array(); $fighter_ids = array(); $post_ids = array(); $saved_get = $_GET;

try {
	foreach ( array( 'one', 'two' ) as $label ) {
		$id = wp_create_user( 'followers_' . $label . '_' . $suffix, wp_generate_password( 24, true, true ), 'followers-' . $label . '-' . $suffix . '@example.test' );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$user_ids[] = (int) $id; get_userdata( $id )->set_role( 'subscriber' ); update_user_meta( $id, '_mmaf_email_verified', '1' );
	}
	update_user_meta( $user_ids[1], '_mmaf_notifications_enabled', '0' );
	$admin = wp_create_user( 'followers_admin_' . $suffix, wp_generate_password( 24, true, true ), 'followers-admin-' . $suffix . '@example.test' );
	if ( is_wp_error( $admin ) ) { throw new RuntimeException( $admin->get_error_message() ); }
	$user_ids[] = (int) $admin; get_userdata( $admin )->set_role( 'administrator' );
	// The second name starts like a spreadsheet formula on purpose.
	foreach ( array( "Followers Alpha $suffix", "=Followers Beta $suffix" ) as $name ) {
		$post_id = wp_insert_post( array( 'post_type' => 'mmaf_fighter', 'post_status' => 'publish', 'post_title' => $name ) ); $post_ids[] = (int) $post_id;
		$wpdb->insert( $canonical['fighters'], array( 'wp_post_id' => $post_id, 'display_name' => $name, 'normalized_name' => sanitize_title( $name ), 'weight_class' => 'Lightweight', 'status' => 'active', 'rankability_status' => 'eligible', 'is_public' => 1, 'is_rankable' => 1, 'tracking_mode' => 'normal', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$fighter_ids[] = (int) $wpdb->insert_id;
	}
	$subscriptions = new Subscriptions();
	$subscriptions->follow( $user_ids[0], $fighter_ids[0] ); $subscriptions->follow( $user_ids[0], $fighter_ids[1] ); $subscriptions->follow( $user_ids[1], $fighter_ids[0] );
	// An unfollow and a subscription left behind by a deleted account must not be counted.
	$subscriptions->follow( $user_ids[1], $fighter_ids[1] ); $subscriptions->unfollow( $user_ids[1], $fighter_ids[1] );
	$wpdb->insert( $n['subscriptions'], array( 'user_id' => 987654321, 'fighter_id' => $fighter_ids[0], 'status' => 'active', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );

	$report   = new FollowersReport();
	$fighters = $report->fighters( array( 'search' => $suffix ) );
	assert_followers( 2 === count( $fighters ) && 2 === $report->count_fighters( array( 'search' => $suffix ) ), 'Followed fighters are not listed once each.' );
	assert_followers( $fighter_ids[0] === (int) $fighters[0]['fighter_id'] && 2 === (int) $fighters[0]['followers'] && 1 === (int) $fighters[1]['followers'], 'Follower counts are wrong or not sorted most-followed first.' );
	assert_followers( '' !== $fighters[0]['profile_url'], 'A followed fighter has no profile link.' );
	$ascending = $report->fighters( array( 'search' => $suffix, 'orderby' => 'followers', 'order' => 'asc' ) );
	assert_followers( $fighter_ids[1] === (int) $ascending[0]['fighter_id'], 'Sorting by followers ascending had no effect.' );
	assert_followers( 2 === count( $report->fighters( array( 'search' => $suffix, 'orderby' => 'name; DROP TABLE x', 'order' => 'sideways' ) ) ), 'An unknown sort key reached the query.' );

	$users = $report->users( array( 'search' => 'followers-' ) );
	$by_id = array(); foreach ( $users as $row ) { $by_id[ (int) $row['user_id'] ] = $row; }
	assert_followers( isset( $by_id[ $user_ids[0] ], $by_id[ $user_ids[1] ] ) && ! isset( $by_id[ $user_ids[2] ] ), 'The reader list does not match who follows fighters.' );
	assert_followers( 2 === (int) $by_id[ $user_ids[0] ]['follows'] && str_contains( $by_id[ $user_ids[0] ]['fighters'], 'Followers Alpha' ) && str_contains( $by_id[ $user_ids[0] ]['fighters'], 'Followers Beta' ), 'A reader is not shown with every fighter they follow.' );
	assert_followers( 'active' === $by_id[ $user_ids[0] ]['delivery'] && 'disabled' === $by_id[ $user_ids[1] ]['delivery'], 'Delivery state does not say who actually receives email.' );
	$of_beta = $report->users( array( 'fighter_id' => $fighter_ids[1] ) );
	assert_followers( 1 === count( $of_beta ) && $user_ids[0] === (int) $of_beta[0]['user_id'] && 2 === (int) $of_beta[0]['follows'], 'Filtering readers by fighter hid the rest of what they follow.' );
	assert_followers( 1 === count( $report->fighters( array( 'user_id' => $user_ids[1] ) ) ), 'Filtering fighters by reader is wrong.' );

	$pairs = $report->export( 'pairs', array( 'search' => $suffix ) );
	assert_followers( 3 === count( $pairs['rows'] ) && 6 === count( $pairs['headers'] ), 'The pair export is not one row per active follow.' );
	$csv = FollowersReport::csv( $pairs['headers'], $pairs['rows'] );
	assert_followers( str_starts_with( $csv, "\xEF\xBB\xBF" ) && str_contains( $csv, 'Email,Ime,Borac' ), 'The CSV has no byte order mark or header row.' );
	assert_followers( str_contains( $csv, "'=Followers Beta" ) && ! preg_match( '/(^|,)"?=Followers/m', $csv ), 'A formula-like name was exported as a live formula.' );
	assert_followers( 2 === count( $report->export( 'fighters', array( 'search' => $suffix ) )['rows'] ) && 11 === count( $report->export( 'users', array( 'search' => 'followers-' ) )['headers'] ), 'The fighter or reader export has the wrong shape.' );

	wp_set_current_user( (int) $admin );
	$_GET = array( 'page' => 'mmaf-followers', 's' => $suffix );
	ob_start(); FollowersPage::render(); $html = (string) ob_get_clean();
	assert_followers( str_contains( $html, 'Followers Alpha ' . $suffix ) && str_contains( $html, 'mmaf_followers_export' ) && str_contains( $html, 'view=users' ), 'The Followers admin page did not render its list and export.' );
	$_GET = array( 'page' => 'mmaf-followers', 'view' => 'users', 'fighter_id' => $fighter_ids[1] );
	ob_start(); FollowersPage::render(); $html = (string) ob_get_clean();
	assert_followers( str_contains( $html, 'followers-one-' . $suffix . '@example.test' ) && ! str_contains( $html, 'followers-two-' . $suffix . '@example.test' ) && str_contains( $html, 'Prikazani su pratioci borca' ), 'The follower list of one fighter is wrong.' );
	echo wp_json_encode( array( 'ok' => true, 'fighters' => count( $fighters ), 'pairs' => count( $pairs['rows'] ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	if ( ob_get_level() > 0 && ! isset( $html ) ) { ob_end_clean(); }
	$_GET = $saved_get; wp_set_current_user( 0 );
	$wpdb->delete( $n['subscriptions'], array( 'user_id' => 987654321 ) );
	if ( $user_ids ) { $ids = implode( ',', array_map( 'intval', $user_ids ) ); foreach ( array( 'items', 'messages', 'reminders', 'subscriptions', 'tokens' ) as $table ) { $wpdb->query( "DELETE FROM {$n[$table]} WHERE user_id IN ($ids)" ); } require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $user_ids as $id ) { wp_delete_user( $id ); } }
	foreach ( $fighter_ids as $id ) { $wpdb->delete( $canonical['fighters'], array( 'id' => $id ) ); }
	foreach ( $post_ids as $id ) { wp_delete_post( $id, true ); }
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
}
