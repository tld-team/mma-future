<?php
/**
 * Aggregate read-only Data Audit services + Record Comparison from CLI.
 *
 * Usage:
 *   [MMAF_CLI_DB_HOST=127.0.0.1:10030] php tools/run-data-audit.php
 *
 * Reads only. Writes no canonical data.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\Audit\BoutIntegrityAuditService;
use MMAF\DataEngine\Services\Audit\DataQualityReportService;
use MMAF\DataEngine\Services\Audit\FightHistoryCompletenessAuditService;
use MMAF\DataEngine\Services\Audit\FighterDuplicateAuditService;
use MMAF\DataEngine\Services\Audit\FighterEnrichmentAuditService;
use MMAF\DataEngine\Services\Audit\FighterIdentityAuditService;
use MMAF\DataEngine\Services\Audit\ProfileRecordComparisonService;

$out = array();

try {
	$identity = ( new FighterIdentityAuditService() )->build_report( 100 );
	$out['fighter_identity'] = $identity['summary'] ?? $identity;
} catch ( \Throwable $e ) {
	$out['fighter_identity_error'] = $e->getMessage();
}

try {
	$dupes = ( new FighterDuplicateAuditService() )->audit( 100 );
	$out['fighter_duplicates'] = is_array( $dupes ) && isset( $dupes['summary'] ) ? $dupes['summary'] : $dupes;
} catch ( \Throwable $e ) {
	$out['fighter_duplicates_error'] = $e->getMessage();
}

try {
	$bouts = ( new BoutIntegrityAuditService() )->audit();
	$out['bout_integrity'] = is_array( $bouts ) && isset( $bouts['summary'] ) ? $bouts['summary'] : $bouts;
} catch ( \Throwable $e ) {
	$out['bout_integrity_error'] = $e->getMessage();
}

try {
	$completeness = ( new FightHistoryCompletenessAuditService() )->summary();
	$out['fight_history_completeness'] = $completeness;
} catch ( \Throwable $e ) {
	$out['fight_history_completeness_error'] = $e->getMessage();
}

try {
	$enrichment = ( new FighterEnrichmentAuditService() )->summary();
	$out['fighter_enrichment'] = $enrichment;
} catch ( \Throwable $e ) {
	$out['fighter_enrichment_error'] = $e->getMessage();
}

try {
	$dq = ( new DataQualityReportService() )->build_report();
	$out['data_quality'] = is_array( $dq ) && isset( $dq['summary'] ) ? $dq['summary'] : $dq;
} catch ( \Throwable $e ) {
	$out['data_quality_error'] = $e->getMessage();
}

try {
	$record_path = ProfileRecordComparisonService::default_path();
	if ( is_file( $record_path ) ) {
		$rc = ( new ProfileRecordComparisonService() )->build_report( $record_path, array(), '', 50, 0 );
		$out['record_comparison'] = array(
			'path'    => $record_path,
			'summary' => $rc['summary'] ?? array(),
		);
	} else {
		$out['record_comparison'] = array(
			'path'    => $record_path,
			'message' => 'fighter_profiles.json not present at default path (expected if no enrichment run yet)',
		);
	}
} catch ( \Throwable $e ) {
	$out['record_comparison_error'] = $e->getMessage();
}

echo wp_json_encode( $out, JSON_PRETTY_PRINT ) . PHP_EOL;
