<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldProvenanceService {
	private const PROTECTED_SOURCE_TYPES = array(
		'manual_verified' => 'protected_by_manual_verified',
		'manual_admin'    => 'protected_by_manual_verified',
		'admin_override'  => 'protected_by_admin_override',
		'admin_verified'  => 'protected_by_admin_override',
		'admin_protected' => 'protected_by_admin_override',
	);

	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['field_provenance'];
	}

	public function upsert( string $entity_type, int $entity_id, string $field_name, $value, int $user_id ): void {
		global $wpdb;

		$now        = DateTime::mysql_now();
		$value_hash = hash( 'sha256', $this->normalize_value( $value ) );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE entity_type = %s AND entity_id = %d AND field_name = %s AND source_type = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entity_type,
				$entity_id,
				$field_name,
				'manual_verified'
			)
		);

		$data = array(
			'source_id'    => 'manual_admin',
			'value_hash'   => $value_hash,
			'locked'       => 0,
			'verified_by'  => $user_id > 0 ? $user_id : null,
			'verified_at'  => $now,
			'last_seen_at' => $now,
			'updated_at'   => $now,
		);

		if ( $existing_id ) {
			$wpdb->update(
				$this->table,
				$data,
				array( 'id' => (int) $existing_id ),
				null,
				array( '%d' )
			);

			return;
		}

		$data['entity_type']   = $entity_type;
		$data['entity_id']     = $entity_id;
		$data['field_name']    = $field_name;
		$data['source_type']   = 'manual_verified';
		$data['first_seen_at'] = $now;
		$data['created_at']    = $now;

		$wpdb->insert( $this->table, $data );
	}

	public function upsert_source( string $entity_type, int $entity_id, string $field_name, $value, string $source_type, ?string $source_id = null, int $user_id = 0 ): void {
		$this->upsert_source_record( $entity_type, $entity_id, $field_name, $value, $source_type, $source_id, $user_id, false );
	}

	public function upsert_admin_approved_source( string $entity_type, int $entity_id, string $field_name, $value, string $source_type, ?string $source_id = null, int $user_id = 0 ): void {
		$this->upsert_source_record( $entity_type, $entity_id, $field_name, $value, $source_type, $source_id, $user_id, true );
	}

	private function upsert_source_record( string $entity_type, int $entity_id, string $field_name, $value, string $source_type, ?string $source_id, int $user_id, bool $admin_approved ): void {
		global $wpdb;

		$now        = DateTime::mysql_now();
		$value_hash = hash( 'sha256', $this->normalize_value( $value ) );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE entity_type = %s AND entity_id = %d AND field_name = %s AND source_type = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entity_type,
				$entity_id,
				$field_name,
				$source_type
			)
		);

		$data = array(
			'source_id'    => $source_id,
			'value_hash'   => $value_hash,
			'locked'       => 0,
			'verified_by'  => $user_id > 0 ? $user_id : null,
			'verified_at'  => $admin_approved ? $now : null,
			'last_seen_at' => $now,
			'updated_at'   => $now,
		);

		if ( $existing_id ) {
			$wpdb->update(
				$this->table,
				$data,
				array( 'id' => (int) $existing_id ),
				null,
				array( '%d' )
			);

			return;
		}

		$data['entity_type']   = $entity_type;
		$data['entity_id']     = $entity_id;
		$data['field_name']    = $field_name;
		$data['source_type']   = $source_type;
		$data['first_seen_at'] = $now;
		$data['created_at']    = $now;

		$wpdb->insert( $this->table, $data );
	}

	public function has_manual_verified( string $entity_type, int $entity_id, string $field_name ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE entity_type = %s AND entity_id = %d AND field_name = %s AND source_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entity_type,
				$entity_id,
				$field_name,
				'manual_verified'
			)
		);

		return $count > 0;
	}

	public function has_source( string $entity_type, int $entity_id, string $field_name, string $source_type ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE entity_type = %s AND entity_id = %d AND field_name = %s AND source_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entity_type,
				$entity_id,
				$field_name,
				$source_type
			)
		);

		return $count > 0;
	}

	public function protected_reason( string $entity_type, int $entity_id, string $field_name ): ?string {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_type, locked FROM {$this->table} WHERE entity_type = %s AND entity_id = %d AND field_name = %s ORDER BY locked DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entity_type,
				$entity_id,
				$field_name
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( 1 === (int) ( $row['locked'] ?? 0 ) ) {
				return 'protected_by_locked_provenance';
			}
		}

		foreach ( (array) $rows as $row ) {
			$source_type = (string) ( $row['source_type'] ?? '' );
			if ( isset( self::PROTECTED_SOURCE_TYPES[ $source_type ] ) ) {
				return self::PROTECTED_SOURCE_TYPES[ $source_type ];
			}
		}

		return null;
	}

	public function is_protected( string $entity_type, int $entity_id, string $field_name ): bool {
		return null !== $this->protected_reason( $entity_type, $entity_id, $field_name );
	}

	private function normalize_value( $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return trim( strtolower( (string) $value ) );
	}
}
