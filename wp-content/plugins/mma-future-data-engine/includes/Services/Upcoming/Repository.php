<?php
namespace MMAF\DataEngine\Services\Upcoming;

use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {
	private array $tables;

	public function __construct() {
		$this->tables = Schema::tables();
	}

	public function find_event( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tables['events']} WHERE id=%d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['bouts'] = $this->bouts_for_events( array( $id ) )[ $id ] ?? array();
		return $row;
	}

	public function find_by_stable_id( string $stable_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tables['events']} WHERE stable_id=%s", $stable_id ), ARRAY_A );
		return $row ?: null;
	}

	public function admin_events( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->tables['events']} ORDER BY event_date DESC,id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array();
	}

	public function import_history( int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->tables['imports']} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array();
	}

	public function public_events_between( string $from, string $to ): array {
		global $wpdb;
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.* FROM {$this->tables['events']} e
				WHERE e.hidden=0 AND e.scope_confirmed=1 AND e.status IN ('published','scheduled','postponed')
				AND e.event_date BETWEEN %s AND %s
				AND EXISTS (SELECT 1 FROM {$this->tables['bouts']} b WHERE b.event_id=e.id AND b.hidden=0 AND b.scope_status='confirmed' AND b.status NOT IN ('cancelled','hidden'))
				ORDER BY e.event_date,e.event_datetime_utc IS NULL,e.event_datetime_utc,e.title",
				$from,
				$to
			),
			ARRAY_A
		) ?: array();
		$by_event = $this->bouts_for_events( array_map( 'intval', wp_list_pluck( $events, 'id' ) ), true );
		foreach ( $events as &$event ) {
			$event['bouts'] = $by_event[ (int) $event['id'] ] ?? array();
		}
		unset( $event );
		return $events;
	}

	public function bouts_for_events( array $event_ids, bool $public_only = false ): array {
		global $wpdb;
		$event_ids = array_values( array_unique( array_filter( array_map( 'intval', $event_ids ) ) ) );
		if ( ! $event_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
		$where = $public_only ? " AND hidden=0 AND scope_status='confirmed' AND status NOT IN ('cancelled','hidden')" : '';
		$sql = $wpdb->prepare( "SELECT * FROM {$this->tables['bouts']} WHERE event_id IN ($placeholders)$where ORDER BY event_id,bout_order,id", ...$event_ids );
		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array();
		$links = $this->public_fighter_links( $rows );
		$grouped = array();
		foreach ( $rows as $row ) {
			foreach ( array( 'a', 'b' ) as $side ) {
				$id = (int) ( $row[ "fighter_{$side}_id" ] ?? 0 );
				$row[ "fighter_{$side}_profile_url" ] = $id && isset( $links[ $id ] ) ? $links[ $id ] : null;
			}
			$grouped[ (int) $row['event_id'] ][] = $row;
		}
		return $grouped;
	}

	public function latest_coverage(): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM {$this->tables['imports']} WHERE status='completed' AND coverage_complete=1 ORDER BY finished_at DESC,id DESC LIMIT 1",
			ARRAY_A
		);
		return $row ?: null;
	}

	public function bump_revision(): int {
		$revision = (int) get_option( 'mmaf_calendar_revision', 0 ) + 1;
		update_option( 'mmaf_calendar_revision', $revision, false );
		return $revision;
	}

	private function public_fighter_links( array $bouts ): array {
		global $wpdb;
		$ids = array();
		foreach ( $bouts as $bout ) {
			$ids[] = (int) ( $bout['fighter_a_id'] ?? 0 );
			$ids[] = (int) ( $bout['fighter_b_id'] ?? 0 );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$fighters = CanonicalSchema::table_names()['fighters'];
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $wpdb->prepare(
			"SELECT f.id,f.wp_post_id FROM $fighters f JOIN {$wpdb->posts} p ON p.ID=f.wp_post_id WHERE f.id IN ($placeholders) AND f.is_public=1 AND p.post_status='publish'",
			...$ids
		);
		$links = array();
		foreach ( $wpdb->get_results( $sql, ARRAY_A ) ?: array() as $row ) {
			$links[ (int) $row['id'] ] = get_permalink( (int) $row['wp_post_id'] );
		}
		return $links;
	}
}
