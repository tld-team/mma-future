<?php
namespace MMAF\DataEngine\Services\Scoring;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\Formula\FormulaV18;
use MMAF\DataEngine\Services\RegularImport\Repository;

/** Verify whole-career coverage without manufacturing bouts from aggregate differences. */
final class CareerAssessment {
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
            if(!self::accepted($row) || !self::valid_date($date) || $date>$end){continue;}
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
    /** Strict source proof used by assessment and by the pre-preparation preview. */
    public static function source_proof(array $profile,?string $verified): bool {
        $e=$profile['career_history_evidence']??[];$loading=$e['loading']??[];
        $pagination_complete=($loading['pagination_present']??true)===false || ($loading['all_pages_loaded']??false)===true;
        $filters_complete=($loading['filters_present']??true)===false || ($loading['filters_active']??true)===false;
        return ($profile['history_scope']??'')==='professional_mma' && ($profile['history_scope_verified']??false)===true
            && ($profile['parser_version']??'')==='profile_dom_v4'
            && ($e['contract_version']??0)===1 && ($e['scope']??'')===FormulaV18::SCOPE
            && ($e['complete']??false)===true && ($e['row_scope_verified']??false)===true && ($e['aggregate_matches']??false)===true
            && ($loading['contract_version']??0)===1 && ($loading['canonical_card_dom']??false)===true
            && ($loading['first_page_verified']??false)===true && ($loading['load_complete']??false)===true
            && ($loading['navigation_exhausted']??false)===true && ($loading['hidden_cards']??-1)===0
            && $pagination_complete && $filters_complete
            && self::valid_date($e['checked_through']??null) && $verified && $e['checked_through']===substr($verified,0,10);
    }
    /** Source-only control totals. These may permit preparation but are never ranking inputs. */
    public static function source_stats(array $rows,string $end): array {
        $stats=['wins'=>0,'losses'=>0,'draws'=>0,'nc'=>0,'pro_fights_count'=>0,'finish_wins'=>0,'finish_rate'=>null,'last_fight_date'=>null];
        foreach($rows as $row){
            if(($row['record_eligible']??true)===false || ($row['is_upcoming']??false)===true || ($row['is_cancelled']??false)===true || ($row['is_exhibition']??false)===true || ($row['is_amateur']??false)===true){continue;}
            $date=$row['fight_date']??null;$result=$row['result']??null;
            $classification=in_array($row['classification_evidence']??'', ['profile_dom_classification','profile_section_classification','bout_dom_classification'],true) && ($row['is_professional']??false)===true && ($row['discipline']??'')==='mma';
            if(($row['classification_evidence']??'')==='profile_section_classification' && (($row['classification_proof']['section']??'')!=='professional_mma' || ($row['classification_proof']['row_boundary_verified']??false)!==true)){$classification=false;}
            if(!$classification || !self::valid_date($date) || $date>$end || !in_array($result,['win','loss','draw','no_contest'],true) || !empty($row['source_conflict_reason'])){continue;}
            $key=['win'=>'wins','loss'=>'losses','draw'=>'draws','no_contest'=>'nc'][$result];++$stats[$key];++$stats['pro_fights_count'];
            if($result==='win' && in_array($row['method_category']??'', ['ko_tko','submission'],true)){++$stats['finish_wins'];}
            $stats['last_fight_date']=max($stats['last_fight_date']??'',$date);
        }
        if($stats['wins']){$stats['finish_rate']=$stats['finish_wins']/$stats['wins'];}
        return $stats;
    }
    public function evaluate(int $id,array $history,?array $career,array $scoring_items,string $end,string $calculated_at): array {
        global $wpdb;$t=Canonical::table_names();
        $saved=(new ProfileStore())->for_fighter($id);$profile=$saved['profile']??[];
        $verified=$saved['source_fetched_at']??null;$e=$profile['career_history_evidence']??[];
        $reasons=[];$warnings=[];$outside=0;$source_rows=[];$matched=[];
        $coverage=$wpdb->get_row($wpdb->prepare("SELECT status,source_fetched_at FROM {$t['fighter_career_coverage']} WHERE fighter_id=%d",$id),ARRAY_A);
        if(!$coverage || ($coverage['status']??'')!=='complete'){$reasons[]=(($coverage['status']??'')==='conflict')?'career_history_conflict':'career_coverage_unconfirmed';}
        elseif($verified && ($coverage['source_fetched_at']??null)!==$verified){$reasons[]='career_snapshot_mismatch';}
        if(!$career || $career['status']!=='accepted'){$reasons[]=$career?'career_record_review':'career_record_missing';}
        elseif(!self::fresh($career['verified_at'],$calculated_at)){$reasons[]='career_verification_stale';}
        if(!self::fresh($verified,$calculated_at)){$reasons[]='career_verification_stale';}
        $proof=self::source_proof($profile,$verified);
        if(!$proof){$reasons[]='career_coverage_unconfirmed';}
        $ids=array_map('intval',array_column($history,'bout_id'));$sources=[];$opponents=[];
        if($ids){
            $in=implode(',',array_unique($ids));
            foreach($wpdb->get_results("SELECT bout_id,source_bout_id FROM {$t['bout_sources']} WHERE bout_id IN ($in) AND source_type='tapology'",ARRAY_A) as $source){$sources[$source['source_bout_id']][]=(int)$source['bout_id'];}
            foreach($wpdb->get_results("SELECT p.bout_id,fs.source_fighter_id FROM {$t['bout_participants']} p JOIN {$t['fighter_sources']} fs ON fs.fighter_id=p.opponent_fighter_id AND fs.source_type='tapology' WHERE p.fighter_id=".(int)$id." AND p.bout_id IN ($in)",ARRAY_A) as $opponent){$opponents[(int)$opponent['bout_id']][]=$opponent['source_fighter_id'];}
        }
        if($wpdb->last_error){throw new \RuntimeException('Cannot compare career identities.');}
        $by_id=[];foreach($history as $row){$by_id[(int)$row['bout_id']]=$row;}
        $previous=null;$boundary=false;$boundary_row=null;$source_last_professional_fight_date=null;
        foreach($profile['fight_history']??[] as $row){
            if(($row['is_upcoming']??false)===true || ($row['is_cancelled']??false)===true || ($row['is_exhibition']??false)===true || ($row['is_amateur']??false)===true || (!empty($row['discipline']) && $row['discipline']!=='mma')){continue;}
            $date=$row['fight_date']??null;
            if(!self::valid_date($date)){$reasons[]='unknown_fight_date';continue;}
            if($previous!==null && $date>$previous){$reasons[]='history_order_unverified';}$previous=$date;
            if($date>$end){continue;}
            $source_rows[]=$row;
            $classification=in_array($row['classification_evidence']??'', ['profile_dom_classification','profile_section_classification','bout_dom_classification'],true) && ($row['is_professional']??false)===true && ($row['discipline']??'')==='mma';
            if(($row['classification_evidence']??'')==='profile_section_classification' && (($row['classification_proof']['section']??'')!=='professional_mma' || ($row['classification_proof']['row_boundary_verified']??false)!==true)){$classification=false;}
            if(!$classification){$reasons[]='career_professional_scope_missing';continue;}
            if(!in_array($row['result']??'', ['win','loss','draw','no_contest'],true) || !empty($row['source_conflict_reason'])){$reasons[]='career_result_conflict';continue;}
            if(($row['record_eligible']??true)===false){
                if(($row['record_eligibility_evidence']??'')!=='source_profile_record_ineligible_mma'){$reasons[]='career_record_exclusion_unconfirmed';}
                continue;
            }
            $source_last_professional_fight_date=max($source_last_professional_fight_date??'',$date);
            $possible=$sources[$row['source_bout_id']??'']??[];
            if(!$possible && empty($row['source_bout_id'])){
                foreach($by_id as $bid=>$canonical){if(($canonical['event_date']??null)===$date && in_array($row['opponent_source_id']??'', $opponents[$bid]??[],true)){$possible[]=$bid;}}
            }
            if(count(array_unique($possible))!==1){$reasons[]=$possible?'career_identity_conflict':'missing_bout_in_career';continue;}
            $bid=$possible[0];$canonical=$by_id[$bid]??[];
            if(!in_array($row['opponent_source_id']??'', $opponents[$bid]??[],true) || ($canonical['event_date']??null)!==$date){$reasons[]='career_identity_conflict';continue;}
            if(!self::accepted($canonical) || $canonical['result_for_fighter']!==$row['result']){$reasons[]='career_result_conflict';continue;}
            if(isset($matched[$bid])){$reasons[]='career_duplicate_source_bout';}
            $matched[$bid]=true;
        }
        foreach($history as $row){
            if(!empty($row['deleted_soft']) || !in_array($row['status']??'', ['valid','completed'],true)){continue;}
            $date=$row['event_date']??null;
            if(!self::valid_date($date)){$reasons[]='unknown_fight_date';continue;}
            if($date>$end){continue;}
            if(!self::accepted($row)){$reasons[]='career_result_conflict';}
            elseif(!isset($matched[(int)$row['bout_id']])){$reasons[]='known_result_not_covered_refresh_required';}
        }
        $stats=self::stats($history,$end);$sample=$stats['wins']+$stats['losses'];
        if($sample<1){$reasons[]='insufficient_sample_size';}elseif($sample<3){$warnings[]='small_scoring_sample';}
        $aggregate=$career && ($career['status']??'')==='accepted' ? CareerRecords::counts((array)($career['record']??[])) : null;
        if($aggregate){foreach(['wins','losses','draws','nc'] as $key){if($aggregate[$key]!==$stats[$key]){$reasons[]='career_record_mismatch';}}}
        if(($verified && substr($verified,0,10)>$end) || (!empty($career['observed_at']) && substr($career['observed_at'],0,10)>$end)){$reasons[]='career_snapshot_after_reference_date';}
        if($aggregate && !empty($career['profile_hash']) && ($career['source_type']??'')==='tapology' && $career['profile_hash']!==($saved['content_hash']??null)){$reasons[]='career_snapshot_mismatch';}
        if($aggregate && ($career['source_type']??'')!=='tapology' && substr((string)($career['observed_at']??''),0,10)!==substr((string)$verified,0,10)){$reasons[]='career_snapshot_mismatch';}
        $accepted=[];foreach($history as $row){if(self::accepted($row) && self::valid_date($row['event_date']??null) && $row['event_date']<=$end && in_array($row['result_for_fighter'],['win','loss'],true)){$accepted[(int)$row['bout_id']]=$row['result_for_fighter'];}}
        $scored=[];$details=['known_records'=>0,'unknown_records'=>0,'known_methods'=>0,'unknown_methods'=>0,'known_win_methods'=>0,'unknown_win_methods'=>0];
        foreach($scoring_items as $item){
            $bid=(int)$item['bout_id'];
            if(isset($scored[$bid]) || ($accepted[$bid]??null)!==$item['result_for_fighter']){$reasons[]='career_scoring_mismatch';}
            $stored_evidence=RecordEvidence::decode($item['record_evidence_json']??null);
            $scored[$bid]=true;if(!empty($stored_evidence['method_conflict'])){$reasons[]='career_method_conflict';}
            if(($stored_evidence['fighter']['status']??'')==='conflict'){$reasons[]='career_prefight_record_conflict';}
            $record=RecordEvidence::opponent($item);
            ++$details[$record['status']==='known'?'known_records':'unknown_records'];
            if($record['status']==='conflict'){$reasons[]='career_prefight_record_conflict';}
            elseif($record['status']!=='known'){$warnings[]='neutral_unknown_prefight_record';}
            $method=$item['method_category']??'unknown';$known=in_array($method,['ko_tko','submission','decision','dq'],true);
            ++$details[$known?'known_methods':'unknown_methods'];
            if($item['result_for_fighter']==='win'){++$details[$known?'known_win_methods':'unknown_win_methods'];}
            if(!$known){$warnings[]='neutral_unknown_method';}
            foreach($source_rows as $source_row){
                if(($source_row['source_bout_id']??null)!==($item['source_bout_id']??null)){continue;}
                $source_method=$source_row['method_category']??'unknown';
                if($known && in_array($source_method,['ko_tko','submission','decision','dq'],true) && $source_method!==$method){$reasons[]='career_method_conflict';}
                $proof=$source_row['record_evidence']['opponent']??[];
                if(($proof['status']??'')==='conflict' || (($proof['status']??'')==='known' && $record['status']==='known' && ((int)($proof['record']['wins']??-1)!==$record['record']['wins'] || (int)($proof['record']['losses']??-1)!==$record['record']['losses']))){$reasons[]='career_prefight_record_conflict';}
            }
        }
        if(array_diff_key($accepted,$scored) || array_diff_key($scored,$accepted)){$reasons[]='career_scoring_mismatch';}
        $stats['known_win_methods']=$details['known_win_methods'];$stats['unknown_win_methods']=$details['unknown_win_methods'];
        $stats['method_coverage']=$stats['wins'] ? $details['known_win_methods']/$stats['wins'] : null;
        $reasons=array_values(array_unique($reasons));
        return ['contract_version'=>2,'methodology'=>FormulaV18::SCOPE,'reference_date'=>$end,
            '_source_inputs'=>['source_url'=>$saved['source_url']??null,'observed_at'=>$verified,'content_hash'=>$saved['content_hash']??null,'career_record_evidence'=>$profile['career_record_evidence']??null,'career_history_evidence'=>$e,'career_source_rows'=>$source_rows,'method_statistics'=>$profile['record_summary']['method_statistics']??null],
            'source_current_roster'=>self::current_roster_verified($profile['current_roster']??null) && self::fresh($verified,$calculated_at)?$profile['current_roster']:null,
            'status'=>$reasons?'provisional':'main','period'=>['start'=>null,'end'=>$end,'inclusive'=>true],
            'sample_size'=>$sample,'career_stats'=>$stats,'career_record_used'=>$aggregate,'last_professional_fight_date'=>$stats['last_fight_date'],
            'coverage'=>$proof && !$reasons?'confirmed':'unconfirmed','detail_coverage'=>$details,'verified_at'=>$verified,'checked_through'=>$e['checked_through']??null,
            'career_verified_at'=>$career['verified_at']??null,'reasons'=>$reasons,'warnings'=>array_values(array_unique($warnings)),
            'score'=>null,'raw_score'=>null,'total_score'=>null,'known_points'=>null,'rank_position'=>null,'input_profile_hash'=>$saved['content_hash']??null];
    }

    public static function input_hash(array $inputs): string {
        $stable=function($value) use (&$stable){if(is_array($value)){if(!array_is_list($value)){ksort($value);}foreach($value as &$item){$item=$stable($item);}unset($item);}return $value;};
        return hash('sha256',wp_json_encode($stable($inputs)));
    }
    /** Independent activation check from frozen inputs, never today's fighter statistics. */
    public static function validate_frozen(array $assessment,array $inputs,array $snapshot,array $run): void {
        $fail=static function(){throw new \RuntimeException('Invalid or inconsistent frozen v1.8 career assessment.');};
        if(($assessment['contract_version']??0)!==2 || ($assessment['status']??'')!=='main' || ($assessment['coverage']??'')!=='confirmed' || !empty($assessment['reasons']) || ($assessment['methodology']??'')!==FormulaV18::SCOPE || ($run['data_scope_version']??'')!==FormulaV18::SCOPE || !hash_equals(self::input_hash($inputs),(string)($assessment['input_hash']??''))){$fail();}
        if(($inputs['reference_date']??null)!==$run['reference_date'] || ($assessment['period']['start']??null)!==null || ($inputs['formula_config']??null)!=FormulaV18::config() || json_decode($run['formula_config_snapshot'],true)!=FormulaV18::config()){$fail();}
        $stats=self::stats($inputs['accepted_career_bouts']??[],$run['reference_date']);
        $aggregate=$assessment['career_record_used']??[];
        foreach(['wins','losses','draws','nc'] as $key){if(($aggregate[$key]??null)!==$stats[$key] || ($assessment['career_stats'][$key]??null)!==$stats[$key]){$fail();}}
        $expected=[];foreach($inputs['accepted_career_bouts']??[] as $row){if(in_array($row['result_for_fighter'],['win','loss'],true)){$expected[(int)$row['bout_id']]=$row['result_for_fighter'];}}
        $formula=new FormulaV18();$score=0.0;$seen=[];
        foreach($inputs['scoring_bouts']??[] as $item){
            $id=(int)$item['bout_id'];$result=$item['result_for_fighter'];
            if(isset($seen[$id]) || ($expected[$id]??null)!==$result || !self::valid_date($item['event_date']??null) || $item['event_date']>$run['reference_date']){$fail();}
            $seen[$id]=true;$record=RecordEvidence::opponent($item);
            if($record['status']==='conflict'){$fail();}
            $score+=$formula->base_record_points($result)+$formula->finish_points($result,$item['method_category']??null);
            $score+=$result==='win'?$formula->opponent_differential_points_for_win($record['diff']):$formula->loss_quality_penalty_points($record['diff']);
        }
        if(array_diff_key($expected,$seen) || array_diff_key($seen,$expected)){$fail();}
        $age=$inputs['breakdown']['tie_breaker']['age']??null;
        if(!is_int($age)){$fail();}
        $score=round($score+$formula->age_adjustment_points($age),3);
        foreach([$assessment['total_score']??null,$assessment['known_points']??null,$snapshot['total_score']??null,$snapshot['raw_score']??null,$inputs['breakdown']['total_score']??null] as $value){if(!is_numeric($value) || abs((float)$value-$score)>0.000001){$fail();}}
        $sample=$stats['wins']+$stats['losses'];
        if((int)$snapshot['sample_size']!==$sample || ($assessment['sample_size']??null)!==$sample || ($inputs['breakdown']['sample_size']??null)!==$sample){$fail();}
        if(json_decode($snapshot['breakdown_json']??'null',true)!=($inputs['breakdown']??null)){$fail();}
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
