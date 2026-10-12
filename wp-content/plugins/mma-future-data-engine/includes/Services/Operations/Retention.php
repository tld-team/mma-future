<?php
namespace MMAF\DataEngine\Services\Operations;
use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\RegularImport\{Mutex,Transaction,Repository,Storage};

/** Fail-closed policy. Activation requires both a saved dry run and full restore proof. */
final class Retention {
    public static function enabled(): bool {
        return (bool)get_option('mmaf_retention_enabled',false) && defined('MMAF_OPERATIONS_DIR');
    }
    public static function dryRun(): array {
        global $wpdb;$t=Canonical::table_names();$o=Schema::tables();
        $out=['format'=>1,'checked_at'=>gmdate('c'),'schema'=>get_option('mmaf_db_version'),'enabled'=>self::enabled(),'imports'=>self::importPreview(),'archives'=>[],'rules'=>['imports_days'=>30,'imports_minimum'=>3,'archive_days'=>365,'drafts_minimum'=>10,'temporary_days'=>7,'logs_days'=>30,'metrics_days'=>90,'summaries_months'=>24,'backups_daily'=>7,'backups_weekly'=>4,'backups_monthly'=>3,'releases_minimum'=>5]];
        foreach(['review','audit'] as $kind){$out['archives'][$kind]=['eligible'=>Archive::candidateCount($kind),'rule'=>'Only closed, linked evidence with a verified isolated restore; manual/activation provenance retained.'];}
        $out['archives']['draft']='Only ledger-tracked, never activated drafts older than 12 months; latest ten and unknown history retained.';
        $out['temporary_rows']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$o['stats']} g JOIN {$o['jobs']} j ON j.id=g.generation_id WHERE j.status IN ('completed','cancelled') AND j.finished_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) AND j.id<>".(int)get_option('mmaf_stats_generation',0));
        $out['sha256']=hash('sha256',wp_json_encode($out));update_option('mmaf_retention_dry_run',$out,false);self::status($out);return $out;
    }
    public static function enable(): void {
        $dry=get_option('mmaf_retention_dry_run',[]);$restore=Monitor::privateState('restore-status.json');
        if(!$dry || time()-strtotime($dry['checked_at'])>DAY_IN_SECONDS || $dry['schema']!==get_option('mmaf_db_version') || empty($restore['ok']) || empty($restore['backup_sha256']) || ($restore['schema']??null)!==get_option('mmaf_db_version') || !isset($restore['media_files']) || time()-strtotime($restore['checked_at'])>30*DAY_IN_SECONDS){throw new \RuntimeException('A current dry-run report and successful full database/media restore are required.');}
        update_option('mmaf_retention_enabled',true,false);update_option('mmaf_retention_acceptance',['enabled_at'=>gmdate('c'),'dry_run_sha256'=>$dry['sha256'],'backup_sha256'=>$restore['backup_sha256']],false);
        Storage::write(MMAF_OPERATIONS_DIR.'/retention-accepted.json',['schema'=>get_option('mmaf_db_version'),'enabled_at'=>gmdate('c'),'dry_run_sha256'=>$dry['sha256'],'backup_sha256'=>$restore['backup_sha256']]);
        self::status(['enabled'=>true,'checked_at'=>gmdate('c'),'acceptance'=>get_option('mmaf_retention_acceptance')]);
    }
    private static function status(array $result): void {
        update_option('mmaf_retention_status',$result,false);
        if(defined('MMAF_OPERATIONS_DIR')){Storage::write(MMAF_OPERATIONS_DIR.'/retention-status.json',$result);}
    }
    private static function importPredicate(string $alias='j'): string {
        $o=Schema::tables();$r=Canonical::table_names()['review_items'];$p=\MMAF\DataEngine\Services\DataPipeline\Schema::tables();
        $jobs=\MMAF\DataEngine\Services\RegularImport\Schema::tables()['jobs'];
        // Jobs in review/retry and source evidence with an open linked review are protected.
        return "$alias.status IN ('completed','completed_with_review','rejected','discarded') AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($alias.meta_json,'$.artifact_expired')),'false')<>'true' AND COALESCE($alias.finished_at,$alias.updated_at)<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM $jobs WHERE status IN ('completed','completed_with_review') ORDER BY id DESC LIMIT 3) retained WHERE retained.id=$alias.id) AND NOT EXISTS (SELECT 1 FROM {$o['review_links']} l JOIN $r r ON r.id=l.review_id WHERE l.entity_type='import' AND l.entity_id=$alias.id AND r.status NOT IN ('resolved','dismissed')) AND NOT EXISTS (SELECT 1 FROM {$p['queue']} q WHERE q.import_job_id=$alias.id AND q.status IN ('pending','paused','failed','review'))";
    }
    public static function importPreview(): array {
        global $wpdb;$table=\MMAF\DataEngine\Services\RegularImport\Schema::tables()['jobs'];$where=self::importPredicate();
        $links=get_option('mmaf_review_link_backfill',[]);
        if(empty($links['done'])){return ['eligible'=>0,'blocked'=>'Review evidence links must finish before import retention.'];}
        return ['eligible'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $table j WHERE $where"),'protected_backups'=>'Import backup SQL and manifests remain protected in their private directories.'];
    }
    public static function expireImports(): array {
        if(!self::enabled()){return ['removed'=>0,'blocked'=>'Retention has not been accepted.'];}
        return Mutex::run(static function(){
            global $wpdb;$repo=new Repository();$table=$repo->tables['jobs'];$where=self::importPredicate();$links=get_option('mmaf_review_link_backfill',[]);
            if(empty($links['done'])){return ['removed'=>0,'blocked'=>'Review evidence links are incomplete.'];}
            $rows=$wpdb->get_results("SELECT j.* FROM $table j WHERE $where ORDER BY j.id LIMIT 10",ARRAY_A);$removed=0;
            foreach($rows as $raw){$job=$repo->decode($raw);if(!empty($job['meta']['artifact_expired'])){continue;}$id=(int)$job['id'];
                $dir=Storage::root().'/job-'.$id;
                // Keep the original backup and manifest; source/current profiles stay in DB.
                if(is_dir($dir)){
                    foreach(new \FilesystemIterator($dir) as $file){
                        if(in_array($file->getFilename(),['before-import.sql.gz','backup-manifest.json'],true)){continue;}
                        if($file->isDir() && !$file->isLink()){Storage::removeTree($file->getPathname());}
                        else{unlink($file->getPathname());}
                    }
                }
                $meta=$job['meta'];$meta['artifact_expired']=true;$meta['retained_backup']=true;$repo->update($id,['meta'=>$meta]);$removed++;
            }
            return ['removed'=>$removed];
        });
    }
    private static function temporary(): int {
        global $wpdb;$o=Schema::tables();$current=(int)get_option('mmaf_stats_generation',0);$removed=0;
        foreach(['stats'=>'generation_id','scores'=>'operation_id'] as $kind=>$key){
            $table=$o[$kind];$job=(int)$wpdb->get_var("SELECT j.id FROM {$o['jobs']} j WHERE j.status IN ('completed','cancelled') AND j.finished_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) AND j.id<>$current AND EXISTS (SELECT 1 FROM $table g WHERE g.$key=j.id) AND NOT EXISTS (SELECT 1 FROM {$o['jobs']} p WHERE p.status NOT IN ('completed','cancelled') AND (JSON_UNQUOTE(JSON_EXTRACT(p.checkpoint_json,'$.stats_generation'))=CAST(j.id AS CHAR) OR JSON_UNQUOTE(JSON_EXTRACT(p.checkpoint_json,'$.stats_job'))=CAST(j.id AS CHAR) OR JSON_UNQUOTE(JSON_EXTRACT(p.checkpoint_json,'$.ranking_job'))=CAST(j.id AS CHAR))) ORDER BY j.id LIMIT 1");
            if(!$job){continue;}
            Repository::check($wpdb->query($wpdb->prepare("DELETE FROM $table WHERE $key=%d LIMIT 250",$job)));$removed+=$wpdb->rows_affected;
        }
        return $removed;
    }
    public static function work(): array {
        if(!self::enabled()){return ['worked'=>false];}
        try{
            return Mutex::run(static function(){
                Archive::schedule();
                $archive=Archive::work(true);$temporary=Transaction::run(static fn()=>self::temporary());$imports=self::expireImports();
                $result=['worked'=>!empty($archive['worked']) || $temporary>0 || !empty($imports['removed']),'enabled'=>true,'checked_at'=>gmdate('c'),'archive'=>$archive,'temporary_rows_removed'=>$temporary,'imports'=>$imports];self::status($result);return $result;
            });
        }catch(\Throwable $e){self::status(['enabled'=>true,'checked_at'=>gmdate('c'),'error'=>substr($e->getMessage(),0,255)]);return ['worked'=>false,'error'=>'Retention stopped; protected data retained.'];}
    }
}
