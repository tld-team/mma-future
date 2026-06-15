<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Services\FieldProvenanceService;
use MMAF\DataEngine\Support\DateTime;
use MMAF\DataEngine\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterProfileEnrichmentApplyService {
	private const SOURCE_TYPE = 'tapology_profile_enrichment';
	private const GENDER_INFERENCE_SOURCE_TYPE = 'tapology_profile_inferred_weight_class';

	private const WRITABLE_FIELDS = array(
		'nickname'      => array(
			'label'         => 'Nickname',
			'current_key'   => 'current_nickname',
			'suggested_key' => 'suggested_nickname',
		),
		'date_of_birth' => array(
			'label'         => 'Date of birth',
			'current_key'   => 'current_dob',
			'suggested_key' => 'suggested_dob',
		),
		'birth_year'    => array(
			'label'         => 'Birth year',
			'current_key'   => 'current_birth_year',
			'suggested_key' => 'suggested_birth_year',
		),
		'gender'        => array(
			'label'         => 'Gender',
			'current_key'   => 'current_gender',
			'suggested_key' => 'suggested_gender',
		),
		'weight_class'  => array(
			'label'         => 'Weight class',
			'current_key'   => 'current_weight_class',
			'suggested_key' => 'suggested_weight_class',
		),
		'height'        => array(
			'label'         => 'Height',
			'current_key'   => 'current_height',
			'suggested_key' => 'suggested_height',
		),
		'height_cm'     => array(
			'label'         => 'Height (cm)',
			'current_key'   => 'current_height_cm',
			'suggested_key' => 'suggested_height_cm',
		),
		'last_weigh_in' => array(
			'label'         => 'Last weigh-in',
			'current_key'   => 'current_last_weigh_in',
			'suggested_key' => 'suggested_last_weigh_in',
		),
		'nationality'   => array(
			'label'         => 'Nationality',
			'current_key'   => 'current_nationality',
			'suggested_key' => 'suggested_country',
		),
	);

	private array $tables;
	private FieldProvenanceService $provenance;
	private AuditLogService $audit_log;

	public function __construct() {
		$this->tables = Schema::table_names();
		$this->provenance = new FieldProvenanceService();
		$this->audit_log = new AuditLogService();
	}

	public static function writable_fields(): array {
		return self::WRITABLE_FIELDS;
	}

	public function load_detail( string $path, string $source_fighter_id, string $source_url ): array {
		$row = ( new FighterProfileEnrichmentPreviewService() )->analyze_profile( $path, $source_fighter_id, $source_url );
		$row['safe_field_reviews'] = $this->field_reviews( $row );
		$row['safe_applyable_count'] = $this->safe_applyable_count( $row['safe_field_reviews'] );

		return $row;
	}

	public function load_detail_json_string( string $content, string $path_label, string $source_fighter_id, string $source_url ): array {
		$row = ( new FighterProfileEnrichmentPreviewService() )->analyze_profile_json_string( $content, $path_label, $source_fighter_id, $source_url );
		$row['safe_field_reviews'] = $this->field_reviews( $row );
		$row['safe_applyable_count'] = $this->safe_applyable_count( $row['safe_field_reviews'] );

		return $row;
	}

	public function apply( string $path, string $source_fighter_id, string $source_url, array $selected_fields, int $user_id ): array {
		$detail = $this->load_detail( $path, $source_fighter_id, $source_url );

		return $this->apply_profile_detail( $detail, $selected_fields, $user_id );
	}

	public function apply_json_string( string $content, string $path_label, string $source_fighter_id, string $source_url, array $selected_fields, int $user_id ): array {
		$detail = $this->load_detail_json_string( $content, $path_label, $source_fighter_id, $source_url );

		return $this->apply_profile_detail( $detail, $selected_fields, $user_id );
	}

	public function apply_all_safe_json_string( string $content, string $path_label, int $user_id ): array {
		$preview = ( new FighterProfileEnrichmentPreviewService() )->analyze_json_string_unpaged( $content, $path_label );
		$summary = array(
			'status' => 'completed',
			'enrichment_file' => $path_label,
			'profiles_total' => (int) ( $preview['summary']['profiles_total'] ?? 0 ),
			'profiles_matched' => 0,
			'profiles_unmatched' => 0,
			'profiles_ambiguous' => 0,
			'profiles_applied' => 0,
			'profiles_no_safe_changes' => 0,
			'profiles_failed' => 0,
			'fields_applied_total' => 0,
			'fields_skipped_total' => 0,
			'applied_by' => $user_id,
		);
		$rows = array();

		foreach ( (array) ( $preview['rows'] ?? array() ) as $row ) {
			$match_type = (string) ( $row['match_type'] ?? '' );
			if ( in_array( $match_type, array( 'exact_source_match', 'source_url_match' ), true ) ) {
				++$summary['profiles_matched'];
			} elseif ( 'ambiguous_match' === $match_type ) {
				++$summary['profiles_ambiguous'];
			} else {
				++$summary['profiles_unmatched'];
			}

			if ( ! in_array( $match_type, array( 'exact_source_match', 'source_url_match' ), true ) ) {
				$rows[] = array(
					'source_fighter_id' => (string) ( $row['source_fighter_id'] ?? '' ),
					'source_url' => (string) ( $row['source_url'] ?? '' ),
					'profile_display_name' => (string) ( $row['profile_display_name'] ?? '' ),
					'matched_canonical_fighter_id' => 0,
					'status' => 'skipped',
					'applied' => array(),
					'skipped' => array( '_profile' => 'skip_unmatched_fighter' ),
				);
				continue;
			}

			$detail = $row;
			$detail['enrichment_file'] = $path_label;
			$detail['safe_field_reviews'] = $this->field_reviews( $detail );
			$detail['safe_applyable_count'] = $this->safe_applyable_count( $detail['safe_field_reviews'] );
			$selected = array();
			foreach ( (array) $detail['safe_field_reviews'] as $field => $review ) {
				if ( ! empty( $review['can_apply'] ) ) {
					$selected[] = (string) $field;
				}
			}

			if ( empty( $selected ) ) {
				++$summary['profiles_no_safe_changes'];
				$rows[] = array(
					'source_fighter_id' => (string) ( $row['source_fighter_id'] ?? '' ),
					'source_url' => (string) ( $row['source_url'] ?? '' ),
					'profile_display_name' => (string) ( $row['profile_display_name'] ?? '' ),
					'matched_canonical_fighter_id' => (int) ( $row['matched_canonical_fighter_id'] ?? 0 ),
					'status' => 'no_safe_changes',
					'applied' => array(),
					'skipped' => $this->skip_reasons_from_reviews( (array) $detail['safe_field_reviews'] ),
				);
				continue;
			}

			try {
				$result = $this->apply_profile_detail( $detail, $selected, $user_id );
				$applied = array_map( 'strval', (array) ( $result['applied'] ?? array() ) );
				$skipped = (array) ( $result['skipped'] ?? array() );
				if ( empty( $applied ) ) {
					++$summary['profiles_no_safe_changes'];
				} else {
					++$summary['profiles_applied'];
				}
				$summary['fields_applied_total'] += count( $applied );
				$summary['fields_skipped_total'] += count( $skipped );
				$rows[] = array(
					'source_fighter_id' => (string) ( $row['source_fighter_id'] ?? '' ),
					'source_url' => (string) ( $row['source_url'] ?? '' ),
					'profile_display_name' => (string) ( $row['profile_display_name'] ?? '' ),
					'matched_canonical_fighter_id' => (int) ( $row['matched_canonical_fighter_id'] ?? 0 ),
					'status' => (string) ( $result['status'] ?? '' ),
					'applied' => $applied,
					'skipped' => $skipped,
				);
			} catch ( \Throwable $error ) {
				++$summary['profiles_failed'];
				$summary['status'] = 'completed_with_profile_errors';
				$rows[] = array(
					'source_fighter_id' => (string) ( $row['source_fighter_id'] ?? '' ),
					'source_url' => (string) ( $row['source_url'] ?? '' ),
					'profile_display_name' => (string) ( $row['profile_display_name'] ?? '' ),
					'matched_canonical_fighter_id' => (int) ( $row['matched_canonical_fighter_id'] ?? 0 ),
					'status' => 'failed',
					'applied' => array(),
					'skipped' => array(),
					'error' => $error->getMessage(),
				);
			}
		}

		return array(
			'summary' => $summary,
			'rows' => $rows,
		);
	}

	private function apply_profile_detail( array $detail, array $selected_fields, int $user_id ): array {
		global $wpdb;

		$selected_fields = array_values(
			array_intersect(
				array_map( 'sanitize_key', $selected_fields ),
				array_keys( self::WRITABLE_FIELDS )
			)
		);

		if ( empty( $selected_fields ) ) {
			throw new \RuntimeException( 'No fields selected.' );
		}

		$match_type = (string) ( $detail['match_type'] ?? '' );
		if ( ! in_array( $match_type, array( 'exact_source_match', 'source_url_match' ), true ) ) {
			throw new \RuntimeException( 'Profile is unmatched or ambiguous and cannot be applied.' );
		}

		$fighter_id = (int) ( $detail['matched_canonical_fighter_id'] ?? 0 );
		if ( $fighter_id <= 0 ) {
			throw new \RuntimeException( 'Profile no longer matches a canonical fighter.' );
		}

		$reviews = (array) $detail['safe_field_reviews'];
		$skipped = array();
		$changes = array();
		foreach ( $selected_fields as $field ) {
			$review = (array) ( $reviews[ $field ] ?? array() );
			if ( 'safe_empty_can_apply' !== (string) ( $review['status'] ?? '' ) ) {
				$skipped[ $field ] = $this->field_action_from_status( $field, (string) ( $review['status'] ?? 'unsupported_field' ) );
				continue;
			}

			$value = $this->prepare_value( $field, (string) ( $review['suggested'] ?? '' ) );
			if ( null === $value ) {
				$skipped[ $field ] = $this->field_action_from_status( $field, 'not_available' );
				continue;
			}

			$changes[ $field ] = array(
				'old' => (string) ( $review['current'] ?? '' ),
				'new' => $value,
			);
		}

		if ( empty( $changes ) ) {
			return array(
				'status' => 'partial',
				'applied' => array(),
				'skipped' => $skipped,
				'fighter_id' => $fighter_id,
			);
		}

		$before = $this->get_fighter( $fighter_id );
		if ( ! $before ) {
			throw new \RuntimeException( 'Canonical fighter no longer exists.' );
		}

		foreach ( $changes as $field => $change ) {
			$protection_reason = $this->provenance->protected_reason( 'fighter', $fighter_id, $field );
			if ( null !== $protection_reason ) {
				$skipped[ $field ] = $this->field_action_from_status( $field, $protection_reason );
				unset( $changes[ $field ] );
				continue;
			}

			$current = (string) ( $before[ $field ] ?? '' );
			if ( ! $this->current_value_is_empty_or_unknown( $field, $current ) ) {
				$skipped[ $field ] = 'skip_existing_value';
				unset( $changes[ $field ] );
			}
		}

		if ( empty( $changes ) ) {
			return array(
				'status' => 'partial',
				'applied' => array(),
				'skipped' => $skipped,
				'fighter_id' => $fighter_id,
			);
		}

		$update = array();
		foreach ( $changes as $field => $change ) {
			$update[ $field ] = $change['new'];
		}
		$update['updated_at'] = DateTime::mysql_now();

		$wpdb->query( 'START TRANSACTION' );

		try {
			$updated = $wpdb->update(
				$this->tables['fighters'],
				$update,
				array( 'id' => $fighter_id ),
				null,
				array( '%d' )
			);

			if ( false === $updated ) {
				throw new \RuntimeException( 'Could not update canonical fighter safe fields.' );
			}

			$source_id = '' !== (string) ( $detail['source_fighter_id'] ?? '' )
				? (string) $detail['source_fighter_id']
				: (string) ( $detail['source_url'] ?? '' );

			foreach ( $changes as $field => $change ) {
				$this->provenance->upsert_admin_approved_source(
					'fighter',
					$fighter_id,
					$field,
					$change['new'],
					$this->provenance_source_type_for_field( $field ),
					$source_id,
					$user_id
				);
			}

			$after = $this->get_fighter( $fighter_id );
			$old_values = array();
			$new_values = array();
			foreach ( $changes as $field => $change ) {
				$old_values[ $field ] = $before[ $field ] ?? null;
				$new_values[ $field ] = $after[ $field ] ?? $change['new'];
			}

			$this->audit_log->write(
				'fighter_profile_enrichment_apply',
				'fighter',
				$fighter_id,
				array(
					'old_values' => $old_values,
					'source_fighter_id' => (string) ( $detail['source_fighter_id'] ?? '' ),
					'source_url' => (string) ( $detail['source_url'] ?? '' ),
					'profile_json_file' => (string) ( $detail['enrichment_file'] ?? '' ),
				),
				array(
					'new_values' => $new_values,
					'fields_changed' => array_keys( $changes ),
					'fields_skipped' => $skipped,
					'field_source_types' => $this->field_source_types( array_keys( $changes ) ),
					'source_fighter_id' => (string) ( $detail['source_fighter_id'] ?? '' ),
					'source_url' => (string) ( $detail['source_url'] ?? '' ),
					'profile_json_file' => (string) ( $detail['enrichment_file'] ?? '' ),
					'applied_by' => $user_id,
				),
				'Admin-approved Tapology fighter profile enrichment applied to empty safe fields only.',
				$user_id
			);

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}

		return array(
			'status' => empty( $skipped ) ? 'success' : 'partial',
			'applied' => array_keys( $changes ),
			'skipped' => $skipped,
			'fighter_id' => $fighter_id,
		);
	}

	private function field_reviews( array $row ): array {
		$match_type = (string) ( $row['match_type'] ?? '' );
		$is_matched = in_array( $match_type, array( 'exact_source_match', 'source_url_match' ), true );
		$fighter_id = (int) ( $row['matched_canonical_fighter_id'] ?? 0 );
		$field_statuses = (array) ( $row['field_statuses'] ?? array() );
		$reviews = array();

		foreach ( self::WRITABLE_FIELDS as $field => $meta ) {
			$status = (string) ( $field_statuses[ $field ] ?? 'not_available' );
			$protection_reason = $is_matched && $fighter_id > 0
				? $this->provenance->protected_reason( 'fighter', $fighter_id, $field )
				: null;

			if ( ! $is_matched && 'not_available' !== $status ) {
				$status = 'unsafe_ambiguous';
			} elseif ( null !== $protection_reason ) {
				$status = $protection_reason;
			} elseif ( 'canonical_empty_can_suggest' === $status ) {
				$status = 'safe_empty_can_apply';
			}

			$reviews[ $field ] = array(
				'label' => $meta['label'],
				'current' => (string) ( $row[ $meta['current_key'] ] ?? '' ),
				'suggested' => (string) ( $row[ $meta['suggested_key'] ] ?? '' ),
				'status' => $status,
				'protection_reason' => $protection_reason,
				'can_apply' => 'safe_empty_can_apply' === $status,
			);
		}

		return $reviews;
	}

	private function safe_applyable_count( array $reviews ): int {
		$count = 0;
		foreach ( $reviews as $review ) {
			if ( ! empty( $review['can_apply'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function prepare_value( string $field, string $value ) {
		if ( 'gender' === $field ) {
			$value = sanitize_key( $value );
			return in_array( $value, array( 'male', 'female' ), true ) ? $value : null;
		}

		if ( 'date_of_birth' === $field ) {
			return Sanitizer::valid_date_or_null( $value );
		}

		if ( 'birth_year' === $field ) {
			return Sanitizer::valid_year_or_null( $value );
		}

		if ( 'weight_class' === $field ) {
			$normalized = $this->normalize_weight_class_for_storage( $value );
			return in_array( $normalized, Sanitizer::WEIGHT_CLASSES, true ) && 'unknown' !== $normalized ? $normalized : null;
		}

		if ( 'height_cm' === $field ) {
			$value = trim( $value );
			if ( ! preg_match( '/^\d{2,3}$/', $value ) ) {
				return null;
			}

			$height_cm = (int) $value;
			return $height_cm >= 100 && $height_cm <= 260 ? $height_cm : null;
		}

		if ( 'height' === $field || 'last_weigh_in' === $field ) {
			return $this->bounded_text_or_null( $value, 60 );
		}

		if ( 'nickname' === $field ) {
			if ( preg_match( '/\b(age|height|location|weight|association|team)\s*:/i', $value ) ) {
				return null;
			}

			return $this->bounded_text_or_null( $value, 255 );
		}

		return Sanitizer::text_or_null( $value );
	}

	private function bounded_text_or_null( string $value, int $max_length ): ?string {
		$value = Sanitizer::text_or_null( $value );
		if ( null === $value ) {
			return null;
		}

		return strlen( $value ) <= $max_length ? $value : null;
	}

	private function get_fighter( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->tables['fighters']} WHERE id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	private function current_value_is_empty_or_unknown( string $field, string $current ): bool {
		$current = trim( $current );
		if ( '' === $current ) {
			return true;
		}
		if ( 'birth_year' === $field && '0' === $current ) {
			return true;
		}
		if ( 'height_cm' === $field && '0' === $current ) {
			return true;
		}
		if ( 'weight_class' === $field && 'unknown' === $this->normalize_weight_class_for_storage( $current ) ) {
			return true;
		}
		if ( 'gender' === $field && 'unknown' === strtolower( $current ) ) {
			return true;
		}

		return false;
	}

	private function skip_reasons_from_reviews( array $reviews ): array {
		$skipped = array();
		foreach ( $reviews as $field => $review ) {
			$status = (string) ( $review['status'] ?? 'not_available' );
			if ( 'safe_empty_can_apply' === $status ) {
				continue;
			}

			$skipped[ (string) $field ] = $this->field_action_from_status( (string) $field, $status );
		}

		return $skipped;
	}

	private function field_action_from_status( string $field, string $status ): string {
		if ( 'not_available' === $status ) {
			return 'gender' === $field ? 'skip_cannot_infer_gender' : 'skip_missing_value';
		}
		if ( 'already_same' === $status || 'canonical_differs_source_suggestion' === $status ) {
			return 'skip_existing_value';
		}
		if ( in_array( $status, array( 'protected_by_locked_provenance', 'protected_by_manual_verified', 'protected_by_admin_override' ), true ) ) {
			return 'skip_locked_or_manual_verified';
		}
		if ( 'backend_column_missing' === $status || 'unsafe_ambiguous' === $status || 'unsupported_field' === $status ) {
			return 'skip_unsupported_field';
		}

		return $status;
	}

	private function provenance_source_type_for_field( string $field ): string {
		return 'gender' === $field ? self::GENDER_INFERENCE_SOURCE_TYPE : self::SOURCE_TYPE;
	}

	private function field_source_types( array $fields ): array {
		$sources = array();
		foreach ( $fields as $field ) {
			$sources[ (string) $field ] = $this->provenance_source_type_for_field( (string) $field );
		}

		return $sources;
	}

	private function normalize_weight_class_for_storage( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = str_replace( array( "women's", 'womens', 'women ' ), 'women ', $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		$value = trim( (string) $value, '_' );
		$value = str_replace( 'women_', 'women_', $value );
		if ( 'strawweight' === $value ) {
			return 'women_strawweight';
		}

		return $value;
	}
}
