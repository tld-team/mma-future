<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';
use MMAF\DataEngine\Services\Priorities\{Registry,Profiles,Report,Schema as PrioritySchema};
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard,Repository,Package,Storage,Engine,DatabaseState,EventCoverage,ImportException};
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Migrations\{Schema,MigrationRunner};
function check($ok,string $why): void {if(!$ok){throw new RuntimeException($why);}echo "PASS: $why\n";}
function rejects(callable $fn,string $why): void {try{$fn();}catch(InvalidArgumentException|ImportException $e){check(true,$why);return;}throw new RuntimeException($why);}
set_exception_handler(static function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
MigrationRunner::run();wp_set_current_user(1);$t=Schema::table_names();$dir=null;
$before=DatabaseState::integrity();WriteGuard::$worker=true;Transaction::begin();
try{
    set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
    ob_start();
    try{\MMAF\DataEngine\Admin\PrioritiesPage::render();$empty=ob_get_contents();}finally{ob_end_clean();restore_error_handler();}
    check(str_contains($empty,'Registered targets · 0'),'empty priority registry renders without PHP warnings or deprecations');
    $csv="Ime,Pobede,Godine\nSame Name,8,2000\nSame Name,12,1996\nNew Candidate,10,2001\n";
    $parsed=Registry::parseCsv($csv);check(count($parsed)===3 && $parsed[0]['entry_key']!==$parsed[1]['entry_key'],'namesakes with different original hints remain separate targets');
    $same=Registry::parseCsv("Ime,Pobede\nSame Name,8\nSame Name,8\n");check($same[0]['entry_key']!==$same[1]['entry_key'],'identical rows within a file retain separate unresolved persons and stable occurrence identities');
    $one=Registry::importCsv($csv,1);$two=Registry::importCsv($csv,1);
    check($one['created']===3 && $two['created']===0 && $two['existing']===3,'reimporting the same CSV is idempotent');
    check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['fighters']}")===$before['fighters'],'CSV scores and names do not create or alter canonical fighters');
    $ids=$wpdb->get_col('SELECT id FROM '.PrioritySchema::table().' ORDER BY id');
    $a=fixture_fighter(94981101,'Synthetic A');$b=fixture_fighter(94981102,'Synthetic B');
    rejects(fn()=>Registry::resolve($ids[0],$a['fighter_id'],$b['source_url'],1),'conflicting source ID cannot be attached to a selected canonical fighter');
    Registry::resolve($ids[0],$a['fighter_id'],$a['source_url'],1);
    $third='https://www.tapology.com/fightcenter/fighters/94981103-new-candidate';
    Registry::resolve($ids[2],0,$third,1);$queue=Registry::export();$bindings=Registry::validateManifest($queue['priority_targets']);
    check(count($bindings)===2 && Registry::get($ids[2])['fighter_id']===null,'confirmed priority can exist without any canonical fighter or event');
    $changed=$queue['priority_targets'];$changed['items'][0]['source_fighter_id']='tapology_fighter_123';
    rejects(fn()=>Registry::validateManifest($changed),'tampered priority source identity is rejected');
    Registry::active($ids[2],false,1);rejects(fn()=>Registry::validateManifest($queue['priority_targets']),'disabled or changed target invalidates an exported queue');Registry::active($ids[2],true,1);
    $queue=Registry::export();$bindings=Registry::validateManifest($queue['priority_targets']);
    $raw=json_decode(file_get_contents(__DIR__.'/../fixtures/scoring-period/profile.json'),true);$raw['source_fighter_id']='tapology_fighter_94981103';$raw['source_url']=$third;$raw['display_name']='Verified Source Name';$raw['scraped_at']=gmdate('c');$raw['fight_history']=[];
    $mismatch=$raw;$mismatch['source_url']=$a['source_url'];rejects(fn()=>Profiles::plan($mismatch,$bindings),'profile ID and URL are checked independently of its client name');
    $made=Profiles::apply($raw,$bindings,1,0);$again=Profiles::apply($raw,$bindings,1,0);$fid=$made['id'];
    $fighter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighters']} WHERE id=%d",$fid),ARRAY_A);
    check($made['created'] && !$again['created'] && $again['id']===$fid && !$fighter['is_public'] && !$fighter['is_rankable'] && $fighter['status']==='provisional','profile-only create is unpublished, provisional and replay-safe');
    check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['events']}")===$before['events'] && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}")===$before['bouts'],'profile-only create invents no event or bout');
    check($fighter['display_name']==='Verified Source Name','candidate name comes from verified source profile, never from a CSV score or fuzzy match');
    Registry::validateManifest($queue['priority_targets']);
    Registry::resolve($ids[2],$fid,'https://www.tapology.com/fightcenter/fighters/94981103-changed-slug',1);
    check((int)Registry::get($ids[2])['fighter_id']===$fid,'changed slug with the same numeric source identity preserves the canonical fighter');
    $store=new ProfileStore();$store->put($raw,'tapology_fighter_profiles_v0_1');$saved=$store->for_fighter($fid);$failed=$raw;$failed['status']='blocked';$failed['scraped_at']=gmdate('c',time()+1);$store->put($failed,'tapology_fighter_profiles_v0_1');$after=$store->for_fighter($fid);
    check($after['source_fetched_at']===$saved['source_fetched_at'] && $after['content_hash']===$saved['content_hash'] && $after['last_attempt_status']==='blocked','failed refresh keeps the successful value and actual observation time');
    $report=Report::rows();check(count($report)===3 && count(array_filter($report,fn($r)=>!empty($r['next_action'])))===3,'every target, including unresolved namesakes, has a private outcome and next action');
    wp_set_current_user(0);check(!\MMAF\DataEngine\Admin\PrioritiesPage::allowed() && !has_action('admin_post_nopriv_mmaf_priorities'),'anonymous users cannot read or mutate priorities');wp_set_current_user(1);
    // Exercise actual ZIP prepare/check/import with a target absent from all event results.
    $archive=getenv('MMAF_PIPELINE_FIXTURE');check($archive && is_file($archive),'Python cycle fixture is available');
    Registry::resolve($ids[1],0,'https://www.tapology.com/fightcenter/fighters/94981104-another',1);
    $new=$raw;$new['source_fighter_id']='tapology_fighter_94981104';$new['source_url']='https://www.tapology.com/fightcenter/fighters/94981104-another';$new['display_name']='Another Source Profile';
    $repo=new Repository();$job=$repo->create('priority-profile-fixture.zip',filesize($archive),1);$dir=Storage::job($job);copy($archive,$dir.'/bundle.zip');$zip=new ZipArchive();$zip->open($dir.'/bundle.zip');$documents=[];
    for($i=0;$i<$zip->numFiles;$i++){$name=$zip->getNameIndex($i);$documents[$name]=json_decode($zip->getFromIndex($i),true);}
    $prefix='latest/';$run=$documents[$prefix.'run_manifest.json']['run_id'];
    $documents[$prefix.'results.json']['events']=[];
    $documents[$prefix.'daily_summary.json']['events_total']=0;$documents[$prefix.'daily_summary.json']['bouts_total']=0;$documents[$prefix.'daily_summary.json']['profiles_total']=1;
    $documents[$prefix.'fighter_profiles.json']['profiles']=[$new];$documents[$prefix.'fighter_profiles.json']['profiles_total']=1;
    $documents[$prefix.'readiness_report.json']['profiles_total']=1;
    $documents[$prefix.'event_coverage.json']=['schema_version'=>'mmaf_event_coverage_v1','run_id'=>$run,'events'=>[]];
    $targets=Registry::export()['priority_targets'];$targets['items']=array_values(array_filter($targets['items'],fn($x)=>$x['source_fighter_id']===$new['source_fighter_id']));$documents[$prefix.'priority_targets.json']=$targets;
    $manifest=$documents[$prefix.'package_manifest.json'];$manifest['contract_version']=2;
    foreach($documents as $name=>$value){if($name===$prefix.'package_manifest.json'){continue;}$bytes=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$zip->addFromString($name,$bytes);$manifest['files'][substr($name,strlen($prefix))]=['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];}
    $zip->addFromString($prefix.'package_manifest.json',wp_json_encode($manifest));$zip->close();$repo->update($job,['phase'=>'prepare','status'=>'queued']);
    for($i=0;$i<20;$i++){$state=$repo->get($job);if($state['status']==='awaiting_approval'){break;}$worked=(new Engine())->work();if(empty($worked['worked'])){throw new RuntimeException(wp_json_encode($worked));}}
    check($state['status']==='awaiting_approval' && $state['meta']['package']['counts']['results']===0,'priority-only ZIP reaches review without a fabricated event');
    $meta=$state['meta'];$meta['baseline']=DatabaseState::integrity();$meta['protected_baseline']=DatabaseState::protectedState();$meta['backup']=['fixture_only'=>true];
    $repo->update($job,['meta'=>$meta,'approved_by'=>1,'writes_started'=>1,'phase'=>'profiles','status'=>'queued']);(new Engine())->work();
    $bound=Registry::get($ids[1]);check($bound['fighter_id'] && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['fighter_sources']} WHERE source_fighter_id=%s",$new['source_fighter_id']))===1,'regular importer creates and binds exactly one profile-only candidate');
    // Manifest and coverage tampering fail before source writes.
    $proof=['source_event_id'=>'tapology_event_1','event_url'=>'https://www.tapology.com/fightcenter/events/1-test','parser_version'=>'tapology_event_card_v2','observed_at'=>gmdate('c'),'source_mma_bouts_total'=>1,'unique_bouts'=>1,'status'=>'complete','reasons'=>[],'counts'=>['accepted'=>1],'bouts'=>[['source_bout_id'=>'tapology_bout_1','sport'=>'mma','division'=>'pro','decision'=>'accepted','reason'=>null]]];
    $path=$dir.'/coverage-test.json';Storage::write($path,['schema_version'=>'mmaf_event_coverage_v1','run_id'=>'fixture','events'=>[$proof]]);$index=EventCoverage::read($path);
    $event=['source_event_id'=>'tapology_event_1','event_url'=>$proof['event_url'],'event_coverage'=>$proof,'bouts'=>[['source_bout_id'=>'tapology_bout_1']]];EventCoverage::checkEvent($event,$index['by_id']);
    $junior=$proof;$junior['source_mma_bouts_total']=2;$junior['unique_bouts']=2;$junior['counts']['excluded']=1;$junior['bouts'][]=['source_bout_id'=>'tapology_bout_2','sport'=>'mma','division'=>'junior','decision'=>'excluded','reason'=>'junior'];
    Storage::write($path,['schema_version'=>'mmaf_event_coverage_v1','events'=>[$junior]]);check(EventCoverage::read($path)['complete'],'explicit junior exclusion preserves the source MMA control count');
    $event['bouts']=[];rejects(fn()=>EventCoverage::checkEvent($event,$index['by_id']),'coverage cannot confirm a source bout omitted from the results');
    $proof['status']='complete';$proof['bouts'][0]['decision']='unresolved';Storage::write($path,['schema_version'=>'mmaf_event_coverage_v1','events'=>[$proof]]);rejects(fn()=>EventCoverage::read($path),'unresolved or inconsistent coverage cannot claim completion');
    rejects(fn()=>EventCoverage::read($dir.'/missing.json'),'missing v2 event evidence is rejected');
}finally{Transaction::rollback();WriteGuard::$worker=false;if($dir){Storage::removeTree($dir);}wp_cache_flush();}
check(DatabaseState::integrity()===$before,'all priority fixtures roll back and leave the active ranking and canonical population unchanged');
