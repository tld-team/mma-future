<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterAliasRepository {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['fighter_aliases'];
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function list_by_fighter( int $fighter_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE fighter_id = %d ORDER BY alias ASC", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function replace_for_fighter( int $fighter_id, array $aliases ): void {
		global $wpdb;

		$now      = DateTime::mysql_now();
		$existing = $this->list_by_fighter( $fighter_id );
		$by_norm  = array();

		foreach ( $existing as $row ) {
			$by_norm[ (string) $row['normalized_alias'] ] = $row;
		}

		foreach ( $aliases as $alias ) {
			if ( isset( $by_norm[ $alias['normalized_alias'] ] ) ) {
				$wpdb->update(
					$this->table,
					array(
						'alias'       => $alias['alias'],
						'source_type' => 'manual',
						'is_verified' => 1,
						'updated_at'  => $now,
					),
					array( 'id' => (int) $by_norm[ $alias['normalized_alias'] ]['id'] ),
					null,
					array( '%d' )
				);

				continue;
			}

			$wpdb->insert(
				$this->table,
				array(
					'fighter_id'        => $fighter_id,
					'alias'             => $alias['alias'],
					'normalized_alias'  => $alias['normalized_alias'],
					'source_type'       => 'manual',
					'is_verified'       => 1,
					'created_at'        => $now,
					'updated_at'        => null,
				)
			);
		}
	}
}
