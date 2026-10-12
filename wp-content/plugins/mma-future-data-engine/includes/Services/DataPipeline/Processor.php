<?php
namespace MMAF\DataEngine\Services\DataPipeline;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\RegularImport\{Repository,Mutex,WriteGuard,Transaction,CareerHistory,Review};
use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentApplyService;
use MMAF\DataEngine\Services\{StatsRebuildService,RankingCalculatorService};

/** No network access: resumable preparation from private source snapshots. */
final class Processor {
    public static function register(): void {
        add_action('mmaf_process_stored_profiles',[self::class,'cron']);
        add_action('admin_notices',[self::class,'notice']);
        add_action('admin_post_mmaf_retry_preparation',[self::class,'retry']);
        add_action('mmaf_public_data_changed',static function($reason) {
            if (!WriteGuard::$worker && in_array($reason,['bout','event'],true)) { self::request_refresh(get_current_user_id(),true); }
        });
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('mmaf data-process work',static function($args,$assoc) {
                try { $result=(new self())->work(min(50,max(1,(int)($assoc['limit']??10)))); }
                catch (\MMAF\DataEngine\Services\RegularImport\ImportException $e) { $result=['worked'=>false,'blocked'=>$e->reason]; }
                \WP_CLI::line(wp_json_encode($result));
            });
            \WP_CLI::add_command('mmaf data-process status',static function(){ \WP_CLI::line(wp_json_encode(self::status())); });
            \WP_CLI::add_command('mmaf data-process seed',static function($args,$assoc) {
                if (empty($assoc['file'])) { \WP_CLI::error('Provide --file=/private/path/fighter_profiles.json.'); }
                \WP_CLI::line(wp_json_encode((new Backfill())->run($assoc['file'],isset($assoc['prepare']))));
            });
        }
    }
    public static function schedule(): void {
        if(defined('MMAF_SERVER_SUPERVISOR') && MMAF_SERVER_SUPERVISOR){return;}
        if (!wp_next_scheduled('mmaf_process_stored_profiles')) { wp_schedule_single_event(time()+60,'mmaf_process_stored_profiles'); }
    }
    public static function cron(): void {
        if(defined('MMAF_SERVER_SUPERVISOR') && MMAF_SERVER_SUPERVISOR){return;}
        try { (new self())->work(); }
        catch (\Throwable $e) { self::schedule(); }
    }
    private static function state(): array {
        global $wpdb; $table=Canonical::table_names()['system_state'];
        return json_decode((string)$wpdb->get_var("SELECT state_value FROM $table WHERE state_key='data_preparation'"),true) ?: [];
    }
    private static function save(array $state): void {
        global $wpdb; $table=Canonical::table_names()['system_state'];
        Repository::check($wpdb->query($wpdb->prepare("INSERT INTO $table (state_key,state_value,autoload,updated_at) VALUES ('data_preparation',%s,'no',%s) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value),updated_at=VALUES(updated_at)",wp_json_encode($state),gmdate('Y-m-d H:i:s'))));
    }
    public static function request_refresh(int $actor=0,bool $stats_needed=true): void {
        if (\MMAF\DataEngine\Services\Operations\Jobs::$current) { return; }
        $state=self::state(); $state['pending']=true; $state['generation']=(int)($state['generation']??0)+1;
        $state['stats_needed']=!empty($state['stats_needed']) || $stats_needed;
        $state['actor']=$actor; $state['requested_at']=gmdate('Y-m-d H:i:s'); $state['attempts']=0; $state['error']=null;
        self::save($state); self::schedule();
    }
    public static function enqueue(int $id,int $actor=0,int $job=0): void {
        global $wpdb; $t=Canonical::table_names(); $queue=Schema::tables()['queue'];
        $fighter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighters']} WHERE id=%d",$id),ARRAY_A);
        if (!$fighter || $fighter['deleted_soft'] || (!$fighter['is_public'] && (!$fighter['is_rankable'] || $fighter['rankability_status']!=='rankable'))) { return; }
        $profile=(new ProfileStore())->for_fighter($id); $hash=$profile['content_hash']??null;
        $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $queue WHERE fighter_id=%d",$id),ARRAY_A);
        if ($old && $hash && $old['processed_hash']===$hash && in_array($old['status'],['completed','review'],true)) { return; }
        Repository::check($wpdb->query($wpdb->prepare("INSERT INTO $queue (fighter_id,status,requested_hash,import_job_id,actor_user_id,attempts,requested_at) VALUES (%d,'pending',NULLIF(%s,''),%d,%d,0,%s) ON DUPLICATE KEY UPDATE status='pending',requested_hash=VALUES(requested_hash),import_job_id=VALUES(import_job_id),actor_user_id=VALUES(actor_user_id),attempts=0,error_message=NULL,requested_at=VALUES(requested_at),finished_at=NULL,retry_at=NULL,summary_json=NULL",$id,$hash??'',$job,$actor,gmdate('Y-m-d H:i:s'))));
        self::schedule();
    }
    public static function pending(int $job=0): int {
        global $wpdb; $queue=Schema::tables()['queue'];
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $queue WHERE status='pending'".($job?' AND import_job_id=%d':' AND %d=0'),$job));
    }
    public function work(int $limit=10,int $job=0): array {
        return Mutex::run(function() use($limit,$job) {
            if (!$job) {
                WriteGuard::assertAllowed(true);
                global $wpdb;
                $operations=\MMAF\DataEngine\Services\Operations\Schema::tables()['jobs'];
                $active=(int)$wpdb->get_var("SELECT id FROM $operations WHERE kind IN ('stats','ranking') AND status='running' ORDER BY id LIMIT 1");
                $state=self::state();
                if($active && !in_array($active,[(int)($state['stats_job']??0),(int)($state['ranking_job']??0)],true)){return ['worked'=>false,'blocked'=>'calculation_locked'];}
                // Finish our frozen generation before starting profiles queued later.
                $calculating=$active>0;
            }
            global $wpdb; $queue=Schema::tables()['queue']; $limit=min(50,max(1,$limit));
            $rows=!empty($calculating)?[]:$wpdb->get_results($wpdb->prepare("SELECT * FROM $queue WHERE status='pending' AND (retry_at IS NULL OR retry_at<=UTC_TIMESTAMP())".($job?' AND import_job_id=%d':' AND %d=0')." ORDER BY fighter_id LIMIT %d",$job,$limit),ARRAY_A);
            $processed=0;$calculated=false;
            $deadline=microtime(true)+20;
            foreach ($rows as $row) {
                if(microtime(true)>$deadline){break;}
                try {
                    Transaction::run(function() use($wpdb,$queue,$row,$job) {
                        $result=$this->prepare((int)$row['fighter_id'],(int)$row['actor_user_id'],$job ?: (int)$row['import_job_id'],0,json_decode($row['summary_json']??'',true)?:[]);
                        $result['done']=$result['done']??true;
                        $status=empty($result['done'])?'pending':((!empty($result['review']) || ($result['coverage']['status']??'complete')!=='complete')?'review':'completed');
                        do_action('mmaf_profile_preparation_before_checkpoint',(int)$row['fighter_id'],$result);
                        Repository::check($wpdb->update($queue,['status'=>$status,'processed_hash'=>empty($result['done'])?$row['processed_hash']:($result['hash']??null),'summary_json'=>wp_json_encode($result),'error_message'=>null,'finished_at'=>empty($result['done'])?null:gmdate('Y-m-d H:i:s')],['fighter_id'=>$row['fighter_id']]));
                    });
                    ++$processed;
                } catch (\Throwable $e) {
                    $attempt=(int)$row['attempts']+1;
                    $conflict=$e instanceof \MMAF\DataEngine\Services\RegularImport\ImportException && $e->reason==='identity';
                    Repository::check($wpdb->update($queue,['status'=>$conflict?'review':($attempt>=3?'failed':'pending'),'attempts'=>$attempt,'retry_at'=>$conflict?null:gmdate('Y-m-d H:i:s',time()+($attempt===1?60:300)),'error_message'=>substr($e->getMessage(),0,255)],['fighter_id'=>$row['fighter_id']]));
                    if ($job) { throw $e; }
                }
            }
            $pending=self::pending($job);
            if ($job) { return ['processed'=>$processed,'pending'=>$pending]; }
            $state=self::state();
            if ($pending && empty($calculating)) { self::schedule(); return ['worked'=>$processed>0,'processed'=>$processed,'pending'=>$pending]; }
            if (!empty($state['pending'])) {
                $generation=(int)($state['frozen_generation']??$state['generation']);
                $actor=(int)($state['actor']??0);
                if (!isset($state['frozen_generation'])) { $state['frozen_generation']=$generation; $state['frozen_stats_needed']=!empty($state['stats_needed']); self::save($state); }
                if (!empty($state['frozen_stats_needed']) && empty($state['stats_done'])) {
                    if (empty($state['stats_job'])) { $state['stats_job']=\MMAF\DataEngine\Services\Operations\Calculation::create('stats',0,$actor,'processor:'.$generation.':stats'); self::save($state); }
                    $calculation=\MMAF\DataEngine\Services\Operations\Calculation::step((int)$state['stats_job']);
                    if ($calculation['status']==='failed') { $state['error']=$calculation['error_message']; self::save($state); return ['worked'=>false,'blocked'=>'calculation_failed','error'=>$state['error']]; }
                    if ($calculation['status']!=='completed') { self::schedule(); return ['worked'=>true,'phase'=>'statistics','operation_id'=>$calculation['id']]; }
                    $state['stats']=$calculation['summary'];$state['stats_done']=true;self::save($state);
                }
                if (empty($state['ranking_job'])) { $state['ranking_job']=\MMAF\DataEngine\Services\Operations\Calculation::create('ranking',0,$actor,'processor:'.$generation.':ranking');self::save($state); }
                $calculation=\MMAF\DataEngine\Services\Operations\Calculation::step((int)$state['ranking_job']);
                if ($calculation['status']==='failed') { $state['error']=$calculation['error_message']; self::save($state); return ['worked'=>false,'blocked'=>'calculation_failed','error'=>$state['error']]; }
                if ($calculation['status']!=='completed') { self::schedule();return ['worked'=>true,'phase'=>'ranking','operation_id'=>$calculation['id']]; }
                $state['draft']=$calculation['summary'];$state['pending']=(int)$state['generation']!==$generation;
                if(!$state['pending']){$state['stats_needed']=false;}
                $state['processed_generation']=$generation;$state['finished_at']=gmdate('Y-m-d H:i:s');$state['error']=null;
                unset($state['stats_job'],$state['ranking_job'],$state['frozen_generation'],$state['frozen_stats_needed'],$state['stats_done']);self::save($state);
                do_action('mmaf_public_data_changed','preparation_completed');$calculated=true;
            }
            return ['worked'=>$processed>0 || $calculated,'processed'=>$processed,'pending'=>0,'draft_run_id'=>$state['draft']['ranking_run_id']??null];
        });
    }
    public function prepare(int $id,int $actor,int $job,int $operation=0,array $progress=[]): array {
        WriteGuard::assertAllowed();
        global $wpdb; $t=Canonical::table_names();
        $fighter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighters']} WHERE id=%d",$id),ARRAY_A);
        if (!$fighter || $fighter['deleted_soft'] || (!$fighter['is_public'] && !$fighter['is_rankable'] && !\MMAF\DataEngine\Services\Operations\Authority::allows($operation,$id))) { return ['review'=>true,'reason'=>'Fighter is outside the approved scope.']; }
        $saved=(new ProfileStore())->for_fighter($id);
        if (!$saved) { return ['review'=>true,'reason'=>'No unambiguous successful stored source profile is available. Existing canonical data is retained.']; }
        $raw=$saved['profile']; $raw['scraped_at']=$saved['source_fetched_at'];
        $fields=(new FighterProfileEnrichmentApplyService())->apply_all_safe_json_string(wp_json_encode(['schema_version'=>'tapology_fighter_profiles_v0_1','source'=>'tapology','profiles'=>[$raw],'profiles_total'=>1]),'Stored source profile',$actor,true);
        if (!empty($fields['summary']['profiles_failed'])) { throw new \RuntimeException('Saved source facts could not be applied.'); }
        $coverage=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighter_career_coverage']} WHERE fighter_id=%d",$id),ARRAY_A);
        if ($coverage && $coverage['source_fetched_at'] && (!$saved['source_fetched_at'] || $coverage['source_fetched_at']>$saved['source_fetched_at'])) {
            return ['hash'=>$saved['content_hash'],'fields'=>$fields['summary'],'coverage'=>['status'=>$coverage['status'],'reason'=>$coverage['reason']],'reason'=>'A more recent canonical career verification was retained.','done'=>true];
        }
        $career=ProfileStore::career_profile($raw,$id);
        $result=(new CareerHistory())->process_chunk($career,$job,$actor,$operation,$progress['checkpoint']??[]);
        if ($job) {
            $repo=new Repository();
            $repo->putItem($job,'actual_stored_career_profile',(string)$id,$raw['source_fighter_id'],$result['coverage']['status']==='complete'?'unchanged':'review',$result);
        }
        $needsReview=false;
        foreach ($fields['rows']??[] as $fieldResult) {
            $reviewFields=array_filter($fieldResult['skipped']??[],static fn($reason)=>$reason==='skip_existing_value');
            if ($reviewFields) { $needsReview=true; Review::upsert($job,'profile_field_difference',$raw['source_fighter_id'],['fighter_id'=>$id,'fields'=>array_keys($reviewFields),'reason'=>'Source facts differ from canonical values requiring review.'],$actor); }
        }
        return ['hash'=>$saved['content_hash'],'fields'=>$fields['summary'],'coverage'=>$result['coverage'],'facts'=>$result['facts'],'review'=>$needsReview,'checkpoint'=>$result['checkpoint']??null,'done'=>$result['done']??true];
    }
    public static function refresh_import_coverage(int $job,int $cursor,int $limit=10): array {
        global $wpdb; $repo=new Repository(); $career=new CareerHistory(); $queue=Schema::tables()['queue'];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,payload_json FROM {$repo->tables['items']} WHERE job_id=%d AND kind='actual_stored_career_profile' AND id>%d ORDER BY id LIMIT %d",$job,$cursor,$limit),ARRAY_A);
        foreach ($rows as $row) {
            $result=json_decode($row['payload_json'],true); $id=(int)$result['fighter_id'];
            $saved=(new ProfileStore())->for_fighter($id);
            if (!$saved) { throw new \RuntimeException('Stored career verification has no source snapshot.'); }
            $result['coverage']=$career->refresh_coverage(ProfileStore::career_profile($saved['profile'],$id),$job,0);
            $repo->putItem($job,'actual_stored_career_profile',(string)$id,$result['source_fighter_id'],$result['coverage']['status']==='complete'?'unchanged':'review',$result);
            if ($result['coverage']['status']!=='complete') { Repository::check($wpdb->update($queue,['status'=>'review'],['fighter_id'=>$id])); }
            $cursor=(int)$row['id'];
        }
        return ['cursor'=>$cursor,'done'=>count($rows)<$limit];
    }
    public static function status(): array {
        global $wpdb; $table=Schema::tables()['queue'];
        return ['queue'=>$wpdb->get_results("SELECT status,COUNT(*) AS total FROM $table GROUP BY status",ARRAY_A),'calculation'=>self::state()];
    }
    public static function metadata(array $ids): array {
        global $wpdb;$t=Canonical::table_names();$p=Schema::tables();$in=implode(',',array_map('intval',$ids));
        if (!$in) { return []; }
        $rows=$wpdb->get_results("SELECT fs.fighter_id,COUNT(DISTINCT snap.id) AS source_count,COUNT(DISTINCT CASE WHEN snap.payload_json IS NOT NULL AND snap.content_hash IS NOT NULL THEN snap.id END) AS successful_profile_count,MAX(snap.last_attempt_at) AS last_attempt_at,MAX(snap.source_fetched_at) AS source_fetched_at,MAX(snap.last_attempt_status) AS last_attempt_status,MAX(q.status) AS preparation_status,MAX(q.error_message) AS error_message,MAX(c.status) AS coverage_status,MAX(c.reason) AS coverage_reason FROM {$t['fighter_sources']} fs LEFT JOIN {$p['profiles']} snap ON snap.source_type=fs.source_type AND (snap.source_fighter_id=fs.source_fighter_id OR snap.source_url=fs.source_url) LEFT JOIN {$p['queue']} q ON q.fighter_id=fs.fighter_id LEFT JOIN {$t['fighter_career_coverage']} c ON c.fighter_id=fs.fighter_id WHERE fs.fighter_id IN ($in) AND fs.source_type='tapology' GROUP BY fs.fighter_id",ARRAY_A);
        $out=[];foreach ($rows as $row) { $out[(int)$row['fighter_id']]=$row; }
        // Separate indexed identities from URL lookup; OR forced a full source scan.
        $shared=$wpdb->get_results("SELECT fighter_id,COUNT(DISTINCT other_fighter_id) bindings FROM (SELECT mine.fighter_id,other.fighter_id other_fighter_id FROM {$t['fighter_sources']} mine JOIN {$t['fighter_sources']} other ON other.source_type=mine.source_type AND other.source_fighter_id=mine.source_fighter_id AND other.fighter_id<>mine.fighter_id WHERE mine.fighter_id IN ($in) AND mine.source_fighter_id IS NOT NULL AND mine.source_fighter_id<>'' UNION ALL SELECT mine.fighter_id,other.fighter_id other_fighter_id FROM {$t['fighter_sources']} mine JOIN {$t['fighter_sources']} other ON other.source_type=mine.source_type AND other.source_url=mine.source_url AND other.fighter_id<>mine.fighter_id WHERE mine.fighter_id IN ($in) AND mine.source_url IS NOT NULL AND mine.source_url<>'') bindings GROUP BY fighter_id",ARRAY_A);
        foreach($shared as $row){$out[(int)$row['fighter_id']]['identity_conflict']=(int)$row['bindings']>0;}
        $o=\MMAF\DataEngine\Services\Operations\Schema::tables();
        if(version_compare((string)get_option('mmaf_db_version','0'),'1.8.0','>=')){
            $active=$wpdb->get_results("SELECT i.fighter_id,MAX(i.operation_id) operation_id FROM {$o['items']} i JOIN {$o['jobs']} j ON j.id=i.operation_id WHERE i.fighter_id IN ($in) AND j.status='running' AND i.status IN ('pending','preparing','prepared') GROUP BY i.fighter_id",ARRAY_A);
            foreach($active as $row){$out[(int)$row['fighter_id']]['preparation_status']='pending';$out[(int)$row['fighter_id']]['operation_id']=(int)$row['operation_id'];}
        }
        $ranked=$wpdb->get_results("SELECT fighter_id,COUNT(*) AS active_board_count,MAX(ranking_run_id) AS active_ranking_run_id FROM {$t['ranking_current']} WHERE fighter_id IN ($in) GROUP BY fighter_id",ARRAY_A);
        foreach($ranked as $row){$out[(int)$row['fighter_id']]=array_merge($out[(int)$row['fighter_id']]??[],$row);}
        if($wpdb->last_error){throw new \RuntimeException('Cannot read fighter preparation metadata.');}
        return $out;
    }
    public static function retry(): void {
        if (!current_user_can(\MMAF\DataEngine\Support\Capabilities::MANAGE)) { wp_die('Access denied.',403); }
        check_admin_referer('mmaf_retry_preparation');
        self::retry_failed(get_current_user_id());
        wp_safe_redirect(admin_url('admin.php?page=mmaf-fighters'));exit;
    }
    /** Explicit retry starts a new generation instead of reusing a failed frozen child. */
    public static function retry_failed(int $actor): array {
        return Mutex::run(static function()use($actor){
            WriteGuard::assertAllowed();
            return Transaction::run(static function()use($actor){
                global $wpdb;$queue=Schema::tables()['queue'];$state=self::state();
                $failed=(int)$wpdb->get_var("SELECT COUNT(*) FROM $queue WHERE status='failed'");
                $children=[];$failedCalculation=!empty($state['error']);
                foreach(['stats_job'=>'stats','ranking_job'=>'ranking'] as $key=>$kind){
                    $id=(int)($state[$key]??0);if(!$id){continue;}
                    $child=\MMAF\DataEngine\Services\Operations\Jobs::get($id);
                    if(!$child || $child['request_key']!=='processor:'.($state['frozen_generation']??'').':'.$kind){throw new \RuntimeException('Stored preparation calculation identity needs review.');}
                    $children[$id]=$child;$failedCalculation=$failedCalculation || in_array($child['status'],['failed','cancelled'],true);
                }
                if(!$failed && !$failedCalculation){return ['retried'=>0,'restarted_calculation'=>false];}
                if($failedCalculation){
                    foreach($children as $id=>$child){if(!in_array($child['status'],['completed','cancelled'],true)){\MMAF\DataEngine\Services\Operations\Jobs::save($id,['status'=>'cancelled','finished_at'=>gmdate('Y-m-d H:i:s'),'error_message'=>'Superseded by an explicit stored-preparation retry.']);}}
                    unset($state['stats_job'],$state['ranking_job'],$state['frozen_generation'],$state['frozen_stats_needed'],$state['stats_done']);
                    self::save($state);
                }
                Repository::check($wpdb->query("UPDATE $queue SET status='pending',attempts=0,error_message=NULL,retry_at=NULL WHERE status='failed'"));
                self::request_refresh($actor,true);
                return ['retried'=>$failed,'restarted_calculation'=>$failedCalculation];
            });
        });
    }
    public static function notice(): void {
        $page=sanitize_key($_GET['page']??'');
        if (strpos($page,'mmaf')!==0) { return; }
        $status=self::status(); $counts=[];
        foreach ($status['queue'] as $row) { $counts[$row['status']]=(int)$row['total']; }
        $state=$status['calculation'];
        if (!$counts && !$state) { return; }
        $text=sprintf('Stored profile preparation: %d pending, %d completed, %d need review, %d failed.',$counts['pending']??0,$counts['completed']??0,$counts['review']??0,$counts['failed']??0);
        if (!empty($state['pending'])) { $text.=' Statistics and one ranking draft are queued.'; }
        elseif (!empty($state['draft']['ranking_run_id'])) { $text.=' Prepared draft #'.(int)$state['draft']['ranking_run_id'].'.'; }
        if (!empty($state['error'])) { $text.=' Calculation needs attention: '.$state['error']; }
        $text.=' Ranking activation remains a separate action.';
        echo '<div class="notice notice-info"><p>'.esc_html($text).'</p></div>';
        if (($counts['failed']??0)>0 || !empty($state['error'])) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mmaf_retry_preparation');
            echo '<input type="hidden" name="action" value="mmaf_retry_preparation"><button class="button">Retry failed preparation</button></form>';
        }
    }
}
