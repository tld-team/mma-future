<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterStatsOverrideRepository {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['fighter_stats_overrides'];
	}

	public function find_for_fighter( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE fighter_id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function find_active_for_fighter( int $fighter_id ): ?array {
		$row = $this->find_for_fighter( $fighter_id );

		return $row && 1 === (int) ( $row['is_active'] ?? 0 ) ? $row : null;
	}

	public function upsert( int $fighter_id, array $record, string $reason, string $calculated_stats_hash, int $user_id ): array {
		global $wpdb;

		$now      = DateTime::mysql_now();
		$existing = $this->find_for_fighter( $fighter_id );
		$row      = array(
			'fighter_id'             => $fighter_id,
			'wins'                   => (int) $record['wins'],
			'losses'                 => (int) $record['losses'],
			'draws'                  => (int) $record['draws'],
			'nc'                     => (int) $record['nc'],
			'display_record_raw'     => self::record_string( $record ),
			'reason'                 => $reason,
			'calculated_stats_hash'  => $calculated_stats_hash,
			'is_active'              => 1,
			'updated_by'             => $user_id > 0 ? $user_id : null,
			'updated_at'             => $now,
			'cleared_by'             => null,
			'cleared_at'             => null,
		);

		if ( $existing ) {
			$updated = $wpdb->update(
				$this->table,
				$row,
				array( 'id' => (int) $existing['id'] ),
				null,
				array( '%d' )
			);

			if ( false === $updated ) {
				throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not update fighter stats override.', 'mma-future-data-engine' ) );
			}

			return (array) $this->find_for_fighter( $fighter_id );
		}

		$row['created_by'] = $user_id > 0 ? $user_id : null;
		$row['created_at'] = $now;

		$inserted = $wpdb->insert( $this->table, $row );
		if ( false === $inserted ) {
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not create fighter stats override.', 'mma-future-data-engine' ) );
		}

		return (array) $this->find_for_fighter( $fighter_id );
	}

	public function clear( int $fighter_id, int $user_id ): ?array {
		global $wpdb;

		$existing = $this->find_for_fighter( $fighter_id );
		if ( ! $existing || 1 !== (int) ( $existing['is_active'] ?? 0 ) ) {
			return $existing;
		}

		$updated = $wpdb->update(
			$this->table,
			array(
				'is_active'  => 0,
				'updated_by' => $user_id > 0 ? $user_id : null,
				'cleared_by' => $user_id > 0 ? $user_id : null,
				'updated_at' => DateTime::mysql_now(),
				'cleared_at' => DateTime::mysql_now(),
			),
			array( 'id' => (int) $existing['id'] ),
			null,
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not clear fighter stats override.', 'mma-future-data-engine' ) );
		}

		return $this->find_for_fighter( $fighter_id );
	}

	public static function record_string( array $record ): string {
		return sprintf(
			'%d-%d-%d-%d',
			(int) ( $record['wins'] ?? 0 ),
			(int) ( $record['losses'] ?? 0 ),
			(int) ( $record['draws'] ?? 0 ),
			(int) ( $record['nc'] ?? 0 )
		);
	}

	public static function calculated_stats_hash( ?array $stats ): string {
		if ( ! $stats ) {
			return hash( 'sha256', 'missing_stats' );
		}

		return hash(
			'sha256',
			wp_json_encode(
				array(
					'wins'             => (int) ( $stats['wins'] ?? 0 ),
					'losses'           => (int) ( $stats['losses'] ?? 0 ),
					'draws'            => (int) ( $stats['draws'] ?? 0 ),
					'nc'               => (int) ( $stats['nc'] ?? 0 ),
					'pro_fights_count' => (int) ( $stats['pro_fights_count'] ?? 0 ),
					'last_fight_date'  => (string) ( $stats['last_fight_date'] ?? '' ),
				)
			)
		);
	}
}
