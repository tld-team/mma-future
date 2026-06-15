<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\RankingCurrentRepository;
use MMAF\DataEngine\Repositories\RankingRunRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterEnrichmentAuditService {
	private const RECENT_FIGHT_MONTHS = 18;

	public const FILTERS = array(
		'missing_dob_birth_year',
		'missing_gender',
		'missing_weight_class',
		'missing_nationality',
		'has_tapology_source',
		'no_tapology_source',
		'has_fights',
		'no_fights',
		'public_not_rankable',
		'scraped_provisional',
		'legacy_public',
		'possible_ranking_candidate',
		'duplicate_conflict_signal',
	);

	public function build_report( array $filters, string $search, int $per_page, int $offset ): array {
		$filters = array_values( array_intersect( $filters, self::FILTERS ) );

		return array(
			'summary' => $this->summary(),
			'total'   => $this->count_rows( $filters, $search ),
			'rows'    => $this->rows( $filters, $search, $per_page, $offset ),
		);
	}

	public function summary(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$runs   = new RankingRunRepository();
		$current = new RankingCurrentRepository();
		$last_ranking_summary = $runs->get_last_calculation_summary();
		$duplicates = ( new FighterDuplicateAuditService() )->audit( 1 );

		return array(
			'total_fighters'                              => $this->count_where( $tables['fighters'], 'deleted_soft = 0' ),
			'public_fighters'                             => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND is_public = 1' ),
			'non_public_fighters'                         => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND is_public = 0' ),
			'rankable_fighters'                           => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND is_rankable = 1' ),
			'provisional_fighters'                        => $this->count_where( $tables['fighters'], "deleted_soft = 0 AND status = 'provisional'" ),
			'pending_review_fighters'                     => $this->count_where( $tables['fighters'], "deleted_soft = 0 AND rankability_status = 'pending_review'" ),
			'fighters_with_tapology_source_mapping'       => $this->count_with_tapology_source(),
			'fighters_without_tapology_source_mapping'    => $this->count_without_tapology_source(),
			'fighters_with_linked_wp_posts'               => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND wp_post_id IS NOT NULL AND wp_post_id > 0' ),
			'fighters_missing_dob_and_birth_year'         => $this->count_where( $tables['fighters'], 'deleted_soft = 0 AND date_of_birth IS NULL AND birth_year IS NULL' ),
			'fighters_missing_gender'                     => $this->count_where( $tables['fighters'], "deleted_soft = 0 AND (gender IS NULL OR gender = '' OR gender = 'unknown')" ),
			'fighters_missing_canonical_weight_class'     => $this->count_where( $tables['fighters'], "deleted_soft = 0 AND (weight_class IS NULL OR weight_class = '' OR weight_class = 'unknown')" ),
			'fighters_missing_nationality'                => $this->count_where( $tables['fighters'], "deleted_soft = 0 AND (nationality IS NULL OR nationality = '')" ),
			'fighters_with_no_fights'                     => $this->count_by_stats_condition( 'COALESCE(st.pro_fights_count, 0) = 0' ),
			'fighters_with_countable_fights'              => $this->count_by_stats_condition( 'COALESCE(st.pro_fights_count, 0) > 0' ),
			'fighters_with_multiple_countable_fights'     => $this->count_by_stats_condition( 'COALESCE(st.pro_fights_count, 0) > 1' ),
			'fighters_with_winning_record'                => $this->count_by_stats_condition( 'COALESCE(st.wins, 0) > COALESCE(st.losses, 0) AND COALESCE(st.pro_fights_count, 0) > 0' ),
			'fighters_with_recent_fight_date'             => $this->count_by_stats_condition( 'st.last_fight_date IS NOT NULL AND st.last_fight_date >= DATE_SUB(CURDATE(), INTERVAL ' . self::RECENT_FIGHT_MONTHS . ' MONTH)' ),
			'recent_fight_window_months'                  => self::RECENT_FIGHT_MONTHS,
			'fighters_with_stats_warnings'                => $this->count_by_stats_condition( "st.warnings_json IS NOT NULL AND st.warnings_json <> '' AND st.warnings_json <> '[]'" ),
			'duplicate_candidate_count_if_available'      => (int) ( $duplicates['likely_duplicates_count'] ?? 0 ),
			'import_conflict_count_if_available'          => $this->count_import_items_by_status( 'conflict' ),
			'import_needs_review_count_if_available'      => $this->count_import_needs_review(),
			'current_active_ranking_run_id'               => $runs->get_active_ranking_run_id(),
			'current_ranking_rows_count'                  => $current->current_count(),
			'latest_draft_eligible_fighters_count_if_available' => is_array( $last_ranking_summary ) ? (int) ( $last_ranking_summary['eligible_fighters'] ?? 0 ) : 0,
			'read_only_note'                              => __( 'This report reads existing fighter, source, bout, stats, import, review, and ranking-status tables only. It does not write canonical data or recalculate rankings.', 'mma-future-data-engine' ),
		);
	}

	private function count_rows( array $filters, string $search ): int {
		global $wpdb;

		$base = $this->base_sql( $filters, $search );
		$sql  = 'SELECT COUNT(*) ' . $base['from'] . ' WHERE ' . implode( ' AND ', $base['where'] );

		if ( ! empty( $base['args'] ) ) {
			$sql = $wpdb->prepare( $sql, $base['args'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function rows( array $filters, string $search, int $per_page, int $offset ): array {
		global $wpdb;

		$base = $this->base_sql( $filters, $search );
		$priority = $this->priority_sql();
		$sql = "
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
				f.gender,
				f.weight_class,
				f.nationality,
				COALESCE(st.wins, 0) AS wins,
				COALESCE(st.losses, 0) AS losses,
				COALESCE(st.draws, 0) AS draws,
				COALESCE(st.nc, 0) AS nc,
				COALESCE(st.pro_fights_count, 0) AS countable_fights,
				COALESCE(st.finish_wins, 0) AS finish_wins,
				st.finish_rate,
				st.last_fight_date,
				st.warnings_json,
				COALESCE(src.source_mapping_count, 0) AS source_mapping_count,
				COALESCE(src.tapology_source_count, 0) AS tapology_source_count,
				COALESCE(src.legacy_source_count, 0) AS legacy_source_count,
				src.source_types,
				src.tapology_source_fighter_id,
				src.tapology_source_url,
				bw.latest_bout_weight_class,
				bwd.bout_weight_distribution,
				COALESCE(sig.duplicate_name_count, 0) AS duplicate_name_count,
				COALESCE(sig.source_conflict_count, 0) AS source_conflict_count,
				COALESCE(imp.import_conflict_count, 0) AS import_conflict_count,
				COALESCE(imp.import_needs_review_count, 0) AS import_needs_review_count,
				COALESCE(ri.open_review_count, 0) AS open_review_count,
				{$priority} AS review_priority
			" . $base['from'] . '
			WHERE ' . implode( ' AND ', $base['where'] ) . '
			ORDER BY review_priority DESC, COALESCE(st.pro_fights_count, 0) DESC, st.last_fight_date DESC, f.display_name ASC, f.id ASC
			LIMIT %d OFFSET %d';

		$args = array_merge( $base['args'], array( $per_page, $offset ) );
		$sql  = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'prepare_row' ), $rows );
	}

	private function base_sql( array $filters, string $search ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$where = array( 'f.deleted_soft = 0' );
		$args  = array();

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(f.display_name LIKE %s OR f.normalized_name LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		foreach ( $filters as $filter ) {
			$condition = $this->filter_condition( $filter );
			if ( '' !== $condition ) {
				$where[] = $condition;
			}
		}

		$from = "
			FROM {$tables['fighters']} f
			LEFT JOIN {$tables['fighter_stats_current']} st ON st.fighter_id = f.id
			LEFT JOIN (
				SELECT
					fighter_id,
					COUNT(*) AS source_mapping_count,
					SUM(CASE WHEN source_type = 'tapology' THEN 1 ELSE 0 END) AS tapology_source_count,
					SUM(CASE WHEN source_type = 'legacy_wp_export' THEN 1 ELSE 0 END) AS legacy_source_count,
					GROUP_CONCAT(DISTINCT source_type ORDER BY source_type ASC SEPARATOR ', ') AS source_types,
					MIN(CASE WHEN source_type = 'tapology' THEN source_fighter_id ELSE NULL END) AS tapology_source_fighter_id,
					MIN(CASE WHEN source_type = 'tapology' THEN source_url ELSE NULL END) AS tapology_source_url
				FROM {$tables['fighter_sources']}
				WHERE fighter_id IS NOT NULL
				GROUP BY fighter_id
			) src ON src.fighter_id = f.id
			LEFT JOIN (
				SELECT fighter_id, SUBSTRING_INDEX(GROUP_CONCAT(weight_class ORDER BY event_date DESC, bout_id DESC SEPARATOR '||'), '||', 1) AS latest_bout_weight_class
				FROM (
					SELECT bp.fighter_id, b.id AS bout_id, COALESCE(e.event_date, '0000-00-00') AS event_date, b.weight_class
					FROM {$tables['bout_participants']} bp
					INNER JOIN {$tables['bouts']} b ON b.id = bp.bout_id AND b.deleted_soft = 0
					LEFT JOIN {$tables['events']} e ON e.id = b.event_id
					WHERE bp.fighter_id IS NOT NULL
						AND b.weight_class IS NOT NULL
						AND b.weight_class <> ''
						AND b.weight_class <> 'unknown'
				) latest_weights
				GROUP BY fighter_id
			) bw ON bw.fighter_id = f.id
			LEFT JOIN (
				SELECT fighter_id, GROUP_CONCAT(CONCAT(weight_class, ' (', class_count, ')') ORDER BY class_count DESC, weight_class ASC SEPARATOR ', ') AS bout_weight_distribution
				FROM (
					SELECT bp.fighter_id, b.weight_class, COUNT(*) AS class_count
					FROM {$tables['bout_participants']} bp
					INNER JOIN {$tables['bouts']} b ON b.id = bp.bout_id AND b.deleted_soft = 0
					WHERE bp.fighter_id IS NOT NULL
						AND b.weight_class IS NOT NULL
						AND b.weight_class <> ''
						AND b.weight_class <> 'unknown'
					GROUP BY bp.fighter_id, b.weight_class
				) weight_counts
				GROUP BY fighter_id
			) bwd ON bwd.fighter_id = f.id
			LEFT JOIN (
				SELECT d.fighter_id, SUM(d.duplicate_name_count) AS duplicate_name_count, SUM(d.source_conflict_count) AS source_conflict_count
				FROM (
					SELECT f2.id AS fighter_id, 1 AS duplicate_name_count, 0 AS source_conflict_count
					FROM {$tables['fighters']} f2
					INNER JOIN (
						SELECT normalized_name
						FROM {$tables['fighters']}
						WHERE deleted_soft = 0 AND normalized_name IS NOT NULL AND normalized_name <> ''
						GROUP BY normalized_name
						HAVING COUNT(*) > 1
					) names ON names.normalized_name = f2.normalized_name
					WHERE f2.deleted_soft = 0
					UNION ALL
					SELECT fs.fighter_id, 0 AS duplicate_name_count, 1 AS source_conflict_count
					FROM {$tables['fighter_sources']} fs
					INNER JOIN (
						SELECT source_type, source_fighter_id
						FROM {$tables['fighter_sources']}
						WHERE source_fighter_id IS NOT NULL AND source_fighter_id <> ''
						GROUP BY source_type, source_fighter_id
						HAVING COUNT(DISTINCT fighter_id) > 1
					) source_dupes ON source_dupes.source_type = fs.source_type AND source_dupes.source_fighter_id = fs.source_fighter_id
					WHERE fs.fighter_id IS NOT NULL
				) d
				GROUP BY d.fighter_id
			) sig ON sig.fighter_id = f.id
			LEFT JOIN (
				SELECT canonical_id AS fighter_id,
					SUM(CASE WHEN status = 'conflict' THEN 1 ELSE 0 END) AS import_conflict_count,
					SUM(CASE WHEN status IN ('needs_review', 'failed', 'needs_research') OR action IN ('likely_match_review', 'review_fighter_match') THEN 1 ELSE 0 END) AS import_needs_review_count
				FROM {$tables['source_import_items']}
				WHERE item_type = 'fighter' AND canonical_id IS NOT NULL
				GROUP BY canonical_id
			) imp ON imp.fighter_id = f.id
			LEFT JOIN (
				SELECT fighter_id, COUNT(*) AS open_review_count
				FROM (
					SELECT canonical_id AS fighter_id
					FROM {$tables['review_items']}
					WHERE status = 'open' AND canonical_id IS NOT NULL
					UNION ALL
					SELECT related_canonical_id AS fighter_id
					FROM {$tables['review_items']}
					WHERE status = 'open' AND related_canonical_id IS NOT NULL
				) review_refs
				GROUP BY fighter_id
			) ri ON ri.fighter_id = f.id
		";

		return array(
			'from'  => $from,
			'where' => $where,
			'args'  => $args,
		);
	}

	private function filter_condition( string $filter ): string {
		switch ( $filter ) {
			case 'missing_dob_birth_year':
				return 'f.date_of_birth IS NULL AND f.birth_year IS NULL';
			case 'missing_gender':
				return "(f.gender IS NULL OR f.gender = '' OR f.gender = 'unknown')";
			case 'missing_weight_class':
				return "(f.weight_class IS NULL OR f.weight_class = '' OR f.weight_class = 'unknown')";
			case 'missing_nationality':
				return "(f.nationality IS NULL OR f.nationality = '')";
			case 'has_tapology_source':
				return 'COALESCE(src.tapology_source_count, 0) > 0';
			case 'no_tapology_source':
				return 'COALESCE(src.tapology_source_count, 0) = 0';
			case 'has_fights':
				return 'COALESCE(st.pro_fights_count, 0) > 0';
			case 'no_fights':
				return 'COALESCE(st.pro_fights_count, 0) = 0';
			case 'public_not_rankable':
				return 'f.is_public = 1 AND f.is_rankable = 0';
			case 'scraped_provisional':
				return "f.status = 'provisional' AND COALESCE(src.tapology_source_count, 0) > 0";
			case 'legacy_public':
				return 'f.is_public = 1 AND COALESCE(src.legacy_source_count, 0) > 0';
			case 'possible_ranking_candidate':
				return "f.is_public = 1 AND f.is_rankable = 0 AND COALESCE(st.pro_fights_count, 0) > 0 AND (f.date_of_birth IS NOT NULL OR f.birth_year IS NOT NULL) AND f.gender IS NOT NULL AND f.gender <> '' AND f.gender <> 'unknown' AND f.weight_class IS NOT NULL AND f.weight_class <> '' AND f.weight_class <> 'unknown'";
			case 'duplicate_conflict_signal':
				return '(COALESCE(sig.duplicate_name_count, 0) > 0 OR COALESCE(sig.source_conflict_count, 0) > 0 OR COALESCE(imp.import_conflict_count, 0) > 0 OR COALESCE(imp.import_needs_review_count, 0) > 0 OR COALESCE(ri.open_review_count, 0) > 0)';
		}

		return '';
	}

	private function priority_sql(): string {
		return "
			(
				CASE WHEN COALESCE(st.pro_fights_count, 0) > 0 THEN 5 ELSE 0 END
				+ CASE WHEN COALESCE(st.pro_fights_count, 0) > 1 THEN 3 ELSE 0 END
				+ CASE WHEN COALESCE(st.wins, 0) > COALESCE(st.losses, 0) AND COALESCE(st.pro_fights_count, 0) > 0 THEN 3 ELSE 0 END
				+ CASE WHEN st.last_fight_date IS NOT NULL AND st.last_fight_date >= DATE_SUB(CURDATE(), INTERVAL " . self::RECENT_FIGHT_MONTHS . " MONTH) THEN 2 ELSE 0 END
				+ CASE WHEN COALESCE(src.tapology_source_count, 0) > 0 THEN 2 ELSE 0 END
				+ CASE WHEN f.wp_post_id IS NOT NULL AND f.wp_post_id > 0 THEN 2 ELSE 0 END
				+ CASE WHEN f.is_public = 1 AND f.is_rankable = 0 AND COALESCE(src.legacy_source_count, 0) > 0 THEN 2 ELSE 0 END
				+ CASE WHEN (f.weight_class IS NULL OR f.weight_class = '' OR f.weight_class = 'unknown') AND bw.latest_bout_weight_class IS NOT NULL THEN 1 ELSE 0 END
				- CASE WHEN f.date_of_birth IS NULL AND f.birth_year IS NULL THEN 3 ELSE 0 END
				- CASE WHEN f.gender IS NULL OR f.gender = '' OR f.gender = 'unknown' THEN 2 ELSE 0 END
				- CASE WHEN f.weight_class IS NULL OR f.weight_class = '' OR f.weight_class = 'unknown' THEN 2 ELSE 0 END
				- CASE WHEN COALESCE(sig.duplicate_name_count, 0) > 0 OR COALESCE(sig.source_conflict_count, 0) > 0 OR COALESCE(imp.import_conflict_count, 0) > 0 OR COALESCE(imp.import_needs_review_count, 0) > 0 OR COALESCE(ri.open_review_count, 0) > 0 THEN 2 ELSE 0 END
				- CASE WHEN COALESCE(st.pro_fights_count, 0) = 0 THEN 5 ELSE 0 END
			)
		";
	}

	private function prepare_row( array $row ): array {
		$row['warnings_reasons'] = implode( '; ', $this->row_reasons( $row ) );
		$row['suggested_next_action'] = $this->suggested_action( $row );
		$row['latest_bout_weight_suggestion'] = $this->should_show_weight_suggestion( $row ) ? (string) $row['latest_bout_weight_class'] : '';

		return $row;
	}

	private function row_reasons( array $row ): array {
		$reasons = array();

		if ( $this->missing_dob_birth_year( $row ) ) {
			$reasons[] = __( 'Missing DOB/birth year', 'mma-future-data-engine' );
		}
		if ( $this->missing_gender( $row ) ) {
			$reasons[] = __( 'Missing gender', 'mma-future-data-engine' );
		}
		if ( $this->missing_weight_class( $row ) ) {
			$reasons[] = __( 'Missing canonical weight class', 'mma-future-data-engine' );
		}
		if ( $this->missing_nationality( $row ) ) {
			$reasons[] = __( 'Missing nationality', 'mma-future-data-engine' );
		}
		if ( 0 === (int) $row['countable_fights'] ) {
			$reasons[] = __( 'No countable fights', 'mma-future-data-engine' );
		}
		if ( 0 === (int) $row['tapology_source_count'] ) {
			$reasons[] = __( 'No Tapology source mapping', 'mma-future-data-engine' );
		}
		if ( $this->duplicate_conflict_signal( $row ) ) {
			$reasons[] = __( 'Duplicate/conflict/review signal', 'mma-future-data-engine' );
		}
		if ( $this->has_stats_warnings( $row ) ) {
			$reasons[] = __( 'Stats warnings', 'mma-future-data-engine' );
		}
		if ( $this->should_show_weight_suggestion( $row ) ) {
			$reasons[] = __( 'Bout-derived weight suggestion available', 'mma-future-data-engine' );
		}

		return $reasons;
	}

	private function suggested_action( array $row ): string {
		if ( $this->duplicate_conflict_signal( $row ) ) {
			return __( 'resolve duplicate/conflict', 'mma-future-data-engine' );
		}
		if ( 0 === (int) $row['countable_fights'] ) {
			return __( 'low priority: no fights', 'mma-future-data-engine' );
		}
		if ( 0 === (int) $row['tapology_source_count'] ) {
			return __( 'low priority: missing source mapping', 'mma-future-data-engine' );
		}
		if ( $this->missing_dob_birth_year( $row ) ) {
			return __( 'add DOB/birth year', 'mma-future-data-engine' );
		}
		if ( $this->missing_gender( $row ) ) {
			return __( 'set gender', 'mma-future-data-engine' );
		}
		if ( $this->missing_weight_class( $row ) ) {
			return __( 'set weight class', 'mma-future-data-engine' );
		}
		if ( $this->missing_nationality( $row ) ) {
			return __( 'add nationality', 'mma-future-data-engine' );
		}
		if ( 1 === (int) $row['is_public'] && 0 === (int) $row['is_rankable'] ) {
			return __( 'candidate for manual rankability review', 'mma-future-data-engine' );
		}
		if ( (int) $row['tapology_source_count'] > 0 ) {
			return __( 'review Tapology source', 'mma-future-data-engine' );
		}

		return __( 'verify identity', 'mma-future-data-engine' );
	}

	private function should_show_weight_suggestion( array $row ): bool {
		return $this->missing_weight_class( $row ) && ! empty( $row['latest_bout_weight_class'] );
	}

	private function missing_dob_birth_year( array $row ): bool {
		return empty( $row['date_of_birth'] ) && empty( $row['birth_year'] );
	}

	private function missing_gender( array $row ): bool {
		$gender = (string) ( $row['gender'] ?? '' );

		return '' === $gender || 'unknown' === $gender;
	}

	private function missing_weight_class( array $row ): bool {
		$weight_class = (string) ( $row['weight_class'] ?? '' );

		return '' === $weight_class || 'unknown' === $weight_class;
	}

	private function missing_nationality( array $row ): bool {
		return '' === (string) ( $row['nationality'] ?? '' );
	}

	private function duplicate_conflict_signal( array $row ): bool {
		return (int) $row['duplicate_name_count'] > 0
			|| (int) $row['source_conflict_count'] > 0
			|| (int) $row['import_conflict_count'] > 0
			|| (int) $row['import_needs_review_count'] > 0
			|| (int) $row['open_review_count'] > 0;
	}

	private function has_stats_warnings( array $row ): bool {
		$warnings = trim( (string) ( $row['warnings_json'] ?? '' ) );

		return '' !== $warnings && '[]' !== $warnings;
	}

	private function count_where( string $table, string $where ): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function count_without_tapology_source(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['fighters']} f
			LEFT JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id AND fs.source_type = 'tapology'
			WHERE f.deleted_soft = 0 AND fs.id IS NULL
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_with_tapology_source(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT f.id)
			FROM {$tables['fighters']} f
			INNER JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id AND fs.source_type = 'tapology'
			WHERE f.deleted_soft = 0
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_by_stats_condition( string $condition ): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['fighters']} f
			LEFT JOIN {$tables['fighter_stats_current']} st ON st.fighter_id = f.id
			WHERE f.deleted_soft = 0 AND {$condition}
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_import_items_by_status( string $status ): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['source_import_items']} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function count_import_needs_review(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$tables['source_import_items']}
			WHERE status IN ('needs_review', 'failed', 'needs_research')
				OR action IN ('likely_match_review', 'review_bout_match', 'review_event_match', 'review_fighter_match')
			" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
