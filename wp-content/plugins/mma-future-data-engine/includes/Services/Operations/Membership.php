<?php
namespace MMAF\DataEngine\Services\Operations;
use MMAF\DataEngine\Services\RegularImport\Repository;

final class Membership {
    public static function record(int $job,int $fighter,string $evidence): void {
        if ($job<1 || $fighter<1) { return; }
        global $wpdb; $table=Schema::tables()['membership'];
        Repository::check($wpdb->query($wpdb->prepare("INSERT INTO $table (import_job_id,fighter_id,evidence,observed_at) VALUES (%d,%d,%s,%s) ON DUPLICATE KEY UPDATE evidence=VALUES(evidence)",$job,$fighter,$evidence,gmdate('Y-m-d H:i:s'))));
    }
    /** Only durable canonical IDs are evidence; dates never infer an import. */
    public static function backfill(int $cursor=0,int $limit=250): array {
        global $wpdb; $t=\MMAF\DataEngine\Migrations\Schema::table_names();
        $batches=(new Repository())->tables['batches'];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT i.id,i.canonical_id,MIN(b.job_id) job_id,COUNT(DISTINCT b.job_id) matching_jobs FROM {$t['source_import_items']} i LEFT JOIN $batches b ON b.import_run_id=i.import_run_id WHERE i.id>%d AND i.item_type='fighter' AND i.canonical_id>0 GROUP BY i.id ORDER BY i.id LIMIT %d",$cursor,min(250,max(1,$limit))),ARRAY_A);
        foreach($rows as $row) {
            $job=(int)$row['matching_jobs']===1?(int)$row['job_id']:0;
            self::record($job,(int)$row['canonical_id'],'canonical_import_item'); $cursor=(int)$row['id'];
        }
        return ['cursor'=>$cursor,'done'=>count($rows)<$limit];
    }
    public static function latest(): int {
        global $wpdb; $table=Schema::tables()['membership'];
        return (int)$wpdb->get_var("SELECT MAX(import_job_id) FROM $table");
    }
    public static function for_page(array $ids): array {
        global $wpdb;$table=Schema::tables()['membership'];$in=implode(',',array_map('absint',$ids));$out=[];
        if(!$in){return $out;}
        foreach($wpdb->get_results("SELECT fighter_id,MAX(import_job_id) import_job_id FROM $table WHERE fighter_id IN ($in) GROUP BY fighter_id",ARRAY_A) as $row){$out[(int)$row['fighter_id']]=(int)$row['import_job_id'];}return $out;
    }
}
