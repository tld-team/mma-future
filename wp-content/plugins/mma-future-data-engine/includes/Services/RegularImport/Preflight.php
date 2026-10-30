<?php
namespace MMAF\DataEngine\Services\RegularImport;
use MMAF\DataEngine\Services\Operations\{Capacity,MediaBackup};

final class Preflight {
    public static function inspect(): array {
        global $wpdb;$problems=[];$estimate=0;$media=['bytes'=>0,'files'=>0];
        if(version_compare((string)get_option('mmaf_db_version','0'),MMAF_DB_VERSION,'<')){$problems[]='Verified database migration is required before import.';}
        try {
            DatabaseState::engines();
            $estimate=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s',DB_NAME));
            if($wpdb->last_error){throw new \RuntimeException('Cannot estimate the database backup.');}
            if(defined('MMAF_OPERATIONS_DIR')){
                $media=MediaBackup::estimate();
                Capacity::requireSpace($estimate*2+134217728+$media['bytes'],$media['files']+100);
            }else{$problems[]='Verified private account capacity is required.';}
        }catch(\Throwable $e){$problems[]=$e->getMessage();}
        $capacity=[];$restore=[];
        if(defined('MMAF_OPERATIONS_DIR')){
            foreach(['capacity.json'=>'capacity','restore-status.json'=>'restore'] as $file=>$name){$path=MMAF_OPERATIONS_DIR.'/'.$file;if(is_file($path)){$data=json_decode(file_get_contents($path),true)?:[];if($name==='capacity'){$capacity=$data;}else{$restore=array_intersect_key($data,array_flip(['checked_at','backup_sha256','ok','tables','media_files']));}}}
        }
        $due=true;
        if(defined('MMAF_OPERATIONS_DIR')){$path=MMAF_OPERATIONS_DIR.'/restore-status.json';$evidence=is_file($path)?json_decode(file_get_contents($path),true):[];$checked=strtotime((string)($evidence['checked_at']??''));$due=empty($evidence['ok']) || ($evidence['schema']??'')!==get_option('mmaf_db_version') || !$checked || time()-$checked>30*86400;}
        return ['ready'=>!$problems,'problems'=>$problems,'ufc_transliteration_available'=>function_exists('transliterator_transliterate'),'ufc_warning'=>function_exists('transliterator_transliterate')?null:'PHP intl is unavailable; UFC matching continues without transliteration.','backup_estimated_bytes'=>$estimate*2+134217728+$media['bytes'],'media_files'=>$media['files'],'capacity'=>$capacity,'restore_verification'=>$restore,'restore_verification_due'=>$due];
    }
    public static function recordCapacity(array $input): array {
        if(!defined('MMAF_OPERATIONS_DIR')){throw new ImportException('Private operations storage is unavailable.','setup');}
        $data=['checked_at'=>time(),'source'=>'manual_cpanel'];
        foreach(['bytes','inodes'] as $kind){
            $unlimited=$input[$kind.'_unlimited']??false;
            if(!is_bool($unlimited)){throw new ImportException('Unlimited capacity must be an explicit boolean.');}
            $data[$kind.'_unlimited']=$unlimited;
            foreach(['used','limit'] as $prefix){
                $key=$prefix.'_'.$kind;$value=$input[$key]??null;
                if($prefix==='limit' && $unlimited){continue;}
                if(!is_int($value) || $value<0 || ($prefix==='limit' && $value<1)){throw new ImportException('Enter current non-negative account usage and a positive limit.');}
                $data[$key]=$value;
            }
            if(!$unlimited && $data['used_'.$kind]>$data['limit_'.$kind]){throw new ImportException('Usage exceeds the entered limit.');}
        }
        Mutex::run(static function() use($data){Storage::write(MMAF_OPERATIONS_DIR.'/capacity.json',$data);});
        return self::inspect();
    }
}
