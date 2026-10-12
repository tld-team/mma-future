<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Mutex {
    private static $handle = null;
    public static function acquire(): void {
        if(self::$handle){return;}
        $inherited=getenv('MMAF_DEPLOY_WRITE_FD');
        if(function_exists('mmaf_runner_owns_write_lock') && mmaf_runner_owns_write_lock()){self::$handle=fopen('php://fd/'.$inherited,'r+');if(!self::$handle){throw new ImportException('Deployment lock is unavailable.','busy',true);}return;}
        $handle=fopen(self::path(),'c');
        if(!$handle||!flock($handle,LOCK_EX|LOCK_NB)){if($handle){fclose($handle);}throw new ImportException('An MMA Future operation is already running. Please try again shortly.','busy',true);}
        self::$handle=$handle;
        register_shutdown_function(static function(){if(is_resource(self::$handle)){flock(self::$handle,LOCK_UN);fclose(self::$handle);self::$handle=null;}});
    }
    public static function run(callable $callback) {
        if (self::$handle) { return $callback(); }
        if(function_exists('mmaf_runner_owns_write_lock') && mmaf_runner_owns_write_lock()){self::acquire();return $callback();}
        $handle = fopen(self::path(), 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) { fclose($handle); }
            throw new ImportException('The import worker is busy. Please try again shortly.', 'busy', true);
        }
        self::$handle = $handle;
        try { return $callback(); }
        finally { flock($handle, LOCK_UN); fclose($handle); self::$handle = null; }
    }
    public static function path(): string {
        return getenv('MMAF_WRITE_LOCK') ?: (defined('MMAF_WRITE_LOCK') ? MMAF_WRITE_LOCK : Storage::root().'/worker.lock');
    }
}
