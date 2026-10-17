<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** Package validity and confirmed event population are separate assessments. */
final class EventCoverage {
    public static function read(string $path): array {
        if(!is_file($path) || filesize($path)>32*1024*1024){throw new ImportException('Missing or oversized event coverage evidence.','coverage');}
        $data=Storage::read($path);
        if(($data['schema_version']??'')!=='mmaf_event_coverage_v1' || !is_array($data['events']??null)){throw new ImportException('Unsupported event coverage evidence.','coverage');}
        $by=[];$counts=[];
        foreach($data['events'] as $event){
            $sid=$event['source_event_id']??'';$status=$event['status']??'';
            if(!is_string($sid)||!preg_match('/^tapology_event_\d+$/D',$sid)||isset($by[$sid])||!in_array($status,['complete','partial','unavailable','excluded'],true)){throw new ImportException('Invalid or duplicate event coverage identity/status.','coverage');}
            $legacy=($event['parser_version']??'')==='legacy';$bouts=$event['bouts']??[];$ids=[];$accepted=[];$mma=0;$unresolved=false;
            if(!is_array($bouts)){throw new ImportException('Invalid covered bout list.','coverage');}
            foreach($bouts as $bout){
                $key=$bout['source_bout_id']??'';$decision=$bout['decision']??'';$sport=$bout['sport']??'';
                if(!is_string($key)||$key===''||isset($ids[$key])||!in_array($decision,['accepted','excluded','unresolved'],true)||!in_array($sport,['mma','non_mma','unknown'],true)){throw new ImportException('Invalid covered bout identity or decision.','coverage');}
                $ids[$key]=true;$mma+=($sport==='mma');$unresolved=$unresolved||$decision==='unresolved';
                if($decision==='accepted'){
                    if(($bout['division']??'')!=='pro'||$sport!=='mma'){throw new ImportException('Accepted coverage lacks professional MMA classification.','coverage');}
                    $accepted[]=$key;
                }elseif($decision==='excluded' && !in_array($bout['reason']??'',['amateur','non_mma','cancelled'],true)){throw new ImportException('Excluded bout needs an explicit reason.','coverage');}
            }
            if(!$legacy){
                $actual=[];foreach($bouts as $bout){$d=$bout['decision'];$actual[$d]=($actual[$d]??0)+1;}
                if(($event['unique_bouts']??-1)!==count($bouts) || ($actual?:[])!=($event['counts']??null)){throw new ImportException('Coverage counters disagree with bout decisions.','coverage');}
            }
            if($legacy && $status!=='partial'){throw new ImportException('Legacy events cannot claim confirmed coverage.','coverage');}
            if(in_array($status,['complete','excluded'],true)){
                $expected=$event['source_mma_bouts_total']??null;
                $scopeExclusion=$status==='excluded' && !$bouts && in_array($event['event_exclusion']??'',['excluded_promotion_ufc','non_mma_metadata'],true) && !empty($event['exclusion_evidence']);
                if((!$bouts&&!$scopeExclusion)||$unresolved||!empty($event['reasons'])||(!$scopeExclusion && $expected!==null && (!is_int($expected)||$mma!==$expected))||!\MMAF\DataEngine\Services\DataPipeline\ProfileStore::date($event['observed_at']??null)){throw new ImportException('Incomplete event claims confirmed coverage.','coverage');}
                if(($status==='complete')!==(!empty($accepted))){throw new ImportException('Coverage status contradicts accepted results.','coverage');}
            }
            sort($accepted);$by[$sid]=['accepted'=>$accepted,'proof'=>$event,'legacy'=>$legacy];$counts[$status]=($counts[$status]??0)+1;
        }
        return ['by_id'=>$by,'run_id'=>$data['run_id']??null,'counts'=>$counts,'complete'=>empty($counts['partial'])&&empty($counts['unavailable'])];
    }
    public static function checkEvent(array $event,array $index): void {
        $proof=$index[$event['source_event_id']??'']??null;
        if(!$proof){throw new ImportException('Source event has no coverage evidence.','coverage');}
        if($proof['legacy']){return;}
        $ids=array_column($event['bouts']??[],'source_bout_id');sort($ids);
        if(($event['event_url']??null)!==($proof['proof']['event_url']??null) || $ids!==$proof['accepted'] || ($event['event_coverage']??null)!=$proof['proof']){throw new ImportException('Event coverage disagrees with accepted source results.','coverage');}
    }
}
