<?php
require __DIR__.'/bootstrap.php';
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getLine().PHP_EOL);exit(1);});
use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Services\PublicReadService;
use MMAF\DataEngine\Services\RegularImport\Transaction;
use MMAF\DataEngine\Services\UfcSigningService;
use MMAF\DataEngine\Services\UfcMatcher;
function ufc_expect($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
global $wpdb;$t=Schema::table_names();
Transaction::begin();
try{
    $now=gmdate('Y-m-d H:i:s');$post=wp_insert_post(['post_type'=>'mmaf_fighter','post_status'=>'publish','post_title'=>'Synthetic UFC Review Fighter']);
    ufc_expect($post>0,'isolated fighter post fixture exists');
    $wpdb->insert($t['fighters'],['wp_post_id'=>$post,'display_name'=>'Synthetic UFC Review Fighter','normalized_name'=>'synthetic ufc review fighter','date_of_birth'=>'1995-01-02','gender'=>'male','weight_class'=>'lightweight','status'=>'verified','rankability_status'=>'rankable','is_public'=>1,'is_rankable'=>1,'created_at'=>$now]);$fighter_id=(int)$wpdb->insert_id;
    $wpdb->insert($t['ranking_runs'],['formula_version'=>'v1.6','formula_config_snapshot'=>'{}','reference_date'=>gmdate('Y-m-d'),'calculated_at'=>$now,'status'=>'active','is_active'=>1,'created_at'=>$now]);$run=(int)$wpdb->insert_id;
    $rank=['ranking_run_id'=>$run,'board_key'=>'overall','fighter_id'=>$fighter_id,'rank_position'=>1,'total_score'=>80,'raw_score'=>80,'normalized_score'=>80,'confidence_score'=>80,'sample_size'=>5,'created_at'=>$now];
    if(false===$wpdb->insert($t['ranking_snapshots'],$rank)){throw new RuntimeException('Fixture snapshot failed: '.$wpdb->last_error);}(new MMAF\DataEngine\Repositories\RankingRunRepository())->set_active_ranking_run_id($run);
    $fighter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighters']} WHERE id=%d",$fighter_id),ARRAY_A);
$service=new UfcSigningService();$public=new PublicReadService();
ufc_expect(UfcSigningService::normalize_name(' Željko--Petrović ')==='zeljko petrovic','name normalization handles diacritics and punctuation');
$index=UfcMatcher::build_index([['id'=>1,'display_name'=>'Same Name','date_of_birth'=>'2000-01-01'],['id'=>2,'display_name'=>'Other Name','date_of_birth'=>'1999-01-01']],[['fighter_id'=>2,'alias'=>'Same Name']]);
$ambiguous=UfcMatcher::match(['source_key'=>'integration/ambiguous','display_name'=>'Same Name','date_of_birth'=>'2000-01-01'],$index);
ufc_expect(count($ambiguous)===2 && $ambiguous[0]['reason']==='name_and_date_of_birth' && count($ambiguous[1]['conflicts'])===2,'same-name and DOB conflicts stay visible for review');
    $row=['source_key'=>'integration/ufc/'.wp_generate_uuid4(),'display_name'=>$fighter['display_name'],'source_url'=>'https://www.roster.watch/fighters/integration-test','date_of_birth'=>$fighter['date_of_birth'],'gender'=>$fighter['gender'],'weight_class'=>$fighter['weight_class'],'roster_status'=>'current','events'=>[['date'=>'2026-10-20','action'=>'added','text'=>'added']]];
    $former=['source_key'=>'integration/former/'.wp_generate_uuid4(),'display_name'=>'Unmatched Former Integration Fighter','source_url'=>'https://www.roster.watch/fighters/former-integration-test','date_of_birth'=>null,'gender'=>null,'weight_class'=>null,'roster_status'=>'former','events'=>[]];
    $doc=['schema_version'=>'mmaf_ufc_roster_v1','source'=>'roster.watch','run_id'=>'integration-'.wp_generate_uuid4(),'scraped_at'=>'2026-10-20T10:00:00Z','source_urls'=>['current'=>'https://www.roster.watch/','former'=>'https://www.roster.watch/former.html','log'=>'https://www.roster.watch/log.html'],'counts'=>['current'=>1,'former'=>1,'log_events'=>1,'fighters'=>2],'fighters'=>[$row,$former]];
    $doc['content_sha256']=hash('sha256',wp_json_encode($doc,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $first=$service->import_document($doc,1,9001);$second=$service->import_document($doc,1,9002);
    ufc_expect($first['candidates_created']===1 && $second['candidates_created']===0,'repeated roster import is idempotent');
    $candidate=$wpdb->get_row($wpdb->prepare("SELECT c.* FROM {$t['ufc_signing_candidates']} c JOIN {$t['ufc_roster_observations']} o ON o.id=c.observation_id WHERE o.source_key=%s AND c.fighter_id=%d",$row['source_key'],$fighter['id']),ARRAY_A);
    ufc_expect($candidate && $candidate['match_reason']==='name_and_date_of_birth','exact name and DOB create a strong candidate');
    $service->resolve_candidate((int)$candidate['id'],'confirm',1,'Integration test reviewed roster evidence',(string)$candidate['evidence_hash'],(string)$candidate['fighter_hash']);
    do_action('mmaf_public_data_changed','ufc_signing_test'); // Outer test transaction delays the normal after-commit callback.
    $stored=$wpdb->get_row($wpdb->prepare("SELECT in_ufc,tracking_mode,is_public,is_rankable FROM {$t['fighters']} WHERE id=%d",$fighter['id']),ARRAY_A);
    ufc_expect((int)$stored['in_ufc']===1 && $stored['tracking_mode']==='opponent_only' && !(int)$stored['is_public'] && !(int)$stored['is_rankable'] && $service->signing((int)$fighter['id']),'confirmation records a signing and archives the fighter as opponent-only');
    $rankings=$public->rankings(['board'=>'overall','q'=>$fighter['display_name'],'per_page'=>100]);
    ufc_expect(!$rankings['items'],'confirmed signer is immediately absent from cached-snapshot rankings');
    $all=$public->rankings(['board'=>'overall','per_page'=>100]);$expected_top=min(15,(int)$all['total']);
    $top=$public->rankings(['board'=>'overall','top15'=>1,'per_page'=>100]);$top_ranks=array_column($top['items'],'rank');
    ufc_expect($top['total']===$expected_top && $top_ranks===($expected_top?range(1,$expected_top):[]),'Top 15 is filled and renumbered after UFC exclusion');
    $top_page_two=$public->rankings(['board'=>'overall','top15'=>1,'page'=>2,'per_page'=>10]);
    $expected_page_two=$expected_top>10?range(11,$expected_top):[];
    ufc_expect($top_page_two['total']===$expected_top && array_column($top_page_two['items'],'rank')===$expected_page_two,'Top 15 pagination is applied after UFC exclusion');
    $profile=$public->fighter((int)$fighter['id']);
    ufc_expect(is_wp_error($profile),'an archived UFC signer has no direct public profile');
    $cards=$public->fighter_cards([(int)$fighter['wp_post_id']]);
    ufc_expect(empty($cards),'archived fighter cards are removed from public listings');
    $run_row=(new MMAF\DataEngine\Repositories\RankingRunRepository())->find($run);
    $validator=new ReflectionMethod(MMAF\DataEngine\Services\RankingActivationService::class,'validate_snapshots');$validator->setAccessible(true);$blocked=false;
    try{$validator->invoke(new MMAF\DataEngine\Services\RankingActivationService(),$run,(string)$run_row['formula_version']);}catch(RuntimeException $error){$blocked=str_contains($error->getMessage(),'confirmed UFC signing');}
    ufc_expect($blocked,'a stale draft containing the confirmed signer cannot be activated');
    $service->correct((int)$fighter['id'],1,'Integration-test correction');
    $events=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ufc_signing_events']} WHERE fighter_id=%d",$fighter['id']));
    $candidate_status=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$t['ufc_signing_candidates']} WHERE id=%d",$candidate['id']));
    $corrected=$wpdb->get_row($wpdb->prepare("SELECT in_ufc,tracking_mode,tracking_reason,is_public,is_rankable FROM {$t['fighters']} WHERE id=%d",$fighter['id']),ARRAY_A);
    ufc_expect(!$service->signing((int)$fighter['id']) && $events>=2 && $candidate_status==='rejected' && !(int)$corrected['in_ufc'] && $corrected['tracking_mode']==='opponent_only' && $corrected['tracking_reason']==='ufc_confirmation_corrected_pending_review' && !(int)$corrected['is_public'] && !(int)$corrected['is_rankable'],'correction preserves history and exclusion until an administrator separately restores tracking');
    $other=['source_key'=>'integration/decision/'.wp_generate_uuid4(),'display_name'=>$fighter['display_name'],'source_url'=>'https://www.roster.watch/fighters/decision-test','date_of_birth'=>$fighter['date_of_birth'],'gender'=>$fighter['gender'],'weight_class'=>$fighter['weight_class'],'roster_status'=>'current','events'=>[['date'=>gmdate('Y-m-d'),'action'=>'added','text'=>'Fighter added','source_url'=>'https://www.roster.watch/log.html']]];
    $fallback=['source_key'=>'integration/fallback/'.wp_generate_uuid4(),'display_name'=>'Unmatched Former Decision Test','source_url'=>'https://www.roster.watch/former.html','date_of_birth'=>null,'gender'=>null,'weight_class'=>null,'roster_status'=>'former','events'=>[]];
    $make_doc=static function(array $source,array $former): array {$doc=['schema_version'=>'mmaf_ufc_roster_v2','source'=>'roster.watch','run_id'=>'integration-'.wp_generate_uuid4(),'scraped_at'=>gmdate('c'),'source_urls'=>['current'=>'https://www.roster.watch/','former'=>'https://www.roster.watch/former.html','log'=>'https://www.roster.watch/log.html'],'counts'=>['current'=>1,'former'=>1,'log_events'=>1,'fighters'=>2],'fighters'=>[$source,$former]];$doc['content_sha256']=hash('sha256',wp_json_encode($doc,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return $doc;};
    $second_doc=$make_doc($other,$fallback);$service->import_document($second_doc,1,9003);
    $second=$wpdb->get_row($wpdb->prepare("SELECT c.* FROM {$t['ufc_signing_candidates']} c JOIN {$t['ufc_roster_observations']} o ON o.id=c.observation_id WHERE o.source_key=%s AND c.fighter_id=%d",$other['source_key'],$fighter['id']),ARRAY_A);
    $service->resolve_candidate((int)$second['id'],'reject',1,'Different athlete despite same name',$second['evidence_hash'],$second['fighter_hash']);
    $service->import_document($second_doc,1,9004);$other['events'][0]['text']='Fighter added with updated evidence';$service->import_document($make_doc($other,$fallback),1,9005);
    $rejected=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$t['ufc_signing_candidates']} WHERE id=%d",$second['id']));ufc_expect($rejected==='rejected','different-identity decision survives repeated and changed evidence');
    $service->reopen_candidate((int)$second['id'],1,'Source identity correction');$reopened=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$t['ufc_signing_candidates']} WHERE id=%d",$second['id']));ufc_expect($reopened==='pending','negative source link can be explicitly corrected');
    $third=$other;$third['source_key']='integration/insufficient/'.wp_generate_uuid4();$service->import_document($make_doc($third,$fallback),1,9006);
    $third_candidate=$wpdb->get_row($wpdb->prepare("SELECT c.* FROM {$t['ufc_signing_candidates']} c JOIN {$t['ufc_roster_observations']} o ON o.id=c.observation_id WHERE o.source_key=%s AND c.fighter_id=%d",$third['source_key'],$fighter['id']),ARRAY_A);
    $service->resolve_candidate((int)$third_candidate['id'],'insufficient_evidence',1,'Same athlete, contract evidence insufficient',$third_candidate['evidence_hash'],$third_candidate['fighter_hash']);
    $service->import_document($make_doc($third,$fallback),1,9007);$still_closed=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$t['ufc_signing_candidates']} WHERE id=%d",$third_candidate['id']));ufc_expect($still_closed==='insufficient_evidence','fetch time alone does not reopen insufficient evidence');
    $third['events'][0]['text']='New official roster addition detail';$service->import_document($make_doc($third,$fallback),1,9008);$now_open=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['ufc_signing_candidates']} WHERE id=%d",$third_candidate['id']),ARRAY_A);ufc_expect($now_open['status']==='pending','materially new evidence reopens insufficient-evidence decision');
    $wpdb->insert($t['fighter_aliases'],['fighter_id'=>$fighter['id'],'alias'=>'New identity alias','normalized_alias'=>'new identity alias','created_at'=>gmdate('Y-m-d H:i:s')]);$stale_blocked=false;
    try{$service->resolve_candidate((int)$third_candidate['id'],'defer',1,'Review after identity change',$now_open['evidence_hash'],$now_open['fighter_hash']);}catch(RuntimeException $error){$stale_blocked=str_contains($error->getMessage(),'identity changed');}
    ufc_expect($stale_blocked,'stale review screen cannot submit after canonical identity changes');
    $service->enqueue_compare('integration');$work=$service->work_compare();ufc_expect($work['worked'] && $work['done'],'saved-roster rematch runs without network after alias change');
    $fresh=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['ufc_signing_candidates']} WHERE id=%d",$third_candidate['id']),ARRAY_A);ufc_expect($fresh['fighter_hash']!==$now_open['fighter_hash'],'rematch refreshes identity version');
    $now=gmdate('Y-m-d H:i:s');for($i=0;$i<505;$i++){$key='integration/bulk/'.$i.'/'.$fighter['id'];$source=['source_key'=>$key,'display_name'=>$fighter['display_name'],'source_url'=>'https://www.roster.watch/','date_of_birth'=>$fighter['date_of_birth'],'roster_status'=>'log_only','events'=>[]];$wpdb->insert($t['ufc_roster_observations'],['source_key'=>$key,'display_name'=>$fighter['display_name'],'normalized_name'=>UfcSigningService::normalize_name($fighter['display_name']),'source_url'=>$source['source_url'],'roster_status'=>'log_only','events_json'=>'[]','evidence_json'=>wp_json_encode($source),'content_hash'=>hash('sha256',wp_json_encode($source)),'last_run_id'=>'integration-bulk','observed_at'=>$now,'created_at'=>$now]);$observation=(int)$wpdb->insert_id;$wpdb->insert($t['ufc_signing_candidates'],['observation_id'=>$observation,'fighter_id'=>$fighter['id'],'status'=>'pending','match_reason'=>'canonical_name','conflicts_json'=>'[]','evidence_hash'=>UfcSigningService::evidence_hash($source),'fighter_hash'=>$fresh['fighter_hash'],'matcher_version'=>MMAF\DataEngine\Services\UfcMatcher::VERSION,'created_at'=>$now,'updated_at'=>$now]);}
    $listing=$service->candidate_page('pending','',1);$last_page=$service->candidate_page('pending','',11);ufc_expect($listing['total']>=505 && count($listing['rows'])===50 && count($last_page['rows'])>0,'more than 500 candidates retain real totals and 50-row pagination');
    $service->enqueue_compare('integration_bulk');$steps=0;$processed=0;do{$step=$service->work_compare();$processed+=$step['processed']??0;++$steps;}while(!empty($step['worked']) && empty($step['done']) && $steps<20);ufc_expect($steps>=6 && !empty($step['done']) && $processed>=505,'comparison checkpoints every 100 observations and resumes to completion');
}finally{Transaction::rollback();}
echo "UFC signing integration checks completed.\n";
