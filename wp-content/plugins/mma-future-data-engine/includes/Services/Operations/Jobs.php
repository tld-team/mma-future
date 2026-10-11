<?php
namespace MMAF\DataEngine\Services\Operations;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Services\DataPipeline\{Processor,ProfileStore};
use MMAF\DataEngine\Services\RegularImport\{Repository,Transaction,Mutex,WriteGuard,ImportException};
use MMAF\DataEngine\Services\FighterReadinessService;
use MMAF\DataEngine\Services\AuditLogService;

/** Immutable DB manifest. No PHP array containing a complete filtered population. */
final class Jobs {
    public static int $current=0;
    public const PREPARE_ACTIONS=['approve_and_prepare','mark_public','mark_verified','mark_rankable'];
    public const ACTIONS=['approve_and_prepare','validate_selected_for_ranking','mark_verified','mark_public','mark_not_public','mark_rankable','mark_not_rankable','move_to_provisional'];
    public static function get(int $id): ?array {
        global $wpdb; $table=Schema::tables()['jobs'];
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id),ARRAY_A);
        if(!$row){return null;}
        foreach(['selection','checkpoint','summary'] as $name){$row[$name]=json_decode($row[$name.'_json']??'',true)?:[];unset($row[$name.'_json']);}
        return $row;
    }
    public static function save(int $id,array $data): void {
        global $wpdb; $table=Schema::tables()['jobs']; $data['updated_at']=gmdate('Y-m-d H:i:s');
        if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','>=') && (isset($data['checkpoint']) || isset($data['phase']))){$data['progress_at']=$data['updated_at'];}
        foreach(['selection','checkpoint','summary'] as $name){if(array_key_exists($name,$data)){$data[$name.'_json']=wp_json_encode($data[$name]);unset($data[$name]);}}
        Repository::check($wpdb->update($table,$data,['id'=>$id]));
        if(isset($data['status'])){
            $t=Schema::tables();Repository::check($wpdb->query($wpdb->prepare("UPDATE {$t['readiness']} r JOIN {$t['items']} i ON i.fighter_id=r.fighter_id SET r.dirty=1 WHERE i.operation_id=%d",$id)));
        }
    }
    public static function create(string $action,array $selection,int $actor,string $key): array {
        if(!in_array($action,self::ACTIONS,true)){throw new \InvalidArgumentException('Unsupported fighter action.');}
        $key=substr(sanitize_text_field($key),0,100);if(!$key){throw new \InvalidArgumentException('A request key is required.');}
        $selection=['mode'=>($selection['mode']??'')==='filtered'?'filtered':'ids','search'=>sanitize_text_field($selection['search']??''),'filters'=>(array)($selection['filters']??[]),'ids'=>array_values(array_unique(array_filter(array_map('absint',(array)($selection['ids']??[])))))];
        if($selection['mode']==='ids' && (count($selection['ids'])>100 || !$selection['ids'])){throw new \InvalidArgumentException('Select 1–100 IDs or choose the complete filtered set.');}
        return Mutex::run(static function() use($action,$selection,$actor,$key){
            WriteGuard::assertAllowed(true); global $wpdb; $t=Schema::tables();
            $old=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['jobs']} WHERE request_key=%s",$key));
            if($old){$job=self::get((int)$old);if($job['action']!==$action || $job['selection']!==$selection || (int)$job['actor_user_id']!==$actor){throw new \RuntimeException('Request key was already used for another selection.');}return $job;}
            return Transaction::run(static function() use($action,$selection,$actor,$key,$wpdb,$t){
                $now=gmdate('Y-m-d H:i:s');
                Repository::check($wpdb->insert($t['jobs'],['request_key'=>$key,'kind'=>'fighters','action'=>$action,'status'=>'previewing','phase'=>'preview','actor_user_id'=>$actor,'selection_json'=>wp_json_encode($selection),'created_at'=>$now,'updated_at'=>$now]));$id=(int)$wpdb->insert_id;
                $repo=new FighterRepository();
                $scope=$selection['mode']==='filtered'?$repo->selection_sql($selection['search'],$selection['filters']):"SELECT id FROM ".Canonical::table_names()['fighters']." WHERE id IN (".implode(',',$selection['ids']).")";
                // A single statement freezes the population at creation, including unchanged candidates.
                Repository::check($wpdb->query($wpdb->prepare("INSERT INTO {$t['items']} (operation_id,fighter_id,status,updated_at) SELECT %d,scope.id,'preview_pending',%s FROM ($scope) scope",$id,$now)));
                $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['items']} WHERE operation_id=%d",$id));
                $excluded=[];
                if($selection['mode']==='ids'){
                    $found=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT fighter_id FROM {$t['items']} WHERE operation_id=%d",$id)));
                    foreach(array_diff($selection['ids'],$found) as $missing){$excluded[]=['fighter_id'=>$missing,'reason'=>'fighter_not_found'];}
                }
                self::save($id,['total'=>$total,'summary'=>['requested_total'=>$selection['mode']==='ids'?count($selection['ids']):$total,'excluded'=>$excluded]]); self::audit($id,'operation_scope_frozen',['total'=>$total,'selection'=>$selection,'excluded'=>$excluded],$actor);
                return self::get($id);
            });
        });
    }
    public static function control(int $id,string $command,int $actor,string $manifest='',array $ids=[]): array {
        return Mutex::run(static function() use($id,$command,$actor,$manifest,$ids){
            WriteGuard::assertAllowed(true); global $wpdb; $t=Schema::tables();$job=self::get($id);if(!$job){throw new \RuntimeException('Operation not found.');}
            if(!empty($job['checkpoint']['local_copy_paused']) && $command!=='cancel'){throw new \RuntimeException('This is a historical staging operation. Create a new local operation after checking its saved packages.');}
            return Transaction::run(static function() use($id,$command,$actor,$manifest,$ids,$wpdb,$t,$job){
                switch($command){
                    case 'start':
                        if($job['status']==='running' && hash_equals((string)$job['manifest_hash'],$manifest)){break;}
                        if($job['status']!=='ready' || !$manifest || !hash_equals((string)$job['manifest_hash'],$manifest)){throw new \RuntimeException('Confirm the prepared manifest before starting.');}
                        self::save($id,['status'=>'running','phase'=>'prepare','confirmed_at'=>gmdate('Y-m-d H:i:s')]);break;
                    case 'pause':
                        if(!in_array($job['status'],['running','previewing'],true)){throw new \RuntimeException('Only active work can be paused.');}
                        self::save($id,['status'=>'paused','checkpoint'=>array_merge($job['checkpoint'],['resume_status'=>$job['status']])]);break;
                    case 'resume':
                        if($job['status']!=='paused'){throw new \RuntimeException('Operation is not paused.');}
                        self::save($id,['status'=>$job['checkpoint']['resume_status']??'running']);break;
                    case 'cancel':
                        if(!in_array($job['status'],['completed','cancelled'],true)){self::save($id,['status'=>'cancelled','finished_at'=>gmdate('Y-m-d H:i:s')]);}break;
                    case 'retry_job':
                        if($job['status']!=='failed'){throw new \RuntimeException('Only a failed operation can be retried.');}
                        if($job['kind']!=='fighters'){if(!hash_equals($job['checkpoint']['input_revision'],Calculation::revision())){throw new \RuntimeException('Input changed. Create a new calculation.');}self::save($id,['status'=>'running','attempts'=>0,'retry_at'=>null,'error_message'=>null]);break;}
                        self::cancel_children($job);
                        $cp=array_diff_key($job['checkpoint'],array_flip(['stats_job','ranking_job']));$cp['cycle']=(int)($cp['cycle']??0)+1;
                        self::save($id,['status'=>$job['confirmed_at']?'running':'previewing','phase'=>$job['confirmed_at']?'prepare':'preview','attempts'=>0,'retry_at'=>null,'error_message'=>null,'checkpoint'=>$cp]);break;
                    case 'retry':
                        if($job['kind']!=='fighters' || !$job['confirmed_at'] || !in_array($job['status'],['completed','failed','paused'],true)){throw new \RuntimeException('Pause or finish a confirmed fighter operation before retrying its failed items.');}
                        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
                        if(!$ids || count($ids)>100){throw new \RuntimeException('Choose up to 100 failed items for retry.');}
                        $affected=$wpdb->query($wpdb->prepare("UPDATE {$t['items']} SET status='pending',attempts=0,retry_at=NULL,reason_code=NULL WHERE operation_id=%d AND status='failed' AND fighter_id IN (".implode(',',$ids).")",$id));Repository::check($affected);
                        if(!$affected){throw new \RuntimeException('The selection contains no failed item in this operation.');}
                        self::cancel_children($job);
                        self::save($id,['status'=>'running','phase'=>'prepare','attempts'=>0,'retry_at'=>null,'error_message'=>null,'checkpoint'=>array_merge(array_diff_key($job['checkpoint'],array_flip(['stats_job','ranking_job'])),['cycle'=>(int)($job['checkpoint']['cycle']??0)+1])]);break;
                    default:throw new \InvalidArgumentException('Unsupported operation command.');
                }
                if(in_array($command,['pause','resume','cancel'],true)){
                    foreach(['stats_job','ranking_job'] as $childKey){$child=(int)($job['checkpoint'][$childKey]??0);if(!$child){continue;}$childJob=self::get($child);if(!$childJob || in_array($childJob['status'],['completed','failed','cancelled'],true)){continue;}self::save($child,['status'=>$command==='resume'?'running':($command==='pause'?'paused':'cancelled')]);}
                }
                self::audit($id,'operation_'.$command,['manifest'=>$manifest,'retry_ids'=>$ids],$actor);return self::get($id);
            });
        });
    }
    /** A retry must not leave a detached frozen calculation blocking the worker. */
    private static function cancel_children(array $job): void {
        foreach(['stats_job','ranking_job'] as $key){
            $child=(int)($job['checkpoint'][$key]??0);if(!$child){continue;}$calculation=self::get($child);
            if($calculation && !in_array($calculation['status'],['completed','cancelled'],true)){
                self::save($child,['status'=>'cancelled','finished_at'=>gmdate('Y-m-d H:i:s'),'error_message'=>'Superseded by an explicit retry of parent operation #'.$job['id']]);
            }
        }
    }
    public static function items(int $id,int $page=1,int $limit=25): array {
        global $wpdb; $t=Schema::tables();$f=Canonical::table_names()['fighters'];$limit=min(100,max(1,$limit));$offset=(max(1,$page)-1)*$limit;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT i.*,f.display_name FROM {$t['items']} i LEFT JOIN $f f ON f.id=i.fighter_id WHERE operation_id=%d ORDER BY i.fighter_id LIMIT %d OFFSET %d",$id,$limit,$offset),ARRAY_A);
        foreach($rows as &$row){$row['result']=json_decode($row['result_json']??'',true)?:[];unset($row['result_json']);}unset($row);
        return ['items'=>$rows,'counts'=>self::counts($id)];
    }
    private static function counts(int $id): array {
        global $wpdb; $t=Schema::tables()['items'];$out=[];
        foreach($wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) total FROM $t WHERE operation_id=%d GROUP BY status",$id),ARRAY_A) as $row){$out[$row['status']]=(int)$row['total'];}return $out;
    }
    private static function item(int $operation,int $fighter,array $data): void {
        global $wpdb;$table=Schema::tables()['items'];$data['updated_at']=gmdate('Y-m-d H:i:s');if(isset($data['result'])){$data['result_json']=wp_json_encode($data['result']);unset($data['result']);}
        Repository::check($wpdb->update($table,$data,['operation_id'=>$operation,'fighter_id'=>$fighter]));
        if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','>=')){Repository::check($wpdb->update(Schema::tables()['jobs'],['progress_at'=>$data['updated_at']],['id'=>$operation]));}
        Readiness::invalidate($fighter);
    }
    public static function work(int $limit=10): array {
        return Mutex::run(static function() use($limit){
            WriteGuard::assertAllowed(true);global $wpdb; $t=Schema::tables();
            $id=(int)$wpdb->get_var("SELECT id FROM {$t['jobs']} WHERE kind='fighters' AND status IN ('previewing','running') AND (retry_at IS NULL OR retry_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT 1");if(!$id){return ['worked'=>false];}
            $activeCalc=(int)$wpdb->get_var("SELECT id FROM {$t['jobs']} WHERE kind IN ('stats','ranking') AND status='running' ORDER BY id LIMIT 1");
            if($activeCalc){$calc=self::get($activeCalc);if((int)($calc['checkpoint']['parent']??0)!==$id){return ['worked'=>false];}}
            $job=self::get($id);$start=microtime(true);self::save($id,['heartbeat_at'=>gmdate('Y-m-d H:i:s')]);
            self::$current=$id;
            try {
                if($job['status']==='previewing'){self::preview($job,min(250,max(1,$limit*25)));}
                else{self::execute($job,min(10,max(1,$limit)),$start+20);}
            } catch(\Throwable $e){$attempt=(int)$job['attempts']+1;$terminal=$attempt>=3 || strpos($e->getMessage(),'generation failed:')!==false || strpos($e->getMessage(),'draft failed:')!==false;self::save($id,['status'=>$terminal?'failed':$job['status'],'attempts'=>$attempt,'retry_at'=>$terminal?null:gmdate('Y-m-d H:i:s',time()+($attempt===1?60:300)),'error_message'=>substr($e->getMessage(),0,255)]);} finally {self::$current=0;}
            $current=self::get($id);self::save($id,['duration_ms'=>(int)$current['duration_ms']+(int)((microtime(true)-$start)*1000),'peak_memory'=>max((int)$current['peak_memory'],memory_get_peak_usage(true))]);
            return ['worked'=>true,'operation'=>self::get($id),'counts'=>self::counts($id)];
        });
    }
    private static function preview(array $job,int $limit): void {
        if($job['phase']==='preview_manifest'){self::preview_manifest($job,$limit);return;}
        global $wpdb; $t=Schema::tables();$id=(int)$job['id'];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['items']} WHERE operation_id=%d AND status='preview_pending' ORDER BY fighter_id LIMIT %d",$id,$limit),ARRAY_A);
        $fighters=(new FighterRepository())->find_many(array_column($rows,'fighter_id'));$metadata=Processor::metadata(array_column($rows,'fighter_id'));
        $evaluated=(new FighterReadinessService())->evaluate_fighters_for_table($fighters);
        foreach($fighters as $fighter){$fid=(int)$fighter['id'];$decision=Readiness::decision($fighter,$metadata[$fid]??[]);
            Transaction::run(static function()use($id,$fid,$decision,$fighter,$evaluated){
                self::item($id,$fid,['status'=>'pending','input_hash'=>Readiness::fingerprint($fid),'reason_code'=>$decision['reason'],'result'=>['preview'=>$decision,'already_public'=>(bool)$fighter['is_public'],'already_rankable'=>(bool)$fighter['is_rankable'],'ranking_blockers'=>$evaluated[$fid]['approval_blocker_codes']??[],'ranking_blocker_summary'=>$evaluated[$fid]['approval_blocker_summary']??'']]);
            });
        }
        if(count($rows)===$limit){return;}
        self::save($id,['phase'=>'preview_manifest','checkpoint'=>array_merge($job['checkpoint'],['manifest_cursor'=>0,'manifest_digest'=>hash('sha256','mmaf-operation-manifest-v1:'.$id)]),'summary'=>array_merge($job['summary'],['total'=>(int)$job['total'],'ready'=>0,'review'=>0,'already_public'=>0,'already_rankable'=>0,'ranking_ready'=>0,'ranking_blocked'=>0,'reasons'=>[]])]);
        self::preview_manifest(self::get($id),$limit);
    }
    /** Hash and summarize one page, with a durable digest for the next worker. */
    private static function preview_manifest(array $job,int $limit): void {
        global $wpdb;$t=Schema::tables();$id=(int)$job['id'];$cp=$job['checkpoint'];$summary=$job['summary'];
        Transaction::run(static function()use($wpdb,$t,$id,$cp,$summary,$limit){
            $batch=$wpdb->get_results($wpdb->prepare("SELECT fighter_id,input_hash,result_json FROM {$t['items']} WHERE operation_id=%d AND fighter_id>%d ORDER BY fighter_id LIMIT %d",$id,(int)$cp['manifest_cursor'],$limit),ARRAY_A);
            foreach($batch as $row){
                $cp['manifest_cursor']=(int)$row['fighter_id'];$cp['manifest_digest']=hash('sha256',$cp['manifest_digest'].';'.$row['fighter_id'].':'.$row['input_hash']);
                $value=json_decode($row['result_json'],true)?:[];$state=$value['preview']['state']??'review';$summary[$state]=($summary[$state]??0)+1;
                $summary['already_public']+=(int)($value['already_public']??0);$summary['already_rankable']+=(int)($value['already_rankable']??0);
                $rankingState=empty($value['ranking_blockers'])?'ranking_ready':'ranking_blocked';$summary[$rankingState]=(int)($summary[$rankingState]??0)+1;
                $reason=$value['preview']['reason']??null;if($reason){$summary['reasons'][$reason]=($summary['reasons'][$reason]??0)+1;}
            }
            self::save($id,['status'=>count($batch)<$limit?'ready':'previewing','manifest_hash'=>count($batch)<$limit?$cp['manifest_digest']:null,'checkpoint'=>$cp,'summary'=>$summary]);
        });
    }
    private static function execute(array $job,int $limit,float $deadline): void {
        global $wpdb; $t=Schema::tables();$id=(int)$job['id'];$checkpoint=$job['checkpoint'];
        if($job['phase']==='prepare'){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['items']} WHERE operation_id=%d AND status IN ('pending','preparing') AND (retry_at IS NULL OR retry_at<=%s) ORDER BY fighter_id LIMIT %d",$id,gmdate('Y-m-d H:i:s'),$limit),ARRAY_A);
            foreach($rows as $row){if(microtime(true)>$deadline){break;}$fid=(int)$row['fighter_id'];
                try{Transaction::run(static function()use($job,$row,$id,$fid,$wpdb,$t){
                    if(!hash_equals((string)$row['input_hash'],Readiness::fingerprint($fid))){self::item($id,$fid,['status'=>'review','reason_code'=>'changed_since_preview','result'=>['reason'=>'Canonical data, source or administrative decision changed after preview. Prepare a new manifest.']]);return;}
                    if(in_array($job['action'],self::PREPARE_ACTIONS,true)){
                        $fighter=(new FighterRepository())->find($fid);$metadata=Processor::metadata([$fid]);$decision=Readiness::decision($fighter??[],$metadata[$fid]??[]);
                        if(in_array($decision['state'],['review','failed'],true)){self::item($id,$fid,['status'=>'review','reason_code'=>$decision['reason']]);return;}
                        $prior=$wpdb->get_var($wpdb->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(result_json,'$.approval_input_hash')) FROM {$t['items']} WHERE fighter_id=%d AND operation_id<>%d AND status IN ('completed','noop') ORDER BY operation_id DESC LIMIT 1",$fid,$id));
                        if($job['action']==='approve_and_prepare' && $prior && $fighter['is_public'] && $fighter['is_rankable'] && $fighter['status']==='verified' && hash_equals($prior,Readiness::approval_hash($fid))){
                            $evaluation=(new FighterReadinessService())->evaluate_fighters_for_table([$fighter]);
                            if(empty($evaluation[$fid]['approval_blocker_codes'])){self::item($id,$fid,['status'=>'noop','result'=>['approval_input_hash'=>$prior,'reason'=>'Already prepared and approved; unchanged source and canonical facts.']]);return;}
                        }
                        // An unchanged prepared candidate that is still ineligible needs review,
                        // rather than another career import, statistics generation and draft.
                        $review=$wpdb->get_var($wpdb->prepare("SELECT i.result_json FROM {$t['items']} i JOIN {$t['jobs']} j ON j.id=i.operation_id WHERE i.fighter_id=%d AND i.operation_id<>%d AND i.status='review' AND i.reason_code='ranking_ineligible' AND j.action=%s ORDER BY i.operation_id DESC LIMIT 1",$fid,$id,$job['action']));
                        $review=$review?json_decode($review,true):[];
                        if(!empty($review['preparation_input_hash']) && hash_equals($review['preparation_input_hash'],Readiness::approval_hash($fid))){
                            $evaluation=(new FighterReadinessService())->evaluate_fighters_for_table([$fighter]);
                            if(!empty($evaluation[$fid]['approval_blocker_codes'])){self::item($id,$fid,['status'=>'review','reason_code'=>'ranking_ineligible','result'=>$review]);return;}
                        }
                        self::item($id,$fid,['status'=>'preparing']);
                        $progress=json_decode($row['result_json']??'',true)?:[];
                        $result=(new Processor())->prepare($fid,(int)$job['actor_user_id'],0,$id,$progress);
                        do_action('mmaf_profile_preparation_before_checkpoint',$fid,$result,$id);
                        if(empty($result['done'])){self::item($id,$fid,['status'=>'preparing','input_hash'=>Readiness::fingerprint($fid),'result'=>$result]);return;}
                        if(($result['coverage']['status']??'')==='conflict' || !empty($result['review'])){self::item($id,$fid,['status'=>'review','reason_code'=>'identity_or_facts_review','result'=>$result]);return;}
                        self::item($id,$fid,['status'=>'prepared','prepared_hash'=>Readiness::fingerprint($fid,false),'result'=>$result]);
                    }else{
                        $result=(new FighterReadinessService())->process_bulk_action($job['action'],[$fid],(int)$job['actor_user_id'],true);
                        self::item($id,$fid,['status'=>$result['blocked_count']?'review':($result['updated_count']?'completed':'noop'),'result'=>$result]);
                    }
                });}catch(\Throwable $e){$attempt=(int)$row['attempts']+1;$conflict=$e instanceof ImportException && $e->reason==='identity';self::item($id,$fid,['status'=>$conflict?'review':($attempt>=3?'failed':'pending'),'attempts'=>$attempt,'retry_at'=>$conflict?null:gmdate('Y-m-d H:i:s',time()+($attempt===1?60:300)),'reason_code'=>$conflict?'identity_conflict':'technical_failure','result'=>array_merge(json_decode($row['result_json']??'',true)?:[],['error'=>substr($e->getMessage(),0,255)])]);}
            }
            $counts=self::counts($id);if(!empty($counts['pending']) || !empty($counts['preparing'])){return;}
            if(in_array($job['action'],self::PREPARE_ACTIONS,true) && !empty($counts['prepared'])){$checkpoint['stats_job']=Calculation::create('stats',$id,(int)$job['actor_user_id'],'operation:'.$id.':'.($checkpoint['cycle']??0).':stats');self::save($id,['phase'=>'stats','checkpoint'=>$checkpoint]);}
            elseif($job['action']!=='validate_selected_for_ranking' && !empty($counts['completed'])){self::queue_ranking($job,$checkpoint);}else{self::finish($job);}return;
        }
        if($job['phase']==='stats'){
            $calc=Calculation::step((int)$checkpoint['stats_job']);
            if($calc['status']==='completed'){self::save($id,['phase'=>'approve']);}elseif($calc['status']==='failed'){throw new \RuntimeException('Statistics generation failed: '.$calc['error_message']);}return;
        }
        if($job['phase']==='approve'){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['items']} WHERE operation_id=%d AND status='prepared' ORDER BY fighter_id LIMIT %d",$id,$limit),ARRAY_A);
            foreach($rows as $row){$fid=(int)$row['fighter_id'];Transaction::run(static function()use($job,$row,$id,$fid,$wpdb,$t){
                // Recheck after history/statistics. A stored profile identity must still be unambiguous.
                if(!hash_equals((string)$row['prepared_hash'],Readiness::fingerprint($fid,false))){self::item($id,$fid,['status'=>'review','reason_code'=>'changed_after_preparation']);return;}
                if(!(new ProfileStore())->for_fighter($fid)){self::item($id,$fid,['status'=>'review','reason_code'=>'identity_changed']);return;}
                $result=(new FighterReadinessService())->process_bulk_action($job['action'],[$fid],(int)$job['actor_user_id'],true);
                self::item($id,$fid,['status'=>$result['blocked_count']?'review':($result['updated_count']?'completed':'noop'),'reason_code'=>$result['blocked_count']?'ranking_ineligible':null,'result'=>['approval'=>$result,'preparation'=>json_decode($row['result_json'],true),'preparation_input_hash'=>Readiness::approval_hash($fid),'approval_input_hash'=>$result['blocked_count']?null:Readiness::approval_hash($fid)]]);
            });}
            if(count($rows)<$limit){self::queue_ranking($job,$checkpoint);}return;
        }
        if($job['phase']==='ranking'){
            $calc=Calculation::step((int)$checkpoint['ranking_job']);
            if($calc['status']==='completed'){self::finish($job,['ranking_run_id'=>$calc['summary']['ranking_run_id']??null]);}elseif($calc['status']==='failed'){throw new \RuntimeException('Ranking draft failed: '.$calc['error_message']);}
        }
    }
    private static function queue_ranking(array $job,array $checkpoint): void {
        $checkpoint['ranking_job']=Calculation::create('ranking',(int)$job['id'],(int)$job['actor_user_id'],'operation:'.$job['id'].':'.($checkpoint['cycle']??0).':ranking');self::save((int)$job['id'],['phase'=>'ranking','checkpoint'=>$checkpoint]);
    }
    private static function finish(array $job,array $extra=[]): void {
        $summary=array_merge(self::counts((int)$job['id']),$extra);self::save((int)$job['id'],['status'=>'completed','phase'=>'done','summary'=>$summary,'finished_at'=>gmdate('Y-m-d H:i:s')]);
        self::audit((int)$job['id'],'operation_completed',$summary,(int)$job['actor_user_id']);Readiness::invalidate();
        do_action('mmaf_public_data_changed','operation_completed');
    }
    private static function audit(int $id,string $action,array $data,int $actor): void {(new AuditLogService())->write($action,'operation',$id,null,$data,'Frozen fighter operation',$actor);}
}
