<?php
namespace MMAF\DataEngine\Services\Scoring;

/** The public DTO excludes private source rows and editor identities. */
final class PublicAssessments {
    public function for_fighters(array $ids,?array $run): array {
        global $wpdb;$career=(new CareerRecords())->for_fighters($ids);$out=[];
        foreach($ids as $id){$record=$career[$id]??['status'=>'unavailable','record'=>null,'verified_at'=>null,'reason'=>'Professional MMA career record has not been accepted.'];unset($record['observation_id'],$record['profile_hash']);foreach(['verified_at','observed_at'] as $key){if(!empty($record[$key])){$record[$key]=str_replace(' ','T',$record[$key]).'Z';}}$out[(int)$id]=['career_record'=>$record,'scoring_assessment'=>null];}
        foreach($out as &$dto){if(($dto['career_record']['source_type']??'')==='manual'){$dto['career_record']['source_url']=null;$dto['career_record']['reason']='Confirmed professional MMA career correction.';}}unset($dto);
        if(!$run || !\MMAF\DataEngine\Services\Formula\FormulaRegistry::has_assessments($run['formula_version']) || !$ids){return $out;}
        $table=Schema::tables()['assessments'];$in=implode(',',array_map('intval',$ids));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT fighter_id,assessment_json FROM $table WHERE ranking_run_id=%d AND fighter_id IN ($in)",(int)$run['id']),ARRAY_A);
        if($wpdb->last_error){throw new \RuntimeException('Cannot read frozen public assessments.');}
        foreach($rows as $row){$assessment=json_decode($row['assessment_json'],true,512,JSON_THROW_ON_ERROR);unset($assessment['input_profile_hash'],$assessment['input_hash'],$assessment['eligibility'],$assessment['source_current_roster']);$assessment['ranking_run_id']=(int)$run['id'];foreach(['verified_at','career_verified_at'] as $key){if(!empty($assessment[$key])){$assessment[$key]=str_replace(' ','T',$assessment[$key]).'Z';}}$out[(int)$row['fighter_id']]['scoring_assessment']=$assessment;}
        return $out;
    }
    public static function methodology(?array $run): array {
        $config=$run?json_decode($run['formula_config_snapshot']??'null',true):null;
        $version=$run['formula_version']??null;
        $career=\MMAF\DataEngine\Services\Formula\FormulaRegistry::is_career_version($version);
        $scope=$career ? ($run['data_scope_version']??\MMAF\DataEngine\Services\Formula\FormulaV18::SCOPE) : ($config['scoring_scope']??null);
        $label=$career ? 'Base points, known finishes and opponent quality across the whole professional MMA career.' : ($version==='v1.7'
            ? 'Career base points; finishes and opponent quality since 9 May 2026.'
            : ($version==='v1.6'?'MMA Future points since 9 May 2026.':'MMA Future Score'));
        $per_finish=is_array($config) && is_numeric($config['finishes']['ko_tko']??null) && \MMAF\DataEngine\Services\Formula\FormulaRegistry::uses_direct_scores((string)$version) ? (float)$config['finishes']['ko_tko'] : \MMAF\DataEngine\Services\Formula\FormulaRegistry::points_per_finish($version);
        return ['formula_version'=>$version,'scoring_scope'=>$scope,'score_label'=>$label,'points_per_finish'=>$per_finish];
    }
}
