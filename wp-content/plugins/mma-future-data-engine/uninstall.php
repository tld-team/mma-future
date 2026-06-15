<?php
/**
 * Conservative uninstall for MMA Future Data Engine.
 *
 * Tables are preserved unless MMAF_ALLOW_UNINSTALL_DROP is explicitly defined
 * as true outside the plugin, for example in wp-config.php.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'MMAF_ALLOW_UNINSTALL_DROP' ) || true !== MMAF_ALLOW_UNINSTALL_DROP ) {
	return;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'mmaf_fighters',
	$wpdb->prefix . 'mmaf_fighter_sources',
	$wpdb->prefix . 'mmaf_fighter_aliases',
	$wpdb->prefix . 'mmaf_events',
	$wpdb->prefix . 'mmaf_event_sources',
	$wpdb->prefix . 'mmaf_bouts',
	$wpdb->prefix . 'mmaf_bout_sources',
	$wpdb->prefix . 'mmaf_bout_participants',
	$wpdb->prefix . 'mmaf_field_provenance',
	$wpdb->prefix . 'mmaf_fighter_stats_current',
	$wpdb->prefix . 'mmaf_ranking_runs',
	$wpdb->prefix . 'mmaf_ranking_current',
	$wpdb->prefix . 'mmaf_ranking_snapshots',
	$wpdb->prefix . 'mmaf_source_import_runs',
	$wpdb->prefix . 'mmaf_source_import_items',
	$wpdb->prefix . 'mmaf_review_items',
	$wpdb->prefix . 'mmaf_audit_log',
	$wpdb->prefix . 'mmaf_system_state',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'mmaf_db_version' );
