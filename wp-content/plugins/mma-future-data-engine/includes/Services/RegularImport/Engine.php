<?php
namespace MMAF\DataEngine\Services\RegularImport;
use MMAF\DataEngine\Services\Import\ScraperJsonDryRunService;
use MMAF\DataEngine\Services\Import\ScraperJsonImportService;
use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentPreviewService;
use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentApplyService;
use MMAF\DataEngine\Services\StatsRebuildService;
use MMAF\DataEngine\Services\Audit\PostImportAuditService;
use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;

final class Engine {
    private Repository $repo;
    public function __construct(){ $this->repo=new Repository(); }
    public function work(): array {
        $next=$this->repo->active();
        $unit=function(): array {return Mutex::run(function(): array {
            update_option('mmaf_import_worker_seen',gmdate('c'),false);
            $job=$this->repo->active();
            if(!$job||in_array($job['status'],['uploading','awaiting_approval','paused','needs_attention'],true)){return ['worked'=>false];}
            $id=(int)$job['id'];
            if($job['retry_at']&&strtotime($job['retry_at'].' UTC')>time()){return ['worked'=>false];}
            if(!empty($job['pause_requested'])){$this->repo->update($id,['status'=>'paused']);return ['worked'=>false];}
            // A previous process that vanished did not commit its current unit of work.
            $attempts=(int)$job['attempts'];
            if($job['status']==='running'&&$attempts>=3){$this->repo->update($id,['status'=>'needs_attention','error'=>['message'=>'The worker stopped three times. Check the server log, then resume.','reason'=>'worker_interrupted','retryable'=>true]]);return ['worked'=>false];}
            $this->repo->update($id,['status'=>'running','attempts'=>$attempts+1,'retry_at'=>null,'heartbeat_at'=>gmdate('Y-m-d H:i:s')]);
            global $wpdb;$job=$this->repo->get($id);$start=microtime(true);$queryStart=$wpdb->num_queries;$lastBeat=0;
            $heartbeat=function() use ($id,&$lastBeat): void {if(time()-$lastBeat>=2){Storage::write(Storage::job($id).'/heartbeat.json',['at'=>gmdate('c'),'phase'=>$this->repo->get($id)['phase']]);$lastBeat=time();}};
            $heartbeat();WriteGuard::$worker=true;
            try{
                if(($job['meta']['site_url']??'')!==home_url('/')){throw new ImportException('This job belongs to a different site.','destination');}
                if($job['phase']==='backup'){
                    $meta=$job['meta'];$meta['backup']=Backup::create($id,$heartbeat);
                    $meta['baseline']=DatabaseState::integrity();$meta['calculation_input_baseline']=DatabaseState::calculationRevision();$meta['protected_baseline']=DatabaseState::protectedState();
                    $mode=$meta['package']['mode']??'';$writePhase=$mode==='career_history'?'career':($mode==='ufc_roster'?'ufc':'results');
                    $this->repo->update($id,['meta'=>$meta,'phase'=>$writePhase,'status'=>'queued','attempts'=>0,'writes_started'=>1,'error'=>null]);
                }else{
                    Transaction::run(function() use ($job,$heartbeat){$this->step($job,$heartbeat);});
                }
                $latest=$this->repo->get($id);
                if(in_array($latest['status'],['completed','completed_with_review'],true)){do_action('mmaf_public_data_changed','import_completed');}
                $meta=$latest['meta'];$duration=round(microtime(true)-$start,3);
                $meta['performance'][$job['phase']]['seconds']=round(($meta['performance'][$job['phase']]['seconds']??0)+$duration,3);
                $meta['performance'][$job['phase']]['units']=(int)($meta['performance'][$job['phase']]['units']??0)+1;
                $meta['performance'][$job['phase']]['db_queries']=(int)($meta['performance'][$job['phase']]['db_queries']??0)+$wpdb->num_queries-$queryStart;
                $meta['last_step_seconds']=$duration;$meta['peak_memory_mb']=max((float)($meta['peak_memory_mb']??0),round(memory_get_peak_usage(true)/1048576,1));
                $this->repo->update($id,['meta'=>$meta,'attempts'=>0,'error'=>null,'heartbeat_at'=>gmdate('Y-m-d H:i:s')]);
                if(in_array($latest['status'],['completed','completed_with_review'],true) && is_file(Storage::job($id).'/report.json')){
                    $report=Storage::read(Storage::job($id).'/report.json');$report['performance']=$meta['performance'];Storage::write(Storage::job($id).'/report.json',$report);
                }
                return ['worked'=>true,'job_id'=>$id,'phase'=>$latest['phase'],'status'=>$latest['status']];
            }catch(\Throwable $e){
                $retryable=!$e instanceof ImportException||$e->retryable;
                $current=$this->repo->get($id);$attempts=(int)$current['attempts'];
                $terminal=!$retryable||$attempts>=3;
                $status=$terminal?'needs_attention':'queued';
                if(!$retryable&&!$current['writes_started']&&!$current['approved_by']){$status='rejected';}
                $this->repo->update($id,['status'=>$status,'retry_at'=>$terminal?null:gmdate('Y-m-d H:i:s',time()+60*$attempts),'error'=>['message'=>$e->getMessage(),'reason'=>$e instanceof ImportException?$e->reason:'execution_error','retryable'=>$retryable],'finished_at'=>$status==='rejected'?gmdate('Y-m-d H:i:s'):null]);
                return ['worked'=>false,'job_id'=>$id,'status'=>$status,'error'=>$e->getMessage()];
            }finally{WriteGuard::$worker=false;}
        });};
        return $next && $next['phase']==='backup'?BackupGuard::run($unit):$unit();
    }
    private function step(array $job,callable $heartbeat): void {
        global $wpdb;$id=(int)$job['id'];$meta=$job['meta'];$phase=$job['phase'];
        if($phase==='assemble'){
            $dir=Storage::job($id);$out=fopen($dir.'/bundle.zip.partial','wb');
            if(!$out){throw new ImportException('Cannot assemble the upload.','storage',true);}
            $total=0;
            try{
            $count=(int)ceil($meta['upload_bytes']/Storage::CHUNK_SIZE);
            for($i=0;$i<$count;$i++){
                $file=$dir.'/upload-'.$i.'.part';
                if(!is_file($file)||!hash_equals($meta['received_chunks'][(string)$i],hash_file('sha256',$file))){throw new ImportException('An uploaded file part is missing or changed.','checksum');}
                $in=fopen($file,'rb');try{$written=stream_copy_to_stream($in,$out);if($written===false){throw new ImportException('Could not assemble upload.','storage',true);}$total+=$written;}finally{fclose($in);}$heartbeat();
            }}finally{fclose($out);}
            if($total!==(int)$meta['upload_bytes']||!rename($dir.'/bundle.zip.partial',$dir.'/bundle.zip')){throw new ImportException('Upload size verification failed.','storage');}
            chmod($dir.'/bundle.zip',0600);
            $this->repo->update($id,['phase'=>'prepare','status'=>'queued']);return;
        }
        if($phase==='prepare'){
            Repository::check($wpdb->delete($this->repo->tables['batches'],['job_id'=>$id]));
            $meta['package']=(new Package())->prepare($job,$this->repo,$heartbeat);
            $meta['check_revision']=DatabaseState::revision();
            $mode=$meta['package']['mode']??'';$checkPhase=$mode==='career_history'?'check_career':($mode==='ufc_roster'?'check_ufc':'check_results');
            $this->repo->update($id,['meta'=>$meta,'bundle_hash'=>$meta['package']['bundle_hash'],'phase'=>$checkPhase,'status'=>'queued']);return;
        }
        if(($meta['package']['mode']??'')==='career_history' && $phase!=='preflight'){$this->careerStep($job);return;}
        if(strpos($phase,'check_')===0){
            $kind=substr($phase,6);$batch=$this->repo->nextBatch($id,$kind,true);
            if($batch){$this->checkBatch($job,$batch);$this->repo->batchUpdate((int)$batch['id'],['checked'=>1]);$this->repo->update($id,['status'=>'queued']);return;}
            $next=['results'=>'check_profiles','profiles'=>'check_review','review'=>'check_ufc','ufc'=>'ready'][$kind];
            if($next==='ready'){
                $revision=DatabaseState::revision();
                if(!hash_equals($meta['check_revision'],$revision)){$this->restartCheck($job,$revision);return;}
                $meta['plan']=$this->repo->planCounts($id);$meta['approval_token']=hash('sha256',$job['bundle_hash'].'|'.$revision.'|'.wp_json_encode($meta['plan']));
                $this->repo->update($id,['phase'=>'ready','status'=>'awaiting_approval','meta'=>$meta,'data_revision'=>$revision,'approved_by'=>null,'approved_hash'=>null]);
            }else{$this->repo->update($id,['phase'=>$next,'status'=>'queued']);}return;
        }
        if($phase==='preflight'){
            if(isset($meta['package']['priority_targets'])){\MMAF\DataEngine\Services\Priorities\Registry::validateManifest($meta['package']['priority_targets']);}
            if(!empty($meta['package']['delta_base'])){Receipt::validate($meta['package']['delta_base']);}
            $revision=DatabaseState::revision();
            if(!hash_equals((string)$job['data_revision'],$revision)){$this->restartCheck($job,$revision);return;}
            if(!hash_equals((string)$job['approved_hash'],(string)($meta['approval_token']??''))){throw new ImportException('The approved plan no longer matches this import.','approval');}
            $this->verifyFiles($job);DatabaseState::engines();
            $this->repo->update($id,['phase'=>'backup','status'=>'queued']);return;
        }
        if($phase==='stored_career'){
            $result=(new \MMAF\DataEngine\Services\DataPipeline\Processor())->work(5,$id);
            $this->repo->update($id,['phase'=>$result['pending']?'stored_career':'stored_career_verify','status'=>'queued']);return;
        }
        if($phase==='stored_career_verify'){
            $result=\MMAF\DataEngine\Services\DataPipeline\Processor::refresh_import_coverage($id,(int)($meta['career_verify_cursor']??0));
            $meta['career_verify_cursor']=$result['cursor'];
            $this->repo->update($id,['meta'=>$meta,'phase'=>$result['done']?'stats':'stored_career_verify','status'=>'queued']);return;
        }
        if(in_array($phase,['results','profiles','review','ufc'],true)){
            $batch=$this->repo->nextBatch($id,$phase,false);
            if($batch){$this->importBatch($job,$batch);$this->repo->update($id,['status'=>'queued']);return;}
            $next=['results'=>'profiles','profiles'=>'review','review'=>'ufc','ufc'=>(($meta['package']['mode']??'')==='ufc_roster'?'ufc_audit':'stored_career')][$phase];
            $this->repo->update($id,['phase'=>$next,'status'=>'queued']);return;
        }
        if($phase==='ufc_audit'){
            $this->verifyFiles($job);foreach($this->repo->batches($id) as $batch){if(!$batch['completed']){throw new ImportException('The UFC roster batch has no completed checkpoint.','audit');}}
			if(DatabaseState::protectedState((int)$meta['protected_baseline']['max_fighter_id'])!==$meta['protected_baseline']){throw new ImportException('UFC evidence import changed protected fighter decisions.','audit');}
            $meta['actual']=$this->actualCounts($id);$meta['completed_at']=gmdate('c');$this->repo->update($id,['meta'=>$meta,'status'=>'completed_with_review','phase'=>'done','finished_at'=>gmdate('Y-m-d H:i:s')]);
            Storage::write(Storage::job($id).'/report.json',['mode'=>'ufc_roster','job_id'=>$id,'destination'=>['site_url'=>home_url('/'),'database'=>defined('DB_NAME')?DB_NAME:null],'run_ids'=>['ufc'=>$meta['package']['headers']['ufc']['run_id']??null],'status'=>'completed_with_review','plan'=>$meta['plan'],'actual'=>$meta['actual'],'last_successful_import'=>get_option('mmaf_ufc_roster_last_import',null)]);return;
        }
        if($phase==='stats'){
            $previous=(new \MMAF\DataEngine\Repositories\FighterStatsRepository())->get_last_rebuild_summary();
            $same=isset($meta['calculation_input_baseline']) && hash_equals($meta['calculation_input_baseline'],DatabaseState::calculationRevision());
            if($same && $previous && ($previous['calculation_policy']??'')===DatabaseState::calculationPolicy() && ($previous['reference_date']??'')===current_time('Y-m-d') && !DatabaseState::integrity()['fighters_without_stats']){
                $meta['stats']=$previous+['reused'=>true];$meta['calculation_needed']=false;
                $this->repo->update($id,['meta'=>$meta,'phase'=>'audit','status'=>'queued']);return;
            }
            $meta['calculation_needed']=true;
            $calculation=\MMAF\DataEngine\Services\Operations\Calculation::create('stats',0,(int)$job['approved_by'],'import:'.$id.':stats');
            $result=\MMAF\DataEngine\Services\Operations\Calculation::step($calculation);
            if($result['status']==='failed'){throw new ImportException('Statistics generation failed: '.$result['error_message'],'calculation');}
            if($result['status']==='completed'){$meta['stats']=$result['summary'];$this->repo->update($id,['meta'=>$meta,'phase'=>'audit','status'=>'queued']);}return;
        }
        if($phase==='audit'){
            $this->verifyFiles($job);$after=DatabaseState::integrity();
            if(isset($meta['protected_baseline'])&&DatabaseState::protectedState((int)$meta['protected_baseline']['max_fighter_id'])!==$meta['protected_baseline']){throw new ImportException('Protected manual data or publication flags changed during import.','audit');}
            $counts=$this->actualCounts($id);
            foreach(['event'=>'results','bout'=>'bouts','profile'=>'profiles'] as $kind=>$source){if(array_sum($counts[$kind]??[])!==(int)$meta['package']['counts'][$source]){throw new ImportException('Final coverage check failed for '.$kind.'.','audit');}}
            $newPublic=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".CanonicalSchema::table_names()['fighters']." WHERE id>%d AND (is_public<>0 OR is_rankable<>0)",(int)($meta['protected_baseline']['max_fighter_id']??PHP_INT_MAX)));
            if($newPublic){throw new ImportException('A new fighter has unexpected publication flags.','audit');}
            foreach(['duplicate_fighter_sources','duplicate_event_sources','duplicate_bout_sources','malformed_bouts','orphan_participants','bouts_without_event'] as $key){
                if($after[$key]>($meta['baseline'][$key]??0)){throw new ImportException('Final verification found an integrity regression: '.$key,'audit');}
            }
            if($after['fighters_without_stats']!==0||$after['ranking_hash']!==$meta['baseline']['ranking_hash']){throw new ImportException('Final verification failed for stats coverage or live rankings.','audit');}
            foreach($this->repo->batches($id) as $batch){if(!$batch['completed']){throw new ImportException('An import batch has no completed checkpoint.','audit');}}
            $meta['audit']=(new PostImportAuditService())->run()['system_summary']??[];
            $receipt=Receipt::create($job);$meta['receipt']=Receipt::pointer($receipt);
            $meta['integrity']=$after;$meta['completed_at']=gmdate('c');$meta['actual']=$this->actualCounts($id);
            $review=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->repo->tables['items']} WHERE job_id=%d AND category='review' AND kind LIKE 'actual_%%'",$id));
            $review+=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->repo->tables['items']} WHERE job_id=%d AND kind='review'",$id));
            $unverified=!isset($meta['package']['event_coverage']) || !$meta['package']['event_coverage']['complete'];
            $status=$review||$unverified?'completed_with_review':'completed';
            $this->repo->update($id,['meta'=>$meta,'status'=>$status,'phase'=>'done','finished_at'=>gmdate('Y-m-d H:i:s')]);
            $changed=0;foreach($counts as $count){$changed+=(int)($count['created']??0)+(int)($count['updated']??0);}
            if(!empty($meta['calculation_needed']) || (!isset($meta['calculation_needed']) && $changed)){\MMAF\DataEngine\Services\DataPipeline\Processor::request_refresh((int)$job['approved_by'],false);}
            Storage::write(Storage::job($id).'/report.json',['job_id'=>$id,'destination'=>['site_url'=>home_url('/'),'database'=>defined('DB_NAME')?DB_NAME:null],'run_ids'=>['events'=>$meta['package']['headers']['run_manifest']['run_id']??null,'profiles'=>$meta['package']['headers']['profiles']['run_id']??null,'ufc'=>$meta['package']['headers']['ufc']['run_id']??null],'status'=>$status,'filename'=>$job['filename'],'bundle_hash'=>$job['bundle_hash'],'plan'=>$meta['plan'],'actual'=>$meta['actual'],'profile_changes'=>$this->profileChanges($id),'readiness'=>$meta['package']['readiness']??null,'event_coverage'=>$meta['package']['event_coverage']??['complete'=>false,'legacy'=>true],'ufc_roster'=>isset($meta['package']['headers']['ufc'])?['included'=>true,'run_id'=>$meta['package']['headers']['ufc']['run_id']??null,'scraped_at'=>$meta['package']['headers']['ufc']['scraped_at']??null]:['included'=>false,'warning'=>$meta['package']['ufc_warning']??null,'last_successful_import'=>$meta['package']['ufc_roster_last_import']??null],'priority_targets'=>$meta['package']['priority_targets']??null,'backup'=>$meta['backup'],'stats'=>$meta['stats'],'integrity'=>$after,'audit'=>$meta['audit'],'receipt'=>$receipt]);return;
        }
        throw new ImportException('Unknown import phase: '.$phase,'state');
    }
    private function restartCheck(array $job,string $revision): void {
        $meta=$job['meta'];$meta['check_revision']=$revision;$meta['notice']='Site data changed. Review the refreshed checks before starting.';
        unset($meta['approval_token']);$this->repo->resetCheck((int)$job['id']);
        $mode=$meta['package']['mode']??'';$phase=$mode==='career_history'?'check_career':($mode==='ufc_roster'?'check_ufc':'check_results');
        $this->repo->update((int)$job['id'],['phase'=>$phase,'status'=>'queued','meta'=>$meta,'approved_by'=>null,'approved_hash'=>null,'data_revision'=>null]);
    }
    private function checkBatch(array $job,array $batch): void {
        global $wpdb;
        $id=(int)$job['id'];$data=Package::payload($job,$batch);$json=wp_json_encode($data);$kind=$batch['kind'];
        if($kind==='ufc'){
            $preview=(new \MMAF\DataEngine\Services\UfcSigningService())->preview_document($data);
            $this->repo->putItem($id,'ufc_roster',(string)$data['run_id'],(string)$data['run_id'],'review',['fighters'=>count($data['fighters']),'scraped_at'=>$data['scraped_at'],'comparison'=>$preview,'destination'=>home_url('/'),'database'=>defined('DB_NAME')?DB_NAME:null]);
        }elseif($kind==='results'){
            $plan=(new ScraperJsonDryRunService())->analyze_json_string($json,(int)$job['created_by'],false);
            if(empty($plan['is_valid'])){throw new ImportException('Results validation failed: '.implode('; ',array_slice($plan['summary']['validation_errors']??[],0,5)));}
            foreach(['fighters'=>'fighter','events'=>'event','bouts'=>'bout'] as $list=>$entity){foreach($plan[$list] as $row){
                $key=(string)($row['ref_key']??$row['source_'.$entity.'_id']??hash('sha256',wp_json_encode($row)));
                $this->repo->putItem($id,$entity,$key,(string)($row['source_'.$entity.'_id']??''),self::category($row['action']??''),$row);
            }}
        }elseif($kind==='profiles'){
            $keys=array_values(array_filter(array_map(static fn($p)=>$p['source_fighter_id']??'', $data['profiles'])));
            $items=[];
            if($keys){$items=$wpdb->get_results($wpdb->prepare("SELECT payload_json FROM {$this->repo->tables['items']} WHERE job_id=%d AND kind='fighter' AND source_id IN (".implode(',',array_fill(0,count($keys),'%s')).")",$id,...$keys),ARRAY_A);}
            $fighters=array_map(static fn($r)=>json_decode($r['payload_json'],true),$items);
            $bindings=isset($job['meta']['package']['priority_targets'])?\MMAF\DataEngine\Services\Priorities\Registry::validateManifest($job['meta']['package']['priority_targets']):[];
            $planned=array_fill_keys(array_column($fighters,'source_fighter_id'),true);
            foreach($data['profiles'] as $profile){
                $plan=\MMAF\DataEngine\Services\Priorities\Profiles::plan($profile,$bindings);
                if($plan && !isset($planned[$plan['source_fighter_id']]) && !(new \MMAF\DataEngine\Repositories\FighterSourceRepository())->find_by_source('tapology',$plan['source_fighter_id'])){
                    $fighters[]=$plan;$planned[$plan['source_fighter_id']]=true;
                    $this->repo->putItem($id,'fighter',$plan['ref_key'],$plan['source_fighter_id'],'created',$plan);
                }
            }
            $preview=(new FighterProfileEnrichmentPreviewService())->create_row_iterator($json,'Import package',['fighters'=>$fighters]);
            foreach($preview['iterator'] as $row){
                $match=$row['match_type']??'';$matched=in_array($match,['exact_source_match','source_url_match','matched_planned_import'],true);
                $changes=(new FighterProfileEnrichmentApplyService())->preview_safe_changes($row, $match==='matched_planned_import');
                $key=(string)($row['source_fighter_id']?:$row['source_url']);
                $this->repo->putItem($id,'profile',$key,$key,$matched?($changes?'updated':'unchanged'):'review',$row);
                $fighterId=(int)($row['matched_canonical_fighter_id']??0);
                if($fighterId){
                    $fighter=(new \MMAF\DataEngine\Repositories\FighterRepository())->find($fighterId);
                    if($fighter && ($fighter['is_public'] || ($fighter['is_rankable'] && $fighter['rankability_status']==='rankable'))){
                        $raw=$data['profiles'][(int)$row['profile_index']]??null;
                        if($raw && ($raw['status']??'')==='success'){
                            $career=(new CareerHistory())->process(\MMAF\DataEngine\Services\DataPipeline\ProfileStore::career_profile($raw,$fighterId),$id,(int)$job['created_by'],false);
                            $this->repo->putItem($id,'stored_career_profile',(string)$fighterId,$key,$career['coverage']['status']==='complete'?'unchanged':'review',$career);
                        }
                    }
                }
            }
        }else{
            foreach($data['items'] as $index=>$row){$source=Review::source((array)($row['context']??[]));$key=wp_json_encode(Review::stable($row));$this->repo->putItem($id,'scraper_review',$key,$source,'review',$row);}
        }
    }
    private function importBatch(array $job,array $batch): void {
        global $wpdb;$id=(int)$job['id'];$actor=(int)$job['approved_by'];$data=Package::payload($job,$batch);$kind=$batch['kind'];$summary=[];$run=0;
        if($kind==='ufc'){
            $summary=(new \MMAF\DataEngine\Services\UfcSigningService())->import_document($data,$actor,$id);
            $this->applied($id,'ufc_roster',(string)$data['run_id'],'review',$summary);
        }elseif($kind==='results'){
            $result=(new ScraperJsonImportService())->import_json_string(wp_json_encode($data),$actor);$summary=$result['summary'];
            if(empty($result['is_valid'])||!empty($summary['item_failures'])||($summary['status']??'')==='failed'){throw new \RuntimeException('An import batch reported failed records.');}
            $run=(int)$summary['import_run_id'];$items=CanonicalSchema::table_names()['source_import_items'];
            $logs=$wpdb->get_results($wpdb->prepare("SELECT * FROM $items WHERE import_run_id=%d ORDER BY id",$run),ARRAY_A);
            foreach($logs?:[] as $log){
                if($log['item_type']==='fighter' && !empty($log['canonical_id'])){\MMAF\DataEngine\Services\Operations\Membership::record($id,(int)$log['canonical_id'],'canonical_import_item');}
                if($log['status']==='failed'){throw new \RuntimeException('Record failed: '.($log['error_message']??''));}
                $key=(string)($log['source_id']?:$log['canonical_id']?:$log['identity_hash']?:$log['id']);
                $category=self::category($log['status']);$this->applied($id,$log['item_type'],$key,$category,$log);
                if($category==='review'){Review::upsert($id,'import_'.$log['item_type'].'_'.$log['action'],$key,['source_id'=>$key,'identity_hash'=>$log['identity_hash'],'reason'=>$log['action']],$actor);}
            }
        }elseif($kind==='profiles'){
            $store=new \MMAF\DataEngine\Services\DataPipeline\ProfileStore();$summary=['stored'=>0,'review'=>0,'profile_changes'=>[]];
            $bindings=isset($job['meta']['package']['priority_targets'])?\MMAF\DataEngine\Services\Priorities\Registry::validateManifest($job['meta']['package']['priority_targets']):[];
            foreach($data['profiles'] as $profile){
                $created=\MMAF\DataEngine\Services\Priorities\Profiles::apply($profile,$bindings,$actor,$id);
                if($created && $created['created']){$this->applied($id,'fighter',$profile['source_fighter_id'],'created',['canonical_id'=>$created['id'],'reason'=>'verified_priority_profile']);}
                $key=(string)(($profile['source_fighter_id']??'')?:($profile['source_url']??''));$saved=$store->put($profile,$data['schema_version'],$id);
                $changeKey=['created'=>'snapshot_new','updated'=>'snapshot_changed','review'=>'snapshot_review'][$saved['status']]??'snapshots_retained';
                $summary['profile_changes'][$changeKey]=($summary['profile_changes'][$changeKey]??0)+1;
                if(($profile['last_attempt']['status']??$profile['status']??'success')!=='success'){$summary['profile_changes']['last_attempt_failed']=($summary['profile_changes']['last_attempt_failed']??0)+1;}
                $category=$saved['status']==='review' || empty($saved['has_success'])?'review':(in_array($saved['status'],['created','updated'],true)?'updated':'unchanged');
                if($category==='review'){++$summary['review'];Review::upsert($id,'profile_snapshot',$key,['source_fighter_id'=>$key,'reason'=>$saved['reason']??'No successful source snapshot is available.'],$actor);}
                else{++$summary['stored'];}
                if(!empty($saved['fighter_id'])&&!empty($saved['has_success'])&&$category!=='review'){
					$fighter=(new \MMAF\DataEngine\Repositories\FighterRepository())->find((int)$saved['fighter_id']);$mode=$fighter?\MMAF\DataEngine\Services\TrackingService::mode($fighter):\MMAF\DataEngine\Services\TrackingService::NORMAL;
					$pausedDue=$mode===\MMAF\DataEngine\Services\TrackingService::PAUSED && (new \MMAF\DataEngine\Services\TrackingService())->recheck_due((int)$saved['fighter_id']);
					if($mode===\MMAF\DataEngine\Services\TrackingService::OPPONENT_ONLY || ($mode===\MMAF\DataEngine\Services\TrackingService::PAUSED && !$pausedDue)){
						$summary['tracking_profiles_skipped']=($summary['tracking_profiles_skipped']??0)+1;$this->applied($id,'profile',$key,'unchanged',['fighter_id'=>(int)$saved['fighter_id'],'tracking_mode'=>$mode,'reason'=>'current_wordpress_tracking_decision']);continue;
					}
                    $latest=$store->for_fighter((int)$saved['fighter_id']);
                    if($latest){
                        $payload=['schema_version'=>'tapology_fighter_profiles_v0_1','source'=>'tapology','profiles'=>[$latest['profile']],'profiles_total'=>1];
                        $result=(new FighterProfileEnrichmentApplyService())->apply_all_safe_json_string(wp_json_encode($payload),'Regular import '.$id,$actor,true);
                        if(!empty($result['summary']['profiles_failed'])){throw new \RuntimeException('Stored profile field update failed.');}
                        if(!empty($result['summary']['fields_applied_total'])){
                            $category='updated';$summary['profile_changes']['facts_profiles']=($summary['profile_changes']['facts_profiles']??0)+1;
                            $summary['profile_changes']['facts_fields']=($summary['profile_changes']['facts_fields']??0)+(int)$result['summary']['fields_applied_total'];
                        }
                        \MMAF\DataEngine\Services\DataPipeline\Processor::enqueue((int)$saved['fighter_id'],$actor,$id);
                    }
                }
                $this->applied($id,'profile',$key,$category,$saved);
            }
        }else{
            foreach($data['items'] as $row){Review::upsert($id,(string)($row['type']??'manual_review'),Review::source((array)($row['context']??[])),(array)($row['context']??[]),$actor);}
            $summary=['items_processed'=>count($data['items'])];
        }
        do_action('mmaf_regular_import_before_checkpoint',$id,$batch);
        $this->repo->batchUpdate((int)$batch['id'],['completed'=>1,'import_run_id'=>$run?:null,'summary'=>$summary,'completed_at'=>gmdate('Y-m-d H:i:s')]);
    }
    private function applied(int $job,string $kind,string $key,string $category,array $row): void {
        global $wpdb;$table=$this->repo->tables['items'];
        $existing=$wpdb->get_var($wpdb->prepare("SELECT category FROM $table WHERE job_id=%d AND kind=%s AND item_key=%s",$job,'actual_'.$kind,hash('sha256',$key)));
        if($existing==='created'||($existing==='updated'&&$category==='unchanged')){return;}
        $this->repo->putItem($job,'actual_'.$kind,$key,$key,$category,$row);
    }
    public function actualCounts(int $job): array {
        $counts=$this->repo->planCounts($job);$actual=[];
        foreach($counts as $kind=>$value){if(strpos($kind,'actual_')===0){$actual[substr($kind,7)]=$value;}}
        return $actual;
    }
    public function profileChanges(int $job,?array $batches=null): array {
        $changes=[];
        foreach($batches??$this->repo->batches($job) as $batch){if($batch['kind']==='profiles' && $batch['completed']){$summary=json_decode($batch['summary_json']??'{}',true);foreach($summary['profile_changes']??[] as $key=>$count){$changes[$key]=($changes[$key]??0)+(int)$count;}}}
        return $changes;
    }
    private function verifyFiles(array $job): void {
        foreach($job['meta']['package']['files'] as $name=>$info){$path=Storage::job((int)$job['id']).'/source/'.$name;if(!is_file($path)||!hash_equals($info['sha256'],hash_file('sha256',$path))){throw new ImportException('Package file changed after checking: '.$name,'checksum');}}
    }
    private function careerStep(array $job): void {
        global $wpdb; $id=(int)$job['id'];$meta=$job['meta'];$phase=$job['phase'];$career=new CareerHistory();
        if(in_array($phase,['check_career','career'],true)){
            $checking=$phase==='check_career';$batch=$this->repo->nextBatch($id,'career',$checking);
            if($batch){
                $data=Package::payload($job,$batch);$summary=[];
                foreach($data['profiles'] as $profile){
                    $result=$career->process($profile,$id,(int)($checking?$job['created_by']:$job['approved_by']),!$checking);
                    if(!$checking){
                        $raw=\MMAF\DataEngine\Services\DataPipeline\ProfileStore::from_career($profile);
                        (new \MMAF\DataEngine\Services\DataPipeline\ProfileStore())->put($raw,'mmaf_career_history_v1',$id);
                    }
                    $category=$result['coverage']['status']==='conflict'?'review':($result['coverage']['status']==='complete'?'unchanged':'review');
                    $this->repo->putItem($id,$checking?'career_profile':'actual_career_profile',(string)$profile['fighter_id'],$profile['source_fighter_id'],$category,$result);
                    foreach($result['items'] as $item){$kind=$checking?'career_bout':'actual_career_bout';$cat=in_array($item['category'],['conflict','review'],true)?'review':$item['category'];$this->repo->putItem($id,$kind,$item['key'],$item['key'],$cat,$item);}
                    $summary[$result['coverage']['status']]=($summary[$result['coverage']['status']]??0)+1;
                }
                if(!$checking){do_action('mmaf_regular_import_before_checkpoint',$id,$batch);}
                $this->repo->batchUpdate((int)$batch['id'],$checking?['checked'=>1]:['completed'=>1,'summary'=>$summary,'completed_at'=>gmdate('Y-m-d H:i:s')]);
                $this->repo->update($id,['status'=>'queued']);return;
            }
            if($checking){
                $revision=DatabaseState::revision();if(!hash_equals($meta['check_revision'],$revision)){$this->restartCheck($job,$revision);return;}
                $meta['plan']=$this->repo->planCounts($id);$meta['approval_token']=hash('sha256',$job['bundle_hash'].'|'.$revision.'|'.wp_json_encode($meta['plan']));
                $this->repo->update($id,['phase'=>'ready','status'=>'awaiting_approval','meta'=>$meta,'data_revision'=>$revision]);return;
            }
            $this->repo->update($id,['phase'=>'stats','status'=>'queued']);return;
        }
        if($phase==='stats'){
            $calculation=\MMAF\DataEngine\Services\Operations\Calculation::create('stats',0,(int)$job['approved_by'],'import:'.$id.':stats');
            $result=\MMAF\DataEngine\Services\Operations\Calculation::step($calculation);
            if($result['status']==='failed'){throw new ImportException('Statistics generation failed: '.$result['error_message'],'calculation');}
            if($result['status']==='completed'){$meta['stats']=$result['summary'];$this->repo->update($id,['meta'=>$meta,'phase'=>'audit','status'=>'queued']);}return;
        }
        if($phase==='audit'){
            // Later opponent perspectives can change a shared bout's final status.
            // Coverage is verified against the completed canonical import.
            foreach($this->repo->batches($id,'career') as $batch){
                foreach(Package::payload($job,$batch)['profiles'] as $profile){
                    $coverage=$career->refresh_coverage($profile,$id,(int)$job['approved_by']);
                    $previous=$wpdb->get_var($wpdb->prepare("SELECT payload_json FROM {$this->repo->tables['items']} WHERE job_id=%d AND kind='actual_career_profile' AND item_key=%s",$id,hash('sha256',(string)$profile['fighter_id'])));
                    $result=json_decode($previous,true);$result['coverage']=$coverage;
                    $this->repo->putItem($id,'actual_career_profile',(string)$profile['fighter_id'],$profile['source_fighter_id'],$coverage['status']==='complete'?'unchanged':'review',$result);
                }
            }
            $this->verifyFiles($job);$after=DatabaseState::integrity();
            if(DatabaseState::protectedState((int)$meta['protected_baseline']['max_fighter_id'])!==$meta['protected_baseline']){throw new ImportException('Protected publication/manual decisions changed during career import.','audit');}
            foreach(['duplicate_fighter_sources','duplicate_event_sources','duplicate_bout_sources','malformed_bouts','orphan_participants','bouts_without_event'] as $key){if($after[$key]>$meta['baseline'][$key]){throw new ImportException('Career integrity regression: '.$key,'audit');}}
            if($after['fighters_without_stats']!==0||$after['ranking_hash']!==$meta['baseline']['ranking_hash']){throw new ImportException('Career import changed public rankings or missed statistics.','audit');}
            $t=CanonicalSchema::table_names();$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->repo->tables['items']} WHERE job_id=%d AND kind='actual_career_profile'",$id));
            if($count!==(int)$meta['package']['counts']['career']){throw new ImportException('Not every career target has a verified processing outcome.','audit');}
            foreach($this->repo->batches($id) as $batch){if(!$batch['completed']){throw new ImportException('Career batch has no completed checkpoint.','audit');}}
            $newPublic=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['fighters']} WHERE id>%d AND (is_public<>0 OR is_rankable<>0)",(int)$meta['protected_baseline']['max_fighter_id']));if($newPublic){throw new ImportException('Historical opponents gained public/rankable status.','audit');}
            update_option('mmaf_history_data_scope','career_history_v1',false);
            $meta['coverage']=$wpdb->get_results($wpdb->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.coverage.status')) AS status,COUNT(*) AS total FROM {$this->repo->tables['items']} WHERE job_id=%d AND kind='actual_career_profile' GROUP BY status",$id),ARRAY_A);
            $meta['integrity']=$after;$meta['actual']=$this->actualCounts($id);$meta['completed_at']=gmdate('c');$status='completed';
            foreach($meta['coverage'] as $row){if($row['status']!=='complete'){$status='completed_with_review';}}
            $this->repo->update($id,['meta'=>$meta,'status'=>$status,'phase'=>'done','finished_at'=>gmdate('Y-m-d H:i:s')]);
            \MMAF\DataEngine\Services\DataPipeline\Processor::request_refresh((int)$job['approved_by'],false);
            Storage::write(Storage::job($id).'/report.json',['mode'=>'career_history','job_id'=>$id,'status'=>$status,'target_manifest'=>$meta['package']['headers']['career']['target_manifest'],'coverage'=>$meta['coverage'],'backup'=>$meta['backup'],'stats'=>$meta['stats'],'integrity'=>$after,'actual'=>$meta['actual']]);return;
        }
        throw new ImportException('Unknown career phase: '.$phase,'state');
    }
    private static function category(string $action): string {
        if(in_array($action,['create_candidate','create_provisional_candidate','create_provisional_with_url_only_identity','created'],true)){return 'created';}
        if(in_array($action,['update_candidate','updated'],true)){return 'updated';}
        if(in_array($action,['no_change_candidate','exact_source_match','exact_source_url_match','exact_source_url_hash_match','no_change'],true)){return 'unchanged';}
        if(in_array($action,['excluded_amateur','excluded_cancelled','excluded_overturned','skipped_non_scoring','skipped'],true)){return 'excluded';}
        return 'review';
    }
}
