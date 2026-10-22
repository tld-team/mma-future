<?php
namespace MMAF\DataEngine\Services\Upcoming;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	public static function tables(): array {
		global $wpdb;
		return array(
			'events'  => $wpdb->prefix . 'mmaf_calendar_events',
			'bouts'   => $wpdb->prefix . 'mmaf_calendar_bouts',
			'imports' => $wpdb->prefix . 'mmaf_calendar_imports',
			'audit'   => $wpdb->prefix . 'mmaf_calendar_audit',
		);
	}

	public static function sql(): array {
		global $wpdb;
		$table = self::tables();
		$collate = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$table['events']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				stable_id VARCHAR(191) NOT NULL,
				source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
				source_event_id VARCHAR(191) NULL,
				source_url TEXT NULL,
				canonical_event_id BIGINT UNSIGNED NULL,
				title VARCHAR(255) NOT NULL,
				promotion VARCHAR(255) NULL,
				event_date DATE NOT NULL,
				event_datetime_utc DATETIME NULL,
				source_time_text VARCHAR(120) NULL,
				source_timezone VARCHAR(120) NULL,
				time_note VARCHAR(255) NULL,
				venue VARCHAR(255) NULL,
				location VARCHAR(255) NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'draft',
				is_manual TINYINT(1) NOT NULL DEFAULT 0,
				scope_confirmed TINYINT(1) NOT NULL DEFAULT 0,
				hidden TINYINT(1) NOT NULL DEFAULT 0,
				source_json LONGTEXT NULL,
				overrides_json LONGTEXT NULL,
				last_import_id BIGINT UNSIGNED NULL,
				source_observed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY stable_id (stable_id),
				KEY source_identity (source_type, source_event_id),
				KEY public_calendar (hidden, status, event_date),
				KEY last_import_id (last_import_id)
			) $collate;",
			"CREATE TABLE {$table['bouts']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_id BIGINT UNSIGNED NOT NULL,
				stable_id VARCHAR(191) NOT NULL,
				source_bout_id VARCHAR(191) NULL,
				source_url TEXT NULL,
				bout_order INT UNSIGNED NOT NULL DEFAULT 0,
				weight_class VARCHAR(100) NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
				scope_status VARCHAR(30) NOT NULL DEFAULT 'review',
				scope_evidence VARCHAR(255) NULL,
				fighter_a_name VARCHAR(255) NOT NULL,
				fighter_a_source_id VARCHAR(191) NULL,
				fighter_a_url TEXT NULL,
				fighter_a_id BIGINT UNSIGNED NULL,
				fighter_b_name VARCHAR(255) NOT NULL,
				fighter_b_source_id VARCHAR(191) NULL,
				fighter_b_url TEXT NULL,
				fighter_b_id BIGINT UNSIGNED NULL,
				is_manual TINYINT(1) NOT NULL DEFAULT 0,
				hidden TINYINT(1) NOT NULL DEFAULT 0,
				source_json LONGTEXT NULL,
				overrides_json LONGTEXT NULL,
				last_import_id BIGINT UNSIGNED NULL,
				source_observed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY stable_id (stable_id),
				KEY event_order (event_id, bout_order),
				KEY source_bout_id (source_bout_id),
				KEY fighter_a_id (fighter_a_id),
				KEY fighter_b_id (fighter_b_id)
			) $collate;",
			"CREATE TABLE {$table['imports']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				bundle_hash CHAR(64) NOT NULL,
				run_id VARCHAR(191) NOT NULL,
				status VARCHAR(30) NOT NULL,
				coverage_from DATE NOT NULL,
				coverage_to DATE NOT NULL,
				coverage_timezone VARCHAR(120) NOT NULL,
				coverage_complete TINYINT(1) NOT NULL DEFAULT 0,
				manifest_json LONGTEXT NOT NULL,
				summary_json LONGTEXT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL,
				finished_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY bundle_hash (bundle_hash),
				KEY latest_coverage (status, coverage_complete, finished_at),
				KEY run_id (run_id)
			) $collate;",
			"CREATE TABLE {$table['audit']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_type VARCHAR(30) NOT NULL,
				entity_id BIGINT UNSIGNED NOT NULL,
				action VARCHAR(60) NOT NULL,
				before_json LONGTEXT NULL,
				after_json LONGTEXT NULL,
				import_id BIGINT UNSIGNED NULL,
				actor_user_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY entity_history (entity_type, entity_id, id),
				KEY import_id (import_id)
			) $collate;",
		);
	}
}
