<?php
namespace MMAF\DataEngine\Services\Scoring;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\Formula\FormulaV16;
use MMAF\DataEngine\Services\RegularImport\Repository;

/** Verify the common period against real source rows and the current accepted results. */
final class PeriodAssessment {
    public static function current_roster_verified($proof): bool {
        return is_array($proof) && ($proof['verified']??false)===true && ($proof['promotion']??'')==='ufc' && ($proof['evidence']??'')==='current_ufc_ranking_widget' && ($proof['widget_boundary_verified']??false)===true && ($proof['logo_alt']??'')==='UFC' && strtolower((string)($proof['heading']??''))==='ufc ranking' && preg_match('~^https://(?:www\.)?tapology\.com/rankings/ufc/[^/?#]+$~D',(string)($proof['ranking_url']??''))===1;
    }
    public static function fresh(?string $verified_at,string $calculated_at): bool {
        if(!$verified_at){return false;}
        $checked=strtotime($verified_at.' UTC');$frozen=strtotime($calculated_at.' UTC');
        return $checked!==false && $frozen!==false && $checked<=$frozen && $frozen-$checked<=7*86400;
    }
    public static function valid_date($date): bool {
        if(!is_string($date)){return false;}
        $value=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        return $value && $value->format('Y-m-d')===$date;
    }
    public static function stats(array $history,string $end): array {
        $stats=['wins'=>0,'losses'=>0,'draws'=>0,'nc'=>0,'pro_fights_count'=>0,'finish_wins'=>0,'finish_rate'=>null,'last_fight_date'=>null];
        foreach($history as $row){
            $date=$row['event_date']??null;
            if(!self::accepted($row) || !self::valid_date($date) || $date<FormulaV16::PERIOD_START || $date>$end){continue;}
            $key=['win'=>'wins','loss'=>'losses','draw'=>'draws','no_contest'=>'nc'][$row['result_for_fighter']];
            ++$stats[$key];++$stats['pro_fights_count'];
            if($key==='wins' && in_array($row['method_category']??'', ['ko_tko','submission'],true)){++$stats['finish_wins'];}
            $stats['last_fight_date']=max($stats['last_fight_date']??'',$date);
        }
        if($stats['wins']){$stats['finish_rate']=$stats['finish_wins']/$stats['wins'];}
        return $stats;
    }
    public static function accepted(array $row): bool {
        return empty($row['deleted_soft']) && !empty($row['event_exists']) && in_array($row['status']??'', ['valid','completed'],true) && ($row['participant_count']??0)===2 && ($row['countable_participants']??0)===2 && ($row['reciprocal_results']??0)===1 && ($row['reciprocal_identities']??0)===1 && in_array($row['result_for_fighter']??'', ['win','loss','draw','no_contest'],true);
    }
    public function evaluate(int $id,array $history,?array $career,string $end,string $calculated_at): array {
        global $wpdb;$t=Canonical::table_names();
        $saved=(new ProfileStore())->for_fighter($id);$profile=$saved['profile']??[];
        $verified=$saved['source_fetched_at']??null;$e=$profile['period_evidence']??[];
        $reasons=[];$warnings=[];$outside=0;$source_rows=[];$matched=[];
        if(!$career || $career['status']!=='accepted'){$reasons[]=$career?'career_record_review':'career_record_missing';}
        elseif(!self::fresh($career['verified_at'],$calculated_at)){$reasons[]='career_verification_stale';}
        if(!self::fresh($verified,$calculated_at)){$reasons[]='period_verification_stale';}
        $scope=($profile['history_scope']??'')==='professional_mma' && ($profile['history_scope_verified']??false)===true;
        $proof=$scope && ($e['start_date']??'')===FormulaV16::PERIOD_START && ($e['scope_verified']??false)===true && ($e['first_page_verified']??false)===true && ($e['load_complete']??false)===true && ($e['chronological_descending']??false)===true && (($e['boundary_crossed']??false)===true || ($e['history_exhausted']??false)===true) && self::valid_date($e['checked_through']??null) && $verified && $e['checked_through']===substr($verified,0,10);
        if(!$proof){$reasons[]='period_coverage_unconfirmed';}
        $ids=array_map('intval',array_column($history,'bout_id'));$sources=[];$opponents=[];
        if($ids){
            $in=implode(',',array_unique($ids));
            foreach($wpdb->get_results("SELECT bout_id,source_bout_id FROM {$t['bout_sources']} WHERE bout_id IN ($in) AND source_type='tapology'",ARRAY_A) as $source){$sources[$source['source_bout_id']][]=(int)$source['bout_id'];}
            foreach($wpdb->get_results("SELECT p.bout_id,fs.source_fighter_id FROM {$t['bout_participants']} p JOIN {$t['fighter_sources']} fs ON fs.fighter_id=p.opponent_fighter_id AND fs.source_type='tapology' WHERE p.fighter_id=".(int)$id." AND p.bout_id IN ($in)",ARRAY_A) as $opponent){$opponents[(int)$opponent['bout_id']][]=$opponent['source_fighter_id'];}
        }
        if($wpdb->last_error){throw new \RuntimeException('Cannot compare period identities.');}
        $by_id=[];foreach($history as $row){$by_id[(int)$row['bout_id']]=$row;}
        $previous=null;$boundary=false;$boundary_row=null;
        foreach($profile['fight_history']??[] as $row){
            if(($row['is_upcoming']??false)===true || ($row['is_cancelled']??false)===true || ($row['is_exhibition']??false)===true || ($row['is_amateur']??false)===true || (!empty($row['discipline']) && $row['discipline']!=='mma')){continue;}
            $date=$row['fight_date']??null;
            if(!self::valid_date($date)){$reasons[]='unknown_fight_date';continue;}
            if($previous!==null && $date>$previous){$reasons[]='history_order_unverified';}$previous=$date;
            if($date<FormulaV16::PERIOD_START){++$outside;if(!$boundary){$boundary_row=$row;}$boundary=true;continue;}
            if($date>$end){continue;}
            if($boundary){$reasons[]='history_order_unverified';}
            $source_rows[]=$row;
            $classification=in_array($row['classification_evidence']??'', ['profile_dom_classification','profile_section_classification','bout_dom_classification'],true) && ($row['is_professional']??false)===true && ($row['discipline']??'')==='mma';
            if(($row['classification_evidence']??'')==='profile_section_classification' && (($row['classification_proof']['section']??'')!=='professional_mma' || ($row['classification_proof']['row_boundary_verified']??false)!==true)){$classification=false;}
            if(!$classification){$reasons[]='period_professional_scope_missing';continue;}
            if(!in_array($row['result']??'', ['win','loss','draw','no_contest'],true) || !empty($row['source_conflict_reason'])){$reasons[]='period_result_conflict';continue;}
            if(($row['record_eligible']??true)===false){
                if(($row['record_eligibility_evidence']??'')!=='source_profile_record_ineligible_mma'){$reasons[]='period_record_exclusion_unconfirmed';}
                continue;
            }
            $possible=$sources[$row['source_bout_id']??'']??[];
            if(!$possible && empty($row['source_bout_id'])){
                foreach($by_id as $bid=>$canonical){if(($canonical['event_date']??null)===$date && in_array($row['opponent_source_id']??'', $opponents[$bid]??[],true)){$possible[]=$bid;}}
            }
            if(count(array_unique($possible))!==1){$reasons[]=$possible?'period_identity_conflict':'missing_bout_in_period';continue;}
            $bid=$possible[0];$canonical=$by_id[$bid]??[];
            if(!in_array($row['opponent_source_id']??'', $opponents[$bid]??[],true) || ($canonical['event_date']??null)!==$date){$reasons[]='period_identity_conflict';continue;}
            if(!self::accepted($canonical) || $canonical['result_for_fighter']!==$row['result']){$reasons[]='period_result_conflict';continue;}
            $matched[$bid]=true;
        }
        if(($e['boundary_crossed']??false)===true && !$boundary){$reasons[]='period_boundary_unverified';}
        foreach($history as $row){
            if(!empty($row['deleted_soft']) || !in_array($row['status']??'', ['valid','completed'],true)){continue;}
            $date=$row['event_date']??null;
            if(!self::valid_date($date)){$reasons[]='unknown_fight_date';continue;}
            if($date<FormulaV16::PERIOD_START || $date>$end){continue;}
            if(!self::accepted($row)){$reasons[]='period_result_conflict';}
            elseif(!isset($matched[(int)$row['bout_id']])){$reasons[]='known_result_not_covered_refresh_required';}
        }
        $stats=self::stats($history,$end);$sample=$stats['wins']+$stats['losses'];
        if($sample<1){$reasons[]='insufficient_sample_size';}elseif($sample<3){$warnings[]='small_scoring_sample';}
        return ['_source_inputs'=>['source_url'=>$saved['source_url']??null,'observed_at'=>$verified,'content_hash'=>$saved['content_hash']??null,'career_record_evidence'=>$profile['career_record_evidence']??null,'period_evidence'=>$e,'current_roster'=>$profile['current_roster']??null,'period_source_rows'=>$source_rows,'boundary_row'=>$boundary_row],'source_current_roster'=>self::current_roster_verified($profile['current_roster']??null) && self::fresh($verified,$calculated_at)?$profile['current_roster']:null,'status'=>$reasons?'provisional':'main','period'=>['start'=>FormulaV16::PERIOD_START,'end'=>$end,'inclusive'=>true],'sample_size'=>$sample,'period_stats'=>$stats,'coverage'=>$proof && !array_intersect($reasons,['period_coverage_unconfirmed','unknown_fight_date','history_order_unverified','period_boundary_unverified','period_professional_scope_missing','period_result_conflict','period_identity_conflict','missing_bout_in_period','period_record_exclusion_unconfirmed','known_result_not_covered_refresh_required'])?'confirmed':'unconfirmed','verified_at'=>$verified,'checked_through'=>$e['checked_through']??null,'career_verified_at'=>$career['verified_at']??null,'reasons'=>array_values(array_unique($reasons)),'warnings'=>$warnings,'history_outside_period_rows'=>$outside,'total_score'=>null,'rank_position'=>null,'input_profile_hash'=>$saved['content_hash']??null];
    }
    public function save(int $run,int $id,array $assessment,array $inputs,string $created_at): void {
        if(!$run){return;}
        global $wpdb;$table=Schema::tables()['assessments'];
        $row=['ranking_run_id'=>$run,'fighter_id'=>$id,'status'=>$assessment['status'],'total_score'=>$assessment['total_score'],'sample_size'=>$assessment['sample_size'],'period_start'=>$assessment['period']['start'],'period_end'=>$assessment['period']['end'],'verified_at'=>$assessment['verified_at'],'assessment_json'=>wp_json_encode($assessment),'inputs_json'=>wp_json_encode($inputs),'created_at'=>$created_at];
        $old=$wpdb->get_row($wpdb->prepare("SELECT assessment_json,inputs_json FROM $table WHERE ranking_run_id=%d AND fighter_id=%d",$run,$id),ARRAY_A);
        if($old){if($old['assessment_json']!==$row['assessment_json'] || $old['inputs_json']!==$row['inputs_json']){throw new \RuntimeException('Frozen assessment inputs changed during resume.');}return;}
        Repository::check($wpdb->insert($table,$row));
    }
}
