<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reminders {
	private array $t;
	public function __construct() { $this->t = Schema::tables(); }

	public function enable( int $user_id, int $bout_id ): array {
		global $wpdb;
		$context = $this->bout_context( $bout_id );
		if ( ! $context || ! $this->available( $context, $user_id ) ) { throw new \InvalidArgumentException( 'This reminder is no longer available.' ); }
		$scheduled = $this->scheduled_utc( (string) $context['event_date'], Config::timezone( $user_id ) );
		$local_day = ( new \DateTimeImmutable( 'now', new \DateTimeZone( Config::timezone( $user_id ) ) ) )->format( 'Y-m-d' );
		$reminder_day = ( new \DateTimeImmutable( $scheduled, new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( Config::timezone( $user_id ) ) )->format( 'Y-m-d' );
		if ( $reminder_day === $local_day && ( new Repository() )->user_has_daily_message( $user_id, $local_day ) ) { throw new \RuntimeException( 'Today’s notification has already been sent, so this reminder cannot be promised.' ); }
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$this->t['reminders']} (user_id,bout_id,event_date_snapshot,scheduled_at_utc,status,created_at,updated_at) VALUES (%d,%d,%s,%s,'active',%s,%s) ON DUPLICATE KEY UPDATE event_date_snapshot=VALUES(event_date_snapshot),scheduled_at_utc=VALUES(scheduled_at_utc),status='active',updated_at=VALUES(updated_at)", $user_id, $bout_id, $context['event_date'], $scheduled, $now, $now ) );
		return array( 'bout_id' => $bout_id, 'active' => true, 'scheduled_at_utc' => $scheduled );
	}

	public function cancel( int $user_id, int $bout_id ): array {
		global $wpdb;
		$wpdb->update( $this->t['reminders'], array( 'status' => 'cancelled', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'user_id' => $user_id, 'bout_id' => $bout_id ) );
		return array( 'bout_id' => $bout_id, 'active' => false );
	}

	public function listing( int $user_id ): array {
		global $wpdb;
		$u = \MMAF\DataEngine\Services\Upcoming\Schema::tables();
		return $wpdb->get_results( $wpdb->prepare( "SELECT r.bout_id,r.status,r.scheduled_at_utc,e.title event_title,e.event_date,b.fighter_a_name,b.fighter_b_name FROM {$this->t['reminders']} r JOIN {$u['bouts']} b ON b.id=r.bout_id JOIN {$u['events']} e ON e.id=b.event_id WHERE r.user_id=%d AND r.status='active' ORDER BY r.scheduled_at_utc", $user_id ), ARRAY_A ) ?: array();
	}

	public function reschedule_for_event( int $event_id ): void {
		if ( version_compare( (string) get_option( 'mmaf_db_version', '0' ), '1.18.0', '<' ) ) { return; }
		global $wpdb;
		$u = \MMAF\DataEngine\Services\Upcoming\Schema::tables();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.id,r.user_id,r.bout_id,r.status reminder_status,r.event_date_snapshot,e.event_date,e.status,e.hidden,b.status bout_status,b.hidden bout_hidden FROM {$this->t['reminders']} r JOIN {$u['bouts']} b ON b.id=r.bout_id JOIN {$u['events']} e ON e.id=b.event_id WHERE e.id=%d AND r.status IN ('active','waiting_date','sent')", $event_id ), ARRAY_A ) ?: array();
		foreach ( $rows as $row ) {
			$status = ( 'cancelled' === $row['status'] || $row['hidden'] || 'cancelled' === $row['bout_status'] || $row['bout_hidden'] ) ? 'cancelled' : ( 'postponed' === $row['status'] ? 'waiting_date' : 'active' );
			if ( 'sent' === $row['reminder_status'] && ( $row['event_date_snapshot'] === $row['event_date'] || $row['event_date'] <= $row['event_date_snapshot'] ) ) { $status = 'sent'; }
			if ( $row['event_date_snapshot'] !== $row['event_date'] || 'active' !== $status ) { $items=Schema::tables()['items'];$wpdb->query($wpdb->prepare("UPDATE $items SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND bout_id=%d AND kind='reminder' AND status IN ('pending','reserved')",(int)$row['user_id'],(int)$row['bout_id'])); }
			$scheduled = 'active' === $status ? $this->scheduled_utc( $row['event_date'], Config::timezone( (int) $row['user_id'] ) ) : null;
			$wpdb->update( $this->t['reminders'], array( 'event_date_snapshot' => $row['event_date'], 'scheduled_at_utc' => $scheduled, 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'] ) );
		}
	}

	public function bout_context( int $bout_id ): ?array {
		global $wpdb;
		$u = \MMAF\DataEngine\Services\Upcoming\Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT b.*,e.title event_title,e.promotion,e.event_date,e.event_datetime_utc,e.source_time_text,e.status event_status,e.hidden event_hidden,e.scope_confirmed FROM {$u['bouts']} b JOIN {$u['events']} e ON e.id=b.event_id WHERE b.id=%d", $bout_id ), ARRAY_A );
		return $row ?: null;
	}

	private function available( array $c, int $user_id ): bool {
		$today = ( new \DateTimeImmutable( 'now', new \DateTimeZone( Config::timezone( $user_id ) ) ) )->format( 'Y-m-d' );
		return $c['event_date'] > $today && empty( $c['event_hidden'] ) && ! empty( $c['scope_confirmed'] ) && empty( $c['hidden'] ) && 'confirmed' === $c['scope_status'] && ! in_array( $c['event_status'], array( 'cancelled','postponed','draft' ), true ) && ! in_array( $c['status'], array( 'cancelled','hidden' ), true );
	}

	private function scheduled_utc( string $date, string $timezone ): string {
		$local = new \DateTimeImmutable( $date . ' 09:00:00', new \DateTimeZone( $timezone ) );
		return $local->modify( '-1 day' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
