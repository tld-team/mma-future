<?php
require __DIR__.'/bootstrap.php';
use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\RegularImport\{Repository,Engine,Storage,Transaction,WriteGuard,DatabaseState};
use MMAF\DataEngine\Services\UfcSigningService;
function ufc_import_check(bool $ok,string $label): void {if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
$archive=getenv('MMAF_UFC_PIPELINE_FIXTURE');if(!$archive || !is_file($archive)){throw new RuntimeException('Build the offline UFC ZIP fixture first.');}
global $wpdb;$t=Schema::table_names();$job=0;Transaction::begin();WriteGuard::$worker=true;
try{
    $wpdb->insert($t['fighters'],['display_name'=>'Synthetic UFC Import Fighter','normalized_name'=>'synthetic ufc import fighter','date_of_birth'=>'1995-01-02','gender'=>'male','weight_class'=>'lightweight','status'=>'verified','rankability_status'=>'rankable','is_public'=>1,'is_rankable'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);$fighter=(int)$wpdb->insert_id;ufc_import_check($fighter>0,'isolated canonical fighter created');
    $repo=new Repository();$job=$repo->create('synthetic-ufc.zip',filesize($archive),1);$dir=Storage::job($job);copy($archive,$dir.'/bundle.zip');$repo->update($job,['phase'=>'prepare','status'=>'queued']);
    for($n=0;$n<20;$n++){$state=$repo->get($job);if($state['status']==='awaiting_approval'){break;}$result=(new Engine())->work();if(empty($result['worked'])){throw new RuntimeException('UFC preview did not progress: '.wp_json_encode($result));}}
    $state=$repo->get($job);ufc_import_check($state['status']==='awaiting_approval','standalone ZIP reaches import preview');
    $items=$repo->items($job,'ufc_roster');$preview=json_decode($items[0]['payload_json']??'null',true);ufc_import_check(($preview['comparison']['new']??0)>=1 && ($preview['comparison']['unmatched']??0)>=1 && ($preview['destination']??'')===home_url('/'),'preview uses the shared matcher and identifies the target site');
    $meta=$state['meta'];$meta['baseline']=DatabaseState::integrity();$meta['protected_baseline']=DatabaseState::protectedState();$meta['backup']=['fixture_only'=>true];
    $repo->update($job,['meta'=>$meta,'approved_by'=>1,'approved_hash'=>$meta['approval_token'],'writes_started'=>1,'phase'=>'ufc','status'=>'queued']);
    for($n=0;$n<10;$n++){$state=$repo->get($job);if($state['phase']==='done'){break;}$result=(new Engine())->work();if(empty($result['worked'])){throw new RuntimeException('UFC apply did not progress: '.wp_json_encode($result));}}
    $state=$repo->get($job);ufc_import_check($state['status']==='completed_with_review','UFC evidence import completes without automatic exclusion');
    $candidate=$wpdb->get_row($wpdb->prepare("SELECT c.* FROM {$t['ufc_signing_candidates']} c WHERE c.fighter_id=%d AND c.status='pending' LIMIT 1",$fighter),ARRAY_A);
    $before=$wpdb->get_row($wpdb->prepare("SELECT in_ufc,is_public,is_rankable FROM {$t['fighters']} WHERE id=%d",$fighter),ARRAY_A);
    ufc_import_check($candidate && !(int)$before['in_ufc'] && (int)$before['is_public'] && (int)$before['is_rankable'],'matched fighter waits for explicit administrator decision');
    $service=new UfcSigningService();$service->resolve_candidate((int)$candidate['id'],'confirm',1,'Synthetic contract evidence reviewed',$candidate['evidence_hash'],$candidate['fighter_hash']);
    $after=$wpdb->get_row($wpdb->prepare("SELECT in_ufc,tracking_mode,is_public,is_rankable,rankability_status FROM {$t['fighters']} WHERE id=%d",$fighter),ARRAY_A);
    ufc_import_check((int)$after['in_ufc']===1 && $after['tracking_mode']==='opponent_only' && !(int)$after['is_public'] && !(int)$after['is_rankable'] && $after['rankability_status']==='ineligible_ufc','manual confirmation excludes the fighter and preserves opponent mode');
    ufc_import_check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ufc_roster_runs']} WHERE run_id=%s",'isolated-ufc-import'))===1 && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ufc_candidate_decisions']} WHERE candidate_id=%d",$candidate['id']))===1,'immutable roster and decision evidence are retained');
    $repairs=(array)get_option('mmaf_ufc_post_commit_repairs',[]);$repairs[$fighter]['post']=true;$repairs[$fighter]['ranking']=true;update_option('mmaf_ufc_post_commit_repairs',$repairs,false);
    $fail=static function(){throw new RuntimeException('Synthetic cache invalidation failure');};add_action('mmaf_public_data_changed',$fail,0);
    $blocked=$service->work_repairs();remove_action('mmaf_public_data_changed',$fail,0);
    ufc_import_check(empty($blocked['worked']) && $blocked['blocked']==='post_commit_retry' && !empty(get_option('mmaf_ufc_post_commit_repairs',[])[$fighter]),'failed post-commit cache step stays queued');
    $recovered=$service->work_repairs();ufc_import_check(!empty($recovered['worked']) && $recovered['remaining']===0,'worker retries and completes the failed post-commit step');
}finally{Transaction::rollback();WriteGuard::$worker=false;if($job){Storage::removeTree(Storage::job($job));}wp_cache_flush();}
echo "UFC standalone import and review checks completed.\n";
