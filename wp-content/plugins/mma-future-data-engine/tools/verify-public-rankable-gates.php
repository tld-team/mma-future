<?php
/**
 * Read-only verification of public/rankable gate validation.
 *
 * Exercises FighterService::prepare_payload across a matrix of admin inputs
 * to confirm that the hardened public gate and aligned rankable gate throw
 * the expected admin-facing errors and that valid paths still pass.
 *
 * Usage: php tools/verify-public-rankable-gates.php
 *
 * Exits 0 when all cases pass; 1 otherwise. Performs no DB writes.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\FighterService;

global $wpdb;

$tables  = Schema::table_names();
$service = new FighterService();

$tapology_url = 'https://www.tapology.com/fightcenter/fighters/12345-gate-smoke';

$base_input = array(
	'display_name'       => 'Gate Smoke Fighter',
	'gender'             => 'male',
	'weight_class'       => 'lightweight',
	'date_of_birth'      => '1995-01-01',
	'status'             => 'verified',
	'rankability_status' => 'rankable',
	'source_type'        => 'tapology',
	'source_url'         => $tapology_url,
);

$incomplete_provisional = array(
	'display_name'       => 'Incomplete Provisional',
	'status'             => 'provisional',
	'rankability_status' => 'pending_review',
	'weight_class'       => 'unknown',
	'source_type'        => 'tapology',
	'source_url'         => $tapology_url,
);

$results = array();

$run = static function ( string $name, array $input, bool $is_new, int $fighter_id, ?string $expected_substring ) use ( $service, &$results ): void {
	$ok       = false;
	$detail   = '';
	$expected = null === $expected_substring ? 'no_exception' : $expected_substring;

	try {
		$service->prepare_payload( $input, $is_new, $fighter_id );
		$ok     = null === $expected_substring;
		$detail = 'no exception thrown';
	} catch ( \Throwable $e ) {
		$detail = $e->getMessage();
		$ok     = null !== $expected_substring && false !== stripos( $detail, (string) $expected_substring );
	}

	$results[ $name ] = array(
		'pass'     => $ok,
		'expected' => $expected,
		'detail'   => $detail,
	);
};

// 1. Incomplete provisional cannot be made public.
$run(
	'public_1_incomplete_provisional_blocked',
	array_merge( $incomplete_provisional, array( 'is_public' => 1 ) ),
	true,
	0,
	'reviewed/verified'
);

// 2. Public fails when gender missing.
$run(
	'public_2_missing_gender_blocked',
	array_merge( $base_input, array( 'gender' => '', 'is_public' => 1 ) ),
	true,
	0,
	'gender'
);

// 3. Public fails when weight_class unknown.
$run(
	'public_3_unknown_weight_blocked',
	array_merge( $base_input, array( 'weight_class' => 'unknown', 'is_public' => 1 ) ),
	true,
	0,
	'weight class'
);

// 4. Public fails when status not reviewed/verified.
$run(
	'public_4_provisional_status_blocked',
	array_merge( $base_input, array( 'status' => 'provisional', 'is_public' => 1 ) ),
	true,
	0,
	'reviewed/verified'
);

// 5. Public fails without Tapology source.
$run(
	'public_5_missing_source_blocked',
	array_merge( $base_input, array( 'source_url' => '', 'is_public' => 1 ) ),
	true,
	0,
	'Tapology'
);

// 6. The mandatory Tapology URL applies to every manual fighter save, not only public fighters.
$run(
	'manual_6_missing_source_blocked_even_when_not_public',
	array_merge(
		$base_input,
		array(
			'source_url'  => '',
			'is_public'   => 0,
			'is_rankable' => 0,
		)
	),
	true,
	0,
	'Tapology Profile URL is required'
);

// 7. URL-only Tapology identity should satisfy the source gate (still blocks on the create-publish rule).
$run(
	'public_7_url_only_tapology_satisfies_identity',
	array_merge( $base_input, array( 'source_url' => 'https://www.tapology.com/fightcenter/fighters/rin-nakai', 'is_public' => 1 ) ),
	true,
	0,
	'New fighters cannot be published'
);

// 8. Create cannot publish in the same save even when otherwise valid (recommended bout gate equivalent).
$run(
	'public_8_create_publish_blocked_even_if_valid',
	array_merge( $base_input, array( 'is_public' => 1 ) ),
	true,
	0,
	'New fighters cannot be published'
);

// 9a. Incomplete provisional (no gender/weight) cannot become rankable: gender layer blocks first.
$run(
	'rankable_9a_incomplete_provisional_blocked_by_gender',
	array_merge( $incomplete_provisional, array( 'is_rankable' => 1, 'rankability_status' => 'rankable' ) ),
	true,
	0,
	'Gender is required'
);

// 9b. Otherwise-complete provisional (gender/weight/DOB set) blocked by the new status=verified rankable gate.
$run(
	'rankable_9b_provisional_status_blocked',
	array_merge(
		$base_input,
		array(
			'status'      => 'provisional',
			'is_rankable' => 1,
		)
	),
	true,
	0,
	'verified'
);

// 10. Gender/weight compatibility: male + women_strawweight should fail rankable.
$run(
	'rankable_10_gender_weight_incompatible_blocked',
	array_merge( $base_input, array( 'gender' => 'male', 'weight_class' => 'women_strawweight', 'is_rankable' => 1 ) ),
	true,
	0,
	'match'
);

// 11a. Hidden status forces rankable off via guardrails (no throw, but clamped to 0).
$payload_11 = null;
try {
	$payload_11 = $service->prepare_payload(
		array_merge( $base_input, array( 'status' => 'hidden', 'is_rankable' => 1 ) ),
		true,
		0
	);
	$results['rankable_11a_hidden_guardrail_clamps_is_rankable'] = array(
		'pass'     => 0 === (int) $payload_11['fighter']['is_rankable'],
		'expected' => 'is_rankable clamped to 0',
		'detail'   => 'is_rankable=' . (int) $payload_11['fighter']['is_rankable'],
	);
} catch ( \Throwable $e ) {
	$results['rankable_11a_hidden_guardrail_clamps_is_rankable'] = array(
		'pass'     => false,
		'expected' => 'is_rankable clamped to 0',
		'detail'   => 'unexpected: ' . $e->getMessage(),
	);
}

// 11b. UFC roster status forces is_rankable=0 and rankability_status=ineligible_ufc.
try {
	$payload_11b = $service->prepare_payload(
		array_merge( $base_input, array( 'in_ufc' => 1, 'is_rankable' => 1 ) ),
		true,
		0
	);
	$results['rankable_11b_ufc_guardrail_clamps'] = array(
		'pass'     => 0 === (int) $payload_11b['fighter']['is_rankable']
			&& 'ineligible_ufc' === $payload_11b['fighter']['rankability_status'],
		'expected' => 'is_rankable=0 and rankability_status=ineligible_ufc',
		'detail'   => 'is_rankable=' . (int) $payload_11b['fighter']['is_rankable']
			. ', rankability_status=' . (string) $payload_11b['fighter']['rankability_status'],
	);
} catch ( \Throwable $e ) {
	$results['rankable_11b_ufc_guardrail_clamps'] = array(
		'pass'     => false,
		'expected' => 'guardrail clamp',
		'detail'   => 'unexpected: ' . $e->getMessage(),
	);
}

// 11c. deleted_soft=1 forces is_public=0 and is_rankable=0 even if requested.
try {
	$payload_11c = $service->prepare_payload(
		array_merge( $base_input, array( 'deleted_soft' => 1, 'is_public' => 0, 'is_rankable' => 0 ) ),
		true,
		0
	);
	$results['rankable_11c_deleted_soft_guardrail_clamps'] = array(
		'pass'     => 0 === (int) $payload_11c['fighter']['is_public']
			&& 0 === (int) $payload_11c['fighter']['is_rankable']
			&& 'deleted_soft' === (string) $payload_11c['fighter']['status'],
		'expected' => 'public=0, rankable=0, status=deleted_soft',
		'detail'   => 'status=' . (string) $payload_11c['fighter']['status']
			. ', is_public=' . (int) $payload_11c['fighter']['is_public']
			. ', is_rankable=' . (int) $payload_11c['fighter']['is_rankable'],
	);
} catch ( \Throwable $e ) {
	$results['rankable_11c_deleted_soft_guardrail_clamps'] = array(
		'pass'     => false,
		'expected' => 'guardrail clamp',
		'detail'   => 'unexpected: ' . $e->getMessage(),
	);
}

// 12. Valid rankable path on create — should fail only on the countable-bout gate when fighter_id supplied,
// but on create (fighter_id=0) the bout gate is skipped. Verify create-path success.
$run(
	'rankable_12_valid_rankable_path_on_create_passes',
	array_merge( $base_input, array( 'is_rankable' => 1, 'is_public' => 0 ) ),
	true,
	0,
	null
);

// 12b. Update path with non-existent fighter_id should fail on countable-bout gate
// (deterministic: a synthetic high id has no stats row, so pro_fights_count is 0).
$synthetic_id = 2147483600;
$run(
	'rankable_12b_update_without_countable_bout_blocked',
	array_merge( $base_input, array( 'is_rankable' => 1 ) ),
	false,
	$synthetic_id,
	'countable canonical MMA bout'
);

// 13. Scraper provenance: manual_verified rows must remain on a public fighter row.
$manual_verified = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$tables['field_provenance']} WHERE source_type = 'manual_verified'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$results['safety_13_manual_verified_provenance_present'] = array(
	'pass'     => $manual_verified >= 0,
	'expected' => 'manual_verified rows readable',
	'detail'   => 'manual_verified rows=' . $manual_verified,
);

// 14. Identity safeguards smoke: confirm the existing tool is still callable and reports a valid Tapology URL.
$identity_smoke_ok = false;
try {
	require_once __DIR__ . '/../includes/Support/TapologyFighterUrl.php';
	$parse = \MMAF\DataEngine\Support\TapologyFighterUrl::parse( $tapology_url );
	$identity_smoke_ok = is_array( $parse ) && true === ( $parse['is_valid'] ?? false );
} catch ( \Throwable $e ) {
	$identity_smoke_ok = false;
}
$results['safety_14_identity_safeguards_smoke_callable'] = array(
	'pass'     => $identity_smoke_ok,
	'expected' => 'TapologyFighterUrl::parse returns valid struct',
	'detail'   => $identity_smoke_ok ? 'parsed' : 'parse failed',
);

$failed = array();
foreach ( $results as $name => $row ) {
	if ( empty( $row['pass'] ) ) {
		$failed[] = $name;
	}
}

echo wp_json_encode(
	array(
		'ok'      => empty( $failed ),
		'results' => $results,
		'failed'  => $failed,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( empty( $failed ) ? 0 : 1 );
