<?php
/**
 * Verify deterministic gender inference used by fighter profile enrichment.
 *
 * Usage:
 *   php tools/verify-profile-enrichment-gender-inference.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR );
}

require_once dirname( __DIR__ ) . '/includes/Services/Import/GenderInferenceService.php';

use MMAF\DataEngine\Services\Import\GenderInferenceService;

$service = new GenderInferenceService();
$cases = array(
	"Women's Flyweight" => 'female',
	'Strawweight' => 'female',
	'Light Heavyweight' => 'male',
	"Men's Lightweight" => 'male',
	'Flyweight' => null,
	'Bantamweight' => null,
	'Featherweight' => null,
	'Catchweight' => null,
	'' => null,
);

$results = array();
$failed = false;
foreach ( $cases as $weight_class => $expected ) {
	$result = $service->infer_from_weight_class( $weight_class );
	$actual = $result['gender'];
	$passed = $actual === $expected;
	$failed = $failed || ! $passed;
	$results[] = array(
		'weight_class' => $weight_class,
		'expected' => $expected,
		'actual' => $actual,
		'warning' => $result['warning'],
		'passed' => $passed,
	);
}

echo json_encode(
	array(
		'passed' => ! $failed,
		'results' => $results,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( $failed ? 1 : 0 );
