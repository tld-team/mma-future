<?php
use MMAF\DataEngine\Migrations\{Schema,MigrationRunner};
if(version_compare((string)get_option('mmaf_db_version'),MMAF_DB_VERSION,'<')){throw new RuntimeException('Schema has not reached the checked release version.');}
global $wpdb;$t=Schema::table_names();$run=(new MMAF\DataEngine\Repositories\RankingRunRepository())->get_active_ranking_run_id();
$missing=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['ranking_current']} c LEFT JOIN {$t['fighters']} f ON f.id=c.fighter_id WHERE f.id IS NULL");
if($missing){throw new RuntimeException('Ranking references are invalid.');}
$manifest=json_decode(file_get_contents(getenv('MMAF_RELEASE_MANIFEST')),true,512,JSON_THROW_ON_ERROR);
foreach($manifest['asset_manifest'] as $key=>$value){if(is_array($value)){continue;}}
$before=json_decode(file_get_contents(getenv('MMAF_RELEASE_BASELINE')),true,512,JSON_THROW_ON_ERROR);
$hash=mmaf_release_ranking_hash($t['ranking_current']);
if((int)$before['active']!==(int)$run || $before['ranking_hash']!==$hash){throw new RuntimeException('Release changed the active ranking.');}
update_option('mmaf_release',['commit'=>$manifest['commit'],'sequence'=>$manifest['sequence'],'schema'=>get_option('mmaf_db_version'),'checked_at'=>gmdate('Y-m-d H:i:s')],false);
do_action('mmaf_public_data_changed','release');
echo json_encode(['ok'=>true,'active'=>$run,'schema'=>get_option('mmaf_db_version')]);

function mmaf_release_ranking_hash(string $table): string {
 global $wpdb;$hash=hash_init('sha256');hash_update($hash,'[');$cursor=0;$first=true;
 do{$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id>%d ORDER BY id LIMIT 250",$cursor),ARRAY_A);foreach($rows as $row){$cursor=(int)$row['id'];hash_update($hash,($first?'':',').wp_json_encode($row));$first=false;}}while(count($rows)===250);
 hash_update($hash,']');return hash_final($hash);
}
