<?php
/** Private runner preflight. No credentials or source payloads are emitted. */
global $wpdb;
$tables=$wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME AS table_name,ENGINE AS engine,TABLE_ROWS AS table_rows,DATA_LENGTH AS data_length,INDEX_LENGTH AS index_length FROM information_schema.tables WHERE table_schema=%s AND table_type=\'BASE TABLE\' ORDER BY TABLE_NAME',DB_NAME),ARRAY_A);
if(!$tables || $wpdb->last_error){throw new RuntimeException('Cannot inspect backup tables.');}
$bytes=0;$names=[];$counts=[];
foreach($tables as $table){
    if(strtoupper($table['engine'])!=='INNODB'){throw new RuntimeException('A consistent backup requires InnoDB tables.');}
    $bytes+=(int)$table['data_length']+(int)$table['index_length'];$names[]=$table['table_name'];
    $name=str_replace('`','``',$table['table_name']);$counts[$table['table_name']]=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$name`");
    if($wpdb->last_error){throw new RuntimeException('Cannot count backup rows.');}
}
echo json_encode(['database'=>DB_NAME,'prefix'=>$wpdb->prefix,'bytes'=>$bytes,'tables'=>$names,'table_rows'=>$counts,'environment'=>wp_get_environment_type(),'schema'=>get_option('mmaf_db_version'),'uploads'=>wp_upload_dir()['basedir']]);
