<?php
namespace MMAF\DataEngine\Services\DataPipeline;

use MMAFVendor\JsonMachine\RecursiveItems;
use MMAF\DataEngine\Migrations\Schema as Canonical;
use MMAF\DataEngine\Services\RegularImport\{ImportException,JsonDocument,Mutex,Repository,Review,Transaction,WriteGuard};

/** One-time CLI migration of existing private exports. No scraping or publication. */
final class Backfill {
    public function run(string $file, bool $prepare=false): array {
        return Mutex::run(function() use($file,$prepare) {
            WriteGuard::assertAllowed();
            if (!is_file($file) || !is_readable($file)) { throw new ImportException('Source export is not readable.'); }
            $hash=hash_file('sha256',$file); $key='profile_seed_'.substr($hash,0,48);
            $header=[]; $count=0;
            // Validate the entire export before the first write; traversal stays bounded.
            $document=RecursiveItems::fromFile($file);
            foreach ($document as $name=>$value) {
                if ($name==='profiles') {
                    if (!$value instanceof RecursiveItems) { throw new ImportException('Expected a profiles array.'); }
                    foreach ($value as $index=>$profile) {
                        if (!is_int($index) || !$profile instanceof RecursiveItems) { throw new ImportException('Invalid profile export row.'); }
                        ++$count;
                    }
                } elseif ($value instanceof RecursiveItems) {
                    $this->consume($value);
                } else { $header[$name]=$value; }
            }
            JsonDocument::assertComplete($file);
            $schema=$header['schema_version']??'';
            if (!in_array($schema,['tapology_fighter_profiles_v0_1','mmaf_career_history_v1'],true) || ($header['source']??'')!=='tapology' || !$count) { throw new ImportException('Unsupported or empty profile export.'); }
            if (isset($header['profiles_total']) && (int)$header['profiles_total']!==$count) { throw new ImportException('Export profile count does not match its contents.'); }
            global $wpdb; $stateTable=Canonical::table_names()['system_state'];
            $state=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT state_value FROM $stateTable WHERE state_key=%s",$key)),true) ?: ['sha256'=>$hash,'total'=>$count,'processed'=>0,'counts'=>[],'reviews'=>[]];
            if (!empty($state['completed'])) { return $state; }
            $store=new ProfileStore(); $batch=[]; $index=0;
            $flush=function() use(&$state,&$batch,$store,$schema,$prepare,$wpdb,$stateTable,$key) {
                if (!$batch) { return; }
                Transaction::run(function() use(&$state,&$batch,$store,$schema,$prepare,$wpdb,$stateTable,$key) {
                    foreach ($batch as $profile) {
                        if ($schema==='mmaf_career_history_v1') { $profile=ProfileStore::from_career($profile); }
                        $saved=$store->put($profile,$schema); $status=$saved['status'];
                        $state['counts'][$status]=($state['counts'][$status]??0)+1;
                        if ($status==='review' && count($state['reviews'])<20) { $state['reviews'][]=['source_fighter_id'=>$profile['source_fighter_id']??null,'reason'=>$saved['reason']]; }
                        if ($status==='review' || empty($saved['has_success'])) {
                            Review::upsert(0,'profile_snapshot',(string)($profile['source_fighter_id']??$profile['source_url']??''),['source_fighter_id'=>$profile['source_fighter_id']??null,'reason'=>$saved['reason']??'No successful source snapshot is available.'],0);
                        }
                        if ($prepare && !empty($saved['fighter_id']) && !empty($saved['has_success']) && $status!=='review') { Processor::enqueue((int)$saved['fighter_id']); }
                        ++$state['processed'];
                    }
                    $state['completed']=$state['processed']===$state['total']; $state['updated_at']=gmdate('c');
                    if ($prepare) { Processor::request_refresh(0,true); }
                    do_action('mmaf_profile_seed_before_checkpoint',$state);
                    Repository::check($wpdb->query($wpdb->prepare("INSERT INTO $stateTable (state_key,state_value,autoload,updated_at) VALUES (%s,%s,'no',%s) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value),updated_at=VALUES(updated_at)",$key,wp_json_encode($state),gmdate('Y-m-d H:i:s'))));
                });
                $batch=[];
            };
            foreach (RecursiveItems::fromFile($file,['pointer'=>'/profiles']) as $profile) {
                if ($index++<(int)$state['processed']) { continue; }
                $batch[]=$profile->toArray();
                if (count($batch)>=100) { $flush(); }
            }
            $flush();
            if (!hash_equals($hash,hash_file('sha256',$file))) { throw new ImportException('Source export changed during migration.'); }
            return $state;
        });
    }
    private function consume(RecursiveItems $items): void {
        foreach ($items as $value) { if ($value instanceof RecursiveItems) { $this->consume($value); } }
    }
}
