<?php
/**
 * Read-only smoke checks for mixed fighter bulk selection behavior.
 *
 * This simulates ready/processable, blocked, and skipped/no-op fighters against
 * FighterReadinessService's bulk decision layer. It performs no database writes
 * and does not mutate real fighter data.
 *
 * Usage:
 *   php tools/verify-fighter-bulk-partial-safe.php
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

require_once __DIR__ . '/bootstrap-wp.php';

use MMAF\DataEngine\Services\FighterReadinessService;

$service = new FighterReadinessService();
$method  = new ReflectionMethod( FighterReadinessService::class, 'bulk_decision' );
$method->setAccessible( true );

$item = static function ( int $id, string $name, array $fighter_overrides = array(), array $item_overrides = array() ): array {
	$fighter = array_merge(
		array(
			'id'                 => $id,
			'display_name'       => $name,
			'status'             => 'verified',
			'rankability_status' => 'pending_review',
			'is_public'          => 0,
			'is_rankable'        => 0,
			'deleted_soft'       => 0,
			'gender'             => 'male',
			'weight_class'       => 'lightweight',
			'date_of_birth'      => '1998-01-01',
			'birth_year'         => 1998,
		),
		$fighter_overrides
	);

	return array_merge(
		array(
			'fighter'                    => $fighter,
			'has_tapology_source'        => true,
			'has_weight_class'           => true,
			'has_countable_bout_history' => true,
			'formula_blocker_codes'      => array(),
			'rankable_blocker_codes'     => array(),
		),
		$item_overrides
	);
};

$cases = array(
	'validate_selected_for_ranking' => array(
		'ready'   => $item( 10001, 'Ready Fighter' ),
		'blocked' => $item( 10002, 'Blocked Fighter', array(), array( 'rankable_blocker_codes' => array( 'missing_dob_or_birth_year' ) ) ),
		'skipped' => null,
		'expect'  => array( 'ready' => 1, 'blocked' => 1, 'skipped' => 0, 'update' => 0 ),
	),
	'mark_verified' => array(
		'ready'   => $item( 10003, 'Processable Fighter', array( 'status' => 'provisional' ) ),
		'blocked' => $item( 10004, 'Blocked Fighter', array( 'status' => 'provisional' ), array( 'has_tapology_source' => false ) ),
		'skipped' => $item( 10005, 'Already Verified' ),
		'expect'  => array( 'ready' => 0, 'blocked' => 1, 'skipped' => 1, 'update' => 1 ),
	),
	'mark_public' => array(
		'ready'   => $item( 10006, 'Processable Fighter' ),
		'blocked' => $item( 10007, 'Blocked Fighter', array( 'status' => 'provisional' ) ),
		'skipped' => $item( 10008, 'Already Public', array( 'is_public' => 1 ) ),
		'expect'  => array( 'ready' => 0, 'blocked' => 1, 'skipped' => 1, 'update' => 1 ),
	),
	'mark_rankable' => array(
		'ready'   => $item( 10009, 'Processable Fighter', array( 'is_public' => 1 ) ),
		'blocked' => $item( 10010, 'Blocked Fighter', array( 'is_public' => 1 ), array( 'rankable_blocker_codes' => array( 'missing_last_fight' ) ) ),
		'skipped' => $item( 10011, 'Already Rankable', array( 'is_public' => 1, 'is_rankable' => 1, 'rankability_status' => 'rankable' ) ),
		'expect'  => array( 'ready' => 0, 'blocked' => 1, 'skipped' => 1, 'update' => 1 ),
	),
	'mark_not_public' => array(
		'ready'   => $item( 10012, 'Processable Fighter', array( 'is_public' => 1, 'is_rankable' => 1, 'rankability_status' => 'rankable' ) ),
		'blocked' => null,
		'skipped' => $item( 10013, 'Already Not Public', array( 'is_public' => 0, 'is_rankable' => 0, 'rankability_status' => 'not_public' ) ),
		'expect'  => array( 'ready' => 0, 'blocked' => 0, 'skipped' => 1, 'update' => 1 ),
	),
	'mark_not_rankable' => array(
		'ready'   => $item( 10014, 'Processable Fighter', array( 'is_rankable' => 1, 'rankability_status' => 'rankable' ) ),
		'blocked' => null,
		'skipped' => $item( 10015, 'Already Not Rankable', array( 'is_rankable' => 0, 'rankability_status' => 'pending_review' ) ),
		'expect'  => array( 'ready' => 0, 'blocked' => 0, 'skipped' => 1, 'update' => 1 ),
	),
	'move_to_provisional' => array(
		'ready'   => $item( 10016, 'Processable Fighter', array( 'is_public' => 1, 'is_rankable' => 1, 'rankability_status' => 'rankable' ) ),
		'blocked' => null,
		'skipped' => $item( 10017, 'Already Provisional', array( 'status' => 'provisional', 'rankability_status' => 'pending_review', 'is_public' => 0, 'is_rankable' => 0 ) ),
		'expect'  => array( 'ready' => 0, 'blocked' => 0, 'skipped' => 1, 'update' => 1 ),
	),
);

$results = array();
foreach ( $cases as $action => $case ) {
	$counts = array( 'ready' => 0, 'blocked' => 0, 'skipped' => 0, 'update' => 0 );
	$unchanged_blocked = true;
	$unchanged_skipped = true;

	foreach ( array( 'ready', 'blocked', 'skipped' ) as $slot ) {
		if ( null === $case[ $slot ] ) {
			continue;
		}

		$before   = $case[ $slot ]['fighter'];
		$decision = $method->invoke( $service, $action, $case[ $slot ] );
		$status   = (string) $decision['status'];
		if ( isset( $counts[ $status ] ) ) {
			++$counts[ $status ];
		}

		$after = $before;
		if ( 'update' === $status ) {
			$after = array_merge( $after, (array) $decision['update'] );
		}
		if ( 'blocked' === $slot && $after !== $before ) {
			$unchanged_blocked = false;
		}
		if ( 'skipped' === $slot && $after !== $before ) {
			$unchanged_skipped = false;
		}
	}

	$pass = $counts === $case['expect'] && $unchanged_blocked && $unchanged_skipped;
	$results[ $action ] = array(
		'pass'              => $pass,
		'counts'            => $counts,
		'expected'          => $case['expect'],
		'blocked_unchanged' => $unchanged_blocked,
		'skipped_unchanged' => $unchanged_skipped,
	);
}

$failed = array_keys( array_filter( $results, static fn( array $result ): bool => ! $result['pass'] ) );

echo wp_json_encode(
	array(
		'ok'             => empty( $failed ),
		'mutates_db'     => false,
		'failed'         => $failed,
		'results'        => $results,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( empty( $failed ) ? 0 : 1 );
