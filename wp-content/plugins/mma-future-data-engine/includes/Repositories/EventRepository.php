<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventRepository {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['events'];
	}

	public function find( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function list( string $search = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE event_name LIKE %s OR normalized_event_name LIKE %s OR promotion_name LIKE %s ORDER BY event_date DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$like,
					$like,
					$like,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY event_date DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function list_for_select( int $limit = 300 ): array {
		global $wpdb;

		$limit = max( 1, min( 1000, $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT id, event_name, event_date, promotion_name FROM {$this->table} ORDER BY event_date DESC, id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function insert( array $data ): int {
		global $wpdb;

		$data['created_at'] = DateTime::mysql_now();
		$data['updated_at'] = null;

		$wpdb->insert( $this->table, $data );

		return (int) $wpdb->insert_id;
	}

	public function update( int $event_id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = DateTime::mysql_now();

		return false !== $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $event_id ),
			null,
			array( '%d' )
		);
	}

	public function find_duplicate_name_date( string $normalized_event_name, ?string $event_date, int $exclude_event_id = 0 ): ?array {
		global $wpdb;

		if ( '' === $normalized_event_name || null === $event_date ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE normalized_event_name = %s AND event_date = %s AND id <> %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$normalized_event_name,
				$event_date,
				$exclude_event_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function count( string $search = '' ): int {
		global $wpdb;

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE event_name LIKE %s OR normalized_event_name LIKE %s OR promotion_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$like,
					$like,
					$like
				)
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
