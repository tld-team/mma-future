<?php
/** Standalone PDO verification: never bootstraps the restored WordPress site. */
if(PHP_SAPI!=='cli'){exit(1);}
$config=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$manifest=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$target=$config['verification'];$database=$target['database'];
if($database===($manifest['source_database']??null) || !preg_match('/^[a-zA-Z0-9_]+$/D',$database)){throw new RuntimeException('Isolated verification database required.');}
$dsn='mysql:dbname='.$database.';charset=utf8mb4;'.(!empty($target['socket'])?'unix_socket='.$target['socket']:'host='.($target['host']??'localhost').';port='.(int)($target['port']??3306));
$pdo=new PDO($dsn,$target['user'],$target['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$names=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if(count($names)!==(int)$manifest['tables']){throw new RuntimeException('Restored table count differs.');}
foreach($manifest['table_rows'] as $name=>$expected){
    if(!in_array($name,$names,true)){throw new RuntimeException('A restored table is missing.');}
    $count=(int)$pdo->query('SELECT COUNT(*) FROM `'.str_replace('`','``',$name).'`')->fetchColumn();
    if($count!==(int)$expected){throw new RuntimeException('Restored row count differs.');}
}
$prefix=$manifest['prefix'];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$prefix)){throw new RuntimeException('Invalid source prefix.');}
$active=(int)$pdo->query("SELECT state_value FROM `{$prefix}mmaf_system_state` WHERE state_key='active_ranking_run_id'")->fetchColumn();
$hash=hash_init('sha256');hash_update($hash,'[');$cursor=0;$first=true;
do{
    $rows=$pdo->query("SELECT * FROM `{$prefix}mmaf_ranking_current` WHERE id>$cursor ORDER BY id LIMIT 250")->fetchAll();
    foreach($rows as $row){$cursor=(int)$row['id'];hash_update($hash,($first?'':',').json_encode($row,JSON_THROW_ON_ERROR));$first=false;}
}while(count($rows)===250);
hash_update($hash,']');$checksum=hash_final($hash);
if($active!==(int)$manifest['baseline']['active'] || $checksum!==$manifest['baseline']['ranking_hash']){throw new RuntimeException('Restored active ranking differs.');}
$missing=[];$cursor=0;$media=$manifest['media'];
do{
    $rows=$pdo->query("SELECT meta_id,meta_value FROM `{$prefix}postmeta` WHERE meta_key='_wp_attached_file' AND meta_id>$cursor ORDER BY meta_id LIMIT 250")->fetchAll();
    foreach($rows as $row){$cursor=(int)$row['meta_id'];if(!isset($media[$row['meta_value']])){$missing[]=$row['meta_id'];}}
}while(count($rows)===250);
if($missing){throw new RuntimeException('Attachment references missing from the verified media manifest: '.count($missing));}
echo json_encode(['ok'=>true,'tables'=>count($names),'active'=>$active,'ranking_hash'=>$checksum,'attachments_verified'=>true]);
