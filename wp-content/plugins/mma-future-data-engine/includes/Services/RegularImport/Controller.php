<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Controller {
    public static function register(): void {
        $permission=static fn()=>current_user_can('manage_options');
        $routes=[
            '/import-uploads'=>['POST','uploadStart'],
            '/import-uploads/(?P<id>\d+)/chunks/(?P<index>\d+)'=>['PUT','uploadChunk'],
            '/import-uploads/(?P<id>\d+)/finish'=>['POST','uploadFinish'],
            '/import-jobs'=>['GET','listing'],
            '/import-jobs/(?P<id>\d+)'=>['GET','detail'],
            '/import-jobs/(?P<id>\d+)/(?P<operation>approve|pause|resume|discard)'=>['POST','action'],
            '/import-setup'=>['GET','setup'],
        ];
        foreach($routes as $path=>[$method,$handler]){
            register_rest_route('mma-future/v1',$path,['methods'=>$method,'permission_callback'=>$permission,'callback'=>static function($request) use($handler){
                try{return new \WP_REST_Response(self::$handler($request));}
                catch(ImportException $e){return new \WP_Error($e->reason,$e->getMessage(),['status'=>$e->reason==='not_found'?404:($e->reason==='busy'?409:400)]);}
                catch(\Throwable $e){error_log('MMAF regular import: '.$e->getMessage());return new \WP_Error('import_error','The request could not be completed. Your saved import progress is safe. Check Technical details or the server log.',['status'=>500]);}
            }]);
        }
    }
    public static function uploadStart($request): array {
        return Mutex::run(function() use($request){
            $repo=new Repository();$active=$repo->active();
            $filename=sanitize_file_name((string)$request['filename']);$bytes=(int)$request['bytes'];
            if(strtolower(pathinfo($filename,PATHINFO_EXTENSION))!=='zip'||$bytes<1||$bytes>Storage::ZIP_LIMIT){throw new ImportException('Choose a ZIP file no larger than 128 MiB.');}
            if($active){
                if($active['status']==='uploading'&&$active['filename']===$filename&&(int)$active['meta']['upload_bytes']===$bytes){return ['id'=>(int)$active['id'],'chunk_size'=>Storage::CHUNK_SIZE];}
                throw new ImportException('Open the unfinished import before uploading another package.','active_job');
            }
            $id=$repo->create($filename,$bytes,get_current_user_id());
            // A database restore can legitimately reuse an old numeric job ID.
            // The freshly-created row owns this ID, so any orphaned test/import
            // directory must be removed before accepting the first chunk.
            $stale=Storage::root().'/job-'.$id;
            if(is_dir($stale)){Storage::removeTree($stale);}
            Storage::job($id);
            return ['id'=>$id,'chunk_size'=>Storage::CHUNK_SIZE];
        });
    }
    public static function uploadChunk($request): array {
        return Mutex::run(function() use($request){
            $repo=new Repository();$id=(int)$request['id'];$index=(int)$request['index'];$job=$repo->get($id);
            if($job['status']!=='uploading'){throw new ImportException('This upload has already finished.','state');}
            $total=(int)$job['meta']['upload_bytes'];$size=min(Storage::CHUNK_SIZE,$total-$index*Storage::CHUNK_SIZE);
            $body=$request->get_body();
            if($index<0||$size<=0||strlen($body)!==$size){throw new ImportException('The uploaded file part has the wrong size.');}
            $path=Storage::job($id).'/upload-'.$index.'.part';$hash=hash('sha256',$body);
            if(is_file($path)&&!hash_equals(hash_file('sha256',$path),$hash)){throw new ImportException('A different file was selected. Discard this upload and choose the correct ZIP.');}
            if(!is_file($path)){Storage::writeBytes($path,$body);}
            $meta=$job['meta'];$meta['received_chunks'][(string)$index]=$hash;$repo->update($id,['meta'=>$meta]);
            return ['received'=>$index];
        });
    }
    public static function uploadFinish($request): array {
        return Mutex::run(function() use($request){
            $repo=new Repository();$id=(int)$request['id'];$job=$repo->get($id);
            if($job['status']!=='uploading'){return self::view($job);}
            $count=(int)ceil($job['meta']['upload_bytes']/Storage::CHUNK_SIZE);
            for($i=0;$i<$count;$i++){if(empty($job['meta']['received_chunks'][(string)$i])){throw new ImportException('Some file parts are missing. Select the same ZIP to continue uploading.');}}
            $repo->update($id,['status'=>'queued','phase'=>'assemble']);return self::view($repo->get($id));
        });
    }
    public static function listing($request): array {
        $repo=new Repository();$active=$repo->active();
        $history=array_map(static fn($job)=>array_intersect_key($job,array_flip(['id','filename','status','phase','created_at','finished_at'])), $repo->recent());
        return ['active'=>$active?self::view($active):null,'history'=>$history];
    }
    public static function detail($request): array {return self::view((new Repository())->get((int)$request['id']));}
    public static function action($request): array {
        $id=(int)$request['id'];$op=(string)$request['operation'];$repo=new Repository();$job=$repo->get($id);
        if($op==='pause'){
            if(in_array($job['status'],['queued','running'],true)){$repo->update($id,['pause_requested'=>1]);}
            return self::view($repo->get($id));
        }
        return Mutex::run(function() use($request,$id,$op,$repo){
            $job=$repo->get($id);
            if($op==='approve'){
                if(in_array($job['status'],['queued','running'],true)&&$job['approved_by']){return self::view($job);}
                if($job['status']!=='awaiting_approval'||!hash_equals((string)($job['meta']['approval_token']??''),(string)$request['approval_token'])){throw new ImportException('Review the current checks before starting this import.','approval');}
                $setup=self::setup(null);
                if(!$setup['ready']){throw new ImportException('Finish the Setup checks before starting: '.implode('; ',$setup['problems']),'setup');}
                $repo->update($id,['approved_by'=>get_current_user_id(),'approved_hash'=>$job['meta']['approval_token'],'status'=>'queued','phase'=>'preflight','attempts'=>0,'error'=>null]);
            }elseif($op==='resume'){
                if(!in_array($job['status'],['paused','needs_attention'],true)){throw new ImportException('This import is not paused.','state');}
                $repo->update($id,['status'=>'queued','pause_requested'=>0,'attempts'=>0,'retry_at'=>null,'error'=>null]);
            }elseif($op==='discard'){
                if($job['writes_started']||$job['approved_by']){throw new ImportException('An approved import can be paused or resumed; its existing writes must be preserved.','state');}
                $repo->update($id,['status'=>'discarded','finished_at'=>gmdate('Y-m-d H:i:s')]);Storage::removeTree(Storage::job($id));
            }
            return self::view($repo->get($id));
        });
    }
    public static function setup($request): array {
        $problems=[];$storage=null;
        try{$storage=Storage::root();}catch(\Throwable $e){$problems[]=$e->getMessage();}
        foreach(['zip','pdo_mysql','zlib'] as $ext){if(!extension_loaded($ext)){$problems[]='PHP extension missing: '.$ext;}}
        $seen=get_option('mmaf_import_worker_seen');
        if(!$seen||strtotime($seen)<time()-180){$problems[]='Background worker has not checked in within three minutes.';}
        try{DatabaseState::engines();}catch(\Throwable $e){$problems[]=$e->getMessage();}
        return ['ready'=>!$problems,'problems'=>$problems,'worker_seen'=>$seen?:null,'storage'=>$storage,'free_bytes'=>$storage?disk_free_space($storage):null,'site_url'=>home_url('/'),'environment'=>wp_get_environment_type(),'zip_limit'=>Storage::ZIP_LIMIT,'chunk_size'=>Storage::CHUNK_SIZE,'runner_script'=>MMAF_PLUGIN_DIR.'bin/regular-import-runner.sh','wordpress_root'=>ABSPATH];
    }
    public static function view(array $job): array {
        $repo=new Repository();$id=(int)$job['id'];$meta=$job['meta'];$batches=$repo->batches($id);$progress=['checked'=>0,'completed'=>0,'total'=>count($batches)];
        foreach($batches as $batch){$progress['checked']+=(int)$batch['checked'];$progress['completed']+=(int)$batch['completed'];}
        $heartbeat=$job['heartbeat_at'];$heartbeatFile=Storage::root().'/job-'.$id.'/heartbeat.json';
        if(is_file($heartbeatFile)){$heartbeat=Storage::read($heartbeatFile)['at']??$heartbeat;}
        $view=array_intersect_key($job,array_flip(['id','filename','status','phase','created_at','finished_at','attempts','retry_at','pause_requested','writes_started','error','bundle_hash']));
        $view+=['progress'=>$progress,'last_activity'=>$heartbeat,'notice'=>$meta['notice']??null,'package'=>array_intersect_key($meta['package']??[],array_flip(['counts','date_from','date_to'])),'plan'=>$meta['plan']??$repo->planCounts($id),'actual'=>(new Engine())->actualCounts($id),'approval_token'=>$job['status']==='awaiting_approval'?($meta['approval_token']??null):null,'backup'=>isset($meta['backup'])?array_intersect_key($meta['backup'],array_flip(['bytes','created_at','sha256'])):null,'stats'=>$meta['stats']??null,'integrity'=>$meta['integrity']??null,'peak_memory_mb'=>$meta['peak_memory_mb']??null,'artifact_expired'=>!empty($meta['artifact_expired']),'site_url'=>$meta['site_url']??home_url('/')];
        $view['review_url']=add_query_arg(['page'=>'mmaf-review','tab'=>'needs_review','import_job_id'=>$id],admin_url('admin.php'));
        foreach(['backup','report'] as $kind){$view[$kind.'_url']=html_entity_decode(wp_nonce_url(add_query_arg(['action'=>'mmaf_regular_import_download','job_id'=>$id,'kind'=>$kind],admin_url('admin-post.php')),'mmaf_import_download_'.$id.'_'.$kind),ENT_QUOTES,'UTF-8');}
        return $view;
    }
    public static function download(): void {
        if(!current_user_can('manage_options')){wp_die('Not allowed','',['response'=>403]);}
        $id=absint($_GET['job_id']??0);$kind=sanitize_key($_GET['kind']??'');
        check_admin_referer('mmaf_import_download_'.$id.'_'.$kind);
        if(!in_array($kind,['backup','report'],true)){wp_die('Unknown artifact','',['response'=>400]);}
        $job=(new Repository())->get($id);$file=Storage::root().'/job-'.$id.'/'.($kind==='backup'?'before-import.sql.gz':'report.json');
        if(!is_file($file)||($kind==='backup'&&empty($job['meta']['backup']))){wp_die('This file is not available.','',['response'=>404]);}
        nocache_headers();header('X-Content-Type-Options: nosniff');header('Content-Type: '.($kind==='backup'?'application/gzip':'application/json'));header('Content-Disposition: attachment; filename="mmaf-import-'.$id.'-'.$kind.($kind==='backup'?'.sql.gz':'.json').'"');header('Content-Length: '.filesize($file));readfile($file);exit;
    }
}
