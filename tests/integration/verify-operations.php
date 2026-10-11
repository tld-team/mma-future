<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';
use MMAF\DataEngine\Services\Operations\{Jobs,Calculation,Readiness,Schema as Operations};
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard};
use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\{FighterRepository,RankingRunRepository};
use MMAF\DataEngine\Services\{StatsRebuildService,RankingCalculatorService};
function assert_operation($ok,$message){if(!$ok){throw new RuntimeException($message);}echo "PASS: $message\n";}
function drive_calculation($id){for($n=0;$n<10000;$n++){$r=Calculation::step($id);if($r['status']==='completed'){return $r;}if($r['status']==='failed' || !empty($r['error_message'])){throw new RuntimeException($r['error_message']??'Calculation failed');}}throw new RuntimeException('Calculation did not finish.');}
function stats_values($table){global $wpdb;return $wpdb->get_results("SELECT fighter_id,wins,losses,draws,nc,pro_fights_count,ko_tko_wins,submission_wins,decision_wins,finish_wins,finish_rate,last_fight_date,streak,recent_form,activity_status,warnings_json FROM $table ORDER BY fighter_id",ARRAY_A);}
function rank_values($table,$run){global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT board_key,fighter_id,rank_position,total_score,raw_score,sample_size,quality_flags_json,breakdown_json,eligibility_json,warnings_json FROM $table WHERE ranking_run_id=%d ORDER BY board_key,rank_position",$run),ARRAY_A);}
set_exception_handler(static function($e){fwrite(STDERR,$e->getMessage().' '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
\MMAF\DataEngine\Migrations\MigrationRunner::run();wp_set_current_user(1);$t=Schema::table_names();$o=Operations::tables();
$active=(new RankingRunRepository())->get_active_ranking_run_id();$liveHash=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)));
Transaction::begin();WriteGuard::$worker=true;
try{
    fixture_canonical_baseline();
    $f=new FighterRepository();$ids=[];for($i=0;$i<3;++$i){$ids[]=$f->insert(['display_name'=>'Operations frozen fixture '.$i,'normalized_name'=>'operations frozen fixture '.$i,'status'=>'provisional','is_public'=>0,'is_rankable'=>0,'created_at'=>gmdate('Y-m-d H:i:s')]);}
    $selection=['mode'=>'filtered','search'=>'Operations frozen fixture'];$job=Jobs::create('validate_selected_for_ranking',$selection,1,'operations-freeze-fixture');
    $later=$f->insert(['display_name'=>'Operations frozen fixture later','normalized_name'=>'operations frozen fixture later','created_at'=>gmdate('Y-m-d H:i:s')]);
    assert_operation((int)$job['total']===3,'complete filter selection is frozen before a later fighter is added');
    assert_operation((int)Jobs::create('validate_selected_for_ranking',$selection,1,'operations-freeze-fixture')['id']===(int)$job['id'],'request replay reuses the same frozen operation');
    Jobs::work();$job=Jobs::get((int)$job['id']);assert_operation($job['status']==='ready' && strlen($job['manifest_hash'])===64,'background preview completes a confirmable hashed manifest');
    try{Jobs::control((int)$job['id'],'start',1,'wrong');throw new RuntimeException('Bad manifest accepted');}catch(RuntimeException $e){assert_operation($e->getMessage()!=='Bad manifest accepted','incorrect manifest cannot start an operation');}
    Jobs::control((int)$job['id'],'start',1,$job['manifest_hash']);Jobs::control((int)$job['id'],'pause',1);assert_operation(Jobs::get((int)$job['id'])['status']==='paused','pause preserves a confirmed manifest');Jobs::control((int)$job['id'],'resume',1);
    $f->update($ids[0],['display_name'=>'Changed after preview']);Jobs::work();
    $results=Jobs::items((int)$job['id']);assert_operation(($results['counts']['review']??0)>=1,'changed input is retained for review instead of silently accepting an old preview');
    assert_operation(!in_array($later,array_map('intval',array_column($results['items'],'fighter_id')),true),'later matching candidates never enter the confirmed operation');
    $baseline=(new StatsRebuildService())->rebuild_all();$expected=stats_values($t['fighter_stats_current']);
    $calculation=Calculation::create('stats',0,1,'operation-stats-parity');$first=Calculation::step($calculation,25);
    assert_operation(stats_values($t['fighter_stats_current'])===$expected,'an incomplete generation leaves confirmed public statistics intact');
    $result=drive_calculation($calculation);assert_operation(stats_values($t['fighter_stats_current'])===$expected,'resumable published statistics match the established reducer exactly');
    foreach(['countable_bouts','skipped_bouts','malformed_bouts','participants_processed','warnings_count','warnings'] as $key){if($key==='warnings'){ksort($baseline[$key]);ksort($result['summary'][$key]);}assert_operation($baseline[$key]===$result['summary'][$key],'statistics diagnostic parity: '.$key);}
    $legacy=(new RankingCalculatorService())->calculate_draft(1,current_time('Y-m-d'));
    $rank=drive_calculation(Calculation::create('ranking',0,1,'operation-ranking-parity'));
    assert_operation(rank_values($t['ranking_snapshots'],$legacy['ranking_run_id'])===rank_values($t['ranking_snapshots'],$rank['summary']['ranking_run_id']),'SQL sorting and resumed snapshots preserve v1.5 ranks, scores, tie breaks and breakdowns');
    assert_operation((new RankingRunRepository())->get_active_ranking_run_id()===$active && hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)))===$liveHash,'operations and new drafts leave the active ranking unchanged');
    $before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}");drive_calculation($rank['id']);assert_operation((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}")===$before,'resuming a completed calculation is a no-op');
    $sources=new \MMAF\DataEngine\Repositories\FighterSourceRepository();$store=new \MMAF\DataEngine\Services\DataPipeline\ProfileStore();
    $target=$f->insert(['display_name'=>'Prepared before publication','gender'=>'male','date_of_birth'=>'2001-01-01','weight_class'=>'lightweight','status'=>'provisional','created_at'=>gmdate('Y-m-d H:i:s')]);
    $opponent=$f->insert(['display_name'=>'Private preparation opponent','gender'=>'male','status'=>'provisional','created_at'=>gmdate('Y-m-d H:i:s')]);
    foreach([$target=>949811001,$opponent=>949811002] as $fid=>$source){$url='https://www.tapology.com/fightcenter/fighters/'.$source.'-operations';$sources->upsert_for_fighter($fid,['source_type'=>'tapology','source_fighter_id'=>'tapology_fighter_'.$source,'source_url'=>$url,'identity_hash'=>hash('sha256',$url),'is_primary'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);}
    $fight=['source_bout_id'=>'tapology_bout_949811101','bout_url'=>'https://www.tapology.com/fightcenter/bouts/949811101-operations','source_event_id'=>'tapology_event_949811201','event_url'=>'https://www.tapology.com/fightcenter/events/949811201-operations','event_name'=>'Operations fixture event','fight_date'=>'2026-10-01','opponent_source_id'=>'tapology_fighter_949811002','opponent_url'=>'https://www.tapology.com/fightcenter/fighters/949811002-operations','opponent_name'=>'Private preparation opponent','discipline'=>'mma','source_sport'=>'mma','source_division'=>'pro','classification_evidence'=>'profile_dom_classification','is_professional'=>true,'result'=>'win','method_category'=>'decision','opponent_record_raw'=>'1-0'];
    $profile=['source_fighter_id'=>'tapology_fighter_949811001','source_url'=>'https://www.tapology.com/fightcenter/fighters/949811001-operations','display_name'=>'Prepared before publication','status'=>'success','scraped_at'=>'2026-10-10T10:00:00Z','record_summary'=>['wins'=>2,'losses'=>0,'draws'=>0,'no_contests'=>0],'history_scope'=>'professional_mma','history_scope_verified'=>true,'history_exhausted'=>true,'fight_history'=>[$fight]];
    $store->put($profile,'tapology_fighter_profiles_v0_1');
    $approved=Jobs::create('approve_and_prepare',['ids'=>[$target]],1,'approval-before-publication');Jobs::work();$approved=Jobs::get((int)$approved['id']);Jobs::control((int)$approved['id'],'start',1,$approved['manifest_hash']);Jobs::work();
    assert_operation(!(int)$f->find($target)['is_public'] && !(int)$f->find($target)['is_rankable'],'career preparation leaves an unpublished candidate private until statistics and validation finish');
    for($n=0;$n<1000;++$n){Jobs::work();$approved=Jobs::get((int)$approved['id']);if($approved['status']==='completed'){break;}if($approved['status']==='failed'){throw new RuntimeException($approved['error_message']);}}
    assert_operation($approved['status']==='completed' && (int)$f->find($target)['is_public']===1 && (int)$f->find($target)['is_rankable']===1,'approved stored JSON is prepared and validated before publication');
    assert_operation(get_post_status((int)$f->find($target)['wp_post_id'])==='publish','publication synchronizes the canonical WordPress profile');
    $coverage=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighter_career_coverage']} WHERE fighter_id=%d",$target),ARRAY_A);
    assert_operation($coverage['status']==='partial','documented partial history can be published without becoming complete');
    assert_operation(!(int)$f->find($opponent)['is_public'],'linked opponents remain private');
    assert_operation(!empty($approved['summary']['ranking_run_id']),'one inactive ranking draft completes the frozen preparation scope');
    $countBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}");
    $repeat=Jobs::create('approve_and_prepare',['ids'=>[$target]],1,'repeat-same-approval');Jobs::work();$repeat=Jobs::get((int)$repeat['id']);Jobs::control((int)$repeat['id'],'start',1,$repeat['manifest_hash']);Jobs::work();$repeat=Jobs::get((int)$repeat['id']);
    assert_operation($repeat['status']==='completed' && (Jobs::items((int)$repeat['id'])['counts']['noop']??0)===1 && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}")===$countBefore,'repeated unchanged approval does not reimport or create an extra draft');
    $forbidden=false;\MMAF\DataEngine\Services\Operations\Jobs::$current=(int)$repeat['id'];
    try{(new \MMAF\DataEngine\Services\FighterReadinessService())->process_bulk_action('approve_and_prepare',[$opponent],1,true);}catch(RuntimeException $e){$forbidden=true;}finally{\MMAF\DataEngine\Services\Operations\Jobs::$current=0;}
    assert_operation($forbidden,'publication authority is tied to the confirmed prepared item');
    wp_cache_flush();
    assert_operation(get_option('mmaf_db_version')===MMAF_DB_VERSION,'query revision tracking tolerates an uncached WordPress option');
    $revision=\MMAF\DataEngine\Services\Operations\Revision::current();
    Transaction::begin();$f->update($target,['nickname'=>'Rolled-back revision']);Transaction::rollback();
    assert_operation(\MMAF\DataEngine\Services\Operations\Revision::current()===$revision,'canonical input revision rolls back with a failed unit');
    $cancel=Jobs::create('validate_selected_for_ranking',['ids'=>[$target,$opponent]],1,'cancel-unit');Jobs::work();$cancel=Jobs::get((int)$cancel['id']);Jobs::control((int)$cancel['id'],'start',1,$cancel['manifest_hash']);Jobs::control((int)$cancel['id'],'cancel',1);Jobs::work();
    assert_operation(Jobs::get((int)$cancel['id'])['status']==='cancelled','cancel leaves confirmed changes and prevents future processing');
    for($n=0;$n<271;++$n){$f->insert(['display_name'=>'Manifest paging fixture '.$n,'normalized_name'=>'manifest paging fixture '.$n,'status'=>'provisional','created_at'=>gmdate('Y-m-d H:i:s')]);}
    $paged=Jobs::create('validate_selected_for_ranking',['mode'=>'filtered','search'=>'Manifest paging fixture'],1,'paged-manifest');
    for($n=0;$n<40;++$n){Jobs::work(1);$paged=Jobs::get((int)$paged['id']);if($paged['phase']==='preview_manifest'){break;}}
    assert_operation($paged['status']==='previewing' && (int)$paged['checkpoint']['manifest_cursor']>0,'large preview saves an incomplete manifest digest instead of scanning the whole selection');
    $checkpoint=$paged['checkpoint'];Jobs::control((int)$paged['id'],'pause',1);Jobs::work(1);
    assert_operation(Jobs::get((int)$paged['id'])['checkpoint']['manifest_cursor']===$checkpoint['manifest_cursor'],'paused preview retains its exact digest checkpoint');
    Jobs::control((int)$paged['id'],'resume',1);
    for($n=0;$n<40;++$n){Jobs::work(1);$paged=Jobs::get((int)$paged['id']);if($paged['status']==='ready'){break;}}
    assert_operation($paged['status']==='ready' && (int)$paged['total']===271 && (int)$paged['summary']['review']===271 && count(Jobs::items((int)$paged['id'],2)['items'])===25,'resumed preview summarizes all pages with paginated per-fighter results');
    Jobs::control((int)$paged['id'],'cancel',1);
    echo 'METRICS '.wp_json_encode(['statistics_ms'=>$result['duration_ms'],'statistics_peak'=>$result['peak_memory'],'ranking_ms'=>$rank['duration_ms'],'ranking_peak'=>$rank['peak_memory']])."\n";
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
