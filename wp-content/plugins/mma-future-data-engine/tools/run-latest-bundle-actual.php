<?php
/**
 * Run ScraperLatestBundleService::import_bundle from CLI.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-latest-bundle-actual.php [path] [--allow-not-ready]
 *
 * Default path: scraper/data/latest relative to this LocalWP site.
 *
 * Writes canonical/source/import/review rows. Does not rebuild stats, calculate
 * rankings, activate rankings, link likely matches, or promote fighters.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\Import\ScraperLatestBundleService;

$default_path = dirname( __DIR__, 6 ) . '/scraper/data/latest';
$path = $argv[1] ?? $default_path;
$allow_not_ready = in_array( '--allow-not-ready', $argv, true );

if ( '--allow-not-ready' === $path ) {
	$path = $default_path;
}

if ( ! is_dir( $path ) ) {
	fwrite( STDERR, "Missing latest bundle directory at {$path}\n" );
	exit( 2 );
}

try {
	$result = ( new ScraperLatestBundleService() )->import_bundle( $path, 0, $allow_not_ready );
} catch ( \Throwable $error ) {
	echo wp_json_encode(
		array(
			'path' => $path,
			'is_valid' => false,
			'import_blocked' => true,
			'error' => $error->getMessage(),
			'allow_not_ready' => $allow_not_ready,
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 1 );
}

$summary = (array) ( $result['summary'] ?? array() );
$bundle_summary = (array) ( $result['bundle_summary'] ?? array() );
$profile_summary = (array) ( $result['profile_enrichment']['summary'] ?? array() );
$manual_review = (array) ( $result['manual_review_import'] ?? array() );

echo wp_json_encode(
	array(
		'path' => $path,
		'is_valid' => (bool) ( $result['is_valid'] ?? false ),
		'bundle_ready_for_import' => (bool) ( $bundle_summary['ready_for_import'] ?? false ),
		'import_summary' => array(
			'import_run_id' => (int) ( $summary['import_run_id'] ?? 0 ),
			'status' => (string) ( $summary['status'] ?? '' ),
			'events_created' => (int) ( $summary['events_created'] ?? 0 ),
			'events_updated' => (int) ( $summary['events_updated'] ?? 0 ),
			'events_no_change' => (int) ( $summary['events_no_change'] ?? 0 ),
			'events_needs_review_conflict' => (int) ( $summary['events_needs_review_conflict'] ?? 0 ),
			'fighters_created_provisional' => (int) ( $summary['fighters_created_provisional'] ?? 0 ),
			'fighters_exact_matched' => (int) ( $summary['fighters_exact_matched'] ?? 0 ),
			'fighters_likely_match_skipped' => (int) ( $summary['fighters_likely_match_skipped'] ?? 0 ),
			'bouts_created' => (int) ( $summary['bouts_created'] ?? 0 ),
			'bouts_updated' => (int) ( $summary['bouts_updated'] ?? 0 ),
			'bouts_no_change' => (int) ( $summary['bouts_no_change'] ?? 0 ),
			'bouts_needs_review_conflict' => (int) ( $summary['bouts_needs_review_conflict'] ?? 0 ),
			'participants_created_updated' => (int) ( $summary['participants_created_updated'] ?? 0 ),
			'profile_enrichment_status' => (string) ( $profile_summary['status'] ?? '' ),
			'profile_fields_applied' => (int) ( $profile_summary['fields_applied_total'] ?? 0 ),
			'manual_review_items_upserted' => (int) ( $manual_review['items_upserted'] ?? 0 ),
			'stats_rebuilt' => false,
			'rankings_recalculated' => false,
			'rankings_activated' => false,
		),
		'blocking_issues' => (array) ( $bundle_summary['blocking_issues'] ?? array() ),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( ! empty( $result['is_valid'] ) ? 0 : 1 );
