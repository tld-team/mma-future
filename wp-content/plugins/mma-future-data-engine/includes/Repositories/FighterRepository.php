<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterRepository {
	private string $table;
	private string $sources_table;

	public function __construct() {
		$tables              = Schema::table_names();
		$this->table         = $tables['fighters'];
		$this->sources_table = $tables['fighter_sources'];
	}

	public function find( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function list( string $search = '', int $limit = 50, int $offset = 0, array $filters = array() ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$where = $this->where_clause( $search, $filters );
		$args  = array_merge( $where['args'], array( $limit, $offset ) );
		$order = 'newest' === ( $filters['sort'] ?? '' ) ? 'f.created_at DESC, f.id DESC' : 'f.updated_at DESC, f.created_at DESC, f.id DESC';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} f {$where['sql']} ORDER BY {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			),
			ARRAY_A
		);
	}

    public function find_many(array $ids): array {
        global $wpdb; $ids=array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0));
        if (!$ids) { return []; }
        return $wpdb->get_results("SELECT * FROM {$this->table} WHERE id IN (".implode(',',$ids).") ORDER BY id", ARRAY_A);
    }
    /** Safe reusable selection SQL for a single INSERT ... SELECT freeze. */
    public function selection_sql(string $search,array $filters): string {
        global $wpdb; $where=$this->where_clause($search,$filters);
        $sql="SELECT f.id FROM {$this->table} f {$where['sql']}";
        return $where['args'] ? $wpdb->prepare($sql,$where['args']) : $sql;
    }

	public function list_for_select( int $limit = 1000 ): array {
		global $wpdb;

		$limit = max( 1, min( 2000, $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT id, display_name, nickname, weight_class FROM {$this->table} ORDER BY display_name ASC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function count( string $search = '', array $filters = array() ): int {
		global $wpdb;

		$where = $this->where_clause( $search, $filters );

		if ( '' === $where['sql'] ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} f" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( empty( $where['args'] ) ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} f {$where['sql']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} f {$where['sql']}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where['args']
			)
		);
	}

	public function where_clause( string $search, array $filters ): array {
		global $wpdb;

		$where = array();
		$args  = array();

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(f.display_name LIKE %s OR f.normalized_name LIKE %s OR f.nickname LIKE %s OR CAST(f.id AS CHAR) = %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
            $args[] = ltrim($search, '#');
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'f.status = %s';
			$args[]  = (string) $filters['status'];
		}

		if ( ! empty( $filters['rankability_status'] ) ) {
			$where[] = 'f.rankability_status = %s';
			$args[]  = (string) $filters['rankability_status'];
		}

		if ( 'public' === ( $filters['public_state'] ?? '' ) ) {
			$where[] = 'f.is_public = 1';
		} elseif ( 'not_public' === ( $filters['public_state'] ?? '' ) ) {
			$where[] = 'f.is_public = 0';
		}

		if ( 'rankable' === ( $filters['rankable_state'] ?? '' ) ) {
			$where[] = 'f.is_rankable = 1';
		} elseif ( 'not_rankable' === ( $filters['rankable_state'] ?? '' ) ) {
			$where[] = 'f.is_rankable = 0';
		}

        $operations=\MMAF\DataEngine\Services\Operations\Schema::tables();
        $coverage=Schema::table_names()['fighter_career_coverage'];
        foreach (['gender','weight_class'] as $field) {
            if (!empty($filters[$field])) { $where[]="f.$field = %s"; $args[]=(string)$filters[$field]; }
        }
        if (($filters['import_job']??'')==='legacy') {
            $where[]="NOT EXISTS (SELECT 1 FROM {$operations['membership']} m WHERE m.fighter_id=f.id)";
        } elseif (!empty($filters['import_job'])) {
            $where[]="EXISTS (SELECT 1 FROM {$operations['membership']} m WHERE m.fighter_id=f.id AND m.import_job_id=%d)"; $args[]=(int)$filters['import_job'];
        }
        if (!empty($filters['preparation'])) { $where[]="EXISTS (SELECT 1 FROM {$operations['readiness']} r WHERE r.fighter_id=f.id AND r.preparation_state=%s)"; $args[]=(string)$filters['preparation']; }
        if (!empty($filters['reason'])) { $where[]="EXISTS (SELECT 1 FROM {$operations['readiness']} r WHERE r.fighter_id=f.id AND r.reason_code=%s)"; $args[]=(string)$filters['reason']; }
        if (!empty($filters['coverage'])) { $where[]="EXISTS (SELECT 1 FROM $coverage c WHERE c.fighter_id=f.id AND c.status=%s)"; $args[]=(string)$filters['coverage']; }

		switch ( (string) ( $filters['readiness_issue'] ?? '' ) ) {
			case 'missing_dob':
				$where[] = "(f.date_of_birth IS NULL OR f.date_of_birth = '' OR f.date_of_birth = '0000-00-00') AND (f.birth_year IS NULL OR f.birth_year = 0)";
				break;
			case 'missing_weight_class':
				$where[] = "(f.weight_class IS NULL OR f.weight_class = '' OR f.weight_class = 'unknown')";
				break;
			case 'provisional_tapology':
				$where[] = "f.status = 'provisional' AND EXISTS (SELECT 1 FROM {$this->sources_table} fs WHERE fs.fighter_id = f.id AND fs.source_type = 'tapology')";
				break;
		}

		return array(
			'sql'  => empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where ),
			'args' => $args,
		);
	}

	public function linked_post_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE wp_post_id IS NOT NULL AND wp_post_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function insert( array $data ): int {
		global $wpdb;

		$data['created_at'] = DateTime::mysql_now();
		$data['updated_at'] = null;

		$wpdb->insert( $this->table, $data );

		return (int) $wpdb->insert_id;
	}

	public function update( int $fighter_id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = DateTime::mysql_now();

		return false !== $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $fighter_id ),
			null,
			array( '%d' )
		);
	}

	public function update_wp_post_id( int $fighter_id, int $post_id ): bool {
		return $this->update(
			$fighter_id,
			array(
				'wp_post_id' => $post_id,
			)
		);
	}

	public function wp_post_exists( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post && 'trash' !== $post->post_status;
	}
}
