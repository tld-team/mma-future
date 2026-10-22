<?php
namespace MMAF\DataEngine\Services\Upcoming;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Editor {
	private array $tables;
	private Repository $repository;

	public function __construct() {
		$this->tables = Schema::tables();
		$this->repository = new Repository();
	}

	public function save_event( array $input, int $actor ): int {
		global $wpdb;
		$id = absint( $input['event_id'] ?? 0 );
		$existing = $id ? $this->repository->find_event( $id ) : null;
		if ( $id && ! $existing ) {
			throw new \RuntimeException( 'Događaj ne postoji.' );
		}
		$title = sanitize_text_field( wp_unslash( $input['title'] ?? '' ) );
		$date = sanitize_text_field( wp_unslash( $input['event_date'] ?? '' ) );
		$status = sanitize_key( $input['status'] ?? 'draft' );
		if ( ! in_array( $status, array( 'draft', 'published', 'scheduled', 'postponed', 'cancelled' ), true ) ) {
			$status = 'draft';
		}
		$bouts = $this->parse_bouts( wp_unslash( $input['bouts'] ?? '' ) );
		$scope = ! empty( $input['scope_confirmed'] );
		$existing_confirmed_bouts = $existing ? array_filter( $existing['bouts'] ?? array(), static fn( array $bout ): bool => empty( $bout['hidden'] ) && 'confirmed' === $bout['scope_status'] && ! in_array( $bout['status'], array( 'cancelled', 'hidden' ), true ) ) : array();
		if ( 'draft' !== $status && ( ! $title || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ( ! $bouts && ! $existing_confirmed_bouts ) || ! $scope ) ) {
			throw new \RuntimeException( 'Za objavu su potrebni naziv, datum, najmanje jedan par i potvrda profesionalnog MMA obuhvata.' );
		}
		if ( ! $date ) {
			$date = gmdate( 'Y-m-d' );
		}
		$fields = array(
			'title' => $title, 'promotion' => sanitize_text_field( wp_unslash( $input['promotion'] ?? '' ) ) ?: null,
			'event_date' => $date, 'source_time_text' => sanitize_text_field( wp_unslash( $input['source_time_text'] ?? '' ) ) ?: null,
			'source_timezone' => sanitize_text_field( wp_unslash( $input['source_timezone'] ?? '' ) ) ?: null,
			'venue' => sanitize_text_field( wp_unslash( $input['venue'] ?? '' ) ) ?: null,
			'location' => sanitize_text_field( wp_unslash( $input['location'] ?? '' ) ) ?: null,
			'source_url' => esc_url_raw( wp_unslash( $input['source_url'] ?? '' ) ) ?: null,
			'status' => $status, 'scope_confirmed' => $scope ? 1 : 0, 'hidden' => ! empty( $input['hidden'] ) ? 1 : 0,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$fields['event_datetime_utc'] = $this->utc_datetime( $date, $fields['source_time_text'], $fields['source_timezone'] );
		if ( $existing && ! $existing['is_manual'] ) {
			$source = json_decode( (string) $existing['source_json'], true ) ?: array();
			$source_map = array( 'title' => 'title', 'promotion' => 'promotion', 'event_date' => 'date', 'source_time_text' => 'source_time_text', 'source_timezone' => 'source_timezone', 'venue' => 'venue', 'location' => 'location', 'source_url' => 'source_url', 'status' => 'status' );
			$overrides = json_decode( (string) $existing['overrides_json'], true ) ?: array();
			foreach ( $source_map as $field => $source_field ) {
				if ( ! empty( $input['reset_source'][ $field ] ) ) {
					unset( $overrides[ $field ] );
					$fields[ $field ] = $source[ $source_field ] ?? null;
				} else {
					$overrides[ $field ] = array( 'active' => true, 'value' => $fields[ $field ] );
				}
			}
			$fields['overrides_json'] = wp_json_encode( $overrides );
			$fields['event_datetime_utc'] = $this->utc_datetime( (string) $fields['event_date'], $fields['source_time_text'], $fields['source_timezone'] );
			if ( false === $wpdb->update( $this->tables['events'], $fields, array( 'id' => $id ) ) ) {
				throw new \RuntimeException( $wpdb->last_error ?: 'Izmena nije uspela.' );
			}
		} elseif ( $existing ) {
			if ( false === $wpdb->update( $this->tables['events'], $fields, array( 'id' => $id ) ) ) {
				throw new \RuntimeException( $wpdb->last_error ?: 'Izmena nije uspela.' );
			}
		} else {
			$fields += array(
				'stable_id' => 'manual_' . wp_generate_uuid4(), 'source_type' => 'manual', 'is_manual' => 1,
				'source_json' => null, 'overrides_json' => wp_json_encode( array() ), 'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);
			if ( false === $wpdb->insert( $this->tables['events'], $fields ) ) {
				throw new \RuntimeException( $wpdb->last_error ?: 'Događaj nije sačuvan.' );
			}
			$id = (int) $wpdb->insert_id;
		}
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tables['bouts']} WHERE event_id=%d AND is_manual=1", $id ) ) ) {
			throw new \RuntimeException( $wpdb->last_error ?: 'Program nije moguće izmeniti.' );
		}
		foreach ( $bouts as $index => $bout ) {
			$wpdb->insert(
				$this->tables['bouts'],
				array(
					'event_id' => $id, 'stable_id' => 'manual_' . wp_generate_uuid4(), 'bout_order' => $index + 1,
					'weight_class' => $bout['weight_class'], 'status' => 'scheduled', 'scope_status' => $scope ? 'confirmed' : 'review',
					'scope_evidence' => $scope ? 'administrator_confirmation' : null,
					'fighter_a_name' => $bout['fighter_a_name'], 'fighter_a_url' => $bout['fighter_a_url'],
					'fighter_b_name' => $bout['fighter_b_name'], 'fighter_b_url' => $bout['fighter_b_url'],
					'is_manual' => 1, 'hidden' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( $wpdb->last_error );
			}
		}
		$this->repository->bump_revision();
		return $id;
	}

	public function set_bout_hidden( int $bout_id, bool $hidden ): void {
		global $wpdb;
		if ( false === $wpdb->update( $this->tables['bouts'], array( 'hidden' => $hidden ? 1 : 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $bout_id ) ) ) {
			throw new \RuntimeException( $wpdb->last_error ?: 'Par nije moguće izmeniti.' );
		}
		$this->repository->bump_revision();
	}

	private function parse_bouts( string $text ): array {
		$rows = array();
		foreach ( preg_split( '/\R/', $text ) ?: array() as $line ) {
			$line = trim( $line );
			if ( ! $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( empty( $parts[0] ) || empty( $parts[1] ) ) {
				throw new \RuntimeException( 'Svaki red programa mora imati format Borac A | Borac B | kategorija | URL A | URL B.' );
			}
			$rows[] = array(
				'fighter_a_name' => sanitize_text_field( $parts[0] ), 'fighter_b_name' => sanitize_text_field( $parts[1] ),
				'weight_class' => isset( $parts[2] ) ? sanitize_text_field( $parts[2] ) : null,
				'fighter_a_url' => isset( $parts[3] ) ? esc_url_raw( $parts[3] ) : null,
				'fighter_b_url' => isset( $parts[4] ) ? esc_url_raw( $parts[4] ) : null,
			);
		}
		return $rows;
	}

	private function utc_datetime( string $date, ?string $time, ?string $timezone ): ?string {
		if ( ! $time || ! $timezone ) {
			return null;
		}
		try {
			$zone = new \DateTimeZone( $timezone );
			$value = new \DateTimeImmutable( $date . ' ' . $time, $zone );
			return $value->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Throwable $error ) {
			return null;
		}
	}
}
