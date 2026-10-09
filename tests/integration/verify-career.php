<?php
require __DIR__.'/bootstrap.php';
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);});
use MMAF\DataEngine\Services\RegularImport\CareerHistory;
use MMAF\DataEngine\Services\RegularImport\Transaction;
use MMAF\DataEngine\Services\RegularImport\WriteGuard;
use MMAF\DataEngine\Repositories\BoutParticipantRepository;
use MMAF\DataEngine\Repositories\BoutSourceRepository;
use MMAF\DataEngine\Services\FieldProvenanceService;
use MMAF\DataEngine\Migrations\Schema;
wp_set_current_user(1);
if(DB_NAME!=='mmaf_frontend_dev'){throw new RuntimeException('Requires the dedicated isolated dev clone.');}
$t=Schema::table_names();$service=new CareerHistory();$fixture=getenv('MMAF_TEST_CAREER_EXPORT');if(!$fixture||!is_file($fixture)){throw new RuntimeException('Set MMAF_TEST_CAREER_EXPORT to the private career fixture export.');}$data=json_decode(file_get_contents($fixture),true);
$a=$data['profiles'][0];$b=$data['profiles'][1];
function check($ok,$why){if(!$ok){throw new RuntimeException($why);}echo "PASS: $why\n";}
function row($number,$opponent,$result='win',$extra=[]){return array_merge(['source_bout_id'=>'tapology_bout_'.$number,'bout_url'=>'https://www.tapology.com/fightcenter/bouts/'.$number.'-fixture','source_event_id'=>'tapology_event_901234568','event_url'=>'https://www.tapology.com/fightcenter/events/901234568-fixture','event_name'=>'Isolated career fixture','fight_date'=>'2020-01-01','opponent_source_id'=>$opponent['source_fighter_id'],'opponent_url'=>$opponent['source_url'],'opponent_name'=>$opponent['display_name'],'discipline'=>'mma','is_professional'=>true,'source_division'=>'pro','result'=>$result,'method_category'=>'decision'], $extra);}
function bout($number){return (new BoutSourceRepository())->find_by_source('tapology','tapology_bout_'.$number);}
function tables_hash($t){global $wpdb; $out=[];foreach(['fighters','events','bouts','bout_participants'] as $key){$out[$key]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t[$key]}");}return $out;}
$before=tables_hash($t);$a['rows']=[row(901234570,$b)];$b['rows']=[row(901234570,$a,'loss')];
$service->process($a,0,1,false);check(tables_hash($t)===$before,'dry-run leaves canonical tables unchanged');
WriteGuard::$worker=true;Transaction::begin();
try{
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
}finally{Transaction::rollback();WriteGuard::$worker=false;}
check(tables_hash($t)===$before,'fixture transaction rolls back completely');
