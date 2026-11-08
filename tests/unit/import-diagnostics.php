<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/mmaf-import-diagnostics-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);mkdir($root.'/public',0700,true);
define('ABSPATH',$root.'/public/');define('MMAF_IMPORT_STORAGE_DIR',$root.'/imports');define('DB_NAME','mmaf_diagnostics_test');
function home_url(string $path=''): string { return 'http://mmaf-diagnostics.test'.$path; }
function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path,0700,true); }
function wp_json_encode($data,int $flags=0): string|false { return json_encode($data,$flags); }
$GLOBALS['wpdb']=(object)['prefix'=>'wp_'];
require __DIR__.'/../../wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport/ImportException.php';
require __DIR__.'/../../wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport/Storage.php';
require __DIR__.'/../../wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport/Diagnostics.php';
use MMAF\DataEngine\Services\RegularImport\{Diagnostics,ImportException,Storage};
function check_diagnostic(bool $value,string $message): void {if(!$value){throw new RuntimeException($message);}echo "PASS: $message\n";}
try {
    $job=['id'=>41,'phase'=>'results'];
    $error=new ImportException('Duplicate source identity.','record_failure',false,['batch'=>['id'=>9,'kind'=>'results','batch_index'=>3,'payload_hash'=>str_repeat('a',64),'row_count'=>20],'record'=>['item_type'=>'bout','source_id'=>'tapology_bout_123','action'=>'create','error_message'=>'Duplicate source identity.','identity_hash'=>str_repeat('b',64),'raw_profile'=>'must-not-be-written']]);
    $entry=Diagnostics::record($job,$error,1);$history=Diagnostics::history(41);
    check_diagnostic($entry['diagnostic_id']===$history[0]['diagnostic_id'] && $history[0]['batch']['batch_index']===3,'diagnostic persists the job, phase and failed batch after rollback');
    check_diagnostic(!str_contains((string)file_get_contents(Storage::job(41).'/'.$history[0]['file']),'must-not-be-written'),'diagnostic excludes raw source payloads');
    check_diagnostic((fileperms(Storage::job(41).'/'.$history[0]['file'])&0777)===0600,'diagnostic artifact is private');
    check_diagnostic(!Diagnostics::retryable(new RuntimeException('Duplicate source identity.')) && Diagnostics::retryable(new RuntimeException('Deadlock found when trying to get lock')),'only recognized transient database failures retry automatically');
} finally {
    if(is_dir($root)){$items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($items as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($root);}
}
