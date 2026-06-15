<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BoutRepository {
	private string $table;
	private string $events_table;
	private string $participants_table;
	private string $fighters_table;

	public function __construct() {
		$tables                   = Schema::table_names();
		$this->table              = $tables['bouts'];
		$this->events_table       = $tables['events'];
		$this->participants_table = $tables['bout_participants'];
		$this->fighters_table     = $tables['fighters'];
	}

	public function find( int $bout_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $bout_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function list( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $filters['event_id'] ) ) {
			$where[]  = 'b.event_id = %d';
			$values[] = (int) $filters['event_id'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'b.status = %s';
			$values[] = (string) $filters['status'];
		}

		if ( ! empty( $filters['fighter'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['fighter'] ) . '%';
			$where[]  = '(fa.display_name LIKE %s OR fb.display_name LIKE %s OR fa.normalized_name LIKE %s OR fb.normalized_name LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$sql = "
			SELECT
				b.*,
				e.event_name,
				e.event_date,
				e.promotion_name,
				pa.fighter_id AS fighter_a_id,
				fa.display_name AS fighter_a_name,
				pb.fighter_id AS fighter_b_id,
				fb.display_name AS fighter_b_name,
				pa.result_for_fighter AS fighter_a_result,
				pb.result_for_fighter AS fighter_b_result
			FROM {$this->table} b
			LEFT JOIN {$this->events_table} e ON e.id = b.event_id
			LEFT JOIN {$this->participants_table} pa ON pa.bout_id = b.id AND pa.participant_role = 'fighter_a'
			LEFT JOIN {$this->participants_table} pb ON pb.bout_id = b.id AND pb.participant_role = 'fighter_b'
			LEFT JOIN {$this->fighters_table} fa ON fa.id = pa.fighter_id
			LEFT JOIN {$this->fighters_table} fb ON fb.id = pb.fighter_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY e.event_date DESC, b.bout_order ASC, b.id DESC
			LIMIT %d OFFSET %d
		";

		$values[] = $limit;
		$values[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function count_filtered( array $filters = array() ): int {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $filters['event_id'] ) ) {
			$where[]  = 'b.event_id = %d';
			$values[] = (int) $filters['event_id'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'b.status = %s';
			$values[] = (string) $filters['status'];
		}

		if ( ! empty( $filters['fighter'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['fighter'] ) . '%';
			$where[]  = '(fa.display_name LIKE %s OR fb.display_name LIKE %s OR fa.normalized_name LIKE %s OR fb.normalized_name LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$sql = "
			SELECT COUNT(DISTINCT b.id)
			FROM {$this->table} b
			LEFT JOIN {$this->participants_table} pa ON pa.bout_id = b.id AND pa.participant_role = 'fighter_a'
			LEFT JOIN {$this->participants_table} pb ON pb.bout_id = b.id AND pb.participant_role = 'fighter_b'
			LEFT JOIN {$this->fighters_table} fa ON fa.id = pa.fighter_id
			LEFT JOIN {$this->fighters_table} fb ON fb.id = pb.fighter_id
			WHERE " . implode( ' AND ', $where );

		if ( empty( $values ) ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function insert( array $data ): int {
		global $wpdb;

		$data['created_at'] = DateTime::mysql_now();
		$data['updated_at'] = null;

		$wpdb->insert( $this->table, $data );

		return (int) $wpdb->insert_id;
	}

	public function update( int $bout_id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = DateTime::mysql_now();

		return false !== $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $bout_id ),
			null,
			array( '%d' )
		);
	}

	public function find_duplicate( int $event_id, int $fighter_a_id, int $fighter_b_id, array $bout, int $exclude_bout_id = 0 ): ?array {
		global $wpdb;

		$low_id  = min( $fighter_a_id, $fighter_b_id );
		$high_id = max( $fighter_a_id, $fighter_b_id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"
				SELECT b.*
				FROM {$this->table} b
				INNER JOIN {$this->participants_table} p1 ON p1.bout_id = b.id AND p1.fighter_id = %d
				INNER JOIN {$this->participants_table} p2 ON p2.bout_id = b.id AND p2.fighter_id = %d
				WHERE b.event_id = %d
					AND b.id <> %d
					AND COALESCE(b.result_type, '') = %s
					AND COALESCE(b.method_category, '') = %s
					AND COALESCE(b.method_detail, '') = %s
					AND COALESCE(b.round_number, 0) = %d
					AND COALESCE(b.time_in_round, '') = %s
				LIMIT 1
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$low_id,
				$high_id,
				$event_id,
				$exclude_bout_id,
				(string) ( $bout['result_type'] ?? '' ),
				(string) ( $bout['method_category'] ?? '' ),
				(string) ( $bout['method_detail'] ?? '' ),
				(int) ( $bout['round_number'] ?? 0 ),
				(string) ( $bout['time_in_round'] ?? '' )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
