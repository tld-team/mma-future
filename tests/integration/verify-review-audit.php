<?php
require __DIR__.'/bootstrap.php';
use MMAF\DataEngine\Services\Audit\FighterDuplicateAuditService;
use MMAF\DataEngine\Migrations\{MigrationRunner,Schema};
use MMAF\DataEngine\Services\RegularImport\{Transaction,Repository};
function audit_assert($ok,$message){if(!$ok){throw new RuntimeException($message);}echo "PASS: $message\n";}
MigrationRunner::run();global $wpdb;$t=Schema::table_names();Transaction::begin();
try{
 $tag='Audit'.bin2hex(random_bytes(4));$rows=[];
 foreach([['Ana '.$tag,'provisional','Brazil','Flyweight'],['Ana '.$tag,'verified','Brazil','Flyweight'],['Amelia '.$tag,'verified','Brazil','Bantamweight'],['Bella '.$tag,'verified','Brazil','Flyweight'],['Charlie '.$tag,'verified','France','Heavyweight']] as $n=>$data){
  $row=['display_name'=>$data[0],'normalized_name'=>strtolower($data[0]),'status'=>$data[1],'nationality'=>$data[2],'weight_class'=>$data[3],'created_at'=>gmdate('Y-m-d H:i:s')];
  Repository::check($wpdb->insert($t['fighters'],$row));$row['id']=(int)$wpdb->insert_id;$rows[]=$row;
 }
 Repository::check($wpdb->insert($t['fighter_sources'],['fighter_id'=>$rows[0]['id'],'source_type'=>'tapology','source_fighter_id'=>'audit-'.$tag,'created_at'=>gmdate('Y-m-d H:i:s')]));
 $service=new FighterDuplicateAuditService();$expected=[];$method=new ReflectionMethod($service,'match_reason');$method->setAccessible(true);
 foreach(array_slice($rows,1) as $row){$match=$method->invoke($service,$rows[0],$row);if($match){$expected[$row['id']]=$match;}}
 $start=microtime(true);$result=$service->audit(100);$actual=[];
 foreach($result['review_candidates'] as $item){if($item['scraped_fighter_id']===$rows[0]['id']){$actual[$item['existing_fighter_id']]=['reason'=>$item['reason'],'confidence'=>$item['confidence']];}}
 audit_assert(count($expected)===3 && $actual===$expected,'bounded SQL audit preserves exact, medium and low identity-review reasons');
 audit_assert(!isset($actual[$rows[4]['id']]),'unrelated surname metadata does not create a match');
 audit_assert($wpdb->last_error==='','temporary materialization and indexed joins complete without SQL errors');
 echo 'METRICS '.wp_json_encode(['elapsed_ms'=>round((microtime(true)-$start)*1000,2),'peak_memory'=>memory_get_peak_usage(true)])."\n";
}finally{Transaction::rollback();wp_cache_flush();}
