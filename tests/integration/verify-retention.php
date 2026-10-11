<?php
require __DIR__.'/bootstrap.php';
use MMAF\DataEngine\Services\Operations\{Archive,ReviewLinks,Jobs,Schema as Operations};
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard,Repository};
use MMAF\DataEngine\Migrations\{Schema,MigrationRunner};
function retention_assert($ok,$message){if(!$ok){throw new RuntimeException($message);}echo "PASS: $message\n";}
function restore_archive($id){for($i=0;$i<1000;$i++){$job=Archive::step($id);if($job['status']==='verified'){return $job;}if($job['status']==='failed'){throw new RuntimeException($job['error_message']);}}throw new RuntimeException('Archive did not finish');}
MigrationRunner::run();wp_set_current_user(1);global $wpdb;$t=Schema::table_names();$o=Operations::tables();
$before=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)));
Transaction::begin();WriteGuard::$worker=true;
try{
    $fighter=(int)$wpdb->get_var("SELECT MIN(id) FROM {$t['fighters']}");
    if(!$fighter){Repository::check($wpdb->insert($t['fighters'],['display_name'=>'Archive fixture','normalized_name'=>'archive fixture','created_at'=>gmdate('Y-m-d H:i:s')]));$fighter=(int)$wpdb->insert_id;}
    $reviews=[];
    for($n=0;$n<3;$n++){
        Repository::check($wpdb->insert($t['review_items'],['item_type'=>'fighter','item_key'=>'archive-fixture-'.wp_generate_uuid4(),'canonical_id'=>$fighter,'status'=>'resolved','notes'=>wp_json_encode(['context'=>['fighter_id'=>$fighter],'evidence'=>'Original confirmed evidence '.$n]),'created_at'=>'2024-01-01 00:00:00','resolved_at'=>'2024-01-02 00:00:00','updated_at'=>'2024-01-02 00:00:00']));
        $reviews[]=(int)$wpdb->insert_id;ReviewLinks::record(end($reviews),['fighter_id'=>$fighter]);
    }
    $id=Archive::create('review');$job=restore_archive($id);
    retention_assert($job['checkpoint']['exported']>=3 && $job['checkpoint']['restored']===$job['checkpoint']['exported'],'archive performs an actual isolated SQL restore with row hash parity');
    retention_assert(str_contains($wpdb->get_var($wpdb->prepare("SELECT notes FROM {$t['review_items']} WHERE id=%d",$reviews[0])),'Original confirmed evidence'),'successful archive verification alone does not remove live evidence');
    $wpdb->update($t['review_items'],['notes'=>'An editor added new evidence'],['id'=>$reviews[1]]);
    $operation=Jobs::create('validate_selected_for_ranking',['mode'=>'ids','ids'=>[$fighter]],1,'archive-reference-'.wp_generate_uuid4());ReviewLinks::link($reviews[2],'operation',(int)$operation['id'],'new_reference_after_preview');
    for($n=0;$n<1000;$n++){$job=Archive::step($id,true);if($job['status']==='completed'){break;}}
    retention_assert($job['status']==='completed','verified archive removal completes in bounded restartable steps');
    retention_assert(str_contains($wpdb->get_var($wpdb->prepare("SELECT notes FROM {$t['review_items']} WHERE id=%d",$reviews[0])),'archive_id'),'closed evidence retains an indexed archive reference');
    retention_assert($wpdb->get_var($wpdb->prepare("SELECT notes FROM {$t['review_items']} WHERE id=%d",$reviews[1]))==='An editor added new evidence','changed row after export is retained');
    retention_assert(str_contains($wpdb->get_var($wpdb->prepare("SELECT notes FROM {$t['review_items']} WHERE id=%d",$reviews[2])),'Original confirmed evidence'),'new open operation reference prevents removal after verification');
    $wpdb->update($t['review_items'],['updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$reviews[1]]);
    $another=Archive::create('review');$recent=restore_archive($another);
    retention_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o['archive_items']} WHERE archive_id=%d AND source_id=%d",$another,$reviews[1]))===0,'recently updated evidence is protected even when its original resolution is old');
    $legacy=(new MMAF\DataEngine\Repositories\RankingRunRepository())->create('v1.5',[],'2024-01-01','2024-01-01 00:00:00','completed','Legacy unknown activation fixture');
    $wpdb->update($t['ranking_runs'],['created_at'=>'2024-01-01 00:00:00','data_snapshot_json'=>'{}'],['id'=>$legacy]);
    retention_assert(!Archive::draftEligible($legacy),'unknown historical activation is protected');
    $runs=[];for($n=0;$n<12;$n++){
        $run=(new MMAF\DataEngine\Repositories\RankingRunRepository())->create('v1.5',[],'2024-01-01','2024-01-01 00:00:00','completed','Retention fixture');$runs[]=$run;
        $wpdb->update($t['ranking_runs'],['created_at'=>'2024-01-01 00:00:00','data_snapshot_json'=>wp_json_encode(['activation_tracking'=>'ledger_v1'])],['id'=>$run]);
    }
    retention_assert(Archive::draftEligible($runs[0]) && !Archive::draftEligible(end($runs)),'at least the latest ten inactive drafts are retained');
    $wpdb->insert($o['activations'],['ranking_run_id'=>$runs[0],'board_key'=>'men','activated_at'=>'2024-02-01 00:00:00','actor_user_id'=>1]);
    retention_assert(!Archive::draftEligible($runs[0]),'ever-activated draft remains permanently protected');
    $wpdb->insert($t['ranking_snapshots'],['ranking_run_id'=>$runs[1],'board_key'=>'men','fighter_id'=>$fighter,'rank_position'=>1,'total_score'=>'1.123','created_at'=>'2024-01-01 00:00:00']);
    $draft=Archive::create('draft',$runs[1]);$verified=restore_archive($draft);
    file_put_contents(MMAF_OPERATIONS_DIR.'/archives/'.$draft.'/rows.ndjson','damaged',FILE_APPEND);
    $refused=false;try{Archive::step($draft,true);}catch(RuntimeException $e){$refused=true;}
    retention_assert($refused && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d",$runs[1]))===1,'corrupted verified archive stops deletion and preserves the snapshot');
    retention_assert(hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)))===$before,'archive processing never changes the active ranking');
    echo 'METRICS '.wp_json_encode(['peak_memory'=>memory_get_peak_usage(true)])."\n";
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
