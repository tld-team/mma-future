<?php
namespace MMAF\DataEngine\Services\Operations;
use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\DataPipeline\Schema as Pipeline;
use MMAF\DataEngine\Services\FighterReadinessService;
use MMAF\DataEngine\Services\DataPipeline\Processor;
use MMAF\DataEngine\Services\RegularImport\Repository;

/** Admin GETs only read projections. Decisions re-evaluate live input under the write lock. */
final class Readiness {
    public const RULES='preparation-v3';
    public const FINGERPRINT_VERSION=2;
    public static function rules(): string { return self::RULES.'|'.\MMAF\DataEngine\Services\Formula\FormulaRegistry::current_version(); }
    public static function fingerprint(int $id,bool $include_stats=true): string {
        global $wpdb; $t=Canonical::table_names(); $p=Pipeline::tables();
        $fighter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighters']} WHERE id=%d",$id),ARRAY_A);
        $sources=$wpdb->get_results($wpdb->prepare("SELECT source_type,source_fighter_id,source_url,identity_hash,is_primary FROM {$t['fighter_sources']} WHERE fighter_id=%d ORDER BY id",$id),ARRAY_A);
        $profiles=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.id,p.content_hash,p.source_fetched_at FROM {$p['profiles']} p JOIN {$t['fighter_sources']} fs ON fs.source_type=p.source_type AND (fs.source_fighter_id=p.source_fighter_id OR fs.source_url=p.source_url) WHERE fs.fighter_id=%d ORDER BY p.id",$id),ARRAY_A);
        $stats=$include_stats?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighter_stats_current']} WHERE fighter_id=%d",$id),ARRAY_A):null;
        $career=(new \MMAF\DataEngine\Services\Scoring\CareerRecords())->for_fighters([$id])[$id]??null;
        return hash('sha256',wp_json_encode([$fighter,$sources,$profiles,$stats,$career,self::bindings($id),self::rules(),self::FINGERPRINT_VERSION]));
    }
    public static function invalidate(int $id=0): void {
        global $wpdb; $table=Schema::tables()['readiness'];
        Repository::check($wpdb->query($id ? $wpdb->prepare("UPDATE $table SET dirty=1 WHERE fighter_id=%d",$id) : "UPDATE $table SET dirty=1"));
    }
    /** Content identity for repeat approval, independent of observation dates and statistics IDs. */
    public static function approval_hash(int $id): string {
        global $wpdb;$t=Canonical::table_names();$p=Pipeline::tables();
        $fighter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fighters']} WHERE id=%d",$id),ARRAY_A);
        unset($fighter['updated_at'],$fighter['created_at']);
        $sources=$wpdb->get_results($wpdb->prepare("SELECT source_type,source_fighter_id,source_url,identity_hash,is_primary FROM {$t['fighter_sources']} WHERE fighter_id=%d ORDER BY id",$id),ARRAY_A);
        $profiles=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.content_hash FROM {$p['profiles']} p JOIN {$t['fighter_sources']} fs ON fs.source_type=p.source_type AND (fs.source_fighter_id=p.source_fighter_id OR fs.source_url=p.source_url) WHERE fs.fighter_id=%d ORDER BY p.content_hash",$id),ARRAY_A);
        return hash('sha256',wp_json_encode([$fighter,$sources,$profiles,self::bindings($id),self::rules()]));
    }
    private static function bindings(int $id): array {
        global $wpdb;$table=Canonical::table_names()['fighter_sources'];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT other.id,other.fighter_id,other.source_type,other.source_fighter_id,other.source_url FROM $table mine JOIN $table other ON other.source_type=mine.source_type AND ((mine.source_fighter_id IS NOT NULL AND mine.source_fighter_id<>'' AND other.source_fighter_id=mine.source_fighter_id) OR (mine.source_url IS NOT NULL AND mine.source_url<>'' AND other.source_url=mine.source_url)) WHERE mine.fighter_id=%d ORDER BY other.id",$id),ARRAY_A);
        if($wpdb->last_error){throw new \RuntimeException('Cannot verify source identity bindings.');}return $rows;
    }
    public static function decision(array $fighter,array $metadata): array {
        if (!$fighter || !empty($fighter['deleted_soft']) || in_array($fighter['status'],['hidden','merged','retired','deleted_soft'],true)) { return ['state'=>'review','reason'=>'excluded_identity']; }
        if (!empty($metadata['identity_conflict']) || (int)($metadata['source_count']??0)>1) { return ['state'=>'review','reason'=>'identity_conflict']; }
        if ((int)($metadata['successful_profile_count']??0)!==1) { return ['state'=>'review','reason'=>'stored_profile_missing']; }
        if (($metadata['preparation_status']??'')==='failed') { return ['state'=>'failed','reason'=>'technical_failure']; }
        if (($metadata['preparation_status']??'')==='pending') { return ['state'=>'preparing','reason'=>null]; }
        return ['state'=>'ready','reason'=>null];
    }
    public static function refresh(array $ids,float $seconds=3.0): int {
        global $wpdb; $table=Schema::tables()['readiness'];
        $deadline=microtime(true)+max(0,$seconds);$processed=0;
        $fighters=(new \MMAF\DataEngine\Repositories\FighterRepository())->find_many($ids);
        $evaluated=(new FighterReadinessService())->evaluate_fighters_for_table($fighters);
        $metadata=Processor::metadata($ids); $now=gmdate('Y-m-d H:i:s');
        foreach($fighters as $fighter) {
            if($processed && microtime(true)>=$deadline){break;}
            $id=(int)$fighter['id']; $decision=self::decision($fighter,$metadata[$id]??[]);
            $projection=$evaluated[$id]??[]; $projection['preparation']=$decision;
            Repository::check($wpdb->replace($table,['fighter_id'=>$id,'preparation_state'=>$decision['state'],'reason_code'=>$decision['reason'],'input_hash'=>self::fingerprint($id),'data_revision'=>Revision::current(),'rules_version'=>self::rules(),'checked_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',strtotime('tomorrow UTC')),'dirty'=>0,'projection_json'=>wp_json_encode($projection)]));
            ++$processed;
        }
        return $processed;
    }
    public static function work(int $limit=250): int {
        global $wpdb; $t=Canonical::table_names(); $table=Schema::tables()['readiness'];
        $ids=$wpdb->get_col($wpdb->prepare("SELECT f.id FROM {$t['fighters']} f LEFT JOIN $table r ON r.fighter_id=f.id WHERE r.fighter_id IS NULL OR r.dirty=1 OR r.expires_at<%s OR r.rules_version<>%s OR r.data_revision<>%s ORDER BY f.id LIMIT %d",gmdate('Y-m-d H:i:s'),self::rules(),Revision::current(),min(250,max(1,$limit))));
        return self::refresh(array_map('intval',$ids));
    }
    public static function read(array $ids): array {
        global $wpdb; $table=Schema::tables()['readiness']; $in=implode(',',array_map('intval',$ids)); if(!$in){return [];}
        $rows=$wpdb->get_results("SELECT * FROM $table WHERE fighter_id IN ($in)",ARRAY_A); $out=[];$revision=Revision::current();
        foreach($rows as $row) { $value=json_decode($row['projection_json'],true)?:[]; $value['checked_at']=$row['checked_at']; $value['stale']=!empty($row['dirty']) || $row['expires_at']<gmdate('Y-m-d H:i:s') || $row['rules_version']!==self::rules() || $row['data_revision']!==$revision; $out[(int)$row['fighter_id']]=$value; }
        return $out;
    }
}
