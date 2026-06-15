<?php
/**
 * DESTRUCTIVE: Reset MMAF plugin-owned data and import a latest scraper bundle.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/reset-and-import-latest-bundle.php [path] [--yes-reset] [--allow-not-ready]
 *
 * Default path: scraper/data/latest relative to this LocalWP site.
 *
 * Without --yes-reset this prints a preflight/reset plan and exits without
 * canonical writes. Without --allow-not-ready this refuses to reset/import when
 * daily_summary.ready_for_import=false.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\Import\ScraperLatestBundleService;

global $wpdb;

$default_path = dirname( __DIR__, 6 ) . '/scraper/data/latest';
$path = $argv[1] ?? $default_path;
if ( in_array( $path, array( '--yes-reset', '--allow-not-ready' ), true ) ) {
	$path = $default_path;
}

$yes_reset = in_array( '--yes-reset', $argv, true );
$allow_not_ready = in_array( '--allow-not-ready', $argv, true );

if ( ! is_dir( $path ) ) {
	fwrite( STDERR, "Missing latest bundle directory at {$path}\n" );
	exit( 2 );
}

$service = new ScraperLatestBundleService();
$preflight = $service->analyze_bundle( $path, 0, false );
$bundle_summary = (array) ( $preflight['summary'] ?? array() );
$before_counts = mmaf_reset_import_counts();
$reset_plan = array(
	'truncate_tables' => array_values( Schema::table_names() ),
	'delete_post_type' => 'mmaf_fighter',
	'delete_postmeta_for_post_type' => 'mmaf_fighter',
	'delete_term_relationships_for_post_type' => 'mmaf_fighter',
);

if ( empty( $preflight['is_valid'] ) ) {
	echo wp_json_encode(
		array(
			'mode' => 'preflight_failed',
			'path' => $path,
			'preflight' => mmaf_reset_import_preflight_summary( $preflight ),
			'before_counts' => $before_counts,
			'reset_plan' => $reset_plan,
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 1 );
}

if ( empty( $preflight['ready_for_import'] ) && ! $allow_not_ready ) {
	echo wp_json_encode(
		array(
			'mode' => 'blocked_not_ready',
			'message' => 'Refusing to reset/import because latest bundle is not ready_for_import. Re-run with --allow-not-ready only for a deliberate manual-review import.',
			'path' => $path,
			'preflight' => mmaf_reset_import_preflight_summary( $preflight ),
			'before_counts' => $before_counts,
			'reset_plan' => $reset_plan,
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 1 );
}

if ( ! $yes_reset ) {
	echo wp_json_encode(
		array(
			'mode' => 'dry_run',
			'message' => 'Refusing to reset. Re-run with --yes-reset to apply.',
			'path' => $path,
			'allow_not_ready' => $allow_not_ready,
			'preflight' => mmaf_reset_import_preflight_summary( $preflight ),
			'before_counts' => $before_counts,
			'reset_plan' => $reset_plan,
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 0 );
}

$reset_result = mmaf_reset_import_apply_reset();
if ( empty( $reset_result['all_zero'] ) || ! empty( $reset_result['errors'] ) ) {
	echo wp_json_encode(
		array(
			'mode' => 'reset_failed',
			'path' => $path,
			'preflight' => mmaf_reset_import_preflight_summary( $preflight ),
			'reset' => $reset_result,
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 1 );
}

try {
	$import = $service->import_bundle( $path, 0, $allow_not_ready );
} catch ( \Throwable $error ) {
	echo wp_json_encode(
		array(
			'mode' => 'import_failed_after_reset',
			'path' => $path,
			'error' => $error->getMessage(),
			'preflight' => mmaf_reset_import_preflight_summary( $preflight ),
			'reset' => $reset_result,
			'after_counts' => mmaf_reset_import_counts(),
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 1 );
}

$summary = (array) ( $import['summary'] ?? array() );
$profile_summary = (array) ( $import['profile_enrichment']['summary'] ?? array() );
$manual_review = (array) ( $import['manual_review_import'] ?? array() );

echo wp_json_encode(
	array(
		'mode' => 'applied',
		'path' => $path,
		'allow_not_ready' => $allow_not_ready,
		'preflight' => mmaf_reset_import_preflight_summary( $preflight ),
		'reset' => $reset_result,
		'import_summary' => array(
			'import_run_id' => (int) ( $summary['import_run_id'] ?? 0 ),
			'status' => (string) ( $summary['status'] ?? '' ),
			'events_created' => (int) ( $summary['events_created'] ?? 0 ),
			'events_updated' => (int) ( $summary['events_updated'] ?? 0 ),
			'events_no_change' => (int) ( $summary['events_no_change'] ?? 0 ),
			'fighters_created_provisional' => (int) ( $summary['fighters_created_provisional'] ?? 0 ),
			'fighters_exact_matched' => (int) ( $summary['fighters_exact_matched'] ?? 0 ),
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
		'after_counts' => mmaf_reset_import_counts(),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( 0 );

function mmaf_reset_import_preflight_summary( array $preflight ): array {
	$summary = (array) ( $preflight['summary'] ?? array() );
	$profile_counts = (array) ( $summary['profile_enrichment_match_counts'] ?? array() );

	return array(
		'is_valid' => (bool) ( $preflight['is_valid'] ?? false ),
		'ready_for_import' => (bool) ( $preflight['ready_for_import'] ?? false ),
		'bundle_hash' => (string) ( $summary['bundle_hash'] ?? '' ),
		'bundle_errors_count' => (int) ( $summary['bundle_errors_count'] ?? 0 ),
		'blocking_issues' => (array) ( $summary['blocking_issues'] ?? array() ),
		'event_run_status' => (string) ( $summary['event_run_status'] ?? '' ),
		'profile_run_status' => (string) ( $summary['profile_run_status'] ?? '' ),
		'events_total' => (int) ( $summary['events_total'] ?? 0 ),
		'bouts_total' => (int) ( $summary['bouts_total'] ?? 0 ),
		'profiles_total' => (int) ( $summary['profiles_total'] ?? 0 ),
		'profiles_success' => (int) ( $summary['profiles_success'] ?? 0 ),
		'manual_review_count' => (int) ( $summary['manual_review_count'] ?? 0 ),
		'results_validation_errors_count' => (int) ( $summary['results_validation_errors_count'] ?? 0 ),
		'results_conflicts_count' => (int) ( $summary['results_conflicts_count'] ?? 0 ),
		'results_unsupported_fields_count' => (int) ( $summary['results_unsupported_fields_count'] ?? 0 ),
		'profiles_resolvable_total' => (int) ( $profile_counts['total_resolvable'] ?? 0 ),
		'profiles_unmatched' => (int) ( $profile_counts['unmatched'] ?? 0 ),
	);
}

function mmaf_reset_import_counts(): array {
	global $wpdb;

	$tables = Schema::table_names();
	$counts = array();
	foreach ( $tables as $key => $table ) {
		$counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$counts['__wp_posts_mmaf_fighter'] = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	$counts['__wp_postmeta_mmaf_fighter'] = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	$counts['__wp_term_relationships_mmaf_fighter'] = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	return $counts;
}

function mmaf_reset_import_apply_reset(): array {
	global $wpdb;

	$tables = Schema::table_names();
	$before = mmaf_reset_import_counts();
	$errors = array();

	$wpdb->query(
		"DELETE tr FROM {$wpdb->term_relationships} tr
		INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
		WHERE p.post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	$wpdb->query(
		"DELETE pm FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE p.post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	$wpdb->query(
		"DELETE FROM {$wpdb->posts} WHERE post_type = 'mmaf_fighter'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	foreach ( $tables as $table ) {
		$ok = $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $ok ) {
			$errors[] = "TRUNCATE failed: {$table} :: " . $wpdb->last_error;
		}
	}

	$after = mmaf_reset_import_counts();
	$all_zero = true;
	foreach ( $after as $n ) {
		if ( 0 !== (int) $n ) {
			$all_zero = false;
			break;
		}
	}

	return array(
		'all_zero' => $all_zero,
		'errors' => $errors,
		'before_counts' => $before,
		'after_counts' => $after,
	);
}
