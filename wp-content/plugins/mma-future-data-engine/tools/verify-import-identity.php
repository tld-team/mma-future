<?php
/**
 * Read-only verification of import identity logic.
 *
 * Runs ScraperJsonDryRunService against the current scraper/data/latest/results.json
 * and reports the fighter identity action breakdown. No DB writes.
 *
 * Usage: php tools/verify-import-identity.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\Import\ScraperJsonDryRunService;
use MMAF\DataEngine\Support\TapologyFighterUrl;

$results_path = dirname( __DIR__, 6 ) . '/scraper/data/latest/results.json';
if ( ! is_file( $results_path ) ) {
	fwrite( STDERR, "Missing results.json at {$results_path}\n" );
	exit( 2 );
}

$report = ( new ScraperJsonDryRunService() )->analyze_file( $results_path, 0, false );

$ref_actions = (array) ( $report['summary']['fighter_actions'] ?? array() );
$url_only_candidates = array();
$ambiguous = array();
$name_only_provisional = array();
$exact_id = array();
$exact_url_hash = array();
$likely_review = array();
foreach ( (array) ( $report['fighters'] ?? array() ) as $ref ) {
	$action = (string) ( $ref['action'] ?? 'unknown' );
	if ( 'create_provisional_with_url_only_identity' === $action ) {
		$url_only_candidates[] = $ref;
	}
	if ( 'ambiguous_source_url_hash' === $action ) {
		$ambiguous[] = $ref;
	}
	if ( 'create_provisional_candidate' === $action ) {
		$name_only_provisional[] = $ref;
	}
	if ( 'exact_source_match' === $action ) {
		$exact_id[] = $ref;
	}
	if ( 'exact_source_url_hash_match' === $action ) {
		$exact_url_hash[] = $ref;
	}
	if ( 'likely_match_review' === $action ) {
		$likely_review[] = $ref;
	}
}

echo wp_json_encode(
	array(
		'results_path'  => $results_path,
		'is_valid'      => (bool) ( $report['is_valid'] ?? false ),
		'summary'       => array(
			'dry_run_only'                => (bool) ( $report['summary']['dry_run_only'] ?? false ),
			'events_total'                => (int) ( $report['summary']['events_total'] ?? 0 ),
			'bouts_total'                 => (int) ( $report['summary']['bouts_total'] ?? 0 ),
			'fighter_refs_total'          => (int) ( $report['summary']['fighter_refs_total'] ?? 0 ),
			'fighter_refs_exact_match'    => (int) ( $report['summary']['fighter_refs_exact_match'] ?? 0 ),
			'fighter_refs_exact_url_hash' => (int) ( $report['summary']['fighter_refs_exact_url_hash'] ?? ( $report['summary']['fighters_exact_source_url_hash_matched'] ?? 0 ) ),
			'fighter_refs_likely_match'   => (int) ( $report['summary']['fighter_refs_likely_match'] ?? 0 ),
			'fighter_refs_needs_review'   => (int) ( $report['summary']['fighter_refs_needs_review'] ?? 0 ),
			'fighter_refs_unresolved'     => (int) ( $report['summary']['fighter_refs_unresolved'] ?? 0 ),
		),
		'ref_action_breakdown' => $ref_actions,
		'samples'       => array(
			'url_only_candidates_first'  => array_slice( $url_only_candidates, 0, 3 ),
			'ambiguous_first'            => array_slice( $ambiguous, 0, 3 ),
			'name_only_provisional_first' => array_slice( $name_only_provisional, 0, 3 ),
			'exact_id_first'             => array_slice( $exact_id, 0, 3 ),
			'exact_url_hash_first'       => array_slice( $exact_url_hash, 0, 3 ),
			'likely_match_review_first'  => array_slice( $likely_review, 0, 3 ),
		),
		'counts' => array(
			'exact_source_match'                          => count( $exact_id ),
			'exact_source_url_hash_match'                 => count( $exact_url_hash ),
			'likely_match_review'                         => count( $likely_review ),
			'create_provisional_candidate'                => count( $name_only_provisional ),
			'create_provisional_with_url_only_identity'   => count( $url_only_candidates ),
			'ambiguous_source_url_hash'                   => count( $ambiguous ),
		),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;
