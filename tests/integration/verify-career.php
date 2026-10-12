<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);});
use MMAF\DataEngine\Services\RegularImport\CareerHistory;
use MMAF\DataEngine\Services\RegularImport\Transaction;
use MMAF\DataEngine\Services\RegularImport\WriteGuard;
use MMAF\DataEngine\Repositories\BoutParticipantRepository;
use MMAF\DataEngine\Repositories\BoutSourceRepository;
use MMAF\DataEngine\Services\FieldProvenanceService;
use MMAF\DataEngine\Migrations\Schema;
wp_set_current_user(1);
\MMAF\DataEngine\Migrations\MigrationRunner::run();
$t=Schema::table_names();$service=new CareerHistory();
function check($ok,$why){if(!$ok){throw new RuntimeException($why);}echo "PASS: $why\n";}
function row($number,$opponent,$result='win',$extra=[]){return array_merge(['source_bout_id'=>'tapology_bout_'.$number,'bout_url'=>'https://www.tapology.com/fightcenter/bouts/'.$number.'-fixture','source_event_id'=>'tapology_event_901234568','event_url'=>'https://www.tapology.com/fightcenter/events/901234568-fixture','event_name'=>'Isolated career fixture','fight_date'=>'2020-01-01','opponent_source_id'=>$opponent['source_fighter_id'],'opponent_url'=>$opponent['source_url'],'opponent_name'=>$opponent['display_name'],'discipline'=>'mma','is_professional'=>true,'source_division'=>'pro','result'=>$result,'method_category'=>'decision'], $extra);}
function bout($number){return (new BoutSourceRepository())->find_by_source('tapology','tapology_bout_'.$number);}
function tables_hash($t){global $wpdb; $out=[];foreach(['fighters','events','bouts','bout_participants'] as $key){$out[$key]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t[$key]}");}return $out;}
$baseline=tables_hash($t);
WriteGuard::$worker=true;Transaction::begin();
try{
 $a=fixture_fighter(901234560,'Synthetic career A',['is_public'=>1]);$b=fixture_fighter(901234561,'Synthetic career B');
 $a+=['scraped_at'=>'2026-10-10T10:00:00Z','source_status'=>'success','expected_record'=>['wins'=>1,'losses'=>0,'draws'=>0,'nc'=>0],'history_scope'=>'professional_mma','history_scope_verified'=>true,'history_exhausted'=>true];
 $b+=['scraped_at'=>'2026-10-10T10:00:00Z','source_status'=>'success','expected_record'=>['wins'=>0,'losses'=>1,'draws'=>0,'nc'=>0],'history_scope'=>'professional_mma','history_scope_verified'=>true,'history_exhausted'=>true];
 // Both perspectives require explicit approved scope; the linked third-party opponents do not.
 (new \MMAF\DataEngine\Repositories\FighterRepository())->update($b['fighter_id'],['is_public'=>1]);
 $before=tables_hash($t);$a['rows']=[row(901234570,$b)];$b['rows']=[row(901234570,$a,'loss')];
 $service->process($a,0,1,false);check(tables_hash($t)===$before,'dry-run leaves canonical tables unchanged');
 $a['verified_facts']=['coach_name'=>['label'=>'Head Coach','raw_value'=>'Isolated coach','value'=>'Isolated coach','evidence'=>'explicit_profile_label']];$r=$service->process($a,0,1,true);check($r['facts']['changes']['coach_name']==='Isolated coach','explicit source fact fills an empty canonical field');check($r['items'][0]['category']==='created','confirmed bout is created');$id=(int)bout(901234570)['bout_id'];
 $participants=(new BoutParticipantRepository())->list_by_bout($id);check(count($participants)===2&&$participants[0]['prefight_wins']===null&&$participants[1]['prefight_wins']===null,'unknown pre-fight records remain null');
 (new FieldProvenanceService())->upsert('fighter',(int)$a['fighter_id'],'coach_name','Isolated coach',1);$a['verified_facts']['coach_name']['value']='Different source coach';$fact_check=$service->process($a,0,1,false);check(empty($fact_check['facts']['changes'])&&$fact_check['facts']['skipped']['coach_name']==='manual_protection','manual canonical fact is protected');
 $once=tables_hash($t);$service->process($b,0,1,true);$service->process($a,0,1,true);check(tables_hash($t)===$once,'both profile perspectives and repeated import produce one canonical bout');
 $a['rows']=[row(901234571,$b,'draw'),row(901234572,$b,'no_contest',['is_overturned'=>true,'final_result_verified'=>true])];$r=$service->process($a,0,1,true);check(count(array_filter($r['items'],fn($i)=>$i['category']==='created'))===2,'draw and confirmed overturned NC are retained');
 check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']} WHERE event_id=(SELECT event_id FROM {$t['bouts']} WHERE id=$id)")===3,'partial historical event preserves other bouts');
 $a['rows']=[row(901234570,$b,'no_contest',['is_overturned'=>true,'final_result_verified'=>true])];$service->process($a,0,1,true);$participants=(new BoutParticipantRepository())->list_by_bout($id);check(array_unique(array_column($participants,'result_for_fighter'))===['no_contest'],'verified final NC updates both participants');
 $a['rows']=[row(901234570,$b,'win')];$r=$service->process($a,0,1,true);check($r['items'][0]['category']==='conflict','unverified changed final result is rejected');
 $a['rows']=[row(901234573,$b,'win',['record_eligible'=>false,'record_eligibility_evidence'=>'source_profile_record_ineligible_mma'])];$r=$service->process($a,0,1,true);$flagged=(int)bout(901234573)['bout_id'];$status=$wpdb->get_row("SELECT status,is_scoring_candidate FROM {$t['bouts']} WHERE id=$flagged",ARRAY_A);check($status['status']==='excluded_record_ineligible'&&(int)$status['is_scoring_candidate']===0,'record-ineligible history is retained without becoming a ranking input');
 $a['rows']=[row(901234573,$b)];$service->process($a,0,1,true);check($wpdb->get_var("SELECT status FROM {$t['bouts']} WHERE id=$flagged")==='excluded_record_ineligible','unflagged duplicate perspective does not remove an exclusion');
 $legacy_url='https://www.tapology.com/fightcenter/fighters/isolated-legacy-opponent';$legacy=['source_url'=>$legacy_url,'source_fighter_id'=>'tapology_fighter_url_'.substr(hash('sha256',$legacy_url),0,24),'display_name'=>'Isolated legacy opponent'];
 $a['rows']=[row(901234580,$legacy)];$service->process($a,0,1,true);$legacy_once=tables_hash($t);$service->process($a,0,1,true);check(tables_hash($t)===$legacy_once,'exact legacy source URL identity is repeatable without name matching');
 $a['rows']=[row(901234574,$b,'win',['is_amateur'=>true]),row(901234575,$b,'win',['discipline'=>'boxing']),row(901234576,$b,'win',['is_cancelled'=>true]),row(901234577,$b,'win',['is_exhibition'=>true])];$r=$service->process($a,0,1,true);check(array_unique(array_column($r['items'],'category'))===['excluded'],'amateur, cancelled, non-MMA and exhibition results are excluded');
 $a['rows']=[row(901234581,$b)];$service->process($a,0,1,true);$classification_id=(int)bout(901234581)['bout_id'];
 $a['rows']=[row(901234581,$b,'win',['is_amateur'=>true,'source_division'=>'amateur','classification_evidence'=>'profile_dom_classification'])];$r=$service->process($a,0,1,false);check($r['items'][0]['category']==='updated'&&$wpdb->get_var("SELECT status FROM {$t['bouts']} WHERE id=$classification_id")==='valid','classification dry-run reports correction without changing the bout');
 $r=$service->process($a,0,1,true);check($wpdb->get_var("SELECT status FROM {$t['bouts']} WHERE id=$classification_id")==='excluded_amateur'&&(int)$wpdb->get_var("SELECT is_scoring_candidate FROM {$t['bouts']} WHERE id=$classification_id")===0,'confirmed amateur classification removes an existing scoring input');
 $a['rows']=[row(901234582,$b)];$service->process($a,0,1,true);$protected_id=(int)bout(901234582)['bout_id'];(new FieldProvenanceService())->upsert('bout',$protected_id,'status','valid',1);
 $a['rows']=[row(901234582,$b,'win',['discipline'=>'boxing','classification_evidence'=>'profile_dom_classification'])];$r=$service->process($a,0,1,true);check($r['items'][0]['category']==='conflict'&&$wpdb->get_var("SELECT status FROM {$t['bouts']} WHERE id=$protected_id")==='valid','manual classification is protected from source corrections');
 $a['rows']=[row(901234578,$b,'win',['source_event_id'=>'','event_url'=>''])];$r=$service->process($a,0,1,true);check($r['items'][0]['category']==='review','missing event identity goes to review without guessing');
 $a['rows']=[row(901234579,$b),row(901234579,$b,'loss')];$r=$service->process($a,0,1,true);check($r['items'][0]['category']==='conflict'&&!bout(901234579),'contradictory duplicate rows cannot write a result');
 $a['rows']=[row(901234570,$b,'draw',['final_result_verified'=>true])];(new FieldProvenanceService())->upsert('bout',$id,'result_type','no_contest',1);$r=$service->process($a,0,1,true);check($r['items'][0]['category']==='conflict','manual final-result decision is protected');
 try{(new BoutParticipantRepository())->replace_exactly_two($id,[$participants[0],$participants[0]]);throw new RuntimeException('Duplicate participants accepted');}catch(InvalidArgumentException $e){echo "PASS: duplicate fighter/role is rejected\n";}
 $large=fixture_fighter(901234590,'Synthetic long career',['is_public'=>1]);
 $rows=[];for($n=0;$n<103;++$n){$rows[]=fixture_fight(901235000+$n,$b,['win','loss','draw','no_contest'][$n%4],['fight_date'=>'2020-01-01']);}
 $large=\MMAF\DataEngine\Services\DataPipeline\ProfileStore::career_profile(fixture_profile($large,$rows,['wins'=>26,'losses'=>26,'draws'=>26,'no_contests'=>25]),$large['fighter_id']);
 $beforeChunk=tables_hash($t);
 try{Transaction::run(static function()use($service,$large){$service->process_chunk($large,0,1,0);throw new RuntimeException('Interrupted before durable checkpoint');});}
 catch(RuntimeException $e){check($e->getMessage()==='Interrupted before durable checkpoint' && tables_hash($t)===$beforeChunk,'interrupted career chunk rolls back all canonical writes before checkpoint');}
 $first=Transaction::run(fn()=>$service->process_chunk($large,0,1,0));
 check(!$first['done'] && $first['checkpoint']['offset']===50 && $first['coverage']['status']==='partial','long career checkpoints the first fifty rows without claiming completeness');
 $modified=$large;$modified['rows'][100]['result']='loss';$changedBefore=tables_hash($t);
 try{$service->process_chunk($modified,0,1,0,$first['checkpoint']);throw new RuntimeException('Changed input accepted');}
 catch(\MMAF\DataEngine\Services\RegularImport\ImportException $e){check($e->reason==='identity' && tables_hash($t)===$changedBefore,'changed source hash cannot resume a partially imported career');}
 $second=Transaction::run(fn()=>$service->process_chunk($large,0,1,0,$first['checkpoint']));
 $third=Transaction::run(fn()=>$service->process_chunk($large,0,1,0,$second['checkpoint']));
 check(!$second['done'] && $second['checkpoint']['offset']===100 && $third['done'] && $third['checkpoint']['offset']===103 && $third['coverage']['status']==='complete' && (int)$third['coverage']['accepted_bouts_count']===103,'resumed chunks verify the complete W/L/D/NC record across all career rows');
 $once=tables_hash($t);$cp=[];do{$repeat=$service->process_chunk($large,0,1,0,$cp);$cp=$repeat['checkpoint'];}while(!$repeat['done']);
 check(tables_hash($t)===$once && $repeat['coverage']['status']==='complete','repeated chunked career import preserves completeness without duplicate entities');
 $conflicted=fixture_fighter(901234591,'Synthetic split conflict',['is_public'=>1]);$conflictedRows=[];
 for($n=0;$n<51;++$n){$conflictedRows[]=fixture_fight(901236000+$n,$b);}
 $conflictedRows[]=fixture_fight(901236000,$b,'loss');
 $conflicted=\MMAF\DataEngine\Services\DataPipeline\ProfileStore::career_profile(fixture_profile($conflicted,$conflictedRows,['wins'=>51,'losses'=>1,'draws'=>0,'no_contests'=>0]),$conflicted['fighter_id']);
 $split=$service->process_chunk($conflicted,0,1,0);check(!bout(901236000),'contradictory duplicate beyond the first chunk prevents its first result from being written');
 $split=$service->process_chunk($conflicted,0,1,0,$split['checkpoint']);
 check($split['done'] && $split['coverage']['status']==='conflict' && !bout(901236000),'cross-chunk conflict remains reviewable after the final checkpoint');
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
check(tables_hash($t)===$baseline,'synthetic fixtures and all career writes roll back completely');
