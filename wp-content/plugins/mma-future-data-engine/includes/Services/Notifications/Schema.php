<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schema {
	public static function tables(): array {
		global $wpdb;
		return array(
			'subscriptions' => $wpdb->prefix . 'mmaf_notification_subscriptions',
			'reminders'     => $wpdb->prefix . 'mmaf_notification_reminders',
			'changes'       => $wpdb->prefix . 'mmaf_notification_changes',
			'items'         => $wpdb->prefix . 'mmaf_notification_items',
			'messages'      => $wpdb->prefix . 'mmaf_notification_messages',
			'attempts'      => $wpdb->prefix . 'mmaf_notification_attempts',
			'tokens'        => $wpdb->prefix . 'mmaf_notification_tokens',
		);
	}

	public static function sql(): array {
		global $wpdb;
		$t = self::tables();
		$c = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$t['subscriptions']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				fighter_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_fighter (user_id, fighter_id),
				KEY fighter_status (fighter_id, status),
				KEY user_status (user_id, status)
			) $c;",
			"CREATE TABLE {$t['reminders']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				bout_id BIGINT UNSIGNED NOT NULL,
				event_date_snapshot DATE NULL,
				scheduled_at_utc DATETIME NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'active',
				last_sent_date DATE NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_bout (user_id, bout_id),
				KEY due (status, scheduled_at_utc),
				KEY user_status (user_id, status)
			) $c;",
			"CREATE TABLE {$t['changes']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_id BIGINT UNSIGNED NOT NULL,
				bout_id BIGINT UNSIGNED NOT NULL,
				change_type VARCHAR(32) NOT NULL,
				fingerprint CHAR(64) NOT NULL,
				before_json LONGTEXT NULL,
				after_json LONGTEXT NOT NULL,
				source_type VARCHAR(32) NOT NULL,
				import_id BIGINT UNSIGNED NULL,
				processed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY fingerprint (fingerprint),
				KEY unprocessed (processed_at, id),
				KEY bout_history (bout_id, id)
			) $c;",
			"CREATE TABLE {$t['items']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				change_id BIGINT UNSIGNED NULL,
				bout_id BIGINT UNSIGNED NOT NULL,
				kind VARCHAR(32) NOT NULL,
				dedupe_key CHAR(64) NOT NULL,
				payload_json LONGTEXT NOT NULL,
				due_at DATETIME NOT NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'pending',
				message_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_dedupe (user_id, dedupe_key),
				KEY user_due (user_id, status, due_at),
				KEY message_id (message_id),
				KEY change_id (change_id)
			) $c;",
			"CREATE TABLE {$t['messages']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				local_day DATE NOT NULL,
				timezone VARCHAR(120) NOT NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'reserved',
				subject VARCHAR(255) NULL,
				html_body LONGTEXT NULL,
				text_body LONGTEXT NULL,
				provider_id VARCHAR(191) NULL,
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				next_attempt_at DATETIME NULL,
				error_code VARCHAR(80) NULL,
				error_message TEXT NULL,
				accepted_at DATETIME NULL,
				delivered_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_day (user_id, local_day),
				KEY send_queue (status, next_attempt_at),
				KEY provider_id (provider_id)
			) $c;",
			"CREATE TABLE {$t['attempts']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				message_id BIGINT UNSIGNED NOT NULL,
				attempt_no INT UNSIGNED NOT NULL,
				status VARCHAR(24) NOT NULL,
				http_code INT NULL,
				provider_id VARCHAR(191) NULL,
				response_excerpt TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY message_attempt (message_id, attempt_no),
				KEY status (status, created_at)
			) $c;",
			"CREATE TABLE {$t['tokens']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NULL,
				email VARCHAR(191) NULL,
				purpose VARCHAR(40) NOT NULL,
				token_hash CHAR(64) NOT NULL,
				payload_json LONGTEXT NULL,
				expires_at DATETIME NOT NULL,
				used_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY expiry (purpose, expires_at),
				KEY user_purpose (user_id, purpose)
			) $c;",
		);
	}
}
