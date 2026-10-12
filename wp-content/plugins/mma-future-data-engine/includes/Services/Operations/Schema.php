<?php
namespace MMAF\DataEngine\Services\Operations;

/** Durable projections, frozen operation scopes and private calculation generations. */
final class Schema {
    public static function tables(): array {
        global $wpdb;
        $names=['membership'=>'import_fighters','readiness'=>'fighter_readiness','jobs'=>'operations','items'=>'operation_items','stats'=>'stats_generation','scores'=>'ranking_candidates','activations'=>'ranking_activations','alerts'=>'operational_alerts','review_links'=>'review_links','archives'=>'archives','archive_items'=>'archive_items'];
        return array_map(static fn($name)=>$wpdb->prefix.'mmaf_'.$name,$names);
    }
    public static function sql(): array {
        global $wpdb; $t=self::tables(); $c=$wpdb->get_charset_collate();
        $sql=[
            "CREATE TABLE {$t['membership']} (
                import_job_id bigint unsigned NOT NULL,
                fighter_id bigint unsigned NOT NULL,
                evidence varchar(40) NOT NULL,
                observed_at datetime NOT NULL,
                PRIMARY KEY  (import_job_id,fighter_id),
                KEY fighter_import (fighter_id,import_job_id)
            ) ENGINE=InnoDB $c;",
            "CREATE TABLE {$t['readiness']} (
                fighter_id bigint unsigned NOT NULL,
                preparation_state varchar(24) NOT NULL,
                reason_code varchar(80) NULL,
                input_hash char(64) NOT NULL,
                data_revision varchar(32) NOT NULL,
                rules_version varchar(80) NOT NULL,
                checked_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                dirty tinyint NOT NULL DEFAULT 0,
                projection_json longtext NOT NULL,
                PRIMARY KEY  (fighter_id),
                KEY refresh (dirty,expires_at,fighter_id),
                KEY preparation (preparation_state,fighter_id),
                KEY reason (reason_code,fighter_id)
            ) ENGINE=InnoDB $c;",
            "CREATE TABLE {$t['jobs']} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                request_key varchar(100) NOT NULL,
                kind varchar(32) NOT NULL,
                action varchar(60) NOT NULL,
                status varchar(24) NOT NULL,
                phase varchar(40) NOT NULL,
                actor_user_id bigint unsigned NOT NULL,
                selection_json longtext NOT NULL,
                checkpoint_json longtext NULL,
                summary_json longtext NULL,
                manifest_hash char(64) NULL,
                total int unsigned NOT NULL DEFAULT 0,
                attempts int unsigned NOT NULL DEFAULT 0,
                retry_at datetime NULL,
                error_message varchar(255) NULL,
                created_at datetime NOT NULL,
                confirmed_at datetime NULL,
                updated_at datetime NOT NULL,
                heartbeat_at datetime NULL,
                progress_at datetime NULL,
                finished_at datetime NULL,
                duration_ms bigint unsigned NOT NULL DEFAULT 0,
                peak_memory bigint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY request_key (request_key),
                KEY worker (status,retry_at,id)
            ) ENGINE=InnoDB $c;",
            "CREATE TABLE {$t['items']} (
                operation_id bigint unsigned NOT NULL,
                fighter_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL,
                input_hash char(64) NULL,
                prepared_hash char(64) NULL,
                attempts int unsigned NOT NULL DEFAULT 0,
                retry_at datetime NULL,
                reason_code varchar(80) NULL,
                result_json longtext NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (operation_id,fighter_id),
                KEY pending (operation_id,status,retry_at,fighter_id),
                KEY fighter (fighter_id,operation_id)
            ) ENGINE=InnoDB $c;",
            "CREATE TABLE {$t['stats']} (
                generation_id bigint unsigned NOT NULL,
                fighter_id bigint unsigned NOT NULL,
                row_json longtext NOT NULL,
                PRIMARY KEY  (generation_id,fighter_id)
            ) ENGINE=InnoDB $c;",
            "CREATE TABLE {$t['scores']} (
                operation_id bigint unsigned NOT NULL,
                fighter_id bigint unsigned NOT NULL,
                score double NOT NULL,
                wins int NOT NULL,
                finish_rate double NOT NULL,
                age int NOT NULL,
                last_fight_date varchar(10) NOT NULL,
                item_json longtext NOT NULL,
                sort_position bigint unsigned NULL,
                PRIMARY KEY  (operation_id,fighter_id),
                KEY ordered (operation_id,score,wins,finish_rate,age,last_fight_date,fighter_id),
                KEY sorted_page (operation_id,sort_position)
            ) ENGINE=InnoDB $c;",
            "CREATE TABLE {$t['activations']} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                ranking_run_id bigint unsigned NOT NULL,
                board_key varchar(60) NOT NULL,
                activated_at datetime NOT NULL,
                actor_user_id bigint unsigned NOT NULL,
                audit_id bigint unsigned NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY audit_board (audit_id,board_key),
                KEY board_audit_history (board_key,activated_at,audit_id,ranking_run_id)
            ) ENGINE=InnoDB $c;",
            "CREATE TABLE {$t['alerts']} (
                alert_key varchar(100) NOT NULL,
                severity varchar(16) NOT NULL,
                message varchar(255) NOT NULL,
                context_json longtext NULL,
                first_seen_at datetime NOT NULL,
                last_seen_at datetime NOT NULL,
                emailed_at datetime NULL,
                resolved_at datetime NULL,
                PRIMARY KEY  (alert_key),
                KEY open_alerts (resolved_at,severity)
            ) ENGINE=InnoDB $c;",
        ];
        $sql[]="CREATE TABLE {$t['review_links']} (
            review_id bigint unsigned NOT NULL,
            entity_type varchar(24) NOT NULL,
            entity_id bigint unsigned NOT NULL,
            evidence varchar(60) NOT NULL,
            PRIMARY KEY  (review_id,entity_type,entity_id),
            KEY entity_review (entity_type,entity_id,review_id)
        ) ENGINE=InnoDB $c;";
        $sql[]="CREATE TABLE {$t['archives']} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(32) NOT NULL,
            entity_id bigint unsigned NOT NULL DEFAULT 0,
            status varchar(24) NOT NULL,
            phase varchar(24) NOT NULL,
            checkpoint_json longtext NOT NULL,
            total bigint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            finished_at datetime NULL,
            error_message varchar(255) NULL,
            PRIMARY KEY  (id),
            KEY worker (status,id)
        ) ENGINE=InnoDB $c;";
        $sql[]="CREATE TABLE {$t['archive_items']} (
            archive_id bigint unsigned NOT NULL,
            source_id bigint unsigned NOT NULL,
            row_hash char(64) NULL,
            status varchar(24) NOT NULL DEFAULT 'frozen',
            PRIMARY KEY  (archive_id,source_id),
            KEY deletion (archive_id,status,source_id)
        ) ENGINE=InnoDB $c;";
        return $sql;
    }
}
