<?php
namespace MMAF\DataEngine\Services\Scoring;

/** Append-only source observations and immutable inputs for each calculation. */
final class Schema {
    public static function tables(): array {
        global $wpdb;
        return ['career'=>$wpdb->prefix.'mmaf_career_observations','assessments'=>$wpdb->prefix.'mmaf_scoring_assessments'];
    }
    public static function sql(): array {
        global $wpdb; $t=self::tables(); $charset=$wpdb->get_charset_collate();
        return [
            "CREATE TABLE {$t['career']} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                fighter_id bigint unsigned NOT NULL,
                observation_hash char(64) NOT NULL,
                source_type varchar(50) NOT NULL,
                source_fighter_id varchar(120) NOT NULL,
                source_url text NULL,
                observed_at datetime NULL,
                status varchar(24) NOT NULL,
                protected tinyint NOT NULL DEFAULT 0,
                record_json longtext NULL,
                previous_record_json longtext NULL,
                evidence_json longtext NOT NULL,
                reason text NULL,
                actor_user_id bigint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY observation_identity (fighter_id,observation_hash),
                KEY fighter_selection (fighter_id,protected,status,observed_at,id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$t['assessments']} (
                ranking_run_id bigint unsigned NOT NULL,
                fighter_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL,
                total_score decimal(10,3) NULL,
                sample_size int unsigned NOT NULL DEFAULT 0,
                period_start date NOT NULL,
                period_end date NOT NULL,
                verified_at datetime NULL,
                assessment_json longtext NOT NULL,
                inputs_json longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (ranking_run_id,fighter_id),
                KEY run_view (ranking_run_id,status,total_score,fighter_id)
            ) ENGINE=InnoDB $charset;",
        ];
    }
}
