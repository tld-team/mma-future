<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventSourceRepository {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['event_sources'];
	}

	public function find_by_source( string $source_type, string $source_event_id ): ?array {
		global $wpdb;

		if ( '' === $source_event_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_type = %s AND source_event_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$source_type,
				$source_event_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function find_first_for_event( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE event_id = %d ORDER BY id ASC LIMIT 1", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function upsert_for_event( int $event_id, array $data ): bool {
		global $wpdb;

		$existing = $this->find_by_source( $data['source_type'], $data['source_event_id'] );
		if ( ! $existing ) {
			$existing = $this->find_first_for_event( $event_id );
		}

		$now              = DateTime::mysql_now();
		$data['event_id'] = $event_id;

		if ( $existing ) {
			$data['updated_at'] = $now;

			return false !== $wpdb->update(
				$this->table,
				$data,
				array( 'id' => (int) $existing['id'] ),
				null,
				array( '%d' )
			);
		}

		$data['created_at'] = $now;
		$data['updated_at'] = null;

		return false !== $wpdb->insert( $this->table, $data );
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
