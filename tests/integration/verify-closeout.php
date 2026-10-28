<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';
use MMAF\DataEngine\Migrations\{Schema,MigrationRunner};
use MMAF\DataEngine\Services\{FighterService,FighterPostSyncService,FieldProvenanceService};
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Services\DataPipeline\{ProfileStore,Processor};
use MMAF\DataEngine\Services\Operations\{Jobs,Readiness,Revision,FactProtectionReport,Schema as Operations};
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard,Repository};
function closeout_check($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
MigrationRunner::run();wp_set_current_user(1);global $wpdb;$t=Schema::table_names();$o=Operations::tables();
WriteGuard::$worker=true;Transaction::begin();add_filter('pre_http_request',static function(){throw new RuntimeException('Unexpected source fetch.');});
try{
    $f=new FighterRepository();$service=new FighterService();$store=new ProfileStore();$provenance=new FieldProvenanceService();
    $a=fixture_fighter(949733001,'Closeout facts',['height_cm'=>180,'nationality'=>'Original','birth_year'=>2000]);$id=$a['fighter_id'];
    (new FighterPostSyncService($f))->sync($id,$f->find($id));
    $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighter_sources']} WHERE fighter_id=%d",$id),ARRAY_A);
    $input=['display_name'=>$a['display_name'],'source_url'=>$a['source_url'],'source_slug'=>$source['source_slug'],'is_primary'=>$source['is_primary'],'is_verified'=>$source['is_verified']];
    $service->update($id,$input,1);$pending=Processor::pending();$state=Processor::status()['calculation'];
    $revision=Revision::current();$manual=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['field_provenance']} WHERE source_type='manual_verified'");
    $service->update($id,$input,1);
    closeout_check(Revision::current()===$revision && Processor::pending()===$pending && Processor::status()['calculation']===$state,'unchanged Save does not change canonical input, queue preparation or request a draft');
    closeout_check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['field_provenance']} WHERE source_type='manual_verified'")===$manual,'unchanged populated and empty facts do not receive manual protection');
    foreach(['status'=>'verified','is_public'=>1,'is_rankable'=>1,'rankability_status'=>'rankable'] as $field=>$value){
        $rejected=false;try{$service->update($id,array_merge($input,[$field=>$value]),1);}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'Save cannot change');}
        closeout_check($rejected && !(int)$f->find($id)['is_public'],'direct Save cannot promote '.$field);
    }
    $image=wp_insert_attachment(['post_title'=>'Synthetic closeout image','post_mime_type'=>'image/png','post_status'=>'inherit']);update_post_meta($image,'_wp_attached_file','2026/closeout-fixture.png');wp_update_attachment_metadata($image,['width'=>10,'height'=>10,'file'=>'2026/closeout-fixture.png']);
    $service->update($id,array_merge($input,['fighter_image_id'=>$image]),1);$service->update($id,array_merge($input,['fighter_image_id'=>$image]),1);
    closeout_check(Processor::pending()===$pending && Processor::status()['calculation']===$state && !$provenance->has_manual_verified('fighter',$id,'height_cm'),'photo-only Save and unchanged selected image do not protect facts or schedule sports work');
    $service->update($id,array_merge($input,['height_cm'=>181]),1);
    closeout_check($provenance->has_manual_verified('fighter',$id,'height_cm') && !$provenance->has_manual_verified('fighter',$id,'nationality'),'only the changed fact gains protection');
    $service->update($id,array_merge($input,['nationality'=>'']),1);
    closeout_check($f->find($id)['nationality']===null && $provenance->has_manual_verified('fighter',$id,'nationality'),'intentional clearing is recorded and protected');
    $service->update($id,array_merge($input,['confirm_fact_fields'=>['stance']]),1);
    closeout_check($provenance->has_manual_verified('fighter',$id,'stance') && !$provenance->has_manual_verified('fighter',$id,'coach_name'),'explicit confirmation protects only the selected fact');
    $failed=fixture_fighter(949733002,'Closeout failed profile');$bad=fixture_profile($failed,[],[],['status'=>'unavailable']);$store->put($bad,'tapology_fighter_profiles_v0_1');
    $m=Processor::metadata([$failed['fighter_id']]);closeout_check(Readiness::decision($f->find($failed['fighter_id']),$m[$failed['fighter_id']])['reason']==='stored_profile_missing','first failed fetch cannot be advertised as a usable saved profile');
    $good=fixture_profile($failed,[],[]);$store->put($good,'tapology_fighter_profiles_v0_1');$bad['scraped_at']='2026-10-11T10:00:00Z';$store->put($bad,'tapology_fighter_profiles_v0_1');
    $m=Processor::metadata([$failed['fighter_id']]);closeout_check(Readiness::decision($f->find($failed['fighter_id']),$m[$failed['fighter_id']])['state']==='ready' && $m[$failed['fighter_id']]['last_attempt_status']==='unavailable','failed latest attempt retains availability of a previous successful profile');
    $first=Jobs::create('validate_selected_for_ranking',['ids'=>[$id]],1,'closeout-date-a');Jobs::save($first['id'],['checkpoint'=>array_merge($first['checkpoint'],['preview_reference_date'=>'2001-01-01'])]);fixture_drive_job($first['id'],'ready');
    $hash=Jobs::items($first['id'])['items'][0]['input_hash'];Jobs::control($first['id'],'cancel',1);
    $second=Jobs::create('validate_selected_for_ranking',['ids'=>[$id]],1,'closeout-date-b');Jobs::save($second['id'],['checkpoint'=>array_merge($second['checkpoint'],['preview_reference_date'=>'2026-10-12'])]);fixture_drive_job($second['id'],'ready');
    closeout_check(Jobs::items($second['id'])['items'][0]['input_hash']===$hash,'different preview dates do not masquerade as changed content');
    $legacy=Jobs::get($second['id']);unset($legacy['checkpoint']['fingerprint_version']);Jobs::save($second['id'],['checkpoint'=>$legacy['checkpoint']]);$rejected=false;
    try{Jobs::control($second['id'],'start',1,$legacy['manifest_hash']);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'fresh preview');}
    closeout_check($rejected,'legacy manifest cannot be confirmed using the new fingerprint contract');
    $replacement=Jobs::control($second['id'],'repreview',1);closeout_check($replacement['id']!==$second['id'] && $replacement['status']==='previewing' && Jobs::get($second['id'])['status']==='cancelled','explicit repreview creates a separate unconfirmed manifest and preserves old evidence');Jobs::control($replacement['id'],'cancel',1);
    $opponent=fixture_fighter(949733003,'Closeout private opponent');$public=fixture_fighter(949733004,'Closeout public without age',['date_of_birth'=>null]);
    $profile=fixture_profile($public,[fixture_fight(949733005,$opponent)],['wins'=>1,'losses'=>0,'draws'=>0,'no_contests'=>0]);$store->put($profile,'tapology_fighter_profiles_v0_1');
    $job=Jobs::create('prepare_publish_profile',['ids'=>[$public['fighter_id']]],1,'closeout-profile-only');fixture_confirm_job($job['id']);Jobs::work(1);
    closeout_check(!(int)$f->find($public['fighter_id'])['is_public'],'profile-only operation also keeps candidates private during preparation');fixture_drive_job($job['id']);$published=$f->find($public['fighter_id']);
    closeout_check($published['status']==='verified' && (int)$published['is_public']===1 && !(int)$published['is_rankable'],'profile-only operation permits publication without birth/ranking eligibility or granting ranking permission');
    $before=$wpdb->get_results("SELECT * FROM {$t['field_provenance']} ORDER BY id",ARRAY_A);$revision=Revision::current();$runs=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}");
    $report=FactProtectionReport::create(1);
    $fault=static function($sql)use($t){if(str_contains($sql,'SELECT DISTINCT entity_id FROM '.$t['field_provenance'])){throw new RuntimeException('Synthetic report read failure');}return $sql;};
    add_filter('query',$fault);FactProtectionReport::work();$retry=Jobs::get($report['id']);
    closeout_check($retry['attempts']==1 && $retry['checkpoint']['cursor']===0 && $retry['retry_at']>gmdate('Y-m-d H:i:s') && FactProtectionReport::work()['worked']===false,'report failure keeps its checkpoint and waits before retry');
    for($n=0;$n<2;++$n){Jobs::save($report['id'],['retry_at'=>null]);FactProtectionReport::work();}
    remove_filter('query',$fault);closeout_check(Jobs::get($report['id'])['status']==='failed','report stops after three technical attempts');
    Jobs::control($report['id'],'retry_job',1);
    for($n=0;$n<100;++$n){FactProtectionReport::work();if(Jobs::get($report['id'])['status']==='completed'){break;}}
    closeout_check(Jobs::get($report['id'])['status']==='completed' && $before===$wpdb->get_results("SELECT * FROM {$t['field_provenance']} ORDER BY id",ARRAY_A) && $revision===Revision::current() && $runs===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_runs']}"),'background protection report never unlocks facts, changes canonical input or creates rankings');
    $result=Jobs::items($report['id']);closeout_check(!str_contains(wp_json_encode($result),'payload_json') && !str_contains(wp_json_encode($result),'fight_history'),'report and paginated results omit private source payloads');
    $wpdb->query('DELETE FROM '.Operations::tables()['readiness'].' WHERE fighter_id IN ('.implode(',',[$id,$failed['fighter_id']]).')');
    $refreshed=Readiness::refresh([$id,$failed['fighter_id']],0);
    closeout_check($refreshed===1 && count(Readiness::read([$id,$failed['fighter_id']]))===1 && Readiness::refresh([$id,$failed['fighter_id']])===2,'readiness time budget leaves unprocessed rows available for a later bounded step');
    Readiness::invalidate($id);$background=\MMAF\DataEngine\Services\Operations\Module::work();
    closeout_check($background['worked'] && $background['readiness_refreshed']>0,'readiness work reports progress so the supervisor continues bounded steps within its time budget');
    $confirmationPage=null;
    for($page=1;$page<=max(1,(int)ceil((int)$report['total']/25));++$page){
        $candidate=Jobs::items($report['id'],$page,25);
        foreach($candidate['items'] as $item){foreach($item['result']['facts']??[] as $fact){
            if(($fact['evidence']??'')==='Explicit confirmation recorded'){$confirmationPage=$candidate;break 2;}
        }}
        if($confirmationPage!==null){break;}
    }
    ob_start();MMAF\DataEngine\Admin\OperationsPage::progress(Jobs::get($report['id']),$confirmationPage??$result);$html=ob_get_clean();closeout_check($confirmationPage!==null && str_contains($html,'Explicit confirmation recorded') && str_contains($html,'no automatic unlocking'),'protection evidence is readable without raw JSON');
    $method=new ReflectionMethod(MMAF\DataEngine\Admin\FightersPage::class,'render_form');ob_start();$method->invoke(null,$id);$html=ob_get_clean();
    closeout_check(str_contains($html,'name="bulk_action" value="approve_and_prepare"') && str_contains($html,'Prepare &amp; publish profile') && !str_contains($html,'name="is_public"'),'single editor exposes the same frozen operation actions instead of editable publication flags');
    echo 'METRICS '.wp_json_encode(['peak_memory'=>memory_get_peak_usage(true)])."\n";
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
