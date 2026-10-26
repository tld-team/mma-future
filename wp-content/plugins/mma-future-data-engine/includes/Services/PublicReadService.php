<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Repositories\RestReadRepository;
use MMAF\DataEngine\Repositories\PublicReadRepository;
use MMAF\DataEngine\REST\AbstractRestController;
use MMAF\DataEngine\Services\Formula\FormulaRegistry;
use MMAF\DataEngine\Services\Scoring\OpponentImpact;
use MMAF\DataEngine\Services\Scoring\FinishSummary;

/** One public contract for templates and REST. No administrator visibility bypass. */
final class PublicReadService extends AbstractRestController {
	private RestReadRepository $read;
	private PublicReadRepository $public;
	public function __construct() { $this->read=new RestReadRepository(); $this->public=new PublicReadRepository(); }

	public static function board_labels(): array {
		return array('overall'=>'Overall','male'=>'Men','female'=>'Women','under_30'=>'Under 30','over_30_to_34'=>'Age 30–34','flyweight'=>'Flyweight','bantamweight'=>'Bantamweight','featherweight'=>'Featherweight','lightweight'=>'Lightweight','welterweight'=>'Welterweight','middleweight'=>'Middleweight','light_heavyweight'=>'Light Heavyweight','heavyweight'=>'Heavyweight','women_strawweight'=>'Women’s Strawweight','women_flyweight'=>'Women’s Flyweight','women_bantamweight'=>'Women’s Bantamweight','women_featherweight'=>'Women’s Featherweight');
	}
	public static function board( string $value ): string { return str_replace('-','_',sanitize_key($value)); }

	public function fighter( int $id ) {
		$f=$this->read->fighter($id);
		if (!$f || !empty($f['deleted_soft']) || 1!==(int)$f['is_public']) { return $this->not_found(); }
		$profile=$this->profile_data($f['wp_post_id'] ?? null);
		if (!$profile['permalink']) { return $this->not_found(); }
		$stats=$this->stats($this->read->fighter_stats($id));
		$rankings=array(); $active=$this->read->active_ranking_run();$inUfc=1===(int)$f['in_ufc'];
		$rankMaps=[];
		foreach($inUfc?[]:$this->read->fighter_current_rankings($id) as $r) {
			if (!$active || (int)$r['ranking_run_id']!==(int)$active['id']) { continue; }
			$board=(string)$r['board_key'];
			if(!isset($rankMaps[$board])){$rankMaps[$board]=$this->public->rank_map((int)$active['id'],$board);}
			$r['rank_position']=$rankMaps[$board][$id]??$r['rank_position'];
			$rankings[]=$this->ranking($r,$active);
		}
		$scoring=(new \MMAF\DataEngine\Services\Scoring\PublicAssessments())->for_fighters([$id],$active)[$id];
		if($inUfc){$scoring['scoring_assessment']=null;}
		$age=$this->age($f['date_of_birth'] ?? null,$f['birth_year'] ?? null);
		$facts=array();
		foreach(array('nickname','gender','date_of_birth','nationality','weight_class','height','last_weigh_in','birthplace','fighting_out_of','stance','team_name','coach_name') as $key) { $facts[$key]=$f[$key] ?? null; }
		foreach(array('birth_year','height_cm','reach_cm') as $key) { $facts[$key]=isset($f[$key]) ? (int)$f[$key] : null; }
		$editorial=array();
		foreach(array('biography','career_highlights','fighter_social_links','hero_image') as $key) { $editorial[$key]=function_exists('get_field') ? get_field($key,(int)$f['wp_post_id']) : get_post_meta((int)$f['wp_post_id'],$key,true); }
		$history=$this->history($id,array('per_page'=>10),false);
		$signing=$inUfc?(new UfcSigningService())->signing($id):null;
		return array('fighter'=>array_merge($facts,array('id'=>$id,'display_name'=>$f['display_name'],'age'=>$age,'age_precision'=>!empty($f['date_of_birth'])?'exact':(null!==$age?'approximate':'unknown'),'status'=>$f['status'],'rankability_status'=>$f['rankability_status'],'is_public'=>true,'is_rankable'=>1===(int)$f['is_rankable'],'in_ufc'=>$inUfc)), 'profile'=>$profile,'stats'=>$stats,'recorded_stats'=>$stats,'rankings'=>$rankings,'ufc_signing'=>['confirmed'=>$inUfc,'signing_date'=>$signing['signing_date']??null,'evidence_url'=>$signing['evidence_url']??null,'confirmed_at'=>$this->utc($signing['created_at']??null)],'recent_fights'=>$history['items'],'career_coverage'=>$this->coverage($id),'career_record'=>$scoring['career_record'],'scoring_assessment'=>$scoring['scoring_assessment'],'editorial'=>$editorial,'dates'=>$this->dates($active));
	}

	public function history( int $id, array $options=array(), bool $check_visibility=true ) {
		$f=$this->read->fighter($id);
		if(!$f || !empty($f['deleted_soft']) || 1!==(int)$f['is_public'] || !$this->profile_data($f['wp_post_id'] ?? null)['permalink']) { return $this->not_found(); }
		return PublicCacheService::remember('history',array($id,$options),fn()=>$this->history_uncached($id,$options));
	}
	private function history_uncached( int $id, array $options=array() ) {

		$options=$this->pagination($options,10); $result=$this->public->fights($id,$options); $this->prime(array_column($result['rows'],'opponent_wp_post_id'));
		$items=array();
		foreach($result['rows'] as $r) {
			$p=$this->profile_data($r['opponent_wp_post_id']);
			$items[]=array('bout_id'=>(int)$r['bout_id'],'event_id'=>(int)$r['event_id'],'event_name'=>$r['event_name'],'event_date'=>$r['event_date'],'event_location'=>$r['location'],'promotion_name'=>$r['promotion_name'],'opponent'=>array('fighter_id'=>isset($r['opponent_fighter_id'])?(int)$r['opponent_fighter_id']:null,'display_name'=>$r['opponent_display_name'],'permalink'=>$p['permalink'],'image'=>$p['image']),'record_eligible'=>'excluded_record_ineligible'!==$r['bout_status'],'record_note'=>'excluded_record_ineligible'===$r['bout_status']?'Excluded from the professional record by the source':null,'result'=>$r['result_for_fighter'],'method_category'=>$r['method_category'],'method_detail'=>$r['method_detail'],'round'=>isset($r['round_number'])?(int)$r['round_number']:null,'time'=>$r['time_in_round'],'weight_class'=>$r['weight_class']);
		}
		return array_merge($options,array('fighter_id'=>$id,'total'=>$result['total'],'total_pages'=>(int)ceil($result['total']/$options['per_page']),'items'=>$items,'career_coverage'=>$this->coverage($id)));
	}

	public function rankings( array $options=array() ) {
		if(!empty($options['q'])) { $options['q']=mb_substr((string)$options['q'],0,128);return $this->rankings_uncached($options); }
		return PublicCacheService::remember('rankings',$options,fn()=>$this->rankings_uncached($options));
	}
	private function rankings_uncached( array $options=array() ) {
        $view=$options['view']??'main';
        if(!in_array($view,['main','provisional'],true)){return new \WP_Error('mmaf_invalid_view','Unknown ranking view.',['status'=>400]);}
        if($view==='provisional'){return $this->provisional_rankings($options);}

		$options=$this->pagination($options,25); $board=self::board((string)($options['board'] ?? 'overall'));
		if(!isset(self::board_labels()[$board])) { return new \WP_Error('mmaf_invalid_board','Unknown ranking board.',array('status'=>400)); }
		$active=$this->read->active_ranking_run(); $state=$this->read->ranking_state_summary();
		$result=$active ? $this->public->rankings((int)$active['id'],$board,$options) : array('total'=>0,'rows'=>array());
		$this->prime(array_column($result['rows'],'wp_post_id')); $items=array();
        $scoring=(new \MMAF\DataEngine\Services\Scoring\PublicAssessments())->for_fighters(array_column($result['rows'],'fighter_id'),$active);$dates=$this->dates($active);
		$rankMap=$active?$this->public->rank_map((int)$active['id'],$board):[];
		foreach($result['rows'] as $r) {
			$r['rank_position']=$rankMap[(int)$r['fighter_id']]??$r['rank_position'];
			$p=$this->profile_data($r['wp_post_id']); $item=$this->ranking($r,$active);
			$item=array_merge($item,array('fighter_id'=>(int)$r['fighter_id'],'display_name'=>$r['display_name'],'nickname'=>$r['nickname'],'slug'=>$p['slug'],'permalink'=>$p['permalink'],'image'=>$p['image'],'gender'=>$r['gender'],'weight_class'=>$r['weight_class'],'nationality'=>$r['nationality'],'age'=>$this->age($r['date_of_birth'],$r['birth_year']),'record'=>array('wins'=>isset($r['wins'])?(int)$r['wins']:null,'losses'=>isset($r['losses'])?(int)$r['losses']:null,'draws'=>isset($r['draws'])?(int)$r['draws']:null,'nc'=>isset($r['nc'])?(int)$r['nc']:null,'pro_fights_count'=>isset($r['pro_fights_count'])?(int)$r['pro_fights_count']:null), 'stats'=>array('finish_wins'=>isset($r['finish_wins'])?(int)$r['finish_wins']:null,'finish_rate'=>isset($r['finish_rate'])?(float)$r['finish_rate']:null,'last_fight_date'=>$r['last_fight_date'],'streak'=>$r['streak'],'recent_form'=>$r['recent_form'],'activity_status'=>$r['activity_status'],'calculated_at'=>$this->utc($r['stats_calculated_at']))));
			if(empty($options['include_breakdown'])) { unset($item['breakdown']); }
            $item=array_merge($item,$scoring[(int)$r['fighter_id']]??[],['dates'=>$dates]);
			$items[]=$item;
		}
		return array_merge($state,$this->dates($active),array('view'=>'main','board'=>$board,'formula_version'=>$active['formula_version'] ?? null,'generated_at'=>$active['calculated_at'] ?? null,'active_calculated_at'=>$active['calculated_at'] ?? null,'latest_completed_ranking_run_id'=>$state['latest_completed_run_id'],'page'=>$options['page'],'per_page'=>$options['per_page'],'total'=>$result['total'],'total_pages'=>(int)ceil($result['total']/$options['per_page']),'items'=>$items,'available_boards'=>$active?$this->public->boards((int)$active['id']):array(),'message'=>$active?null:'No active ranking run is available.'));
	}

    private function provisional_rankings(array $options): array {
        $options=$this->pagination($options,25);$board=self::board((string)($options['board']??'overall'));
        if(!isset(self::board_labels()[$board])){return new \WP_Error('mmaf_invalid_board','Unknown ranking board.',['status'=>400]);}
        $active=$this->read->active_ranking_run();$supported=$active && $active['formula_version']==='v1.6';
        $result=$supported?$this->public->provisional_rankings((int)$active['id'],$board,$options):['total'=>0,'rows'=>[]];
        $this->prime(array_column($result['rows'],'wp_post_id'));$dto=(new \MMAF\DataEngine\Services\Scoring\PublicAssessments())->for_fighters(array_column($result['rows'],'fighter_id'),$active);$items=[];$dates=$this->dates($active);
        foreach($result['rows'] as $r){$id=(int)$r['fighter_id'];$p=$this->profile_data($r['wp_post_id']);$items[]=array_merge(['fighter_id'=>$id,'display_name'=>$r['display_name'],'nickname'=>$r['nickname'],'slug'=>$p['slug'],'permalink'=>$p['permalink'],'image'=>$p['image'],'gender'=>$r['gender'],'weight_class'=>$r['weight_class'],'nationality'=>$r['nationality'],'age'=>$this->age($r['date_of_birth'],$r['birth_year']),'board'=>$board,'rank'=>null,'rank_position'=>null,'score'=>$r['total_score']===null?null:(float)$r['total_score'],'opponent_impact'=>OpponentImpact::from_breakdown($this->json_value($r['breakdown_json']??null)),'scoring_finishes'=>FinishSummary::from_breakdown($this->json_value($r['breakdown_json']??null)),'sample_size'=>(int)$r['sample_size'],'formula_version'=>'v1.6','active_ranking_run_id'=>(int)$active['id'],'record'=>['wins'=>$r['wins']===null?null:(int)$r['wins'],'losses'=>$r['losses']===null?null:(int)$r['losses'],'draws'=>$r['draws']===null?null:(int)$r['draws'],'nc'=>$r['nc']===null?null:(int)$r['nc'],'pro_fights_count'=>$r['pro_fights_count']===null?null:(int)$r['pro_fights_count']]],$dto[$id]??[],['dates'=>$dates,'stats'=>['finish_wins'=>$r['finish_wins']===null?null:(int)$r['finish_wins'],'finish_rate'=>$r['finish_rate']===null?null:(float)$r['finish_rate'],'last_fight_date'=>$r['last_fight_date'],'streak'=>$r['streak'],'recent_form'=>$r['recent_form'],'activity_status'=>$r['activity_status'],'calculated_at'=>$this->utc($r['stats_calculated_at'])]]);}
        return array_merge($this->dates($active),['view'=>'provisional','board'=>$board,'formula_version'=>$active['formula_version']??null,'active_ranking_run_id'=>$active?(int)$active['id']:null,'page'=>$options['page'],'per_page'=>$options['per_page'],'total'=>$result['total'],'total_pages'=>(int)ceil($result['total']/$options['per_page']),'items'=>$items,'available_boards'=>$supported?array_keys(self::board_labels()):[],'message'=>$supported?'Provisional points from known accepted fights; no main ranking position.':'The active methodology has no provisional ranking view.']);
    }
	public function movers( string $board='overall', int $limit=3, array $options=[] ) {
		$board=self::board($board); if(!isset(self::board_labels()[$board])) { return new \WP_Error('mmaf_invalid_board','Unknown ranking board.',array('status'=>400)); }
		$current=$this->read->active_ranking_run(); $previous=$current?$this->public->previous_activation((int)$current['id'],$board):null;
		$response=array('board'=>$board,'active_ranking_run_id'=>$current?(int)$current['id']:null,'previous_ranking_run_id'=>$previous?(int)$previous['id']:null,'current_activated_at'=>$this->dates($current)['ranking_activated_at'],'previous_activated_at'=>$this->utc($previous['activated_at'] ?? null),'current_calculated_at'=>$this->utc($current['calculated_at'] ?? null),'previous_calculated_at'=>$this->utc($previous['calculated_at'] ?? null),'status'=>'unavailable','message'=>'Comparison unavailable.','items'=>array(),'new_fighter_ids'=>array(),'new_entries'=>array());
        if($current && $previous && ($current['formula_version']!==$previous['formula_version'] || ($current['data_scope_version']??'legacy_partial_v1')!==($previous['data_scope_version']??'legacy_partial_v1'))){$response['reason']='methodology_changed';$response['message']='Ranking methodology changed. Movers will be available after two comparable activations.';return $response;}
		if($current && $this->public->run_contains_ufc_signings((int)$current['id'])){$response['reason']='ufc_signing_pending_recalculation';$response['message']='Movement comparisons will return after the next ranking activation.';return $response;}
		if(!$current || !$previous || $current['formula_version']!==$previous['formula_version'] || $this->json_value($current['formula_config_snapshot'])!==$this->json_value($previous['formula_config_snapshot']) || ($current['data_scope_version'] ?? 'legacy_partial_v1')!==($previous['data_scope_version'] ?? 'legacy_partial_v1') || !in_array($board,$this->public->boards((int)$previous['id']),true)) { return $response; }
		$rows=$this->public->movers((int)$current['id'],(int)$previous['id'],$board,$limit); $this->prime(array_column($rows,'wp_post_id'));
		foreach($rows as $r) {

			if((int)$r['rank_change']<=0 || count($response['items'])>=max(1,min(10,$limit))) { continue; }
			$p=$this->profile_data($r['wp_post_id']); $response['items'][]=array('fighter_id'=>(int)$r['fighter_id'],'display_name'=>$r['display_name'],'current_rank'=>(int)$r['current_rank'],'previous_rank'=>(int)$r['previous_rank'],'rank_change'=>(int)$r['rank_change'],'permalink'=>$p['permalink'],'image'=>$p['image']);
		}
        $new=$this->public->new_entries((int)$current['id'],(int)$previous['id'],$board,$options);$this->prime(array_column($new['rows'],'wp_post_id'));
        foreach($new['rows'] as $r){$response['new_fighter_ids'][]=(int)$r['fighter_id'];$p=$this->profile_data($r['wp_post_id']);$response['new_entries'][]=['fighter_id'=>(int)$r['fighter_id'],'display_name'=>$r['display_name'],'current_rank'=>(int)$r['current_rank'],'previous_rank'=>null,'rank_change'=>null,'label'=>'New','permalink'=>$p['permalink']];}
        if(isset($options['new_page']) || isset($options['new_per_page'])){$response['new_total']=$new['total'];$response['new_page']=max(1,(int)($options['new_page']??1));$response['new_per_page']=min(100,max(1,(int)($options['new_per_page']??25)));$response['new_total_pages']=(int)ceil($new['total']/$response['new_per_page']);}
		$response['status']=$response['items']?'available':'unchanged'; $response['message']=$response['items']?null:'No upward movement between these activations.'; return $response;
	}

    public function fighter_cards(array $posts): array {
        $rows=$this->public->cards($posts);$this->prime(array_column($rows,'wp_post_id'));$out=[];$active=$this->read->active_ranking_run();$scoring=(new \MMAF\DataEngine\Services\Scoring\PublicAssessments())->for_fighters(array_column($rows,'id'),$active);$dates=$this->dates($active);
        $rankMap=$active?$this->public->rank_map((int)$active['id'],'overall'):[];
        foreach($rows as $r){$id=(int)$r['id'];$r['rank_position']=$rankMap[$id]??null;$fighter=$r;$fighter['id']=$id;$fighter['age']=$this->age($r['date_of_birth'],$r['birth_year']);$fighter['age_precision']=$r['date_of_birth']?'exact':($fighter['age']!==null?'approximate':'unknown');$stats=null;
            if($r['wins']!==null){$stats=[];foreach(['wins','losses','draws','nc','pro_fights_count'] as $key){$stats[$key]=(int)$r[$key];}}
            $out[(int)$r['wp_post_id']]=['fighter'=>$fighter,'profile'=>$this->profile_data($r['wp_post_id']),'stats'=>$stats,'recorded_stats'=>$stats,'career_coverage'=>['status'=>$r['coverage_status']??'unavailable'],'rankings'=>$r['rank_position']!==null?[['board'=>'overall','rank'=>(int)$r['rank_position']]]:[]];
            $scoreDto=$scoring[$id]??[];if(!empty($r['in_ufc'])){$scoreDto['scoring_assessment']=null;}
            $out[(int)$r['wp_post_id']]=array_merge($out[(int)$r['wp_post_id']],$scoreDto,['dates'=>$dates]);
        }return $out;
    }

	public function coverage( int $id ): array {
		$r=$this->public->coverage($id);
		return array('status'=>$r['status'] ?? 'unavailable','label'=>'complete'===($r['status'] ?? '')?'Complete professional MMA history':'Recorded fights','source'=>$r['source_type'] ?? null,'verified_at'=>$this->utc($r['verified_at'] ?? null),'accepted_bouts_count'=>isset($r['accepted_bouts_count'])?(int)$r['accepted_bouts_count']:null,'reason'=>$r['reason'] ?? 'Career coverage has not been verified.');
	}

	public function stats( ?array $s ): ?array {
		if(!$s) { return null; } $out=array();
		foreach(array('wins','losses','draws','nc','pro_fights_count','ko_tko_wins','submission_wins','decision_wins','finish_wins') as $k) { $out[$k]=(int)$s[$k]; }
		foreach(array('last_fight_date','streak','recent_form','activity_status') as $k) { $out[$k]=$s[$k]; }
		$out['finish_rate']=isset($s['finish_rate'])?(float)$s['finish_rate']:null; $den=$out['wins']+$out['losses']+$out['draws']; $out['win_rate']=$den?$out['wins']/$den:null; $out['calculated_at']=$this->utc($s['calculated_at']); return $out;
	}

	private function ranking( array $r, array $run ): array {
		$version=$r['formula_version'] ?? $run['formula_version'];
		$out=array('board'=>$r['board_key'],'rank'=>(int)$r['rank_position'],'score'=>$this->ranking_public_score($r,$version),'raw_score'=>$this->ranking_raw_public_score($r),'formula_version'=>$version,'sample_size'=>(int)$r['sample_size'],'active_ranking_run_id'=>(int)$r['ranking_run_id'],'breakdown'=>$this->json_value($r['breakdown_json'] ?? null),'quality_flags'=>$this->json_value($r['quality_flags_json'] ?? null),'warnings_count'=>count($this->json_value($r['warnings_json'] ?? null) ?? array()));
		$out['opponent_impact']=FormulaRegistry::uses_direct_scores($version) ? OpponentImpact::from_breakdown($out['breakdown'],$this->json_value($r['source_summary_json']??null)) : null;
		$out['scoring_finishes']=FormulaRegistry::uses_direct_scores($version) ? FinishSummary::from_breakdown($out['breakdown']) : null;
		if(FormulaRegistry::uses_normalized_scores($version)) { $out['performance_raw_score']=$this->ranking_performance_raw_public_score($r,$version); $out['confidence_score']=$this->ranking_confidence_public_score($r,$version); }
		return $out;
	}
	private function dates( ?array $run ): array { $h=$this->read->public_dates(); $s=$h; return array('ranking_calculated_at'=>$this->utc($run['calculated_at'] ?? null),'ranking_activated_at'=>$this->utc($h['latest_public_ranking_update'] ?? null),'stats_updated_at'=>$this->utc($s['latest_stats_rebuild_time'] ?? null),'data_scope_version'=>$run['data_scope_version'] ?? 'legacy_partial_v1')+\MMAF\DataEngine\Services\Scoring\PublicAssessments::methodology($run); }
	private function pagination( array $o, int $default ): array { $o['page']=max(1,(int)($o['page'] ?? 1)); $o['per_page']=max(1,min(100,(int)($o['per_page'] ?? $default))); return $o; }
	private function utc( ?string $value ): ?string { return $value?str_replace(' ','T',$value).'Z':null; }
	private function not_found(): \WP_Error { return new \WP_Error('mmaf_fighter_not_found','Fighter not found.',array('status'=>404)); }
	private function prime( array $ids ): void {
		$ids=array_values(array_unique(array_filter(array_map('intval',$ids)))); if(!$ids) { return; }
		_prime_post_caches($ids,false,true); $images=array(); foreach($ids as $id) { $image=(int)get_post_thumbnail_id($id); if($image) { $images[]=$image; } } if($images) { _prime_post_caches(array_unique($images),false,true); }
	}
}
