<?php
namespace MMAF\DataEngine\Services\Scoring;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Repositories\RankingRunRepository;
use MMAF\DataEngine\Services\RegularImport\CareerHistory;

/** Read-only rollout tools. Source fetch, import and activation remain separate operations. */
final class Command {
    public static function register(): void {
        if(defined('WP_CLI') && WP_CLI){\WP_CLI::add_command('mmaf scoring',self::class);}
    }
    public function candidates(array $args,array $options): void {
        global $wpdb;$t=Canonical::table_names();$manifest=CareerHistory::manifest();$targets=$manifest['targets'];
        if(isset($options['pilot'])){
            $limit=min(100,max(1,(int)$options['pilot']));$active=(new RankingRunRepository())->get_active_ranking_run_id();
            $ids=$wpdb->get_col($wpdb->prepare("SELECT r.fighter_id FROM {$t['ranking_snapshots']} r JOIN {$t['fighters']} f ON f.id=r.fighter_id WHERE r.ranking_run_id=%d AND r.board_key='overall' AND f.is_public=1 AND f.deleted_soft=0 ORDER BY r.rank_position LIMIT %d",$active,$limit));
            $conflicts=$wpdb->get_col($wpdb->prepare("SELECT r.fighter_id FROM {$t['ranking_snapshots']} r JOIN {$t['fighter_career_coverage']} c ON c.fighter_id=r.fighter_id WHERE r.ranking_run_id=%d AND r.board_key='overall' AND c.status='conflict' ORDER BY r.rank_position",$active));
            $ids=array_map('intval',array_unique(array_merge($ids,$conflicts)));
            $targets=array_values(array_filter($targets,static fn($target)=>in_array((int)$target['fighter_id'],$ids,true)));
        }
        $items=[];
        foreach(array_chunk($targets,100) as $batch){
            $ids=array_map('intval',array_column($batch,'fighter_id'));$records=(new CareerRecords())->for_fighters($ids);$known=[];
            $in=implode(',',$ids);
            foreach($wpdb->get_results("SELECT p.fighter_id,bs.source_bout_id,e.event_date,p.result_for_fighter FROM {$t['bout_participants']} p JOIN {$t['bouts']} b ON b.id=p.bout_id JOIN {$t['events']} e ON e.id=b.event_id LEFT JOIN {$t['bout_sources']} bs ON bs.bout_id=b.id AND bs.source_type='tapology' WHERE p.fighter_id IN ($in) AND b.deleted_soft=0 AND e.deleted_soft=0 AND e.event_date>='2026-05-09' AND b.status IN ('valid','completed') ORDER BY p.fighter_id,e.event_date DESC,p.bout_id",ARRAY_A) as $row){$known[(int)$row['fighter_id']][]=['source_bout_id'=>$row['source_bout_id'],'fight_date'=>$row['event_date'],'result'=>$row['result_for_fighter']];}
            if($wpdb->last_error){throw new \RuntimeException('Cannot export known candidate results.');}
            foreach($batch as $target){$id=(int)$target['fighter_id'];$record=$records[$id]??null;$items[]=$target+['action'=>'refresh_source_evidence','force_refresh'=>isset($options['pilot']),'fetch_scope'=>($record['status']??'')==='accepted' && $record['record']['pro_fights_count']>30?'career_record':'career_and_period','career_record'=>$record,'known_results'=>$known[$id]??[],'reasons'=>['Verify professional MMA career facts first, then the scoring period for eligible candidates.']];}
        }
        $value=['schema_version'=>'mmaf_source_enrichment_v1','contract_version'=>1,'generated_at'=>gmdate('c'),'period_start'=>'2026-05-09','max_verification_age_days'=>7,'scope'=>isset($options['pilot'])?'top_ranked_plus_recorded_conflicts':'public_or_rankable','target_count'=>count($items),'excluded_identity_review'=>$manifest['excluded_identity_review'],'items'=>$items];
        $this->output($value,$options);
    }
    public function report(array $args,array $options): void {
        $id=(int)($options['run-id']??0);$value=self::report_run($id);$this->output($value,$options);
    }
    public static function report_run(int $id): array {
        global $wpdb;$run=(new RankingRunRepository())->find($id);
        if(!$run){throw new \InvalidArgumentException('A saved ranking run is required.');}
        $t=Schema::tables();$c=Canonical::table_names();$counts=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) fighters,SUM(sample_size) scoring_bouts FROM {$t['assessments']} WHERE ranking_run_id=%d GROUP BY status ORDER BY status",$id),ARRAY_A);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT a.fighter_id,f.display_name,a.status,a.total_score,a.sample_size,a.verified_at,a.assessment_json,r.rank_position FROM {$t['assessments']} a JOIN {$c['fighters']} f ON f.id=a.fighter_id LEFT JOIN {$c['ranking_snapshots']} r ON r.ranking_run_id=a.ranking_run_id AND r.fighter_id=a.fighter_id AND r.board_key='overall' WHERE a.ranking_run_id=%d AND (f.is_public=1 OR f.is_rankable=1) ORDER BY a.status,a.total_score DESC,a.fighter_id",$id),ARRAY_A);
        if($wpdb->last_error){throw new \RuntimeException('Cannot read saved assessment report.');}
        $reasons=[];$items=[];
        foreach($rows as $row){$a=json_decode($row['assessment_json'],true,512,JSON_THROW_ON_ERROR);unset($row['assessment_json']);$row['reasons']=$a['reasons'];$row['warnings']=$a['warnings'];$row['coverage']=$a['coverage'];$row['period']=$a['period'];$row['next_tasks']=array_map([Admin::class,'task'],$a['reasons']);$items[]=$row;foreach($a['reasons'] as $reason){$reasons[$reason]=($reasons[$reason]??0)+1;}}
        return ['run'=>array_intersect_key($run,array_flip(['id','formula_version','reference_date','calculated_at','is_active','status','data_scope_version','formula_config_snapshot'])),'counts'=>$counts,'public_or_rankable_reasons'=>$reasons,'items'=>$items,'activation'=>'Manual review and activation required.'];
    }
    private function output(array $value,array $options): void {
        if(!empty($options['output'])){
            $path=(string)$options['output'];$parent=realpath(dirname($path));$public=realpath(ABSPATH);
            if(!$parent || !$public || str_starts_with($parent,$public) || is_link($path)){throw new \InvalidArgumentException('Write rollout evidence to an existing private directory outside the public WordPress root.');}
            if(file_put_contents($path,wp_json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))===false || !chmod($path,0600)){throw new \RuntimeException('Cannot save private rollout evidence.');}
            \WP_CLI::line(wp_json_encode(['output'=>$path,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'target_count'=>$value['target_count']??null]));return;
        }
        \WP_CLI::line(wp_json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    }
}
