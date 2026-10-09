<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Mutex {
    private static $handle = null;
    public static function acquire(): void {
        if(self::$handle){return;}
        $handle=fopen(Storage::root().'/worker.lock','c');
        if(!$handle||!flock($handle,LOCK_EX|LOCK_NB)){if($handle){fclose($handle);}throw new ImportException('An MMA Future operation is already running. Please try again shortly.','busy',true);}
        self::$handle=$handle;
        register_shutdown_function(static function(){if(is_resource(self::$handle)){flock(self::$handle,LOCK_UN);fclose(self::$handle);self::$handle=null;}});
    }
    public static function run(callable $callback) {
        if (self::$handle) { return $callback(); }
        $handle = fopen(Storage::root() . '/worker.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) { fclose($handle); }
            throw new ImportException('The import worker is busy. Please try again shortly.', 'busy', true);
        }
        self::$handle = $handle;
        try { return $callback(); }
        finally { flock($handle, LOCK_UN); fclose($handle); self::$handle = null; }
    }
}
