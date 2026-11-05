<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Services\Scoring\RecordEvidence;

/** One merge rule for profile and event imports. Never replaces participant identities. */
final class PrefightRecordMerge {
    public const FIELDS=['wins','losses','draws','nc','record_raw'];
    private const TRUSTED_EVIDENCE=['labelled_record_before_fight','labelled_record_at_fight','manual_verified_prefight'];

    public static function evidence(array $own,array $opponent): string {
        return wp_json_encode(['contract_version'=>1,'fighter'=>$own,'opponent'=>$opponent]);
    }

    public static function manual(array $record): array {
        $known=isset($record['wins'],$record['losses']);
        return ['status'=>$known?'known':'unknown','reason'=>$known?null:'missing_manual_record',
            'record'=>$known?array_intersect_key($record,array_flip(['wins','losses','draws','nc'])):null,
            'raw'=>$record['raw']??$record['record_raw']??null,'evidence'=>$known?'manual_verified_prefight':null,
            'source'=>'manual','observed_at'=>gmdate('c'),'parser_version'=>null];
    }

    /** Pure proposal; callers persist both rows in their existing transaction. */
    public static function merge(array $current,array $incoming,?callable $protected=null): array {
        $protected=$protected??static function($id,$field){return $id && (new FieldProvenanceService())->is_protected('bout_participant',$id,$field);};
        if(count($incoming)!==2 || ($current && count($current)!==2)){throw new \InvalidArgumentException('Pre-fight merge requires exactly two participants.');}
        $by=[];foreach($incoming as $row){$by[(int)$row['fighter_id']]=$row;}
        $rows=$current?:$incoming;
        foreach($rows as $row){if(!isset($by[(int)$row['fighter_id']])){throw new \InvalidArgumentException('Pre-fight merge cannot change participant identity.');}}
        $conflicts=[];
        foreach([0,1] as $index){
            $other=1-$index;$fighter=(int)$rows[$index]['fighter_id'];
            $targets=[
                self::target($rows,$index,'prefight','fighter',$protected),
                self::target($rows,$other,'opponent_prefight','opponent',$protected),
            ];
            $sources=[
                [$rows[$index],'prefight','fighter',true,$targets[0]['locked']],
                [$rows[$other],'opponent_prefight','opponent',true,$targets[1]['locked']],
                [$by[$fighter],'prefight','fighter',false,false],
                [$by[(int)$rows[$other]['fighter_id']],'opponent_prefight','opponent',false,false],
            ];
            $candidates=[];$conflict=false;$existing_conflict=null;
            foreach($sources as [$row,$prefix,$side,$existing,$locked]){
                $candidate=self::candidate($row,$prefix,$side,$existing,$locked);
                if(!$candidate){continue;}
                if(($candidate['proof']['status']??'')==='conflict'){$conflict=true;$existing_conflict=$existing_conflict?:$candidate['proof'];}
                if($candidate['invalid_proof']){$conflict=true;}
                if($locked && !$candidate['complete']){$conflict=true;}
                $candidates[]=$candidate;
            }
            $trusted=array_values(array_filter($candidates,static fn($candidate)=>$candidate['trusted']));
            foreach($trusted as $left_index=>$left){
                foreach(array_slice($trusted,$left_index+1) as $right){if(!self::compatible($left['value'],$right['value'])){$conflict=true;}}
            }
            $chosen=$trusted[0]??null;
            if(!$chosen){foreach($candidates as $candidate){if($candidate['complete']){$chosen=$candidate;break;}}}
            if($chosen){
                foreach($candidates as $candidate){if(self::compatible($chosen['value'],$candidate['value'])){$chosen['value']=self::enrich($chosen['value'],$candidate['value']);}}
            }
            if(!$chosen && !$conflict){continue;}
            $proof=($chosen['proof']??[])?:['status'=>'unknown','reason'=>'prefight_semantics_unproven'];
            if($chosen && $chosen['trusted'] && ($proof['status']??'')!=='known'){$proof=self::manual($chosen['value']);}
            if($conflict){
                $conflicts[]=$fighter;
                $proof=['status'=>'conflict','reason'=>'conflicting_prefight_records','accepted'=>$proof,'candidates'=>$candidates];
                // Re-importing the same conflict must not recursively grow evidence.
                if($existing_conflict){$proof=$existing_conflict;}
            }
            foreach($targets as $target){
                ['index'=>$i,'prefix'=>$prefix,'side'=>$side,'locked'=>$locked,'evidence_locked'=>$evidence_locked]=$target;
                if(!$locked && !$conflict && $chosen){foreach(self::FIELDS as $field){$rows[$i][$prefix.'_'.$field]=$chosen['value'][$field];}}
                $evidence=RecordEvidence::decode($rows[$i]['record_evidence_json']??null);
                if(!$evidence_locked){
                    $evidence['contract_version']=1;$evidence[$side]=$proof;$rows[$i]['record_evidence_json']=wp_json_encode($evidence);
                }
                if($prefix==='opponent_prefight' && !$locked && !$conflict && $chosen){$rows[$i]['opponent_prefight_diff']=$chosen['value']['wins']-$chosen['value']['losses'];}
            }
        }
        return ['rows'=>$rows,'conflicts'=>array_values(array_unique($conflicts)),'changed'=>$rows!=($current?:$incoming)];
    }

    private static function target(array $rows,int $index,string $prefix,string $side,callable $protected): array {
        $id=(int)($rows[$index]['id']??0);$evidence_locked=$protected($id,'record_evidence_json');$locked=$evidence_locked;
        foreach(array_map(static fn($field)=>$prefix.'_'.$field,self::FIELDS) as $field){$locked=$locked||$protected($id,$field);}
        if($prefix==='opponent_prefight'){$locked=$locked||$protected($id,'opponent_prefight_diff');}
        return ['index'=>$index,'prefix'=>$prefix,'side'=>$side,'locked'=>$locked,'evidence_locked'=>$evidence_locked];
    }

    private static function candidate(array $row,string $prefix,string $side,bool $existing,bool $locked): ?array {
        $proof=RecordEvidence::decode($row['record_evidence_json']??null)[$side]??[];$value=[];
        foreach(self::FIELDS as $field){$raw=$row[$prefix.'_'.$field]??null;$value[$field]=$raw!==null&&$field!=='record_raw'?(int)$raw:$raw;}
        $complete=$value['wins']!==null&&$value['losses']!==null;
        if(!$complete && ($proof['status']??'')!=='conflict' && !$locked){return null;}
        $invalid=false;
        if(($proof['status']??'')==='known'){
            foreach(['wins','losses','draws','nc'] as $field){
                if(array_key_exists($field,$proof['record']??[]) && ($value[$field]===null||(int)$proof['record'][$field]!==$value[$field])){$invalid=true;}
            }
        }
        $trusted=$complete&&$locked;
        if($complete&&($proof['status']??'')==='known'&&in_array($proof['evidence']??'',self::TRUSTED_EVIDENCE,true)&&!$invalid){$trusted=true;}
        if($trusted&&($proof['status']??'')!=='known'){$proof=self::manual($value);}
        return ['value'=>$value,'proof'=>$proof,'existing'=>$existing,'locked'=>$locked,'complete'=>$complete,'trusted'=>$trusted,'invalid_proof'=>$invalid];
    }

    private static function compatible(array $left,array $right): bool {
        foreach(['wins','losses','draws','nc'] as $field){if($left[$field]!==null&&$right[$field]!==null&&$left[$field]!==$right[$field]){return false;}}
        return true;
    }

    private static function enrich(array $preferred,array $fallback): array {
        foreach(self::FIELDS as $field){if($preferred[$field]===null&&$fallback[$field]!==null){$preferred[$field]=$fallback[$field];}}
        return $preferred;
    }

    public static function method_conflict(array $rows,?string $old,?string $new): array {
        $known=['ko_tko','submission','decision','dq','draw','no_contest'];
        if($old===$new || !in_array($old,$known,true) || !in_array($new,$known,true)){return $rows;}
        foreach($rows as &$row){$e=RecordEvidence::decode($row['record_evidence_json']??null);$e['method_conflict']=['accepted'=>$old,'incoming'=>$new];$row['record_evidence_json']=wp_json_encode($e);}unset($row);
        return $rows;
    }
    public static function provenance(int $bout_id,string $source,int $actor): void {
        $service=new FieldProvenanceService();
        foreach((new \MMAF\DataEngine\Repositories\BoutParticipantRepository())->list_by_bout($bout_id) as $row){
            foreach(array_merge(array_map(static fn($f)=>'prefight_'.$f,self::FIELDS),array_map(static fn($f)=>'opponent_prefight_'.$f,self::FIELDS),['opponent_prefight_diff','record_evidence_json']) as $field){
                if(!$service->is_protected('bout_participant',(int)$row['id'],$field)){$service->upsert_source('bout_participant',(int)$row['id'],$field,$row[$field]??null,'tapology',$source,$actor);}
            }
        }
    }
}
