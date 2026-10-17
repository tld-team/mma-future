<?php
namespace MMAF\DataEngine\Services\Priorities;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Support\TapologyFighterUrl;
use MMAF\DataEngine\Services\RegularImport\{Transaction,Repository,WriteGuard,Mutex,ImportException};
use MMAF\DataEngine\Services\AuditLogService;

/** Editorial membership only. CSV hints never change sports facts or ranking gates. */
final class Registry {
    public static function parseCsv(string $csv): array {
        if(strlen($csv)>8*1024*1024 || !preg_match('//u',$csv)){throw new \InvalidArgumentException('Use a UTF-8 CSV smaller than 8 MiB.');}
        $stream=fopen('php://temp','w+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);
        try {
            $header=fgetcsv($stream,0,',','"','');
            if(!$header){throw new \InvalidArgumentException('CSV header is missing.');}
            $header=array_map('trim',$header);$name=array_search('Ime',$header,true);
            if($name===false){$name=array_search('name',$header,true);}
            if($name===false || count($header)!==count(array_unique($header))){throw new \InvalidArgumentException('CSV needs a unique Ime or name column.');}
            $out=[];$number=1;$occurrences=[];
            while(($values=fgetcsv($stream,0,',','"',''))!==false){
                ++$number;if($values===[null]){continue;}
                if(count($values)!==count($header)){throw new \InvalidArgumentException('Column count differs on CSV row '.$number);}
                if(count($out)>=5000){throw new \InvalidArgumentException('Import at most 5,000 rows per CSV.');}
                $raw=array_combine($header,array_map(static fn($v)=>trim((string)$v),$values));
                $display=trim((string)$values[$name]);
                if($display==='' || strlen($display)>255){throw new \InvalidArgumentException('Missing or oversized name on row '.$number);}
                $stable=$raw;ksort($stable);$fingerprint=hash('sha256',json_encode($stable,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$occurrence=$occurrences[$fingerprint]??0;$occurrences[$fingerprint]=$occurrence+1;$key=hash('sha256',$fingerprint.'|'.$occurrence);
                $out[]=['entry_key'=>$key,'client_name'=>$display,'source_row'=>['row'=>$number,'values'=>$raw]];
            }
            return $out;
        } finally {fclose($stream);}
    }
    public static function importCsv(string $csv,int $actor): array {
        $rows=self::parseCsv($csv);WriteGuard::assertAllowed();
        return Mutex::run(static function()use($rows,$actor){return Transaction::run(static function()use($rows,$actor){
            global $wpdb;$t=Schema::table();$created=0;$existing=0;$now=gmdate('Y-m-d H:i:s');
            foreach($rows as $row){
                if($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE entry_key=%s",$row['entry_key']))){++$existing;continue;}
                Repository::check($wpdb->insert($t,['entry_key'=>$row['entry_key'],'client_name'=>sanitize_text_field($row['client_name']),'source_row_json'=>wp_json_encode($row['source_row']),'created_by'=>$actor,'created_at'=>$now,'updated_at'=>$now]));++$created;
            }
            (new AuditLogService())->write('priority_csv_imported','priority',0,null,['rows'=>count($rows),'created'=>$created,'existing'=>$existing],'Imported editorial priorities; sports facts unchanged.',$actor);
            return ['rows'=>count($rows),'created'=>$created,'existing'=>$existing];
        });});
    }
    public static function get(int $id): ?array {global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table().' WHERE id=%d',$id),ARRAY_A)?:null;}
    public static function token(array $row): string {return hash('sha256',implode('|',[$row['id'],$row['source_fighter_id'],$row['source_url'],$row['binding_revision'],$row['is_active']]));}
    public static function resolve(int $id,int $fighter,string $url,int $actor): array {
        WriteGuard::assertAllowed();
        return Mutex::run(static function()use($id,$fighter,$url,$actor){return Transaction::run(static function()use($id,$fighter,$url,$actor){
            global $wpdb;$old=self::get($id);if(!$old){throw new \InvalidArgumentException('Priority target not found.');}
            $t=Canonical::table_names();
            if($url==='' && $fighter){
                $sources=$wpdb->get_results($wpdb->prepare("SELECT source_url FROM {$t['fighter_sources']} WHERE fighter_id=%d AND source_type='tapology' ORDER BY is_primary DESC,id",$fighter),ARRAY_A);
                $keys=[];foreach($sources as $s){$p=TapologyFighterUrl::parse($s['source_url']);if($p && $p['source_fighter_id']){$keys[$p['source_fighter_id']]=$s['source_url'];}}
                if(count($keys)!==1){throw new \InvalidArgumentException('Choose an explicit source URL for an ambiguous or missing identity.');}$url=reset($keys);
            }
            $identity=TapologyFighterUrl::parse($url);
            if(!$identity || !preg_match('/^tapology_fighter_\d+$/D',(string)$identity['source_fighter_id'])){throw new \InvalidArgumentException('A numeric Tapology fighter URL is required.');}
            $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT fighter_id FROM {$t['fighter_sources']} WHERE source_type='tapology' AND source_fighter_id=%s AND fighter_id IS NOT NULL",$identity['source_fighter_id']));
            if(count($ids)>1 || ($fighter && $ids && (int)$ids[0]!==$fighter)){throw new \InvalidArgumentException('Source identity conflicts with the selected fighter.');}
            if($fighter && !$ids){throw new \InvalidArgumentException('The selected fighter has no matching source identity.');}
            $found=$ids?(int)$ids[0]:null;
            Repository::check($wpdb->update(Schema::table(),['source_fighter_id'=>$identity['source_fighter_id'],'source_url'=>$identity['source_url'],'fighter_id'=>$found,'identity_status'=>'confirmed','binding_revision'=>(int)$old['binding_revision']+1,'confirmed_by'=>$actor,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]));
            $after=self::get($id);(new AuditLogService())->write('priority_identity_confirmed','priority',$id,$old,$after,'Source identity confirmed by editor.',$actor);return $after;
        });});
    }
    public static function active(int $id,bool $active,int $actor): void {
        WriteGuard::assertAllowed();Mutex::run(static function()use($id,$active,$actor){global $wpdb;$old=self::get($id);if(!$old){throw new \InvalidArgumentException('Priority not found.');}
            Repository::check($wpdb->update(Schema::table(),['is_active'=>$active?1:0,'binding_revision'=>(int)$old['binding_revision']+1,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]));
            (new AuditLogService())->write('priority_membership_changed','priority',$id,$old,self::get($id),'Editorial priority changed.',$actor);
        });
    }
    public static function export(): array {
        global $wpdb;$rows=$wpdb->get_results('SELECT * FROM '.Schema::table()." WHERE is_active=1 AND identity_status='confirmed' ORDER BY id",ARRAY_A);
        $bindings=[];$items=[];$ids=array_values(array_unique(array_filter(array_map('intval',array_column($rows,'fighter_id')))));
        $records=$ids?(new \MMAF\DataEngine\Services\Scoring\CareerRecords())->for_fighters($ids):[];
        foreach($rows as $r){
            $key=$r['source_fighter_id'];$record=$records[(int)$r['fighter_id']]??null;
            $bindings[]=['target_id'=>(int)$r['id'],'source_fighter_id'=>$key,'source_url'=>$r['source_url'],'token'=>self::token($r)];
            $items[$key]=['source_fighter_id'=>$key,'source_url'=>$r['source_url'],'display_name'=>$r['client_name'],'fighter_id'=>$r['fighter_id']?(int)$r['fighter_id']:null,'action'=>'refresh_source_evidence','fetch_scope'=>($record['status']??'')==='accepted' && ($record['record']['pro_fights_count']??999)<=30?'career_and_period':'career_record','force_refresh'=>true];
        }
        return ['schema_version'=>'mmaf_source_enrichment_v1','contract_version'=>1,'generated_at'=>gmdate('c'),'items'=>array_values($items),'priority_targets'=>['schema_version'=>'mmaf_priority_targets_v1','site_url'=>home_url('/'),'exported_at'=>gmdate('c'),'items'=>$bindings]];
    }
    public static function validateManifest(array $manifest): array {
        if(($manifest['schema_version']??'')!=='mmaf_priority_targets_v1'||($manifest['site_url']??'')!==home_url('/')||!is_array($manifest['items']??null)){throw new ImportException('Priority targets belong to a different registry/site.','priority');}
        global $wpdb;$seen=[];$by=[];$t=Canonical::table_names();
        $ids=array_map('intval',array_column($manifest['items'],'target_id'));
        if(count($ids)>5000 || in_array(0,$ids,true)){throw new ImportException('Invalid priority target manifest size/identity.','priority');}
        $rows=$ids?$wpdb->get_results('SELECT * FROM '.Schema::table().' WHERE id IN ('.implode(',',$ids).')',ARRAY_A):[];
        $index=array_column($rows,null,'id');
        $sources=$rows?array_values(array_unique(array_column($rows,'source_fighter_id'))):[];$canonical=[];
        if($sources){foreach($wpdb->get_results($wpdb->prepare("SELECT source_fighter_id,fighter_id FROM {$t['fighter_sources']} WHERE source_type='tapology' AND source_fighter_id IN (".implode(',',array_fill(0,count($sources),'%s')).")",...$sources),ARRAY_A) as $source){$canonical[$source['source_fighter_id']][]=(int)$source['fighter_id'];}}
        if($wpdb->last_error){throw new ImportException('Cannot verify current priority source bindings.','priority');}
        foreach($manifest['items'] as $item){
            $id=(int)($item['target_id']??0);$r=$index[$id]??null;
            if(isset($seen[$id])||!$r||!$r['is_active']||$r['identity_status']!=='confirmed'||($item['source_fighter_id']??'')!==$r['source_fighter_id']||($item['source_url']??'')!==$r['source_url']||!hash_equals(self::token($r),(string)($item['token']??''))){throw new ImportException('A priority identity changed; export a fresh queue.','priority');}
            $mapped=array_values(array_unique($canonical[$r['source_fighter_id']]??[]));
            if(count($mapped)>1 || ($r['fighter_id'] && $mapped!==[(int)$r['fighter_id']])){throw new ImportException('Confirmed priority canonical mapping changed; review its source identity.','priority');}
            $seen[$id]=true;$by[$r['source_fighter_id']][]=$id;
        }
        return $by;
    }
    public static function bindCreated(string $source,int $fighter,int $actor=0): void {
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Schema::table()." WHERE source_fighter_id=%s AND identity_status='confirmed'",$source),ARRAY_A);
        foreach($rows as $old){
            if((int)$old['fighter_id']===$fighter){continue;}
            Repository::check($wpdb->update(Schema::table(),['fighter_id'=>$fighter,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$old['id']]));
            (new AuditLogService())->write('priority_canonical_linked','priority',(int)$old['id'],$old,self::get((int)$old['id']),'Linked through confirmed source identity during profile import.',$actor);
        }
    }
    public static function candidates(string $name): array {
        global $wpdb;$t=Canonical::table_names();$key=\MMAF\DataEngine\Support\Sanitizer::normalize_name($name);
        return $wpdb->get_results($wpdb->prepare("SELECT DISTINCT f.id,f.display_name,f.date_of_birth,f.nationality,s.source_fighter_id,s.source_url FROM {$t['fighters']} f JOIN {$t['fighter_sources']} s ON s.fighter_id=f.id AND s.source_type='tapology' LEFT JOIN {$t['fighter_aliases']} a ON a.fighter_id=f.id WHERE f.deleted_soft=0 AND (f.normalized_name=%s OR a.normalized_alias=%s) ORDER BY f.id LIMIT 10",$key,$key),ARRAY_A);
    }
}
