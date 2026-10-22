<?php
namespace MMAF\DataEngine\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MigrationRunner {
	public static function run(): void {
        // A verified runner already owns the writer barrier and canonical lock.
        // Paused historical jobs must not prevent an additive release migration.
        if(!(function_exists('mmaf_runner_owns_write_lock') && mmaf_runner_owns_write_lock())){
            \MMAF\DataEngine\Services\RegularImport\WriteGuard::assertAllowed();
        }
		\MMAF\DataEngine\Services\RegularImport\Mutex::run(static function() { self::migrate(); });
	}

	private static function migrate(): void {
		$installed = (string) get_option('mmaf_db_version', '0');
		if (version_compare($installed, MMAF_DB_VERSION, '>')) {
			throw new \RuntimeException('An older release cannot downgrade the installed database schema.');
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$statements = array_merge(Schema::sql(), \MMAF\DataEngine\Services\RegularImport\Schema::sql(), \MMAF\DataEngine\Services\DataPipeline\Schema::sql(), \MMAF\DataEngine\Services\Operations\Schema::sql(), \MMAF\DataEngine\Services\Scoring\Schema::sql(), \MMAF\DataEngine\Services\Priorities\Schema::sql(), \MMAF\DataEngine\Services\Upcoming\Schema::sql());
		foreach ( $statements as $statement ) {
			dbDelta( $statement );
			if ($wpdb->last_error) { throw new \RuntimeException('Schema migration failed: ' . $wpdb->last_error); }
		}

		self::run_safe_alters();
		self::repair_import_item_kinds();
		foreach ($statements as $statement) {
			preg_match('/CREATE TABLE\s+(\w+)/i', $statement, $match);
			$table = $match[1] ?? '';
			if (!$table) { throw new \RuntimeException('Invalid schema statement.'); }
			$columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
			if ($wpdb->last_error || !$columns) { throw new \RuntimeException('Required schema table is unavailable: ' . $table); }
			preg_match_all('/^\s*([a-z][a-z0-9_]*)\s+(?:bigint|int|tinyint|smallint|varchar|char|text|longtext|mediumtext|datetime|date|decimal|double|float|timestamp|enum)\b/im', $statement, $expected);
			if (array_diff($expected[1], $columns)) { throw new \RuntimeException('Required schema columns are missing: ' . $table); }
			if(in_array($table,\MMAF\DataEngine\Services\Operations\Schema::tables(),true) || in_array($table,\MMAF\DataEngine\Services\Scoring\Schema::tables(),true) || in_array($table,[Schema::table_names()['fighters'],Schema::table_names()['field_provenance']],true)){
				self::verify_operational_indexes($table,$statement);
			}
		}
		update_option( 'mmaf_db_version', MMAF_DB_VERSION, false );
	}

	/** Restore the exact original labels after widening their storage. */
	private static function repair_import_item_kinds(): void {
		global $wpdb;
		$table = \MMAF\DataEngine\Services\RegularImport\Schema::tables()['items'];
		$legacy = 'actual_stored_career_pro';
		$full = 'actual_stored_career_profile';
		$column = $wpdb->get_row("SHOW COLUMNS FROM `$table` WHERE Field='kind'", ARRAY_A);
		if ( ! $column || ! preg_match('/^varchar\((\d+)\)$/i', $column['Type'], $length) || (int) $length[1] < strlen($full) ) {
			throw new \RuntimeException('Import item storage cannot preserve career evidence labels.');
		}
		$conflicts = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM `$table` a JOIN `$table` b ON a.job_id=b.job_id AND a.item_key=b.item_key WHERE a.kind=%s AND b.kind=%s", $legacy, $full
		));
		if ( $wpdb->last_error || (int) $conflicts > 0 ) {
			throw new \RuntimeException('Conflicting career evidence labels require review before migration.');
		}
		self::query($wpdb->prepare("UPDATE `$table` SET kind=%s WHERE kind=%s", $full, $legacy));
	}

	/** Check the indexes that make operation manifests and worker pages reliable. */
	private static function verify_operational_indexes(string $table,string $statement): void {
		global $wpdb;$actual=[];
		foreach($wpdb->get_results("SHOW INDEX FROM `$table`",ARRAY_A) as $row){
			$actual[$row['Key_name']]['columns'][(int)$row['Seq_in_index']]=$row['Column_name'];
			$actual[$row['Key_name']]['unique']=(int)$row['Non_unique']===0;
		}
		if($wpdb->last_error){throw new \RuntimeException('Cannot verify operational indexes: '.$table);}
		preg_match_all('/^\s*(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY)\s*(\w+)?\s*\(([^)]+)\)/im',$statement,$matches,PREG_SET_ORDER);
		foreach($matches as $match){
			$primary=stripos($match[1],'PRIMARY')===0;$name=$primary?'PRIMARY':$match[2];
			$columns=array_map('trim',explode(',',$match[3]));$found=$actual[$name]??[];if(isset($found['columns'])){ksort($found['columns']);}
			if(array_values($found['columns']??[])!==$columns || (($primary || stripos($match[1],'UNIQUE')===0) && empty($found['unique']))){throw new \RuntimeException('Required operational index is missing or incompatible: '.$table.'.'.$name);}
		}
	}

	private static function run_safe_alters(): void {
		global $wpdb;

		$tables = Schema::table_names();

		if ( ! self::column_exists( $tables['fighters'], 'nickname' ) ) {
			self::query( "ALTER TABLE {$tables['fighters']} ADD COLUMN nickname VARCHAR(255) NULL AFTER display_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['fighters'], 'height' ) ) {
			self::query( "ALTER TABLE {$tables['fighters']} ADD COLUMN height VARCHAR(60) NULL AFTER weight_class" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['fighters'], 'height_cm' ) ) {
			self::query( "ALTER TABLE {$tables['fighters']} ADD COLUMN height_cm SMALLINT UNSIGNED NULL AFTER height" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['fighters'], 'last_weigh_in' ) ) {
			self::query( "ALTER TABLE {$tables['fighters']} ADD COLUMN last_weigh_in VARCHAR(60) NULL AFTER height_cm" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$source_column = $wpdb->get_row(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $tables['fighter_sources'] . ' LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'source_fighter_id'
			),
			ARRAY_A
		);

		if ( $source_column && 'NO' === $source_column['Null'] ) {
			self::query( "ALTER TABLE {$tables['fighter_sources']} MODIFY source_fighter_id VARCHAR(120) NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['event_sources'], 'source_slug' ) ) {
			self::query( "ALTER TABLE {$tables['event_sources']} ADD COLUMN source_slug VARCHAR(255) NULL AFTER source_url" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['event_sources'], 'source_promotion_url' ) ) {
			self::query( "ALTER TABLE {$tables['event_sources']} ADD COLUMN source_promotion_url TEXT NULL AFTER source_promotion_numeric_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['event_sources'], 'is_verified' ) ) {
			self::query( "ALTER TABLE {$tables['event_sources']} ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER last_import_run_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $tables['event_sources'], 'is_primary' ) ) {
			self::query( "ALTER TABLE {$tables['event_sources']} ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER is_verified" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		self::add_ranking_score_columns( $tables['ranking_current'] );
		self::add_ranking_score_columns( $tables['ranking_snapshots'] );

		self::run_safe_unique_indexes( $tables );
	}

	private static function add_ranking_score_columns( string $table ): void {
		global $wpdb;

		if ( ! self::column_exists( $table, 'raw_score' ) ) {
			self::query( "ALTER TABLE {$table} ADD COLUMN raw_score DECIMAL(10,3) NULL AFTER total_score" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $table, 'normalized_score' ) ) {
			self::query( "ALTER TABLE {$table} ADD COLUMN normalized_score DECIMAL(10,3) NULL AFTER raw_score" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $table, 'confidence_score' ) ) {
			self::query( "ALTER TABLE {$table} ADD COLUMN confidence_score DECIMAL(6,3) NULL AFTER normalized_score" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $table, 'sample_size' ) ) {
			self::query( "ALTER TABLE {$table} ADD COLUMN sample_size INT NOT NULL DEFAULT 0 AFTER confidence_score" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::column_exists( $table, 'quality_flags_json' ) ) {
			self::query( "ALTER TABLE {$table} ADD COLUMN quality_flags_json LONGTEXT NULL AFTER sample_size" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		self::query( "ALTER TABLE {$table} ADD UNIQUE KEY {$index_name} {$columns_sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	private static function query(string $sql) {
		global $wpdb;
		$result = $wpdb->query($sql);
		if (false === $result) { throw new \RuntimeException('Schema change failed: ' . $wpdb->last_error); }
		return $result;
	}

}
