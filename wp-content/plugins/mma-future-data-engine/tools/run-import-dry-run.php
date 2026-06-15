<?php
/**
 * Run ScraperJsonDryRunService against a results.json file from CLI.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-import-dry-run.php [path]
 *
 * Default path: scraper/data/latest/results.json relative to this LocalWP site.
 *
 * Writes a dry-run row to wp_mmaf_source_import_runs (persist=true) but performs
 * no canonical writes. Exits 0 on `is_valid=true`, 1 otherwise.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\Import\ScraperJsonDryRunService;

$default_path = dirname( __DIR__, 6 ) . '/scraper/data/latest/results.json';
$path = $argv[1] ?? $default_path;
if ( ! is_file( $path ) ) {
	fwrite( STDERR, "Missing results.json at {$path}\n" );
	exit( 2 );
}

$report  = ( new ScraperJsonDryRunService() )->analyze_file( $path, 0, true );
$summary = (array) $report['summary'];

$fighters_breakdown = array();
foreach ( (array) ( $report['fighters'] ?? array() ) as $ref ) {
	$action = (string) ( $ref['action'] ?? 'unknown' );
	$fighters_breakdown[ $action ] = ( $fighters_breakdown[ $action ] ?? 0 ) + 1;
}

$url_only_sample = array();
foreach ( (array) ( $report['fighters'] ?? array() ) as $ref ) {
	if ( 'create_provisional_with_url_only_identity' === ( $ref['action'] ?? '' ) ) {
		$url_only_sample[] = array(
			'source_name'     => $ref['source_name'] ?? '',
			'source_url'      => $ref['source_url'] ?? '',
			'source_url_hash' => $ref['source_url_hash'] ?? '',
		);
		if ( count( $url_only_sample ) >= 3 ) {
			break;
		}
	}
}

$affected_existing_fighter_ids = array();
$existing_fighter_samples      = array();
foreach ( (array) ( $report['fighters'] ?? array() ) as $ref ) {
	$matched_fighter_id = (int) ( $ref['matched_fighter_id'] ?? 0 );
	if ( $matched_fighter_id <= 0 ) {
		continue;
	}

	$affected_existing_fighter_ids[ $matched_fighter_id ] = true;
	if ( count( $existing_fighter_samples ) < 10 ) {
		$existing_fighter_samples[] = array(
			'source_name'        => (string) ( $ref['source_name'] ?? '' ),
			'source_fighter_id'  => (string) ( $ref['source_fighter_id'] ?? '' ),
			'matched_fighter_id' => $matched_fighter_id,
			'matched_fighter'    => (string) ( $ref['matched_fighter'] ?? '' ),
			'action'             => (string) ( $ref['action'] ?? '' ),
		);
	}
}

$affected_existing_event_ids = array();
foreach ( (array) ( $report['events'] ?? array() ) as $event ) {
	$matched_event_id = (int) ( $event['matched_event_id'] ?? 0 );
	if ( $matched_event_id > 0 ) {
		$affected_existing_event_ids[ $matched_event_id ] = true;
	}
}

$affected_existing_bout_ids = array();
foreach ( (array) ( $report['bouts'] ?? array() ) as $bout ) {
	$matched_bout_id = (int) ( $bout['matched_bout_id'] ?? 0 );
	if ( $matched_bout_id > 0 ) {
		$affected_existing_bout_ids[ $matched_bout_id ] = true;
	}
}

$decoded                = json_decode( (string) file_get_contents( $path ), true );
$prefight_refs_total    = 0;
$prefight_refs_complete = 0;
if ( is_array( $decoded ) ) {
	foreach ( (array) ( $decoded['events'] ?? array() ) as $event ) {
		foreach ( (array) ( $event['bouts'] ?? array() ) as $bout ) {
			foreach ( array( 'fighter_a', 'fighter_b' ) as $role ) {
				++$prefight_refs_total;
				$record = (array) ( $bout[ $role ]['prefight_record'] ?? array() );
				if ( ! empty( $record ) && empty( $record['is_missing'] ) && isset( $record['wins'], $record['losses'], $record['draws'], $record['nc'] ) ) {
					++$prefight_refs_complete;
				}
			}
		}
	}
}

echo wp_json_encode(
	array(
		'path'      => $path,
		'is_valid'  => (bool) ( $report['is_valid'] ?? false ),
		'summary'   => array(
			'import_run_id'              => (int) ( $summary['import_run_id'] ?? 0 ),
			'schema_version'             => (string) ( $summary['schema_version'] ?? '' ),
			'source_run_id'              => (string) ( $summary['source_run_id'] ?? '' ),
			'events_total'               => (int) ( $summary['events_total'] ?? 0 ),
			'bouts_total'                => (int) ( $summary['bouts_total'] ?? 0 ),
			'fighter_refs_total'         => (int) ( $summary['fighter_refs_total'] ?? 0 ),
			'unique_fighter_refs'        => (int) ( $summary['unique_fighter_refs'] ?? 0 ),
			'validation_errors_count'    => (int) ( $summary['validation_errors_count'] ?? 0 ),
			'conflicts_count'            => (int) ( $summary['conflicts_count'] ?? 0 ),
			'warnings_count'             => (int) ( $summary['warnings_count'] ?? 0 ),
			'unsupported_fields_count'   => (int) ( $summary['unsupported_fields_count'] ?? 0 ),
			'non_scoring_bouts'          => (int) ( $summary['non_scoring_bouts'] ?? 0 ),
			'dry_run_only'               => (bool) ( $summary['dry_run_only'] ?? true ),
			'canonical_writes_performed' => (bool) ( $summary['canonical_writes_performed'] ?? false ),
		),
		'event_actions'    => (array) ( $summary['event_actions'] ?? array() ),
		'fighter_actions'  => (array) ( $summary['fighter_actions'] ?? array() ),
		'bout_actions'     => (array) ( $summary['bout_actions'] ?? array() ),
		'fighters_breakdown_per_ref' => $fighters_breakdown,
		'affected_existing_fighter_ids' => array_map( 'intval', array_keys( $affected_existing_fighter_ids ) ),
		'affected_existing_event_ids' => array_map( 'intval', array_keys( $affected_existing_event_ids ) ),
		'affected_existing_bout_ids' => array_map( 'intval', array_keys( $affected_existing_bout_ids ) ),
		'existing_fighter_resolution_samples' => $existing_fighter_samples,
		'prefight_record_coverage' => array(
			'fighter_refs_total' => $prefight_refs_total,
			'complete'           => $prefight_refs_complete,
			'missing'            => $prefight_refs_total - $prefight_refs_complete,
		),
		'url_only_samples' => $url_only_sample,
		'validation_errors'          => (array) ( $summary['validation_errors'] ?? array() ),
		'conflicts'                  => (array) ( $summary['conflicts'] ?? array() ),
		'warnings'                   => array_slice( (array) ( $summary['warnings'] ?? array() ), 0, 10 ),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( $report['is_valid'] ? 0 : 1 );
