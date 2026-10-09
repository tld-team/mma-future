<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/pipeline-tests/LegacyStatsRebuildService.php';
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard};
if(DB_NAME!=='mmaf_frontend_dev'){throw new RuntimeException('Isolated dev clone required.');}
WriteGuard::$worker=true;Transaction::begin();
try {
 memory_reset_peak_usage();$baseline=memory_get_usage();$start=microtime(true);
 $service=($argv[1]??'new')==='old'?new MMAF\DataEngine\Services\LegacyStatsRebuildService():new MMAF\DataEngine\Services\StatsRebuildService();
 $summary=$service->rebuild_all();
 echo wp_json_encode(['implementation'=>$argv[1]??'new','fighters'=>$summary['fighters_total'],'bouts'=>$summary['countable_bouts'],'seconds'=>microtime(true)-$start,'baseline_bytes'=>$baseline,'peak_used_bytes'=>memory_get_peak_usage(),'peak_allocated_bytes'=>memory_get_peak_usage(true)]);
} finally {Transaction::rollback();WriteGuard::$worker=false;}
