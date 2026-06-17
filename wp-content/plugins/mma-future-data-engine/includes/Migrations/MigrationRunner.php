<?php
namespace MMAF\DataEngine\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MigrationRunner {
	public static function run(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::sql() as $statement ) {
			dbDelta( $statement );
		}

		self::run_safe_alters();

		update_option( 'mmaf_db_version', MMAF_DB_VERSION, false );
	}

	private static function run_safe_alters(): void {
		global $wpdb;

		$tables = Schema::table_names();

		if ( ! self::column_exists( $tables['fighters'], 'nickname' ) ) {
			$wpdb->query( "ALTER TABLE {$tables['fighters']} ADD COLUMN nickname VARCHAR(255) NULL AFTER display_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['fighters'], 'height' ) ) {
			$wpdb->query( "ALTER TABLE {$tables['fighters']} ADD COLUMN height VARCHAR(60) NULL AFTER weight_class" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['fighters'], 'height_cm' ) ) {
			$wpdb->query( "ALTER TABLE {$tables['fighters']} ADD COLUMN height_cm SMALLINT UNSIGNED NULL AFTER height" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['fighters'], 'last_weigh_in' ) ) {
			$wpdb->query( "ALTER TABLE {$tables['fighters']} ADD COLUMN last_weigh_in VARCHAR(60) NULL AFTER height_cm" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$source_column = $wpdb->get_row(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $tables['fighter_sources'] . ' LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'source_fighter_id'
			),
			ARRAY_A
		);

		if ( $source_column && 'NO' === $source_column['Null'] ) {
			$wpdb->query( "ALTER TABLE {$tables['fighter_sources']} MODIFY source_fighter_id VARCHAR(120) NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['event_sources'], 'source_slug' ) ) {
			$wpdb->query( "ALTER TABLE {$tables['event_sources']} ADD COLUMN source_slug VARCHAR(255) NULL AFTER source_url" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['event_sources'], 'source_promotion_url' ) ) {
			$wpdb->query( "ALTER TABLE {$tables['event_sources']} ADD COLUMN source_promotion_url TEXT NULL AFTER source_promotion_numeric_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['event_sources'], 'is_verified' ) ) {
			$wpdb->query( "ALTER TABLE {$tables['event_sources']} ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER last_import_run_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['event_sources'], 'is_primary' ) ) {
			$wpdb->query( "ALTER TABLE {$tables['event_sources']} ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER is_verified" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		self::add_ranking_score_columns( $tables['ranking_current'] );
		self::add_ranking_score_columns( $tables['ranking_snapshots'] );

		self::run_safe_unique_indexes( $tables );
	}

	private static function add_ranking_score_columns( string $table ): void {
		global $wpdb;

		if ( ! self::column_exists( $table, 'raw_score' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN raw_score DECIMAL(10,3) NULL AFTER total_score" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $table, 'normalized_score' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN normalized_score DECIMAL(10,3) NULL AFTER raw_score" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $table, 'confidence_score' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN confidence_score DECIMAL(6,3) NULL AFTER normalized_score" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $table, 'sample_size' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN sample_size INT NOT NULL DEFAULT 0 AFTER confidence_score" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $table, 'quality_flags_json' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN quality_flags_json LONGTEXT NULL AFTER sample_size" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	private static function run_safe_unique_indexes( array $tables ): void {
		self::add_unique_index_if_clean(
			$tables['fighter_sources'],
			'source_type_identity_hash',
			'(source_type, identity_hash)',
			"
			SELECT COUNT(*)
			FROM (
				SELECT source_type, identity_hash, COUNT(*) AS row_count
				FROM {$tables['fighter_sources']}
				WHERE identity_hash IS NOT NULL
				GROUP BY source_type, identity_hash
				HAVING row_count > 1
			) duplicates
			"
		);

		self::add_unique_index_if_clean(
			$tables['fighter_stats_current'],
			'fighter_id_unique',
			'(fighter_id)',
			"
			SELECT COUNT(*)
			FROM (
				SELECT fighter_id, COUNT(*) AS row_count
				FROM {$tables['fighter_stats_current']}
				GROUP BY fighter_id
				HAVING row_count > 1
			) duplicates
			"
		);

		self::add_unique_index_if_clean(
			$tables['fighter_stats_overrides'],
			'fighter_id_unique',
			'(fighter_id)',
			"
			SELECT COUNT(*)
			FROM (
				SELECT fighter_id, COUNT(*) AS row_count
				FROM {$tables['fighter_stats_overrides']}
				GROUP BY fighter_id
				HAVING row_count > 1
			) duplicates
			"
		);

		self::add_unique_index_if_clean(
			$tables['ranking_current'],
			'board_rank_position',
			'(board_key, rank_position)',
			"
			SELECT COUNT(*)
			FROM (
				SELECT board_key, rank_position, COUNT(*) AS row_count
				FROM {$tables['ranking_current']}
				GROUP BY board_key, rank_position
				HAVING row_count > 1
			) duplicates
			"
		);

		self::add_unique_index_if_clean(
			$tables['ranking_snapshots'],
			'run_board_fighter',
			'(ranking_run_id, board_key, fighter_id)',
			"
			SELECT COUNT(*)
			FROM (
				SELECT ranking_run_id, board_key, fighter_id, COUNT(*) AS row_count
				FROM {$tables['ranking_snapshots']}
				GROUP BY ranking_run_id, board_key, fighter_id
				HAVING row_count > 1
			) duplicates
			"
		);

		self::add_unique_index_if_clean(
			$tables['ranking_snapshots'],
			'run_board_rank_position',
			'(ranking_run_id, board_key, rank_position)',
			"
			SELECT COUNT(*)
			FROM (
				SELECT ranking_run_id, board_key, rank_position, COUNT(*) AS row_count
				FROM {$tables['ranking_snapshots']}
				GROUP BY ranking_run_id, board_key, rank_position
				HAVING row_count > 1
			) duplicates
			"
		);

		self::add_unique_index_if_clean(
			$tables['bout_participants'],
			'bout_role',
			'(bout_id, participant_role)',
			"
			SELECT COUNT(*)
			FROM (
				SELECT bout_id, participant_role, COUNT(*) AS row_count
				FROM {$tables['bout_participants']}
				GROUP BY bout_id, participant_role
				HAVING row_count > 1
			) duplicates
			"
		);
	}

	private static function add_unique_index_if_clean( string $table, string $index_name, string $columns_sql, string $duplicates_sql ): void {
		global $wpdb;

		if ( self::index_exists( $table, $index_name ) ) {
			return;
		}

		$duplicates = (int) $wpdb->get_var( $duplicates_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $duplicates > 0 ) {
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY {$index_name} {$columns_sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $table . ' LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$column
			)
		);

		return $found === $column;
	}

	private static function index_exists( string $table, string $index_name ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$index_name
			)
		);

		return null !== $found;
	}
}
