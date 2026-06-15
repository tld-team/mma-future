<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SystemSnapshotExportService {
	public const FORMAT = 'mmaf-system-snapshot';
	public const FORMAT_VERSION = 2;
	private const CHUNK_SIZE = 250;

	public function stream_jsonl(): void {
		$tables = Schema::table_names();
		$counts = $this->table_counts( $tables );

		$this->write_line(
			array(
				'type'           => 'manifest',
				'format'         => self::FORMAT,
				'format_version' => self::FORMAT_VERSION,
				'encoding'       => 'jsonl',
				'plugin_version' => defined( 'MMAF_PLUGIN_VERSION' ) ? MMAF_PLUGIN_VERSION : '',
				'db_version'     => defined( 'MMAF_DB_VERSION' ) ? MMAF_DB_VERSION : '',
				'exported_at'    => current_time( 'mysql' ),
				'exported_from'  => array(
					'home_url' => home_url(),
					'site_url' => site_url(),
				),
				'counts'         => $counts,
				'tables'         => array_keys( $tables ),
			)
		);

		foreach ( $tables as $key => $table ) {
			$this->write_line(
				array(
					'type'  => 'table',
					'table' => $key,
					'count' => (int) ( $counts[ $key ] ?? 0 ),
				)
			);

			$this->stream_table_rows( $key, $table );

			$this->write_line(
				array(
					'type'  => 'table_end',
					'table' => $key,
				)
			);
		}

		$this->write_line(
			array(
				'type'   => 'end',
				'counts' => $counts,
			)
		);
	}

	private function table_counts( array $tables ): array {
		global $wpdb;

		$counts = array();
		foreach ( $tables as $key => $table ) {
			$counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $counts;
	}

	private function stream_table_rows( string $key, string $table ): void {
		global $wpdb;

		$offset = 0;
		do {
			$query = $wpdb->prepare( "SELECT * FROM {$table} LIMIT %d OFFSET %d", self::CHUNK_SIZE, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $query, ARRAY_A );
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}

			foreach ( $rows as $row ) {
				$this->write_line(
					array(
						'type'  => 'row',
						'table' => $key,
						'data'  => $row,
					)
				);
			}

			$offset += self::CHUNK_SIZE;
		} while ( count( $rows ) === self::CHUNK_SIZE );
	}

	private function write_line( array $payload ): void {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) {
			throw new \RuntimeException( 'Could not encode system snapshot JSONL line.' );
		}

		echo $json . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}
}
