<?php
namespace MMAF\DataEngine\Services\Scoring;

/** The public DTO excludes private source rows and editor identities. */
final class PublicAssessments {
    public function for_fighters(array $ids,?array $run): array {
        global $wpdb;$career=(new CareerRecords())->for_fighters($ids);$out=[];
        foreach($ids as $id){$record=$career[$id]??['status'=>'unavailable','record'=>null,'verified_at'=>null,'reason'=>'Professional MMA career record has not been accepted.'];unset($record['observation_id'],$record['profile_hash']);foreach(['verified_at','observed_at'] as $key){if(!empty($record[$key])){$record[$key]=str_replace(' ','T',$record[$key]).'Z';}}$out[(int)$id]=['career_record'=>$record,'scoring_assessment'=>null];}
        foreach($out as &$dto){if(($dto['career_record']['source_type']??'')==='manual'){$dto['career_record']['source_url']=null;$dto['career_record']['reason']='Confirmed professional MMA career correction.';}}unset($dto);
        if(!$run || !in_array($run['formula_version'],['v1.6','v1.7'],true) || !$ids){return $out;}
        $table=Schema::tables()['assessments'];$in=implode(',',array_map('intval',$ids));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT fighter_id,assessment_json FROM $table WHERE ranking_run_id=%d AND fighter_id IN ($in)",(int)$run['id']),ARRAY_A);
        if($wpdb->last_error){throw new \RuntimeException('Cannot read frozen public assessments.');}
        foreach($rows as $row){$assessment=json_decode($row['assessment_json'],true,512,JSON_THROW_ON_ERROR);unset($assessment['input_profile_hash'],$assessment['eligibility']);$assessment['ranking_run_id']=(int)$run['id'];foreach(['verified_at','career_verified_at'] as $key){if(!empty($assessment[$key])){$assessment[$key]=str_replace(' ','T',$assessment[$key]).'Z';}}$out[(int)$row['fighter_id']]['scoring_assessment']=$assessment;}
        return $out;
    }
    public static function methodology(?array $run): array {
        $config=$run?json_decode($run['formula_config_snapshot']??'null',true):null;
        $scope=$config['scoring_scope']??null;
        $version=$run['formula_version']??null;
        $label=$version==='v1.7'
            ? 'Osnovni poeni za celu karijeru; završnice i kvalitet protivnika od 9. maja 2026.'
            : ($version==='v1.6'?'MMA Future bodovi od 9. maja 2026.':'MMA Future Score');
        return ['formula_version'=>$version,'scoring_scope'=>$scope,'score_label'=>$label];
    }
}
