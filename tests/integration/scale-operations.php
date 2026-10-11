<?php
require dirname(__DIR__,2).'/ops/scale-bootstrap.php';
use MMAF\DataEngine\Migrations\{MigrationRunner,Schema};
use MMAF\DataEngine\Services\Operations\{Archive,BulkWriter,Readiness,ReviewLinks,Schema as Operations};
use MMAF\DataEngine\Services\RegularImport\{DatabaseState,Transaction,Repository,WriteGuard};
MigrationRunner::run();global $wpdb;$t=Schema::table_names();$o=Operations::tables();$size=DB_NAME==='mmaf_scale_small'?10000:100000;$metrics=[];
if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['field_provenance']}")!==0){throw new RuntimeException('A clean synthetic provenance fixture is required.');}
Transaction::begin();WriteGuard::$worker=true;
try{
 for($base=1;$base<=$size;$base+=250){$fields=[];for($id=$base;$id<min($base+250,$size+1);$id++){$fields[]=['entity_type'=>'fighter','entity_id'=>$id,'field_name'=>'height_cm','source_type'=>'manual_verified','locked'=>1,'created_at'=>'2024-01-01 00:00:00'];}BulkWriter::insert($t['field_provenance'],$fields);}
 $start=microtime(true);$queries=$wpdb->num_queries;$protected=DatabaseState::protectedState();
 $expected=DatabaseState::arrayHash((static function()use($size){for($id=1;$id<=$size;$id++){yield [['entity_type'=>'fighter','entity_id'=>(string)$id,'field_name'=>'height_cm'],null];}})());
 if($protected['manual_hash']!==$expected){throw new RuntimeException('Manual protection hash parity failed.');}
 $metrics['protection_hashes']=['seconds'=>microtime(true)-$start,'queries'=>$wpdb->num_queries-$queries,'manual_fields'=>$size,'hash_parity'=>true,'peak_memory'=>memory_get_peak_usage(true)];
 $start=microtime(true);$audit=(new MMAF\DataEngine\Services\Audit\FighterDuplicateAuditService())->audit(100);$metrics['duplicate_audit']=['seconds'=>microtime(true)-$start,'candidates'=>$audit['likely_duplicates_count'],'peak_memory'=>memory_get_peak_usage(true)];
 for($base=1;$base<=$size;$base+=250){$rows=[];for($id=$base;$id<min($base+250,$size+1);$id++){$rows[]=['item_type'=>'fighter','item_key'=>'scale-archive-'.$id,'canonical_id'=>1,'status'=>'resolved','notes'=>str_repeat('Synthetic evidence ',20),'created_at'=>'2024-01-01 00:00:00','resolved_at'=>'2024-01-02 00:00:00','updated_at'=>'2024-01-02 00:00:00'];}BulkWriter::insert($t['review_items'],$rows);}
 $cursor=0;do{$rows=$wpdb->get_results($wpdb->prepare("SELECT id FROM {$t['review_items']} WHERE id>%d ORDER BY id LIMIT 250",$cursor),ARRAY_A);$links=[];foreach($rows as $row){$cursor=(int)$row['id'];$links[]=['review_id'=>$cursor,'entity_type'=>'fighter','entity_id'=>1,'evidence'=>'synthetic_fixture'];}BulkWriter::insert($o['review_links'],$links);}while(count($rows)===250);
 $privateRead=false;$inspect=static function($sql)use(&$privateRead){if(preg_match('/(?:payload_json|history_json|career_payload_json)/i',$sql)){$privateRead=true;}return $sql;};add_filter('query',$inspect);
 if(getenv('MMAF_SCALE_PROFILE_QUERIES')){define('SAVEQUERIES',true);}
 $start=microtime(true);$queries=$wpdb->num_queries;$page=(new MMAF\DataEngine\Repositories\FighterRepository())->list('',25,0,[]);Readiness::read(array_column($page,'id'));MMAF\DataEngine\Services\DataPipeline\Processor::metadata(array_column($page,'id'));$reviews=ReviewLinks::for_fighter(1);
 remove_filter('query',$inspect);if($privateRead || count($reviews)!==25){throw new RuntimeException('Ordinary admin reads exceeded the public metadata contract.');}
 if(getenv('MMAF_SCALE_PROFILE_QUERIES')){$metrics['queries']=$wpdb->queries;}
 $metrics['admin_review']=['seconds'=>microtime(true)-$start,'queries'=>$wpdb->num_queries-$queries,'fighters'=>count($page),'reviews'=>count($reviews),'private_payload_read'=>false,'peak_memory'=>memory_get_peak_usage(true)];
 if(getenv('MMAF_SCALE_SKIP_ARCHIVE')){echo 'OPERATIONS_SCALE_RESULT '.wp_json_encode($metrics)."\n";return;}
 $start=microtime(true);$archive=Archive::create('review');$steps=0;
 do{$job=Archive::step($archive);$steps++;if($steps%200===0){echo 'PROGRESS '.wp_json_encode(['phase'=>$job['phase'],'steps'=>$steps,'peak_memory'=>memory_get_peak_usage(true)])."\n";flush();}if($steps>20000){throw new RuntimeException('Archive did not reach verification.');}}while($job['status']!=='verified');
 if($job['checkpoint']['exported']!==$size || $job['checkpoint']['restored']!==$size){throw new RuntimeException('Archive coverage differs.');}
 $verifySeconds=microtime(true)-$start;$removeStart=microtime(true);
 do{$job=Archive::step($archive,true);$steps++;}while($job['status']!=='completed');
 $metrics['archive']=['rows'=>$size,'verification_seconds'=>$verifySeconds,'removal_seconds'=>microtime(true)-$removeStart,'steps'=>$steps,'deleted_payloads'=>$job['checkpoint']['deleted'],'sha256'=>$job['checkpoint']['sha256'],'restore_verified'=>true,'peak_memory'=>memory_get_peak_usage(true)];
 if(memory_get_peak_usage(true)>=256*1024*1024){throw new RuntimeException('Operational scale memory budget exceeded.');}
 echo 'OPERATIONS_SCALE_RESULT '.wp_json_encode($metrics)."\n";
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_cache_flush();}
