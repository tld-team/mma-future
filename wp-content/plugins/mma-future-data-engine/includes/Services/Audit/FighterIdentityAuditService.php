<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\TapologyFighterUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterIdentityAuditService {
	private array $tables;

	public function __construct() {
		$this->tables = Schema::table_names();
	}

	public function build_report( int $limit = 100 ): array {
		$limit = max( 1, min( 250, $limit ) );

		return array(
			'summary' => array(
				'manual_legacy_without_tapology_count'       => $this->manual_without_tapology_count(),
				'duplicate_source_fighter_id_groups_count'   => count( $this->duplicate_source_fighter_ids( 500 ) ),
				'duplicate_normalized_source_url_groups_count'=> count( $this->duplicate_normalized_source_urls( 500 ) ),
				'possible_legacy_tapology_pairs_count'       => count( $this->possible_legacy_tapology_pairs( 500 ) ),
			),
			'manual_legacy_without_tapology' => $this->manual_without_tapology_rows( $limit ),
			'duplicate_source_fighter_ids'   => $this->duplicate_source_fighter_ids( $limit ),
			'duplicate_normalized_source_urls'=> $this->duplicate_normalized_source_urls( $limit ),
			'possible_legacy_tapology_pairs' => $this->possible_legacy_tapology_pairs( $limit ),
			'recent_without_tapology_source' => $this->recent_without_tapology_source( $limit ),
		);
	}

	private function manual_without_tapology_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$this->tables['fighters']} f
			WHERE f.deleted_soft = 0
				AND NOT EXISTS (
					SELECT 1 FROM {$this->tables['fighter_sources']} fs
					WHERE fs.fighter_id = f.id AND fs.source_type = 'tapology'
				)
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function manual_without_tapology_rows( int $limit ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					f.id,
					f.display_name,
					f.status,
					f.rankability_status,
					f.is_public,
					f.is_rankable,
					f.wp_post_id,
					f.date_of_birth,
					f.birth_year,
					f.nationality,
					f.gender,
					f.weight_class,
					GROUP_CONCAT(DISTINCT fs.source_type ORDER BY fs.source_type SEPARATOR ', ') AS source_type,
					GROUP_CONCAT(DISTINCT fs.source_fighter_id ORDER BY fs.source_type SEPARATOR ', ') AS source_fighter_id,
					GROUP_CONCAT(DISTINCT fs.source_url ORDER BY fs.source_type SEPARATOR ' | ') AS source_url,
					COALESCE(st.wins, 0) AS wins,
					COALESCE(st.losses, 0) AS losses,
					COALESCE(st.draws, 0) AS draws,
					COALESCE(st.nc, 0) AS nc,
					st.last_fight_date,
					'Manual/legacy fighter has no Tapology identity mapping.' AS flag_reason,
					'attach Tapology source if confirmed' AS recommended_action
				FROM {$this->tables['fighters']} f
				LEFT JOIN {$this->tables['fighter_sources']} fs ON fs.fighter_id = f.id
				LEFT JOIN {$this->tables['fighter_stats_current']} st ON st.fighter_id = f.id
				WHERE f.deleted_soft = 0
					AND NOT EXISTS (
						SELECT 1 FROM {$this->tables['fighter_sources']} tfs
						WHERE tfs.fighter_id = f.id AND tfs.source_type = 'tapology'
					)
				GROUP BY f.id, st.id
				ORDER BY f.is_public DESC, f.display_name ASC
				LIMIT %d
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);
	}

	private function duplicate_source_fighter_ids( int $limit ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					source_type,
					source_fighter_id,
					COUNT(*) AS rows_count,
					COUNT(DISTINCT fighter_id) AS fighter_count,
					GROUP_CONCAT(fighter_id ORDER BY fighter_id SEPARATOR ', ') AS fighter_ids,
					GROUP_CONCAT(source_url ORDER BY fighter_id SEPARATOR ' | ') AS source_urls,
					'data error: duplicate source mapping' AS recommended_action
				FROM {$this->tables['fighter_sources']}
				WHERE source_fighter_id IS NOT NULL AND source_fighter_id <> ''
				GROUP BY source_type, source_fighter_id
				HAVING rows_count > 1 OR fighter_count > 1
				ORDER BY rows_count DESC, source_type ASC
				LIMIT %d
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);
	}

	private function duplicate_normalized_source_urls( int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"
			SELECT source_type, source_url, fighter_id, source_fighter_id
			FROM {$this->tables['fighter_sources']}
			WHERE source_url IS NOT NULL AND source_url <> ''
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$groups = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['source_type'] . '|' . TapologyFighterUrl::normalize( (string) $row['source_url'] );
			$groups[ $key ][] = $row;
		}

		$duplicates = array();
		foreach ( $groups as $key => $group_rows ) {
			$url_variants = array();
			$fighter_ids = array();
			foreach ( $group_rows as $row ) {
				$url_variants[ (string) $row['source_url'] ] = true;
				$fighter_ids[ (int) $row['fighter_id'] ] = true;
			}

			if ( count( $group_rows ) <= 1 && count( $url_variants ) <= 1 && count( $fighter_ids ) <= 1 ) {
				continue;
			}

			$duplicates[] = array(
				'normalized_source_url' => substr( $key, strpos( $key, '|' ) + 1 ),
				'rows_count'            => count( $group_rows ),
				'url_variants'          => count( $url_variants ),
				'fighter_count'         => count( $fighter_ids ),
				'fighter_ids'           => implode( ', ', array_keys( $fighter_ids ) ),
				'source_urls'           => implode( ' | ', array_keys( $url_variants ) ),
				'recommended_action'    => 'data error: duplicate source mapping',
			);
		}

		return array_slice( $duplicates, 0, $limit );
	}

	private function possible_legacy_tapology_pairs( int $limit ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					l.id AS legacy_fighter_id,
					l.display_name AS legacy_display_name,
					l.status AS legacy_status,
					l.rankability_status AS legacy_rankability_status,
					l.is_public AS legacy_is_public,
					l.is_rankable AS legacy_is_rankable,
					l.nationality AS legacy_nationality,
					l.birth_year AS legacy_birth_year,
					l.gender AS legacy_gender,
					l.weight_class AS legacy_weight_class,
					ls.source_fighter_id AS legacy_source_fighter_id,
					ls.source_url AS legacy_source_url,
					t.id AS tapology_fighter_id,
					t.display_name AS tapology_display_name,
					t.status AS tapology_status,
					t.rankability_status AS tapology_rankability_status,
					t.is_public AS tapology_is_public,
					t.is_rankable AS tapology_is_rankable,
					t.nationality AS tapology_nationality,
					t.birth_year AS tapology_birth_year,
					t.gender AS tapology_gender,
					t.weight_class AS tapology_weight_class,
					ts.source_fighter_id AS tapology_source_fighter_id,
					ts.source_url AS tapology_source_url,
					'same surname + first initial; not identity proof' AS flag_reason,
					'needs research' AS recommended_action
				FROM {$this->tables['fighters']} l
				INNER JOIN {$this->tables['fighter_sources']} ls ON ls.fighter_id = l.id AND ls.source_type <> 'tapology'
				INNER JOIN {$this->tables['fighters']} t ON t.deleted_soft = 0
				INNER JOIN {$this->tables['fighter_sources']} ts ON ts.fighter_id = t.id AND ts.source_type = 'tapology'
				WHERE l.deleted_soft = 0
					AND l.normalized_name <> t.normalized_name
					AND LEFT(l.normalized_name, 1) = LEFT(t.normalized_name, 1)
					AND SUBSTRING_INDEX(l.normalized_name, ' ', -1) = SUBSTRING_INDEX(t.normalized_name, ' ', -1)
				ORDER BY l.display_name ASC, t.display_name ASC
				LIMIT %d
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);
	}

	private function recent_without_tapology_source( int $limit ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					a.id AS audit_id,
					a.action,
					a.entity_id AS fighter_id,
					f.display_name,
					f.status,
					f.rankability_status,
					f.is_public,
					f.is_rankable,
					a.created_at,
					'Created/edited fighter currently has no Tapology source mapping.' AS flag_reason,
					'attach Tapology source if confirmed' AS recommended_action
				FROM {$this->tables['audit_log']} a
				INNER JOIN {$this->tables['fighters']} f ON f.id = a.entity_id
				WHERE a.entity_type = 'fighter'
					AND a.action IN ('fighter_created', 'fighter_updated', 'legacy_fighter_imported')
					AND NOT EXISTS (
						SELECT 1 FROM {$this->tables['fighter_sources']} fs
						WHERE fs.fighter_id = f.id AND fs.source_type = 'tapology'
					)
				ORDER BY a.created_at DESC, a.id DESC
				LIMIT %d
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);
	}
}
