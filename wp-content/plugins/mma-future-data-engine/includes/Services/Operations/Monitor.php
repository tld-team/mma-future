<?php
namespace MMAF\DataEngine\Services\Operations;
use MMAF\DataEngine\Services\RegularImport\{Storage,Repository};
use MMAF\DataEngine\Repositories\{RankingRunRepository,FighterStatsRepository};

/** Cheap administration reads; explicit diagnostics and an independent server monitor. */
final class Monitor {
    public static function snapshot(): array {
        global $wpdb;$table=Schema::tables()['jobs'];$runs=new RankingRunRepository();
        $release=get_option('mmaf_release',[]);
        return ['environment'=>wp_get_environment_type(),'release'=>$release,'deployment'=>self::privateState('release-status.json'),'capacity'=>self::privateState('capacity-status.json'),'schema'=>get_option('mmaf_db_version'),'active_ranking'=>$runs->get_active_ranking_run_id(),'latest_run'=>$runs->latest(),'statistics'=>(new FighterStatsRepository())->get_last_rebuild_summary(),'heartbeat'=>get_option('mmaf_operations_heartbeat'),'jobs'=>$wpdb->get_results("SELECT id,kind,status,phase,total,heartbeat_at,duration_ms,peak_memory,error_message FROM $table WHERE status IN ('running','previewing','paused','failed') ORDER BY id DESC LIMIT 25",ARRAY_A),'diagnostics'=>get_option('mmaf_operations_diagnostics',[]),'backup'=>get_option('mmaf_backup_status',[]),'restore'=>self::privateState('restore-status.json')?:get_option('mmaf_restore_check',[]),'retention'=>self::privateState('retention-status.json')?:get_option('mmaf_retention_status',[]),'alerts'=>$wpdb->get_results("SELECT alert_key,severity,message,last_seen_at FROM ".Schema::tables()['alerts']." WHERE resolved_at IS NULL ORDER BY last_seen_at DESC LIMIT 25",ARRAY_A)];
    }
    public static function privateState(string $name): array {
        if(!defined('MMAF_OPERATIONS_DIR') || !in_array($name,['release-status.json','capacity-status.json','capacity.json','apply-state.json','restore-status.json','retention-status.json'],true)){return [];}
        $path=MMAF_OPERATIONS_DIR.'/'.$name;
        if(!is_file($path) || filesize($path)>65536){return [];}
        return json_decode((string)file_get_contents($path),true)?:[];
    }
    public static function diagnostics(): array {
        global $wpdb;$root=Storage::root();$used=0;
        $roots=[$root];if(defined('MMAF_OPERATIONS_DIR') && realpath(MMAF_OPERATIONS_DIR)!==realpath($root)){$roots[]=MMAF_OPERATIONS_DIR;}
        foreach($roots as $private){if(!is_dir($private)){continue;}
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($private,\FilesystemIterator::SKIP_DOTS)) as $file){if($file->isFile()&&!$file->isLink()){$used+=$file->getSize();}}
        }
        $database=$wpdb->get_row($wpdb->prepare('SELECT SUM(data_length+index_length) bytes,COUNT(*) tables_total FROM information_schema.tables WHERE table_schema=%s',DB_NAME),ARRAY_A);
        $total=disk_total_space($root);$free=disk_free_space($root);
        $out=['checked_at'=>gmdate('Y-m-d H:i:s'),'database_bytes'=>(int)$database['bytes'],'private_bytes'=>$used,'disk_total'=>$total,'disk_free'=>$free,'disk_used_percent'=>$total?round(100*(1-$free/$total),1):null,'disk_scope'=>'Filesystem; account quota can differ'];
        update_option('mmaf_operations_diagnostics',$out,false);return $out;
    }
    public static function check(bool $send=true): array {
        $heartbeat=get_option('mmaf_operations_heartbeat');$seconds=$heartbeat?max(0,time()-strtotime($heartbeat.' UTC')):PHP_INT_MAX;
        $disk=get_option('mmaf_operations_diagnostics',[]);$alerts=[];
        if($seconds>=300){$alerts['heartbeat']=['severity'=>$seconds>=600?'critical':'warning','message'=>'Worker heartbeat is '.($heartbeat?$seconds.' seconds old':'missing').'.'];}
        if(isset($disk['disk_used_percent']) && $disk['disk_used_percent']>=80){$alerts['disk']=['severity'=>$disk['disk_used_percent']>=90?'critical':'warning','message'=>'Filesystem usage is '.$disk['disk_used_percent'].'%.'];}
        global $wpdb;$jobs=Schema::tables()['jobs'];$failed=(int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs WHERE status='failed'");if($failed){$alerts['failed_operations']=['severity'=>'critical','message'=>$failed.' operation(s) require attention.'];}
        $post=get_option('mmaf_after_commit_error');if($post){$alerts['invalidation']=['severity'=>'critical','message'=>'A post-commit cache invalidation needs retry.'];}
        $deployment=self::privateState('release-status.json');
        if(in_array($deployment['state']??'', ['failed','rolled_back'],true)){$alerts['release']=['severity'=>'critical','message'=>'Staging release #'.(int)($deployment['sequence']??0).' '.$deployment['state'].'. Inspect the Operations overview.'];}
        $capacity=self::privateState('capacity-status.json');$quota=self::privateState('capacity.json');
        $quotaAge=time()-(int)($quota['checked_at']??0);
        $bytesKnown=($quota['bytes_unlimited']??false)===true || (isset($quota['limit_bytes'],$quota['used_bytes']) && is_int($quota['limit_bytes']) && is_int($quota['used_bytes']));
        $inodesKnown=($quota['inodes_unlimited']??false)===true || (isset($quota['limit_inodes'],$quota['used_inodes']) && is_int($quota['limit_inodes']) && is_int($quota['used_inodes']));
        if(defined('MMAF_OPERATIONS_DIR') && (!$bytesKnown || !$inodesKnown || $quotaAge<0 || $quotaAge>3600)){$alerts['quota']=['severity'=>'warning','message'=>'Hosting account capacity is unknown or stale. Risky operations are paused until capacity is verified.'];}
        if(isset($capacity['account_used_percent']) && $capacity['account_used_percent']>=80){$alerts['account_disk']=['severity'=>$capacity['account_used_percent']>=90?'critical':'warning','message'=>'Hosting account usage is '.$capacity['account_used_percent'].'%.'];}
        if(isset($capacity['account_inode_used_percent']) && $capacity['account_inode_used_percent']>=80){$alerts['account_inodes']=['severity'=>$capacity['account_inode_used_percent']>=90?'critical':'warning','message'=>'Hosting inode usage is '.$capacity['account_inode_used_percent'].'%.'];}
        $restore=self::privateState('restore-status.json')?:get_option('mmaf_restore_check',[]);
        if(!$restore || empty($restore['ok']) || empty($restore['checked_at']) || strtotime($restore['checked_at'])<time()-30*DAY_IN_SECONDS){$alerts['restore']=['severity'=>'warning','message'=>'A successful full restore check is missing or older than 30 days.'];}
        if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','>=')){
            $stalled=$wpdb->get_results("SELECT id,progress_at,created_at,retry_at FROM $jobs WHERE status IN ('running','previewing') AND (retry_at IS NULL OR retry_at<=UTC_TIMESTAMP()) AND COALESCE(progress_at,created_at)<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) ORDER BY id LIMIT 25",ARRAY_A);
            foreach($stalled as $job){$alerts['stalled_'.$job['id']]=['severity'=>'critical','message'=>'Operation #'.$job['id'].' has made no confirmed progress for ten minutes.'];}
        }
        $retention=get_option('mmaf_retention_status',[]);if(!empty($retention['error'])){$alerts['retention']=['severity'=>'critical','message'=>'Retention stopped without removing protected data: '.$retention['error']];}
        if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','>=')){
            $failedArchives=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::tables()['archives']." WHERE status='failed'");
            if($failedArchives){$alerts['archives']=['severity'=>'critical','message'=>$failedArchives.' archive verification(s) failed. Data remains protected; inspect archive status.'];}
        }
        $table=Schema::tables()['alerts'];$now=gmdate('Y-m-d H:i:s');
        foreach($alerts as $key=>$alert){
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE alert_key=%s",$key),ARRAY_A);
            $mail=$send && wp_get_environment_type()!=='local' && $alert['severity']==='critical' && (!$old || $old['resolved_at'] || $old['severity']!=='critical' || !$old['emailed_at'] || strtotime($old['emailed_at'].' UTC')<time()-DAY_IN_SECONDS);
            $sent=$mail?self::email($key,$alert['message']):false;
            Repository::check($wpdb->query($wpdb->prepare("INSERT INTO $table (alert_key,severity,message,context_json,first_seen_at,last_seen_at,emailed_at,resolved_at) VALUES (%s,%s,%s,%s,%s,%s,NULLIF(%s,''),NULL) ON DUPLICATE KEY UPDATE severity=VALUES(severity),message=VALUES(message),context_json=VALUES(context_json),last_seen_at=VALUES(last_seen_at),emailed_at=IF(VALUES(emailed_at) IS NULL,emailed_at,VALUES(emailed_at)),resolved_at=NULL",$key,$alert['severity'],$alert['message'],wp_json_encode(['environment'=>wp_get_environment_type()]),$now,$now,$sent?$now:'')));
        }
        $keys=array_keys($alerts);$where=$keys?' WHERE alert_key NOT IN ('.implode(',',array_fill(0,count($keys),'%s')).')':'';
        Repository::check($wpdb->query($keys?$wpdb->prepare("UPDATE $table SET resolved_at=%s $where",array_merge([$now],$keys)):$wpdb->prepare("UPDATE $table SET resolved_at=%s",$now)));
        return ['checked_at'=>$now,'alerts'=>$alerts];
    }
    public static function email(string $job,string $reason): bool {
        return wp_mail('luka.m@tldteam.com','[MMA Future]['.wp_get_environment_type().'] '.($job==='test'?'TEST operational notification':'Critical: '.$job),"Environment: ".wp_get_environment_type()."\nJob: $job\nReason: $reason\nAdministration: ".admin_url('admin.php?page=mmaf-health')."\nThis message contains no profile payload or credentials.");
    }
}
