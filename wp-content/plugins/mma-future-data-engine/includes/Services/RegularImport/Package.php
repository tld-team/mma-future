<?php
namespace MMAF\DataEngine\Services\RegularImport;
use MMAFVendor\JsonMachine\RecursiveItems;

final class Package {
    public const REQUIRED = ['results.json','daily_summary.json','manual_review.json','run_manifest.json','changes.json','fighter_profiles.json','fighter_profiles_report.json'];
    public const OPTIONAL = ['state_report.json','package_manifest.json','career_history.json','readiness_report.json','source_enrichment_queue.json','event_coverage.json','priority_targets.json'];
    public function prepare(array $job, Repository $repo, callable $heartbeat): array {
        $id=(int)$job['id']; $dir=Storage::job($id); $source=$dir.'/source';
        if (is_dir($source)) { Storage::removeTree($source); }
        wp_mkdir_p($source); chmod($source,0700);
        $zip = new \ZipArchive();
        if (true !== $zip->open($dir.'/bundle.zip', \ZipArchive::CHECKCONS)) { throw new ImportException('The ZIP is damaged or cannot be opened.'); }
        $seen=[]; $total=0; $layout=null;
        try {
            if ($zip->numFiles > 32) { throw new ImportException('The ZIP contains unexpected files. Export a scraper import bundle.'); }
            for ($i=0;$i<$zip->numFiles;$i++) {
                $entry=$zip->statIndex($i); $name=$entry['name'];
                if ($name==='latest/') { continue; }
                $current = strpos($name,'latest/')===0 ? 'latest/' : '';
                if ($layout!==null && $layout!==$current) { throw new ImportException('ZIP mixes root files and latest/ files.'); }
                $layout=$current; $base=substr($name,strlen($current));
                if (!in_array($base,array_merge(self::REQUIRED,self::OPTIONAL),true) || isset($seen[$base])) { throw new ImportException('Unexpected or duplicate ZIP entry: '.$name); }
                $zip->getExternalAttributesIndex($i,$opsys,$attr);
                if (($attr >> 16 & 0170000) === 0120000 || !empty($entry['encryption_method'])) { throw new ImportException('Encrypted entries and symbolic links are not accepted.'); }
                $total+=(int)$entry['size'];
                if ($total>Storage::EXPANDED_LIMIT) { throw new ImportException('The expanded package exceeds 1 GiB.'); }
                $input=$zip->getStream($name); $out=fopen($source.'/'.$base,'xb');
                if (!$input || !$out) { throw new ImportException('Could not unpack the ZIP. Check free disk space.','storage'); }
                try {
                    $written=stream_copy_to_stream($input,$out,Storage::EXPANDED_LIMIT+1);
                    if ($written!==$entry['size']) { throw new ImportException('ZIP entry size mismatch: '.$base); }
                } finally { fclose($input); fclose($out); }
                chmod($source.'/'.$base,0600); $seen[$base]=['bytes'=>$written,'sha256'=>hash_file('sha256',$source.'/'.$base)]; $heartbeat();
            }
        } finally { $zip->close(); }
        $required = isset($seen['career_history.json']) ? ['career_history.json','package_manifest.json'] : self::REQUIRED;
        if (array_diff($required,array_keys($seen))) { throw new ImportException('Required scraper files are missing: '.implode(', ',array_diff($required,array_keys($seen)))); }
        if (isset($seen['package_manifest.json'])) {
            $transport=Storage::read($source.'/package_manifest.json');
            if (($transport['format']??'')!=='mmaf-import-bundle' || ($transport['format_version']??0)!==1) { throw new ImportException('Unsupported import package version.'); }
            if(isset($transport['contract_version']) && !in_array($transport['contract_version'],[1,2],true)){throw new ImportException('Unsupported import evidence contract.','contract');}
            if(!in_array($transport['mode']??'full',['full','delta','career_history'],true)){throw new ImportException('Unsupported import package mode.','contract');}
            $listed=array_keys($transport['files']??[]);$actual=array_values(array_diff(array_keys($seen),['package_manifest.json']));sort($listed);sort($actual);
            if($listed!==$actual){throw new ImportException('Package file list disagrees with its manifest.','checksum');}
            foreach ($seen as $file=>$info) {
                if ($file==='package_manifest.json') { continue; }
                $expected=$transport['files'][$file]??[];
                if (($expected['bytes']??-1)!==$info['bytes'] || !hash_equals((string)($expected['sha256']??''),$info['sha256'])) { throw new ImportException('Package checksum mismatch: '.$file); }
            }
        }
        $hashes=$seen; unset($hashes['package_manifest.json']); ksort($hashes);
        $meta=['files'=>$seen,'bundle_hash'=>hash('sha256',wp_json_encode($hashes)),'expanded_bytes'=>$total,'headers'=>[],'counts'=>[]];
        $coverage=null;
        if(($transport['contract_version']??1)===2 && !isset($seen['career_history.json'])){
            $coverage=EventCoverage::read($source.'/event_coverage.json');
            $meta['event_coverage']=array_intersect_key($coverage,array_flip(['counts','complete']));
        }elseif(isset($seen['event_coverage.json'])){throw new ImportException('Event evidence requires contract v2.','contract');}
        if(($transport['mode']??'')==='delta'){
            $meta['mode']='delta';$meta['delta_base']=Receipt::pointer(Receipt::validate((array)($transport['delta_base']??[])));
        }
        if (isset($seen['career_history.json'])) {
            if (($transport['mode']??'')!=='career_history') { throw new ImportException('Career packages must declare their import mode.'); }
            $index=0;$count=0;$rows=[];$ids=[];$sources=[];$dir=Storage::job($id);
            $flush=function() use (&$rows,&$index,$id,$dir,$repo) { if(!$rows){return;} $path=$dir.'/career-'.$index.'.json'; Storage::write($path,['rows'=>$rows]); $repo->addBatch($id,'career',$index,hash_file('sha256',$path),count($rows)); ++$index;$rows=[]; };
            $header=$this->scan($source.'/career_history.json','profiles',function(array $row) use (&$rows,&$count,&$ids,&$sources,$flush,$heartbeat) {
                $fighter=(int)($row['fighter_id']??0);$source=(string)($row['source_fighter_id']??'');
                if(!$fighter||!$source||isset($ids[$fighter])||isset($sources[$source])){throw new ImportException('Missing or duplicate career target identity.');}
                (new CareerHistory())->verify_target($row); $ids[$fighter]=$source;$sources[$source]=$fighter;
                if(strlen(wp_json_encode($row))>Storage::BATCH_BYTES){throw new ImportException('A career profile exceeds the supported batch size.');}
                if(count($rows)>=10){$flush();$heartbeat();} $rows[]=$row;++$count;
            }); $flush();
            if(($header['schema_version']??'')!=='mmaf_career_history_v1'||($header['source']??'')!=='tapology'){throw new ImportException('Unsupported career history schema/source.');}
            $manifest=$header['target_manifest']['targets']??[];
            $targetIds=array_map('intval',array_column($manifest,'fighter_id'));sort($targetIds);$rowIds=array_keys($ids);sort($rowIds);
            if(!$targetIds||$targetIds!==$rowIds||count(array_unique($targetIds))!==count($targetIds)){throw new ImportException('Every declared career target must have exactly one processing record.');}
            foreach($manifest as $target){if(($ids[(int)($target['fighter_id']??0)]??'')!==($target['source_fighter_id']??'')){throw new ImportException('Target manifest source identity does not match its profile.');}}
            $meta['mode']='career_history';$meta['headers']['career']=$header;$meta['counts']['career']=$count;$meta['date_from']=null;$meta['date_to']=null;
            return $meta;
        }
        if(isset($seen['priority_targets.json'])){
            if(($transport['contract_version']??1)!==2 || $seen['priority_targets.json']['bytes']>2097152){throw new ImportException('Priority evidence requires contract v2 and a bounded manifest.','priority');}
            $meta['priority_targets']=Storage::read($source.'/priority_targets.json');
            \MMAF\DataEngine\Services\Priorities\Registry::validateManifest($meta['priority_targets']);
        }
        $seenEvents=[]; $seenEventIdentities=[]; $seenBouts=[]; $seenBoutIdentities=[]; $seenProfiles=[]; $dates=[];
        foreach (['results'=>['events',20],'fighter_profiles'=>['profiles',100],'manual_review'=>['items',100]] as $file=>$setting) {
            [$field,$limit]=$setting; $kind=['results'=>'results','fighter_profiles'=>'profiles','manual_review'=>'review'][$file];
            $rows=[];$bytes=0;$index=0;$count=0;$header=[];
            // Header is obtained in the same streaming pass; chunks carry full metadata when finalized below.
            $flush=function() use (&$rows,&$bytes,&$index,$dir,$kind,$id,$repo): void {
                if (!$rows) { return; }
                $path=$dir.'/'.$kind.'-'.$index.'.json'; Storage::write($path,['rows'=>$rows]);
                $repo->addBatch($id,$kind,$index,hash_file('sha256',$path),count($rows));
                $index++;$rows=[];$bytes=0;
            };
            $header=$this->scan($source.'/'.$file.'.json',$field,function(array $row) use (&$rows,&$bytes,&$count,$flush,$limit,$kind,&$seenEvents,&$seenEventIdentities,&$seenBouts,&$seenBoutIdentities,&$seenProfiles,&$dates,$heartbeat,$coverage): void {
                if ($kind==='results') {
                    if($coverage){EventCoverage::checkEvent($row,$coverage['by_id']);}
                    $key=(string)($row['source_event_id']??'');
                    if ($key==='' || isset($seenEvents[$key])) { throw new ImportException('Missing or duplicate event source identity.'); }
                    $seenEvents[$key]=true; if (!empty($row['event_date'])) { $dates[]=$row['event_date']; }
                    $identity=(string)($row['identity_hash']??'');
                    if($identity!==''&&isset($seenEventIdentities[$identity])){throw new ImportException('Duplicate event identity in package: '.$key);}
                    if($identity!==''){$seenEventIdentities[$identity]=true;}
                    foreach ($row['bouts']??[] as $bout) {
                        $bkey=(string)($bout['source_bout_id']??'');
                        if ($bkey!=='' && isset($seenBouts[$bkey])) { throw new ImportException('Duplicate bout source identity: '.$bkey); }
                        if ($bkey!=='') { $seenBouts[$bkey]=true; }
                        $bidentity=(string)($bout['identity_hash']??'');
                        if($bidentity!==''&&isset($seenBoutIdentities[$bidentity])){throw new ImportException('Duplicate bout identity in package: '.$bkey);}
                        if($bidentity!==''){$seenBoutIdentities[$bidentity]=true;}
                    }
                } elseif ($kind==='profiles') {
                    $key=(string)(($row['source_fighter_id']??'')?:($row['source_url']??''));
                    if ($key==='' || isset($seenProfiles[$key])) { throw new ImportException('Missing or duplicate profile source identity.'); }
                    $seenProfiles[$key]=true;
                }
                $size=strlen(wp_json_encode($row));
                if ($size>Storage::BATCH_BYTES) { throw new ImportException('A single source record exceeds the supported 4 MiB size.'); }
                if (count($rows)>=$limit || $bytes+$size>Storage::BATCH_BYTES) { $flush(); $heartbeat(); }
                $rows[]=$row;$bytes+=$size;$count++;
            });
            $flush(); $meta['headers'][$kind]=$header; $meta['counts'][$kind]=$count;
        }
        foreach (['daily_summary','run_manifest','changes','fighter_profiles_report','state_report'] as $file) {
            if (isset($seen[$file.'.json'])) { $meta['headers'][$file]=$this->scan($source.'/'.$file.'.json',null,null); $heartbeat(); }
        }
        if($coverage){foreach($coverage['by_id'] as $sid=>$proof){if($proof['accepted'] && !isset($seenEvents[$sid])){throw new ImportException('Accepted coverage has no source results.','coverage');}}}
        $daily=$meta['headers']['daily_summary'];
        if($coverage && $coverage['run_id']!==($meta['headers']['run_manifest']['run_id']??'')){throw new ImportException('Coverage and results refer to different runs.','coverage');}
        if (empty($daily['ready_for_import']) || !empty($daily['blocking_issues'])) { throw new ImportException('Scraper health check is not ready: '.implode('; ',(array)($daily['blocking_issues']??[])),'health_gate'); }
        $meta['counts']['bouts']=count($seenBouts);
        if ((int)($daily['events_total']??-1)!==$meta['counts']['results'] || (int)($daily['bouts_total']??-1)!==$meta['counts']['bouts']) { throw new ImportException('Summary counts do not match the package contents.'); }
        if (($meta['headers']['results']['run']['run_id']??'')!==($meta['headers']['run_manifest']['run_id']??'')) { throw new ImportException('Event results and run manifest refer to different runs.'); }
        if (($meta['headers']['profiles']['run_id']??'')!==($meta['headers']['fighter_profiles_report']['run_id']??'')) { throw new ImportException('Profiles and profile report refer to different runs.'); }
        if (($meta['headers']['profiles']['schema_version']??'')!=='tapology_fighter_profiles_v0_1' || ($meta['headers']['profiles']['source']??'')!=='tapology') { throw new ImportException('Unsupported fighter profile schema or source.'); }
        if ((int)($meta['headers']['profiles']['profiles_total']??-1)!==$meta['counts']['profiles']) { throw new ImportException('Profile count is inconsistent.'); }
        if(isset($seen['readiness_report.json'])){
            if($seen['readiness_report.json']['bytes']>1048576){throw new ImportException('Readiness summary exceeds its supported size.','contract');}
            $readiness=Storage::read($source.'/readiness_report.json');
            if(($readiness['schema_version']??'')!=='mmaf_import_readiness_v1' || ($readiness['contract_version']??0)!==1 || ($readiness['profiles_total']??-1)!==$meta['counts']['profiles']){throw new ImportException('Readiness report does not match this package.','contract');}
            $meta['readiness']=$readiness;
        }
        if(isset($meta['priority_targets'])){
            foreach($meta['priority_targets']['items'] as $target){if(!isset($seenProfiles[$target['source_fighter_id']])){throw new ImportException('A declared priority target has no packaged profile outcome.','priority');}}
        }
        if(!$coverage){$meta['event_coverage']=['complete'=>false,'legacy'=>true,'counts'=>['legacy'=>$meta['counts']['results']]];}
        sort($dates);$meta['date_from']=$dates[0]??null;$meta['date_to']=$dates ? end($dates):null;
        return $meta;
    }
    /** Traverse the entire document, including trailing content; only one source row is materialized. */
    private function scan(string $file,?string $field,?callable $row): array {
        $header=[];$found=$field===null;
        try {
            $document=RecursiveItems::fromFile($file);
            foreach ($document as $key=>$value) {
                if (!is_string($key)) { throw new ImportException('Scraper documents must be JSON objects.'); }
                if ($key===$field) {
                    $found=true;
                    if (!$value instanceof RecursiveItems) { throw new ImportException('Expected a record array in '.$key); }
                    foreach ($value as $index=>$record) {
                        if (!is_int($index) || !$record instanceof RecursiveItems) { throw new ImportException('Expected an array of source records.'); }
                        $row($record->toArray());
                    }
                } elseif ($value instanceof RecursiveItems) {
                    // Large report arrays are validated completely without retaining their rows.
                    if (in_array($key,['profiles','events','items','errors','changes_manual_review_events'],true)) { $this->consume($value); $header[$key]=[]; }
                    else { $header[$key]=$value->toArray(); }
                } else { $header[$key]=$value; }
            }
            JsonDocument::assertComplete($file);
        } catch (ImportException $e) { throw $e; }
        catch (\Throwable $e) { throw new ImportException('Invalid JSON in '.basename($file).': '.$e->getMessage()); }
        if (!$found) { throw new ImportException('Missing '.$field.' in '.basename($file)); }
        return $header;
    }
    private function consume(RecursiveItems $items): void { foreach ($items as $value) { if ($value instanceof RecursiveItems) { $this->consume($value); } } }
    public static function payload(array $job,array $batch): array {
        $path=Storage::job((int)$job['id']).'/'.$batch['kind'].'-'.$batch['batch_index'].'.json';
        if (!is_file($path) || !hash_equals($batch['payload_hash'],hash_file('sha256',$path))) { throw new ImportException('Prepared batch checksum mismatch.','checksum'); }
        $data=$job['meta']['package']['headers'][$batch['kind']];
        $field=['results'=>'events','profiles'=>'profiles','review'=>'items','career'=>'profiles'][$batch['kind']];
        $data[$field]=Storage::read($path)['rows'];
        if ($batch['kind']==='profiles') { $data['profiles_total']=count($data[$field]); }
        return $data;
    }
}
