<?php
namespace MMAF\DataEngine\Services\Operations;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\FighterService;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\RegularImport\{Mutex,Transaction,Repository,WriteGuard};
use MMAF\DataEngine\Support\Capabilities;

/** Read-only evidence collection. Only its own operation/checkpoint/results are written. */
final class FactProtectionReport {
    public static function create(int $actor): array {
        if (!current_user_can(Capabilities::MANAGE) || $actor!==get_current_user_id()) { throw new \RuntimeException('Access denied.'); }
        return Mutex::run(static function()use($actor){
            WriteGuard::assertAllowed(true);
            return Transaction::run(static function()use($actor){
                global $wpdb;$t=Canonical::table_names();$o=Schema::tables();$now=gmdate('Y-m-d H:i:s');
                $max=(int)$wpdb->get_var("SELECT MAX(id) FROM {$t['field_provenance']}");
                $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT entity_id) FROM {$t['field_provenance']} WHERE entity_type='fighter' AND source_type='manual_verified' AND id<=%d",$max));
                Repository::check($wpdb->insert($o['jobs'],['request_key'=>'facts:'.wp_generate_uuid4(),'kind'=>'fact_protection_report','action'=>'fact_protection_report','status'=>'running','phase'=>'fact_evidence','actor_user_id'=>$actor,'selection_json'=>'{}','checkpoint_json'=>wp_json_encode(['cursor'=>0,'max_provenance_id'=>$max]),'total'=>$total,'created_at'=>$now,'updated_at'=>$now,'confirmed_at'=>$now]));
                return Jobs::get((int)$wpdb->insert_id);
            });
        });
    }
    public static function work(int $limit=10): array {
        return Mutex::run(static function()use($limit){
            WriteGuard::assertAllowed(true);global $wpdb;$t=Canonical::table_names();$o=Schema::tables();
            $id=(int)$wpdb->get_var("SELECT id FROM {$o['jobs']} WHERE kind='fact_protection_report' AND status='running' AND (retry_at IS NULL OR retry_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT 1");
            if(!$id){return ['worked'=>false];}$job=Jobs::get($id);$start=microtime(true);
            try { Transaction::run(static function()use($job,$limit,$id,$wpdb,$t,$o){
                $cp=$job['checkpoint'];$ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT entity_id FROM {$t['field_provenance']} WHERE entity_type='fighter' AND source_type='manual_verified' AND id<=%d AND entity_id>%d ORDER BY entity_id LIMIT %d",$cp['max_provenance_id'],$cp['cursor'],min(10,max(1,$limit)))));
                foreach($ids as $fighter){
                    $canonical=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighters']} WHERE id=%d",$fighter),ARRAY_A)?:[];
                    $rows=$wpdb->get_results($wpdb->prepare("SELECT id,field_name,source_type,source_id,verified_by,verified_at,value_hash,locked FROM {$t['field_provenance']} WHERE entity_type='fighter' AND entity_id=%d AND source_type='manual_verified' AND id<=%d ORDER BY id LIMIT 100",$fighter,$cp['max_provenance_id']),ARRAY_A);
                    $audits=$wpdb->get_results($wpdb->prepare("SELECT id,action,actor_user_id,created_at,before_json,after_json FROM {$t['audit_log']} WHERE entity_type='fighter' AND entity_id=%d AND action IN ('fighter_updated','fighter_fact_changed','fighter_fact_confirmed') AND OCTET_LENGTH(COALESCE(before_json,''))+OCTET_LENGTH(COALESCE(after_json,''))<=65536 ORDER BY id DESC LIMIT 50",$fighter),ARRAY_A);
                    usort($audits,static fn($a,$b)=>(($a['action']==='fighter_updated'?1:0)<=>($b['action']==='fighter_updated'?1:0)) ?: ((int)$b['id']<=>(int)$a['id']));
                    // One profile at a time; private payloads never enter the report or admin response.
                    try{$saved=(new ProfileStore())->for_fighter($fighter);}catch(\RuntimeException $error){$saved=null;}
                    $source=$saved['profile']??[];$facts=[];
                    foreach($rows as $row){
                        $field=$row['field_name'];if(!in_array($field,FighterService::FACT_FIELDS,true)){continue;}
                        $evidence='Insufficient historical evidence';$audit_id=null;
                        foreach($audits as $audit){
                            if((string)$audit['actor_user_id']!==(string)$row['verified_by'] || $audit['created_at']!==$row['verified_at']){continue;}
                            $before=json_decode($audit['before_json']??'',true)?:[];$after=json_decode($audit['after_json']??'',true)?:[];
                            if(!array_key_exists($field,$after)){continue;}
                            $normalized=$after[$field]===null?'':trim(strtolower(is_bool($after[$field])?($after[$field]?'1':'0'):(string)$after[$field]));
                            if(!hash_equals($row['value_hash'],hash('sha256',$normalized))){continue;}
                            $audit_id=(int)$audit['id'];
                            $evidence=$audit['action']==='fighter_fact_confirmed'?'Explicit confirmation recorded':(((string)($before[$field]??'')===(string)($after[$field]??''))?'Unchanged in matching historical Save; review intent':'Manual change recorded');break;
                        }
                        $suggestion=$source['verified_facts'][$field]??$source[$field]??null;
                        $facts[]=['field'=>$field,'current_value'=>$canonical[$field]??null,'protection'=>$row['source_type'],'source_id'=>$row['source_id'],'locked'=>(bool)$row['locked'],'verified_at'=>$row['verified_at'],'evidence'=>$evidence,'audit_id'=>$audit_id,'source_suggestion'=>is_scalar($suggestion)?mb_substr((string)$suggestion,0,500):null,'source_fetched_at'=>$saved['source_fetched_at']??null];
                    }
                    $now=gmdate('Y-m-d H:i:s');Repository::check($wpdb->replace($o['items'],['operation_id'=>$id,'fighter_id'=>$fighter,'status'=>'completed','updated_at'=>$now,'result_json'=>wp_json_encode(['facts'=>$facts,'checked_at'=>$now,'read_only'=>true])]));$cp['cursor']=$fighter;
                }
                $now=gmdate('Y-m-d H:i:s');$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o['items']} WHERE operation_id=%d",$id));
                $data=['checkpoint_json'=>wp_json_encode($cp),'summary_json'=>wp_json_encode(['processed'=>$count,'read_only'=>true]),'heartbeat_at'=>$now,'progress_at'=>$now,'updated_at'=>$now,'attempts'=>0,'retry_at'=>null,'error_message'=>null];
                if(count($ids)<min(10,max(1,$limit))){$data+=['status'=>'completed','phase'=>'done','finished_at'=>$now];}
                Repository::check($wpdb->update($o['jobs'],$data,['id'=>$id]));
            }); } catch (\Throwable $error) {
                $attempt=(int)$job['attempts']+1;$terminal=$attempt>=3;
                Jobs::save($id,['status'=>$terminal?'failed':'running','attempts'=>$attempt,'retry_at'=>$terminal?null:gmdate('Y-m-d H:i:s',time()+($attempt===1?60:300)),'error_message'=>substr($error->getMessage(),0,255)]);
            }
            $current=Jobs::get($id);Jobs::save($id,['duration_ms'=>(int)$current['duration_ms']+(int)((microtime(true)-$start)*1000),'peak_memory'=>max((int)$current['peak_memory'],memory_get_peak_usage(true))]);
            return ['worked'=>true,'operation'=>Jobs::get($id)];
        });
    }
}
