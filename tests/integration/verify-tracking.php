<?php
require __DIR__.'/bootstrap.php';
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getLine().PHP_EOL);exit(1);});

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\PublicReadRepository;
use MMAF\DataEngine\Services\RegularImport\Transaction;
use MMAF\DataEngine\Services\StatsRebuildService;
use MMAF\DataEngine\Services\TrackingService;

function tracking_expect($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}

global $wpdb;$t=Schema::table_names();
$fixture=$wpdb->get_row("SELECT p.fighter_id,p.opponent_fighter_id,b.id bout_id,opp.display_name opponent_name,opp.wp_post_id opponent_post,s.calculated_at FROM {$t['bout_participants']} p JOIN {$t['bouts']} b ON b.id=p.bout_id JOIN {$t['fighters']} active ON active.id=p.fighter_id AND active.is_public=1 AND active.deleted_soft=0 JOIN {$t['fighters']} opp ON opp.id=p.opponent_fighter_id AND opp.deleted_soft=0 LEFT JOIN {$t['fighter_stats_current']} s ON s.fighter_id=opp.id WHERE b.deleted_soft=0 AND b.status IN ('valid','completed') AND s.fighter_id IS NOT NULL ORDER BY b.id DESC LIMIT 1",ARRAY_A);
if(!$fixture){throw new RuntimeException('Fixture needs a public fighter with one recorded opponent and a current stats row.');}
$run=(int)(new MMAF\DataEngine\Repositories\RankingRunRepository())->get_active_ranking_run_id();
$score_before=$wpdb->get_var($wpdb->prepare("SELECT total_score FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d AND fighter_id=%d ORDER BY id LIMIT 1",$run,$fixture['fighter_id']));
$identity_before=$wpdb->get_row($wpdb->prepare("SELECT f.id,f.display_name,s.source_fighter_id,s.source_url FROM {$t['fighters']} f LEFT JOIN {$t['fighter_sources']} s ON s.fighter_id=f.id AND s.source_type='tapology' WHERE f.id=%d ORDER BY s.is_primary DESC,s.id LIMIT 1",$fixture['opponent_fighter_id']),ARRAY_A);

Transaction::begin();
try{
    $service=new TrackingService();
    $result=$service->set_mode([(int)$fixture['opponent_fighter_id']],TrackingService::OPPONENT_ONLY,'integration_archive','https://example.test/tracking-evidence','admin',1);
    tracking_expect($result['updated']===1,'opponent-only decision is applied once');
    $archived=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighters']} WHERE id=%d",$fixture['opponent_fighter_id']),ARRAY_A);
    tracking_expect($archived['tracking_mode']==='opponent_only' && !(int)$archived['is_public'] && !(int)$archived['is_rankable'] && !(int)$archived['deleted_soft'],'archive retains identity and history while disabling publication and ranking');
    tracking_expect((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['bout_participants']} WHERE fighter_id=%d OR opponent_fighter_id=%d",$fixture['opponent_fighter_id'],$fixture['opponent_fighter_id']))>0,'archive keeps canonical bout relationships');
    $identity_after=$wpdb->get_row($wpdb->prepare("SELECT f.id,f.display_name,s.source_fighter_id,s.source_url FROM {$t['fighters']} f LEFT JOIN {$t['fighter_sources']} s ON s.fighter_id=f.id AND s.source_type='tapology' WHERE f.id=%d ORDER BY s.is_primary DESC,s.id LIMIT 1",$fixture['opponent_fighter_id']),ARRAY_A);
    tracking_expect($identity_after==$identity_before,'archive preserves internal and Tapology identity');
    $score_after=$wpdb->get_var($wpdb->prepare("SELECT total_score FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d AND fighter_id=%d ORDER BY id LIMIT 1",$run,$fixture['fighter_id']));
    tracking_expect((string)$score_after===(string)$score_before,'archiving an opponent does not rewrite historical score snapshots');
    $history=(new PublicReadRepository())->fights((int)$fixture['fighter_id'],['per_page'=>100]);$fight=array_values(array_filter($history['rows'],fn($row)=>(int)$row['bout_id']===(int)$fixture['bout_id']))[0]??null;
    tracking_expect($fight && $fight['opponent_display_name']===$fixture['opponent_name'] && empty($fight['opponent_wp_post_id']),'public history keeps the opponent name without a profile link');
    $carried=iterator_to_array((new StatsRebuildService())->stats_rows([(int)$fixture['opponent_fighter_id']]));
    tracking_expect(count($carried)===1 && $carried[0]['_tracking_stats_strategy']==='carried' && $carried[0]['calculated_at']===$fixture['calculated_at'],'opponent-only stats carry their original calculation date');
    $manifest=$service->export_manifest();$decision=array_values(array_filter($manifest['fighters'],fn($row)=>(int)$row['fighter_id']===(int)$fixture['opponent_fighter_id']))[0]??null;
    tracking_expect($manifest['schema_version']==='mmaf_tracking_decisions_v1' && $decision && $decision['tracking_mode']==='opponent_only','private scraper export contains the current canonical decision');
    $repeat=$service->set_mode([(int)$fixture['opponent_fighter_id']],TrackingService::OPPONENT_ONLY,'integration_archive','https://example.test/tracking-evidence','admin',1);
    tracking_expect($repeat['updated']===0 && $repeat['unchanged']===1,'repeating the same decision is idempotent');
    $restored=$service->set_mode([(int)$fixture['opponent_fighter_id']],TrackingService::NORMAL,'integration_return','','admin',1);
    $review=$wpdb->get_row($wpdb->prepare("SELECT tracking_mode,is_public,is_rankable,rankability_status FROM {$t['fighters']} WHERE id=%d",$fixture['opponent_fighter_id']),ARRAY_A);
    tracking_expect($restored['updated']===1 && $review['tracking_mode']==='normal' && !(int)$review['is_public'] && !(int)$review['is_rankable'] && $review['rankability_status']==='pending_review','restoring tracking waits for the existing admin approval flow');
}finally{Transaction::rollback();}
echo "Tracking integration checks completed.\n";
