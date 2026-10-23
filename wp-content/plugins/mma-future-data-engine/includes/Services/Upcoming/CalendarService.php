<?php
namespace MMAF\DataEngine\Services\Upcoming;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CalendarService {
	public const DEFAULT_TIMEZONE = 'Europe/Belgrade';
	private Repository $repository;

	public function __construct() {
		$this->repository = new Repository();
	}

	public static function timezones(): array {
		return array(
			'Europe/Belgrade' => 'Belgrade', 'Europe/London' => 'London', 'America/New_York' => 'New York',
			'America/Chicago' => 'Chicago', 'America/Los_Angeles' => 'Los Angeles', 'Asia/Dubai' => 'Dubai',
			'Asia/Tokyo' => 'Tokyo', 'Australia/Sydney' => 'Sydney', 'UTC' => 'UTC',
		);
	}

	public static function valid_timezone( ?string $timezone ): string {
		return isset( self::timezones()[ (string) $timezone ] ) ? (string) $timezone : self::DEFAULT_TIMEZONE;
	}

	public function month( string $month, string $timezone ): array {
		$timezone = self::valid_timezone( $timezone );
		$zone = new \DateTimeZone( $timezone );
		$first = \DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', $zone );
		if ( ! $first || $first->format( 'Y-m' ) !== $month ) {
			throw new \InvalidArgumentException( 'Month must use the YYYY-MM format.' );
		}
		$last = $first->modify( 'last day of this month' );
		$revision = (int) get_option( 'mmaf_calendar_revision', 0 );
		$today = new \DateTimeImmutable( 'today', $zone );
		$cache_key = 'month:' . md5( $month . '|' . $timezone . '|' . $revision . '|' . $today->format( 'Y-m-d' ) );
		$cached = wp_cache_get( $cache_key, 'mmaf_calendar' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$events = $this->repository->public_events_between( $first->modify( '-2 days' )->format( 'Y-m-d' ), $last->modify( '+2 days' )->format( 'Y-m-d' ) );
		$grouped = array();
		foreach ( $events as $event ) {
			$date = $this->display_date( $event, $zone );
			if ( $date < $first->format( 'Y-m-d' ) || $date > $last->format( 'Y-m-d' ) || $date < $today->format( 'Y-m-d' ) ) {
				continue;
			}
			$grouped[ $date ][] = $this->event_summary( $event, $zone );
		}
		$coverage = $this->repository->latest_coverage();
		$days = array();
		for ( $cursor = $first; $cursor <= $last; $cursor = $cursor->modify( '+1 day' ) ) {
			$date = $cursor->format( 'Y-m-d' );
			$day_events = $grouped[ $date ] ?? array();
			$state = $this->day_state( $cursor, $zone, $coverage, $day_events );
			$days[] = array(
				'date' => $date, 'day' => (int) $cursor->format( 'j' ), 'weekday' => (int) $cursor->format( 'N' ),
				'is_today' => $date === $today->format( 'Y-m-d' ), 'state' => $state,
				'events' => array_slice( $day_events, 0, 2 ), 'event_count' => count( $day_events ),
			);
		}
		$result = array(
			'month' => $month, 'label' => self::month_label( $first ), 'timezone' => $timezone,
			'today' => $today->format( 'Y-m-d' ), 'days' => $days,
			'coverage' => $coverage ? array(
				'from' => $coverage['coverage_from'], 'to' => $coverage['coverage_to'], 'timezone' => $coverage['coverage_timezone'],
				'last_refreshed' => gmdate( 'c', strtotime( $coverage['finished_at'] . ' UTC' ) ),
			) : null,
			'revision' => $revision,
		);
		wp_cache_set( $cache_key, $result, 'mmaf_calendar', 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	public function day( string $date, string $timezone, int $page = 1, int $per_page = 20 ): array {
		$timezone = self::valid_timezone( $timezone );
		$zone = new \DateTimeZone( $timezone );
		$selected = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $zone );
		if ( ! $selected || $selected->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( 'Date must use the YYYY-MM-DD format.' );
		}
		$events = $this->repository->public_events_between( $selected->modify( '-2 days' )->format( 'Y-m-d' ), $selected->modify( '+2 days' )->format( 'Y-m-d' ) );
		$events = array_values( array_filter( $events, fn( array $event ): bool => $this->display_date( $event, $zone ) === $date ) );
		if ( $selected < new \DateTimeImmutable( 'today', $zone ) ) {
			$events = array();
		}
		$total = count( $events );
		$page = max( 1, $page );
		$per_page = max( 1, min( 50, $per_page ) );
		$events = array_slice( $events, ( $page - 1 ) * $per_page, $per_page );
		$coverage = $this->repository->latest_coverage();
		$summaries = array_map( fn( array $event ): array => $this->event_detail( $event, $zone ), $events );
		return array(
			'date' => $date, 'timezone' => $timezone, 'state' => $this->day_state( $selected, $zone, $coverage, $summaries ),
			'events' => $summaries, 'page' => $page, 'per_page' => $per_page, 'total' => $total,
			'message' => $this->empty_message( $selected, $zone, $coverage, $summaries ),
		);
	}

	public function upcoming( int $limit = 6, string $timezone = self::DEFAULT_TIMEZONE ): array {
		$timezone = self::valid_timezone( $timezone );
		$zone = new \DateTimeZone( $timezone );
		$today = new \DateTimeImmutable( 'today', $zone );
		$events = $this->repository->public_events_between( $today->modify( '-1 day' )->format( 'Y-m-d' ), $today->modify( '+60 days' )->format( 'Y-m-d' ) );
		$events = array_values( array_filter( $events, fn( array $event ): bool => $this->display_date( $event, $zone ) >= $today->format( 'Y-m-d' ) ) );
		return array_slice( array_map( fn( array $event ): array => $this->event_summary( $event, $zone ), $events ), 0, max( 1, min( 20, $limit ) ) );
	}

	private function event_summary( array $event, \DateTimeZone $zone ): array {
		return array(
			'id' => (int) $event['id'], 'title' => (string) $event['title'], 'promotion' => $event['promotion'] ?: null,
			'date' => $this->display_date( $event, $zone ), 'time' => $this->display_time( $event, $zone ),
			'is_manual' => (bool) $event['is_manual'], 'status' => (string) $event['status'],
		);
	}

	private function event_detail( array $event, \DateTimeZone $zone ): array {
		$data = $this->event_summary( $event, $zone );
		$data += array(
			'location' => $event['location'] ?: null, 'venue' => $event['venue'] ?: null,
			'time_note' => $event['time_note'] ?: ( $event['event_datetime_utc'] ? null : 'Start time is not confirmed.' ),
			'source_url' => $event['source_url'] && str_starts_with( $event['source_url'], 'https://www.tapology.com/' ) ? $event['source_url'] : null,
			'bouts' => array(),
		);
		foreach ( $event['bouts'] as $bout ) {
			$data['bouts'][] = array(
				'id' => (int) $bout['id'], 'order' => (int) $bout['bout_order'], 'weight_class' => $bout['weight_class'] ?: null,
				'status' => (string) $bout['status'],
				'fighter_a' => array( 'name' => $bout['fighter_a_name'], 'profile_url' => $bout['fighter_a_profile_url'] ?: null, 'source_url' => self::tapology_url( $bout['fighter_a_url'] ?? null ) ),
				'fighter_b' => array( 'name' => $bout['fighter_b_name'], 'profile_url' => $bout['fighter_b_profile_url'] ?: null, 'source_url' => self::tapology_url( $bout['fighter_b_url'] ?? null ) ),
			);
		}
		return $data;
	}

	private static function tapology_url( ?string $url ): ?string {
		return $url && str_starts_with( $url, 'https://www.tapology.com/' ) ? $url : null;
	}

	private function display_date( array $event, \DateTimeZone $zone ): string {
		if ( ! empty( $event['event_datetime_utc'] ) ) {
			return ( new \DateTimeImmutable( $event['event_datetime_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d' );
		}
		return (string) $event['event_date'];
	}

	private function display_time( array $event, \DateTimeZone $zone ): ?string {
		if ( ! empty( $event['event_datetime_utc'] ) ) {
			return ( new \DateTimeImmutable( $event['event_datetime_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'H:i' );
		}
		return $event['source_time_text'] ?: null;
	}

	private function day_state( \DateTimeImmutable $day, \DateTimeZone $zone, ?array $coverage, array $events ): string {
		$today = new \DateTimeImmutable( 'today', $zone );
		if ( $day < $today ) {
			return 'past';
		}
		$coverage_state = $this->coverage_state( $day, $zone, $coverage );
		if ( 'uncovered' === $coverage_state && $events ) {
			return 'manual';
		}
		return $coverage_state;
	}

	private function coverage_state( \DateTimeImmutable $day, \DateTimeZone $zone, ?array $coverage ): string {
		if ( ! $coverage ) {
			return 'uncovered';
		}
		$source_zone = new \DateTimeZone( $coverage['coverage_timezone'] );
		$coverage_start = new \DateTimeImmutable( $coverage['coverage_from'] . ' 00:00:00', $source_zone );
		$coverage_end = ( new \DateTimeImmutable( $coverage['coverage_to'] . ' 00:00:00', $source_zone ) )->modify( '+1 day' );
		$day_start = new \DateTimeImmutable( $day->format( 'Y-m-d' ) . ' 00:00:00', $zone );
		$day_end = $day_start->modify( '+1 day' );
		if ( $day_start >= $coverage_start && $day_end <= $coverage_end ) {
			return 'covered';
		}
		if ( $day_end > $coverage_start && $day_start < $coverage_end ) {
			return 'partial';
		}
		return 'uncovered';
	}

	private function empty_message( \DateTimeImmutable $day, \DateTimeZone $zone, ?array $coverage, array $events ): ?string {
		if ( $events ) {
			return 'manual' === $this->day_state( $day, $zone, $coverage, $events ) ? 'Schedule may be updated.' : null;
		}
		$state = $this->day_state( $day, $zone, $coverage, $events );
		if ( 'past' === $state ) {
			return 'Past dates are not displayed.';
		}
		return in_array( $state, array( 'covered', 'partial' ), true ) ? 'No events announced.' : 'Schedule pending.';
	}

	public static function month_label( \DateTimeImmutable $date ): string {
		$months = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' );
		return $months[ (int) $date->format( 'n' ) ] . ' ' . $date->format( 'Y' );
	}

	public static function date_label( \DateTimeImmutable $date ): string {
		$weekdays = array( 1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$months = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' );
		return $weekdays[ (int) $date->format( 'N' ) ] . ', ' . $months[ (int) $date->format( 'n' ) ] . ' ' . $date->format( 'j, Y' );
	}
}
