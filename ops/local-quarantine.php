<?php
/** Explicit staging-to-local restore step. No copied server jobs are resumed automatically. */
if(wp_get_environment_type()!=='local'){throw new RuntimeException('Local environment required.');}
global $wpdb;$t=\MMAF\DataEngine\Migrations\Schema::table_names();$p=\MMAF\DataEngine\Services\DataPipeline\Schema::tables();$j=\MMAF\DataEngine\Services\RegularImport\Schema::tables()['jobs'];
update_option('mmaf_local_copied_cron',get_option('cron',[]),false);update_option('cron',[]);update_option('mmaf_local_restored_at',gmdate('Y-m-d H:i:s'),false);
update_option('mmaf_retention_enabled',false,false);
@unlink(MMAF_OPERATIONS_DIR.'/retention-accepted.json');
$wpdb->query("UPDATE $j SET status='paused' WHERE status IN ('queued','running','awaiting_approval')");
$wpdb->query("UPDATE {$p['queue']} SET status='paused' WHERE status='pending'");
$wpdb->query("UPDATE {$t['system_state']} SET state_value=JSON_SET(COALESCE(state_value,'{}'),'$.pending',false,'$.local_copy_paused',true) WHERE state_key='data_preparation'");
if(version_compare((string)get_option('mmaf_db_version'),'1.8.0','>=')){
 $o=\MMAF\DataEngine\Services\Operations\Schema::tables();$wpdb->query("UPDATE {$o['jobs']} SET status='paused',checkpoint_json=JSON_SET(COALESCE(checkpoint_json,'{}'),'$.local_copy_paused',true) WHERE status IN ('running','previewing','ready','paused','failed')");
 if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','>=')){$wpdb->query("UPDATE {$o['archives']} SET status='paused' WHERE status IN ('running','verified')");}
}
if($wpdb->last_error){throw new RuntimeException('Local job quarantine failed.');}
echo "Local jobs paused; external mail and automatic cron disabled by the local environment gate.\n";
