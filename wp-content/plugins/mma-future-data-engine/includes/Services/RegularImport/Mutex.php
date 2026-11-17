<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Mutex {
    /** Seconds a human POST waits for the unit that is currently running. */
    private const INTERACTIVE_WAIT = 15.0;
    private static $handle = null;
    public static function acquire(): void {
        if(self::$handle){return;}
        $inherited=getenv('MMAF_DEPLOY_WRITE_FD');
        if(function_exists('mmaf_runner_owns_write_lock') && mmaf_runner_owns_write_lock()){self::$handle=fopen('php://fd/'.$inherited,'r+');if(!self::$handle){throw new ImportException('Deployment lock is unavailable.','busy',true);}return;}
        $handle=fopen(self::path(),'c');
        if(!$handle||!self::lock($handle)){if($handle){fclose($handle);}throw new ImportException('An MMA Future operation is already running. Please try again shortly.','busy',true);}
        self::$handle=$handle;
        register_shutdown_function(static function(){if(is_resource(self::$handle)){flock(self::$handle,LOCK_UN);fclose(self::$handle);self::$handle=null;}});
    }
    public static function run(callable $callback) {
        if (self::$handle) { return $callback(); }
        if(function_exists('mmaf_runner_owns_write_lock') && mmaf_runner_owns_write_lock()){self::acquire();return $callback();}
        $handle = fopen(self::path(), 'c');
        if (!$handle || !self::lock($handle)) {
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
    private static function lock($handle): bool {
        // System workers yield immediately. A human POST may wait briefly for
        // the current atomic unit, without weakening the shared write lock.
        // While it waits it holds a shared "intent" lock; system workers check
        // that first and step aside. Otherwise a worker with a backlog re-takes
        // the write lock within milliseconds of releasing it and a polling
        // save never gets a turn.
        $interactive=($_SERVER['REQUEST_METHOD']??'')==='POST' && !(defined('WP_CLI') && WP_CLI);
        $intent=@fopen(self::path().'.interactive','c');
        if(!$interactive){
            if($intent){
                $free=flock($intent,LOCK_EX|LOCK_NB);
                if($free){flock($intent,LOCK_UN);}
                fclose($intent);
                if(!$free){return false;}
            }
            return flock($handle,LOCK_EX|LOCK_NB);
        }
        if($intent){flock($intent,LOCK_SH);}
        $deadline=microtime(true)+self::INTERACTIVE_WAIT;
        try {
            do {
                if(flock($handle,LOCK_EX|LOCK_NB)){return true;}
                if(microtime(true)>=$deadline){return false;}
                usleep(5000);
            } while(true);
        } finally {
            if($intent){flock($intent,LOCK_UN);fclose($intent);}
        }
    }
}
