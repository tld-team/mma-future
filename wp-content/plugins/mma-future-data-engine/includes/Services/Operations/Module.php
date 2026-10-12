<?php
namespace MMAF\DataEngine\Services\Operations;
use MMAF\DataEngine\Support\Capabilities;
use MMAF\DataEngine\Services\RegularImport\{Mutex,WriteGuard};

final class Module {
    public static function register(): void {
        Revision::register();
        add_action('admin_post_mmaf_operation',[self::class,'post']);
        add_action('admin_enqueue_scripts',static function(){
            if(($_GET['page']??'')==='mmaf-operations'){
                $path=dirname(__DIR__,3).'/assets/operations.js';
                wp_enqueue_script('mmaf-operations',plugins_url('assets/operations.js',dirname(__DIR__,3).'/mma-future-data-engine.php'),[],(string)filemtime($path),true);
            }
        });
        add_action('rest_api_init',[self::class,'routes']);
        add_action('admin_post_mmaf_fact_report',static function(){if(!current_user_can(Capabilities::MANAGE)){wp_die('Access denied.','',['response'=>403]);}check_admin_referer('mmaf_fact_report');$job=FactProtectionReport::create(get_current_user_id());wp_safe_redirect(admin_url('admin.php?page=mmaf-operations&operation_id='.$job['id']));exit;});
        add_filter('cron_schedules',static function($schedules){$schedules['mmaf_minute']=['interval'=>60,'display'=>'Every minute'];return $schedules;});
        add_action('mmaf_operations_supervisor',static function(){if(!(defined('MMAF_SERVER_SUPERVISOR') && MMAF_SERVER_SUPERVISOR)){self::work();}});
        if(version_compare((string)get_option('mmaf_db_version','0'),'1.8.0','>=')){
            if(!(defined('MMAF_SERVER_SUPERVISOR') && MMAF_SERVER_SUPERVISOR) && !wp_next_scheduled('mmaf_operations_supervisor') && wp_get_environment_type()!=='local'){wp_schedule_event(time()+60,'mmaf_minute','mmaf_operations_supervisor');}
        }
        if(defined('WP_CLI') && WP_CLI){
            $authorize=static function(){if(!get_current_user_id() || !current_user_can(Capabilities::MANAGE)){\WP_CLI::error('Use --user with an administrator allowed to manage MMA Future.');}};
            \WP_CLI::add_command('mmaf operations fact-report',static function(){try{\WP_CLI::line(wp_json_encode(FactProtectionReport::create(get_current_user_id())));}catch(\Throwable $e){\WP_CLI::error($e->getMessage());}});
            \WP_CLI::add_command('mmaf operations diagnostics',static function(){\WP_CLI::line(wp_json_encode(Monitor::diagnostics()));});
            \WP_CLI::add_command('mmaf operations review-diagnostics',static function(){\WP_CLI::line(wp_json_encode(\MMAF\DataEngine\Admin\ReviewPage::summary(true)));});
            \WP_CLI::add_command('mmaf operations monitor',static function(){\WP_CLI::line(wp_json_encode(Monitor::check()));});
            \WP_CLI::add_command('mmaf operations retention',static function($args){
                $command=$args[0]??'dry-run';
                if($command==='dry-run'){\WP_CLI::line(wp_json_encode(Retention::dryRun()));}
                elseif($command==='enable'){Retention::enable();\WP_CLI::success('Retention enabled after dry-run and full restore verification.');}
                elseif($command==='disable'){update_option('mmaf_retention_enabled',false,false);if(defined('MMAF_OPERATIONS_DIR')){@unlink(MMAF_OPERATIONS_DIR.'/retention-accepted.json');}\WP_CLI::success('Retention paused.');}
                elseif($command==='work'){\WP_CLI::line(wp_json_encode(Retention::work()));}
                else{\WP_CLI::error('Use dry-run, enable, disable or work.');}
            });
            \WP_CLI::add_command('mmaf operations archive',static function($args,$assoc){
                if(($args[0]??'')==='create'){\WP_CLI::line((string)Archive::create($assoc['kind']??'',(int)($assoc['entity']??0)));}
                elseif(($args[0]??'')==='work'){\WP_CLI::line(wp_json_encode(Archive::step((int)($assoc['id']??0),Retention::enabled())));}
                elseif(($args[0]??'')==='status'){\WP_CLI::line(wp_json_encode(Archive::get((int)($assoc['id']??0))));}
                elseif(($args[0]??'')==='retry'){\WP_CLI::line(wp_json_encode(Archive::retry((int)($assoc['id']??0))));}
                else{\WP_CLI::error('Use create, work, status or retry.');}
            });
            \WP_CLI::add_command('mmaf operations test-email',static function(){if(wp_get_environment_type()==='local'){\WP_CLI::error('External email is disabled locally.');}if(!Monitor::email('test','Clearly marked one-off mail transport verification.')){\WP_CLI::error('Mail transport rejected the request.');}\WP_CLI::success('Test notification accepted by mail transport; verify actual receipt.');});
            \WP_CLI::add_command('mmaf operations preview',static function($args,$assoc)use($authorize){$authorize();\WP_CLI::line(wp_json_encode(Jobs::create($args[0]??'',isset($assoc['ids'])?['mode'=>'ids','ids'=>explode(',',$assoc['ids'])]:['mode'=>'filtered','search'=>$assoc['search']??'','filters'=>isset($assoc['filters'])?json_decode($assoc['filters'],true):[]],get_current_user_id(),$assoc['key']??wp_generate_uuid4())));});
            \WP_CLI::add_command('mmaf operations work',static function(){\WP_CLI::line(wp_json_encode(self::work()));});
            \WP_CLI::add_command('mmaf operations status',static function($args,$assoc){global $wpdb;$table=Schema::tables()['jobs'];$id=(int)($assoc['id']??0);\WP_CLI::line(wp_json_encode($id?Jobs::get($id):$wpdb->get_results("SELECT id,kind,status,phase,total,heartbeat_at,error_message FROM $table ORDER BY id DESC LIMIT 25",ARRAY_A)));});
            \WP_CLI::add_command('mmaf operations control',static function($args,$assoc)use($authorize){$authorize();\WP_CLI::line(wp_json_encode(Jobs::control((int)($assoc['id']??0),$args[0]??'',get_current_user_id(),$assoc['manifest']??'',isset($assoc['ids'])?explode(',',$assoc['ids']):[])));});
        }
    }
    public static function work(): array {
        if(version_compare((string)get_option('mmaf_db_version','0'),'1.8.0','<')){return ['worked'=>false,'blocked'=>'migration_required'];}
        try{return Mutex::run(static function(){
            WriteGuard::assertAllowed(true);
            $result=Jobs::work();
            if(empty($result['worked'])){$result=Calculation::work();}
            if(empty($result['worked'])){$result=FactProtectionReport::work();}
            $result['readiness_refreshed']=Readiness::work(250);
            $result['cache_retry']=\MMAF\DataEngine\Services\PublicCacheService::retry_invalidation();
            $progress=(array)get_option('mmaf_membership_backfill',[]);
            if(empty($progress['done'])){$progress=Membership::backfill((int)($progress['cursor']??0));update_option('mmaf_membership_backfill',$progress,false);}
            $activations=(array)get_option('mmaf_activation_backfill',[]);
            if(empty($activations['done'])){$activations=Activations::backfill((int)($activations['cursor']??0));update_option('mmaf_activation_backfill',$activations,false);}
            if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','>=')){
                $links=(array)get_option('mmaf_review_link_backfill',[]);
                update_option('mmaf_review_link_backfill',ReviewLinks::backfill((int)($links['cursor']??0)),false);
                if(empty($result['worked'])){$result['retention']=Retention::work();$result['worked']=!empty($result['retention']['worked']);}
            }
            update_option('mmaf_operations_heartbeat',gmdate('Y-m-d H:i:s'),false);
            return $result;
        });}catch(\Throwable $e){return ['worked'=>false,'blocked'=>substr($e->getMessage(),0,255)];}
    }
    public static function permission(\WP_REST_Request $request): bool {
        return current_user_can(Capabilities::MANAGE) && (bool)wp_verify_nonce($request->get_header('X-WP-Nonce'),'wp_rest');
    }
    public static function routes(): void {
        register_rest_route('mma-future/v1','/admin/operations',[
            'methods'=>'POST','permission_callback'=>[self::class,'permission'],
            'callback'=>static function($r){try{return Jobs::create((string)$r['action'],(array)$r['selection'],get_current_user_id(),(string)$r['request_key']);}catch(\Throwable $e){return new \WP_Error('operation_error',$e->getMessage(),['status'=>409]);}},
        ]);
        register_rest_route('mma-future/v1','/admin/operations/(?P<id>\d+)',[
            ['methods'=>'GET','permission_callback'=>[self::class,'permission'],'callback'=>static function($r){
                $job=Jobs::get((int)$r['id']);if(!$job){return new \WP_Error('not_found','Operation not found.',['status'=>404]);}
                $results=Jobs::items((int)$r['id'],max(1,(int)$r['page']),max(1,(int)($r['per_page']?:25)));
                $response=['operation'=>$job,'results'=>$results,'progress'=>Presentation::progress($job,$results['counts'])];
                if($r['html']){ob_start();\MMAF\DataEngine\Admin\OperationsPage::progress($job,$results);$response['progress_html']=ob_get_clean();}
                return $response;
            }],
            ['methods'=>'POST','permission_callback'=>[self::class,'permission'],'callback'=>static function($r){try{return Jobs::control((int)$r['id'],(string)$r['command'],get_current_user_id(),(string)$r['manifest_hash'],(array)$r['fighter_ids']);}catch(\Throwable $e){return new \WP_Error('operation_error',$e->getMessage(),['status'=>409]);}}],
        ]);
    }
    public static function post(): void {
        if(!current_user_can(Capabilities::MANAGE)){wp_die('Access denied.','',['response'=>403]);}
        check_admin_referer('mmaf_operation');$id=absint($_POST['operation_id']??0);
        try{$result=Jobs::control($id,sanitize_key($_POST['command']??''),get_current_user_id(),sanitize_text_field(wp_unslash($_POST['manifest_hash']??'')),(array)($_POST['fighter_ids']??[]));}
        catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'Operation could not continue',['response'=>409,'back_link'=>true]);}
        wp_safe_redirect(admin_url('admin.php?page=mmaf-operations&operation_id='.(int)($result['id']??$id)));exit;
    }
}
