<?php
namespace MMAF\DataEngine\Services\Scoring;

use MMAF\DataEngine\Services\RegularImport\{Repository,Transaction,WriteGuard,Mutex};
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;

/** Career aggregates are observations, never a sum of aggregate + recorded bouts. */
final class CareerRecords {
    public static function counts(array $record): ?array {
        $out=[];
        foreach(['wins','losses','draws','nc'] as $key){
            $value=$record[$key]??($key==='nc'?($record['no_contests']??null):null);
            if(!is_int($value) || $value<0 || $value>1000){return null;}
            $out[$key]=$value;
        }
        $out['pro_fights_count']=array_sum($out);
        return $out;
    }
    public static function scope_verified(array $profile): bool {
        $e=$profile['career_record_evidence']??[];
        if(($e['verified']??false)!==true || ($e['scope']??'')!=='professional_mma'){return false;}
        if(($e['record_raw']??null)!==($profile['record_summary']['pro_record_raw']??null) || empty($e['record_raw'])){return false;}
        if(!preg_match('/^\s*(\d{1,4})\s*-\s*(\d{1,4})\s*-\s*(\d{1,4})(?:\s*(?:,|\()\s*(\d{1,4})\s*NC\)?)?\s*$/iD',$e['record_raw'],$match)){return false;}
        $parsed=self::counts(['wins'=>(int)$match[1],'losses'=>(int)$match[2],'draws'=>(int)$match[3],'nc'=>(int)($match[4]??0)]);
        if(!$parsed || $parsed!==self::counts($profile['record_summary']??[])){return false;}
        if(($e['evidence']??'')==='explicit_mma_record_label'){
            return in_array($e['label']??'', ['Pro MMA Record','Professional MMA Record'],true);
        }
        return ($e['evidence']??'')==='profile_record_mma_context' && ($e['label']??'')==='Pro Record' && ($e['context']??'')==='professional_mma' && ($e['record_context_verified']??false)===true;
    }
    public function observe(int $id,array $profile,string $content_hash,int $actor=0): ?array {
        if(version_compare((string)get_option('mmaf_db_version','0'),'1.11.0','<')){return null;}
        if(($profile['status']??'')!=='success'){return null;}
        $date=ProfileStore::date($profile['scraped_at']??$profile['profile_scraped_at']??null);
        $record=self::counts($profile['record_summary']??[]);
        $accepted=$date && $record && self::scope_verified($profile);
        return $this->append($id,'tapology',(string)($profile['source_fighter_id']??''),(string)($profile['source_url']??''),$date,$record,$accepted?'accepted':'review',false,['proof'=>$profile['career_record_evidence']??null,'profile_hash'=>$content_hash],$accepted?null:'Professional MMA career aggregate or observation date needs source evidence.',$actor);
    }
    /** Explicit career correction is separate from the legacy display override. */
    public function protect(int $id,array $record,string $observed_at,string $source_url,string $reason,int $actor): array {
        WriteGuard::assertAllowed();
        $record=self::counts($record);$date=ProfileStore::date($observed_at);
        if(!$record || !$date || !trim($reason) || !$actor || !filter_var($source_url,FILTER_VALIDATE_URL)){
            throw new \InvalidArgumentException('A confirmed professional MMA record, real verification date, evidence URL, reason and editor are required.');
        }
        return Mutex::run(fn()=>Transaction::run(fn()=>$this->append($id,'manual','career_correction',$source_url,$date,$record,'accepted',true,['scope'=>'professional_mma','confirmed'=>true],$reason,$actor)));
    }
    private function append(int $id,string $source,string $source_id,string $url,?string $date,?array $record,string $status,bool $protected,array $evidence,?string $reason,int $actor): array {
        global $wpdb;$table=Schema::tables()['career'];
        $hash=hash('sha256',wp_json_encode([$source,$source_id,$url,$date,$record,$status,$protected,$evidence,$reason]));
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE fighter_id=%d AND observation_hash=%s",$id,$hash),ARRAY_A);
        if($existing){return $existing;}
        $previous=$this->for_fighters([$id])[$id]??null;
        $data=['fighter_id'=>$id,'observation_hash'=>$hash,'source_type'=>$source,'source_fighter_id'=>$source_id,'source_url'=>$url,'observed_at'=>$date,'status'=>$status,'protected'=>$protected?1:0,'record_json'=>wp_json_encode($record),'previous_record_json'=>wp_json_encode($previous['record']??null),'evidence_json'=>wp_json_encode($evidence),'reason'=>$reason,'actor_user_id'=>$actor,'created_at'=>gmdate('Y-m-d H:i:s')];
        Repository::check($wpdb->insert($table,$data));$data['id']=(int)$wpdb->insert_id;
        (new AuditLogService())->write('career_record_observed','fighter',$id,$previous,['observation_id'=>$data['id'],'record'=>$record,'status'=>$status,'protected'=>$protected,'observed_at'=>$date],$reason??'Source professional MMA career observation',$actor);
        Transaction::after_commit(static function(){do_action('mmaf_public_data_changed','career_record');});
        return $data;
    }
    public function for_fighters(array $ids): array {
        if(version_compare((string)get_option('mmaf_db_version','0'),'1.11.0','<')){return [];}
        global $wpdb;$table=Schema::tables()['career'];$in=implode(',',array_map('intval',$ids));
        if(!$in){return [];}
        // Keep the most recent accepted value even when a newer source needs review.
        $rows=$wpdb->get_results("SELECT * FROM (SELECT c.*,ROW_NUMBER() OVER (PARTITION BY fighter_id,source_type,source_fighter_id,protected,status ORDER BY observed_at DESC,id DESC) n FROM $table c WHERE fighter_id IN ($in)) observations WHERE n=1 ORDER BY fighter_id,protected DESC,observed_at DESC,id DESC",ARRAY_A);
        if($wpdb->last_error){throw new \RuntimeException('Cannot read career observations: '.$wpdb->last_error);}
        $canonical=\MMAF\DataEngine\Migrations\Schema::table_names();$bindings=[];
        foreach($wpdb->get_results("SELECT fighter_id,source_type,source_fighter_id FROM {$canonical['fighter_sources']} WHERE fighter_id IN ($in)",ARRAY_A) as $binding){$bindings[(int)$binding['fighter_id']][$binding['source_type']][$binding['source_fighter_id']]=true;}
        if($wpdb->last_error){throw new \RuntimeException('Cannot verify career source identity.');}
        foreach($rows as &$row){if(!$row['protected'] && empty($bindings[(int)$row['fighter_id']][$row['source_type']][$row['source_fighter_id']])){$row['status']='review';$row['reason']='Career observation is no longer linked to the current fighter source identity.';}}unset($row);
        $groups=[];foreach($rows as $row){$groups[(int)$row['fighter_id']][]=$row;}
        $out=[];
        foreach($groups as $id=>$observations){
            $protected=array_values(array_filter($observations,static fn($r)=>$r['protected'] && $r['status']==='accepted'));
            $accepted=array_values(array_filter($observations,static fn($r)=>$r['status']==='accepted'));
            $selected=$protected[0]??$accepted[0]??$observations[0];
            $record=$selected['status']==='accepted'?json_decode($selected['record_json'],true):null;
            $review=$selected['status']!=='accepted';$reason=$selected['reason'];$source_records=[];
            if(!$protected){
                foreach($observations as $row){
                    if($row['status']==='review' && ($row['observed_at']??'')>=($selected['observed_at']??'')){$review=true;$reason=$row['reason'];}
                    if($row['status']==='accepted'){$source_records[]=json_decode($row['record_json'],true);}
                }
                if(count(array_unique(array_map('wp_json_encode',$source_records)))>1){$review=true;$reason='Accepted source aggregates disagree; review career record.';}
            }
            $evidence=json_decode((string)($selected['evidence_json']??''),true);
            $out[$id]=['status'=>$review?'review':'accepted','record'=>$record,'observation_id'=>(int)$selected['id'],'source_type'=>$selected['source_type'],'source_url'=>$selected['source_url'],'observed_at'=>$selected['observed_at'],'verified_at'=>$selected['observed_at'],'profile_hash'=>is_array($evidence)?($evidence['profile_hash']??null):null,'protected'=>(bool)$selected['protected'],'reason'=>$reason,'review_required'=>$review];
        }
        $known=(new \MMAF\DataEngine\Repositories\FighterStatsRepository())->records_for_fighters(array_keys($out));
        foreach($out as $id=>&$record){
            if($record['protected'] || !$record['record'] || !isset($known[$id])){continue;}
            foreach(['wins','losses','draws','nc'] as $key){
                if((int)$known[$id][$key]>$record['record'][$key]){$record['status']='review';$record['review_required']=true;$record['reason']='Source career aggregate is smaller than accepted recorded results; review source freshness or older classifications.';break;}
            }
        }unset($record);
        return $out;
    }
}
