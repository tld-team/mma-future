<?php
require __DIR__.'/bootstrap.php';
use MMAF\DataEngine\Admin\RankingsPage;
use MMAF\DataEngine\Migrations\{Schema,MigrationRunner};
use MMAF\DataEngine\Repositories\{FighterRepository,RankingRunRepository,RankingCurrentRepository};
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard,Repository};
function ranking_ui_check($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
function ranking_ui_render(array $query): array {
    global $wpdb;$saved=$_GET;$_GET=$query;$queries=[];
    $watch=static function($sql)use(&$queries){$queries[]=$sql;return $sql;};add_filter('query',$watch);
    try{ob_start();RankingsPage::render();$html=ob_get_clean();}finally{$_GET=$saved;remove_filter('query',$watch);}
    $doc=new DOMDocument();@$doc->loadHTML($html);return [$html,new DOMXPath($doc),$queries];
}
MigrationRunner::run();wp_set_current_user(1);global $wpdb;$t=Schema::table_names();
$runs=new RankingRunRepository();$repo=new RankingCurrentRepository();$fighters=new FighterRepository();
$originalActive=$runs->get_active_ranking_run_id();$originalHash=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)));
$savedMethod=$_SERVER['REQUEST_METHOD']??null;$_SERVER['REQUEST_METHOD']='GET';Transaction::begin();WriteGuard::$worker=true;
try{
    $summary=['eligible_fighters'=>28,'ineligible_fighters'=>0,'warnings_count'=>1];
    $a=$runs->create('v1.5',[],'2026-10-12','2026-10-12 12:00:00','completed',wp_json_encode($summary));
    $b=$runs->create('v1.4',[],'2026-10-12','2026-10-12 13:00:00','completed',wp_json_encode($summary));
    $runs->update($a,['is_active'=>1]);$rows=[];
    for($i=1;$i<=28;$i++){
        $id=$fighters->insert(['display_name'=>'Ranking admin fixture '.$i,'normalized_name'=>'ranking admin fixture '.$i,'created_at'=>gmdate('Y-m-d H:i:s')]);
        $rows[]=['board_key'=>'overall','fighter_id'=>$id,'rank_position'=>$i,'total_score'=>30-$i,'raw_score'=>30-$i,'confidence_score'=>100,'sample_size'=>$i===1?0:2,'eligibility_json'=>wp_json_encode(['eligible'=>true]),'warnings_json'=>wp_json_encode(['warnings'=>$i===1?['missing_prefight_record']:[]]),'quality_flags_json'=>'[]','breakdown_json'=>wp_json_encode(['base_record_points'=>0,'finishes_points'=>null,'age_adjustment_points'=>1,'total_score'=>30-$i]),'source_summary_json'=>'{}'];
    }
    $repo->insert_draft_snapshots($a,$rows);$repo->insert_draft_snapshots($b,$rows);
    $runCount=$runs->count();$o=\MMAF\DataEngine\Services\Operations\Schema::tables();$jobCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$o['jobs']}");
    [$html,$xpath,$queries]=ranking_ui_render(['mmaf_run'=>$a,'mmaf_board'=>'overall','per_page'=>25,'paged'=>2]);
    $shown=$xpath->query('//table[contains(@class,"mmaf-ranking-results")]/tbody/tr');
    ranking_ui_check($shown->length===3 && str_contains($shown->item(0)->textContent,'Ranking admin fixture 26'),'selected snapshot paginates by canonical rank rather than always displaying the latest run');
    ranking_ui_check($xpath->query('//select[@name="mmaf_run"]/option[@selected and @value="'.$a.'"]')->length===1 && str_contains($html,'Direct score'),'run selector preserves selected v1.5 snapshot and direct score contract');
    $href=$xpath->query('//a[contains(@class,"page-numbers") and contains(@href,"paged=1")]')->item(0)->getAttribute('href');parse_str(parse_url(html_entity_decode($href),PHP_URL_QUERY),$args);
    ranking_ui_check($args['mmaf_run']==$a && $args['mmaf_board']==='overall' && $args['per_page']==25,'pagination preserves run, board and page size');
    ranking_ui_check(!array_filter($queries,static fn($sql)=>str_contains($sql,'source_summary_json') || str_contains($sql,'payload_json')),'ordinary admin GET omits full warning diagnostics and private profile payloads');
    [$html,$xpath]=ranking_ui_render(['mmaf_run'=>$a,'mmaf_board'=>'invalid_board','per_page'=>25]);
    ranking_ui_check($xpath->query('//select[@name="mmaf_board"]/option[@selected and @value="overall"]')->length===1,'unavailable board falls back to an actual board for the selected snapshot');
    ranking_ui_check(str_contains($html,'<dd>0.000</dd>') && str_contains($html,'<dd>-</dd>') && str_contains($html,'Missing prefight record'),'score details preserve known zero, unknown components and recorded warning reasons');
    [$legacy]=ranking_ui_render(['mmaf_run'=>$b]);
    ranking_ui_check(str_contains($legacy,'Total (0–100)') && str_contains($legacy,'Performance raw') && str_contains($legacy,'Confidence (%)'),'historical formula retains its own score and confidence presentation');
    [$missing,$xpath]=ranking_ui_render(['mmaf_run'=>PHP_INT_MAX]);
    ranking_ui_check(str_contains($missing,'Ranking run not found') && $xpath->query('//table[contains(@class,"mmaf-ranking-results")]')->length===0,'unknown run cannot silently display another calculation');
    $empty=$runs->create('v1.5',[],'2026-10-12','2026-10-12 14:00:00','running','{}');
    [$emptyHtml,$xpath]=ranking_ui_render(['mmaf_run'=>$empty]);
    ranking_ui_check(str_contains($emptyHtml,'No ranked fighters in this calculation') && $xpath->query('//form[input[@name="ranking_run_id" and @value="'.$empty.'"]]')->length===0,'unfinished empty calculation has a clear empty state and no activation form');
    [$diagnostic,$xpath]=ranking_ui_render(['mmaf_run'=>$b,'mmaf_diagnostics'=>'1']);
    ranking_ui_check(str_contains($diagnostic,'Selected snapshot warning breakdown') && str_contains($diagnostic,'missing_prefight_record'),'explicit read-only diagnostics remain available for the selected run');
    ranking_ui_check($xpath->query('//input[@name="mmaf_rankings_nonce"]')->length===1 && $xpath->query('//form[input[@name="ranking_run_id" and @value="'.$b.'"]]/input[@name="mmaf_rankings_activation_nonce"]')->length===1,'calculate and per-run activation retain their separate nonce contracts');
    ranking_ui_check($runs->count()===$runCount+1 && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$o['jobs']}")===$jobCount && $runs->get_active_ranking_run_id()===$originalActive && hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)))===$originalHash,'all preview and diagnostic GETs leave jobs, active ranking and live rows unchanged');
    $savedPost=$_POST;$savedGet=$_GET;$_GET=['page'=>'mmaf-rankings'];$_SERVER['REQUEST_METHOD']='POST';$_POST=['mmaf_action'=>'calculate_draft'];
    $die=static fn()=>static function($message){throw new RuntimeException(strip_tags($message));};add_filter('wp_die_handler',$die);
    try{
        $denied=false;wp_set_current_user(0);try{RankingsPage::handle_calculation_request();}catch(RuntimeException $e){$denied=str_contains($e->getMessage(),'Access denied');}
        ranking_ui_check($denied && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$o['jobs']}")===$jobCount,'header-safe calculation handler rejects unauthorized requests before any job is created');
        $denied=false;wp_set_current_user(1);try{RankingsPage::handle_calculation_request();}catch(RuntimeException $e){$denied=str_contains($e->getMessage(),'Missing security token');}
        ranking_ui_check($denied && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$o['jobs']}")===$jobCount,'header-safe calculation handler preserves the required nonce and leaves invalid requests without jobs');
    }finally{remove_filter('wp_die_handler',$die);$_POST=$savedPost;$_GET=$savedGet;$_SERVER['REQUEST_METHOD']='GET';wp_set_current_user(1);}
    echo 'METRICS '.wp_json_encode(['peak_memory'=>memory_get_peak_usage(true)])."\n";
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();$_SERVER['REQUEST_METHOD']=$savedMethod;}
