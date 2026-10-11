<?php
global $wpdb;$t=\MMAF\DataEngine\Migrations\Schema::table_names();$out=['active'=>(new \MMAF\DataEngine\Repositories\RankingRunRepository())->get_active_ranking_run_id(),'schema'=>get_option('mmaf_db_version'),'counts'=>[]];
foreach(['fighters','events','bouts','bout_participants'] as $key){$out['counts'][$key]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t[$key]}");}
$out['ranking_hash']=mmaf_release_ranking_hash($t['ranking_current']);
echo json_encode($out);

function mmaf_release_ranking_hash(string $table): string {
 global $wpdb;$hash=hash_init('sha256');hash_update($hash,'[');$cursor=0;$first=true;
 do{$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id>%d ORDER BY id LIMIT 250",$cursor),ARRAY_A);foreach($rows as $row){$cursor=(int)$row['id'];hash_update($hash,($first?'':',').wp_json_encode($row));$first=false;}}while(count($rows)===250);
 hash_update($hash,']');return hash_final($hash);
}
