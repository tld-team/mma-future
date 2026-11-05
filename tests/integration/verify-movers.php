<?php
require __DIR__.'/bootstrap.php';
use MMAF\DataEngine\Repositories\{RankingRunRepository,PublicReadRepository};
use MMAF\DataEngine\Services\{PublicReadService,AuditLogService};
use MMAF\DataEngine\Services\RegularImport\{WriteGuard,Transaction};
use MMAF\DataEngine\Migrations\Schema;
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
function check($ok,$why){if(!$ok){throw new RuntimeException($why);}echo "PASS: $why\n";}
$t=Schema::table_names();$runs=new RankingRunRepository();$original=$runs->get_active_ranking_run_id();$service=new PublicReadService();
$config=\MMAF\DataEngine\Services\Formula\FormulaV17::config();$ids=[];
function event($id){global $runs; $runs->set_active_ranking_run_id($id);$audit=(new AuditLogService())->write('ranking_activated','ranking_run',$id,null,['status'=>'activated','ranking_run_id'=>$id,'boards'=>['overall']],'Isolated fixture',1);\MMAF\DataEngine\Services\Operations\Activations::record($id,['overall'],gmdate('Y-m-d H:i:s'),1,$audit);}
function snapshots($run,$positions){global $wpdb,$t,$ids;foreach($positions as $index=>$rank){$r=['ranking_run_id'=>$run,'board_key'=>'overall','fighter_id'=>$ids[$index],'rank_position'=>$rank,'total_score'=>20-$rank,'raw_score'=>20-$rank,'sample_size'=>2,'created_at'=>gmdate('Y-m-d H:i:s')];if(false===$wpdb->insert($t['ranking_snapshots'],$r)){throw new RuntimeException($wpdb->last_error);}}}
WriteGuard::$worker=true;Transaction::begin();
try{
 for($i=0;$i<6;$i++){
     $post=wp_insert_post(['post_type'=>'mmaf_fighter','post_status'=>'publish','post_title'=>'Synthetic mover '.$i]);
     $ids[]=(new \MMAF\DataEngine\Repositories\FighterRepository())->insert(['wp_post_id'=>$post,'display_name'=>'Synthetic mover '.$i,'normalized_name'=>'synthetic mover '.$i,'is_public'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);
 }
 $a=$runs->create('v1.7',$config,'2026-10-08','2026-10-08 10:00:00','completed','fixture A');
 $b=$runs->create('v1.7',$config,'2026-10-08','2026-10-08 11:00:00','completed','fixture B');
 $scope=$runs->find($a)['data_scope_version'];snapshots($a,[5,4,3,2,1]);snapshots($b,[1,2,3,4,5,6]);event($a);event($b);$m=$service->movers();
 check($m['status']==='available'&&array_column($m['items'],'rank_change')===[4,-4,2],'largest absolute changes include rises and falls, with deterministic ties');
 check(array_column($m['items'],'direction')===['up','down','up'],'movement direction follows the signed position difference');
 check($m['new_entries'][0]['label']==='New'&&$m['new_entries'][0]['rank_change']===null,'new entry has a New label without numeric movement');
 event($b);$m=$service->movers();check($m['previous_ranking_run_id']===$a,'repeat activation is not a new comparison point');
 event($a);$m=$service->movers();check($m['previous_ranking_run_id']===$b&&$m['active_ranking_run_id']===$a,'rollback compares activation order instead of run ID order');
 event($b);$runs->update($b,['data_scope_version'=>'different_scope']);check($service->movers()['status']==='unavailable','incompatible career scope disables comparison');
 $runs->update($b,['data_scope_version'=>$scope,'formula_version'=>'v1.6']);check($service->movers()['status']==='unavailable','different formula disables comparison');
 $runs->update($b,['formula_version'=>'v1.7','formula_config_snapshot'=>'{"fixture_changed_rule":true}']);check($service->movers()['status']==='unavailable','changed formula rules disable comparison');
 $runs->update($b,['formula_config_snapshot'=>wp_json_encode($config)]);
 $wpdb->query($wpdb->prepare("DELETE FROM {$t['ranking_snapshots']} WHERE ranking_run_id IN (%d,%d)",$a,$b));snapshots($a,[3,4,1,2,5]);snapshots($b,[1,2,3,4,5,6]);$m=$service->movers();
 check(array_column(array_slice($m['items'],0,2),'fighter_id')===[(int)$ids[0],(int)$ids[1]],'equal movement resolves by better current rank');
 $wpdb->query($wpdb->prepare("DELETE FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d",$b));snapshots($b,[3,4,1,2,5]);$m=$service->movers();check($m['status']==='unchanged'&&$m['items']===[],'identical rankings have an explicit unchanged state');
 $wpdb->query($wpdb->prepare("DELETE FROM {$t['ranking_snapshots']} WHERE ranking_run_id IN (%d,%d)",$a,$b));snapshots($a,[1,2,3,4,5]);snapshots($b,[2,3,4,5,6,1]);$m=$service->movers();
 check($m['status']==='available'&&array_column($m['items'],'rank_change')===[-1,-1,-1],'a board with only declines still shows movers');
 check($m['new_entries'][0]['current_rank']===1 && $m['new_entries'][0]['rank_change']===null,'newcomers retain their current rank without fabricated movement');
 $wpdb->query($wpdb->prepare("DELETE FROM {$t['ranking_snapshots']} WHERE ranking_run_id IN (%d,%d)",$a,$b));snapshots($a,[5,4,3,2,1]);snapshots($b,[1,2,3,4,5,6]);
 $wpdb->update($t['fighters'],['in_ufc'=>1],['id'=>$ids[2]]);$m=$service->movers();
 check($m['status']==='available'&&array_column($m['items'],'rank_change')===[3,-3,1],'a UFC signing does not disable movement for the remaining fighters');
 check(!in_array((int)$ids[2],array_column($m['items'],'fighter_id'),true) && $m['new_entries'][0]['current_rank']===5,'UFC fighters are excluded before assigning contiguous comparison and newcomer ranks');
 $visible=(new PublicReadRepository())->rank_map($b,'overall');
 foreach($m['items'] as $item){check($visible[$item['fighter_id']]===$item['current_rank'],'mover current rank matches the visible public ranking');}
 $limited=$service->movers('overall',1,['include_new'=>false]);
 check(count($limited['items'])===1 && !$limited['new_entries'],'homepage limit and omitted newcomer payload remain bounded');
 $wpdb->update($t['fighters'],['in_ufc'=>0,'is_public'=>0],['id'=>$ids[2]]);$m=$service->movers();
 check(array_column($m['items'],'rank_change')===[3,-3,1],'private fighters cannot distort the visible comparison');
 $wpdb->update($t['fighters'],['is_public'=>1],['id'=>$ids[2]]);
 $post=(int)$wpdb->get_var($wpdb->prepare("SELECT wp_post_id FROM {$t['fighters']} WHERE id=%d",$ids[2]));
 $wpdb->update($wpdb->posts,['post_status'=>'draft'],['ID'=>$post]);$m=$service->movers();
 check(array_column($m['items'],'rank_change')===[3,-3,1],'unpublished fighter posts are excluded from both comparison ranks');
 check($service->movers('female')['status']==='unavailable','board absent from previous activation is unavailable');
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
check($runs->get_active_ranking_run_id()===$original,'fixture restores the active ranking without a real activation');
