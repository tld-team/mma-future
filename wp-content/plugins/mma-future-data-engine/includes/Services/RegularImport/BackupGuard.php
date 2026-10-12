<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** Take the writer barrier BEFORE the canonical mutex to avoid lock inversion. */
final class BackupGuard {
    public static bool $owned=false;
    public static function run(callable $callback) {
        if(!defined('MMAF_OPERATIONS_DIR')){
            if(getenv('MMAF_OPERATIONS_DIR')){throw new ImportException('The configured private write gate did not load. Check wp-config before starting backup.','backup');}
            if(wp_get_environment_type()==='local'){return $callback();}
            throw new ImportException('Install the private write gate before backup or import.','backup');
        }
        if(!function_exists('mmaf_runner_owns_write_lock')){throw new ImportException('The early request write gate is not installed.','backup');}
        if(mmaf_runner_owns_write_lock()){return $callback();}
        global $mmaf_writer_lock;$root=MMAF_OPERATIONS_DIR;$deployment=fopen($root.'/deployment.lock','c');
        if(!$deployment || !flock($deployment,LOCK_EX|LOCK_NB)){if($deployment){fclose($deployment);}throw new ImportException('An import backup or release is already preparing.','busy',true);}
        chmod($root.'/deployment.lock',0600);$flag=$root.'/writes-paused.flag';$writers=null;$created=false;$ownRequest=is_resource($mmaf_writer_lock);$mask=umask(0077);
        try{
            if(is_file($root.'/maintenance.flag') || is_file($flag)){throw new ImportException('A protected checkpoint is already active.','busy',true);}
            if(file_put_contents($flag,(string)time())===false){throw new ImportException('Cannot protect backup writes.','backup');}$created=true;chmod($flag,0600);
            $writers=$ownRequest?$mmaf_writer_lock:fopen($root.'/writers.lock','c');
            if(!$writers){throw new ImportException('Cannot open the writer lock.','backup');}
            if($ownRequest){flock($writers,LOCK_UN);}
            $deadline=microtime(true)+30;
            while(!flock($writers,LOCK_EX|LOCK_NB)){if(microtime(true)>$deadline){throw new ImportException('Waiting for an active write to reach its checkpoint.','busy',true);}usleep(100000);}
            self::$owned=true;return $callback();
        }finally{
            self::$owned=false;
            if(is_resource($writers)){if($ownRequest){flock($writers,LOCK_SH);}else{flock($writers,LOCK_UN);fclose($writers);}}
            if($created){@unlink($flag);}flock($deployment,LOCK_UN);fclose($deployment);umask($mask);
        }
    }
}
