<?php
namespace MMAF\DataEngine\Services\Operations;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\RegularImport\{Repository,Transaction,Mutex,WriteGuard};
use MMAF\DataEngine\Repositories\{FighterStatsRepository,RankingRunRepository};
use MMAF\DataEngine\Services\{StatsRebuildService,RankingCalculatorService,AuditLogService};
use MMAF\DataEngine\Services\Formula\FormulaRegistry;

/** Bounded, crash-resumable full calculations. Live statistics are untouched until commit. */
final class Calculation {
    public static function create(string $kind,int $parent=0,int $actor=0,string $key='',?string $reference_date=null): int {
        if(!in_array($kind,['stats','ranking'],true)){throw new \InvalidArgumentException('Unsupported calculation.');}
        $reference_date=$reference_date??current_time('Y-m-d');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$reference_date);
        if(!$date || $date->format('Y-m-d')!==$reference_date){throw new \InvalidArgumentException('Reference date must be a valid YYYY-MM-DD date.');}
        $key=$key?:'calculation:'.$kind.':'.$parent.':'.($parent?'cohort':wp_generate_uuid4());
        if(strlen($key)>100){throw new \InvalidArgumentException('Calculation request key is too long.');}
        return Mutex::run(static function()use($kind,$parent,$actor,$key,$reference_date){
        global $wpdb; $t=Schema::tables();$f=Canonical::table_names()['fighters'];
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['jobs']} WHERE request_key=%s",$key));
        if($existing){$job=Jobs::get($existing);if($job['kind']!==$kind || (int)($job['checkpoint']['parent']??0)!==$parent){throw new \RuntimeException('Request key belongs to a different calculation.');}return $existing;}
        WriteGuard::assertAllowed(true);
        return Transaction::run(static function()use($kind,$parent,$actor,$key,$reference_date,$wpdb,$t,$f){
            $now=gmdate('Y-m-d H:i:s');$checkpoint=['cursor'=>0,'max_id'=>(int)$wpdb->get_var("SELECT MAX(id) FROM $f"),'input_revision'=>self::revision(),'reference_date'=>$reference_date,'calculated_at'=>$now,'rules'=>FormulaRegistry::current_version(),'config'=>FormulaRegistry::current_config(),'stats_generation'=>get_option('mmaf_stats_generation',0),'parent'=>$parent];
            Repository::check($wpdb->insert($t['jobs'],['request_key'=>$key,'kind'=>$kind,'action'=>'calculate','status'=>'running','phase'=>$kind==='stats'?'stats_rows':'score','actor_user_id'=>$actor,'selection_json'=>wp_json_encode(['parent'=>$parent]),'checkpoint_json'=>wp_json_encode($checkpoint),'created_at'=>$now,'updated_at'=>$now,'confirmed_at'=>$now]));return (int)$wpdb->insert_id;
        });
        });
    }
    /** Aggregate revision never reads source payloads or the complete population in PHP. */
    public static function revision(): string {
        return Revision::current();
    }
    public static function work(): array {
        global $wpdb;$table=Schema::tables()['jobs'];
        $id=(int)$wpdb->get_var("SELECT id FROM $table WHERE kind IN ('stats','ranking') AND status='running' ORDER BY id LIMIT 1");
        if(!$id){return ['worked'=>false];}$job=Jobs::get($id);
        if(!empty($job['checkpoint']['parent'])){return ['worked'=>false];}
        return ['worked'=>true,'operation'=>self::step($id)];
    }
    public static function step(int $id,int $limit=250): array {
        return Mutex::run(static function()use($id,$limit){
            $previous=WriteGuard::$calculation;WriteGuard::$calculation=$id;
            try{
                WriteGuard::assertAllowed();$job=Jobs::get($id);if(!$job){throw new \RuntimeException('Calculation not found.');}if($job['status']!=='running'){return $job;}
                if($job['retry_at'] && $job['retry_at']>gmdate('Y-m-d H:i:s')){return $job;}
                if($job['kind']==='ranking' && (int)$job['checkpoint']['stats_generation']!==(int)get_option('mmaf_stats_generation',0)){Jobs::save($id,['status'=>'failed','error_message'=>'Statistics generation changed during calculation.']);return Jobs::get($id);}
                $start=microtime(true);Jobs::save($id,['heartbeat_at'=>gmdate('Y-m-d H:i:s')]);
                try{
                    if(!hash_equals($job['checkpoint']['input_revision'],self::revision())){throw new \RuntimeException('Input data changed; discard this incomplete generation and prepare a new calculation.');}
                    if($job['checkpoint']['rules']!==FormulaRegistry::current_version() || wp_json_encode($job['checkpoint']['config'])!==wp_json_encode(FormulaRegistry::current_config())){throw new \RuntimeException('Formula configuration changed during calculation.');}
                    Transaction::run(static function()use($job,$limit){if($job['kind']==='stats'){self::stats($job,min(250,max(1,$limit)));}else{self::ranking($job,min(250,max(1,$limit)));}});
                }catch(\Throwable $e){
                    $attempt=(int)$job['attempts']+1;$changed=strpos($e->getMessage(),'changed')!==false;
                    Jobs::save($id,['attempts'=>$attempt,'status'=>$changed || $attempt>=3?'failed':'running','retry_at'=>gmdate('Y-m-d H:i:s',time()+($attempt===1?60:300)),'error_message'=>substr($e->getMessage(),0,255)]);
                }
                $current=Jobs::get($id);Jobs::save($id,['duration_ms'=>(int)$current['duration_ms']+(int)((microtime(true)-$start)*1000),'peak_memory'=>max((int)$current['peak_memory'],memory_get_peak_usage(true))]);return Jobs::get($id);
            }finally{WriteGuard::$calculation=$previous;}
        });
    }
    private static function stats(array $job,int $limit): void {
        global $wpdb;$t=Canonical::table_names();$o=Schema::tables();$id=(int)$job['id'];$cp=$job['checkpoint'];$summary=$job['summary'];$service=new StatsRebuildService();$repo=new FighterStatsRepository();
        if($job['phase']==='stats_rows'){
            $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['fighters']} WHERE id>%d AND id<=%d AND deleted_soft=0 ORDER BY id LIMIT %d",$cp['cursor'],$cp['max_id'],$limit));
            $generation=[];foreach($service->stats_rows($ids) as $row){$row['calculated_at']=$cp['calculated_at'];$row['created_at']=$cp['calculated_at'];$row['updated_at']=$cp['calculated_at'];$generation[]=['generation_id'=>$id,'fighter_id'=>$row['fighter_id'],'row_json'=>wp_json_encode($row)];}BulkWriter::insert($o['stats'],$generation,true);
            if($ids){$cp['cursor']=(int)end($ids);}$summary['stats_rows_written']=($summary['stats_rows_written']??0)+count($ids);
            if(count($ids)<$limit){$cp['cursor']=0;Jobs::save($id,['phase'=>'diagnostics','checkpoint'=>$cp,'summary'=>$summary]);}else{Jobs::save($id,['checkpoint'=>$cp,'summary'=>$summary]);}return;
        }
        if($job['phase']==='diagnostics'){
            $bouts=$repo->bout_batch((int)$cp['cursor'],$limit);$metrics=$service->diagnostics_batch($bouts);$summary=self::merge($summary,$metrics);
            if($bouts){$cp['cursor']=(int)end($bouts)['id'];}Jobs::save($id,['phase'=>count($bouts)<$limit?'publish_stats':'diagnostics','checkpoint'=>$cp,'summary'=>$summary]);return;
        }
        if($job['phase']==='publish_stats'){
            $expected=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['fighters']} WHERE deleted_soft=0");$actual=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o['stats']} WHERE generation_id=%d",$id));
            if($actual!==$expected || $actual!==(int)($summary['stats_rows_written']??0)){throw new \RuntimeException('Statistics generation row count mismatch.');}
            $orphan=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o['stats']} g LEFT JOIN {$t['fighters']} f ON f.id=g.fighter_id AND f.deleted_soft=0 WHERE generation_id=%d AND f.id IS NULL",$id));if($orphan){throw new \RuntimeException('Statistics generation contains an invalid fighter.');}
            $columns=$wpdb->get_col("SHOW COLUMNS FROM {$t['fighter_stats_current']}");$columns=array_values(array_diff($columns,['id']));
            foreach($columns as $col){if(!preg_match('/^[a-z_]+$/D',$col)){throw new \RuntimeException('Unexpected statistics column.');}}
            $values=array_map(static fn($col)=>"NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_json,'$.$col')),'null')",$columns);
            Repository::check($wpdb->query("DELETE FROM {$t['fighter_stats_current']}"));
            Repository::check($wpdb->query($wpdb->prepare("INSERT INTO {$t['fighter_stats_current']} (".implode(',',$columns).") SELECT ".implode(',',$values)." FROM {$o['stats']} WHERE generation_id=%d ORDER BY fighter_id",$id)));
            $summary+=['rebuilt_at'=>$cp['calculated_at'],'fighters_total'=>$expected,'generation_id'=>$id];$repo->set_last_rebuild_summary($summary);update_option('mmaf_stats_generation',$id,false);
            (new AuditLogService())->write('stats_rebuilt','system',0,null,$summary,'Validated private statistics generation',(int)$job['actor_user_id']);
            // Publishing all rows and its durable phase marker is one transaction.
            $cp['cursor']=0;Jobs::save($id,['phase'=>'coverage','checkpoint'=>$cp,'summary'=>$summary]);return;
        }
        if($job['phase']==='coverage'){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['fighter_career_coverage']} WHERE fighter_id>%d ORDER BY fighter_id LIMIT %d",$cp['cursor'],$limit),ARRAY_A);
            $career=new \MMAF\DataEngine\Services\RegularImport\CareerHistory();foreach($rows as $row){$career->refresh_record_coverage($row,(int)$job['actor_user_id']);$cp['cursor']=(int)$row['fighter_id'];}
            if(count($rows)<$limit){self::complete($job,$summary);Readiness::invalidate();Transaction::after_commit(static function(){do_action('mmaf_public_data_changed','stats');});}else{Jobs::save($id,['checkpoint'=>$cp]);}
        }
    }
    private static function ranking(array $job,int $limit): void {
        global $wpdb;$t=Canonical::table_names();$o=Schema::tables();$id=(int)$job['id'];$cp=$job['checkpoint'];$summary=$job['summary'];$service=new RankingCalculatorService();$runs=new RankingRunRepository();
        if(empty($cp['run_id'])){$cp['run_id']=$runs->create($cp['rules'],$cp['config'],$cp['reference_date'],$cp['calculated_at'],'draft','Resumable calculation #'.$id);}
        $run=(int)$cp['run_id'];
        if($job['phase']==='score'){
            $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['fighters']} WHERE id>%d AND id<=%d ORDER BY id LIMIT %d",$cp['cursor'],$cp['max_id'],$limit));
            $result=$service->score_batch($ids?$runs->list_fighters_with_stats($ids):[],$cp['reference_date'],$cp['calculated_at'],$run);$summary=self::merge($summary,$result['summary']);
            $candidates=[];foreach($result['rows'] as $item){$fid=(int)$item['fighter']['id'];$stats=$item['stats']??[];
                // Binding decimal strings to DOUBLE preserves the PHP float used by v1.5 sorting.
                $candidates[]=['operation_id'=>$id,'fighter_id'=>$fid,'score'=>sprintf('%.17g',(float)$item['total_score']),'wins'=>(int)($stats['wins']??0),'finish_rate'=>sprintf('%.17g',(float)($stats['finish_rate']??0)),'age'=>(int)($item['eligibility']['age']??999),'last_fight_date'=>(string)($stats['last_fight_date']??''),'item_json'=>wp_json_encode($item)];
            }
            BulkWriter::insert($o['scores'],$candidates);
            if($ids){$cp['cursor']=(int)end($ids);}if(count($ids)<$limit){$cp['cursor']=0;$cp['offset']=0;$cp['positions']=array_fill_keys($service::boards(),0);Jobs::save($id,['phase'=>'order_scores','checkpoint'=>$cp,'summary'=>$summary]);}else{Jobs::save($id,['checkpoint'=>$cp,'summary'=>$summary]);}return;
        }
        if($job['phase']==='order_scores'){
            Repository::check($wpdb->query($wpdb->prepare("UPDATE {$o['scores']} s JOIN (SELECT fighter_id,ROW_NUMBER() OVER (ORDER BY score DESC,wins DESC,finish_rate DESC,age ASC,last_fight_date DESC,fighter_id ASC) position FROM {$o['scores']} WHERE operation_id=%d) ordered ON ordered.fighter_id=s.fighter_id SET s.sort_position=ordered.position WHERE s.operation_id=%d",$id,$id)));
            $cp['cursor']=0;Jobs::save($id,['phase'=>'snapshots','checkpoint'=>$cp]);return;
        }
        if($job['phase']==='snapshots'){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT item_json,sort_position FROM {$o['scores']} WHERE operation_id=%d AND sort_position>%d ORDER BY sort_position LIMIT %d",$id,$cp['cursor'],$limit),ARRAY_A);
            $snapshots=[];foreach($rows as $row){$item=json_decode($row['item_json'],true,512,JSON_THROW_ON_ERROR);foreach($service::boards() as $board){if(!$service->item_matches_board($item,$board)){continue;}$snapshot=$service->snapshot_row_for_item($item,$board,++$cp['positions'][$board]);$snapshot['ranking_run_id']=$run;$snapshot['created_at']=$cp['calculated_at'];$snapshots[]=$snapshot;$summary['ranked_rows']=($summary['ranked_rows']??0)+1;}}
            BulkWriter::insert($t['ranking_snapshots'],$snapshots);
            $cp['offset']+=count($rows);if($rows){$cp['cursor']=(int)end($rows)['sort_position'];}Jobs::save($id,['phase'=>count($rows)<$limit?'validate_ranking':'snapshots','checkpoint'=>$cp,'summary'=>$summary]);return;
        }
        if($job['phase']==='validate_ranking'){
            $summary['ranked_rows']=(int)($summary['ranked_rows']??0);
            $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d",$run));if($count!==$summary['ranked_rows']){throw new \RuntimeException('Ranking snapshot count mismatch.');}
            $groups=$wpdb->get_results($wpdb->prepare("SELECT board_key,COUNT(*) n,COUNT(DISTINCT fighter_id) fighters,COUNT(DISTINCT rank_position) positions,MIN(rank_position) first_rank,MAX(rank_position) last_rank FROM {$t['ranking_snapshots']} WHERE ranking_run_id=%d GROUP BY board_key",$run),ARRAY_A);
            foreach($groups as $g){if((int)$g['n']!==(int)$g['fighters'] || (int)$g['n']!==(int)$g['positions'] || (int)$g['first_rank']!==1 || (int)$g['last_rank']!==(int)$g['n']){throw new \RuntimeException('Ranking board integrity check failed.');}}
            $summary['status']='completed';$summary['ranking_run_id']=$run;$summary['calculated_at']=$cp['calculated_at'];$summary['formula_version']=$cp['rules'];$summary['reference_date']=$cp['reference_date'];$summary['is_active']=0;$summary['stats_generation']=$cp['stats_generation'];
            $runs->update($run,['status'=>'completed','notes'=>wp_json_encode($summary)]);$runs->set_last_calculation_summary($summary);
            (new AuditLogService())->write('ranking_calculated','system',$run,null,$summary,'Resumable bounded draft calculation',(int)$job['actor_user_id']);self::complete($job,$summary);
        }
    }
    private static function merge(array $a,array $b): array {
        $add=['stats_rows_written','warnings_count','countable_bouts','skipped_bouts','malformed_bouts','participants_processed','eligible_fighters','ineligible_fighters','calculated_fighters','trusted_ranked_fighters','excluded_insufficient_sample','missing_prefight_count'];
        foreach($b as $key=>$value){if(in_array($key,$add,true)){$a[$key]=($a[$key]??0)+(int)$value;}elseif(in_array($key,['warnings','eligibility_reasons','structural_warnings'],true)){foreach($value as $reason=>$n){$a[$key][$reason]=($a[$key][$reason]??0)+$n;}}elseif($key!=='ranked_rows'){$a[$key]=$value;}}
        return $a;
    }
    private static function complete(array $job,array $summary): void {Jobs::save((int)$job['id'],['status'=>'completed','phase'=>'done','summary'=>$summary,'finished_at'=>gmdate('Y-m-d H:i:s'),'error_message'=>null]);}
}
