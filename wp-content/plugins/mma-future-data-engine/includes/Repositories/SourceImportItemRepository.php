<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SourceImportItemRepository {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['source_import_items'];
	}

	public function count_for_run( int $import_run_id ): int {
		global $wpdb;

		if ( $import_run_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE import_run_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$import_run_id
			)
		);
	}

	public function insert( array $data ): int {
		global $wpdb;

		$now = DateTime::mysql_now();

		$wpdb->insert(
			$this->table,
			array(
				'import_run_id' => (int) ( $data['import_run_id'] ?? 0 ),
				'item_type'     => (string) ( $data['item_type'] ?? '' ),
				'source_id'     => isset( $data['source_id'] ) ? (string) $data['source_id'] : null,
				'identity_hash' => isset( $data['identity_hash'] ) ? (string) $data['identity_hash'] : null,
				'content_hash'  => isset( $data['content_hash'] ) ? (string) $data['content_hash'] : null,
				'canonical_id'  => isset( $data['canonical_id'] ) ? (int) $data['canonical_id'] : null,
				'status'        => (string) ( $data['status'] ?? 'skipped' ),
				'action'        => isset( $data['action'] ) ? (string) $data['action'] : null,
				'warnings_json' => isset( $data['warnings'] ) ? wp_json_encode( (array) $data['warnings'] ) : null,
				'error_message' => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
				'created_at'    => $now,
				'updated_at'    => null,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function count_status_for_run( int $import_run_id, string $status ): int {
		global $wpdb;

		if ( $import_run_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE import_run_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$import_run_id,
				$status
			)
		);
	}

	public function counts_for_run( int $import_run_id ): array {
		global $wpdb;

		if ( $import_run_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_type, status, COUNT(*) AS total FROM {$this->table} WHERE import_run_id = %d GROUP BY item_type, status ORDER BY item_type ASC, status ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$import_run_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function list_for_run( int $import_run_id, string $item_type = '', int $limit = 100 ): array {
		global $wpdb;

		if ( $import_run_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 200, $limit ) );

		if ( '' !== $item_type ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE import_run_id = %d AND item_type = %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$import_run_id,
					$item_type,
					$limit
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE import_run_id = %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$import_run_id,
				$limit
			),
			ARRAY_A
		);
	}
}
