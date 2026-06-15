<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SourceImportRunRepository {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['source_import_runs'];
	}

	public function insert_dry_run( array $data ): int {
		global $wpdb;

		$now = DateTime::mysql_now();

		$row = array(
			'source_type'           => (string) ( $data['source_type'] ?? 'tapology' ),
			'source_schema_version' => (string) ( $data['source_schema_version'] ?? '' ),
			'source_run_id'         => isset( $data['source_run_id'] ) ? (string) $data['source_run_id'] : null,
			'payload_hash'          => (string) ( $data['payload_hash'] ?? '' ),
			'status'                => (string) ( $data['status'] ?? 'dry_run_completed' ),
			'mode'                  => 'dry_run',
			'dry_run'               => 1,
			'started_at'            => (string) ( $data['started_at'] ?? $now ),
			'finished_at'           => (string) ( $data['finished_at'] ?? $now ),
			'summary_json'          => isset( $data['summary'] ) ? wp_json_encode( $data['summary'] ) : null,
			'error_message'         => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
			'created_by'            => isset( $data['created_by'] ) ? (int) $data['created_by'] : null,
			'created_at'            => $now,
			'updated_at'            => null,
		);

		$wpdb->insert( $this->table, $row );

		return (int) $wpdb->insert_id;
	}

	public function insert_import_run( array $data ): int {
		global $wpdb;

		$now = DateTime::mysql_now();

		$wpdb->insert(
			$this->table,
			array(
				'source_type'           => (string) ( $data['source_type'] ?? 'tapology' ),
				'source_schema_version' => (string) ( $data['source_schema_version'] ?? '' ),
				'source_run_id'         => isset( $data['source_run_id'] ) ? (string) $data['source_run_id'] : null,
				'payload_hash'          => (string) ( $data['payload_hash'] ?? '' ),
				'status'                => (string) ( $data['status'] ?? 'running' ),
				'mode'                  => 'import',
				'dry_run'               => 0,
				'started_at'            => (string) ( $data['started_at'] ?? $now ),
				'finished_at'           => null,
				'summary_json'          => isset( $data['summary'] ) ? wp_json_encode( $data['summary'] ) : null,
				'error_message'         => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
				'created_by'            => isset( $data['created_by'] ) ? (int) $data['created_by'] : null,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function update_run( int $run_id, array $data ): bool {
		global $wpdb;

		if ( $run_id <= 0 ) {
			return false;
		}

		$row = array(
			'updated_at' => DateTime::mysql_now(),
		);

		foreach ( array( 'status', 'finished_at', 'summary_json', 'error_message' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$row[ $field ] = $data[ $field ];
			}
		}

		if ( array_key_exists( 'summary', $data ) ) {
			$row['summary_json'] = wp_json_encode( $data['summary'] );
		}

		return false !== $wpdb->update(
			$this->table,
			$row,
			array( 'id' => $run_id ),
			null,
			array( '%d' )
		);
	}

	public function count_by_payload_hash( string $payload_hash ): int {
		global $wpdb;

		if ( '' === $payload_hash ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE payload_hash = %s AND dry_run = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$payload_hash
			)
		);
	}

	public function latest_dry_run(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->table} WHERE dry_run = 1 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function latest_import(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->table} WHERE dry_run = 0 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function count_dry_runs_by_payload_hash( string $payload_hash ): int {
		global $wpdb;

		if ( '' === $payload_hash ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE payload_hash = %s AND dry_run = 1 AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$payload_hash,
				'dry_run_completed'
			)
		);
	}

	public function recent( int $limit = 10 ): array {
		global $wpdb;

		$limit = max( 1, min( 25, $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);
	}

	public function find( int $run_id ): ?array {
		global $wpdb;

		if ( $run_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$run_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}
}
