<?php
/**
 * Read-only impact report for the Tapology identity ranking gate.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/diagnose-tapology-ranking-gate.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;

global $wpdb;

$tables = Schema::table_names();

$rankable_where = "f.deleted_soft = 0 AND f.is_rankable = 1 AND f.rankability_status = 'rankable'";
$valid_source_exists = "
	EXISTS (
		SELECT 1
		FROM {$tables['fighter_sources']} fs
		WHERE fs.fighter_id = f.id
			AND fs.source_type = 'tapology'
			AND fs.source_url IS NOT NULL
			AND fs.source_url <> ''
			AND fs.identity_hash IS NOT NULL
			AND fs.identity_hash <> ''
		LIMIT 1
	)";
$tapology_source_exists = "
	EXISTS (
		SELECT 1
		FROM {$tables['fighter_sources']} fs_any
		WHERE fs_any.fighter_id = f.id
			AND fs_any.source_type = 'tapology'
		LIMIT 1
	)";
$malformed_source_exists = "
	EXISTS (
		SELECT 1
		FROM {$tables['fighter_sources']} fs_bad
		WHERE fs_bad.fighter_id = f.id
			AND fs_bad.source_type = 'tapology'
			AND (
				fs_bad.source_url IS NULL
				OR fs_bad.source_url = ''
				OR fs_bad.identity_hash IS NULL
				OR fs_bad.identity_hash = ''
			)
		LIMIT 1
	)";

$count = static function ( string $sql ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
};

$sample = static function ( string $where ) use ( $wpdb, $tables, $rankable_where ): array {
	$rows = $wpdb->get_results(
		"
		SELECT f.id, f.display_name, f.status, f.rankability_status, f.is_public, f.is_rankable
		FROM {$tables['fighters']} f
		WHERE {$rankable_where}
			AND {$where}
		ORDER BY f.id ASC
		LIMIT 10
		", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : array();
};

$board_impact = static function ( string $table, string $where, array $args = array() ) use ( $wpdb, $tables, $valid_source_exists ): array {
	$prepared_where = $where;
	if ( ! empty( $args ) ) {
		$prepared_where = $wpdb->prepare( $where, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	$rows = $wpdb->get_results(
		"
		SELECT
			r.board_key,
			COUNT(*) AS before_count,
			SUM(CASE WHEN {$valid_source_exists} THEN 1 ELSE 0 END) AS after_count,
			SUM(CASE WHEN {$valid_source_exists} THEN 0 ELSE 1 END) AS removed_count
		FROM {$table} r
		INNER JOIN {$tables['fighters']} f ON f.id = r.fighter_id
		WHERE {$prepared_where}
		GROUP BY r.board_key
		ORDER BY CASE WHEN r.board_key = 'overall' THEN 0 ELSE 1 END, r.board_key ASC
		", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	$impact = array();
	foreach ( (array) $rows as $row ) {
		$impact[ (string) $row['board_key'] ] = array(
			'before_count'  => (int) $row['before_count'],
			'after_count'   => (int) $row['after_count'],
			'removed_count' => (int) $row['removed_count'],
		);
	}

	return $impact;
};

$active_run_id = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT state_value FROM {$tables['system_state']} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'active_ranking_run_id'
	)
);
$latest_run_id = (int) $wpdb->get_var( "SELECT id FROM {$tables['ranking_runs']} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$report = array(
	'ok' => true,
	'valid_mapping_definition' => array(
		'source_type' => 'tapology',
		'source_url' => 'non-empty canonical source URL',
		'identity_hash' => 'non-empty source URL identity hash',
		'fighter_id' => 'matches canonical fighter id',
		'soft_delete_supported' => false,
		'audited_rankable_override_supported' => false,
	),
	'rankable_fighters' => array(
		'total_rankable' => $count( "SELECT COUNT(*) FROM {$tables['fighters']} f WHERE {$rankable_where}" ),
		'with_valid_tapology_mapping' => $count( "SELECT COUNT(*) FROM {$tables['fighters']} f WHERE {$rankable_where} AND {$valid_source_exists}" ),
		'missing_tapology_mapping' => $count( "SELECT COUNT(*) FROM {$tables['fighters']} f WHERE {$rankable_where} AND NOT {$tapology_source_exists}" ),
		'malformed_or_empty_tapology_mapping' => $count( "SELECT COUNT(DISTINCT f.id) FROM {$tables['fighters']} f WHERE {$rankable_where} AND {$tapology_source_exists} AND NOT {$valid_source_exists} AND {$malformed_source_exists}" ),
	),
	'samples' => array(
		'missing_tapology_mapping' => $sample( "NOT {$tapology_source_exists}" ),
		'malformed_or_empty_tapology_mapping' => $sample( "{$tapology_source_exists} AND NOT {$valid_source_exists} AND {$malformed_source_exists}" ),
	),
	'active_current_board_impact' => array(
		'active_ranking_run_id' => $active_run_id > 0 ? $active_run_id : null,
		'boards' => $active_run_id > 0
			? $board_impact( $tables['ranking_current'], 'r.ranking_run_id = %d', array( $active_run_id ) )
			: array(),
	),
	'latest_snapshot_board_impact' => array(
		'latest_ranking_run_id' => $latest_run_id > 0 ? $latest_run_id : null,
		'boards' => $latest_run_id > 0
			? $board_impact( $tables['ranking_snapshots'], 'r.ranking_run_id = %d', array( $latest_run_id ) )
			: array(),
	),
);

echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . PHP_EOL;
