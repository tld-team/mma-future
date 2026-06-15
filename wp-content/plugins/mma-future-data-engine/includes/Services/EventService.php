<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Repositories\EventRepository;
use MMAF\DataEngine\Repositories\EventSourceRepository;
use MMAF\DataEngine\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventService {
	public const EVENT_STATUSES = array(
		'valid',
		'pending_review',
		'cancelled',
		'postponed',
		'completed',
		'hidden',
		'deleted_soft',
	);

	public const SOURCE_TYPES = array(
		'manual',
		'tapology',
		'sherdog',
		'other',
	);

	private const PROVENANCE_FIELDS = array(
		'event_name',
		'event_date',
		'event_datetime_utc',
		'timezone_raw',
		'promotion_name',
		'venue',
		'location',
		'city',
		'country',
		'region',
		'status',
		'deleted_soft',
	);

	private EventRepository $events;
	private EventSourceRepository $sources;
	private FieldProvenanceService $provenance;
	private AuditLogService $audit_log;
	private array $last_notices = array();

	public function __construct() {
		$this->events     = new EventRepository();
		$this->sources    = new EventSourceRepository();
		$this->provenance = new FieldProvenanceService();
		$this->audit_log  = new AuditLogService();
	}

	public function create( array $input, int $user_id ): array {
		global $wpdb;

		$payload            = $this->prepare_payload( $input, 0 );
		$this->last_notices = $payload['notices'];

		$wpdb->query( 'START TRANSACTION' );

		try {
			$event_id = $this->events->insert( $payload['event'] );
			$this->save_related_data( $event_id, $payload, $user_id );

			$after = $this->events->find( $event_id );
			$this->audit_log->write(
				'event_created',
				'event',
				$event_id,
				null,
				$this->with_source_summary( $after, $payload['source'] ),
				'Manual event creation',
				$user_id
			);

			$wpdb->query( 'COMMIT' );

			$result                  = $after ?: $payload['event'];
			$result['_mmaf_notices'] = $this->last_notices;

			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function update( int $event_id, array $input, int $user_id ): array {
		global $wpdb;

		$before = $this->events->find( $event_id );
		if ( ! $before ) {
			throw new \RuntimeException( __( 'Event not found.', 'mma-future-data-engine' ) );
		}

		$payload            = $this->prepare_payload( $input, $event_id );
		$this->last_notices = $payload['notices'];

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->events->update( $event_id, $payload['event'] );
			$this->save_related_data( $event_id, $payload, $user_id );

			$after = $this->events->find( $event_id );
			$this->audit_log->write(
				'event_updated',
				'event',
				$event_id,
				$before,
				$this->with_source_summary( $after, $payload['source'] ),
				'Manual event update',
				$user_id
			);

			$wpdb->query( 'COMMIT' );

			$result                  = $after ?: $payload['event'];
			$result['_mmaf_notices'] = $this->last_notices;

			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function prepare_payload( array $input, int $event_id ): array {
		$event_name = Sanitizer::text_or_null( $input['event_name'] ?? '' );
		if ( null === $event_name ) {
			throw new \InvalidArgumentException( __( 'Event name is required.', 'mma-future-data-engine' ) );
		}

		$event_date = $this->date_or_null( $input['event_date'] ?? '' );
		$datetime   = $this->datetime_or_null( $input['event_datetime_utc'] ?? '' );
		$status     = $this->event_status( $input['status'] ?? 'valid' );
		$deleted    = Sanitizer::bool_int( $input['deleted_soft'] ?? 0 );

		if ( 1 === $deleted ) {
			$status = 'deleted_soft';
		}

		$event = array(
			'event_name'          => $event_name,
			'normalized_event_name' => Sanitizer::normalize_name( $event_name ),
			'event_date'          => $event_date,
			'event_datetime_utc'  => $datetime,
			'timezone_raw'        => Sanitizer::text_or_null( $input['timezone_raw'] ?? '' ),
			'promotion_name'      => Sanitizer::text_or_null( $input['promotion_name'] ?? '' ),
			'venue'               => Sanitizer::text_or_null( $input['venue'] ?? '' ),
			'location'            => Sanitizer::text_or_null( $input['location'] ?? '' ),
			'city'                => Sanitizer::text_or_null( $input['city'] ?? '' ),
			'country'             => Sanitizer::text_or_null( $input['country'] ?? '' ),
			'region'              => Sanitizer::text_or_null( $input['region'] ?? '' ),
			'status'              => $status,
			'deleted_soft'        => $deleted,
		);

		$duplicate = $this->events->find_duplicate_name_date( $event['normalized_event_name'], $event_date, $event_id );
		if ( $duplicate ) {
			throw new \InvalidArgumentException( __( 'An event with the same normalized name and event date already exists. Review it before creating another record.', 'mma-future-data-engine' ) );
		}

		return array(
			'event'   => $event,
			'source'  => $this->prepare_source( $input, $event, $event_id ),
			'notices' => array(),
		);
	}

	private function save_related_data( int $event_id, array $payload, int $user_id ): void {
		if ( null !== $payload['source'] ) {
			$existing_source = $this->sources->find_by_source(
				$payload['source']['source_type'],
				$payload['source']['source_event_id']
			);

			if ( $existing_source && ! empty( $existing_source['event_id'] ) && (int) $existing_source['event_id'] !== $event_id ) {
				throw new \RuntimeException( __( 'This source event ID is already linked to another event.', 'mma-future-data-engine' ) );
			}

			$this->sources->upsert_for_event( $event_id, $payload['source'] );
		}

		foreach ( self::PROVENANCE_FIELDS as $field_name ) {
			$this->provenance->upsert(
				'event',
				$event_id,
				$field_name,
				$payload['event'][ $field_name ] ?? null,
				$user_id
			);
		}
	}

	private function prepare_source( array $input, array $event, int $event_id ): ?array {
		$source_type = $this->source_type( $input['source_type'] ?? 'manual' );
		$source_url  = isset( $input['source_url'] ) ? esc_url_raw( wp_unslash( $input['source_url'] ) ) : '';
		$promo_url   = isset( $input['source_promotion_url'] ) ? esc_url_raw( wp_unslash( $input['source_promotion_url'] ) ) : '';
		$source_slug = Sanitizer::text_or_null( $input['source_slug'] ?? '' );

		if ( '' === $source_url ) {
			throw new \InvalidArgumentException( __( 'Tapology event URL is required before an event can be saved manually.', 'mma-future-data-engine' ) );
		}

		$tapology_event = $this->derive_tapology_event_from_url( $source_url );
		$source_type = 'tapology';

		$source_event_id         = '';
		$source_event_numeric_id = null;

		if ( ! $tapology_event ) {
			throw new \InvalidArgumentException( __( 'Tapology event URL must match https://www.tapology.com/fightcenter/events/{numeric_id}-{slug}.', 'mma-future-data-engine' ) );
		}

		$source_event_id         = $tapology_event['source_event_id'];
		$source_event_numeric_id = $tapology_event['source_event_numeric_id'];
		$source_slug             = $tapology_event['source_slug'];
		$source_url              = $tapology_event['source_url'];

		$promotion = $this->derive_tapology_promotion_from_url( $promo_url );
		if ( '' !== $promo_url && ! $promotion && 'tapology' === $source_type ) {
			throw new \InvalidArgumentException( __( 'Tapology promotion URL must match https://www.tapology.com/fightcenter/promotions/{numeric_id}-{slug}.', 'mma-future-data-engine' ) );
		}

		if ( '' === $promo_url && $event_id > 0 ) {
			$existing_source = $this->sources->find_first_for_event( $event_id );
			if ( $existing_source ) {
				$promo_url = (string) ( $existing_source['source_promotion_url'] ?? '' );
				$promotion = array(
					'source_promotion_id'         => $existing_source['source_promotion_id'] ?? null,
					'source_promotion_numeric_id' => $existing_source['source_promotion_numeric_id'] ?? null,
				);
			}
		}

		$identity_basis = implode(
			'|',
			array(
				$source_type,
				$source_event_id,
				$source_url,
			)
		);
		$content_basis = implode(
			'|',
			array(
				$event['event_name'],
				(string) $event['event_date'],
				(string) $event['promotion_name'],
				$promo_url,
			)
		);

		return array(
			'source_type'                  => $source_type,
			'source_event_id'              => $source_event_id,
			'source_event_numeric_id'      => $source_event_numeric_id,
			'source_promotion_id'          => $promotion['source_promotion_id'] ?? null,
			'source_promotion_numeric_id'  => $promotion['source_promotion_numeric_id'] ?? null,
			'source_promotion_url'         => '' !== $promo_url ? $promo_url : null,
			'source_url'                   => $source_url,
			'identity_hash'                => hash( 'sha256', $identity_basis ),
			'content_hash'                 => hash( 'sha256', $content_basis ),
			'raw_payload'                  => null,
			'last_import_run_id'           => null,
			'is_verified'                  => Sanitizer::bool_int( $input['is_verified'] ?? 0 ),
			'is_primary'                   => Sanitizer::bool_int( $input['is_primary'] ?? 0 ),
			'source_slug'                  => $source_slug,
		);
	}

	private function date_or_null( $value ): ?string {
		$value = Sanitizer::text_or_null( $value );
		if ( null === $value ) {
			return null;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			throw new \InvalidArgumentException( __( 'Event date must be empty or a valid YYYY-MM-DD date.', 'mma-future-data-engine' ) );
		}

		$parts = explode( '-', $value );
		if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
			throw new \InvalidArgumentException( __( 'Event date must be empty or a valid YYYY-MM-DD date.', 'mma-future-data-engine' ) );
		}

		return $value;
	}

	private function datetime_or_null( $value ): ?string {
		$value = Sanitizer::text_or_null( $value );
		if ( null === $value ) {
			return null;
		}

		$value = str_replace( 'T', ' ', $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			$value .= ':00';
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			throw new \InvalidArgumentException( __( 'UTC datetime must be empty or a valid YYYY-MM-DD HH:MM:SS datetime.', 'mma-future-data-engine' ) );
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value );
		if ( ! $dt || $dt->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new \InvalidArgumentException( __( 'UTC datetime must be empty or a valid YYYY-MM-DD HH:MM:SS datetime.', 'mma-future-data-engine' ) );
		}

		return $value;
	}

	private function event_status( $value ): string {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, self::EVENT_STATUSES, true ) ? $value : 'valid';
	}

	private function source_type( $value ): string {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, self::SOURCE_TYPES, true ) ? $value : 'manual';
	}

	private function derive_tapology_event_from_url( string $url ): ?array {
		if ( ! preg_match( '~^https?://(?:www\.)?tapology\.com/fightcenter/events/(\d+)-([^/?#]+)~i', $url, $matches ) ) {
			return null;
		}

		$numeric_id = $matches[1];
		$slug       = sanitize_title( $matches[2] );

		return array(
			'source_event_id'         => 'tapology_event_' . $numeric_id,
			'source_event_numeric_id' => $numeric_id,
			'source_slug'             => $slug,
			'source_url'              => 'https://www.tapology.com/fightcenter/events/' . $numeric_id . '-' . $slug,
		);
	}

	private function derive_tapology_promotion_from_url( string $url ): ?array {
		if ( '' === $url ) {
			return null;
		}

		if ( ! preg_match( '~^https?://(?:www\.)?tapology\.com/fightcenter/promotions/(\d+)-([^/?#]+)~i', $url, $matches ) ) {
			return null;
		}

		$numeric_id = $matches[1];

		return array(
			'source_promotion_id'         => 'tapology_promotion_' . $numeric_id,
			'source_promotion_numeric_id' => $numeric_id,
		);
	}

	private function with_source_summary( ?array $event, ?array $source ): ?array {
		if ( null === $event ) {
			return null;
		}

		if ( null !== $source ) {
			$event['_source_summary'] = array(
				'source_type'             => $source['source_type'],
				'source_event_id'         => $source['source_event_id'],
				'source_event_numeric_id' => $source['source_event_numeric_id'],
				'source_promotion_url'    => $source['source_promotion_url'] ?? null,
				'source_promotion_id'     => $source['source_promotion_id'],
				'source_promotion_numeric_id' => $source['source_promotion_numeric_id'],
			);
		}

		return $event;
	}
}
