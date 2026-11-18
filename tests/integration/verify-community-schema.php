<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\Schema;

function assert_community_schema( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

assert_community_schema( Config::schema_ready() && get_option( 'mmaf_db_version' ) === MMAF_DB_VERSION, 'The migration did not reach the community schema.' );
assert_community_schema( version_compare( MMAF_DB_VERSION, Config::SCHEMA_VERSION, '>=' ) && version_compare( MMAF_DB_VERSION, Config::REPUTATION_SCHEMA_VERSION, '>=' ) && Config::reputation_schema_ready(), 'The plugin schema is older than the schema the community code needs.' );

$expected_unique = array(
	'profiles'       => array( 'profile_user' => array( 'user_id' ), 'profile_handle' => array( 'handle' ) ),
	'fan_lists'      => array( 'list_user_board' => array( 'user_id', 'board_key' ) ),
	'fan_list_items' => array( 'item_list_position' => array( 'list_id', 'position' ), 'item_list_fighter' => array( 'list_id', 'fighter_id' ) ),
	'ratings'        => array( 'rating_list_rater' => array( 'list_id', 'rater_user_id' ) ),
	'rep_events'     => array( 'event_user_dedupe' => array( 'user_id', 'dedupe_key' ) ),
	'comment_votes'  => array( 'vote_comment_user' => array( 'comment_id', 'user_id' ) ),
);
foreach ( Schema::tables() as $key => $table ) {
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	assert_community_schema( 'InnoDB' === $engine, "$table is missing or is not InnoDB." );
	$indexes = array();
	foreach ( $wpdb->get_results( "SHOW INDEX FROM `$table`", ARRAY_A ) as $index ) {
		if ( 0 === (int) $index['Non_unique'] ) { $indexes[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name']; }
	}
	foreach ( $expected_unique[ $key ] as $name => $columns ) {
		$found = $indexes[ $name ] ?? array();
		ksort( $found );
		assert_community_schema( array_values( $found ) === $columns, "$table lost its unique key $name." );
	}
}

// A second migration must have nothing left to do; a statement dbDelta keeps "fixing" would rewrite the table on every release.
foreach ( Schema::sql() as $statement ) {
	$pending = dbDelta( $statement, false );
	assert_community_schema( array() === $pending, 'dbDelta still wants to change the community schema: ' . implode( ' | ', $pending ) );
}

// The unique keys are what make a handle and a list position belong to one owner.
$tables = Schema::tables();
$now    = gmdate( 'Y-m-d H:i:s' );
$handle = 'schema_' . strtolower( wp_generate_password( 8, false, false ) );
$quiet  = $wpdb->suppress_errors( true );
try {
	assert_community_schema( 1 === $wpdb->insert( $tables['profiles'], array( 'user_id' => 990000001, 'handle' => $handle, 'created_at' => $now, 'updated_at' => $now ) ), 'A profile row could not be inserted.' );
	assert_community_schema( false === $wpdb->insert( $tables['profiles'], array( 'user_id' => 990000002, 'handle' => $handle, 'created_at' => $now, 'updated_at' => $now ) ), 'Two profiles could share one handle.' );
	assert_community_schema( false === $wpdb->insert( $tables['profiles'], array( 'user_id' => 990000001, 'handle' => $handle . 'x', 'created_at' => $now, 'updated_at' => $now ) ), 'One reader could own two profiles.' );
	assert_community_schema( 1 === $wpdb->insert( $tables['fan_lists'], array( 'user_id' => 990000001, 'created_at' => $now, 'updated_at' => $now ) ), 'A fan list row could not be inserted.' );
	$list_id = (int) $wpdb->insert_id;
	assert_community_schema( 'overall' === $wpdb->get_var( $wpdb->prepare( "SELECT board_key FROM {$tables['fan_lists']} WHERE id=%d", $list_id ) ), 'A fan list does not default to the overall board.' );
	assert_community_schema( 1 === $wpdb->insert( $tables['fan_list_items'], array( 'list_id' => $list_id, 'position' => 1, 'fighter_id' => 7, 'picked_at' => $now ) ), 'A fan list item could not be inserted.' );
	assert_community_schema( false === $wpdb->insert( $tables['fan_list_items'], array( 'list_id' => $list_id, 'position' => 1, 'fighter_id' => 8, 'picked_at' => $now ) ), 'Two fighters could hold one position.' );
	assert_community_schema( false === $wpdb->insert( $tables['fan_list_items'], array( 'list_id' => $list_id, 'position' => 2, 'fighter_id' => 7, 'picked_at' => $now ) ), 'One fighter could hold two positions.' );
	assert_community_schema( 1 === $wpdb->insert( $tables['ratings'], array( 'list_id' => $list_id, 'owner_user_id' => 990000001, 'rater_user_id' => 990000002, 'value' => 3, 'created_at' => $now, 'updated_at' => $now ) ), 'A rating could not be inserted.' );
	assert_community_schema( false === $wpdb->insert( $tables['ratings'], array( 'list_id' => $list_id, 'owner_user_id' => 990000001, 'rater_user_id' => 990000002, 'value' => 1, 'created_at' => $now, 'updated_at' => $now ) ), 'One reader could rate one list twice.' );
	assert_community_schema( 1 === $wpdb->insert( $tables['rep_events'], array( 'user_id' => 990000001, 'kind' => 'profile', 'dedupe_key' => 'profile_complete', 'points' => 10, 'created_at' => $now ) ) && false === $wpdb->insert( $tables['rep_events'], array( 'user_id' => 990000001, 'kind' => 'profile', 'dedupe_key' => 'profile_complete', 'points' => 10, 'created_at' => $now ) ), 'A one-off award could be stored twice.' );
	assert_community_schema( 1 === $wpdb->insert( $tables['comment_votes'], array( 'comment_id' => 990000009, 'user_id' => 990000002, 'author_user_id' => 990000001, 'value' => -1, 'created_at' => $now, 'updated_at' => $now ) ) && -1 === (int) $wpdb->get_var( "SELECT value FROM {$tables['comment_votes']} WHERE comment_id=990000009" ), 'A comment vote cannot hold a down vote.' );
	echo wp_json_encode( array( 'ok' => true, 'tables' => array_values( $tables ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	$wpdb->suppress_errors( $quiet );
	$wpdb->query( "DELETE i FROM {$tables['fan_list_items']} i JOIN {$tables['fan_lists']} l ON l.id=i.list_id WHERE l.user_id IN (990000001,990000002)" );
	$wpdb->query( "DELETE FROM {$tables['fan_lists']} WHERE user_id IN (990000001,990000002)" );
	$wpdb->query( "DELETE FROM {$tables['profiles']} WHERE user_id IN (990000001,990000002)" );
	$wpdb->query( "DELETE FROM {$tables['ratings']} WHERE owner_user_id IN (990000001,990000002)" );
	$wpdb->query( "DELETE FROM {$tables['rep_events']} WHERE user_id IN (990000001,990000002)" );
	$wpdb->query( "DELETE FROM {$tables['comment_votes']} WHERE comment_id=990000009" );
}
