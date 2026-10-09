<?php
namespace MMAF\DataEngine\Services\RegularImport;
use MMAFVendor\Ifsnop\Mysqldump\Mysqldump;
final class Backup {
    public static function create(int $job,callable $heartbeat): array {
        global $wpdb;
        DatabaseState::engines();
        if(!extension_loaded('pdo_mysql')||!extension_loaded('zlib')){throw new ImportException('Automatic backup needs PDO MySQL and gzip support. Open Setup.','backup');}
        $estimate=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s',DB_NAME));
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
        $stream=gzopen($temp,'rb');$bytes=0;
        if(!$stream){throw new ImportException('Cannot verify compressed backup.','backup');}
        try{while(!gzeof($stream)){$chunk=gzread($stream,1048576);if($chunk===false){throw new ImportException('Backup compression verification failed.','backup');}$bytes+=strlen($chunk);$heartbeat();}}
        finally{gzclose($stream);}
        if(!$bytes){throw new ImportException('Database backup is empty.','backup');}
        chmod($temp,0600);if(!rename($temp,$file)){throw new ImportException('Cannot finalize database backup.','storage');}
        $result=['file'=>'before-import.sql.gz','bytes'=>filesize($file),'uncompressed_bytes'=>$bytes,'sha256'=>hash_file('sha256',$file),'tables'=>$tables,'created_at'=>gmdate('c'),'database'=>DB_NAME,'site_url'=>home_url('/')];
        Storage::write($dir.'/backup-manifest.json',$result);return $result;
    }
}
