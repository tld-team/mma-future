<?php
require dirname(__DIR__,2).'/ops/scale-bootstrap.php';
use MMAF\DataEngine\Migrations\{Schema,MigrationRunner};
use MMAF\DataEngine\Services\Operations\{Calculation,Jobs,Readiness,Revision};
use MMAF\DataEngine\Repositories\{FighterRepository,PublicReadRepository};
$mode=$argv[1]??'';
if($mode==='install'){
 require ABSPATH.'wp-admin/includes/upgrade.php';wp_install('Synthetic scale','fixture','fixture@example.test',false,'','synthetic-fixture-only');
 update_option('active_plugins',['mma-future-data-engine/mma-future-data-engine.php']);update_option('stylesheet','mma-future');update_option('template','mma-future');
 require ABSPATH.'wp-content/plugins/mma-future-data-engine/mma-future-data-engine.php';MigrationRunner::run();echo "Synthetic schema installed.\n";exit;
}
$t=Schema::table_names();$size=DB_NAME==='mmaf_scale_small'?10000:100000;$bouts=$size*5;
function scale_query(string $sql):void{global $wpdb;if(false===$wpdb->query($sql)){throw new RuntimeException($wpdb->last_error);}}
if($mode==='seed'){
 if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['fighters']}")>0){throw new RuntimeException('Scale database is not empty.');}
 $start=microtime(true);
 for($base=1;$base<=$size;$base+=1000){$f=[];$sources=[];$posts=[];
  for($id=$base;$id<min($base+1000,$size+1);++$id){$source=780000000+$id;$post=300000+$id;$name='Synthetic fighter '.$id;$url='https://www.tapology.com/fightcenter/fighters/'.$source.'-synthetic';
   $f[]="($id,$post,'$name','$name','male','2000-01-01','lightweight','verified',1,1,'rankable',0,'2026-10-01 00:00:00')";
   $sources[]="($id,'tapology','tapology_fighter_$source','$url','".hash('sha256',$url)."',1,'2026-10-01 00:00:00')";
   $posts[]="($post,'$name','synthetic-$id','mmaf_fighter','publish','2026-10-01 00:00:00','2026-10-01 00:00:00')";
  }
  scale_query("INSERT INTO {$t['fighters']} (id,wp_post_id,display_name,normalized_name,gender,date_of_birth,weight_class,status,is_public,is_rankable,rankability_status,deleted_soft,created_at) VALUES ".implode(',',$f));
  scale_query("INSERT INTO {$t['fighter_sources']} (fighter_id,source_type,source_fighter_id,source_url,identity_hash,is_primary,created_at) VALUES ".implode(',',$sources));
  scale_query("INSERT INTO {$wpdb->posts} (ID,post_title,post_name,post_type,post_status,post_date,post_date_gmt) VALUES ".implode(',',$posts));
 }
 for($base=1;$base<=$bouts;$base+=1000){$events=[];$bs=[];$ps=[];
  for($id=$base;$id<min($base+1000,$bouts+1);++$id){$event=(int)ceil($id/100);if(($id-1)%100===0){$events[]="($event,'Synthetic event $event','2026-10-01','valid',0,'2026-10-01 00:00:00')";}
   $a=1+2*(($id-1)%($size/2));$b=$a+1;$method=['decision','ko_tko','submission'][$id%3];
   $bs[]="($id,$event,'lightweight','$method','win_loss','valid',1,0,'2026-10-01 00:00:00')";
   foreach([[$a,$b,'fighter_a','win',1],[$b,$a,'fighter_b','loss',0]] as [$f,$opp,$role,$result,$win]){$ps[]="($id,$f,$opp,'$role','$result',$win,5,2,0,0,5,2,0,0,3,'2026-10-01 00:00:00')";}
  }
  scale_query("INSERT INTO {$t['events']} (id,event_name,event_date,status,deleted_soft,created_at) VALUES ".implode(',',$events));
  scale_query("INSERT INTO {$t['bouts']} (id,event_id,weight_class,method_category,result_type,status,is_scoring_candidate,deleted_soft,created_at) VALUES ".implode(',',$bs));
  scale_query("INSERT INTO {$t['bout_participants']} (bout_id,fighter_id,opponent_fighter_id,participant_role,result_for_fighter,is_winner,prefight_wins,prefight_losses,prefight_draws,prefight_nc,opponent_prefight_wins,opponent_prefight_losses,opponent_prefight_draws,opponent_prefight_nc,opponent_prefight_diff,created_at) VALUES ".implode(',',$ps));
 }
 echo json_encode(['fighters'=>$size,'bouts'=>$bouts,'seed_seconds'=>microtime(true)-$start,'peak'=>memory_get_peak_usage(true)])."\n";exit;
}
if($mode==='calculate'){
 $metrics=[];
 foreach(['stats','ranking'] as $kind){$job=Calculation::create($kind,0,1,'scale:'.$kind);$steps=0;$start=microtime(true);$queryStart=$wpdb->num_queries;
  do{$r=Calculation::step($job);if($r['status']==='failed' || $r['error_message']){throw new RuntimeException($r['error_message']);}++$steps;if($steps%100===0){echo json_encode(['kind'=>$kind,'phase'=>$r['phase'],'steps'=>$steps,'peak'=>memory_get_peak_usage(true)])."\n";flush();wp_cache_flush();}}while($r['status']!=='completed');
  $metrics[$kind]=['seconds'=>microtime(true)-$start,'queries'=>$wpdb->num_queries-$queryStart,'steps'=>$steps,'peak'=>memory_get_peak_usage(true),'operation_id'=>$job];
 }
 $repo=new FighterRepository();$start=microtime(true);$q=$wpdb->num_queries;$page=$repo->list('',25,0,[]);Readiness::read(array_column($page,'id'));\MMAF\DataEngine\Services\DataPipeline\Processor::metadata(array_column($page,'id'));
 $metrics['admin_page']=['seconds'=>microtime(true)-$start,'queries'=>$wpdb->num_queries-$q,'rows'=>count($page)];
 $run=(int)$r['summary']['ranking_run_id'];$public=new PublicReadRepository();$start=microtime(true);$q=$wpdb->num_queries;$rows=$public->rankings($run,'male',['q'=>'Synthetic fighter '.($size-1),'per_page'=>25]);
 $metrics['search']=['seconds'=>microtime(true)-$start,'queries'=>$wpdb->num_queries-$q,'total'=>$rows['total']];
 $job=Jobs::create('validate_selected_for_ranking',['mode'=>'filtered','search'=>'Synthetic fighter'],1,'scale:manifest');
 $metrics['manifest']=['total'=>(int)$job['total'],'memory'=>memory_get_peak_usage(true)];Jobs::control((int)$job['id'],'cancel',1);
 // Refresh table statistics before reporting size; recently bulk-loaded
 // InnoDB estimates otherwise understate the smaller fixture markedly.
 $tables=$wpdb->get_col('SHOW TABLES');scale_query('ANALYZE TABLE `'.implode('`,`',$tables).'`');
 $metrics['disk_database']=(int)$wpdb->get_var($wpdb->prepare('SELECT SUM(data_length+index_length) FROM information_schema.tables WHERE table_schema=%s',DB_NAME));
 $metrics['largest_tables']=$wpdb->get_results($wpdb->prepare('SELECT table_name,data_length,index_length FROM information_schema.tables WHERE table_schema=%s ORDER BY data_length+index_length DESC LIMIT 8',DB_NAME),ARRAY_A);
 $metrics['snapshots']=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t['ranking_snapshots']);
 if($metrics['search']['total']!==1 || $metrics['manifest']['total']!==$size || memory_get_peak_usage(true)>256*1024*1024){throw new RuntimeException('Scale acceptance failed.');}
 echo 'SCALE_RESULT '.json_encode($metrics)."\n";
}
