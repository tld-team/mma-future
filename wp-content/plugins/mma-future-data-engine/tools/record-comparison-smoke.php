<?php
/**
 * Read-only smoke checks for profile record comparison.
 *
 * Usage: php tools/record-comparison-smoke.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

require_once __DIR__ . '/bootstrap-wp.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\Audit\ProfileRecordComparisonService;

global $wpdb;

$tables = Schema::table_names();
$checks = array();

function mmaf_record_comparison_counts(): array {
	global $wpdb, $tables;

	return array(
		'fighters'              => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighters']}" ),
		'fighter_sources'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighter_sources']}" ),
		'bouts'                 => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['bouts']}" ),
		'bout_participants'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['bout_participants']}" ),
		'fighter_stats_current' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['fighter_stats_current']}" ),
		'ranking_current'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ranking_current']}" ),
		'active_ranking_run_id' => (int) $wpdb->get_var( "SELECT id FROM {$tables['ranking_runs']} WHERE is_active = 1 LIMIT 1" ),
		'audit_log'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['audit_log']}" ),
		'field_provenance'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['field_provenance']}" ),
	);
}

$service = new ProfileRecordComparisonService();
$path = ProfileRecordComparisonService::default_path();
$before = mmaf_record_comparison_counts();

try {
	$report = $service->build_report( $path, array(), '', 50, 0 );
	$summary = (array) $report['summary'];
	$checks['valid_fighter_profiles_json_parses'] = (int) ( $summary['profiles_total'] ?? 0 ) > 0;
	$checks['matched_profile_comparison_exists'] = (int) ( $summary['profiles_matched'] ?? 0 ) > 0;
	$checks['record_gap_detected'] = (int) ( $summary['matched_fighters_profile_record_differs_from_canonical_stats'] ?? 0 ) > 0;
	$checks['fight_history_coverage_counted'] = (int) ( $summary['total_history_rows'] ?? 0 ) > 0;
	$checks['unmatched_profile_reported_not_linked'] = true;

	$unmatched_json = wp_json_encode(
		array(
			'schema_version' => 'tapology_fighter_profiles_v0_1',
			'source' => 'tapology',
			'run_id' => 'record_comparison_smoke',
			'scraped_at' => gmdate( 'c' ),
			'profiles' => array(
				array(
					'source_fighter_id' => 'tapology_fighter_999999997',
					'source_url' => 'https://www.tapology.com/fightcenter/fighters/999999997-record-comparison-smoke',
					'display_name' => 'Record Comparison Smoke Unmatched',
					'record_summary' => array(
						'pro_record_raw' => '7-1-0',
						'wins' => 7,
						'losses' => 1,
						'draws' => 0,
						'no_contests' => 0,
					),
					'fight_history' => array(),
				),
			),
		)
	);
	$unmatched = $service->analyze_json_string( (string) $unmatched_json );
	$checks['unmatched_profile_reported_not_linked'] = 'unmatched' === (string) ( $unmatched['rows'][0]['match_type'] ?? '' )
		&& 0 === (int) ( $unmatched['rows'][0]['matched_canonical_fighter_id'] ?? 0 );
} catch ( Throwable $error ) {
	$checks['valid_fighter_profiles_json_parses'] = false;
	$checks['matched_profile_comparison_exists'] = false;
	$checks['record_gap_detected'] = false;
	$checks['fight_history_coverage_counted'] = false;
	$checks['unmatched_profile_reported_not_linked'] = false;
}

try {
	$service->analyze_json_string( '{"schema_version":"unsupported","profiles":[]}' );
	$checks['unsupported_schema_blocked'] = false;
} catch ( Throwable $error ) {
	$checks['unsupported_schema_blocked'] = false !== strpos( $error->getMessage(), 'Unsupported fighter profile schema version' );
}

wp_set_current_user( 1 );
$_GET = array(
	'page' => 'mmaf-data-audit',
	'tab'  => 'record_comparison',
);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
\MMAF\DataEngine\Admin\DataAuditPage::render();
$html = ob_get_clean();
$after = mmaf_record_comparison_counts();

$checks['record_comparison_render'] = false !== strpos( $html, 'Record Comparison' )
	&& false !== strpos( $html, 'profile aggregate record is display/audit suggestion only' );
$checks['no_db_writes_from_service_or_render'] = $before === $after;

$failed = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );

echo wp_json_encode(
	array(
		'ok' => empty( $failed ),
		'checks' => $checks,
		'failed' => $failed,
		'path' => $path,
		'summary' => $summary ?? array(),
		'counts_before' => $before,
		'counts_after' => $after,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( empty( $failed ) ? 0 : 1 );
