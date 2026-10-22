<?php

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname(__DIR__, 2) . '/wp-load.php';
}

use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
use MMAF\DataEngine\Services\Upcoming\CalendarService;
use MMAF\DataEngine\Services\Upcoming\Editor;
use MMAF\DataEngine\Services\Upcoming\Importer;
use MMAF\DataEngine\Services\Upcoming\Repository;
use MMAF\DataEngine\Services\Upcoming\Schema;

if ( ! class_exists( ZipArchive::class ) ) {
	throw new RuntimeException( 'ZipArchive is required.' );
}

global $wpdb;
$tables = Schema::tables();
$canonical = CanonicalSchema::table_names();
$actor = 1;
$today = new DateTimeImmutable( 'today', new DateTimeZone( 'Europe/Belgrade' ) );
$event_date = $today->modify( '+2 days' )->format( 'Y-m-d' );
$manual_date = $today->modify( '+40 days' )->format( 'Y-m-d' );
$run = 'calendar-test-' . gmdate( 'YmdHis' );
$event_stable = 'test_event_' . wp_generate_uuid4();
$bout_stable = 'test_bout_' . wp_generate_uuid4();
$created_event_ids = array();
$bundle_hashes = array();
$before = array(
	'fighters' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$canonical['fighters']}" ),
	'events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$canonical['events']}" ),
	'bouts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$canonical['bouts']}" ),
	'ranking' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$canonical['ranking_current']}" ),
);

function make_calendar_bundle( array $document ): string {
	$run_manifest = array( 'schema_version' => 'mmaf_upcoming_run_v1', 'run_id' => $document['run_id'], 'status' => 'success', 'coverage' => $document['coverage'], 'counts' => $document['counts'] );
	$document_json = wp_json_encode( $document, JSON_PRETTY_PRINT );
	$run_json = wp_json_encode( $run_manifest, JSON_PRETTY_PRINT );
	$manifest = array(
		'format' => 'mmaf-upcoming-bundle', 'format_version' => 1, 'mode' => 'upcoming', 'contract' => 'mmaf_upcoming_v1',
		'run_id' => $document['run_id'], 'coverage' => $document['coverage'], 'counts' => $document['counts'],
		'files' => array(
			'upcoming.json' => array( 'bytes' => strlen( $document_json ), 'sha256' => hash( 'sha256', $document_json ) ),
			'run_manifest.json' => array( 'bytes' => strlen( $run_json ), 'sha256' => hash( 'sha256', $run_json ) ),
		),
	);
	$path = tempnam( sys_get_temp_dir(), 'mmaf-calendar-' ) . '.zip';
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'latest/upcoming.json', $document_json );
	$zip->addFromString( 'latest/run_manifest.json', $run_json );
	$zip->addFromString( 'latest/package_manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
	$zip->close();
	return $path;
}

function assert_calendar( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

try {
	$document = array(
		'schema_version' => 'mmaf_upcoming_v1', 'source' => 'tapology', 'run_id' => $run, 'collected_at' => gmdate( 'c' ),
		'coverage' => array( 'from' => $today->format( 'Y-m-d' ), 'to' => $today->modify( '+9 days' )->format( 'Y-m-d' ), 'timezone' => 'Europe/Belgrade', 'requested_days' => 10, 'pagination_complete' => true, 'events_complete' => true, 'complete' => true ),
		'events' => array(
			array(
				'stable_id' => $event_stable, 'source_event_id' => $event_stable, 'source_url' => 'https://www.tapology.com/fightcenter/events/999999-calendar-test',
				'title' => 'Source Test Event', 'promotion' => 'Test Promotion', 'date' => $event_date, 'start_utc' => $event_date . 'T18:00:00Z',
				'source_time_text' => '20:00', 'source_timezone' => 'Europe/Belgrade', 'time_note' => null, 'venue' => 'Source Hall', 'location' => 'Belgrade, Serbia',
				'status' => 'scheduled', 'source_observed_at' => gmdate( 'c' ), 'full_card_read' => true,
				'bouts' => array(
					array(
						'stable_id' => $bout_stable, 'source_bout_id' => $bout_stable, 'source_url' => 'https://www.tapology.com/fightcenter/bouts/999999-calendar-test',
						'order' => 1, 'weight_class' => 'Lightweight', 'status' => 'scheduled', 'scope_status' => 'confirmed', 'scope_evidence' => 'test',
						'fighter_a' => array( 'name' => 'Test Fighter A', 'source_fighter_id' => 'test_fighter_a', 'source_url' => null ),
						'fighter_b' => array( 'name' => 'Test Fighter B', 'source_fighter_id' => 'test_fighter_b', 'source_url' => null ),
					),
				),
			),
		),
		'counts' => array( 'events' => 1, 'bouts' => 1 ),
	);
	$path = make_calendar_bundle( $document );
	$importer = new Importer();
	$package = $importer->inspect_zip( $path );
	$bundle_hashes[] = $package['bundle_hash'];
	$preview = $importer->preview( $package );
	assert_calendar( 1 === $preview['events']['new'] && 1 === $preview['bouts']['new'], 'Preview did not classify the fixture as new.' );
	$result = $importer->apply( $package, $actor );
	assert_calendar( empty( $result['idempotent'] ), 'First import was incorrectly idempotent.' );
	$repeat = $importer->apply( $package, $actor );
	assert_calendar( ! empty( $repeat['idempotent'] ), 'Repeated package was not idempotent.' );
	$event = ( new Repository() )->find_by_stable_id( $event_stable );
	assert_calendar( $event && 'published' === $event['status'], 'Imported event is not public.' );
	$created_event_ids[] = (int) $event['id'];

	( new Editor() )->save_event(
		array( 'event_id' => $event['id'], 'title' => 'Editorial Event Title', 'promotion' => $event['promotion'], 'event_date' => $event['event_date'], 'source_time_text' => $event['source_time_text'], 'source_timezone' => $event['source_timezone'], 'venue' => '', 'location' => $event['location'], 'source_url' => $event['source_url'], 'status' => 'published', 'scope_confirmed' => 1, 'bouts' => '' ),
		$actor
	);
	$document['run_id'] .= '-newer';
	$document['collected_at'] = gmdate( 'c', time() + 60 );
	$document['events'][0]['title'] = 'Changed Source Title';
	$document['events'][0]['venue'] = 'Changed Source Hall';
	$document['events'][0]['source_observed_at'] = gmdate( 'c', time() + 60 );
	$path2 = make_calendar_bundle( $document );
	$package2 = $importer->inspect_zip( $path2 );
	$bundle_hashes[] = $package2['bundle_hash'];
	$importer->apply( $package2, $actor );
	$event = ( new Repository() )->find_by_stable_id( $event_stable );
	assert_calendar( 'Editorial Event Title' === $event['title'], 'A source refresh overwrote the manual title.' );
	assert_calendar( null === $event['venue'], 'An intentionally empty manual value was not preserved.' );

	$manual_id = ( new Editor() )->save_event(
		array( 'title' => 'Manual Future Event', 'promotion' => 'Manual Promotion', 'event_date' => $manual_date, 'source_time_text' => '19:30', 'source_timezone' => 'Europe/Belgrade', 'venue' => 'Manual Hall', 'location' => 'Novi Sad, Serbia', 'status' => 'published', 'scope_confirmed' => 1, 'bouts' => 'Manual A | Manual B | Welterweight' ),
		$actor
	);
	$created_event_ids[] = $manual_id;
	$day = ( new CalendarService() )->day( $manual_date, 'Europe/Belgrade' );
	assert_calendar( 'manual' === $day['state'] && 1 === count( $day['events'] ), 'Manual event outside coverage is not selectable.' );
	assert_calendar( 'Raspored može biti dopunjen.' === $day['message'], 'Manual uncovered day lacks the supplementation note.' );

	$after = array(
		'fighters' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$canonical['fighters']}" ),
		'events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$canonical['events']}" ),
		'bouts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$canonical['bouts']}" ),
		'ranking' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$canonical['ranking_current']}" ),
	);
	assert_calendar( $before === $after, 'Calendar work changed canonical sports data.' );
	echo wp_json_encode( array( 'ok' => true, 'preview' => $preview, 'first_import' => $result, 'repeat_import' => $repeat, 'canonical_counts' => $after ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	if ( $created_event_ids ) {
		$ids = implode( ',', array_map( 'intval', $created_event_ids ) );
		$wpdb->query( "DELETE FROM {$tables['bouts']} WHERE event_id IN ($ids)" );
		$wpdb->query( "DELETE FROM {$tables['audit']} WHERE entity_type='event' AND entity_id IN ($ids)" );
		$wpdb->query( "DELETE FROM {$tables['events']} WHERE id IN ($ids)" );
	}
	foreach ( $bundle_hashes as $hash ) {
		$wpdb->delete( $tables['imports'], array( 'bundle_hash' => $hash ) );
	}
	foreach ( array_filter( array( $path ?? null, $path2 ?? null ) ) as $file ) {
		@unlink( $file );
	}
	( new Repository() )->bump_revision();
}
