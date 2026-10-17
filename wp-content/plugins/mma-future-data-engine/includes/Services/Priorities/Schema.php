<?php
namespace MMAF\DataEngine\Services\Priorities;
final class Schema {
    public static function table(): string {global $wpdb;return $wpdb->prefix.'mmaf_priority_targets';}
    public static function sql(): array {
        global $wpdb;$t=self::table();$charset=$wpdb->get_charset_collate();
        return ["CREATE TABLE $t (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            entry_key char(64) NOT NULL,
            client_name varchar(255) NOT NULL,
            source_row_json longtext NOT NULL,
            source_fighter_id varchar(120) NULL,
            source_url text NULL,
            fighter_id bigint unsigned NULL,
            identity_status varchar(24) NOT NULL DEFAULT 'pending',
            is_active tinyint unsigned NOT NULL DEFAULT 1,
            binding_revision int unsigned NOT NULL DEFAULT 1,
            created_by bigint unsigned NOT NULL,
            confirmed_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entry_key (entry_key),
            KEY source_fighter_id (source_fighter_id),
            KEY fighter_id (fighter_id),
            KEY active_identity (is_active,identity_status,id)
        ) ENGINE=InnoDB $charset;"];
    }
}
