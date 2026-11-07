<?php
namespace MMAF\DataEngine\Services\Priorities;

/** Private editorial report; never registered as a public REST resource. */
final class Report {
    public static function rows(int $limit=0,int $offset=0,int $run=0): array {
        global $wpdb;$t=\MMAF\DataEngine\Migrations\Schema::table_names();
        $s=\MMAF\DataEngine\Services\Scoring\Schema::tables();$p=\MMAF\DataEngine\Services\DataPipeline\Schema::tables();
        if(!$run){$run=(int)$wpdb->get_var("SELECT MAX(id) FROM {$t['ranking_runs']} WHERE formula_version IN ('v1.6','v1.7','v1.8') AND status IN ('draft','completed','active')");}
        $bound=$limit?$wpdb->prepare(' LIMIT %d OFFSET %d',$limit,$offset):'';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT r.*,f.display_name,f.is_public,f.is_rankable,f.rankability_status,f.deleted_soft,p.source_fetched_at,p.last_attempt_at,p.last_attempt_status,p.last_error,a.status AS assessment_status,a.assessment_json FROM ".Schema::table()." r LEFT JOIN {$t['fighters']} f ON f.id=r.fighter_id LEFT JOIN {$p['profiles']} p ON p.source_type='tapology' AND p.source_fighter_id=r.source_fighter_id LEFT JOIN {$s['assessments']} a ON a.fighter_id=r.fighter_id AND a.ranking_run_id=%d ORDER BY r.id$bound",$run),ARRAY_A);
        if($wpdb->last_error){throw new \RuntimeException('Cannot read priority status.');}
        foreach($rows as &$row){
            $a=json_decode($row['assessment_json']??'null',true)?:[];unset($row['assessment_json']);
            $row['ranking_run_id']=$run?:null;$row['career_coverage']=$a['coverage']??'unconfirmed';$row['period_status']=$row['career_coverage'];$row['ranking_status']=$row['assessment_status']??'not_assessed';$row['reasons']=$a['reasons']??[];
            $conflict=(bool)array_filter($row['reasons'],static fn($reason)=>str_contains((string)$reason,'conflict'));
            $failed_attempt=!empty($row['last_attempt_status']) && $row['last_attempt_status']!=='success' && (!$row['source_fetched_at'] || ($row['last_attempt_at']??'')>$row['source_fetched_at']);
            if(!$row['is_active']){$outcome='inactive';}
            elseif($row['identity_status']!=='confirmed'){$outcome='waiting_identity';}
            elseif($conflict || !empty($row['deleted_soft'])){$outcome='conflict';}
            elseif($failed_attempt){$outcome='error';}
            elseif(!$row['source_fetched_at']){$outcome='waiting_fetch';}
            elseif(!$row['fighter_id']){$outcome='fetched';}
            elseif($row['ranking_status']==='ineligible' || str_starts_with((string)$row['rankability_status'],'ineligible_')){$outcome='ineligible';}
            elseif(!$row['is_public'] || !$row['is_rankable'] || $row['rankability_status']!=='rankable'){$outcome='awaiting_approval';}
            elseif($row['ranking_status']==='main' && $row['career_coverage']==='confirmed'){$outcome='ready';}
            else{$outcome='imported';}
            $row['recovery_status']=$outcome;
            $row['recovery_complete']=in_array($outcome,['ready','ineligible'],true);
            $next='resolve_assessment_reasons';
            if(!$row['is_active']){$next='inactive';}
            elseif($row['identity_status']!=='confirmed'){$next='resolve_identity';}
            elseif(!$row['source_fetched_at']){$next='fetch_profile';}
            elseif(!$row['fighter_id']){$next='import_verified_profile';}
            elseif(!$row['is_public'] || !$row['is_rankable']){$next='review_and_approve_profile';}
            elseif($row['last_attempt_status']!=='success'){$next='review_failed_fetch';}
            elseif($row['ranking_status']==='not_assessed'){$next='calculate_draft';}
            elseif($row['ranking_status']==='main'){$next='review_draft';}
            $row['next_action']=$next;
        }unset($row);return $rows;
    }
}
