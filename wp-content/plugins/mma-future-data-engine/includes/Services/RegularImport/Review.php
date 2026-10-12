<?php
namespace MMAF\DataEngine\Services\RegularImport;
use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
final class Review {
    public static function upsert(int $job,string $type,string $source,array $context,int $actor): int {
        global $wpdb;$t=CanonicalSchema::table_names()['review_items'];
        $context=self::stable($context);
        $key='regular_'.hash('sha256','tapology|'.$type.'|'.$source.'|'.wp_json_encode($context));
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE item_key=%s",$key));
        if(!$existing && $source!==''){
            $candidates=$wpdb->get_results($wpdb->prepare("SELECT id,notes FROM $t WHERE source_type='tapology' AND source_id=%s AND action_taken=%s ORDER BY id DESC LIMIT 100",$source,$type),ARRAY_A);
            foreach($candidates?:[] as $candidate){$notes=json_decode($candidate['notes']??'',true);if(isset($notes['context'])&&self::stable($notes['context'])===$context){$existing=(int)$candidate['id'];break;}}
        }
        if(!$existing){
            Repository::check($wpdb->insert($t,['item_type'=>'scraper_import','item_key'=>$key,'source_type'=>'tapology','source_id'=>$source?:null,'status'=>'open','action_taken'=>$type,'notes'=>wp_json_encode(['context'=>$context,'first_import_job'=>$job]),'created_by'=>$actor,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]));
            $existing=(int)$wpdb->insert_id;
        }
        if($job>0){(new Repository())->putItem($job,'review',$key,$source,'review',['type'=>$type,'context'=>$context],$existing);}
        \MMAF\DataEngine\Services\Operations\ReviewLinks::record($existing,$context,$job);
        return $existing;
    }
    public static function stable(array $context): array {
        foreach(['run_id','generated_at','scraped_at','import_run_id','observed_at','last_seen_at'] as $key){unset($context[$key]);}
        foreach($context as &$value){if(is_array($value)){$value=self::stable($value);}}unset($value);
        if(!array_is_list($context)){ksort($context);}return $context;
    }
    public static function source(array $context): string {
        foreach(['source_event_id','source_bout_id','source_fighter_id','event_url','bout_url','source_url'] as $key){if(!empty($context[$key])){return (string)$context[$key];}}return '';
    }
}
