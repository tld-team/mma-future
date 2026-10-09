<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/pipeline-tests/LegacyStatsRebuildService.php';
require __DIR__.'/pipeline-tests/LegacyRankingCalculatorService.php';
use MMAF\DataEngine\Services\DataPipeline\{ProfileStore,Processor,Schema as PipelineSchema};
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard,CareerHistory};
use MMAF\DataEngine\Services\{StatsRebuildService,LegacyStatsRebuildService,RankingCalculatorService,LegacyRankingCalculatorService,FighterReadinessService,FieldProvenanceService};
use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\{FighterRepository,FighterSourceRepository,RankingRunRepository};
function check($ok,$reason){if(!$ok){throw new RuntimeException($reason);}echo "PASS: $reason\n";}
function stats_content($table){global $wpdb;return $wpdb->get_results("SELECT fighter_id,wins,losses,draws,nc,pro_fights_count,ko_tko_wins,submission_wins,decision_wins,finish_wins,finish_rate,last_fight_date,streak,recent_form,activity_status,warnings_json FROM $table ORDER BY fighter_id",ARRAY_A);}
function ranking_content($table,$run){global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT board_key,fighter_id,rank_position,total_score,raw_score,sample_size,quality_flags_json,breakdown_json,eligibility_json,warnings_json FROM $table WHERE ranking_run_id=%d ORDER BY board_key,rank_position",$run),ARRAY_A);}
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
check(DB_NAME==='mmaf_frontend_dev','isolated dev clone is required');wp_set_current_user(1);
$t=Schema::table_names();$p=PipelineSchema::tables();$store=new ProfileStore();$now=gmdate('Y-m-d H:i:s');
$active=(new RankingRunRepository())->get_active_ranking_run_id();
$rank_hash=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)));
// Any accidental fetch during preparation fails the test immediately.
add_filter('pre_http_request',static function(){throw new RuntimeException('Preparation attempted a network request.');});
WriteGuard::$worker=true;Transaction::begin();
try {
 $start=microtime(true);memory_reset_peak_usage();$legacy=(new LegacyStatsRebuildService())->rebuild_all();$old_stats=stats_content($t['fighter_stats_current']);$old_peak=memory_get_peak_usage(true);$old_time=microtime(true)-$start;
 $start=microtime(true);memory_reset_peak_usage();$new=(new StatsRebuildService())->rebuild_all();$new_peak=memory_get_peak_usage(true);$new_time=microtime(true)-$start;
 check($old_stats===stats_content($t['fighter_stats_current']),'batched statistics exactly match previous records, rates, form, streaks and warnings');
 foreach(['countable_bouts','skipped_bouts','malformed_bouts','participants_processed','warnings_count','warnings'] as $key){check($legacy[$key]===$new[$key],'statistics diagnostic parity: '.$key);}
 $old_run=(new LegacyRankingCalculatorService())->calculate_draft(1,'2026-10-09');
 $new_run=(new RankingCalculatorService())->calculate_draft(1,'2026-10-09');
 check(ranking_content($t['ranking_snapshots'],$old_run['ranking_run_id'])===ranking_content($t['ranking_snapshots'],$new_run['ranking_run_id']),'batched v1.5 calculation exactly matches previous ranks, scores, breakdowns and eligibility');
 unset($old_stats);
 $invalidPair=$wpdb->get_results("SELECT p.id,p.result_for_fighter FROM {$t['bout_participants']} p WHERE p.bout_id=(SELECT b.id FROM {$t['bouts']} b WHERE b.deleted_soft=0 AND b.status='valid' AND (SELECT COUNT(*) FROM {$t['bout_participants']} p2 WHERE p2.bout_id=b.id AND p2.result_for_fighter IN ('win','loss','draw','no_contest'))=2 LIMIT 1) ORDER BY p.id",ARRAY_A);
 foreach ([1,2] as $invalidCount){$wpdb->update($t['bout_participants'],['result_for_fighter'=>'unknown'],['id'=>$invalidPair[$invalidCount-1]['id']]);(new LegacyStatsRebuildService())->rebuild_all();$expectedStats=stats_content($t['fighter_stats_current']);(new StatsRebuildService())->rebuild_all();check($expectedStats===stats_content($t['fighter_stats_current']),'invalid participant result diagnostic parity: '.$invalidCount);}
 foreach($invalidPair as $participant){$wpdb->update($t['bout_participants'],['result_for_fighter'=>$participant['result_for_fighter']],['id'=>$participant['id']]);}unset($expectedStats);
 $f=new FighterRepository();$s=new FighterSourceRepository();$fixture=[];
 foreach([1,2] as $n){$source='tapology_fighter_94990010'.$n;$url='https://www.tapology.com/fightcenter/fighters/94990010'.$n.'-pipeline';$id=$f->insert(['display_name'=>'Pipeline fixture '.$n,'gender'=>'male','date_of_birth'=>'2000-01-01','weight_class'=>'lightweight','status'=>'provisional','created_at'=>$now]);$s->upsert_for_fighter($id,['source_type'=>'tapology','source_fighter_id'=>$source,'source_url'=>$url,'identity_hash'=>hash('sha256',$url),'is_primary'=>1,'created_at'=>$now]);$fixture[$n]=['id'=>$id,'source'=>$source,'url'=>$url];}
 $a=$fixture[1];$b=$fixture[2];
 $fight=['source_bout_id'=>'tapology_bout_949900301','bout_url'=>'https://www.tapology.com/fightcenter/bouts/949900301-pipeline','source_event_id'=>'tapology_event_949900401','event_url'=>'https://www.tapology.com/fightcenter/events/949900401-pipeline','event_name'=>'Pipeline fixture event','fight_date'=>'2026-09-01','opponent_source_id'=>$b['source'],'opponent_url'=>$b['url'],'opponent_name'=>'Pipeline fixture 2','discipline'=>'mma','source_sport'=>'mma','source_division'=>'pro','classification_evidence'=>'profile_dom_classification','is_professional'=>true,'result'=>'win','method_category'=>'decision'];
 $raw=['source_fighter_id'=>$a['source'],'source_url'=>$a['url'],'display_name'=>'Pipeline fixture 1','status'=>'success','scraped_at'=>'2026-10-08T10:00:00Z','profile_summary'=>['nickname'=>'Initial name','weight_class'=>'Lightweight'],'record_summary'=>['wins'=>2,'losses'=>0,'draws'=>0,'no_contests'=>0],'history_scope'=>'professional_mma','history_scope_verified'=>true,'history_exhausted'=>true,'fight_history'=>[$fight,array_merge($fight,['source_bout_id'=>'tapology_bout_949900302','bout_url'=>'https://www.tapology.com/fightcenter/bouts/949900302-pipeline','source_event_id'=>'tapology_event_949900402','event_url'=>'https://www.tapology.com/fightcenter/events/949900402-pipeline','fight_date'=>'2026-10-05'])]];
 $before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}");$saved=$store->put($raw,'tapology_fighter_profiles_v0_1');
 check($saved['status']==='created' && $store->for_fighter($a['id'])['profile']===$raw,'full successful profile persists for a provisional fighter');
 check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}")===$before,'storing an unapproved profile does not import its career or publish it');
 $older=$raw;$older['scraped_at']='2026-09-01T10:00:00Z';$older['profile_summary']['nickname']='Old name';check($store->put($older,'tapology_fighter_profiles_v0_1')['status']==='older_ignored','old package cannot replace a newer successful snapshot');
 $failed=$raw;$failed['status']='failed_parse';$failed['scraped_at']='2026-10-08T12:00:00Z';$failed['error_reason']='fixture source error';$store->put($failed,'tapology_fighter_profiles_v0_1');
 check($store->for_fighter($a['id'])['profile']['status']==='success' && $store->for_fighter($a['id'])['last_attempt_status']==='failed_parse','failed fetch retains last successful content and records the failed attempt separately');
 $same=$raw;$same['profile_summary']['nickname']='Conflict';check($store->put($same,'tapology_fighter_profiles_v0_1')['status']==='review','same timestamp with contradictory content requires review');
 $bad=$raw;$bad['source_fighter_id']=$b['source'];check($store->put($bad,'tapology_fighter_profiles_v0_1')['status']==='review','source ID/URL conflict cannot alter a saved profile');
 // Seed one canonical bout as the regular event import already would, then approve using stored profile history.
 $f->update($a['id'],['is_public'=>1]);$seed=ProfileStore::career_profile($raw,$a['id']);$seed['rows']=[$fight];(new CareerHistory())->process($seed,0,1,true);$f->update($a['id'],['is_public'=>0]);
 (new StatsRebuildService())->rebuild_all();
 $approval=(new FighterReadinessService())->process_bulk_action('approve_and_prepare',[$a['id']],1);
 check($approval['updated_count']===1,'one approval marks a ready candidate verified, public and rankable');
 $post=(int)$f->find($a['id'])['wp_post_id'];check(get_post_status($post)==='publish' && get_post_type($post)==='mmaf_fighter','bulk approval creates a valid published canonical profile');
 check(Processor::pending()===1,'approval queues stored preparation without fetching a profile');
 $interruption=static function(){throw new RuntimeException('Interrupted before preparation checkpoint');};add_action('mmaf_profile_preparation_before_checkpoint',$interruption);
 $beforeRestart=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}");(new Processor())->work(1);
 check(Processor::pending()===1 && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}")===$beforeRestart,'interrupted preparation rolls back career writes and leaves a resumable queue item');
 remove_action('mmaf_profile_preparation_before_checkpoint',$interruption);
 $result=(new Processor())->work(1);
 check(empty($result['error']) && Processor::pending()===0,'stored preparation and the coalesced calculation finish');
 $coverage=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighter_career_coverage']} WHERE fighter_id=%d",$a['id']),ARRAY_A);
 check($coverage['status']==='complete' && (int)$coverage['accepted_bouts_count']===2,'approved fighter gains complete canonical history from the saved JSON');
 $sharedParticipants=$wpdb->get_results("SELECT p.id,p.result_for_fighter FROM {$t['bout_participants']} p JOIN {$t['bout_sources']} s ON s.bout_id=p.bout_id WHERE s.source_bout_id='tapology_bout_949900302'",ARRAY_A);
 foreach($sharedParticipants as $participant){$wpdb->update($t['bout_participants'],['result_for_fighter'=>'no_contest'],['id'=>$participant['id']]);}(new StatsRebuildService())->rebuild_all();
 check($wpdb->get_var($wpdb->prepare("SELECT status FROM {$t['fighter_career_coverage']} WHERE fighter_id=%d",$a['id']))==='partial','shared final-result correction invalidates a stale complete-career claim');
 foreach($sharedParticipants as $participant){$wpdb->update($t['bout_participants'],['result_for_fighter'=>$participant['result_for_fighter']],['id'=>$participant['id']]);}(new StatsRebuildService())->rebuild_all();
 $coverage=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighter_career_coverage']} WHERE fighter_id=%d",$a['id']),ARRAY_A);
 check($coverage['status']==='complete','verified completeness recovers when canonical W/L/D/NC again match the source');
 $staleCareer=ProfileStore::career_profile($raw,$a['id']);$staleCareer['scraped_at']='2026-09-01T10:00:00Z';$staleCareer['rows']=[];$staleResult=(new CareerHistory())->process($staleCareer,0,1,true);check(!empty($staleResult['retained_newer']) && $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighter_career_coverage']} WHERE fighter_id=%d",$a['id']),ARRAY_A)===$coverage,'an older career package cannot downgrade newer verified coverage');
 $prepared=Processor::status()['calculation'];check(!empty($prepared['draft']['ranking_run_id']) && !$prepared['pending'],'one completed ranking draft is recorded');
 $run_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}");$bout_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}");
 Processor::enqueue($a['id'],1);(new Processor())->work();
 check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}")===$run_count && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}")===$bout_count,'repeat preparation creates neither another draft nor duplicate bouts');
 $latest=$raw;$latest['scraped_at']='2026-10-08T13:00:00Z';$latest['profile_summary']['nickname']='Latest nickname';$store->put($latest,'tapology_fighter_profiles_v0_1');Processor::enqueue($a['id'],1);Processor::request_refresh(1);(new Processor())->work();
 check($f->find($a['id'])['nickname']==='Latest nickname','newer source-owned evolving facts refresh automatically');
 (new FieldProvenanceService())->upsert('fighter',$a['id'],'nickname','Latest nickname',1);
 $latest['scraped_at']='2026-10-08T14:00:00Z';$latest['profile_summary']['nickname']='Unwanted replacement';$store->put($latest,'tapology_fighter_profiles_v0_1');Processor::enqueue($a['id'],1);Processor::request_refresh(1);(new Processor())->work();
 check($f->find($a['id'])['nickname']==='Latest nickname','manual verification remains protected from new source values');
 $hidden=(new FighterReadinessService())->process_bulk_action('mark_not_public',[$a['id']],1);clean_post_cache($post);check($hidden['updated_count']===1 && get_post_status($post)==='draft','bulk hiding synchronizes the public profile immediately');
 check((new RankingRunRepository())->get_active_ranking_run_id()===$active && hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)))===$rank_hash,'all imports, approvals and draft calculations leave active rankings unchanged');
 $seedFile=tempnam(sys_get_temp_dir(),'mmaf-source-seed-');
 try {
  $seedProfiles=[];for($n=1;$n<=101;$n++){$seedProfiles[]=['source_fighter_id'=>'tapology_fighter_948880'.sprintf('%03d',$n),'source_url'=>'https://www.tapology.com/fightcenter/fighters/948880'.sprintf('%03d',$n).'-seed','status'=>'success','scraped_at'=>'2026-10-08T10:00:00Z','fight_history'=>[]];}
  file_put_contents($seedFile,wp_json_encode(['schema_version'=>'tapology_fighter_profiles_v0_1','source'=>'tapology','profiles_total'=>101,'profiles'=>$seedProfiles]));
  $backfill=new \MMAF\DataEngine\Services\DataPipeline\Backfill();
  $stopSeed=static function($state){if($state['processed']>100){throw new RuntimeException('Interrupted second seed batch');}};
  add_action('mmaf_profile_seed_before_checkpoint',$stopSeed);
  try{$backfill->run($seedFile);throw new RuntimeException('Seed did not interrupt');}catch(RuntimeException $e){check($e->getMessage()==='Interrupted second seed batch','seed interruption occurs after an atomic first batch: '.$e->getMessage());}
  remove_action('mmaf_profile_seed_before_checkpoint',$stopSeed);
  $seedResult=$backfill->run($seedFile);check($seedResult['processed']===101 && $seedResult['counts']['created']===101 && $seedResult['completed'],'seed resumes from its committed checkpoint without duplicating profiles');
  check($backfill->run($seedFile)===$seedResult,'repeated source backfill is idempotent');
  $invalidIdentity=$seedProfiles[0];$invalidIdentity['source_fighter_id']='tapology_fighter_1111111';file_put_contents($seedFile,wp_json_encode(['schema_version'=>'tapology_fighter_profiles_v0_1','source'=>'tapology','profiles'=>[$invalidIdentity]]));$reviewCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['review_items']}");$reviewSeed=$backfill->run($seedFile);check($reviewSeed['counts']['review']===1 && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['review_items']}")>$reviewCount,'source identity conflicts are retained in the administrator review queue');
  file_put_contents($seedFile,'{"schema_version":"tapology_fighter_profiles_v0_1","source":"tapology","profiles":[{}]} trailing');
  $profileCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p['profiles']}");
  try{$backfill->run($seedFile);throw new RuntimeException('Invalid source accepted');}catch(Throwable $e){check($e->getMessage()!=='Invalid source accepted' && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p['profiles']}")===$profileCount,'invalid export is fully rejected before source writes');}
 }finally{unlink($seedFile);}
 file_put_contents(__DIR__.'/pipeline-verification.json',wp_json_encode(['stats_parity'=>true,'ranking_parity'=>true,'counts'=>['fighters'=>$new['fighters_total'],'bouts'=>$new['countable_bouts']],'legacy_stats'=>['seconds'=>$old_time,'peak_bytes'=>$old_peak],'batched_stats'=>['seconds'=>$new_time,'peak_bytes'=>$new_peak],'network_requests'=>0,'active_rankings_unchanged'=>true],JSON_PRETTY_PRINT));
} finally {Transaction::rollback();WriteGuard::$worker=false;}
check(!$store->for_fighter($fixture[1]['id']),'fixture snapshots and jobs roll back with canonical data');
