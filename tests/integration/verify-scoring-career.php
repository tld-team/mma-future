<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';

use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\Formula\FormulaV18;
use MMAF\DataEngine\Services\Formula\FormulaV19;
use MMAF\DataEngine\Services\RegularImport\{CareerHistory,Transaction,WriteGuard};
use MMAF\DataEngine\Services\Scoring\{CareerAssessment,PublicAssessments,Schema as ScoringSchema};
use MMAF\DataEngine\Services\{RankingActivationService,RankingCalculatorService,StatsRebuildService};
use MMAF\DataEngine\Repositories\{RankingCurrentRepository,RankingRunRepository};

function career_v18_check($ok,string $why): void {if(!$ok){throw new RuntimeException($why);}echo "PASS: $why\n";}

wp_set_current_user(1);\MMAF\DataEngine\Migrations\MigrationRunner::run();
$t=\MMAF\DataEngine\Migrations\Schema::table_names();$s=ScoringSchema::tables();
$live=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)));
WriteGuard::$worker=true;Transaction::begin();
try{
    $flags=['is_public'=>1,'is_rankable'=>1,'rankability_status'=>'rankable','status'=>'verified'];
    $a=fixture_fighter(97981101,'Career V18 A',$flags);$b=fixture_fighter(97981102,'Career V18 B');
    $rows=[
        fixture_fight(97981501,$b,'win',['fight_date'=>'2025-05-08','method_category'=>'ko_tko','prefight_record_raw'=>'0-0-0','opponent_record_raw'=>'0-0-0']),
        fixture_fight(97981502,$b,'win',['fight_date'=>'2026-05-09','method_category'=>'unknown','prefight_record_raw'=>'1-0-0','opponent_record_raw'=>null]),
        fixture_fight(97981503,$b,'loss',['fight_date'=>'2026-08-01','method_category'=>'decision','prefight_record_raw'=>'2-0-0','opponent_record_raw'=>'1-1-0']),
        fixture_fight(97981504,$b,'no_contest',['fight_date'=>'2026-09-01','method_category'=>'no_contest','prefight_record_raw'=>'2-1-0','opponent_record_raw'=>'2-1-0','final_result_verified'=>true]),
    ];
    foreach($rows as &$row){
        $own=$row['prefight_record_raw']??null;$opp=$row['opponent_record_raw']??null;
        $parse=static function($raw){if(!$raw){return ['status'=>'unknown','reason'=>'prefight_semantics_unproven'];}[$w,$l,$d]=array_map('intval',explode('-',$raw));return ['status'=>'known','reason'=>null,'evidence'=>'labelled_record_before_fight','record'=>['wins'=>$w,'losses'=>$l,'draws'=>$d,'nc'=>0],'raw'=>$raw,'source'=>'fixture','observed_at'=>'2026-11-04T10:00:00Z','parser_version'=>'profile_dom_v4'];};
        $row['record_evidence']=['fighter'=>$parse($own),'opponent'=>$parse($opp)];
    }unset($row);$rows=array_reverse($rows);
    $profile=fixture_profile($a,$rows,['wins'=>2,'losses'=>1,'draws'=>0,'no_contests'=>1],[
        'scraped_at'=>'2026-11-04T10:00:00Z','parser_version'=>'profile_dom_v4',
        'career_history_evidence'=>[
            'contract_version'=>1,'scope'=>FormulaV18::SCOPE,'complete'=>true,'row_scope_verified'=>true,'aggregate_matches'=>true,'checked_through'=>'2026-11-04',
            'loading'=>['contract_version'=>1,'canonical_card_dom'=>true,'cards_count'=>4,'hidden_cards'=>0,'controls'=>[],'first_page_verified'=>true,'load_complete'=>true,'navigation_exhausted'=>true,'pagination_present'=>true,'all_pages_loaded'=>true,'loaded_pages'=>2,'filters_present'=>true,'filters_active'=>false],
        ],
    ]);
    $history=new CareerHistory();$store=new ProfileStore();
    $history->process(ProfileStore::career_profile($profile,$a['fighter_id']),0,1,true);
    $store->put($profile,'tapology_fighter_profiles_v0_1');
    (new StatsRebuildService())->rebuild_all();

    $runs=new RankingRunRepository();$calculator=new RankingCalculatorService('v1.8');
    $fighters=$runs->list_fighters_with_stats([$a['fighter_id']]);
    $run=$runs->create('v1.8',FormulaV18::config(),'2026-11-05','2026-11-05 10:00:00','draft','Synthetic whole-career fixture');
    $batch=$calculator->score_batch($fighters,'2026-11-05','2026-11-05 10:00:00',$run);
    $row=$batch['rows'][0]??null;$assessment=$batch['assessments'][$a['fighter_id']]??null;
    career_v18_check($row && $assessment['status']==='main','complete W/L/D/NC career produces a main v1.8 row');
    career_v18_check($row['sample_size']===3 && $assessment['career_stats']['pro_fights_count']===4,'sample size is career W+L while D/NC remain in career fights');
    career_v18_check(abs((float)$row['total_score']+0.5)<0.000001,'whole-career base, known finishes, reliable quality and age are each counted once');
    career_v18_check($assessment['detail_coverage']['unknown_records']===1 && $assessment['detail_coverage']['unknown_methods']===1,'unknown quality and method remain explicit neutral coverage warnings');
    career_v18_check(in_array('neutral_unknown_prefight_record',$assessment['warnings'],true) && in_array('neutral_unknown_method',$assessment['warnings'],true),'neutral detail gaps do not block main status');
    career_v18_check(!isset($assessment['historical_record']) && !isset($assessment['period_record']),'v1.8 assessment exposes no historical/period split');
    $frozen=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$s['assessments']} WHERE ranking_run_id=%d AND fighter_id=%d",$run,$a['fighter_id']),ARRAY_A);
    career_v18_check($frozen && $frozen['period_start']===null && $frozen['period_end']==='2026-11-05','whole-career assessment uses nullable period_start and freezes the reference date');
    $inputs=json_decode($frozen['inputs_json'],true,512,JSON_THROW_ON_ERROR);
    career_v18_check(count($inputs['accepted_career_bouts'])===4 && count($inputs['scoring_bouts'])===3 && strlen($assessment['input_hash'])===64,'assessment v2 freezes accepted bouts, scoring inputs and an input hash');
    $public=(new PublicAssessments())->for_fighters([$a['fighter_id']],$runs->find($run))[$a['fighter_id']]['scoring_assessment'];
    career_v18_check(isset($public['career_stats'],$public['detail_coverage'],$public['breakdown']) && !isset($public['input_hash']),'public DTO exposes frozen career metrics without private input hash');
    $methodology=PublicAssessments::methodology($runs->find($run));
    career_v18_check($methodology['scoring_scope']===FormulaV18::SCOPE && $methodology['score_label']==='Base points, known finishes and opponent quality across the whole professional MMA career.' && abs($methodology['points_per_finish']-1.0)<0.000001,'public methodology uses the exact whole-career label and the v1.8 finish value');

    (new RankingCurrentRepository())->insert_draft_snapshots($run,[$calculator->snapshot_row_for_item($row,'overall',1)]);$runs->update($run,['status'=>'completed']);
    $activated=(new RankingActivationService())->activate($run,1);
    career_v18_check($activated['formula_version']==='v1.8' && $activated['current_rows_written']===1,'independent frozen-input validation permits a valid manual v1.8 activation');
    $active_before=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d AND fighter_id=%d",$run,$a['fighter_id']),ARRAY_A);

    $quality_bout=(int)$inputs['scoring_bouts'][1]['bout_id'];
    $proof=['contract_version'=>1,'opponent'=>['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>0,'losses'=>0],'source'=>'fixture','observed_at'=>'2026-11-04T10:00:00Z','parser_version'=>'profile_dom_v4']];
    $wpdb->update($t['bout_participants'],['opponent_prefight_wins'=>0,'opponent_prefight_losses'=>0,'opponent_prefight_draws'=>0,'opponent_prefight_nc'=>0,'opponent_prefight_record_raw'=>'0-0-0','opponent_prefight_diff'=>0,'record_evidence_json'=>wp_json_encode($proof)],['bout_id'=>$quality_bout,'fighter_id'=>$a['fighter_id']]);
    $quality=$calculator->score_batch($fighters,'2026-11-05','2026-11-05 10:00:01');
    career_v18_check(abs((float)$quality['rows'][0]['total_score']-(float)$row['total_score']-1.0)<0.000001,'later reliable record changes only that bout quality in a new draft');
    $method_bout=(int)$inputs['scoring_bouts'][1]['bout_id'];$wpdb->update($t['bouts'],['method_category'=>'submission'],['id'=>$method_bout]);
    $method=$calculator->score_batch($fighters,'2026-11-05','2026-11-05 10:00:02');
    career_v18_check(abs((float)$method['rows'][0]['total_score']-(float)$quality['rows'][0]['total_score']-1.0)<0.000001,'later method completion changes only finish and derived coverage in a new draft');
    $active_after=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d AND fighter_id=%d",$run,$a['fighter_id']),ARRAY_A);
    career_v18_check($active_before===$active_after,'active frozen run remains byte-for-byte unchanged after later enrichment');

    $tampered=$inputs;$tampered['scoring_bouts'][0]['opponent_prefight_wins']=999;
    try{CareerAssessment::validate_frozen(json_decode($frozen['assessment_json'],true),$tampered,$active_before,$runs->find($run));$rejected=false;}catch(Throwable $e){$rejected=true;}
    career_v18_check($rejected,'activation validator rejects tampered frozen input');

    // v1.9: same frozen career inputs, 0.25 per finish, loss limit below five wins.
    $calculator19=new RankingCalculatorService('v1.9');
    $run19=$runs->create('v1.9',FormulaV19::config(),'2026-11-05','2026-11-05 11:00:00','draft','Synthetic whole-career v1.9 fixture');
    $batch19=$calculator19->score_batch($fighters,'2026-11-05','2026-11-05 11:00:00',$run19);
    $row19=$batch19['rows'][0]??null;$assessment19=$batch19['assessments'][$a['fighter_id']]??null;
    career_v18_check($row19 && $assessment19['status']==='main' && $runs->find($run19)['data_scope_version']===FormulaV18::SCOPE,'a 2–1 career is still a main v1.9 row with the shared whole-career scope');
    $same18=$calculator->score_batch($fighters,'2026-11-05','2026-11-05 11:00:01');
    $finish19=(float)$assessment19['breakdown']['finishes_points'];$finish18=(float)$same18['assessments'][$a['fighter_id']]['breakdown']['finishes_points'];
    career_v18_check($finish18>=2.0 && abs($finish19-$finish18*0.25)<0.000001,'v1.9 counts each known finish as a quarter of the v1.8 finish points');
    career_v18_check(abs((float)$row19['total_score']-((float)$same18['rows'][0]['total_score']-$finish18*0.75))<0.000001,'only the finish component differs between v1.8 and v1.9 on the same inputs');
    (new RankingCurrentRepository())->insert_draft_snapshots($run19,[$calculator19->snapshot_row_for_item($row19,'overall',1)]);$runs->update($run19,['status'=>'completed']);
    $activated19=(new RankingActivationService())->activate($run19,1);
    career_v18_check($activated19['formula_version']==='v1.9' && $activated19['current_rows_written']===1,'independent frozen-input validation permits a valid manual v1.9 activation');
    $methodology19=PublicAssessments::methodology($runs->find($run19));
    career_v18_check($methodology19['scoring_scope']===FormulaV18::SCOPE && abs($methodology19['points_per_finish']-0.25)<0.000001,'public v1.9 methodology reports 0.25 points per finish');
    $frozen19=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$s['assessments']} WHERE ranking_run_id=%d AND fighter_id=%d",$run19,$a['fighter_id']),ARRAY_A);
    $snapshot19=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d AND fighter_id=%d",$run19,$a['fighter_id']),ARRAY_A);
    try{CareerAssessment::validate_frozen(json_decode($frozen19['assessment_json'],true),json_decode($frozen19['inputs_json'],true),$snapshot19,$runs->find($run));$cross=false;}catch(Throwable $e){$cross=true;}
    career_v18_check($cross,'a v1.9 assessment is never accepted as a v1.8 run');

    $c=fixture_fighter(97981103,'Career V19 Two Losses',$flags);
    $loss_rows=[
        fixture_fight(97981511,$b,'win',['fight_date'=>'2026-03-01','method_category'=>'decision','prefight_record_raw'=>'0-0-0','opponent_record_raw'=>'0-0-0']),
        fixture_fight(97981512,$b,'loss',['fight_date'=>'2026-06-01','method_category'=>'decision','prefight_record_raw'=>'1-0-0','opponent_record_raw'=>'0-0-0']),
        fixture_fight(97981513,$b,'loss',['fight_date'=>'2026-09-01','method_category'=>'decision','prefight_record_raw'=>'1-1-0','opponent_record_raw'=>'0-0-0']),
    ];
    foreach($loss_rows as &$row){$row['record_evidence']=['fighter'=>['status'=>'unknown','reason'=>'prefight_semantics_unproven'],'opponent'=>['status'=>'unknown','reason'=>'prefight_semantics_unproven']];}unset($row);
    $loss_profile=fixture_profile($c,array_reverse($loss_rows),['wins'=>1,'losses'=>2,'draws'=>0,'no_contests'=>0],['scraped_at'=>'2026-11-04T10:00:00Z','parser_version'=>'profile_dom_v4','career_history_evidence'=>array_replace_recursive($profile['career_history_evidence'],['loading'=>['cards_count'=>3]])]);
    $history->process(ProfileStore::career_profile($loss_profile,$c['fighter_id']),0,1,true);$store->put($loss_profile,'tapology_fighter_profiles_v0_1');
    (new StatsRebuildService())->rebuild_all();
    $two=$runs->list_fighters_with_stats([$c['fighter_id']]);
    $blocked19=$calculator19->score_batch($two,'2026-11-05','2026-11-05 11:00:02');$allowed18=$calculator->score_batch($two,'2026-11-05','2026-11-05 11:00:03');
    career_v18_check(empty($blocked19['rows']) && in_array('ineligible_loss_limit',$blocked19['assessments'][$c['fighter_id']]['reasons']??[],true),'v1.9 excludes a 1–2 career by the loss limit below five wins');
    career_v18_check(!in_array('ineligible_loss_limit',$allowed18['assessments'][$c['fighter_id']]['reasons']??[],true),'v1.8 keeps its original loss table for reproducibility');
}finally{
    Transaction::rollback();WriteGuard::$worker=false;
}
career_v18_check($live===hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A))),'synthetic v1.8 checks roll back without changing the active ranking');
