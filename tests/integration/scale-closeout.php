<?php
require dirname(__DIR__,2).'/ops/scale-bootstrap.php';
use MMAF\DataEngine\Migrations\{MigrationRunner,Schema};
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Services\DataPipeline\Processor;
use MMAF\DataEngine\Services\Operations\{BulkWriter,Readiness,FactProtectionReport,Jobs};
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard};
MigrationRunner::run();wp_set_current_user(1);global $wpdb;$t=Schema::table_names();$metrics=[];
$size=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['fighters']}");
if(!in_array($size,[10000,100000],true)){throw new RuntimeException('An existing named synthetic scale fixture is required.');}
Transaction::begin();WriteGuard::$worker=true;
try{
    for($base=1;$base<=$size;$base+=250){$rows=[];for($id=$base;$id<min($base+250,$size+1);++$id){$rows[]=['entity_type'=>'fighter','entity_id'=>$id,'field_name'=>'height_cm','source_type'=>'manual_verified','locked'=>1,'created_at'=>'2024-01-01 00:00:00'];}BulkWriter::insert($t['field_provenance'],$rows);}
    $private=false;$inspect=static function($sql)use(&$private){$selection=preg_replace('/\bpayload_json\s+IS\s+NOT\s+NULL\b/i','payload_available',$sql);if(preg_match('/(?:payload_json|history_json|career_payload_json)/i',$selection)){$private=true;}return $sql;};
    add_filter('query',$inspect);$start=microtime(true);$queries=$wpdb->num_queries;
    $page=(new FighterRepository())->list('',25,0,[]);$ids=array_column($page,'id');$metadata=Processor::metadata($ids);Readiness::read($ids);
    foreach($page as $fighter){Readiness::decision($fighter,$metadata[$fighter['id']]??[]);}
    remove_filter('query',$inspect);if($private || count($page)!==25){throw new RuntimeException('Admin page read private profiles or the whole population.');}
    $metrics['admin_page']=['seconds'=>microtime(true)-$start,'queries'=>$wpdb->num_queries-$queries,'rows'=>25,'private_payload_read'=>false];
    $start=microtime(true);$queries=$wpdb->num_queries;$refreshed=Readiness::work(250);
    $metrics['readiness_batch']=['seconds'=>microtime(true)-$start,'queries'=>$wpdb->num_queries-$queries,'rows'=>$refreshed];
    $start=microtime(true);$queries=$wpdb->num_queries;$report=FactProtectionReport::create(1);FactProtectionReport::work(10);$progress=Jobs::get($report['id']);
    if((int)$progress['total']!==$size || (int)$progress['summary']['processed']!==10){throw new RuntimeException('Evidence report did not preserve its bounded scope.');}
    $metrics['protection_report_batch']=['seconds'=>microtime(true)-$start,'queries'=>$wpdb->num_queries-$queries,'rows'=>10,'total'=>$size];
    $metrics+=['fighters'=>$size,'bouts'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']}"),'peak_memory'=>memory_get_peak_usage(true)];
    if($metrics['peak_memory']>=256*1024*1024){throw new RuntimeException('Closeout memory budget exceeded.');}
    echo 'CLOSEOUT_SCALE_RESULT '.wp_json_encode($metrics)."\n";
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
