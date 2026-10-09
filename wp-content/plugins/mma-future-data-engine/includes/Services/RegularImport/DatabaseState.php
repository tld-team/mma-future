<?php
namespace MMAF\DataEngine\Services\RegularImport;
use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
final class DatabaseState {
    public static function revision(): string {
        global $wpdb; $tables=CanonicalSchema::table_names(); $hash=hash_init('sha256');
        foreach (['fighters','fighter_sources','events','event_sources','bouts','bout_sources','bout_participants','field_provenance','fighter_stats_overrides','ranking_current'] as $kind) {
            $table=$tables[$kind];hash_update($hash,$kind);$last=0;
            do {
                $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id>%d ORDER BY id LIMIT 250",$last),ARRAY_A);
                if ($wpdb->last_error) { throw new \RuntimeException('Could not check current data: '.$wpdb->last_error); }
                foreach ($rows ?: [] as $row) { hash_update($hash,wp_json_encode($row)); $last=(int)$row['id']; }
            } while(count($rows ?: [])===250);
        }
        $table=\MMAF\DataEngine\Services\DataPipeline\Schema::tables()['profiles'];$last=0;
        do {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT id,source_type,source_fighter_id,source_url,content_hash,source_fetched_at,last_attempt_at,last_attempt_status FROM $table WHERE id>%d ORDER BY id LIMIT 250",$last),ARRAY_A);
            if($wpdb->last_error){throw new \RuntimeException('Could not check stored source revision.');}
            foreach($rows ?: [] as $row){hash_update($hash,wp_json_encode($row));$last=(int)$row['id'];}
        }while(count($rows ?: [])===250);
        $table=$tables['fighter_career_coverage'];$last=0;
        do {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE fighter_id>%d ORDER BY fighter_id LIMIT 250",$last),ARRAY_A);
            if($wpdb->last_error){throw new \RuntimeException('Could not check career coverage revision.');}
            foreach($rows ?: [] as $row){hash_update($hash,wp_json_encode($row));$last=(int)$row['fighter_id'];}
        }while(count($rows ?: [])===250);
        return hash_final($hash);
    }
    public static function integrity(): array {
        global $wpdb;$t=CanonicalSchema::table_names();$out=[];
        foreach (['fighter_sources'=>'source_fighter_id','event_sources'=>'source_event_id','bout_sources'=>'source_bout_id'] as $key=>$col) {
            $out['duplicate_'.$key]=(int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT source_type,$col FROM {$t[$key]} WHERE $col IS NOT NULL AND $col<>'' GROUP BY source_type,$col HAVING COUNT(*)>1) d");
        }
        $out['malformed_bouts']=(int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT b.id FROM {$t['bouts']} b LEFT JOIN {$t['bout_participants']} p ON p.bout_id=b.id GROUP BY b.id HAVING COUNT(p.id)<>2 OR COUNT(DISTINCT p.fighter_id)<>2) d");
        $out['orphan_participants']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bout_participants']} p LEFT JOIN {$t['fighters']} f ON f.id=p.fighter_id LEFT JOIN {$t['bouts']} b ON b.id=p.bout_id WHERE f.id IS NULL OR b.id IS NULL");
        $out['bouts_without_event']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['bouts']} b LEFT JOIN {$t['events']} e ON e.id=b.event_id WHERE e.id IS NULL");
        $out['fighters_without_stats']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['fighters']} f LEFT JOIN {$t['fighter_stats_current']} s ON s.fighter_id=f.id WHERE f.deleted_soft=0 AND s.fighter_id IS NULL");
        foreach(['fighters','events','bouts','bout_participants','fighter_stats_current'] as $key){$out[$key]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t[$key]}");}
        $out['ranking_hash']=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$t['ranking_current']} ORDER BY id",ARRAY_A)));
        if ($wpdb->last_error) { throw new \RuntimeException('Integrity check failed: '.$wpdb->last_error); }
        return $out;
    }
    public static function protectedState(?int $maxId=null): array {
        global $wpdb;$t=CanonicalSchema::table_names();
        $maxId=$maxId??(int)$wpdb->get_var("SELECT MAX(id) FROM {$t['fighters']}");
        $rows=$wpdb->get_results($wpdb->prepare("SELECT f.id,f.is_public,f.is_rankable,f.status,f.rankability_status,f.deleted_soft,f.in_ufc,p.post_status FROM {$t['fighters']} f LEFT JOIN {$wpdb->posts} p ON p.ID=f.wp_post_id WHERE f.id<=%d ORDER BY f.id",$maxId),ARRAY_A);
        if($wpdb->last_error){throw new \RuntimeException('Cannot verify publication flags.');}
        $manual=[];
        $fields=$wpdb->get_results("SELECT entity_type,entity_id,field_name FROM {$t['field_provenance']} WHERE locked=1 OR source_type IN ('manual_verified','manual_admin','admin_override','admin_verified','admin_protected') ORDER BY entity_type,entity_id,field_name",ARRAY_A);
        foreach($fields as $field){
            $kind=['fighter'=>'fighters','event'=>'events','bout'=>'bouts'][$field['entity_type']]??null;
            if(!$kind||!preg_match('/^[a-z_]+$/D',$field['field_name'])){continue;}
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t[$kind]} WHERE id=%d",$field['entity_id']),ARRAY_A);
            if($wpdb->last_error){throw new \RuntimeException('Cannot verify protected fields.');}
            $manual[]=[$field,$row[$field['field_name']]??null];
        }
        return ['max_fighter_id'=>$maxId,'publication_hash'=>hash('sha256',wp_json_encode($rows)),'manual_hash'=>hash('sha256',wp_json_encode($manual))];
    }
    public static function engines(): void {
        global $wpdb;
        $engines=$wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_TYPE='BASE TABLE'",DB_NAME),ARRAY_A);
        if (!$engines || $wpdb->last_error) { throw new ImportException('Cannot inspect database tables for a consistent backup.','backup'); }
        foreach($engines as $table){if(strtoupper($table['ENGINE'])!=='INNODB'){throw new ImportException('Automatic import requires transactional tables. Ask the site administrator to review table '.$table['TABLE_NAME'].'.','backup');}}
    }
}
