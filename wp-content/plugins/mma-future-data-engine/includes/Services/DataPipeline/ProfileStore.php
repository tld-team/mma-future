<?php
namespace MMAF\DataEngine\Services\DataPipeline;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Support\TapologyFighterUrl;
use MMAF\DataEngine\Services\RegularImport\Repository;
use MMAF\DataEngine\Services\RegularImport\Transaction;

/** Private last-success input, independent of ZIP retention. Never served by public REST. */
final class ProfileStore {
    public static function from_career(array $profile): array {
        $raw=['source_fighter_id'=>$profile['source_fighter_id'],'source_url'=>$profile['source_url'],'status'=>$profile['source_status']??'unavailable','scraped_at'=>$profile['scraped_at']??null,'fight_history'=>$profile['rows']??[],'history_scope'=>$profile['history_scope']??'unknown','history_scope_verified'=>$profile['history_scope_verified']??false,'history_exhausted'=>$profile['history_exhausted']??false,'verified_facts'=>$profile['verified_facts']??[],'record_summary'=>$profile['expected_record']??[]];
        $raw['record_summary']['no_contests']=$raw['record_summary']['nc']??null;
        unset($raw['record_summary']['nc']);
        foreach(['career_record_evidence','period_evidence','parser_version','current_roster'] as $field){if(isset($profile[$field])){$raw[$field]=$profile[$field];}}
        if(isset($profile['record_raw'])){$raw['record_summary']['pro_record_raw']=$profile['record_raw'];}
        return $raw;
    }
    public static function date($value): ?string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D',$value)) { return null; }
        try {
            $date=new \DateTimeImmutable($value,new \DateTimeZone('UTC'));
            $errors=\DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) { return null; }
            if ($date->getTimestamp()>time()+300) { return null; }
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception $e) { return null; }
    }
    private static function stable($value) {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as &$item) { $item=self::stable($item); } unset($item);
        return $value;
    }
    public function put(array $profile, string $schema, int $job=0): array {
        global $wpdb;
        $identity=TapologyFighterUrl::parse((string)($profile['source_url']??''));
        if (!$identity) { return ['status'=>'review','reason'=>'Invalid profile source URL.']; }
        $key=$identity['source_fighter_id'] ?: 'tapology_fighter_url_'.substr($identity['source_url_hash'],0,24);
        if (!empty($profile['source_fighter_id']) && $profile['source_fighter_id']!==$key) { return ['status'=>'review','reason'=>'Profile source ID and URL disagree.']; }
        $profile['source_fighter_id']=$key; $profile['source_url']=$identity['source_url'];
        $observed=self::date($profile['scraped_at']??$profile['profile_scraped_at']??null);
        $success=($profile['status']??'')==='success';
        $content=$profile;
        foreach (['scraped_at','profile_scraped_at','fetched_at','diagnostics','profile_cache_hit','profile_cache_source','profile_cache_scraped_at','last_attempt'] as $field) { unset($content[$field]); }
        $payload=wp_json_encode($profile); $encoded=wp_json_encode(self::stable($content));
        if ($payload===false || $encoded===false || strlen($payload)>4*1024*1024) { return ['status'=>'review','reason'=>'Invalid or oversized profile payload.']; }
        $hash=hash('sha256',$encoded); $table=Schema::tables()['profiles']; $now=gmdate('Y-m-d H:i:s');
        return Transaction::run(function() use($wpdb,$table,$profile,$key,$identity,$observed,$success,$payload,$hash,$schema,$job,$now) {
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE source_type='tapology' AND source_fighter_id=%s FOR UPDATE",$key),ARRAY_A);
            $fighter=$this->resolve($identity);
            \MMAF\DataEngine\Services\Operations\Membership::record($job,$fighter,'stored_profile');
            $data=['source_type'=>'tapology','source_fighter_id'=>$key,'source_url'=>$identity['source_url'],'fighter_id'=>$fighter?:null,'schema_version'=>$schema,'updated_at'=>$now];
            $lastAttempt=$profile['last_attempt']??[];
            $attempt=self::date($lastAttempt['at']??null)??$observed;
            if (!$old || ($attempt && (!$old['last_attempt_at'] || $attempt>=$old['last_attempt_at']))) {
                $attemptStatus=(string)($lastAttempt['status']??$profile['status']??'unknown');
                $data+=['last_attempt_at'=>$attempt,'last_attempt_status'=>$attemptStatus,'last_error'=>$attemptStatus==='success'?null:substr(sanitize_text_field($lastAttempt['error_reason']??$profile['error_reason']??'Source profile unavailable.'),0,255)];
            }
            $replace=$success && (!$old || !$old['payload_json'] || ($observed && (!$old['source_fetched_at'] || $observed>$old['source_fetched_at'])));
            $status=$success?'unchanged':'retained_previous';
            if ($old && $success && $old['payload_json'] && $observed && $observed===$old['source_fetched_at'] && !hash_equals($old['content_hash'],$hash)) {
                return ['status'=>'review','reason'=>'Different profile content has the same source timestamp.','fighter_id'=>$fighter];
            }
            if ($replace) { $data+=['payload_json'=>$payload,'content_hash'=>$hash,'source_fetched_at'=>$observed,'import_job_id'=>$job?:null]; $status=(!$old || !$old['payload_json'])?'created':(hash_equals($old['content_hash'],$hash)?'unchanged':'updated'); }
            elseif ($success && $old && $old['source_fetched_at'] && (!$observed || $observed<$old['source_fetched_at'])) { $status='older_ignored'; }
            if ($old) {
                $changed=[];foreach($data as $field=>$value){if($field!=='updated_at' && ($old[$field]===null?$value!==null:(string)$old[$field] !== (string)$value)){$changed[$field]=$value;}}
                if($changed){$changed['updated_at']=$now;Repository::check($wpdb->update($table,$changed,['id'=>$old['id']]));}
            }
            else { $data+=['created_at'=>$now,'last_attempt_status'=>(string)($profile['status']??'unknown')]; Repository::check($wpdb->insert($table,$data)); }
            if($fighter && $replace){(new \MMAF\DataEngine\Services\Scoring\CareerRecords())->observe($fighter,$profile,$hash);}
            if($fighter && (!$old || !empty($changed))){\MMAF\DataEngine\Services\Operations\Readiness::invalidate($fighter);}
            return ['status'=>$status,'fighter_id'=>$fighter,'source_fighter_id'=>$key,'has_success'=>$replace || !empty($old['payload_json']),'hash'=>$replace?$hash:($old['content_hash']??null)];
        });
    }
    private function resolve(array $identity): int {
        global $wpdb; $t=Canonical::table_names();
        $rows=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT fighter_id FROM {$t['fighter_sources']} WHERE source_type='tapology' AND (source_fighter_id=%s OR identity_hash=%s OR source_url=%s) AND fighter_id IS NOT NULL LIMIT 3",$identity['source_fighter_id']??'',$identity['source_url_hash'],$identity['source_url']));
        return count($rows)===1 ? (int)$rows[0] : 0;
    }
    public function for_fighter(int $id): ?array {
        global $wpdb; $t=Canonical::table_names(); $table=Schema::tables()['profiles'];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.* FROM $table p JOIN {$t['fighter_sources']} fs ON fs.source_type=p.source_type AND (fs.source_fighter_id=p.source_fighter_id OR fs.source_url=p.source_url) WHERE fs.fighter_id=%d AND fs.source_type='tapology' AND p.payload_json IS NOT NULL ORDER BY p.source_fetched_at DESC,p.id DESC LIMIT 2",$id),ARRAY_A);
        if (count($rows)!==1) { return null; }
        $row=$rows[0]; $identity=TapologyFighterUrl::parse($row['source_url']);
        if (!$identity || $this->resolve($identity)!==$id) { return null; }
        $row['profile']=json_decode($row['payload_json'],true);
        if (!is_array($row['profile'])) { throw new \RuntimeException('Stored profile payload is invalid.'); }
        return $row;
    }
    public static function career_profile(array $raw,int $id): array {
        $record=$raw['record_summary']??[];
        $expected=['wins'=>$record['wins']??null,'losses'=>$record['losses']??null,'draws'=>$record['draws']??null,'nc'=>$record['no_contests']??null];
        $rows=$raw['fight_history']??[];
        foreach ($rows as &$row) {
            $row['source_sport']=$row['source_sport']??$row['discipline']??null;
            if(($row['classification_evidence']??'')==='profile_section_classification' && (($raw['history_scope_verified']??false)!==true || ($raw['history_scope']??'')!=='professional_mma' || ($row['classification_proof']['section']??'')!=='professional_mma' || ($row['classification_proof']['row_boundary_verified']??false)!==true)){
                unset($row['classification_evidence']);
            }
            if (!$row['source_sport'] || !in_array($row['classification_evidence']??'', ['profile_dom_classification','profile_section_classification','bout_dom_classification'],true)) {
                // Old text-parser booleans cannot exclude an existing canonical bout.
                foreach (['is_amateur','is_cancelled','is_exhibition','source_division'] as $field) { unset($row[$field]); }
                $row['is_professional']=false;
            }
            $row['is_professional']=($row['is_professional']??false)===true;
            if (empty($row['opponent_record_raw'])) { unset($row['prefight_record_raw']); }
        } unset($row);
        return ['current_roster'=>$raw['current_roster']??null,'career_record_evidence'=>$raw['career_record_evidence']??null,'period_evidence'=>$raw['period_evidence']??null,'record_raw'=>$record['pro_record_raw']??null,'parser_version'=>$raw['parser_version']??null,'fighter_id'=>$id,'source_fighter_id'=>$raw['source_fighter_id'],'source_url'=>$raw['source_url'],'scraped_at'=>$raw['scraped_at']??$raw['profile_scraped_at']??null,'source_status'=>$raw['status']??'unavailable','source_error_reason'=>$raw['error_reason']??null,'expected_record'=>in_array(null,$expected,true)?null:$expected,'history_scope'=>$raw['history_scope']??'unknown','history_scope_verified'=>($raw['history_scope_verified']??false)===true,'history_exhausted'=>($raw['history_exhausted']??false)===true,'verified_facts'=>$raw['verified_facts']??[],'rows'=>$rows];
    }
}
