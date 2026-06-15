<?php
/**
 * Read-only status report on canonical fighters and Tapology source mappings.
 *
 * Usage: php tools/verify-fighter-states.php
 *
 * No DB writes.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;

global $wpdb;
$tables = Schema::table_names();

$status_counts = $wpdb->get_results(
	"SELECT status, rankability_status, is_public, is_rankable, COUNT(*) AS n
	FROM {$tables['fighters']}
	GROUP BY status, rankability_status, is_public, is_rankable
	ORDER BY n DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ARRAY_A
);

$source_summary = $wpdb->get_results(
	"SELECT
		source_type,
		SUM(CASE WHEN source_fighter_id IS NOT NULL AND source_fighter_id <> '' THEN 1 ELSE 0 END) AS with_source_fighter_id,
		SUM(CASE WHEN (source_fighter_id IS NULL OR source_fighter_id = '') AND source_url IS NOT NULL AND source_url <> '' THEN 1 ELSE 0 END) AS url_only,
		SUM(CASE WHEN (source_fighter_id IS NULL OR source_fighter_id = '') AND (source_url IS NULL OR source_url = '') THEN 1 ELSE 0 END) AS missing_both,
		COUNT(*) AS n
	FROM {$tables['fighter_sources']}
	GROUP BY source_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ARRAY_A
);

$rin = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT f.id, f.display_name, f.status, f.rankability_status, f.is_public, f.is_rankable,
				fs.source_type, fs.source_fighter_id, fs.source_numeric_id, fs.source_url, fs.identity_hash
		FROM {$tables['fighters']} f
		LEFT JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id
		WHERE f.display_name = %s
		LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'Rin Nakai'
	),
	ARRAY_A
);

$rankings = $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ranking_current']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$active_run = $wpdb->get_var( "SELECT id FROM {$tables['ranking_runs']} WHERE is_active = 1 LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

echo wp_json_encode(
	array(
		'fighter_status_breakdown'    => $status_counts,
		'fighter_sources_summary'     => $source_summary,
		'rin_nakai'                   => $rin,
		'ranking_current_rows'        => (int) $rankings,
		'active_ranking_run_id'       => null === $active_run ? null : (int) $active_run,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;
