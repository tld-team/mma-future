<?php
namespace MMAF\DataEngine\Services\RegularImport;
use MMAFVendor\Ifsnop\Mysqldump\Mysqldump;
final class Backup {
    public static function create(int $job,callable $heartbeat): array {
        global $wpdb;
        DatabaseState::engines();
        if(!extension_loaded('pdo_mysql')||!extension_loaded('zlib')){throw new ImportException('Automatic backup needs PDO MySQL and gzip support. Open Setup.','backup');}
        $estimate=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s',DB_NAME));
        if(defined('MMAF_OPERATIONS_DIR')){
            $media=\MMAF\DataEngine\Services\Operations\MediaBackup::estimate();
            \MMAF\DataEngine\Services\Operations\Capacity::requireSpace($estimate*2+134217728+$media['bytes'],$media['files']+100);
        }
        $dir=Storage::job($job);$free=disk_free_space($dir);
        if($free===false||$free < $estimate*2+134217728){throw new ImportException('Not enough disk space to create and verify the database backup.','disk_space');}
        $file=$dir.'/before-import.sql.gz';$temp=$file.'.partial';
        if(is_file($temp)){unlink($temp);}
        $host=DB_HOST;$port=null;$socket=null;
        if(strpos($host,':/')!==false){[$host,$socket]=explode(':',$host,2);}
        elseif(preg_match('/^(.+):(\d+)$/',$host,$m)){$host=$m[1];$port=$m[2];}
        $dsn='mysql:dbname='.DB_NAME.';charset='.DB_CHARSET.';'.($socket?'unix_socket='.$socket:'host='.$host.($port?';port='.$port:''));
        $dump=new Mysqldump($dsn,DB_USER,DB_PASSWORD,[
            'compress'=>Mysqldump::GZIP,'single-transaction'=>true,'lock-tables'=>false,
            'add-drop-table'=>true,'no-create-db'=>true,'routines'=>true,'events'=>true,'hex-blob'=>true,
        ],[\PDO::ATTR_PERSISTENT=>false,\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $tables=[];$last=0;
        $dump->setInfoHook(function($type,$info) use (&$tables,$heartbeat){if($type==='table'){$tables[$info['name']]=(int)$info['rowCount'];$heartbeat();}});
        $dump->setTransformTableRowHook(function($table,$row) use ($heartbeat,&$last){if(time()-$last>=3){$heartbeat();$last=time();}return $row;});
        try{$dump->start($temp);}catch(\Throwable $e){@unlink($temp);throw new ImportException('Database backup failed: '.$e->getMessage(),'backup',true);}
        if(!is_file($temp)||filesize($temp)<64||!$tables){throw new ImportException('Database backup was incomplete.','backup');}
        $stream=gzopen($temp,'rb');$bytes=0;$crc=hash_init('crc32b');
        if(!$stream){throw new ImportException('Cannot verify compressed backup.','backup');}
        try{while(!gzeof($stream)){$chunk=gzread($stream,1048576);if($chunk===false){throw new ImportException('Backup compression verification failed.','backup');}$bytes+=strlen($chunk);hash_update($crc,$chunk);$heartbeat();}}
        finally{gzclose($stream);}
        if(!$bytes){throw new ImportException('Database backup is empty.','backup');}
        $raw=fopen($temp,'rb');fseek($raw,-8,SEEK_END);$footer=unpack('Vcrc/Vsize',fread($raw,8));fclose($raw);
        if(sprintf('%08x',$footer['crc'])!==hash_final($crc) || $footer['size']!==($bytes%4294967296)){throw new ImportException('Compressed backup checksum or size is invalid.','backup');}
        chmod($temp,0600);if(!rename($temp,$file)){throw new ImportException('Cannot finalize database backup.','storage');}
        $result=['file'=>'before-import.sql.gz','bytes'=>filesize($file),'uncompressed_bytes'=>$bytes,'sha256'=>hash_file('sha256',$file),'tables'=>$tables,'created_at'=>gmdate('c'),'database'=>DB_NAME,'site_url'=>home_url('/')];
        Storage::write($dir.'/backup-manifest.json',$result);
        if(defined('MMAF_OPERATIONS_DIR')){
            $catalog=MMAF_OPERATIONS_DIR.'/catalog';$target=MMAF_OPERATIONS_DIR.'/backups/import-'.$job;
            if(!wp_mkdir_p($catalog) || !wp_mkdir_p($target)){throw new ImportException('Cannot register the private import backup.','backup');}chmod($catalog,0700);chmod($target,0700);
            $registered=$target.'/database.sql.gz';
            if(!is_file($registered) && !@link($file,$registered) && !copy($file,$registered)){throw new ImportException('Cannot retain the import backup.','backup');}
            if(hash_file('sha256',$registered)!==$result['sha256']){throw new ImportException('Registered import backup differs.','backup');}chmod($registered,0600);
            $manifest=['format'=>1,'path'=>realpath($registered),'reason'=>'import','import_job_id'=>$job,'created_at'=>$result['created_at'],'environment'=>wp_get_environment_type(),'schema'=>get_option('mmaf_db_version'),'source_database'=>DB_NAME,'prefix'=>$wpdb->prefix,'verified'=>true,'sha256'=>$result['sha256'],'compressed_bytes'=>$result['bytes'],'sql_bytes'=>$bytes,'tables'=>count($tables),'table_rows'=>$tables,'holds'=>['import:'.$job],'restore_verified_at'=>null];
            // An empty manifest is still a map in the shared backup contract.
            $manifest['media']=(object)\MMAF\DataEngine\Services\Operations\MediaBackup::capture();
            $manifest['sql_format']='mysqldump-php-v1';
            $manifest['commit']=get_option('mmaf_release',[])['commit']??null;
            $integrity=DatabaseState::integrity();$manifest['baseline']=['active'=>(new \MMAF\DataEngine\Repositories\RankingRunRepository())->get_active_ranking_run_id(),'ranking_hash'=>$integrity['ranking_hash']];
            Storage::write($target.'/manifest.json',$manifest);Storage::write($catalog.'/'.hash('sha256',realpath($registered)).'.json',$manifest);
        }
        return $result;
    }
}
