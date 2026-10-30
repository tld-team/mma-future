<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';

use MMAF\DataEngine\Services\Scoring\Schema as ScoringSchema;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\RegularImport\{CareerHistory,Transaction,WriteGuard};
use MMAF\DataEngine\Services\{RankingActivationService,RankingCalculatorService,StatsRebuildService};
use MMAF\DataEngine\Repositories\{RankingCurrentRepository,RankingRunRepository};

function check_hybrid($ok,string $why): void {if(!$ok){throw new RuntimeException($why);}echo "PASS: $why\n";}

wp_set_current_user(1);\MMAF\DataEngine\Migrations\MigrationRunner::run();
$t=\MMAF\DataEngine\Migrations\Schema::table_names();$s=ScoringSchema::tables();
$live=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)));
WriteGuard::$worker=true;Transaction::begin();
try{
    $flags=['is_public'=>1,'is_rankable'=>1,'rankability_status'=>'rankable','status'=>'verified'];
    $a=fixture_fighter(95981101,'Hybrid A',$flags);$b=fixture_fighter(95981102,'Hybrid B',$flags);
    $raw=json_decode(file_get_contents(__DIR__.'/../fixtures/scoring-period/profile.json'),true,512,JSON_THROW_ON_ERROR);
    $raw['source_fighter_id']=$a['source_fighter_id'];$raw['source_url']=$a['source_url'];$raw['display_name']=$a['display_name'];
    foreach($raw['fight_history'] as $n=>&$fight){
        $fight['source_bout_id']='tapology_bout_9598150'.($n+1);$fight['bout_url']='https://www.tapology.com/fightcenter/bouts/9598150'.($n+1).'-hybrid';
        $fight['source_event_id']='tapology_event_9598140'.($n+1);$fight['event_url']='https://www.tapology.com/fightcenter/events/9598140'.($n+1).'-hybrid';
        $fight['opponent_source_id']=$b['source_fighter_id'];$fight['opponent_url']=$b['source_url'];$fight['opponent_name']=$b['display_name'];
    }unset($fight);
    $history=new CareerHistory();$profiles=new ProfileStore();
    $history->process(ProfileStore::career_profile($raw,$a['fighter_id']),0,1,true);$profiles->put($raw,'tapology_fighter_profiles_v0_1');
    (new StatsRebuildService())->rebuild_all();

    // Exact opponent pre-fight records are frozen canonical bout inputs. They are
    // synthetic here and deliberately not inferred from today's opponent record.
    $recent_bouts=$wpdb->get_col($wpdb->prepare("SELECT p.bout_id FROM {$t['bout_participants']} p JOIN {$t['bouts']} b ON b.id=p.bout_id JOIN {$t['events']} e ON e.id=b.event_id WHERE p.fighter_id=%d AND e.event_date>='2026-05-09'",$a['fighter_id']));
    foreach($recent_bouts as $bout){$wpdb->update($t['bout_participants'],['opponent_prefight_wins'=>5,'opponent_prefight_losses'=>5,'opponent_prefight_draws'=>0,'opponent_prefight_nc'=>0,'opponent_prefight_record_raw'=>'5-5-0','opponent_prefight_diff'=>0],['bout_id'=>(int)$bout,'fighter_id'=>$a['fighter_id']]);}

    $runs=new RankingRunRepository();$calculator=new RankingCalculatorService('v1.7');$fighters=$runs->list_fighters_with_stats([$a['fighter_id']]);
    $run=$runs->create('v1.7',\MMAF\DataEngine\Services\Formula\FormulaV17::config(),'2026-10-14','2026-10-14 12:00:00','draft','Synthetic hybrid fixture');
    $batch=$calculator->score_batch($fighters,'2026-10-14','2026-10-14 12:00:00',$run);$row=$batch['rows'][0]??null;$assessment=$batch['assessments'][$a['fighter_id']];
    check_hybrid($row && $assessment['status']==='main' && $row['sample_size']===2,'complete recent evidence produces a main v1.7 row');
    check_hybrid($assessment['historical_record']['wins']===8 && $assessment['historical_record']['losses']===3 && $assessment['period_record']['wins']===2,'career minus every accepted recent result produces the historical split');
    check_hybrid(abs((float)$row['total_score']-2.5)<0.000001 && (float)$row['breakdown']['career_base_points']===0.5 && (float)$row['breakdown']['opponent_differential_points']===2.0,'v1.7 score uses full-career base plus recent opponent quality exactly once');
    $saved=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$s['assessments']} WHERE ranking_run_id=%d AND fighter_id=%d",$run,$a['fighter_id']),ARRAY_A);$frozen=json_decode($saved['inputs_json'],true,512,JSON_THROW_ON_ERROR);
    check_hybrid($frozen['historical_record']===$assessment['historical_record'] && count($frozen['accepted_period_bouts'])===2 && strlen($assessment['input_hash'])===64,'assessment freezes the accepted career split, exact recent scoring rows and input hash');
    check_hybrid((float)$row['total_score']===(float)$row['raw_score'],'v1.7 main total and raw scores use the same direct sum');
    (new RankingCurrentRepository())->insert_draft_snapshots($run,[$calculator->snapshot_row_for_item($row,'overall',1)]);
    $runs->update($run,['status'=>'completed']);
    $activated=(new RankingActivationService())->activate($run,1);
    check_hybrid($activated['formula_version']==='v1.7' && $activated['current_rows_written']===1,'a complete zero-normalization v1.7 snapshot passes manual activation validation');

    $first=(int)$recent_bouts[0];$wpdb->update($t['bouts'],['method_category'=>'unknown'],['id'=>$first]);
    $unknown=$calculator->score_batch($runs->list_fighters_with_stats([$a['fighter_id']]),'2026-10-14','2026-10-14 12:00:00')['assessments'][$a['fighter_id']];
    check_hybrid($unknown['status']==='provisional' && $unknown['total_score']===null && is_numeric($unknown['known_points']) && in_array('missing_recent_win_method',$unknown['reasons'],true),'unknown recent-win method exposes only a temporary sum');
    $wpdb->update($t['bouts'],['method_category'=>'decision'],['id'=>$first]);

    $wpdb->update($t['bout_participants'],['opponent_prefight_wins'=>null,'opponent_prefight_losses'=>null,'opponent_prefight_record_raw'=>null,'opponent_prefight_diff'=>null],['bout_id'=>$first,'fighter_id'=>$a['fighter_id']]);
    $missing=$calculator->score_batch($runs->list_fighters_with_stats([$a['fighter_id']]),'2026-10-14','2026-10-14 12:00:00')['assessments'][$a['fighter_id']];
    check_hybrid($missing['status']==='provisional' && in_array('missing_recent_prefight_record',$missing['reasons'],true),'missing recent opponent pre-fight record blocks a final score');
    $wpdb->update($t['bout_participants'],['opponent_prefight_wins'=>5,'opponent_prefight_losses'=>5,'opponent_prefight_record_raw'=>'5-5-0','opponent_prefight_diff'=>0],['bout_id'=>$first,'fighter_id'=>$a['fighter_id']]);

    $wpdb->update($t['bouts'],['is_scoring_candidate'=>0],['id'=>$first]);
    $mismatch=$calculator->score_batch($runs->list_fighters_with_stats([$a['fighter_id']]),'2026-10-14','2026-10-14 12:00:00')['assessments'][$a['fighter_id']];
    check_hybrid($mismatch['status']==='provisional' && in_array('period_scoring_mismatch',$mismatch['reasons'],true),'a scoring flag cannot silently remove an accepted recent W/L result');
    $wpdb->update($t['bouts'],['is_scoring_candidate'=>1],['id'=>$first]);

    $c=fixture_fighter(95981103,'Hybrid Historical Only',$flags);$old=fixture_fight(95981504,$b,'win',['fight_date'=>'2026-05-08']);
    $oldProfile=fixture_profile($c,[$old],['wins'=>10,'losses'=>0,'draws'=>0,'no_contests'=>0],[
		'scraped_at'=>'2026-10-10T10:00:00Z',
        'history_exhausted'=>false,
        'career_record_evidence'=>['scope'=>'professional_mma','verified'=>true,'label'=>'Pro MMA Record','record_raw'=>'10-0-0','evidence'=>'explicit_mma_record_label','context'=>null,'record_context_verified'=>false],
        'period_evidence'=>['start_date'=>'2026-05-09','checked_through'=>'2026-10-10','scope_verified'=>true,'first_page_verified'=>true,'load_complete'=>true,'chronological_descending'=>true,'boundary_crossed'=>true,'history_exhausted'=>false,'parser_version'=>'scoring_period_v1'],
    ]);
    $oldProfile['record_summary']['pro_record_raw']='10-0-0';
    $history->process(ProfileStore::career_profile($oldProfile,$c['fighter_id']),0,1,true);$profiles->put($oldProfile,'tapology_fighter_profiles_v0_1');(new StatsRebuildService())->rebuild_all();
    $oldOnly=$calculator->score_batch($runs->list_fighters_with_stats([$c['fighter_id']]),'2026-10-14','2026-10-14 12:00:00');$oldRow=$oldOnly['rows'][0]??null;$oldAssessment=$oldOnly['assessments'][$c['fighter_id']];
    check_hybrid($oldRow && $oldRow['sample_size']===0 && abs((float)$oldRow['total_score']-5.0)<0.000001 && $oldAssessment['no_recent_fights'],'confirmed historical-only fighter ranks with zero recent sample and one age adjustment');

	$d=fixture_fighter(95981104,'Hybrid Boundary',$flags);
	$boundaryProfile=fixture_profile($d,[fixture_fight(95981505,$b,'win',['fight_date'=>'2026-05-09']),fixture_fight(95981506,$b,'win',['fight_date'=>'2026-05-08'])],['wins'=>2,'losses'=>0,'draws'=>0,'no_contests'=>0],[
		'scraped_at'=>'2026-10-10T10:00:00Z',
		'history_exhausted'=>false,
		'period_evidence'=>['start_date'=>'2026-05-09','checked_through'=>'2026-10-10','scope_verified'=>true,'first_page_verified'=>true,'load_complete'=>true,'chronological_descending'=>true,'boundary_crossed'=>true,'history_exhausted'=>false,'parser_version'=>'scoring_period_v1'],
	]);
	$history->process(ProfileStore::career_profile($boundaryProfile,$d['fighter_id']),0,1,true);$profiles->put($boundaryProfile,'tapology_fighter_profiles_v0_1');(new StatsRebuildService())->rebuild_all();
	$boundary=$calculator->score_batch($runs->list_fighters_with_stats([$d['fighter_id']]),'2026-10-14','2026-10-14 12:00:00');$boundaryRow=$boundary['rows'][0]??null;$boundaryAssessment=$boundary['assessments'][$d['fighter_id']];
	check_hybrid($boundaryRow && $boundaryAssessment['historical_record']['wins']===1 && $boundaryAssessment['period_record']['wins']===1 && abs((float)$boundaryRow['total_score']-2.0)<0.000001,'8 May is historical, 9 May is recent, and import time does not affect the boundary');
	$oldBoundaryBout=(int)$wpdb->get_var("SELECT bout_id FROM {$t['bout_sources']} WHERE source_bout_id='tapology_bout_95981506'");$wpdb->update($t['bouts'],['method_category'=>'ko_tko'],['id'=>$oldBoundaryBout]);
	$oldDetailChanged=$calculator->score_batch($runs->list_fighters_with_stats([$d['fighter_id']]),'2026-10-14','2026-10-14 12:00:00')['rows'][0]??null;
	check_hybrid($oldDetailChanged && (float)$oldDetailChanged['total_score']===(float)$boundaryRow['total_score'],'discovering an old finish does not change v1.7 points when historical W/L is unchanged');

	$e=fixture_fighter(95981105,'Hybrid Unknown Loss Method',$flags);
	$lossProfile=fixture_profile($e,[fixture_fight(95981507,$b,'loss',['method_category'=>'unknown'])],['wins'=>0,'losses'=>1,'draws'=>0,'no_contests'=>0],[
		'scraped_at'=>'2026-10-10T10:00:00Z',
		'period_evidence'=>['start_date'=>'2026-05-09','checked_through'=>'2026-10-10','scope_verified'=>true,'first_page_verified'=>true,'load_complete'=>true,'chronological_descending'=>true,'boundary_crossed'=>false,'history_exhausted'=>true,'parser_version'=>'scoring_period_v1'],
	]);
	$history->process(ProfileStore::career_profile($lossProfile,$e['fighter_id']),0,1,true);$profiles->put($lossProfile,'tapology_fighter_profiles_v0_1');(new StatsRebuildService())->rebuild_all();
	$unknownLoss=$calculator->score_batch($runs->list_fighters_with_stats([$e['fighter_id']]),'2026-10-14','2026-10-14 12:00:00');$unknownLossAssessment=$unknownLoss['assessments'][$e['fighter_id']];
	check_hybrid(($unknownLoss['rows'][0]??null) && $unknownLossAssessment['status']==='main' && !in_array('missing_recent_win_method',$unknownLossAssessment['reasons'],true),'an unknown recent-loss method does not block the final hybrid score');
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
check_hybrid($live===hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A))),'hybrid fixtures roll back without changing the active ranking');
