<?php
namespace MMAF\DataEngine\Services\Operations;
use MMAF\DataEngine\Services\RegularImport\Repository;
use MMAF\DataEngine\Migrations\Schema as Canonical;

/** Indexed relationships come only from explicit IDs or exact canonical source bindings. */
final class ReviewLinks {
    public static function link(int $review,string $type,int $id,string $evidence): void {
        if($review<1 || $id<1 || !in_array($type,['fighter','import','operation'],true)){return;}
        global $wpdb;$table=Schema::tables()['review_links'];
        Repository::check($wpdb->query($wpdb->prepare("INSERT INTO $table (review_id,entity_type,entity_id,evidence) VALUES (%d,%s,%d,%s) ON DUPLICATE KEY UPDATE evidence=VALUES(evidence)",$review,$type,$id,$evidence)));
    }
    public static function record(int $review,array $context,int $job=0): void {
        if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','<')){return;}
        self::link($review,'import',$job,'explicit_import_job');
        foreach(['fighter_id','canonical_fighter_id','target_fighter_id'] as $key){if(isset($context[$key]) && is_numeric($context[$key])){self::link($review,'fighter',(int)$context[$key],'explicit_'.$key);}}
        if(!empty($context['operation_id'])){self::link($review,'operation',(int)$context['operation_id'],'explicit_operation');}
        global $wpdb;$t=Canonical::table_names();
        $row=$wpdb->get_row($wpdb->prepare("SELECT item_type,canonical_id,related_canonical_id,source_type,source_id FROM {$t['review_items']} WHERE id=%d",$review),ARRAY_A);
        if(!$row){return;}
        if(in_array($row['item_type'],['fighter','fighter_duplicate','fighter_source_link','source_link'],true)){
            self::link($review,'fighter',(int)$row['canonical_id'],'typed_canonical_id');
            self::link($review,'fighter',(int)$row['related_canonical_id'],'typed_related_id');
        }
        if(!empty($row['source_id'])){
            foreach($wpdb->get_col($wpdb->prepare("SELECT fighter_id FROM {$t['fighter_sources']} WHERE source_type=%s AND source_fighter_id=%s",$row['source_type'],$row['source_id'])) as $id){self::link($review,'fighter',(int)$id,'exact_source_binding');}
        }
    }
    public static function backfill(int $cursor=0,int $limit=250): array {
        global $wpdb;$t=Canonical::table_names();$limit=min(250,max(1,$limit));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,item_type,source_type,source_id,canonical_id,related_canonical_id,notes FROM {$t['review_items']} WHERE id>%d ORDER BY id LIMIT %d",$cursor,$limit),ARRAY_A);
        foreach($rows as $row){
            $cursor=(int)$row['id'];$notes=json_decode($row['notes']??'',true)?:[];
            self::record($cursor,(array)($notes['context']??[]),(int)($notes['first_import_job']??0));
            if(in_array($row['item_type'],['fighter','fighter_duplicate','fighter_source_link','source_link'],true)){
                self::link($cursor,'fighter',(int)$row['canonical_id'],'typed_canonical_id');self::link($cursor,'fighter',(int)$row['related_canonical_id'],'typed_related_id');
            }
        }
        if($rows){
            $ids=implode(',',array_map('intval',array_column($rows,'id')));
            $links=$wpdb->get_results("SELECT r.id,fs.fighter_id FROM {$t['review_items']} r JOIN {$t['fighter_sources']} fs ON fs.source_type=r.source_type AND fs.source_fighter_id=r.source_id WHERE r.id IN ($ids) AND r.source_id IS NOT NULL AND r.source_id<>''",ARRAY_A);
            foreach($links as $link){self::link((int)$link['id'],'fighter',(int)$link['fighter_id'],'exact_source_binding');}
        }
        return ['cursor'=>$cursor,'done'=>count($rows)<$limit];
    }
    public static function for_fighter(int $id,int $page=1): array {
        if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','<')){return [];}
        global $wpdb;$t=Canonical::table_names();$links=Schema::tables()['review_links'];
        return $wpdb->get_results($wpdb->prepare("SELECT r.id,r.item_type,r.action_taken,r.status,r.notes,r.updated_at FROM $links l JOIN {$t['review_items']} r ON r.id=l.review_id WHERE l.entity_type='fighter' AND l.entity_id=%d ORDER BY r.id DESC LIMIT 25 OFFSET %d",$id,(max(1,$page)-1)*25),ARRAY_A);
    }
}
