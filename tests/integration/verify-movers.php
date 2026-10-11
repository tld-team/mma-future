<?php
require __DIR__.'/bootstrap.php';
use MMAF\DataEngine\Repositories\{RankingRunRepository,PublicReadRepository};
use MMAF\DataEngine\Services\{PublicReadService,AuditLogService};
use MMAF\DataEngine\Services\RegularImport\{WriteGuard,Transaction};
use MMAF\DataEngine\Migrations\Schema;
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
function check($ok,$why){if(!$ok){throw new RuntimeException($why);}echo "PASS: $why\n";}
$t=Schema::table_names();$runs=new RankingRunRepository();$base=$runs->active();$original=$runs->get_active_ranking_run_id();$service=new PublicReadService();
$sample=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d AND board_key='overall' LIMIT 1",$original),ARRAY_A);
$ids=$wpdb->get_col("SELECT f.id FROM {$t['fighters']} f JOIN {$wpdb->posts} p ON p.ID=f.wp_post_id WHERE f.is_public=1 AND f.deleted_soft=0 AND p.post_type='mmaf_fighter' AND p.post_status='publish' ORDER BY f.id LIMIT 6");
function event($id){global $runs; $runs->set_active_ranking_run_id($id);$audit=(new AuditLogService())->write('ranking_activated','ranking_run',$id,null,['status'=>'activated','ranking_run_id'=>$id,'boards'=>['overall']],'Isolated fixture',1);\MMAF\DataEngine\Services\Operations\Activations::record($id,['overall'],gmdate('Y-m-d H:i:s'),1,$audit);}
function snapshots($run,$positions){global $wpdb,$t,$sample,$ids;foreach($positions as $index=>$rank){$r=$sample;unset($r['id']);$r['ranking_run_id']=$run;$r['fighter_id']=$ids[$index];$r['rank_position']=$rank;if(false===$wpdb->insert($t['ranking_snapshots'],$r)){throw new RuntimeException($wpdb->last_error);}}}
WriteGuard::$worker=true;Transaction::begin();
try{
 $a=$runs->create('v1.5',json_decode($base['formula_config_snapshot'],true),'2026-10-08','2026-10-08 10:00:00','completed','fixture A');
 $b=$runs->create('v1.5',json_decode($base['formula_config_snapshot'],true),'2026-10-08','2026-10-08 11:00:00','completed','fixture B');
 $scope=$runs->find($a)['data_scope_version'];snapshots($a,[12,10,8,7,1]);snapshots($b,[3,2,1,7,9,4]);event($a);event($b);$m=$service->movers();
 check($m['status']==='available'&&array_column($m['items'],'rank_change')===[9,8,7],'three largest positive changes are selected');
 check($m['new_entries'][0]['label']==='New'&&$m['new_entries'][0]['rank_change']===null,'new entry has a New label without numeric movement');
 event($b);$m=$service->movers();check($m['previous_ranking_run_id']===$a,'repeat activation is not a new comparison point');
 event($a);$m=$service->movers();check($m['previous_ranking_run_id']===$b&&$m['active_ranking_run_id']===$a,'rollback compares activation order instead of run ID order');
 event($b);$runs->update($b,['data_scope_version'=>'different_scope']);check($service->movers()['status']==='unavailable','incompatible career scope disables comparison');
 $runs->update($b,['data_scope_version'=>$scope,'formula_version'=>'v1.4']);check($service->movers()['status']==='unavailable','different formula disables comparison');
 $runs->update($b,['formula_version'=>'v1.5','formula_config_snapshot'=>'{"fixture_changed_rule":true}']);check($service->movers()['status']==='unavailable','changed formula rules disable comparison');
 $runs->update($b,['formula_config_snapshot'=>$base['formula_config_snapshot']]);
 $wpdb->query($wpdb->prepare("DELETE FROM {$t['ranking_snapshots']} WHERE ranking_run_id IN (%d,%d)",$a,$b));snapshots($a,[5,6,10,7,1]);snapshots($b,[1,2,8,7,9,4]);$m=$service->movers();
 check(array_column(array_slice($m['items'],0,2),'fighter_id')===[(int)$ids[0],(int)$ids[1]],'equal movement resolves by better current rank');
 $wpdb->query($wpdb->prepare("DELETE FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d",$b));snapshots($b,[5,6,10,7,1]);$m=$service->movers();check($m['status']==='unchanged'&&$m['items']===[],'board without rises has an explicit empty state');
 check($service->movers('female')['status']==='unavailable','board absent from previous activation is unavailable');
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
check($runs->get_active_ranking_run_id()===$original,'fixture restores the active ranking without a real activation');
