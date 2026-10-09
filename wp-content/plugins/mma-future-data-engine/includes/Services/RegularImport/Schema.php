<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Schema {
    public static function tables(): array {
        global $wpdb;
        return ['jobs' => $wpdb->prefix . 'mmaf_import_jobs', 'batches' => $wpdb->prefix . 'mmaf_import_batches', 'items' => $wpdb->prefix . 'mmaf_import_job_items'];
    }
    public static function sql(): array {
        global $wpdb; $t = self::tables(); $charset = $wpdb->get_charset_collate();
        return [
            "CREATE TABLE {$t['jobs']} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                status varchar(32) NOT NULL,
                phase varchar(32) NOT NULL,
                filename varchar(255) NOT NULL,
                bundle_hash char(64) NULL,
                approved_hash char(64) NULL,
                data_revision char(64) NULL,
                cursor_index bigint unsigned NOT NULL DEFAULT 0,
                attempts int unsigned NOT NULL DEFAULT 0,
                retry_at datetime NULL,
                heartbeat_at datetime NULL,
                pause_requested tinyint NOT NULL DEFAULT 0,
                writes_started tinyint NOT NULL DEFAULT 0,
                meta_json longtext NOT NULL,
                error_json longtext NULL,
                created_by bigint unsigned NOT NULL,
                approved_by bigint unsigned NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                finished_at datetime NULL,
                PRIMARY KEY  (id),
                KEY status (status)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$t['batches']} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                job_id bigint unsigned NOT NULL,
                kind varchar(24) NOT NULL,
                batch_index int unsigned NOT NULL,
                payload_hash char(64) NOT NULL,
                row_count int unsigned NOT NULL,
                checked tinyint NOT NULL DEFAULT 0,
                completed tinyint NOT NULL DEFAULT 0,
                import_run_id bigint unsigned NULL,
                summary_json longtext NULL,
                completed_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_batch (job_id,kind,batch_index),
                KEY import_run_id (import_run_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$t['items']} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                job_id bigint unsigned NOT NULL,
                kind varchar(24) NOT NULL,
                item_key char(64) NOT NULL,
                source_id varchar(255) NULL,
                category varchar(32) NOT NULL,
                payload_json longtext NOT NULL,
                review_item_id bigint unsigned NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_item (job_id,kind,item_key),
                KEY job_category (job_id,category),
                KEY review_item_id (review_item_id)
            ) ENGINE=InnoDB $charset;",
        ];
    }
}
