<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every table has a surrogate id and named unique keys: dbDelta can neither alter a primary key nor drop an index,
 * so the keys that carry correctness are stated once, as plain column lists, and verified after each migration.
 */
final class Schema {
	public static function tables(): array {
		global $wpdb;
		return array(
			'profiles'       => $wpdb->prefix . 'mmaf_profiles',
			'fan_lists'      => $wpdb->prefix . 'mmaf_fan_lists',
			'fan_list_items' => $wpdb->prefix . 'mmaf_fan_list_items',
		);
	}

	public static function sql(): array {
		global $wpdb;
		$t = self::tables();
		$c = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$t['profiles']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				handle VARCHAR(20) NOT NULL,
				display_name VARCHAR(40) NOT NULL DEFAULT '',
				bio VARCHAR(280) NOT NULL DEFAULT '',
				socials_json TEXT NULL,
				avatar_path VARCHAR(191) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				rep_points INT NOT NULL DEFAULT 0,
				handle_changed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY profile_user (user_id),
				UNIQUE KEY profile_handle (handle),
				KEY profile_status (status)
			) $c;",
			"CREATE TABLE {$t['fan_lists']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				board_key VARCHAR(32) NOT NULL DEFAULT 'overall',
				revision INT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY list_user_board (user_id, board_key),
				KEY list_board_status (board_key, status)
			) $c;",
			"CREATE TABLE {$t['fan_list_items']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				list_id BIGINT UNSIGNED NOT NULL,
				board_key VARCHAR(32) NOT NULL DEFAULT 'overall',
				position TINYINT UNSIGNED NOT NULL,
				fighter_id BIGINT UNSIGNED NOT NULL,
				picked_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY item_list_position (list_id, position),
				UNIQUE KEY item_list_fighter (list_id, fighter_id),
				KEY item_board_fighter (board_key, fighter_id, position)
			) $c;",
		);
	}
}
