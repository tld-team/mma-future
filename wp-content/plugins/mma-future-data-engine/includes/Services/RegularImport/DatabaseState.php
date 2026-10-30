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
        $table=\MMAF\DataEngine\Services\Scoring\Schema::tables()['career'];
        foreach(self::rows("SELECT * FROM $table ORDER BY id") as $row){hash_update($hash,wp_json_encode($row));}
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
        $out['ranking_hash']=self::arrayHash(self::rows("SELECT * FROM {$t['ranking_current']} ORDER BY id"));
        if ($wpdb->last_error) { throw new \RuntimeException('Integrity check failed: '.$wpdb->last_error); }
        return $out;
    }
    /** Freshness and protected career decisions are calculation inputs in v1.6. */
    public static function calculationRevision(): string {
        $t=CanonicalSchema::table_names();$hash=hash_init('sha256');
        $queries=[
            ['fighters',"SELECT id,date_of_birth,gender,weight_class,status,is_public,is_rankable,rankability_status,in_ufc,tracking_mode,tracking_revision,tracking_last_checked_bout_id,tracking_return_candidate_at,deleted_soft FROM {$t['fighters']} ORDER BY id",'id'],
            ['events',"SELECT id,event_date,status,deleted_soft FROM {$t['events']} ORDER BY id",'id'],
            ['bouts',"SELECT * FROM {$t['bouts']} ORDER BY id",'id'],
            ['participants',"SELECT * FROM {$t['bout_participants']} ORDER BY id",'id'],
            ['overrides',"SELECT * FROM {$t['fighter_stats_overrides']} ORDER BY id",'id'],
            ['coverage',"SELECT fighter_id,status,accepted_bouts_count,expected_record_json FROM {$t['fighter_career_coverage']} ORDER BY fighter_id",'fighter_id'],
        ];
        $career=\MMAF\DataEngine\Services\Scoring\Schema::tables()['career'];
        $profiles=\MMAF\DataEngine\Services\DataPipeline\Schema::tables()['profiles'];
        $queries[]=['career_records',"SELECT * FROM $career ORDER BY id",'id'];
        $queries[]=['source_verifications',"SELECT id,content_hash,source_fetched_at FROM $profiles ORDER BY id",'id'];
        foreach($queries as [$name,$sql,$key]){hash_update($hash,$name);foreach(self::rows($sql,$key) as $row){unset($row['created_at'],$row['updated_at']);hash_update($hash,wp_json_encode($row));}}
        return hash_final($hash);
    }
    public static function calculationPolicy(): string {
        return hash('sha256',wp_json_encode([MMAF_DB_VERSION,\MMAF\DataEngine\Services\Formula\FormulaRegistry::current_version(),\MMAF\DataEngine\Services\Formula\FormulaRegistry::current_config()]));
    }
    public static function protectedState(?int $maxId=null): array {
        global $wpdb;$t=CanonicalSchema::table_names();
        $maxId=$maxId??(int)$wpdb->get_var("SELECT MAX(id) FROM {$t['fighters']}");
        $flags=self::rows($wpdb->prepare("SELECT f.id,f.is_public,f.is_rankable,f.status,f.rankability_status,f.deleted_soft,f.in_ufc,f.tracking_mode,f.tracking_reason,f.tracking_source,f.tracking_evidence_url,f.tracking_changed_at,f.tracking_revision,f.tracking_last_checked_at,f.tracking_last_checked_bout_id,f.tracking_return_candidate_at,p.post_status FROM {$t['fighters']} f LEFT JOIN {$wpdb->posts} p ON p.ID=f.wp_post_id WHERE f.id<=%d ORDER BY f.id",$maxId),'f.id');
        $manual=(static function()use($wpdb,$t){
            $cursor=null;
            do{
                $after=$cursor?$wpdb->prepare(" AND (entity_type>%s OR (entity_type=%s AND entity_id>%d) OR (entity_type=%s AND entity_id=%d AND field_name>%s) OR (entity_type=%s AND entity_id=%d AND field_name=%s AND id>%d))",$cursor['entity_type'],$cursor['entity_type'],$cursor['entity_id'],$cursor['entity_type'],$cursor['entity_id'],$cursor['field_name'],$cursor['entity_type'],$cursor['entity_id'],$cursor['field_name'],$cursor['id']):'';
                $fields=$wpdb->get_results("SELECT id,entity_type,entity_id,field_name FROM {$t['field_provenance']} FORCE INDEX (manual_protection) WHERE (locked=1 OR source_type IN ('manual_verified','manual_admin','admin_override','admin_verified','admin_protected')) $after ORDER BY entity_type,entity_id,field_name,id LIMIT 250",ARRAY_A);
                if($wpdb->last_error){throw new \RuntimeException('Cannot verify protected fields.');}
                $entities=[];
                foreach(['fighter'=>'fighters','event'=>'events','bout'=>'bouts'] as $type=>$kind){
                    $ids=array_unique(array_map('intval',array_column(array_filter($fields,static fn($f)=>$f['entity_type']===$type),'entity_id')));
                    if(!$ids){continue;}
                    $rows=$wpdb->get_results("SELECT * FROM {$t[$kind]} WHERE id IN (".implode(',',$ids).")",ARRAY_A);
                    if($wpdb->last_error){throw new \RuntimeException('Cannot read protected entities.');}
                    foreach($rows as $row){$entities[$type][(int)$row['id']]=$row;}
                }
                foreach($fields as $field){
                    if(!isset(['fighter'=>1,'event'=>1,'bout'=>1][$field['entity_type']])||!preg_match('/^[a-z_]+$/D',$field['field_name'])){continue;}
                    unset($field['id']);yield [$field,$entities[$field['entity_type']][(int)$field['entity_id']][$field['field_name']]??null];
                }
                if($fields){$cursor=end($fields);}
            }while(count($fields)===250);
        })();
        $career=\MMAF\DataEngine\Services\Scoring\Schema::tables()['career'];
        $protectedCareer=self::rows("SELECT * FROM $career WHERE protected=1 ORDER BY id");
        return ['max_fighter_id'=>$maxId,'publication_hash'=>self::arrayHash($flags),'manual_hash'=>self::arrayHash($manual),'protected_career_hash'=>self::arrayHash($protectedCareer)];
    }
    /** Hash exactly the former JSON array, without retaining the complete result. */
    public static function arrayHash(iterable $rows): string {
        $hash=hash_init('sha256');hash_update($hash,'[');$first=true;
        foreach($rows as $row){hash_update($hash,($first?'':',').wp_json_encode($row));$first=false;}
        hash_update($hash,']');return hash_final($hash);
    }
    private static function rows(string $sql,string $key='id'): \Generator {
        global $wpdb;$cursor=0;[$base,$order]=explode(' ORDER BY ',$sql,2);$parts=explode('.',$key);$column=end($parts);
        do{
            $rows=$wpdb->get_results($base.(str_contains($base,' WHERE ')?' AND ':' WHERE ').$key.'>'.(int)$cursor.' ORDER BY '.$order.' LIMIT 250',ARRAY_A);
            if($wpdb->last_error){throw new \RuntimeException('Cannot read protected database state.');}
            foreach($rows as $row){yield $row;$cursor=(int)$row[$column];}
        }while(count($rows)===250);
    }
    public static function engines(): void {
        global $wpdb;
        $engines=$wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_TYPE='BASE TABLE'",DB_NAME),ARRAY_A);
        if (!$engines || $wpdb->last_error) { throw new ImportException('Cannot inspect database tables for a consistent backup.','backup'); }
        foreach($engines as $table){if(strtoupper($table['ENGINE'])!=='INNODB'){throw new ImportException('Automatic import requires transactional tables. Ask the site administrator to review table '.$table['TABLE_NAME'].'.','backup');}}
    }
}
