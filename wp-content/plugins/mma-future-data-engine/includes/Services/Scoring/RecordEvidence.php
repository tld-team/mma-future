<?php
namespace MMAF\DataEngine\Services\Scoring;

/** Quality is available only when the record and its pre-fight provenance agree. */
final class RecordEvidence {
    public static function decode($value): array {
        return is_array($value) ? $value : (json_decode((string)$value,true) ?: []);
    }
    public static function opponent(array $item): array {
        $proof=self::decode($item['record_evidence_json']??null)['opponent']??[];
        $status=$proof['status']??'unknown';
        $unknown=['status'=>'unknown','record'=>null,'diff'=>null,'reason'=>$proof['reason']??'prefight_semantics_unproven'];
        if($status==='conflict'){return array_replace($unknown,['status'=>'conflict','reason'=>'conflicting_prefight_records']);}
        if($status!=='known' || !in_array($proof['evidence']??'', ['labelled_record_before_fight','labelled_record_at_fight','manual_verified_prefight'],true)){return $unknown;}
        $record=[];
        foreach(['wins','losses'] as $key){
            $value=$item['opponent_prefight_'.$key]??null;$expected=$proof['record'][$key]??null;
            if($value===null || !is_numeric($value) || (int)$value<0 || $expected===null || (int)$value!==(int)$expected){return array_replace($unknown,['status'=>'conflict','reason'=>'record_evidence_value_conflict']);}
            $record[$key]=(int)$value;
        }
        $diff=$record['wins']-$record['losses'];
        if(isset($item['opponent_prefight_diff']) && (int)$item['opponent_prefight_diff']!==$diff){return array_replace($unknown,['status'=>'conflict','reason'=>'record_differential_conflict']);}
        return ['status'=>'known','record'=>$record,'diff'=>$diff,'reason'=>null];
    }
}
