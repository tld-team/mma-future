<?php
namespace MMAF\DataEngine\Services\Operations;
use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\RegularImport\{Repository,Transaction};

/** Only successful, evidenced activations. No guessed ordering from calculation dates. */
final class Activations {
    public static function record(int $run,array $boards,string $date,int $actor,int $audit): void {
        global $wpdb;$table=Schema::tables()['activations'];
        foreach(array_unique($boards) as $board){Repository::check($wpdb->query($wpdb->prepare("INSERT INTO $table (ranking_run_id,board_key,activated_at,actor_user_id,audit_id) VALUES (%d,%s,%s,%d,%d) ON DUPLICATE KEY UPDATE audit_id=VALUES(audit_id)",$run,$board,$date,$actor,$audit)));}
    }
    public static function backfill(int $cursor=0,int $limit=250): array {
        global $wpdb;$t=Canonical::table_names();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,entity_id,after_json,created_at,actor_user_id FROM {$t['audit_log']} WHERE id>%d AND action='ranking_activated' AND entity_type='ranking_run' ORDER BY id LIMIT %d",$cursor,$limit),ARRAY_A);
        foreach($rows as $row){$value=json_decode($row['after_json'],true)?:[];$cursor=(int)$row['id'];
            if(($value['status']??'')!=='activated' || (int)($value['ranking_run_id']??0)!==(int)$row['entity_id'] || empty($value['boards'])){continue;}
            // Confirm the saved board actually exists in the immutable snapshot.
            $boards=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT board_key FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d",$row['entity_id']));
            $confirmed=array_intersect($value['boards'],$boards);
            Transaction::run(static function()use($row,$confirmed){self::record((int)$row['entity_id'],$confirmed,$row['created_at'],(int)$row['actor_user_id'],(int)$row['id']);});
        }
        return ['cursor'=>$cursor,'done'=>count($rows)<$limit];
    }
}
