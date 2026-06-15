<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SystemSnapshotImportService {
	public function import_file( string $path, int $user_id = 0, bool $reset_wp_post_links = true ): array {
		$first_line = $this->first_non_empty_line( $path );
		$first      = json_decode( $first_line, true, 512, JSON_BIGINT_AS_STRING );

		if ( is_array( $first ) && 'manifest' === (string) ( $first['type'] ?? '' ) && 'jsonl' === (string) ( $first['encoding'] ?? '' ) ) {
			return $this->import_jsonl_file( $path, $user_id, $reset_wp_post_links );
		}

		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read legacy system snapshot JSON.' );
		}

		return $this->import_json_string( $content, $user_id, $reset_wp_post_links );
	}

	public function import_json_string( string $json, int $user_id = 0, bool $reset_wp_post_links = true ): array {
		global $wpdb;

		$payload = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			throw new \RuntimeException( 'Invalid system snapshot JSON: ' . json_last_error_msg() );
		}

		$this->validate_legacy_payload( $payload );

		$tables       = Schema::table_names();
		$table_rows   = (array) $payload['tables'];
		$delete_order = array_reverse( array_keys( $tables ) );
		$inserted     = array();
		$started_at   = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( $delete_order as $key ) {
				$wpdb->query( "DELETE FROM {$tables[ $key ]}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$column_cache = array();
			foreach ( $tables as $key => $table ) {
				$column_cache[ $key ] = $this->table_columns( $table );
				$count = 0;

				foreach ( (array) $table_rows[ $key ] as $row ) {
					if ( ! is_array( $row ) ) {
						throw new \RuntimeException( 'Invalid row in snapshot table: ' . $key );
					}

					$this->insert_row( $key, $table, $row, $column_cache[ $key ], $reset_wp_post_links );
					++$count;
				}

				$inserted[ $key ] = $count;
			}

			$this->write_import_marker( $payload, $inserted, $user_id, $started_at, $reset_wp_post_links );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return $this->result_payload( $payload, $inserted, $reset_wp_post_links );
	}

	private function import_jsonl_file( string $path, int $user_id, bool $reset_wp_post_links ): array {
		global $wpdb;

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open system snapshot JSONL file.' );
		}

		$tables       = Schema::table_names();
		$delete_order = array_reverse( array_keys( $tables ) );
		$columns      = array();
		$inserted     = array_fill_keys( array_keys( $tables ), 0 );
		$manifest     = null;
		$line_number  = 0;
		$started_at   = current_time( 'mysql' );
		$saw_end      = false;

		$wpdb->query( 'START TRANSACTION' );

		try {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				++$line_number;
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				$record = json_decode( $line, true, 512, JSON_BIGINT_AS_STRING );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $record ) ) {
					throw new \RuntimeException( 'Invalid JSONL record at line ' . $line_number . ': ' . json_last_error_msg() );
				}

				$type = (string) ( $record['type'] ?? '' );
				if ( 'manifest' === $type ) {
					if ( null !== $manifest ) {
						throw new \RuntimeException( 'System snapshot JSONL contains more than one manifest.' );
					}

					$this->validate_jsonl_manifest( $record );
					$manifest = $record;

					foreach ( $delete_order as $key ) {
						$wpdb->query( "DELETE FROM {$tables[ $key ]}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}

					foreach ( $tables as $key => $table ) {
						$columns[ $key ] = $this->table_columns( $table );
					}
					continue;
				}

				if ( null === $manifest ) {
					throw new \RuntimeException( 'System snapshot JSONL must start with a manifest record.' );
				}

				if ( 'table' === $type || 'table_end' === $type ) {
					$table_key = (string) ( $record['table'] ?? '' );
					if ( ! array_key_exists( $table_key, $tables ) ) {
						throw new \RuntimeException( 'System snapshot JSONL references an unknown table: ' . $table_key );
					}
					continue;
				}

				if ( 'row' === $type ) {
					$table_key = (string) ( $record['table'] ?? '' );
					if ( ! array_key_exists( $table_key, $tables ) ) {
						throw new \RuntimeException( 'System snapshot JSONL row references an unknown table: ' . $table_key );
					}

					$row = $record['data'] ?? null;
					if ( ! is_array( $row ) ) {
						throw new \RuntimeException( 'System snapshot JSONL row is missing data at line ' . $line_number . '.' );
					}

					$this->insert_row( $table_key, $tables[ $table_key ], $row, $columns[ $table_key ], $reset_wp_post_links );
					++$inserted[ $table_key ];
					continue;
				}

				if ( 'end' === $type ) {
					$saw_end = true;
					continue;
				}

				throw new \RuntimeException( 'Unknown system snapshot JSONL record type at line ' . $line_number . ': ' . $type );
			}

			if ( null === $manifest ) {
				throw new \RuntimeException( 'System snapshot JSONL manifest was not found.' );
			}

			if ( ! $saw_end ) {
				throw new \RuntimeException( 'System snapshot JSONL end marker was not found.' );
			}

			$this->validate_imported_counts( (array) ( $manifest['counts'] ?? array() ), $inserted );
			$this->write_import_marker( $manifest, $inserted, $user_id, $started_at, $reset_wp_post_links );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			fclose( $handle );
			throw $e;
		}

		fclose( $handle );

		return $this->result_payload( $manifest, $inserted, $reset_wp_post_links );
	}

	private function validate_legacy_payload( array $payload ): void {
		if ( SystemSnapshotExportService::FORMAT !== (string) ( $payload['format'] ?? '' ) ) {
			throw new \RuntimeException( 'This JSON is not an MMA Future system snapshot export.' );
		}

		if ( 1 !== (int) ( $payload['format_version'] ?? 0 ) ) {
			throw new \RuntimeException( 'Unsupported legacy MMA Future system snapshot format version.' );
		}

		$this->validate_db_version( $payload );

		if ( empty( $payload['tables'] ) || ! is_array( $payload['tables'] ) ) {
			throw new \RuntimeException( 'System snapshot is missing tables.' );
		}

		$expected = array_keys( Schema::table_names() );
		foreach ( $expected as $key ) {
			if ( ! array_key_exists( $key, $payload['tables'] ) || ! is_array( $payload['tables'][ $key ] ) ) {
				throw new \RuntimeException( 'System snapshot is missing required table: ' . $key );
			}
		}
	}

	private function validate_jsonl_manifest( array $manifest ): void {
		if ( SystemSnapshotExportService::FORMAT !== (string) ( $manifest['format'] ?? '' ) ) {
			throw new \RuntimeException( 'This JSONL file is not an MMA Future system snapshot export.' );
		}

		if ( SystemSnapshotExportService::FORMAT_VERSION !== (int) ( $manifest['format_version'] ?? 0 ) ) {
			throw new \RuntimeException( 'Unsupported MMA Future system snapshot JSONL format version.' );
		}

		if ( 'jsonl' !== (string) ( $manifest['encoding'] ?? '' ) ) {
			throw new \RuntimeException( 'System snapshot manifest is not marked as JSONL.' );
		}

		$this->validate_db_version( $manifest );

		$expected = array_keys( Schema::table_names() );
		$actual   = array_map( 'strval', (array) ( $manifest['tables'] ?? array() ) );
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new \RuntimeException( 'System snapshot JSONL table list does not match this plugin schema.' );
		}
	}

	private function validate_db_version( array $payload ): void {
		$snapshot_db_version = (string) ( $payload['db_version'] ?? '' );
		$current_db_version  = defined( 'MMAF_DB_VERSION' ) ? MMAF_DB_VERSION : '';
		if ( '' !== $snapshot_db_version && '' !== $current_db_version && $snapshot_db_version !== $current_db_version ) {
			throw new \RuntimeException( 'System snapshot DB version does not match this plugin schema. Snapshot: ' . $snapshot_db_version . '; current: ' . $current_db_version . '.' );
		}
	}

	private function validate_imported_counts( array $expected, array $inserted ): void {
		foreach ( Schema::table_names() as $key => $table ) {
			unset( $table );
			$expected_count = (int) ( $expected[ $key ] ?? -1 );
			$actual_count   = (int) ( $inserted[ $key ] ?? 0 );
			if ( $expected_count !== $actual_count ) {
				throw new \RuntimeException( 'System snapshot imported row count mismatch for table ' . $key . '. Expected ' . $expected_count . ', inserted ' . $actual_count . '.' );
			}
		}
	}

	private function insert_row( string $key, string $table, array $row, array $columns, bool $reset_wp_post_links ): void {
		global $wpdb;

		$data = array_intersect_key( $row, array_flip( $columns ) );
		if ( 'fighters' === $key && $reset_wp_post_links && array_key_exists( 'wp_post_id', $data ) ) {
			$data['wp_post_id'] = null;
		}

		if ( empty( $data ) ) {
			return;
		}

		$result = $wpdb->insert( $table, $data );
		if ( false === $result ) {
			throw new \RuntimeException( 'Snapshot import failed for table ' . $key . ': ' . (string) $wpdb->last_error );
		}
	}

	private function table_columns( string $table ): array {
		global $wpdb;

		$rows = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			throw new \RuntimeException( 'Could not inspect table columns for snapshot import: ' . $table );
		}

		return array_values(
			array_filter(
				array_map(
					static fn( array $row ): string => (string) ( $row['Field'] ?? '' ),
					$rows
				)
			)
		);
	}

	private function first_non_empty_line( string $path ): string {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open system snapshot file.' );
		}

		try {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					return $line;
				}
			}
		} finally {
			fclose( $handle );
		}

		throw new \RuntimeException( 'System snapshot file is empty.' );
	}

	private function result_payload( array $payload, array $inserted, bool $reset_wp_post_links ): array {
		return array(
			'status'              => 'completed',
			'imported_at'         => current_time( 'mysql' ),
			'reset_wp_post_links' => $reset_wp_post_links,
			'source'              => array(
				'format_version' => (int) ( $payload['format_version'] ?? 0 ),
				'plugin_version' => (string) ( $payload['plugin_version'] ?? '' ),
				'db_version'     => (string) ( $payload['db_version'] ?? '' ),
				'exported_at'    => (string) ( $payload['exported_at'] ?? '' ),
				'exported_from'  => (array) ( $payload['exported_from'] ?? array() ),
			),
			'inserted'            => $inserted,
		);
	}

	private function write_import_marker( array $payload, array $inserted, int $user_id, string $started_at, bool $reset_wp_post_links ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$value  = wp_json_encode(
			array(
				'status'              => 'completed',
				'imported_at'         => current_time( 'mysql' ),
				'started_at'          => $started_at,
				'imported_by'         => $user_id,
				'reset_wp_post_links' => $reset_wp_post_links,
				'snapshot'            => array(
					'plugin_version' => (string) ( $payload['plugin_version'] ?? '' ),
					'db_version'     => (string) ( $payload['db_version'] ?? '' ),
					'exported_at'    => (string) ( $payload['exported_at'] ?? '' ),
					'exported_from'  => (array) ( $payload['exported_from'] ?? array() ),
				),
				'inserted'            => $inserted,
			),
			JSON_UNESCAPED_SLASHES
		);

		$wpdb->replace(
			$tables['system_state'],
			array(
				'state_key'   => 'last_system_snapshot_import',
				'state_value' => is_string( $value ) ? $value : '',
				'autoload'    => 'no',
				'updated_at'  => current_time( 'mysql' ),
			)
		);
	}
}
