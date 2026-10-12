<?php
use MMAF\DataEngine\Repositories\{FighterRepository,FighterSourceRepository};
use MMAF\DataEngine\Services\Operations\Jobs;

/** Invented identities and source rows; no private sports package or network access. */
function fixture_fighter(int $source, string $name, array $values=[]): array {
    $url='https://www.tapology.com/fightcenter/fighters/'.$source.'-fixture';
    $id=(new FighterRepository())->insert(array_merge([
        'display_name'=>$name,'normalized_name'=>strtolower($name),'gender'=>'male',
        'date_of_birth'=>'2000-01-01','weight_class'=>'lightweight','status'=>'provisional',
        'created_at'=>gmdate('Y-m-d H:i:s'),
    ],$values));
    (new FighterSourceRepository())->upsert_for_fighter($id,[
        'source_type'=>'tapology','source_fighter_id'=>'tapology_fighter_'.$source,
        'source_url'=>$url,'identity_hash'=>hash('sha256',$url),'is_primary'=>1,
    ]);
    return ['fighter_id'=>$id,'source_fighter_id'=>'tapology_fighter_'.$source,'source_url'=>$url,'display_name'=>$name];
}
function fixture_fight(int $source, array $opponent, string $result='win', array $values=[]): array {
    return array_merge([
        'source_bout_id'=>'tapology_bout_'.$source,'bout_url'=>'https://www.tapology.com/fightcenter/bouts/'.$source.'-fixture',
        'source_event_id'=>'tapology_event_'.$source,'event_url'=>'https://www.tapology.com/fightcenter/events/'.$source.'-fixture',
        'event_name'=>'Synthetic fixture event','fight_date'=>'2026-10-05',
        'opponent_source_id'=>$opponent['source_fighter_id'],'opponent_url'=>$opponent['source_url'],'opponent_name'=>$opponent['display_name'],
        'discipline'=>'mma','source_sport'=>'mma','source_division'=>'pro','classification_evidence'=>'profile_dom_classification',
        'is_professional'=>true,'result'=>$result,'method_category'=>'decision',
    ],$values);
}
function fixture_profile(array $fighter, array $rows, array $record, array $values=[]): array {
    return array_merge([
        'source_fighter_id'=>$fighter['source_fighter_id'],'source_url'=>$fighter['source_url'],'display_name'=>$fighter['display_name'],
        'status'=>'success','scraped_at'=>'2026-10-10T10:00:00Z','record_summary'=>$record,
        'history_scope'=>'professional_mma','history_scope_verified'=>true,'history_exhausted'=>true,'fight_history'=>$rows,
    ],$values);
}
function fixture_drive_job(int $id, string $target='completed'): array {
    for($n=0;$n<2000;++$n){
        $job=Jobs::get($id);
        if($job['status']===$target){return $job;}
        if(in_array($job['status'],['failed','cancelled'],true) || !empty($job['error_message'])){
            throw new RuntimeException('Operation #'.$id.' '.$job['status'].': '.($job['error_message']??''));
        }
        $result=Jobs::work();
        if(empty($result['worked'])){throw new RuntimeException('Operation #'.$id.' cannot progress at '.$job['phase']);}
    }
    throw new RuntimeException('Operation #'.$id.' exceeded its test step budget.');
}
function fixture_confirm_job(int $id): array {
    $job=fixture_drive_job($id,'ready');
    return Jobs::control($id,'start',1,$job['manifest_hash']);
}
function fixture_canonical_baseline(): array {
    $flags=['status'=>'verified','is_public'=>1,'is_rankable'=>1,'rankability_status'=>'rankable'];
    $a=fixture_fighter(949899901,'Synthetic parity A',$flags);
    $b=fixture_fighter(949899902,'Synthetic parity B',$flags);
    $rows=[];foreach(['win','loss','draw','no_contest'] as $n=>$result){$rows[]=fixture_fight(949899910+$n,$b,$result);}
    $profile=fixture_profile($a,$rows,['wins'=>1,'losses'=>1,'draws'=>1,'no_contests'=>1]);
    (new \MMAF\DataEngine\Services\RegularImport\CareerHistory())->process(
        \MMAF\DataEngine\Services\DataPipeline\ProfileStore::career_profile($profile,$a['fighter_id']),0,1,true
    );
    return [$a,$b];
}
