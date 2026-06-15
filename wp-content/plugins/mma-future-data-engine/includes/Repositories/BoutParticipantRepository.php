<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BoutParticipantRepository {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['bout_participants'];
	}

	public function list_by_bout( int $bout_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE bout_id = %d ORDER BY participant_role ASC, id ASC", $bout_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function replace_exactly_two( int $bout_id, array $participants ): void {
		global $wpdb;

		if ( 2 !== count( $participants ) ) {
			throw new \InvalidArgumentException( __( 'Exactly two bout participants are required.', 'mma-future-data-engine' ) );
		}

		$now = DateTime::mysql_now();
		$keep_ids = array();

		foreach ( $participants as $participant ) {
			$role = (string) ( $participant['participant_role'] ?? '' );
			if ( ! in_array( $role, array( 'fighter_a', 'fighter_b' ), true ) ) {
				throw new \InvalidArgumentException( __( 'Bout participant role must be fighter_a or fighter_b.', 'mma-future-data-engine' ) );
			}

			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->table} WHERE bout_id = %d AND participant_role = %s ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$bout_id,
					$role
				)
			);

			$participant['bout_id'] = $bout_id;

			if ( $existing_id > 0 ) {
				$participant['updated_at'] = $now;
				$wpdb->update(
					$this->table,
					$participant,
					array( 'id' => $existing_id ),
					null,
					array( '%d' )
				);
				$keep_ids[] = $existing_id;
				continue;
			}

			$participant['created_at'] = $now;
			$participant['updated_at'] = null;
			$wpdb->insert( $this->table, $participant );
			$keep_ids[] = (int) $wpdb->insert_id;
		}

		if ( ! empty( $keep_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE bout_id = %d AND id NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $bout_id ), $keep_ids )
				)
			);
		}

		$this->assert_exactly_two( $bout_id );
	}

	public function assert_exactly_two( int $bout_id ): void {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE bout_id = %d", $bout_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( 2 !== $count ) {
			throw new \RuntimeException( __( 'Bout participant integrity check failed: saved bout does not have exactly two participant rows.', 'mma-future-data-engine' ) );
		}
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function malformed_bout_count(): int {
		global $wpdb;

		$tables = Schema::table_names();
		$bouts  = $tables['bouts'];

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$bouts} b
			LEFT JOIN (
				SELECT bout_id, COUNT(*) AS participant_count
				FROM {$this->table}
				GROUP BY bout_id
			) p ON p.bout_id = b.id
			WHERE COALESCE(p.participant_count, 0) <> 2
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
