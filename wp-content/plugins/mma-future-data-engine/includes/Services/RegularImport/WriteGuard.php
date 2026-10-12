<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class WriteGuard {
    public static bool $worker = false;
    public static int $calculation=0;
    public static function assertAllowed(bool $control=false): void {
        if(defined('MMAF_OPERATIONS_DIR') && (is_file(MMAF_OPERATIONS_DIR.'/maintenance.flag') || (is_file(MMAF_OPERATIONS_DIR.'/writes-paused.flag') && !BackupGuard::$owned)) && !(function_exists('mmaf_runner_owns_write_lock') && mmaf_runner_owns_write_lock())){throw new ImportException('Backup or release preparation has paused new writes. Try again shortly.','release_locked',true);}
        Mutex::acquire();
        if (!self::$worker && !$control && version_compare((string)get_option('mmaf_db_version','0'),'1.8.0','>=')) {
            global $wpdb; $table=\MMAF\DataEngine\Services\Operations\Schema::tables()['jobs'];
            $active=(int)$wpdb->get_var("SELECT id FROM $table WHERE kind IN ('stats','ranking') AND status='running' ORDER BY id LIMIT 1");
            if($active && $active!==self::$calculation){throw new ImportException('A frozen calculation is running. Pause or finish it before changing canonical data.','calculation_locked',true);}
        }
        if (self::$worker || version_compare((string)get_option('mmaf_db_version','0'),'1.0.0','<')) { return; }
        $job=(new Repository())->active();
        if ($job && (!empty($job['approved_by']) || !empty($job['writes_started']))) {
            throw new ImportException('An import is in progress. Finish or resume it before changing MMA Future data.','import_locked');
        }
    }
    public static function adminGuard(): void {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { return; }
        $page=sanitize_key($_GET['page']??''); $action=sanitize_key($_REQUEST['action']??'');
        if (strpos($page,'mmaf')!==0 && strpos($action,'mmaf_')!==0) { return; }
        try { self::assertAllowed($action==='mmaf_operation' || ($page==='mmaf-fighters' && ($_POST['mmaf_action']??'')==='bulk_readiness')); } catch (ImportException $e) { wp_die(esc_html($e->getMessage()),'Import in progress',['response'=>409,'back_link'=>true]); }
    }
}
