<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** One bootstrap, bounded atomic work, unchanged shared locks and checkpoints. */
final class Worker {
    public static function run(int $seconds=35,int $memoryMb=256,int $maxSteps=500,?callable $unit=null): array {
        $seconds=max(1,min(35,$seconds));$maxSteps=max(1,min(1000,$maxSteps));
        $configured=wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memoryMb=max(32,min(384,$memoryMb));
        $ceiling=$configured>0?min($memoryMb*1048576,(int)($configured*.75)):$memoryMb*1048576;
        global $wpdb;$queryStart=$wpdb->num_queries;$start=microtime(true);$units=0;$last=['worked'=>false];$reason='step_budget';
        while($units<$maxSteps){
            if(self::paused()){$reason='protected_checkpoint';break;}
            if(microtime(true)-$start>=$seconds){$reason='time_budget';break;}
            if(memory_get_usage(true)>=$ceiling){$reason='memory_budget';break;}
            update_option('mmaf_operations_heartbeat',gmdate('Y-m-d H:i:s'),false);
            update_option('mmaf_import_worker_seen',gmdate('c'),false);
            try{$last=$unit?$unit():self::step();}
            catch(ImportException $e){if(in_array($e->reason,['busy','release_locked'],true)){$reason=$e->reason;break;}throw $e;}
            if(empty($last['worked'])){$reason=$last['blocked']??'idle';break;}
            ++$units;
            if(function_exists('wp_cache_flush_runtime')){wp_cache_flush_runtime();}
        }
        return ['worked'=>$units>0,'units'=>$units,'stop_reason'=>$reason,'elapsed_seconds'=>round(microtime(true)-$start,3),'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,1),'db_queries'=>$wpdb->num_queries-$queryStart,'last'=>$last,'bootstraps'=>1];
    }
    private static function paused(): bool {
        if(!defined('MMAF_OPERATIONS_DIR')){return false;}
        foreach(['writes-paused.flag','maintenance.flag','recovery-readonly.flag'] as $flag){clearstatcache(true,MMAF_OPERATIONS_DIR.'/'.$flag);if(is_file(MMAF_OPERATIONS_DIR.'/'.$flag)){return true;}}
        return false;
    }
    private static function step(): array {
        $job=(new Repository())->active();
        if($job){return (new Engine())->work();}
        $repairs=(new \MMAF\DataEngine\Services\UfcSigningService())->work_repairs();
        if(!empty($repairs['worked'])){return $repairs;}
        $comparison=(new \MMAF\DataEngine\Services\UfcSigningService())->work_compare();
        if(!empty($comparison['worked'])){return $comparison;}
        // Complete the import's preparation/draft before general readiness refreshes.
        // Processor yields when another frozen calculation owns the inputs.
        $result=(new \MMAF\DataEngine\Services\DataPipeline\Processor())->work();
        if(!empty($result['worked'])){return $result;}
        return \MMAF\DataEngine\Services\Operations\Module::work();
    }
}
