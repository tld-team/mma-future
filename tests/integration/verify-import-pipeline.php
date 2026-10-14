<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';
use MMAF\DataEngine\Migrations\{MigrationRunner,Schema};
use MMAF\DataEngine\Services\RegularImport\{Repository,Engine,Storage,Transaction,WriteGuard,Worker,Controller};
use MMAF\DataEngine\Services\DataPipeline\{ProfileStore,Processor,Schema as PipelineSchema};
function check($ok,string $reason): void {if(!$ok){throw new RuntimeException($reason);}echo "PASS: $reason\n";}
set_exception_handler(static function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
set_error_handler(static function($severity,$message,$file,$line){if(error_reporting()&$severity){throw new ErrorException($message,0,$severity,$file,$line);}return false;});
MigrationRunner::run();wp_set_current_user(1);
$queueTable=PipelineSchema::tables()['queue'];
if(!(int)$wpdb->get_var("SELECT COUNT(*) FROM $queueTable")){
    Repository::check($wpdb->query("ALTER TABLE $queueTable DROP COLUMN processed_context_hash"));update_option('mmaf_db_version','1.9.0',false);
    MigrationRunner::run();
    check(get_option('mmaf_db_version')===MMAF_DB_VERSION && $wpdb->get_row("SHOW COLUMNS FROM $queueTable LIKE 'processed_context_hash'"),'explicit upgrade from the prior queue schema adds the context column');
    MigrationRunner::run();check((int)$wpdb->get_var("SELECT COUNT(*) FROM $queueTable")===0,'additive schema upgrade is idempotent');
}
$archive=getenv('MMAF_PIPELINE_FIXTURE');if(!$archive||!is_file($archive)){throw new RuntimeException('Generate the parser/cycle/ZIP fixture first.');}
$repo=new Repository();$dir=null;$deltaDir=null;$temporary=[];$t=Schema::table_names();$q=PipelineSchema::tables()['queue'];
WriteGuard::$worker=true;Transaction::begin();
try{
    $capacityPath=MMAF_OPERATIONS_DIR.'/capacity.json';$capacityBefore=is_file($capacityPath)?file_get_contents($capacityPath):null;
    try{
        try{\MMAF\DataEngine\Services\RegularImport\Preflight::recordCapacity(['bytes_unlimited'=>true,'used_bytes'=>'unknown','inodes_unlimited'=>true,'used_inodes'=>0]);throw new RuntimeException('Unknown quota accepted');}catch(\MMAF\DataEngine\Services\RegularImport\ImportException $e){check(true,'unknown quota cannot be made fresh by a timestamp alone');}
        $preflight=\MMAF\DataEngine\Services\RegularImport\Preflight::recordCapacity(['bytes_unlimited'=>true,'used_bytes'=>0,'inodes_unlimited'=>false,'used_inodes'=>100,'limit_inodes'=>800000]);
        check($preflight['ready'] && $preflight['capacity']['source']==='manual_cpanel','supported capacity entry verifies schema, storage, account quota and inode budget');
    }finally{if($capacityBefore!==null){file_put_contents($capacityPath,$capacityBefore);}else{@unlink($capacityPath);}}
    $contract=json_decode(file_get_contents(__DIR__.'/../fixtures/import-pipeline/contracts.json'),true);
    foreach($contract['digest_cases'] as $case){check(\MMAF\DataEngine\Services\RegularImport\InputDigest::hash($case['input'])===$case['sha256'],'shared JSON digest contract preserves floats, Unicode, empty maps and volatile observations');}
    foreach($contract['url_cases'] as $case){$identity=\MMAF\DataEngine\Support\TapologyFighterUrl::parse($case['url']);$source=$identity?($identity['source_fighter_id']?:'tapology_fighter_url_'.substr($identity['source_url_hash'],0,24)):null;check(($identity['source_url']??null)===$case['normalized'] && $source===$case['source_fighter_id'],'shared URL contract: '.$case['url']);}
    $a=fixture_fighter(94981101,'Synthetic A',['is_public'=>1,'is_rankable'=>1,'rankability_status'=>'rankable','status'=>'verified']);
    $b=fixture_fighter(94981102,'Synthetic B',['is_public'=>1,'is_rankable'=>1,'rankability_status'=>'rankable','status'=>'verified']);
    $job=$repo->create('synthetic-parser-cycle.zip',filesize($archive),1);$dir=Storage::job($job);copy($archive,$dir.'/bundle.zip');
    $repo->update($job,['phase'=>'prepare','status'=>'queued']);
    for($n=0;$n<100;$n++){
        $current=$repo->get($job);if($current['status']==='awaiting_approval'){break;}
        $result=(new Engine())->work();if(empty($result['worked'])){throw new RuntimeException(wp_json_encode($result));}
    }
    $current=$repo->get($job);
    check($current['status']==='awaiting_approval','actual Python parser/cycle ZIP passes PHP package validation and dry-run');
    check($current['meta']['package']['readiness']['profiles']['career_ready_candidates']===2,'source readiness is preserved through the real package reader');
    check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}")===0,'dry-run keeps canonical bouts untouched');
    // Test safe writes with a frozen baseline; the separate backup tests cover actual restore.
    // This fixture never bypasses backup in application code.
    $meta=$current['meta'];$meta['baseline']=\MMAF\DataEngine\Services\RegularImport\DatabaseState::integrity();$meta['protected_baseline']=\MMAF\DataEngine\Services\RegularImport\DatabaseState::protectedState();$meta['backup']=['fixture_only'=>true];
    $repo->update($job,['meta'=>$meta,'approved_by'=>1,'writes_started'=>1,'phase'=>'results','status'=>'queued']);
    $units=Worker::run(35,256,250);
    $current=$repo->get($job);
    check(in_array($current['status'],['completed','completed_with_review'],true) && !$current['error'],'one bounded worker finishes actual import, careers, statistics and final audit');
    $career=$wpdb->get_results($wpdb->prepare("SELECT status FROM {$t['fighter_career_coverage']} WHERE fighter_id IN (%d,%d)",$a['fighter_id'],$b['fighter_id']),ARRAY_A);
    check(count($career)===2 && array_column($career,'status')===['complete','complete'],'section evidence survives Python to PHP and confirms both fighter perspectives');
    check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bout_sources']} WHERE source_bout_id='tapology_bout_94981301'")===1,'two source perspectives produce one canonical bout');
    check($units['bootstraps']===1 && $units['units']>1,'supported worker reuses one WordPress bootstrap for multiple atomic units');
    check((new Engine())->profileChanges($job)['snapshot_new']===2,'raw snapshot counters are separate from profile fact changes');
    check(!empty($current['meta']['performance']['stored_career']['units']),'phase performance is recorded durably');
    $draft=Processor::status()['calculation']['draft']['ranking_run_id']??0;
    $assessmentTable=\MMAF\DataEngine\Services\Scoring\Schema::tables()['assessments'];
    check($draft && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $assessmentTable WHERE ranking_run_id=%d AND fighter_id IN (%d,%d) AND status='main'",$draft,$a['fighter_id'],$b['fighter_id']))===2,'real parser → ZIP → import → frozen period assessment → inactive v1.6 ranking draft covers both perspectives');
    if(getenv('MMAF_PIPELINE_METRICS')){file_put_contents(getenv('MMAF_PIPELINE_METRICS'),wp_json_encode(['worker'=>$units,'phases'=>$current['meta']['performance'],'source_readiness'=>$current['meta']['package']['readiness'],'environment'=>'isolated local synthetic database; two profiles; backup covered separately'],JSON_PRETTY_PRINT));}
    $receipt=Storage::read($dir.'/receipt.json');
    check(\MMAF\DataEngine\Services\RegularImport\Receipt::validate($receipt)['job_id']===$job,'committed destination receipt is accepted as a delta base');
    $wrong=$receipt;$wrong['destination_id']=str_repeat('0',64);
    try{\MMAF\DataEngine\Services\RegularImport\Receipt::validate($wrong);throw new RuntimeException('Foreign delta base accepted');}catch(\MMAF\DataEngine\Services\RegularImport\ImportException $e){check($e->reason==='delta_base','foreign destination base is rejected before writes');}
    $wrong=$receipt;$wrong['bundle_hash']=str_repeat('0',64);
    try{\MMAF\DataEngine\Services\RegularImport\Receipt::validate($wrong);throw new RuntimeException('Wrong base hash accepted');}catch(\MMAF\DataEngine\Services\RegularImport\ImportException $e){check($e->reason==='delta_base','incorrect committed bundle hash is rejected before writes');}
    $python=getenv('MMAF_TEST_PYTHON');if(!$python){throw new RuntimeException('Set MMAF_TEST_PYTHON for cross-language delta verification.');}
    $receiptPath=tempnam(sys_get_temp_dir(),'mmaf-delta-receipt-');file_put_contents($receiptPath,wp_json_encode($receipt));$temporary[]=$receiptPath;
    $deltaPath=$receiptPath.'.zip';$temporary[]=$deltaPath;
    $latest=getenv('MMAF_PIPELINE_LATEST');
    $process=proc_open([$python,__DIR__.'/build-delta-fixture.py',$latest,$receiptPath,$deltaPath],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    check($code===0,'Python exports a delta using the real PHP receipt: '.$errors);
    $delta=$repo->create('synthetic-delta.zip',filesize($deltaPath),1);$deltaDir=Storage::job($delta);copy($deltaPath,$deltaDir.'/bundle.zip');$repo->update($delta,['phase'=>'prepare','status'=>'queued']);
    for($n=0;$n<100;$n++){$d=$repo->get($delta);if($d['status']==='awaiting_approval'){break;}$result=(new Engine())->work();if(empty($result['worked'])){throw new RuntimeException(wp_json_encode($result));}}
    $d=$repo->get($delta);
    check($d['status']==='awaiting_approval' && !$d['meta']['package']['counts']['results'] && !$d['meta']['package']['counts']['profiles'],'identical input becomes a valid empty delta across Python and PHP hashes');
    $dm=$d['meta'];$dm['baseline']=\MMAF\DataEngine\Services\RegularImport\DatabaseState::integrity();$dm['protected_baseline']=\MMAF\DataEngine\Services\RegularImport\DatabaseState::protectedState();$dm['calculation_input_baseline']=\MMAF\DataEngine\Services\RegularImport\DatabaseState::calculationRevision();$dm['backup']=['fixture_only'=>true];
    $runsBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}");
    $repo->update($delta,['meta'=>$dm,'approved_by'=>1,'writes_started'=>1,'phase'=>'results','status'=>'queued']);Worker::run(35,256,250);$d=$repo->get($delta);
    check(in_array($d['status'],['completed','completed_with_review'],true) && !empty($d['meta']['stats']['reused']) && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}")===$runsBefore,'empty same-date delta reuses verified stats and creates no additional ranking draft');
    $profile=(new ProfileStore())->for_fighter($a['fighter_id']);$raw=$profile['profile'];
    $invalid=$raw;$invalid['history_scope_verified']='false';$invalid['history_exhausted']='false';$adapted=ProfileStore::career_profile($invalid,$a['fighter_id']);
    check(!$adapted['history_scope_verified'] && !$adapted['history_exhausted'] && !$adapted['rows'][0]['is_professional'],'string false cannot masquerade as verified scope or complete history');
    $raw['last_attempt']=['at'=>gmdate('c',strtotime($profile['source_fetched_at'].' UTC')+1),'status'=>'failed_parse','error_reason'=>'Synthetic timeout'];
    (new ProfileStore())->put($raw,'tapology_fighter_profiles_v0_1');$saved=(new ProfileStore())->for_fighter($a['fighter_id']);
    check($saved['content_hash']===$profile['content_hash'] && $saved['last_attempt_status']==='failed_parse' && $saved['profile']['status']==='success','a newer failed attempt is visible without changing the retained success content hash');
    Processor::enqueue($a['fighter_id'],1,$job);
    check(!Processor::pending($job),'identical source and canonical context skip repeat preparation');
    $participant=$wpdb->get_row($wpdb->prepare("SELECT p.id,p.result_for_fighter FROM {$t['bout_participants']} p JOIN {$t['bout_sources']} s ON s.bout_id=p.bout_id WHERE s.source_bout_id='tapology_bout_94981301' AND p.fighter_id=%d",$b['fighter_id']),ARRAY_A);
    $wpdb->update($t['bout_participants'],['result_for_fighter'=>'win'],['id'=>$participant['id']]);
    Processor::enqueue($a['fighter_id'],1,$job);Processor::enqueue($b['fighter_id'],1,$job);
    check(Processor::pending($job)===2,'a shared opponent result correction invalidates both preparation contexts');
    try{\MMAF\DataEngine\Services\RegularImport\Receipt::validate($receipt);throw new RuntimeException('Changed destination base accepted');}catch(\MMAF\DataEngine\Services\RegularImport\ImportException $e){check($e->reason==='delta_base','canonical changes invalidate a previously valid delta base');}
    $calls=0;$result=Worker::run(35,256,2,static function()use(&$calls){++$calls;return ['worked'=>true];});
    check($calls===2 && $result['stop_reason']==='step_budget','worker stops at the explicit step budget');
    $flag=MMAF_OPERATIONS_DIR.'/writes-paused.flag';
    try{$calls=0;$result=Worker::run(35,256,3,static function()use(&$calls,$flag){++$calls;file_put_contents($flag,'fixture');return ['worked'=>true];});check($calls===1 && $result['stop_reason']==='protected_checkpoint','new release pause flag stops work between committed units');}finally{@unlink($flag);}
    $result=Worker::run(35,32,2,static function(){throw new RuntimeException('Memory guard did not stop before work.');});
    check($result['stop_reason']==='memory_budget' && !$result['worked'],'worker respects a memory ceiling before starting another unit');
    $result=Worker::run(1,256,3,static function(){usleep(1100000);return ['worked'=>true];});
    check($result['units']===1 && $result['stop_reason']==='time_budget','worker respects elapsed time between committed units');
    $table=$t['fighter_career_coverage'];
    for($n=0;$n<251;$n++){Repository::check($wpdb->insert($table,['fighter_id'=>94990000+$n,'status'=>'unavailable','accepted_bouts_count'=>0,'verified_at'=>gmdate('Y-m-d H:i:s')]));}
    $revision=\MMAF\DataEngine\Services\RegularImport\DatabaseState::calculationRevision();
    $wpdb->update($table,['accepted_bouts_count'=>1],['fighter_id'=>94990250]);
    check($revision!==\MMAF\DataEngine\Services\RegularImport\DatabaseState::calculationRevision(),'calculation digest covers pages beyond 250 career records with fighter_id cursors');
}finally{Transaction::rollback();WriteGuard::$worker=false;if($dir){Storage::removeTree($dir);}if($deltaDir){Storage::removeTree($deltaDir);}foreach($temporary as $file){@unlink($file);}wp_cache_flush();}
