<?php
namespace MMAF\DataEngine\Services;
use MMAF\DataEngine\Migrations\Schema;

final class FighterPublicationService {
 public function reconcile(bool $apply=false,int $actor=0): array {
  global $wpdb;
  if($apply){\MMAF\DataEngine\Services\RegularImport\WriteGuard::assertAllowed();}
  $t=Schema::table_names();$report=array('changes'=>array(),'review'=>array(),'checked'=>0);
  $rows=$wpdb->get_results("SELECT f.* FROM {$t['fighters']} f LEFT JOIN {$wpdb->posts} p ON p.ID=f.wp_post_id WHERE (f.is_public=1 AND f.deleted_soft=0) OR (p.post_type='mmaf_fighter' AND p.post_status='publish') ORDER BY f.id",ARRAY_A);
  foreach($rows as $f){
   ++$report['checked'];$post=!empty($f['wp_post_id'])?get_post((int)$f['wp_post_id']):null;
   if($post&&$post->post_type!=='mmaf_fighter'){$report['review'][]=array('fighter_id'=>(int)$f['id'],'reason'=>'Linked post has the wrong type.');continue;}
   $desired=!empty($f['is_public'])&&empty($f['deleted_soft'])?'publish':'draft';
   if($post&&$post->post_status===$desired&&$post->post_title===$f['display_name']){continue;}
   $before=$post?array('post_id'=>$post->ID,'status'=>$post->post_status,'slug'=>$post->post_name):null;
   $change=array('fighter_id'=>(int)$f['id'],'before'=>$before,'desired_status'=>$desired);
   if($apply){$post_id=(new FighterPostSyncService())->sync((int)$f['id'],$f);$saved=get_post($post_id);$change['after']=array('post_id'=>$post_id,'status'=>$saved->post_status,'slug'=>$saved->post_name);(new AuditLogService())->write('fighter_publication_reconciled','fighter',(int)$f['id'],$before,$change['after'],'Preserved canonical publication decision',$actor);}
   $report['changes'][]=$change;
  }
  if($apply&&$report['changes']){do_action('mmaf_public_data_changed','publication');}
  return $report;
 }
}
