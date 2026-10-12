<?php
namespace MMAF\DataEngine\Services\DataPipeline;

final class Schema {
    public static function tables(): array {
        global $wpdb;
        return ['profiles'=>$wpdb->prefix.'mmaf_profile_snapshots', 'queue'=>$wpdb->prefix.'mmaf_fighter_preparation'];
    }
    public static function sql(): array {
        global $wpdb; $t=self::tables(); $charset=$wpdb->get_charset_collate();
        return [
            "CREATE TABLE {$t['profiles']} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                source_type varchar(50) NOT NULL,
                source_fighter_id varchar(120) NOT NULL,
                source_url text NOT NULL,
                fighter_id bigint unsigned NULL,
                schema_version varchar(80) NOT NULL,
                payload_json longtext NULL,
                content_hash char(64) NULL,
                source_fetched_at datetime NULL,
                last_attempt_at datetime NULL,
                last_attempt_status varchar(40) NOT NULL,
                last_error varchar(255) NULL,
                import_job_id bigint unsigned NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_identity (source_type,source_fighter_id),
                KEY fighter_id (fighter_id),
                KEY source_url (source_url(191))
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$t['queue']} (
                fighter_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL,
                requested_hash char(64) NULL,
                processed_hash char(64) NULL,
                import_job_id bigint unsigned NOT NULL DEFAULT 0,
                actor_user_id bigint unsigned NOT NULL DEFAULT 0,
                attempts int unsigned NOT NULL DEFAULT 0,
                error_message varchar(255) NULL,
                summary_json longtext NULL,
                requested_at datetime NOT NULL,
                finished_at datetime NULL,
                retry_at datetime NULL,
                PRIMARY KEY  (fighter_id),
                KEY pending_job (status,import_job_id,fighter_id)
            ) ENGINE=InnoDB $charset;",
        ];
    }
}
