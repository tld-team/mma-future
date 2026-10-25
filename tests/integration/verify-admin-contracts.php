<?php
require __DIR__.'/bootstrap.php';
use MMAF\DataEngine\Services\Operations\{Module,Readiness,ReviewLinks,Jobs,Schema as Operations};
use MMAF\DataEngine\Services\DataPipeline\Processor;
use MMAF\DataEngine\Services\RegularImport\{DatabaseState,Transaction,Repository,WriteGuard};
use MMAF\DataEngine\Migrations\{Schema,MigrationRunner};
function admin_assert($ok,$message){if(!$ok){throw new RuntimeException($message);}echo "PASS: $message\n";}
MigrationRunner::run();global $wpdb;$t=Schema::table_names();$o=Operations::tables();Transaction::begin();WriteGuard::$worker=true;
try{
    $repo=new MMAF\DataEngine\Repositories\FighterRepository();$a=$repo->insert(['display_name'=>'Admin identity fixture A','normalized_name'=>'admin identity fixture a','height_cm'=>180,'created_at'=>gmdate('Y-m-d H:i:s')]);$b=$repo->insert(['display_name'=>'Admin identity fixture B','normalized_name'=>'admin identity fixture b','created_at'=>gmdate('Y-m-d H:i:s')]);
    $source=['fighter_id'=>$a,'source_type'=>'tapology','source_fighter_id'=>'tapology_fighter_987654321-admin','source_url'=>'https://www.tapology.com/fightcenter/fighters/987654321-admin','created_at'=>gmdate('Y-m-d H:i:s')];Repository::check($wpdb->insert($t['fighter_sources'],$source));
    $before=Readiness::fingerprint($a);$approval=Readiness::approval_hash($a);$source['fighter_id']=$b;Repository::check($wpdb->insert($t['fighter_sources'],$source));
    $metadata=Processor::metadata([$a,$b]);
    admin_assert(!empty($metadata[$a]['identity_conflict']) && !empty($metadata[$b]['identity_conflict']),'grouped metadata flags shared source identity for both fighters');
    admin_assert(Readiness::fingerprint($a)!==$before && Readiness::approval_hash($a)!==$approval,'new contradictory binding changes preview and approval fingerprints');
    admin_assert(Readiness::decision($repo->find($a),$metadata[$a])['reason']==='identity_conflict','shared identity requires review before missing-profile preparation');
    wp_set_current_user(1);$job=Jobs::create('validate_selected_for_ranking',['mode'=>'ids','ids'=>[$a,PHP_INT_MAX]],1,'missing-ids-'.wp_generate_uuid4());
    Repository::check($wpdb->insert($t['fighter_stats_current'],['fighter_id'=>$a,'wins'=>7,'losses'=>2,'draws'=>0,'nc'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]));
    $records=(new MMAF\DataEngine\Repositories\FighterStatsRepository())->records_for_fighters([$a,$b]);
    admin_assert((int)$records[$a]['wins']===7 && !isset($records[$b]) && !array_key_exists('warnings_json',$records[$a]),'page record read preserves missing stats and omits detailed payloads');
    $savedGet=$_GET;$_GET=['s'=>'Admin identity fixture A'];
    try{$render=new ReflectionMethod(MMAF\DataEngine\Admin\FightersPage::class,'render_list');ob_start();$render->invoke(null);$html=ob_get_clean();}finally{$_GET=$savedGet;}
    admin_assert(str_contains($html,'7-2-0-1') && str_contains($html,'Needs review'),'admin page shows current record and identity conflict before a readiness projection exists');
    \MMAF\DataEngine\Services\Operations\Membership::record(987654321,$a,'synthetic_admin_contract');
    $_GET=['s'=>'Admin identity fixture A','import_job'=>'987654321','fighter_status'=>'provisional','per_page'=>25,'paged'=>9];
    try{ob_start();$render->invoke(null);$filteredHtml=ob_get_clean();}finally{$_GET=$savedGet;}
    $document=new DOMDocument();@$document->loadHTML($filteredHtml);$xpath=new DOMXPath($document);
    $bulk=$xpath->query('//form[@class="mmaf-bulk-form"]')->item(0);parse_str(parse_url(html_entity_decode($bulk->getAttribute('action')),PHP_URL_QUERY),$bulkArgs);
    admin_assert($bulkArgs['s']==='Admin identity fixture A' && $bulkArgs['import_job']==='987654321' && $bulkArgs['fighter_status']==='provisional' && $bulkArgs['per_page']==='25','bulk manifest receives the same search/import/status scope as the rendered filtered list');
    admin_assert($xpath->query('//select[@name="import_job"]/option[@value="987654321" and @selected]')->length===1 && $xpath->query('//details[contains(@class,"mmaf-more-filters") and @open]')->length===1,'numeric import IDs and active advanced filters survive the filter form');
    $remove=$xpath->query('//a[contains(@aria-label,"Remove Approval status filter")]')->item(0);parse_str(parse_url(html_entity_decode($remove->getAttribute('href')),PHP_URL_QUERY),$removeArgs);
    admin_assert(!isset($removeArgs['fighter_status'],$removeArgs['paged']) && $removeArgs['s']==='Admin identity fixture A' && $removeArgs['import_job']==='987654321' && $removeArgs['per_page']==='25','removing one filter preserves the other filters and page size while resetting pagination');
    $clear=$xpath->query('//a[@class="mmaf-clear-filters"]')->item(0);parse_str(parse_url(html_entity_decode($clear->getAttribute('href')),PHP_URL_QUERY),$clearArgs);
    admin_assert($clearArgs===['page'=>'mmaf-fighters','per_page'=>'25'] && $xpath->query('//input[@name="mmaf_fighter_bulk_nonce"]')->length===1,'clear filters preserves page size and the native bulk form retains its security token');
    Repository::check($wpdb->replace($o['readiness'],['fighter_id'=>$a,'preparation_state'=>'review','reason_code'=>'identity_conflict','input_hash'=>str_repeat('0',64),'data_revision'=>'fixture','rules_version'=>Readiness::rules(),'checked_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'dirty'=>0,'projection_json'=>wp_json_encode(['approval_blocker_codes'=>[],'approval_blocker_summary'=>'Ready'])]));
    $_GET=['s'=>'Admin identity fixture A'];try{ob_start();$render->invoke(null);$readyHtml=ob_get_clean();}finally{$_GET=$savedGet;}
    admin_assert(!str_contains($readyHtml,'<summary>Ranking checks</summary>') && str_contains($readyHtml,'Identity conflict'),'the Ready summary is not presented as a ranking blocker while actual identity conflicts stay visible');
    Jobs::work(10);$job=Jobs::get((int)$job['id']);
    admin_assert($job['status']==='ready' && (int)$job['summary']['review']===1 && (int)$job['summary']['ranking_blocked']===1,'preview keeps source readiness separate from current ranking blockers');
    ob_start();MMAF\DataEngine\Admin\OperationsPage::progress($job,Jobs::items((int)$job['id']));$progress=ob_get_clean();
    admin_assert(str_contains($progress,'Current ranking checks: 0 pass; 1 have blockers') && str_contains($progress,'Current ranking blockers:') && str_contains($progress,'Review frozen selection'),'admin preview explains blockers without opening raw processing JSON');
    admin_assert(($job['summary']['requested_total']??0)===2 && ($job['summary']['excluded'][0]['reason']??'')==='fighter_not_found','explicit missing IDs remain visible with their exclusion reason');
    do_action('rest_api_init');$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$o['jobs']}");
    $subscriber=wp_insert_user(['user_login'=>'admin-contracts-'.wp_generate_uuid4(),'user_pass'=>'local-fixture-only','role'=>'subscriber']);
    foreach([['anonymous',0,null],['subscriber',$subscriber,'valid'],['missing nonce',1,null],['invalid nonce',1,'invalid'],['expired nonce',1,'expired']] as [$label,$user,$nonce]){
        wp_set_current_user($user);$request=new WP_REST_Request('POST','/mma-future/v1/admin/operations');$request->set_body_params(['action'=>'validate_selected_for_ranking','selection'=>['mode'=>'ids','ids'=>[$a]],'request_key'=>'forbidden-'.wp_generate_uuid4()]);
        if($nonce==='valid'){$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));}
        elseif($nonce==='invalid'){$request->set_header('X-WP-Nonce','invalid');}
        elseif($nonce==='expired'){$expired=substr(wp_hash((wp_nonce_tick('wp_rest')-3).'|wp_rest|'.$user.'|'.wp_get_session_token(),'nonce'),-12,10);$request->set_header('X-WP-Nonce',$expired);}
        $result=rest_do_request($request);admin_assert($result->get_status()>=400,$label.' cannot create an operation');
    }
    admin_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$o['jobs']}")===$count,'rejected REST requests do not write operation records');
    wp_set_current_user(1);$request=new WP_REST_Request('GET','/mma-future/v1/admin/operations/'.$job['id']);$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$request->set_param('html',1);$response=rest_do_request($request);
    admin_assert($response->get_status()===200 && isset($response->get_data()['progress_html'],$response->get_data()['progress']['allowed_commands']),'authorized progress shares readable HTML and permitted commands');
    for($n=0;$n<275;$n++){Repository::check($wpdb->insert($t['field_provenance'],['entity_type'=>'fighter','entity_id'=>$a,'field_name'=>'height_cm','source_type'=>'manual_verified','locked'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]));}
    $max=(int)$wpdb->get_var("SELECT MAX(id) FROM {$t['fighters']}");$expectedFlags=$wpdb->get_results($wpdb->prepare("SELECT f.id,f.is_public,f.is_rankable,f.status,f.rankability_status,f.deleted_soft,f.in_ufc,f.tracking_mode,f.tracking_reason,f.tracking_source,f.tracking_evidence_url,f.tracking_changed_at,f.tracking_revision,f.tracking_last_checked_at,f.tracking_last_checked_bout_id,f.tracking_return_candidate_at,p.post_status FROM {$t['fighters']} f LEFT JOIN {$wpdb->posts} p ON p.ID=f.wp_post_id WHERE f.id<=%d ORDER BY f.id",$max),ARRAY_A);
    $fields=$wpdb->get_results("SELECT entity_type,entity_id,field_name FROM {$t['field_provenance']} WHERE locked=1 OR source_type IN ('manual_verified','manual_admin','admin_override','admin_verified','admin_protected') ORDER BY entity_type,entity_id,field_name",ARRAY_A);$manual=[];
    foreach($fields as $field){$kind=['fighter'=>'fighters','event'=>'events','bout'=>'bouts'][$field['entity_type']]??null;if(!$kind || !preg_match('/^[a-z_]+$/D',$field['field_name'])){continue;}$value=$wpdb->get_var($wpdb->prepare('SELECT '.$field['field_name'].' FROM '.$t[$kind].' WHERE id=%d',$field['entity_id']));$manual[]=[$field,$value];}
    $actual=DatabaseState::protectedState();admin_assert($actual['publication_hash']===hash('sha256',wp_json_encode($expectedFlags)),'streamed publication hash matches the prior complete-array hash');
    admin_assert($actual['manual_hash']===hash('sha256',wp_json_encode($manual)),'streamed manual hash preserves duplicate provenance across the 250-row boundary');
    admin_assert(DatabaseState::arrayHash([])===hash('sha256','[]'),'empty streamed state preserves the established hash');
    echo 'METRICS '.wp_json_encode(['peak_memory'=>memory_get_peak_usage(true)])."\n";
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();wp_set_current_user(0);}
