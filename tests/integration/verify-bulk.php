<?php
require __DIR__ . '/bootstrap.php';
use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\{FighterRepository,FighterSourceRepository,RankingRunRepository};
use MMAF\DataEngine\Services\FighterReadinessService;
use MMAF\DataEngine\Services\DataPipeline\Processor;
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard};
function bulk_check($ok,$label) { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
wp_set_current_user(1);
add_filter('pre_http_request',static function(){throw new RuntimeException('Approval attempted a source fetch.');});
$tables=Schema::table_names();$fighters=new FighterRepository();$sources=new FighterSourceRepository();$service=new FighterReadinessService();
$active=(new RankingRunRepository())->get_active_ranking_run_id();$pending=Processor::pending();
$posts=[];WriteGuard::$worker=true;Transaction::begin();
try {
    $ids=[];
    foreach (range(1,4) as $n) {
        $id=$fighters->insert(['display_name'=>'Bulk approval fixture '.$n,'gender'=>'male','date_of_birth'=>$n===3?null:'2000-01-01','weight_class'=>'lightweight','status'=>$n===4?'hidden':'provisional']);
        $ids[]=$id;
        $url='https://www.tapology.com/fightcenter/fighters/94877000'.$n.'-bulk';
        $sources->upsert_for_fighter($id,['source_type'=>'tapology','source_fighter_id'=>'tapology_fighter_94877000'.$n,'source_url'=>$url,'identity_hash'=>hash('sha256',$url),'is_primary'=>1]);
        $wpdb->insert($tables['fighter_stats_current'],['fighter_id'=>$id,'wins'=>3,'losses'=>0,'draws'=>0,'nc'=>0,'pro_fights_count'=>3,'last_fight_date'=>'2026-10-05','calculated_at'=>gmdate('Y-m-d H:i:s')]);
        $wpdb->update($tables['fighters'],['created_at'=>'2026-10-0'.$n.' 00:00:00','updated_at'=>'2026-10-0'.(5-$n).' 00:00:00'],['id'=>$id]);
    }
    $listed=$fighters->list('Bulk approval fixture',100,0,['status'=>'provisional','public_state'=>'not_public','readiness_issue'=>'provisional_tapology','sort'=>'newest']);
    bulk_check(array_map('intval',array_column($listed,'id'))===[$ids[2],$ids[1],$ids[0]],'new candidate view excludes hidden fighters and orders by import creation rather than later updates');
    $selected=array_merge($ids,[$ids[0],999999999]);
    $preview=$service->process_bulk_action('validate_selected_for_ranking',$selected,1);
    bulk_check($preview['selected_count']===5 && $preview['ready_count']===2 && $preview['blocked_count']===3 && Processor::pending()===$pending,'read-only preview forecasts combined approval, reports unknown IDs and makes no changes');
    $result=$service->process_bulk_action('approve_and_prepare',$selected,1);
    bulk_check($result['updated_count']===2 && $result['blocked_count']===3,'mixed bulk selection approves ready candidates and blocks missing data, hidden and unknown fighters');
    foreach(array_slice($ids,0,2) as $id) {
        $fighter=$fighters->find($id);$posts[]=(int)$fighter['wp_post_id'];
        bulk_check($fighter['status']==='verified' && (int)$fighter['is_public']===1 && (int)$fighter['is_rankable']===1 && $fighter['rankability_status']==='rankable' && get_post_status($fighter['wp_post_id'])==='publish','combined decision publishes one canonical profile with all approved flags');
    }
    bulk_check(Processor::pending()===$pending+2 && (new RankingRunRepository())->get_active_ranking_run_id()===$active,'bulk decision queues each selected fighter and leaves the active ranking unchanged');
    $repeat=$service->process_bulk_action('approve_and_prepare',$selected,1);
    bulk_check($repeat['updated_count']===0 && $repeat['skipped_count']===2 && $repeat['blocked_count']===3 && Processor::pending()===$pending+2,'repeating a mixed approval does not duplicate posts or pending work');
    try { $service->process_bulk_action('approve_and_prepare',range(1,101),1);throw new RuntimeException('Unbounded bulk accepted'); }
    catch (RuntimeException $error) { bulk_check($error->getMessage()!=='Unbounded bulk accepted' && Processor::pending()===$pending+2,'oversized batches fail before writes instead of silently approving part of the selection'); }
} finally {
    Transaction::rollback();WriteGuard::$worker=false;
    foreach($posts as $post){clean_post_cache($post);}
}
bulk_check(Processor::pending()===$pending,'bulk profile and preparation writes roll back with their enclosing transaction');
