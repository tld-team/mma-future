<?php
namespace MMAF\DataEngine\Services\Operations;

use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\RegularImport\{Mutex,Repository,Transaction,WriteGuard};

/** Frozen, bounded archive with an actual isolated SQL restore before any removal. */
final class Archive {
    private const BATCH=250;
    public static function create(string $kind,int $entity=0): int {
        return Mutex::run(static function()use($kind,$entity){
            WriteGuard::assertAllowed(true);global $wpdb;$tables=Schema::tables();$source=self::source($kind);
            $estimate=(int)$wpdb->get_var($wpdb->prepare('SELECT DATA_LENGTH+INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,$source));
            Capacity::requireSpace($estimate*3+10485760,500);
            if($kind==='draft' && !self::draftEligible($entity)){throw new \RuntimeException('Ranking is retained, has uncertain activation history, or is still referenced.');}
            $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['archives']} WHERE kind=%s AND entity_id=%d AND status IN ('running','verified') ORDER BY id DESC LIMIT 1",$kind,$entity));
            if($existing){return $existing;}
            $now=gmdate('Y-m-d H:i:s');$max=(int)$wpdb->get_var("SELECT MAX(id) FROM $source");
            $cp=['cursor'=>0,'max_id'=>$max,'cutoff'=>gmdate('Y-m-d H:i:s',time()-YEAR_IN_SECONDS),'offset'=>0,'exported'=>0,'restore_offset'=>0,'deleted'=>0,'retained'=>0];
            Repository::check($wpdb->insert($tables['archives'],['kind'=>$kind,'entity_id'=>$entity,'status'=>'running','phase'=>'freeze','checkpoint_json'=>wp_json_encode($cp),'created_at'=>$now,'updated_at'=>$now]));return (int)$wpdb->insert_id;
        });
    }
    public static function get(int $id): ?array {
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::tables()['archives'].' WHERE id=%d',$id),ARRAY_A);
        if($row){$row['checkpoint']=json_decode($row['checkpoint_json'],true,512,JSON_THROW_ON_ERROR);}return $row?:null;
    }
    private static function save(int $id,array $fields): void {
        global $wpdb;if(isset($fields['checkpoint'])){$fields['checkpoint_json']=wp_json_encode($fields['checkpoint']);unset($fields['checkpoint']);}
        $fields['updated_at']=gmdate('Y-m-d H:i:s');Repository::check($wpdb->update(Schema::tables()['archives'],$fields,['id'=>$id]));
    }
    public static function retry(int $id): array {
        return Mutex::run(static function()use($id){
            WriteGuard::assertAllowed(true);$job=self::get($id);
            if(!$job || $job['status']!=='failed'){throw new \RuntimeException('Only a failed archive can be retried.');}
            // Resume the same manifest; damaged evidence must still pass its checksum.
            self::save($id,['status'=>'running','error_message'=>null]);return self::get($id);
        });
    }
    private static function source(string $kind): string {
        $t=Canonical::table_names();$map=['draft'=>'ranking_snapshots','review'=>'review_items','audit'=>'audit_log'];
        if(!isset($map[$kind])){throw new \InvalidArgumentException('Unsupported archive kind.');}return $t[$map[$kind]];
    }
    private static function predicate(array $job,string $alias='r'): string {
        global $wpdb;$t=Canonical::table_names();$o=Schema::tables();$cutoff=$job['checkpoint']['cutoff'];
        if($job['kind']==='draft'){return $wpdb->prepare("$alias.ranking_run_id=%d",$job['entity_id']);}
        if($job['kind']==='review'){
            // Indexed links preserve open jobs. Legacy unlinked evidence is retained.
            return $wpdb->prepare("$alias.status IN ('resolved','dismissed') AND GREATEST(COALESCE($alias.resolved_at,$alias.created_at),COALESCE($alias.updated_at,$alias.created_at))<%s AND $alias.notes NOT LIKE '%%\"archive_id\"%%' AND EXISTS (SELECT 1 FROM {$o['review_links']} l WHERE l.review_id=$alias.id) AND NOT EXISTS (SELECT 1 FROM {$o['review_links']} l JOIN {$o['jobs']} j ON l.entity_type='operation' AND j.id=l.entity_id WHERE l.review_id=$alias.id AND j.status NOT IN ('completed','cancelled')) AND NOT EXISTS (SELECT 1 FROM {$o['review_links']} l JOIN ".\MMAF\DataEngine\Services\RegularImport\Schema::tables()['jobs']." j ON l.entity_type='import' AND j.id=l.entity_id WHERE l.review_id=$alias.id AND j.status NOT IN ('completed','completed_with_review','rejected','discarded'))",$cutoff);
        }
        // Manual decisions, provenance evidence and activation audits remain online.
        return $wpdb->prepare("$alias.action IN ('stats_rebuilt','ranking_calculated') AND $alias.created_at<%s AND COALESCE($alias.after_json,'') NOT LIKE '%%\"archive_id\"%%' AND $alias.id < COALESCE((SELECT MAX(a.id) FROM {$t['audit_log']} a WHERE a.action=$alias.action),0) AND ($alias.action='stats_rebuilt' OR EXISTS (SELECT 1 FROM {$t['ranking_runs']} run WHERE run.id=$alias.entity_id AND run.is_active=0 AND JSON_UNQUOTE(JSON_EXTRACT(run.data_snapshot_json,'$.activation_tracking'))='ledger_v1' AND NOT EXISTS (SELECT 1 FROM {$o['activations']} x WHERE x.ranking_run_id=run.id)))",$cutoff);
    }
    public static function draftEligible(int $id): bool {
        global $wpdb;$t=Canonical::table_names();$o=Schema::tables();if($id<1){return false;}
        $run=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['ranking_runs']} WHERE id=%d",$id),ARRAY_A);
        if(!$run || (int)$run['is_active'] || $run['status']!=='completed' || strtotime($run['created_at'].' UTC')>=time()-YEAR_IN_SECONDS){return false;}
        if((json_decode($run['data_snapshot_json']??'',true)['activation_tracking']??null)!=='ledger_v1'){return false;}
        $lastTen=(int)$wpdb->get_var("SELECT id FROM {$t['ranking_runs']} WHERE is_active=0 AND status='completed' ORDER BY id DESC LIMIT 1 OFFSET 9");
        if(!$lastTen || $id>=$lastTen){return false;}
        $activated=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o['activations']} WHERE ranking_run_id=%d",$id));
        $current=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ranking_current']} WHERE ranking_run_id=%d",$id));
        $pending=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o['jobs']} WHERE status NOT IN ('completed','cancelled') AND JSON_UNQUOTE(JSON_EXTRACT(checkpoint_json,'$.run_id'))=%s",(string)$id));
        return !$activated && !$current && !$pending;
    }
    public static function candidateCount(string $kind): int {
        if(!in_array($kind,['review','audit'],true)){throw new \InvalidArgumentException('Unsupported candidate summary.');}
        global $wpdb;$source=self::source($kind);$where=self::predicate(['kind'=>$kind,'checkpoint'=>['cutoff'=>gmdate('Y-m-d H:i:s',time()-YEAR_IN_SECONDS)]]);
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $source r WHERE $where");
    }
    public static function schedule(): void {
        global $wpdb;$o=Schema::tables();$t=Canonical::table_names();
        if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$o['archives']} WHERE status IN ('running','verified')")){return;}
        foreach(['review','audit'] as $kind){
            // A failed archive needs an explicit retry, not a new copy every minute.
            if((int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$o['archives']} WHERE kind=%s AND status='failed' LIMIT 1",$kind))){continue;}
            if(self::candidateCount($kind)>0){self::create($kind);return;}
        }
        $ids=$wpdb->get_col("SELECT r.id FROM {$t['ranking_runs']} r WHERE r.is_active=0 AND r.status='completed' AND r.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 12 MONTH) AND JSON_UNQUOTE(JSON_EXTRACT(r.data_snapshot_json,'$.activation_tracking'))='ledger_v1' AND EXISTS (SELECT 1 FROM {$t['ranking_snapshots']} s WHERE s.ranking_run_id=r.id) AND NOT EXISTS (SELECT 1 FROM {$o['archives']} a WHERE a.kind='draft' AND a.entity_id=r.id AND a.status IN ('failed','retained')) ORDER BY r.id LIMIT 100");
        foreach($ids as $id){if(self::draftEligible((int)$id)){self::create('draft',(int)$id);return;}}
    }
    private static function directory(int $id): string {
        if(!defined('MMAF_OPERATIONS_DIR')){throw new \RuntimeException('Private archive directory is not configured.');}
        $base=realpath(MMAF_OPERATIONS_DIR);if(!$base || str_starts_with($base,realpath(ABSPATH).DIRECTORY_SEPARATOR)){throw new \RuntimeException('Archives must be outside the web root.');}
        $dir=$base.'/archives/'.$id;if(!is_dir($dir) && !mkdir($dir,0700,true)){throw new \RuntimeException('Cannot create private archive.');}return $dir;
    }
    private static function target(): \PDO {
        if(!defined('MMAF_OPERATIONS_DIR')){throw new \RuntimeException('Private verification configuration required.');}
        $path=MMAF_OPERATIONS_DIR.'/config.json';if(!is_file($path) || (fileperms($path)&0077)){throw new \RuntimeException('Verification configuration must be private.');}
        $config=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$v=$config['verification']??[];$db=$v['database']??'';
        if($db===DB_NAME || !preg_match('/^(?:mmaf_restore_[a-z0-9_]+|lukamut1_mmaf_verify)$/D',$db)){throw new \RuntimeException('An isolated verification database is required.');}
        $dsn='mysql:dbname='.$db.';charset=utf8mb4;'.(!empty($v['socket'])?'unix_socket='.$v['socket']:'host='.($v['host']??'localhost').';port='.(int)($v['port']??3306));
        return new \PDO($dsn,$v['user'],$v['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_STRINGIFY_FETCHES=>true,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
    }
    public static function step(int $id,bool $allowRemoval=false): array {
        return Mutex::run(static function()use($id,$allowRemoval){
            WriteGuard::assertAllowed(true);$job=self::get($id);if(!$job){throw new \RuntimeException('Archive not found.');}
            if(!in_array($job['status'],['running','verified'],true)){return $job;}
            try{
                if($job['phase']==='remove' && !$allowRemoval){return $job;}
                self::unit($job,$allowRemoval);
            }catch(\Throwable $e){self::save($id,['status'=>'failed','error_message'=>substr($e->getMessage(),0,255)]);throw $e;}
            return self::get($id);
        });
    }
    private static function unit(array $job,bool $allowRemoval): void {
        global $wpdb;$id=(int)$job['id'];$cp=$job['checkpoint'];$o=Schema::tables();$source=self::source($job['kind']);$dir=self::directory($id);$file=$dir.'/rows.ndjson';
        if($job['kind']==='draft' && !self::draftEligible((int)$job['entity_id'])){self::save($id,['status'=>'retained','error_message'=>'Ranking became protected; no further rows were removed.']);return;}
        if($job['phase']==='freeze'){
            $where=self::predicate($job);$ids=$wpdb->get_col($wpdb->prepare("SELECT r.id FROM $source r WHERE r.id>%d AND r.id<=%d AND $where ORDER BY r.id LIMIT %d",$cp['cursor'],$cp['max_id'],self::BATCH));
            Transaction::run(static function()use($ids,$id,&$cp,$o){
                $items=[];foreach($ids as $value){$items[]=['archive_id'=>$id,'source_id'=>$value,'status'=>'frozen'];$cp['cursor']=(int)$value;}BulkWriter::insert($o['archive_items'],$items,true);
                $cp['frozen']=($cp['frozen']??0)+count($ids);self::save($id,['phase'=>count($ids)<self::BATCH?'export':'freeze','checkpoint'=>$cp,'total'=>$cp['frozen']]);
            });return;
        }
        if($job['phase']==='export'){
            $handle=fopen($file,'c+b');if(!$handle){throw new \RuntimeException('Cannot write archive.');}chmod($file,0600);ftruncate($handle,(int)$cp['offset']);fseek($handle,(int)$cp['offset']);
            try{
                $rows=$wpdb->get_results($wpdb->prepare("SELECT r.* FROM {$o['archive_items']} i JOIN $source r ON r.id=i.source_id WHERE i.archive_id=%d AND i.status='frozen' ORDER BY i.source_id LIMIT %d",$id,self::BATCH),ARRAY_A);$written=[];$bytes=0;
                foreach($rows as $row){$line=wp_json_encode($row,JSON_UNESCAPED_SLASHES)."\n";if(strlen($line)>4*1024*1024){throw new \RuntimeException('Archive row exceeds safe byte budget.');}if($written && $bytes+strlen($line)>1024*1024){break;}
                    if(fwrite($handle,$line)!==strlen($line)){throw new \RuntimeException('Archive write failed.');}$written[(int)$row['id']]=hash('sha256',wp_json_encode($row));$bytes+=strlen($line);
                }
                fflush($handle);if(function_exists('fsync')){fsync($handle);}$cp['offset']=ftell($handle);$cp['exported']+=count($written);
                Transaction::run(static function()use($written,$id,$o,$cp){foreach($written as $row=>$hash){Repository::check($GLOBALS['wpdb']->update($o['archive_items'],['row_hash'=>$hash,'status'=>'exported'],['archive_id'=>$id,'source_id'=>$row]));}self::save($id,['checkpoint'=>$cp]);});
                if(!$rows){
                    $missing=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o['archive_items']} WHERE archive_id=%d AND status='frozen'",$id));
                    if($missing){Repository::check($wpdb->query($wpdb->prepare("UPDATE {$o['archive_items']} SET status='retained' WHERE archive_id=%d AND status='frozen'",$id)));$cp['retained']+=$missing;}
                    $create=$wpdb->get_row("SHOW CREATE TABLE $source",ARRAY_N);$cp['sha256']=hash_file('sha256',$file);$cp['create_sql']=$create[1];$cp['verification_table']='mmaf_archive_verify_'.$id;
                    self::manifest($dir,$job,$cp);self::save($id,['phase'=>'restore','checkpoint'=>$cp]);
                }
            }finally{fclose($handle);}return;
        }
        if($job['phase']==='restore'){
            if(hash_file('sha256',$file)!==$cp['sha256']){throw new \RuntimeException('Archive changed before restore verification.');}
            $pdo=self::target();$table=$cp['verification_table'];
            $sql=preg_replace('/^CREATE TABLE `[^`]+`/',"CREATE TABLE IF NOT EXISTS `$table`",$cp['create_sql'],1);$pdo->exec($sql);
            $handle=fopen($file,'rb');fseek($handle,(int)$cp['restore_offset']);$n=0;$bytes=0;
            try{
                $pdo->beginTransaction();
                while($n<self::BATCH && ($line=fgets($handle,4*1024*1024+1))!==false){
                    if(!str_ends_with($line,"\n")){throw new \RuntimeException('Archive record is incomplete.');}
                    $row=json_decode($line,true,512,JSON_THROW_ON_ERROR);$columns=array_keys($row);foreach($columns as $column){if(!preg_match('/^[a-z_]+$/D',$column)){throw new \RuntimeException('Unexpected archive column.');}}
                    $insert=$pdo->prepare("INSERT IGNORE INTO `$table` (`".implode('`,`',$columns)."`) VALUES (".implode(',',array_fill(0,count($columns),'?')).')');$insert->execute(array_values($row));
                    $query=$pdo->prepare("SELECT * FROM `$table` WHERE id=?");$query->execute([$row['id']]);$restored=$query->fetch();
                    if(hash('sha256',wp_json_encode($restored))!==hash('sha256',wp_json_encode($row))){throw new \RuntimeException('Restored archive row differs.');}
                    $n++;$bytes+=strlen($line);if($bytes>=1024*1024){break;}
                }
                $offset=ftell($handle);$end=feof($handle);$pdo->commit();$cp['restore_offset']=$offset;$cp['restored']=($cp['restored']??0)+$n;
                if($end){
                    if((int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn()!==(int)$cp['exported']){throw new \RuntimeException('Archive restore count differs.');}
                    $cp['restore_verified_at']=gmdate('c');self::manifest($dir,$job,$cp);self::save($id,['status'=>'verified','phase'=>'remove','checkpoint'=>$cp]);
                }else{self::save($id,['checkpoint'=>$cp]);}
            }finally{if($pdo->inTransaction()){$pdo->rollBack();}fclose($handle);}return;
        }
        if($job['phase']==='remove' && $allowRemoval){
            if(empty($cp['restore_verified_at']) || hash_file('sha256',$file)!==$cp['sha256']){throw new \RuntimeException('Archive restore evidence or checksum is invalid.');}
            Transaction::run(static function()use($id,$o,$source,$job,&$cp){
                $items=$GLOBALS['wpdb']->get_results($GLOBALS['wpdb']->prepare("SELECT source_id,row_hash FROM {$o['archive_items']} WHERE archive_id=%d AND status='exported' ORDER BY source_id LIMIT %d FOR UPDATE",$id,self::BATCH),ARRAY_A);
                foreach($items as $item){
                    $row=$GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare("SELECT * FROM $source WHERE id=%d FOR UPDATE",$item['source_id']),ARRAY_A);
                    $where=self::predicate($job);$eligible=(int)$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM $source r WHERE r.id=%d AND $where",$item['source_id']));
                    $remove=$row && $eligible && hash_equals($item['row_hash'],hash('sha256',wp_json_encode($row)));
                    if($remove){
                        if($job['kind']==='draft'){Repository::check($GLOBALS['wpdb']->delete($source,['id'=>$item['source_id']]));}
                        elseif($job['kind']==='review'){Repository::check($GLOBALS['wpdb']->update($source,['notes'=>wp_json_encode(['archive_id'=>$id,'row_hash'=>$item['row_hash']])],['id'=>$item['source_id']]));}
                        else{Repository::check($GLOBALS['wpdb']->update($source,['before_json'=>null,'after_json'=>wp_json_encode(['archive_id'=>$id,'row_hash'=>$item['row_hash']])],['id'=>$item['source_id']]));}
                    }
                    Repository::check($GLOBALS['wpdb']->update($o['archive_items'],['status'=>$remove?'archived':'retained'],['archive_id'=>$id,'source_id'=>$item['source_id']]));$cp[$remove?'deleted':'retained']++;
                }
                self::save($id,['checkpoint'=>$cp]);
                if(!$items){self::save($id,['status'=>'completed','phase'=>'done','finished_at'=>gmdate('Y-m-d H:i:s')]);}
            });return;
        }
    }
    private static function manifest(string $dir,array $job,array $cp): void {
        $manifest=['format'=>1,'archive_id'=>(int)$job['id'],'kind'=>$job['kind'],'entity_id'=>(int)$job['entity_id'],'schema'=>get_option('mmaf_db_version'),'commit'=>get_option('mmaf_release',[])['commit']??null,'rows'=>$cp['exported'],'sha256'=>$cp['sha256'],'restore_verified_at'=>$cp['restore_verified_at']??null,'create_sql'=>$cp['create_sql']];
        $path=$dir.'/manifest.json';$temp=$path.'.pending';if(file_put_contents($temp,wp_json_encode($manifest))===false){throw new \RuntimeException('Cannot finalize archive manifest.');}chmod($temp,0600);rename($temp,$path);
    }
    public static function work(bool $allowRemoval=false): array {
        global $wpdb;$id=(int)$wpdb->get_var('SELECT id FROM '.Schema::tables()['archives']." WHERE status='running'".($allowRemoval?" OR status='verified'":'').' ORDER BY id LIMIT 1');
        if(!$id){return ['worked'=>false];}return ['worked'=>true,'archive'=>self::step($id,$allowRemoval)];
    }
}
