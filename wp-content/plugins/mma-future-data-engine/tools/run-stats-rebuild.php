<?php
/**
 * Run StatsRebuildService::rebuild_all from CLI.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-stats-rebuild.php
 *
 * Writes/refreshes wp_mmaf_fighter_stats_current. Does not touch rankings.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\StatsRebuildService;

global $wpdb;
$tables = Schema::table_names();

$summary = ( new StatsRebuildService() )->rebuild_all( 0, 'Phase 7 E2E stats rebuild' );

$active_fighters = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$tables['fighters']} WHERE deleted_soft = 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$stats_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighter_stats_current']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$bouts_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['bouts']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$participants_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['bout_participants']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$same_fighter_bouts = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE fighter_id IS NOT NULL AND opponent_fighter_id IS NOT NULL AND fighter_id = opponent_fighter_id" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$missing_fighter_id = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE fighter_id IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$missing_opponent_fighter_id = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$tables['bout_participants']} WHERE opponent_fighter_id IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

$expected_participants = $bouts_count * 2;
$invariants_ok = (
	$stats_rows === $active_fighters
	&& $participants_count === $expected_participants
	&& 0 === $same_fighter_bouts
	&& 0 === $missing_fighter_id
	&& 0 === $missing_opponent_fighter_id
	&& 0 === (int) ( $summary['warnings']['malformed_bout_skipped'] ?? 0 )
);

echo wp_json_encode(
	array(
		'service_summary' => $summary,
		'integrity'       => array(
			'active_fighters'                   => $active_fighters,
			'fighter_stats_current_rows'        => $stats_rows,
			'stats_rows_match_active_fighters'  => $stats_rows === $active_fighters,
			'bouts'                             => $bouts_count,
			'bout_participants'                 => $participants_count,
			'participants_equal_bouts_times_2'  => $participants_count === $expected_participants,
			'same_fighter_bouts'                => $same_fighter_bouts,
			'missing_fighter_id_in_participants'=> $missing_fighter_id,
			'missing_opponent_fighter_id'       => $missing_opponent_fighter_id,
			'malformed_bout_skipped_summary'    => (int) ( $summary['warnings']['malformed_bout_skipped'] ?? 0 ),
			'all_invariants_ok'                 => $invariants_ok,
		),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( $invariants_ok ? 0 : 1 );
