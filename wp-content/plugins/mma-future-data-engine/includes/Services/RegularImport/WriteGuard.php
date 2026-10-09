<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class WriteGuard {
    public static bool $worker = false;
    public static function assertAllowed(): void {
        if (self::$worker) { return; }
        Mutex::acquire();
        $job=(new Repository())->active();
        if ($job && (!empty($job['approved_by']) || !empty($job['writes_started']))) {
            throw new ImportException('An import is in progress. Finish or resume it before changing MMA Future data.','import_locked');
        }
    }
    public static function adminGuard(): void {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { return; }
        $page=sanitize_key($_GET['page']??''); $action=sanitize_key($_REQUEST['action']??'');
        if (strpos($page,'mmaf')!==0 && strpos($action,'mmaf_')!==0) { return; }
        try { self::assertAllowed(); } catch (ImportException $e) { wp_die(esc_html($e->getMessage()),'Import in progress',['response'=>409,'back_link'=>true]); }
    }
}
