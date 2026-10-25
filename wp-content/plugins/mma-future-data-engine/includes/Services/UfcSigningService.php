<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;

final class UfcSigningService {
    private array $tables;
    public function __construct(){ $this->tables=Schema::table_names(); }

    public static function normalize_name(string $name): string {
        return UfcMatcher::normalize($name);
    }

    public function validate_document(array $doc): array {
        $version=(string)($doc['schema_version']??'');
        if(!in_array($version,['mmaf_ufc_roster_v1','mmaf_ufc_roster_v2'],true) || ($doc['source']??'')!=='roster.watch'){
            throw new \RuntimeException('Unsupported UFC roster document.');
        }
        if(empty($doc['run_id']) || empty($doc['scraped_at']) || !is_array($doc['fighters']??null)){
            throw new \RuntimeException('UFC roster metadata is incomplete.');
        }
		if(strlen((string)$doc['run_id'])>100 || strtotime((string)$doc['scraped_at'])===false){throw new \RuntimeException('UFC roster run metadata is invalid.');}
		foreach(['current','former','log'] as $page){if(!filter_var($doc['source_urls'][$page]??'',FILTER_VALIDATE_URL)){throw new \RuntimeException('UFC roster source page metadata is incomplete.');}}
		foreach(['current','former','log_events','fighters'] as $count){if((int)($doc['counts'][$count]??0)<1){throw new \RuntimeException('UFC roster page validation counts are incomplete.');}}
		if((int)$doc['counts']['fighters']!==count($doc['fighters'])){throw new \RuntimeException('UFC roster fighter count is inconsistent.');}
        $expected=(string)($doc['content_sha256']??'');$copy=$doc;unset($copy['content_sha256']);
        $actual=hash('sha256',wp_json_encode($copy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        if(!$expected || !hash_equals($expected,$actual)){throw new \RuntimeException('UFC roster content checksum is invalid.');}
        $seen=[];
        $statuses=['current'=>0,'former'=>0];
        foreach($doc['fighters'] as $row){
            $key=(string)($row['source_key']??'');$name=trim((string)($row['display_name']??''));
			if(!$key||strlen($key)>191||!$name||mb_strlen($name)>255||isset($seen[$key])||!in_array($row['roster_status']??'',['current','former','log_only'],true)||($version==='mmaf_ufc_roster_v1' && $row['roster_status']==='log_only')){throw new \RuntimeException('UFC roster contains an invalid or duplicate fighter.');}
			if($version==='mmaf_ufc_roster_v2' && !filter_var($row['source_url']??'',FILTER_VALIDATE_URL)){throw new \RuntimeException('UFC fighter source URL is missing.');}
			foreach(($row['events']??[]) as $event){if(!in_array($event['action']??'',['added','removed','unknown'],true) || ($version==='mmaf_ufc_roster_v2' && !filter_var($event['source_url']??'',FILTER_VALIDATE_URL))){throw new \RuntimeException('UFC roster change event is incomplete.');}}
			$seen[$key]=true;if(isset($statuses[$row['roster_status']])){++$statuses[$row['roster_status']];}
        }
        if(!$statuses['current']||!$statuses['former']){throw new \RuntimeException('UFC roster does not contain both current and former roster records.');}
        // Verify the original v1 bytes before interpreting its historical log-only rows.
        if($version==='mmaf_ufc_roster_v1'){
            foreach($doc['fighters'] as &$row){if(($row['roster_status']??'')==='former' && empty($row['source_cells']) && !empty($row['events'])){
                $row['roster_status']='log_only';$row['source_url']=$row['source_url']?:($doc['source_urls']['log']??null);
                foreach($row['events'] as &$event){$event['source_url']??=$row['source_url'];}unset($event);
            }}unset($row);
        }
        return $doc;
    }

    public function import_document(array $doc,int $actor=0,int $job_id=0): array {
        global $wpdb;$original=$doc;$doc=$this->validate_document($doc);$now=gmdate('Y-m-d H:i:s');$observed=gmdate('Y-m-d H:i:s',strtotime($doc['scraped_at']));
        $last=get_option('mmaf_ufc_roster_last_import',[]);
        if(!empty($last['scraped_at']) && strtotime($doc['scraped_at'])<strtotime($last['scraped_at'])){throw new \RuntimeException('An older UFC snapshot cannot replace a newer one.');}
        $stored=$wpdb->get_row($wpdb->prepare("SELECT content_sha256 FROM {$this->tables['ufc_roster_runs']} WHERE run_id=%s",$doc['run_id']),ARRAY_A);
        if($stored && !hash_equals($stored['content_sha256'],$original['content_sha256'])){throw new \RuntimeException('UFC run ID was reused with different evidence.');}
        if(!$stored && false===$wpdb->insert($this->tables['ufc_roster_runs'],['run_id'=>$doc['run_id'],'content_sha256'=>$original['content_sha256'],'document_json'=>wp_json_encode($original,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'scraped_at'=>$observed,'imported_at'=>$now,'job_id'=>$job_id])){throw new \RuntimeException($wpdb->last_error);}
        $counts=['observations'=>0,'candidates_created'=>0,'candidates_updated'=>0,'reopened'=>0,'preserved_decisions'=>0,'already_confirmed'=>0,'unmatched'=>0];
		$observationIds=[];foreach($wpdb->get_results("SELECT id,source_key FROM {$this->tables['ufc_roster_observations']}",ARRAY_A)?:[] as $saved){$observationIds[$saved['source_key']]=(int)$saved['id'];}
		$matchIndex=$this->matcher_index();
        foreach($doc['fighters'] as $row){
            $key=(string)$row['source_key'];$normalized=self::normalize_name((string)$row['display_name']);
            $data=['source_key'=>$key,'display_name'=>(string)$row['display_name'],'normalized_name'=>$normalized,'source_url'=>esc_url_raw((string)($row['source_url']??''))?:null,'date_of_birth'=>$this->date($row['date_of_birth']??null),'gender'=>sanitize_key((string)($row['gender']??''))?:null,'weight_class'=>sanitize_text_field((string)($row['weight_class']??''))?:null,'roster_status'=>(string)$row['roster_status'],'events_json'=>wp_json_encode($row['events']??[]),'evidence_json'=>wp_json_encode($row),'content_hash'=>hash('sha256',wp_json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'last_run_id'=>(string)$doc['run_id'],'observed_at'=>$observed,'updated_at'=>$now];
			$id=$observationIds[$key]??0;
            if($id){if(false===$wpdb->update($this->tables['ufc_roster_observations'],$data,['id'=>$id])){throw new \RuntimeException($wpdb->last_error);}}
			else{$data['created_at']=$now;if(false===$wpdb->insert($this->tables['ufc_roster_observations'],$data)){throw new \RuntimeException($wpdb->last_error);}$id=(int)$wpdb->insert_id;$observationIds[$key]=$id;}
			++$counts['observations'];$matches=UfcMatcher::match($row,$matchIndex);
            if(!$matches){++$counts['unmatched'];continue;}
            foreach($matches as $match){
				$this->upsert_candidate($id,$row,$match,$counts,$now);
            }
        }
        update_option('mmaf_ufc_roster_last_import',['run_id'=>$doc['run_id'],'scraped_at'=>$doc['scraped_at'],'imported_at'=>gmdate('c'),'job_id'=>$job_id,'counts'=>$counts],false);
        $counts['compare_job_id']=$this->enqueue_compare('ufc_import');
        (new AuditLogService())->write('ufc_roster_imported','system',0,null,['run_id'=>$doc['run_id'],'counts'=>$counts],'Roster.watch evidence imported; no signing was confirmed.',$actor);
        return $counts;
    }

    public static function evidence_hash(array $row): string {
        $events=[];foreach(($row['events']??[]) as $event){$events[]=[(string)($event['date']??''),(string)($event['action']??''),(string)($event['text']??''),(string)($event['source_url']??'')];}
        return hash('sha256',wp_json_encode([$row['source_key']??'',$row['display_name']??'',$row['source_url']??'',$row['date_of_birth']??'',$row['gender']??'',$row['weight_class']??'',$row['roster_status']??'',$events],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private function matcher_index(): array {
        global $wpdb;
        $fighters=$wpdb->get_results("SELECT id,display_name,date_of_birth,gender,weight_class,in_ufc,deleted_soft,is_public,is_rankable FROM {$this->tables['fighters']} WHERE deleted_soft=0",ARRAY_A)?:[];
        $aliases=$wpdb->get_results("SELECT fighter_id,alias FROM {$this->tables['fighter_aliases']}",ARRAY_A)?:[];
        $links=$wpdb->get_results("SELECT source_key,fighter_id,status FROM {$this->tables['ufc_identity_links']}",ARRAY_A)?:[];
        return UfcMatcher::build_index($fighters,$aliases,$links);
    }

    private function upsert_candidate(int $observation_id,array $source,array $match,array &$counts,string $now): void {
        global $wpdb;$fighter_id=(int)$match['fighter_id'];$hash=self::evidence_hash($source);
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['ufc_signing_candidates']} WHERE observation_id=%d AND fighter_id=%d",$observation_id,$fighter_id),ARRAY_A);
        if(!$existing && !empty($match['already_confirmed'])){++$counts['already_confirmed'];return;}
        $data=['match_reason'=>$match['reason'],'conflicts_json'=>wp_json_encode($match['conflicts']),'signals_json'=>wp_json_encode($match['signals']??[]),'evidence_hash'=>$hash,'fighter_hash'=>$match['fighter_hash'],'matcher_version'=>UfcMatcher::VERSION,'last_checked_at'=>$now,'updated_at'=>$now];
        if(!$existing){$data+=['observation_id'=>$observation_id,'fighter_id'=>$fighter_id,'status'=>'pending','created_at'=>$now];if(false===$wpdb->insert($this->tables['ufc_signing_candidates'],$data)){throw new \RuntimeException($wpdb->last_error);}++$counts['candidates_created'];return;}
        if($existing['status']==='insufficient_evidence' && !hash_equals((string)$existing['decision_evidence_hash'],$hash)){$data['status']='pending';$data['resolved_by']=null;$data['resolved_at']=null;++$counts['reopened'];}
        elseif(in_array($existing['status'],['rejected','confirmed','fighter_confirmed','insufficient_evidence','deferred'],true)){++$counts['preserved_decisions'];}
        if(false===$wpdb->update($this->tables['ufc_signing_candidates'],$data,['id'=>(int)$existing['id']])){throw new \RuntimeException($wpdb->last_error);}++$counts['candidates_updated'];
    }

    public function preview_document(array $document): array {
        $doc=$this->validate_document($document);$index=$this->matcher_index();$counts=['new'=>0,'updated'=>0,'conflicts'=>0,'preserved_rejections'=>0,'already_confirmed'=>0,'unmatched'=>0,'candidates'=>[],'unmatched_rows'=>[]];global $wpdb;
        $priorByKey=[];foreach($wpdb->get_results("SELECT o.source_key,c.fighter_id,c.status FROM {$this->tables['ufc_signing_candidates']} c JOIN {$this->tables['ufc_roster_observations']} o ON o.id=c.observation_id",ARRAY_A)?:[] as $prior){$priorByKey[$prior['source_key']][(int)$prior['fighter_id']]=$prior['status'];}
        foreach($doc['fighters'] as $row){$saved=$priorByKey[$row['source_key']]??[];$counts['preserved_rejections']+=count(array_filter($saved,static fn($status)=>$status==='rejected'));
            $matches=UfcMatcher::match($row,$index);if(!$matches){++$counts['unmatched'];if(count($counts['unmatched_rows'])<50){$counts['unmatched_rows'][]=['source_key'=>$row['source_key'],'name'=>$row['display_name'],'source_url'=>$row['source_url']??null];}continue;}
            foreach($matches as $match){if($match['conflicts']){++$counts['conflicts'];}if($match['already_confirmed']){++$counts['already_confirmed'];if(count($counts['candidates'])<50){$counts['candidates'][]=['source_key'=>$row['source_key'],'source_name'=>$row['display_name'],'fighter_id'=>$match['fighter_id'],'reason'=>$match['reason'],'conflicts'=>$match['conflicts'],'status'=>'already_confirmed'];}continue;}
                $prior=$saved[$match['fighter_id']]??null;
                if(!$prior){++$counts['new'];}elseif($prior!=='rejected'){++$counts['updated'];}
                if(count($counts['candidates'])<50){$counts['candidates'][]=['source_key'=>$row['source_key'],'source_name'=>$row['display_name'],'fighter_id'=>$match['fighter_id'],'reason'=>$match['reason'],'conflicts'=>$match['conflicts'],'status'=>$prior??'new'];}
            }
        }return $counts;
    }

    public function enqueue_compare(string $reason='manual',int $fighter_id=0): int {
        global $wpdb;$last=get_option('mmaf_ufc_roster_last_import',[]);$run=(string)($last['run_id']??'');
        if(!$run){$run=(string)$wpdb->get_var("SELECT run_id FROM {$this->tables['ufc_roster_runs']} ORDER BY scraped_at DESC LIMIT 1");}
        if(!$run){throw new \RuntimeException('Import a UFC roster before comparing fighters.');}
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['ufc_match_jobs']} WHERE status IN ('queued','running') AND fighter_id=%d ORDER BY id DESC LIMIT 1",$fighter_id));
        if($existing){$wpdb->update($this->tables['ufc_match_jobs'],['status'=>'queued','cursor_id'=>0,'processed'=>0,'source_run_id'=>$run,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>(int)$existing]);return (int)$existing;}
        $now=gmdate('Y-m-d H:i:s');
        if(false===$wpdb->insert($this->tables['ufc_match_jobs'],['status'=>'queued','reason'=>$reason,'fighter_id'=>$fighter_id,'cursor_id'=>0,'processed'=>0,'source_run_id'=>$run,'created_at'=>$now,'updated_at'=>$now])){throw new \RuntimeException($wpdb->last_error);}
        return (int)$wpdb->insert_id;
    }

    public function compare_saved(int $fighter_id=0,int $limit=0): array {
        global $wpdb;$index=$this->matcher_index();$out=['observations'=>0,'matches'=>0,'new'=>0,'existing'=>0,'unmatched'=>0,'conflicts'=>0,'already_confirmed'=>0];
        $sql="SELECT o.* FROM {$this->tables['ufc_roster_observations']} o ORDER BY o.id".($limit>0?' LIMIT '.(int)$limit:'');
        foreach($wpdb->get_results($sql,ARRAY_A)?:[] as $observation){$source=json_decode((string)$observation['evidence_json'],true);if(!is_array($source)){continue;}
            $matches=UfcMatcher::match($source,$index);if($fighter_id){$matches=array_values(array_filter($matches,static fn($m)=>$m['fighter_id']===$fighter_id));}
            ++$out['observations'];if(!$matches){++$out['unmatched'];continue;}
            foreach($matches as $match){++$out['matches'];if($match['conflicts']){++$out['conflicts'];}if($match['already_confirmed']){++$out['already_confirmed'];}
                $prior=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['ufc_signing_candidates']} WHERE observation_id=%d AND fighter_id=%d",$observation['id'],$match['fighter_id']));++$out[$prior?'existing':'new'];
            }
        }return $out;
    }

    public function work_compare(): array {
        global $wpdb;
        return \MMAF\DataEngine\Services\RegularImport\Transaction::run(function()use($wpdb){
            $job=$wpdb->get_row("SELECT * FROM {$this->tables['ufc_match_jobs']} WHERE status IN ('queued','running') ORDER BY id LIMIT 1 FOR UPDATE",ARRAY_A);
            if(!$job){return ['worked'=>false];}
            $index=$this->matcher_index();$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['ufc_roster_observations']} WHERE id>%d ORDER BY id LIMIT 100",(int)$job['cursor_id']),ARRAY_A)?:[];
            $counts=['candidates_created'=>0,'candidates_updated'=>0,'reopened'=>0,'preserved_decisions'=>0,'already_confirmed'=>0];$cursor=(int)$job['cursor_id'];$now=gmdate('Y-m-d H:i:s');
            foreach($rows as $observation){$cursor=(int)$observation['id'];$source=json_decode((string)$observation['evidence_json'],true);if(!is_array($source)){continue;}
                foreach(UfcMatcher::match($source,$index) as $match){if((int)$job['fighter_id'] && (int)$job['fighter_id']!==$match['fighter_id']){continue;}$this->upsert_candidate($cursor,$source,$match,$counts,$now);}
            }
            $done=count($rows)<100;$wpdb->update($this->tables['ufc_match_jobs'],['status'=>$done?'completed':'running','cursor_id'=>$cursor,'processed'=>(int)$job['processed']+count($rows),'updated_at'=>$now,'finished_at'=>$done?$now:null],['id'=>(int)$job['id']]);
            return ['worked'=>true,'job_id'=>(int)$job['id'],'done'=>$done,'processed'=>count($rows),'counts'=>$counts];
        });
    }

    public function status(): array {
        global $wpdb;$job=$wpdb->get_row("SELECT * FROM {$this->tables['ufc_match_jobs']} ORDER BY id DESC LIMIT 1",ARRAY_A);$last=get_option('mmaf_ufc_roster_last_import',null);
        $counts=$wpdb->get_results("SELECT status,COUNT(*) total FROM {$this->tables['ufc_signing_candidates']} GROUP BY status",ARRAY_A)?:[];
        return ['site_url'=>home_url('/'),'database'=>defined('DB_NAME')?DB_NAME:null,'last_import'=>$last,'snapshot_stale'=>!$last||!strtotime((string)($last['scraped_at']??''))||strtotime($last['scraped_at'])<time()-48*3600,'compare_job'=>$job?:null,'candidate_counts'=>$counts,'pending_repairs'=>count((array)get_option('mmaf_ufc_post_commit_repairs',[])),'intl_available'=>function_exists('transliterator_transliterate'),'matcher_version'=>UfcMatcher::VERSION];
    }

    public function candidates(string $status='pending'): array {
		global $wpdb;$where=$status==='all'?'1=1':($status==='open'?"c.status IN ('pending','deferred')":$wpdb->prepare('c.status=%s',$status));
        return $wpdb->get_results("SELECT c.*,o.display_name source_name,o.source_url,o.date_of_birth source_dob,o.gender source_gender,o.weight_class source_weight_class,o.roster_status,o.observed_at,o.source_key,o.events_json,f.display_name fighter_name,f.date_of_birth fighter_dob FROM {$this->tables['ufc_signing_candidates']} c JOIN {$this->tables['ufc_roster_observations']} o ON o.id=c.observation_id JOIN {$this->tables['fighters']} f ON f.id=c.fighter_id WHERE $where ORDER BY c.created_at DESC,c.id DESC LIMIT 500",ARRAY_A)?:[];
    }
    public function candidate_page(string $status='open',string $search='',int $page=1): array {
        global $wpdb;$page=max(1,$page);$allowed=['open','pending','deferred','rejected','insufficient_evidence','confirmed','all'];if(!in_array($status,$allowed,true)){$status='open';}
        $where=$status==='all'?'1=1':($status==='open'?"c.status IN ('pending','deferred')":$wpdb->prepare('c.status=%s',$status));
        if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where.=$wpdb->prepare(' AND (f.display_name LIKE %s OR o.display_name LIKE %s OR o.source_key LIKE %s'.(ctype_digit($search)?' OR c.fighter_id=%d':'').')',...array_merge([$like,$like,$like],ctype_digit($search)?[(int)$search]:[]));}
        $from="FROM {$this->tables['ufc_signing_candidates']} c JOIN {$this->tables['ufc_roster_observations']} o ON o.id=c.observation_id JOIN {$this->tables['fighters']} f ON f.id=c.fighter_id LEFT JOIN {$this->tables['ranking_current']} rc ON rc.fighter_id=f.id";
        $total=(int)$wpdb->get_var("SELECT COUNT(DISTINCT c.id) $from WHERE $where");$offset=($page-1)*50;
        $rows=$wpdb->get_results("SELECT DISTINCT c.*,o.display_name source_name,o.source_url,o.date_of_birth source_dob,o.gender source_gender,o.weight_class source_weight_class,o.roster_status,o.observed_at,o.source_key,o.events_json,f.display_name fighter_name,f.date_of_birth fighter_dob,f.gender fighter_gender,f.weight_class fighter_weight_class,f.is_public fighter_public,f.wp_post_id,CASE WHEN rc.fighter_id IS NULL THEN 0 ELSE 1 END ranked,CASE WHEN o.events_json LIKE '%\"added\"%' THEN 1 ELSE 0 END fresh_added $from WHERE $where ORDER BY ranked DESC,f.is_public DESC,fresh_added DESC,c.updated_at DESC,c.id DESC LIMIT 50 OFFSET $offset",ARRAY_A)?:[];
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/50)];
    }
    public function unmatched_count(): int {
        global $wpdb;return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['ufc_roster_observations']} o WHERE NOT EXISTS (SELECT 1 FROM {$this->tables['ufc_signing_candidates']} c WHERE c.observation_id=o.id)");
    }
    public function aliases_for(int $fighter_id): array {
        global $wpdb;return $wpdb->get_col($wpdb->prepare("SELECT alias FROM {$this->tables['fighter_aliases']} WHERE fighter_id=%d ORDER BY alias",$fighter_id))?:[];
    }
    public function decision_history(int $candidate_id): array {
        global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT action,note,source_run_id,actor_user_id,created_at FROM {$this->tables['ufc_candidate_decisions']} WHERE candidate_id=%d ORDER BY id DESC LIMIT 10",$candidate_id),ARRAY_A)?:[];
    }
    public function unmatched(): array {
        global $wpdb;return $wpdb->get_results("SELECT o.* FROM {$this->tables['ufc_roster_observations']} o WHERE NOT EXISTS (SELECT 1 FROM {$this->tables['ufc_signing_candidates']} c WHERE c.observation_id=o.id) ORDER BY o.observed_at DESC,o.display_name LIMIT 500",ARRAY_A)?:[];
    }
    public function signing(int $fighter_id): ?array {
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['ufc_signing_events']} WHERE fighter_id=%d AND is_active=1 ORDER BY id DESC LIMIT 1",$fighter_id),ARRAY_A);return $row?:null;
    }

    public function resolve_candidate(int $candidate_id,string $action,int $actor,string $note='',string $expected_evidence='',string $expected_fighter='',string $extra_url=''): void {
		global $wpdb;if(!in_array($action,['reject','defer','confirm','insufficient_evidence'],true)){throw new \InvalidArgumentException('Unknown UFC review action.');}
        if(trim($note)===''){throw new \InvalidArgumentException('A decision note is required.');}
		$row=\MMAF\DataEngine\Services\RegularImport\Transaction::run(function()use($candidate_id,$action,$actor,$note,$expected_evidence,$expected_fighter,$extra_url,$wpdb){
            $row=$wpdb->get_row($wpdb->prepare("SELECT c.*,o.source_url,o.source_key,o.display_name source_name,o.evidence_json source_evidence_json,o.last_run_id source_run_id FROM {$this->tables['ufc_signing_candidates']} c JOIN {$this->tables['ufc_roster_observations']} o ON o.id=c.observation_id WHERE c.id=%d FOR UPDATE",$candidate_id),ARRAY_A);
            if(!$row){throw new \RuntimeException('UFC candidate was not found.');}
			if(!$expected_evidence||!$expected_fighter||!hash_equals((string)$row['evidence_hash'],$expected_evidence)||!hash_equals((string)$row['fighter_hash'],$expected_fighter)){throw new \RuntimeException('Evidence or fighter identity changed. Refresh UFC review before deciding.');}
            $fighter=$wpdb->get_row($wpdb->prepare("SELECT id,display_name,date_of_birth,gender,weight_class,in_ufc,deleted_soft FROM {$this->tables['fighters']} WHERE id=%d",$row['fighter_id']),ARRAY_A);
            $source=json_decode((string)$row['source_evidence_json'],true);
            $index=$fighter?UfcMatcher::build_index([$fighter],array_map(static fn($alias)=>['fighter_id'=>(int)$row['fighter_id'],'alias'=>$alias],$this->aliases_for((int)$row['fighter_id']))):['fighters'=>[]];
            $current_hash=$index['fighters'][(int)$row['fighter_id']]['identity_hash']??'';
            if(!$fighter || !is_array($source) || !hash_equals((string)$row['evidence_hash'],self::evidence_hash($source)) || !hash_equals((string)$row['fighter_hash'],$current_hash)){throw new \RuntimeException('Evidence or fighter identity changed. Refresh UFC review before deciding.');}
            if(!in_array($row['status'],['pending','deferred'],true)){throw new \RuntimeException('This UFC candidate is no longer open.');}
            $conflicts=json_decode((string)$row['conflicts_json'],true)?:[];
            if($action==='confirm'){
                if($conflicts && !filter_var($extra_url,FILTER_VALIDATE_URL)){throw new \InvalidArgumentException('Confirmation despite a conflict requires an additional evidence URL.');}
                $this->confirm_locked((int)$row['fighter_id'],$actor,'roster_watch',$extra_url?:((string)$row['source_url']),sanitize_textarea_field($note),null,(string)$row['source_key']);$status='confirmed';
            }elseif($action==='reject'){$status='rejected';}elseif($action==='insufficient_evidence'){$status='insufficient_evidence';}else{$status='deferred';}
            $now=gmdate('Y-m-d H:i:s');
            if(false===$wpdb->update($this->tables['ufc_signing_candidates'],['status'=>$status,'decision_note'=>sanitize_textarea_field($note),'decision_evidence_hash'=>$row['evidence_hash'],'decision_run_id'=>$row['source_run_id'],'decision_source_json'=>$row['source_evidence_json'],'resolved_by'=>$actor,'resolved_at'=>$now,'updated_at'=>$now],['id'=>$candidate_id])){throw new \RuntimeException($wpdb->last_error);}
            $this->record_decision($row,$candidate_id,$action,$actor,$note,$now);
            if(in_array($action,['confirm','reject','insufficient_evidence'],true)){$this->save_link((string)$row['source_key'],(int)$row['fighter_id'],$action==='reject'?'different':'verified',$actor,(string)$row['evidence_hash'],$note);}
            (new AuditLogService())->write('ufc_candidate_decision','fighter',(int)$row['fighter_id'],['status'=>$row['status'],'evidence_hash'=>$row['evidence_hash'],'fighter_hash'=>$row['fighter_hash']],['status'=>$status,'candidate_id'=>$candidate_id,'evidence_hash'=>$row['evidence_hash'],'fighter_hash'=>$row['fighter_hash']],sanitize_textarea_field($note),$actor);
			if($action==='confirm'){$this->after_change((int)$row['fighter_id'],$actor);}
			return $row;
		});
    }

    private function save_link(string $key,int $fighter_id,string $status,int $actor,string $hash,string $note): void {
        global $wpdb;$now=gmdate('Y-m-d H:i:s');$data=['status'=>$status,'actor_user_id'=>$actor,'evidence_hash'=>$hash,'note'=>sanitize_textarea_field($note),'updated_at'=>$now];
        if($status==='verified' && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['ufc_identity_links']} WHERE source_key=%s AND fighter_id<>%d AND status='verified' LIMIT 1",$key,$fighter_id))){throw new \RuntimeException('This roster identity is already verified for a different canonical fighter. Correct that link first.');}
        $prior=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['ufc_identity_links']} WHERE source_key=%s AND fighter_id=%d",$key,$fighter_id));
        if($prior){$ok=$wpdb->update($this->tables['ufc_identity_links'],$data,['id'=>(int)$prior]);}
        else{$ok=$wpdb->insert($this->tables['ufc_identity_links'],$data+['source_key'=>$key,'fighter_id'=>$fighter_id,'created_at'=>$now]);}
        if($ok===false){throw new \RuntimeException($wpdb->last_error);}
    }
    private function record_decision(array $row,int $candidate_id,string $action,int $actor,string $note,string $now): void {
        global $wpdb;$data=['candidate_id'=>$candidate_id,'fighter_id'=>(int)$row['fighter_id'],'action'=>$action,'note'=>sanitize_textarea_field($note),'evidence_hash'=>(string)($row['evidence_hash']??''),'fighter_hash'=>(string)($row['fighter_hash']??''),'source_run_id'=>(string)($row['source_run_id']??$row['decision_run_id']??''),'source_json'=>(string)($row['source_evidence_json']??$row['decision_source_json']??''),'actor_user_id'=>$actor,'created_at'=>$now];
        if(false===$wpdb->insert($this->tables['ufc_candidate_decisions'],$data)){throw new \RuntimeException($wpdb->last_error);}
    }
    public function reopen_candidate(int $candidate_id,int $actor,string $note): void {
        if(trim($note)===''){throw new \InvalidArgumentException('A correction note is required.');}
        global $wpdb;\MMAF\DataEngine\Services\RegularImport\Transaction::run(function()use($candidate_id,$actor,$note,$wpdb){
            $row=$wpdb->get_row($wpdb->prepare("SELECT c.*,o.source_key FROM {$this->tables['ufc_signing_candidates']} c JOIN {$this->tables['ufc_roster_observations']} o ON o.id=c.observation_id WHERE c.id=%d FOR UPDATE",$candidate_id),ARRAY_A);
            if(!$row || !in_array($row['status'],['rejected','insufficient_evidence'],true)){throw new \RuntimeException('Only a closed non-confirmed candidate can be reopened.');}
            $now=gmdate('Y-m-d H:i:s');if(false===$wpdb->update($this->tables['ufc_signing_candidates'],['status'=>'pending','decision_note'=>sanitize_textarea_field($note),'resolved_by'=>null,'resolved_at'=>null,'updated_at'=>$now],['id'=>$candidate_id])){throw new \RuntimeException($wpdb->last_error);}
            $this->record_decision($row,$candidate_id,'reopen',$actor,$note,$now);
            if($row['status']==='rejected'){$this->save_link($row['source_key'],(int)$row['fighter_id'],'corrected',$actor,(string)$row['evidence_hash'],$note);}
            (new AuditLogService())->write('ufc_candidate_reopened','fighter',(int)$row['fighter_id'],['status'=>$row['status']],['status'=>'pending','candidate_id'=>$candidate_id],sanitize_textarea_field($note),$actor);
        });
    }

    public function confirm_manual(int $fighter_id,int $actor,string $url,string $note,?string $signing_date=null): void {
        $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
        if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array($scheme,['http','https'],true)||trim($note)===''){throw new \InvalidArgumentException('A valid evidence URL and note are required.');}
		$date=$this->date($signing_date);if($signing_date && !$date){throw new \InvalidArgumentException('Signing date must use YYYY-MM-DD.');}
		\MMAF\DataEngine\Services\RegularImport\Transaction::run(function()use($fighter_id,$actor,$url,$note,$date){$this->confirm_locked($fighter_id,$actor,'news',esc_url_raw($url),sanitize_textarea_field($note),$date,null);$this->after_change($fighter_id,$actor);});
    }

    private function confirm_locked(int $fighter_id,int $actor,string $source,string $url,string $note,?string $date,?string $ref): void {
        global $wpdb;$fighter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['fighters']} WHERE id=%d FOR UPDATE",$fighter_id),ARRAY_A);if(!$fighter){throw new \RuntimeException('Fighter was not found.');}
        $before=$this->signing($fighter_id);if($before){return;}
        if(false===$wpdb->insert($this->tables['ufc_signing_events'],['fighter_id'=>$fighter_id,'event_type'=>'confirmed','is_active'=>1,'signing_date'=>$date,'evidence_url'=>$url?:null,'evidence_note'=>$note,'source_type'=>$source,'source_ref'=>$ref,'actor_user_id'=>$actor,'created_at'=>gmdate('Y-m-d H:i:s')])){throw new \RuntimeException($wpdb->last_error);}
        $event=(int)$wpdb->insert_id;$now=gmdate('Y-m-d H:i:s');
		$tracking=['in_ufc'=>1,'tracking_mode'=>TrackingService::OPPONENT_ONLY,'tracking_reason'=>'confirmed_ufc_signing','tracking_source'=>$source,'tracking_evidence_url'=>$url?:null,'tracking_changed_at'=>$now,'tracking_revision'=>(int)($fighter['tracking_revision']??0)+1,'tracking_return_candidate_at'=>null,'is_public'=>0,'is_rankable'=>0,'rankability_status'=>'ineligible_ufc','updated_at'=>$now];
		if(false===$wpdb->update($this->tables['fighters'],$tracking,['id'=>$fighter_id])){throw new \RuntimeException($wpdb->last_error);}
        if(false===$wpdb->query($wpdb->prepare("UPDATE {$this->tables['ufc_signing_candidates']} SET status='fighter_confirmed',updated_at=%s WHERE fighter_id=%d AND status IN ('pending','deferred')",$now,$fighter_id))){throw new \RuntimeException($wpdb->last_error);}
        (new AuditLogService())->write('ufc_signing_confirmed','fighter',$fighter_id,$before,['event_id'=>$event,'source_type'=>$source,'evidence_url'=>$url,'signing_date'=>$date],$note,$actor);
    }

    public function correct(int $fighter_id,int $actor,string $reason): void {
        if(trim($reason)===''){throw new \InvalidArgumentException('A correction reason is required.');}
		global $wpdb;\MMAF\DataEngine\Services\RegularImport\Transaction::run(function()use($fighter_id,$actor,$reason,$wpdb){
            $fighter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['fighters']} WHERE id=%d FOR UPDATE",$fighter_id),ARRAY_A);$active=$this->signing($fighter_id);
            if(!$fighter||!$active){throw new \RuntimeException('No confirmed UFC signing exists for this fighter.');}
			$now=gmdate('Y-m-d H:i:s');
            if(false===$wpdb->update($this->tables['ufc_signing_events'],['is_active'=>0],['id'=>(int)$active['id']])){throw new \RuntimeException($wpdb->last_error);}
            if(false===$wpdb->insert($this->tables['ufc_signing_events'],['fighter_id'=>$fighter_id,'event_type'=>'correction','is_active'=>0,'evidence_note'=>sanitize_textarea_field($reason),'source_type'=>'manual','supersedes_event_id'=>(int)$active['id'],'actor_user_id'=>$actor,'created_at'=>$now])){throw new \RuntimeException($wpdb->last_error);}
			if(false===$wpdb->update($this->tables['ufc_signing_candidates'],['status'=>'rejected','resolved_by'=>$actor,'resolved_at'=>$now,'updated_at'=>$now],['fighter_id'=>$fighter_id,'status'=>'confirmed'])){throw new \RuntimeException($wpdb->last_error);}
            $tracking=['in_ufc'=>0,'tracking_mode'=>TrackingService::OPPONENT_ONLY,'tracking_reason'=>'ufc_confirmation_corrected_pending_review','tracking_source'=>'admin','tracking_changed_at'=>$now,'tracking_revision'=>(int)($fighter['tracking_revision']??0)+1,'tracking_return_candidate_at'=>null,'is_public'=>0,'is_rankable'=>0,'rankability_status'=>'pending_review','updated_at'=>$now];
			if(false===$wpdb->update($this->tables['fighters'],$tracking,['id'=>$fighter_id])){throw new \RuntimeException($wpdb->last_error);}
			(new AuditLogService())->write('ufc_signing_corrected','fighter',$fighter_id,$active,$tracking,sanitize_textarea_field($reason),$actor);
			$this->after_change($fighter_id,$actor);
		});
    }

    private function after_change(int $fighter_id,int $actor): void {
        $queue=(array)get_option('mmaf_ufc_post_commit_repairs',[]);$queue[$fighter_id]=['actor'=>$actor,'cache'=>false,'post'=>false,'ranking'=>false,'queued_at'=>gmdate('c')];update_option('mmaf_ufc_post_commit_repairs',$queue,false);
        \MMAF\DataEngine\Services\RegularImport\Transaction::after_commit(fn()=>$this->repair_post_commit($fighter_id));
    }
    private function repair_post_commit(int $fighter_id): void {
        $queue=(array)get_option('mmaf_ufc_post_commit_repairs',[]);if(empty($queue[$fighter_id])){return;}$task=$queue[$fighter_id];
        foreach(['cache','post','ranking'] as $part){if(!empty($task[$part])){continue;}
            try{
                if($part==='cache'){do_action('mmaf_public_data_changed','ufc_signing_changed');}
                elseif($part==='post'){$repository=new \MMAF\DataEngine\Repositories\FighterRepository();$fighter=$repository->find($fighter_id);if($fighter){(new FighterPostSyncService($repository))->sync($fighter_id,$fighter);}}
                else{\MMAF\DataEngine\Services\DataPipeline\Processor::request_refresh((int)$task['actor'],true);}
                $task[$part]=true;
            }catch(\Throwable $error){$task['last_error']=$error->getMessage();}
            $queue[$fighter_id]=$task;update_option('mmaf_ufc_post_commit_repairs',$queue,false);
        }
        if($task['cache']&&$task['post']&&$task['ranking']){unset($queue[$fighter_id]);update_option('mmaf_ufc_post_commit_repairs',$queue,false);}
    }
    public function work_repairs(): array {
        $queue=(array)get_option('mmaf_ufc_post_commit_repairs',[]);if(!$queue){return ['worked'=>false];}
        $fighter_id=(int)array_key_first($queue);$before=array_sum(array_map('intval',array_intersect_key($queue[$fighter_id],array_flip(['cache','post','ranking']))));$this->repair_post_commit($fighter_id);$remaining=(array)get_option('mmaf_ufc_post_commit_repairs',[]);
        $after=isset($remaining[$fighter_id])?array_sum(array_map('intval',array_intersect_key($remaining[$fighter_id],array_flip(['cache','post','ranking'])))):3;
        return ['worked'=>$after>$before,'blocked'=>$after===$before?'post_commit_retry':null,'fighter_id'=>$fighter_id,'remaining'=>count($remaining)];
    }
    private function date($value): ?string {if(!$value){return null;}$value=substr((string)$value,0,10);$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d&&$d->format('Y-m-d')===$value?$value:null;}
}
