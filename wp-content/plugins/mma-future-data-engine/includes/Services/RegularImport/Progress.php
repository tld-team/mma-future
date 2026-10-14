<?php
namespace MMAF\DataEngine\Services\RegularImport;

final class Progress {
    public static function forJob(array $job): array {
        global $wpdb;$id=(int)$job['id'];$p=\MMAF\DataEngine\Services\DataPipeline\Schema::tables();$repo=new Repository();$phase=$job['phase'];
        if($phase==='stored_career'){
            $groups=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) total FROM {$p['queue']} WHERE import_job_id=%d GROUP BY status",$id),ARRAY_A);
            $counts=[];foreach($groups as $r){$counts[$r['status']]=(int)$r['total'];}$total=array_sum($counts);
            return ['unit'=>'Profiles','processed'=>$total-($counts['pending']??0),'total'=>$total,'counts'=>$counts];
        }
        if($phase==='stored_career_verify'){
            $cursor=(int)($job['meta']['career_verify_cursor']??0);
            $all=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$repo->tables['items']} WHERE job_id=%d AND kind='actual_stored_career_profile'",$id));
            $done=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$repo->tables['items']} WHERE job_id=%d AND kind='actual_stored_career_profile' AND id<=%d",$id,$cursor));
            return ['unit'=>'Verified careers','processed'=>$done,'total'=>$all];
        }
        if($phase==='stats'){
            $o=\MMAF\DataEngine\Services\Operations\Schema::tables();
            $row=$wpdb->get_row($wpdb->prepare("SELECT phase,checkpoint_json FROM {$o['jobs']} WHERE request_key=%s",'import:'.$id.':stats'),ARRAY_A);
            if($row){$cp=json_decode($row['checkpoint_json'],true)?:[];$f=\MMAF\DataEngine\Migrations\Schema::table_names()['fighters'];return ['unit'=>'Fighters checked','phase'=>$row['phase'],'processed'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $f WHERE id<=%d",(int)($cp['cursor']??0))),'total'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $f WHERE id<=%d",(int)($cp['max_id']??0)))];}
        }
        return [];
    }
}
