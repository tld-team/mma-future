<?php
/**
 * Run ScraperLatestBundleService::analyze_bundle from CLI.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-latest-bundle-dry-run.php [path]
 *
 * Default path: scraper/data/latest relative to this LocalWP site.
 *
 * Writes a dry-run row for the results import plan, but performs no canonical
 * writes. Exits 0 when the bundle is structurally valid, even if the latest
 * health gate says ready_for_import=false.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\Import\ScraperLatestBundleService;

$default_path = dirname( __DIR__, 6 ) . '/scraper/data/latest';
$path = $argv[1] ?? $default_path;
if ( ! is_dir( $path ) ) {
	fwrite( STDERR, "Missing latest bundle directory at {$path}\n" );
	exit( 2 );
}

$report = ( new ScraperLatestBundleService() )->analyze_bundle( $path, 0, true );
$summary = (array) ( $report['summary'] ?? array() );
$results_summary = (array) ( $report['results_dry_run']['summary'] ?? array() );
$profile_summary = (array) ( $report['profile_enrichment']['summary'] ?? array() );
$profile_match_counts = (array) ( $summary['profile_enrichment_match_counts'] ?? array() );

echo wp_json_encode(
	array(
		'path'             => $path,
		'is_valid'         => (bool) ( $report['is_valid'] ?? false ),
		'ready_for_import' => (bool) ( $report['ready_for_import'] ?? false ),
		'summary'          => array(
			'bundle_hash'                       => (string) ( $summary['bundle_hash'] ?? '' ),
			'bundle_errors_count'               => (int) ( $summary['bundle_errors_count'] ?? 0 ),
			'blocking_issues_count'             => (int) ( $summary['blocking_issues_count'] ?? 0 ),
			'event_run_status'                  => (string) ( $summary['event_run_status'] ?? '' ),
			'profile_run_status'                => (string) ( $summary['profile_run_status'] ?? '' ),
			'source_run_id'                     => (string) ( $summary['source_run_id'] ?? '' ),
			'events_total'                      => (int) ( $summary['events_total'] ?? 0 ),
			'bouts_total'                       => (int) ( $summary['bouts_total'] ?? 0 ),
			'profiles_total'                    => (int) ( $summary['profiles_total'] ?? 0 ),
			'profiles_success'                  => (int) ( $summary['profiles_success'] ?? 0 ),
			'manual_review_count'               => (int) ( $summary['manual_review_count'] ?? 0 ),
			'results_validation_errors_count'   => (int) ( $summary['results_validation_errors_count'] ?? 0 ),
			'results_conflicts_count'           => (int) ( $summary['results_conflicts_count'] ?? 0 ),
			'results_warnings_count'            => (int) ( $summary['results_warnings_count'] ?? 0 ),
			'results_unsupported_fields_count'  => (int) ( $summary['results_unsupported_fields_count'] ?? 0 ),
			'profile_matching_quality'          => (string) ( $profile_summary['matching_quality_good_enough_for_later_import'] ?? '' ),
			'profiles_matched_existing_canonical' => (int) ( $profile_match_counts['existing_canonical'] ?? 0 ),
			'profiles_matched_planned_import'   => (int) ( $profile_match_counts['planned_import'] ?? 0 ),
			'profiles_resolvable_total'         => (int) ( $profile_match_counts['total_resolvable'] ?? 0 ),
			'profiles_unmatched'                => (int) ( $profile_summary['profiles_unmatched'] ?? 0 ),
			'profiles_ambiguous'                => (int) ( $profile_summary['profiles_ambiguous'] ?? 0 ),
			'results_import_run_id'             => (int) ( $results_summary['import_run_id'] ?? 0 ),
		),
		'blocking_issues' => (array) ( $summary['blocking_issues'] ?? array() ),
		'bundle_errors'   => (array) ( $summary['bundle_errors'] ?? array() ),
		'changes'         => (array) ( $summary['changes'] ?? array() ),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( ! empty( $report['is_valid'] ) ? 0 : 1 );
