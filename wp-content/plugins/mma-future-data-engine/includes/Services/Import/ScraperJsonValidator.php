<?php
namespace MMAF\DataEngine\Services\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScraperJsonValidator {
	public const SCHEMA_VERSION = 'scraper_results_v0_5';
	public const SOURCE         = 'tapology';

	private const TOP_LEVEL_FIELDS = array(
		'schema_version',
		'source',
		'source_url',
		'scraped_at',
		'filters',
		'run',
		'events',
	);

	private const EVENT_FIELDS = array(
		'internal_event_id',
		'source_event_id',
		'source_event_numeric_id',
		'event_url',
		'event_name',
		'promotion_name',
		'promotion_url',
		'source_promotion_id',
		'source_promotion_numeric_id',
		'event_date',
		'event_datetime_raw',
		'event_time_raw',
		'event_timezone_raw',
		'timezone_raw',
		'location',
		'venue',
		'enclosure',
		'city',
		'country',
		'region',
		'warnings',
		'flags',
		'source_event_status',
		'source_rows_seen',
		'source_mma_rows_seen',
		'source_bouts_total',
		'source_mma_bouts_total',
		'source_mma_bouts_total_mismatch',
		'bouts_included',
		'bouts_skipped_total',
		'bouts_skipped_no_result',
		'skipped_bouts',
		'needs_recheck',
		'fighter_profiles',
		'profile_enrichment',
		'bouts',
		'identity_hash',
		'content_hash',
	);

	private const BOUT_FIELDS = array(
		'internal_bout_id',
		'internal_event_id',
		'source_event_id',
		'source_bout_id',
		'source_bout_numeric_id',
		'bout_url',
		'status',
		'pro_am',
		'card_position',
		'weight_class',
		'weight_lbs',
		'title_fight',
		'winner',
		'loser',
		'result_type',
		'fighter_a',
		'fighter_b',
		'result',
		'flags',
		'warnings',
		'identity_hash',
		'content_hash',
	);

	private const FIGHTER_FIELDS = array(
		'name',
		'url',
		'source_fighter_id',
		'source_fighter_numeric_id',
		'prefight_record',
		'profile_ref',
		'profile_summary',
	);

	private const REQUIRED_FLAGS = array(
		'is_completed',
		'is_professional',
		'is_amateur',
		'is_cancelled',
		'is_overturned',
		'is_upcoming',
		'is_scoring_candidate',
	);

	public function validate_string( string $json ): array {
		$decoded = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array(
				'is_valid'           => false,
				'data'               => null,
				'errors'             => array( 'Invalid JSON: ' . json_last_error_msg() ),
				'warnings'           => array(),
				'unsupported_fields' => array(),
			);
		}

		return $this->validate_data( $decoded );
	}

	public function validate_data( $data ): array {
		$errors             = array();
		$warnings           = array();
		$unsupported_fields = array();

		if ( ! is_array( $data ) || ! $this->is_assoc( $data ) ) {
			return array(
				'is_valid'           => false,
				'data'               => $data,
				'errors'             => array( 'Top-level JSON value must be an object.' ),
				'warnings'           => array(),
				'unsupported_fields' => array(),
			);
		}

		$this->collect_unknown_fields( '$', $data, self::TOP_LEVEL_FIELDS, $unsupported_fields );

		if ( ( $data['schema_version'] ?? null ) !== self::SCHEMA_VERSION ) {
			$errors[] = 'schema_version must be ' . self::SCHEMA_VERSION . '.';
		}

		if ( ( $data['source'] ?? null ) !== self::SOURCE ) {
			$errors[] = 'source must be ' . self::SOURCE . '.';
		}

		if ( empty( $data['events'] ) || ! is_array( $data['events'] ) || ( array() !== $data['events'] && $this->is_assoc( $data['events'] ) ) ) {
			$errors[] = 'events must be a non-empty array.';
		}

		if ( ! empty( $data['events'] ) && is_array( $data['events'] ) ) {
			foreach ( $data['events'] as $event_index => $event ) {
				$path = '$.events[' . $event_index . ']';
				if ( ! is_array( $event ) || ! $this->is_assoc( $event ) ) {
					$errors[] = $path . ' must be an object.';
					continue;
				}

				$this->validate_event( $event, $path, $errors, $warnings, $unsupported_fields );
			}
		}

		return array(
			'is_valid'           => empty( $errors ),
			'data'               => $data,
			'errors'             => $errors,
			'warnings'           => $warnings,
			'unsupported_fields' => $unsupported_fields,
		);
	}

	private function validate_event( array $event, string $path, array &$errors, array &$warnings, array &$unsupported_fields ): void {
		$this->collect_unknown_fields( $path, $event, self::EVENT_FIELDS, $unsupported_fields );

		if ( empty( $event['source_event_id'] ) && empty( $event['identity_hash'] ) ) {
			$errors[] = $path . ' must include source_event_id or identity_hash.';
		}

		if ( empty( $event['identity_hash'] ) || ! is_string( $event['identity_hash'] ) ) {
			$errors[] = $path . '.identity_hash is required.';
		}

		if ( empty( $event['content_hash'] ) || ! is_string( $event['content_hash'] ) ) {
			$errors[] = $path . '.content_hash is required.';
		}

		if ( empty( $event['event_date'] ) ) {
			$warnings[] = $path . ' is missing event_date.';
		}

		if ( ! isset( $event['bouts'] ) ) {
			$warnings[] = $path . ' is missing bouts; treating as an event with zero retained bouts.';
			return;
		}

		if ( ! is_array( $event['bouts'] ) || ( array() !== $event['bouts'] && $this->is_assoc( $event['bouts'] ) ) ) {
			$errors[] = $path . '.bouts must be an array when present.';
			return;
		}

		foreach ( $event['bouts'] as $bout_index => $bout ) {
			$bout_path = $path . '.bouts[' . $bout_index . ']';
			if ( ! is_array( $bout ) || ! $this->is_assoc( $bout ) ) {
				$errors[] = $bout_path . ' must be an object.';
				continue;
			}

			$this->validate_bout( $bout, $bout_path, $errors, $warnings, $unsupported_fields );
		}
	}

	private function validate_bout( array $bout, string $path, array &$errors, array &$warnings, array &$unsupported_fields ): void {
		$this->collect_unknown_fields( $path, $bout, self::BOUT_FIELDS, $unsupported_fields );

		if ( empty( $bout['identity_hash'] ) || ! is_string( $bout['identity_hash'] ) ) {
			$errors[] = $path . '.identity_hash is required.';
		}

		if ( empty( $bout['content_hash'] ) || ! is_string( $bout['content_hash'] ) ) {
			$errors[] = $path . '.content_hash is required.';
		}

		if ( ! isset( $bout['fighter_a'], $bout['fighter_b'] ) || ! is_array( $bout['fighter_a'] ) || ! is_array( $bout['fighter_b'] ) ) {
			$errors[] = $path . ' must include exactly two fighter references: fighter_a and fighter_b.';
		} else {
			$this->validate_fighter_ref( $bout['fighter_a'], $path . '.fighter_a', $errors, $warnings, $unsupported_fields );
			$this->validate_fighter_ref( $bout['fighter_b'], $path . '.fighter_b', $errors, $warnings, $unsupported_fields );
		}

		if ( ! isset( $bout['flags'] ) || ! is_array( $bout['flags'] ) || ! $this->is_assoc( $bout['flags'] ) ) {
			$errors[] = $path . '.flags object is required.';
			return;
		}

		foreach ( self::REQUIRED_FLAGS as $flag ) {
			if ( ! array_key_exists( $flag, $bout['flags'] ) ) {
				$errors[] = $path . '.flags.' . $flag . ' is required.';
				continue;
			}

			if ( ! is_bool( $bout['flags'][ $flag ] ) ) {
				$errors[] = $path . '.flags.' . $flag . ' must be boolean.';
			}
		}

		$result = is_array( $bout['result'] ?? null ) ? $bout['result'] : array();
		if ( 'unknown' === (string) ( $result['method_category'] ?? '' ) ) {
			$warnings[] = $path . ' has unknown method_category.';
		}

		if ( empty( $bout['fighter_a']['prefight_record'] ) || ! is_array( $bout['fighter_a']['prefight_record'] ) ) {
			$warnings[] = $path . '.fighter_a is missing prefight_record.';
		}

		if ( empty( $bout['fighter_b']['prefight_record'] ) || ! is_array( $bout['fighter_b']['prefight_record'] ) ) {
			$warnings[] = $path . '.fighter_b is missing prefight_record.';
		}
	}

	private function validate_fighter_ref( array $fighter, string $path, array &$errors, array &$warnings, array &$unsupported_fields ): void {
		$this->collect_unknown_fields( $path, $fighter, self::FIGHTER_FIELDS, $unsupported_fields );

		if ( empty( $fighter['name'] ) || ! is_string( $fighter['name'] ) ) {
			$errors[] = $path . '.name is required.';
		}

		if ( empty( $fighter['source_fighter_id'] ) && empty( $fighter['url'] ) ) {
			$warnings[] = $path . ' is missing source_fighter_id and source URL.';
		}
	}

	private function collect_unknown_fields( string $path, array $row, array $known_fields, array &$unsupported_fields ): void {
		foreach ( array_keys( $row ) as $field ) {
			if ( ! in_array( $field, $known_fields, true ) ) {
				$unsupported_fields[] = $path . '.' . $field;
			}
		}
	}

	private function is_assoc( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
