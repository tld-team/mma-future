<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reminders {
	private array $t;
	public function __construct() { $this->t = Schema::tables(); }

	/**
	 * When the reminder for an event goes out, in UTC: 24 hours before a known start.
	 * Without a confirmed start time there is no "24 hours before", so it falls back to 09:00 reader-local on the day before.
	 */
	public static function schedule( string $event_date, ?string $event_datetime_utc, string $timezone ): string {
		$utc = new \DateTimeZone( 'UTC' );
		if ( $event_datetime_utc ) { return ( new \DateTimeImmutable( $event_datetime_utc, $utc ) )->modify( '-24 hours' )->format( 'Y-m-d H:i:s' ); }
		return ( new \DateTimeImmutable( $event_date . ' 09:00:00', new \DateTimeZone( $timezone ) ) )->modify( '-1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	}

	/** A reminder can still be promised only while its send time is ahead. Every "Remind me" control and the server check share this rule. */
	public static function in_time( string $event_date, ?string $event_datetime_utc, string $timezone ): bool {
		return '' !== $event_date && self::schedule( $event_date, $event_datetime_utc ?: null, $timezone ) > gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * True once a reminder would arrive too late to be one.
	 * event_date is the source's calendar date, not the reader's: a US evening card starts on the next local day in Europe,
	 * so a known start time is the only reliable test.
	 */
	public static function started( array $context, string $timezone ): bool {
		if ( ! empty( $context['event_datetime_utc'] ) ) { return (string) $context['event_datetime_utc'] <= gmdate( 'Y-m-d H:i:s' ); }
		return (string) $context['event_date'] <= ( new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) ) )->format( 'Y-m-d' );
	}

	public function can_remind( array $c, int $user_id ): bool {
		return empty( $c['event_hidden'] ) && ! empty( $c['scope_confirmed'] ) && empty( $c['hidden'] ) && 'confirmed' === $c['scope_status']
			&& ! in_array( $c['event_status'], array( 'cancelled','postponed','draft' ), true ) && ! in_array( $c['status'], array( 'cancelled','hidden' ), true )
			&& self::in_time( (string) $c['event_date'], $c['event_datetime_utc'] ?: null, Config::timezone( $user_id ) );
	}

	public function enable( int $user_id, int $bout_id ): array {
		global $wpdb;
		if ( ! Config::schema_ready() ) { throw new \RuntimeException( 'Reminders are not available yet.' ); }
		$context = $this->bout_context( $bout_id );
		if ( ! $context || ! $this->can_remind( $context, $user_id ) ) {
			$too_late = $context && ! self::in_time( (string) $context['event_date'], $context['event_datetime_utc'] ?: null, Config::timezone( $user_id ) );
			throw new \InvalidArgumentException( $too_late ? 'This fight is too close for a reminder to be sent.' : 'This reminder is no longer available.' );
		}
		$start     = $context['event_datetime_utc'] ?: null;
		$scheduled = self::schedule( (string) $context['event_date'], $start, Config::timezone( $user_id ) );
		$now       = gmdate( 'Y-m-d H:i:s' );
		// prepare() would turn a missing start time into '', which is not a valid DATETIME; NULL has to be written as SQL.
		$start_sql = null === $start ? 'NULL' : $wpdb->prepare( '%s', $start );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$this->t['reminders']} (user_id,bout_id,event_date_snapshot,event_start_snapshot,scheduled_at_utc,status,created_at,updated_at) VALUES (%d,%d,%s,$start_sql,%s,'active',%s,%s) ON DUPLICATE KEY UPDATE event_date_snapshot=VALUES(event_date_snapshot),event_start_snapshot=VALUES(event_start_snapshot),scheduled_at_utc=VALUES(scheduled_at_utc),status='active',updated_at=VALUES(updated_at)", $user_id, $bout_id, $context['event_date'], $scheduled, $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

	/** Called after every calendar write: keeps each reminder's send time in step with the event it belongs to. */
	public function reschedule_for_event( int $event_id ): void {
		if ( ! Config::schema_ready() ) { return; }
		global $wpdb;
		$u    = \MMAF\DataEngine\Services\Upcoming\Schema::tables();
		$now  = gmdate( 'Y-m-d H:i:s' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.id,r.user_id,r.bout_id,r.status reminder_status,r.event_date_snapshot,r.event_start_snapshot,r.scheduled_at_utc,e.event_date,e.event_datetime_utc,e.status,e.hidden,b.status bout_status,b.hidden bout_hidden FROM {$this->t['reminders']} r JOIN {$u['bouts']} b ON b.id=r.bout_id JOIN {$u['events']} e ON e.id=b.event_id WHERE e.id=%d AND r.status IN ('active','waiting_date','sent')", $event_id ), ARRAY_A ) ?: array();
		foreach ( $rows as $row ) {
			$start     = $row['event_datetime_utc'] ?: null;
			$status    = ( 'cancelled' === $row['status'] || $row['hidden'] || 'cancelled' === $row['bout_status'] || $row['bout_hidden'] ) ? 'cancelled' : ( 'postponed' === $row['status'] ? 'waiting_date' : 'active' );
			$scheduled = 'active' === $status ? self::schedule( (string) $row['event_date'], $start, Config::timezone( (int) $row['user_id'] ) ) : null;
			// The start time can move while the date stays the same, so both are compared.
			$moved = (string) $row['event_date_snapshot'] !== (string) $row['event_date'] || (string) $row['event_start_snapshot'] !== (string) $start;
			// A reminder that already went out is final unless the fight moved far enough that a new one is still ahead.
			if ( 'sent' === $row['reminder_status'] && ! ( 'active' === $status && $moved && $scheduled > $now ) ) { continue; }
			if ( $moved || 'active' !== $status ) { $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['items']} SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE user_id=%d AND bout_id=%d AND kind='reminder' AND status IN ('pending','reserved')", (int) $row['user_id'], (int) $row['bout_id'] ) ); }
			if ( ! $moved && $status === $row['reminder_status'] && (string) $scheduled === (string) $row['scheduled_at_utc'] ) { continue; }
			// $wpdb->update() writes PHP null as SQL NULL, which the two nullable columns rely on.
			$wpdb->update( $this->t['reminders'], array( 'event_date_snapshot' => $row['event_date'], 'event_start_snapshot' => $start, 'scheduled_at_utc' => $scheduled, 'status' => $status, 'updated_at' => $now ), array( 'id' => (int) $row['id'] ) );
		}
	}

	public function bout_context( int $bout_id ): ?array {
		global $wpdb;
		$u = \MMAF\DataEngine\Services\Upcoming\Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT b.*,e.title event_title,e.promotion,e.event_date,e.event_datetime_utc,e.source_time_text,e.venue,e.location,e.status event_status,e.hidden event_hidden,e.scope_confirmed FROM {$u['bouts']} b JOIN {$u['events']} e ON e.id=b.event_id WHERE b.id=%d", $bout_id ), ARRAY_A );
		return $row ?: null;
	}
}
