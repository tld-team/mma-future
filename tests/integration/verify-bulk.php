<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/synthetic-fixtures.php';
use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\{FighterRepository,FighterSourceRepository,RankingRunRepository};
use MMAF\DataEngine\Services\FighterReadinessService;
use MMAF\DataEngine\Services\DataPipeline\Processor;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\Operations\Jobs;
use MMAF\DataEngine\Services\Operations\Schema as Operations;
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard};
function bulk_check($ok,$label) { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
set_exception_handler(static function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
\MMAF\DataEngine\Migrations\MigrationRunner::run();
wp_set_current_user(1);
add_filter('pre_http_request',static function(){throw new RuntimeException('Approval attempted a source fetch.');});
$tables=Schema::table_names();$fighters=new FighterRepository();$sources=new FighterSourceRepository();$service=new FighterReadinessService();
$active=(new RankingRunRepository())->get_active_ranking_run_id();$pending=Processor::pending();
$posts=[];WriteGuard::$worker=true;Transaction::begin();
try {
    $ids=[];$fixtures=[];$store=new ProfileStore();
    $opponent=fixture_fighter(948770009,'Bulk private opponent');
    foreach (range(1,4) as $n) {
        $fixture=fixture_fighter(948770000+$n,'Bulk approval fixture '.$n,['date_of_birth'=>$n===3?null:'2000-01-01','status'=>$n===4?'hidden':'provisional']);
        $id=$fixture['fighter_id'];$fixtures[]=$fixture;
        $ids[]=$id;
        $rows=[];for($i=1;$i<=3;++$i){$rows[]=fixture_fight(948771000+$n*10+$i,$opponent);}
        $store->put(fixture_profile($fixture,$rows,['wins'=>3,'losses'=>0,'draws'=>0,'no_contests'=>0]),'tapology_fighter_profiles_v0_1');
        $wpdb->insert($tables['fighter_stats_current'],['fighter_id'=>$id,'wins'=>3,'losses'=>0,'draws'=>0,'nc'=>0,'pro_fights_count'=>3,'last_fight_date'=>'2026-10-05','calculated_at'=>gmdate('Y-m-d H:i:s')]);
        $wpdb->update($tables['fighters'],['created_at'=>'2026-10-0'.$n.' 00:00:00','updated_at'=>'2026-10-0'.(5-$n).' 00:00:00'],['id'=>$id]);
    }
    $listed=$fighters->list('Bulk approval fixture',100,0,['status'=>'provisional','public_state'=>'not_public','readiness_issue'=>'provisional_tapology','sort'=>'newest']);
    bulk_check(array_map('intval',array_column($listed,'id'))===[$ids[2],$ids[1],$ids[0]],'new candidate view excludes hidden fighters and orders by import creation rather than later updates');
    $selected=array_merge($ids,[$ids[0],999999999]);
    $preview=$service->process_bulk_action('validate_selected_for_ranking',$selected,1);
    bulk_check($preview['selected_count']===5 && $preview['ready_count']===2 && $preview['blocked_count']===3 && Processor::pending()===$pending,'read-only preview forecasts combined approval, reports unknown IDs and makes no changes');
    $runsBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$tables['ranking_runs']}");
    $result=$service->process_bulk_action('approve_and_prepare',$ids,1);
    bulk_check($result['updated_count']===0 && !empty($result['operation_id']) && Processor::pending()===$pending,'approval freezes a background operation without publishing or using the legacy queue');
    fixture_confirm_job($result['operation_id']);Jobs::work(1);
    bulk_check(!(int)$fighters->find($ids[0])['is_public'] && !(int)$fighters->find($ids[0])['is_rankable'],'prepared candidate stays private before statistics and final validation');
    $operation=fixture_drive_job($result['operation_id']);$counts=Jobs::items($result['operation_id'])['counts'];
    bulk_check(($counts['completed']??0)===2 && ($counts['review']??0)===2,'mixed operation publishes ready candidates and retains missing birth date and hidden identity for review');
    foreach(array_slice($ids,0,2) as $id) {
        $fighter=$fighters->find($id);$posts[]=(int)$fighter['wp_post_id'];
        bulk_check($fighter['status']==='verified' && (int)$fighter['is_public']===1 && (int)$fighter['is_rankable']===1 && $fighter['rankability_status']==='rankable' && get_post_status($fighter['wp_post_id'])==='publish','combined decision publishes one canonical profile with all approved flags');
    }
    bulk_check(!(int)$fighters->find($ids[2])['is_public'] && !(int)$fighters->find($ids[3])['is_public'] && !(int)$fighters->find($opponent['fighter_id'])['is_public'],'blocked candidates and linked opponents retain their private status');
    bulk_check(Processor::pending()===$pending && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tables['ranking_runs']}")===$runsBefore+1 && !empty($operation['summary']['ranking_run_id']) && (new RankingRunRepository())->get_active_ranking_run_id()===$active,'one finished scope creates one inactive draft and leaves the active ranking unchanged');
    $repeat=$service->process_bulk_action('approve_and_prepare',$ids,1);fixture_confirm_job($repeat['operation_id']);fixture_drive_job($repeat['operation_id']);$counts=Jobs::items($repeat['operation_id'])['counts'];
    bulk_check(($counts['noop']??0)===2 && ($counts['review']??0)===2 && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tables['ranking_runs']}")===$runsBefore+1,'repeated mixed approval is a no-op for prepared candidates and creates no additional draft');
    $fighters->update($ids[2],['date_of_birth'=>'2000-01-01']);
    $resolved=$service->process_bulk_action('approve_and_prepare',[$ids[2]],1);fixture_confirm_job($resolved['operation_id']);fixture_drive_job($resolved['operation_id']);
    $posts[]=(int)$fighters->find($ids[2])['wp_post_id'];
    bulk_check((int)$fighters->find($ids[2])['is_public']===1 && (int)$fighters->find($ids[2])['is_rankable']===1,'fixing a reviewed candidate invalidates the previous result and permits preparation and publication');
    $long=fixture_fighter(948770010,'Bulk checkpoint candidate',['status'=>'verified']);$rows=[];
    for($n=0;$n<64;++$n){$rows[]=fixture_fight(948772000+$n,$opponent);}
    $store->put(fixture_profile($long,$rows,['wins'=>64,'losses'=>0,'draws'=>0,'no_contests'=>0]),'tapology_fighter_profiles_v0_1');
    $longJob=Jobs::create('mark_public',['ids'=>[$long['fighter_id']]],1,'bulk-checkpoint-retry');fixture_confirm_job($longJob['id']);Jobs::work(1);
    $checkpoint=Jobs::items($longJob['id'])['items'][0];
    bulk_check($checkpoint['status']==='preparing' && $checkpoint['result']['checkpoint']['offset']===50,'large approval saves its first career chunk while retaining a private profile');
    $stop=static function($fid){throw new RuntimeException('Synthetic checkpoint interruption');};add_action('mmaf_profile_preparation_before_checkpoint',$stop);
    try{
        for($n=0;$n<3;++$n){$wpdb->update(Operations::tables()['items'],['retry_at'=>gmdate('Y-m-d H:i:s',time()-1)],['operation_id'=>$longJob['id']]);Jobs::work(1);}
    }finally{remove_action('mmaf_profile_preparation_before_checkpoint',$stop);}
    $failed=Jobs::items($longJob['id'])['items'][0];
    bulk_check($failed['status']==='failed' && (int)$failed['attempts']===3 && $failed['result']['checkpoint']['offset']===50 && !(int)$fighters->find($long['fighter_id'])['is_public'],'three technical failures preserve the last committed checkpoint and never publish the candidate');
    $oldChild=\MMAF\DataEngine\Services\Operations\Calculation::create('stats',$longJob['id'],1,'bulk-failed-parent-child');
    Jobs::save($longJob['id'],['status'=>'failed','attempts'=>2,'retry_at'=>gmdate('Y-m-d H:i:s',time()+300),'error_message'=>'Synthetic parent failure','checkpoint'=>array_merge(Jobs::get($longJob['id'])['checkpoint'],['stats_job'=>$oldChild])]);
    $restarted=Jobs::control($longJob['id'],'retry',1,'',[$long['fighter_id']]);
    bulk_check((int)$restarted['attempts']===0 && $restarted['retry_at']===null && Jobs::get($oldChild)['status']==='cancelled' && empty($restarted['checkpoint']['stats_job']),'targeted retry clears parent backoff and cancels its detached frozen calculation');
    Jobs::work(1);$resumed=Jobs::items($longJob['id'])['items'][0];
    bulk_check($resumed['status']==='prepared' && $resumed['result']['checkpoint']['offset']===64,'targeted retry resumes the remaining career rows directly from the preserved checkpoint');
    fixture_drive_job($longJob['id']);$posts[]=(int)$fighters->find($long['fighter_id'])['wp_post_id'];
    bulk_check((int)$fighters->find($long['fighter_id'])['is_public']===1 && !(int)$fighters->find($long['fighter_id'])['is_rankable'],'retried public-profile preparation finishes without overriding ranking eligibility for a long career');
    try { $service->process_bulk_action('approve_and_prepare',range(1,101),1);throw new RuntimeException('Unbounded bulk accepted'); }
    catch (InvalidArgumentException $error) { bulk_check(Processor::pending()===$pending,'oversized ID selections fail before writes; filtered-set operations use their separate manifest'); }
} finally {
    Transaction::rollback();WriteGuard::$worker=false;
    wp_cache_flush();
}
bulk_check(Processor::pending()===$pending,'bulk profile and preparation writes roll back with their enclosing transaction');
