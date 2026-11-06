<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EventChanges {
	private array $t;
	public function __construct() { $this->t = Schema::tables(); }

	public function record( ?array $before, array $after, string $source_type, ?int $import_id = null ): int {
		if ( version_compare( (string) get_option( 'mmaf_db_version', '0' ), '1.18.0', '<' ) ) { return 0; }
		$old = array();
		foreach ( (array) ( $before['bouts'] ?? array() ) as $bout ) { $old[ (string) $bout['stable_id'] ] = $bout; }
		$count = 0;
		foreach ( (array) ( $after['bouts'] ?? array() ) as $bout ) {
			$previous = $old[ (string) $bout['stable_id'] ] ?? null;
			foreach ( $this->classify( $before, $after, $previous, $bout ) as $type ) {
				if ( $this->insert( $type, $before, $after, $previous, $bout, $source_type, $import_id ) ) { ++$count; }
			}
		}
		return $count;
	}

	private function classify( ?array $old_event, array $event, ?array $old_bout, array $bout ): array {
		if ( ! $this->notifiable( $event, $bout ) && ! $old_bout ) { return array(); }
		if ( ! $old_bout ) { return $this->notifiable( $event, $bout ) ? array( 'new_bout' ) : array(); }
		$types = array();
		$old_cancelled = $this->cancelled( $old_event, $old_bout );
		$new_cancelled = $this->cancelled( $event, $bout );
		if ( ! $old_cancelled && $new_cancelled ) { $types[] = 'cancelled'; }
		$old_postponed = 'postponed' === ( $old_event['status'] ?? '' );
		$new_postponed = 'postponed' === ( $event['status'] ?? '' );
		if ( ! $new_cancelled && ! $old_postponed && $new_postponed ) { $types[] = 'postponed'; }
		if ( ! $new_cancelled && ( (string) ( $old_event['event_date'] ?? '' ) !== (string) $event['event_date'] || (string) ( $old_event['event_datetime_utc'] ?? '' ) !== (string) ( $event['event_datetime_utc'] ?? '' ) ) ) { $types[] = 'schedule_changed'; }
		if ( $this->participants( $old_bout ) !== $this->participants( $bout ) ) { $types[] = 'opponent_changed'; }
		return array_values( array_unique( $types ) );
	}

	private function insert( string $type, ?array $old_event, array $event, ?array $old_bout, array $bout, string $source_type, ?int $import_id ): bool {
		global $wpdb;
		$payload = $this->payload( $event, $bout, $type );
		$before = $old_bout ? $this->payload( $old_event ?: array(), $old_bout, $type ) : null;
		$payload['change_note'] = $this->change_note( $type, $before, $payload );
		$fingerprint = hash( 'sha256', implode( '|', array( $type, (string) $bout['stable_id'], wp_json_encode( $this->meaningful( $payload ) ) ) ) );
		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$this->t['changes']} (event_id,bout_id,change_type,fingerprint,before_json,after_json,source_type,import_id,created_at) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s)",
			(int) $event['id'], (int) $bout['id'], $type, $fingerprint, $before ? wp_json_encode( $before ) : null, wp_json_encode( $payload ), $source_type, $import_id ?: null, gmdate( 'Y-m-d H:i:s' )
		);
		return 1 === (int) $wpdb->query( $sql );
	}

	public function payload( array $event, array $bout, string $type ): array {
		return array(
			'kind' => $type,
			'event_id' => (int) ( $event['id'] ?? 0 ), 'event_title' => (string) ( $event['title'] ?? '' ), 'promotion' => (string) ( $event['promotion'] ?? '' ),
			'event_date' => (string) ( $event['event_date'] ?? '' ), 'event_datetime_utc' => $event['event_datetime_utc'] ?? null, 'source_time_text' => $event['source_time_text'] ?? null,
			'event_status' => (string) ( $event['status'] ?? '' ), 'event_hidden' => (int) ( $event['hidden'] ?? 0 ),
			'bout_id' => (int) ( $bout['id'] ?? 0 ), 'bout_stable_id' => (string) ( $bout['stable_id'] ?? '' ), 'bout_status' => (string) ( $bout['status'] ?? '' ), 'bout_hidden' => (int) ( $bout['hidden'] ?? 0 ),
			'fighter_a_id' => (int) ( $bout['fighter_a_id'] ?? 0 ), 'fighter_a_name' => (string) ( $bout['fighter_a_name'] ?? '' ),
			'fighter_b_id' => (int) ( $bout['fighter_b_id'] ?? 0 ), 'fighter_b_name' => (string) ( $bout['fighter_b_name'] ?? '' ),
			'calendar_url' => home_url( '/calendar/' ),
		);
	}

	private function meaningful( array $p ): array {
		return array_intersect_key( $p, array_flip( array( 'kind','event_date','event_datetime_utc','event_status','bout_status','fighter_a_id','fighter_b_id','fighter_a_name','fighter_b_name' ) ) );
	}
	private function change_note( string $type, ?array $before, array $after ): string {
		if('new_bout'===$type){return 'A new upcoming fight has been announced.';}
		if('schedule_changed'===$type){return 'Schedule changed from '.(string)($before['event_date']??'an earlier date').' to '.(string)$after['event_date'].'.';}
		if('opponent_changed'===$type){return 'Matchup changed from '.trim((string)($before['fighter_a_name']??'').' vs '.(string)($before['fighter_b_name']??'')).' to '.trim($after['fighter_a_name'].' vs '.$after['fighter_b_name']).'.';}
		if('cancelled'===$type){return 'The event or fight is now confirmed as cancelled.';}
		if('postponed'===$type){return 'The event is now confirmed as postponed.';}
		return '';
	}
	private function participants( array $b ): array { return array( (int) ( $b['fighter_a_id'] ?? 0 ), (int) ( $b['fighter_b_id'] ?? 0 ), (string) ( $b['fighter_a_name'] ?? '' ), (string) ( $b['fighter_b_name'] ?? '' ) ); }
	private function cancelled( ?array $e, array $b ): bool { return 'cancelled' === ( $e['status'] ?? '' ) || 'cancelled' === ( $b['status'] ?? '' ); }
	private function notifiable( array $e, array $b ): bool { return empty( $e['hidden'] ) && ! empty( $e['scope_confirmed'] ) && in_array( $e['status'], array( 'published','scheduled','postponed' ), true ) && empty( $b['hidden'] ) && 'confirmed' === $b['scope_status'] && ! in_array( $b['status'], array( 'cancelled','hidden' ), true ) && ( ! empty( $b['fighter_a_id'] ) || ! empty( $b['fighter_b_id'] ) ); }
}
