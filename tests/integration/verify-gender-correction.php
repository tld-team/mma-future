<?php
/** What a Tapology profile states about gender corrects an inferred value, never a manual one. */
require __DIR__.'/bootstrap.php';
use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\{FighterRepository,FighterSourceRepository};
use MMAF\DataEngine\Services\{FieldProvenanceService,GenderSource};
use MMAF\DataEngine\Services\Import\{FighterProfileEnrichmentApplyService,GenderInferenceService};
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\RegularImport\{Review,Transaction,WriteGuard};
function check($ok,$reason){if(!$ok){throw new RuntimeException($reason);}echo "PASS: $reason\n";}
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
\MMAF\DataEngine\Migrations\MigrationRunner::run();wp_set_current_user(1);
$t=Schema::table_names();$f=new FighterRepository();$s=new FighterSourceRepository();$provenance=new FieldProvenanceService();$now=gmdate('Y-m-d H:i:s');
add_filter('pre_http_request',static function(){throw new RuntimeException('Gender correction attempted a network request.');});

$he=['pronoun'=>'he','women_p4p_ranking'=>false,'female_grouping_note'=>false,'status'=>'explicit','excerpt'=>'He is only ranked in regions where he has fought at least 2 times out of his last 6 MMA bouts.'];
$she=['pronoun'=>'she','women_p4p_ranking'=>true,'female_grouping_note'=>true,'status'=>'explicit','excerpt'=>'She is only ranked in regions where she has fought at least 2 times out of her last 6 MMA bouts.'];
$fixture=static function(string $key,array $fighter)use($f,$s,$now):array{
    $number='9488'.str_pad((string)abs(crc32($key)%100000),5,'0',STR_PAD_LEFT);$source='tapology_fighter_'.$number;$url='https://www.tapology.com/fightcenter/fighters/'.$number.'-gender-'.$key;
    $id=$f->insert($fighter+['display_name'=>'Gender fixture '.$key,'normalized_name'=>'gender fixture '.$key,'weight_class'=>'unknown','status'=>'provisional','created_at'=>$now]);
    $s->upsert_for_fighter($id,['source_type'=>'tapology','source_fighter_id'=>$source,'source_url'=>$url,'identity_hash'=>hash('sha256',$url),'is_primary'=>1,'created_at'=>$now]);
    return ['id'=>$id,'source'=>$source,'url'=>$url,'name'=>'Gender fixture '.$key];
};
$profile=static fn(array $fx,string $class,?string $gender,?array $evidence):array=>['source_fighter_id'=>$fx['source'],'source_url'=>$fx['url'],'status'=>'success','display_name'=>$fx['name'],'scraped_at'=>gmdate('c'),
    'profile_summary'=>['weight_class'=>$class,'gender'=>$gender,'gender_evidence'=>$evidence],'record_summary'=>[],'fight_history'=>[]];
$apply=static function(array $profiles):array{
    return (new FighterProfileEnrichmentApplyService())->apply_all_safe_json_string(wp_json_encode(['schema_version'=>'tapology_fighter_profiles_v0_1','source'=>'tapology','profiles'=>$profiles,'profiles_total'=>count($profiles)]),'Gender correction fixture',1);
};
$count=static function(array $filters)use($f,$t,$wpdb,&$ids):int{
    $where=$f->where_clause('',$filters);$sql="SELECT COUNT(*) FROM {$t['fighters']} f {$where['sql']} AND f.id IN (".implode(',',array_map('intval',$ids)).')';
    return (int)($where['args']?$wpdb->get_var($wpdb->prepare($sql,$where['args'])):$wpdb->get_var($sql));
};
$state=static fn(int $id,string $gender)=>GenderSource::classify($provenance->sources_for_value('fighter',$id,'gender',$gender),$gender);

WriteGuard::$worker=true;Transaction::begin();
try {
    // The reported case: a man at Strawweight whom the weight class alone had marked female.
    $inferred=$fixture('inferred',['gender'=>'female','weight_class'=>'women_strawweight']);
    $provenance->upsert_admin_approved_source('fighter',$inferred['id'],'gender','female',GenderInferenceService::SOURCE_WEIGHT_CLASS,$inferred['source'],1);
    $manual=$fixture('manual',['gender'=>'female','weight_class'=>'women_strawweight']);
    $provenance->upsert('fighter',$manual['id'],'gender','female',1);
    $unknown_origin=$fixture('origin',['gender'=>'female','weight_class'=>'women_strawweight']);
    $empty_she=$fixture('emptyshe',[]);
    $empty_straw=$fixture('emptystraw',[]);
    $empty_heavy=$fixture('emptyheavy',[]);
    $confirmed=$fixture('confirmed',['gender'=>'male','weight_class'=>'lightweight']);
    $provenance->upsert_admin_approved_source('fighter',$confirmed['id'],'gender','male',GenderInferenceService::SOURCE_WEIGHT_CLASS,$confirmed['source'],1);
    $ids=array_column([$inferred,$manual,$unknown_origin,$empty_she,$empty_straw,$empty_heavy,$confirmed],'id');

    check($count(['gender'=>'unknown'])===3,'the Unknown gender filter finds fighters whose gender is NULL');
    check($count(['gender_source'=>'inferred'])===2,'inferred, unconfirmed genders are listed before the import');
    $cleared_early=$fixture('clearedearly',[]);$provenance->upsert('fighter',$cleared_early['id'],'gender','',1);
    $paused=$fixture('paused',['tracking_mode'=>'paused']);
    $export=GenderSource::export_targets();
    $listed=array_column(array_filter($export['fighters'],static fn($row)=>in_array($row['fighter_id'],array_merge($ids,[$cleared_early['id'],$paused['id']]),true)),'reason','fighter_id');
    check($export['schema_version']==='mmaf_gender_targets_v1'&&$listed==[$inferred['id']=>'inferred_female',$empty_she['id']=>'missing',$empty_straw['id']=>'missing',$empty_heavy['id']=>'missing'],'the gender targets export lists fighters without a gender and inferred women, not manual, cleared, paused or inferred-male ones');
    $row=current(array_filter($export['fighters'],static fn($row)=>$row['fighter_id']===$inferred['id']));
    check($row['source_fighter_id']===$inferred['source']&&str_contains((string)$row['source_url'],'/fightcenter/fighters/'),'each target carries its Tapology identity');
    check(in_array($paused['id'],array_column(GenderSource::export_targets(['provisional'],false)['fighters'],'fighter_id'),true)&&!in_array($inferred['id'],array_column(GenderSource::export_targets(['verified'])['fighters'],'fighter_id'),true),'the export honours the status and tracking options');

    $result=$apply([
        $profile($inferred,'Strawweight','male',$he),$profile($manual,'Strawweight','male',$he),$profile($unknown_origin,'Strawweight','male',$he),
        $profile($empty_she,'Flyweight','female',$she),$profile($empty_straw,'Strawweight',null,['pronoun'=>null,'women_p4p_ranking'=>false,'female_grouping_note'=>false,'status'=>'ineligible','excerpt'=>'']),
        $profile($empty_heavy,'Heavyweight',null,null),$profile($confirmed,'Lightweight','male',$he),
    ]);
    check(empty($result['summary']['profiles_failed']),'every profile applied without an error');

    $row=$f->find($inferred['id']);
    check($row['gender']==='male'&&$row['weight_class']==='flyweight','an inferred female Strawweight whose profile says "he" becomes male and moves to flyweight');
    check($state($inferred['id'],'male')===['state'=>GenderSource::CONFIRMED,'conflict'=>false],'the corrected gender is recorded as confirmed by Tapology');
    check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['audit_log']} WHERE action='fighter_gender_corrected' AND entity_type='fighter' AND entity_id=%d",$inferred['id']))===1,'the correction is written to the audit log');

    $row=$f->find($manual['id']);
    check($row['gender']==='female'&&$row['weight_class']==='women_strawweight','a manually set gender is never overwritten');
    check($state($manual['id'],'female')===['state'=>GenderSource::MANUAL,'conflict'=>true],'the disagreement with Tapology is recorded as a conflict');

    $row=$f->find($unknown_origin['id']);
    check($row['gender']==='female'&&$row['weight_class']==='women_strawweight','a gender of unknown origin is left alone');
    check($state($unknown_origin['id'],'female')===['state'=>GenderSource::UNKNOWN,'conflict'=>true],'and is flagged as a conflict instead');

    $row=$f->find($empty_she['id']);
    check($row['gender']==='female'&&$row['weight_class']==='women_flyweight','an empty gender takes "she" and the women\'s division');
    $row=$f->find($empty_straw['id']);
    check(empty($row['gender'])&&$row['weight_class']==='unknown','a Strawweight without evidence gets neither a gender nor a division');
    $row=$f->find($empty_heavy['id']);
    check($row['gender']==='male'&&$row['weight_class']==='heavyweight','a heavyweight without evidence is inferred male');
    check($state($empty_heavy['id'],'male')===['state'=>GenderSource::WEIGHT_CLASS,'conflict'=>false],'and that inference stays marked as unconfirmed');
    check($state($confirmed['id'],'male')===['state'=>GenderSource::CONFIRMED,'conflict'=>false],'an inferred gender the profile agrees with becomes confirmed without a write');

    check($count(['gender'=>'unknown'])===1,'only the fighter without evidence is still Unknown');
    check($count(['gender_source'=>'confirmed'])===3&&$count(['gender_source'=>'inferred'])===1&&$count(['gender_source'=>'manual'])===1&&$count(['gender_source'=>'conflict'])===2,'the gender source filters match the stored provenance');

    // A later profile that says "she" corrects the heavyweight inference too.
    $apply([$profile($empty_heavy,'Featherweight','female',$she)]);
    $row=$f->find($empty_heavy['id']);
    check($row['gender']==='female'&&$row['weight_class']==='women_featherweight','explicit evidence corrects a male weight-class inference and its division');

    // Fighters whose profile states no gender take it from opponents, but only from trusted ones.
    $bout=static function(int $a,int $b,string $class='unknown',string $status='completed')use($wpdb,$t,$now):int{
        $wpdb->insert($t['bouts'],['event_id'=>0,'weight_class'=>$class,'status'=>$status,'result_type'=>'win_loss','created_at'=>$now]);$id=(int)$wpdb->insert_id;
        foreach([[$a,$b,'fighter_a'],[$b,$a,'fighter_b']] as [$one,$other,$role]){$wpdb->insert($t['bout_participants'],['bout_id'=>$id,'fighter_id'=>$one,'participant_role'=>$role,'opponent_fighter_id'=>$other,'created_at'=>$now]);}
        return $id;
    };
    $weak=$fixture('weak',['gender'=>'male','weight_class'=>'welterweight']);
    $provenance->upsert_admin_approved_source('fighter',$weak['id'],'gender','male',GenderInferenceService::SOURCE_WEIGHT_CLASS,$weak['source'],1);
    $agreed=$fixture('agreed',['weight_class'=>'flyweight']);$single=$fixture('single',[]);$split=$fixture('split',[]);$cleared=$fixture('cleared',[]);$weakonly=$fixture('weakonly',[]);
    $provenance->upsert('fighter',$cleared['id'],'gender','',1);
    $labelled=$bout($agreed['id'],$inferred['id'],'women_strawweight');$bout($agreed['id'],$confirmed['id']);
    $bout($single['id'],$empty_she['id']);$bout($single['id'],$empty_heavy['id'],'unknown','excluded_cancelled');
    $bout($split['id'],$inferred['id']);$bout($split['id'],$empty_she['id']);
    $bout($cleared['id'],$inferred['id']);$bout($cleared['id'],$confirmed['id']);
    $bout($weakonly['id'],$weak['id']);$bout($weakonly['id'],$confirmed['id']);
    $consensus=new \MMAF\DataEngine\Services\Import\OpponentGenderConsensusService();
    $mine=static fn(array $rows)=>array_values(array_filter($rows,static fn($row)=>in_array((int)$row['fighter_id'],[$agreed['id'],$single['id'],$split['id'],$cleared['id'],$weakonly['id']],true)));
    $decisions=array_column($mine($consensus->candidates()),'reason','fighter_id');
    check($decisions[$agreed['id']]==='opponents_agree'&&$decisions[$split['id']]==='opponents_disagree'&&$decisions[$single['id']]==='too_few_trusted_opponents'&&$decisions[$weakonly['id']]==='too_few_trusted_opponents','opponent consensus needs two trusted opponents who agree; cancelled bouts and inferred genders do not count');
    $dry=$consensus->apply(1,false);
    check(empty($f->find($agreed['id'])['gender'])&&$dry['applied']===0,'a dry run writes nothing');
    $done=$consensus->apply(1,true);
    $row=$f->find($agreed['id']);
    check($row['gender']==='male'&&$row['weight_class']==='flyweight','two confirmed male opponents make the fighter male');
    check($state($agreed['id'],'male')===['state'=>GenderSource::OPPONENTS,'conflict'=>false],'and the gender is recorded as inferred from opponents');
    check(empty($f->find($single['id'])['gender'])&&empty($f->find($split['id'])['gender'])&&empty($f->find($weakonly['id'])['gender']),'fighters without enough agreeing trusted opponents keep no gender');
    check(empty($f->find($cleared['id'])['gender'])&&$done['skipped_protected']>=1,'a gender an admin cleared stays empty');
    check(in_array($split['id'],$done['opponents_disagree'],true),'disagreeing opponents are reported');
    $second=$consensus->apply(1,true);
    check(!in_array($agreed['id'],array_column($consensus->candidates(),'fighter_id'),true)&&$second['applied']===0,'the opponent inference is not passed on and a second run changes nothing');
    $apply([$profile($agreed,'Flyweight','female',$she)]);
    $row=$f->find($agreed['id']);
    check($row['gender']==='female'&&$row['weight_class']==='women_flyweight','a profile that later states the gender corrects the opponent inference');
    check(in_array($agreed['id'],array_column($consensus->mismatches(),'fighter_id'),true),'a fighter whose trusted opponents have the other gender is reported as a mismatch');
    $labels=$consensus->fix_strawweight_bout_labels(true);
    check(in_array($labelled,$labels['bout_ids'],true)&&$wpdb->get_var($wpdb->prepare("SELECT weight_class FROM {$t['bouts']} WHERE id=%d",$labelled))==='unknown','a Strawweight bout with a confirmed man loses its women\'s label');
    // Gender evidence read later from an already stored capture completes it; nothing else may change at the same time.
    $store=new ProfileStore();$schema='tapology_fighter_profiles_v0_1';$capture=$fixture('capture',[]);
    $plain=['source_fighter_id'=>$capture['source'],'source_url'=>$capture['url'],'status'=>'success','display_name'=>$capture['name'],'scraped_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-600),
        'profile_summary'=>['weight_class'=>'Strawweight'],'record_summary'=>[],'fight_history'=>[]];
    check($store->put($plain,$schema)['status']==='created','a capture without gender evidence is stored');
    $hold=Review::upsert(0,'profile_snapshot',$capture['source'],['source_fighter_id'=>$capture['source'],'reason'=>ProfileStore::SAME_TIMESTAMP_REASON],1);
    $edited=$plain;$edited['profile_summary']['weight_class']='Flyweight';
    $with=$plain;$with['profile_summary']+=['gender'=>'male','gender_evidence'=>$he];
    $both=$with;$both['profile_summary']['weight_class']='Flyweight';
    check($store->put($edited,$schema)['status']==='review'&&$store->put($both,$schema)['status']==='review','different content at the same source time is still held, with or without gender evidence');
    $saved=$store->put($with,$schema);
    check($saved['status']==='updated'&&$saved['gender_evidence_added']===true&&$store->for_fighter($capture['id'])['profile']['profile_summary']['gender']==='male','the same capture with only gender evidence added replaces the stored one');
    $apply([$store->for_fighter($capture['id'])['profile']]);
    check($f->find($capture['id'])['gender']==='male'&&$f->find($capture['id'])['weight_class']==='flyweight','and its gender reaches the fighter');
    $flipped=$with;$flipped['profile_summary']['gender']='female';$flipped['profile_summary']['gender_evidence']=$she;
    check($store->put($flipped,$schema)['status']==='review'&&$store->put($with,$schema)['status']==='unchanged','stored gender evidence is not replaced at the same source time');
    check(Review::settle('profile_snapshot',$capture['source'],ProfileStore::SAME_TIMESTAMP_REASON,'Fixture')===1&&$wpdb->get_var($wpdb->prepare("SELECT status FROM {$t['review_items']} WHERE id=%d",$hold))==='resolved'&&Review::settle('profile_snapshot',$capture['source'],ProfileStore::SAME_TIMESTAMP_REASON,'Fixture')===0,'the hold raised for that capture is resolved once');
} finally {Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
check(!$f->find($inferred['id']),'fixtures roll back');
