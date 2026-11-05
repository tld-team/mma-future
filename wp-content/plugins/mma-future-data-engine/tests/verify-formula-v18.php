<?php
/** Deterministic Formula v1.8 whole-career contract checks. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR ); }
if ( ! function_exists( '__' ) ) { function __( string $text, string $domain = '' ): string { return $text; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value ): string { return json_encode( $value ); } }

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'Services/Formula/FormulaV18.php';
require_once $base . 'Services/Scoring/RecordEvidence.php';
require_once $base . 'Services/PrefightRecordMerge.php';

use MMAF\DataEngine\Services\Formula\FormulaV18;
use MMAF\DataEngine\Services\Scoring\RecordEvidence;
use MMAF\DataEngine\Services\PrefightRecordMerge;

$failed=[];$count=0;
$check=static function(string $name,$expected,$actual) use (&$failed,&$count): void {
    ++$count;$ok=is_float($expected)||is_float($actual)?abs((float)$expected-(float)$actual)<0.000001:$expected===$actual;
    if(!$ok){$failed[$name]=['expected'=>$expected,'actual'=>$actual];}
};
$formula=new FormulaV18();
$check('version','v1.8',FormulaV18::VERSION);
$check('scope','professional_mma_career_v1',FormulaV18::SCOPE);
$check('no_lower_date',null,FormulaV18::PERIOD_START);

foreach([[-11,-2.0],[-10,-2.0],[-9,0.0],[-1,0.0],[0,1.0],[10,1.0],[11,3.0],[30,3.0],[31,5.0],[60,5.0],[61,7.0],[100,7.0],[101,10.0]] as [$od,$points]){
    $check('win_'.$od,$points,$formula->opponent_differential_points_for_win($od));
}
foreach([[1,0.0],[0,-2.0],[-10,-2.0],[-11,-5.0],[-20,-5.0],[-21,-10.0]] as [$od,$points]){
    $check('loss_'.$od,$points,$formula->loss_quality_penalty_points($od));
}
foreach([[24,1.0],[25,0.0],[30,0.0],[31,-1.0]] as [$age,$points]){$check('age_'.$age,$points,$formula->age_adjustment_points($age));}
$check('unknown_quality_win',0.0,$formula->opponent_differential_points_for_win(null));
$check('unknown_quality_loss',0.0,$formula->loss_quality_penalty_points(null));
$check('unknown_method',0.0,$formula->finish_points('win','unknown'));
$check('dq_method',0.0,$formula->finish_points('win','dq'));
$check('ko_finish',1.0,$formula->finish_points('win','ko_tko'));
$check('submission_finish',1.0,$formula->finish_points('win','submission'));

$proof=static function(int $wins,int $losses): string {
    return wp_json_encode(['contract_version'=>1,'opponent'=>['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>$wins,'losses'=>$losses]]]);
};
$known=RecordEvidence::opponent(['opponent_prefight_wins'=>0,'opponent_prefight_losses'=>0,'opponent_prefight_diff'=>0,'record_evidence_json'=>$proof(0,0)]);
$check('known_zero_status','known',$known['status']);$check('known_zero_diff',0,$known['diff']);
$unknown=RecordEvidence::opponent(['opponent_prefight_wins'=>0,'opponent_prefight_losses'=>0,'opponent_prefight_diff'=>0]);
$check('unproved_zero_unknown','unknown',$unknown['status']);
$conflict=RecordEvidence::opponent(['opponent_prefight_wins'=>1,'opponent_prefight_losses'=>0,'opponent_prefight_diff'=>1,'record_evidence_json'=>$proof(0,0)]);
$check('evidence_value_conflict','conflict',$conflict['status']);
$check('ko_unknown_total',1.5,$formula->base_record_points('win')+$formula->finish_points('win','ko_tko')+$formula->opponent_differential_points_for_win($unknown['diff']));
$check('ko_zero_total',2.5,$formula->base_record_points('win')+$formula->finish_points('win','ko_tko')+$formula->opponent_differential_points_for_win($known['diff']));

$incoming=[
 ['fighter_id'=>1,'participant_role'=>'fighter_a','prefight_wins'=>3,'prefight_losses'=>1,'opponent_prefight_wins'=>0,'opponent_prefight_losses'=>0,'opponent_prefight_diff'=>0,'record_evidence_json'=>PrefightRecordMerge::evidence(['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>3,'losses'=>1]],['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>0,'losses'=>0]])],
 ['fighter_id'=>2,'participant_role'=>'fighter_b','prefight_wins'=>0,'prefight_losses'=>0,'opponent_prefight_wins'=>3,'opponent_prefight_losses'=>1,'opponent_prefight_diff'=>2,'record_evidence_json'=>PrefightRecordMerge::evidence(['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>0,'losses'=>0]],['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>3,'losses'=>1]])],
];
$empty=$incoming;foreach($empty as &$row){foreach(['prefight_wins','prefight_losses','opponent_prefight_wins','opponent_prefight_losses','opponent_prefight_diff'] as $key){$row[$key]=null;}$row['record_evidence_json']=null;}unset($row);
$merged=PrefightRecordMerge::merge($empty,$incoming,static fn()=>false);
$check('merge_changed',true,$merged['changed']);$check('merge_mirrored',2,$merged['rows'][1]['opponent_prefight_diff']);$check('merge_no_conflict',[],$merged['conflicts']);
$repeated=PrefightRecordMerge::merge($merged['rows'],$incoming,static fn()=>false);
$check('merge_idempotent',false,$repeated['changed']);
$poorer=$incoming;foreach($poorer as &$row){foreach(['prefight_wins','prefight_losses','opponent_prefight_wins','opponent_prefight_losses','opponent_prefight_diff'] as $key){$row[$key]=null;}$row['record_evidence_json']=null;}unset($row);
$preserved=PrefightRecordMerge::merge($merged['rows'],$poorer,static fn()=>false);
$check('poorer_does_not_erase',3,$preserved['rows'][0]['prefight_wins']);

$untrusted=$merged['rows'];
$untrusted[0]['prefight_wins']=9;$untrusted[0]['prefight_losses']=8;$untrusted[0]['record_evidence_json']=null;
$untrusted[1]['opponent_prefight_wins']=9;$untrusted[1]['opponent_prefight_losses']=8;$untrusted[1]['opponent_prefight_diff']=1;$untrusted[1]['record_evidence_json']=null;
$enriched=PrefightRecordMerge::merge($untrusted,$incoming,static fn()=>false);
$check('trusted_replaces_untrusted',3,$enriched['rows'][0]['prefight_wins']);
$check('trusted_replacement_no_conflict',[],$enriched['conflicts']);

$protected=$merged['rows'];$protected[0]['id']=101;$protected[1]['id']=102;
$same_protected=PrefightRecordMerge::merge($protected,$incoming,static fn($id,$field)=>$id===101&&$field==='prefight_wins');
$check('matching_manual_protection_is_idempotent',false,$same_protected['changed']);
$different=$incoming;$different[0]['prefight_wins']=4;
$different[0]['record_evidence_json']=PrefightRecordMerge::evidence(['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>4,'losses'=>1]],['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>0,'losses'=>0]]);
$different[1]['opponent_prefight_wins']=4;$different[1]['opponent_prefight_diff']=3;
$different[1]['record_evidence_json']=PrefightRecordMerge::evidence(['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>0,'losses'=>0]],['status'=>'known','evidence'=>'labelled_record_before_fight','record'=>['wins'=>4,'losses'=>1]]);
$protected_conflict=PrefightRecordMerge::merge($protected,$different,static fn($id,$field)=>$id===101&&$field==='prefight_wins');
$check('protected_conflict_reported',[1],$protected_conflict['conflicts']);
$check('protected_value_survives',3,$protected_conflict['rows'][0]['prefight_wins']);
$check('mirrored_value_not_partially_changed',3,$protected_conflict['rows'][1]['opponent_prefight_wins']);

echo json_encode(['ok'=>!$failed,'checks'=>$count,'failed'=>$failed],JSON_PRETTY_PRINT)."\n";
exit($failed?1:0);
