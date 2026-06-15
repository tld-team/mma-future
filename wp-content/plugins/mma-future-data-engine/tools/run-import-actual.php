<?php
/**
 * Run ScraperJsonImportService::import_file against a results.json file from CLI.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-import-actual.php [path]
 *
 * Writes canonical/source/import rows. Does not rebuild stats, calculate
 * rankings, activate rankings, link likely matches, or promote fighters.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\Import\ScraperJsonImportService;

$default_path = dirname( __DIR__, 6 ) . '/scraper/data/latest/results.json';
$path = $argv[1] ?? $default_path;
if ( ! is_file( $path ) ) {
	fwrite( STDERR, "Missing results.json at {$path}\n" );
	exit( 2 );
}

global $wpdb;
$tables = Schema::table_names();

$snapshot = static function () use ( $wpdb, $tables ): array {
	$out = array();
	foreach ( $tables as $key => $table ) {
		$out[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	return $out;
};

$before = $snapshot();
$result = ( new ScraperJsonImportService() )->import_file( $path, 0 );
$after  = $snapshot();

$delta = array();
foreach ( $after as $key => $n ) {
	$delta[ $key ] = $n - ( $before[ $key ] ?? 0 );
}

$summary = (array) ( $result['summary'] ?? array() );

$import_run_id = (int) ( $result['import_run_id'] ?? ( $summary['import_run_id'] ?? 0 ) );
$run_row = $import_run_id > 0
	? $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, status, mode, dry_run, started_at, finished_at, error_message FROM {$tables['source_import_runs']} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$import_run_id
		),
		ARRAY_A
	)
	: null;

$item_status_breakdown = $import_run_id > 0
	? $wpdb->get_results(
		$wpdb->prepare(
			"SELECT item_type, status, action, COUNT(*) AS n FROM {$tables['source_import_items']} WHERE import_run_id = %d GROUP BY item_type, status, action ORDER BY item_type, status, action", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$import_run_id
		),
		ARRAY_A
	)
	: array();

$item_failures = $import_run_id > 0
	? (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE import_run_id = %d AND status IN ('error','failed')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$import_run_id
		)
	)
	: 0;

$needs_review = $import_run_id > 0
	? (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE import_run_id = %d AND status IN ('needs_review','likely_match_review')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$import_run_id
		)
	)
	: 0;

// URL-only provisional fighters created in this run (source row joined to fighter created by this run).
$url_only_created = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$tables['fighter_sources']} WHERE source_type = 'tapology' AND (source_fighter_id IS NULL OR source_fighter_id = '') AND identity_hash IS NOT NULL AND identity_hash <> ''" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

echo wp_json_encode(
	array(
		'path'              => $path,
		'import_run_id'     => $import_run_id,
		'run_row'           => $run_row,
		'service_summary'   => $summary,
		'delta_table_counts' => $delta,
		'before_table_counts' => $before,
		'after_table_counts'  => $after,
		'item_status_breakdown' => $item_status_breakdown,
		'item_failures_count'   => $item_failures,
		'needs_review_count'    => $needs_review,
		'url_only_provisional_fighters_total_now' => $url_only_created,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( $item_failures > 0 || ( $run_row && 'completed' !== ( $run_row['status'] ?? '' ) && 'success' !== ( $run_row['status'] ?? '' ) ) ? 1 : 0 );
